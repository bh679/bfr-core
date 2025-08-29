<?php
declare(strict_types=1);

namespace BFR\Admin;

use BFR\Infrastructure\WordPress\OptionRepository;
use BFR\Admin\Components\DropdownProvider;
use BFR\Admin\Components\DropdownArrayManager;
use BFR\Admin\Components\CalculatedMetaFieldInputs;
use BFR\Admin\Components\MetaKeysTable;

/**
 * Class CalculatedMetaEditor
 *
 * Admin screen to configure calculator registry values.
 * - Renders global controls (Target CPT, Input CPT, Relation key)
 * - Renders calculators table via MetaKeysTable (per-calculator fields)
 * - Persists overrides to the options table via OptionRepository
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
        add_action('admin_menu', [$this, 'add_menu']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'bfr-root',
            __('Calculated Meta', 'bfr'),
            __('Calculated Meta', 'bfr'),
            'manage_options',
            'bfr-calc-editor',
            [$this, 'render_editor']
        );
    }

    /**
     * Render the editor page.
     * IMPORTANT: This fixes the fatal by constructing CalculatedMetaFieldInputs with the correct argument order:
     *   new CalculatedMetaFieldInputs($dropdowns, $arrays, $targetCpt, $inputCpt)
     */
    public function render_editor(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }

        // Load persisted overrides and merge onto defaults for display
        $overrides      = $this->options->get_registry_overrides();
        $overrides      = is_array($overrides) ? $overrides : [];
        $activeRegistry = $this->merge_overrides($this->registry, $overrides);

        $nonce  = wp_create_nonce('bfr-calc-edit');
        $action = admin_url('admin-post.php');

        // Global fields (with sensible defaults)
        $targetCpt   = sanitize_key((string)($_GET['target_cpt_id'] ?? ($overrides['_globals']['target_cpt_id'] ?? 'destinations')));
        $inputCpt    = sanitize_key((string)($_GET['input_cpt_id']  ?? ($overrides['_globals']['input_cpt_id']  ?? 'freedive-schools')));
        $relationKey = sanitize_key((string)($_GET['relation_meta_key'] ?? ($overrides['_globals']['relation_meta_key'] ?? 'destination_id')));

        // --- The three UI helpers we depend on ---
        $dropdowns = new DropdownProvider();                 // provides options and single select+custom
        $arrays    = new DropdownArrayManager($dropdowns);   // provides array-of-select+custom + inline JS
        // FIX: Proper parameter order (previous fatal)
        $inputs    = new CalculatedMetaFieldInputs($dropdowns, $arrays, $targetCpt, $inputCpt);
        $table     = new MetaKeysTable($activeRegistry, $inputs);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Calculated Meta', 'bfr') . '</h1>';

        // Feedback message
        if (! empty($_GET['bfr_msg'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Saved.', 'bfr') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url($action) . '">';
        echo '<input type="hidden" name="action" value="bfr_save_calc" />';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';

        // --- Global controls (above the table) ---
        echo '<h2>' . esc_html__('Basics', 'bfr') . '</h2>';
        echo '<table class="form-table"><tbody>';

        // Target CPT (select-with-custom)
        echo '<tr><th scope="row">' . esc_html__('Target CPT (post type)', 'bfr') . '</th><td>';
        echo $dropdowns->render_select_with_custom(
            'target_cpt_id',
            'target_cpt_id_custom',
            'target_cpt_id_mode',
            $targetCpt,
            '',
            $dropdowns->get_post_type_options()
        );
        echo '</td></tr>';

        // Input CPT (select-with-custom)
        echo '<tr><th scope="row">' . esc_html__('Input CPT (post type)', 'bfr') . '</th><td>';
        echo $dropdowns->render_select_with_custom(
            'input_cpt_id',
            'input_cpt_id_custom',
            'input_cpt_id_mode',
            $inputCpt,
            '',
            $dropdowns->get_post_type_options()
        );
        echo '</td></tr>';

        // Relation meta key (simple text input)
        echo '<tr><th scope="row">' . esc_html__('Relation Meta Key (on input posts)', 'bfr') . '</th><td>';
        echo '<input type="text" name="relation_meta_key" value="' . esc_attr($relationKey) . '" class="regular-text" />';
        echo '</td></tr>';

        echo '</tbody></table>';

        // --- Calculators table (MetaKeysTable) ---
        echo $table->render();

        // Save button (the table intentionally does not render it)
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Save', 'bfr') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Persist posted values.
     * Delegates per-calculator resolution to MetaKeysTable::save_all().
     */
    public function handle_save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'bfr'));
        }
        check_admin_referer('bfr-calc-edit');

        // Read globals from POST (respect custom mode)
        $targetCpt = $this->resolve_select_or_custom('target_cpt_id', 'target_cpt_id_custom', 'target_cpt_id_mode');
        $inputCpt  = $this->resolve_select_or_custom('input_cpt_id',  'input_cpt_id_custom',  'input_cpt_id_mode');
        $relationKey = sanitize_key((string)($_POST['relation_meta_key'] ?? ''));

        $overrides      = $this->options->get_registry_overrides();
        $overrides      = is_array($overrides) ? $overrides : [];
        $activeRegistry = $this->merge_overrides($this->registry, $overrides);

        // Prepare helpers for save resolution (same as render)
        $dropdowns = new DropdownProvider();
        $arrays    = new DropdownArrayManager($dropdowns);
        $inputs    = new CalculatedMetaFieldInputs($dropdowns, $arrays, $targetCpt, $inputCpt);
        $table     = new MetaKeysTable($activeRegistry, $inputs);

        // Save all calculator rows
        $overrides = $table->save_all($overrides, $targetCpt, $inputCpt, $relationKey);

        // Also save the globals so the next render preselects them
        $overrides['_globals'] = [
            'target_cpt_id'     => $targetCpt,
            'input_cpt_id'      => $inputCpt,
            'relation_meta_key' => $relationKey,
        ];

        $this->options->save_registry_overrides($overrides);

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'bfr-calc-editor', 'bfr_msg' => 'saved'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Merge option overrides onto defaults recursively (shallow for our needs).
     *
     * @param array<string,array<string,mixed>> $defaults
     * @param array<string,array<string,mixed>> $overrides
     * @return array<string,array<string,mixed>>
     */
    private function merge_overrides(array $defaults, array $overrides): array
    {
        $merged = $defaults;

        // Per-calculator
        foreach ($defaults as $slug => $cfg) {
            if (isset($overrides[$slug]) && is_array($overrides[$slug])) {
                $merged[$slug] = array_merge($cfg, $overrides[$slug]);
            }
        }

        // If any override added a new slug, include it
        foreach ($overrides as $slug => $cfg) {
            if ($slug === '_globals') {
                continue;
            }
            if (! isset($merged[$slug]) && is_array($cfg)) {
                $merged[$slug] = $cfg;
            }
        }

        return $merged;
    }

    /**
     * Resolve a "select-with-custom" triple from POST.
     *
     * @param string $nameSel   e.g. 'target_cpt_id'
     * @param string $nameCust  e.g. 'target_cpt_id_custom'
     * @param string $nameMode  e.g. 'target_cpt_id_mode'
     * @return string
     */
    private function resolve_select_or_custom(string $nameSel, string $nameCust, string $nameMode): string
    {
        $sel  = isset($_POST[$nameSel])  ? sanitize_text_field((string)$_POST[$nameSel])  : '';
        $mode = isset($_POST[$nameMode]) ? sanitize_text_field((string)$_POST[$nameMode]) : 'value';
        $cus  = isset($_POST[$nameCust]) ? sanitize_key((string)$_POST[$nameCust])        : '';
        return $mode === 'custom' ? $cus : sanitize_key($sel);
    }
}