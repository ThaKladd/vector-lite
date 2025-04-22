<?php

namespace ThaKladd\VectorLite\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\Traits\HasVector;

class Vector extends Model
{
    use HasVector;

    protected $guarded = [];
}
