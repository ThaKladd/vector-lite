<?php

namespace ThaKladd\VectorLite;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

            DB::connection()->getPdo()->sqliteCreateFunction('COSIM_CACHE', function ($rowId, $binaryRowVector, $queryId, $binaryQueryVector) {
                // Cache the unpacked query vector in a static variable - so it does not need to be unpacked for each row
                static $cache = [];
                static $dot = [];
                static $amount = 0;
                static $driver = null;
                static $cacheTime = false;

                // If cache is empty, and driver is set, then load the cache from the driver
                if (! $dot && $driver = config('vector-lite.cache_driver')) {
                    $cacheTime = $cacheTime ?: config('vector-lite.cache_time');
                    $dot = Cache::get($driver)->get('vector-lite-cache', []);
                }

                // Check if the dot product is already cached
                if (isset($dot[$rowId][$queryId])) {
                    return $dot[$rowId][$queryId];
                }

                // Cache the unpacked vectors for the current session
                if (! isset($cache[$queryId])) {
                    $cache[$queryId] = unpack('f*', $binaryQueryVector);
                }
                if (! isset($cache[$rowId])) {
                    $cache[$rowId] = unpack('f*', $binaryRowVector);
                }

                if ($amount === 0) {
                    $amount = count($cache[$queryId]);
                }

                $dotProduct = 0.0;
                for ($i = 1; $i <= $amount; $i++) {
                    $dotProduct += $cache[$queryId][$i] * $cache[$rowId][$i];
                }
                $dotProduct = $dot[$rowId][$queryId] = $dotProduct;

                if (isset($driver) && $driver) {
                    Cache::store($driver)->put('vector-lite-cache', $dot, $cacheTime);
                }

                return $dotProduct;
            });

            DB::connection()->getPdo()->sqliteCreateFunction('COSIM', function ($binaryRowVector, $binaryQueryVector) {
                $queryVector = unpack('f*', $binaryQueryVector);
                $rowVector = unpack('f*', $binaryRowVector);
                $amount = count($queryVector);
                $dotProduct = 0.0;
                for ($i = 1; $i <= $amount; $i++) {
                    $dotProduct += $queryVector[$i] * $rowVector[$i];
                }

                return $dotProduct;
            });

            Blueprint::macro('vectorLite', function (string $column, $length = null, $fixed = false) {
                /** @var Blueprint $this */
                $this->binary($column, $length, $fixed)->nullable();
                $this->string($column.'_hash')->nullable();
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
