<?php
declare(strict_types=1);

namespace BFR\Admin;

use BFR\Infrastructure\WordPress\OptionRepository;

/**
 * Calculator configuration editor.
 *
 * Responsibilities:
 * - Renders an admin page under "BFR → Calculator Editor".
 * - Lets admins edit calculator configs and saves overrides in WP options.
 * - Provides reusable UI helpers:
 *     1) render_select_with_custom()  → select with "Custom…" option that toggles a free-text input.
 *     2) render_cpt_selector()        → post type (CPT) selector built on (1).
 *     3) render_meta_key_selector()   → meta key selector for a given CPT built on (1).
 *
 * Security:
 * - Capability: manage_options
 * - CSRF: nonce "bfr-calc-edit"
 */
final class CalculatedMetaEditor
{
    /** @var array<string, array<string,mixed>> Registry of calculators keyed by slug */
    private array $registry;

    /** Option storage/retrieval */
    private OptionRepository $options;

    /**
     * @param array<string, array<string,mixed>> $registry Active calculator registry (defaults overlaid by saved options)
     * @param OptionRepository                   $options  Options repo (reads/writes overrides)
     */
    public function __construct(array $registry, OptionRepository $options)
    {
        $this->registry = $registry;
        $this->options  = $options;
    }

    /**
     * Register admin menu and form handlers.
     */
    public function register(): void
    {
        add_action('admin_post_bfr_save_calc', [$this, 'handle_save']);
        add_action('admin_menu', [$this, 'add_menu']);
    }

    /**
     * Add "Calculator Editor" submenu under the BFR root menu.
     */
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
     * Render the editor UI for all calculators.
     *
     * Uses the new helper renderers to:
     * - Pick the Target CPT via a dropdown (with Custom).
     * - Pick the Target Meta Key via a dropdown of discovered meta keys (with Custom).
     * - Provide "helpers" to add Input CPTs and Input Meta Keys to their CSV fields.
     */
    public function render_editor(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }

        $nonce  = wp_create_nonce('bfr-calc-edit');
        $action = admin_url('admin-post.php');

        echo '<div class="wrap"><h1>Calculator Editor</h1>';
        echo '<p>Edit calculator configs. Changes are stored in the options table and override defaults.</p>';

        // Render each calculator as a section with its own form
        foreach ($this->registry as $slug => $cfg) {
            echo '<hr/>';
            echo '<h2>' . esc_html($cfg['name'] ?? $slug) . ' <small><code>' . esc_html($slug) . '</code></small></h2>';

            // Current settings (fallbacks)
            $target = (string)($cfg['target_cpt_id']   ?? '');
            $tmeta  = (string)($cfg['target_meta_key'] ?? '');
            $inputs = (array) ($cfg['input_cpt_id']    ?? []);
            $imeta  = (array) ($cfg['input_meta_keys'] ?? []);
            $rel    = (string)($cfg['relation_meta_key'] ?? '');

            echo '<form method="post" action="' . esc_url($action) . '">';
            echo '<input type="hidden" name="action" value="bfr_save_calc" />';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
            echo '<input type="hidden" name="slug" value="' . esc_attr($slug) . '" />';

            echo '<table class="form-table"><tbody>';

            // === Target CPT (post type) using Function 2 (built on Function 1) ===
            echo '<tr><th scope="row">Target CPT (post type slug)</th><td>';
            $this->render_cpt_selector(
                field_base: 'target_cpt_id',
                current_value: $target,
                label: 'Select Target Post Type',
                description: 'Choose an existing post type or select "Custom…" to enter a custom slug.'
            );
            echo '</td></tr>';

            // === Target Meta Key using Function 3 (built on Function 1) ===
            echo '<tr><th scope="row">Target Meta Key</th><td>';
            $this->render_meta_key_selector(
                field_base: 'target_meta_key',
                post_type: $target,
                current_value: $tmeta,
                label: 'Select Target Meta Key',
                description: 'Suggested keys are discovered from posts of the selected Target CPT. Or choose "Custom…" to type any key.'
            );
            echo '</td></tr>';

            // === Input CPT(s): keep CSV field for multiple CPTs; add a helper selector to append ===
            echo '<tr><th scope="row">Input CPT(s)</th><td>';
            echo '<input type="text" name="input_cpt_id" value="' . esc_attr(implode(',', $inputs)) . '" class="regular-text" />';
            echo '<p class="description">Comma-separated list of input post type slugs.</p>';

            echo '<div style="margin-top:.5rem">';
            $this->render_cpt_selector(
                field_base: 'input_cpt_id_helper',
                current_value: '',
                label: 'Add an Input CPT',
                description: 'Use this helper to append a CPT to the comma-separated field above.'
            );
            echo '<button type="button" class="button" data-bfr-append="#bfr-input-cpt-' . esc_attr($slug) . '">Add</button>';
            echo '</div>';

            // tie the text input to a unique id for the helper button
            echo '<script>document.addEventListener("DOMContentLoaded",function(){';
            echo 'var f=document.currentScript.closest("tr").querySelector(\'input[name="input_cpt_id"]\');';
            echo 'if(f&&!f.id){f.id="bfr-input-cpt-' . esc_js($slug) . '";}';
            echo '});</script>';
            echo '</td></tr>';

            // === Input Meta Keys: keep CSV with helper to append discovered key ===
            // Heuristic: for discovery, prefer first input CPT; fallback to target CPT if inputs empty.
            $discovery_cpt = $inputs[0] ?? $target;
            echo '<tr><th scope="row">Input Meta Keys</th><td>';
            echo '<input type="text" name="input_meta_keys" value="' . esc_attr(implode(',', $imeta)) . '" class="regular-text" />';
            echo '<p class="description">Comma-separated list of meta keys read from input posts.</p>';

            echo '<div style="margin-top:.5rem">';
            $this->render_meta_key_selector(
                field_base: 'input_meta_keys_helper',
                post_type: (string)$discovery_cpt,
                current_value: '',
                label: 'Add an Input Meta Key',
                description: 'Select a discovered key (from the first input CPT if available), or add a Custom key, then click "Add".'
            );
            echo '<button type="button" class="button" data-bfr-append="#bfr-input-meta-' . esc_attr($slug) . '">Add</button>';
            echo '</div>';

            // tie the text input to a unique id for the helper button
            echo '<script>document.addEventListener("DOMContentLoaded",function(){';
            echo 'var f=document.currentScript.closest("tr").querySelector(\'input[name="input_meta_keys"]\');';
            echo 'if(f&&!f.id){f.id="bfr-input-meta-' . esc_js($slug) . '";}';
            echo '});</script>';
            echo '</td></tr>';

            // === Relation Meta Key (plain text or use the meta key selector against input/target if you prefer) ===
            echo '<tr><th scope="row">Relation Meta Key</th><td>';
            echo '<input type="text" name="relation_meta_key" value="' . esc_attr($rel) . '" class="regular-text" />';
            echo '<p class="description">Meta key on input posts that stores the related Target post ID.</p>';
            echo '</td></tr>';

            echo '</tbody></table>';

            echo '<p><button class="button button-primary">Save</button></p>';
            echo '</form>';
        }

