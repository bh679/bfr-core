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

	/* ---------- Settings & fields ---------- */

	public function register_settings() {
		$agg = BFR_Aggregator::instance();

		register_setting('bfr_core_group', 'bfr_core_options', [
			'sanitize_callback' => function($input) use ($agg){
				$input = is_array($input) ? $input : [];

				// Allowed values for selects
				$valid_cpts = $this->get_cpt_choices();              // slug => label
				$valid_rel  = $this->get_relation_choices();         // slug => label (may be empty)

				// Sanitize select fields
				$input['dest_cpt']   = isset($input['dest_cpt'],   $valid_cpts[$input['dest_cpt']])   ? $input['dest_cpt']   : $agg->defaults()['dest_cpt'];
				$input['school_cpt'] = isset($input['school_cpt'], $valid_cpts[$input['school_cpt']]) ? $input['school_cpt'] : $agg->defaults()['school_cpt'];

				// Relation slug: allow blank or a known relation
				if (isset($input['je_relation']) && $input['je_relation'] !== '') {
					$input['je_relation'] = isset($valid_rel[$input['je_relation']]) ? $input['je_relation'] : $agg->defaults()['je_relation'];
				} else {
					$input['je_relation'] = ''; // explicitly disable relation path
				}

				// Text fields
				foreach (['meta_dest_id','meta_max_depth','meta_price','meta_languages','meta_facilities'] as $k) {
					if (isset($input[$k])) $input[$k] = sanitize_text_field( (string) $input[$k] );
				}

				// Backfill any missing keys with defaults
				return wp_parse_args($input, $agg->defaults());
			}
		]);

		add_settings_section('bfr_core_section', 'General Settings', function(){
			echo '<p>Pick your CPTs, relation slug (optional), and meta keys used for aggregation.</p>';
		}, 'bfr-core');

		// Destination CPT (select)
		add_settings_field('dest_cpt', 'Destination CPT Slug', function(){
			$agg   = BFR_Aggregator::instance();
			$opts  = wp_parse_args( get_option('bfr_core_options', []), $agg->defaults() );
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

		// School CPT (select)
		add_settings_field('school_cpt', 'School CPT Slug', function(){
			$agg   = BFR_Aggregator::instance();
			$opts  = wp_parse_args( get_option('bfr_core_options', []), $agg->defaults() );
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

		// JetEngine Relation (select; blank allowed; fallback to text input if none)
		add_settings_field('je_relation', 'JetEngine Relation Slug', function(){
			$agg     = BFR_Aggregator::instance();
			$opts    = wp_parse_args( get_option('bfr_core_options', []), $agg->defaults() );
			$choices = $this->get_relation_choices();

			if (!empty($choices)) {
				echo '<select name="bfr_core_options[je_relation]">';
				printf('<option value=""%s>%s</option>', selected($opts['je_relation'], '', false), esc_html('— Disabled (use meta only) —'));
				foreach ($choices as $slug => $label) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr($slug),
						selected($opts['je_relation'], $slug, false),
						esc_html($label . " ($slug)")
					);
				}
				echo '</select>';
				echo '<p class="description">Pick your JetEngine relation, or choose Disabled to rely on the School meta key only.</p>';
			} else {
				// Fallback: no relations detected — show a text box (never crash)
				printf(
					'<input type="text" class="regular-text" name="bfr_core_options[je_relation]" value="%s" placeholder="%s" />',
					esc_attr($opts['je_relation']),
					esc_attr('e.g. destination-to-school (leave blank to disable)')
				);
				echo '<p class="description">No JetEngine relations detected. If you have one, type its slug manually or leave blank to disable.</p>';
			}
		}, 'bfr-core', 'bfr_core_section');

		// Text inputs for meta keys
		foreach ([
			'meta_dest_id'    => 'School Meta → Destination ID key',
			'meta_max_depth'  => 'School Meta: Max Depth key',
			'meta_price'      => 'School Meta: Lowest Course Price key',
			'meta_languages'  => 'School Meta: Languages key',
			'meta_facilities' => 'School Meta: Facilities key',
		] as $key => $label) {
			add_settings_field($key, $label, function() use ($key){
				$agg  = BFR_Aggregator::instance();
				$opts = wp_parse_args( get_option('bfr_core_options', []), $agg->defaults() );
				printf('<input type="text" class="regular-text" name="bfr_core_options[%1$s]" value="%2$s" />',
					esc_attr($key), esc_attr($opts[$key] ?? '')
				);
			}, 'bfr-core', 'bfr_core_section');
		}
	}

	public function render_settings() {
		if ( ! current_user_can('manage_options') ) return;
		$agg  = BFR_Aggregator::instance();
		$opts = wp_parse_args( get_option('bfr_core_options', []), $agg->defaults() );
		?>
		<div class="wrap">
			<h1>BFR Core</h1>
			<form method="post" action="options.php">
				<?php settings_fields('bfr_core_group'); ?>
				<?php do_settings_sections('bfr-core'); ?>
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
				<li><strong>Meta keys:</strong>
					dest_id=<code><?php echo esc_html($opts['meta_dest_id']); ?></code>,
					max_depth=<code><?php echo esc_html($opts['meta_max_depth']); ?></code>,
					price=<code><?php echo esc_html($opts['meta_price']); ?></code>,
					langs=<code><?php echo esc_html($opts['meta_languages']); ?></code>,
					facils=<code><?php echo esc_html($opts['meta_facilities']); ?></code>
				</li>
			</ul>

			<?php if ( isset($_GET['bfr_debug']) && current_user_can('manage_options') ) : ?>
	<hr/>
	<h2>Debug: JetEngine relations snapshot</h2>
	<pre style="max-height:300px;overflow:auto;background:#fafafa;border:1px solid #ddd;padding:10px;"><?php
		$dump = [];
		try {
			$dump['has_function_jet_engine'] = function_exists('jet_engine');
			if ( function_exists('jet_engine') ) {
				$je = jet_engine();
				$dump['has_relations_prop'] = isset($je->relations);
				if ( isset($je->modules) && method_exists($je->modules, 'get_module') ) {
					$rel_module = $je->modules->get_module('relations');
					if ($rel_module) {
						$dump['modules_relations_class'] = get_class($rel_module);
						if ( isset($rel_module->relations) ) {
							$mgr = $rel_module->relations;
							$dump['modules_relations_mgr_class'] = is_object($mgr) ? get_class($mgr) : gettype($mgr);
							if ( is_object($mgr) && method_exists($mgr, 'get_relations') ) {
								$rels = $mgr->get_relations();
								$dump['modules_relations_count'] = is_array($rels) ? count($rels) : 'not-array';
							}
						}
					}
				}
				if ( isset($je->relations) ) {
					$comp = $je->relations;
					$dump['component_relations_class'] = is_object($comp) ? get_class($comp) : gettype($comp);
					if ( is_object($comp) && method_exists($comp, 'get_component') ) {
						$c = $comp->get_component();
						$dump['component_get_component_class'] = is_object($c) ? get_class($c) : gettype($c);
						if ( is_object($c) && method_exists($c, 'get_relations') ) {
							$list = $c->get_relations();
							$dump['component_relations_count'] = is_array($list) ? count($list) : 'not-array';
						}
					}
				}
			}
			$opt = get_option('jet_engine_relations');
			$dump['option_relations_count'] = is_array($opt) ? count($opt) : 'not-array';
		} catch (\Throwable $e) {
			$dump['error'] = $e->getMessage();
		}
		echo esc_html( print_r($dump, true) );
	?></pre>
	<p class="description">Open this page with <code>&bfr_debug=1</code> to see this panel. Remove after we’re done.</p>
<?php endif; ?>
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

	/**
	 * Return CPT choices suitable for a settings dropdown.
	 * We include public post types with UI, minus built-in "attachment", "revision", etc.
	 */
	private function get_cpt_choices(): array {
		$builtin_exclude = ['attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block','wp_template','wp_template_part','wp_navigation','elementor_library'];
		$types = get_post_types(['show_ui' => true], 'objects');
		$out = [];
		foreach ($types as $slug => $obj) {
			if ( in_array($slug, $builtin_exclude, true) ) continue;
			$out[$slug] = $obj->labels->singular_name ?: $obj->label ?: $slug;
		}
		// Sort by label for nice UX
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
			// ---------- Strategy A: Modules API (JetEngine 3.x+)
			if ( function_exists('jet_engine') && method_exists(jet_engine(), 'modules') ) {
				$modules = jet_engine()->modules;
				if ( $modules && method_exists($modules, 'get_module') ) {
					$rel_module = $modules->get_module('relations'); // object or null
					if ( $rel_module ) {
						// common shapes observed across builds
						if ( isset($rel_module->relations) && is_object($rel_module->relations) ) {
							$mgr = $rel_module->relations;
							if ( method_exists($mgr, 'get_relations') ) {
								$list = $mgr->get_relations();
								if ( is_array($list) ) {
									foreach ($list as $rel) {
										$slug = ''; $name = '';
										if ( is_object($rel) && method_exists($rel, 'get_args') ) {
											$args = $rel->get_args();
											$slug = isset($args['slug']) ? (string)$args['slug'] : '';
											$name = isset($args['name']) ? (string)$args['name'] : '';
										} elseif ( is_array($rel) ) {
											$slug = isset($rel['slug']) ? (string)$rel['slug'] : '';
											$name = isset($rel['name']) ? (string)$rel['name'] : '';
										}
										if ( ! $slug && is_object($rel) && method_exists($rel, 'get_id') )    $slug = (string) $rel->get_id();
										if ( ! $name && is_object($rel) && method_exists($rel, 'get_label') ) $name = (string) $rel->get_label();
										$add($slug, $name);
									}
								}
							}
						}
					}
				}
			}

			// ---------- Strategy B: Legacy component (older builds)
			if ( empty($choices) && function_exists('jet_engine') && isset(jet_engine()->relations) ) {
				$rel_comp = jet_engine()->relations;
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
					foreach ($list as $rel) {
						$slug = ''; $name = '';
						if ( is_array($rel) ) {
							$slug = isset($rel['slug']) ? (string)$rel['slug'] : '';
							$name = isset($rel['name']) ? (string)$rel['name'] : '';
						} elseif ( is_object($rel) ) {
							if ( method_exists($rel, 'get_args') ) {
								$args = $rel->get_args();
								$slug = isset($args['slug']) ? (string)$args['slug'] : '';
								$name = isset($args['name']) ? (string)$args['name'] : '';
							}
							if ( ! $slug && method_exists($rel, 'get_id') )    $slug = (string) $rel->get_id();
							if ( ! $name && method_exists($rel, 'get_label') ) $name = (string) $rel->get_label();
						}
						$add($slug, $name);
					}
				}
			}

			// ---------- Strategy C: Option fallback (some sites cache relation args)
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
			// swallow; we always return an array
		}

		if ( ! empty($choices) ) {
			natcasesort($choices);
		}

		return $choices;
	}
}