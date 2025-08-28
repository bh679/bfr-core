<?php
declare(strict_types=1);

namespace BFR\Admin;

use BFR\Infrastructure\WordPress\OptionRepository;

final class CalculatedMetaEditor
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
        add_action('admin_post_bfr_save_calc', [$this, 'handle_save']);
        add_action('admin_menu', [$this, 'add_menu']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'bfr-root',
            'Calculator Editor',
            'Calculator Editor',
            'manage_options',
            'bfr-calc-editor',
            [$this, 'render_editor']
        );
    }

    public function render_editor(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }

        $nonce = wp_create_nonce('bfr-calc-edit');
        $action = admin_url('admin-post.php');

        echo '<div class="wrap"><h1>Calculator Editor</h1>';
        echo '<p>Edit calculator configs. Changes are stored in the options table and override defaults.</p>';

        foreach ($this->registry as $slug => $cfg) {
            echo '<hr/>';
            echo '<h2>'.esc_html($cfg['name'] ?? $slug).' <small><code>'.$slug.'</code></small></h2>';
            echo '<form method="post" action="'.esc_url($action).'">';
            echo '<input type="hidden" name="action" value="bfr_save_calc" />';
            echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'" />';
            echo '<input type="hidden" name="slug" value="'.esc_attr($slug).'" />';

            $target = (string)($cfg['target_cpt_id'] ?? '');
            $tmeta  = (string)($cfg['target_meta_key'] ?? '');
            $inputs = (array)($cfg['input_cpt_id'] ?? []);
            $imeta  = (array)($cfg['input_meta_keys'] ?? []);
            $rel    = (string)($cfg['relation_meta_key'] ?? '');

            echo '<table class="form-table"><tbody>';
            echo '<tr><th>Target CPT (post type slug)</th><td><input name="target_cpt_id" value="'.esc_attr($target).'" class="regular-text"/></td></tr>';
            echo '<tr><th>Target Meta Key</th><td><input name="target_meta_key" value="'.esc_attr($tmeta).'" class="regular-text"/></td></tr>';
            echo '<tr><th>Input CPT(s)</th><td><input name="input_cpt_id" value="'.esc_attr(implode(',', $inputs)).'" class="regular-text"/></td></tr>';
            echo '<tr><th>Input Meta Keys</th><td><input name="input_meta_keys" value="'.esc_attr(implode(',', $imeta)).'" class="regular-text"/></td></tr>';
            echo '<tr><th>Relation Meta Key</th><td><input name="relation_meta_key" value="'.esc_attr($rel).'" class="regular-text"/></td></tr>';
            echo '</tbody></table>';
            echo '<p><button class="button button-primary">Save</button></p>';
            echo '</form>';
        }

        echo '</div>';
    }

    public function handle_save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }
        check_admin_referer('bfr-calc-edit');

        $slug = sanitize_key($_POST['slug'] ?? '');
        if (! $slug) {
            wp_safe_redirect(add_query_arg(['page' => 'bfr-calc-editor', 'bfr_msg' => 'invalid'], admin_url('admin.php')));
            exit;
        }

        $target = sanitize_text_field($_POST['target_cpt_id'] ?? '');
        $tmeta  = sanitize_key($_POST['target_meta_key'] ?? '');

        $inputs_raw = sanitize_text_field($_POST['input_cpt_id'] ?? '');
        $inputs = array_values(array_filter(array_map('trim', explode(',', $inputs_raw))));

        $input_meta_raw = sanitize_text_field($_POST['input_meta_keys'] ?? '');
        $input_metas = array_values(array_filter(array_map('trim', explode(',', $input_meta_raw))));

        $rel = sanitize_key($_POST['relation_meta_key'] ?? '');

        $overrides = $this->options->get_registry_overrides();
        if (! is_array($overrides)) {
            $overrides = [];
        }
        $overrides[$slug] = array_merge($overrides[$slug] ?? [], [
            'target_cpt_id'   => $target,
            'target_meta_key' => $tmeta,
            'input_cpt_id'    => $inputs,
            'input_meta_keys' => $input_metas,
            'relation_meta_key' => $rel,
        ]);
        $this->options->save_registry_overrides($overrides);

        wp_safe_redirect(add_query_arg(['page' => 'bfr-calc-editor', 'bfr_msg' => 'saved'], admin_url('admin.php')));
        exit;
    }
}
