<?php // Starts PHP mode
declare(strict_types=1); // Enables strict typing in this file

namespace BFR\Admin\Components; // Declares the namespace for the class to avoid name collisions

/** // Begins a DocBlock describing the purpose of this class
 * Class DropdownArrayInput // Provides a descriptive name for this helper class
 *
 * A helper for rendering and resolving multi-row dropdown inputs that // Explains overall responsibility of this class
 * populate an array of values. Each row consists of a select box backed // Clarifies that each row includes a select box
 * by a provided set of options plus a “Custom…” option to allow free // Notes that a custom option is available for user-defined values
 * text entry. A hidden input tracks whether the row represents a // Mentions the use of a hidden input to track the mode
 * predefined value or a custom value. // Indicates what the hidden input signifies
 *
 * This class delegates the actual select + custom field rendering to // States that rendering work is delegated to another provider
 * {@see DropdownProvider::render_select_with_custom()}, and handles // Points to the method used for rendering each select/custom pair
 * repeating rows, the add/remove UI, and parsing posted values back // Lists additional responsibilities: repeating rows and parsing values
 * into a clean array. The generated markup mirrors the existing // Notes that the HTML produced matches existing markup used elsewhere
 * meta‑keys UI, ensuring the existing JavaScript that binds add/remove // Explains why the existing JS will still work
 * behaviour continues to work without changes. // Emphasizes backward compatibility
 */
final class DropdownArrayInput // Defines a final class so it cannot be extended further
{
		private DropdownProvider $dropdowns; // Private property to hold the injected DropdownProvider

		public function __construct(DropdownProvider $dropdowns) // Constructor receives a DropdownProvider dependency
		{
				$this->dropdowns = $dropdowns; // Assigns the passed DropdownProvider to the property
		}

		/** // Begins a DocBlock for the render method
		 * Render a multi‑row dropdown input. // Describes what this method does
		 *
		 * Given base field names for the select, custom text input and mode // Explains the parameters expected
		 * hidden input, this will emit a container with one row per selected // Describes the structure of the generated HTML
		 * value. Each row contains a select/custom pair and a remove button. // Details what each row includes
		 * At the end of the container an “Add key +” button is rendered to // Mentions the presence of an add-row button
		 * duplicate the last row when clicked (handled by existing JS). // Notes that JS handles duplication
		 *
		 * @param string               $base_select_name Base name of the select field (include slug in square brackets) // Documents the first parameter
		 * @param string               $base_custom_name Base name of the custom text field (include slug) // Documents the second parameter
		 * @param string               $base_mode_name   Base name of the mode hidden field (include slug) // Documents the third parameter
		 * @param array<string,string> $options          Map of value => label used to populate the select // Lists the select options
		 * @param array<int,string>    $selected_values  Preselected values (empty array yields one blank row) // Describes the preselected values array
		 * @param array<int,string>    $custom_values    Preselected custom values aligned by index // Describes the custom values array
		 * @param string|null          $data_post_type   Optional post type slug to emit as data‑post‑type attribute // Describes optional data attribute
		 * @return string HTML // Declares the return type of this method
		 */
		public function render(
				string $base_select_name,
				string $base_custom_name,
				string $base_mode_name,
				array $options,
				array $selected_values = [],
				array $custom_values = [],
				?string $data_post_type = null
		): string {
				// At least one row
				if (empty($selected_values)) {
						$selected_values = ['']; // Ensures there is at least one row by default
				}

				$html  = '<div class="bfr-metakeys-multi"'; // Begins the outer container and assigns the CSS class
				if ($data_post_type !== null && $data_post_type !== '') {
						$html .= ' data-post-type="' . esc_attr($data_post_type) . '"'; // Adds a data attribute when a post type is provided
				}
				$html .= '>'; // Closes the opening tag

				foreach ($selected_values as $i => $sel) { // Loops through each selected value to render a row
						$sel = (string)$sel; // Casts the selected value to a string
						$custom = (string)($custom_values[$i] ?? ''); // Retrieves the aligned custom value or defaults to an empty string
						$select_name = $base_select_name . '[' . $i . ']'; // Builds the name attribute for the select field
						$custom_name = $base_custom_name . '[' . $i . ']'; // Builds the name attribute for the custom text field
						$mode_name   = $base_mode_name   . '[' . $i . ']'; // Builds the name attribute for the hidden mode field

						$html .= '<div class="bfr-metakeys-row" style="margin-bottom:6px">'; // Starts a new row with margin styling
						$html .= $this->dropdowns->render_select_with_custom(
								$select_name,
								$custom_name,
								$mode_name,
								$options,
								$sel,
								$custom
						); // Uses DropdownProvider to render the select + custom input
						$html .= ' <button type="button" class="button bfr-remove-row" aria-label="Remove">–</button>'; // Adds a remove button to the row
						$html .= '</div>'; // Closes the row div
				}
				$html .= '<p><button type="button" class="button button-secondary bfr-add-row">Add key +</button></p>'; // Adds a button to add new rows
				$html .= '</div>'; // Closes the outer container
				return $html; // Returns the constructed HTML string
		}

