<?php

/**
 * Admin UI and actions for BFR Core.
 *
 * Responsibilities:
 * - Adds “BFR Core” settings page under Settings.
 * - Registers/sanitizes plugin options:
 *     dest_cpt, school_cpt, je_relation,
 *     meta_dest_id, meta_max_depth, meta_price, meta_languages, meta_facilities.
 * - Renders fields (with meta-key picker + “Custom…” input).
 * - “Recalculate all now” maintenance action (nonce-protected).
 * - Diagnostics table: verifies aggregate meta are registered and REST-exposed,
 *   and whether JetEngine also defines those fields (for Elementor pickers).
 *
 * Relies on Helpers for discovery of CPTs/meta/relations.
 *
 * @package   BFR\Core
 * @since     0.6.0
 * @internal  Loaded by Composer PSR-4 autoloading (namespace BFR\Core).
 */
namespace BFR\Core;

if (!defined('ABSPATH')) exit;

final class Admin {

	private static $instance = null;

	public static function instance(): self {
		return self::$instance ?: self::$instance = new self();
	}

	private function __construct() {
		add_action('admin_menu',  [$this, 'admin_menu']);
		add_action('admin_init',  [$this, 'register_settings']);
		add_action('admin_post_bfr_recalc', [$this, 'handle_recalc_now']);
	}

	/* ============================ Menu =========================== */

	public function admin_menu(): void {
		add_options_page('BFR Core', 'BFR Core', 'manage_options', 'bfr-core', [$this, 'render_settings']);
	}

	/* ====================== Settings & fields ==================== */

