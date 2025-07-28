<?php

namespace ThaKladd\VectorLite\Support;

class SqlValidator
{
    /**
     * Validate the operator to avoid SQL injection.
     */
    public static function validateOperator(string $operator): void
    {
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!='];
        if (! in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [$operator] provided.");
        }
    }

    /**
     * Validate the direction to avoid SQL injection or mistakes.
     */
    public static function validateDirection(string $direction): void
    {
        if (! in_array(strtoupper($direction), ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid direction [$direction] provided.");
        }
    }
}