		/** // Begins a DocBlock for the parse_post method
		 * Parse posted values from a multi‑row dropdown input back into an array. // Describes this method's function
		 *
		 * This examines the $_POST superglobal for keys of the form // Notes that the method inspects POST data
		 *    $_POST[$base_select_field][$slug][$i] // Describes the select value indexing
		 *    $_POST[$base_custom_field][$slug][$i] // Describes the custom value indexing
		 *    $_POST[$base_mode_field][$slug][$i] // Describes the mode value indexing
		 * and resolves each row to either the selected value (sanitized) or // Explains how a row is resolved
		 * the custom value depending on the mode. Empty rows are skipped. // Mentions that empty rows are ignored
		 * If the result would be empty, the provided $existing_values are // Explains fallback behavior
		 * preserved instead. // Completes the explanation of fallback
		 *
		 * @param string          $base_select_field Root key for the select values (e.g. 'input_meta_keys') // Documents the select root key
		 * @param string          $base_custom_field Root key for the custom values (e.g. 'input_meta_keys_custom') // Documents the custom root key
		 * @param string          $base_mode_field   Root key for the mode values (e.g. 'input_meta_keys_mode') // Documents the mode root key
		 * @param string          $slug_key          Sanitized slug used as subkey in $_POST arrays // Describes the slug subkey
		 * @param array<int,mixed> $existing_values  Existing array of values (used if no new values provided) // Describes the fallback values
		 * @return array<int,string> Resolved array of strings (unique, indexed) // Declares the return type
		 */
		public function parse_post(
				string $base_select_field,
				string $base_custom_field,
				string $base_mode_field,
				string $slug_key,
				array $existing_values = []
		): array {
				$row_sels  = isset($_POST[$base_select_field][$slug_key]) && is_array($_POST[$base_select_field][$slug_key])
						? (array)$_POST[$base_select_field][$slug_key]
						: []; // Retrieves the array of select values for this slug if it exists
				$row_modes = isset($_POST[$base_mode_field][$slug_key]) && is_array($_POST[$base_mode_field][$slug_key])
						? (array)$_POST[$base_mode_field][$slug_key]
						: []; // Retrieves the array of mode values for this slug if it exists
				$row_custs = isset($_POST[$base_custom_field][$slug_key]) && is_array($_POST[$base_custom_field][$slug_key])
						? (array)$_POST[$base_custom_field][$slug_key]
						: []; // Retrieves the array of custom values for this slug if it exists

				$final = []; // Initializes an empty array to collect resolved values
				if ($row_sels) { // Checks if there are any select values to process
						$max = max(array_keys($row_sels)); // Finds the highest index used in the select array
						for ($i = 0; $i <= $max; $i++) { // Iterates through each possible index
								$sel  = isset($row_sels[$i])  ? sanitize_text_field((string)$row_sels[$i])  : ''; // Sanitizes the select value if present
								$mode = isset($row_modes[$i]) ? sanitize_text_field((string)$row_modes[$i]) : 'value'; // Sanitizes the mode value or defaults to 'value'
								$cus  = isset($row_custs[$i]) ? sanitize_key((string)$row_custs[$i])        : ''; // Sanitizes the custom value if present
								$resolved = ($mode === 'custom') ? $cus : sanitize_key($sel); // Uses the custom value if mode is 'custom', else uses the sanitized select value
								if ($resolved !== '') {
										$final[] = $resolved; // Adds the resolved value to the final array if not empty
								}
						}
				}
				if (empty($final)) {
						// Preserve existing values if nothing new was provided
						$final = array_map('strval', (array)$existing_values); // Converts existing values to strings to return instead
				}
				return array_values(array_unique($final)); // Returns a unique, re-indexed array of resolved values
		}
}