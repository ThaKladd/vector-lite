<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ThaKladd\VectorLite\Enums\ReduceBy;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\VectorLite;

ini_set('memory_limit', '20000M');

uses(VectorTestHelpers::class);

it('can connect to database', function () {
    $result = DB::select('SELECT 1 AS test');
    expect($result[0]->test)->toBe(1);
});

it('can create vector table and use vector methods', function () {
    $vectorAmount = 100;

    // Create the table
    // Artisan::call('vector-lite:make', ['model' => 'Vector']);

    // Check if the table exists
    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vectors'));

    // Print out the table columns
    // $columns = DB::getSchemaBuilder()->getColumnListing('vectors');

    // $columns = DB::getSchemaBuilder()->getColumnListing('vectors_clusters');

    // Fill the table with random vectors
    $this->fillVectorTable($vectorAmount, 36);

    // $this->fillVectorClusterTable(100, 36);

    // Check if the vectors are inserted
    $vectors = DB::table('vectors')->count();

    $this->assertSame($vectors, $vectorAmount);

    $vectorModel = Vector::query()->inRandomOrder()->first();
    // dump('$vectorModel', $vectorModel);
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

it('can do clustering of vectors', function () {
    $vectorAmount = 5000; // 5000 should make the clustering have an effect on speed
    $clusterSize = config('vector-lite.clusters_size');
    $clusteringThreshold = $vectorAmount / $clusterSize * 3;

    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vectors_clusters'));
    $this->fillVectorClusterTable($vectorAmount, 36);
    $vectors = DB::table('vectors')->count();
    $this->assertSame($vectors, $vectorAmount);
    $vectorClusters = DB::table('vectors_clusters')->count();
    $columns = DB::getSchemaBuilder()->getColumnListing('vectors_clusters');
    $this->assertTrue($vectorClusters <= $clusteringThreshold);
    $vectorModel = Vector::query()->inRandomOrder()->first();
    $this->assertNotNull($vectorModel);
    $this->assertNotEmpty($vectorModel->vector);

    $bestCluster = Vector::findBestClustersByVector($vectorModel->vector);
    $this->assertNotNull($bestCluster);
    $this->assertTrue($bestCluster->id === $vectorModel->vectors_cluster_id || $bestCluster->similarity > 0.9);

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

/**
 * The VectorModel, when querying with an object instance
 * $vectorModel = VectorModel::find(1);
 * $vectorModel->...
 */
it('works with object calls', function () {
    $this->fillVectorTable(100, 36);
    $vectorModel = Vector::query()->inRandomOrder()->first();

    $similar = $vectorModel->findBestVectorMatch();
    $this->assertNotEquals($vectorModel, $similar);

    $similarModels = $vectorModel->getBestVectorMatches();
    $this->assertTrue($similarModels->first()->similarity >= $similarModels->last()->similarity);

});

/**
 * The VectorModel, when querying without an object instance
 * VectorModel::...
 */
it('works with static calls', function () {
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
    $vectorAmount = 1000;
    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vectors_clusters'));
    $this->fillVectorClusterTable($vectorAmount, 36);
    $vectors = DB::table('vectors')->count();
    $this->assertSame($vectors, $vectorAmount);
    $vectorModel = Vector::query()->inRandomOrder()->first();
    $allVectors = Vector::all();

    $similarModel = $allVectors->findBestByVector($vectorModel);
    $this->assertEquals($similarModel->id, $vectorModel->id);

    $similarModels = $allVectors->searchBestByVector($vectorModel, 5);
    $this->assertTrue($similarModels->first()->similarity > $similarModels->last()->similarity);

    $similarModels = $allVectors->sortBySimilarityToVector($vectorModel, 'asc');
    $this->assertTrue($similarModels->first()->similarity < $similarModels->last()->similarity);

    $similarModels = $allVectors->filterAboveSimilarityThreshold($vectorModel, 0.1);
    $this->assertTrue(min($similarModels->pluckSimilarities()) >= 0.1);
});

/*

it('can create vector cluster table and use cluster methods', function () {
    $this->fillVectorClusterTable(100, 36);

    // Check if the table exists
    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vectors_clusters'));

    $vectors = DB::table('vectors_clusters')->count();
    $this->assertTrue($vectors > 0);
});
*/
