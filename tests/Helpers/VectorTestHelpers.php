<?php

namespace ThaKladd\VectorLite\Tests\Helpers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\VectorLite;

trait VectorTestHelpers
{
    private function createVectorArray(int $dimensions = 1536): array
    {
        $vector = [];
        for ($j = 0; $j < $dimensions; $j++) {
            $vector[] = mt_rand() / mt_getrandmax();
        }

        return $vector;
    }

    private function createVectorRecord(int $dimensions = 1536, bool $isBatch = false): array
    {
        $vector = $this->createVectorArray($dimensions);
        $binaryVector = VectorLite::normalizeToBinary($vector);
        return [
            'vector' => $isBatch ? $binaryVector : $vector,
            'vector_hash' => VectorLiteQueryBuilder::hashVectorBlob($binaryVector),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createVectors(int $amount = 1000, int $dimensions = 1536, bool $isBatch = false): array
    {
        $records = [];
        for ($i = 0; $i < $amount; $i++) {
            $records[] = $this->createVectorRecord($dimensions, $isBatch);
        }

        return $records;
    }

    private function createAndInsertVector(int $dimensions = 1536): Vector
    {
        $this->fillVectorTable(1, $dimensions);

        return Vector::query()->orderBy('id', 'desc')->first();
    }

    private function fillVectorTable(int $amount = 1000, int $dimensions = 1536): void
    {
        $records = $this->createVectors($amount, $dimensions, true);

        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('vectors')->insert($chunk);
        }
    }

    private function fillVectorClusterTable(int $amount = 1000, int $dimensions = 1536): void
    {
        Artisan::call('vector-lite:cluster', ['table' => 'vectors']);

        $records = $this->createVectors($amount, $dimensions);

        foreach ($records as $record) {
            Vector::create($record);
        }
    }
}
