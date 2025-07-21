<?php

namespace ThaKladd\VectorLite\Collections;

use Illuminate\Database\Eloquent\Collection;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends \Illuminate\Database\Eloquent\Collection<int|string, TModel>
 */
class VectorModelCollection extends Collection
{
    public function searchBestByVector(array $vector, int $limit = 1, string $vectorKey = 'vector')
    {
        // TODO: Work with _small
        // TODO: Get vector key from model
        // TODO: Implement the cosine similarity
        // TODO: Figure out cache
        // TODO: Exclude current if provided?
        return $this
            ->map(function ($item) {
                // $item->similarity = cosine_similarity($vector, data_get($item, $vectorKey));
                return $item;
            })
            ->sortByDesc('similarity')
            ->take($limit)
            ->values();
    }
}
