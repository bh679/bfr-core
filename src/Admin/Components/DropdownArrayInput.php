<?php // Start PHP execution for this file
declare(strict_types=1); // Enforce strict type checking for safer code

namespace BFR\Admin\Components; // Define the namespace for this class

/** // Begin a docblock explaining the purpose of this class
 * Class DropdownArrayInput // Define the helper class name
 *
 * A helper for rendering and resolving multi-row dropdown inputs that // Explain the main purpose of the class
 * populate an array of values. Each row consists of a select box backed // Describe the structure of each row
 * by a provided set of options plus a “Custom…” option to allow free // Note that custom text values are supported
 * text entry. A hidden input tracks whether the row represents a // Mention the hidden input used to determine value type
 * predefined value or a custom value. // Explain what the hidden input stores
 *
 * This class delegates the actual select + custom field rendering to // Specify that rendering uses another provider
 * {@see DropdownProvider::render_select_with_custom()}, and handles // Reference the rendering helper method used
 * repeating rows, the add/remove UI, and parsing posted values back // List other responsibilities like repeating rows and parsing input
 * into a clean array. The generated markup mirrors the existing // Emphasize compatibility with existing markup/JS
 * meta‑keys UI, ensuring the existing JavaScript that binds add/remove // Explain why the existing JS will still function correctly
 * behaviour continues to work without changes. // End of class description
 */
final class DropdownArrayInput // Declare a final class that cannot be extended
{
	private DropdownProvider $dropdowns; // Hold a reference to the injected DropdownProvider

	public function __construct(DropdownProvider $dropdowns) // Constructor receiving a DropdownProvider
	{
		$this->dropdowns = $dropdowns; // Store the provided DropdownProvider for later use
	}

