<?php

namespace ThaKladd\VectorLite;

use Illuminate\Support\Facades\Cache;
use ThaKladd\VectorLite\Enums\EmbeddingInputType;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Support\EmbeddingConfig;
use ThaKladd\VectorLite\Support\EmbeddingsResponse;

/**
 * Standalone equivalent of Laravel AI SDK's `Laravel\Ai\Embeddings`, generating
 * embeddings via vector-lite's own EmbeddingService (the configured
 * OpenAI-compatible endpoint) behind the same fluent shape:
 * Embeddings::for([...])->dimensions()->cache()->generate().
 *
 * Inputs are embedded as documents by default; call ->asQuery() for search
 * text, so it gets vector-lite.embeddings.query_prefix instead.
 *
 * A real class with a real static for(), not a Facade - so it works whether
 * or not laravel/ai is also installed, under its own namespace. It's also
 * exposed via Facades\Embeddings for parity/testability with the rest of
 * this package's facade (VectorLite).
 */
final class Embeddings
{
    protected ?int $dimensions = null;

    protected EmbeddingInputType $inputType = EmbeddingInputType::Document;

    protected bool $cacheEnabled;

    protected ?int $cacheSeconds = null;

    protected ?int $timeoutSeconds = null;

    private function __construct(protected array $inputs)
    {
        $this->cacheEnabled = (bool) config('vector-lite.caching.embeddings.cache', false);
    }

    /**
     * @param  array<int, string>  $inputs
     */
    public static function for(array $inputs): static
    {
        return new self($inputs);
    }

    public function dimensions(int $dimensions): static
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    /**
     * Embed the inputs as search queries (query_prefix) rather than documents.
     */
    public function asQuery(): static
    {
        $this->inputType = EmbeddingInputType::Query;

        return $this;
    }

    /**
     * Embed the inputs as documents (document_prefix) - the default.
     */
    public function asDocument(): static
    {
        $this->inputType = EmbeddingInputType::Document;

        return $this;
    }

    public function timeout(int $seconds): static
    {
        $this->timeoutSeconds = $seconds;

        return $this;
    }

    /**
     * Enable caching for this call. $seconds overrides the configured ttl
     * (vector-lite.caching.embeddings.ttl, default 30 days); true/omitted
     * uses that default. false disables caching for this call.
     */
    public function cache(bool|int $seconds = true): static
    {
        $this->cacheEnabled = $seconds !== false;
        $this->cacheSeconds = is_int($seconds) ? $seconds : null;

        return $this;
    }

    /**
     * Mirrors Laravel AI SDK's ->generate($provider, $model).
     *
     * $provider (a name or enum such as Lab::OpenAI) selects an endpoint from
     * vector-lite.embeddings.providers - "openai" is built in - and defaults
     * to the main vector-lite.embeddings endpoint. $model overrides the
     * model for this call. Failover lists aren't supported: vectors from
     * different models can't be mixed.
     */
    public function generate(mixed $provider = null, ?string $model = null): EmbeddingsResponse
    {
        $service = app(EmbeddingService::class);
        if ($provider !== null) {
            $service->usingConfig(EmbeddingConfig::resolve($provider));
        }
        if ($model !== null && $model !== '') {
            $service->setModel($model);
        }
        if ($this->timeoutSeconds !== null) {
            $service->setTimeout($this->timeoutSeconds);
        }

        $config = $service->config()->withDimensions($this->dimensions);

        $embeddings = [];
        $toFetch = [];
        $toFetchIndexes = [];

        foreach ($this->inputs as $index => $input) {
            $cacheKey = $config->cacheKey($input, $this->inputType);
            if ($this->cacheEnabled && Cache::store($this->store())->has($cacheKey)) {
                $embeddings[$index] = Cache::store($this->store())->get($cacheKey);

                continue;
            }
            $toFetch[] = $input;
            $toFetchIndexes[] = $index;
        }

        if (! empty($toFetch)) {
            $fetched = array_column($service->createEmbeddings($toFetch, $config->dimensions, $this->inputType), 'embedding');

            foreach ($toFetchIndexes as $position => $index) {
                $embeddings[$index] = $fetched[$position];

                if ($this->cacheEnabled) {
                    Cache::store($this->store())->put(
                        $config->cacheKey($this->inputs[$index], $this->inputType),
                        $embeddings[$index],
                        $this->cacheSeconds ?? config('vector-lite.caching.embeddings.ttl', 60 * 60 * 24 * 30)
                    );
                }
            }
        }

        ksort($embeddings);

        return new EmbeddingsResponse(array_values($embeddings));
    }

    protected function store(): ?string
    {
        return config('vector-lite.caching.embeddings.store', 'database');
    }
}
