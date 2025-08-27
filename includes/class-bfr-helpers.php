<?php
/**
 * Small, reusable utilities for CPTs, meta keys, and JetEngine discovery.
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Small grab-bag of helpers used across the plugin.
 */
final class BFR_Helpers {

	/**
<<<<<<< HEAD
	 * Return CPT choices suitable for a settings dropdown.
	 * Includes public post types with UI, minus built-in/system ones.
	 *
	 * @return array slug => label
	 */
	public static function get_cpt_choices(): array {
		$builtin_exclude = [
			'attachment','revision','nav_menu_item','custom_css','customize_changeset',
			'oembed_cache','user_request','wp_block','wp_template','wp_template_part',
			'wp_navigation','elementor_library'
		];

		$types = get_post_types(['show_ui' => true], 'objects');
		$out = [];
		foreach ($types as $slug => $obj) {
			if ( in_array($slug, $builtin_exclude, true) ) continue;
			$label = $obj->labels->singular_name ?: $obj->label ?: $slug;
			$out[$slug] = $label;
		}
		natcasesort($out);
		return $out;
	}

	/**
	 * Return distinct meta keys used by a CPT (cached for 10 minutes).
	 * Scans posts joined to postmeta for that post_type (lightweight list).
	 *
	 * @param string $cpt
	 * @param int    $limit
	 * @return string[]
	 */
	public static function get_meta_key_choices_for_cpt( string $cpt, int $limit = 300 ): array {
		global $wpdb;

		$cache_key = 'bfr_meta_keys_' . sanitize_key($cpt);
		$cached    = get_transient($cache_key);
		if ( is_array($cached) ) {
			return $cached;
		}

		$sql = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s
			   AND pm.meta_key <> ''
			 LIMIT %d",
			$cpt,
			$limit
		);
		$rows = $wpdb->get_col($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$keys = [];
		if ( is_array($rows) ) {
			$skip_prefixes = ['_edit_','_thumbnail_id','_elementor','_wp_','_aioseo_','_yoast_','_et_','_oembed_','_jet_'];
			foreach ( $rows as $k ) {
				$k = (string) $k;
				$skip = false;
				foreach ( $skip_prefixes as $pref ) {
					if ( substr($k, 0, strlen($pref)) === $pref ) { $skip = true; break; }
				}
				if ( ! $skip ) $keys[$k] = $k;
			}
		}

		natcasesort($keys);
		$keys = array_unique(array_values($keys));

		set_transient($cache_key, $keys, 10 * MINUTE_IN_SECONDS);
		return $keys;
	}

