<?php

namespace ThaKladd\VectorLite\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\Support\SqlValidator;
use ThaKladd\VectorLite\VectorLite;

/**
 * @template TModel of \ThaKladd\VectorLite\Models\VectorModel
 *
 * @extends Collection<int|string, TModel>
 */
class VectorModelCollection extends Collection
{
    private static ?bool $useCache = null;

    /**
     * Calculates similarity between a model and the given vector.
     */
    private function computeSimilarity(VectorModel $model, array|string|VectorModel $vector): float
    {
        if (is_null(self::$useCache)) {
            self::$useCache = config('vector-lite.use_cached_cosim', false);
        }

        $binaryVector = VectorLite::vectorToBinary($vector);

        return self::$useCache
            ? VectorLite::cosineSimilarityModelAndVectorCache($model, $binaryVector)
            : VectorLite::cosineSimilarityModelAndVector($model, $binaryVector);
    }

    /**
     * Search for the best matches with vector
     */
    public function searchBestByVector(array|string|VectorModel $vector, int $limit = 1): Collection
    {
        $similarityAlias = VectorModel::similarityAlias();

        return $this
            ->map(function ($item) use ($vector, $similarityAlias) {
                $item->{$similarityAlias} = $this->computeSimilarity($item, $vector);

                return $item;
            })
            ->sortByDesc($similarityAlias)
            ->take($limit)
            ->values();
    }

    /**
     * Sort the collection according to the similarity to vector
     */
    public function sortBySimilarityToVector(array|string|VectorModel $vector, string $sortOrder = 'desc'): static
    {
        $sortOrder = strtoupper($sortOrder);
        SqlValidator::validateDirection($sortOrder);
        $similarityAlias = VectorModel::similarityAlias();

        $map = $this->map(function ($item) use ($vector, $similarityAlias) {
            $item->$similarityAlias = $this->computeSimilarity($item, $vector);

            return $item;
        });

        $map = match ($sortOrder) {
            'DESC' => $map->sortByDesc(fn ($item) => (float) ($item->{$similarityAlias} ?? 0)),
            'ASC' => $map->sortBy(fn ($item) => (float) ($item->{$similarityAlias} ?? 0)),
            default => $map,
        };

        return $map->values();
    }

    /**
     * Filter the collection according to a similarity threshold
     */
    public function filterAboveSimilarityThreshold(array|string|VectorModel $vector, float $threshold = 0.7): static
    {
        /** @var static */
        return $this->filter(function ($item) use ($vector, $threshold) {
            return $this->computeSimilarity($item, $vector) >= $threshold;
        })->values();
    }

    /**
     * Pluck only the similarities to the given vector
     */
    public function pluckSimilarities(null|array|string|VectorModel $vector = null): array
    {
        $similarityAlias = VectorModel::similarityAlias();

        return $this
            ->mapWithKeys(function ($item) use ($vector, $similarityAlias) {
                return [$item->getKey() => $vector ? $this->computeSimilarity($item, $vector) : $item->$similarityAlias];
            })
            ->toArray();
    }

    /**
     * Add similarities to a vector for every item into the collection
     */
    public function withSimilarities(array|string|VectorModel $vector): static
    {
        $similarityAlias = VectorModel::similarityAlias();

        return $this->map(function ($item) use ($vector, $similarityAlias) {
            $item->$similarityAlias = $this->computeSimilarity($item, $vector);

            return $item;
        });
    }

    /**
     * Return the best matched model from the collection
     */
    public function findBestByVector(array|string|VectorModel $vector): ?Model
    {
        return $this->searchBestByVector($vector, 1)->first();
    }
}
