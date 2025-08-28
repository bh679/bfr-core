<?php
declare(strict_types=1);

namespace BFR\Utils;

final class Arr
{
    /**
     * @param array<mixed> $arr
     * @return array<mixed>
     */
    public static function flatten(array $arr): array
    {
        $result = [];
        array_walk_recursive($arr, static function ($v) use (&$result) {
            $result[] = $v;
        });
        return $result;
    }
}
