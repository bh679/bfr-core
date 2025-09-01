<?php
declare(strict_types=1);        // Enforce strict typing rules

namespace BFR\Admin\Components; // Component namespace

// ⬇️ New dropdown system imports
use BFR\Admin\Components\Dropdown\Rendering\SelectRenderer;                                     // Renders select + custom + mode
use BFR\Admin\Components\Dropdown\Providers\MetaKeyOptionsProvider;                     // Meta key provider
use BFR\Admin\Components\Dropdown\Providers\ClassOptionsProvider;                               // Class provider (reads registry.php)
use BFR\Admin\Components\Dropdown\Controls\SingleDropdown;                                              // Single control
use BFR\Admin\Components\Dropdown\Controls\ArrayDropdown;                                               // Array control

use BFR\Meta\CalculatedMetaField;       // Access to registry()

/**
 * Class CalculatedMetaFieldInputs
 *
 * Responsibilities:
 * - Render the input controls for a *single* calculator's fields (Target Meta Key, Input Meta Keys, Calculation Class).
 * - Does not know about tables/rows/layout — pure field rendering.
 * - Provide a save method that resolves just this calculator's posted values.
 */
final class CalculatedMetaFieldInputs
{
        // Active CPTs (drive which meta keys are discovered)
        private string $target_cpt;                             // Active target CPT
        private string $input_cpt;                              // Active input CPT
        private int $preview_post_id;                              // Active input CPT

        // Dropdown plumbing (providers + controls)
        private SelectRenderer $renderer;               // Shared HTML renderer
        private MetaKeyOptionsProvider $metaProvider;   // Meta key discovery
        private SingleDropdown $single;                 // Single dropdown for target meta key
        private ArrayDropdown $array;                   // Array dropdown for input meta keys
        private ClassOptionsProvider $classProvider;    // Class options from registry
        private SingleDropdown $classSingle;    // Single dropdown for class selector

