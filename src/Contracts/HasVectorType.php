<?php

namespace ThaKladd\VectorLite\Contracts;

use Illuminate\Database\Eloquent\Relations\HasOne;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;

interface HasVectorType
{
    public function newEloquentBuilder($query): VectorLiteQueryBuilder;

    public function initializeHasVector(): void;

    public function getClusterForeignKey(): string;

    public function getClusterTableName(): string;

    public function getClusterModelName(): string;

    public function isCluster(): bool;

    public function getModelName(): string;

    public function getUniqueRowIdAttribute(): string;

    public function getSimilarityAttribute(): ?float;

    public function setVectorAttribute(array $vector): void;

    public function cluster(): HasOne;
}
