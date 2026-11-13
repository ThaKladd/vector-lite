<?php

use Illuminate\Support\Facades\DB;
use ThaKladd\VectorLite\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Switches the default connection to MySQL (the CI service / DDEV db), to
 * exercise vector-lite's non-SQLite path. Call $this->setUpDatabase() after.
 */
function useMySqlConnection(): void
{
    config()->set('database.default', 'mysql');

    DB::purge();
    DB::reconnect();
}
