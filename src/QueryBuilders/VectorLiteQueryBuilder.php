<?php

namespace ThaKladd\VectorLite\QueryBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use ThaKladd\VectorLite\Collections\VectorModelCollection;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Support\SqlValidator;
use ThaKladd\VectorLite\VectorLite;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class VectorLiteQueryBuilder extends Builder
{
    protected static ?bool $useClusterCache = null;

    protected static array $hashColumnExistsCache = [];

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
    protected function preparedBinaryVector(array $vectorArray): string
    {
        $resolvedModel = $this->resolveModel();
        $smallVectorArray = $resolvedModel->isCluster() ? VectorLite::reduceVector($vectorArray) : $vectorArray;

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
     * An explicit $column bypasses the model's configured vector column
     * (and its clustering "_small" suffix), used by the AI-SDK-style
     * per-call-column methods (whereVectorSimilarTo, etc.).
     */
    protected function qualifiedVectorColumnName(?VectorModel $model = null, ?string $column = null): string
    {
        $resolvedModel = $this->resolveModel($model);

        return $resolvedModel->getTable().'.'.$this->vectorColumnNameWithSmall($resolvedModel, $column);
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
     *
     * @param  null|iterable<VectorModel>|VectorModel  $models
     * @return array<int|string>
     */
    protected function filterToKeys(null|iterable|VectorModel $models): array
    {
        return VectorModelCollection::wrap($models)->pluck('id')->all();
    }

    /**
     * Get the column name including the _small version if applicable.
     * An explicit $column is used as-is (no clustering "_small" suffix),
     * since it names a specific physical column rather than "the" model's
     * configured vector column.
     */
    private function vectorColumnNameWithSmall(?VectorModel $model = null, ?string $column = null): string
    {
        if ($column !== null) {
            return $column;
        }

        $resolvedModel = $this->resolveModel($model);
        $suffix = $resolvedModel->isCluster() ? VectorLite::smallVectorColumn($resolvedModel) : '';

        return $resolvedModel::vectorColumn().$suffix;
    }

    /**
     * Gets the hash column for the vector, including if the small vector is used
     * If there is no hash column, then use a table:id as the unique identifier
     */
    public function getVectorHashColumn(?VectorModel $model = null, ?string $column = null): string
    {
        $resolvedModel = $this->resolveModel($model);
        $table = $resolvedModel->getTable();

        $hashColumn = $this->vectorColumnNameWithSmall($resolvedModel, $column).'_hash';
        $key = "$table.$hashColumn";

        if (! isset(self::$hashColumnExistsCache[$key])) {
            self::$hashColumnExistsCache[$key] = Schema::hasColumn($table, $hashColumn);
        }

        return self::$hashColumnExistsCache[$key] ? $hashColumn : "'{$table}:{$resolvedModel->getKey()}'";
    }

    /**
     * Select vector with similarity alias
     */
    protected function selectWithSimilarity(array $resolvedVector): void
    {
        $model = $this->resolveModel();
        $cosimMethodCall = $this->getCosimMethod($resolvedVector);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
        $this->selectRaw("{$model->getTable()}.*, {$cosimMethodCall} as {$model::similarityAlias()}", [$binaryVector]);
        $this->similarityColumnSelected = true;
    }

    /**
     * Get the the Cosine Similarity method to use in the query
     * The method needs one argument for the binding,
     * that is the same vector that is passed here
     */
    protected function getCosimMethod(array $resolvedVector, ?string $column = null): string
    {
        $vectorColumn = $this->qualifiedVectorColumnName(null, $column);
        if ($this->useCachedCosim()) {

            $hashColumn = $this->getVectorHashColumn(null, $column);
            $binaryVector = $this->preparedBinaryVector($resolvedVector);
            $hashedSmallVector = VectorLite::hashVectorBlob($binaryVector);

            return "COSIM_CACHE({$hashColumn}, {$vectorColumn}, '{$hashedSmallVector}', ?)";
        } else {
            return "COSIM({$vectorColumn}, ?)";
        }
    }

    /**
     * Resolves array|string embedding input for the AI-SDK-style methods
     * below. Unlike the rest of this query builder (whereVector(),
     * bestByVector(), etc.), where a string means an already-packed binary
     * vector, a string here is treated as natural-language text to embed —
     * matching Laravel's AI SDK whereVectorSimilarTo() semantics.
     */
    protected function resolveEmbeddingInput(array|string $embedding): array
    {
        if (is_array($embedding)) {
            return $embedding;
        }

        return app(EmbeddingService::class)->createEmbedding($embedding, config('vector-lite.default_dimensions'));
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
        $clusterModelName = $resolvedModel->getClusterClass();
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
        return $this->resolveModel()->getClusterClass()::searchBestByVector($vector, $amount, $excludeInputModel);
    }

    /**
     * Find the  best clusters based on a vector.
     */
    public function findBestClustersByVector(array|string|VectorModel $vector): ?VectorModel
    {
        /** @var VectorModel|null $model */
        $model = $this->resolveModel()
            ->getClusterClass()::searchBestByVector($vector, 1, false)
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
        $this->orderBySimilarity($resolvedVector);

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
        SqlValidator::validateOperator($operator);
        $resolvedVector = $this->resolveVector($vector);
        $cosimMethodCall = $this->getCosimMethod($resolvedVector);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
        $this->whereRaw("$cosimMethodCall $operator CAST(? AS REAL)", [$binaryVector, $threshold]);

        return $this;
    }

    /**
     * Add a where clause based on cosine similarity where the vector is between two values.
     */
    public function whereVectorBetween(array|string|VectorModel $vector, float $min = 0.0, float $max = 1.0): static
    {
        $resolvedVector = $this->resolveVector($vector);
        $cosimMethodCall = $this->getCosimMethod($resolvedVector);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
        $this->whereRaw("$cosimMethodCall BETWEEN CAST(? AS REAL) AND CAST(? AS REAL)", [$binaryVector, $min, $max]);

        return $this;
    }

    /**
     * Filter to rows whose cosine similarity to the given embedding is at
     * least $minSimilarity. Mirrors Laravel's AI SDK whereVectorSimilarTo().
     * Unlike whereVector(), a string $embedding is text to embed, not a
     * packed binary vector - see resolveEmbeddingInput().
     */
    public function whereVectorSimilarTo(string $column, array|string $embedding, float $minSimilarity = 0.0): static
    {
        $resolvedVector = $this->resolveEmbeddingInput($embedding);
        $cosimMethodCall = $this->getCosimMethod($resolvedVector, $column);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
        $this->whereRaw("$cosimMethodCall >= CAST(? AS REAL)", [$binaryVector, $minSimilarity]);

        return $this;
    }

    /**
     * Select the cosine distance (1 - cosine similarity) to the given
     * embedding as $as. Mirrors Laravel's AI SDK selectVectorDistance().
     * vector-lite only supports cosine distance, not L2/inner-product.
     */
    public function selectVectorDistance(string $column, array|string $embedding, string $as = 'distance'): static
    {
        $resolvedVector = $this->resolveEmbeddingInput($embedding);
        $cosimMethodCall = $this->getCosimMethod($resolvedVector, $column);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
        $this->selectRaw("(1 - {$cosimMethodCall}) as {$as}", [$binaryVector]);

        return $this;
    }

    /**
     * Filter to rows whose cosine distance (1 - cosine similarity) to the
     * given embedding is less than $maxDistance. Mirrors Laravel's AI SDK
     * whereVectorDistanceLessThan().
     */
    public function whereVectorDistanceLessThan(string $column, array|string $embedding, float $maxDistance): static
    {
        $resolvedVector = $this->resolveEmbeddingInput($embedding);
        $cosimMethodCall = $this->getCosimMethod($resolvedVector, $column);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
        $this->whereRaw("(1 - {$cosimMethodCall}) < CAST(? AS REAL)", [$binaryVector, $maxDistance]);

        return $this;
    }

    /**
     * Order by cosine distance (1 - cosine similarity) to the given
     * embedding, closest first. Mirrors Laravel's AI SDK orderByVectorDistance().
     */
    public function orderByVectorDistance(string $column, array|string $embedding): static
    {
        $resolvedVector = $this->resolveEmbeddingInput($embedding);
        $cosimMethodCall = $this->getCosimMethod($resolvedVector, $column);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
        $this->orderByRaw("(1 - {$cosimMethodCall}) ASC", [$binaryVector]);

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
        SqlValidator::validateOperator($operator);

        $model = $this->resolveModel();
        if ($this->shouldUseSimilarityAlias($vector)) {
            // If the similarity column is already selected, use it in the HAVING clause.
            // CAST is required: SQLite compares a bound float (bound as TEXT storage
            // class by PDO) against the alias's numeric value using storage-class
            // ordering (numeric always < text) unless explicitly cast to REAL - the
            // fluent having() can't express that, so this uses havingRaw() instead.
            $this->havingRaw("{$model::similarityAlias()} {$operator} CAST(? AS REAL)", [$threshold]);

            return $this;
        }

        $resolvedVector = $this->resolveVector($vector);

        // Otherwise, compute the similarity on the fly.
        // (Note: Using HAVING RAW here because operators and bindings must be inline.)
        $cosimMethodCall = $this->getCosimMethod($resolvedVector);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
        $this->havingRaw("$cosimMethodCall $operator CAST(? AS REAL)", [$binaryVector, $threshold]);

        return $this;
    }

    /**
     * Order the query by cosine similarity.
     */
    public function orderBySimilarity(array|string|null|VectorModel $vector = null, string $direction = 'DESC'): static
    {
        $direction = strtoupper($direction);
        SqlValidator::validateDirection($direction);

        $model = $this->resolveModel();
        if ($this->shouldUseSimilarityAlias($vector)) {
            // If the similarity column is already selected, use it in the ORDER BY clause.
            $this->orderBy($model::similarityAlias(), $direction);

            return $this;
        }

        $resolvedVector = $this->resolveVector($vector);
        // Otherwise, compute the similarity on the fly.
        // (Note: Using ORDER BY RAW here because operators and bindings must be inline.)
        $cosimMethodCall = $this->getCosimMethod($resolvedVector);
        $binaryVector = $this->preparedBinaryVector($resolvedVector);
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
