<?php

namespace ThaKladd\VectorLite\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class VectorLiteCommand extends Command
{
    public $signature = 'vector-lite:cluster --table=';

    public $description = 'Create a cluster for a vector column in a table';

    public function __construct(public Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $forTableName = $this->argument('table');
        $modelClass = 'App\\Models\\' . Str::studly((Str::singular($forTableName)));
        $clusterModelClass = $modelClass . 'Cluster';
        $clusterModelTable = Str::snake($forTableName) . '_clusters';
        $this->createMigration($forTableName, $clusterModelTable);
        $this->createModel($clusterModelClass);
        $this->call('migrate');
        $this->info("Migrated.");

        return self::SUCCESS;
    }

    protected function createMigration(string $modelTable, string $newTable)
    {
        // Determine migration filename with current timestamp.
        $timestamp = date('Y_m_d_His');
        $migrationFile = database_path("migrations/{$timestamp}_create_{$newTable}_table.php");
        $foreignColumn = Str::singular($newTable).'_id';

        // A simple stub for the migration.
        $stub = <<<'STUB'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('$newTable', function (Blueprint \$table) {
            \$table->id();
            \$table->binary('vector');
            \$table->timestamps();
        });

        Schema::table('$modelTable', function (Blueprint \$table) {
            \$table->foreignId('$foreignColumn')->nullable()->constrained();
        });
    }

    public function down()
    {
        Schema::dropIfExists('$newTable');
        Schema::table('$modelTable', function (Blueprint \$table) {
            \$table->dropForeign(['$foreignColumn']);
            \$table->dropColumn('$foreignColumn');
        });
    }
};
STUB;

        $this->files->put($migrationFile, $stub);
        $this->info("Created migration: {$migrationFile}");
    }

    protected function createModel(string $className)
    {
        //Call an artisan command to create a model
        $this->call('make:model', ['name' => $className]);
        $this->info("Created model: {$className}");
    }
}
