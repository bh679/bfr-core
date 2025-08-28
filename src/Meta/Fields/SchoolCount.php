<?php
declare(strict_types=1);

namespace BFR\Meta\Fields;

use BFR\Meta\CalculatedMetaField;

final class SchoolCount extends CalculatedMetaField
{
    protected function compute(int $target_post_id): mixed
    {
        $posts = $this->findInputPostsForTarget($target_post_id);
        return is_array($posts) ? count($posts) : 0;
    }
}
