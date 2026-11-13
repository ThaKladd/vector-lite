<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ThaKladd\VectorLite\Embeddings;
use ThaKladd\VectorLite\Enums\EmbeddingInputType;
use ThaKladd\VectorLite\Exceptions\EmbeddingException;
use ThaKladd\VectorLite\Reranking;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Support\ClusterCache;
use ThaKladd\VectorLite\Support\EmbeddingConfig;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Document;
use ThaKladd\VectorLite\Tests\Models\Vector;

uses(VectorTestHelpers::class);

beforeEach(function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
});

/**
 * Points vector-lite at a keyless, Ollama-style local endpoint.
 */
function useLocalEmbeddingEndpoint(array $overrides = []): void
{
    config()->set('vector-lite.embeddings', array_merge(config('vector-lite.embeddings'), [
        'url' => 'http://ollama.test:11434/v1/',
        'api_key' => null,
        'model' => 'qwen3-embedding:0.6b',
    ], $overrides));
}

/**
 * Fakes an endpoint that succeeds until $fail is flipped to true, then answers 503.
 */
function fakeFlakyEmbeddingApi(object $test, bool &$fail): void
{
    Http::fake(['*' => function (Request $request) use ($test, &$fail) {
        if ($fail) {
            return Http::response(['error' => ['message' => 'server is down']], 503);
        }

        $size = (int) ($request['dimensions'] ?? config('vector-lite.default_dimensions'));

        return Http::response((fn () => $this->embeddingResponse(
            array_map(fn () => $this->createVectorArray($size), (array) $request['input'])
        ))->call($test));
    }]);
}

// --- Configuration and the endpoint -------------------------------------

it('ships backwards-compatible defaults: OpenAI, text-embedding-3-small, no prefixes, throw on error', function () {
    $defaults = require __DIR__.'/../config/vector-lite.php';

    expect($defaults['default_dimensions'])->toBe(1536);
    expect($defaults['embeddings'])->toMatchArray([
        'url' => 'https://api.openai.com/v1/',
        'api_key' => null,
        'model' => 'text-embedding-3-small',
        'timeout' => 60,
        'send_dimensions' => true,
        'query_prefix' => '',
        'document_prefix' => '',
        'on_error' => 'throw',
    ]);
});

it('sends the default OpenAI request with the legacy vector-lite.openai.api_key', function () {
    $this->fakeEmbeddingApi();

    app(EmbeddingService::class)->createEmbedding('hello');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/embeddings'
        && $request->hasHeader('Authorization', 'Bearer 1234')
        && $request['model'] === 'text-embedding-3-small'
        && $request['dimensions'] === 36
        && $request['input'] === ['hello']
        && $request['encoding_format'] === 'float');
});

it('sends the configured url, model and api key', function () {
    config()->set('vector-lite.embeddings.url', 'https://embeddings.example.com/v1');
    config()->set('vector-lite.embeddings.api_key', 'secret-key');
    config()->set('vector-lite.embeddings.model', 'custom-model');
    $this->fakeEmbeddingApi();

    app(EmbeddingService::class)->createEmbedding('hello');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://embeddings.example.com/v1/embeddings'
        && $request->hasHeader('Authorization', 'Bearer secret-key')
        && $request['model'] === 'custom-model');
});

it('accepts the full /embeddings endpoint as the url too', function () {
    useLocalEmbeddingEndpoint(['url' => 'http://ollama.test:11434/v1/embeddings']);
    $this->fakeEmbeddingApi();

    app(EmbeddingService::class)->createEmbedding('hello');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/v1/embeddings');
});

it('works against a keyless local endpoint and never sends it the OpenAI key', function () {
    useLocalEmbeddingEndpoint();
    $this->fakeEmbeddingApi();

    $service = app(EmbeddingService::class);
    expect($service->isConfigured())->toBeTrue();
    expect($service->createEmbedding('hello'))->toHaveCount(36);

    $vector = Vector::create(['title' => 'embedded locally']);
    expect($vector->refresh()->vector)->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/v1/embeddings'
        && $request['model'] === 'qwen3-embedding:0.6b');
    Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization'));
});

it('sends "dimensions" only while send_dimensions is on', function () {
    $this->fakeEmbeddingApi(36);

    app(EmbeddingService::class)->createEmbedding('with dimensions');
    config()->set('vector-lite.embeddings.send_dimensions', false);
    app(EmbeddingService::class)->createEmbedding('without dimensions');

    $payloads = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
    expect($payloads[0])->toHaveKey('dimensions', 36);
    expect($payloads[1])->not->toHaveKey('dimensions');
});

