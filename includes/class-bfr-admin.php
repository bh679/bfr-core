<?php
if ( ! defined('ABSPATH') ) exit;

final class BFR_Admin {

	private static $instance = null;

	public static function instance() {
		return self::$instance ?: self::$instance = new self();
	}

	private function __construct() {
		add_action('admin_menu',  [$this, 'admin_menu']);
		add_action('admin_init',  [$this, 'register_settings']);
		add_action('admin_post_bfr_recalc', [$this, 'handle_recalc_now']);
	}

	/* ---------- Menu ---------- */
	public function admin_menu() {
		add_options_page('BFR Core', 'BFR Core', 'manage_options', 'bfr-core', [$this, 'render_settings']);
	}

	/* ---------- Settings ---------- */
	public function register_settings() {

		register_setting('bfr_core_group', 'bfr_core_options', [
			'sanitize_callback' => function( $input ) {

				$opts = BFR_Helpers::get_opts();
				$input = is_array($input) ? $input : [];

				// Validate CPT selects
				$cpts = $this->get_cpt_choices();
				foreach ( ['dest_cpt','school_cpt'] as $cpt_key ) {
					if ( isset($input[$cpt_key]) && isset($cpts[ $input[$cpt_key] ]) ) {
						// ok
					} else {
						$input[$cpt_key] = $opts[$cpt_key] ?? 'destinations';
					}
				}

				// Relation slug (optional)
				$rels = $this->get_relation_choices();
				if ( isset($input['je_relation']) && $input['je_relation'] !== '' ) {
					$input['je_relation'] = isset($rels[ $input['je_relation'] ]) ? $input['je_relation'] : '';
				} else {
					$input['je_relation'] = '';
				}

				// School-side meta keys (text)
				foreach ( ['meta_dest_id','meta_max_depth','meta_price','meta_languages','meta_facilities'] as $k ) {
					if ( isset($input[$k]) ) $input[$k] = sanitize_text_field( (string) $input[$k] );
				}

				// Destination OUTPUT keys – handle picker (“_select”) + custom
				$out_keys = ['out_school_count','out_max_depth','out_min_price','out_languages','out_facilities'];
				foreach ( $out_keys as $k ) {
					$sel = isset($input[$k . '_select']) ? (string) $input[$k . '_select'] : '';
					$val = isset($input[$k]) ? (string) $input[$k] : '';
					if ( $sel && $sel !== '__custom__' ) {
						$input[$k] = sanitize_text_field($sel);
					} else {
						$input[$k] = sanitize_text_field($val);
					}
					unset($input[$k . '_select']);
				}

				// Backfill with existing values/defaults
				return wp_parse_args( $input, $opts );
			}
		]);

		// Section: General Settings
		add_settings_section('bfr_core_section', 'General Settings', function(){
			echo '<p>Pick your CPTs, relation slug (optional), and meta keys used for aggregation.</p>';
		}, 'bfr-core');

		// Destination CPT
		add_settings_field('dest_cpt', 'Destination CPT Slug', function(){
			$opts    = BFR_Helpers::get_opts();
			$choices = $this->get_cpt_choices();
			echo '<select name="bfr_core_options[dest_cpt]">';
			foreach ($choices as $slug => $label) {
				printf('<option value="%s"%s>%s</option>',
					esc_attr($slug),
					selected($opts['dest_cpt'], $slug, false),
					esc_html($label . " ($slug)")
				);
			}
			echo '</select>';
		}, 'bfr-core', 'bfr_core_section');

		// School CPT
		add_settings_field('school_cpt', 'School CPT Slug', function(){
			$opts    = BFR_Helpers::get_opts();
			$choices = $this->get_cpt_choices();
			echo '<select name="bfr_core_options[school_cpt]">';
			foreach ($choices as $slug => $label) {
				printf('<option value="%s"%s>%s</option>',
					esc_attr($slug),
					selected($opts['school_cpt'], $slug, false),
					esc_html($label . " ($slug)")
				);
			}
			echo '</select>';
		}, 'bfr-core', 'bfr_core_section');

		// JetEngine Relation
		add_settings_field('je_relation', 'JetEngine Relation Slug', function(){
			$opts    = BFR_Helpers::get_opts();
			$choices = $this->get_relation_choices();
			if ( ! empty($choices) ) {
				echo '<select name="bfr_core_options[je_relation]">';
				printf('<option value=""%s>%s</option>', selected($opts['je_relation'], '', false), esc_html('— Disabled (use meta only) —'));
				foreach ($choices as $slug => $label) {
					printf('<option value="%s"%s>%s</option>',
						esc_attr($slug),
						selected($opts['je_relation'], $slug, false),
						esc_html($label . " ($slug)")
					);
				}
				echo '</select>';
			} else {
				printf(
					'<input type="text" class="regular-text" name="bfr_core_options[je_relation]" value="%s" placeholder="%s" />',
					esc_attr($opts['je_relation']),
					esc_attr('e.g. destination-to-school (leave blank to disable)')
				);
				echo '<p class="description">No JetEngine relations detected. Type the slug manually or leave blank to disable.</p>';
			}
		}, 'bfr-core', 'bfr_core_section');

		// School-side meta keys (text boxes)
		foreach ([
			'meta_dest_id'    => 'School Meta → Destination ID key',
			'meta_max_depth'  => 'School Meta: Max Depth key',
			'meta_price'      => 'School Meta: Lowest Course Price key',
			'meta_languages'  => 'School Meta: Languages key',
			'meta_facilities' => 'School Meta: Facilities key',
		] as $key => $label) {
			add_settings_field($key, $label, function() use ($key){
				$opts = BFR_Helpers::get_opts();
				printf('<input type="text" class="regular-text" name="bfr_core_options[%1$s]" value="%2$s" />',
					esc_attr($key), esc_attr($opts[$key] ?? '')
				);
			}, 'bfr-core', 'bfr_core_section');
		}
	}

