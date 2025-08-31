<?php
declare(strict_types=1);

namespace BFR\Admin\Components\Dropdown\Rendering;

/**
 * SelectRenderer
 * - Single place to output a <select> with "Custom…" text input and hidden "mode".
 * - Optional preview block can be toggled via args to keep parity with your existing UX.
 */
final class SelectRenderer {
	/**
	 * Render a select + optional custom field + hidden mode + optional preview.
	 * @param string $name			// Name attribute for <select>
	 * @param array<string,string> $options	// Options value=>label
	 * @param string|null $value		// Current saved value
	 * @param array<string,mixed> $args		// id, class, custom_name, mode_name, placeholder, show_preview, preview_post_selector, preview_label
	 * @return string				// HTML string
	 */
	public function render(string $name, array $options, ?string $value, array $args = []): string {
		// Resolve stable ID.
		$id = isset($args['id']) ? (string) $args['id'] : $this->id_from_name($name);
		// Determine if value is custom.
		$is_custom  = ($value !== null && !array_key_exists($value, $options));
		// Pick the select's shown value (sentinel if custom).
		$select_val = $is_custom ? '__custom__' : (string) ($value ?? '');
		// Resolve helpers.
		$custom_name  = $args['custom_name']  ?? ($name . '_custom');
		$mode_name    = $args['mode_name']    ?? ($name . '_mode');
		$placeholder  = $args['placeholder']  ?? 'Enter custom value…';
		$select_class = $args['class']        ?? 'regular-text';
		$show_preview = (bool)($args['show_preview'] ?? false);

		// Start wrapper span with a data scope for JS.
		$html  = '<span class="bfr-select" data-bfr-scope="' . esc_attr($id) . '">';

		// Open the select.
		$html .= sprintf('<select id="%s" name="%s" class="%s" data-bfr-select="1">', esc_attr($id), esc_attr($name), esc_attr($select_class));
		// Optional empty option.
		$html .= '<option value="">' . esc_html__('— Select —', 'bfr-core') . '</option>';
		// Emit options.
		foreach ($options as $opt_value => $label) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr((string) $opt_value),
				selected($select_val, (string) $opt_value, false),
				esc_html((string) $label)
			);
		}
		// Close select.
		$html .= '</select> ';

		// Custom input visibility toggles with sentinel.
		$custom_style = $is_custom ? '' : 'style="display:none"';
		$custom_val   = $is_custom ? (string) $value : '';
		$html .= sprintf(
			'<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" data-bfr-custom="1" %s/>',
			esc_attr($custom_name),
			esc_attr($custom_val),
			esc_attr($placeholder),
			$custom_style
		);

		// Hidden mode input so saves are deterministic.
		$mode_value = $is_custom ? 'custom' : 'select';
		$html .= sprintf('<input type="hidden" name="%s" value="%s" data-bfr-mode="1"/>', esc_attr($mode_name), esc_attr($mode_value));

		// Optional small preview slot (kept generic; your page JS can hydrate it).
		if ($show_preview) {
			$html .= '<div class="bfr-preview" style="margin-top:6px;"><code id="' . esc_attr($id) . '-preview-value">—</code></div>';
		}

		// Close wrapper span.
		$html .= '</span>';

		// Return final HTML.
		return $html;
	}

	/**
	 * Create a stable ID from a name like "registry[target_meta_key]".
	 * @param string $name
	 * @return string
	 */
	private function id_from_name(string $name): string {
		// Replace brackets with hyphens for safe IDs.
		$id = preg_replace('/\[+/', '-', $name);
		// Remove closing brackets.
		$id = preg_replace('/\]+/', '', (string) $id);
		// Collapse unsafe chars to hyphens.
		$id = preg_replace('/[^a-zA-Z0-9\-_:.]/', '-', (string) $id);
		// Trim hyphens from ends.
		return trim((string) $id, '-');
	}
}