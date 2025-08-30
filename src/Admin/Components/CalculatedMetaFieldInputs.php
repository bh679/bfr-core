<?php // Starts PHP mode for this file
declare(strict_types=1); // Enforces strict typing rules

namespace BFR\Admin\Components; // Declares the namespace for this class

use BFR\Admin\Components\DropdownArrayInput; // Imports the DropdownArrayInput class for use in this file
use BFR\Meta\CalculatedMetaField;

/** // Begins the class-level DocBlock
 * Class CalculatedMetaFieldInputs // Names the class
 *
 * Responsibilities: // Summarizes what this class does
 * - Render the input controls for a *single* calculator's fields (Target Meta Key, Input Meta Keys). // Lists one responsibility
 * - Does not know about tables/rows/layout — pure field rendering. // Clarifies separation of concerns
 * - Provide a save method that resolves just this calculator's posted values. // Mentions the save functionality
 */
final class CalculatedMetaFieldInputs // Declares a final class that cannot be extended
{
        private DropdownProvider $dropdowns; // Holds a DropdownProvider instance for rendering options
        private string $target_cpt; // Stores the currently active target post type slug
        private string $input_cpt; // Stores the currently active input post type slug

        /** // Begins a DocBlock for the helper property
         * Helper for rendering and parsing multi-row dropdown arrays. // Explains what this property is for
         *
         * @var DropdownArrayInput // Documents the type of this property
         */
        private DropdownArrayInput $arrayDropdown; // Holds the helper instance for array dropdown inputs

        /** // Begins the constructor DocBlock
         * @param DropdownProvider $dropdowns  Provider for options + select-with-custom rendering // Describes the first constructor argument
         * @param string           $target_cpt Active target CPT (used for discovering target meta keys) // Describes the second constructor argument
         * @param string           $input_cpt  Active input CPT (used for discovering input meta keys) // Describes the third constructor argument
         */
        public function __construct(DropdownProvider $dropdowns, string $target_cpt, string $input_cpt)
        {
                $this->dropdowns = $dropdowns; // Assigns the DropdownProvider to a property
                $this->target_cpt = $target_cpt; // Stores the provided target CPT slug
                $this->input_cpt  = $input_cpt; // Stores the provided input CPT slug

                // Compose a helper to manage multi-row dropdown inputs. It reuses
                // the provided DropdownProvider for rendering the select + custom
                // fields and abstracts away the repetition and parsing logic.
                $this->arrayDropdown = new DropdownArrayInput($dropdowns); // Instantiates the helper for handling array dropdown inputs
        }

        /** // Begins the DocBlock for rendering the target meta key
         * Render the Target Meta Key selector for a given calculator slug. // Describes what this method does
         * Emits three parallel fields: // Introduces the fields generated
         * - target_meta_key[slug] // Lists the select field
         * - target_meta_key_custom[slug] // Lists the custom input field
         * - target_meta_key_mode[slug] ('value'|'custom') // Lists the hidden mode field
         *
         * @param string $slug         Calculator slug // Describes the slug parameter
         * @param string $selected_key Preselected target meta key // Describes the preselected value
         * @param string $custom_val   Custom text (if any) // Describes the preselected custom value
         * @return string HTML // Specifies the return type
         */
        public function render_target_meta_key(string $slug, string $selected_key = '', string $custom_val = ''): string
        {
                $options = $this->dropdowns->discover_meta_keys_for_post_type($this->target_cpt, 200); // Fetches meta key options for the current target CPT
                return $this->dropdowns->render_select_with_custom(
                        'target_meta_key['.$slug.']',
                        'target_meta_key_custom['.$slug.']',
                        'target_meta_key_mode['.$slug.']',
                        $options,
                        $selected_key,
                        $custom_val
                ); // Uses DropdownProvider to render the select/custom/mode controls for the target key
        }