	/* ---------- Render page ---------- */
	public function render_settings() {
		if ( ! current_user_can('manage_options') ) return;

		$opts       = BFR_Helpers::get_opts();
		$dest_cpt   = (string) ($opts['dest_cpt'] ?? 'destinations');

		// Choices for Destination OUTPUT meta keys (from JE meta box fields etc.)
		$dest_key_choices = BFR_Helpers::get_destination_meta_keys( $dest_cpt );

		?>
		<div class="wrap">
			<h1>BFR Core</h1>

			<form method="post" action="options.php">
				<?php settings_fields('bfr_core_group'); ?>
				<?php do_settings_sections('bfr-core'); ?>

				<hr/>
				<h2>Destination Meta Registration & Mapping</h2>
				<p>Map each <em>aggregated</em> value to a Destination meta key. Pick an existing key from your Destination Meta Boxes / Meta fields, or choose <strong>Custom…</strong> to type a key name. These will also be registered for REST/Elementor.</p>

				<table class="widefat striped" style="max-width:1000px">
					<thead>
						<tr>
							<th style="width:260px">Aggregate</th>
							<th>Destination Meta key</th>
							<th style="width:120px">Type</th>
							<th style="width:80px">REST</th>
						</tr>
					</thead>
					<tbody>
						<?php
						BFR_Helpers::print_picker_js_once();

						$rows = [
							['out_school_count', 'Number of schools', 'integer'],
							['out_max_depth',    'Max depth',         'number'],
							['out_min_price',    'Lowest course price','number'],
							['out_languages',    'Languages (CSV)',    'string'],
							['out_facilities',   'Facilities (CSV)',   'string'],
						];

						foreach ( $rows as [$opt_key, $label, $type] ):
							$field_name = 'bfr_core_options['.$opt_key.']';
							$current    = (string) ($opts[$opt_key] ?? '');
							$picker     = BFR_Helpers::render_meta_key_picker_html( $field_name, $current, $dest_key_choices );
							?>
							<tr>
								<td><code><?php echo esc_html($label); ?></code></td>
								<td><?php echo $picker; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><code><?php echo esc_html($type); ?></code></td>
								<td>on</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button('Save Changes'); ?>
			</form>

			<hr/>
			<h2>Maintenance</h2>
			<p>Run a full recompute of all Destinations (safe anytime).</p>
			<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
				<input type="hidden" name="action" value="bfr_recalc" />
				<?php wp_nonce_field('bfr_recalc_now'); ?>
				<?php submit_button('Recalculate all now', 'secondary'); ?>
			</form>

			<hr/>
			<h2>Diagnostics</h2>
			<ul>
				<li><strong>Destination CPT:</strong> <?php echo esc_html($opts['dest_cpt']); ?></li>
				<li><strong>School CPT:</strong> <?php echo esc_html($opts['school_cpt']); ?></li>
				<li><strong>Relation slug:</strong> <?php echo $opts['je_relation'] ? esc_html($opts['je_relation']) : '<em>disabled</em>'; ?></li>
			</ul>
		</div>
		<?php
	}

