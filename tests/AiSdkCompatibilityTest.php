<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ThaKladd\VectorLite\Embeddings;
use ThaKladd\VectorLite\Reranking;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Support\ClusterCache;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Document;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\Tools\SimilaritySearch;

uses(VectorTestHelpers::class);

it('reshapes vectorLite() to accept dimensions like core vector()', function () {
    // TestCase::setUpDatabase() already builds "documents" via
    // $table->vectorLite('embedding', dimensions: 6); this just proves the
    // schema round-trips real data through the reshaped macro.
    $this->assertTrue(DB::getSchemaBuilder()->hasTable('documents'));
    $this->assertTrue(DB::getSchemaBuilder()->hasColumn('documents', 'embedding'));

    $document = new Document(['title' => 'doc']);
    $document->embedding = [1.0, 2.0, 3.0, 4.0];
    $document->save();

    $this->assertNotEmpty($document->refresh()->embedding);
});

it('resolves a per-model vector column name instead of the global default', function () {
    expect(Document::vectorColumn())->toBe('embedding');
    expect(Vector::vectorColumn())->toBe('vector');
});

it('round-trips a vector array through the AsVector cast', function () {
    $document = new Document(['title' => 'cast test']);
    $document->embedding = [0.5, -0.5, 1.0, 0.0];
    $document->save();

    $fresh = Document::find($document->id);

    expect($fresh->embedding)->each->toBeFloat();
    foreach ($fresh->embedding as $i => $value) {
        expect(round($value, 4))->toBe(round([0.5, -0.5, 1.0, 0.0][$i], 4));
    }
    expect($fresh->embedding_hash)->not->toBeNull();
    expect($fresh->embedding_norm)->toBeGreaterThan(0);
});

it('supports whereVectorSimilarTo, selectVectorDistance, whereVectorDistanceLessThan and orderByVectorDistance', function () {
    $match = new Document(['title' => 'match']);
    $match->embedding = [1, 0, 0, 0];
    $match->save();

    $other = new Document(['title' => 'other']);
    $other->embedding = [0, 1, 0, 0];
    $other->save();

    $query = [1, 0, 0, 0];

    $similar = Document::query()->whereVectorSimilarTo('embedding', $query, minSimilarity: 0.99)->get();
    expect($similar->pluck('title')->all())->toBe(['match']);

    // selectVectorDistance() is additive (like Laravel's AI SDK), so an
    // explicit select('*') is needed to keep the other columns too.
    $withDistance = Document::query()->select('*')->selectVectorDistance('embedding', $query, as: 'distance')->orderBy('distance')->get();
    expect(round($withDistance->first()->distance, 4))->toBe(0.0);
    expect($withDistance->first()->title)->toBe('match');

    $close = Document::query()->whereVectorDistanceLessThan('embedding', $query, maxDistance: 0.01)->get();
    expect($close->pluck('title')->all())->toBe(['match']);

    $ordered = Document::query()->orderByVectorDistance('embedding', $query)->get();
    expect($ordered->first()->title)->toBe('match');
    expect($ordered->last()->title)->toBe('other');
});

it('fixes whereVector()/whereVectorBetween() to accept a model instance without a TypeError', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbedding')
        ->with(Mockery::type('string'), Mockery::type('int'))
        ->andReturnUsing(function ($text, $dim) {
            return $this->createVectorArray($dim);
        });
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $this->fillVectorTable(5, 36);
    $vectorModel = Vector::query()->first();

    $result = Vector::query()->whereVector($vectorModel, '>=', -1.0)->get();
    expect($result)->not->toBeEmpty();

    $result2 = Vector::query()->whereVectorBetween($vectorModel, -1.0, 1.0)->get();
    expect($result2)->not->toBeEmpty();
});

it('AI SDK query methods exist but fail at execution time on mysql (COSIM is a SQLite-only function) - mysql', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    useMySqlConnection();
    $this->setUpDatabase();

    // VectorModel::newEloquentBuilder() always returns VectorLiteQueryBuilder
    // regardless of driver, so the method itself exists on any connection -
    // it only fails once COSIM/COSIM_CACHE actually get executed, since those
    // are registered as SQLite-only functions in VectorLiteServiceProvider.
    expect(fn () => Vector::query()->whereVectorSimilarTo('vector', [1, 0], 0.5)->get())
        ->toThrow(QueryException::class);
});

