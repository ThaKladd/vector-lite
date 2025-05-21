<?php

namespace ThaKladd\VectorLite\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

class VectorLiteMakeCommand extends Command
{
    public $signature = 'vector-lite:make';

    public $description = 'Create a vector and vector hash column for a model.';

    public function __construct(public Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Get a list of all models in the app directory
        $models = $this->laravel['files']->allFiles(app_path('Models'));
        $modelList = [];
        foreach ($models as $model) {
            $modelName = $model->getFilenameWithoutExtension();
            $modelList[] = $modelName;
        }
        $this->info('This command will create a vector column and a vector hash column for a model.');
        $newValue = select('Select model to add vector columns to:', $modelList);
        $model = 'App\\Models\\'.Str::studly($newValue);

        $this->createMigration($model);
        if (select('Run the migrations?', ['yes', 'no']) === 'yes') {
            $this->call('migrate');
            $this->info('Migrated.');
        } else {
            $this->info("Run 'php artisan migrate' to create the columns.");
        }

        if (select('Make clustering for this vectors?', ['yes', 'no']) === 'yes') {
            $table = (new $model)->getTable();
            $this->call('vector-lite:cluster', ['table' => $table]);
        }

        return self::SUCCESS;
    }

    protected function createMigration(string $model): void
    {
        // Determine migration filename with current timestamp.
        $timestamp = date('Y_m_d_His');
        $table = (new $model)->getTable();
        $migrationFile = database_path("migrations/{$timestamp}_alter_{$table}_add_vector_columns.php");

        // A simple stub for the migration.
        $stub = <<<'STUB'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('{{$model}}', function (Blueprint $table) {
            $table->vectorLite('vector');
        });
    }

    public function down()
    {
        Schema::table('{{$model}}', function (Blueprint $table) {
            $table->dropColumn('vector');
            $table->dropColumn('vector_hash');
        });
    }
};
STUB;
        $stub = str_replace('{{$model}}', $model, $stub);
        $this->files->put($migrationFile, $stub);
        $this->info("Created migration: {$migrationFile}");
    }
}
