<?php

use App\Models\VectorsCluster;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\VectorLite;

ini_set('memory_limit', '20000M');

it('can connect to database', function () {
    $result = DB::select('SELECT 1 AS test');
    expect($result[0]->test)->toBe(1);
});

it('can run vector lite cluster command', function(){
    //$this->artisan('vector-lite:cluster table=vectors');
    //Artisan::call('vector-lite:cluster', ['table' => 'vectors']);
    //$this->assertTrue(DB::getSchemaBuilder()->hasTable('vectors_clusters'));
});

it('measures the performance of the COSIM functions', function () {
    Artisan::call('vector-lite:cluster', ['table' => 'vectors']);
    $this->assertTrue(DB::getSchemaBuilder()->hasTable('vectors_clusters'));

    $start = microtime(true);

    $amount = 100000; //2000 = 2.2975 sec
    $records = [];

    for ($i = 0; $i < $amount; $i++) {
        $vector = [];
        for ($j = 0; $j < 1536; $j++) {
            $vector[] = mt_rand() / mt_getrandmax();
        }
        $chunk = 1;
        if ($i % 1000 === 0) {
            $chunk++;
        }

        $records[] = [
            'chunk' => $chunk,
            //'vector' => VectorLite::normalizeToBinary($vector), //$vector,
            'vector' => VectorLite::normalizeToBinary($vector), //implode(',', $vector),
            //'vector_normalized' => implode(',', VectorLite::normalize($vector)),
            //'vector_packed' => VectorLite::normalizeToBinary($vector),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        unset($vector);
    }

    // Insert records in chunks to avoid memory issues.


    foreach (array_chunk($records, 1000) as $chunk) {
        DB::table('vectors')->insert($chunk);
    }


    /*
    $i = 0;
    $startTime = microtime(true);
    foreach ($records as $record) {
        $i++;
        if($i % 100) {
            $startTime = microtime(true);
        }

        Vector::create($record);
        if($i % 100 == 0) {
            echo 'Time on '.$i. ': '.round(microtime(true) - $startTime, 8) . PHP_EOL;
        }
    }*/

    $testVector = [];
    for ($i = 0; $i < 1536; $i++) {
        $testVector[] = mt_rand() / mt_getrandmax();
    }
    $testVectorPacked = VectorLite::normalizeToBinary($testVector);
    $elapsed = round(microtime(true) - $start, 4);
    echo "\n\nSeeding database: $elapsed\n\n";

    // Echo out all tables in database
    /**
    $results = DB::select('SELECT name FROM sqlite_master WHERE type = "table"');
    echo 'Tables:'.PHP_EOL;
    foreach ($results as $result) {
        echo $result->name . "\n";
    }

    // Echo out the contents of the vectors_clusters table.
    $results = DB::select('SELECT * FROM vectors_clusters');
    echo 'vectors_clusters:'.PHP_EOL;
    foreach ($results as $result) {
        //Count the amount of vectors in each cluster with a query
        $realVectorsCount = DB::select('SELECT COUNT(*) as count FROM vectors WHERE vectors_cluster_id = ?', [$result->id])[0]->count;
        //echo $result->id . ' - ' . $result->vectors_count . ' real: ' . $realVectorsCount . ' vector: ' . $result->vector. PHP_EOL;
        echo $result->id . ' - ' . $result->vectors_count . ' real: ' . $realVectorsCount . PHP_EOL;
    }

    // Echo out the contents of the vectors table.
    $results = DB::select('SELECT id, vector, vectors_cluster_id, vectors_cluster_match FROM vectors LIMIT 30');
    echo 'vectors:'.PHP_EOL;

    foreach ($results as $result) {
        //echo 'Vector '.$result->id.': cluster-'.$result->vectors_cluster_id .' - match-' . $result->vectors_cluster_match  . ' - '. $result->vector . PHP_EOL;
        echo 'Vector '.$result->id.': cluster-'.$result->vectors_cluster_id .' - match-' . $result->vectors_cluster_match . PHP_EOL;
    }
*/

    /*
    $results = DB::select('SELECT id FROM vectors_clusters LIMIT 30');
    echo 'vector_clusters:'.PHP_EOL;

    foreach ($results as $result) {
        $clusterAmount = Vector::selectRaw(
            "COUNT(id) as count"
        )->where('vectors_cluster_id', '=', $result->id)->first()->count;

        echo 'VectorCluster '.$result->id . ' Amount:' . $clusterAmount.  PHP_EOL;
    }*/


    // Measure the time to run the query.
    /*
    $start = microtime(true);
    $results = DB::select('
        SELECT COSIM_RAW(vector_raw, ?) as similarity FROM vectors WHERE id <= 100 AND vectors_cluster_id IN (' . $subQuery . ')', [$testVectorRaw, $testVectorRaw]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_RAW(100): $elapsed\n";
*/
    /*
    $start = microtime(true);
    $results = DB::select('SELECT COSIM_RAW(vector_raw, ?) as similarity FROM vectors WHERE id <= 1000', [$testVectorRaw]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_RAW(1000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_RAW(vector_raw, ?) as similarity FROM vectors WHERE id <= 10000', [$testVectorRaw]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_RAW(10000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_RAW(vector_raw, ?) as similarity FROM vectors WHERE id <= 100000', [$testVectorRaw]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_RAW(100000): $elapsed\n\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_NORM(vector_normalized, ?) as similarity FROM vectors WHERE id <= 100', [$testVectorNormalized]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_NORM(100): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_NORM(vector_normalized, ?) as similarity FROM vectors WHERE id <= 1000', [$testVectorNormalized]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_NORM(1000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_NORM(vector_normalized, ?) as similarity FROM vectors WHERE id <= 10000', [$testVectorNormalized]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_NORM(10000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_NORM(vector_normalized, ?) as similarity FROM vectors WHERE id <= 100000', [$testVectorNormalized]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_NORM(100000): $elapsed\n\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_PACK(vector_packed, ?) as similarity FROM vectors WHERE id <= 100', [$testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_PACK(100): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_PACK(vector_packed, ?) as similarity FROM vectors WHERE id <= 1000', [$testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_PACK(1000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_PACK(vector_packed, ?) as similarity FROM vectors WHERE id <= 10000', [$testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_PACK(10000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_PACK(vector_packed, ?) as similarity FROM vectors WHERE id <= 100000', [$testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_PACK(100000): $elapsed\n\n";

*/
    $start = microtime(true);
    $results = DB::select('SELECT COSIM_CACHE(vectors.id, vector, 1, ?) as similarity FROM vectors WHERE id <= 100', [$testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_CACHE(100): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_CACHE(vectors.id, vector, 2, ?) as similarity FROM vectors WHERE id <= 1000', [$testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_CACHE(1000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_CACHE(vectors.id, vector, 3, ?) as similarity FROM vectors WHERE id <= 10000', [$testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_CACHE(10000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_CACHE(vectors.id, vector, 4, ?) as similarity FROM vectors WHERE id <= 100000', [$testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_CACHE(100000): $elapsed\n\n";

    /*
    $subQuery = 'SELECT id FROM vectors_clusters ORDER BY COSIM_CACHE(vectors_clusters.id, vectors_clusters.vector, vectors_clusters.id, ?) DESC LIMIT 1';
    $start = microtime(true);
    $results = DB::select('SELECT COSIM_CACHE(vectors.id, vector, vectors.id, ?) as similarity FROM vectors WHERE id <= 100 AND vectors_cluster_id IN (' . $subQuery . ')', [$testVectorPacked, $testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_CACHE(100): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_CACHE(vectors.id, vector, vectors.id, ?) as similarity FROM vectors WHERE id <= 1000 AND vectors_cluster_id IN (' . $subQuery . ')', [$testVectorPacked, $testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_CACHE(1000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_CACHE(vectors.id, vector, vectors.id, ?) as similarity FROM vectors WHERE id <= 10000 AND vectors_cluster_id IN (' . $subQuery . ')', [$testVectorPacked, $testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_CACHE(10000): $elapsed\n";

    $start = microtime(true);
    $results = DB::select('SELECT COSIM_CACHE(vectors.id, vector, vectors.id, ?) as similarity FROM vectors WHERE id <= 100000 AND vectors_cluster_id IN (' . $subQuery . ')', [$testVectorPacked, $testVectorPacked]);
    $elapsed = round(microtime(true) - $start, 4);
    echo "COSIM_CACHE(100000): $elapsed\n\n";
*/
    //expect($results)->not->toBeEmpty();
});
