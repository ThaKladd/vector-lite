<?php

namespace ThaKladd\VectorLite\Models;

use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\Contracts\HasVectorType;
use ThaKladd\VectorLite\Traits\HasVector;

abstract class VectorModel extends Model implements HasVectorType
{
    use HasVector;
}
