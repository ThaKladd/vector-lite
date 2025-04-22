<?php

namespace ThaKladd\VectorLite\QueryBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class VectorLiteQueryBuilder extends Builder
{
    protected int $clusterLimit = 1;

    public function clusterAmount($limit)
    {
        $this->clusterLimit = $limit;
        return $this;
    }

    private function getCosimMethod($vector): string
    {
        $model = $this->getModel();
        if($model->useCache) {
            $hashColumn = $this->getVectorHashColumn() ?? "'{$this->getTable()}:{$this->id}'";
            $hashed = self::hashVectorBlob($vector);
            return "COSIM_CACHE({$hashColumn}, {$model::$vectorColumn}, {$hashed}, ?)";
        } else {
            return "COSIM({$model::$vectorColumn}, ?)";
        }
    }


    public function clusterIdsByVector(string $vector): VectorLiteQueryBuilder
    {
        $clusterClass = $this->getClusterModelName();
        $clusterModel = new $clusterClass;
        $ids = $clusterModel->searchBestByVector($vector, $this->clusterLimit)->pluck('id')->toArray();
        if(empty($ids)) {
            return $this->whereRaw('1 = 0');
        }
        if(count($ids) === 1) {
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
    public function findBestByVector(null|array|string $vector = null): self
    {
        $this->bestByVector($vector);
        return $this->first();
    }

    /**
     * Search for the best matches based on cosine similarity.
     */
    public function searchBestByVector(null|array|string $vector = null, int $limit = 3): Collection
    {
        $this->bestByVector($vector);
        return $this->get();
    }

    public function bestByVector(null|array|string $vector = null): Builder
    {
        $model = $this->getModel();
        $cosimMethodCall = $this->getCosimMethod($vector, $model::$vectorColumn);
        return $this->selectRaw("{$model->getTable()}.*, {$cosimMethodCall} as {$model::$similarityAlias}", [$vector])
            ->whereRaw("{$model::$similarityAlias} >= ?", [$vector, 0.0])
            ->orderByRaw("{$model::$similarityAlias} DESC", [$vector]);
    }

    /**
     * Add a where clause based on cosine similarity.
     */
    public function scopeWhereVector(null|array|string $vector = null, string $operator = '>', float $threshold = 0.0): Builder
    {
        // Validate the operator to avoid SQL injection.
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!='];
        if (!in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [$operator] provided.");
        }
        $cosimMethodCall = $this->getCosimMethod($vector);
        return $this->whereRaw("$cosimMethodCall $operator ?", [$vector, $threshold]);
    }

    /**
     * Add a where clause based on cosine similarity where the vector is between two values.
     */
    public function scopeWhereVectorBetween(null|array|string $vector = null, float $min = 0.0, float $max = 1.0): Builder
    {
        $cosimMethodCall = $this->getCosimMethod($vector);
        return $this->whereRaw("$cosimMethodCall BETWEEN ? AND ?", [$vector, $min, $max]);
    }

    /**
     * Add a having clause based on cosine similarity.
     */
    public function scopeHavingVector(null|string|array $vector = null, string $operator = '>', float $threshold = 0.0): Builder
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
        if ($vector === $model::$similarityAlias || (!empty($query->columns) && is_array($query->columns) && in_array($model::$similarityAlias, $query->columns))) {
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
    public function scopeOrderBySimilarity($vector, string $direction = 'desc'): Builder
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $cosimMethodCall = $this->getCosimMethod($vector);
        return $this->orderByRaw("$cosimMethodCall $direction", [$vector]);
    }

    /**
     * Exclude the current model from the query.
     */
    public function scopeExcludeCurrent(Model $model): Builder
    {
        return $this->where($this->getModel()->getTable() . '.id', '!=', $model->id);
    }

}
