<?php

namespace ThaKladd\VectorLite\QueryBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use ThaKladd\VectorLite\Collections\VectorModelCollection;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\VectorLite;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends \Illuminate\Database\Eloquent\Builder<TModel>
 */
class VectorLiteQueryBuilder extends Builder
{
    protected static ?bool $useClusterCache = null;

    protected bool $similarityColumnSelected = false;

    protected bool $deferClusterFilter = false;

    protected int $clusterLimit = 1;

    /**
     * Set the cluster amount limit to work with
     */
    private function clusterAmount(int $limit): static
    {
        $this->clusterLimit = $limit;

        return $this;
    }

    /**
     * Resolves the model, if there is an instance or not
     */
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
     * Resolves the vector as array and excludes if object and setting set
     */
    protected function resolveVector(array|string|VectorModel $vector, bool $excludeInputModel = true): array
    {
        $vectorArray = VectorLite::vectorToArray($vector);
        if (is_object($vector) && $excludeInputModel) {
            $this->withoutModels($vector);
        }

        return $vectorArray;
    }

    /**
     * Get the setting for using cached cosim method
     */
    protected function useCachedCosim()
    {
        if (is_null(self::$useClusterCache)) {
            self::$useClusterCache = config('vector-lite.use_cached_cosim', false);
        }

        return self::$useClusterCache;
    }

    /**
     * Get the binary version of the vector from multiple inputs
     */
    protected function preparedBinaryVector(string|array $vector): string
    {
        $resolvedModel = $this->resolveModel();
        $vectorArray = VectorLite::vectorToArray($vector);
        $smallVectorArray = $resolvedModel->isCluster() ? VectorLite::reduceVector($this->resolveModel(), $vectorArray) : $vectorArray;

        return VectorLite::vectorToBinary($smallVectorArray);
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
     * Gets the qualified vector column name, table.vector
     */
    protected function qualifiedVectorColumnName(?VectorModel $model = null): string
    {
        $resolvedModel = $this->resolveModel($model);
        $smallVectorColumn = $resolvedModel->isCluster() ? VectorLite::smallVectorColumn($resolvedModel) : '';

        return $resolvedModel->getTable().'.'.$resolvedModel::$vectorColumn.$smallVectorColumn;
    }

    /**
     * Resolves the model, returns the class name of the VectorModel type
     *
     * @return class-string<VectorModel>
     */
    public function resolveModelName(?VectorModel $model = null): string
    {
        return get_class($this->resolveModel($model));
    }

    /**
     * Filters the input to an array of keys
     */
    protected function filterToKeys(null|iterable|VectorModel $models): array
    {
        $models = VectorModelCollection::wrap($models);

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
        $resolvedModel = $this->resolveModel($model);
        $smallVectorColumn = $resolvedModel->isCluster() ? VectorLite::smallVectorColumn($resolvedModel) : '';
        $vectorHashColumn = $resolvedModel::$vectorColumn.$smallVectorColumn.'_hash';
        if (Schema::hasColumn($resolvedModel->getTable(), $vectorHashColumn)) {
            return $vectorHashColumn;
        }

        return "'{$resolvedModel->getTable()}:{$resolvedModel->getKey()}'";
    }

    /**
     * Validate the operator to avoid SQL injection.
     */
    protected function verifyOperator(string $operator): void
    {
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!='];
        if (! in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [$operator] provided.");
        }
    }

    /**
     * Validate the direction to avoid SQL injection or mistakes.
     */
    protected function verifyDirection(string $direction): void
    {
        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid direction [$direction] provided.");
        }
    }

    /**
     * Select vector with similarity alias
     */
    protected function selectWithSimilarity(array $resolvedVector): void
    {
        $model = $this->resolveModel();
        $cosimMethodCall = $this->getCosimMethod($resolvedVector);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
        $this->selectRaw("{$model->getTable()}.*, {$cosimMethodCall} as {$model::$similarityAlias}", [$binaryVector]);
        $this->similarityColumnSelected = true;
    }

    /**
     * Get the the Cosine Similarity method to use in the query
     * The method needs one argument for the binding,
     * that is the same vector that is passed here
     */
    protected function getCosimMethod(array|string $vector): string
    {
        $vectorColumn = $this->qualifiedVectorColumnName();
        if ($this->useCachedCosim()) {

            $hashColumn = $this->getVectorHashColumn();
            $binaryVector = $this->preparedBinaryVector($vector);
            $hashedSmallVector = VectorLite::hashVectorBlob($binaryVector);

            return "COSIM_CACHE({$hashColumn}, {$vectorColumn}, '{$hashedSmallVector}', ?)";
        } else {
            return "COSIM({$vectorColumn}, ?)";
        }
    }

    /**
     * Finds the clusters to reduce the scope of the query
     * based on the limit of clusters to get.
     * More clusters equals wider result,
     * possibly better, but slower.
     */
    public function filterByClosestClusters(int $amount = 1): static
    {
        $this->deferClusterFilter = true;
        $this->clusterAmount($amount);

        return $this;
    }

    /**
     * The actual work of the filterByClosestClusters() that is deferred
     */
    private function searchClusters(null|array|string|VectorModel $vector = null)
    {
        $resolvedModel = $this->resolveModel();
        $clusterModelName = $resolvedModel->getClusterModelName();
        $ids = $clusterModelName::searchBestByVector($vector, $this->clusterLimit)->pluck('id')->toArray();
        if (empty($ids)) {
            $this->whereRaw('1 = 0');
        } else {
            $clusterModel = $resolvedModel->getClusterModel();
            $this->whereIn($clusterModel->getClusterForeignKey(), $ids);
        }

        return $this;
    }