it('throws a clear exception when the model returns a different length than configured', function () {
    config()->set('vector-lite.default_dimensions', 1536);
    useLocalEmbeddingEndpoint();
    $this->fakeEmbeddingApi(1024); // qwen3-embedding:0.6b tops out at 1024

    expect(fn () => app(EmbeddingService::class)->createEmbedding('hello'))
        ->toThrow(EmbeddingException::class, 'embedding model [qwen3-embedding:0.6b] returned 1024-dimensional vectors, but 1536 were expected');
});

it('wraps HTTP errors, unreachable servers and malformed responses in EmbeddingException', function () {
    $responses = [
        fn () => Http::response(['error' => ['message' => 'model "nope" not found']], 404),
        fn () => throw new ConnectionException('Connection refused'),
        fn () => Http::response(['object' => 'list']),
        fn () => Http::response(['data' => [['index' => 0, 'embedding' => 'AAAA']]]),
        fn () => Http::response(['data' => []]),
    ];
    Http::fake(['*' => function () use (&$responses) {
        return array_shift($responses)();
    }]);
    $service = app(EmbeddingService::class);

    expect(fn () => $service->createEmbedding('a'))->toThrow(EmbeddingException::class, 'returned HTTP 404');
    expect(fn () => $service->createEmbedding('a'))->toThrow(EmbeddingException::class, 'could not reach the embedding endpoint');
    expect(fn () => $service->createEmbedding('a'))->toThrow(EmbeddingException::class, 'it has no "data" list');
    expect(fn () => $service->createEmbedding('a'))->toThrow(EmbeddingException::class, 'missing or not a list of floats');
    expect(fn () => $service->createEmbedding('a'))->toThrow(EmbeddingException::class, 'holds 0 embeddings for 1 inputs');
});

it('returns the embeddings in input order, following the response "index"', function () {
    config()->set('vector-lite.default_dimensions', 2);
    Http::fake(['*' => Http::response(['data' => [
        ['index' => 1, 'embedding' => [0.0, 1.0]],
        ['index' => 0, 'embedding' => [1.0, 0.0]],
    ]])]);

    $rows = app(EmbeddingService::class)->createEmbeddings(['first', 'second']);

    expect(array_column($rows, 'embedding'))->toBe([[1.0, 0.0], [0.0, 1.0]]);
});

it('treats OpenAI without any API key as unconfigured: saving skips embedding instead of a TypeError', function () {
    config()->set('vector-lite.openai.api_key', null);
    Http::fake();

    $vector = Vector::create(['title' => 'no key yet']);

    expect($vector->createEmbedding())->toBeNull();
    expect($vector->refresh()->vector)->toBeNull();
    expect($vector->embed_hash)->toBeNull();
    Http::assertNothingSent();

    expect(fn () => app(EmbeddingService::class)->createEmbedding('search text'))
        ->toThrow(EmbeddingException::class, 'no embedding provider is configured');
});

// --- Query vs document prefixes, per call site --------------------------

it('applies the document prefix when a model is embedded on save', function () {
    config()->set('vector-lite.embeddings.document_prefix', 'doc: ');
    config()->set('vector-lite.embeddings.query_prefix', 'query: ');
    $this->fakeEmbeddingApi();

    $vector = Vector::create(['title' => 'Running shoes']);

    $inputs = $this->sentEmbeddingInputs();
    expect($inputs)->toHaveCount(1);
    expect($inputs[0][0])->toStartWith('doc: <vector');
    expect($inputs[0][0])->toContain('<title>Running shoes</title>');
    expect($vector->vector)->not->toBeNull();
});

it('applies the query prefix to natural-language text searches in the query builder', function () {
    config()->set('vector-lite.embeddings.document_prefix', 'doc: ');
    config()->set('vector-lite.embeddings.query_prefix', 'query: ');
    $this->fakeEmbeddingApi();

    Document::query()->whereVectorSimilarTo('embedding', 'red running shoes', 0.5)->get();
    Document::query()->orderByVectorDistance('embedding', 'blue sandals')->get();

    expect($this->sentEmbeddingInputs())->toBe([['query: red running shoes'], ['query: blue sandals']]);
});

it('embeds the reranking query as a query and the documents as documents, in one request', function () {
    config()->set('vector-lite.embeddings.document_prefix', 'doc: ');
    config()->set('vector-lite.embeddings.query_prefix', 'query: ');
    config()->set('vector-lite.default_dimensions', 2);
    $this->fakeEmbeddingVectors([
        'query: wine' => [1, 0],
        'doc: beer' => [0, 1],
        'doc: red wine' => [1, 0],
    ]);

    $results = Reranking::of(['beer', 'red wine'])->rerank('wine');

    expect($results->first()->document)->toBe('red wine');
    expect($this->sentEmbeddingInputs())->toBe([['query: wine', 'doc: beer', 'doc: red wine']]);
});

