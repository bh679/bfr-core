<?php
declare(strict_types=1);

namespace BFR\Admin;

use BFR\Infrastructure\WordPress\OptionRepository;

/**
 * Class CalculatedMetaEditor
 *
 * Admin UI to configure calculated meta "calculators".
 * - Adds helpers to render a select with "Custom…" option (reveals a text input).
 * - Provides CPT selectors (input CPT and target CPT) built on top of that helper.
 * - Provides meta key selectors for a chosen CPT, with multi-row support for "input keys".
 * - Renders a single form to update ALL calculators at once.
 *
 * SECURITY:
 * - Capability check (manage_options) for both viewing and saving.
 * - Nonce check on save.
 *
 * SAVING MODEL:
 * - Global CPT choices are saved to every calculator override.
 * - Per-calculator row saves: name, target_meta_key, input_meta_keys[].
 */
final class CalculatedMetaEditor
{
    /** @var array<string, array<string,mixed>> Registry of active calculators, keyed by slug. */
    private array $registry;

    /** Storage for overrides in the wp_options table. */
    private OptionRepository $options;

    /**
     * @param array<string, array<string,mixed>> $registry Active calculator definitions.
     * @param OptionRepository                   $options  Options repo to persist overrides.
     */
    public function __construct(array $registry, OptionRepository $options)
    {
        $this->registry = $registry;
        $this->options  = $options;
    }

    /**
     * Register admin hooks for menu and saving.
     */
    public function register(): void
    {
        add_action('admin_post_bfr_save_calc', [$this, 'handle_save']); // Form submission handler
        add_action('admin_menu',               [$this, 'add_menu']);    // Menu item
    }

    /**
     * Add the "Calculator Editor" submenu under the main BFR menu.
     */
    public function add_menu(): void
    {
        add_submenu_page(
            'bfr-root',              // parent slug (created by AdminPanel)
            'Calculator Editor',     // page title
            'Calculator Editor',     // menu title
            'manage_options',        // capability
            'bfr-calc-editor',       // menu slug
            [$this, 'render_editor'] // render callback
        );
    }

    /**
     * =========================
     * Function 1 (Helper)
     * =========================
     *
     * Render a <select> from provided options, with an extra "Custom…" entry.
     * When "Custom…" is selected, display an adjacent text input.
     * - This function ONLY renders the fields, not labels or descriptions.
     *
     * @param string               $name          HTML name attribute for the select (and the custom input will use "{$name}_custom").
     * @param array<string,string> $options       Key/value pairs of value => label to show in the dropdown.
     * @param string               $selected      Selected option value. If '__custom__', the text input is shown.
     * @param string               $custom_value  Text input value to prefill when in custom mode.
     * @param string|null          $id            Optional explicit id (auto-generated if omitted).
     *
     * @return string HTML markup (select + optional text input).
     */
    private function render_select_with_custom(
        string $name,
        array $options,
        string $selected = '',
        string $custom_value = '',
        ?string $id = null
    ): string {
        // Ensure stable, unique id for this field (used by JS toggler)
        $id = $id ?: 'fld_' . md5($name . wp_rand());

        // Inject a synthetic option to represent "Custom…" mode.
        $options_with_custom = $options + ['__custom__' => 'Custom…'];

        // Build options HTML
        $opts_html = '';
        foreach ($options_with_custom as $value => $label) {
            $sel = selected($selected, (string)$value, false);
            $opts_html .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr((string)$value),
                $sel,
                esc_html((string)$label)
            );
        }

        // Decide initial visibility for the custom input
        $custom_style = ($selected === '__custom__') ? '' : 'style="display:none"';

        // Text input name will be "{$name}_custom"
        $custom_name  = $name . '_custom';

        // Render select + custom text input wrapper
        // The wrapper has data attributes for JS to toggle visibility
        $html  = '<span class="bfr-select-with-custom" ';
        $html .= 'data-select-id="'.esc_attr($id).'" ';
        $html .= 'data-custom-target="'.esc_attr($custom_name).'">';
        $html .= sprintf(
            '<select id="%s" name="%s" class="regular-text">',
            esc_attr($id),
            esc_attr($name)
        );
        $html .= $opts_html;
        $html .= '</select> ';
        $html .= sprintf(
            '<input type="text" name="%s" value="%s" class="regular-text" %s/>',
            esc_attr($custom_name),
            esc_attr($custom_value),
            $custom_style
        );
        $html .= '</span>';

