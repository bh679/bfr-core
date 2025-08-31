<?php
declare(strict_types=1);	// Enforce strict typing

namespace BFR\Admin\Components\Dropdown\Providers;	// Providers namespace

use BFR\Admin\Components\Dropdown\OptionProviderInterface;	// Import interface

/**
 * MetaKeyOptionsProvider
 *
 * Produces a distinct list of meta keys for a given CPT. Sources:
 * 1) JetEngine meta boxes (if present)
 * 2) Fallback sample from wp_postmeta for posts of that CPT
 *
 * NOTE: This is best-effort; WordPress doesn’t centralize meta keys.
 */
final class MetaKeyOptionsProvider implements OptionProviderInterface
{
	/** @var int */
	private int $sampleLimit;							// Control DB load

	/**
	 * @param int $sampleLimit	Limit number of posts to sample for meta keys (fallback path)
	 */
	public function __construct(int $sampleLimit = 200)	// Constructor with sane default
	{
		$this->sampleLimit = max(20, $sampleLimit);		// Clamp to avoid very small limits
	}

	/**
	 * @inheritDoc
	 */
	public function get_options(array $context = []): array	// Build meta key options
	{
		$cpt = (string)($context['cpt'] ?? '');				// Target CPT from context
		if ($cpt === '') {									// If missing CPT
			return [];										// No options possible
		}

		$keys = [];											// Aggregate set of keys

		// 1) JetEngine fields (most accurate)
		if (function_exists('jet_engine')) {				// If JetEngine loaded
			try {											// Try JE API
				$fields = $this->get_jetengine_fields_for_cpt($cpt);	// Get fields
				foreach ($fields as $key) {					// Loop JE keys
					$keys[(string)$key] = true;				// Mark presence
				}
			} catch (\Throwable $e) {
				// Ignore and fall through to DB sample			// Fail-safe
			}
		}

		// 2) Fallback sample from wp_postmeta
		if (empty($keys)) {									// If no JE keys found
			global $wpdb;									// Access WP DB
			// Query a sample of recent posts for this CPT			// Efficient-ish fallback
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID
					 FROM {$wpdb->posts}
					 WHERE post_type = %s AND post_status IN ('publish','draft','pending','future')
					 ORDER BY ID DESC
					 LIMIT %d",
					$cpt,
					$this->sampleLimit
				)
			);
			if (!empty($post_ids)) {						// If we have posts
				$in = implode(',', array_map('absint', $post_ids));	// Build CSV list
				$meta_keys = $wpdb->get_col(
					"SELECT DISTINCT meta_key
					 FROM {$wpdb->postmeta}
					 WHERE post_id IN ($in)
					 AND meta_key NOT LIKE '\_%'
					 ORDER BY meta_key ASC"
				);
				foreach ((array)$meta_keys as $k) {			// Loop distinct keys
					$k = (string)$k;						// Cast to string
					if ($k !== '') {							// Avoid empties
						$keys[$k] = true;					// Mark presence
					}
				}
			}
		}

		// Convert set -> "value => label"
		$out = [];											// Final output
		foreach (array_keys($keys) as $k) {					// Loop unique keys
			$out[$k] = $k;									// Label equals key
		}

		ksort($out, SORT_NATURAL | SORT_FLAG_CASE);			// Sort for stable UX
		return $out;										// Return options
	}

	/**
	 * Attempt to read JetEngine field keys for a CPT.
	 *
	 * @param string $cpt	Target post type
	 * @return string[]		List of meta keys
	 */
	private function get_jetengine_fields_for_cpt(string $cpt): array
	{
		$keys = [];										// Accumulator

		// JetEngine stores meta boxes per post type
		$mb = jet_engine()->meta_boxes ?? null;			// Get JE meta boxes manager
		if ($mb && method_exists($mb, 'get_fields_for_post_type')) {	// Newer JE API
			$fields = $mb->get_fields_for_post_type($cpt);	// Fetch fields by CPT
			if (is_array($fields)) {						// Ensure array
				foreach ($fields as $field) {				// Loop fields
					$key = (string)($field['name'] ?? '');	// Field "name" is meta key
					if ($key !== '') {						// Skip empties
						$keys[] = $key;						// Add meta key
					}
				}
			}
		} elseif ($mb && method_exists($mb, 'get_all_fields')) {	// Legacy JE API
			$fields = $mb->get_all_fields();				// Get all fields
			if (is_array($fields)) {						// Ensure array
				foreach ($fields as $field) {				// Loop fields
					if (($field['object_type'] ?? '') === $cpt) {	// Match CPT
						$key = (string)($field['name'] ?? '');	// Field name
						if ($key !== '') {					// Skip empties
							$keys[] = $key;					// Add meta key
						}
					}
				}
			}
		}

		return $keys;										// Return collected keys
	}
}