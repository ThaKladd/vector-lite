<?php

namespace ThaKladd\VectorLite\Support;

use BackedEnum;
use InvalidArgumentException;
use ThaKladd\VectorLite\Enums\EmbeddingInputType;
use ThaKladd\VectorLite\VectorLite;
use UnitEnum;

/**
 * The resolved settings for one OpenAI-compatible /embeddings endpoint
 * (OpenAI itself, Ollama, LM Studio, llama.cpp, vLLM, TEI, ...), read from
 * the "vector-lite.embeddings" config block.
 *
 * It also owns the embedding identity - the settings that decide which
 * vector space a text ends up in (model, dimensions, document prefix) -
 * and derives the model's embed_hash and the Embeddings cache key from it,
 * so a change to any of them marks every stored vector as stale.
 */
final class EmbeddingConfig
{
    public const OPENAI_URL = 'https://api.openai.com/v1/';

    /** The embedding identity vector-lite used before it became configurable. */
    public const LEGACY_MODEL = 'text-embedding-3-small';

    public const LEGACY_DIMENSIONS = 1536;

    public function __construct(
        public readonly string $url,
        public readonly ?string $apiKey,
        public readonly string $model,
        public readonly int $dimensions,
        public readonly int $timeout = 60,
        public readonly bool $sendDimensions = true,
        public readonly string $queryPrefix = '',
        public readonly string $documentPrefix = '',
    ) {}

    /**
     * Resolve the default endpoint, or a named one from
     * vector-lite.embeddings.providers (e.g. Embeddings::...->generate('ollama')).
     * A named provider inherits every unset key from the default endpoint.
     * The API key is the exception: it's only inherited when the provider
     * points at the same host, so a key is never sent somewhere it wasn't
     * configured for. "openai" is built in and needs no providers entry.
     */
    public static function resolve(mixed $provider = null): self
    {
        $settings = (array) config('vector-lite.embeddings', []);
        unset($settings['providers']);
        $mainUrl = (string) ($settings['url'] ?? '') ?: self::OPENAI_URL;
        $apiKey = $settings['api_key'] ?? null;
        $dimensions = config('vector-lite.default_dimensions', self::LEGACY_DIMENSIONS);

        if ($provider !== null) {
            $overrides = self::providerSettings(self::providerName($provider));
            $settings = array_merge($settings, $overrides);
            $sameHost = self::host((string) ($settings['url'] ?? '') ?: self::OPENAI_URL) === self::host($mainUrl);
            $apiKey = array_key_exists('api_key', $overrides) ? $overrides['api_key'] : ($sameHost ? $apiKey : null);
            $dimensions = $overrides['dimensions'] ?? $dimensions;
        }

        $url = (string) ($settings['url'] ?? '') ?: self::OPENAI_URL;

        // The legacy vector-lite.openai.api_key (OPENAI_API_KEY) still works,
        // but only for OpenAI itself - never leaked to a local or third-party server.
        if (blank($apiKey) && self::isOpenAiUrl($url)) {
            $apiKey = config('vector-lite.openai.api_key');
        }

        return new self(
            url: $url,
            apiKey: filled($apiKey) ? (string) $apiKey : null,
            model: (string) ($settings['model'] ?? '') ?: self::LEGACY_MODEL,
            dimensions: (int) $dimensions,
            timeout: (int) ($settings['timeout'] ?? 60) ?: 60,
            sendDimensions: filter_var($settings['send_dimensions'] ?? true, FILTER_VALIDATE_BOOL),
            queryPrefix: (string) ($settings['query_prefix'] ?? ''),
            documentPrefix: (string) ($settings['document_prefix'] ?? ''),
        );
    }

    public function withModel(?string $model): self
    {
        return $model === null || $model === '' || $model === $this->model ? $this : $this->copy(['model' => $model]);
    }

    public function withDimensions(?int $dimensions): self
    {
        return $dimensions === null || $dimensions === $this->dimensions ? $this : $this->copy(['dimensions' => $dimensions]);
    }

