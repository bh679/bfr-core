<?php
/**
 * Plugin Name: BFR Core
 * Description: Site-specific logic and destination aggregates for Book Freediving Retreats.
 * Author: Brennan Hatton
 * Version: 0.3.0
 */

if ( ! defined('ABSPATH') ) exit;

final class BFR_Core {

	private static $instance = null;
	private $cfg = [];

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Load options with defaults
		$this->cfg = wp_parse_args( get_option('bfr_core_options', []), [
			'dest_cpt'        => 'destination',
			'school_cpt'      => 'freedive-school',
			'je_relation'     => 'destination-to-school', // blank to disable relation path
			'meta_dest_id'    => 'destination_id',        // School → Destination meta key
			'meta_max_depth'  => 'max_depth',
			'meta_price'      => 'course_price',
			'meta_languages'  => 'languages',
			'meta_facilities' => 'facilities',
		]);

		/* Settings UI */
		add_action('admin_menu',  [$this, 'admin_menu']);
		add_action('admin_init',  [$this, 'register_settings']);
		add_action('admin_post_bfr_recalc', [$this, 'handle_recalc_now']);

		/* Cron */
		register_activation_hook(__FILE__,  [$this, 'activate']);
		register_deactivation_hook(__FILE__,[$this, 'deactivate']);
		add_action('bfr_destinations_recalc', [$this, 'cron_recalculate_all']);

		/* Triggers */
		add_action('save_post',      [$this, 'on_any_saved'], 20, 3);
		add_action('trashed_post',   [$this, 'on_any_trashed']);
		add_action('untrashed_post', [$this, 'on_any_untrashed']);

