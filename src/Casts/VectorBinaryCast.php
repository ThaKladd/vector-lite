<?php

namespace ThaKladd\VectorLite\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\Support\VectorHelper;

class VectorBinaryCast implements CastsAttributes
{

    public function get(Model $model, string $key, mixed $value, array $attributes)
    {
        return unpack("f*", $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        return VectorHelper::normalizeToBinary($value);
    }

}
