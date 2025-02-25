<?php

namespace ThaKladd\VectorLite\Commands;

use Illuminate\Console\Command;

class VectorLiteCommand extends Command
{
    public $signature = 'vector-lite';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
