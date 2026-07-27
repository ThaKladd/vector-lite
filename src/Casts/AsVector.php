<?php

namespace ThaKladd\VectorLite\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\Enums\ReduceBy;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\VectorLite;

/**
 * Casts a vector-lite binary vector column to/from a plain float array -
 * the SQLite equivalent of Laravel's AI SDK `'embedding' => 'array'` cast
 * for a native pgvector column:
 *
 *     protected function casts(): array
 *     {
 *         return ['embedding' => AsVector::class];
 *     }
 *
 * Unlike the legacy `$model->vector = [...]` mutator (HasVector::setVectorAttribute,
 * always keyed to the literal attribute name "vector"), this cast is keyed to
 * whatever column name it's declared against, so it also works for arbitrary
 * per-model vector column names. It writes the companion `_norm`/`_hash`
 * columns alongside the packed binary value, and - when applied to a
 * VectorModel's configured vector column with clustering-dimension reduction
 * enabled - the `_small` trio too, matching what setVectorAttribute() maintains.
 */
class AsVector implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $norm = (float) ($attributes[$key.'_norm'] ?? 1.0);

        return VectorLite::denormalizeFromBinary($value, $norm);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return [$key => null, $key.'_norm' => null, $key.'_hash' => null];
        }

        [$normalizedVector, $norm] = VectorLite::normalize($value);
        $binaryVector = VectorLite::normalToBinary($normalizedVector);
        $binaryVectorHash = VectorLite::hashVectorBlob($binaryVector);

        VectorLite::$cache[$binaryVectorHash] = $normalizedVector;

        $columns = [
            $key => $binaryVector,
            $key.'_norm' => $norm,
            $key.'_hash' => $binaryVectorHash,
        ];

        return array_merge($columns, $this->reducedClusteringColumns($model, $key, $value));
    }

    /**
     * Mirrors the "_small" reduction HasVector::setVectorAttribute() performs,
     * but only when this cast is applied to a VectorModel's own configured
     * vector column - an arbitrary secondary vector column on the same model
     * (or a cast on a plain, non-VectorModel Eloquent model) has no clustering
     * concept to reduce for.
     */
    private function reducedClusteringColumns(Model $model, string $key, array $vector): array
    {
        if (
            ! $model instanceof VectorModel
            || $key !== $model::vectorColumn()
            || ! config('vector-lite.use_clustering_dimensions', false)
        ) {
            return [];
        }

        $reduceDimensions = config('vector-lite.clustering_dimensions');
        if ($reduceDimensions >= count($vector)) {
            return [];
        }

        /** @var ReduceBy $reduceByMethod */
        $reduceByMethod = config('vector-lite.reduction_method');
        $reducedVector = $reduceByMethod->reduceVector($vector, $reduceDimensions);
        [$normalizedReducedVector, $reducedNorm] = VectorLite::normalize($reducedVector);
        $reducedBinaryVector = VectorLite::normalToBinary($normalizedReducedVector);
        $reducedBinaryVectorHash = VectorLite::hashVectorBlob($reducedBinaryVector);

        VectorLite::$cache[$reducedBinaryVectorHash] = $normalizedReducedVector;

        return [
            $key.'_small' => $reducedBinaryVector,
            $key.'_small_norm' => $reducedNorm,
            $key.'_small_hash' => $reducedBinaryVectorHash,
        ];
    }
}
