<?php

namespace ThaKladd\VectorLite;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
use ThaKladd\VectorLite\Tests\Models\VectorCluster;

class VectorLite
{
    protected string $clusterTable = '';

    protected string $clusterModelName = '';

    protected string $clusterForeignKey = '';

    protected string $matchColumn = '';

    protected int $maxClusterSize = 500;

    protected string $countColumn = '';

    protected string $modelClass = '';

    protected string $clusterClass = '';

    protected string $modelTable = '';

    protected string $smallVector = '';

    public static array $clusterCache = [];

    // Cache the unpacked vectors in a static variable - so it does not need to be unpacked each time
    public static $cache = [];

    // Cache of the dot products of all vectors calculated
    public static $dot = [];

    // Save the driver and cache time
    private static $driver = null;

    private static $cacheTime = false;

    private static $useClusteringDimensions = null;

    private static $dimensions = null;

    private static $reduceByMethod = null;

    private static bool $initialized = false;

    private static bool $isSqlLite = false;

    private array $vectorCache = [];

    static ?Collection $clusterListCache = null;

    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $config = VectorLiteConfig::getInstance();
        self::$driver = $config->cacheDriver;
        self::$cacheTime = $config->cacheTime;
        self::$dot = Cache::get(self::$driver)?->get('vector-lite-cache', []);
        self::$useClusteringDimensions = $config->useClusteringDimensions;
        self::$dimensions = $config->clusteringDimensions;
        self::$reduceByMethod = $config->reductionMethod;
        self::$initialized = true;
        self::$isSqlLite = $config->isSqlLite;
    }

    public function __construct(?VectorModel $model = null)
    {
        if ($model) {
            $config = VectorLiteConfig::getInstance();
            self::$isSqlLite = $config->isSqlLite;

            $this->clusterTable = $model->getClusterTableName();
            $this->clusterForeignKey = Str::singular($this->clusterTable).'_id';
            $this->matchColumn = Str::singular($this->clusterTable).'_match';
            $this->clusterModelName = $model->getClusterModelName();
            $this->modelClass = get_class($model);
            $this->maxClusterSize = $config->maxClusterSize;
            $this->modelTable = $model->getTable();
            $this->smallVector = $config->useClusteringDimensions ? '_small' : '';

            // e.g., if your model table is "vectors", this might be "vector_count"
            $this->countColumn = Str::singular($model->getTable()).'_count';
        }
    }

    public function processDelete(VectorModel $model)
    {
        // Delete the model from the cluster
        $clusterId = $model->{$this->clusterForeignKey};
        if ($clusterId) {
            /* @var class-string<\ThaKladd\VectorLite\Models\VectorModel> $this->clusterModelName */
            if(self::$isSqlLite ) {
                self::$clusterListCache[$clusterId]->decrement($this->countColumn);
            } else {
                $cluster = $this->clusterModelName::find($clusterId);
                if ($cluster) {
                    $cluster->decrement($this->countColumn);
                    $cluster->save();
                }
            }
        }
    }

    public function processUpdated(VectorModel $model): void
    {
        // Delete the model from the old cluster
        $oldClusterId = $model->{$this->clusterForeignKey};
        if ($oldClusterId) {
            if(self::$isSqlLite){
                self::$clusterListCache[$oldClusterId]->decrement($this->countColumn);
            } else {
                DB::table($this->clusterTable)
                    ->where('id', $oldClusterId)
                    ->decrement($this->countColumn);
            }

            DB::table($this->modelTable)
                ->where('id', $model->getKey())
                ->update([
                    $this->clusterForeignKey => null,
                    $this->matchColumn => null,
                ]);

            // Recalculate the cluster as new vector is created
            $this->processCreated($model);
        }

    }

    public function processCreated(VectorModel $model): void
    {
        if ($model->{$this->clusterForeignKey}) {
            return;
        }

        // This is called when a model is created - meaning,
        // it has an id and won't trigger again when updated
        DB::statement('PRAGMA journal_mode = WAL');
        DB::transaction(function () use ($model) {
            // Find the closest cluster by cosine similarity to the model’s vector.
            $bestCluster = $this->findBestCluster($model);
            if ($bestCluster) {
                // Add to best cluster because it may be next best match there
                $this->updateModelWithCluster($model, $bestCluster, $bestCluster->similarity);
                $this->cacheClusterOperation($bestCluster, 1);

                if ($bestCluster->{$this->countColumn} >= $this->maxClusterSize) {
                    // The cluster is full. Handle full-cluster logic.
                    $this->handleFullCluster($bestCluster);
                }
            } else {
                // No cluster found; create a new one and add it to model.
                $newCluster = $this->createNewCluster($model);
                $this->updateModelWithCluster($model, $newCluster, 1.0);
            }

            foreach (self::$clusterCache as $clusterId => $count) {
                if(self::$isSqlLite){
                    self::$clusterListCache[$clusterId]->increment($this->countColumn, $count);
                } else {
                    DB::table($this->clusterTable)
                        ->where('id', $clusterId)
                        ->increment($this->countColumn, $count);
                }
            }
            self::$clusterCache = [];
        });
    }

    private function findBestCluster(VectorModel $model): ?object
    {
        $clusterModelName = $this->clusterModelName;
        $vectorColumn = $this->clusterTable.'.vector'.$this->smallVector;
        $vectorColumnHash = $vectorColumn.'_hash';
        $modelVectorColumn = $model::vectorColumn().$this->smallVector;

        $modelVectorHash = $this->getCachedVector($model, $modelVectorColumn.'_hash');
        $modelVector = $this->getCachedVector($model, $modelVectorColumn);

        if(self::$isSqlLite) {
            /* @var class-string<\ThaKladd\VectorLite\Models\VectorModel> $clusterModelName */
            return $clusterModelName::selectRaw(
                    "{$this->clusterTable}.id, {$this->clusterTable}.{$this->countColumn}, COSIM_CACHE({$vectorColumnHash}, {$vectorColumn}, ?, ?) as similarity",
                    [$modelVectorHash, $modelVector]
                )
                ->orderBy('similarity', 'desc')
                ->first();
        } else {
            if (is_null(self::$clusterListCache)) {
                self::$clusterListCache = $clusterModelName::all()->keyBy('id');
            }

            $existingIds = self::$clusterListCache->pluck('id');
            $newClusters = $clusterModelName::whereNotIn('id', $existingIds)->get();

            if ($newClusters->isNotEmpty()) {
                self::$clusterListCache = self::$clusterListCache->merge($newClusters->keyBy('id'));
            }

            return self::$clusterListCache->findBestByVector($modelVector);
        }
    }

    private function getCachedVector(VectorModel $model, string $column): string
    {
        $key = $model->getKey().'_'.$column;

        if (! isset($this->vectorCache[$key])) {
            $this->vectorCache[$key] = $model->$column;
        }

        return $this->vectorCache[$key];
    }

    private function batchUpdateModels(array $updates): void
    {
        foreach ($updates as $update) {
            DB::table($this->modelTable)
                ->where('id', $update['id'])
                ->update([
                    $this->clusterForeignKey => $update['cluster_id'],
                    $this->matchColumn => $update['similarity'],
                ]);
        }
    }

    protected function createNewCluster(VectorModel $model): object
    {

        $vectorColumn = $model::vectorColumn();
        $clusterModelName = $this->clusterModelName;
        $attributes = [
            $vectorColumn => $this->getCachedVector($model, $vectorColumn),
            $vectorColumn.'_hash' => $this->getCachedVector($model, $vectorColumn.'_hash'),
            $vectorColumn.'_norm' => $this->getCachedVector($model, $vectorColumn.'_norm'),
        ];

        $smallVectors = [];
        if ($this->smallVector === '_small') {
            $smallCol = $vectorColumn.$this->smallVector;
            $smallVectors = [
                $smallCol => $this->getCachedVector($model, $smallCol),
                $smallCol.'_hash' => $this->getCachedVector($model, $smallCol.'_hash'),
                $smallCol.'_norm' => $this->getCachedVector($model, $smallCol.'_norm'),
            ];
        }

        /* @var class-string<VectorModel> $clusterModelName */
        $clusterModel = new $clusterModelName;
        $clusterModel->setRawAttributes([
            ...$attributes,
            ...$smallVectors,
            $this->countColumn => 1,
        ]);

        $clusterModel->save();

        if (self::$isSqlLite) {
            $clusterModelCollection = collect([$clusterModel]);
            if(self::$clusterListCache) {
                self::$clusterListCache = self::$clusterListCache->merge($clusterModelCollection);
            } else {
                self::$clusterListCache = $clusterModelCollection;
            }
            self::$clusterListCache = self::$clusterListCache->keyBy('id');
        }

        return $clusterModel;
    }

    protected function updateModelWithCluster(VectorModel $model, VectorModel $cluster, float $similarity): void
    {
        DB::table($this->modelTable)->where('id', $model->getKey())->update([
            $this->clusterForeignKey => $cluster->getKey(),
            $this->matchColumn => $similarity,
        ]);
    }

    protected function handleFullCluster($fullCluster): void
    {
        // The least matched model in the $fullCluster will be moved to its own cluster
        // Then take rest of the models in the cluster and find the best new match for them
        // Until the cluster is the best cluster for the model

        // Get all models in the cluster, sorted by lowest similarity first.
        $modelClassName = $this->modelClass;
        $vectorColumn = $modelClassName::vectorColumn();

        /** @var class-string<\ThaKladd\VectorLite\Models\VectorModel> $modelClassName */
        $clusterVectors = $modelClassName::query()
            ->select([
                'id',
                $this->matchColumn,
                $vectorColumn,
                $vectorColumn.'_hash',
                $vectorColumn.'_norm',
                $vectorColumn.$this->smallVector,
                $vectorColumn.$this->smallVector.'_hash',
                $vectorColumn.$this->smallVector.'_norm',
            ])
            ->where($this->clusterForeignKey, $fullCluster->id)
            ->orderBy($this->matchColumn)
            ->get();

        // Get the model that is the least similar in the cluster, make a new cluster, and assign it to model
        /** @var \ThaKladd\VectorLite\Models\VectorModel $leastSimilarModel */
        $leastSimilarModel = $clusterVectors->shift();
        $newCluster = $this->createNewCluster($leastSimilarModel);
        $this->cacheClusterOperation($fullCluster, -1);
        $this->updateModelWithCluster($leastSimilarModel, $newCluster, 1.0);

        $updates = [];

        // Iterate through the rest of the models in the cluster.
        /** @var \ThaKladd\VectorLite\Models\VectorModel $leastMatchModel */
        foreach ($clusterVectors as $leastMatchModel) {
            // For each model in the cluster, find the best matching cluster.
            $bestCluster = $this->findBestCluster($leastMatchModel);

            /** @var \ThaKladd\VectorLite\Models\VectorModel $bestCluster */
            // Round to 14 decimals because of what database column can hold
            $bestSimilarity = round($bestCluster->similarity, 14);
            $leastMatchModelSimilarity = round($leastMatchModel->{$this->matchColumn}, 14);

            if ($bestSimilarity === $leastMatchModelSimilarity) {
                // The best is still the current cluster; no re-assignment needed.
                break;
            }

            $idColumn = $leastMatchModel->getKey();
            $updates[] = [
                'id' => $leastMatchModel->$idColumn,
                'cluster_id' => $bestCluster->$idColumn,
                'similarity' => $bestCluster->similarity,
            ];

            $this->cacheClusterOperation($fullCluster, -1);
            $this->cacheClusterOperation($bestCluster, 1);
        }

        if (! empty($updates)) {
            $this->batchUpdateModels($updates);
        }
    }

    public function cacheClusterOperation($cluster, $add = 1): void
    {
        if (! isset(self::$clusterCache[$cluster->id])) {
            self::$clusterCache[$cluster->id] = 0;
        }
        self::$clusterCache[$cluster->id] += $add;
    }

    /**
     * Normalize a vector.
     */
    public static function normalize(array $vector): array
    {
        $sum = 0.0;

        // Compute the sum of squares.
        foreach ($vector as $i => $val) {
            $sum += $val * $val;
        }

        // Compute the norm.
        $norm = sqrt($sum);

        // Avoid division by zero.
        if ($norm == 0.0) {
            $norm = 1.0;
        }

        // Pre-calculate reciprocal to avoid repeated division.
        $inv = 1.0 / $norm;

        // Normalize each element.
        foreach ($vector as $i => $val) {
            $vector[$i] *= $inv;
        }

        return [$vector, $norm];
    }

    /**
     * Denormalize a vector using the original norm.
     */
    public static function denormalize(array $normalizedVector, float $originalNorm): array
    {
        foreach ($normalizedVector as $i => $val) {
            $normalizedVector[$i] *= $originalNorm;
        }

        return $normalizedVector;
    }

    /**
     * Denormalize a vector using the original norm from the binary.
     */
    public static function denormalizeFromBinary(string $binaryVector, float $originalNorm): array
    {
        return self::denormalize(self::binaryVectorToArray($binaryVector), $originalNorm);
    }

    /**
     * Normalize a vector to binary.
     */
    public static function normalizeToBinary(array $vector): array
    {
        [$normalVector, $norm] = self::normalize($vector);

        return [self::normalToBinary($normalVector), $norm];
    }

    /**
     * Normalized vector to binary.
     */
    public static function normalToBinary(array $normalVector): string
    {
        return pack('f*', ...$normalVector);
    }

    /**
     * Binary vector to array
     */
    public static function binaryVectorToArray(string $vector): array
    {
        return array_values(unpack('f*', $vector));
    }

    /**
     * Hashes the vector blob
     */
    public static function hashVectorBlob(array|string $vector): string
    {
        if (is_array($vector)) {
            [$vector, $norm] = VectorLite::normalizeToBinary($vector);
        }

        return self::hashText($vector);
    }

    /**
     * Hashes text
     */
    public static function hashText(string $text): string
    {
        return hash('xxh3', $text);
    }

    /**
     * Vector to array
     */
    public static function vectorToArray(array|string|VectorModel $vector): array
    {
        if (is_array($vector)) {
            return $vector;
        } elseif (is_string($vector)) {
            return self::binaryVectorToArray($vector);
        }

        return self::vectorToArray($vector->{$vector::vectorColumn()});
    }

    /**
     * Get the binary version of the vector
     */
    public static function vectorToBinary(array|string|VectorModel $vector): string
    {
        if (is_string($vector)) {
            return $vector;
        } elseif (is_object($vector)) {
            $vectorColumn = $vector::vectorColumn().self::smallVectorColumn($vector);

            return $vector->$vectorColumn;
        }

        [$vector, $norm] = self::normalizeToBinary($vector);

        return $vector;
    }

    /**
     * If a different clustering dimension is used,
     * the column column needs to be amended
     */
    public static function smallVectorColumn(?VectorModel $model): string
    {
        if ($model->isCluster()) {
            return config('vector-lite.use_clustering_dimensions', false) ? '_small' : '';
        }

        return '';
    }

    /**
     * When clusters use a smaller sized vector, this method reduces
     * the current vector down to the clusters smaller vector size
     * on the model and saves it
     */
    public static function reduceModelVector(VectorModel $model): array
    {
        $vectorColumn = $model::vectorColumn();
        $vectorColumnSmall = $model::{$vectorColumn.'_small'};

        $vectorToReduce = self::denormalizeFromBinary($model->$vectorColumn, $model->{$vectorColumn.'_norm'});
        if (self::$useClusteringDimensions) {
            // If small vector exists, return it
            if ($model->$vectorColumnSmall) {
                return self::denormalizeFromBinary($model->$vectorColumnSmall, $model->{$vectorColumnSmall.'_norm'});
            }

            // Reduce the vector if it is bigger than the smaller dimension size
            if (self::$dimensions < count($vectorToReduce)) {
                $reducedVector = self::$reduceByMethod->reduceVector($vectorToReduce, self::$dimensions);
                [$smallNormalizedVector, $norm] = self::normalize($reducedVector);
                $smallBinaryVector = self::normalToBinary($smallNormalizedVector);
                $smallBinaryVectorHash = self::hashVectorBlob($smallBinaryVector);
                self::$cache[$smallBinaryVectorHash] = $smallNormalizedVector;
                $model->update([
                    $vectorColumnSmall => $smallBinaryVector,
                    $vectorColumnSmall.'_hash' => $smallBinaryVectorHash,
                    $vectorColumnSmall.'_norm' => $norm,
                ]);

                return $reducedVector;
            }
        }

        return self::vectorToArray($vectorToReduce);
    }

    /**
     * Reduce the vector down to the clusters smaller vector size
     */
    public static function reduceVector(array|string $vector): array
    {
        self::init();
        $vectorToReduce = self::vectorToArray($vector);
        if (self::$useClusteringDimensions) {
            // Reduce the vector if it is bigger than the smaller dimension size
            if (self::$dimensions < count($vectorToReduce)) {
                return self::$reduceByMethod->reduceVector($vectorToReduce, self::$dimensions);
            }
        }

        return $vectorToReduce;
    }

    /**
     * Cosine similarity on binary vector with caching logic.
     */
    public static function cosineSimilarityBinaryCache(int|string|null $targetId, string $binaryTargetVector, int|string|null $queryId, string $binaryQueryVector): float
    {
        self::init();

        // Check if the dot product is already cached
        if (isset(self::$dot[$targetId][$queryId])) {
            return self::$dot[$targetId][$queryId];
        }

        // Cache the unpacked vectors for the current session
        if (! isset(self::$cache[$queryId])) {
            self::$cache[$queryId] = self::binaryVectorToArray($binaryQueryVector);
        }

        if (! isset(self::$cache[$targetId])) {
            self::$cache[$targetId] = self::binaryVectorToArray($binaryTargetVector);
        }

        self::$dot[$targetId][$queryId] = $dotProduct = self::dotProduct(self::$cache[$queryId], self::$cache[$targetId]);

        if (isset(self::$driver) && self::$driver) {
            Cache::store(self::$driver)->put('vector-lite-cache', self::$dot, self::$cacheTime);
        }

        return $dotProduct;
    }

    /**
     * Cosine similarity with caching logic.
     */
    public static function cosineSimilarityCache(int|string $targetId, array $targetVector, int|string $queryId, array $queryVector): float
    {
        self::init();

        // Check if the dot product is already cached
        if (isset(self::$dot[$targetId][$queryId])) {
            return self::$dot[$targetId][$queryId];
        }

        // Cache the unpacked vectors for the current session
        if (! isset(self::$cache[$queryId])) {
            self::$cache[$queryId] = $queryVector;
        }

        if (! isset(self::$cache[$targetId])) {
            self::$cache[$targetId] = $targetVector;
        }

        self::$dot[$targetId][$queryId] = $dotProduct = self::dotProduct(self::$cache[$queryId], self::$cache[$targetId]);

        if (isset(self::$driver) && self::$driver) {
            Cache::store(self::$driver)->put('vector-lite-cache', self::$dot, self::$cacheTime);
        }

        return $dotProduct;
    }

    /**
     * Cosine similarity on two models vectors.
     */
    public static function cosineSimilarityModelsCache(VectorModel $vectorModelA, VectorModel $vectorModelB): float
    {
        self::init();
        $vectorColumn = $vectorModelA::vectorColumn();
        if (self::$useClusteringDimensions && $vectorModelA->isCluster()) {
            $vectorColumn = $vectorColumn.'_small';
        }
        $vectorHashColumn = $vectorModelA::vectorColumn().'_hash';

        return self::cosineSimilarityBinaryCache($vectorModelA->$vectorHashColumn, $vectorModelA->$vectorColumn, $vectorModelB->$vectorHashColumn, $vectorModelB->$vectorColumn);
    }

    /**
     * Cosine similarity on model vector and vector.
     */
    public static function cosineSimilarityModelAndVectorCache(VectorModel $vectorModel, string|array $queryVector): float
    {
        self::init();
        $vectorColumn = $vectorModel::vectorColumn();
        if (self::$useClusteringDimensions && $vectorModel->isCluster()) {
            $queryVector = self::reduceVector($queryVector);
            $vectorColumn = $vectorColumn.'_small';
        }

        $targetVector = self::vectorToBinary($queryVector);
        $vectorHashColumn = $vectorModel::vectorColumn().'_hash';
        $targetHash = self::hashVectorBlob($targetVector);

        return self::cosineSimilarityBinaryCache($vectorModel->$vectorHashColumn, $vectorModel->$vectorColumn, $targetHash, $targetVector);
    }

    /**
     * Cosine similarity on binary vectors.
     */
    public static function cosineSimilarityBinary(string $binaryTargetVector, string $binaryQueryVector): float
    {
        $queryVector = unpack('f*', $binaryQueryVector);
        $targetVector = unpack('f*', $binaryTargetVector);

        return self::dotProduct($targetVector, $queryVector);
    }

    /**
     * Cosine similarity on model vector and vector.
     */
    public static function cosineSimilarityModels(VectorModel $vectorModelA, VectorModel $vectorModelB): float
    {
        $vectorColumn = $vectorModelA::vectorColumn();

        return self::cosineSimilarityBinary($vectorModelA->$vectorColumn, $vectorModelB->$vectorColumn);
    }

    /**
     * Cosine similarity on model vector and vector.
     */
    public static function cosineSimilarityModelAndVector(VectorModel $vectorModel, string|array $queryVector): float
    {
        self::init();
        $vectorColumn = $vectorModel::vectorColumn();
        if (self::$useClusteringDimensions && $vectorModel->isCluster()) {
            $queryVector = self::vectorToBinary(self::reduceVector($queryVector));
            $vectorColumn = $vectorColumn.'_small';
        }

        return self::cosineSimilarityBinary($vectorModel->$vectorColumn, $queryVector);
    }

    /**
     * Cosine similarity on array vectors.
     */
    public static function cosineSimilarity(array $targetVector, array $queryVector): float
    {
        return self::dotProduct($targetVector, $queryVector);
    }

    /**
     * Calculate the dot product of two vectors
     */
    public static function dotProduct(array $vectorA, array $vectorB): float
    {
        $amount = count($vectorA);

        $dotProduct = 0.0;
        foreach ($vectorA as $i => $vector) {
            $dotProduct += $vector * $vectorB[$i];
        }

        return $dotProduct;
    }
}
