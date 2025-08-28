<?php
declare(strict_types=1);

namespace BFR\Admin;

use BFR\Infrastructure\WordPress\OptionRepository;
use BFR\Infrastructure\WordPress\WPHooks;

final class AdminPanel
{
    /** @var array<string, array<string,mixed>> */
    private array $registry;

    private OptionRepository $options;

    public function __construct(array $registry, OptionRepository $options)
    {
        $this->registry = $registry;
        $this->options = $options;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_bfr_run_calc', [$this, 'handle_run_single']);
        add_action('admin_post_bfr_run_all',  [$this, 'handle_run_all']);
    }

    public function add_menu(): void
    {
        add_menu_page(
            'BFR',
            'BFR',
            'manage_options',
            'bfr-root',
            [$this, 'render_root'],
            'dashicons-controls-repeat',
            60
        );

        add_submenu_page(
            'bfr-root',
            'Calculated Meta',
            'Calculated Meta',
            'manage_options',
            'bfr-calculated-meta',
            [$this, 'render_calculated_meta']
        );
    }

    public function render_root(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }
        echo '<div class="wrap"><h1>BFR</h1><p>Welcome to Book Freediving Retreats Core.</p></div>';
    }

    public function render_calculated_meta(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }

        $action_single = admin_url('admin-post.php');
        $action_all    = esc_url(admin_url('admin-post.php'));
        $nonce         = wp_create_nonce('bfr-calcs');

        echo '<div class="wrap">';
        echo '<h1>Calculated Meta</h1>';
        echo '<form method="post" action="'.esc_url($action_all).'" style="margin-bottom:1rem">';
        echo '<input type="hidden" name="action" value="bfr_run_all" />';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'" />';
        echo '<button class="button button-primary">Run All (for every target post)</button>';
        echo '</form>';

        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>Name</th><th>Slug</th><th>Target Type</th><th>Target Meta</th><th>Input Types</th><th>Input Metas</th><th>Relation Key</th><th>Run</th>';
        echo '</tr></thead><tbody>';

        foreach ($this->registry as $slug => $cfg) {
            echo '<tr>';
            echo '<td>'.esc_html($cfg['name'] ?? $slug).'</td>';
            echo '<td><code>'.esc_html($slug).'</code></td>';
            echo '<td><code>'.esc_html((string)($cfg['target_cpt_id'] ?? '')) . '</code></td>';
            echo '<td><code>'.esc_html((string)($cfg['target_meta_key'] ?? '')) . '</code></td>';
            echo '<td><code>'.esc_html(implode(', ', (array)($cfg['input_cpt_id'] ?? []))).'</code></td>';
            echo '<td><code>'.esc_html(implode(', ', (array)($cfg['input_meta_keys'] ?? []))).'</code></td>';
            echo '<td><code>'.esc_html((string)($cfg['relation_meta_key'] ?? '')) . '</code></td>';
            echo '<td>';
            echo '<form method="post" action="'.esc_url($action_single).'">';
            echo '<input type="hidden" name="action" value="bfr_run_calc" />';
            echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'" />';
            echo '<input type="hidden" name="slug" value="'.esc_attr($slug).'" />';
            echo '<input type="number" name="post_id" placeholder="Target Post ID" min="1" style="width:140px" required /> ';
            echo '<label><input type="checkbox" name="dry" value="1" /> Dry run</label> ';
            echo '<button class="button">Run</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function handle_run_single(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }
        check_admin_referer('bfr-calcs');

        $slug   = sanitize_key($_POST['slug'] ?? '');
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $dry    = ! empty($_POST['dry']);

        if (! $slug || ! $post_id || ! isset($this->registry[$slug])) {
            wp_safe_redirect(add_query_arg(['page' => 'bfr-calculated-meta', 'bfr_msg' => 'invalid'], admin_url('admin.php')));
            exit;
        }

        $class = $this->registry[$slug]['class'];
        if (! class_exists($class)) {
            wp_safe_redirect(add_query_arg(['page' => 'bfr-calculated-meta', 'bfr_msg' => 'noclass'], admin_url('admin.php')));
            exit;
        }
        /** @var \BFR\Meta\CalculatedMetaField $calc */
        $calc = new $class($this->registry[$slug]);
        $value = $calc->run($post_id, $dry);

        $msg = $dry ? "Dry value: " . maybe_serialize($value) : "Saved value: " . maybe_serialize($value);
        wp_safe_redirect(add_query_arg(['page' => 'bfr-calculated-meta', 'bfr_msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    public function handle_run_all(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }
        check_admin_referer('bfr-calcs');

        // Iterate each calculator; for each, query all target posts and run.
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
        wp_safe_redirect(add_query_arg(['page' => 'bfr-calculated-meta', 'bfr_msg' => 'Ran all'], admin_url('admin.php')));
        exit;
    }
}
