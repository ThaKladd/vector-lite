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

    public function clusterAmount($limit)
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

    private function smallClusterVectorColumn(): string
    {
        $useSmall = config('vector-lite.use_clustering_dimensions', false);

        return $useSmall ? '_small' : '';
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

    public function getVectorHashColumn(?VectorModel $model = null, bool $useSmall = false): ?string
    {
        $model = $this->resolveModel($model);
        $smallVectorColumn = $useSmall ? '_small' : '';
        $columnExists = Schema::hasColumn($model->getTable(), $model::$vectorColumn.$smallVectorColumn.'_hash');

        return $columnExists ? $model->getTable().'.'.$model::$vectorColumn.'_hash' : null;
    }

    public static function hashVectorBlob(null|array|string $vector)
    {
        if (is_array($vector)) {
            $vector = VectorLite::normalizeToBinary($vector);
        }

        return hash('xxh3', $vector);
    }

    private function getCosimMethod(null|array|string $vector): string
    {
        // TODO: If cluster -> if use small -> make vector small
        $model = $this->resolveModel();
        $vectorColumn = "{$model->getTable()}.{$model::$vectorColumn}";
        if ($model->useCache) {
            $hashColumn = $this->getVectorHashColumn($model) ?? "'{$model->getTable()}:{$model->getKey()}'";
            $hashed = self::hashVectorBlob($vector);

            return "COSIM_CACHE({$hashColumn}, {$vectorColumn}, '{$hashed}', ?)";
        } else {
            return "COSIM({$vectorColumn}, ?)";
        }
    }

    public function clusterIdsByVector(string $vector): VectorLiteQueryBuilder
    {
        // TODO: If cluster -> if use small -> make vector small
        $clusterModel = $this->getClusterModel();
        $clusterModelName = $this->getClusterModelName();
        $ids = $clusterModelName::searchBestByVector($vector, $this->clusterLimit)->pluck('id')->toArray();
        if (empty($ids)) {
            return $this->whereRaw('1 = 0');
        }
        if (count($ids) === 1) {
            return $this->where($clusterModel->getClusterForeignKey(), $ids[0]);
        }

        return $this->whereIn($clusterModel->getClusterForeignKey(), $ids);
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

    public function bestClusters(int $amount = 1): Collection
    {
        // TODO: If cluster -> if use small -> use _small vector column
        $clusterClass = $this->getClusterModelName();

        return $clusterClass::searchBestByVector($this->{$clusterClass::$vectorColumn}, $amount);
    }

    /**
     * Add a select clause that computes cosine similarity.
     */
    public function selectSimilarity($vector): Builder
    {
        // TODO: If cluster -> if use small -> make vector small
        $model = $this->resolveModel();
        $cosimMethodCall = $this->getCosimMethod($vector);

        return $this->selectRaw("{$model->getTable()}.*, {$cosimMethodCall} as {$model::$similarityAlias}", [$vector]);
    }

    /**
     * Find the best match based on cosine similarity.
     */
    public function findBestByVector(null|array|string $vector = null): ?Model
    {
        // TODO: If cluster -> if use small -> make vector small
        return $this->bestByVector($vector)->first();
    }

    /**
     * Search for the best matches based on cosine similarity.
     */
    public function searchBestByVector(null|array|string $vector = null, ?int $limit = null): Collection
    {
        // TODO: If cluster -> if use small -> make vector small
        return $this->bestByVector($vector, $limit)->get();
    }

    public function bestByVector(null|array|string $vector = null, ?int $limit = null): Builder
    {
        // TODO: If cluster -> if use small -> make vector small
        if (is_array($vector)) {
            $vector = VectorLite::normalizeToBinary($vector);
        }

        $model = $this->resolveModel();
        $cosimMethodCall = $this->getCosimMethod($vector);

        $query = $this->selectRaw("{$model->getTable()}.*, {$cosimMethodCall} as {$model::$similarityAlias}", [$vector])
            ->orderByRaw("{$model::$similarityAlias} DESC");

        if ($limit) {
            return $query->limit($limit);
        } else {
            return $query;
        }
    }

    /**
     * Add a where clause based on cosine similarity.
     */
    public function whereVector(null|array|string $vector = null, string $operator = '>', float $threshold = 0.0): Builder
    {
        // TODO: If cluster -> if use small -> make vector small
        // Validate the operator to avoid SQL injection.
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!='];
        if (! in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [$operator] provided.");
        }
        $cosimMethodCall = $this->getCosimMethod($vector);

        return $this->whereRaw("$cosimMethodCall $operator ?", [$vector, $threshold]);
    }

    /**
     * Add a where clause based on cosine similarity where the vector is between two values.
     */
    public function whereVectorBetween(null|array|string $vector = null, float $min = 0.0, float $max = 1.0): Builder
    {
        // TODO: If cluster -> if use small -> make vector small
        $cosimMethodCall = $this->getCosimMethod($vector);

        return $this->whereRaw("$cosimMethodCall BETWEEN ? AND ?", [$vector, $min, $max]);
    }

    /**
     * Add a having clause based on cosine similarity.
     */
    public function havingVector(null|string|array $vector = null, string $operator = '>', float $threshold = 0.0): Builder
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
            return $this->having($model::$similarityAlias, $operator, $threshold);
        }

        // Otherwise, compute the similarity on the fly.
        // (Note: Using HAVING RAW here because operators and bindings must be inline.)
        $cosimMethodCall = $this->getCosimMethod($vector);

        return $this->havingRaw("$cosimMethodCall $operator ?", [$vector, $threshold]);
    }

    /**
     * Order the query by cosine similarity.
     */
    public function orderBySimilarity($vector, string $direction = 'desc'): Builder
    {
        // TODO: If cluster -> if use small -> make vector small
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $cosimMethodCall = $this->getCosimMethod($vector);

        return $this->orderByRaw("$cosimMethodCall $direction", [$vector]);
    }

    /**
     * Exclude the current model from the query.
     */
    public function excludeCurrent(?VectorModel $model = null): Builder
    {
        $current = $model ?? $this->resolveModel();

        return $this->where($this->resolveModel()->getTable().'.id', '!=', $current->getKey());
    }
}
