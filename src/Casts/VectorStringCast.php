<?php

namespace ThaKladd\VectorLite\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class VectorStringCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes)
    {
        return array_map('floatval', explode(',', $value));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        return implode(',', $value);
    }
}
