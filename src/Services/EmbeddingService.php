<?php

namespace ThaKladd\VectorLite\Services;

use GuzzleHttp\Client;

class EmbeddingService
{
    public string $embeddingModel = 'text-embedding-3-small';

    private Client $client;

    public function setModel(string $model)
    {
        $this->embeddingModel = $model;
    }

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer '.config('vector-lite.openai.api_key'),
                'Accept' => 'application/json',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
            'timeout' => 60,
        ]);
    }

    public function createEmbeddings(array $texts, ?int $dimensions = null): array
    {
        $response = $this->client->post('embeddings', [
            'json' => [
                'input' => $texts,
                'model' => $this->embeddingModel,
                'encoding_format' => 'float',
                'dimensions' => $dimensions ?? 1536,
            ],
        ]);

        return json_decode($response?->getBody()->getContents() ?? '[]', true)['data'] ?? [];
    }

    public function createEmbedding($text, ?int $dimensions = null): array
    {
        return $this->createEmbeddings([$text], $dimensions)[0]['embedding'] ?? [];
    }
}
