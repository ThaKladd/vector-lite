<?php

use ThaKladd\VectorLite\Support\ClusterCache;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\VectorLite;

uses(VectorTestHelpers::class);

beforeEach(function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
});

it('computes the dot product of two arrays', function () {
    expect(VectorLite::dotProduct([1, 2, 3], [4, 5, 6]))->toBe(32.0);
});

it('hashes an array vector the same as its already-packed binary form', function () {
    $vector = $this->createVectorArray(16);
    [$binaryVector] = VectorLite::normalizeToBinary($vector);

    expect(VectorLite::hashVectorBlob($vector))->toBe(VectorLite::hashVectorBlob($binaryVector));
});

it('produces different hashes for different text/vectors', function () {
    expect(VectorLite::hashText('hello'))->not->toBe(VectorLite::hashText('world'));
});

it('resolves vectorToArray()/vectorToBinary() for a VectorModel using its configured vector column', function () {
    $this->fillVectorTable(1, 16);
    $vectorModel = Vector::query()->first();

    $asArray = VectorLite::vectorToArray($vectorModel);
    $asBinary = VectorLite::vectorToBinary($vectorModel);

    expect($asArray)->toBeArray();
    expect($asBinary)->toBe($vectorModel->vector);
    expect(VectorLite::binaryVectorToArray($asBinary))->toEqual($asArray);
});

it('normalizes and denormalizes a vector back to its original values', function () {
    $vector = $this->createVectorArray(10);

    [$normalized, $norm] = VectorLite::normalize($vector);
    $denormalized = VectorLite::denormalize($normalized, $norm);

    foreach ($vector as $i => $value) {
        expect(round($denormalized[$i], 4))->toBe(round($value, 4));
    }
});
