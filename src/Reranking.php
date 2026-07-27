<?php

namespace ThaKladd\VectorLite;

use Illuminate\Support\Collection;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Support\RerankedDocument;

/**
 * Standalone equivalent of Laravel AI SDK's `Laravel\Ai\Reranking`. vector-lite
 * has no dedicated reranker model (laravel/ai uses Cohere/Jina/VoyageAI), so
 * this scores documents by cosine similarity between embeddings of the query
 * and each document (via the existing EmbeddingService) - an embedding-cosine
 * reranking, not a cross-encoder reranker; scores won't match a real reranker
 * numerically, but ordering is a reasonable standalone approximation.
 */
final class Reranking
{
    protected ?int $limit = null;

    /**
     * @param  array<int, string>  $documents
     */
    private function __construct(protected array $documents) {}

    /**
     * @param  array<int, string>  $documents
     */
    public static function of(array $documents): static
    {
        return new self($documents);
    }

    public function limit(?int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * $provider is accepted (and ignored) purely so calls written against
     * Laravel's AI SDK - e.g. ->rerank('query', provider: Lab::Cohere) -
     * don't error out standalone.
     *
     * @return Collection<int, RerankedDocument>
     */
    public function rerank(string $query, mixed $provider = null): Collection
    {
        $documents = array_values($this->documents);
        $dimensions = config('vector-lite.default_dimensions');

        $service = app(EmbeddingService::class);
        $allEmbeddings = array_column($service->createEmbeddings([$query, ...$documents], $dimensions), 'embedding');
        [$queryEmbedding, $documentEmbeddings] = [$allEmbeddings[0], array_slice($allEmbeddings, 1)];
        [$normalizedQuery] = VectorLite::normalize($queryEmbedding);

        $results = collect($documents)->map(function ($document, $index) use ($documentEmbeddings, $normalizedQuery) {
            [$normalizedDocument] = VectorLite::normalize($documentEmbeddings[$index]);

            return new RerankedDocument(
                document: $document,
                score: VectorLite::cosineSimilarity($normalizedQuery, $normalizedDocument),
                index: $index,
            );
        })->sortByDesc('score')->values();

        if ($this->limit !== null) {
            $results = $results->take($this->limit);
        }

        return $results->values();
    }
}
