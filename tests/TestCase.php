<?php

namespace ThaKladd\VectorLite\Tests;

use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;
use ThaKladd\VectorLite\VectorLiteServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();
        Factory::guessFactoryNamesUsing(
            fn (string $model) => 'ThaKladd\\VectorLite\\Database\\Factories\\'.class_basename($model).'Factory'
        );
    }

    /**
     * Register the package's service provider.
     */
    protected function getPackageProviders($app)
    {
        return [
            VectorLiteServiceProvider::class,
        ];
    }

    /**
     * Configure the sqlite connection to point at testing.sqlite.
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => database_path('testing.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('database.migrations', []);
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        // Vector lite settings
        $app['config']->set('vector-lite.default_dimensions', 36);
        $app['config']->set('vector-lite.clustering_dimensions', 6);
        $app['config']->set('vector-lite.clusters_size', 10);

        if (file_exists(__DIR__.'/../.env')) {
            Dotenv::createImmutable(__DIR__.'/../')->load();
        }
    }

    /**
     * Drop & recreate "vectors", then run the two VectorLite commands.
     */
    protected function setUpDatabase(): void
    {
        $tableName = 'vectors';
        $clusterTableName = 'vector_clusters';

        Schema::dropIfExists($clusterTableName);
        Schema::dropIfExists($tableName);

        Schema::create('others', function (Blueprint $table) {
            $table->id();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->foreignId('other_id')->nullable();
            $table->vectorLite('vector');
            $table->timestamps();
        });

        $foreignColumn = Str::singular($clusterTableName);

        Schema::create($clusterTableName, function (Blueprint $table) use ($tableName) {
            $tableNameSingular = Str::singular($tableName);
            $table->id();
            $table->vectorLiteCluster('vector');
            $table->integer("{$tableNameSingular}_count")->default(0);
            $table->timestamps();
        });

        Schema::table($tableName, function (Blueprint $table) use ($clusterTableName, $foreignColumn) {
            $table->foreignId($foreignColumn.'_id')
                ->nullable()
                ->constrained($clusterTableName)
                ->after('id');

            $table->float($foreignColumn.'_match')
                ->nullable()
                ->after($foreignColumn.'_id');
        });
    }
}
