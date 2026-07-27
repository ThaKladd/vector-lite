<?php

namespace ThaKladd\VectorLite;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use ThaKladd\VectorLite\Commands\MakeVectorLiteClusterCommand;
use ThaKladd\VectorLite\Commands\VectorLiteClusterCommand;
use ThaKladd\VectorLite\Commands\VectorLiteMakeCommand;

class VectorLiteServiceProvider extends PackageServiceProvider
{
    public function boot()
    {
        // Implementing the idea from here: https://www.splitbrain.org/blog/2023-08/15-using_sqlite_as_vector_store_in_php
        // TODO: If not SQLite, the columns can still be there - but not the methods, and the QueryBuilder. The Collection can provide the job as well.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('COSIM_CACHE', [VectorLite::class, 'cosineSimilarityBinaryCache']);
            DB::connection()->getPdo()->sqliteCreateFunction('COSIM', [VectorLite::class, 'cosineSimilarityBinary']);
        }

        /**
         * @method Blueprint vectorLiteColumns(string $column, ?int $dimensions = null, bool $fixed = false)
         */
        Blueprint::macro('vectorLiteColumns', function (string $column, ?int $dimensions = null, bool $fixed = false) {
            /** @var Blueprint $this */
            // Each dimension is packed as a 4-byte float (see VectorLite::normalToBinary).
            $length = $dimensions ? $dimensions * 4 : null;
            $this->binary($column, $length, $fixed)->nullable();
            $this->float($column.'_norm')->nullable();
            $this->string($column.'_hash')->nullable()->index();
            if (config('vector-lite.use_clustering_dimensions', false)) {
                $this->binary($column.'_small', $length, $fixed)->nullable();
                $this->float($column.'_small_norm')->nullable();
                $this->string($column.'_small_hash')->nullable()->index();
            }
        });

        /**
         * Mirrors the signature of Laravel's own Schema::vector() column type
         * (`$table->vector('embedding', dimensions: 1536)`), since core's vector()
         * throws on SQLite and can't be overridden by a macro of the same name.
         *
         * @method Blueprint vectorLite(string $column, ?int $dimensions = null, bool $fixed = false)
         */
        Blueprint::macro('vectorLite', function (string $column, ?int $dimensions = null, bool $fixed = false) {
            /** @var Blueprint $this */
            $this->string('embed_hash')->nullable();
            $this->vectorLiteColumns($column, $dimensions, $fixed);
        });

        /**
         * @method Blueprint vectorLiteCluster(string $column, ?int $dimensions = null, bool $fixed = false)
         */
        Blueprint::macro('vectorLiteCluster', function (string $column, ?int $dimensions = null, bool $fixed = false) {
            /** @var Blueprint $this */
            $this->vectorLiteColumns($column, $dimensions, $fixed);
        });

        /**
         * @method Blueprint dropVectorLiteColumns(string $column)
         */
        Blueprint::macro('dropVectorLiteColumns', function (string $column) {
            /** @var Blueprint $this */
            // The "_hash" columns are created with ->index() (see vectorLiteColumns()
            // above) - on SQLite 3.35+, dropping an indexed column via a plain
            // ALTER TABLE ... DROP COLUMN leaves a dangling index definition and
            // throws, so the index must be dropped first.
            $this->dropIndex([$column.'_hash']);
            $this->dropColumn($column);
            $this->dropColumn($column.'_norm');
            $this->dropColumn($column.'_hash');

            if (config('vector-lite.use_clustering_dimensions', false)) {
                $this->dropIndex([$column.'_small_hash']);
                $this->dropColumn($column.'_small');
                $this->dropColumn($column.'_small_norm');
                $this->dropColumn($column.'_small_hash');
            }
        });

        /**
         * @method Blueprint dropVectorLite(string $column)
         */
        Blueprint::macro('dropVectorLite', function (string $column) {
            /** @var Blueprint $this */
            $this->dropColumn('embed_hash');
            $this->dropVectorLiteColumns($column);
        });

        $this->publishes([
            __DIR__.'/../config/vector-lite.php' => config_path('vector-lite.php'),
        ], 'vector-lite-config');

        $this->registerStrToEmbeddingsMacro();
        $this->registerCollectionRerankMacro();
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vector-lite.php', 'vector-lite');
        $this->commands([
            VectorLiteMakeCommand::class,
            VectorLiteClusterCommand::class,
            MakeVectorLiteClusterCommand::class,
        ]);

        // Embeddings::for() is a static factory; this binding only exists so the
        // Facades\Embeddings root resolves to *some* instance to call it through.
        $this->app->bind(Embeddings::class, fn () => Embeddings::for([]));
    }

    /**
     * Mirrors Laravel AI SDK's Str::of($text)->toEmbeddings() / Str::toEmbeddings($text),
     * backed by vector-lite's own Embeddings class instead of laravel/ai.
     */
    private function registerStrToEmbeddingsMacro(): void
    {
        $generate = function (string $text, mixed $provider, ?string $model, ?int $timeout, bool|int $cache): array {
            $embeddings = Embeddings::for([$text]);

            if ($cache !== false) {
                $embeddings->cache($cache);
            }

            if ($timeout !== null) {
                $embeddings->timeout($timeout);
            }

            return $embeddings->generate($provider, $model)->embeddings[0] ?? [];
        };

        Str::macro('toEmbeddings', function (string $text, mixed $provider = null, ?string $model = null, ?int $timeout = null, bool|int $cache = false) use ($generate) {
            return $generate($text, $provider, $model, $timeout, $cache);
        });

        Stringable::macro('toEmbeddings', function (mixed $provider = null, ?string $model = null, ?int $timeout = null, bool|int $cache = false) use ($generate) {
            /** @var Stringable $this */
            return $generate((string) $this, $provider, $model, $timeout, $cache);
        });
    }

    /**
     * Mirrors Laravel AI SDK's collection ->rerank() macro (by field name,
     * array of field names, or closure), backed by vector-lite's own
     * Reranking class. Works on any Collection, e.g. Post::all()->rerank(...).
     */
    private function registerCollectionRerankMacro(): void
    {
        Collection::macro('rerank', function (string|array|\Closure $by, string $query, ?int $limit = null, mixed $provider = null) {
            /** @var Collection $this */
            $items = $this->values();

            $extract = match (true) {
                $by instanceof \Closure => $by,
                is_array($by) => fn ($item) => implode(' ', array_map(fn ($field) => (string) data_get($item, $field), $by)),
                default => fn ($item) => (string) data_get($item, $by),
            };

            $documents = $items->map($extract)->all();

            return Reranking::of($documents)->limit($limit)->rerank($query, $provider)
                ->map(fn ($result) => $items[$result->index])
                ->values();
        });
    }

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('vector-lite')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_vector_lite_table')
            ->hasCommand(VectorLiteClusterCommand::class);
    }
}