	public function register_settings(): void {

		register_setting('bfr_core_group', \BFR_CORE_OPTION, [
			'sanitize_callback' => function ($input) {
				$agg      = Aggregator::instance();
				$defaults = $agg->defaults();

				$input = is_array($input) ? $input : [];

				// For cache-busting comparisons
				$prev_opts   = get_option(\BFR_CORE_OPTION, []);
				$prev_dest   = is_array($prev_opts) ? ($prev_opts['dest_cpt']   ?? '') : '';
				$prev_school = is_array($prev_opts) ? ($prev_opts['school_cpt'] ?? '') : '';

				// --- CPT choices / relation choices ---
				$valid_cpts = Helpers::get_cpt_choices();      // slug => label
				$valid_rel  = Helpers::get_relation_choices(); // slug => label (may be empty)

				// Destination CPT
				if (isset($input['dest_cpt']) && isset($valid_cpts[$input['dest_cpt']])) {
					// ok
				} else {
					$input['dest_cpt'] = $defaults['dest_cpt'];
				}

				// School CPT
				if (isset($input['school_cpt']) && isset($valid_cpts[$input['school_cpt']])) {
					// ok
				} else {
					$input['school_cpt'] = $defaults['school_cpt'];
				}

				// If either CPT changed, clear meta-key caches so pickers re-pull from DB
				if ($prev_dest !== $input['dest_cpt']) {
					if ($prev_dest) delete_transient('bfr_meta_keys_' . sanitize_key($prev_dest));
					delete_transient('bfr_meta_keys_' . sanitize_key($input['dest_cpt']));
				}
				if ($prev_school !== $input['school_cpt']) {
					if ($prev_school) delete_transient('bfr_meta_keys_' . sanitize_key($prev_school));
					delete_transient('bfr_meta_keys_' . sanitize_key($input['school_cpt']));
				}

				// Relation slug: blank allowed; otherwise must be a known relation
				if (isset($input['je_relation']) && $input['je_relation'] !== '') {
					$input['je_relation'] = isset($valid_rel[$input['je_relation']])
						? $input['je_relation']
						: $defaults['je_relation'];
				} else {
					$input['je_relation'] = '';
				}

				// --- School meta key pickers (dropdown + custom) ---
				foreach (['meta_dest_id','meta_max_depth','meta_price','meta_languages','meta_facilities'] as $k) {
					$sel = $k . '_select';
					if (isset($input[$sel]) && $input[$sel] !== '__custom__') {
						$input[$k] = sanitize_text_field((string) $input[$sel]);
					} elseif (isset($input[$k])) {
						$input[$k] = sanitize_text_field((string) $input[$k]);
					} else {
						$input[$k] = $defaults[$k];
					}
				}

				// --- Destination meta keys (dropdown + custom, validate names) ---
				$sanitize_key_name = function($v, $fallback) {
					$v = is_string($v) ? trim($v) : '';
					// letters/numbers/_/- only, must start with a letter
					return ($v !== '' && preg_match('/^[a-z][a-z0-9_\-]*$/', $v)) ? $v : $fallback;
				};

				foreach ([
					'dest_meta_school_count',
					'dest_meta_max_depth',
					'dest_meta_min_course_price',
					'dest_meta_languages',
					'dest_meta_facilities',
					'dest_meta_languages_array',
					'dest_meta_facilities_array',
				] as $k) {
					$sel = $k . '_select';
					if (isset($input[$sel]) && $input[$sel] !== '__custom__') {
						// Chosen from dropdown (CPT keys)
						$input[$k] = sanitize_text_field((string) $input[$sel]);
					} else {
						// Custom (or empty) -> validate or fall back
						$input[$k] = $sanitize_key_name($input[$k] ?? '', $defaults[$k]);
					}
				}

				// Backfill missing + allow external filters
				$out = wp_parse_args($input, $defaults);
				return apply_filters('bfr_core_sanitized_options', $out);
			}
		]);

		add_settings_section('bfr_core_section', 'General Settings', function(){
			echo '<p>Pick your CPTs, relation (optional), and meta keys used for aggregation.</p>';
		}, 'bfr-core');

		// Destination CPT
		add_settings_field('dest_cpt', 'Destination CPT Slug', function() {
			$agg     = Aggregator::instance();
			$opts    = wp_parse_args(get_option(\BFR_CORE_OPTION, []), $agg->defaults());
			$choices = Helpers::get_cpt_choices();
			echo '<select name="'.esc_attr(\BFR_CORE_OPTION).'[dest_cpt]">';
			foreach ($dest_fields as $key => $label) {
			    add_settings_field($key, $label, function() use ($key, $label) {
			        $agg  = Aggregator::instance();
			        $opts = wp_parse_args(get_option(\BFR_CORE_OPTION, []), $agg->defaults());

			        // IMPORTANT: use Destination CPT and restrict to JetEngine-active, user-visible keys
			        echo Helpers::meta_key_picker_html(
			            $key,
			            $label,
			            $opts,
			            $opts['dest_cpt'] ?? 'destinations',
			            true // <-- only_jetengine_active
			        );

			        echo '<p class="description">Letters, numbers, underscore, hyphen. Must start with a letter.</p>';
			    }, 'bfr-core', 'bfr_core_dest_keys');
			}
			echo '</select>';
		}, 'bfr-core', 'bfr_core_section');

		// School CPT
		add_settings_field('school_cpt', 'School CPT Slug', function() {
			$agg     = Aggregator::instance();
			$opts    = wp_parse_args(get_option(\BFR_CORE_OPTION, []), $agg->defaults());
			$choices = Helpers::get_cpt_choices();
			echo '<select name="'.esc_attr(\BFR_CORE_OPTION).'[school_cpt]">';
			foreach ($choices as $slug => $label) {
				printf('<option value="%s"%s>%s</option>',
					esc_attr($slug),
					selected($opts['school_cpt'], $slug, false),
					esc_html($label . " ($slug)")
				);
			}
			echo '</select>';
		}, 'bfr-core', 'bfr_core_section');

		add_settings_section(
		    'bfr_core_dest_keys',
		    'Destination Meta Keys (Outputs)',
		    function(){
		        echo '<p>Customize the meta keys BFR writes to the Destination posts. Choose an existing key from the Destination CPT or select “Custom…”.</p>';
		    },
		    'bfr-core'
		);

		$dest_fields = [
		    'dest_meta_school_count'     => 'Dest Meta: School Count',
		    'dest_meta_max_depth'        => 'Dest Meta: Max Depth',
		    'dest_meta_min_course_price' => 'Dest Meta: Min Course Price',
		    'dest_meta_languages'        => 'Dest Meta: Languages (CSV)',
		    'dest_meta_facilities'       => 'Dest Meta: Facilities (CSV)',
		    'dest_meta_languages_array'  => 'Dest Meta: Languages (JSON array)',
		    'dest_meta_facilities_array' => 'Dest Meta: Facilities (JSON array)',
		];

		foreach ($dest_fields as $key => $label) {
		    add_settings_field($key, $label, function() use ($key, $label) {
		        $agg  = Aggregator::instance();
		        $opts = wp_parse_args(get_option(\BFR_CORE_OPTION, []), $agg->defaults());
		        // IMPORTANT: use Destination CPT for the dropdown source
		        echo Helpers::meta_key_picker_html($key, $label, $opts, $opts['dest_cpt'] ?? 'destinations');
		        echo '<p class="description">Letters, numbers, underscore, hyphen. Must start with a letter.</p>';
		    }, 'bfr-core', 'bfr_core_dest_keys');
		}

		// Relation
		add_settings_field('je_relation', 'JetEngine Relation Slug', function() {
			$agg     = Aggregator::instance();
			$opts    = wp_parse_args(get_option(\BFR_CORE_OPTION, []), $agg->defaults());
			$choices = Helpers::get_relation_choices();
			$name    = esc_attr(\BFR_CORE_OPTION).'[je_relation]';

			if (!empty($choices)) {
				echo '<select name="'.$name.'">';
				printf('<option value=""%s>%s</option>', selected($opts['je_relation'], '', false), esc_html('— Disabled (use meta only) —'));
				foreach ($choices as $slug => $label) {
					printf('<option value="%s"%s>%s</option>',
						esc_attr($slug),
						selected($opts['je_relation'], $slug, false),
						esc_html($label . " ($slug)")
					);
				}
				echo '</select>';
				echo '<p class="description">Pick your relation, or disable to rely on the School meta link only.</p>';
			} else {
				printf(
					'<input type="text" class="regular-text" name="%s" value="%s" placeholder="%s" />',
					$name,
					esc_attr($opts['je_relation']),
					esc_attr('e.g. destination-to-school (leave blank to disable)')
				);
				echo '<p class="description">No JetEngine relations detected. Type the slug or leave blank to disable.</p>';
			}
		}, 'bfr-core', 'bfr_core_section');

		// Meta key pickers
		$meta_labels = [
			'meta_dest_id'    => 'School Meta → Destination ID key',
			'meta_max_depth'  => 'School Meta: Max Depth key',
			'meta_price'      => 'School Meta: Lowest Course Price key',
			'meta_languages'  => 'School Meta: Languages key',
			'meta_facilities' => 'School Meta: Facilities key',
		];
		foreach ($meta_labels as $key => $label) {
			add_settings_field($key, $label, function() use ($key, $label) {
				$agg  = Aggregator::instance();
				$opts = wp_parse_args(get_option(\BFR_CORE_OPTION, []), $agg->defaults());
				echo Helpers::meta_key_picker_html($key, $label, $opts);
			}, 'bfr-core', 'bfr_core_section');
		}
	}

