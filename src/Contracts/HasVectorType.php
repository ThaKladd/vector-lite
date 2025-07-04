<?php

namespace ThaKladd\VectorLite\Contracts;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;

interface HasVectorType
{
    public function newEloquentBuilder($query): VectorLiteQueryBuilder;

    public function initializeHasVector(): void;

    public function getClusterForeignKey(): string;

    public function getClusterTableName(): string;

    public function getClusterModelName(): string;

    public function getClusterModel(): VectorModel;

    public function isCluster(): bool;

    public function getModelName(): string;

    public function getUniqueRowIdAttribute(): string;

    public function getSimilarityAttribute(): ?float;

    public function setVectorAttribute(array $vector): void;

    public function cluster(): HasOne;

    public function createEmbedding(string $text, ?int $dimensions = null): array;

    public function getBestVectorMatches(?int $limit = null): Collection;

    public function findBestVectorMatch(): Collection;

    public function getBestClusters(int $amount = 1): Collection;
}
