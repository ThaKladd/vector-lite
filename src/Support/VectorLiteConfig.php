<?php

namespace ThaKladd\VectorLite\Support;

use Illuminate\Support\Facades\DB;

class VectorLiteConfig
{
    private static ?self $instance = null;

    public readonly ?string $cacheDriver;

    public readonly int $cacheTime;

    public readonly bool $useClusteringDimensions;

    public readonly int $clusteringDimensions;

    public readonly int $maxClusterSize;

    public readonly bool $useCachedCosim;

    public readonly string $vectorColumn;

    public readonly string $similarityAlias;

    public readonly string $embedHashColumn;

    public readonly mixed $reductionMethod;

    public readonly int $defaultDimensions;

    public readonly ?string $openaiApiKey;

    public readonly bool $excludeSelfByDefault;

    public readonly bool $isSqlLite;

    private function __construct()
    {
        // Read fresh on every construction - reset() is how callers (and
        // tests) pick up config changes, so this must not be memoized.
        $config = config('vector-lite');

        // false/'' (the shipped default) means "no persistent cache store" -
        // normalized to null so it's never passed on as Cache::store(''),
        // which Laravel 13.42+ rejects instead of falling back to the default store.
        $this->cacheDriver = ($config['cache_driver'] ?? null) ?: null;
        $this->cacheTime = $config['cache_time'] ?? 3600;
        $this->useClusteringDimensions = $config['use_clustering_dimensions'] ?? false;
        $this->clusteringDimensions = $config['clustering_dimensions'] ?? 384;
        $this->maxClusterSize = $config['clusters_size'] ?? 500;
        $this->useCachedCosim = $config['use_cached_cosim'] ?? false;
        $this->vectorColumn = $config['vector_column'] ?? 'vector';
        $this->similarityAlias = $config['similarity_alias'] ?? 'similarity';
        $this->embedHashColumn = $config['embed_hash_column'] ?? 'embed_hash';
        $this->reductionMethod = $config['reduction_method'] ?? null;
        $this->defaultDimensions = $config['default_dimensions'] ?? 1536;
        $this->openaiApiKey = $config['openai']['api_key'] ?? null;
        $this->excludeSelfByDefault = $config['exclude_self_by_default'] ?? false;
        // $this->isSqlLite = false;
        $this->isSqlLite = DB::connection()->getDriverName() === 'sqlite';
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
