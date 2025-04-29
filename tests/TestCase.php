<?php

namespace ThaKladd\VectorLite\Tests;

use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use ThaKladd\VectorLite\VectorLiteServiceProvider;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();

        \Illuminate\Support\Facades\Schema::setFacadeApplication($this->app);

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'ThaKladd\\VectorLite\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            VectorLiteServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        if (! is_file(database_path('testing.sqlite'))) {
            touch(database_path('testing.sqlite'));
        }
        // config()->set('database.default', 'testing');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => database_path('testing.sqlite'), // ':memory:',
            'prefix' => '',
        ]);

        // $app['config']->set('database.migrations', []);

        // Optionally load your .env variables here if needed:
        if (file_exists(__DIR__.'/../.env')) {
            Dotenv::createImmutable(__DIR__.'/../')->load();
        }

        $app->singleton('db.schema', function ($app) {
            return $app['db']->connection()->getSchemaBuilder();
        });

        // dd(DB::connection()->getPdo()->query('SELECT name FROM sqlite_master WHERE type = "table"')->fetchAll());
        /*
         foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
         }
         */
    }

    public function setUpDatabase(): void
    {
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
    }
}
