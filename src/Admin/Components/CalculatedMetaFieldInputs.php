<?php // Starts PHP mode for this file
declare(strict_types=1); // Enforces strict typing rules

namespace BFR\Admin\Components; // Declares the namespace for this class

use BFR\Admin\Components\DropdownArrayInput; // Imports the DropdownArrayInput class for use in this file

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

        /** // Begins the DocBlock for saving posted values
         * Resolve (save) the posted values ONLY for this calculator slug. // Explains what this method does
         * Returns an array with keys: // Lists the keys returned
         * - target_meta_key (string) // Returned target meta key
         * - input_meta_keys (string[]) // Returned array of input meta keys
         *
         * @param string               $slug        Calculator slug // Describes the slug parameter
         * @param array<string,mixed>  $existingCfg Existing config (to preserve values when none posted) // Describes the existing configuration
         * @return array{target_meta_key:string,input_meta_keys:array<int,string>} // Specifies the return type structure
         */
        public function save_for_slug(string $slug, array $existingCfg): array
        {
                $slug_key = sanitize_key($slug); // Sanitizes the slug to be used as an array key

                // Target meta key
                $t_sel  = isset($_POST['target_meta_key'][$slug_key])
                        ? sanitize_text_field((string)$_POST['target_meta_key'][$slug_key])
                        : ''; // Reads the selected target key from POST and sanitizes it
                $t_mode = isset($_POST['target_meta_key_mode'][$slug_key])
                        ? sanitize_text_field((string)$_POST['target_meta_key_mode'][$slug_key])
                        : 'value'; // Reads the mode (value or custom) for the target key
                $t_cus  = isset($_POST['target_meta_key_custom'][$slug_key])
                        ? sanitize_key((string)$_POST['target_meta_key_custom'][$slug_key])
                        : ''; // Reads the custom target key if provided

                $target_key = ($t_mode === 'custom') ? $t_cus : sanitize_key($t_sel); // Chooses custom or selected value based on the mode
                if ($target_key === '') {
                        $target_key = (string)($existingCfg['target_meta_key'] ?? ''); // Falls back to existing config if nothing was posted
                }

                // Input meta keys – delegate parsing to the dropdown array helper
                $final_input = $this->arrayDropdown->parse_post(
                        'input_meta_keys',
                        'input_meta_keys_custom',
                        'input_meta_keys_mode',
                        $slug_key,
                        (array)($existingCfg['input_meta_keys'] ?? [])
                ); // Uses the helper to parse the array of input meta keys

                return [
                        'target_meta_key' => $target_key, // Returns the resolved target key
                        'input_meta_keys' => $final_input, // Returns the resolved array of input meta keys
                ]; // Returns an associative array with target and input keys
        }
}