it('embeds Embeddings::for() inputs as documents by default and as queries with asQuery()', function () {
    config()->set('vector-lite.embeddings.document_prefix', 'doc: ');
    config()->set('vector-lite.embeddings.query_prefix', 'query: ');
    $this->fakeEmbeddingApi();

    Embeddings::for(['first', 'second'])->generate();
    Embeddings::for(['what is first?'])->asQuery()->generate();
    Str::toEmbeddings('a stored text');

    expect($this->sentEmbeddingInputs())->toBe([
        ['doc: first', 'doc: second'],
        ['query: what is first?'],
        ['doc: a stored text'],
    ]);
});

it('mixes input types per text in one batched service call', function () {
    config()->set('vector-lite.embeddings.document_prefix', 'doc: ');
    config()->set('vector-lite.embeddings.query_prefix', 'query: ');
    $this->fakeEmbeddingApi();

    app(EmbeddingService::class)->createEmbeddings(
        ['q', 'd'],
        null,
        [EmbeddingInputType::Query, EmbeddingInputType::Document]
    );

    expect($this->sentEmbeddingInputs())->toBe([['query: q', 'doc: d']]);
});

// --- Embeddings::generate($provider, $model) ----------------------------

it('uses the $provider and $model arguments of Embeddings::generate()', function () {
    config()->set('vector-lite.embeddings.api_key', 'main-key');
    config()->set('vector-lite.embeddings.providers', [
        'ollama' => ['url' => 'http://ollama.test:11434/v1/', 'model' => 'qwen3-embedding:0.6b'],
    ]);
    $this->fakeEmbeddingApi();

    Embeddings::for(['a'])->generate('ollama');
    Embeddings::for(['a'])->generate('ollama', 'qwen3-embedding:4b');
    Embeddings::for(['a'])->generate(null, 'text-embedding-3-large');
    Embeddings::for(['a'])->generate('OpenAI');

    $sent = Http::recorded()->map(fn ($pair) => [
        $pair[0]->url(),
        $pair[0]['model'],
        $pair[0]->header('Authorization')[0] ?? null,
    ])->values()->all();

    expect($sent)->toBe([
        // A provider on another host never inherits the main key.
        ['http://ollama.test:11434/v1/embeddings', 'qwen3-embedding:0.6b', null],
        ['http://ollama.test:11434/v1/embeddings', 'qwen3-embedding:4b', null],
        ['https://api.openai.com/v1/embeddings', 'text-embedding-3-large', 'Bearer main-key'],
        // The built-in "openai" provider, on the main endpoint's host, does.
        ['https://api.openai.com/v1/embeddings', 'text-embedding-3-small', 'Bearer main-key'],
    ]);
});

it('rejects an unknown provider and failover lists instead of silently using another model', function () {
    Http::fake();

    expect(fn () => Embeddings::for(['a'])->generate('gemini'))
        ->toThrow(InvalidArgumentException::class, 'embedding provider [gemini] is not configured');
    expect(fn () => Embeddings::for(['a'])->generate(['openai', 'ollama']))
        ->toThrow(InvalidArgumentException::class, 'Failover between providers is not supported');
    Http::assertNothingSent();
});

it('keys the Embeddings cache on model, dimensions and input type', function () {
    config()->set('cache.stores.array', ['driver' => 'array']);
    config()->set('vector-lite.caching.embeddings.store', 'array');
    $this->fakeEmbeddingApi();

    Embeddings::for(['same text'])->cache()->generate();
    Embeddings::for(['same text'])->cache()->generate(); // cached
    Embeddings::for(['same text'])->asQuery()->cache()->generate(); // another input type
    Embeddings::for(['same text'])->dimensions(12)->cache()->generate(); // other dimensions
    config()->set('vector-lite.embeddings.model', 'another-model');
    Embeddings::for(['same text'])->cache()->generate(); // another model

    Http::assertSentCount(4);
});

// --- embed_hash identity -------------------------------------------------

it('keeps the pre-0.5.0 embed_hash for the legacy default, so upgrading re-embeds nothing', function () {
    config()->set('vector-lite.default_dimensions', 1536);

    $config = EmbeddingConfig::resolve();
    expect($config->isLegacyIdentity())->toBeTrue();
    expect($config->embedHash('some text'))->toBe(hash('xxh3', 'some text'));

    // A row as vector-lite 0.4 stored it: embed_hash = xxh3(embedding text).
    $id = DB::table('vectors')->insertGetId(['title' => 'stored by 0.4', 'created_at' => now(), 'updated_at' => now()]);
    $vector = Vector::find($id);
    DB::table('vectors')->where('id', $id)->update(['embed_hash' => hash('xxh3', $vector->getEmbeddingText())]);
    $vector->refresh();
    Http::fake();

    expect($vector->hasStaleEmbedding())->toBeFalse();
    $vector->save();

    Http::assertNothingSent();
});

