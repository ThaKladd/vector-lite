<?php

namespace ThaKladd\VectorLite\Models;

use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\Collections\VectorModelCollection;
use ThaKladd\VectorLite\Contracts\HasVectorType;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;
use ThaKladd\VectorLite\Traits\HasVector;

abstract class VectorModel extends Model implements HasVectorType
{
    use HasVector;

    protected $fillable = [];

    protected static function booted(): void
    {
        static::retrieved(function (self $model) {
            $model->mergeFillableFromVectorColumn();
        });

        static::creating(function (self $model) {
            $model->mergeFillableFromVectorColumn();
        });
    }

    protected function mergeFillableFromVectorColumn(): void
    {
        $column = static::$vectorColumn;
        $vectorColumns = [$column, $column.'_norm', $column.'_hash'];

        $columnSmall = config('vector-lite.use_clustering_dimensions', false) ? $column.'_small' : '';
        $vectorSmallColumns = $columnSmall ? [$columnSmall, $columnSmall.'_norm', $columnSmall.'_hash'] : [];

        $this->fillable(array_merge(
            $this->getFillable(),
            $vectorColumns,
            $vectorSmallColumns,
        ));
    }

    /**
     * @return VectorLiteQueryBuilder<static>
     */
    public function newEloquentBuilder($query): VectorLiteQueryBuilder
    {
        /** @var VectorLiteQueryBuilder<static> */
        return new VectorLiteQueryBuilder($query);
    }

    /**
     * @return VectorModelCollection<static>
     */
    public function newCollection(array $models = []): VectorModelCollection
    {
        /** @var VectorModelCollection<static> */
        return new VectorModelCollection($models);
    }

    /**
     * @return VectorLiteQueryBuilder<static>
     */
    public function newModelQuery(): VectorLiteQueryBuilder
    {
        /** @var VectorLiteQueryBuilder<static> */
        return parent::newModelQuery();
    }
}
