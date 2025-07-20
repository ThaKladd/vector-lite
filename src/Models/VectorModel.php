<?php

namespace ThaKladd\VectorLite\Models;

use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\Contracts\HasVectorType;
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

        $columnSmall = config('vector-lite.use_clustering_dimensions', false) ? $column . '_small' : '';
        $vectorSmallColumns = $columnSmall ? [$columnSmall, $columnSmall . '_norm', $columnSmall . '_hash'] : [];

        $this->fillable(array_merge(
            $this->getFillable(),
            $vectorColumns,
            $vectorSmallColumns,
        ));
    }
}