        /**
         * @param string $target_cpt    Active target CPT (used for discovering target meta keys)
         * @param string $input_cpt             Active input CPT (used for discovering input meta keys)
         */
        public function __construct(string $target_cpt, string $input_cpt, int $preview_post_id)
        {
                $this->target_cpt = $target_cpt;                                                                // Save target CPT
                $this->input_cpt  = $input_cpt;                                                                 // Save input CPT
                $this->preview_post_id = $preview_post_id; //preview post

                $this->renderer     = new SelectRenderer();                                             // Create renderer
                $this->metaProvider = new MetaKeyOptionsProvider(200);                  // Meta keys (sample limit)
                $this->single       = new SingleDropdown($this->metaProvider, $this->renderer); // Single meta-key control
                $this->array        = new ArrayDropdown($this->metaProvider, $this->renderer);  // Array meta-keys control

                $this->classProvider = new ClassOptionsProvider(CalculatedMetaField::registry());       // Registry-backed classes
                $this->classSingle   = new SingleDropdown($this->classProvider, $this->renderer);       // Class chooser
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
                return $this->single->render(                                                                           // Render one composite control
                        'target_meta_key['.$slug.']',                                                                   // Select name
                        $selected_key,                                                                                                  // Current selection
                        [
                                'cpt' => $this->target_cpt,
                                'post_id' => (int) ($this->preview_post_id ?? 0)
                        ],                                                                   // Provider context
                        ['class' => 'regular-text'],                                                                    // Extra attrs
                        'target_meta_key_custom['.$slug.']',                                                    // Custom input name
                        $custom_val,                                                                                                    // Custom text
                        'target_meta_key_mode['.$slug.']'                                                               // Hidden mode name
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
                return $this->array->render_array(                                                                      // Render repeating rows
                        'input_meta_keys[' . $slug . ']',                                                               // Base select name
                        $selected_keys,                                                                                                 // Selected values
                        ['cpt' => $this->input_cpt],                                                                    // Provider context (input CPT)
                        ['class' => 'regular-text'],                                                                    // Select attrs
                        'input_meta_keys_custom[' . $slug . ']',                                                // Base custom name
                        $custom_values,                                                                                                 // Custom texts
                        'input_meta_keys_mode[' . $slug . ']'                                                   // Base mode name
                );
        }

        /**
         * Render a <select> for choosing a calculator class from the registry.
         *
         * @param string $name          The HTML name attribute for the <select>.
         * @param string $selected_fqcn The currently selected FQCN (e.g., "\BFR\Meta\Fields\MaxDepth").
         * @return string               The full HTML for the dropdown.
         */
        public function renderClassDropdown(string $name, string $selected_fqcn): string
        {
                // Use the same SingleDropdown machinery backed by ClassOptionsProvider
                return $this->classSingle->render(
                        $name,                                                          // name (e.g. calculation[slug])
                        ltrim($selected_fqcn, '\\'),            // stored may be with leading "\"; provider values are class FQCNs (no leading backslash needed)
                        [],                                                                     // no context
                        ['class' => 'bfr-class-select'],        // attrs
                        null,                                                           // no custom text input
                        null,                                                           // no custom text
                        null                                                            // no mode
                );
        }

        /**
         * Resolve (save) the posted values ONLY for this calculator slug.
         * Returns an array with keys:
         * - target_meta_key (string)
         * - input_meta_keys (string[])
         * - calculation (string)      // FQCN from registry
         * - class (string)            // legacy mirror of 'calculation'
         *
         * @param string               $slug        Calculator slug
         * @param array<string,mixed>  $existingCfg Existing config (to preserve values when none posted)
         * @return array{
         *   target_meta_key:string,
         *   input_meta_keys:array<int,string>,
         *   calculation:string,
         *   class:string
         * }
         */
        public function save_for_slug(string $slug, array $existingCfg): array
        {
                $slug_key = sanitize_key($slug);                                                                                                        // Normalize slug index

                // ---------------------------
                // Target meta key
                // ---------------------------
                $t_sel  = isset($_POST['target_meta_key'][$slug_key]) ? (string) wp_unslash($_POST['target_meta_key'][$slug_key]) : '';
                $t_mode = isset($_POST['target_meta_key_mode'][$slug_key]) ? (string) wp_unslash($_POST['target_meta_key_mode'][$slug_key]) : 'value';
                $t_cus  = isset($_POST['target_meta_key_custom'][$slug_key]) ? (string) wp_unslash($_POST['target_meta_key_custom'][$slug_key]) : '';

                $target_key = ($t_mode === 'custom') ? sanitize_key($t_cus) : sanitize_key($t_sel);
                if ($target_key === '') {
                        $target_key = (string) ($existingCfg['target_meta_key'] ?? '');                                 // Fallback
                }

                // ------------------------------------------
                // Input meta keys (parse posted arrays)
                // ------------------------------------------
                $final_input = $this->parse_input_meta_keys_from_post(
                        $slug_key,
                        (array) ($existingCfg['input_meta_keys'] ?? [])
                );

                // ---------------------------------------------------
                // Calculation class (FQCN) via registry dropdown
                // ---------------------------------------------------
                $posted_calc = isset($_POST['calculation'][$slug_key]) ? (string) wp_unslash($_POST['calculation'][$slug_key]) : '';
                $posted_calc = ltrim($posted_calc, '\\');
                $posted_calc = preg_replace('/[^A-Za-z0-9_\\\\]/', '', $posted_calc) ?? '';
                $posted_calc = ($posted_calc !== '') ? '\\' . $posted_calc : '';

                $registry = CalculatedMetaField::registry();                                                                            // Registry data
                $allowed  = [];
                foreach ($registry as $r) {
                        if (!empty($r['class']) && is_string($r['class'])) {
                                $fq = '\\' . ltrim($r['class'], '\\');
                                $allowed[$fq] = true;
                        }
                }

                $existing_calc  = '\\' . ltrim((string) ($existingCfg['calculation'] ?? ''), '\\');
                $existing_class = '\\' . ltrim((string) ($existingCfg['class'] ?? ''), '\\');

                if ($posted_calc !== '' && isset($allowed[$posted_calc])) {
                        $final_calc = $posted_calc;
                } elseif ($existing_calc !== '' && isset($allowed[$existing_calc])) {
                        $final_calc = $existing_calc;
                } elseif ($existing_class !== '' && isset($allowed[$existing_class])) {
                        $final_calc = $existing_class;
                } else {
                        $final_calc = '';
                }

                return [
                        'target_meta_key' => $target_key,
                        'input_meta_keys' => $final_input,
                        'calculation'     => $final_calc,
                        'class'           => $final_calc,       // legacy mirror
                ];
        }

        /**
         * Parse posted input meta keys array for a given calculator slug key.
         * Accepts the triad:
         *  - input_meta_keys[$slug][i]
         *  - input_meta_keys_custom[$slug][i]
         *  - input_meta_keys_mode[$slug][i] ('value'|'custom')
         *
         * @param string   $slug_key     Calculator slug (array key)
         * @param string[] $existingList Existing values used as fallback
         * @return array<int,string>     Cleaned list of meta keys
         */
        private function parse_input_meta_keys_from_post(string $slug_key, array $existingList): array
        {
                $vals = $_POST['input_meta_keys'][$slug_key]        ?? [];              // Raw select values
                $modes= $_POST['input_meta_keys_mode'][$slug_key]   ?? [];              // Per-item modes
                $cust = $_POST['input_meta_keys_custom'][$slug_key] ?? [];              // Custom texts

                if (!is_array($vals))  { $vals  = []; }
                if (!is_array($modes)) { $modes = []; }
                if (!is_array($cust))  { $cust  = []; }

                $out = [];                                                                                                              // Final cleaned
                $rows = max(count($vals), count($modes), count($cust));                 // Longest length

                for ($i = 0; $i < $rows; $i++) {
                        $mode = (string) ($modes[$i] ?? 'value');                                       // Default mode
                        $sel  = (string) ($vals[$i]  ?? '');                                            // Selected
                        $cus  = (string) ($cust[$i]  ?? '');                                            // Custom

                        $item = ($mode === 'custom') ? sanitize_key($cus) : sanitize_key($sel); // Resolve
                        if ($item !== '') { $out[] = $item; }                                           // Keep non-empty
                }

                // If nothing posted but we have existing values, preserve them
                if (empty($out) && !empty($existingList)) {
                        return array_values(array_filter(array_map('sanitize_key', $existingList), 'strlen'));
                }

                return $out;                                                                                                    // Return cleaned list
        }
}