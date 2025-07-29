<?php

namespace ThaKladd\VectorLite\Support;

class VectorLiteConfig
{
    private static ?self $instance = null;

    public readonly string $cacheDriver;

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

    private function __construct()
    {
        static $config = null;
        $config ??= config('vector-lite');

        $this->cacheDriver = $config['cache_driver'] ?? 'file';
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
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self;
    }
}
