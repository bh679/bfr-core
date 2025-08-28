<?php
declare(strict_types=1);

namespace BFR\Utils;

final class Str
{
    public static function title_case(string $s): string
    {
        // Handle hyphens and apostrophes a bit nicer than ucwords by splitting and rejoining.
        $words = preg_split('/(\s|-)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! $words) {
            return ucwords($s);
        }
        $out = '';
        foreach ($words as $w) {
            if (preg_match('/\s|-/', $w)) {
                $out .= $w;
            } else {
                $out .= mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
            }
        }
        return $out;
    }
}
