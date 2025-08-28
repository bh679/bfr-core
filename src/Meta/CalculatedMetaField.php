<?php
declare(strict_types=1);

namespace BFR\Meta;

use BFR\Utils\Arr;
use BFR\Utils\Str;

/**
 * Base class for a calculated meta field.
 *
 * NOTE: The naming uses "cpt_id" as provided by requirements, but in practice
 * these represent post type slugs (e.g., 'destination', 'course'). // TODO: confirm
 */
abstract class CalculatedMetaField
{
    protected string $name;
    protected string $slug;

    /** Target post type slug (named "cpt_id" per requirements). */
    protected string $target_cpt_id;
    protected string $target_meta_key;

    /** @var string[] */
    protected array $input_cpt_id = [];

    /** @var string[] */
    protected array $input_meta_keys = [];

    /**
     * Meta key on INPUT posts that references the TARGET post ID.
     * Example: a course has meta '_bfr_destination_id' that stores the destination post ID.
     * If null, subclasses must override findInputPostsForTarget().
     */
    protected ?string $relation_meta_key = null;

    public function __construct(array $config)
    {
        $this->name            = (string)($config['name'] ?? static::class);
        $this->slug            = (string)($config['slug'] ?? sanitize_key($this->name));
        $this->target_cpt_id   = (string)($config['target_cpt_id'] ?? '');
        $this->target_meta_key = (string)($config['target_meta_key'] ?? '');
        $this->input_cpt_id    = array_values(array_filter((array)($config['input_cpt_id'] ?? [])));
        $this->input_meta_keys = array_values(array_filter((array)($config['input_meta_keys'] ?? [])));
        $this->relation_meta_key = isset($config['relation_meta_key']) && is_string($config['relation_meta_key'])
            ? $config['relation_meta_key']
            : null;
    }

    public function get_slug(): string { return $this->slug; }
    public function get_name(): string { return $this->name; }
    public function get_target_meta_key(): string { return $this->target_meta_key; }
    public function get_target_post_type(): string { return $this->target_cpt_id; }

    /**
     * Run the calculator for a single target post and save the meta.
     *
     * @param int  $target_post_id
     * @param bool $dry_run If true, return computed value but do NOT save it.
     * @return mixed
     */
    public function run(int $target_post_id, bool $dry_run = false): mixed
    {
        $value = $this->compute($target_post_id);
        if (! $dry_run) {
            $this->set_target_meta_value($target_post_id, $this->target_meta_key, $value);
        }
        return $value;
    }

    /**
     * Default averaging of numeric input metas across related posts.
     * Subclasses may call this helper.
     *
     * @return float|int|null
     */
    public function average_input_meta_values(int $target_post_id): float|int|null
    {
        $posts = $this->findInputPostsForTarget($target_post_id);
        if (empty($posts) || empty($this->input_meta_keys)) {
            return null;
        }
        $values = [];
        foreach ($posts as $p) {
            foreach ($this->input_meta_keys as $key) {
                $raw = $this->get_input_meta_value($p->ID, $key);
                if (is_numeric($raw)) {
                    $values[] = 0 + $raw;
                } elseif (is_string($raw) && is_numeric(trim($raw))) {
                    $values[] = 0 + trim($raw);
                }
            }
        }
        if (! $values) {
            return null;
        }
        return array_sum($values) / max(count($values), 1);
    }

    /**
     * Compute the value for this calculator for a given target post id.
     * Must be implemented by subclasses.
     *
     * @param int $target_post_id
     * @return mixed
     */
    abstract protected function compute(int $target_post_id): mixed;

    /**
     * Get a single meta value from an INPUT or TARGET post.
     */
    public function get_input_meta_value(int $post_id, string $meta_key): mixed
    {
        return get_post_meta($post_id, $meta_key, true);
    }

    /**
     * Persist the computed value to TARGET post meta.
     */
    public function set_target_meta_value(int $target_post_id, string $meta_key, mixed $value): bool
    {
        return update_post_meta($target_post_id, $meta_key, $value) !== false;
    }

    /**
     * Find input posts that reference the given target post id via $relation_meta_key.
     * Subclasses can override for more complex relations.
     *
     * @param int $target_post_id
     * @return \WP_Post[]
     */
    protected function findInputPostsForTarget(int $target_post_id): array
    {
        if ($this->relation_meta_key === null) {
            return [];
        }
        $q = new \WP_Query([
            'post_type'      => $this->input_cpt_id,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [[
                'key'     => $this->relation_meta_key,
                'value'   => (string)$target_post_id,
                'compare' => '=',
            ]],
            'no_found_rows'  => true,
            // 'fields' => 'all', // removed: undocumented; default returns full posts
        ]);
        return is_wp_error($q) ? [] : ($q->posts ?? []);
    }

    /**
     * Utility: Normalize a "list" meta (JSON array, CSV, serialized, plain string) to array of strings.
     *
     * @param mixed $raw
     * @return string[]
     */
    protected function normalize_list(mixed $raw): array
    {
        if (is_array($raw)) {
            $arr = $raw;
        } elseif (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') {
                $arr = [];
            } elseif (str_starts_with($trim, '[') && str_ends_with($trim, ']')) {
                $decoded = json_decode($trim, true);
                $arr = is_array($decoded) ? $decoded : [$trim];
            } elseif (str_contains($trim, ',')) {
                $arr = array_map('trim', explode(',', $trim));
            } else {
                $arr = [$trim];
            }
        } else {
            $arr = [];
        }

        // Normalize: trim, lowercase (mbstring fallback) then title case for consistent display, drop empties
        $norm = [];
        foreach ($arr as $v) {
            if (! is_scalar($v)) {
                continue;
            }
            $s = trim((string)$v);
            if ($s === '') {
                continue;
            }
            $lower = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
            $s = Str::title_case($lower);
            $norm[$s] = true; // unique
        }
        $out = array_keys($norm);
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }
}
