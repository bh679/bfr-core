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

		// JetEngine Relation (select; blank allowed)
		add_settings_field('je_relation', 'JetEngine Relation Slug', function(){
			$agg   = BFR_Aggregator::instance();
			$opts  = wp_parse_args( get_option('bfr_core_options', []), $agg->defaults() );
			$choices = $this->get_relation_choices(); // may be empty
			echo '<select name="bfr_core_options[je_relation]">';
			// First option = disabled
			printf('<option value=""%s>%s</option>', selected($opts['je_relation'], '', false), esc_html('— Disabled (use meta only) —'));
			foreach ($choices as $slug => $label) {
				printf('<option value="%s"%s>%s</option>',
					esc_attr($slug),
					selected($opts['je_relation'], $slug, false),
					esc_html($label . " ($slug)")
				);
			}
			echo '</select>';
			echo '<p class="description">If you leave this blank, BFR will only use the School meta key for linking (default: destination_id).</p>';
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

	// Strategy A: JetEngine Relations Manager (most stable)
	if ( class_exists('\Jet_Engine\Relations\Manager') ) {
		$mgr = \Jet_Engine\Relations\Manager::instance();
		if ( method_exists($mgr, 'get_relations') ) {
			$rels = $mgr->get_relations(); // array of Relation objects
			if ( is_array($rels) ) {
				foreach ( $rels as $rel ) {
					// Newer versions expose ->get_args(); also keep fallbacks
					$slug  = '';
					$name  = '';

					if ( method_exists($rel, 'get_args') ) {
						$args = $rel->get_args();
						$slug = isset($args['slug']) ? (string) $args['slug'] : '';
						$name = isset($args['name']) ? (string) $args['name'] : '';
					}

					// Some builds expose ->get_id() / ->get_label()
					if ( ! $slug && method_exists($rel, 'get_id') ) {
						$slug = (string) $rel->get_id();
					}
					if ( ! $name && method_exists($rel, 'get_label') ) {
						$name = (string) $rel->get_label();
					}

					if ( $slug !== '' ) {
						$choices[$slug] = $name ?: $slug;
					}
				}
			}
		}
	}

	// Strategy B: Legacy access via jet_engine()->relations component
	if ( empty($choices) && function_exists('jet_engine') && isset( jet_engine()->relations ) ) {
		$rels = jet_engine()->relations;
		if ( method_exists($rels, 'get_component') ) {
			$component = $rels->get_component();
			// Some versions have ->get_relations(); others ->relations (prop)
			if ( method_exists($component, 'get_relations') ) {
				$list = $component->get_relations();
			} elseif ( isset($component->relations) ) {
				$list = $component->relations;
			} else {
				$list = [];
			}
			if ( is_array($list) ) {
				foreach ($list as $rel) {
					$slug = '';
					$name = '';
					// Array shape
					if ( is_array($rel) ) {
						$slug = isset($rel['slug']) ? (string) $rel['slug'] : '';
						$name = isset($rel['name']) ? (string) $rel['name'] : '';
					// Object shape (Relation)
					} elseif ( is_object($rel) ) {
						if ( method_exists($rel, 'get_args') ) {
							$args = $rel->get_args();
							$slug = isset($args['slug']) ? (string) $args['slug'] : '';
							$name = isset($args['name']) ? (string) $args['name'] : '';
						}
						if ( ! $slug && method_exists($rel, 'get_id') ) $slug = (string) $rel->get_id();
						if ( ! $name && method_exists($rel, 'get_label') ) $name = (string) $rel->get_label();
					}
					if ($slug !== '') $choices[$slug] = $name ?: $slug;
				}
			}
		}
	}

	natcasesort($choices);
	return $choices;
}
}