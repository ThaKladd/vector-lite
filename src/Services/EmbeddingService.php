<?php

namespace ThaKladd\VectorLite\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ThaKladd\VectorLite\Enums\EmbeddingInputType;
use ThaKladd\VectorLite\Exceptions\EmbeddingException;
use ThaKladd\VectorLite\Support\EmbeddingConfig;

/**
 * Talks to one OpenAI-compatible POST /embeddings endpoint, configured under
 * vector-lite.embeddings (see EmbeddingConfig). Every place vector-lite
 * embeds text goes through here: model embeddings (VectorModel),
 * Embeddings::for(), Reranking, the query builder's text search and the
 * NEW_EMBEDDING clustering reduction.
 *
 * Resolved fresh from the container on every use (app(EmbeddingService::class)),
 * so the setters below only affect the instance they're called on.
 */
class EmbeddingService
{
    /** Kept public for backwards compatibility; the model sent with each request. */
    public string $embeddingModel;

    protected EmbeddingConfig $config;

    private ?int $timeoutOverride = null;

    public function __construct(?EmbeddingConfig $config = null)
    {
        $this->usingConfig($config ?? EmbeddingConfig::resolve());
    }

    /**
     * Point this instance at another endpoint, e.g. EmbeddingConfig::resolve('ollama').
     */
    public function usingConfig(EmbeddingConfig $config): static
    {
        $this->config = $config;
        $this->embeddingModel = $config->model;

        return $this;
    }

    /**
     * The settings this instance sends requests with, including any
     * setModel()/setTimeout() override.
     */
    public function config(): EmbeddingConfig
    {
        return $this->config
            ->withModel($this->embeddingModel)
            ->withTimeout($this->timeoutOverride);
    }

    public function setModel(string $model): void
    {
        $this->embeddingModel = $model;
    }

    public function setTimeout(int $seconds): void
    {
        $this->timeoutOverride = $seconds;
    }

    /**
     * False only for OpenAI without an API key. A local endpoint such as
     * Ollama works without one.
     */
    public function isConfigured(): bool
    {
        return $this->config()->isConfigured();
    }

    /**
     * Embed several texts in one request. Each text gets the prefix for its
     * input type (vector-lite.embeddings.query_prefix / document_prefix), so
     * queries and documents can share one batch - $inputType is either one
     * type for every text or a list with one type per text, in order.
     *
     * Returns the response's "data" rows in input order, each with a
     * validated "embedding" of exactly $dimensions floats.
     *
     * @param  array<int, string>  $texts
     * @param  EmbeddingInputType|array<int, EmbeddingInputType>  $inputType
     * @return array<int, array{embedding: array<int, float>, index: int}>
     *
     * @throws EmbeddingException
     */
    public function createEmbeddings(array $texts, ?int $dimensions = null, EmbeddingInputType|array $inputType = EmbeddingInputType::Document): array
    {
        $texts = array_values($texts);
        if ($texts === []) {
            return [];
        }

        $config = $this->config();
        if (! $config->isConfigured()) {
            throw EmbeddingException::notConfigured($config);
        }

        $dimensions ??= $config->dimensions;
        $types = is_array($inputType) ? array_values($inputType) : array_fill(0, count($texts), $inputType);
        if (count($types) !== count($texts)) {
            throw new InvalidArgumentException('VectorLite: pass one EmbeddingInputType per text, or a single one for all of them.');
        }

        $payload = [
            'input' => array_map(
                fn (string $text, EmbeddingInputType $type) => $config->prefixFor($type).$text,
                $texts,
                $types
            ),
            'model' => $config->model,
            'encoding_format' => 'float',
        ];

        if ($config->sendDimensions) {
            $payload['dimensions'] = $dimensions;
        }

        $request = Http::acceptJson()->asJson()->timeout($config->timeout);
        if ($config->apiKey !== null) {
            $request = $request->withToken($config->apiKey);
        }

        try {
            $response = $request->post($config->endpoint(), $payload);
        } catch (ConnectionException $exception) {
            throw EmbeddingException::connectionFailed($config, $exception);
        }

        if ($response->failed()) {
            throw EmbeddingException::requestFailed($config, $response->status(), $response->body());
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw EmbeddingException::invalidResponse($config, 'it has no "data" list.', $response->body());
        }

        if (count($data) !== count($texts)) {
            throw EmbeddingException::invalidResponse($config, 'it holds '.count($data).' embeddings for '.count($texts).' inputs.');
        }

        $data = $this->inInputOrder(array_values($data));

        foreach ($data as $position => $row) {
            $embedding = is_array($row) ? ($row['embedding'] ?? null) : null;
            if (! is_array($embedding) || $embedding === []) {
                throw EmbeddingException::invalidResponse($config, "embedding #{$position} is missing or not a list of floats.");
            }

            if (count($embedding) !== $dimensions) {
                throw EmbeddingException::dimensionMismatch($config, $dimensions, count($embedding));
            }

            foreach ($embedding as $value) {
                if (! is_int($value) && ! is_float($value)) {
                    throw EmbeddingException::invalidResponse($config, "embedding #{$position} holds a non-numeric value.");
                }
            }

            // JSON drops a zero fraction ("1.0" -> 1), so cast back to floats.
            $data[$position] = ['index' => $position, 'embedding' => array_map('floatval', $embedding)] + $row;
        }

        return $data;
    }

    /**
     * Embed one text and return its vector.
     *
     * @return array<int, float>
     *
     * @throws EmbeddingException
     */
    public function createEmbedding(string $text, ?int $dimensions = null, EmbeddingInputType $inputType = EmbeddingInputType::Document): array
    {
        return $this->createEmbeddings([$text], $dimensions, $inputType)[0]['embedding'];
    }

    /**
     * OpenAI-style responses carry an "index" per row; honour it when it's a
     * clean 0..n-1 permutation, otherwise trust the order received.
     */
    private function inInputOrder(array $data): array
    {
        $indexes = array_map(fn ($row) => is_array($row) ? ($row['index'] ?? null) : null, $data);
        $sorted = $indexes;
        sort($sorted);

        if ($sorted !== range(0, count($data) - 1)) {
            return $data;
        }

        usort($data, fn ($a, $b) => $a['index'] <=> $b['index']);

        return $data;
    }
}
