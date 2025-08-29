<?php // Begin PHP execution
declare(strict_types=1); // Enforce strict typing

namespace BFR\Admin\Components; // Define namespace for the helper

/** // Class documentation
 * Class DropdownArrayInput
 *
 * Renders multi-row dropdown inputs with a custom-value option, and // Summarize purpose
 * injects the necessary JavaScript once per page to handle custom // Notes the JS injection
 * toggles and add/remove row logic. // Describes additional behavior
 */
final class DropdownArrayInput // Declare a final class so it cannot be extended
{
		private DropdownProvider $dropdowns; // Holds the dropdown rendering dependency

		/** // Static property documentation
		 * Indicates if the JavaScript has already been printed. // Prevents duplicate scripts
		 * @var bool
		 */
		private static bool $scriptInjected = false; // Initialize the static flag

		/** // Constructor documentation
		 * Accepts a DropdownProvider for rendering select/custom pairs. // Explain dependency injection
		 * @param DropdownProvider $dropdowns // Type hint for the constructor argument
		 */
		public function __construct(DropdownProvider $dropdowns)
		{
				$this->dropdowns = $dropdowns; // Store the provider for later use
		}

		/** // Method documentation
		 * Outputs HTML for a multi-row dropdown and embeds supporting JS once. // Describe what render() does
		 *
		 * @param string               $base_select_name Prefix for select input names (including slug) // Explain parameter 1
		 * @param string               $base_custom_name Prefix for custom input names (including slug) // Explain parameter 2
		 * @param string               $base_mode_name   Prefix for hidden mode names (including slug) // Explain parameter 3
		 * @param array<string,string> $options          Options for the select dropdown as value => label // Explain parameter 4
		 * @param array<int,string>    $selected_values  Preselected values for each row // Explain parameter 5
		 * @param array<int,string>    $custom_values    Preselected custom values aligned with rows // Explain parameter 6
		 * @param string|null          $data_post_type   Optional data attribute indicating post type // Explain parameter 7
		 * @return string HTML // Describe the return type
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
				// Ensure there is at least one row even if no selections are provided
				if (empty($selected_values)) {
						$selected_values = ['']; // Start with a single blank entry
				}

				$html  = '<div class="bfr-metakeys-multi"'; // Open the container element
				if ($data_post_type !== null && $data_post_type !== '') { // Check for a post type attribute
						$html .= ' data-post-type="' . esc_attr($data_post_type) . '"'; // Append the post type as a data attribute
				}
				$html .= '>'; // Close the opening tag

				foreach ($selected_values as $i => $sel) { // Loop through each provided selection
						$sel = (string)$sel; // Cast the selected value to a string
						$custom = (string)($custom_values[$i] ?? ''); // Retrieve the corresponding custom value or default to empty
						$select_name = $base_select_name . '[' . $i . ']'; // Construct the select name for this row
						$custom_name = $base_custom_name . '[' . $i . ']'; // Construct the custom input name for this row
						$mode_name   = $base_mode_name   . '[' . $i . ']'; // Construct the hidden mode input name for this row

						$html .= '<div class="bfr-metakeys-row" style="margin-bottom:6px">'; // Start a row container with bottom margin
						$html .= $this->dropdowns->render_select_with_custom(
								$select_name,
								$custom_name,
								$mode_name,
								$options,
								$sel,
								$custom
						); // Use the DropdownProvider to render the select and custom text input
						$html .= ' <button type="button" class="button bfr-remove-row" aria-label="Remove">–</button>'; // Add a remove-row button
						$html .= '</div>'; // Close the row container
				}
				$html .= '<p><button type="button" class="button button-secondary bfr-add-row">Add key +</button></p>'; // Add a button to duplicate the last row
				$html .= '</div>'; // Close the outer container

				// Inject the JavaScript once per page after all rows are rendered
				if (! self::$scriptInjected) { // Check if the script has not been output yet
						$html .= "\n<script>\n"; // Begin the script element
						// Wait for the DOM to be fully parsed before binding handlers to ensure all calculators are present
						$html .= "document.addEventListener(\"DOMContentLoaded\", function(){\n"; // Add a DOMContentLoaded listener
						$html .= "  function bindSelectWithCustom(wrapper){\n"; // Define a helper to bind select/custom interactions
						$html .= "    var sel = wrapper.querySelector(\"select\");\n"; // Find the select element in the wrapper
						$html .= "    var txt = wrapper.querySelector(\"input[type=\\\"text\\\"]\");\n"; // Find the custom text input in the wrapper
						$html .= "    var modeName = wrapper.getAttribute(\"data-mode-name\");\n"; // Get the name of the hidden mode input
						$html .= "    function update(){\n"; // Define an update function for toggling visibility
						$html .= "      if (!sel || !modeName) return;\n"; // If select or mode name is missing, exit early
						$html .= "      var isCustom = sel.value === \"__custom__\";\n"; // Determine if the custom option is selected
						$html .= "      if (txt) txt.style.display = isCustom ? \"\" : \"none\";\n"; // Show or hide the custom text input
						$html .= "      var hidden = wrapper.querySelector(\"input[type=\\\"hidden\\\"][name=\\\"\" + modeName + \"\\\"]\");\n"; // Find the hidden mode input
						$html .= "      if (hidden) hidden.value = isCustom ? \"custom\" : \"value\";\n"; // Set the hidden input to indicate the mode
						$html .= "    }\n"; // End of update function
						$html .= "    if (sel) sel.addEventListener(\"change\", update);\n"; // Attach the update function to the select’s change event
						$html .= "    update();\n"; // Run the update function immediately to initialize visibility
						$html .= "  }\n"; // End of bindSelectWithCustom helper
						$html .= "  document.querySelectorAll(\".bfr-select-with-custom\").forEach(bindSelectWithCustom);\n"; // Attach handlers to all select/custom wrappers
						$html .= "  document.querySelectorAll(\".bfr-metakeys-multi\").forEach(function(block){\n"; // Iterate over all multi-row blocks
						$html .= "    function rebindRow(row){\n"; // Define a helper to bind events for a single row
						$html .= "      row.querySelectorAll(\".bfr-select-with-custom\").forEach(bindSelectWithCustom);\n"; // Bind select/custom handling for the row
						$html .= "      var rem = row.querySelector(\".bfr-remove-row\");\n"; // Find the remove button in the row
						$html .= "      if (rem) rem.addEventListener(\"click\", function(){\n"; // Attach a click handler to the remove button
						$html .= "        var rows = block.querySelectorAll(\".bfr-metakeys-row\");\n"; // Count how many rows exist in the block
						$html .= "        if (rows.length > 1) row.remove();\n"; // Only remove the row if more than one remain
						$html .= "      });\n"; // End of remove button handler
						$html .= "    }\n"; // End of rebindRow helper
						$html .= "    block.querySelectorAll(\".bfr-metakeys-row\").forEach(rebindRow);\n"; // Bind events for all existing rows
						$html .= "    var addBtn = block.querySelector(\".bfr-add-row\");\n"; // Find the add-row button in the block
						$html .= "    if (addBtn) addBtn.addEventListener(\"click\", function(){\n"; // Attach a click handler to duplicate the last row
						$html .= "      var rows = block.querySelectorAll(\".bfr-metakeys-row\");\n"; // Retrieve all current rows
						$html .= "      var last = rows[rows.length - 1];\n"; // Identify the last row to clone
						$html .= "      if (!last) return;\n"; // If no rows exist, abort the duplication
						$html .= "      var clone = last.cloneNode(true);\n"; // Create a deep clone of the last row
						$html .= "      var sel2 = clone.querySelector(\"select\"); if (sel2) sel2.value = \"\";\n"; // Reset the select in the cloned row
						$html .= "      var txt2 = clone.querySelector(\"input[type=\\\"text\\\"]\"); if (txt2){ txt2.value = \"\"; txt2.style.display = \"none\"; }\n"; // Reset and hide the custom text input in the clone
						$html .= "      var hid2 = clone.querySelector(\"input[type=\\\"hidden\\\"]\"); if (hid2) hid2.value = \"value\";\n"; // Reset the hidden mode input in the clone
						$html .= "      addBtn.parentNode.parentNode.insertBefore(clone, addBtn.parentNode);\n"; // Insert the cloned row before the add button
						$html .= "      rebindRow(clone);\n"; // Bind events on the cloned row
						$html .= "    });\n"; // End of addBtn click handler
						$html .= "  });\n"; // End of forEach over multi-row blocks
						$html .= "});\n"; // Close the DOMContentLoaded listener
						$html .= "</script>\n"; // Close the script tag
						self::$scriptInjected = true; // Mark the script as injected so it’s not added again
				}

				return $html; // Return the complete HTML with script (on first call)
		}
}