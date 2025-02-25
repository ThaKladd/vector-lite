<?php

namespace ThaKladd\VectorLite\Support;

class VectorHelper
{
    /**
     * Normalize a vector.
     *
     * @param array $vector
     * @return array
     */
    public static function normalize(array $vector): array
    {
        // Compute the norm
        $norm = 0.0;
        foreach ($vector as $val) {
            $norm += $val * $val;
        }
        $norm = sqrt($norm);

        // Avoid division by zero; if norm is zero, just store zeros or handle error
        if ($norm == 0.0) {
            $norm = 1.0; // So we don't get NaNs; though a zero vector isn't very useful
        }

        // Normalize the vector
        foreach ($vector as &$val) {
            $val = $val / $norm;
        }

        return $vector;
    }

    public static function normalize_fast(array $vector): array
    {
        $n = count($vector);
        $sum = 0.0;

        // Compute the sum of squares.
        for ($i = 0; $i < $n; ++$i) {
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
        for ($i = 0; $i < $n; ++$i) {
            $vector[$i] *= $inv;
        }

        return $vector;
    }

    public static function normalizeToBinaryFast(array $vector): string
    {
        return pack("f*", ...self::normalize_fast($vector));
    }

    /**
     * Normalize a vector to binary.
     *
     * @param array $vector
     * @return string
     */
    public static function normalizeToBinary(array $vector): string
    {
        return pack("f*", ...self::normalize($vector));
    }
}
