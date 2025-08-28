<?php

/**
 * Utility helpers for BFR Core (no side effects).
 *
 * Provides:
 * - get_cpt_choices()                     : list of user-facing CPTs (slug => label).
 * - get_meta_key_choices_for_cpt($cpt)    : distinct meta keys used by a CPT (cached).
 * - meta_key_picker_html($optKey, ...)    : select + “Custom…” text field (tiny JS).
 * - get_relation_choices()                : JetEngine relation slugs (best-effort).
 * - get_jetengine_meta_keys_for_cpt($cpt) : keys defined in JetEngine (CPT/Meta Boxes).
 *
 * Notes:
 * - All methods are static; this class is a pure helper.
 * - Swallows JetEngine API variations gracefully (new/legacy).
 * - Keep it UI-agnostic except for the tiny inline picker JS/CSS.
 *
 * @package   BFR\Core
 * @since     0.6.0
 * @internal  Loaded by Composer PSR-4 autoloading (namespace BFR\Core).
 */
namespace BFR\Core;

if (!defined('ABSPATH')) exit;

/**
 * Utility helpers for CPT/meta discovery and JetEngine integration.
 */
final class Helpers {

	public static function get_cpt_choices(): array {
		$builtin_exclude = [
			'attachment','revision','nav_menu_item','custom_css','customize_changeset',
			'oembed_cache','user_request','wp_block','wp_template','wp_template_part',
			'wp_navigation','elementor_library'
		];
		$types = get_post_types(['show_ui' => true], 'objects');
		$out = [];
		foreach ($types as $slug => $obj) {
			if (in_array($slug, $builtin_exclude, true)) continue;
			$label = $obj->labels->singular_name ?: $obj->label ?: $slug;
			$out[$slug] = $label;
		}
		natcasesort($out);
		return $out;
	}

