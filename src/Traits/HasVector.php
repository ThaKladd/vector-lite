<?php

namespace ThaKladd\VectorLite\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\QueryBuilders\VectorLiteQueryBuilder;
use ThaKladd\VectorLite\Support\VectorLiteConfig;
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
            /** @var VectorModel $model */
            // Make sure the computed attribute is not saved
            if (array_key_exists(self::similarityAlias(), $model->attributes)) {
                unset($model->attributes[self::similarityAlias()]);
            }
        });

        static::created(function ($model) {
            /** @var VectorModel $model */
            // Only attempt clustering if a clusters table exists
            $clusterTable = $model->getClusterTableName();
            if (Schema::hasTable($clusterTable) && $model->{self::vectorColumn()}) {
                // Delegate the clustering logic to a dedicated service
                (new VectorLite($model))->processCreated($model);
            }
        });

        static::saving(function ($model) {
            /** @var VectorModel $model */
            if (array_key_exists(self::similarityAlias(), $model->attributes)) {
                unset($model->attributes[self::similarityAlias()]);
            }
            if (! empty($model->embedFields)) {
                // Update embedding hash column and create new embedding if changed.
                $model->createAndFillEmbedding();
            }
        });

        static::saved(function ($model) {
            /** @var VectorModel $model */
            // If saved, and the vector column was dirty, re-calculate the cluster
            $clusterTable = $model->getClusterTableName();
            if (Schema::hasTable($clusterTable) && $model->isDirty(self::vectorColumn()) && $model->{self::vectorColumn()}) {
                // Delegate the clustering logic to a dedicated service
                (new VectorLite($model))->processUpdated($model);
            }
        });

        static::deleted(function ($model) {
            /** @var VectorModel $model */
            // If deleted, remove from the cluster
            $clusterTable = $model->getClusterTableName();
            if (Schema::hasTable($clusterTable) && $model->{self::vectorColumn()}) {
                // Delegate the clustering logic to a dedicated service
                (new VectorLite($model))->processDelete($model);
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

    public function newEloquentBuilder($query): VectorLiteQueryBuilder|Builder
    {
        $config = VectorLiteConfig::getInstance();

        if ($config->isSqlLite) {
            return new VectorLiteQueryBuilder($query);
        }

        return new Builder($query);
    }

    /**
     * Gets you the vector query, for better autocompletion
     * as well as exception if not SqlLite
     */
    public function vectorQuery(): VectorLiteQueryBuilder
    {
        if (! VectorLiteConfig::getInstance()->isSqlLite) {
            throw new \RuntimeException('Vector queries only supported on SQLite');
        }

        return $this->newQuery();
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
        return Str::singular($this->getTable()).'_clusters';
    }

    /**
     * Gets a new instance of the cluster model for the given model
     */
    public function getClusterModel(): VectorModel
    {
        $clusterClass = $this->getClusterClass();

        return new $clusterClass;
    }

    /**
     * Check if the model is a cluster
     */
    public function isCluster(): bool
    {
        return str_ends_with(class_basename(static::class), 'Cluster');
    }

    /**
     * Get the model name
     */
    public function getModelName(): string
    {
        return static::class;
    }

    /**
     * Gets the name of the cluster for the given model
     *
     * @return class-string<VectorModel>
     */
    public function getClusterClass(): string
    {
        if ($this->isCluster()) {
            return static::class;
        }

        return static::class.'Cluster';
    }

    /**
     * Gets the name of the model for the given cluster
     *
     * @return class-string<VectorModel>
     */
    public function getModelClass(): string
    {
        if ($this->isCluster()) {
            return str_replace('Cluster', '', static::class);
        }

        return static::class;
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
        $vectorColumn = self::vectorColumn();
        // Pack array of floats into binary format
        // If not an array, assume it's already binary or handle error
        [$normalizedVector, $norm] = VectorLite::normalize($vector);
        $binaryVector = VectorLite::normalToBinary($normalizedVector);
        $binaryVectorHash = VectorLite::hashVectorBlob($binaryVector);

        VectorLite::$cache[$binaryVectorHash] = $normalizedVector;
        $this->attributes[$vectorColumn] = $binaryVector;
        $this->attributes[$vectorColumn.'_norm'] = $norm;
        $this->attributes[$vectorColumn.'_hash'] = $binaryVectorHash;

        if (config('vector-lite.use_clustering_dimensions', false)) {
            // Reduce the vector if it is bigger than the smaller dimension size
            $reduceByMethod = config('vector-lite.reduction_method');
            $reduceDimensions = config('vector-lite.clustering_dimensions');
            $reducedBinaryVector = $reducedNorm = $reducedVector = null;
            if ($reduceDimensions < count($vector)) {
                $vectorColumnSmall = $vectorColumn.'_small';
                $reducedVector = $reduceByMethod->reduceVector($vector, $reduceDimensions);
                [$normalizedReducedVector, $reducedNorm] = VectorLite::normalize($reducedVector);
                $reducedBinaryVector = VectorLite::normalToBinary($normalizedReducedVector);
                $reducedBinaryVectorHash = VectorLite::hashVectorBlob($reducedBinaryVector);

                VectorLite::$cache[$reducedBinaryVectorHash] = $normalizedReducedVector;
                $this->attributes[$vectorColumnSmall] = $reducedBinaryVector;
                $this->attributes[$vectorColumnSmall.'_norm'] = $reducedNorm;
                $this->attributes[$vectorColumnSmall.'_hash'] = $reducedBinaryVectorHash;
            }
        }
    }

    // Relationship to the cluster
    public function cluster(): BelongsTo
    {
        return $this->belongsTo($this->getClusterClass());
    }

    // Relationship to the models
    public function models(): HasMany
    {
        return $this->HasMany($this->getModelClass());
    }

    /**
     * Search for the best matches for the vector on the current object
     */
    public function getBestVectorMatches(?int $limit = null): Collection
    {
        $config = VectorLiteConfig::getInstance();
        if ($config->isSqlLite) {
            return static::query()->withoutModels($this)->bestByVector($this, $limit)->get();
        }

        $clusterKeys = collect([]);
        $clusterForeignKey = $this->getClusterForeignKey();
        if ($config->useClusteringDimensions) {
            $amountOfClusters = ceil($limit / $config->maxClusterSize) + 2;
            $clusterKeys = $this->getBestClusters((int) $amountOfClusters)->pluck('id');
        }

        return static::query()
            ->where($this->getKeyName(), '!=', $this->getKey())
            ->when(! $clusterKeys->isEmpty(), fn ($query) => $query->whereIn($clusterForeignKey, $clusterKeys))
            ->get()
            ->searchBestByVector($this, $limit);
    }

    /**
     * Find the best match for the vector on the current object
     */
    public function findBestVectorMatch(bool $includeCurrent = false): ?VectorModel
    {
        $config = VectorLiteConfig::getInstance();
        if ($config->isSqlLite) {
            $query = static::query();
            if (! $includeCurrent) {
                $query->withoutModels($this);
            }
            $query->bestByVector($this, 1)->first();
        }
        $clusterForeignKey = $this->getClusterForeignKey();
        $query = static::where($clusterForeignKey, $this->$clusterForeignKey);
        $collection = $query->get();
        if (! $includeCurrent) {
            $collection = $collection->except($this->getKey());
        }

        $vectorModel = $collection->searchBestByVector($this, 1)->first();

        /** @var VectorModel|null $vectorModel */
        return $vectorModel;
    }

    /**
     * Search for the best clusters based on current model
     */
    public function getBestClusters(int $amount = 1): Collection
    {
        /** @var class-string<VectorModel> $clusterClass */
        $clusterClass = $this->getClusterClass();
        $small = VectorLite::smallVectorColumn($this);
        $attribute = self::vectorColumn().$small;

        if (VectorLiteConfig::getInstance()->isSqlLite) {
            return $clusterClass::searchBestByVector($this->$attribute, $amount);
        }

        return $clusterClass::all()->searchBestByVector($this, $amount);
    }

    /**
     * Find for the best cluster based on current model
     */
    public function findBestCluster(): ?VectorModel
    {
        return $this->getBestClusters(1)->first();
    }
}
