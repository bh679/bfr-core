<?php
declare(strict_types=1);

namespace BFR\Admin\Components\Dropdown\Providers;

use BFR\Admin\Components\Dropdown\OptionProviderInterface;

/**
 * CPTOptionsProvider
 * - Lists public post types as value=>label pairs.
 */
final class CPTOptionsProvider implements OptionProviderInterface {
	/**
	 * @inheritDoc
	 */
	public function get_options(array $context): array {
		// Get public post types as objects.
		$post_types = get_post_types(['public' => true], 'objects');
		// Build value=>label list.
		$out = [];
		foreach ($post_types as $slug => $obj) {
			// Use singular label when available.
			$out[$slug] = $obj->labels->singular_name ?: $slug;
		}
		// Return options for renderer.
		return $out;
	}
}