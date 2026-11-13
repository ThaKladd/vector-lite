<?php

use Illuminate\Support\Facades\Http;
use ThaKladd\VectorLite\Enums\ReduceBy;
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

it('delegates NEW_EMBEDDING reduction to the EmbeddingService as a document embedding', function () {
    config()->set('vector-lite.embeddings.document_prefix', 'doc: ');
    config()->set('vector-lite.embeddings.query_prefix', 'query: ');
    $this->fakeEmbeddingVectors(['doc: some text' => [0.1, 0.2, 0.3, 0.4]]);

    $reduced = ReduceBy::NEW_EMBEDDING->reduceVector('some text', 4);

    expect($reduced)->toBe([0.1, 0.2, 0.3, 0.4]);
    Http::assertSent(fn ($request) => $request['dimensions'] === 4);
});

it('gives every input/output dimension pair its own projection matrix, never a stale one', function () {
    $reducedFrom19 = ReduceBy::RPM->reduceVector($this->createVectorArray(19), 5);
    $reducedFrom23 = ReduceBy::RPM->reduceVector($this->createVectorArray(23), 5);

    expect($reducedFrom19)->toHaveCount(5);
    expect($reducedFrom23)->toHaveCount(5);
    expect(file_exists(storage_path('app/random_project_19_5.json')))->toBeTrue();
    expect(file_exists(storage_path('app/random_project_23_5.json')))->toBeTrue();
});

it('regenerates a cached projection matrix whose shape does not match its dimension pair', function () {
    $path = storage_path('app/random_project_17_3.json');
    file_put_contents($path, json_encode([[1, 0], [0, 1]])); // 2x2, not 3x17

    $reduced = ReduceBy::RPM->reduceVector($this->createVectorArray(17), 3);

    $matrix = json_decode(file_get_contents($path), true);
    expect($reduced)->toHaveCount(3);
    expect($matrix)->toHaveCount(3);
    expect($matrix[0])->toHaveCount(17);

    unlink($path);
});