	/* ---------- Handlers ---------- */
	public function handle_recalc_now() {
		if ( ! current_user_can('manage_options') || ! check_admin_referer('bfr_recalc_now') ) {
			wp_die('Not allowed');
		}
		BFR_Aggregator::instance()->cron_recalculate_all();
		wp_safe_redirect( add_query_arg('bfr_recalc_done', '1', wp_get_referer() ?: admin_url('options-general.php?page=bfr-core') ) );
		exit;
	}

	/* ---------- Helpers (choices) ---------- */

	private function get_cpt_choices(): array {
		$builtin_exclude = ['attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block','wp_template','wp_template_part','wp_navigation','elementor_library'];
		$types = get_post_types(['show_ui' => true], 'objects');
		$out = [];
		foreach ($types as $slug => $obj) {
			if ( in_array($slug, $builtin_exclude, true) ) continue;
			$out[$slug] = $obj->labels->singular_name ?: $obj->label ?: $slug;
		}
		natcasesort($out);
		return $out;
	}

	/**
	 * Return JetEngine relation choices if JetEngine is active.
	 * Key = relation slug, value = relation label.
	 */
	private function get_relation_choices(): array {
		$choices = [];

		$add = function($slug, $label = '') use (&$choices) {
			$slug = is_string($slug) ? trim($slug) : '';
			if ($slug === '') return;
			$choices[$slug] = $label !== '' ? $label : $slug;
		};

		try {
			// JetEngine >= 3.x modules
			if ( function_exists('jet_engine') && method_exists(jet_engine(), 'modules') ) {
				$modules = jet_engine()->modules;
				if ( $modules && method_exists($modules, 'get_module') ) {
					$rel_module = $modules->get_module('relations');
					if ( $rel_module && isset($rel_module->relations) ) {
						$mgr = $rel_module->relations;
						if ( is_object($mgr) ) {
							if ( method_exists($mgr, 'get_relations') ) {
								foreach ( (array) $mgr->get_relations() as $rel ) {
									$slug = $name = '';
									if ( is_object($rel) && method_exists($rel, 'get_args') ) {
										$args = $rel->get_args();
										$slug = (string)($args['slug'] ?? '');
										$name = (string)($args['name'] ?? '');
									} elseif ( is_array($rel) ) {
										$slug = (string)($rel['slug'] ?? '');
										$name = (string)($rel['name'] ?? '');
									}
									if ( ! $slug && is_object($rel) && method_exists($rel, 'get_id') )    $slug = (string) $rel->get_id();
									if ( ! $name && is_object($rel) && method_exists($rel, 'get_label') ) $name = (string) $rel->get_label();
									if ( $slug ) $add($slug, $name);
								}
							}
						}
					}
				}
			}

			// Legacy fallback
			if ( empty($choices) && function_exists('jet_engine') && isset(jet_engine()->relations) ) {
				$comp = jet_engine()->relations;
				if ( is_object($comp) && method_exists($comp, 'get_component') ) {
					$c = $comp->get_component();
					if ( $c ) {
						if ( method_exists($c, 'get_relations') ) {
							foreach ( (array) $c->get_relations() as $rel ) {
								$slug = (string) ($rel['slug'] ?? '');
								$name = (string) ($rel['name'] ?? '');
								if ( $slug ) $add($slug, $name);
							}
						} elseif ( isset($c->relations) && is_array($c->relations) ) {
							foreach ( $c->relations as $rel ) {
								$slug = (string) ($rel['slug'] ?? '');
								$name = (string) ($rel['name'] ?? '');
								if ( $slug ) $add($slug, $name);
							}
						}
					}
				}
			}

			// Option fallback
			if ( empty($choices) ) {
				$opt = get_option('jet_engine_relations');
				if ( is_array($opt) ) {
					foreach ( $opt as $rel ) {
						if ( is_array($rel) ) $add( (string)($rel['slug'] ?? ''), (string)($rel['name'] ?? '') );
					}
				}
			}
		} catch (\Throwable $e) { /* ignore */ }

		if ( ! empty($choices) ) natcasesort($choices);
		return $choices;
	}
}