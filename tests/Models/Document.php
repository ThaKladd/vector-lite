<?php

namespace ThaKladd\VectorLite\Tests\Models;

use ThaKladd\VectorLite\Casts\AsVector;
use ThaKladd\VectorLite\Models\VectorModel;

class Document extends VectorModel
{
    protected $guarded = [];

    protected static ?string $vectorColumnName = 'embedding';

    protected $casts = [
        'embedding' => AsVector::class,
    ];
}
