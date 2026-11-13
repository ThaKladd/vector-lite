<?php

namespace ThaKladd\VectorLite\Enums;

use ThaKladd\VectorLite\Services\EmbeddingService;

enum ReduceBy: string
{
    case RPM = 'rpm'; // Random Projection Method
    case NEW_EMBEDDING = 'new_embedding'; // Create a new embedding with specified dimensions
    case CHUNKED_AGGREGATION = 'chunked_aggregation'; // Aggregate chunks of the vector into a new one

    public function reduceVector(array|string $vectorData, ?int $toDimensions = null): array
    {
        $toDimensions = $toDimensions ?? config('vector-lite.clustering_dimensions');

        return match ($this) {
            self::RPM => $this->reduceByRandomProjection($vectorData, $toDimensions),
            self::CHUNKED_AGGREGATION => $this->reduceByChunkedAggregation($vectorData, $toDimensions),
            self::NEW_EMBEDDING => $this->createNewEmbedding($vectorData, $toDimensions),
        };
    }

    private function reduceByRandomProjection(array $vector, int $toDimensions): array
    {
        $vectorDimensions = count($vector);
        $projectionMatrix = $this->generateRandomMatrix($toDimensions, $vectorDimensions);

        $reducedVector = [];
        foreach ($projectionMatrix as $row) {
            $sum = 0.0;
            foreach ($row as $i => $weight) {
                $sum += $vector[$i] * $weight;
            }
            $reducedVector[] = $sum;
        }

        return $reducedVector;
    }

    private function reduceByChunkedAggregation(array $vector, int $toDimensions): array
    {
        $vectorDimensions = count($vector);
        $chunkSize = (int) floor($vectorDimensions / $toDimensions);
        $reducedVector = [];

        for ($i = 0; $i < $toDimensions; $i++) {
            $chunk = array_slice($vector, $i * $chunkSize, $chunkSize);
            $reducedVector[] = array_sum($chunk) / max(count($chunk), 1);
        }

        return $reducedVector;
    }

    /**
     * A second, smaller embedding of the same document text, through the same
     * configured endpoint (and document prefix) as the full vector.
     */
    private function createNewEmbedding(string $input, int $toDimensions): array
    {
        $embeddingService = app(EmbeddingService::class);

        return $embeddingService->createEmbedding($input, $toDimensions, EmbeddingInputType::Document);
    }

    /**
     * The projection matrix for one (input, output) dimension pair, cached on
     * disk so every vector of that size is projected the same way. A vector
     * of another length (e.g. after switching embedding models) gets its own
     * matrix and file - never a stale one - and a cached file whose shape
     * doesn't match the pair is regenerated rather than used.
     */
    private function generateRandomMatrix(int $outputDim, int $inputDim): array
    {
        static $matrices = [];

        $fileName = "random_project_{$inputDim}_{$outputDim}.json";
        $filePath = storage_path("app/{$fileName}");

        if (isset($matrices[$filePath])) {
            return $matrices[$filePath];
        }

        if (file_exists($filePath)) {
            $matrix = json_decode((string) file_get_contents($filePath), true);
            if (self::matrixHasShape($matrix, $outputDim, $inputDim)) {
                return $matrices[$filePath] = $matrix;
            }
        }

        $matrix = [];
        for ($i = 0; $i < $outputDim; $i++) {
            $row = [];
            for ($j = 0; $j < $inputDim; $j++) {
                // Uniformly sampled from [-1, 1]
                $row[] = mt_rand() / mt_getrandmax() * 2 - 1;
            }
            $matrix[] = $row;
        }

        file_put_contents($filePath, json_encode($matrix));

        return $matrices[$filePath] = $matrix;
    }

    private static function matrixHasShape(mixed $matrix, int $rows, int $columns): bool
    {
        if (! is_array($matrix) || count($matrix) !== $rows) {
            return false;
        }

        foreach ($matrix as $row) {
            if (! is_array($row) || count($row) !== $columns) {
                return false;
            }
        }

        return true;
    }
}