it('changes embed_hash when the model, dimensions or document prefix change - but not the url or query prefix', function () {
    $text = "<vector id=\"1\">\n  <title>Shoes</title>\n</vector>\n";
    $legacy = new EmbeddingConfig(EmbeddingConfig::OPENAI_URL, 'key', 'text-embedding-3-small', 1536);

    $hashes = [
        'legacy' => $legacy->embedHash($text),
        'model' => $legacy->withModel('qwen3-embedding:0.6b')->embedHash($text),
        'dimensions' => $legacy->withDimensions(1024)->embedHash($text),
        'document prefix' => (new EmbeddingConfig(EmbeddingConfig::OPENAI_URL, 'key', 'text-embedding-3-small', 1536, documentPrefix: 'title: none | text: '))->embedHash($text),
    ];

    expect(array_unique($hashes))->toHaveCount(4);

    $movedAndQueryPrefixed = new EmbeddingConfig('http://ollama.test:11434/v1/', null, 'text-embedding-3-small', 1536, queryPrefix: 'query: ');
    expect($movedAndQueryPrefixed->embedHash($text))->toBe($hashes['legacy']);

    $qwen = new EmbeddingConfig('http://ollama.test:11434/v1/', null, 'qwen3-embedding:0.6b', 1024);
    $qwenElsewhere = new EmbeddingConfig('http://gpu-box.lan:11434/v1/', null, 'qwen3-embedding:0.6b', 1024);
    expect($qwenElsewhere->embedHash($text))->toBe($qwen->embedHash($text));
    expect($qwen->embedHash($text))->toBe(hash('xxh3', '{"model":"qwen3-embedding:0.6b","dimensions":1024,"document_prefix":""}'."\n".$text));
});

it('marks every row stale after a model switch and re-embeds it on the next save', function () {
    $this->fakeEmbeddingApi();
    $vector = Vector::create(['title' => 'Shoes']);
    // The embedding text holds the model's id, which doesn't exist yet while
    // creating - so the first save after create() embeds once more.
    $vector->save();
    expect($vector->hasStaleEmbedding())->toBeFalse();

    $vector->save();
    Http::assertSentCount(2); // unchanged text and settings: nothing re-embedded

    config()->set('vector-lite.embeddings.model', 'qwen3-embedding:0.6b');
    expect($vector->hasStaleEmbedding())->toBeTrue();

    $vector->save();
    Http::assertSentCount(3);
    expect($vector->refresh()->embed_hash)->toBe($vector->embeddingHash());
});

// --- Failed embeddings ---------------------------------------------------

it('leaves embed_hash and the vector untouched when embedding fails with on_error "throw"', function () {
    $fail = false;
    fakeFlakyEmbeddingApi($this, $fail);
    $vector = Vector::create(['title' => 'before']);
    $before = DB::table('vectors')->find($vector->id);

    $fail = true;
    $vector->title = 'after';

    expect(fn () => $vector->save())->toThrow(EmbeddingException::class, 'returned HTTP 503');
    expect($vector->embed_hash)->toBe($before->embed_hash);

    $after = DB::table('vectors')->find($vector->id);
    expect($after->title)->toBe('before');
    expect($after->embed_hash)->toBe($before->embed_hash);
    expect($after->vector)->toBe($before->vector);

    expect(fn () => Vector::create(['title' => 'never saved']))->toThrow(EmbeddingException::class);
    expect(Vector::count())->toBe(1);
});

it('saves without a new vector and keeps embed_hash stale when embedding fails with on_error "skip"', function () {
    config()->set('vector-lite.embeddings.on_error', 'skip');
    $fail = false;
    fakeFlakyEmbeddingApi($this, $fail);
    $existing = Vector::create(['title' => 'before']);
    $before = DB::table('vectors')->find($existing->id);

    $fail = true;
    $existing->title = 'after';
    $existing->save();
    $new = Vector::create(['title' => 'while the server is down']);

    // The edit is saved, but the old vector and hash stay - so it reads as stale.
    $after = DB::table('vectors')->find($existing->id);
    expect($after->title)->toBe('after');
    expect($after->embed_hash)->toBe($before->embed_hash);
    expect($after->vector)->toBe($before->vector);
    expect($existing->hasStaleEmbedding())->toBeTrue();

    // The new row is saved with no vector at all.
    $newRow = DB::table('vectors')->find($new->id);
    expect($newRow->vector)->toBeNull();
    expect($newRow->embed_hash)->toBeNull();

    // Once the server is back, the next save retries both.
    $fail = false;
    $existing->save();
    $new->save();

    expect(DB::table('vectors')->find($existing->id)->embed_hash)->toBe($existing->embeddingHash());
    expect(DB::table('vectors')->find($new->id)->embed_hash)->toBe($new->embeddingHash());
    expect(DB::table('vectors')->find($new->id)->vector)->not->toBeNull();
    expect($new->refresh()->vector_cluster_id)->not->toBeNull();
});
