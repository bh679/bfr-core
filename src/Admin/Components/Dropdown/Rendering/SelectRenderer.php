<?php
declare(strict_types=1);	// Enforce strict typing

namespace BFR\Admin\Components\Dropdown\Rendering;	// Rendering namespace

/**
 * SelectRenderer
 *
 * Renders a <select> with options, PLUS an optional "Custom…" input that
 * shows when the special value "__custom__" is selected. Also includes a
 * hidden "mode" input so saving code is reliable:
 *  - "value"  => user picked a predefined option
 *  - "custom" => user entered a custom text
 */
final class SelectRenderer
{
	/** @var string */
	private string $customSentinel = '__custom__';		// Sentinel value for custom

	/** @var string */
	private string $customLabel    = 'Custom…';			// Label for custom option

	/**
	 * Render the composite control.
	 *
	 * @param string					$nameSelect		Name for <select>
	 * @param string|null				$currentValue	Current selected value (may be null)
	 * @param array<string,string>		$options		Map value => label
	 * @param array<string,string>		$attrsSelect	Extra attributes for <select> (e.g., ['id'=>'foo','onchange'=>'...'])
	 * @param string|null				$nameCustom		Name for text input (set null to disable custom UI)
	 * @param string|null				$currentCustom	Current custom text (if any)
	 * @param string|null				$nameMode		Name for hidden mode input (set null to disable)
	 * @return string									HTML markup
	 */
	public function render(
		string $nameSelect,
		?string $currentValue,
		array $options,
		array $attrsSelect = [],
		?string $nameCustom = null,
		?string $currentCustom = null,
		?string $nameMode = null
	): string {
		// Determine initial mode: 'custom' if value not in options and custom UI is enabled
		$useCustom = $nameCustom !== null && $currentValue !== null && !array_key_exists($currentValue, $options);

		// Build the select’s attributes
		$attrsSelect = $this->normalize_attrs($attrsSelect);			// Ensure strings
		$attrsSelect['name'] = $nameSelect;							// Set name
		$attrsHtml = $this->attrs_to_html($attrsSelect);			// Convert to HTML

		// Begin wrapper span for easy JS targeting
		$html = '<span class="bfr-select-with-custom">';			// Wrapper element

		// Build the <select> element
		$html .= '<select ' . $attrsHtml . '>';						// Open select

		// Emit provided options
		foreach ($options as $val => $label) {						// Loop options
			$selected = (!$useCustom && $currentValue === $val) ? ' selected' : '';	// Selected?
			$html    .= '<option value="' . esc_attr((string)$val) . '"' . $selected . '>' . esc_html((string)$label) . '</option>';	// Option
		}

		// Append the "Custom…" sentinel if custom UI is enabled
		if ($nameCustom !== null) {									// If custom enabled
			$sel = $useCustom ? ' selected' : '';					// Selected for sentinel?
			$html .= '<option value="' . esc_attr($this->customSentinel) . '"' . $sel . '>' . esc_html($this->customLabel) . '</option>';	// Custom option
		}

		$html .= '</select>';										// Close select

		// If custom UI enabled, render the text input and hidden mode
		if ($nameCustom !== null) {									// If custom allowed
			$style = $useCustom ? '' : ' style="display:none"';		// Toggle visibility
			$html .= '<input type="text" class="regular-text bfr-custom" name="' . esc_attr($nameCustom) . '" value="' . esc_attr((string)($currentCustom ?? '')) . '"' . $style . ' />';	// Custom text
			if ($nameMode !== null) {								// If mode tracking requested
				$html .= '<input type="hidden" class="bfr-mode" name="' . esc_attr($nameMode) . '" value="' . esc_attr($useCustom ? 'custom' : 'value') . '" />';	// Hidden mode
			}
		}

		$html .= '</span>';											// Close wrapper

		// Inline minimal JS to toggle custom field on change
		$html .= $this->script_once();								// Inject once

		return $html;												// Return markup
	}

	/**
	 * Convert attributes array to HTML string.
	 *
	 * @param array<string,string> $attrs	Attributes map
	 * @return string						HTML attributes
	 */
	private function attrs_to_html(array $attrs): string
	{
		$parts = [];												// Accumulator
		foreach ($attrs as $k => $v) {								// Loop pairs
			$parts[] = esc_attr((string)$k) . '="' . esc_attr((string)$v) . '"';	// k="v"
		}
		return implode(' ', $parts);								// Join by spaces
	}

	/**
	 * Ensure attribute values are strings.
	 *
	 * @param array<string,mixed> $attrs	Input attributes
	 * @return array<string,string>			Normalized attributes
	 */
	private function normalize_attrs(array $attrs): array
	{
		$out = [];													// Normalized map
		foreach ($attrs as $k => $v) {								// Loop pairs
			$out[(string)$k] = (string)$v;							// Cast to strings
		}
		return $out;												// Return normalized
	}

	/**
	 * Emit the small toggle script, once per page.
	 *
	 * @return string	Inline <script> (empty if already printed)
	 */
	private function script_once(): string
	{
		static $done = false;										// Static guard
		if ($done) {												// If already emitted
			return '';												// No-op
		}
		$done = true;												// Mark emitted

		// Vanilla JS: toggles custom input + mode based on sentinel selection
		return '<script>
document.addEventListener("change", function(e){
	const select = e.target;
	if (!(select instanceof HTMLSelectElement)) return;
	if (!select.closest(".bfr-select-with-custom")) return;
	const wrap   = select.closest(".bfr-select-with-custom");
	const custom = wrap.querySelector(".bfr-custom");
	const mode   = wrap.querySelector(".bfr-mode");
	const isCustom = (select.value === "__custom__");
	if (custom) { custom.style.display = isCustom ? "" : "none"; }
	if (mode)   { mode.value = isCustom ? "custom" : "value"; }
});
</script>';														// Return script
	}
}