        return $html;
    }

    /**
     * =========================
     * Function 2 (Helper)
     * =========================
     *
     * Build a dropdown (using Function 1) of available CPT post type slugs.
     * - Public post types are listed by their labels.
     *
     * @param string $name           Field name attribute.
     * @param string $selected       Selected post type slug, or '__custom__'.
     * @param string $custom_value   Custom post type slug if using custom.
     *
     * @return string HTML for the dropdown + custom text input.
     */
    private function render_cpt_selector(string $name, string $selected = '', string $custom_value = ''): string
    {
        // Fetch public post types and map slug => label
        $types = get_post_types(['public' => true], 'objects');
        $options = [];
        foreach ($types as $slug => $obj) {
            $options[$slug] = $obj->labels->singular_name ?: $slug;
        }

        // Reuse select-with-custom helper
        return $this->render_select_with_custom($name, $options, $selected, $custom_value);
    }

    /**
     * =========================
     * Function 3 (Helper)
     * =========================
     *
     * Build a dropdown (using Function 1) of discovered meta keys for a given CPT.
     * - Discovery is best-effort: it queries postmeta joined to posts by post_type.
     * - If your site uses a custom relation/store, override this discovery as needed.
     *
     * @param string $name           Field name attribute.
     * @param string $post_type      CPT slug to discover meta keys for.
     * @param string $selected       Selected meta key, or '__custom__'.
     * @param string $custom_value   Value for custom meta key input.
     * @param int    $limit          Max meta keys to show (prevent huge lists).
     *
     * @return string HTML for the dropdown + custom text input.
     */
    private function render_meta_key_selector(
        string $name,
        string $post_type,
        string $selected = '',
        string $custom_value = '',
        int $limit = 200
    ): string {
        $options = $this->discover_meta_keys_for_post_type($post_type, $limit);
        return $this->render_select_with_custom($name, $options, $selected, $custom_value);
    }

    /**
     * Discover distinct meta keys for posts belonging to a given post type.
     * NOTE: This can be moderately expensive; capped by LIMIT and grouped by meta_key.
     *
     * @param string $post_type CPT slug.
     * @param int    $limit     Max meta keys to return.
     *
     * @return array<string,string> value=>label map (meta_key => meta_key).
     */
    private function discover_meta_keys_for_post_type(string $post_type, int $limit = 200): array
    {
        global $wpdb;

        $post_type = sanitize_key($post_type);
        if ($post_type === '') {
            return [];
        }

        // Query distinct meta keys for posts with the given post_type
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "
            SELECT DISTINCT pm.meta_key
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key NOT LIKE '\_%'  -- hide internal keys by default
            ORDER BY pm.meta_key ASC
            LIMIT %d
            ",
            $post_type,
            $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $keys = $wpdb->get_col($sql);
        if (! is_array($keys)) {
            return [];
        }

        $out = [];
        foreach ($keys as $k) {
            $k = (string)$k;
            $out[$k] = $k;
        }
        return $out;
    }

    /**
     * Render a MULTI meta-key picker (for "input keys") using repeated calls to render_meta_key_selector().
     * Includes "Add key" / "Remove" controls in-place via tiny inline JS.
     *
     * @param string   $base_name     Base name for field array (e.g., "input_meta_keys[slug]").
     * @param string   $post_type     CPT whose meta keys should populate the dropdowns.
     * @param string[] $selected_keys Preselected keys; can contain '__custom__' with companion custom values.
     * @param string[] $custom_values Preselected custom values aligned to $selected_keys.
     *
     * @return string HTML block with one or more meta key selectors and controls.
     */
    private function render_input_keys_multi(
        string $base_name,
        string $post_type,
        array $selected_keys = [],
        array $custom_values = []
    ): string {
        // Ensure at least one row exists
        if (empty($selected_keys)) {
            $selected_keys = [''];
        }

        $html  = '<div class="bfr-metakeys-multi" data-post-type="'.esc_attr($post_type).'" data-base-name="'.esc_attr($base_name).'">';
        $index = 0;
        foreach ($selected_keys as $idx => $sel) {
            $sel = (string)$sel;
            $custom = (string)($custom_values[$idx] ?? '');
            // Each row's name uses base_name[index]
            $field_name = $base_name . '[' . $index . ']';
            $custom_name = $field_name . '_custom'; // text input is auto-generated by the helper

            $html .= '<div class="bfr-metakeys-row" style="margin-bottom:6px">';
            $html .= $this->render_meta_key_selector($field_name, $post_type, $sel, $custom);
            $html .= ' <button type="button" class="button bfr-remove-row" aria-label="Remove">–</button>';
            $html .= '</div>';
            $index++;
        }
        $html .= '<p><button type="button" class="button button-secondary bfr-add-row">Add key +</button></p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the Calculator Editor page.
     * - Asks for Input CPT (once) and Target CPT (once).
     * - Displays a table with one row per calculator:
     *     columns: Name (label only + description), Target Meta Key (single), Input Meta Keys (multi)
     * - Uses the helper functions for dropdowns with "Custom…" and meta key discovery.
     */
    public function render_editor(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }

        // CSRF nonce and form action
        $nonce  = wp_create_nonce('bfr-calc-edit');
        $action = admin_url('admin-post.php');

        // Derive sensible defaults for the global CPT selectors from the first calculator
        $any = reset($this->registry) ?: [];
        $default_target_cpt = (string)($any['target_cpt_id'] ?? '');
        $default_input_cpt  = (string)((($any['input_cpt_id'] ?? []))[0] ?? '');

        echo '<div class="wrap"><h1>Calculator Editor</h1>';
        echo '<p>Edit all calculators in one place. Choose your global post types below, then set the target and input meta keys per calculator.</p>';

        // Single form for ALL calculators
        echo '<form method="post" action="'.esc_url($action).'">';
        echo '<input type="hidden" name="action" value="bfr_save_calc" />';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'" />';

        // ======= Global CPT selectors section =======
        echo '<h2>Global Settings</h2>';
        echo '<table class="form-table"><tbody>';

        // Input CPT (once for all calculators)
        echo '<tr><th scope="row">Input CPT (post type slug)</th><td>';
        echo $this->render_cpt_selector(
            'input_cpt_id_global',
            $default_input_cpt === '' ? '' : $default_input_cpt,
            '' // no custom prefill
        );
        echo '<p class="description">Used to discover available <strong>Input Meta Keys</strong> and saved to each calculator.</p>';
        echo '</td></tr>';

        // Target CPT (once for all calculators)
        echo '<tr><th scope="row">Target CPT (post type slug)</th><td>';
        echo $this->render_cpt_selector(
            'target_cpt_id_global',
            $default_target_cpt === '' ? '' : $default_target_cpt,
            '' // no custom prefill
        );
        echo '<p class="description">Used to discover available <strong>Target Meta Keys</strong> and saved to each calculator.</p>';
        echo '</td></tr>';

        // Relation meta key (applies to all calculators)
        $default_relation = (string)($any['relation_meta_key'] ?? '');
        echo '<tr><th scope="row">Relation Meta Key (on input posts)</th><td>';
        // Relation keys can vary widely → keep as plain input (allow custom only)
        printf(
            '<input type="text" name="relation_meta_key_global" value="%s" class="regular-text" />',
            esc_attr($default_relation)
        );
        echo '<p class="description">Meta key on <em>input</em> posts that stores the <em>target</em> post ID (e.g., <code>_bfr_destination_id</code>).</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        // ======= Table: one row per calculator =======
        echo '<h2>Calculators</h2>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:30%">Calculator</th>';
        echo '<th style="width:30%">Target Meta Key</th>';
        echo '<th style="width:40%">Input Meta Keys</th>';
        echo '</tr></thead><tbody>';

        foreach ($this->registry as $slug => $cfg) {
            $name        = (string)($cfg['name'] ?? $slug);
            $description = (string)($cfg['description'] ?? '');
            $tmeta       = (string)($cfg['target_meta_key'] ?? '');
            $imeta       = (array)($cfg['input_meta_keys'] ?? []);

            // Ensure string values for display
            $imeta = array_map(static fn($v) => (string)$v, $imeta);

            echo '<tr>';

            // Column: Name (label only) + Description (below)
            echo '<td>';
            echo '<strong>'.esc_html($name).'</strong><br/>';
            echo '<code>'.esc_html($slug).'</code>';
            if ($description !== '') {
                echo '<p style="margin:6px 0 0;color:#555;">'.esc_html($description).'</p>';
            }
            echo '</td>';

            // Column: Target Meta Key (single select-with-custom)
            echo '<td>';
            echo $this->render_meta_key_selector('target_meta_key['.$slug.']', $default_target_cpt, $tmeta, '');
            echo '<p class="description">Select a known key or pick <em>Custom…</em> and type your own.</p>';
            echo '</td>';

            // Column: Input Meta Keys (multi)
            echo '<td>';
            echo $this->render_input_keys_multi('input_meta_keys['.$slug.']', $default_input_cpt, $imeta, []);
            echo '<p class="description">Add as many input keys as needed. Each can be a known key or <em>Custom…</em>.</p>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin-top:1rem;"><button class="button button-primary">Save All</button></p>';
        echo '</form>';

        // ======= Minimal inline JS to handle "Custom…" toggle + multi-row add/remove =======
        ?>
        <script>
        (function(){
            // Toggle visibility of the adjacent text input when "Custom…" is selected
            document.querySelectorAll('.bfr-select-with-custom select').forEach(function(sel){
                sel.addEventListener('change', function(){
                    var wrapper = sel.closest('.bfr-select-with-custom');
                    if (!wrapper) return;
                    var input = wrapper.querySelector('input[type="text"]');
                    if (!input) return;
                    input.style.display = (sel.value === '__custom__') ? '' : 'none';
                });
            });

            // Add/remove rows for multi input meta keys
            document.querySelectorAll('.bfr-metakeys-multi').forEach(function(block){
                // Add
                var addBtn = block.querySelector('.bfr-add-row');
                if (addBtn) {
                    addBtn.addEventListener('click', function(){
                        var rows = block.querySelectorAll('.bfr-metakeys-row');
                        var last = rows[rows.length - 1];
                        if (!last) return;
                        var clone = last.cloneNode(true);

                        // Reset values in the clone
                        var sel = clone.querySelector('select');
                        if (sel) { sel.value = ''; }
                        var text = clone.querySelector('input[type="text"]');
                        if (text) { text.value = ''; text.style.display = 'none'; }

                        block.insertBefore(clone, addBtn.parentNode);

                        // Rebind custom toggler for the new select
                        var newSel = clone.querySelector('select');
                        if (newSel) {
                            newSel.addEventListener('change', function(){
                                var wrap = newSel.closest('.bfr-select-with-custom');
                                var inp = wrap ? wrap.querySelector('input[type="text"]') : null;
                                if (inp) { inp.style.display = (newSel.value === '__custom__') ? '' : 'none'; }
                            });
                        }

                        // Rebind remove button
                        var remBtn = clone.querySelector('.bfr-remove-row');
                        if (remBtn) {
                            remBtn.addEventListener('click', function(){
                                var rows2 = block.querySelectorAll('.bfr-metakeys-row');
                                if (rows2.length > 1) {
                                    clone.remove();
                                }
                            });
                        }
                    });
                }
                // Remove (existing rows)
                block.querySelectorAll('.bfr-remove-row').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var rows = block.querySelectorAll('.bfr-metakeys-row');
                        var row = btn.closest('.bfr-metakeys-row');
                        if (rows.length > 1 && row) {
                            row.remove();
                        }
                    });
                });
            });
        })();
        </script>
        <?php

        echo '</div>'; // .wrap
    }

    /**
     * Handle form submission.
     * - Saves global input/target CPTs and relation key.
     * - Saves each calculator row: target_meta_key (incl. custom), input_meta_keys[] (incl. custom entries).
     * - Writes everything into options as overrides keyed by slug.
     *
     * NOTE: We no longer save "name" (since name is label-only in the UI).
     */
    public function handle_save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }
        check_admin_referer('bfr-calc-edit');

        // Global CPTs (may be "__custom__" with companion *_custom fields)
        $input_cpt_sel  = sanitize_text_field($_POST['input_cpt_id_global'] ?? '');
        $input_cpt_val  = ($input_cpt_sel === '__custom__')
            ? sanitize_key($_POST['input_cpt_id_global_custom'] ?? '')
            : sanitize_key($input_cpt_sel);

        $target_cpt_sel = sanitize_text_field($_POST['target_cpt_id_global'] ?? '');
        $target_cpt_val = ($target_cpt_sel === '__custom__')
            ? sanitize_key($_POST['target_cpt_id_global_custom'] ?? '')
            : sanitize_key($target_cpt_sel);

        $relation_global = sanitize_key($_POST['relation_meta_key_global'] ?? '');

        // Per-row arrays (names removed: we no longer edit/save calculator names)
        $target_meta     = is_array($_POST['target_meta_key'] ?? null) ? (array)$_POST['target_meta_key'] : [];
        $input_meta_all  = is_array($_POST['input_meta_keys'] ?? null) ? (array)$_POST['input_meta_keys'] : [];

        // Existing overrides
        $overrides = $this->options->get_registry_overrides();
        if (! is_array($overrides)) {
            $overrides = [];
        }

        // Iterate all known calculators by slug
        foreach ($this->registry as $slug => $cfg) {
            $slug_key = sanitize_key((string)$slug);

            // Target meta key (could be "__custom__")
            $t_sel = isset($target_meta[$slug_key]) ? sanitize_text_field($target_meta[$slug_key]) : '';
            // Attempt to resolve the custom companion field with robust fallbacks
            if ($t_sel === '__custom__') {
                $t_key = '';
                // Common patterns for the companion name generated by render_select_with_custom:
                // 1) target_meta_key[slug]_custom
                // 2) target_meta_key_custom[slug]
                if (isset($_POST['target_meta_key'][$slug_key . '_custom'])) {
                    $t_key = (string)$_POST['target_meta_key'][$slug_key . '_custom'];
                } elseif (isset($_POST['target_meta_key_custom'][$slug_key])) {
                    $t_key = (string)$_POST['target_meta_key_custom'][$slug_key];
                } else {
                    // Last-resort scans for any posted key that matches the pattern
                    foreach ($_POST as $k => $v) {
                        if (is_array($v) && str_starts_with((string)$k, 'target_meta_key')) {
                            foreach ($v as $subk => $subv) {
                                if ((string)$subk === $slug_key . '_custom') {
                                    $t_key = (string)$subv;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                $t_key = sanitize_key($t_key);
            } else {
                $t_key = sanitize_key($t_sel);
            }

            // Input meta keys (array of values, each may be "__custom__")
            $im_arr = isset($input_meta_all[$slug_key]) && is_array($input_meta_all[$slug_key])
                ? (array)$input_meta_all[$slug_key]
                : [];
            $final_input_keys = [];

            foreach ($im_arr as $idx => $sel) {
                $sel = sanitize_text_field((string)$sel);
                if ($sel === '') {
                    continue;
                }
                if ($sel === '__custom__') {
                    // Companion custom field naming patterns:
                    // 1) input_meta_keys[slug][idx]_custom
                    // 2) input_meta_keys_custom[slug][idx]
                    $custom_val = '';
                    if (isset($_POST['input_meta_keys'][$slug_key][$idx . '_custom'])) {
                        $custom_val = (string)$_POST['input_meta_keys'][$slug_key][$idx . '_custom'];
                    } elseif (isset($_POST['input_meta_keys_custom'][$slug_key][$idx])) {
                        $custom_val = (string)$_POST['input_meta_keys_custom'][$slug_key][$idx];
                    } else {
                        // Last-resort scan
                        foreach ($_POST as $k => $v) {
                            if (is_array($v) && str_starts_with((string)$k, 'input_meta_keys')) {
                                if (isset($v[$slug_key][$idx . '_custom'])) {
                                    $custom_val = (string)$v[$slug_key][$idx . '_custom'];
                                    break;
                                }
                            }
                        }
                    }
                    $custom_val = sanitize_key($custom_val);
                    if ($custom_val !== '') {
                        $final_input_keys[] = $custom_val;
                    }
                } else {
                    $final_input_keys[] = sanitize_key($sel);
                }
            }

            // Build/merge override record for this slug (no "name" saved)
            $overrides[$slug_key] = array_merge($overrides[$slug_key] ?? [], [
                'target_cpt_id'     => $target_cpt_val,
                'target_meta_key'   => $t_key,
                'input_cpt_id'      => [$input_cpt_val], // store as array to match schema
                'input_meta_keys'   => array_values(array_unique($final_input_keys)),
                'relation_meta_key' => $relation_global,
            ]);
        }

        // Persist
        $this->options->save_registry_overrides($overrides);

        // Redirect back with a small success flag
        wp_safe_redirect(add_query_arg(['page' => 'bfr-calc-editor', 'bfr_msg' => 'saved'], admin_url('admin.php')));
        exit;
    }
}