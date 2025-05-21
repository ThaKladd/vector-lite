<?php

namespace ThaKladd\VectorLite\Tests;

use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;
use ThaKladd\VectorLite\VectorLiteServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        // 1) Boot the application and register your service provider (so $table->vectorLite() is available)
        parent::setUp();

        // 2) Ensure our sqlite file exists (file-based so Artisan sees the same DB)
        $path = database_path('testing.sqlite');
        if (! file_exists($path)) {
            touch($path);
        }

        // 3) Now do our manual schema + package commands
        $this->setUpDatabase();

        // 4) Factory name guessing (optional)
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
        // Use the sqlite file, not :memory:
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => database_path('testing.sqlite'),
            'prefix' => '',
            // you can enable foreign key constraints if you like
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('database.migrations', []);
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        // If you have a .env in the package root, load it here:
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
        $clusterTableName = 'vectors_clusters';

        Schema::dropIfExists($clusterTableName);
        Schema::dropIfExists($tableName);

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->vectorLite('vector');
            $table->timestamps();
        });

        $foreignColumn = Str::singular($clusterTableName);

        Schema::create($clusterTableName, function (Blueprint $table) use ($tableName) {
            $table->id();
            $table->vectorLite('vector');
            $table->integer("{$tableName}_count")->default(0);
            $table->timestamps();
        });

        Schema::table($clusterTableName, function (Blueprint $table) use ($clusterTableName, $foreignColumn) {
            $table->foreignId($foreignColumn.'_id')
                ->nullable()
                ->constrained($clusterTableName)
                ->after('id');

            $table->float($foreignColumn.'_match')
                ->nullable()
                ->after($foreignColumn.'_id');
        });
    }

    /*
    public function setUpDatabase(): void
    {
        Schema::dropIfExists('vectors');
        Schema::dropIfExists('vectors_clusters');
        Schema::create('vectors', function (Blueprint $table) {
            $table->id();
            $table->vectorLite('vector');
            $table->timestamps();
        });

        Artisan::call('vector-lite:cluster', ['table' => 'vectors']);
        Artisan::call('vector-lite:make:cluster', [
            'table' => 'vectors',
        ]);


        /*
        Schema::dropIfExists('vectors');
        Schema::create('vectors', function (Blueprint $table) {
            $table->id();
            // $table->integer('chunk')->index();
            $table->vectorLite('vector');

            // $table->vectorLite('vector_raw');
            // $table->vectorLite('vector_normalized');
            // $table->vectorLite('vector_packed');
            $table->timestamps();
        });
        Schema::create('vectors_clusters', function (Blueprint $table) {
            $table->id();
            $table->vectorLite('vector');
            $table->timestamps();
        })
    */
}
