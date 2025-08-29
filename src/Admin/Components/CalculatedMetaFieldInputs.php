<?php
declare(strict_types=1);

namespace BFR\Admin\Components;

/**
 * Class CalculatedMetaFieldInputs
 *
 * Responsibilities:
 * - Render the input controls for a *single* calculator's fields (Target Meta Key, Input Meta Keys).
 * - Does not know about tables/rows/layout — pure field rendering.
 * - Provide a save method that resolves just this calculator's posted values.
 */
final class CalculatedMetaFieldInputs
{
    private DropdownProvider $dropdowns;
    private string $target_cpt;
    private string $input_cpt;

    /**
     * @param DropdownProvider $dropdowns  Provider for options + select-with-custom rendering
     * @param string           $target_cpt Active target CPT (used for discovering target meta keys)
     * @param string           $input_cpt  Active input CPT (used for discovering input meta keys)
     */
    public function __construct(DropdownProvider $dropdowns, string $target_cpt, string $input_cpt)
    {
        $this->dropdowns = $dropdowns;
        $this->target_cpt = $target_cpt;
        $this->input_cpt  = $input_cpt;
    }

    /**
     * Render the Target Meta Key selector for a given calculator slug.
     * Emits three parallel fields:
     * - target_meta_key[slug]
     * - target_meta_key_custom[slug]
     * - target_meta_key_mode[slug] ('value'|'custom')
     *
     * @param string $slug         Calculator slug
     * @param string $selected_key Preselected target meta key
     * @param string $custom_val   Custom text (if any)
     * @return string HTML
     */
    public function render_target_meta_key(string $slug, string $selected_key = '', string $custom_val = ''): string
    {
        $options = $this->dropdowns->discover_meta_keys_for_post_type($this->target_cpt, 200);
        return $this->dropdowns->render_select_with_custom(
            'target_meta_key['.$slug.']',
            'target_meta_key_custom['.$slug.']',
            'target_meta_key_mode['.$slug.']',
            $options,
            $selected_key,
            $custom_val
        );
    }

    /**
     * Render a multi-row Input Meta Keys selector for a given calculator slug.
     * For each row i, emits:
     * - input_meta_keys[slug][i]
     * - input_meta_keys_custom[slug][i]
     * - input_meta_keys_mode[slug][i] ('value'|'custom')
     *
     * @param string   $slug            Calculator slug
     * @param string[] $selected_keys   Preselected input meta keys
     * @param string[] $custom_values   Custom values aligned by index
     * @return string HTML
     */
    public function render_input_meta_keys(string $slug, array $selected_keys = [], array $custom_values = []): string
    {
        if (empty($selected_keys)) {
            $selected_keys = [''];
        }
        $options = $this->dropdowns->discover_meta_keys_for_post_type($this->input_cpt, 200);

        $base_select = 'input_meta_keys['.$slug.']';
        $base_custom = 'input_meta_keys_custom['.$slug.']';
        $base_mode   = 'input_meta_keys_mode['.$slug.']';

        $html  = '<div class="bfr-metakeys-multi" data-post-type="'.esc_attr($this->input_cpt).'">';
        foreach ($selected_keys as $i => $sel) {
            $sel = (string)$sel;
            $custom = (string)($custom_values[$i] ?? '');

            $select_name = $base_select . '['.$i.']';
            $custom_name = $base_custom . '['.$i.']';
            $mode_name   = $base_mode   . '['.$i.']';

            $html .= '<div class="bfr-metakeys-row" style="margin-bottom:6px">';
            $html .= $this->dropdowns->render_select_with_custom(
                $select_name,
                $custom_name,
                $mode_name,
                $options,
                $sel,
                $custom
            );
            $html .= ' <button type="button" class="button bfr-remove-row" aria-label="Remove">–</button>';
            $html .= '</div>';
        }
        $html .= '<p><button type="button" class="button button-secondary bfr-add-row">Add key +</button></p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Resolve (save) the posted values ONLY for this calculator slug.
     * Returns an array with keys:
     * - target_meta_key (string)
     * - input_meta_keys (string[])
     *
     * @param string               $slug        Calculator slug
     * @param array<string,mixed>  $existingCfg Existing config (to preserve values when none posted)
     * @return array{target_meta_key:string,input_meta_keys:array<int,string>}
     */
    public function save_for_slug(string $slug, array $existingCfg): array
    {
        $slug_key = sanitize_key($slug);

        // Target meta key
        $t_sel  = isset($_POST['target_meta_key'][$slug_key])
            ? sanitize_text_field((string)$_POST['target_meta_key'][$slug_key])
            : '';
        $t_mode = isset($_POST['target_meta_key_mode'][$slug_key])
            ? sanitize_text_field((string)$_POST['target_meta_key_mode'][$slug_key])
            : 'value';
        $t_cus  = isset($_POST['target_meta_key_custom'][$slug_key])
            ? sanitize_key((string)$_POST['target_meta_key_custom'][$slug_key])
            : '';

        $target_key = ($t_mode === 'custom') ? $t_cus : sanitize_key($t_sel);
        if ($target_key === '') {
            $target_key = (string)($existingCfg['target_meta_key'] ?? '');
        }

        // Input meta keys
        $final_input = [];
        $row_sels  = isset($_POST['input_meta_keys'][$slug_key]) && is_array($_POST['input_meta_keys'][$slug_key])
            ? (array)$_POST['input_meta_keys'][$slug_key]
            : [];
        $row_modes = isset($_POST['input_meta_keys_mode'][$slug_key]) && is_array($_POST['input_meta_keys_mode'][$slug_key])
            ? (array)$_POST['input_meta_keys_mode'][$slug_key]
            : [];
        $row_custs = isset($_POST['input_meta_keys_custom'][$slug_key]) && is_array($_POST['input_meta_keys_custom'][$slug_key])
            ? (array)$_POST['input_meta_keys_custom'][$slug_key]
            : [];

        if ($row_sels) {
            $max = max(array_keys($row_sels));
            for ($i = 0; $i <= $max; $i++) {
                $sel  = isset($row_sels[$i])  ? sanitize_text_field((string)$row_sels[$i])  : '';
                $mode = isset($row_modes[$i]) ? sanitize_text_field((string)$row_modes[$i]) : 'value';
                $cus  = isset($row_custs[$i]) ? sanitize_key((string)$row_custs[$i])        : '';
                $resolved = ($mode === 'custom') ? $cus : sanitize_key($sel);
                if ($resolved !== '') {
                    $final_input[] = $resolved;
                }
            }
        }
        if (empty($final_input)) {
            $final_input = array_map('strval', (array)($existingCfg['input_meta_keys'] ?? []));
        }

        return [
            'target_meta_key' => $target_key,
            'input_meta_keys' => array_values(array_unique($final_input)),
        ];
    }
}