<?php

namespace ThaKladd\VectorLite\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

class VectorLiteClusterCommand extends Command
{
    public $signature = 'vector-lite:cluster {table : The name of the base table}';

    public $description = 'Create a cluster for a vector column in a table';

    public function __construct(public Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $timestamp = date('Y_m_d_His');
        $forTableName = $this->argument('table');
        $modelClass = 'App\\Models\\'.Str::studly($forTableName);
        $clusterModelClass = $modelClass.'Cluster';
        $clusterModelTable = Str::snake($forTableName).'_clusters';

        $this->info('This will create a new table and model for the cluster.');
        $this->createMigration($forTableName, $clusterModelTable, $timestamp);
        $this->createModel($clusterModelClass);

        // Delete the migration file if testing environment
        if (app()->environment('testing')) {
            $this->call('migrate');
            $this->info('Migrated.');
            $this->files->delete(database_path("migrations/{$timestamp}_create_{$clusterModelTable}_table.php"));
        } else {
            if (select('Run the migrations?', ['yes', 'no']) === 'yes') {
                $this->call('migrate');
                $this->info('Cluster table migrated.');
            } else {
                $this->info("Run 'php artisan migrate' to create the cluster table.");
            }
        }

        return self::SUCCESS;
    }

    protected function createMigration(string $modelTable, string $newTable, $timestamp): void
    {
        // Determine migration filename with current timestamp.
        $migrationFile = database_path("migrations/{$timestamp}_create_{$newTable}_table.php");
        $foreignColumn = Str::singular($newTable);

        // A simple stub for the migration.
        $stub = <<<'STUB'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('{{newTable}}', function (Blueprint $table) {
            $table->id();
            $table->vectorLite('vector');
            $table->integer('{{modelTable}}_count')->default(0);
            $table->timestamps();
        });

        Schema::table('{{modelTable}}', function (Blueprint $table) {
            $table->foreignId('{{foreignColumn}}_id')->nullable()->constrained();
            $table->float('{{foreignColumn}}_match')->nullable()->after('{{foreignColumn}}_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('{{newTable}}');
        Schema::table('{{modelTable}}', function (Blueprint $table) {
            $table->dropForeign(['{{foreignColumn}}_id']);
            $table->dropColumn('{{foreignColumn}}_id');
            $table->dropColumn('{{foreignColumn}}_match');
        });
    }
};
STUB;
        $stub = str_replace('{{newTable}}', $newTable, $stub);
        $stub = str_replace('{{modelTable}}', $modelTable, $stub);
        $stub = str_replace('{{foreignColumn}}', $foreignColumn, $stub);
        $this->files->put($migrationFile, $stub);
        $this->info("Created migration: {$migrationFile}");
    }

    protected function createModel(string $className): void
    {
        // Call an artisan command to create a model
        $this->call('vector-lite:make:cluster', ['name' => $className]);
        $this->info("Created model: {$className}");
    }
}
