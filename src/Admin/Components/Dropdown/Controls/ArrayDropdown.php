<?php
declare(strict_types=1);

namespace BFR\Admin\Components\Dropdown\Controls;

use BFR\Admin\Components\Dropdown\OptionProviderInterface;
use BFR\Admin\Components\Dropdown\Rendering\SelectRenderer;

/**
 * ArrayDropdown
 * - Repeatable dropdown list (adds/removes rows) using the same renderer per item.
 */
final class ArrayDropdown {
	/** @var OptionProviderInterface */
	private OptionProviderInterface $provider;
	/** @var SelectRenderer */
	private SelectRenderer $renderer;

	/**
	 * @param OptionProviderInterface $provider	// Options for each row
	 * @param SelectRenderer $renderer		// Shared renderer
	 */
	public function __construct(OptionProviderInterface $provider, SelectRenderer $renderer) {
		// Save provider instance.
		$this->provider = $provider;
		// Save renderer instance.
		$this->renderer = $renderer;
	}

	/**
	 * Render a repeatable dropdown array control.
	 * @param string $name_base		// Base name, e.g., 'registry[input_meta_keys]'
	 * @param array<int,string> $values	// Current values array (may be empty)
	 * @param array $context		// Provider context
	 * @param array $args		// Renderer args applied per item (id is auto-suffixed)
	 * @return string			// HTML block with add/remove UI
	 */
	public function render(string $name_base, array $values, array $context, array $args = []): string {
		// Get the options once for all rows.
		$options = $this->provider->get_options($context);

		// Wrapper.
		$html  = '<div class="bfr-array-dropdown" data-bfr-array>';
		// Iterate existing values or at least one empty slot.
		$rows = !empty($values) ? array_values($values) : [''];
		foreach ($rows as $idx => $val) {
			// Compose per-row name like registry[input_meta_keys][0]
			$row_name = $name_base . '[' . $idx . ']';
			// Compose per-row ID (unique).
			$row_args = $args;
			$row_args['id'] = ($args['id'] ?? $this->id_from_name($name_base)) . '-' . $idx;

			// Row container with remove button.
			$html .= '<div class="bfr-array-row" data-bfr-row>';
			// Render one select row via shared renderer.
			$html .= $this->renderer->render($row_name, $options, $val !== '' ? (string)$val : null, $row_args);
			// Add a remove button per row.
			$html .= ' <button type="button" class="button-link-delete" data-bfr-remove title="Remove">×</button>';
			// Close row.
			$html .= '</div>';
		}

		// Add button for new rows.
		$html .= '<p><button type="button" class="button" data-bfr-add>Add another</button></p>';
		// Close wrapper.
		$html .= '</div>';

		// Enqueue/print minimal JS hook (or enqueue a shared file).
		$html .= $this->inline_script();

		// Return block.
		return $html;
	}

	/**
	 * Minimal behavior: add/remove rows; allow removing last row to yield empty array.
	 * @return string
	 */
	private function inline_script(): string {
		// Keep very small here; you can move to an enqueued file later.
		$js = <<<HTML
<script>
(function(){
	// Delegate clicks within any .bfr-array-dropdown
	document.addEventListener('click', function(e){
		var addBtn = e.target.closest('[data-bfr-add]');
		var rmBtn  = e.target.closest('[data-bfr-remove]');
		if (!addBtn && !rmBtn) return;

		var wrap = (addBtn || rmBtn).closest('[data-bfr-array]');
		if (!wrap) return;

		// Add row flow.
		if (addBtn) {
			var rows = wrap.querySelectorAll('[data-bfr-row]');
			var idx  = rows.length;
			var tpl  = rows[rows.length - 1];		// clone last row as a template
			if (!tpl) return;
			var clone = tpl.cloneNode(true);		// deep clone
			// Clean values inside clone.
			clone.querySelectorAll('select, input[type="text"]').forEach(function(el){
				if (el.matches('select')) el.value = '';
				if (el.matches('input[type="text"]')) { el.value = ''; el.style.display = 'none'; }
			});
			clone.querySelectorAll('[data-bfr-mode]').forEach(function(el){ el.value = 'select'; });

			// Bump names/ids with new index.
			clone.querySelectorAll('[name]').forEach(function(el){
				el.name = el.name.replace(/\\[\\d+\\]/, '[' + idx + ']');
			});
			clone.querySelectorAll('[id]').forEach(function(el){
				el.id = el.id.replace(/-\\d+$/, '-' + idx);
			});
			// Append clone.
			wrap.querySelector('[data-bfr-add]').closest('p').insertAdjacentElement('beforebegin', clone);
			e.preventDefault();
			return;
		}

		// Remove row flow.
		if (rmBtn) {
			var row = rmBtn.closest('[data-bfr-row]');
			if (!row) return;

			var rows = wrap.querySelectorAll('[data-bfr-row]');
			if (rows.length === 1) {
				// If it's the last row, clear inputs instead of removing node.
				row.querySelectorAll('select').forEach(function(el){ el.value = ''; });
				row.querySelectorAll('[data-bfr-custom="1"]').forEach(function(el){ el.value = ''; el.style.display = 'none'; });
				row.querySelectorAll('[data-bfr-mode]').forEach(function(el){ el.value = 'select'; });
			} else {
				// Remove the row.
				row.remove();
				// Reindex remaining rows (names/ids).
				var newIdx = 0;
				wrap.querySelectorAll('[data-bfr-row]').forEach(function(r){
					r.querySelectorAll('[name]').forEach(function(el){
						el.name = el.name.replace(/\\[\\d+\\]/, '[' + newIdx + ']');
					});
					r.querySelectorAll('[id]').forEach(function(el){
						el.id = el.id.replace(/-\\d+$/, '-' + newIdx);
					});
					newIdx++;
				});
			}
			e.preventDefault();
			return;
		}
	});

	// Toggle custom input visibility when sentinel selected.
	document.addEventListener('change', function(e){
		var sel = e.target.closest('[data-bfr-select="1"]');
		if (!sel) return;
		var scope = sel.closest('[data-bfr-scope]');
		if (!scope) return;
		var custom = scope.querySelector('[data-bfr-custom="1"]');
		var mode   = scope.querySelector('[data-bfr-mode="1"]');
		if (!custom || !mode) return;

		if (sel.value === '__custom__') {
			custom.style.display = '';
			mode.value = 'custom';
		} else {
			custom.style.display = 'none';
			mode.value = 'select';
		}
	});
})();
</script>
HTML;
		// Return script block.
		return $js;
	}

	/**
	 * Basic ID derivation helper (same logic as renderer for consistency).
	 * @param string $name_base
	 * @return string
	 */
	private function id_from_name(string $name_base): string {
		// Convert bracketed names into safe IDs.
		$id = preg_replace('/\[+/', '-', $name_base);
		$id = preg_replace('/\]+/', '', (string) $id);
		$id = preg_replace('/[^a-zA-Z0-9\-_:.]/', '-', (string) $id);
		return trim((string) $id, '-');
	}
}