        /** // Begins the DocBlock for rendering input meta keys
         * Render a multi-row Input Meta Keys selector for a given calculator slug. // Describes the method's purpose
         * For each row i, emits: // Lists the fields created per row
         * - input_meta_keys[slug][i] // The select field name
         * - input_meta_keys_custom[slug][i] // The custom input field name
         * - input_meta_keys_mode[slug][i] ('value'|'custom') // The mode field name
         *
         * @param string   $slug            Calculator slug // Describes the slug parameter
         * @param string[] $selected_keys   Preselected input meta keys // Describes the selected keys array
         * @param string[] $custom_values   Custom values aligned by index // Describes the custom values array
         * @return string HTML // Specifies the return type
         */
        public function render_input_meta_keys(string $slug, array $selected_keys = [], array $custom_values = []): string
        {
                $options = $this->dropdowns->discover_meta_keys_for_post_type($this->input_cpt, 200); // Retrieves meta key options for the current input CPT
                $base_select = 'input_meta_keys[' . $slug . ']'; // Defines the base name for select fields including the slug
                $base_custom = 'input_meta_keys_custom[' . $slug . ']'; // Defines the base name for custom text fields
                $base_mode   = 'input_meta_keys_mode[' . $slug . ']'; // Defines the base name for mode fields

                return $this->arrayDropdown->render(
                        $base_select,
                        $base_custom,
                        $base_mode,
                        $options,
                        $selected_keys,
                        $custom_values,
                        $this->input_cpt
                ); // Delegates rendering of multi-row input meta keys to the helper
        }



        /**
         * Render a <select> for choosing a calculator class from the registry.
         *
         * @param string $name                  The HTML name attribute for the <select>.
         * @param string $selected_fqcn The currently selected FQCN (e.g., "\BFR\Meta\Fields\MaxDepth").
         * @return string                               The full HTML for the dropdown.
         */
        public function renderClassDropdown(string $name, string $selected_fqcn): string {
                // Load the cached registry once (fast; static cache inside CalculatedMetaField).
                $registry = \BFR\Meta\CalculatedMetaField::registry();          // Map: slug => ['name','class',...]
                
                // Start output buffering so we can return a complete HTML string.
                ob_start();                                                                                                     // Begin capturing generated HTML
                
                // Open the <select>; add a predictable CSS class for styling/hooks if needed.
                echo '<select name="' . esc_attr($name) . '" class="bfr-class-select">';        // Start select for class chooser
                
                // First, add a neutral "Choose…" placeholder (not selected by default if we have a match).
                echo '<option value="">— Select calculation class —</option>';                          // Placeholder option
                
                // Iterate over registry entries to build options.
                foreach ($registry as $slug => $entry) {                                                                        // Each calculator descriptor
                        // Resolve display name and FQCN safely.
                        $label = isset($entry['name']) ? (string)$entry['name'] : (string)$slug;        // Prefer human name
                        $fqcn  = isset($entry['class']) ? (string)$entry['class'] : '';                 // Fully qualified class name
                        
                        // Compute selection state.
                        $selected = selected($selected_fqcn, $fqcn, false);                                             // WP helper returns ' selected="selected"' or ''
                        
                        // Print the option; show the friendly name, but keep the FQCN as the value.
                        echo '<option value="' . esc_attr($fqcn) . '" ' . $selected . '>';              // Open option tag with value
                        echo esc_html($label);                                                                                                  // Visible text for the option
                        echo '</option>';                                                                                                               // Close option
                }
                
                // Close the <select>.
                echo '</select>';                                                                                                                       // End select
                
                // Return the composed HTML string.
                return (string) ob_get_clean();                                                                                         // Return buffered HTML
        }
        

