<?php
declare(strict_types=1);

namespace BFR\Meta\Fields;

use BFR\Meta\CalculatedMetaField;

final class MaxDepth extends CalculatedMetaField
{
    protected function compute(int $target_post_id): mixed
    {
        $posts = $this->findInputPostsForTarget($target_post_id);
        if (empty($posts) || empty($this->input_meta_keys)) {
            return null;
        }
        $max = null;
        foreach ($posts as $p) {
            foreach ($this->input_meta_keys as $key) {
                $raw = $this->get_input_meta_value($p->ID, $key);
                if (is_numeric($raw)) {
                    $val = 0 + $raw;
                } elseif (is_string($raw) && is_numeric(trim($raw))) {
                    $val = 0 + trim($raw);
                } else {
                    $val = null;
                }
                if ($val !== null) {
                    $max = $max === null ? $val : max($max, $val);
                }
            }
        }
        return $max;
    }
}
