<?php

namespace ThaKladd\VectorLite;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use ThaKladd\VectorLite\Commands\MakeVectorLiteClusterCommand;
use ThaKladd\VectorLite\Commands\VectorLiteClusterCommand;

class VectorLiteServiceProvider extends PackageServiceProvider
{
    public function boot()
    {
        // Implementing the idea from here: https://www.splitbrain.org/blog/2023-08/15-using_sqlite_as_vector_store_in_php
        if (DB::connection()->getDriverName() === 'sqlite') {

            DB::connection()->getPdo()->sqliteCreateFunction('COSIM_CACHE', [VectorLite::class, 'cosineSimilarityBinaryCache']);

            DB::connection()->getPdo()->sqliteCreateFunction('COSIM', [VectorLite::class, 'cosineSimilarityBinary']);

            /**
             * @method Blueprint vectorLiteColumns(string $column, $length = null, $fixed = false)
             */
            Blueprint::macro('vectorLiteColumns', function (string $column, $length = null, $fixed = false) {
                /** @var Blueprint $this */
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
             * @method Blueprint vectorLite(string $column, $length = null, $fixed = false)
             */
            Blueprint::macro('vectorLite', function (string $column, $length = null, $fixed = false) {
                /** @var Blueprint $this */
                $this->float('embed_hash')->nullable();
                $this->vectorLiteColumns($column, $length, $fixed);
            });

            /**
             * @method Blueprint vectorLiteCluster(string $column, $length = null, $fixed = false)
             */
            Blueprint::macro('vectorLiteCluster', function (string $column, $length = null, $fixed = false) {
                /** @var Blueprint $this */
                $this->vectorLiteColumns($column, $length, $fixed);
            });

            /**
             * @method Blueprint dropVectorLiteColumns(string $column)
             */
            Blueprint::macro('dropVectorLiteColumns', function (string $column) {
                /** @var Blueprint $this */
                $this->dropColumn($column);
                $this->dropColumn($column.'_norm');
                $this->dropColumn($column.'_hash');

                if (config('vector-lite.use_clustering_dimensions', false)) {
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
        }

        $this->publishes([
            __DIR__.'/../config/vector-lite.php' => config_path('vector-lite.php'),
        ], 'vector-lite-config');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vector-lite.php', 'vector-lite');
        $this->commands([
            VectorLiteClusterCommand::class,
            MakeVectorLiteClusterCommand::class,
        ]);
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
