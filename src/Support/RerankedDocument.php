<?php

namespace ThaKladd\VectorLite\Support;

/**
 * One result of Reranking::of([...])->rerank(...), mirroring the shape of
 * Laravel AI SDK's reranking result (->document / ->score / ->index).
 */
class RerankedDocument
{
    public function __construct(
        public readonly mixed $document,
        public readonly float $score,
        public readonly int $index,
    ) {}
}
