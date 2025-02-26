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
        $chunks = array_chunk($embeddings, 100);
        foreach ($chunks as $chunk) {
            $this->body['vectors'] = $chunk;
            $this->client->post('vectors/upsert', [
                'json' => $this->body,
            ]);
        }
    }

    public function storeEmbedding(int $id, array $embedding, array $meta = []): void
    {
        $this->storeEmbeddings([
            ['id' => 'i'.$id, 'values' => $embedding, 'metadata' => $meta],
        ]);
    }

    public function query(int $id, array $vector, $topK = 5, $includeValues = false, $includeMetadata = true): string
    {
        return $this->client->post('query', [
            'json' => [
                'topK' => $topK,
                'filter' => [
                    'id' => [
                        '$lte' => $id,
                    ],
                ],
                'namespace' => $this->body['namespace'] ?? '',
                'includeValues' => $includeValues,
                'includeMetadata' => $includeMetadata,
                'vector' => $vector,
            ],
        ])->getBody()->getContents();
    }

    public function deleteEmbeddings()
    {
        $this->body['deleteAll'] = 'true';
        return $this->client->post('vectors/delete', [
            'json' => $this->body,
        ]);
    }

}
