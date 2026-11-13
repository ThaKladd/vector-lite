<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Document;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\Tests\Models\VectorCluster;

uses(VectorTestHelpers::class);

/**
 * 25 clustered rows embedded with the test default (36 dimensions), each
 * saved once more so its embed_hash covers its final embedding text.
 */
function seedEmbeddedVectors(object $test, int $amount = 25, bool $fake = true): void
{
    if ($fake) {
        (fn () => $this->fakeEmbeddingApi())->call($test);
    }
    DB::table('others')->insert(['description' => 'shared', 'created_at' => now(), 'updated_at' => now()]);

    foreach (range(1, $amount) as $i) {
        Vector::create(['title' => "row {$i}", 'other_id' => 1])->save();
    }
}

function switchToLocalQwen(int $dimensions = 24): void
{
    config()->set('vector-lite.default_dimensions', $dimensions);
    config()->set('vector-lite.embeddings.url', 'http://ollama.test:11434/v1/');
    config()->set('vector-lite.embeddings.api_key', null);
    config()->set('vector-lite.embeddings.model', 'qwen3-embedding:0.6b');
    VectorLiteConfig::reset();
}

it('re-embeds stale rows in batches with the new model, then rebuilds the clusters in the new space', function () {
    seedEmbeddedVectors($this);
    $oldClusterIds = VectorCluster::pluck('id')->all();
    expect($oldClusterIds)->not->toBeEmpty();
    expect(Vector::all()->every(fn (Vector $vector) => ! $vector->hasStaleEmbedding()))->toBeTrue();

    switchToLocalQwen(24);
    expect(Vector::all()->every(fn (Vector $vector) => $vector->hasStaleEmbedding()))->toBeTrue();

    $this->fakeEmbeddingApi();
    $before = Http::recorded()->count();

    $this->artisan('vector-lite:reembed', ['model' => Vector::class, '--chunk' => 10, '--only-stale' => true])
        ->expectsOutputToContain('[qwen3-embedding:0.6b], 24 dimensions')
        ->expectsOutputToContain('Clustered 25 rows into')
        ->assertSuccessful();

    // 25 rows in chunks of 10: three batched requests, all to the new endpoint.
    $requests = Http::recorded()->slice($before)->map(fn ($pair) => $pair[0])->values();
    expect($requests)->toHaveCount(3);
    expect($requests->map(fn (Request $request) => count($request['input']))->all())->toBe([10, 10, 5]);
    expect($requests->every(fn (Request $request) => $request->url() === 'http://ollama.test:11434/v1/embeddings'
        && $request['model'] === 'qwen3-embedding:0.6b'
        && $request['dimensions'] === 24))->toBeTrue();

    $vectors = Vector::all();
    expect($vectors->every(fn (Vector $vector) => ! $vector->hasStaleEmbedding()))->toBeTrue();
    expect($vectors->every(fn (Vector $vector) => strlen($vector->vector) === 24 * 4))->toBeTrue();
    expect($vectors->every(fn (Vector $vector) => $vector->vector_cluster_id !== null))->toBeTrue();

    // The old clusters are gone; the new ones hold new-space vectors and add up.
    $clusters = VectorCluster::all();
    expect(array_intersect($clusters->pluck('id')->all(), $oldClusterIds))->toBe([]);
    expect($clusters->every(fn (VectorCluster $cluster) => strlen($cluster->vector) === 24 * 4))->toBeTrue();
    expect((int) $clusters->sum('vector_count'))->toBe(25);
});

it('skips up-to-date rows with --only-stale and leaves the clusters alone when nothing changed', function () {
    seedEmbeddedVectors($this, 12);
    $clusterIds = VectorCluster::pluck('id')->all();
    $before = Http::recorded()->count();

    $this->artisan('vector-lite:reembed', ['model' => Vector::class, '--only-stale' => true])
        ->doesntExpectOutputToContain('Rebuilding the clusters')
        ->assertSuccessful();

    expect(Http::recorded()->count())->toBe($before);
    expect(VectorCluster::pluck('id')->all())->toBe($clusterIds);
});

it('re-embeds every row without --only-stale', function () {
    seedEmbeddedVectors($this, 12);
    $before = Http::recorded()->count();

    $this->artisan('vector-lite:reembed', ['model' => 'ThaKladd\\VectorLite\\Tests\\Models\\Vector', '--chunk' => 5])
        ->assertSuccessful();

    $inputs = array_slice($this->sentEmbeddingInputs(), $before);
    expect(array_map('count', $inputs))->toBe([5, 5, 2]);
});

it('catches up a few failed rows with --keep-clusters, without rebuilding every cluster', function () {
    seedEmbeddedVectors($this, 15);
    $clusterIds = VectorCluster::pluck('id')->sort()->values()->all();
    $stale = Vector::query()->orderBy('id')->first();
    DB::table('vectors')->where('id', $stale->id)->update(['embed_hash' => null]);
    $before = Http::recorded()->count();

    $this->artisan('vector-lite:reembed', ['model' => Vector::class, '--only-stale' => true, '--keep-clusters' => true])
        ->doesntExpectOutputToContain('Rebuilding the clusters')
        ->assertSuccessful();

    expect($this->sentEmbeddingInputs())->toHaveCount($before + 1);
    expect($stale->refresh()->hasStaleEmbedding())->toBeFalse();
    expect($stale->vector_cluster_id)->not->toBeNull();
    // Existing clusters are kept (full-cluster splits may add new ones).
    expect(array_diff($clusterIds, VectorCluster::pluck('id')->all()))->toBe([]);
    expect((int) VectorCluster::sum('vector_count'))->toBe(15);
});

it('stops on a provider error, keeps finished batches and points at --only-stale to resume', function () {
    // Http::fake() stubs stack (the first match wins), so one fake serves the
    // seeding and the failure: it answers 500 from request number $failFrom on.
    $calls = 0;
    $failFrom = PHP_INT_MAX;
    Http::fake(['*' => function (Request $request) use (&$calls, &$failFrom) {
        if (++$calls >= $failFrom) {
            return Http::response(['error' => 'out of memory'], 500);
        }

        $size = (int) $request['dimensions'];

        return Http::response($this->embeddingResponse(
            array_map(fn () => $this->createVectorArray($size), (array) $request['input'])
        ));
    }]);

    seedEmbeddedVectors($this, 12, fake: false);
    switchToLocalQwen(24);
    $failFrom = $calls + 2; // the first batch of 5 succeeds, the second fails

    $this->artisan('vector-lite:reembed', ['model' => Vector::class, '--chunk' => 5, '--only-stale' => true])
        ->expectsOutputToContain('returned HTTP 500')
        ->expectsOutputToContain('Run again with --only-stale to resume')
        ->doesntExpectOutputToContain('Rebuilding the clusters')
        ->assertFailed();

    expect(Vector::all()->filter(fn (Vector $vector) => ! $vector->hasStaleEmbedding()))->toHaveCount(5);
});

it('refuses models it cannot re-embed', function () {
    $this->artisan('vector-lite:reembed', ['model' => 'NoSuchModel'])
        ->expectsOutputToContain('is not a VectorModel class')
        ->assertFailed();

    $this->artisan('vector-lite:reembed', ['model' => Document::class])
        ->expectsOutputToContain('has no $embedFields')
        ->assertFailed();

    config()->set('vector-lite.openai.api_key', null);
    $this->artisan('vector-lite:reembed', ['model' => Vector::class])
        ->expectsOutputToContain('no embedding provider is configured')
        ->assertFailed();
});
