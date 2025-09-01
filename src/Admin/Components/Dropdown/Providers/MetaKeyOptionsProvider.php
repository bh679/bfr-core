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
 * Optionally decorates labels with the current meta value for a specific post
 * when $context['post_id'] is provided.
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
	 * @param array<string,mixed> $context
	 *   Required: ['cpt' => string]
	 *   Optional: ['post_id' => int] to show current values in labels
	 */
	public function get_options(array $context = []): array	// Build meta key options
	{
		$cpt = (string)($context['cpt'] ?? '');				// Target CPT from context
		if ($cpt === '') {									// If missing CPT
			return [];										// No options possible
		}

		$postId     = isset($context['post_id']) ? (int)$context['post_id'] : 0;	// Optional preview post
		$showValues = $postId > 0;												// Whether to decorate labels

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

		// Convert set -> "value => label" (decorate if a post_id is provided)
		$out = [];											// Final output
		foreach (array_keys($keys) as $k) {					// Loop unique keys
			if ($showValues) {								// If we should show values
				$val = get_post_meta($postId, $k, true);	// Read current meta value
				$out[$k] = $this->format_label_with_value($k, $val);	// Append preview
			} else {
				$out[$k] = $k;								// Plain label
			}
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

		$je = function_exists('jet_engine') ? jet_engine() : null;	// JE instance (or null)
		if (!$je || !is_object($je)) {
			return $keys;											// No JetEngine available
		}

		// Newer JetEngine API: meta_boxes->get_fields_for_post_type()
		if (isset($je->meta_boxes) && is_object($je->meta_boxes)) {
			$mb = $je->meta_boxes;
			if (method_exists($mb, 'get_fields_for_post_type')) {
				$fields = $mb->get_fields_for_post_type($cpt);
				if (is_array($fields)) {
					foreach ($fields as $field) {
						$key = (string)($field['name'] ?? '');
						if ($key !== '') { $keys[] = $key; }
					}
				}
				return $keys;										// Prefer this path
			}
			// Legacy catch-all
			if (method_exists($mb, 'get_all_fields')) {
				$fields = $mb->get_all_fields();
				if (is_array($fields)) {
					foreach ($fields as $field) {
						if (($field['object_type'] ?? '') === $cpt) {
							$key = (string)($field['name'] ?? '');
							if ($key !== '') { $keys[] = $key; }
						}
					}
				}
				return $keys;
			}
		}

		return $keys;										// Return collected keys
	}

	/**
	 * Format the option label as: "{meta_key} — {value preview}".
	 * Safely stringifies arrays/objects and truncates long strings.
	 *
	 * @param string $key   Meta key
	 * @param mixed  $value Raw meta value from get_post_meta()
	 * @return string       Human-friendly label with value
	 */
	private function format_label_with_value(string $key, mixed $value): string
	{
		$preview = $this->stringify_preview($value);			// Convert to short string
		return ($preview === '')
			? $key . ' — (empty)'
			: $key . ' — ' . $preview;
	}

	/**
	 * Convert a meta value into a short, safe preview.
	 * - Scalars are cast to string.
	 * - Arrays/objects are JSON-encoded compactly.
	 * - Long strings are truncated to ~60 chars with an ellipsis.
	 *
	 * @param mixed $value Raw meta
	 * @return string      Short preview
	 */
	private function stringify_preview(mixed $value): string
	{
		if (is_null($value) || $value === '') {
			return '';
		}
		if (is_scalar($value)) {
			$str = (string) $value;
		} else {
			$json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$str  = is_string($json) ? $json : '';
		}
		$str = trim($str);
		if ($str === '') {
			return '';
		}
		if (mb_strlen($str) > 60) {
			$str = mb_substr($str, 0, 57) . '…';
		}
		return $str;
	}
}