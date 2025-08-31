<?php
declare(strict_types=1);	// Enforce strict typing

namespace BFR\Admin\Components\Dropdown\Providers;	// Providers namespace

use BFR\Admin\Components\Dropdown\OptionProviderInterface;	// Import interface

/**
 * ClassOptionsProvider
 *
 * Lists available calculator classes from /src/Meta/registry.php.
 * Uses 'name' for labels and supports either 'class' or 'calculation' keys.
 */
final class ClassOptionsProvider implements OptionProviderInterface
{
	/** @var array<string,mixed> */
	private array $registry;							// Cached registry array

	/**
	 * @param array<string,mixed>|null $registry	Optional injected registry for testing
	 */
	public function __construct(?array $registry = null)	// Constructor allows DI
	{
		if ($registry !== null) {						// If provided
			$this->registry = $registry;				// Use injected registry
			return;										// Done
		}

		// Load default registry file
		$path = plugin_dir_path(BFR_CORE_MAIN_FILE ?? __FILE__) . 'src/Meta/registry.php';	// Compute path
		if (!is_file($path)) {							// If not found at computed path
			// Fallback: resolve relative to this file				// Safer default
			$path = dirname(__DIR__, 3) . '/Meta/registry.php';		// ../../Meta/registry.php
		}

		/** @var array<string,mixed> $data */
		$data = is_file($path) ? (require $path) : [];	// Require registry or empty
		$this->registry = is_array($data) ? $data : [];	// Normalize to array
	}

	/**
	 * @inheritDoc
	 */
	public function get_options(array $context = []): array	// Build class options
	{
		$out = [];											// Output map

		foreach ($this->registry as $slug => $row) {			// Loop registry items
			if (!is_array($row)) {								// Skip non-arrays
				continue;
			}
			$label = (string)($row['name'] ?? (string)$slug);	// Prefer human name
			$fqcn  = (string)($row['calculation'] ?? $row['class'] ?? '');	// Class key support
			if ($fqcn !== '') {									// Only if class present
				$out[$fqcn] = $label;							// Map class => label
			}
		}

		// If empty (e.g., registry not set up), provide a safe hint
		if (empty($out)) {										// Nothing found
			$out[''] = '— No calculators found —';				// UX hint
		}

		asort($out, SORT_NATURAL | SORT_FLAG_CASE);				// Sort by label
		return $out;											// Return options
	}
}