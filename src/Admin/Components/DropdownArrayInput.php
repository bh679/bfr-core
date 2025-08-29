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
		/**
		 * Render the calculators table.
		 * Columns: Calculator (name + slug + description), Target Meta Key, Input Meta Keys
		 *
		 * @return string HTML
		 */
		public function render(): string
		{
				ob_start(); // Starts output buffering so that the generated HTML can be returned as a string

				echo '<h2>Calculators</h2>'; // Outputs a heading for the table
				echo '<table class="widefat fixed striped">'; // Starts the table with WordPress admin styles
				echo '<thead><tr>'; // Begins the table header row
				echo '<th style="width:30%">Calculator</th>'; // Header for the calculator name/slug column
				echo '<th style="width:30%">Target Meta Key</th>'; // Header for the target meta key column
				echo '<th style="width:40%">Input Meta Keys</th>'; // Header for the input meta keys column
				echo '</tr></thead><tbody>'; // Closes header and opens table body

				foreach ($this->registry as $slug => $cfg) { // Loops over each calculator in the registry
						$slug     = (string)$slug; // Ensures the slug is a string
						$name     = (string)($cfg['name'] ?? $slug); // Gets the name or falls back to slug
						$desc     = (string)($cfg['description'] ?? ''); // Gets the description or empty string
						$tmeta    = (string)($cfg['target_meta_key'] ?? ''); // Gets the configured target meta key
						$imeta    = array_map(static fn($v) => (string)$v, (array)($cfg['input_meta_keys'] ?? [])); // Casts each input meta key to string

						echo '<tr>'; // Starts a new table row

						// Column 1: Name + slug + description
						echo '<td>'; // Opens the first column cell
						echo '<strong>'.esc_html($name).'</strong><br/><code>'.esc_html($slug).'</code>'; // Prints the calculator name and slug
						if ($desc !== '') { // Checks if there is a description
								echo '<p style="margin:6px 0 0;color:#555;">'.esc_html($desc).'</p>'; // Prints the description in a paragraph with styling
						}
						echo '</td>'; // Closes the first column cell

						// Column 2: Target Meta Key selector
						echo '<td>'; // Opens the second column cell
						echo $this->inputs->render_target_meta_key($slug, $tmeta, ''); // Renders the target meta key selector for this calculator
						echo '<p class="description">Pick a known key or choose <em>Custom…</em> and type your own.</p>'; // Prints a helpful description under the field
						echo '</td>'; // Closes the second column cell

						// Column 3: Input Meta Keys (multi)
						echo '<td>'; // Opens the third column cell
						echo $this->inputs->render_input_meta_keys($slug, $imeta, []); // Renders the multi-row input meta keys selector
						echo '<p class="description">Add as many input keys as needed. Each can be a known key or <em>Custom…</em>.</p>'; // Prints a helpful description
						echo '</td>'; // Closes the third column cell

						echo '</tr>'; // Ends the current table row
				}

				echo '</tbody></table>'; // Closes the table body and table

				// Minimal JS (toggle custom + add/remove rows)
				?>
				<script>
				(function(){
					// Bind behaviour to select-with-custom wrappers so that the custom text box shows/hides properly
					function bindSelectWithCustom(wrapper){
						var sel = wrapper.querySelector('select'); // Find the select element inside the wrapper
						var txt = wrapper.querySelector('input[type="text"]'); // Find the custom text input
						var modeName = wrapper.getAttribute('data-mode-name'); // Read the name of the hidden mode field
						function update(){
							if (!sel || !modeName) return; // Exit if select or mode name is missing
							var isCustom = sel.value === '__custom__'; // Check if the user chose the custom option
							if (txt) txt.style.display = isCustom ? '' : 'none'; // Show/hide the custom text box based on selection
							var hidden = wrapper.querySelector('input[type="hidden"][name="'+modeName+'"]'); // Find the hidden mode input
							if (hidden) hidden.value = isCustom ? 'custom' : 'value'; // Set hidden value to reflect whether the row is custom or predefined
						}
						if (sel) sel.addEventListener('change', update); // Trigger update when the select changes
						update(); // Initialize the display on page load
					}
					document.querySelectorAll('.bfr-select-with-custom').forEach(bindSelectWithCustom); // Apply binding to all select-with-custom wrappers on the page

					document.querySelectorAll('.bfr-metakeys-multi').forEach(function(block){
						// For each multi-key container, this function binds remove and add row logic
						function rebindRow(row){
							row.querySelectorAll('.bfr-select-with-custom').forEach(bindSelectWithCustom); // Bind select/custom behaviour in the row
							var rem = row.querySelector('.bfr-remove-row'); // Find the remove button in the row
							if (rem) rem.addEventListener('click', function(){
								var rows = block.querySelectorAll('.bfr-metakeys-row'); // Get all rows in this block
								if (rows.length > 1) {
									// If there are multiple rows, simply remove this row
									row.remove();
								} else {
									// When removing the last remaining row, clear its fields and hide it
									var sel = row.querySelector('select');
									if (sel) sel.value = ''; // Clear the select field
									var txt = row.querySelector('input[type="text"]');
									if (txt) { txt.value = ''; txt.style.display = 'none'; } // Clear and hide the custom text input
									var hid = row.querySelector('input[type="hidden"]');
									if (hid) hid.value = 'value'; // Reset the hidden mode input to the default mode
									row.style.display = 'none'; // Hide the row completely so the dropdown disappears
								}
							});
						}
						block.querySelectorAll('.bfr-metakeys-row').forEach(rebindRow); // Bind existing rows initially
						var addBtn = block.querySelector('.bfr-add-row'); // Find the add-row button within the block
						if (addBtn) addBtn.addEventListener('click', function(){
							var rows = block.querySelectorAll('.bfr-metakeys-row'); // Get all rows in the block
							var last = rows[rows.length - 1]; // Identify the last row (even if hidden)
							if (!last) return; // If there is no template row, do nothing
							var clone = last.cloneNode(true); // Clone the last row including its children
							var sel = clone.querySelector('select'); if (sel) sel.value = ''; // Clear the select value in the clone
							var txt = clone.querySelector('input[type="text"]'); if (txt){ txt.value = ''; txt.style.display = 'none'; } // Clear and hide the custom text in the clone
							var hid = clone.querySelector('input[type="hidden"]'); if (hid) hid.value = 'value'; // Reset the hidden mode input in the clone
							// Ensure the new row is visible even if the last row was hidden
							clone.style.display = ''; // Show the clone in case the original was hidden
							addBtn.parentNode.parentNode.insertBefore(clone, addBtn.parentNode); // Insert the clone before the add button row
							rebindRow(clone); // Bind select/custom behaviour and remove functionality to the clone
						});
					});
				})(); // Immediately invoke the function to set up the UI behaviour
				</script>
				<?php

				return (string)ob_get_clean(); // Returns the buffered output as a string
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
				: []; // Read select values from $_POST
			$row_modes = isset($_POST[$base_mode_field][$slug_key]) && is_array($_POST[$base_mode_field][$slug_key])
				? (array)$_POST[$base_mode_field][$slug_key]
				: []; // Read mode values from $_POST
			$row_custs = isset($_POST[$base_custom_field][$slug_key]) && is_array($_POST[$base_custom_field][$slug_key])
				? (array)$_POST[$base_custom_field][$slug_key]
				: []; // Read custom values from $_POST

			$final = []; // Collect resolved values
			if ($row_sels) { // Process each row if any were posted
				$max = max(array_keys($row_sels)); // Determine last index
				for ($i = 0; $i <= $max; $i++) { // Loop through each row index
					$sel  = isset($row_sels[$i])  ? sanitize_text_field((string)$row_sels[$i])  : ''; // Sanitize select value
					$mode = isset($row_modes[$i]) ? sanitize_text_field((string)$row_modes[$i]) : 'value'; // Sanitize mode
					$cus  = isset($row_custs[$i]) ? sanitize_key((string)$row_custs[$i])        : ''; // Sanitize custom value
					$resolved = ($mode === 'custom') ? $cus : sanitize_key($sel); // Use custom value if mode is custom
					if ($resolved !== '') {
						$final[] = $resolved; // Keep only non-empty values
					}
				}
			}
			// Return the unique set of resolved values without falling back to old values,
			// allowing an empty array to indicate that all items have been removed
			return array_values(array_unique($final));
		}
}