<?php

use Illuminate\Support\Facades\DB;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Support\ClusterCache;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Vector;

uses(VectorTestHelpers::class);

beforeEach(function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
});

it('includes only the given models with includeModels()', function () {
    $this->fillVectorTable(10, 36);
    $subset = Vector::query()->orderBy('id')->limit(3)->get();

    $result = Vector::query()->includeModels($subset)->get();

    expect($result)->toHaveCount(3);
    expect($result->pluck('id')->sort()->values()->all())
        ->toBe($subset->pluck('id')->sort()->values()->all());
});

it('leaves the query untouched when includeModels() is given an empty list', function () {
    $this->fillVectorTable(5, 36);

    $result = Vector::query()->includeModels(null)->get();

    expect($result)->toHaveCount(5);
});

it('force-includes a model via orIncludeModels() even if the base query excludes it', function () {
    $this->fillVectorTable(10, 36);
    $included = Vector::query()->orderBy('id')->first();

    $result = Vector::query()->whereRaw('1 = 0')->orIncludeModels($included)->get();

    expect($result->pluck('id')->all())->toBe([$included->id]);
});

it('excludes the current model instance with withoutSelf()', function () {
    $this->fillVectorTable(5, 36);
    $vectorModel = Vector::query()->first();

    $result = $vectorModel->newQuery()->withoutSelf()->get();

    expect($result->pluck('id')->all())->not->toContain($vectorModel->id);
});

it('re-includes the current model with includeSelf() even when otherwise excluded', function () {
    $this->fillVectorTable(5, 36);
    $vectorModel = Vector::query()->first();

    $result = $vectorModel->newQuery()->whereRaw('1 = 0')->includeSelf()->get();

    expect($result->pluck('id')->all())->toBe([$vectorModel->id]);
});

it('filters with whereVectorBetween() min/max bounds', function () {
    $this->fillVectorTable(20, 36);
    $vectorModel = Vector::query()->first();

    $withinFullRange = Vector::query()->whereVectorBetween($vectorModel, -1.0, 1.0)->get();
    $outsidePossibleRange = Vector::query()->whereVectorBetween($vectorModel, 2.0, 3.0)->get();

    expect($withinFullRange)->not->toBeEmpty();
    expect($outsidePossibleRange)->toBeEmpty();
});

it('rejects an invalid operator passed to whereVector()', function () {
    $this->fillVectorTable(5, 36);
    $vectorModel = Vector::query()->first();

    expect(fn () => Vector::query()->whereVector($vectorModel, 'DROP TABLE', 0.5))
        ->toThrow(InvalidArgumentException::class);
});

it('filters using havingSimilarity() with an explicit vector when no similarity column is selected', function () {
    $this->fillVectorTable(10, 36);
    $vectorModel = Vector::query()->first();

    // SQLite rejects a HAVING clause on a non-aggregate query without a
    // GROUP BY - grouping by the primary key keeps one row per group.
    $result = Vector::query()->groupBy('vectors.id')->havingSimilarity($vectorModel, '>=', -1.0)->get();
    expect($result)->not->toBeEmpty();

    $none = Vector::query()->groupBy('vectors.id')->havingSimilarity($vectorModel, '>=', 2.0)->get();
    expect($none)->toBeEmpty();
});

it('filters using the havingSimilarity() alias branch once a similarity column is selected', function () {
    $this->fillVectorTable(10, 36);
    $vectorModel = Vector::query()->first();

    $result = Vector::query()
        ->groupBy('vectors.id')
        ->selectSimilarity($vectorModel)
        ->havingSimilarity(threshold: -1.0)
        ->get();

    expect($result)->not->toBeEmpty();
    expect($result->first()->similarity)->not->toBeNull();
});

it('orders ascending via orderBySimilarity() and validates the direction', function () {
    $this->fillVectorTable(10, 36);
    $vectorModel = Vector::query()->first();

    $ascending = Vector::query()->selectSimilarity($vectorModel)->orderBySimilarity(direction: 'asc')->get();

    expect($ascending->first()->similarity)->toBeLessThanOrEqual($ascending->last()->similarity);

    expect(fn () => Vector::query()->orderBySimilarity($vectorModel, 'sideways'))
        ->toThrow(InvalidArgumentException::class);
});

it('gets the closest clusters for a vector via getBestClustersByVector()', function () {
    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbedding')
        ->with(Mockery::type('string'), Mockery::type('int'))
        ->andReturnUsing(function ($text, $dim) {
            return $this->createVectorArray($dim);
        });
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $this->fillVectorClusterTable(200, 36);
    $vectorModel = Vector::query()->first();

    $clusters = Vector::query()->getBestClustersByVector($vectorModel->vector, 3);

    expect($clusters)->toHaveCount(3);
    expect($clusters->first()->similarity)->toBeGreaterThanOrEqual($clusters->last()->similarity);
});

it('vectorQuery() returns a working builder on SQLite but throws off of it', function () {
    $this->fillVectorTable(3, 36);
    $vectorModel = Vector::query()->first();

    expect($vectorModel->vectorQuery())->toBeInstanceOf(VectorLiteQueryBuilder::class);

    config()->set('database.default', 'mysql');
    DB::purge();
    DB::reconnect();
    VectorLiteConfig::reset();

    $mysqlVectorModel = new Vector;

    expect(fn () => $mysqlVectorModel->vectorQuery())->toThrow(RuntimeException::class);
});
