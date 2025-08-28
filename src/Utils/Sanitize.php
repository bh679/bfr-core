<?php
declare(strict_types=1);

namespace BFR\Utils;

final class Sanitize
{
    /**
     * Convert a value to an integer if numeric, otherwise null.
     */
    public static function to_int_or_null(mixed $v): ?int
    {
        if (is_numeric($v)) {
            return (int)$v;
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return (int)trim($v);
        }
        return null;
    }
}
