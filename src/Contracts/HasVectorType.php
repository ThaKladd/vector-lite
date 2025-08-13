<?php

namespace ThaKladd\VectorLite\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;

interface HasVectorType
{
    public static function similarityAlias(): string;

    public static function vectorColumn(): string;

    public static function embedHashColumn(): string;

    public function vectorQuery(): VectorLiteQueryBuilder;

    public function newEloquentBuilder($query): VectorLiteQueryBuilder|Builder;

    public function initializeHasVector(): void;

    public function getClusterForeignKey(): string;

    public function getClusterTableName(): string;

    public function getClusterClass(): string;

    public function getModelClass(): string;

    public function getClusterModel(): VectorModel;

    public function isCluster(): bool;

    public function getModelName(): string;

    public function getUniqueRowIdAttribute(): string;

    public function getSimilarityAttribute(): ?float;

    public function setVectorAttribute(array $vector): void;

    public function cluster(): BelongsTo;

    public function models(): HasMany;

    public function createEmbedding(string $text, ?int $dimensions = null): array;

    public function getBestVectorMatches(?int $limit = null): Collection;

    public function findBestVectorMatch(): ?VectorModel;

    public function getBestClusters(int $amount = 1): Collection;

    public function findBestCluster(): ?VectorModel;
}
