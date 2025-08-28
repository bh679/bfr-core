<?php
declare(strict_types=1);

namespace BFR\Infrastructure\WPCLI;

use WP_CLI;

final class Commands
{
    /** @var array<string, array<string,mixed>> */
    private array $registry;

    public function __construct(array $registry)
    {
        $this->registry = $registry;
    }

    public function register(): void
    {
        \WP_CLI::add_command('bfr recalc', [$this, 'recalc']);
    }

    /**
     * wp bfr recalc [--all] [--slug=<slug>] [--post_id=<id>] [--dry-run]
     */
    public function recalc(array $args, array $assoc_args): void
    {
        $dry = isset($assoc_args['dry-run']);
        $all = isset($assoc_args['all']);
        $slug = isset($assoc_args['slug']) ? sanitize_key($assoc_args['slug']) : null;
        $post_id = isset($assoc_args['post_id']) ? intval($assoc_args['post_id']) : null;

        if ($all) {
            foreach ($this->registry as $s => $cfg) {
                $this->run_for_all_targets($cfg, $dry);
            }
            WP_CLI::success('Ran all calculators for all targets.');
            return;
        }

        if ($slug && isset($this->registry[$slug]) && $post_id) {
            $class = $this->registry[$slug]['class'];
            if (! class_exists($class)) {
                WP_CLI::error("Class not found for {$slug}");
                return;
            }
            /** @var \BFR\Meta\CalculatedMetaField $calc */
            $calc = new $class($this->registry[$slug]);
            $val = $calc->run((int)$post_id, $dry);
            WP_CLI::success(($dry ? 'Dry value: ' : 'Saved value: ') . maybe_serialize($val));
            return;
        }

        WP_CLI::line('Usage:');
        WP_CLI::line('  wp bfr recalc --all');
        WP_CLI::line('  wp bfr recalc --slug=languages --post_id=123 [--dry-run]');
    }

    private function run_for_all_targets(array $cfg, bool $dry): void
    {
        $class = $cfg['class'] ?? null;
        $target_type = (string)($cfg['target_cpt_id'] ?? '');
        if (! $class || ! $target_type || ! class_exists($class)) {
            return;
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
                $calc->run((int)$pid, $dry);
            }
        }
    }
}