        // One-time inline script to support "Custom…" toggle and helper "Add" buttons
        $this->print_inline_editor_script();

        echo '</div>';
    }

    /**
     * Handle POST submission to save calculator overrides to options.
     *
     * Accepts values from:
     * - target_cpt_id_select / target_cpt_id_custom  (select-with-custom)
     * - target_meta_key_select / target_meta_key_custom
     * - input_cpt_id  (CSV)
     * - input_meta_keys (CSV)
     * - relation_meta_key (text)
     */
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

        // Resolve select-with-custom for Target CPT
        $target = $this->resolve_select_with_custom('target_cpt_id');

        // Resolve select-with-custom for Target Meta Key
        $tmeta = $this->resolve_select_with_custom('target_meta_key', is_meta_key: true);

        // CSV input CPTs
        $inputs_raw = sanitize_text_field($_POST['input_cpt_id'] ?? '');
        $inputs = array_values(array_filter(array_map('trim', explode(',', $inputs_raw))));

        // CSV input meta keys
        $input_meta_raw = sanitize_text_field($_POST['input_meta_keys'] ?? '');
        $input_metas = array_values(array_filter(array_map('trim', explode(',', $input_meta_raw))));

        // Relation meta key
        $rel = sanitize_key($_POST['relation_meta_key'] ?? '');

        $overrides = $this->options->get_registry_overrides();
        if (! is_array($overrides)) {
            $overrides = [];
        }

        // Shallow merge override for this calculator
        $overrides[$slug] = array_merge($overrides[$slug] ?? [], [
            'target_cpt_id'     => $target,
            'target_meta_key'   => $tmeta,
            'input_cpt_id'      => $inputs,
            'input_meta_keys'   => $input_metas,
            'relation_meta_key' => $rel,
        ]);

        $this->options->save_registry_overrides($overrides);

        wp_safe_redirect(add_query_arg(['page' => 'bfr-calc-editor', 'bfr_msg' => 'saved'], admin_url('admin.php')));
        exit;
    }

    // ---------------------------------------------------------------------
    // Function 1: Generic "select with custom" renderer
    // ---------------------------------------------------------------------

    /**
     * Render a labeled select field populated with $options and a "Custom…" option that reveals a text input.
     *
     * Structure:
     *   - <label>...</label>
     *   - <select name="{$field_base}_select" data-role="with-custom">...</select>
     *   - <input type="text" name="{$field_base}_custom" ...>  (auto-toggled)
     *
     * On submit, call resolve_select_with_custom($field_base) to retrieve the chosen value.
     *
     * @param string   $field_base         Base name for the field (e.g., 'target_cpt_id')
     * @param string[] $options            Map of option => label (or a flat list; values will be used for both when numeric keys)
     * @param string   $current_value      The currently saved value (determines which control is pre-selected)
     * @param string   $label              Human-readable label rendered above the control
     * @param string   $description        Optional helper text rendered below the control
     * @param string   $custom_placeholder Placeholder used when the "Custom…" input appears
     */
    private function render_select_with_custom(
        string $field_base,
        array $options,
        string $current_value,
        string $label,
        string $description = '',
        string $custom_placeholder = 'Custom value…'
    ): void {
        // Normalize options to value => label map
        $normalized = [];
        foreach ($options as $k => $v) {
            if (is_int($k)) {
                $normalized[(string)$v] = (string)$v;
            } else {
                $normalized[(string)$k] = (string)$v;
            }
        }

        // If current value is not among options, pre-select "Custom…" and populate the custom input
        $is_custom = ($current_value !== '' && ! array_key_exists($current_value, $normalized));

        $select_name = $field_base . '_select';
        $custom_name = $field_base . '_custom';
        $select_id   = $field_base . '_select_' . wp_generate_password(6, false);
        $custom_id   = $field_base . '_custom_' . wp_generate_password(6, false);

        echo '<div class="bfr-select-with-custom">';
        echo '<label for="' . esc_attr($select_id) . '"><strong>' . esc_html($label) . '</strong></label><br/>';

        echo '<select id="' . esc_attr($select_id) . '" name="' . esc_attr($select_name) . '" data-role="with-custom" data-custom="#' . esc_attr($custom_id) . '">';
        // Default blank
        echo '<option value="">— Select —</option>';

        // Render provided options
        foreach ($normalized as $value => $text) {
            $selected = (!$is_custom && $current_value !== '' && $current_value === $value) ? ' selected' : '';
            echo '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($text) . '</option>';
        }

        // Custom option
        $custom_selected = $is_custom ? ' selected' : '';
        echo '<option value="__custom__"' . $custom_selected . '>Custom…</option>';
        echo '</select> ';

        // Custom text input (toggled via JS)
        $custom_style = $is_custom ? '' : 'style="display:none"';
        $custom_val   = $is_custom ? $current_value : '';
        echo '<input type="text" id="' . esc_attr($custom_id) . '" name="' . esc_attr($custom_name) . '" value="' . esc_attr($custom_val) . '" class="regular-text" ' . $custom_style . ' placeholder="' . esc_attr($custom_placeholder) . '"/>';

        if ($description !== '') {
            echo '<p class="description" style="margin-top:.25rem">' . esc_html($description) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Resolve the posted value from a select-with-custom control created by render_select_with_custom().
     *
     * @param string $field_base  Base name (e.g., 'target_cpt_id')
     * @param bool   $is_meta_key If true, sanitize as a meta key (sanitize_key); else use sanitize_text_field
     * @return string             Final selected value (custom or from the select)
     */
    private function resolve_select_with_custom(string $field_base, bool $is_meta_key = false): string
    {
        $select_name = $field_base . '_select';
        $custom_name = $field_base . '_custom';

        $selected = sanitize_text_field($_POST[$select_name] ?? '');
        if ($selected === '__custom__') {
            $custom = $is_meta_key
                ? sanitize_key($_POST[$custom_name] ?? '')
                : sanitize_text_field($_POST[$custom_name] ?? '');
            return $custom;
        }

        // Sanitize selected value
        return $is_meta_key ? sanitize_key($selected) : sanitize_text_field($selected);
    }

    // ---------------------------------------------------------------------
    // Function 2: CPT selector built on select-with-custom
    // ---------------------------------------------------------------------

    /**
     * Render a CPT selector using render_select_with_custom().
     *
     * It lists public post types (including custom types), e.g., 'post', 'page', 'freedive-school', etc.
     * Adds a "Custom…" option for arbitrary slugs.
     *
     * @param string $field_base     Base input name (e.g., 'target_cpt_id')
     * @param string $current_value  The currently selected CPT slug
     * @param string $label          Label shown above the field
     * @param string $description    Optional help text rendered below
     */
    private function render_cpt_selector(
        string $field_base,
        string $current_value,
        string $label,
        string $description = ''
    ): void {
        $post_types = get_post_types(['show_in_nav_menus' => true], 'names'); // tends to include public CPTs
        if (empty($post_types)) {
            $post_types = get_post_types(['public' => true], 'names'); // fallback
        }

        // Build value => label list (labels are more human friendly if needed in future)
        $options = [];
        foreach ($post_types as $slug) {
            $options[$slug] = $slug;
        }

        $this->render_select_with_custom(
            field_base: $field_base,
            options: $options,
            current_value: $current_value,
            label: $label,
            description: $description,
            custom_placeholder: 'Enter custom post type slug…'
        );
    }

    // ---------------------------------------------------------------------
    // Function 3: Meta key selector for a given CPT built on select-with-custom
    // ---------------------------------------------------------------------

    /**
     * Render a meta key selector using render_select_with_custom(), pre-populated with discovered keys for $post_type.
     *
     * Discovery is best-effort:
     * - Queries distinct meta_key from wp_postmeta joined to posts of the given post type.
     * - Limited to a reasonable cap to avoid heavy queries.
     *
     * @param string $field_base     Base input name (e.g., 'target_meta_key')
     * @param string $post_type      Post type slug used to discover meta keys (can be empty → results in an empty option list)
     * @param string $current_value  The currently selected meta key
     * @param string $label          Label shown above the field
     * @param string $description    Optional help text rendered below
     */
    private function render_meta_key_selector(
        string $field_base,
        string $post_type,
        string $current_value,
        string $label,
        string $description = ''
    ): void {
        $keys = $this->discover_meta_keys_for_post_type($post_type, 200); // cap to 200 unique keys
        $options = [];
        foreach ($keys as $k) {
            $options[$k] = $k;
        }

        $this->render_select_with_custom(
            field_base: $field_base,
            options: $options,
            current_value: $current_value,
            label: $label,
            description: $description,
            custom_placeholder: 'Enter custom meta key…'
        );
    }

    /**
     * Discover distinct meta keys attached to posts of a given post type.
     *
     * WARNING:
     * - This can be moderately expensive on very large sites; we cap results by $limit.
     * - Results are best-effort for admin convenience only.
     *
     * @param string $post_type Post type slug to scan
     * @param int    $limit     Max unique keys to return
     * @return string[]         List of meta_key strings
     */
    private function discover_meta_keys_for_post_type(string $post_type, int $limit = 200): array
    {
        global $wpdb;

        $post_type = sanitize_key($post_type);
        if ($post_type === '') {
            return [];
        }

        // Query: distinct meta_key for posts of this post_type, ignoring protected WP internals when possible.
        // Note: we allow leading underscores since your use-case often uses leading-underscore keys.
        $sql = "
            SELECT DISTINCT pm.meta_key
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = %s
              AND pm.meta_key <> ''
            LIMIT %d
        ";

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
        $prepared = $wpdb->prepare($sql, $post_type, $limit);
        $rows = is_string($prepared) ? $wpdb->get_col($prepared) : [];

        // Sort naturally for nicer UX
        if (is_array($rows)) {
            natcasesort($rows);
            $rows = array_values(array_unique(array_map('strval', $rows)));
        } else {
            $rows = [];
        }

        return $rows;
        // If desired, add a transient cache here to avoid re-querying on every load.
    }

    /**
     * Print a small inline script (once) that:
     * - Toggles "Custom" inputs when the select value is "__custom__".
     * - Handles helper "Add" buttons to append values to CSV text fields without duplicates.
     */
    private function print_inline_editor_script(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        echo '<script>
document.addEventListener("DOMContentLoaded",function(){
  // Toggle custom input visibility based on select value
  document.querySelectorAll(\'select[data-role="with-custom"]\').forEach(function(sel){
    var targetSel = sel;
    var custom = document.querySelector(sel.getAttribute("data-custom"));
    var toggle = function(){
      if (!custom) return;
      if (targetSel.value === "__custom__") { custom.style.display = ""; }
      else { custom.style.display = "none"; }
    };
    sel.addEventListener("change", toggle);
    toggle();
  });

  // Helper buttons that append selected/custom value to a target CSV input
  document.querySelectorAll(\'button[data-bfr-append]\').forEach(function(btn){
    btn.addEventListener("click", function(){
      var container = btn.closest("td") || document;
      var sel = container.querySelector(\'select[data-role="with-custom"]\');
      if (!sel) return;
      var val = sel.value;
      if (val === "__custom__") {
        var custom = container.querySelector(sel.getAttribute("data-custom"));
        val = custom ? custom.value.trim() : "";
      }
      if (!val) return;

      var targetInput = document.querySelector(btn.getAttribute("data-bfr-append"));
      if (!targetInput) return;

      // Build unique CSV without duplicates
      var parts = targetInput.value ? targetInput.value.split(",").map(function(s){return s.trim();}).filter(Boolean) : [];
      if (parts.indexOf(val) === -1) { parts.push(val); }
      targetInput.value = parts.join(",");
    });
  });
});
</script>';
    }
}