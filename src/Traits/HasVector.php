<?php

namespace ThaKladd\VectorLite\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\VectorLite;

trait HasVector
{
    protected string $similarityAlias = 'similarity';

    protected string $vectorColumn = 'vector';

    /**
     * Add a select clause that computes cosine similarity.
     *
     * @param  mixed  $vector  The binary vector to compare
     */
    public function scopeSelectSimilarity(Builder $query, $vector, ?string $alias = null, ?string $vectorColumn = null): Builder
    {
        $this->similarityAlias = $alias ?? $this->similarityAlias;
        $this->vectorColumn = $vectorColumn ?? $this->vectorColumn;

        // Using {$this->getTable()} ensures we select all columns from the current table first.
        /* @var Model $this */
        return $query->selectRaw("{$this->getTable()}.*, COSIM({$this->vectorColumn}, ?) as {$alias}", [$vector]);
    }

    /**
     * Add a where clause based on cosine similarity.
     *
     * @param  mixed  $vector  The binary vector to compare
     * @param  string  $operator  The comparison operator (e.g. '>', '<', etc.)
     * @param  float  $threshold  The similarity threshold
     */
    public function scopeWhereVector(Builder $query, null|array|string $vector = null, string $operator = '>', float $threshold = 0.0, ?string $vectorColumn = null): Builder
    {
        // Validate the operator to avoid SQL injection.
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!='];
        if (! in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [$operator] provided.");
        }
        $vectorColumn = $vectorColumn ?? $this->vectorColumn;

        return $query->whereRaw("COSIM({$vectorColumn}, ?) $operator ?", [$vector, $threshold]);
    }

    /**
     * Add a where clause based on cosine similarity where the vector is between two values.
     *
     * @param  mixed  $vector  The binary vector to compare
     * @param  float  $min  The minimum similarity threshold
     * @param  float  $max  The maximum similarity threshold
     */
    public function scopeWhereVectorBetween(Builder $query, null|array|string $vector = null, float $min = 0.0, float $max = 1.0, ?string $vectorColumn = null): Builder
    {
        $vectorColumn = $vectorColumn ?? $this->vectorColumn;

        return $query->whereRaw("COSIM({$vectorColumn}, ?) BETWEEN ? AND ?", [$vector, $min, $max]);
    }

    /**
     * Add a having clause based on cosine similarity.
     *
     * @param  mixed  $vector  The binary vector to compare
     * @param  string  $operator  The comparison operator (e.g. '>', '<', etc.)
     * @param  float  $threshold  The similarity threshold
     */
    public function scopeHavingVector(Builder $query, string|array|null $vector = null, string $operator = '>', float $threshold = 0.0, ?string $vectorColumn = null): Builder
    {
        // Validate the operator.
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!='];
        if (! in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [$operator] provided.");
        }

        $vector = $vector ?? $this->similarityAlias;

        // Determine if the similarity column is already part of the query.
        $hasSimilarityColumn = false;
        if ($vector === $this->similarityAlias || (! empty($query->columns) && is_array($query->columns) && in_array($this->similarityAlias, $query->columns))) {
            $hasSimilarityColumn = true;
        }

        if ($hasSimilarityColumn) {
            // If the similarity column is already selected, use it in the HAVING clause.
            return $query->having($this->similarityAlias, $operator, $threshold);
        }

        $vectorColumn = $vectorColumn ?? $this->vectorColumn;

        // Otherwise, compute the similarity on the fly.
        // (Note: Using HAVING RAW here because operators and bindings must be inline.)
        return $query->havingRaw("COSIM({$vectorColumn}, ?) $operator ?", [$vector, $threshold]);
    }

    /**
     * Order the query by cosine similarity.
     *
     * @param  mixed  $vector  The binary vector to compare
     * @param  string  $direction  The ordering direction ('asc' or 'desc')
     */
    public function scopeOrderBySimilarity(Builder $query, $vector, string $direction = 'desc', ?string $vectorColumn = null): Builder
    {
        $vectorColumn = $vectorColumn ?? $this->vectorColumn;
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderByRaw("COSIM({$vectorColumn}, ?) $direction", [$vector]);
    }

    /**
     * Accessor for the similarity attribute.
     *
     * This allows you to cast the similarity attribute to a float, for example.
     *
     * @return float|null
     */
    public function getSimilarityAttribute()
    {
        // Use the dynamic column name stored in $this->similarityAlias, defaulting to 'similarity'
        return isset($this->attributes[$this->similarityAlias]) ? (float) $this->attributes[$this->similarityAlias] : null;
    }

    /**
     * Accessor for the vector column attribute.
     *
     * When you retrieve $model->vector, it will be unpacked into an array of floats.
     *
     * @param  mixed  $value
     * @return array|null
     */
    public function getVectorAttribute($value)
    {
        $column = $this->vectorColumn ?? 'vector';
        $floats = isset($this->attributes[$column]) ? (float) $this->attributes[$column] : null;

        if (! is_null($floats)) {
            // Unpack binary string back into an array of floats
            $floats = unpack('f*', $value);

            // unpack returns a 1-based indexed array,
            // convert it to a 0-based indexed array:
            return array_values($floats);
        }

        return null;
    }

    /**
     * Mutator for setting the vector attribute.
     *
     * When you do $model->vector = [0.123, 0.456, ...], it will be stored as binary floats.
     *
     * @return void
     */
    protected function setVectorAttribute(array $vector)
    {
        $column = $this->vectorColumn ?? 'vector';
        // Pack array of floats into binary format
        // If not an array, assume it's already binary or handle error
        $this->attributes[$column] = VectorLite::normalizeToBinary($vector);
    }
}