        /**
         * Resolve (save) the posted values ONLY for this calculator slug.
         * Returns an array with keys:
         * - target_meta_key (string)
         * - input_meta_keys (string[])
         * - calculation (string)      // NEW: FQCN from registry
         * - class (string)            // NEW: legacy mirror of 'calculation'
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
        public function save_for_slug(string $slug, array $existingCfg): array {
                // Normalize the row key used across POST arrays
                $slug_key = sanitize_key($slug);                                                                // Slug-safe array index (e.g., 'max_depth')

                // ---------------------------
                // Target meta key (existing)
                // ---------------------------
                $t_sel  = isset($_POST['target_meta_key'][$slug_key])
                        ? (string) wp_unslash($_POST['target_meta_key'][$slug_key])     // Raw selected value from dropdown
                        : '';
                $t_mode = isset($_POST['target_meta_key_mode'][$slug_key])
                        ? (string) wp_unslash($_POST['target_meta_key_mode'][$slug_key])
                        : 'value';
                $t_cus  = isset($_POST['target_meta_key_custom'][$slug_key])
                        ? (string) wp_unslash($_POST['target_meta_key_custom'][$slug_key])
                        : '';

                $target_key = ($t_mode === 'custom') ? sanitize_key($t_cus) : sanitize_key($t_sel);
                if ($target_key === '') {
                        $target_key = (string) ($existingCfg['target_meta_key'] ?? '');         // Fallback to existing value
                }

                // ------------------------------------------
                // Input meta keys (existing via array helper)
                // ------------------------------------------
                $final_input = $this->arrayDropdown->parse_post(
                        'input_meta_keys',                                                                                              // Base name for dropdown values
                        'input_meta_keys_custom',                                                                               // Base name for custom values
                        'input_meta_keys_mode',                                                                                 // Base name for per-item mode
                        $slug_key,                                                                                                              // Row key
                        (array) ($existingCfg['input_meta_keys'] ?? [])                                 // Existing default array
                );

                // ---------------------------------------------------
                // NEW: Calculation class (FQCN) via registry dropdown
                // ---------------------------------------------------
                // Expect the admin dropdown to post as calculation[$slug]
                $posted_calc = isset($_POST['calculation'][$slug_key])
                        ? (string) wp_unslash($_POST['calculation'][$slug_key])                 // Raw posted FQCN (may include leading '\')
                        : '';

                // Sanitize the FQCN while preserving namespace separators:
                // - allow backslashes, A–Z, a–z, 0–9, and underscores
                $posted_calc = ltrim($posted_calc, '\\');                                                               // Normalize away any leading backslash
                $posted_calc = preg_replace('/[^A-Za-z0-9_\\\\]/', '', $posted_calc) ?? '';
                $posted_calc = ($posted_calc !== '') ? '\\' . $posted_calc : '';                // Store as leading-backslash FQCN

                // Load the registry once (cached inside CalculatedMetaField)
                $registry = \BFR\Meta\CalculatedMetaField::registry();                                  // Map: slug => ['name','class',...]
                $allowed  = [];                                                                                                                 // Build a fast lookup of allowed FQCNs
                foreach ($registry as $r) {
                        if (!empty($r['class']) && is_string($r['class'])) {
                                $fq = '\\' . ltrim($r['class'], '\\');                                                  // Normalize registry class form
                                $allowed[$fq] = true;                                                                                   // Mark allowed
                        }
                }

                // Determine the final class to use:
                // Priority: valid posted class → valid existing 'calculation' → valid existing 'class' → ''
                $existing_calc  = '\\' . ltrim((string) ($existingCfg['calculation'] ?? ''), '\\');
                $existing_class = '\\' . ltrim((string) ($existingCfg['class'] ?? ''), '\\');

                if ($posted_calc !== '' && isset($allowed[$posted_calc])) {
                        $final_calc = $posted_calc;                                                                             // Accept the posted value if in registry
                } elseif ($existing_calc !== '' && isset($allowed[$existing_calc])) {
                        $final_calc = $existing_calc;                                                                           // Keep existing 'calculation' if valid
                } elseif ($existing_class !== '' && isset($allowed[$existing_class])) {
                        $final_calc = $existing_class;                                                                          // Fallback to legacy 'class' if valid
                } else {
                        $final_calc = '';                                                                                                       // No valid class available
                }

                // Mirror into legacy 'class' for backward compatibility
                $legacy_class = $final_calc;

                // Return the merged row; CalculatedMetaEditor can safely ignore unknown keys if it only reads needed ones
                return [
                        'target_meta_key' => $target_key,                                                                       // Existing: target meta key
                        'input_meta_keys' => $final_input,                                                                      // Existing: list of input meta keys
                        'calculation'     => $final_calc,                                                                       // NEW: selected FQCN (from registry)
                        'class'           => $legacy_class,                                                                     // NEW: legacy mirror for older code paths
                ];
        }
}