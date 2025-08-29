<?php // Starts PHP processing
declare(strict_types=1); // Enables strict typing for more reliable code

namespace BFR\Admin\Components; // Declares the namespace of this class for autoloading

/** // Class-level documentation explaining purpose and usage
 * Class DropdownArrayInput
 *
 * A helper for rendering and resolving multi-row dropdown inputs that // Describes what the class does
 * populate an array of values. Each row consists of a select box backed // Adds detail about how each row is built
 * by a provided set of options plus a “Custom…” option to allow free // Explains that a custom entry field exists
 * text entry. A hidden input tracks whether the row represents a // Notes the existence of a hidden mode field
 * predefined value or a custom value. // Clarifies what that hidden mode field signifies
 *
 * This class delegates the actual select + custom field rendering to // Indicates that it relies on an external renderer
 * {@see DropdownProvider::render_select_with_custom()}, and handles // Provides a reference to the rendering method used
 * repeating rows, the add/remove UI, and parsing posted values back // Lists responsibilities such as repetition and parsing
 * into a clean array. The generated markup mirrors the existing // Explains the benefits of consistent markup
 * meta‑keys UI, ensuring the existing JavaScript that binds add/remove // Notes how this works seamlessly with existing JS
 * behaviour continues to work without changes. // Summarizes why the markup stays familiar
 */
final class DropdownArrayInput // Declares a final class so it can't be subclassed
{
		private DropdownProvider $dropdowns; // Holds a reference to the DropdownProvider for rendering selects

		public function __construct(DropdownProvider $dropdowns) // Constructor that accepts a DropdownProvider
		{
				$this->dropdowns = $dropdowns; // Stores the passed DropdownProvider in a property
		}