    /**
     * Search for the best clusters based on a vector.
     * TODO: This could be done via clusters relationship?
     * But, There is need for
     * 1. Get best cluster for the vector
     * 2. Get the best cluster for the vector on the model -> but the not static
     */
    public function getBestClustersByVector(array|string|VectorModel $vector, int $amount = 1, bool $excludeInputModel = true): VectorModelCollection
    {
        return $this->resolveModel()->getClusterModelName()::searchBestByVector($vector, $amount, $excludeInputModel);
    }

    /**
     * Find the  best clusters based on a vector.
     */
    public function findBestClustersByVector(array|string|VectorModel $vector): ?VectorModel
    {
        /** @var VectorModel|null $model */
        $model = $this->resolveModel()
            ->getClusterModelName()::searchBestByVector($vector, 1, false)
            ->first();

        return $model;
    }

    /**
     * Add a select clause that computes cosine similarity.
     */
    public function selectSimilarity(array|string|VectorModel $vector, bool $excludeInputModel = true): static
    {
        $resolvedVector = $this->resolveVector($vector, $excludeInputModel);
        $this->selectWithSimilarity($resolvedVector);

        return $this;
    }

    /**
     * Scope the query to order by cosine similarity to the given vector,
     * optionally limiting to the top N best matches.
     */
    public function bestByVector(array|string|VectorModel $vector, ?int $limit = null, bool $excludeInputModel = true): static
    {
        if ($this->deferClusterFilter) {
            $this->searchClusters($vector);
        }

        $model = $this->resolveModel();
        $resolvedVector = $this->resolveVector($vector, $excludeInputModel);

        $this->selectWithSimilarity($resolvedVector);
        $this->orderBySimilarity();

        if ($model->getKey()) {
            $this->withoutModels($model);
        }

        if ($limit) {
            $this->limit($limit);
        }

        return $this;
    }

    /**
     * Find the best match based on cosine similarity.
     */
    public function findBestByVector(array|string|VectorModel $vector, bool $excludeInputModel = true): ?VectorModel
    {
        /** @var VectorModel|null $result */
        $result = $this->bestByVector($vector, 1, $excludeInputModel)->first();

        return $result;
    }

    /**
     * Search for the best matches based on cosine similarity.
     *
     * @return VectorModelCollection<VectorModel>
     */
    public function searchBestByVector(array|string|VectorModel $vector, ?int $limit = null, bool $excludeInputModel = true): VectorModelCollection
    {
        return $this->bestByVector($vector, $limit, $excludeInputModel)->get();
    }

    /**
     * Add a where clause based on cosine similarity.
     */
    public function whereVector(array|string|VectorModel $vector, string $operator = '>', float $threshold = 0.0): static
    {
        $this->verifyOperator($operator);
        $cosimMethodCall = $this->getCosimMethod($vector);
        $binaryVector = $this->preparedBinaryVector($vector);
        $this->whereRaw("$cosimMethodCall $operator ?", [$binaryVector, $threshold]);

        return $this;
    }

    /**
     * Add a where clause based on cosine similarity where the vector is between two values.
     */
    public function whereVectorBetween(array|string|VectorModel $vector, float $min = 0.0, float $max = 1.0): static
    {
        $cosimMethodCall = $this->getCosimMethod($vector);
        $binaryVector = $this->preparedBinaryVector($vector);
        $this->whereRaw("$cosimMethodCall BETWEEN ? AND ?", [$binaryVector, $min, $max]);

        return $this;
    }

    protected function shouldUseSimilarityAlias(array|string|null|VectorModel $vector): bool
    {
        return is_null($vector) || $this->similarityColumnSelected;
    }

    /**
     * Add a having clause based on cosine similarity.
     */
    public function havingSimilarity(array|string|null|VectorModel $vector = null, string $operator = '>', float $threshold = 0.0): static
    {
        $this->verifyOperator($operator);

        $model = $this->resolveModel();
        if ($this->shouldUseSimilarityAlias($vector)) {
            // If the similarity column is already selected, use it in the HAVING clause.
            $this->having($model::$similarityAlias, $operator, $threshold);

            return $this;
        }

        $vector = $this->resolveVector($vector);

        // Otherwise, compute the similarity on the fly.
        // (Note: Using HAVING RAW here because operators and bindings must be inline.)
        $cosimMethodCall = $this->getCosimMethod($vector);
        $binaryVector = $this->preparedBinaryVector($vector);
        $this->havingRaw("$cosimMethodCall $operator ?", [$binaryVector, $threshold]);

        return $this;
    }

    /**
     * Order the query by cosine similarity.
     */
    public function orderBySimilarity(array|string|null|VectorModel $vector = null, string $direction = 'DESC'): static
    {
        $direction = strtoupper($direction);
        $this->verifyDirection($direction);

        $model = $this->resolveModel();
        if ($this->shouldUseSimilarityAlias($vector)) {
            // If the similarity column is already selected, use it in the ORDER BY clause.
            $this->orderBy($model::$similarityAlias, $direction);

            return $this;
        }

        $vector = $this->resolveVector($vector);
        // Otherwise, compute the similarity on the fly.
        // (Note: Using ORDER BY RAW here because operators and bindings must be inline.)
        $cosimMethodCall = $this->getCosimMethod($vector);
        $binaryVector = $this->preparedBinaryVector($vector);
        $this->orderByRaw("$cosimMethodCall $direction", [$binaryVector]);

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
