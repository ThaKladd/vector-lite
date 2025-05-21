<?php

namespace ThaKladd\VectorLite\QueryBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ThaKladd\VectorLite\VectorLite;

class VectorLiteQueryBuilder extends Builder
{
    protected int $clusterLimit = 1;

    public function clusterAmount($limit)
    {
        $this->clusterLimit = $limit;

        return $this;
    }

    public function getVectorHashColumn(?Model $model): ?string
    {
        $model = $model ?? $this->getModel();
        $columnExists = Schema::hasColumn($model->getTable(), $model::$vectorColumn.'_hash');

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
        $model = $this->getModel();
        $vectorColumn = "{$model->getTable()}.{$model::$vectorColumn}";
        if ($model->useCache) {
            $hashColumn = $this->getVectorHashColumn($model) ?? "'{$model->getTable()}:{$model->id}'";
            $hashed = self::hashVectorBlob($vector);

            return "COSIM_CACHE({$hashColumn}, {$vectorColumn}, '{$hashed}', ?)";
        } else {
            return "COSIM({$vectorColumn}, ?)";
        }
    }

    public function clusterIdsByVector(string $vector): VectorLiteQueryBuilder
    {
        $clusterClass = $this->getClusterModelName();
        $clusterModel = new $clusterClass;
        $ids = $clusterModel->searchBestByVector($vector, $this->clusterLimit)->pluck('id')->toArray();
        if (empty($ids)) {
            return $this->whereRaw('1 = 0');
        }
        if (count($ids) === 1) {
            return $this->where($this->getClusterForeignKey(), $ids[0]);
        }

        return $this->whereIn($this->getClusterForeignKey(), $ids);
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
        $clusterClass = $this->getModel()->getClusterModelName();
        $clusterModel = new $clusterClass;

        return $clusterModel->searchBestByVector($vector, $amount);
    }

    public function bestClusters(int $amount = 1): Collection
    {
        $clusterClass = $this->getModel()->getClusterModelName();
        $clusterModel = new $clusterClass;

        return $clusterModel->searchBestByVector($this->{$clusterModel::$vectorColumn}, $amount);
    }

    /**
     * Add a select clause that computes cosine similarity.
     */
    public function selectSimilarity($vector): Builder
    {
        $model = $this->getModel();
        /* @var Model $modelClass */
        $cosimMethodCall = $this->getCosimMethod($vector);

        return $this->selectRaw("{$model->getTable()}.*, {$cosimMethodCall} as {$model::$similarityAlias}", [$vector]);
    }

    /**
     * Find the best match based on cosine similarity.
     */
    public function findBestByVector(null|array|string $vector = null): ?Model
    {
        return $this->bestByVector($vector)->first();
    }

    /**
     * Search for the best matches based on cosine similarity.
     */
    public function searchBestByVector(null|array|string $vector = null, ?int $limit = null): Collection
    {
        return $this->bestByVector($vector, $limit)->get();
    }

    public function bestByVector(null|array|string $vector = null, ?int $limit = null): Builder
    {
        if (is_array($vector)) {
            $vector = VectorLite::normalizeToBinary($vector);
        }

        $model = $this->getModel();
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
        $cosimMethodCall = $this->getCosimMethod($vector);

        return $this->whereRaw("$cosimMethodCall BETWEEN ? AND ?", [$vector, $min, $max]);
    }

    /**
     * Add a having clause based on cosine similarity.
     */
    public function havingVector(null|string|array $vector = null, string $operator = '>', float $threshold = 0.0): Builder
    {
        // Validate the operator.
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!='];
        if (! in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [$operator] provided.");
        }
        $model = $this->getModel();

        $vector = $vector ?? $model::$similarityAlias;

        // Determine if the similarity column is already part of the query.
        $hasSimilarityColumn = false;
        if ($vector === $model::$similarityAlias || (! empty($query->columns) && is_array($query->columns) && in_array($model::$similarityAlias, $query->columns))) {
            $hasSimilarityColumn = true;
        }

        if ($hasSimilarityColumn) {
            // If the similarity column is already selected, use it in the HAVING clause.
            return $query->having($model::$similarityAlias, $operator, $threshold);
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
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $cosimMethodCall = $this->getCosimMethod($vector);

        return $this->orderByRaw("$cosimMethodCall $direction", [$vector]);
    }

    /**
     * Exclude the current model from the query.
     */
    public function excludeCurrent(?Model $model = null): Builder
    {
        $current = $model ?? $this;

        return $this->where($this->getModel()->getTable().'.id', '!=', $current->id);
    }
}
