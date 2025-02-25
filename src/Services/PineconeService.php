<?php

namespace ThaKladd\VectorLite\Services;

use GuzzleHttp\Client;

class PineconeService
{
    private Client $client;

    private array $body = [];

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => config('vector-lite.pinecone.base_url'),
            'timeout' => 3000,
            'connect_timeout' => 3000,
            'curl' => [
                CURLOPT_CONNECTTIMEOUT => 3000000,
                CURLOPT_CONNECTTIMEOUT_MS => 3000000,
            ],
            'headers' => [
                'Api-Key' => config('vector-lite.pinecone.api_key'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function namespace(string $namespace): self
    {
        $this->body['namespace'] = $namespace;

        return $this;
    }

    public function storeEmbeddings(array $embeddings): void
    {
        $this->body['vectors'] = $embeddings;
        $this->client->post('vectors/upsert', [
            'json' => $this->body,
        ]);
    }

    public function storeEmbedding(int $id, array $embedding, array $meta = []): void
    {
        $this->storeEmbeddings([
            ['id' => $id, 'values' => $embedding, 'metadata' => $meta],
        ]);
    }

    public function query(int $id, array $vector, $topK = 5, $includeValues = false, $includeMetadata = true)
    {
        return $this->client->post('query', [
            'json' => [
                'topK' => $topK,
                'filter' => [
                    '$and' => [
                        ['id' => $id],
                    ],
                ],
                'includeValues' => $includeValues,
                'includeMetadata' => $includeMetadata,
                'vector' => $vector,
            ],
        ]);
    }

    public function deleteEmbeddings()
    {
        $this->body['deleteAll'] = 'true';

        return $this->client->post('vectors/delete', [
            'json' => $this->body,
        ]);
    }
}
