<?php
declare(strict_types=1);    // Enforce strict typing

namespace BFR\Admin;    // Plugin admin namespace

// ⬇️ New dropdown system imports
use BFR\Admin\Components\Dropdown\Rendering\SelectRenderer; // Renders select + custom + mode
use BFR\Admin\Components\Dropdown\Controls\SingleDropdown;  // Single dropdown control
use BFR\Admin\Components\Dropdown\Providers\CPTOptionsProvider; // CPT options provider

// Keep your existing components
use BFR\Admin\Components\CalculatedMetaFieldInputs; // Field renderer/saver for each calculator row
use BFR\Admin\Components\MetaKeysTable;             // Table that uses the Inputs class
use BFR\Infrastructure\WordPress\OptionRepository;  // Options I/O

/**
 * CalculatedMetaEditor
 *
 * Orchestrates the Calculator Editor page:
 * - Renders all content above the table (global CPTs, preview post, relation key).
 * - Invokes MetaKeysTable to render the calculators table (no save button inside).
 * - Renders the single Save button.
 * - On submit, resolves globals, then delegates to MetaKeysTable::save_all()
 *   to update per-calculator overrides.
 */
final class CalculatedMetaEditor
{
    /** @var array<string, array<string,mixed>> */
    private array $registry;            // Calculator registry (merged config)

    private OptionRepository $options;  // Options repository

    // Lazily-created renderer to share across controls
    private ?SelectRenderer $renderer = null;   // Shared SelectRenderer

    public function __construct(array $registry, OptionRepository $options)
    {
        $this->registry = $registry;    // Store registry
        $this->options  = $options;     // Store repository
    }

