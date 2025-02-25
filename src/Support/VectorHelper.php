<?php

namespace ThaKladd\VectorLite\Support;

class VectorHelper
{
    /**
     * Normalize a vector.
     */
    public static function normalize(array $vector): array
    {
        $n = count($vector);
        $sum = 0.0;

        // Compute the sum of squares.
        for ($i = 0; $i < $n; $i++) {
            $val = $vector[$i];
            $sum += $val * $val;
        }

        // Compute the norm.
        $norm = sqrt($sum);

        // Avoid division by zero.
        if ($norm == 0.0) {
            $norm = 1.0;
        }

        // Pre-calculate reciprocal to avoid repeated division.
        $inv = 1.0 / $norm;

        // Normalize each element.
        for ($i = 0; $i < $n; $i++) {
            $vector[$i] *= $inv;
        }

        return $vector;
    }

    /**
     * Normalize a vector to binary.
     */
    public static function normalizeToBinary(array $vector): string
    {
        return pack('f*', ...self::normalize($vector));
    }
}
