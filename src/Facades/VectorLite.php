<?php

namespace ThaKladd\VectorLite\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \ThaKladd\VectorLite\VectorLite
 */
class VectorLite extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ThaKladd\VectorLite\VectorLite::class;
    }
}
