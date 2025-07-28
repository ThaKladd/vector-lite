<?php

namespace ThaKladd\VectorLite\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ThaKladd\VectorLite\Enums\ReduceBy;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\Tests\Models\Vector;
use ThaKladd\VectorLite\VectorLite;

/**
 * @mixin Model
 */
trait HasVector
{
    public bool $useCache = false;

    public static function bootHasVector(): void
    {
        static::creating(function ($model) {
            // Make sure the computed attribute is not saved
            if (array_key_exists(self::similarityAlias(), $model->attributes)) {
                unset($model->attributes[self::similarityAlias()]);
            }
        });

        static::created(function ($model) {
            // Remove the computed attribute if it exists in the attributes array
            /*
            if (array_key_exists($model->similarityAlias, $model->attributes)) {
                unset($model->attributes[$model->similarityAlias]);
            }

            if (!in_array($model->similarityAlias, $model->guarded)) {
                $model->guarded[] = $model->similarityAlias;
            }*/

            // if ($model->isDirty($model->vectorColumn)) {
            // $model->vector = VectorLite::normalizeToBinary($model->vector);
            // Only attempt clustering if a clusters table exists
            $clusterTable = $model->getClusterTableName();
            if (Schema::hasTable($clusterTable) && $model->{self::vectorColumn()}) {
                // Delegate the clustering logic to a dedicated service
                new VectorLite($model)->processCreated($model);
            }
            // }
        });

        static::saving(function ($model) {
            // If the vector column is dirty, re-create hash
            if ($model->isDirty($model->{self::vectorColumn()})) {
                $hashColumn = $model->getVectorHashColumn(self::vectorColumn());
                $model->$hashColumn = $model->hashVectorBlob(self::vectorColumn());
            }
        });

        static::saved(function ($model) {
            // If saved, and the vector column was dirty, re-calculate the cluster
            $clusterTable = $model->getClusterTableName();
            if (Schema::hasTable($clusterTable) && $model->isDirty($model->{self::vectorColumn()}) && $model->{self::vectorColumn()}) {
                // Delegate the clustering logic to a dedicated service
                new VectorLite($model)->processUpdated($model);
            }

            if (! empty($model->embedFields)) {
                $text = $model->getEmbeddingText();
                $hash = VectorLite::hashText($text);

                if ($model->{self::embedHashColumn()} !== $hash) {
                    $model->{self::embedHashColumn()} = $hash;
                    $model->saveQuietly();
                    $model->createEmbedding($text);
                }
            }
        });

        static::deleted(function ($model) {
            // If deleted, remove from the cluster
            $clusterTable = $model->getClusterTableName();
            if (Schema::hasTable($clusterTable) && $model->{self::vectorColumn()}) {
                // Delegate the clustering logic to a dedicated service
                new VectorLite($model)->processDelete($model);
            }
        });
    }

    public static function similarityAlias(): string
    {
        return config('vector-lite.similarity_alias', 'similarity');
    }

    public static function vectorColumn(): string
    {
        return config('vector-lite.vector_column', 'vector');
    }

    public static function embedHashColumn(): string
    {
        return config('vector-lite.embed_hash_column', 'embed_hash');
    }

    public function newEloquentBuilder($query): VectorLiteQueryBuilder
    {
        return new VectorLiteQueryBuilder($query);
    }

    /**
     * This initializer is automatically called when the model is instantiated.
     */
    public function initializeHasVector(): void
    {
        $this->useCache = config('vector-lite.use_cached_cosim', false);
        // Ensure 'similarity' is in the appended attributes.
        if (! in_array(self::similarityAlias(), $this->appends)) {
            $this->appends[] = self::similarityAlias();
        }
    }

    /**
     * Get the foreign key for the cluster table id of the vector on the model
     */
    public function getClusterForeignKey(): string
    {
        return Str::singular($this->getClusterTableName()).'_id';
    }

    /**
     * Get the table name for the clustering of the vector on the model
     */
    public function getClusterTableName(): string
    {
        return $this->getTable().'_clusters';
    }

    /**
     * Gets the name of the cluster model for the given model
     *
     * @return class-string<VectorModel>
     */
    public function getClusterModelName(): string
    {
        return Str::plural(get_class($this)).'Cluster';
    }

    /**
     * Gets a new instance of the cluster model for the given model
     */
    public function getClusterModel(): VectorModel
    {
        $clusterClass = $this->getClusterModelName();

        return new $clusterClass;
    }

    /**
     * Check if the model is a cluster
     */
    public function isCluster(): bool
    {
        return str_ends_with($this->getModel()::class, 'Cluster');
    }

    public function getModelName(): string
    {
        return get_class($this);
    }

    public function getUniqueRowIdAttribute(): string
    {
        return $this->getTable().'_'.$this->getKey();
    }

