<?php

namespace ThaKladd\VectorLite\Support;

/**
 * Return value of Embeddings::generate(), mirroring the shape of
 * Laravel AI SDK's embeddings response (`$response->embeddings`).
 */
class EmbeddingsResponse
{
    /**
     * @param  array<int, array<int, float>>  $embeddings
     */
    public function __construct(public readonly array $embeddings) {}
}