		/** // Documentation for the render method
		 * Render a multi‑row dropdown input. // Describes the purpose
		 *
		 * Given base field names for the select, custom text input and mode // Describes the expected naming scheme
		 * hidden input, this will emit a container with one row per selected // Explains structure: one row per selected value
		 * value. Each row contains a select/custom pair and a remove button. // Describes contents of each row
		 * At the end of the container an “Add key +” button is rendered to // Mentions the add-row UI element
		 * duplicate the last row when clicked (handled by existing JS). // Notes the duplication handled by JS
		 *
		 * @param string               $base_select_name Base name of the select field (include slug in square brackets) // Parameter description
		 * @param string               $base_custom_name Base name of the custom text field (include slug) // Parameter description
		 * @param string               $base_mode_name   Base name of the mode hidden field (include slug) // Parameter description
		 * @param array<string,string> $options          Map of value => label used to populate the select // Parameter description
		 * @param array<int,string>    $selected_values  Preselected values (empty array yields one blank row) // Parameter description
		 * @param array<int,string>    $custom_values    Preselected custom values aligned by index // Parameter description
		 * @param string|null          $data_post_type   Optional post type slug to emit as data‑post‑type attribute // Parameter description
		 * @return string HTML // Describes the return type
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
						$selected_values = ['']; // Ensures a blank row appears if no values are preselected
				}

				$html  = '<div class="bfr-metakeys-multi"'; // Starts the outer container and adds a CSS class
				if ($data_post_type !== null && $data_post_type !== '') {
						$html .= ' data-post-type="' . esc_attr($data_post_type) . '"'; // Adds a data attribute if a post type is provided
				}
				$html .= '>'; // Closes the opening div tag

				foreach ($selected_values as $i => $sel) { // Loops through each preselected value to build a row
						$sel = (string)$sel; // Casts the selected value to a string
						$custom = (string)($custom_values[$i] ?? ''); // Retrieves the corresponding custom value or defaults to empty
						$select_name = $base_select_name . '[' . $i . ']'; // Builds the name attribute for the select field
						$custom_name = $base_custom_name . '[' . $i . ']'; // Builds the name attribute for the custom text field
						$mode_name   = $base_mode_name   . '[' . $i . ']'; // Builds the name attribute for the hidden mode field

						$html .= '<div class="bfr-metakeys-row" style="margin-bottom:6px">'; // Opens a new row with margin styling
						$html .= $this->dropdowns->render_select_with_custom(
								$select_name,
								$custom_name,
								$mode_name,
								$options,
								$sel,
								$custom
						); // Uses the DropdownProvider to render the select/custom/mode fields
						$html .= ' <button type="button" class="button bfr-remove-row" aria-label="Remove">–</button>'; // Adds a remove button to the row
						$html .= '</div>'; // Closes the current row div
				}
				$html .= '<p><button type="button" class="button button-secondary bfr-add-row">Add key +</button></p>'; // Adds a button for adding new rows
				$html .= '</div>'; // Closes the outer container
				return $html; // Returns the constructed HTML
		}

		/** // Documentation for the parse_post method
		 * Parse posted values from a multi‑row dropdown input back into an array. // Describes functionality
		 *
		 * This examines the $_POST superglobal for keys of the form // Notes that it inspects POST data
		 *    $_POST[$base_select_field][$slug][$i] // Indicates how the select values are indexed
		 *    $_POST[$base_custom_field][$slug][$i] // Indicates how the custom values are indexed
		 *    $_POST[$base_mode_field][$slug][$i] // Indicates how the mode values are indexed
		 * and resolves each row to either the selected value (sanitized) or // Explains resolution logic
		 * the custom value depending on the mode. Empty rows are skipped. // Mentions skipping empty rows
		 * If the result would be empty, the provided $existing_values are // Describes fallback behavior
		 * preserved instead unless there were posted rows, in which case // Introduces new clearing logic
		 * an empty array is returned to allow clearing all selections. // Specifies that clearing is now possible
		 *
		 * @param string          $base_select_field Root key for the select values (e.g. 'input_meta_keys') // Parameter description
		 * @param string          $base_custom_field Root key for the custom values (e.g. 'input_meta_keys_custom') // Parameter description
		 * @param string          $base_mode_field   Root key for the mode values (e.g. 'input_meta_keys_mode') // Parameter description
		 * @param string          $slug_key          Sanitized slug used as subkey in $_POST arrays // Parameter description
		 * @param array<int,mixed> $existing_values  Existing array of values (used if no new values provided) // Parameter description
		 * @return array<int,string> Resolved array of strings (unique, indexed) // Describes return type
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
						: []; // Retrieves the posted select values if available
				$row_modes = isset($_POST[$base_mode_field][$slug_key]) && is_array($_POST[$base_mode_field][$slug_key])
						? (array)$_POST[$base_mode_field][$slug_key]
						: []; // Retrieves the posted mode values if available
				$row_custs = isset($_POST[$base_custom_field][$slug_key]) && is_array($_POST[$base_custom_field][$slug_key])
						? (array)$_POST[$base_custom_field][$slug_key]
						: []; // Retrieves the posted custom values if available

				$final = []; // Initializes the array to hold resolved values
				if ($row_sels) { // Processes posted select values if present
						$max = max(array_keys($row_sels)); // Finds the highest index of posted select values
						for ($i = 0; $i <= $max; $i++) { // Iterates through each possible index
								$sel  = isset($row_sels[$i])  ? sanitize_text_field((string)$row_sels[$i])  : ''; // Sanitizes the select value
								$mode = isset($row_modes[$i]) ? sanitize_text_field((string)$row_modes[$i]) : 'value'; // Sanitizes the mode value or defaults to 'value'
								$cus  = isset($row_custs[$i]) ? sanitize_key((string)$row_custs[$i])        : ''; // Sanitizes the custom value
								$resolved = ($mode === 'custom') ? $cus : sanitize_key($sel); // Chooses the custom or select value based on the mode
								if ($resolved !== '') {
										$final[] = $resolved; // Adds the resolved value if it's not empty
								}
						}
				}
				if (empty($final)) { // Checks if no values were resolved
						// Determine if there were any posted rows for this slug; if none were posted at all, // Describes what happens when no fields were posted
						// fall back to the existing values. Otherwise, return an empty array to allow // Clarifies fallback vs. clearing behavior
						// clearing all selections. // Notes why this logic exists
						$has_posted_rows = isset($_POST[$base_select_field][$slug_key])
								|| isset($_POST[$base_custom_field][$slug_key])
								|| isset($_POST[$base_mode_field][$slug_key]); // Determines whether any input arrays exist
						if (! $has_posted_rows) {
								// No posted values at all; keep the existing values
								return array_values(array_map('strval', (array)$existing_values)); // Returns existing values if nothing was posted
						}
						// There were rows submitted but all resolved to empty; return an empty array
						return []; // Returns an empty array to allow clearing
				}
				return array_values(array_unique($final)); // Returns the unique resolved values re-indexed
		}
}