    public function register(): void
    {
        add_action('admin_post_bfr_save_calc', [$this, 'handle_save']); // Save handler
        add_action('admin_menu',               [$this, 'add_menu']);        // Admin menu
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'bfr-root',                     // Parent slug
            'Calculator Editor',            // Page title
            'Calculator Editor',            // Menu title
            'manage_options',               // Capability
            'bfr-calc-editor',              // Menu slug
            [$this, 'render_editor']        // Callback
        );
    }

    /**
     * Ensure dropdown renderer exists (lazy init).
     */
    private function ensure_dropdown_renderer(): void
    {
        if ($this->renderer === null) {                             // If not created yet
            $this->renderer = new SelectRenderer();             // Create renderer
        }
    }

    /**
     * Render the editor page:
     * - Global selectors (Input CPT, Target CPT, Preview Page, Relation meta key)
     * - Calculators table (via MetaKeysTable)
     * - Save button (only here)
     */
    public function render_editor(): void
    {
        if (! current_user_can('manage_options')) {             // Gate by capability
            wp_die(__('Insufficient permissions.', 'bfr'));     // Bail if not allowed
        }

        $this->ensure_dropdown_renderer();                      // Create renderer

        $nonce  = wp_create_nonce('bfr-calc-edit');             // Nonce
        $action = admin_url('admin-post.php');                  // Form action

        // Defaults derived from the first calculator
        $any = reset($this->registry) ?: [];                    // First registry row
        $default_target_cpt = (string)($any['target_cpt_id'] ?? '');    // Default target CPT
        $default_input_cpt  = (string)((($any['input_cpt_id'] ?? []))[0] ?? '');    // Default input CPT
        $default_relation   = (string)($any['relation_meta_key'] ?? '');            // Default relation key

        // Build controls/providers for global CPT selects
        $cptProvider = new CPTOptionsProvider();                // CPT source
        $cptSelect   = new SingleDropdown($cptProvider, $this->renderer);   // Single dropdown

        // Build the editor UI
        echo '<div class="wrap"><h1>Calculator Editor</h1>';
        echo '<p>Edit all calculators in one place. Choose your global post types below, then set the target and input meta keys per calculator.</p>';

        echo '<form method="post" action="'.esc_url($action).'">';  // Open form
        echo '<input type="hidden" name="action" value="bfr_save_calc" />'; // Action
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'" />';    // Nonce

        // ===== Global controls =====
        echo '<h2>Global Settings</h2>';
        echo '<table class="form-table"><tbody>';

        // Input CPT (select/custom/mode)
        echo '<tr><th scope="row">Input CPT (post type slug)</th><td>';
        echo $cptSelect->render(
            'input_cpt_id_global',                  // <select> name
            $default_input_cpt,                 // current value
            [],                                 // no context needed
            [
                'id'       => 'bfr-input-cpt',  // id for hooks
                'onchange' => 'bfrOnTargetOrInputCPTChange && bfrOnTargetOrInputCPTChange(this);',  // optional hook
            ],
            'input_cpt_id_global_custom',       // custom field name
            '',                                 // custom prefill
            'input_cpt_id_global_mode'          // hidden mode field name
        );
        echo '<p class="description">Used to discover <strong>Input Meta Keys</strong> and saved to each calculator.</p>';
        echo '</td></tr>';

        // Target CPT (select/custom/mode)
        echo '<tr><th scope="row">Target CPT (post type slug)</th><td>';
        echo $cptSelect->render(
            'target_cpt_id_global',             // <select> name
            $default_target_cpt,                // current value
            [],                                 // no context
            [
                'id'       => 'bfr-target-cpt', // id for hooks
                'onchange' => 'bfrOnTargetOrInputCPTChange && bfrOnTargetOrInputCPTChange(this);',  // optional hook
            ],
            'target_cpt_id_global_custom',      // custom field
            '',                                 // custom prefill
            'target_cpt_id_global_mode'         // hidden mode
        );
        echo '<p class="description">Used to discover <strong>Target Meta Keys</strong> and saved to each calculator.</p>';
        echo '</td></tr>';

        // PREVIEW PAGE — list posts for the selected Target CPT (no separate provider needed)
        echo '<tr><th scope="row">Preview Page</th><td>';
        $previewOptions = $this->collect_preview_posts_for_cpt($default_target_cpt);    // Build post options
        echo $this->renderer->render(                       // Reuse the same renderer
            'preview_post_id_global',                       // select name (post ID values)
            null,                                           // no default selection
            $previewOptions,                                // options: id => title
            ['id' => 'bfr-preview-post'],                   // attrs
            'preview_post_id_global_custom',                // custom text input name
            '',                                             // custom prefill
            'preview_post_id_global_mode'                   // hidden mode name
        );
        echo '<p class="description">Pick a sample <strong>' . esc_html($default_target_cpt) . '</strong> post to preview templates/calculations. You can also enter a custom value.</p>';
        echo '</td></tr>';

        // Relation meta key (text)
        echo '<tr><th scope="row">Relation Meta Key (on input posts)</th><td>';
        printf(
            '<input type="text" name="relation_meta_key_global" value="%s" class="regular-text" />',
            esc_attr($default_relation)
        );
        echo '<p class="description">Meta key on <em>input</em> posts that stores the <em>target</em> post ID (e.g., <code>destination_id</code>).</p>';
        echo '</td></tr>';

        echo '</tbody></table>';


        // Resolve preview post id from the current form state (falls back to 0 if nothing chosen)
        $pp_mode = sanitize_text_field($_POST['preview_post_id_global_mode'] ?? 'value');   // 'value' | 'custom'
        $pp_sel  = (string)($_POST['preview_post_id_global'] ?? '');                        // Selected post id
        $pp_cus  = (string)($_POST['preview_post_id_global_custom'] ?? '');             // Custom post id
        $preview_post_id = 0;//($pp_mode === 'custom') ? (int)$pp_cus : (int)$pp_sel;

        // ===== Calculators table =====
        $inputs = new CalculatedMetaFieldInputs(            // Build field renderer bound to these CPTs
            $default_target_cpt,
            $default_input_cpt,
            $preview_post_id
        );
        $table = new MetaKeysTable($this->registry, $inputs);   // Compose table with renderer
        echo $table->render();                                  // Render table

        // ===== Save button (Editor owns the button) =====
        echo '<p style="margin-top:1rem;"><button class="button button-primary">Save All</button></p>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Handle form submission.
     */
    public function handle_save(): void
    {
        if (! current_user_can('manage_options')) {                 // Capability gate
            wp_die(__('Insufficient permissions.', 'bfr'));         // Bail
        }
        check_admin_referer('bfr-calc-edit');                       // Verify nonce

        // Resolve global Input CPT
        $input_mode = sanitize_text_field($_POST['input_cpt_id_global_mode'] ?? 'value');   // 'value' | 'custom'
        $input_sel  = sanitize_text_field($_POST['input_cpt_id_global'] ?? '');             // Selected value
        $input_cus  = sanitize_key($_POST['input_cpt_id_global_custom'] ?? '');             // Custom value
        $input_cpt  = ($input_mode === 'custom') ? $input_cus : sanitize_key($input_sel);   // Final

        // Resolve global Target CPT
        $target_mode = sanitize_text_field($_POST['target_cpt_id_global_mode'] ?? 'value'); // 'value' | 'custom'
        $target_sel  = sanitize_text_field($_POST['target_cpt_id_global'] ?? '');           // Selected value
        $target_cus  = sanitize_key($_POST['target_cpt_id_global_custom'] ?? '');           // Custom value
        $target_cpt  = ($target_mode === 'custom') ? $target_cus : sanitize_key($target_sel);// Final

        // Relation meta key
        $relation_key = sanitize_key($_POST['relation_meta_key_global'] ?? '');             // Relation key

        // Build inputs helper bound to the chosen CPTs for accurate save logic
        $inputs = new CalculatedMetaFieldInputs($target_cpt, $input_cpt);                   // Field renderer
        $table  = new MetaKeysTable($this->registry, $inputs);                              // Table

        $overrides = $this->options->get_registry_overrides();                              // Load existing overrides
        if (! is_array($overrides)) { $overrides = []; }                                    // Normalize

        // Delegate per-calculator saving to the table
        $overrides = $table->save_all($overrides, $target_cpt, $input_cpt, $relation_key);  // Merge/save

        // Persist
        $this->options->save_registry_overrides($overrides);                                // Save back

        // Redirect
        wp_safe_redirect(add_query_arg(['page' => 'bfr-calc-editor', 'bfr_msg' => 'saved'], admin_url('admin.php')));   // Back to editor
        exit;                                                                               // Terminate
    }

    /**
     * Build "preview post" options: post_id => post_title for the given CPT.
     *
     * @param string $cpt   Post type slug
     * @return array<string,string> Map of id => title
     */
    private function collect_preview_posts_for_cpt(string $cpt): array
    {
        if ($cpt === '') { return []; }                                                 // No CPT, no options
        $posts = get_posts([
            'post_type'      => $cpt,                                                   // Target CPT
            'posts_per_page' => 100,                                                    // Reasonable limit
            'post_status'    => ['publish','draft','pending','future'],             // Admin can browse drafts
            'orderby'        => 'date',                                                 // Sort by recency
            'order'          => 'DESC',
            'suppress_filters' => true,
            'no_found_rows'  => true,
            'fields'         => 'ids',                                                  // Faster
        ]);
        $out = [];                                                                      // Output map
        foreach ($posts as $pid) {                                                      // Loop IDs
            $title = get_the_title((int)$pid) ?: ('#' . (int)$pid);                     // Resolve title
            $out[(string)(int)$pid] = (string)$title;                                   // Map id => title
        }
        return $out;                                                                        // Return options
    }
}