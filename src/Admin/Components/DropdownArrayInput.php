<?php // Starts PHP mode
declare(strict_types=1); // Enables strict typing for this file

namespace BFR\Admin\Components; // Defines the namespace for this class

/** // Start of class-level DocBlock
 * Class DropdownArrayInput // Names the helper class
 *
 * This helper renders multi-row dropdown inputs and embeds the JavaScript // Describes overall purpose
 * needed to toggle custom input fields and add/remove rows.  Each call to // Notes that JS is included here
 * render() outputs the HTML for one multi-row control and injects the JS // Explains what render() does
 * the first time it’s called. // Clarifies that JS is only injected once
 */
final class DropdownArrayInput // Declares a final class that cannot be extended
{
		private DropdownProvider $dropdowns; // Holds a DropdownProvider instance for rendering select/custom controls

		/** // Static property DocBlock
		 * Tracks whether the JavaScript has been injected to avoid duplicates. // Explains why this flag exists
		 * @var bool // Declares the property type
		 */
		private static bool $scriptInjected = false; // Initializes the static flag to false

		/** // Constructor DocBlock
		 * Injects the DropdownProvider dependency. // Explains the purpose of the constructor argument
		 * @param DropdownProvider $dropdowns // Type-hints the dependency
		 */
		public function __construct(DropdownProvider $dropdowns)
		{
				$this->dropdowns = $dropdowns; // Stores the injected DropdownProvider
		}

