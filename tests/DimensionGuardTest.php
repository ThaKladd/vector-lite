<?php

use Illuminate\Support\Facades\DB;
use ThaKladd\VectorLite\Support\ClusterCache;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
use ThaKladd\VectorLite\Support\VectorLiteFfi;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\Tests\Models\VectorCluster;
use ThaKladd\VectorLite\VectorLite;

uses(VectorTestHelpers::class);

beforeEach(function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    VectorLiteFfi::reset();
});

afterEach(fn () => VectorLiteFfi::reset());

/**
 * Inserts rows straight into "vectors" - $dimensions per row, in order -
 * the way a table looks halfway through re-embedding with another model.
 *
 * @param  array<int, int>  $dimensions
 * @return array<int, int> the inserted ids, keyed like $dimensions
 */
function insertVectorsOfLengths(object $test, array $dimensions): array
{
    DB::table('others')->insert(['description' => 'test', 'created_at' => now(), 'updated_at' => now()]);

    return array_map(function (int $size) use ($test) {
        $record = (fn () => $this->createVectorRecord($size, true))->call($test);

        return DB::table('vectors')->insertGetId($record);
    }, $dimensions);
}

it('scores two vectors of different lengths as non-matching in every PHP cosine variant', function () {
    [$long] = VectorLite::normalizeToBinary($this->createVectorArray(36));
    [$short] = VectorLite::normalizeToBinary($this->createVectorArray(24));
    $longArray = VectorLite::binaryVectorToArray($long);
    $shortArray = VectorLite::binaryVectorToArray($short);

    expect(VectorLite::cosineSimilarityBinary($long, $short))->toBe(VectorLite::NON_MATCHING_SIMILARITY);
    expect(VectorLite::cosineSimilarityBinary($short, $long))->toBe(-1.0);
    expect(VectorLite::cosineSimilarityBinaryCache('long-hash', $long, 'short-hash', $short))->toBe(-1.0);
    expect(VectorLite::cosineSimilarity($longArray, $shortArray))->toBe(-1.0);
    expect(VectorLite::cosineSimilarityCache('long-array', $longArray, 'short-array', $shortArray))->toBe(-1.0);

    // Nothing was memoized for the mismatched pair.
    expect(VectorLite::$dot['long-hash']['short-hash'] ?? null)->toBeNull();

    // A missing vector is non-matching too, rather than a TypeError.
    expect(VectorLite::cosineSimilarityBinary(null, $long))->toBe(-1.0);
    expect(VectorLite::cosineSimilarityBinaryCache(null, null, 'long-hash', $long))->toBe(-1.0);

    // Equal lengths still compute normally.
    expect(round(VectorLite::cosineSimilarityBinary($long, $long), 4))->toBe(1.0);
});

it('scores vectors of different lengths as non-matching on the FFI path instead of reading past the shorter one', function () {
    if (! extension_loaded('ffi')) {
        $this->markTestSkipped('The "ffi" extension is not loaded.');
    }
    config()->set('vector-lite.ffi.enabled', true);
    config()->set('vector-lite.ffi.library_path', null);
    if (! VectorLiteFfi::isAvailable()) {
        $this->markTestSkipped('FFI is not usable in this environment.');
    }

    [$long] = VectorLite::normalizeToBinary($this->createVectorArray(36));
    [$short] = VectorLite::normalizeToBinary($this->createVectorArray(24));

    expect(VectorLite::cosineSimilarityBinary($long, $short))->toBe(-1.0);
    expect(VectorLite::cosineSimilarityBinaryCache('ffi-long', $long, 'ffi-short', $short))->toBe(-1.0);
    expect(round(VectorLite::cosineSimilarityBinary($long, $long), 4))->toBe(1.0);
});

it('scores vectors of different lengths as non-matching in SQLite COSIM and COSIM_CACHE', function () {
    [$long] = VectorLite::normalizeToBinary($this->createVectorArray(36));
    [$short] = VectorLite::normalizeToBinary($this->createVectorArray(24));

    $row = DB::selectOne(
        'SELECT COSIM(?, ?) AS plain, COSIM_CACHE(?, ?, ?, ?) AS cached, COSIM(NULL, ?) AS missing',
        [$long, $short, 'sql-long', $long, 'sql-short', $short, $long]
    );

    expect((float) $row->plain)->toBe(-1.0);
    expect((float) $row->cached)->toBe(-1.0);
    expect((float) $row->missing)->toBe(-1.0);
});

it('ranks rows of another length last, with the same score, in SQL and in the collection API - sqlite', function () {
    $ids = insertVectorsOfLengths($this, [36, 24, 36, 24, 36]);
    $mismatched = [$ids[1], $ids[3]];
    $query = $this->createVectorArray(36);

    $sql = Vector::searchBestByVector($query, 10);
    $collection = Vector::all()->searchBestByVector($query, null);

    foreach ([$sql, $collection] as $results) {
        expect($results)->toHaveCount(5);
        expect($results->take(-2)->pluck('id')->sort()->values()->all())->toBe($mismatched);
        expect($results->take(-2)->pluck('similarity')->all())->toBe([-1.0, -1.0]);
        expect($results->take(3)->every(fn ($row) => $row->similarity > -1.0))->toBeTrue();
    }

    expect(Vector::query()->whereVector($query, '>', -0.5)->pluck('id')->sort()->values()->all())
        ->toBe([$ids[0], $ids[2], $ids[4]]);
});

it('ranks rows of another length last in the PHP path - mysql', function () {
    useMySqlConnection();
    $this->setUpDatabase();
    VectorLiteConfig::reset();

    $ids = insertVectorsOfLengths($this, [36, 24, 36, 24, 36]);
    $query = $this->createVectorArray(36);

    $results = Vector::all()->searchBestByVector($query, null);
    expect($results->take(-2)->pluck('id')->sort()->values()->all())->toBe([$ids[1], $ids[3]]);
    expect($results->take(-2)->pluck('similarity')->all())->toBe([-1.0, -1.0]);

    $matches = Vector::find($ids[0])->getBestVectorMatches(10);
    expect($matches->take(-2)->pluck('similarity')->all())->toBe([-1.0, -1.0]);
});

it('starts a new cluster instead of joining one of another vector length', function () {
    config()->set('vector-lite.use_clustering_dimensions', false);
    VectorLiteConfig::reset();
    $this->fakeEmbeddingApi();

    foreach (range(1, 5) as $i) {
        Vector::create(['title' => "old model {$i}"]);
    }
    $oldClusters = VectorCluster::pluck('id')->all();

    config()->set('vector-lite.default_dimensions', 24);
    $new = Vector::create(['title' => 'new model']);
    $new->refresh();

    expect($oldClusters)->not->toContain($new->vector_cluster_id);
    expect(strlen(VectorCluster::find($new->vector_cluster_id)->vector))->toBe(24 * 4);
    expect((float) $new->vector_cluster_match)->toBe(1.0);
});

it('re-clusters a row whose vector changed on update instead of leaving it without a cluster', function () {
    $this->fakeEmbeddingApi();
    foreach (range(1, 25) as $i) {
        Vector::create(['title' => "row {$i}"]);
    }

    $row = Vector::query()->whereNotNull('vector_cluster_id')->first();
    $row->title = 'a completely different title';
    $row->save();

    expect($row->refresh()->vector_cluster_id)->not->toBeNull();
    expect(Vector::query()->whereNull('vector_cluster_id')->count())->toBe(0);
    expect((int) VectorCluster::sum('vector_count'))->toBe(Vector::count());
});
