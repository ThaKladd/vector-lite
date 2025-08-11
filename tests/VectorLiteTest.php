<?php

use Illuminate\Support\Facades\DB;
use ThaKladd\VectorLite\Enums\ReduceBy;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Support\ClusterCache;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Other;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\VectorLite;

use function PHPUnit\Framework\assertTrue;

ini_set('memory_limit', '20000M');

uses(VectorTestHelpers::class);

function useMySqlConnection(array $overrides = []): void
{
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => env('MYSQL_HOST', 'db'),
        'port' => env('MYSQL_PORT', '3306'),
        'database' => env('MYSQL_DATABASE', 'db'),
        'username' => env('MYSQL_USER', 'db'),
        'password' => env('MYSQL_PASSWORD', 'db'),
    ]);

    config()->set('database.default', 'mysql');

    DB::purge();
    DB::reconnect();
}

it('can connect to database', function () {
    $result = DB::select('SELECT 1 AS test');
    expect($result[0]->test)->toBe(1);
});

it('can create vector table and use vector methods - sqlite', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    $vectorAmount = 100;

    // Check if the table exists
    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vectors'));

    // Fill the table with random vectors
    $this->fillVectorTable($vectorAmount, 36);

    // Check if the vectors are inserted
    $vectors = DB::table('vectors')->count();

    $this->assertSame($vectors, $vectorAmount);

    $vectorModel = Vector::query()->inRandomOrder()->first();

    $this->assertNotNull($vectorModel);
    $this->assertNotEmpty($vectorModel->vector);

    $similarVector = Vector::findBestByVector($vectorModel->vector);
    $this->assertNotNull($similarVector);
    $this->assertEquals($vectorModel->id, $similarVector->id);

    $similarVectorOther = Vector::bestByVector($vectorModel->vector, 1)->withoutModels($vectorModel)->first();

    $this->assertNotNull($similarVectorOther);
    $this->assertNotEquals($vectorModel->id, $similarVectorOther->id);

    // Make one random vector
    $vectorArray = $this->createVectorArray(36);
    $bestVectors = Vector::searchBestByVector($vectorArray, 3);

    $this->assertNotNull($bestVectors);
    $this->assertCount(3, $bestVectors);
    $this->assertTrue($bestVectors->first()->similarity > $bestVectors->last()->similarity);
});

it('can create vector table and use vector methods - mysql', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    useMySqlConnection();
    $this->setUpDatabase();

    // Now run assertions against MySQL
    expect(DB::connection()->getDatabaseName())->toBe('db');
    $vectorAmount = 10;

    // Check if the table exists
    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vectors'));

    // Fill the table with random vectors
    $this->fillVectorTable($vectorAmount, 36);

    // Check if the vectors are inserted
    $vectors = DB::table('vectors')->count();

    $this->assertSame($vectors, $vectorAmount);

    $vectorModel = Vector::query()->inRandomOrder()->first();

    $this->assertNotNull($vectorModel);
    $this->assertNotEmpty($vectorModel->vector);

    $similarVector = $vectorModel->findBestVectorMatch();
    $this->assertNotNull($similarVector);
    $this->assertNotEquals($vectorModel->id, $similarVector->id);

    $similarVectors = $vectorModel->getBestVectorMatches(5);
    $this->assertNotNull($similarVectors);
    $this->assertEquals($similarVector->id, $similarVectors->first()->id);
    $this->assertTrue($similarVectors->last()->similarity < $similarVectors->first()->similarity);
});

