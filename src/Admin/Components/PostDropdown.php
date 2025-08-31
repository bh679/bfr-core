<?php
declare(strict_types=1);

namespace BFR\Admin\Components;

/**
 * Class PostDropdown
 *
 * Responsibilities:
 * - Build a <select> option list from posts belonging to a given CPT (post type).
 * - Render a single dropdown that mirrors the look/feel of other dropdowns in the editor:
 *   a <select> plus a "Custom…" text box and a hidden "mode" input.
 *
 * Notes:
 * - This class intentionally delegates the actual HTML of the combo input
 *   (select + custom + hidden mode) to {@see DropdownProvider::render_select_with_custom()}
 *   so it stays visually consistent with existing controls.
 */
final class PostDropdown
{
	/** @var DropdownProvider */
	private DropdownProvider $dropdowns;	// Store the existing renderer/helper for consistent UI

	/**
	 * Inject the shared DropdownProvider to reuse its renderer.
	 *
	 * @param DropdownProvider $dropdowns	Generic dropdown renderer/helper
	 */
	public function __construct(DropdownProvider $dropdowns)
	{
		// Keep reference so we can render "select + custom + hidden mode" consistently
		$this->dropdowns = $dropdowns;
	}

	/**
	 * Build an associative array of { post_id => post_title } for a CPT.
	 *
	 * @param string $post_type				CPT slug (e.g. 'destinations')
	 * @param int    $limit					Max number of posts to include
	 * @return array<string,string>			post_id => label
	 */
	public function get_post_options(string $post_type, int $limit = 200): array
	{
		// If no post type is specified, return an empty options list
		if ($post_type === '') {
			return [];
		}

		// Query posts of the given CPT (include drafts so you can preview WIP content)
		$q = new \WP_Query([
			'post_type'             => $post_type,           // Only this CPT
			'posts_per_page'        => $limit,               // Cap results for performance
			'post_status'           => ['publish', 'draft'], // Allow draft previews
			'orderby'               => 'date',               // Most recent first
			'order'                 => 'DESC',
			'no_found_rows'         => true,                 // Performance optimization
			'ignore_sticky_posts'   => true,                 // Not relevant for CPTs
			'fields'                => 'ids',                // Fetch IDs first
		]);

		$options = [];

		// First option is a friendly prompt
		$options[''] = '— Select a post —';

		// Map each ID to a human-readable label that includes the ID for clarity
		if ($q->have_posts()) {
			foreach ($q->posts as $post_id) {
				$title = get_the_title((int)$post_id);             // Get the post title
				if ($title === '' || $title === null) {            // Fallback for untitled posts
					$title = '(no title)';
				}
				$label = sprintf('%s (ID %d)', $title, (int)$post_id); // Compose label with ID
				$options[(string)$post_id] = $label;                // Use string keys (consistent with other inputs)
			}
		}

		return $options;	// Return value => label pairs
	}

	/**
	 * Render a select + "Custom…" input for picking a post of a CPT.
	 *
	 * @param string      $select_name		POST name for the <select>
	 * @param string      $custom_name		POST name for the "Custom…" <input type="text">
	 * @param string      $mode_name		POST name for the hidden "mode" ('value' | 'custom')
	 * @param string      $post_type		Target CPT whose posts should populate the dropdown
	 * @param string      $selected_value	Currently-selected post ID (string; '__custom__' to show custom box)
	 * @param string      $custom_value		Prefill for the custom text box (free-form, e.g. a URL or ID)
	 * @param string|null $id_attr			Optional id attribute for the <select>
	 * @return string						HTML output
	 */
	public function render_select_with_custom(
		string $select_name,
		string $custom_name,
		string $mode_name,
		string $post_type,
		string $selected_value = '',
		string $custom_value = '',
		?string $id_attr = null
	): string {
		// Build the post options for the requested CPT
		$options = $this->get_post_options($post_type);

		// Render using the same helper as other dropdowns so it looks identical
		return $this->dropdowns->render_select_with_custom(
			$select_name,      // Name of the <select>
			$custom_name,      // Name of the custom text input
			$mode_name,        // Hidden "mode" field name
			$options,          // value => label list (post_id => title)
			$selected_value,   // Selected option
			$custom_value,     // Prefill for custom text
			$id_attr           // Optional id attribute
		);
	}
}