	/** // Begin a docblock for the render method
	 * Render a multi‑row dropdown input. // Describe what this method does
	 *
	 * Given base field names for the select, custom text input and mode // Explain the parameters expected
	 * hidden input, this will emit a container with one row per selected // Describe the resulting HTML structure
	 * value. Each row contains a select/custom pair and a remove button. // List the contents of each row
	 * At the end of the container an “Add key +” button is rendered to // Mention the Add button at the end
	 * duplicate the last row when clicked (handled by existing JS). // Clarify that the JS handles cloning on click
	 *
	 * @param string               $base_select_name Base name of the select field (include slug in square brackets) // Document base name for selects
	 * @param string               $base_custom_name Base name of the custom text field (include slug) // Document base name for custom inputs
	 * @param string               $base_mode_name   Base name of the mode hidden field (include slug) // Document base name for mode inputs
	 * @param array<string,string> $options          Map of value => label used to populate the select // List of selectable options
	 * @param array<int,string>    $selected_values  Preselected values (can be empty to hide all rows) // Array of preselected values
	 * @param array<int,string>    $custom_values    Preselected custom values aligned by index // Array of preselected custom values
	 * @param string|null          $data_post_type   Optional post type slug to emit as data‑post‑type attribute // Optional data attribute for JS
	 * @return string HTML // Return type declaration
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
		// Do not force an initial row when there are no selected values. // Clarify that an empty array will produce no rows
		// If no values are provided, the dropdown will not appear until the user adds one. // Ensure the dropdown stays hidden until triggered

		$html  = '<div class="bfr-metakeys-multi"'; // Start the outer container with a CSS class
		if ($data_post_type !== null && $data_post_type !== '') {
			$html .= ' data-post-type="' . esc_attr($data_post_type) . '"'; // Append a data attribute when provided
		}
		$html .= '>'; // Close the opening tag for the container

		foreach ($selected_values as $i => $sel) { // Loop through each selected value to create a row
			$sel = (string)$sel; // Cast the selection to a string for consistent processing
			$custom = (string)($custom_values[$i] ?? ''); // Retrieve the aligned custom value or default to an empty string
			$select_name = $base_select_name . '[' . $i . ']'; // Compose the full name attribute for the select field
			$custom_name = $base_custom_name . '[' . $i . ']'; // Compose the full name attribute for the custom input field
			$mode_name   = $base_mode_name   . '[' . $i . ']'; // Compose the full name attribute for the mode hidden input

			$html .= '<div class="bfr-metakeys-row" style="margin-bottom:6px">'; // Open a row container with margin styling
			$html .= $this->dropdowns->render_select_with_custom(
				$select_name,
				$custom_name,
				$mode_name,
				$options,
				$sel,
				$custom
			); // Delegate rendering of the select/custom pair to the DropdownProvider
			$html .= ' <button type="button" class="button bfr-remove-row" aria-label="Remove">–</button>'; // Append a remove button to the row
			$html .= '</div>'; // Close the row container
		}
		$html .= '<p><button type="button" class="button button-secondary bfr-add-row">Add key +</button></p>'; // Always add an "Add key +" button at the end
		$html .= '</div>'; // Close the outer container
		return $html; // Return the completed HTML
	}

	/** // Begin a docblock for the parse_post method
	 * Parse posted values from a multi‑row dropdown input back into an array. // Describe the method's responsibility
	 *
	 * This examines the $_POST superglobal for keys of the form // Note that values are read from the POST array
	 *    $_POST[$base_select_field][$slug][$i] // Specifies the path for select values
	 *    $_POST[$base_custom_field][$slug][$i] // Specifies the path for custom values
	 *    $_POST[$base_mode_field][$slug][$i] // Specifies the path for mode values
	 * and resolves each row to either the selected value (sanitized) or // Explain how rows are resolved
	 * the custom value depending on the mode. Empty rows are skipped. // Note that empty rows are ignored
	 * If the result would be empty, the provided $existing_values are // Explain fallback behavior
	 * preserved instead. // End of explanation
	 *
	 * @param string          $base_select_field Root key for the select values (e.g. 'input_meta_keys') // Document select root key
	 * @param string          $base_custom_field Root key for the custom values (e.g. 'input_meta_keys_custom') // Document custom root key
	 * @param string          $base_mode_field   Root key for the mode values (e.g. 'input_meta_keys_mode') // Document mode root key
	 * @param string          $slug_key          Sanitized slug used as subkey in $_POST arrays // Document slug key
	 * @param array<int,mixed> $existing_values  Existing array of values (used if no new values provided) // Document existing values fallback
	 * @return array<int,string> Resolved array of strings (unique, indexed) // Declare the return type
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
			: []; // Retrieve all posted select values for this slug or use an empty array
		$row_modes = isset($_POST[$base_mode_field][$slug_key]) && is_array($_POST[$base_mode_field][$slug_key])
			? (array)$_POST[$base_mode_field][$slug_key]
			: []; // Retrieve all posted mode values for this slug or use an empty array
		$row_custs = isset($_POST[$base_custom_field][$slug_key]) && is_array($_POST[$base_custom_field][$slug_key])
			? (array)$_POST[$base_custom_field][$slug_key]
			: []; // Retrieve all posted custom values for this slug or use an empty array

		$final = []; // Initialize the result array
		if ($row_sels) { // Proceed only if there are select values
			$max = max(array_keys($row_sels)); // Determine the highest index in the select array
			for ($i = 0; $i <= $max; $i++) { // Iterate through all possible indexes
				$sel  = isset($row_sels[$i])  ? sanitize_text_field((string)$row_sels[$i])  : ''; // Sanitize the select value or default to an empty string
				$mode = isset($row_modes[$i]) ? sanitize_text_field((string)$row_modes[$i]) : 'value'; // Sanitize the mode value or default to 'value'
				$cus  = isset($row_custs[$i]) ? sanitize_key((string)$row_custs[$i])        : ''; // Sanitize the custom value or default to an empty string
				$resolved = ($mode === 'custom') ? $cus : sanitize_key($sel); // Choose the custom value if mode is 'custom', otherwise choose the sanitized select value
				if ($resolved !== '') {
					$final[] = $resolved; // Add the resolved value to the final array if not empty
				}
			}
		}
		// Always return a unique, re-indexed array of resolved values. This may be empty when all
		// rows have been removed, allowing callers to persist an empty array. // Explain the return policy
		return array_values(array_unique($final)); // Return the final array with duplicate values removed and indexes reset
	}
}