it('can do clustering of vectors - sqlite', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();

    $vectorAmount = 5000; // 5000 should make the clustering have an effect on speed
    $clusterSize = config('vector-lite.clusters_size');
    $clusteringThreshold = $vectorAmount / $clusterSize * 3;
    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbedding')
        ->with(Mockery::type('string'), Mockery::type('int'))
        ->andReturn($this->createVectorArray(36));
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vector_clusters'));
    $this->fillVectorClusterTable($vectorAmount, 36);

    $vectors = DB::table('vectors')->count();
    $this->assertSame($vectors, $vectorAmount);

    $vectorClusters = DB::table('vector_clusters')->count();
    $this->assertTrue($vectorClusters > 2);
    $this->assertTrue($vectorClusters <= $clusteringThreshold);

    $vectorModel = Vector::query()->inRandomOrder()->first();
    $this->assertNotNull($vectorModel);
    $this->assertNotEmpty($vectorModel->vector);

    $bestCluster = Vector::findBestClustersByVector($vectorModel->vector);
    $this->assertNotNull($bestCluster);
    $this->assertTrue($bestCluster->id === $vectorModel->vector_cluster_id || $bestCluster->similarity > 0.9);

    $similarVector = Vector::findBestByVector($vectorModel->vector);
    $this->assertNotNull($similarVector);
    $this->assertEquals($vectorModel->id, $similarVector->id);

    $similarVectorOther = Vector::bestByVector($vectorModel->vector, 1)->withoutModels($vectorModel)->first();

    $this->assertNotNull($similarVectorOther);
    $this->assertNotEquals($vectorModel->id, $similarVectorOther->id);
    $vectorArray = $this->createVectorArray(36);
    $bestVectors = Vector::searchBestByVector($vectorArray, 3);

    $this->assertNotNull($bestVectors);
    $this->assertCount(3, $bestVectors);
    $this->assertTrue($bestVectors->first()->similarity > $bestVectors->last()->similarity);

    $startTime = microtime(true);
    $bestVectors = Vector::searchBestByVector($vectorModel->vector, 10);
    $normalSearchTime = round(microtime(true) - $startTime, 6);

    $startTime = microtime(true);
    $bestVectors = Vector::filterByClosestClusters()->searchBestByVector($vectorModel->vector, 10);
    $limitedSearchTime = round(microtime(true) - $startTime, 6);
    $this->assertTrue($normalSearchTime > $limitedSearchTime);
});

it('can do clustering of vectors - mysql', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    useMySqlConnection();
    $this->setUpDatabase();
    $vectorClusters = DB::table('vector_clusters')->count();

    $vectorAmount = 500;
    $clusterSize = config('vector-lite.clusters_size');
    $clusteringThreshold = $vectorAmount / $clusterSize * 3;
    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbedding')
        ->with(Mockery::type('string'), Mockery::type('int'))
        ->andReturn($this->createVectorArray(36));
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vector_clusters'));
    $this->fillVectorClusterTable($vectorAmount, 36);

    $vectors = DB::table('vectors')->count();
    $this->assertSame($vectors, $vectorAmount);

    $vectorClusters = DB::table('vector_clusters');
    $vectorClustersCount = $vectorClusters->count();
    $this->assertTrue($vectorClustersCount > 2);
    $this->assertTrue($vectorClustersCount <= $clusteringThreshold);

    $vectorModel = Vector::query()->inRandomOrder()->first();
    $this->assertNotNull($vectorModel);
    $this->assertNotEmpty($vectorModel->vector);

    $bestCluster = $vectorModel->findBestCluster();
    $this->assertNotNull($bestCluster);
    $this->assertTrue($bestCluster->id === $vectorModel->vector_cluster_id || $bestCluster->similarity > 0.9);

    $similarVector = $vectorModel->findBestVectorMatch();
    $this->assertNotNull($similarVector);
    $this->assertEquals($vectorModel->vector_cluster_id, $similarVector->vector_cluster_id);

    $bestClusters = $vectorModel->getBestClusters(5);
    $this->assertTrue($bestClusters->last()->similarity < $bestClusters->first()->similarity);
});

/**
 * The VectorModel, when querying with an object instance
 * $vectorModel = VectorModel::find(1);
 * $vectorModel->...
 */
it('works with object calls', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    $this->fillVectorTable(100, 36);
    $vectorModel = Vector::query()->inRandomOrder()->first();

    $similar = $vectorModel->findBestVectorMatch();
    $this->assertNotEquals($vectorModel, $similar);

    $similarModels = $vectorModel->getBestVectorMatches();
    $this->assertTrue($similarModels->first()?->similarity >= $similarModels->last()?->similarity);
});

