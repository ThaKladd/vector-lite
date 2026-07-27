<?php

use ThaKladd\VectorLite\Collections\VectorModelCollection;
use ThaKladd\VectorLite\Support\ClusterCache;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Vector;

uses(VectorTestHelpers::class);

beforeEach(function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
});

it('wraps a single model, an iterable, or null into a VectorModelCollection', function () {
    $this->fillVectorTable(3, 36);
    $vectorModel = Vector::query()->first();

    expect(VectorModelCollection::wrap($vectorModel))->toBeInstanceOf(VectorModelCollection::class);
    expect(VectorModelCollection::wrap($vectorModel))->toHaveCount(1);
    expect(VectorModelCollection::wrap(null))->toHaveCount(0);
    expect(VectorModelCollection::wrap([$vectorModel, $vectorModel]))->toHaveCount(2);
});

it('annotates every item with a similarity via withSimilarities()', function () {
    $this->fillVectorTable(10, 36);
    $vectorModel = Vector::query()->first();
    $all = Vector::all();

    $withSimilarities = $all->withSimilarities($vectorModel);

    expect($withSimilarities->pluck('similarity')->filter(fn ($similarity) => $similarity !== null))
        ->toHaveCount($all->count());
});

it('plucks similarities against an explicit vector argument', function () {
    $this->fillVectorTable(10, 36);
    $vectorModel = Vector::query()->first();
    $all = Vector::all();

    $similarities = $all->pluckSimilarities($vectorModel);

    expect($similarities)->toHaveCount($all->count());
    expect(array_key_exists($vectorModel->id, $similarities))->toBeTrue();
    expect($similarities[$vectorModel->id])->toBeGreaterThan(0.99);
});

it('removes given models from a plain collection with withoutModels()', function () {
    $this->fillVectorTable(5, 36);
    $all = Vector::all();
    $excluded = $all->first();

    $filtered = $all->withoutModels($excluded);

    expect($filtered)->toHaveCount($all->count() - 1);
    expect($filtered->pluck('id')->all())->not->toContain($excluded->id);
});

it('validates the sort direction in sortBySimilarityToVector()', function () {
    $this->fillVectorTable(5, 36);
    $vectorModel = Vector::query()->first();
    $all = Vector::all();

    expect(fn () => $all->sortBySimilarityToVector($vectorModel, 'upwards'))
        ->toThrow(InvalidArgumentException::class);
});

it('treats a model with no vector as least similar (-1)', function () {
    $this->fillVectorTable(3, 36);
    $vectorModel = Vector::query()->first();

    $noVectorModel = new Vector(['title' => 'no vector']);
    $noVectorModel->id = 999999;

    $all = Vector::all()->push($noVectorModel);
    $similarities = $all->pluckSimilarities($vectorModel);

    expect($similarities[999999])->toBe(-1.0);
});
