<?php

namespace ThaKladd\VectorLite\Exceptions;

use RuntimeException;
use ThaKladd\VectorLite\Support\EmbeddingConfig;
use Throwable;

/**
 * Anything that went wrong while asking the embedding endpoint for vectors:
 * no provider configured, the server being unreachable, an error response,
 * or a response that doesn't hold one valid vector per input.
 *
 * This is the exception that vector-lite.embeddings.on_error = "skip"
 * swallows (and reports) in the model saving hook. The API key is never
 * part of a message.
 */
class EmbeddingException extends RuntimeException
{
    public static function notConfigured(EmbeddingConfig $config): self
    {
        return new self(
            "VectorLite: no embedding provider is configured. [{$config->url}] needs an API key - set ".
            'VECTOR_LITE_EMBEDDINGS_API_KEY (or OPENAI_API_KEY), or point VECTOR_LITE_EMBEDDINGS_URL at a local '.
            'OpenAI-compatible server such as Ollama.'
        );
    }

    public static function connectionFailed(EmbeddingConfig $config, Throwable $previous): self
    {
        return new self(
            "VectorLite: could not reach the embedding endpoint [{$config->endpoint()}]: {$previous->getMessage()}",
            0,
            $previous
        );
    }

    public static function requestFailed(EmbeddingConfig $config, int $status, string $body): self
    {
        return new self(
            "VectorLite: the embedding endpoint [{$config->endpoint()}] returned HTTP {$status} for model [{$config->model}]: ".
            self::excerpt($body),
            $status
        );
    }

    public static function invalidResponse(EmbeddingConfig $config, string $reason, string $body = ''): self
    {
        return new self(
            "VectorLite: unexpected response from the embedding endpoint [{$config->endpoint()}] for model [{$config->model}]: {$reason}".
            ($body !== '' ? ' Response: '.self::excerpt($body) : '')
        );
    }

    public static function dimensionMismatch(EmbeddingConfig $config, int $expected, int $actual): self
    {
        return new self(
            "VectorLite: embedding model [{$config->model}] returned {$actual}-dimensional vectors, but {$expected} were expected. ".
            "Set vector-lite.default_dimensions (VECTOR_LITE_DEFAULT_DIMENSIONS) to {$actual} or less if the model supports ".
            'shortened output, or use a model that can return the configured size. Changing it means re-embedding every row '.
            '(php artisan vector-lite:reembed).'
        );
    }

    private static function excerpt(string $body): string
    {
        $body = trim($body);

        return mb_strlen($body) > 500 ? mb_substr($body, 0, 500).'…' : $body;
    }
}
