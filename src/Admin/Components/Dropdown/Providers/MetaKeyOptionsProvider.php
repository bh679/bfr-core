<?php
declare(strict_types=1);

namespace BFR\Admin\Components\Dropdown\Providers;

use BFR\Admin\Components\Dropdown\OptionProviderInterface;

/**
 * MetaKeyOptionsProvider
 * - Scans postmeta for a post_type and returns distinct keys.
 */
final class MetaKeyOptionsProvider implements OptionProviderInterface {
	/** @var int */
	private int $limit;

	/**
	 * @param int $limit	// Safety cap for distinct keys
	 */
	public function __construct(int $limit = 200) {
		// Save the limit.
		$this->limit = $limit;
	}

	/**
	 * @inheritDoc
	 */
	public function get_options(array $context): array {
		// Pull required post_type from context.
		$post_type = isset($context['post_type']) ? (string) $context['post_type'] : '';
		// If no post_type, return only "Custom…".
		if ($post_type === '') {
			return ['__custom__' => 'Custom…'];
		}

		// Access WPDB for direct SQL.
		global $wpdb;

		// Prepare SQL to find distinct non-internal meta_keys.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s
			   AND pm.meta_key NOT LIKE '\\_%'
			 ORDER BY pm.meta_key ASC
			 LIMIT %d",
			$post_type,
			$this->limit
		);

		// Execute query and collect into value=>label.
		$keys = (array) $wpdb->get_col($sql);
		$out  = [];
		foreach ($keys as $k) {
			// Mirror value as label for meta keys.
			$out[$k] = $k;
		}

		// Append Custom… sentinel.
		$out['__custom__'] = 'Custom…';

		// Return the options.
		return $out;
	}
}