it('caches embeddings via Embeddings::for()->cache() and reuses them on the next call', function () {
    VectorLiteConfig::reset();
    config()->set('cache.stores.array', ['driver' => 'array']);
    config()->set('vector-lite.caching.embeddings.store', 'array');

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService->shouldReceive('setModel')->withAnyArgs();
    $mockEmbeddingService
        ->shouldReceive('createEmbeddings')
        ->once()
        ->andReturn([['embedding' => [0.1, 0.2, 0.3]]]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $first = Embeddings::for(['hello world'])->cache()->generate()->embeddings[0];
    $second = Embeddings::for(['hello world'])->cache()->generate()->embeddings[0];

    expect($first)->toBe([0.1, 0.2, 0.3]);
    expect($second)->toBe($first);
});

it('generates embeddings via Str::toEmbeddings()', function () {
    VectorLiteConfig::reset();
    config()->set('vector-lite.caching.embeddings.cache', false);

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService->shouldReceive('setModel')->withAnyArgs();
    $mockEmbeddingService
        ->shouldReceive('createEmbeddings')
        ->once()
        ->with(['Napa Valley has great wine.'], Mockery::any())
        ->andReturn([['embedding' => [0.4, 0.5]]]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $embedding = Str::of('Napa Valley has great wine.')->toEmbeddings();

    expect($embedding)->toBe([0.4, 0.5]);
});

it('reranks documents by cosine similarity to a query', function () {
    VectorLiteConfig::reset();

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbeddings')
        ->once()
        ->andReturn([
            ['embedding' => [1, 0]], // query
            ['embedding' => [0, 1]], // "unrelated text"
            ['embedding' => [1, 0]], // "matching text"
        ]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $results = Reranking::of(['unrelated text', 'matching text'])->rerank('query text');

    expect($results->first()->document)->toBe('matching text');
    expect($results->first()->index)->toBe(1);
    expect($results->first()->score)->toBeGreaterThan($results->last()->score);
});

it('reranks a plain collection via the ->rerank() macro', function () {
    VectorLiteConfig::reset();

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbeddings')
        ->once()
        ->andReturn([
            ['embedding' => [1, 0]], // query
            ['embedding' => [0, 1]], // post A
            ['embedding' => [1, 0]], // post B
        ]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $posts = collect([
        ['title' => 'A', 'body' => 'unrelated'],
        ['title' => 'B', 'body' => 'matching'],
    ]);

    $reranked = $posts->rerank('body', 'query text');

    expect($reranked->first()['title'])->toBe('B');
});

it('runs a similarity search via the SimilaritySearch tool', function () {
    VectorLiteConfig::reset();

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbedding')
        ->with('find matching', Mockery::type('int'))
        ->andReturn([1, 0, 0, 0]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $match = new Document(['title' => 'match']);
    $match->embedding = [1, 0, 0, 0];
    $match->save();

    $other = new Document(['title' => 'other']);
    $other->embedding = [0, 1, 0, 0];
    $other->save();

    $tool = SimilaritySearch::usingModel(Document::class, 'embedding', minSimilarity: 0.9);
    $results = $tool('find matching');

    expect($results->pluck('title')->all())->toBe(['match']);
});

it('clears the vector, norm and hash columns when the AsVector cast is set to null', function () {
    $document = new Document(['title' => 'to be cleared']);
    $document->embedding = [1.0, 2.0, 3.0, 4.0];
    $document->save();

    $document->embedding = null;
    $document->save();

    $fresh = $document->refresh();

    expect($fresh->embedding)->toBeNull();
    expect($fresh->embedding_norm)->toBeNull();
    expect($fresh->embedding_hash)->toBeNull();
});

it('only fetches inputs that are not already cached from Embeddings::for()', function () {
    VectorLiteConfig::reset();
    config()->set('cache.stores.array', ['driver' => 'array']);
    config()->set('vector-lite.caching.embeddings.store', 'array');

    $firstMock = Mockery::mock(EmbeddingService::class);
    $firstMock->shouldReceive('setModel')->withAnyArgs();
    $firstMock
        ->shouldReceive('createEmbeddings')
        ->once()
        ->with(['hello world'], Mockery::any())
        ->andReturn([['embedding' => [0.1, 0.2, 0.3]]]);
    $this->app->instance(EmbeddingService::class, $firstMock);

    Embeddings::for(['hello world'])->cache()->generate();

    $secondMock = Mockery::mock(EmbeddingService::class);
    $secondMock->shouldReceive('setModel')->withAnyArgs();
    $secondMock
        ->shouldReceive('createEmbeddings')
        ->once()
        ->with(['goodbye world'], Mockery::any())
        ->andReturn([['embedding' => [0.4, 0.5, 0.6]]]);
    $this->app->instance(EmbeddingService::class, $secondMock);

    // "hello world" is already cached from the call above, so only
    // "goodbye world" should be sent to the (new) mock's createEmbeddings().
    $response = Embeddings::for(['hello world', 'goodbye world'])->cache()->generate();

    expect($response->embeddings[0])->toBe([0.1, 0.2, 0.3]);
    expect($response->embeddings[1])->toBe([0.4, 0.5, 0.6]);
});

it('passes a custom timeout through Embeddings::timeout() to the EmbeddingService', function () {
    VectorLiteConfig::reset();
    config()->set('vector-lite.caching.embeddings.cache', false);

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService->shouldReceive('setModel')->withAnyArgs();
    $mockEmbeddingService->shouldReceive('setTimeout')->once()->with(5);
    $mockEmbeddingService
        ->shouldReceive('createEmbeddings')
        ->once()
        ->andReturn([['embedding' => [0.1]]]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    Embeddings::for(['hi'])->timeout(5)->generate();
});

it('caches an embedding generated via Str::toEmbeddings(cache: true)', function () {
    VectorLiteConfig::reset();
    config()->set('cache.stores.array', ['driver' => 'array']);
    config()->set('vector-lite.caching.embeddings.store', 'array');

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService->shouldReceive('setModel')->withAnyArgs();
    $mockEmbeddingService
        ->shouldReceive('createEmbeddings')
        ->once()
        ->andReturn([['embedding' => [0.7, 0.8]]]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $first = Str::toEmbeddings('cache me please', cache: true);
    $second = Str::toEmbeddings('cache me please', cache: true);

    expect($first)->toBe([0.7, 0.8]);
    expect($second)->toBe($first);
});

it('limits reranked results via Reranking::limit()', function () {
    VectorLiteConfig::reset();

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbeddings')
        ->once()
        ->andReturn([
            ['embedding' => [1, 0]], // query
            ['embedding' => [1, 0]], // "closest"
            ['embedding' => [0.9, 0.1]], // "second"
            ['embedding' => [0, 1]], // "furthest"
        ]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $results = Reranking::of(['closest', 'second', 'furthest'])->limit(2)->rerank('query text');

    expect($results)->toHaveCount(2);
    expect($results->pluck('document')->all())->toBe(['closest', 'second']);
});

it('reranks via the ->rerank() macro using an array of fields or a closure extractor', function () {
    VectorLiteConfig::reset();

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbeddings')
        ->twice()
        ->andReturn([
            ['embedding' => [1, 0]], // query
            ['embedding' => [0, 1]], // post A ("unrelated")
            ['embedding' => [1, 0]], // post B ("matching")
        ]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $posts = collect([
        ['title' => 'A', 'body' => 'unrelated'],
        ['title' => 'B', 'body' => 'matching'],
    ]);

    $rerankedByFields = $posts->rerank(['title', 'body'], 'query text');
    expect($rerankedByFields->first()['title'])->toBe('B');

    $rerankedByClosure = $posts->rerank(fn ($post) => $post['body'], 'query text');
    expect($rerankedByClosure->first()['title'])->toBe('B');
});

it('exposes a default and a customizable description on SimilaritySearch', function () {
    $tool = SimilaritySearch::usingModel(Document::class, 'embedding');

    expect($tool->description())->toBe('Search for content similar to a given query.');

    $tool->withDescription('Find related docs.');
    expect($tool->description())->toBe('Find related docs.');
});

it('limits results and lets a custom query closure narrow the SimilaritySearch tool', function () {
    VectorLiteConfig::reset();

    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbedding')
        ->with('find matching', Mockery::type('int'))
        ->andReturn([1, 0, 0, 0]);
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $match = new Document(['title' => 'match']);
    $match->embedding = [1, 0, 0, 0];
    $match->save();

    $matchTwo = new Document(['title' => 'match two']);
    $matchTwo->embedding = [1, 0, 0, 0];
    $matchTwo->save();

    $other = new Document(['title' => 'other']);
    $other->embedding = [0, 1, 0, 0];
    $other->save();

    $tool = SimilaritySearch::usingModel(
        Document::class,
        'embedding',
        minSimilarity: 0.9,
        limit: 1,
        query: fn ($builder) => $builder->where('title', 'match'),
    );

    $results = $tool('find matching');

    expect($results->pluck('title')->all())->toBe(['match']);
});

it('runs a similarity search via a custom `using` closure passed to the constructor', function () {
    $tool = new SimilaritySearch(using: fn (string $query) => Collection::make(["echo: {$query}"]));

    expect($tool('hello')->all())->toBe(['echo: hello']);
});
