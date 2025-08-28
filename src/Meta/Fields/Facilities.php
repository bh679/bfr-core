<?php
declare(strict_types=1);

namespace BFR\Meta\Fields;

use BFR\Meta\CalculatedMetaField;

final class Facilities extends CalculatedMetaField
{
    protected function compute(int $target_post_id): mixed
    {
        $posts = $this->findInputPostsForTarget($target_post_id);
        $all = [];
        foreach ($posts as $p) {
            foreach ($this->input_meta_keys as $key) {
                $raw = $this->get_input_meta_value($p->ID, $key);
                $all = array_merge($all, $this->normalize_list($raw));
            }
        }
        $all = array_values(array_unique($all, SORT_STRING));
        sort($all, SORT_NATURAL | SORT_FLAG_CASE);
        return wp_json_encode($all);
    }
}
