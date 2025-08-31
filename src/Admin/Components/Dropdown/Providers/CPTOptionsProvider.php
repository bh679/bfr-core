<?php
declare(strict_types=1);	// Enforce strict typing

namespace BFR\Admin\Components\Dropdown\Providers;	// Providers namespace

use BFR\Admin\Components\Dropdown\OptionProviderInterface;	// Import interface

/**
 * CPTOptionsProvider
 *
 * Returns all public CPTs (WordPress) + JetEngine CPTs if available.
 */
final class CPTOptionsProvider implements OptionProviderInterface
{
	/**
	 * @inheritDoc
	 */
	public function get_options(array $context = []): array	// Build CPT options
	{
		$options = [];										// Prepare output map

		// Core/public CPTs first
		$types = get_post_types(['public' => true], 'objects');	// Fetch CPT objects
		foreach ($types as $slug => $obj) {					// Loop each CPT object
			$label = $obj->labels->singular_name ?: $obj->label ?: $slug;	// Resolve label
			$options[(string)$slug] = (string)$label;		// Add "slug => label"
		}

		// JetEngine CPTs (if plugin active)
		if (function_exists('jet_engine')) {					// Check plugin presence
			try {												// Safely try to access API
				$items = jet_engine()->post_types->get_items();	// Get JE CPT items
				if (is_array($items)) {							// Ensure array shape
					foreach ($items as $item) {					// Loop JE definitions
						$slug  = (string)($item['slug'] ?? '');	// Read CPT slug
						$label = (string)($item['labels']['singular_name'] ?? $slug);	// Read label
						if ($slug && !isset($options[$slug])) {	// Avoid overriding WP CPTs
							$options[$slug] = $label;			// Add JE CPT
						}
					}
				}
			} catch (\Throwable $e) {							// On any error
				// Silently ignore so the admin page never breaks	// Fail-safe
			}
		}

		ksort($options, SORT_NATURAL | SORT_FLAG_CASE);			// Sort for stable UX
		return $options;											// Return the map
	}
}