<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ThaKladd\VectorLite\Tests\Helpers\VectorTestHelpers;
use ThaKladd\VectorLite\Tests\Models\Vector;

ini_set('memory_limit', '20000M');

uses(VectorTestHelpers::class);

it('can connect to database', function () {
    $result = DB::select('SELECT 1 AS test');
    expect($result[0]->test)->toBe(1);
});

it('can create vector table and use vector methods', function () {
    // Create the table
    // Artisan::call('vector-lite:make', ['model' => 'Vector']);

    // Check if the table exists
    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vectors'));

    // Print out the table columns
    // $columns = DB::getSchemaBuilder()->getColumnListing('vectors');

    // $columns = DB::getSchemaBuilder()->getColumnListing('vectors_clusters');

    // Fill the table with random vectors
    $this->fillVectorTable(100, 36);

    // $this->fillVectorClusterTable(100, 36);

    // Check if the vectors are inserted
    $vectors = DB::table('vectors')->count();

    $this->assertSame($vectors, 100);

    $vectorModel = Vector::query()->inRandomOrder()->first();
    // dump('$vectorModel', $vectorModel);
    $this->assertNotNull($vectorModel);
    $this->assertNotEmpty($vectorModel->vector);

    // dump($vectorModel->vector);
    // dump(unpack('f*', hex2bin($vectorModel->vector)));
    $similarVector = Vector::findBestByVector($vectorModel->vector);
    // dump('$similarVector', $similarVector);
    $this->assertNotNull($similarVector);
    $this->assertEquals($vectorModel->id, $similarVector->id);

    $similarVectorOther = Vector::bestByVector($vectorModel->vector, 1)->excludeCurrent($vectorModel)->first();
    // dump('$similarVectorOther', $similarVectorOther);
    $this->assertNotNull($similarVectorOther);
    $this->assertNotEquals($vectorModel->id, $similarVectorOther->id);

    // Make one random vector
    $vectorArray = $this->createVectorArray(36);
    $bestVectors = Vector::searchBestByVector($vectorArray, 3);
    // dump('$bestVectors', $bestVectors);
    $this->assertNotNull($bestVectors);
    $this->assertCount(3, $bestVectors);

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
