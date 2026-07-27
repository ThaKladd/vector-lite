<?php

namespace ThaKladd\VectorLite\Tools;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;

/**
 * Standalone equivalent of Laravel AI SDK's `Laravel\Ai\Tools\SimilaritySearch`.
 * Own namespace under vector-lite, not a drop-in for laravel/ai's agent
 * tools() contract unless that package is installed - this is a plain,
 * directly-callable class that runs vector-lite's own whereVectorSimilarTo()
 * query (SQLite only, see VectorLiteQueryBuilder) under the hood.
 */
final class SimilaritySearch
{
    protected string $description = 'Search for content similar to a given query.';

    public function __construct(protected ?Closure $using = null) {}

    /**
     * @param  class-string<VectorModel>  $model
     */
    public static function usingModel(
        string $model,
        string $column,
        float $minSimilarity = 0.0,
        ?int $limit = null,
        ?Closure $query = null,
    ): static {
        return new self(function (string $search) use ($model, $column, $minSimilarity, $limit, $query) {
            /** @var VectorLiteQueryBuilder $builder */
            $builder = $model::query()->whereVectorSimilarTo($column, $search, $minSimilarity);

            if ($query) {
                $query($builder);
            }

            if ($limit) {
                $builder->limit($limit);
            }

            return $builder->get();
        });
    }

    public function withDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function __invoke(string $query): Collection
    {
        return ($this->using)($query);
    }
}
