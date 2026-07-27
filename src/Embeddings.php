<?php

namespace ThaKladd\VectorLite;

use Illuminate\Support\Facades\Cache;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Support\EmbeddingsResponse;

/**
 * Standalone equivalent of Laravel AI SDK's `Laravel\Ai\Embeddings`, generating
 * embeddings via vector-lite's own EmbeddingService (OpenAI) behind the same
 * fluent shape: Embeddings::for([...])->dimensions()->cache()->generate().
 *
 * A real class with a real static for(), not a Facade - so it works whether
 * or not laravel/ai is also installed, under its own namespace. It's also
 * exposed via Facades\Embeddings for parity/testability with the rest of
 * this package's facade (VectorLite).
 */
final class Embeddings
{
    protected ?int $dimensions = null;

    protected string $model = 'text-embedding-3-small';

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
     * $provider is accepted (and ignored) purely so calls written against
     * Laravel's AI SDK - e.g. ->generate(Lab::OpenAI, 'text-embedding-3-small') -
     * don't error out standalone; vector-lite only has one embedding provider.
     */
    public function generate(mixed $provider = null, ?string $model = null): EmbeddingsResponse
    {
        $model ??= $this->model;
        $dimensions = $this->dimensions ?? config('vector-lite.default_dimensions');

        $embeddings = [];
        $toFetch = [];
        $toFetchIndexes = [];

        foreach ($this->inputs as $index => $input) {
            $cacheKey = $this->cacheKey($model, $dimensions, $input);
            if ($this->cacheEnabled && Cache::store($this->store())->has($cacheKey)) {
                $embeddings[$index] = Cache::store($this->store())->get($cacheKey);

                continue;
            }
            $toFetch[] = $input;
            $toFetchIndexes[] = $index;
        }

        if (! empty($toFetch)) {
            $service = app(EmbeddingService::class);
            $service->setModel($model);
            if ($this->timeoutSeconds !== null) {
                $service->setTimeout($this->timeoutSeconds);
            }
            $fetched = array_column($service->createEmbeddings($toFetch, $dimensions), 'embedding');

            foreach ($toFetchIndexes as $position => $index) {
                $embeddings[$index] = $fetched[$position] ?? [];

                if ($this->cacheEnabled) {
                    Cache::store($this->store())->put(
                        $this->cacheKey($model, $dimensions, $this->inputs[$index]),
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

    protected function cacheKey(string $model, int $dimensions, string $input): string
    {
        return 'vector-lite-embedding:'.VectorLite::hashText("openai|{$model}|{$dimensions}|{$input}");
    }
}
