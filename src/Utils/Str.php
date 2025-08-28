<?php
declare(strict_types=1);

namespace BFR\Utils;

final class Str
{
    public static function title_case(string $s): string
    {
        // mbstring-safe helpers
        $substr  = \function_exists('mb_substr') ? 'mb_substr' : 'substr';
        $strlen  = \function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
        $toupper = \function_exists('mb_strtoupper') ? 'mb_strtoupper' : 'strtoupper';

        $parts = preg_split('/(\s|-)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! $parts) {
            // Fallback to ucwords if regex fails
            return ucwords($s);
        }
        $out = '';
        foreach ($parts as $w) {
            if (preg_match('/\s|-/', $w)) {
                $out .= $w;
            } else {
                $first = $substr($w, 0, 1);
                $rest  = $substr($w, 1, $strlen($w));
                $out  .= $toupper($first) . $rest;
            }
        }
        return $out;
    }
}
