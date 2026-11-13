<?php

namespace ThaKladd\VectorLite\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use ThaKladd\VectorLite\Enums\EmbeddingInputType;
use ThaKladd\VectorLite\Exceptions\EmbeddingException;
use ThaKladd\VectorLite\Models\VectorModel;
use ThaKladd\VectorLite\Services\EmbeddingService;
use ThaKladd\VectorLite\VectorLite;
use Throwable;

/**
 * Re-embeds a model's rows with the currently configured embedding endpoint,
 * one batched request per chunk, then rebuilds the model's clusters - their
 * vectors were computed in the old model's space and can't be compared with
 * the new ones.
 *
 * Rows are written with model events and timestamps off: the embedding is
 * already computed here, and clusters are rebuilt once at the end instead
 * of row by row.
 */
class VectorLiteReembedCommand extends Command
{
    public $signature = 'vector-lite:reembed
        {model : The VectorModel class, e.g. "App\\Models\\Link" or just "Link"}
        {--chunk=100 : Rows per batch - one embedding request per batch}
        {--only-stale : Only re-embed rows whose embed_hash does not match the current text and embedding settings}
        {--keep-clusters : Keep the existing clusters and only re-assign the re-embedded rows. For catching up rows that failed to embed earlier - not after switching model, dimensions or prefix}';

    public $description = 'Re-embed a model\'s rows with the configured embedding model, then rebuild its clusters';

    public function handle(): int
    {
        $modelClass = $this->resolveModelClass((string) $this->argument('model'));
        if ($modelClass === null) {
            $this->error("VectorLite: [{$this->argument('model')}] is not a VectorModel class.");

            return self::FAILURE;
        }

        /** @var VectorModel $prototype */
        $prototype = new $modelClass;
        if ($prototype->isCluster() || empty($prototype->getEmbedFields())) {
            $this->error("VectorLite: [{$modelClass}] has no \$embedFields to build embedding text from.");

            return self::FAILURE;
        }

        $service = app(EmbeddingService::class);
        $config = $service->config();
        if (! $config->isConfigured()) {
            $this->error(EmbeddingException::notConfigured($config)->getMessage());

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $onlyStale = (bool) $this->option('only-stale');
        $keepClusters = (bool) $this->option('keep-clusters');
        $hasClusters = Schema::hasTable($prototype->getClusterTableName());
        $hashColumn = $modelClass::embedHashColumn();

        $this->info("Embedding {$modelClass} with [{$config->model}], {$config->dimensions} dimensions, via {$config->endpoint()}");

        $stats = ['re-embedded' => 0, 'up to date' => 0, 'no embedding text' => 0];
        $bar = $this->output->createProgressBar($modelClass::query()->count());
        $bar->start();

        try {
            $modelClass::query()->chunkById($chunkSize, function ($models) use (
                $service, $config, $onlyStale, $keepClusters, $hasClusters, $hashColumn, $bar, &$stats
            ) {
                $pending = [];
                foreach ($models as $model) {
                    /** @var VectorModel $model */
                    $text = $model->getEmbeddingText();
                    if ($text === '') {
                        $stats['no embedding text']++;

                        continue;
                    }

                    $hash = $config->embedHash($text);
                    if ($onlyStale && $model->{$hashColumn} === $hash) {
                        $stats['up to date']++;

                        continue;
                    }

                    $pending[] = ['model' => $model, 'text' => $text, 'hash' => $hash];
                }

                if ($pending !== []) {
                    $rows = $service->createEmbeddings(
                        array_column($pending, 'text'),
                        $config->dimensions,
                        EmbeddingInputType::Document
                    );

                    foreach ($pending as $position => $item) {
                        /** @var VectorModel $model */
                        $model = $item['model'];
                        $model->fillEmbedding($rows[$position]['embedding'], $item['hash']);
                        $model->timestamps = false;
                        $model->saveQuietly();

                        if ($keepClusters && $hasClusters) {
                            (new VectorLite($model))->processUpdated($model);
                        }

                        $stats['re-embedded']++;
                    }
                }

                $bar->advance(count($models));
                VectorLite::flushMemoryCache();
            });
        } catch (EmbeddingException $exception) {
            $bar->clear();
            $this->newLine();
            $this->error($exception->getMessage());
            $this->line("Re-embedded {$stats['re-embedded']} rows before the failure; they are saved. Run again with --only-stale to resume.");

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);
        $this->table(['Rows', 'Count'], collect($stats)->map(fn ($count, $label) => [$label, $count])->values()->all());

        if ($hasClusters && ! $keepClusters && $stats['re-embedded'] > 0) {
            $this->rebuildClusters($prototype);
        }

        return self::SUCCESS;
    }

    private function rebuildClusters(VectorModel $prototype): void
    {
        $modelClass = get_class($prototype);
        $this->info("Rebuilding the clusters in [{$prototype->getClusterTableName()}]...");

        $bar = $this->output->createProgressBar($modelClass::query()->whereNotNull($modelClass::vectorColumn())->count());
        $bar->start();
        $clustered = (new VectorLite($prototype))->rebuildClusters(fn () => $bar->advance());
        $bar->finish();
        $this->newLine(2);

        $clusters = $prototype->getClusterModel()->newQuery()->count();
        $this->info("Clustered {$clustered} rows into {$clusters} clusters.");
    }

    /**
     * @return class-string<VectorModel>|null
     */
    private function resolveModelClass(string $name): ?string
    {
        $name = ltrim($name, '\\');
        $candidates = [$name];

        try {
            $namespace = $this->laravel->getNamespace();
            $candidates[] = $namespace.'Models\\'.$name;
            $candidates[] = $namespace.$name;
        } catch (Throwable) {
            // No detectable application namespace - only a fully-qualified name works.
        }

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, VectorModel::class)) {
                return $candidate;
            }
        }

        return null;
    }
}
