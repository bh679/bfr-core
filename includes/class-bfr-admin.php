<?php

/*(settings page)*/

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

	/* ---------- Menu & Settings ---------- */

	public function admin_menu() {
		add_options_page('BFR Core', 'BFR Core', 'manage_options', 'bfr-core', [$this, 'render_settings']);
	}

	public function register_settings() {
		$agg = BFR_Aggregator::instance();

		register_setting('bfr_core_group', 'bfr_core_options', [
			'sanitize_callback' => function($input) use ($agg){
				$input = is_array($input) ? $input : [];
				foreach ($input as $k => $v) {
					$input[$k] = is_string($v) ? sanitize_text_field($v) : $v;
				}
				// backfill any missing keys with defaults
				return wp_parse_args($input, $agg->defaults());
			}
		]);

		add_settings_section('bfr_core_section', 'General Settings', function(){
			echo '<p>Set CPT slugs, relation slug, and meta keys used for aggregation.</p>';
		}, 'bfr-core');

		$fields = [
			'dest_cpt'        => 'Destination CPT Slug',
			'school_cpt'      => 'School CPT Slug',
			'je_relation'     => 'JetEngine Relation Slug (blank to disable)',
			'meta_dest_id'    => 'School Meta: destination_id key',
			'meta_max_depth'  => 'School Meta: max_depth key',
			'meta_price'      => 'School Meta: course_price key',
			'meta_languages'  => 'School Meta: languages key',
			'meta_facilities' => 'School Meta: facilities key',
		];

		foreach ($fields as $key => $label) {
			add_settings_field($key, $label, function() use ($key, $agg){
				$opts = get_option('bfr_core_options', []);
				$opts = wp_parse_args( is_array($opts) ? $opts : [], $agg->defaults() );
				printf(
					'<input type="text" class="regular-text" name="bfr_core_options[%1$s]" value="%2$s" />',
					esc_attr($key),
					esc_attr($opts[$key] ?? '')
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
}