<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Small grab-bag of helpers used across the plugin.
 */
final class BFR_Helpers {

	/**
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
									}
								}
							}
						}
					}
				}
			} catch ( \Throwable $e ) { /* swallow */ }
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
	 * Compact little component to render a <select> of meta keys with a “Custom…” input.
	 *   - $current: the saved key (string).
     *   - $choices: array of available keys (strings).
	 *
	 * Returns the HTML string (so callers can place it inside tables).
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