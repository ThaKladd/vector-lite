<?php

namespace ThaKladd\VectorLite\QueryBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\VectorLite;

class VectorLiteQueryBuilder extends Builder
{
    protected int $clusterLimit = 1;

    public function clusterAmount(int $limit): static
    {
        $this->clusterLimit = $limit;

        return $this;
    }

    public function resolveModel(?VectorModel $model = null): VectorModel
    {
        $candidate = $model ?? $this->getModel();

        if (! $candidate instanceof VectorModel) {
            throw new \UnexpectedValueException(
                sprintf('Expected a %s, got %s', VectorModel::class, get_class($candidate))
            );
        }

        return $candidate;
    }

    /**
     * If a different clustering dimension is used,
     * the column column needs to be amended
     */
    private function smallClusterVectorColumn(): string
    {
        $useSmall = config('vector-lite.use_clustering_dimensions', false);

        return $useSmall ? '_small' : '';
    }

    /**
    * @phpstan-ignore-next-line
    */
    private function fixVectorSize(array|string $vector): string
    {
        $model = $this->resolveModel();
        if ($model->isCluster() && $this->smallClusterVectorColumn()) {
            $dimensions = config('vector-lite.clustering_dimensions');
            $reduceByMethod = config('vector-lite.reduction_method');

            return $reduceByMethod->reduceVector($vector, $dimensions);
        }

        return $vector;
    }

    /**
     * Gets the qualified key name, table.id
     */
    protected function qualifiedKeyName(?VectorModel $model = null): string
    {
        $resolvedModel = $this->resolveModel($model);

        return $resolvedModel->getTable().'.'.$resolvedModel->getKeyName();
    }

    /**
     * @return class-string<VectorModel>
     */
    public function resolveModelName(?VectorModel $model = null): string
    {
        return get_class($this->resolveModel($model));
    }

    /**
     * @return class-string<VectorModel>
     */
    public function getClusterModelName(?VectorModel $model = null): string
    {
        return $this->resolveModel($model)->getClusterModelName();
    }

    public function getClusterModel(?VectorModel $model = null): VectorModel
    {
        return new ($this->getClusterModelName($model));
    }

    public function isCluster(): bool
    {
        return str_ends_with($this->getModel()::class, 'Cluster');
    }

    /**
     * Filters the input to an array of keys
     */
    protected function filterToKeys(null|iterable|VectorModel $models): array
    {
        $models = Collection::wrap($models);

        return $models->map(function ($model) {
            if (! $model instanceof VectorModel) {
                throw new \InvalidArgumentException('Model to exclude has to be a VectorModel.');
            }

            return $model->getKey();
        })->filter()->all();
    }

    /**
     * Gets the hash column for the vector, including if the small vector is used
     * If there is no hash column, then use a table:id as the unique identifier
     */
    public function getVectorHashColumn(?VectorModel $model = null): string
    {
        $model = $this->resolveModel($model);
        $smallVectorColumn = $model->isCluster() ? $this->smallClusterVectorColumn() : '';
        $column = $model::$vectorColumn.'_hash';
        $smallColumn = $model::$vectorColumn.$smallVectorColumn.'_hash';

        if (Schema::hasColumn($model->getTable(), $smallColumn)) {
            return $smallColumn;
        } elseif (Schema::hasColumn($model->getTable(), $column)) {
            return $column;
        }

        return "'{$model->getTable()}:{$model->getKey()}'";
    }

    /**
     * Hashes the vector blob
     */
    public static function hashVectorBlob(null|array|string $vector)
    {
        if (is_array($vector)) {
            [$vector, $norm] = VectorLite::normalizeToBinary($vector);
        }

        return hash('xxh3', $vector);
    }

    /**
     * Get the the Cosine Similarity method to use in the query
     * The method needs one argument for the binding,
     * that is the same vector that is passed here
     */
    private function getCosimMethod(null|array|string $vector): string
    {
        // TODO: If cluster -> if use small -> make vector small
        $model = $this->resolveModel();
        $vectorColumn = "{$model->getTable()}.{$model::$vectorColumn}";
        if ($model->useCache) {
            $hashColumn = $this->getVectorHashColumn($model);
            $hashed = self::hashVectorBlob($vector);

            return "COSIM_CACHE({$hashColumn}, {$vectorColumn}, '{$hashed}', ?)";
        } else {
            return "COSIM({$vectorColumn}, ?)";
        }
    }

