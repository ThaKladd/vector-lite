<?php

namespace ThaKladd\VectorLite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use ThaKladd\VectorLite\Collections\VectorModelCollection;
use ThaKladd\VectorLite\Contracts\HasVectorType;
use ThaKladd\VectorLite\Enums\EmbeddingInputType;
use ThaKladd\VectorLite\Exceptions\EmbeddingException;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Traits\HasVector;

abstract class VectorModel extends Model implements HasVectorType
{
    use HasVector;

    protected static array $relationCache = [];

    /**
     * Fields or relations to embed into the vector text.
     * Supports dot notation for nested relations.
     * Example: ['title', 'description', 'reviews.title', 'reviews.review']
     */
    protected $embedFields = [];

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
        if (! empty($this->fillable)) {
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
        $fieldsText = '';
        foreach ($this->embedFields as $path) {
            $fieldsText .= $this->resolveEmbedPath($this, $path, 1);
        }

        $text = '';
        if (! empty($fieldsText)) {
            $root = strtolower(class_basename($this));

            $text = "<{$root} id=\"{$this->getKey()}\">\n$fieldsText</{$root}>\n";
        }

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
        if (empty($parts) && array_key_exists($first, $model->getAttributes())) {
            $value = $model->getAttribute($first);
            if ($value === null || $value === '') {
                return '';
            }
            $value = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return "{$indent}<{$first}>{$value}</{$first}>\n";
        }

        // Relation
        if ($this->isRelationName($model, $first)) {
            $relation = $model->$first;
            if ($relation === null) {
                return '';
            }

            return $this->resolveEmbedPathFromValue(
                $first,
                $relation,
                $remaining,
                $indentLevel
            );
        }

        // Method
        if (method_exists($model, $first) && is_callable([$model, $first])) {
            $value = $model->{$first}();

            // If method returns a Model or Collection
            if ($value instanceof Model || $value instanceof Collection) {
                return $this->resolveEmbedPathFromValue(
                    $first,
                    $value,
                    $remaining,
                    $indentLevel
                );
            }

            // If method returns scalar or string
            if (! empty($value) && is_scalar($value)) {
                $value = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return "{$indent}<{$first}>{$value}</{$first}>\n";
            }

            // If method returns array
            if (is_array($value)) {
                $output = "{$indent}<{$first}>\n";
                foreach ($value as $key => $val) {
                    $output .= "{$indent}  <{$key}>{$val}</{$key}>\n";
                }
                $output .= "{$indent}</{$first}>\n";

                return $output;
            }
        }

        return '';
    }

    /**
     * Helper to handle Model/Collection recursion
     */
    protected function resolveEmbedPathFromValue(
        string $tag,
        $value,
        string $remaining,
        int $indentLevel
    ): string {
        $indent = str_repeat('  ', $indentLevel);
        $output = "{$indent}<{$tag}>\n";

        if ($value instanceof \Illuminate\Database\Eloquent\Collection) {
            foreach ($value as $relatedModel) {
                $singular = rtrim($tag, 's');
                $output .= "{$indent}  <{$singular}>\n";
                $output .= $this->resolveEmbedPath(
                    $relatedModel,
                    $remaining,
                    $indentLevel + 2
                );
                $output .= "{$indent}  </{$singular}>\n";
            }
        } elseif ($value instanceof Model) {
            $output .= $this->resolveEmbedPath(
                $value,
                $remaining,
                $indentLevel + 1
            );
        }

        $output .= "{$indent}</{$tag}>\n";

        return $output;
    }

    /**
     * Helper to figure out if the name is a relation method
     */
    protected function isRelationName(Model $model, string $name): bool
    {
        $class = get_class($model);
        if (isset(self::$relationCache[$class][$name])) {
            return self::$relationCache[$class][$name];
        }

        self::$relationCache[$class][$name] = false;

        if (method_exists($model, $name)) {
            try {
                $result = Relation::noConstraints(fn () => $model->{$name}());
                self::$relationCache[$class][$name] = $result instanceof Relation;
            } catch (\Throwable $e) {
            }
        }

        return self::$relationCache[$class][$name];
    }

    /**
     * The fields/relations/methods this model's embedding text is built from.
     */
    public function getEmbedFields(): array
    {
        return $this->embedFields;
    }

    /**
     * Creates a document embedding from the model's embedding text (or $text).
     * Returns null, without calling out, when there is no text or no
     * embedding provider is configured (OpenAI without an API key).
     *
     * @throws EmbeddingException when the provider fails or returns an invalid vector
     */
    public function createEmbedding(?string $text = null): ?array
    {
        return $this->embedText(app(EmbeddingService::class), $text ?? $this->getEmbeddingText());
    }

    /**
     * The embed_hash this model should have for its current embedding text
     * under the current embedding settings (model, dimensions, document
     * prefix) - see EmbeddingConfig::embedHash().
     */
    public function embeddingHash(?string $text = null, ?EmbeddingService $service = null): string
    {
        $service ??= app(EmbeddingService::class);

        return $service->config()->embedHash($text ?? $this->getEmbeddingText());
    }

    /**
     * Whether the stored vector is out of date: its text changed, or it was
     * embedded with other settings, or embedding it failed earlier.
     */
    public function hasStaleEmbedding(): bool
    {
        return $this->{static::embedHashColumn()} !== $this->embeddingHash();
    }

    /**
     * Creates the embedding and fills it into the model (together with the
     * reduced clustering vector, if enabled) - only when the embedding text
     * or the embedding settings changed since the stored embed_hash.
     *
     * embed_hash and the vector are written together, and only after a
     * successful, valid embedding. If there's no provider, no text, or the
     * provider throws, neither is touched, so the row stays stale and the
     * next attempt retries it.
     *
     * @throws EmbeddingException
     */
    public function createAndFillEmbedding(): static
    {
        $service = app(EmbeddingService::class);
        $text = $this->getEmbeddingText();
        $hash = $this->embeddingHash($text, $service);

        if ($this->{static::embedHashColumn()} === $hash) {
            return $this;
        }

        $embedding = $this->embedText($service, $text);
        if (! empty($embedding)) {
            $this->fillEmbedding($embedding, $hash);
        }

        return $this;
    }

    /**
     * Sets the vector and its embed_hash together.
     */
    public function fillEmbedding(array $embedding, ?string $hash = null): static
    {
        $this->{static::vectorColumn()} = $embedding;
        $this->{static::embedHashColumn()} = $hash ?? $this->embeddingHash();

        return $this;
    }

    protected function embedText(EmbeddingService $service, string $text): ?array
    {
        if ($text === '' || ! $service->isConfigured()) {
            return null;
        }

        return $service->createEmbedding($text, $service->config()->dimensions, EmbeddingInputType::Document);
    }
}