    /**
     * Accessor for the similarity attribute.
     *
     * This allows you to cast the similarity attribute to a float, for example.
     */
    public function getSimilarityAttribute(): ?float
    {
        // Use the dynamic column name stored in $this->similarityAlias, defaulting to 'similarity'
        return isset($this->attributes[self::similarityAlias()]) ? (float) $this->attributes[self::similarityAlias()] : null;
    }

    /**
     * Accessor for the vector column attribute.
     *
     * When you retrieve $model->vector, it will be unpacked into an array of floats.
     *
     * @param  mixed  $value
     * @return array|null
     */
    /*
    public function getVectorAttribute($value) {
        $column = $this->vectorColumn ?? 'vector';
        $floats = isset($this->attributes[$column]) ? (float) $this->attributes[$column] : null;

        if (!is_null($floats)) {
            // Unpack binary string back into an array of floats
            $floats = unpack("f*", $value);
            // unpack returns a 1-based indexed array,
            // convert it to a 0-based indexed array:
            return array_values($floats);
        }

        return null;
    }*/

    /**
     * Mutator for setting the vector attribute.
     *
     * When you do $model->vector = [0.123, 0.456, ...], it will be stored as binary floats.
     */
    public function setVectorAttribute(array $vector): void
    {
        // Pack array of floats into binary format
        // If not an array, assume it's already binary or handle error
        [$binaryVector, $norm] = VectorLite::normalizeToBinary($vector);
        $this->attributes[self::vectorColumn()] = $binaryVector;
        $this->attributes[self::vectorColumn().'_norm'] = $norm;
        $this->attributes[self::vectorColumn().'_hash'] = VectorLite::hashVectorBlob($binaryVector);

        if (config('vector-lite.use_clustering_dimensions', false)) {
            // Reduce the vector if it is bigger than the smaller dimension size
            $reduceByMethod = config('vector-lite.reduction_method');
            $reduceDimensions = config('vector-lite.clustering_dimensions');
            $reducedBinaryVector = $reducedNorm = $reducedVector = null;
            if ($reduceDimensions < count($vector)) {
                $reducedVector = $reduceByMethod->reduceVector($vector, $reduceDimensions);
                [$reducedBinaryVector, $reducedNorm] = VectorLite::normalizeToBinary($reducedVector);
                $this->attributes[self::vectorColumn().'_small'] = $reducedBinaryVector;
                $this->attributes[self::vectorColumn().'_small_norm'] = $reducedNorm;
                $this->attributes[self::vectorColumn().'_small_hash'] = VectorLite::hashVectorBlob($reducedBinaryVector);
            }
        }
    }

    // Relationship to the cluster
    public function cluster(): HasOne
    {
        $clusterModelName = $this->getClusterModelName();
        $clusterModel = new $clusterModelName;

        return $this->hasOne($clusterModel::class);
    }

    /**
     * Creates an embedding, and, if turned on, a reduced embedding, for the text input
     */
    public function createEmbedding(string $text, ?int $dimensions = null): array
    {
        $dimensions = $dimensions ?? config('vector-lite.default_dimensions');
        $embeddingServiceKey = config('openai.api_key');
        if ($embeddingServiceKey) {
            $embeddingService = app(EmbeddingService::class);
            $embedding = $embeddingService->createEmbedding($text, $dimensions);
        } else {
            $embedding = VectorLite::vectorToArray($this->{self::vectorColumn()});
        }

        $reducedEmbedding = [];
        if (config('vector-lite.use_clustering_dimensions')) {
            $dimensions = config('vector-lite.clustering_dimensions');
            $reduceByMethod = config('vector-lite.reduction_method');
            $vectorInput = $reduceByMethod === ReduceBy::NEW_EMBEDDING ? $text : $embedding;
            $reducedEmbedding = $reduceByMethod->reduceVector($vectorInput, $dimensions);
        }

        return [$embedding, $reducedEmbedding];
    }

    /**
     * Search for the best matches for the vector on the current object
     */
    public function getBestVectorMatches(?int $limit = null): Collection
    {
        return static::query()->withoutModels($this)->bestByVector($this, $limit)->get();
    }

    /**
     * Find the best match for the vector on the current object
     */
    public function findBestVectorMatch(): Collection
    {
        return static::query()->withoutModels($this)->bestByVector($this, 1)->get();
    }

    /**
     * Search for the best clusters based on current model
     */
    public function getBestClusters(int $amount = 1): Collection
    {
        /** @var class-string<VectorModel> $clusterClass */
        $clusterClass = $this->getClusterModelName();
        $small = VectorLite::smallVectorColumn($this);
        $attribute = self::vectorColumn().$small;

        return $clusterClass::searchBestByVector($this->$attribute, $amount);
    }
}