    public function clusterIdsByVector(string $vector): static
    {
        // TODO: If cluster -> if use small -> make vector small
        $clusterModel = $this->getClusterModel();
        $clusterModelName = $this->getClusterModelName();
        $ids = $clusterModelName::searchBestByVector($vector, $this->clusterLimit)->pluck('id')->toArray();
        if (empty($ids)) {
            $this->whereRaw('1 = 0');
        }
        if (count($ids) === 1) {
            $this->where($clusterModel->getClusterForeignKey(), $ids[0]);
        }

        $this->whereIn($clusterModel->getClusterForeignKey(), $ids);
        return $this;
    }

    /**
     * Search for the best clusters based on a vector.
     * TODO: This could be done via clusters relationship?
     * But, There is need for
     * 1. Get best cluster for the vector
     * 2. Get the best cluster for the vector on the model -> but the not static
     */
    public function bestClustersByVector(string $vector, int $amount = 1): Collection
    {
        return $this->getClusterModelName()::searchBestByVector($vector, $amount);
    }

    /**
     * Search for the best clusters based on current model
     */
    public function bestClusters(int $amount = 1): Collection
    {
        // TODO: If cluster -> if use small -> use _small vector column
        $clusterClass = $this->getClusterModelName();

        return $clusterClass::searchBestByVector($this->{$clusterClass::$vectorColumn}, $amount);
    }

    /**
     * Add a select clause that computes cosine similarity.
     */
    public function selectSimilarity($vector): static
    {
        // TODO: If cluster -> if use small -> make vector small
        $model = $this->resolveModel();
        $cosimMethodCall = $this->getCosimMethod($vector);

        $this->selectRaw("{$model->getTable()}.*, {$cosimMethodCall} as {$model::$similarityAlias}", [$vector]);
        return $this;
    }

    /**
     * Find the best match based on vector model using cosine similarity.
     *
     * @return \ThaKladd\VectorLite\Models\VectorModel|null
     */
    public function findBestByVectorModel(VectorModel $model): ?VectorModel
    {
        /** @var VectorModel|null $result */
        $result = $this->bestByVector($model)->first();
        return $result;
    }

    /**
     * Find the best match based on cosine similarity.
     *
     * @return \ThaKladd\VectorLite\Models\VectorModel|null
     */
    public function findBestByVector(null|array|string $vector = null): ?VectorModel
    {
        // TODO: If cluster -> if use small -> make vector small
        /** @var VectorModel|null $result */
        $result = $this->bestByVector($vector)->first();
        return $result;
    }

    /**
     * Search for the best matches using vector model based on cosine similarity.
     */
    public function searchBestByVectorModel(VectorModel $model, ?int $limit = null): Collection
    {
        return $this->bestByVector($model, $limit)->get();
    }

    /**
     * Search for the best matches based on cosine similarity.
     */
    public function searchBestByVector(null|array|string $vector = null, ?int $limit = null): Collection
    {
        // TODO: If cluster -> if use small -> make vector small
        return $this->bestByVector($vector, $limit)->get();
    }

    public function bestByVectorModel(VectorModel $model, ?int $limit = null): static
    {
        $this->withoutModels($model)->bestByVector($model->$model::$vectorColumn, $limit);
        return $this;
    }

    public function bestByVector(null|array|string $vector = null, ?int $limit = null): static
    {
        // TODO: If cluster -> if use small -> make vector small
        if (is_array($vector)) {
            [$vector, $norm] = VectorLite::normalizeToBinary($vector);
        }

        $model = $this->resolveModel();
        $cosimMethodCall = $this->getCosimMethod($vector);

        $this->selectRaw("{$model->getTable()}.*, {$cosimMethodCall} as {$model::$similarityAlias}", [$vector])
            ->orderByRaw("{$model::$similarityAlias} DESC");

        if($model->getKey()) {
            $this->withoutModels($model);
        }

        if ($limit) {
            $this->limit($limit);
        }

        return $this;
    }

