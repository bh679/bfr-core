<?php
declare(strict_types=1);	// Enforce strict typing

namespace BFR\Admin\Components\Dropdown\Controls;	// Controls namespace

use BFR\Admin\Components\Dropdown\OptionProviderInterface;			// Import interface
use BFR\Admin\Components\Dropdown\Rendering\SelectRenderer;			// Import renderer

/**
 * ArrayDropdown
 *
 * Extends SingleDropdown to manage an array of rows, each row containing
 * (select [+ "Custom…"] + hidden mode) and +/- controls.
 * It supports removing the last remaining row (leaving an empty array).
 */
final class ArrayDropdown extends SingleDropdown
{
	/** @var string */
	private string $rowClass = 'bfr-array-row';			// CSS hook for rows

	/** @var string */
	private string $wrapClass = 'bfr-array-wrap';		// CSS hook for wrapper

	/**
	 * Render an array dropdown set.
	 *
	 * @param string               $baseName       	Base name (e.g., "input_meta_keys[slug]")
	 * @param list<string|null>    $currentValues  	Array of selected values (nulls allowed)
	 * @param array<string,mixed>  $context        	Context for options provider
	 * @param array<string,string> $attrsSelect    	Attributes for each select
	 * @param string               $customBaseName 	Base for custom text names (e.g., "..._custom[slug]")
	 * @param list<string|null>    $currentCustoms 	Array of custom texts aligned with $currentValues
	 * @param string               $modeBaseName   	Base for hidden modes (e.g., "..._mode[slug]")
	 * @return string                               	HTML block
	 */
	public function render_array(
		string $baseName,
		array $currentValues,
		array $context = [],
		array $attrsSelect = [],
		string $customBaseName = '',
		array $currentCustoms = [],
		string $modeBaseName = ''
	): string {
		// Ensure we have at least one visible row (even if no values)
		if (empty($currentValues)) {						// If no rows provided
			$currentValues = [null];						// Seed single row
		}

		// Produce options once (same for all rows)
		$options = $this->provider->get_options($context);	// Fetch options

		// Open wrapper with data-name templates for JS cloning
		$html  = '<div class="' . esc_attr($this->wrapClass) . '" ';	// Wrapper start
		$html .= 'data-name="' . esc_attr($baseName) . '" ';			// Data: base name
		$html .= 'data-name-custom="' . esc_attr($customBaseName) . '" ';	// Data: custom base
		$html .= 'data-name-mode="' . esc_attr($modeBaseName) . '">';		// Data: mode base

		// Render each row
		foreach (array_values($currentValues) as $i => $val) {			// Loop rows
			$selectName = $baseName . '[' . $i . ']';						// Name for select
			$customName = $customBaseName !== '' ? $customBaseName . '[' . $i . ']' : null;	// Name for custom
			$modeName   = $modeBaseName   !== '' ? $modeBaseName   . '[' . $i . ']' : null;	// Name for mode
			$customTxt  = $currentCustoms[$i] ?? null;						// Current custom text

			$html .= '<div class="' . esc_attr($this->rowClass) . '">';		// Row start

			// Row UI: select+custom+mode
			$html .= $this->renderer->render(								// Render composite
				$selectName,												// Select name
				$val === null ? null : (string)$val,						// Selected value
				$options,													// Options map
				$attrsSelect,												// Attributes
				$customName,												// Custom name
				$customTxt === null ? null : (string)$customTxt,			// Custom text
				$modeName													// Mode name
			);

			// +/- controls
			$html .= ' <button type="button" class="button bfr-array-add" aria-label="Add">+</button>';	// Add button
			$html .= ' <button type="button" class="button bfr-array-remove" aria-label="Remove">−</button>';	// Remove button

			$html .= '</div>';												// Row end
		}

		$html .= '</div>';													// Wrapper end

		// JS to manage add/remove (including allowing empty array)
		$html .= $this->script_once();										// Inject once

		return $html;														// Return markup
	}

	/**
	 * Small JS block; emitted once per page.
	 *
	 * @return string	Inline script
	 */
	private function script_once(): string
	{
		static $done = false;												// Guard
		if ($done) { return ''; }											// No-op on repeat
		$done = true;														// Mark emitted

		// Vanilla JS for cloning rows, renaming indices, and removing last row
		return '<script>
(function(){
	function reindexRows(wrap){
		const rows = Array.from(wrap.querySelectorAll("."+wrap.dataset.rowClass));	// (not used; kept for extension)
		// Intentionally simple; we rebuild names at clone-time instead
	}
	function makeRowHTML(wrap){
		const last = wrap.querySelector(".' . $this->rowClass . ':last-child");		// Grab last row
		const clone = last.cloneNode(true);											// Deep clone
		// Clear values
		const select = clone.querySelector("select");									// Find select
		if (select){ select.value = ""; }												// Reset select
		const custom = clone.querySelector(".bfr-custom");							// Find custom
		if (custom){ custom.value=""; custom.style.display="none"; }					// Hide custom
		const mode = clone.querySelector(".bfr-mode");									// Find mode
		if (mode){ mode.value = "value"; }												// Reset mode

		// Compute new index
		const rows = wrap.querySelectorAll(".' . $this->rowClass . '");				// All rows
		const idx  = rows.length;														// Next index

		// Rewrite names on select/custom/mode
		const base = wrap.dataset.name;													// Base select name
		const baseC= wrap.dataset.nameCustom || "";										// Base custom name
		const baseM= wrap.dataset.nameMode   || "";										// Base mode name

		if (select){ select.name = base + "["+idx+"]"; }								// New select name
		if (custom && baseC){ custom.name = baseC + "["+idx+"]"; }						// New custom name
		if (mode   && baseM){   mode.name   = baseM + "["+idx+"]"; }					// New mode name

		return clone;																	// Return prepared clone
	}

	document.addEventListener("click", function(e){
		const btn = e.target;															// Clicked element
		if (!(btn instanceof HTMLButtonElement)) return;								// Only buttons
		if (!btn.closest(".' . $this->wrapClass . '")) return;							// Only inside wraps
		const wrap = btn.closest(".' . $this->wrapClass . '");							// Find wrapper

		if (btn.classList.contains("bfr-array-add")){									// Handle add
			const newRow = makeRowHTML(wrap);											// Prepare row
			wrap.appendChild(newRow);													// Append
			return;																		// Done
		}
		if (btn.classList.contains("bfr-array-remove")){								// Handle remove
			const rows = wrap.querySelectorAll(".' . $this->rowClass . '");			// All rows
			if (rows.length > 1){														// If more than 1 row
				btn.closest(".' . $this->rowClass . '").remove();						// Remove row
			} else {
				// Allow removing the last row: leave an empty array (no fields submitted)
				btn.closest(".' . $this->rowClass . '").remove();						// Remove only row
				// Optionally, you could append a hidden marker if your save logic needs it.
				// Keeping it empty is usually fine: PHP sees missing indexes as empty array.
			}
			return;																		// Done
		}
	});
})();
</script>';
	}
}