/**
 * The VectorModel, when querying without an object instance
 * VectorModel::...
 */
it('works with static calls', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    $this->fillVectorTable(100, 36);
    $vectorModel = Vector::query()->inRandomOrder()->first();
    $similarQuery = Vector::query();
    $similar = $similarQuery->findBestByVector($vectorModel);
    $this->assertNotEquals($vectorModel, $similar);

    $similarQuery = Vector::query();
    $similarModels = $similarQuery->searchBestByVector($vectorModel);
    $this->assertTrue($similarModels->first()->similarity >= $similarModels->last()->similarity);
});

it('can transform a vector', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    $vector = $this->createVectorArray(32);
    [$normalizedVector, $norm] = VectorLite::normalize($vector);
    [$binaryVector, $norm2] = VectorLite::normalizeToBinary($vector);
    $this->assertEquals($norm, $norm2);
    $this->assertTrue(is_array($normalizedVector));
    $this->assertTrue(is_string($binaryVector));
    $arrayVector = VectorLite::binaryVectorToArray($binaryVector);
    $this->assertEquals(round(array_sum($arrayVector), 4), round(array_sum($normalizedVector), 4));
    $denormalizedVector = VectorLite::denormalize($arrayVector, $norm);
    $this->assertEquals(round(array_sum($vector), 4), round(array_sum($denormalizedVector), 4));
    $reducedVectorRPM = ReduceBy::RPM->reduceVector($vector, 6);
    $this->assertEquals(count($reducedVectorRPM), 6);
    $reducedVectorCA = ReduceBy::CHUNKED_AGGREGATION->reduceVector($vector, 6);
    $this->assertEquals(count($reducedVectorCA), 6);
});

it('can use the methods on the collection', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbedding')
        ->with(Mockery::type('string'), Mockery::type('int'))
        ->andReturn($this->createVectorArray(36));
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $vectorAmount = 1000;
    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vector_clusters'));
    $this->fillVectorClusterTable($vectorAmount, 36);
    $vectors = DB::table('vectors')->count();
    $this->assertSame($vectors, $vectorAmount);
    $vectorModel = Vector::query()->inRandomOrder()->first();
    $allVectors = Vector::all();

    $similarModel = $allVectors->findBestByVector($vectorModel);
    $this->assertEquals($similarModel->id, $vectorModel->id);

    $similarModels = $allVectors->searchBestByVector($vectorModel, 5);
    $this->assertTrue($similarModels->first()->similarity >= $similarModels->last()->similarity);

    $similarModels = $allVectors->sortBySimilarityToVector($vectorModel, 'asc');
    $this->assertTrue($similarModels->first()->similarity <= $similarModels->last()->similarity);

    $similarModels = $allVectors->filterAboveSimilarityThreshold($vectorModel, 0.1);
    $this->assertTrue(min($similarModels->pluckSimilarities()) >= 0.01);
});

it('changes model embedding after text change', function () {
    VectorLiteConfig::reset();
    ClusterCache::reset();
    $vectorAmount = 50;
    $this->fillVectorTable($vectorAmount, 36);
    $mockEmbeddingService = Mockery::mock(EmbeddingService::class);
    $mockEmbeddingService
        ->shouldReceive('createEmbedding')
        ->with(Mockery::type('string'), Mockery::type('int'))
        ->andReturn($this->createVectorArray(36));
    $this->app->instance(EmbeddingService::class, $mockEmbeddingService);

    $vectorModel = Vector::first();
    $vectorModelEmbed = $vectorModel->embed_hash;

    $vectorModel->title = 'another';
    $vectorModel->save();

    $vectorModelEmbed2 = $vectorModel->refresh()->embed_hash;
    assertTrue($vectorModelEmbed != $vectorModelEmbed2);

    $other = Other::first();
    $other->description = 'another';
    $other->save();
    $vectorModel->refresh()->save();
    $vectorModelEmbed3 = $vectorModel->embed_hash;

    assertTrue($vectorModelEmbed3 != $vectorModelEmbed2);
});
