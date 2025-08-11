<?php

namespace Illuminate\Database\Schema;

class Blueprint
{
    // Externally called macros in your migrations
    public function vectorLite(string $column, $length = null, $fixed = false): void {}

    public function vectorLiteCluster(string $column, $length = null, $fixed = false): void {}

    public function dropVectorLite(string $column): void {}

    public function dropVectorLiteColumns(string $column): void {}

    // Internal helper macro you call from other macros
    public function vectorLiteColumns(string $column, $length = null, $fixed = false): void {}
}
