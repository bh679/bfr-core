<?php
declare(strict_types=1);

namespace BFR\Infrastructure\WordPress;

final class WPHooks
{
    /** @var array<string, array<string,mixed>> */
    private array $registry;

    public function __construct(array $registry)
    {
        $this->registry = $registry;
    }

    public function register(): void
    {
        // When saving INPUT posts, recompute affected TARGET posts by relation meta.
        add_action('save_post', [$this, 'maybe_recalculate_on_save'], 20, 3);
        // Optional nightly cron for full recompute.
        add_action('bfr_calculations_nightly', [$this, 'run_all']);
    }

    /**
     * Recompute only if post_type belongs to any calculator's input types.
     */
    public function maybe_recalculate_on_save(int $post_ID, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($post_ID) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        $post_type = get_post_type($post);
        foreach ($this->registry as $slug => $cfg) {
            $inputs = (array)($cfg['input_cpt_id'] ?? []);
            if (! in_array($post_type, $inputs, true)) {
                continue;
            }
            $relation_key = $cfg['relation_meta_key'] ?? null;
            $class = $cfg['class'] ?? null;
            $target_type = $cfg['target_cpt_id'] ?? null;
            if (! $relation_key || ! $class || ! $target_type || ! class_exists($class)) {
                continue;
            }
            $target_id = intval(get_post_meta($post_ID, $relation_key, true));
            if ($target_id > 0 && get_post_type($target_id) === $target_type) {
                /** @var \BFR\Meta\CalculatedMetaField $calc */
                $calc = new $class($cfg);
                $calc->run($target_id, false);
            }
        }
    }

    /**
     * Full recompute for all calculators across all targets (used by cron).
     */
    public function run_all(): void
    {
        foreach ($this->registry as $slug => $cfg) {
            $class = $cfg['class'] ?? null;
            $target_type = (string)($cfg['target_cpt_id'] ?? '');
            if (! $class || ! $target_type || ! class_exists($class)) {
                continue;
            }
            $q = new \WP_Query([
                'post_type'      => $target_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            if (! is_wp_error($q) && ! empty($q->posts)) {
                /** @var \BFR\Meta\CalculatedMetaField $calc */
                $calc = new $class($cfg);
                foreach ($q->posts as $pid) {
                    $calc->run((int)$pid, false);
                }
            }
        }
    }
}