	public static function get_meta_key_choices_for_cpt(string $cpt, int $limit = 300): array {
	    // Make $wpdb (the WordPress database object) available inside this function
	    global $wpdb;

	    // Build a unique cache key name based on the CPT slug
	    // e.g. if $cpt = "destinations", key becomes "bfr_meta_keys_destinations"
	    $cache_key = 'bfr_meta_keys_' . sanitize_key($cpt);

	    // Try to load any previously cached results for this CPT
	    $cached = get_transient($cache_key);

	    // If we already cached a list (and it’s an array), return it immediately
	    if (is_array($cached)) return $cached;

	    // Otherwise: query the database directly to discover meta keys
	    // Prepare a SQL query that selects distinct meta_key values
	    // from wp_postmeta joined with wp_posts, only for this CPT.
	    // Skip empty meta_key rows, and limit the number of rows for safety.
	    $sql = $wpdb->prepare(
	        "SELECT DISTINCT pm.meta_key
	         FROM {$wpdb->postmeta} pm
	         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
	         WHERE p.post_type = %s
	           AND pm.meta_key <> ''
	         LIMIT %d",
	        $cpt, $limit
	    );

	    // Execute the query and get back a flat list of meta_key strings
	    $rows = $wpdb->get_col($sql); // returns an array of strings

	    // Initialize an empty array of usable keys
	    $keys = [];

	    // If the query returned rows, process them
	    if (is_array($rows)) {
	        // Define prefixes we want to ignore (internal WP/Elementor/SEO junk keys)
	        $skip_prefixes = ['_edit_','_thumbnail_id','_elementor','_wp_','_aioseo_','_yoast_','_et_','_oembed_','_jet_'];

	        // Loop through each meta key
	        foreach ($rows as $k) {
	            $k = (string) $k;   // make sure it’s a string
	            $skip = false;      // assume we keep it

	            // Check if the meta key starts with any of the excluded prefixes
	            foreach ($skip_prefixes as $pref) {
	                if (substr($k, 0, strlen($pref)) === $pref) {
	                    $skip = true; // mark to skip this key
	                    break;        // no need to check further
	                }
	            }

	            // If it’s not a skip key, keep it in the array
	            if (!$skip) $keys[$k] = $k;
	        }
	    }

	    // Sort the list alphabetically (case-insensitive, natural order)
	    natcasesort($keys);

	    // Convert to a simple list of unique values (remove duplicates, reindex)
	    $keys = array_unique(array_values($keys));

	    // Save the cleaned list into a transient cache for 10 minutes
	    // This means next call won’t need to hit the DB.
	    set_transient($cache_key, $keys, 10 * MINUTE_IN_SECONDS);

	    // Return the list of meta keys
	    return $keys;
	}


public static function meta_key_picker_html(
    string $opt_key,
    string $label,
    array $opts,
    ?string $cpt_slug = null,
    ?string $default_value = null   // NEW: what we want to use if CPT doesn't have it yet
) : string {
    // Use caller's CPT or fall back to school CPT for legacy callers
    $cpt = $cpt_slug ?: ( isset($opts['school_cpt']) && is_string($opts['school_cpt']) ? $opts['school_cpt'] : 'freedive-school' );

    // Keys that actually exist on this CPT (DB-backed only)
    $list = self::get_meta_key_choices_for_cpt($cpt);
    // Keep them as a set for fast lookup
    $present = array_fill_keys($list, true);

    // Current option value (if any) and the plugin default
    $current_opt   = isset($opts[$opt_key]) ? (string) $opts[$opt_key] : '';
    $default_value = is_string($default_value) ? $default_value : (isset($opts[$opt_key]) ? (string) $opts[$opt_key] : '');

    // Decide selection:
    // 1) If user saved something previously and it exists on CPT -> select it
    // 2) Else if user saved something previously (but not present) -> Custom with that value
    // 3) Else (first load) -> if default exists on CPT select it, otherwise Custom with default prefilled
    $select_value = '';
    $custom_value = '';
    $use_custom   = false;

    if ($current_opt !== '') {
        if (isset($present[$current_opt])) {
            $select_value = $current_opt;
        } else {
            $use_custom   = true;
            $custom_value = $current_opt;
        }
    } else {
        if ($default_value !== '' && isset($present[$default_value])) {
            $select_value = $default_value;
        } else {
            $use_custom   = true;
            $custom_value = $default_value ?: '';
        }
    }

    $select_name = \BFR_CORE_OPTION . '[' . esc_attr($opt_key) . '_select]';
    $input_name  = \BFR_CORE_OPTION . '[' . esc_attr($opt_key) . ']';

    ob_start(); ?>
    <select name="<?php echo esc_attr($select_name); ?>" data-bfr-target="<?php echo esc_attr($opt_key); ?>">
        <?php foreach ($list as $key): ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($select_value, $key); ?>>
                <?php echo esc_html($key); ?>
            </option>
        <?php endforeach; ?>
        <option value="__custom__" <?php selected($use_custom ? '__custom__' : '', '__custom__'); ?>>Custom…</option>
    </select>
    <input type="text"
        class="regular-text bfr-meta-custom <?php echo $use_custom ? '' : 'hidden'; ?>"
        data-bfr-for="<?php echo esc_attr($opt_key); ?>"
        name="<?php echo esc_attr($input_name); ?>"
        value="<?php echo esc_attr($custom_value); ?>"
        aria-label="<?php echo esc_attr($label); ?>"
        placeholder="<?php echo esc_attr('Type meta key'); ?>" />

    <?php
    $html = ob_get_clean();

    static $printed = false;
    if (!$printed) {
        $printed = true;
        $html .= '<style>.hidden{display:none}</style>';
        $html .= '<script>
        document.addEventListener("change", function(ev){
            var sel = ev.target;
            if (!sel.matches(\'select[name^="' . \BFR_CORE_OPTION . '"][name$="_select]"]\')) return;
            var key = sel.getAttribute("data-bfr-target");
            var input = document.querySelector(\'input.bfr-meta-custom[data-bfr-for="\'+key+\'"]\');
            if (!input) return;
            if (sel.value === "__custom__") {
                input.classList.remove("hidden");
                input.removeAttribute("hidden");
                input.focus();
            } else {
                input.value = sel.value || "";
                input.classList.add("hidden");
                input.setAttribute("hidden","hidden");
            }
        });
        </script>';
    }
    return $html;
}

	public static function get_relation_choices(): array {
		$choices = [];
		$add = function($slug, $label = '') use (&$choices) {
			$slug = is_string($slug) ? trim($slug) : '';
			if ($slug === '') return;
			$choices[$slug] = $label !== '' ? $label : $slug;
		};

		try {
			if (function_exists('jet_engine')) {
				$je = jet_engine();

				if (isset($je->modules) && method_exists($je->modules, 'get_module')) {
					$rel_module = $je->modules->get_module('relations');
					if ($rel_module && isset($rel_module->relations) && method_exists($rel_module->relations, 'get_relations')) {
						$list = $rel_module->relations->get_relations();
						if (is_array($list)) {
							foreach ($list as $rel) {
								$slug=''; $name='';
								if (is_object($rel) && method_exists($rel, 'get_args')) {
									$args = $rel->get_args();
									$slug = $args['slug'] ?? '';
									$name = $args['name'] ?? '';
								} elseif (is_array($rel)) {
									$slug = $rel['slug'] ?? '';
									$name = $rel['name'] ?? '';
								}
								if (!$slug && is_object($rel) && method_exists($rel, 'get_id'))    $slug = (string) $rel->get_id();
								if (!$name && is_object($rel) && method_exists($rel, 'get_label')) $name = (string) $rel->get_label();
								$add($slug, $name);
							}
						}
					}
				}

				if (empty($choices) && isset($je->relations)) {
					$rel_comp = $je->relations;
					$list = [];
					if (is_object($rel_comp) && method_exists($rel_comp, 'get_component')) {
						$component = $rel_comp->get_component();
						if ($component && method_exists($component, 'get_relations')) {
							$list = $component->get_relations();
						} elseif ($component && isset($component->relations)) {
							$list = $component->relations;
						}
					}
					if (empty($list) && isset($rel_comp->relations)) $list = $rel_comp->relations;

					if (is_array($list)) {
						foreach ($list as $rel) {
							$slug=''; $name='';
							if (is_array($rel)) {
								$slug = $rel['slug'] ?? '';
								$name = $rel['name'] ?? '';
							} elseif (is_object($rel)) {
								if (method_exists($rel, 'get_args')) {
									$args = $rel->get_args();
									$slug = $args['slug'] ?? '';
									$name = $args['name'] ?? '';
								}
								if (!$slug && method_exists($rel, 'get_id'))    $slug = (string) $rel->get_id();
								if (!$name && method_exists($rel, 'get_label')) $name = (string) $rel->get_label();
							}
							$add($slug, $name);
						}
					}
				}
			}

			if (empty($choices)) {
				$opt = get_option('jet_engine_relations');
				if (is_array($opt)) {
					foreach ($opt as $entry) {
						if (is_array($entry)) $add($entry['slug'] ?? '', $entry['name'] ?? '');
					}
				}
			}
		} catch (\Throwable $e) {}

		if (!empty($choices)) natcasesort($choices);
		return $choices;
	}

	public static function get_jetengine_meta_keys_for_cpt(string $cpt): array {
		$keys = [];
		$add = function($k) use (&$keys) {
			$k = is_string($k) ? trim($k) : '';
			if ($k !== '') $keys[$k] = true;
		};

		if (!function_exists('jet_engine')) return $keys;

		$je = jet_engine();

		try {
			if (isset($je->post_type) && is_object($je->post_type) && method_exists($je->post_type, 'get_post_types')) {
				$pts = $je->post_type->get_post_types();
				if (is_array($pts) && isset($pts[$cpt]['meta_fields']) && is_array($pts[$cpt]['meta_fields'])) {
					foreach ($pts[$cpt]['meta_fields'] as $field) {
						if (is_array($field) && isset($field['name'])) $add($field['name']);
					}
				}
			} elseif (isset($je->post_types) && is_object($je->post_types) && method_exists($je->post_types, 'get_post_types')) {
				$pts = $je->post_types->get_post_types();
				if (is_array($pts) && isset($pts[$cpt]['meta_fields']) && is_array($pts[$cpt]['meta_fields'])) {
					foreach ($pts[$cpt]['meta_fields'] as $field) {
						if (is_array($field) && isset($field['name'])) $add($field['name']);
					}
				}
			}
		} catch (\Throwable $e) {}

		try {
			if (isset($je->meta_boxes) && is_object($je->meta_boxes) && method_exists($je->meta_boxes, 'get_meta_boxes')) {
				$boxes = $je->meta_boxes->get_meta_boxes();
				if (is_array($boxes)) {
					foreach ($boxes as $box) {
						$targets = [];
						if (isset($box['args']['post_type'])) {
							$targets = is_array($box['args']['post_type']) ? $box['args']['post_type'] : [$box['args']['post_type']];
						} elseif (isset($box['post_type'])) {
							$targets = is_array($box['post_type']) ? $box['post_type'] : [$box['post_type']];
						}
						if (!in_array($cpt, $targets, true)) continue;

						if (isset($box['meta_fields']) && is_array($box['meta_fields'])) {
							foreach ($box['meta_fields'] as $field) {
								if (is_array($field) && isset($field['name'])) $add($field['name']);
							}
						}
					}
				}
			}
		} catch (\Throwable $e) {}

		return $keys;
	}
}