		/* Meta change (School → Destination) */
		add_action('added_post_meta',   [$this, 'on_meta_added'],   10, 4);
		add_action('updated_post_meta', [$this, 'on_meta_updated'], 10, 4);
		add_action('deleted_post_meta', [$this, 'on_meta_deleted'], 10, 4);
	}

	/* ================= Settings ================= */

	public function admin_menu() {
		add_options_page('BFR Core', 'BFR Core', 'manage_options', 'bfr-core', [$this, 'render_settings']);
	}

	public function register_settings() {
		register_setting('bfr_core_group', 'bfr_core_options', [
			'sanitize_callback' => function($input){
				if (!is_array($input)) return [];
				foreach ($input as $k => $v) {
					$input[$k] = is_string($v) ? sanitize_text_field($v) : $v;
				}
				return $input;
			}
		]);

		add_settings_section('bfr_core_section', 'General Settings', function(){
			echo '<p>Set CPT slugs, relation slug, and meta keys used for aggregation.</p>';
		}, 'bfr-core');

		$fields = [
			'dest_cpt'        => 'Destination CPT Slug',
			'school_cpt'      => 'School CPT Slug',
			'je_relation'     => 'JetEngine Relation Slug (leave blank to disable)',
			'meta_dest_id'    => 'School Meta: destination_id key',
			'meta_max_depth'  => 'School Meta: max_depth key',
			'meta_price'      => 'School Meta: course_price key',
			'meta_languages'  => 'School Meta: languages key',
			'meta_facilities' => 'School Meta: facilities key',
		];

		foreach ($fields as $key => $label) {
			add_settings_field($key, $label, function() use ($key){
				$opts = wp_parse_args( get_option('bfr_core_options', []), [] );
				$current = isset($opts[$key]) ? $opts[$key] : '';
				printf(
					'<input type="text" class="regular-text" name="bfr_core_options[%1$s]" value="%2$s" />',
					esc_attr($key),
					esc_attr($current)
				);
			}, 'bfr-core', 'bfr_core_section');
		}
	}

	public function render_settings() {
		if (!current_user_can('manage_options')) return;
		$opts = $this->cfg;
		?>
		<div class="wrap">
			<h1>BFR Core</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields('bfr_core_group');
				do_settings_sections('bfr-core');
				submit_button('Save Changes');
				?>
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

	public function handle_recalc_now() {
		if ( ! current_user_can('manage_options') || ! check_admin_referer('bfr_recalc_now') ) {
			wp_die('Not allowed');
		}
		$this->cron_recalculate_all();
		wp_safe_redirect( add_query_arg('bfr_recalc_done', '1', wp_get_referer() ?: admin_url('options-general.php?page=bfr-core') ) );
		exit;
	}

	/* ================= Cron ================= */

	public function activate() {
		if ( ! wp_next_scheduled('bfr_destinations_recalc') ) {
			// 03:17 server time
			$ts = strtotime('03:17:00');
			if ($ts === false) { $ts = time() + 300; }
			wp_schedule_event( $ts, 'daily', 'bfr_destinations_recalc' );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook('bfr_destinations_recalc');
	}

	public function cron_recalculate_all() {
		$dest_ids = get_posts([
			'post_type'        => $this->cfg['dest_cpt'],
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		]);
		foreach ($dest_ids as $dest_id) {
			$this->recalculate_destination( (int) $dest_id );
		}
	}

	/* ================= Triggers ================= */

	public function on_any_saved($post_ID, $post, $update) {
		if ( $post instanceof WP_Post && $post->post_type === $this->cfg['school_cpt'] ) {
			foreach ( $this->get_destinations_for_school($post_ID) as $dest_id ) {
				$this->recalculate_destination($dest_id);
			}
		}
	}

	public function on_any_trashed($post_ID) {
		$post = get_post($post_ID);
		if ( $post && $post->post_type === $this->cfg['school_cpt'] ) {
			foreach ( $this->get_destinations_for_school($post_ID) as $dest_id ) {
				$this->recalculate_destination($dest_id);
			}
		}
	}

	public function on_any_untrashed($post_ID) {
		$post = get_post($post_ID);
		if ( $post && $post->post_type === $this->cfg['school_cpt'] ) {
			foreach ( $this->get_destinations_for_school($post_ID) as $dest_id ) {
				$this->recalculate_destination($dest_id);
			}
		}
	}

	/* Meta change handlers for School → Destination linking */
	public function on_meta_added($meta_id, $object_id, $meta_key, $meta_value) {
		if ( $meta_key === $this->cfg['meta_dest_id'] ) {
			$this->recalc_from_meta_change($object_id);
		}
	}
	public function on_meta_updated($meta_id, $object_id, $meta_key, $meta_value) {
		if ( $meta_key === $this->cfg['meta_dest_id'] ) {
			$this->recalc_from_meta_change($object_id);
		}
	}
	public function on_meta_deleted($meta_ids, $object_id, $meta_key, $meta_value) {
		if ( $meta_key === $this->cfg['meta_dest_id'] ) {
			$this->recalc_from_meta_change($object_id);
		}
	}

	private function recalc_from_meta_change($school_id) {
		$post = get_post($school_id);
		if ( $post && $post->post_type === $this->cfg['school_cpt'] ) {
			foreach ( $this->get_destinations_for_school($school_id) as $dest_id ) {
				$this->recalculate_destination($dest_id);
			}
		}
	}

	/* ================= Aggregation ================= */

	public function recalculate_destination( $dest_id ) {
		$school_ids = $this->get_schools_for_destination($dest_id);

		$count     = 0;
		$max_depth = null;
		$min_price = null;
		$langs     = [];
		$facils    = [];

		foreach ($school_ids as $sid) {
			$sp = get_post($sid);
			if ( ! $sp || $sp->post_status === 'trash' ) continue;

			$count++;

			$depth = $this->to_numeric( get_post_meta($sid, $this->cfg['meta_max_depth'], true) );
			if ( $depth !== null ) {
				$max_depth = ($max_depth === null) ? $depth : max($max_depth, $depth);
			}

			$price = $this->to_numeric( get_post_meta($sid, $this->cfg['meta_price'], true) );
			if ( $price !== null ) {
				$min_price = ($min_price === null) ? $price : min($min_price, $price);
			}

			$langs  = $this->merge_terms($langs,  get_post_meta($sid, $this->cfg['meta_languages'], true));
			$facils = $this->merge_terms($facils, get_post_meta($sid, $this->cfg['meta_facilities'], true));
		}

		update_post_meta($dest_id, 'bfr_school_count',      (int) $count);
		update_post_meta($dest_id, 'bfr_max_depth',         ($max_depth === null ? '' : $max_depth));
		update_post_meta($dest_id, 'bfr_min_course_price',  ($min_price === null ? '' : $min_price));

		$langs  = array_values( array_unique( array_filter($langs) ) );
		$facils = array_values( array_unique( array_filter($facils) ) );

		update_post_meta($dest_id, 'bfr_languages_array',   wp_json_encode($langs));
		update_post_meta($dest_id, 'bfr_facilities_array',  wp_json_encode($facils));
		update_post_meta($dest_id, 'bfr_languages',         implode(',', $langs));
		update_post_meta($dest_id, 'bfr_facilities',        implode(',', $facils));
	}

	/* ================= Helpers ================= */

	private function get_schools_for_destination( $dest_id ) : array {
		$ids = [];

		// 1) JetEngine relation children
		foreach ( $this->get_child_schools_via_relation($dest_id) as $rid ) {
			$ids[] = (int) $rid;
		}

		// 2) Meta fallback (destination_id on School)
		$via_meta = get_posts([
			'post_type'        => $this->cfg['school_cpt'],
			'post_status'      => 'any',
			'fields'           => 'ids',
			'numberposts'      => -1,
			'meta_query'       => [[
				'key'     => $this->cfg['meta_dest_id'],
				'value'   => (string) $dest_id,
				'compare' => '=',
			]],
			'no_found_rows'    => true,
			'suppress_filters' => true,
		]);
		foreach ($via_meta as $id) {
			$ids[] = (int) $id;
		}

		return array_values( array_unique( $ids ) );
	}

	private function get_child_schools_via_relation( $dest_id ) : array {
		$ids = [];
		$slug = trim( (string) $this->cfg['je_relation'] );
		if ( $slug === '' ) return $ids;

		if ( function_exists('jet_engine') && isset( jet_engine()->relations ) ) {
			$rels = jet_engine()->relations;
			if ( method_exists( $rels, 'get_component' ) ) {
				$relation = $rels->get_component()->get_relation( $slug );
				if ( $relation ) {
					$children = $relation->get_children( $dest_id, [
						'post_type' => $this->cfg['school_cpt'],
						'fields'    => 'ids',
					] );
					if ( is_array($children) ) {
						$ids = array_map('intval', $children);
					}
				}
			}
		}
		return $ids;
	}

	private function get_destinations_for_school( $school_id ) : array {
		$ids = [];

		// Meta
		$meta = get_post_meta($school_id, $this->cfg['meta_dest_id'], true);
		if ( is_numeric($meta) ) {
			$ids[] = (int) $meta;
		}

		// Relation parents
		$slug = trim( (string) $this->cfg['je_relation'] );
		if ( $slug !== '' && function_exists('jet_engine') && isset( jet_engine()->relations ) ) {
			$rels = jet_engine()->relations;
			if ( method_exists( $rels, 'get_component' ) ) {
				$relation = $rels->get_component()->get_relation( $slug );
				if ( $relation ) {
					$parents = $relation->get_parents( $school_id, [
						'post_type' => $this->cfg['dest_cpt'],
						'fields'    => 'ids',
					] );
					if ( is_array($parents) ) {
						foreach ($parents as $pid) { $ids[] = (int) $pid; }
					}
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function to_numeric( $val ) {
		if ( $val === '' || $val === null ) return null;
		$val = is_array($val) ? reset($val) : $val;
		$val = preg_replace('/[^\d\.\-]/', '', (string) $val);
		if ( $val === '' || ! is_numeric($val) ) return null;
		return 0 + $val;
	}

	private function merge_terms( array $acc, $raw ) : array {
		if ( empty($raw) ) return $acc;

		if ( is_string($raw) ) {
			$parts = array_map('trim', explode(',', $raw));
		} elseif ( is_array($raw) ) {
			$parts = [];
			foreach ($raw as $v) {
				if ( is_array($v) ) {
					$parts = array_merge($parts, array_map('trim', $v));
				} else {
					$parts[] = trim( (string) $v );
				}
			}
		} else {
			$parts = [ trim( (string) $raw ) ];
		}

		return array_merge($acc, $parts);
	}
}

BFR_Core::instance();