    /**
     * Add a where clause based on cosine similarity.
     */
    public function whereVector(null|array|string $vector = null, string $operator = '>', float $threshold = 0.0): static
    {
        // TODO: If cluster -> if use small -> make vector small
        // Validate the operator to avoid SQL injection.
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!='];
        if (! in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [$operator] provided.");
        }
        $cosimMethodCall = $this->getCosimMethod($vector);

        $this->whereRaw("$cosimMethodCall $operator ?", [$vector, $threshold]);

        return $this;
    }

    /**
     * Add a where clause based on cosine similarity where the vector is between two values.
     */
    public function whereVectorBetween(null|array|string $vector = null, float $min = 0.0, float $max = 1.0): static
    {
        // TODO: If cluster -> if use small -> make vector small
        $cosimMethodCall = $this->getCosimMethod($vector);

        $this->whereRaw("$cosimMethodCall BETWEEN ? AND ?", [$vector, $min, $max]);
        return $this;
    }

    /**
     * Add a having clause based on cosine similarity.
     */
    public function havingVector(null|string|array $vector = null, string $operator = '>', float $threshold = 0.0): static
    {
        // TODO: If cluster -> if use small -> make vector small
        // Validate the operator.
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!='];
        if (! in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [$operator] provided.");
        }

        $model = $this->resolveModel();
        $vector = $vector ?? $model::$similarityAlias;

        // Determine if the similarity column is already part of the query.
        $hasSimilarityColumn = false;
        if ($vector === $model::$similarityAlias || (! empty($this->columns) && in_array($model::$similarityAlias, $this->columns))) {
            $hasSimilarityColumn = true;
        }

        if ($hasSimilarityColumn) {
            // If the similarity column is already selected, use it in the HAVING clause.
            $this->having($model::$similarityAlias, $operator, $threshold);
            return $this;
        }

        // Otherwise, compute the similarity on the fly.
        // (Note: Using HAVING RAW here because operators and bindings must be inline.)
        $cosimMethodCall = $this->getCosimMethod($vector);

        $this->havingRaw("$cosimMethodCall $operator ?", [$vector, $threshold]);
        return $this;
    }

    /**
     * Order the query by cosine similarity.
     */
    public function orderBySimilarity($vector, string $direction = 'desc'): static
    {
        // TODO: If cluster -> if use small -> make vector small
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $cosimMethodCall = $this->getCosimMethod($vector);

        $this->orderByRaw("$cosimMethodCall $direction", [$vector]);
        return $this;
    }

    /**
     * Order the query by cosine similarity to a model.
     */
    public function orderBySimilarityToModel(VectorModel $model, string $direction = 'desc'): static
    {
        $this->orderBySimilarity($model->{$model::$vectorColumn}, $direction);
        return $this;
    }

    /**
     * Exclude given models from the query results.
     */
    public function withoutModels(null|iterable|VectorModel $models): static
    {

        $keys = $this->filterToKeys($models);

        if (count($keys) > 0) {
            $this->whereNotIn($this->qualifiedKeyName(), $keys);
        }

        return $this;
    }

    /**
     * Force includes models in addition to the rest of the query,
     * so that they are always part of the result
     */
    public function orIncludeModels(null|iterable|VectorModel $models): static
    {
        $keys = $this->filterToKeys($models);

        if (count($keys) > 0) {
            $this->orWhereIn($this->qualifiedKeyName(), $keys);
        }

        return $this;
    }

    /**
     * Includes only the models in the arguments
     */
    public function includeModels(null|iterable|VectorModel $models): static
    {
        $keys = $this->filterToKeys($models);

        if (count($keys) > 0) {
            $this->whereIn($this->qualifiedKeyName(), $keys);
        }

        return $this;
    }

    /**
     * Exclude the current model from the query.
     */
    public function withoutSelf(): static
    {
        $this->withoutModels($this->resolveModel());
        return $this;
    }

    /**
     * Include the current model from the query.
     */
    public function includeSelf(): static
    {
        $this->orWhere($this->qualifiedKeyName(), '=', $this->resolveModel()->getKey());
        return $this;
    }
}
