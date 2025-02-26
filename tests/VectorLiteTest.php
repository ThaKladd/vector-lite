<?php

use Illuminate\Support\Facades\DB;
use ThaKladd\VectorLite\Services\PineconeService;
use ThaKladd\VectorLite\VectorLite;

ini_set('memory_limit', '20000M');


it('tests the connection to the Pinecone service', function () {
    $pinecone = new PineconeService();

    $vector = [];
    for ($j = 0; $j < 1536; $j++) {
        $vector[] = mt_rand() / mt_getrandmax();
    }
    $pinecone->namespace('testing');
    $pinecone->storeEmbedding(3, $vector, ['id' => 2]);
    $pinecone->query(1, $vector, 10);
    $pinecone->deleteEmbeddings();
    expect(true)->toBeTrue();
})->skip();

it('measures the performance of the COSIM functions', function () {


    $start = microtime(true);

    $amount = 100000;
    $records = [];
    /*
    $vectors = [];
    for ($i = 0; $i < $amount; $i++) {
        $vector = [];
        for ($j = 0; $j < 1536; $j++) {
            $vector[] = mt_rand() / mt_getrandmax();
        }
        $chunk = 1;
        if ($i % 1000 === 0) {
            $chunk++;
        }
        $vectors[] = ['id' => 'i'.$i, 'values' => $vector, 'metadata' => ['id' => $i]];
        /*
        $records[] = [
            'chunk' => $chunk,
            'vector_raw' => implode(',', $vector),
            'vector_normalized' => implode(',', VectorLite::normalize($vector)),
            'vector_packed' => VectorLite::normalizeToBinary($vector),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        unset($vector);
    }*/

    // Insert records in chunks to avoid memory issues.
    /*
    foreach (array_chunk($records, 1000) as $chunk) {
        DB::table('vectors')->insert($chunk);
    }
*/
    $startPinecone = microtime(true);
    // Insert records into Pinecone
    $pinecone = new PineconeService();
    $pinecone->namespace('testing');
    //$pinecone->storeEmbeddings($vectors);
    $elapsedPinecone = round(microtime(true) - $startPinecone, 4);
    echo "\n\nUpsert to Pinecone database: $elapsedPinecone\n\n";

    $testVector = [];
    for ($i = 0; $i < 1536; $i++) {
        $testVector[] = mt_rand() / mt_getrandmax();
    }
    $testVectorRaw = implode(',', $testVector);
    $testVectorNormalized = implode(',', VectorLite::normalize($testVector));
    $testVectorPacked = VectorLite::normalizeToBinary($testVector);
    $elapsed = round(microtime(true) - $start, 4);
    echo "\n\nSeeding database: $elapsed\n\n";

    $topK = 20;
    // Measure the time to run the query.
    $start = microtime(true);
    $result = $pinecone->query(100, $testVector, $topK, true);
    $elapsed = round(microtime(true) - $start, 4);
    echo "100: $elapsed\n";

    $start = microtime(true);
    $result = $pinecone->query(1000, $testVector, $topK, true);
    $elapsed = round(microtime(true) - $start, 4);
    echo "1000: $elapsed\n";

    $start = microtime(true);
    $result = $pinecone->query(10000, $testVector, $topK, true);
    $elapsed = round(microtime(true) - $start, 4);
    echo "10000: $elapsed\n";

    $start = microtime(true);
    $result = $pinecone->query(100000, $testVector, $topK, true);
    $elapsed = round(microtime(true) - $start, 4);
    echo "100000: $elapsed\n\n";

    //$pinecone->deleteEmbeddings();
    /*
        // Measure the time to run the query.
        $start = microtime(true);
        $results = DB::select('SELECT COSIM_RAW(vector_raw, ?) as similarity FROM vectors WHERE id <= 100', [$testVectorRaw]);
        $elapsed = round(microtime(true) - $start, 4);
        echo "COSIM_RAW(100): $elapsed\n";

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


        $start = microtime(true);
        $results = DB::select('SELECT COSIM_CACHE(vector_packed, ?) as similarity FROM vectors WHERE id <= 100', [$testVectorPacked]);
        $elapsed = round(microtime(true) - $start, 4);
        echo "COSIM_CACHE(100): $elapsed\n";

        $start = microtime(true);
        $results = DB::select('SELECT COSIM_CACHE(vector_packed, ?) as similarity FROM vectors WHERE id <= 1000', [$testVectorPacked]);
        $elapsed = round(microtime(true) - $start, 4);
        echo "COSIM_CACHE(1000): $elapsed\n";

        $start = microtime(true);
        $results = DB::select('SELECT COSIM_CACHE(vector_packed, ?) as similarity FROM vectors WHERE id <= 10000', [$testVectorPacked]);
        $elapsed = round(microtime(true) - $start, 4);
        echo "COSIM_CACHE(10000): $elapsed\n";

        $start = microtime(true);
        $results = DB::select('SELECT COSIM_CACHE(vector_packed, ?) as similarity FROM vectors WHERE id <= 100000', [$testVectorPacked]);
        $elapsed = round(microtime(true) - $start, 4);
        echo "COSIM_CACHE(100000): $elapsed\n\n";

        expect($results)->not->toBeEmpty();*/
})->skip();
