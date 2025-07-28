<?php

namespace ThaKladd\VectorLite\Models;

use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\Collections\VectorModelCollection;
use ThaKladd\VectorLite\Contracts\HasVectorType;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;
use ThaKladd\VectorLite\Traits\HasVector;

abstract class VectorModel extends Model implements HasVectorType
{
    use HasVector;

    /**
     * Fields or relations to embed into the vector text.
     * Supports dot notation for nested relations.
     * Example: ['title', 'description', 'reviews.title', 'reviews.review']
     */
    protected array $embedFields = [];

    protected $fillable = [];

    protected static function booted(): void
    {
        static::retrieved(function (self $model) {
            $model->mergeFillableFromVectorColumn();
        });

        static::creating(function (self $model) {
            $model->mergeFillableFromVectorColumn();
        });
    }

    protected function mergeFillableFromVectorColumn(): void
    {
        $column = static::vectorColumn();
        $vectorColumns = [$column, $column.'_norm', $column.'_hash'];

        $columnSmall = config('vector-lite.use_clustering_dimensions', false) ? $column.'_small' : '';
        $vectorSmallColumns = $columnSmall ? [$columnSmall, $columnSmall.'_norm', $columnSmall.'_hash'] : [];

        $this->fillable(array_merge(
            $this->getFillable(),
            $vectorColumns,
            $vectorSmallColumns,
        ));
    }

    /**
     * @return VectorLiteQueryBuilder<static>
     */
    public function newEloquentBuilder($query): VectorLiteQueryBuilder
    {
        /** @var VectorLiteQueryBuilder<static> */
        return new VectorLiteQueryBuilder($query);
    }

    /**
     * @param  static[]  $models
     * @return VectorModelCollection<static>
     */
    public function newCollection(array $models = []): VectorModelCollection
    {
        /** @var VectorModelCollection<static> */
        return new VectorModelCollection($models);
    }

    /**
     * @return VectorLiteQueryBuilder<static>
     */
    public function newModelQuery(): VectorLiteQueryBuilder
    {
        /** @var VectorLiteQueryBuilder<static> */
        return parent::newModelQuery();
    }

    /**
     * Builds the embedding text content based on $embedFields.
     */
    public function getEmbeddingText(): string
    {
        $root = strtolower(class_basename($this));
        $text = "<{$root} id=\"{$this->getKey()}\">\n";

        foreach ($this->embedFields as $path) {
            $text .= $this->resolveEmbedPath($this, $path, 1);
        }

        $text .= "</{$root}>\n";

        return $text;
    }

    /**
     * Recursively resolve an embed path and return as pseudo-HTML string.
     */
    protected function resolveEmbedPath(Model $model, string $path, int $indentLevel): string
    {
        $indent = str_repeat('  ', $indentLevel);
        $parts = explode('.', $path);
        $first = array_shift($parts);
        $remaining = implode('.', $parts);

        // Attribute
        if (empty($parts)) {
            $value = $model->getAttribute($first);
            if ($value === null) {
                return '';
            }

            return "{$indent}<{$first}>{$value}</{$first}>\n";
        }

        // Relation
        $relation = $model->$first;

        if ($relation === null) {
            return '';
        }

        $output = "{$indent}<{$first}>\n";

        if ($relation instanceof \Illuminate\Database\Eloquent\Collection) {
            foreach ($relation as $relatedModel) {
                $singular = rtrim($first, 's');
                $output .= "{$indent}  <{$singular}>\n";
                $output .= $this->resolveEmbedPath($relatedModel, $remaining, $indentLevel + 2);
                $output .= "{$indent}  </{$singular}>\n";
            }
        } elseif ($relation instanceof Model) {
            $output .= $this->resolveEmbedPath($relation, $remaining, $indentLevel + 1);
        }

        $output .= "{$indent}</{$first}>\n";

        return $output;
    }
}
