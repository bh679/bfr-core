<?php
declare(strict_types=1);

namespace BFR\Admin\Components;

/**
 * Class CalculatedMetaFieldInputs
 *
 * Renders and saves the inputs for a single calculator (Target Meta Key + Input Meta Keys).
 * - Uses DropdownProvider for single select-with-custom (Target Meta Key).
 * - Uses DropdownArrayManager for array dropdown inputs (Input Meta Keys).
 * - Knows nothing about outer layout/tables.
 */
final class CalculatedMetaFieldInputs
{
    private DropdownProvider $dropdowns;
    private DropdownArrayManager $arrays;
    private string $target_cpt;
    private string $input_cpt;

    /**
     * @param DropdownProvider     $dropdowns  Options + select-with-custom renderer
     * @param DropdownArrayManager $arrays     Array dropdown manager
     * @param string               $target_cpt Active target CPT (discover target meta keys)
     * @param string               $input_cpt  Active input CPT (discover input meta keys)
     */
    public function __construct(
        DropdownProvider $dropdowns,
        DropdownArrayManager $arrays,
        string $target_cpt,
        string $input_cpt
    ) {
        $this->dropdowns = $dropdowns;
        $this->arrays    = $arrays;
        $this->target_cpt = $target_cpt;
        $this->input_cpt  = $input_cpt;
    }

    /**
     * Render the Target Meta Key selector for a given calculator slug.
     * Emits three fields:
     *   target_meta_key[slug], target_meta_key_custom[slug], target_meta_key_mode[slug]
     *
     * @param string $slug         Calculator slug
     * @param string $selected_key Preselected target meta key
     * @param string $custom_val   Custom text (if any)
     * @return string HTML
     */
    public function render_target_meta_key(string $slug, string $selected_key = '', string $custom_val = ''): string
    {
        // Ensure core JS exists (toggle + array controls) — cheap no-op after first call
        $this->arrays->ensureScripts();

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
     * Render the Input Meta Keys multi control for a slug.
     * Uses DropdownArrayManager so the UI can have 0..N rows and a shared JS handler.
     *
     * @param string   $slug          Calculator slug
     * @param string[] $selected_keys Preselected keys
     * @param string[] $custom_values Custom values aligned by index
     * @return string HTML
     */
    public function render_input_meta_keys(string $slug, array $selected_keys = [], array $custom_values = []): string
    {
        $options = $this->dropdowns->discover_meta_keys_for_post_type($this->input_cpt, 200);

        return $this->arrays->renderArrayControl(
            'input_meta_keys['.$slug.']',
            'input_meta_keys_custom['.$slug.']',
            'input_meta_keys_mode['.$slug.']',
            $options,
            $selected_keys,
            $custom_values,
            'bfr-metakeys-multi' // default class used elsewhere
        );
    }

    /**
     * Resolve (save) the posted values ONLY for this calculator slug.
     * Returns array:
     *   - target_meta_key (string)
     *   - input_meta_keys (string[])
     *
     * @param string               $slug        Calculator slug
     * @param array<string,mixed>  $existingCfg Existing config to fall back for target key if blank
     * @return array{target_meta_key:string,input_meta_keys:array<int,string>}
     */
    public function save_for_slug(string $slug, array $existingCfg): array
    {
        $slug_key = sanitize_key($slug);

        // Target meta key (single select-with-custom trio)
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
            // Preserve prior target key if user left it blank
            $target_key = (string)($existingCfg['target_meta_key'] ?? '');
        }

        // Input meta keys (array control resolved via manager)
        $final_input = $this->arrays->resolveArrayFromPost(
            'input_meta_keys['.$slug_key.']',
            'input_meta_keys_custom['.$slug_key.']',
            'input_meta_keys_mode['.$slug_key.']'
        );

        return [
            'target_meta_key' => $target_key,
            'input_meta_keys' => $final_input, // may be empty array if user removed all rows
        ];
        }
}