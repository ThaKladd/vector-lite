<?php

use ThaKladd\VectorLite\Enums\ReduceBy;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;

uses(VectorTestHelpers::class);

it('reuses the cached random projection matrix for identical dimension pairs', function () {
    $vectorA = $this->createVectorArray(24);
    $vectorB = $this->createVectorArray(24);

    $reducedA1 = ReduceBy::RPM->reduceVector($vectorA, 8);
    $reducedA2 = ReduceBy::RPM->reduceVector($vectorA, 8);
    $reducedB = ReduceBy::RPM->reduceVector($vectorB, 8);

    expect($reducedA1)->toHaveCount(8);
    // Same input through the same cached projection matrix -> identical output.
    expect($reducedA1)->toBe($reducedA2);
    // Different input through the same matrix -> different output.
    expect($reducedA1)->not->toBe($reducedB);
});

it('aggregates chunks with CHUNKED_AGGREGATION, dropping any remainder when dimensions do not divide evenly', function () {
    $vector = range(1, 10); // 10 values into 3 chunks of 3 (floor(10/3)=3); the 10th value is dropped.
    $reduced = ReduceBy::CHUNKED_AGGREGATION->reduceVector($vector, 3);

    expect($reduced)->toBe([2, 5, 8]);
});

it('delegates NEW_EMBEDDING reduction to the EmbeddingService', function () {
    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbedding')
        ->once()
        ->with('some text', 4)
        ->andReturn([0.1, 0.2, 0.3, 0.4]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $reduced = ReduceBy::NEW_EMBEDDING->reduceVector('some text', 4);

    expect($reduced)->toBe([0.1, 0.2, 0.3, 0.4]);
});
