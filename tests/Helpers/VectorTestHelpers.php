<?php

namespace ThaKladd\VectorLite\Tests\Helpers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ThaKladd\VectorLite\Support\ClusterCache;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\VectorLite;

trait VectorTestHelpers
{
    /**
     * Fakes the OpenAI-compatible embeddings endpoint: every input gets a
     * random vector of the requested "dimensions" (or $dimensions, e.g. when
     * the request doesn't send it).
     */
    private function fakeEmbeddingApi(?int $dimensions = null): void
    {
        Http::fake(['*' => function (Request $request) use ($dimensions) {
            $payload = $request->data();
            $size = $dimensions ?? (int) ($payload['dimensions'] ?? config('vector-lite.default_dimensions'));

            return Http::response($this->embeddingResponse(
                array_map(fn () => $this->createVectorArray($size), (array) $payload['input'])
            ));
        }]);
    }

    /**
     * Fakes the embeddings endpoint with a fixed vector per input text, as
     * sent - i.e. including any query/document prefix.
     *
     * @param  array<string, array<int, float|int>>  $vectors
     */
    private function fakeEmbeddingVectors(array $vectors): void
    {
        Http::fake(['*' => function (Request $request) use ($vectors) {
            return Http::response($this->embeddingResponse(array_map(
                fn ($input) => $vectors[$input] ?? throw new RuntimeException("No fake embedding for input [{$input}]"),
                (array) $request->data()['input']
            )));
        }]);
    }

    /**
     * @param  array<int, array<int, float|int>>  $embeddings
     */
    private function embeddingResponse(array $embeddings, string $model = 'fake-model'): array
    {
        return [
            'object' => 'list',
            'data' => array_map(
                fn ($embedding, $index) => ['object' => 'embedding', 'index' => $index, 'embedding' => $embedding],
                array_values($embeddings),
                array_keys(array_values($embeddings))
            ),
            'model' => $model,
        ];
    }

    /**
     * The "input" lists of every request sent to the faked endpoint, in order.
     *
     * @return array<int, array<int, string>>
     */
    private function sentEmbeddingInputs(): array
    {
        return Http::recorded()->map(fn ($pair) => (array) $pair[0]->data()['input'])->values()->all();
    }

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
        [$binaryVector, $norm] = VectorLite::normalizeToBinary($vector);

        return [
            'title' => 'test',
            'other_id' => 1,
            'embed_hash' => '529ba8695f839f00',
            'vector' => $isBatch ? $binaryVector : $vector,
            'vector_hash' => VectorLite::hashVectorBlob($binaryVector),
            'vector_norm' => $norm,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createVectors(int $amount = 1000, int $dimensions = 1536, bool $isBatch = false): array
    {
        DB::table('others')->insert([
            'description' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
        ClusterCache::reset();
        $records = $this->createVectors($amount, $dimensions, true);
        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('vectors')->insert($chunk);
        }
    }

    private function fillVectorClusterTable(int $amount = 1000, int $dimensions = 1536): void
    {
        ClusterCache::reset();
        $records = $this->createVectors($amount, $dimensions);

        foreach ($records as $record) {
            Vector::create($record);
        }
    }
}
