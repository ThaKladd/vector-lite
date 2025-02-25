<?php

namespace ThaKladd\VectorLite;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use ThaKladd\VectorLite\Commands\VectorLiteCommand;

class VectorLiteServiceProvider extends PackageServiceProvider
{
    public function boot()
    {
        // Implementing the idea from here: https://www.splitbrain.org/blog/2023-08/15-using_sqlite_as_vector_store_in_php
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('COSIM_RAW', function ($arg1, $arg2) {
                // Convert comma-separated strings to arrays of floats.
                $vector1 = array_map('floatval', explode(',', $arg1));
                $vector2 = array_map('floatval', explode(',', $arg2));

                // Ensure both vectors have the same number of elements.
                if (count($vector1) !== count($vector2)) {
                    return null; // Alternatively, you could throw an exception.
                }

                // Compute the dot product and the magnitudes of both vectors.
                $dotProduct = 0;
                $magnitude1 = 0;
                $magnitude2 = 0;
                foreach ($vector1 as $index => $value) {
                    $dotProduct += $value * $vector2[$index];
                    $magnitude1 += $value * $value;
                    $magnitude2 += $vector2[$index] * $vector2[$index];
                }

                // Avoid division by zero.
                if ($magnitude1 == 0 || $magnitude2 == 0) {
                    return null;
                }

                // Calculate and return the cosine similarity.
                return $dotProduct / (sqrt($magnitude1) * sqrt($magnitude2));
            });

            DB::connection()->getPdo()->sqliteCreateFunction('COSIM_NORM', function ($arg1, $arg2) {
                // Convert comma-separated strings to arrays of floats.
                $vector1 = array_map('floatval', explode(',', $arg1));
                $vector2 = array_map('floatval', explode(',', $arg2));

                // Ensure both vectors have the same number of elements.
                if (count($vector1) !== count($vector2)) {
                    return null; // Alternatively, handle error as needed.
                }

                // Calculate the dot product of the two normalized vectors.
                $dotProduct = 0;
                foreach ($vector1 as $index => $value) {
                    $dotProduct += $value * $vector2[$index];
                }

                return $dotProduct;
            });

            DB::connection()->getPdo()->sqliteCreateFunction('COSIM_PACK', function ($arg1, $arg2) {
                // Unpack binary data into arrays of floats.
                $vector1 = unpack('f*', $arg1);
                $vector2 = unpack('f*', $arg2);

                // Calculate the dot product assuming vectors are pre-normalized.
                $dotProduct = 0.0;
                foreach ($vector1 as $index => $value) {
                    $dotProduct += $value * $vector2[$index];
                }
                return $dotProduct;
            });

            DB::connection()->getPdo()->sqliteCreateFunction('COSIM_CACHE', function ($binaryRowVector, $binaryQueryVector) {
                //Cache the unpacked query vector in a static variable - so it does not need to be unpacked for each row
                static $queryVector = null;
                if ($queryVector === null) {
                    $queryVector = unpack('f*', $binaryQueryVector);
                }

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
                return $this->binary($column, $length, $fixed);
            });
        }
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
            ->hasCommand(VectorLiteCommand::class);
    }
}