	/**
	 * Build markup for a dropdown + “Custom…” input for a meta-key option.
	 * $opt_key (e.g., 'meta_dest_id') and $label describe the input.
	 * $opts must include the currently selected 'school_cpt'.
	 *
	 * @return string HTML
	 */
	public static function meta_key_picker_html( string $opt_key, string $label, array $opts ) : string {
		$cpt   = isset($opts['school_cpt']) && is_string($opts['school_cpt']) ? $opts['school_cpt'] : 'freedive-school';
		$list  = self::get_meta_key_choices_for_cpt( $cpt );
		$current = isset($opts[$opt_key]) ? (string) $opts[$opt_key] : '';

		$use_custom = $current !== '' && ! in_array( $current, $list, true );

		$opt_base    = defined('BFR_CORE_OPTION') ? BFR_CORE_OPTION : 'bfr_core_options';
		$select_name = $opt_base . '[' . esc_attr($opt_key) . '_select]';
		$input_name  = $opt_base . '[' . esc_attr($opt_key) . ']';

		ob_start();
		?>
		<select name="<?php echo esc_attr($select_name); ?>" data-bfr-target="<?php echo esc_attr($opt_key); ?>">
			<option value="" <?php selected( $use_custom ? '' : $current, '' ); ?>>— Select meta key —</option>
			<?php foreach ( $list as $key ) : ?>
				<option value="<?php echo esc_attr($key); ?>" <?php selected( $use_custom ? '' : $current, $key ); ?>>
					<?php echo esc_html($key); ?>
				</option>
			<?php endforeach; ?>
			<option value="__custom__" <?php selected( $use_custom ? '__custom__' : '', '__custom__' ); ?>>Custom…</option>
		</select>
		<input type="text"
			class="regular-text bfr-meta-custom <?php echo $use_custom ? '' : 'hidden'; ?>"
			data-bfr-for="<?php echo esc_attr($opt_key); ?>"
			name="<?php echo esc_attr($input_name); ?>"
			value="<?php echo esc_attr($current); ?>"
			aria-label="<?php echo esc_attr($label); ?>"
			placeholder="<?php echo esc_attr('Type meta key (when using “Custom…” )'); ?>" />
		<?php

		$html = ob_get_clean();

		// One-time tiny JS/CSS (no jQuery dependency)
		static $printed = false;
		if ( ! $printed ) {
			$printed = true;
			$html .= '<style>.hidden{display:none}</style>';
			$html .= '<script>
			document.addEventListener("change", function(ev){
				var sel = ev.target;
				if (!sel.matches(\'select[name^="'.esc_js($opt_base).'"][name$="_select]"]\')) return;
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

	/**
	 * Return JetEngine relation choices if JetEngine is active.
	 * Key = relation slug, value = relation label.
	 *
	 * @return array
	 */
	public static function get_relation_choices(): array {
		$choices = [];
		$add = function($slug, $label = '') use (&$choices) {
			$slug = is_string($slug) ? trim($slug) : '';
			if ($slug === '') return;
			$choices[$slug] = $label !== '' ? $label : $slug;
		};
=======
	 * Convenience wrapper: options merged with Aggregator defaults.
	 */
	public static function get_opts(): array {
		$defaults = class_exists('BFR_Aggregator')
			? BFR_Aggregator::instance()->defaults()
			: [
				'dest_cpt'        => 'destinations',
				'school_cpt'      => 'freedive-schools',
				'je_relation'     => '',
				'meta_dest_id'    => 'destination_id',
				'meta_max_depth'  => 'max_depth',
				'meta_price'      => 'course_price',
				'meta_languages'  => 'languages',
				'meta_facilities' => 'facilities',

				// Output keys (Destination meta)
				'out_school_count' => 'bfr_school_count',
				'out_max_depth'    => 'bfr_max_depth',
				'out_min_price'    => 'bfr_min_course_price',
				'out_languages'    => 'bfr_languages',
				'out_facilities'   => 'bfr_facilities',
			];

		$raw = get_option('bfr_core_options', []);
		return wp_parse_args( is_array($raw) ? $raw : [], $defaults );
	}

	/**
	 * Return a (sorted) list of JetEngine meta keys that belong to Meta Boxes
	 * targeting the given CPT. Falls back to Post Type "Meta fields" if present,
	 * and finally to scanning distinct postmeta keys in the DB.
	 *
	 * @return string[] list of meta keys
	 */
	public static function get_destination_meta_keys(string $cpt, int $limit = 400): array {
		$keys = [];
>>>>>>> main

		// Strategy A: JetEngine Meta Boxes API
		try {
			if ( function_exists('jet_engine') && isset( jet_engine()->meta_boxes ) ) {
				$mb = jet_engine()->meta_boxes;
				if ( method_exists($mb, 'get_items') ) {
					$boxes = $mb->get_items(); // array of meta box configs
					if ( is_array($boxes) ) {
						foreach ($boxes as $box) {
							// Each $box should have 'args' with 'post_type' targets and 'fields'
							$args   = is_object($box) && method_exists($box, 'get_args') ? $box->get_args() : (array) $box;
							$target = (array)($args['post_type'] ?? []);
							if ( ! empty($target) && in_array($cpt, $target, true) ) {
								$fields = (array)($args['fields'] ?? []);
								foreach ( $fields as $f ) {
									$name = '';
									if ( is_object($f) && method_exists($f, 'get_name') ) {
										$name = (string) $f->get_name();
									} elseif ( is_array($f) && isset($f['name']) ) {
										$name = (string) $f['name'];
									}
									if ( $name !== '' ) $keys[$name] = $name;
								}
							}
						}
					}
				}
			}
		} catch ( \Throwable $e ) { /* swallow */ }

<<<<<<< HEAD
				// Strategy A: Modules API
				if ( isset($je->modules) && method_exists($je->modules, 'get_module') ) {
					$rel_module = $je->modules->get_module('relations');
					if ( $rel_module && isset($rel_module->relations) ) {
						$mgr = $rel_module->relations;
						if ( method_exists($mgr, 'get_relations') ) {
							$list = $mgr->get_relations();
							if ( is_array($list) ) {
								foreach ( $list as $rel ) {
									$slug=''; $name='';
									if ( is_object($rel) && method_exists($rel, 'get_args') ) {
										$args = $rel->get_args();
										$slug = isset($args['slug']) ? (string)$args['slug'] : '';
										$name = isset($args['name']) ? (string)$args['name'] : '';
									} elseif ( is_array($rel) ) {
										$slug = $rel['slug'] ?? '';
										$name = $rel['name'] ?? '';
=======
		// Strategy B: JetEngine Post Type “Meta fields” (older setups)
		if ( empty($keys) ) {
			try {
				if ( function_exists('jet_engine') && isset( jet_engine()->post_type ) ) {
					$pt = jet_engine()->post_type;
					if ( method_exists($pt, 'data') ) {
						$list = $pt->data->get_items();
						if ( is_array($list) ) {
							foreach ($list as $pt_def) {
								$args = is_object($pt_def) && method_exists($pt_def, 'get_args') ? $pt_def->get_args() : (array) $pt_def;
								if ( ($args['slug'] ?? '') === $cpt ) {
                                    foreach ( (array)($args['meta_fields'] ?? []) as $field ) {
										$name = is_array($field) ? (string)($field['name'] ?? '') : '';
										if ( $name !== '' ) $keys[$name] = $name;
>>>>>>> main
									}
									if ( ! $slug && is_object($rel) && method_exists($rel, 'get_id') )    $slug = (string) $rel->get_id();
									if ( ! $name && is_object($rel) && method_exists($rel, 'get_label') ) $name = (string) $rel->get_label();
									$add($slug, $name);
								}
							}
						}
					}
				}
<<<<<<< HEAD

				// Strategy B: Legacy component
				if ( empty($choices) && isset($je->relations) ) {
					$rel_comp = $je->relations;
					$list = [];
					if ( is_object($rel_comp) ) {
						if ( method_exists($rel_comp, 'get_component') ) {
							$component = $rel_comp->get_component();
							if ( $component && method_exists($component, 'get_relations') ) {
								$list = $component->get_relations();
							} elseif ( $component && isset($component->relations) ) {
								$list = $component->relations;
							}
						}
						if ( empty($list) && isset($rel_comp->relations) ) {
							$list = $rel_comp->relations;
						}
					}
					if ( is_array($list) ) {
						foreach ( $list as $rel ) {
							$slug=''; $name='';
							if ( is_array($rel) ) {
								$slug = $rel['slug'] ?? '';
								$name = $rel['name'] ?? '';
							} elseif ( is_object($rel) ) {
								if ( method_exists($rel, 'get_args') ) {
									$args = $rel->get_args();
									$slug = $args['slug'] ?? '';
									$name = $args['name'] ?? '';
								}
								if ( ! $slug && method_exists($rel, 'get_id') )    $slug = (string) $rel->get_id();
								if ( ! $name && method_exists($rel, 'get_label') ) $name = (string) $rel->get_label();
							}
							$add($slug, $name);
						}
					}
				}
			}

			// Strategy C: Option fallback
			if ( empty($choices) ) {
				$opt = get_option('jet_engine_relations');
				if ( is_array($opt) ) {
					foreach ( $opt as $entry ) {
						if ( is_array($entry) ) {
							$add( $entry['slug'] ?? '', $entry['name'] ?? '' );
						}
					}
				}
			}
		} catch (\Throwable $e) {
			// swallow errors, return what we have
=======
			} catch ( \Throwable $e ) { /* swallow */ }
>>>>>>> main
		}

		// Strategy C: DB scan (distinct postmeta keys for that CPT)
		if ( empty($keys) ) {
			global $wpdb;
			$sql = $wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				   FROM {$wpdb->postmeta} pm
				   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE p.post_type = %s
				    AND pm.meta_key <> ''
				  LIMIT %d",
				$cpt,
				(int) $limit
			);
			$rows = $wpdb->get_col($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $k ) {
				$k = (string) $k;
				// Skip noisy internals
				if ( preg_match('/^(_edit_|_elementor|_yoast_|_aioseo_|_oembed_|_wp_|_thumbnail_id)/', $k) ) {
					continue;
				}
				$keys[$k] = $k;
			}
		}

		natcasesort($keys);
		return array_values($keys);
	}

	/**
<<<<<<< HEAD
	 * Return an assoc set of meta keys JetEngine defines for a CPT.
	 * Reads both: CPT “Meta fields” and Meta Boxes targeting the CPT.
	 *
	 * @param  string $cpt
	 * @return array [ meta_key => true, ... ]
=======
	 * Compact little component to render a <select> of meta keys with a “Custom…” input.
	 *   - $current: the saved key (string).
     *   - $choices: array of available keys (strings).
	 *
	 * Returns the HTML string (so callers can place it inside tables).
>>>>>>> main
	 */
	public static function render_meta_key_picker_html( string $field_name, string $current, array $choices, string $placeholder = '— Select meta key —' ): string {
		$current   = (string) $current;
		$use_custom = ($current !== '' && ! in_array($current, $choices, true));

		ob_start();
		?>
		<select name="<?php echo esc_attr($field_name . '_select'); ?>" data-bfr-target="<?php echo esc_attr($field_name); ?>">
			<option value="" <?php selected( ! $use_custom && $current === '' ); ?>>
				<?php echo esc_html($placeholder); ?>
			</option>
			<?php foreach ($choices as $k): ?>
				<option value="<?php echo esc_attr($k); ?>" <?php selected( ! $use_custom && $current === $k ); ?>><?php echo esc_html($k); ?></option>
			<?php endforeach; ?>
			<option value="__custom__" <?php selected( $use_custom ); ?>><?php echo esc_html('Custom…'); ?></option>
		</select>
		<input type="text"
			   class="regular-text bfr-meta-custom <?php echo $use_custom ? '' : 'hidden'; ?>"
			   data-bfr-for="<?php echo esc_attr($field_name); ?>"
			   name="<?php echo esc_attr($field_name); ?>"
			   value="<?php echo esc_attr($current); ?>"
			   placeholder="<?php echo esc_attr('Type meta key when using “Custom…”'); ?>" />
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One-time JS for toggling the “Custom…” input.
	 */
	public static function print_picker_js_once(): void {
		static $printed = false;
		if ( $printed ) return;
		$printed = true;
		?>
		<style>.hidden{display:none}</style>
		<script>
		document.addEventListener('change', function (ev) {
			var sel = ev.target;
			if (!sel.matches('select[name$="_select"]')) return;
			var base = sel.name.replace(/_select$/, '');
			var input = document.querySelector('input.bfr-meta-custom[data-bfr-for="'+ base +'"]');
			if (!input) return;
			if (sel.value === '__custom__') {
				input.classList.remove('hidden');
				input.removeAttribute('hidden');
				input.focus();
			} else {
				input.value = sel.value || '';
				input.classList.add('hidden');
				input.setAttribute('hidden', 'hidden');
			}
		});
		</script>
		<?php
	}
}