    public function withTimeout(?int $seconds): self
    {
        return $seconds === null || $seconds === $this->timeout ? $this : $this->copy(['timeout' => $seconds]);
    }

    /**
     * OpenAI needs a key; a local server such as Ollama doesn't.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== null || ! $this->isOpenAi();
    }

    public function isOpenAi(): bool
    {
        return self::isOpenAiUrl($this->url);
    }

    /**
     * The full URL to POST to. Accepts both a base URL (".../v1/") and the
     * full endpoint (".../v1/embeddings").
     */
    public function endpoint(): string
    {
        $url = rtrim($this->url, '/');

        return str_ends_with($url, '/embeddings') ? $url : $url.'/embeddings';
    }

    public function prefixFor(EmbeddingInputType $type): string
    {
        return $type === EmbeddingInputType::Query ? $this->queryPrefix : $this->documentPrefix;
    }

    /**
     * The settings that decide which vector space a stored document lands in.
     * The endpoint URL is deliberately left out: moving a server doesn't
     * change its vectors. The query prefix is left out too - it never
     * touches stored vectors.
     *
     * @return array{model: string, dimensions: int, document_prefix: string}
     */
    public function identity(): array
    {
        return [
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'document_prefix' => $this->documentPrefix,
        ];
    }

    /**
     * Whether this is the identity every vector-lite release before 0.5.0
     * embedded with (OpenAI text-embedding-3-small, 1536 dimensions, no prefix).
     */
    public function isLegacyIdentity(): bool
    {
        return $this->model === self::LEGACY_MODEL
            && $this->dimensions === self::LEGACY_DIMENSIONS
            && $this->documentPrefix === '';
    }

    /**
     * The embed_hash for a model's embedding text:
     *
     *   xxh3(text)                              for the legacy identity
     *   xxh3(json_encode(identity()) . "\n" . text)   for anything else
     *
     * The legacy case is exactly what earlier releases stored, so upgrading
     * on the default OpenAI setup re-embeds nothing. Any change to model,
     * dimensions or document prefix changes every hash, so every row reads
     * as stale and gets re-embedded (on its next save, or by
     * `php artisan vector-lite:reembed`).
     */
    public function embedHash(string $text): string
    {
        if ($this->isLegacyIdentity()) {
            return VectorLite::hashText($text);
        }

        return VectorLite::hashText(self::encode($this->identity())."\n".$text);
    }

    /**
     * Cache key for Embeddings::for(...)->cache(): the same identity plus
     * the input type and the prefix that type gets, so a query and a
     * document embedding of the same text never share an entry.
     */
    public function cacheKey(string $input, EmbeddingInputType $type): string
    {
        $key = [
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'type' => $type->value,
            'prefix' => $this->prefixFor($type),
        ];

        return 'vector-lite-embedding:'.VectorLite::hashText(self::encode($key)."\n".$input);
    }

    public static function isOpenAiUrl(string $url): bool
    {
        return self::host($url) === 'api.openai.com';
    }

    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private function copy(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function providerName(mixed $provider): string
    {
        $name = match (true) {
            $provider instanceof BackedEnum => (string) $provider->value,
            $provider instanceof UnitEnum => $provider->name,
            is_string($provider) && $provider !== '' => $provider,
            default => throw new InvalidArgumentException(
                'VectorLite: pass a single embedding provider name. Failover between providers is not supported, '.
                'because vectors from different models live in different spaces and cannot be compared.'
            ),
        };

        return strtolower($name);
    }

    private static function providerSettings(string $name): array
    {
        $providers = array_change_key_case((array) config('vector-lite.embeddings.providers', []), CASE_LOWER);

        if (isset($providers[$name]) && is_array($providers[$name])) {
            return $providers[$name];
        }

        if ($name === 'openai') {
            return [
                'url' => self::OPENAI_URL,
                'model' => self::LEGACY_MODEL,
                'send_dimensions' => true,
                'query_prefix' => '',
                'document_prefix' => '',
            ];
        }

        throw new InvalidArgumentException(
            "VectorLite: embedding provider [{$name}] is not configured. Add it under vector-lite.embeddings.providers."
        );
    }
}
