<?php

namespace ThaKladd\VectorLite\Enums;

/**
 * Whether a text is embedded as something to be found (a document) or as
 * something to search with (a query). Retrieval models such as
 * Qwen3-Embedding and EmbeddingGemma are asymmetric and expect a different
 * prompt prefix for each side - see vector-lite.embeddings.query_prefix
 * and vector-lite.embeddings.document_prefix.
 */
enum EmbeddingInputType: string
{
    case Document = 'document';
    case Query = 'query';
}
