<?php

namespace ThaKladd\VectorLite;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VectorLite {

    protected string $clusterTable;
    protected string $clusterModelName;
    protected string $clusterForeignKey;
    protected string $matchColumn;

    protected string $maxClusterSize;
    protected string $countColumn;
    protected string $modelClass;
    protected string $clusterClass;

    protected string $modelTable;

    public static array $clusterCache = [];

    public function __construct(?Model $model = null)
    {
        if($model) {
            $this->clusterTable = $model->getClusterTableName();
            $this->clusterForeignKey = Str::singular($this->clusterTable) . '_id';
            $this->matchColumn = Str::singular($this->clusterTable) . '_match';
            $this->clusterModelName = $model->getClusterModelName();
            $this->modelClass = get_class($model);
            $this->maxClusterSize = config('vector-lite.clusters_size', 500);
            $this->modelTable = $model->getTable();

            // e.g., if your model table is "vectors", this might be "vectors_count"
            $this->countColumn = $model->getTable() . '_count';
        }
    }

    public function processDelete(Model $model)
    {
        // Delete the model from the cluster
        $clusterId = $model->{$this->clusterForeignKey};
        if ($clusterId) {
            /* @var Model $this->clusterModelName */
            $cluster = $this->clusterModelName::find($clusterId);
            if ($cluster) {
                $cluster->decrement($this->countColumn);
                $cluster->save();
            }
        }
    }

    public function processUpdated(Model $model): void
    {
        // Delete the model from the old cluster
        $oldClusterId = $model->{$this->clusterForeignKey};
        if ($oldClusterId) {
            /* @var Model $this->clusterModelName */
            $oldCluster = $this->clusterModelName::find($oldClusterId);
            if ($oldCluster) {
                $oldCluster->decrement($this->countColumn);
                $oldCluster->save();
            }
            // Remove the cluster id from the model
            $model->{$this->clusterForeignKey} = null;
            $model->{$this->matchColumn} = null;
            $model->save();

            //Recalculate the cluster as new vector is created
            $model->processCreated($model);
        }

    }

    public function processCreated(Model $model): void
    {
        // This is called when a model is created - meaning,
        // it has an id and won't trigger again when updated
        DB::statement('PRAGMA journal_mode = WAL');
        DB::transaction(function () use ($model) {
            // Find the closest cluster by cosine similarity to the model’s vector.
            /** @var Model $this->model */
            $bestCluster = $this->clusterModelName::selectRaw(
                "{$this->clusterTable}.id, {$this->clusterTable}.{$this->countColumn}, COSIM_CACHE({$model->getVectorHashColumn()}, {$this->clusterTable}.vector, ?, ?) as similarity",
                [$model->{$model->vectorColumn . '_hash'}, $model->{$model->vectorColumn}]
            )->orderBy('similarity', 'desc')->first();

            if ($bestCluster) {
                $this->clusterClass = get_class($bestCluster);

                //Add to best cluster because it may be next best match there
                $this->updateModelWithCluster($model, $bestCluster, $bestCluster->similarity);
                $this->cacheClusterOpeartion($bestCluster, 1);

                if ($bestCluster->{$this->countColumn} >= $this->maxClusterSize) {
                    // The cluster is full. Handle full-cluster logic.
                    $this->handleFullCluster($bestCluster);
                }
            } else {
                // No cluster found; create a new one and add it to model.
                $newCluster = $this->createNewCluster($model, 1);
                $this->updateModelWithCluster($model, $newCluster, 1.0);
            }

            foreach (self::$clusterCache as $clusterId => $count) {
                DB::table($this->clusterTable)
                    ->where('id', $clusterId)
                    ->increment($this->countColumn, $count);
            }

            self::$clusterCache = [];
        });
    }

    protected function createNewCluster(Model $model): object
    {
        $clusterModelName = $this->clusterModelName;

        /* @var Model $clusterModelName */
        return $clusterModelName::create([
            'vector_raw' => $model->{$model->vectorColumn},
            $this->countColumn => 1,
        ]);
    }

    protected function updateModelWithCluster(Model $model, Model $cluster, float $similarity): void
    {
        DB::table($this->modelTable)->where('id', $model->id)->update([
            $this->clusterForeignKey => $cluster->id,
            $this->matchColumn => $similarity,
        ]);
    }

    protected function handleFullCluster($fullCluster): void
    {
        // The least matched model in the $fullCluster will be moved to its own cluster
        // Then take rest of the models in the cluster and find the best new match for them
        // Until the cluster is the best cluster for the model

        // Get all models in the cluster, sorted by lowest similarity first.
        /** @var Model $this->modelClass */
        $clusterVectors = $this->modelClass::query()
            ->where($this->clusterForeignKey, $fullCluster->id)
            ->where($this->matchColumn, '<', 1.0)
            ->orderBy($this->matchColumn)
            ->get();

        // Get the model that is the least similar in the cluster, and make a new cluster and assign it to model
        $leastSimilarModel = $clusterVectors->shift(); //Remove the first out from the collection
        $newCluster = $this->createNewCluster($leastSimilarModel, 1);
        $this->cacheClusterOpeartion($fullCluster, -1);
        $this->updateModelWithCluster($leastSimilarModel, $newCluster, 1.0);

        // Iterate through the rest of the models in the cluster.
        foreach ($clusterVectors as $leastMatchModel) {
            // For each model in the cluster, find the best matching cluster.
            /** @var Model $this->clusterClass */
            $bestCluster = $this->clusterClass::selectRaw(
                "{$this->clusterTable}.id, {$this->clusterTable}.{$this->countColumn}, COSIM_CACHE({$fullCluster->getVectorHashColumn()}, {$this->clusterTable}.vector, ?, ?) as similarity",
                [$leastMatchModel->{$leastMatchModel->vectorColumn . '_hash'}, $leastMatchModel->{$leastMatchModel->vectorColumn}]
            )->orderBy('similarity', 'desc')->get()->first();

            //Round to 14 decimals because of what database column can hold
            $bestSimilarity = round($bestCluster->similarity, 14);
            $leastMatchModelSimilarity = round($leastMatchModel->{$this->matchColumn}, 14);

            if ($bestCluster && $bestSimilarity === $leastMatchModelSimilarity) {
                // The best is still the current cluster; no re-assignment needed.
                break;
            }

            $this->cacheClusterOpeartion($fullCluster, -1);
            $this->updateModelWithCluster($leastMatchModel, $bestCluster, $bestCluster->similarity);
            $this->cacheClusterOpeartion($bestCluster, 1);
        }
    }

    public function cacheClusterOpeartion($cluster, $add = 1) {
        if (!isset(self::$clusterCache[$cluster->id])) {
            self::$clusterCache[$cluster->id] = 0;
        }
        self::$clusterCache[$cluster->id] += $add;
    }

    /**
     * Normalize a vector.
     */
    public static function normalize(array $vector): array
    {
        $n = count($vector);
        $sum = 0.0;

        // Compute the sum of squares.
        for ($i = 0; $i < $n; $i++) {
            $val = $vector[$i];
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
        for ($i = 0; $i < $n; $i++) {
            $vector[$i] *= $inv;
        }

        return $vector;
    }

    /**
     * Normalize a vector to binary.
     */
    public static function normalizeToBinary(array $vector): string
    {
        return pack('f*', ...self::normalize($vector));
    }

}
