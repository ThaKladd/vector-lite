<?php

namespace ThaKladd\VectorLite\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ThaKladd\VectorLite\Embeddings for(array $inputs)
 *
 * @see \ThaKladd\VectorLite\Embeddings
 */
class Embeddings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ThaKladd\VectorLite\Embeddings::class;
    }
}