	public function render_settings(): void {
		if (!current_user_can('manage_options')) return;

		$agg  = Aggregator::instance();
		$opts = wp_parse_args(get_option(\BFR_CORE_OPTION, []), $agg->defaults());

		// Handle "Refresh meta keys" action (clears cached meta-key lists)
		if (isset($_GET['bfr_refresh_keys']) && isset($_GET['_wpnonce'])
		    && wp_verify_nonce($_GET['_wpnonce'], 'bfr_refresh_keys')) {

			$dest   = is_string($opts['dest_cpt'] ?? '')   ? $opts['dest_cpt']   : 'destinations';
			$school = is_string($opts['school_cpt'] ?? '') ? $opts['school_cpt'] : 'freedive-school';

			$dk1 = 'bfr_meta_keys_' . sanitize_key($dest);
			$dk2 = 'bfr_meta_keys_' . sanitize_key($school);

			// Clear transients (single + multisite just in case)
			delete_transient($dk1);
			delete_transient($dk2);
			delete_site_transient($dk1);
			delete_site_transient($dk2);

			// Show admin notice
			add_settings_error(
				'bfr-core',
				'bfr_core_meta_keys_refreshed',
				sprintf(
					'Meta key caches cleared for CPTs: %s and %s.',
					esc_html($dest),
					esc_html($school)
				),
				'updated'
			);

			// Clean URL (remove action + nonce)
			$clean_url = remove_query_arg(['bfr_refresh_keys', '_wpnonce']);
			echo '<script>history.replaceState(null, "", ' . wp_json_encode(esc_url_raw($clean_url)) . ');</script>';
		}

		$dest_cpt = $opts['dest_cpt'] ?? 'destinations';

		$keys = [
			$opts['dest_meta_school_count']     => 'integer',
			$opts['dest_meta_max_depth']        => 'number',
			$opts['dest_meta_min_course_price'] => 'number',
			$opts['dest_meta_languages']        => 'string',
			$opts['dest_meta_facilities']       => 'string',
		];
		$expected_meta = $keys;

		$registered = function_exists('get_registered_meta_keys')
			? (get_registered_meta_keys('post', $dest_cpt) ?: [])
			: [];

		$je_defined = Helpers::get_jetengine_meta_keys_for_cpt($dest_cpt);


		$refresh_url = add_query_arg(
		    [
		        'bfr_refresh_keys' => '1',
		        '_wpnonce'         => wp_create_nonce('bfr_refresh_keys'),
		    ]
		);

		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:.5rem">
				BFR Core
				<a class="button button-secondary button-small" href="<?php echo esc_url($refresh_url); ?>">
					Refresh meta keys
				</a>
			</h1>

			<?php settings_errors('bfr-core'); ?>

			<form method="post" action="options.php">
				<?php settings_fields('bfr_core_group'); ?>
				<?php do_settings_sections('bfr-core'); ?>
				<?php submit_button('Save Changes'); ?>
			</form>

			<hr/>
			<h2>Maintenance</h2>
			<p>Run a full recompute of all Destinations (safe anytime).</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="bfr_recalc" />
				<?php wp_nonce_field('bfr_recalc_now'); ?>
				<?php submit_button('Recalculate all now', 'secondary'); ?>
			</form>

			<hr/>
			<h2>Destination Meta Registration Status</h2>
			<table class="widefat striped" style="max-width:980px">
				<thead>
				<tr>
					<th>Meta key</th>
					<th>Status</th>
					<th>Type</th>
					<th>REST</th>
					<th>JE UI</th>
					<th>Notes</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($expected_meta as $key => $want_type):
					$exists = isset($registered[$key]);
					$type   = $exists ? ($registered[$key]['type'] ?? '(unknown)') : '(not registered)';
					$rest   = $exists && !empty($registered[$key]['show_in_rest']) ? 'on' : 'off';
					$ok     = $exists && ($type === $want_type) && ($rest === 'on');
					$in_je  = isset($je_defined[$key]);
					$msg    = $ok ? 'OK' : ($exists ? 'Type/REST mismatch' : 'Not registered');
					?>
					<tr>
						<td><code><?php echo esc_html($key); ?></code></td>
						<td><?php echo $ok ? '✅ Registered' : ($exists ? '⚠️ Issue' : '❌ Missing'); ?></td>
						<td><?php echo esc_html($type); ?></td>
						<td><?php echo $rest === 'on' ? 'on' : 'off'; ?></td>
						<td><?php echo $in_je ? '✅ yes' : '—'; ?></td>
						<td><?php echo esc_html($msg); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if (isset($_GET['bfr_debug']) && current_user_can('manage_options')): ?>
				<hr/>
				<h2>Debug: JetEngine relations snapshot</h2>
				<pre style="max-height:300px;overflow:auto;background:#fafafa;border:1px solid #ddd;padding:10px;"><?php
					$dump = [];
					try {
						$dump['has_function_jet_engine'] = function_exists('jet_engine');
						if (function_exists('jet_engine')) {
							$je = jet_engine();
							$dump['has_relations_prop'] = isset($je->relations);
							if (isset($je->modules) && method_exists($je->modules, 'get_module')) {
								$rel_module = $je->modules->get_module('relations');
								if ($rel_module) {
									$dump['component_relations_class'] = get_class($rel_module);
								}
							}
						}
					} catch (\Throwable $e) {
						$dump['error'] = $e->getMessage();
					}
					echo esc_html(print_r($dump, true));
				?></pre>
				<p class="description">Open this page with <code>&bfr_debug=1</code> to see this panel.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =========================== Actions ========================= */

	public function handle_recalc_now(): void {
		if (!current_user_can('manage_options') || !check_admin_referer('bfr_recalc_now')) {
			wp_die('Not allowed');
		}
		Aggregator::instance()->recalc_all();
		$target = wp_get_referer() ?: admin_url('options-general.php?page=bfr-core');
		wp_safe_redirect(add_query_arg('bfr_recalc_done', '1', $target));
		exit;
	}
}