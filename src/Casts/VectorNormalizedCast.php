<?php

namespace ThaKladd\VectorLite\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use ThaKladd\VectorLite\VectorLite;

class VectorNormalizedCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes)
    {
        return array_map('floatval', explode(',', $value));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        // Normalize and then implode
        [$vector, $norm] = VectorLite::normalize($value);
        $model->setAttribute($key.'_norm', $norm);

        return $vector;
    }
}