		/** // Method DocBlock
		 * Renders a multi-row dropdown input and injects supporting JS once. // Summarizes this method’s purpose
		 *
		 * @param string               $base_select_name Name prefix for each select element (must include the slug) // Describes the first parameter
		 * @param string               $base_custom_name Name prefix for each custom input (must include the slug) // Describes the second parameter
		 * @param string               $base_mode_name   Name prefix for each hidden mode input (must include the slug) // Describes the third parameter
		 * @param array<string,string> $options          List of options for the select dropdown (value => label) // Describes the select options
		 * @param array<int,string>    $selected_values  Pre-selected values, one per row (empty to start with blank row) // Describes the selected values
		 * @param array<int,string>    $custom_values    Pre-selected custom values, aligned with $selected_values // Describes the custom values
		 * @param string|null          $data_post_type   Optional data attribute for post type identification // Describes the optional post type attribute
		 * @return string HTML // Declares the return type
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
				// If no selected values are supplied, start with one empty row
				if (empty($selected_values)) {
						$selected_values = ['']; // Ensures there is always at least one row
				}

				$html  = '<div class="bfr-metakeys-multi"'; // Begins the outer container with the required class
				if ($data_post_type !== null && $data_post_type !== '') { // Checks if a post type data attribute was provided
						$html .= ' data-post-type="' . esc_attr($data_post_type) . '"'; // Adds a data-post-type attribute for context
				}
				$html .= '>'; // Closes the opening div tag

				foreach ($selected_values as $i => $sel) { // Iterates over each preselected value to build rows
						$sel = (string)$sel; // Casts the selected value to a string
						$custom = (string)($custom_values[$i] ?? ''); // Retrieves the corresponding custom value or defaults to an empty string
						$select_name = $base_select_name . '[' . $i . ']'; // Constructs the name for the select element in this row
						$custom_name = $base_custom_name . '[' . $i . ']'; // Constructs the name for the custom input element in this row
						$mode_name   = $base_mode_name   . '[' . $i . ']'; // Constructs the name for the hidden mode field in this row

						$html .= '<div class="bfr-metakeys-row" style="margin-bottom:6px">'; // Opens a container for the row with a bottom margin
						$html .= $this->dropdowns->render_select_with_custom(
								$select_name,
								$custom_name,
								$mode_name,
								$options,
								$sel,
								$custom
						); // Delegates the rendering of the select and its custom input to DropdownProvider
						$html .= ' <button type="button" class="button bfr-remove-row" aria-label="Remove">–</button>'; // Adds a button to remove this row
						$html .= '</div>'; // Closes the row container
				}
				$html .= '<p><button type="button" class="button button-secondary bfr-add-row">Add key +</button></p>'; // Adds a button to duplicate the last row
				$html .= '</div>'; // Closes the outer container

				// Injects the JavaScript once per page to handle toggling custom inputs and adding/removing rows
				if (! self::$scriptInjected) { // Checks if the script has already been added
						$html .= "\n<script>\n"; // Opens the script tag
						$html .= "(function(){\n"; // Immediately-invoked function to avoid polluting the global scope
						$html .= "  function bindSelectWithCustom(wrapper){\n"; // Defines a helper that binds select/custom logic
						$html .= "    var sel = wrapper.querySelector(\"select\");\n"; // Grabs the select element within the wrapper
						$html .= "    var txt = wrapper.querySelector(\"input[type=\\\"text\\\"]\");\n"; // Grabs the custom text input within the wrapper
						$html .= "    var modeName = wrapper.getAttribute(\"data-mode-name\");\n"; // Reads the name of the hidden mode input
						$html .= "    function update(){\n"; // Defines an update function to sync the controls
						$html .= "      if (!sel || !modeName) return;\n"; // If there is no select or mode name, do nothing
						$html .= "      var isCustom = sel.value === \"__custom__\";\n"; // Checks if the custom option is selected
						$html .= "      if (txt) txt.style.display = isCustom ? \"\" : \"none\";\n"; // Shows or hides the text input depending on selection
						$html .= "      var hidden = wrapper.querySelector(\"input[type=\\\"hidden\\\"][name=\\\"\" + modeName + \"\\\"]\");\n"; // Finds the hidden mode input
						$html .= "      if (hidden) hidden.value = isCustom ? \"custom\" : \"value\";\n"; // Updates the hidden input value to reflect the current mode
						$html .= "    }\n"; // Ends the update function definition
						$html .= "    if (sel) sel.addEventListener(\"change\", update);\n"; // Registers the update function to run when the select changes
						$html .= "    update();\n"; // Calls update immediately to set the initial state
						$html .= "  }\n"; // Ends the bindSelectWithCustom helper
						$html .= "  document.querySelectorAll(\".bfr-select-with-custom\").forEach(bindSelectWithCustom);\n"; // Binds all existing select-with-custom wrappers on the page
						$html .= "  document.querySelectorAll(\".bfr-metakeys-multi\").forEach(function(block){\n"; // Iterates over each multi-row block
						$html .= "    function rebindRow(row){\n"; // Defines a helper to bind events on a newly added row
						$html .= "      row.querySelectorAll(\".bfr-select-with-custom\").forEach(bindSelectWithCustom);\n"; // Binds select/custom logic for each control in the row
						$html .= "      var rem = row.querySelector(\".bfr-remove-row\");\n"; // Finds the remove button in the row
						$html .= "      if (rem) rem.addEventListener(\"click\", function(){\n"; // If found, attaches a click handler
						$html .= "        var rows = block.querySelectorAll(\".bfr-metakeys-row\");\n"; // Counts all current rows
						$html .= "        if (rows.length > 1) row.remove();\n"; // Removes the row only if more than one remain
						$html .= "      });\n"; // Ends the click handler
						$html .= "    }\n"; // Ends rebindRow helper
						$html .= "    block.querySelectorAll(\".bfr-metakeys-row\").forEach(rebindRow);\n"; // Binds all initial rows
						$html .= "    var addBtn = block.querySelector(\".bfr-add-row\");\n"; // Finds the add-row button within the block
						$html .= "    if (addBtn) addBtn.addEventListener(\"click\", function(){\n"; // If found, attaches a click handler
						$html .= "      var rows = block.querySelectorAll(\".bfr-metakeys-row\");\n"; // Gets all current rows
						$html .= "      var last = rows[rows.length - 1];\n"; // Selects the last row
						$html .= "      if (!last) return;\n"; // If no rows exist, abort
						$html .= "      var clone = last.cloneNode(true);\n"; // Deep-clones the last row
						$html .= "      var sel2 = clone.querySelector(\"select\"); if (sel2) sel2.value = \"\";\n"; // Resets the select in the clone
						$html .= "      var txt2 = clone.querySelector(\"input[type=\\\"text\\\"]\"); if (txt2){ txt2.value = \"\"; txt2.style.display = \"none\"; }\n"; // Resets and hides the text input in the clone
						$html .= "      var hid2 = clone.querySelector(\"input[type=\\\"hidden\\\"]\"); if (hid2) hid2.value = \"value\";\n"; // Resets the hidden mode field in the clone
						$html .= "      addBtn.parentNode.parentNode.insertBefore(clone, addBtn.parentNode);\n"; // Inserts the clone before the add button's container
						$html .= "      rebindRow(clone);\n"; // Binds the appropriate events on the cloned row
						$html .= "    });\n"; // Ends addBtn click handler
						$html .= "  });\n"; // Ends forEach for each block
						$html .= "})();\n"; // Immediately invokes the function to set up event handlers
						$html .= "</script>\n"; // Closes the script tag
						self::$scriptInjected = true; // Marks the script as injected so it won't be added again
				}

				return $html; // Returns the complete HTML including the script (on first call)
		}
}