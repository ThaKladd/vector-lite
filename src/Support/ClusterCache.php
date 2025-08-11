<?php

namespace ThaKladd\VectorLite\Support;

use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\Collections\VectorModelCollection;
use ThaKladd\VectorLite\Models\VectorModel;

class ClusterCache
{
    public static array $instances = [];

    public ?VectorModelCollection $objects = null;

    public ?array $objectKeys = [];

    public static function getInstance(string $modelClass): self
    {
        return self::$instances[$modelClass] ??= new self($modelClass);
    }

    public static function reset(): void
    {
        self::$instances = [];
    }

    public function __construct(public string $modelClass)
    {
        $this->objects = new VectorModelCollection;
    }

    /**
     * This is called to ensure that the cache contains all the clusters
     */
    public function fetchAllFromDb(): static
    {
        if ($this->objects === null) {
            $this->objects = $this->modelClass::all()->keyBy('id');
        }

        $newClusters = $this->modelClass::whereNotIn('id', $this->objects->keys()->all())->get();
        if ($newClusters->isNotEmpty()) {
            $this->objects = $this->objects->merge($newClusters->keyBy('id'));
        }

        return $this;
    }

    public function add(VectorModel $modelVector): static
    {
        $this->objects[$modelVector->getKey()] = $modelVector;

        return $this;
    }

    public function increment(int $clusterId, string $countColumn, int $count): static
    {
        $this->getOrLoadCluster($clusterId)->$countColumn += $count;

        return $this;
    }

    public function decrement(int $clusterId, string $countColumn, int $count): static
    {
        $this->getOrLoadCluster($clusterId)->$countColumn -= $count;

        return $this;
    }

    private function getOrLoadCluster(int $id): VectorModel
    {
        if ($this->objects !== null) {
            $model = $this->modelClass::find($id);
            if (! $model) {
                throw new \RuntimeException("Cluster $id not found");
            }
            $this->add($model);
        }

        return $this->objects[$id];
    }

    public function findBestByVector(array|string|VectorModel $modelVector): ?Model
    {
        if (! isset($this->objects)) {
            $this->fetchAllFromDb();
        }

        return $this->objects->findBestByVector($modelVector);
    }

    public function persist(): void
    {
        $this->objects
            ->filter(fn ($object) => $object->isDirty())
            ->each->save();
    }
}
