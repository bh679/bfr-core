<?php
declare(strict_types=1);

namespace BFR\Admin;

use BFR\Admin\Components\DropdownProvider;
use BFR\Admin\Components\CalculatedMetaFieldInputs;
use BFR\Admin\Components\MetaKeysTable;
use BFR\Admin\Components\PostDropdown;
use BFR\Infrastructure\WordPress\OptionRepository;

/**
 * CalculatedMetaEditor
 *
 * Orchestrates the Calculator Editor page:
 * - Renders all content above the table (global CPTs and relation key).
 * - Invokes MetaKeysTable to render the calculators table (no save button inside).
 * - Renders the single Save button.
 * - On submit, resolves globals, then delegates to MetaKeysTable::save_all()
 *   to update per-calculator overrides.
 */
final class CalculatedMetaEditor
{
    /** @var array<string, array<string,mixed>> */
    private array $registry;

    private OptionRepository $options;

    public function __construct(array $registry, OptionRepository $options)
    {
        $this->registry = $registry;
        $this->options  = $options;
    }

    public function register(): void
    {
        add_action('admin_post_bfr_save_calc', [$this, 'handle_save']);
        add_action('admin_menu',               [$this, 'add_menu']);
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

    /**
     * Render the editor page:
     * - Global selectors (Input CPT, Target CPT, Relation meta key)
     * - Calculators table (via MetaKeysTable)
     * - Save button (only here)
     */
    public function render_editor(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }

        $nonce  = wp_create_nonce('bfr-calc-edit');
        $action = admin_url('admin-post.php');

        // Defaults derived from the first calculator
        $any = reset($this->registry) ?: [];
        $default_target_cpt = (string)($any['target_cpt_id'] ?? '');
        $default_input_cpt  = (string)((($any['input_cpt_id'] ?? []))[0] ?? '');
        $default_relation   = (string)($any['relation_meta_key'] ?? '');

        // Build providers/components
        $dropdowns = new DropdownProvider();
        $inputs    = new CalculatedMetaFieldInputs($dropdowns, $default_target_cpt, $default_input_cpt);
        $post_dropdown = new PostDropdown($dropdowns); // NEW
        $table     = new MetaKeysTable($this->registry, $inputs);

        echo '<div class="wrap"><h1>Calculator Editor</h1>';
        echo '<p>Edit all calculators in one place. Choose your global post types below, then set the target and input meta keys per calculator.</p>';

        echo '<form method="post" action="'.esc_url($action).'">';
        echo '<input type="hidden" name="action" value="bfr_save_calc" />';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'" />';

        // ===== Global controls =====
        echo '<h2>Global Settings</h2>';
        echo '<table class="form-table"><tbody>';

        // Input CPT (select/custom/mode)
        $cpt_options = $dropdowns->get_cpt_options();
        echo '<tr><th scope="row">Input CPT (post type slug)</th><td>';
        echo $dropdowns->render_select_with_custom(
            'input_cpt_id_global',
            'input_cpt_id_global_custom',
            'input_cpt_id_global_mode',
            $cpt_options,
            $default_input_cpt,
            ''
        );
        echo '<p class="description">Used to discover <strong>Input Meta Keys</strong> and saved to each calculator.</p>';
        echo '</td></tr>';

        // Target CPT (select/custom/mode)
        echo '<tr><th scope="row">Target CPT (post type slug)</th><td>';
        echo $dropdowns->render_select_with_custom(
            'target_cpt_id_global',
            'target_cpt_id_global_custom',
            'target_cpt_id_global_mode',
            $cpt_options,
            $default_target_cpt,
            ''
        );
        echo '<p class="description">Used to discover <strong>Target Meta Keys</strong> and saved to each calculator.</p>';
        echo '</td></tr>';

        // PREVIEW PAGE (NEW) — built from posts of the currently selected Target CPT
        echo '<tr><th scope="row">Preview Page</th><td>';
        echo $post_dropdown->render_select_with_custom(
            'preview_post_id_global',          // <select> name (post ID values)
            'preview_post_id_global_custom',   // "Custom…" text input name
            'preview_post_id_global_mode',     // hidden mode field name: 'value' | 'custom'
            $default_target_cpt,               // Populate options from selected Target CPT
            '',                                // no default selection
            ''                                 // no custom prefill
        );
        echo '<p class="description">Pick a sample <strong>' . esc_html($default_target_cpt) . '</strong> post to preview templates/calculations. You can also enter a custom value.</p>';
        echo '</td></tr>';

        // Relation meta key (text)
        echo '<tr><th scope="row">Relation Meta Key (on input posts)</th><td>';
        printf('<input type="text" name="relation_meta_key_global" value="%s" class="regular-text" />', esc_attr($default_relation));
        echo '<p class="description">Meta key on <em>input</em> posts that stores the <em>target</em> post ID (e.g., <code>destination_id</code>).</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        // ===== Calculators table =====
        echo $table->render();

        // ===== Save button (Editor owns the button) =====
        echo '<p style="margin-top:1rem;"><button class="button button-primary">Save All</button></p>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Handle form submission:
     * - Resolve globals (CPTs via mode/value/custom, relation key).
     * - Delegate per-calculator resolution to MetaKeysTable::save_all().
     * - Persist overrides via OptionRepository.
     */
    public function handle_save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }
        check_admin_referer('bfr-calc-edit');

        $dropdowns = new DropdownProvider();

        // Resolve global Input CPT
        $input_mode = sanitize_text_field($_POST['input_cpt_id_global_mode'] ?? 'value');
        $input_sel  = sanitize_text_field($_POST['input_cpt_id_global'] ?? '');
        $input_cus  = sanitize_key($_POST['input_cpt_id_global_custom'] ?? '');
        $input_cpt  = ($input_mode === 'custom') ? $input_cus : sanitize_key($input_sel);

        // Resolve global Target CPT
        $target_mode = sanitize_text_field($_POST['target_cpt_id_global_mode'] ?? 'value');
        $target_sel  = sanitize_text_field($_POST['target_cpt_id_global'] ?? '');
        $target_cus  = sanitize_key($_POST['target_cpt_id_global_custom'] ?? '');
        $target_cpt  = ($target_mode === 'custom') ? $target_cus : sanitize_key($target_sel);

        // Relation meta key
        $relation_key = sanitize_key($_POST['relation_meta_key_global'] ?? '');

        // Build inputs helper bound to the chosen CPTs for accurate save logic
        $inputs = new CalculatedMetaFieldInputs($dropdowns, $target_cpt, $input_cpt);
        $table  = new MetaKeysTable($this->registry, $inputs);

        $overrides = $this->options->get_registry_overrides();
        if (! is_array($overrides)) {
            $overrides = [];
        }

        // Delegate per-calculator saving to the table
        $overrides = $table->save_all($overrides, $target_cpt, $input_cpt, $relation_key);

        // Persist
        $this->options->save_registry_overrides($overrides);

        // Redirect
        wp_safe_redirect(add_query_arg(['page' => 'bfr-calc-editor', 'bfr_msg' => 'saved'], admin_url('admin.php')));
        exit;
    }
}