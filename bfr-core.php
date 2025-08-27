if ( ! defined('ABSPATH') ) exit;

final class BFR_Core {

	private static $instance = null;
	private $cfg = [];

	public static function instance() {
		return self::$instance ?: self::$instance = new self();
	}

	private function __construct() {
		// Load options with defaults
		$this->cfg = wp_parse_args( get_option('bfr_core_options', []), [
			'dest_cpt'        => 'destination',
			'school_cpt'      => 'freedive-school',
			'je_relation'     => 'destination-to-school', // leave '' to disable relation path
			'meta_dest_id'    => 'destination_id',        // School→Destination meta key
			'meta_max_depth'  => 'max_depth',
			'meta_price'      => 'course_price',
			'meta_languages'  => 'languages',
			'meta_facilities' => 'facilities',
		]);

		// Settings page
		add_action('admin_menu',        [$this, 'admin_menu']);
		add_action('admin_init',        [$this, 'register_settings']);
		add_action('admin_post_bfr_recalc', [$this, 'handle_recalc_now']);

		// Cron
		register_activation_hook(__FILE__,  [$this, 'activate']);
		register_deactivation_hook(__FILE__,[$this, 'deactivate']);
		add_action('bfr_destinations_recalc', [$this, 'cron_recalculate_all']);

		// React to School lifecycle changes
		add_action('save_post',            [$this, 'on_any_saved'], 20, 3);
		add_action('trashed_post',         [$this, 'on_any_trashed'], 10, 1);
		add_action('untrashed_post',       [$this, 'on_any_untrashed'], 10, 1);

		// React when School→Destination meta changes
		add_action('added_post_meta',      [$this, 'on_meta_changed'], 10, 4);
		add_action('updated_post_meta',    [$this, 'on_meta_changed_updated'], 10, 4);
		add_action('deleted_post_meta',    [$this, 'on_meta_deleted'], 10, 4);
	}

	/* ================= Settings UI ================= */

	public function admin_menu() {
		add_options_page('BFR Core', 'BFR Core', 'manage_options', 'bfr-core', [$this, 'render_settings']);
	}

	public function register_settings() {
		register_setting('bfr_core_group', 'bfr_core_options', [
			'sanitize_callback' => function($input){
				foreach ($input as $k => &$v) { $v = is_string($v) ? sanitize_text_field($v) : $v; }
				return $input;
			}
		]);

		add_settings_section('bfr_core_section', 'General', function(){
			echo '<p>Configure CPT slugs, relation slug, and meta keys used for aggregation.</p>';
		}, 'bfr-core');

		$fields = [
			'dest_cpt'        => 'Destination CPT Slug',
			'school_cpt'      => 'School CPT Slug',
			'je_relation'     => 'JetEngine Relation Slug (blank to disable)',
			'meta_dest_id'    => 'School Meta → Destination ID key',
			'meta_max_depth'  => 'School Meta: Max Depth key',
			'meta_price'      => 'School Meta: Lowest Course Price key',
			'meta_languages'  => 'School Meta: Languages key',
			'meta_facilities' => 'School Meta: Facilities key',
		];

		foreach ($fields as $key => $label) {
			add_settings_field($key, $label, function() use ($key){
				$opts = $this->cfg;
				printf('<input type="text" class="regular-text" name="bfr_core_options[%1$s]" value="%2$s" />', esc_attr($key), esc_attr($opts[$key] ?? ''));
			}, 'bfr-core', 'bfr_core_section');
		}
	}

	public function render_settings() {
		if (!current_user_can('manage_options')) return;
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
			<p>Run a full recompute of all Destinations (safe to run anytime).</p>
			<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
				<input type="hidden" name="action" value="bfr_recalc" />
				<?php wp_nonce_field('bfr_recalc_now'); ?>
				<?php submit_button('Recalculate all now', 'secondary'); ?>
			</form>

			<hr/>
			<h2>Diagnostics</h2>
			<ul>
				<li><strong>Destination CPT:</strong> <?php echo esc_html($this->cfg['dest_cpt']); ?></li>
				<li><strong>School CPT:</strong> <?php echo esc_html($this->cfg['school_cpt']); ?></li>
				<li><strong>Relation slug:</strong> <?php echo $this->cfg['je_relation'] ? esc_html($this->cfg['je_relation']) : '<em>disabled</em>'; ?></li>
				<li><strong>Meta keys:</strong>
					dest_id=<code><?php echo esc_html($this->cfg['meta_dest_id']); ?></code>,
					max_depth=<code><?php echo esc_html($this->cfg['meta_max_depth']); ?></code>,
					price=<code><?php echo esc_html($this->cfg['meta_price']); ?></code>,
					langs=<code><?php echo esc_html($this->cfg['meta_languages']); ?></code>,
					facils=<code><?php echo esc_html($this->cfg['meta_facilities']); ?></code>
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
			wp_schedule_event( strtotime('03:17:00'), 'daily', 'bfr_destinations_recalc' );
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
			$this->recalculate_destination((int) $dest_id);
		}
	}

	/* ================= Triggers ================= */

	public function on_any_saved($post_ID, $post, $update) {
		if ( $post->post_type !== $this->cfg['school_cpt'] ) return;
		foreach ( $this->get_destinations_for_school($post_ID) as $dest_id ) {
			$this->recalculate_destination($dest_id);
		}
	}
	public function on_any_trashed($post_ID) {
		$post = get_post($post_ID);
		if ( ! $post || $post->post_type !== $this->cfg['school_cpt'] ) return;
		foreach ( $this->get_destinations_for_school($post_ID) as $dest_id ) {
			$this->recalculate_destination($dest_id);
		}
	}
	public function on_any_untrashed($post_ID) {
		$post = get_post($post_ID);
		if ( ! $post || $post->post_type !== $this->cfg['school_cpt'] ) return;
		foreach ( $this->get_destinations_for_school($post_ID) as $dest_id ) {
			$this->recalculate_destination($dest_id);
		}
	}

	public function on_meta_changed($meta_id, $object_id, $meta_key, $_meta_value) {
		if ( $meta_key !== $this->cfg['meta_dest_id'] ) return;
		$this->recalc_from_meta_change($object_id, null, $_meta_value);
	}
	public function on_meta_changed_updated($meta_id, $object_id, $meta_key, $meta_value) {
		if ( $meta_key !== $this->cfg['meta_dest_id'] ) return;
		// We don't have the previous value directly; just recompute parents of this school.
		$this->recalc_from_meta_change($object_id, null, $meta_value);
	}
	public function on_meta_deleted($meta_ids, $object_id, $meta_key, $_meta_value) {
		if ( $meta_key !== $this->cfg['meta_dest_id'] ) return;
		$this->recalc_from_meta_change($object_id, $_meta_value, null);
	}
	private function recalc_from_meta_change($school_id, $old, $new) {
		$post = get_post($school_id);
		if ( ! $post || $post->post_type !== $this->cfg['school_cpt'] ) return;

		$targets = $this->get_destinations_for_school($school_id);
		if ( is_numeric($old) ) $targets[] = (int) $old;
		if ( is_numeric($new) ) $targets[] = (int) $new;

		foreach ( array_unique( array_filter( array_map('intval', $targets) ) ) as $dest_id ) {
			$this->recalculate_destination($dest_id);
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
				if ($max_depth === null || $depth > $max_depth) $max_depth = $depth;
			}

			$price = $this->to_numeric( get_post_meta($sid, $this->cfg['meta_price'], true) );
			if ( $price !== null ) {
				if ($min_price === null || $price < $min_price) $min_price = $price;
			}

			$langs  = $this->merge_terms($langs,  get_post_meta($sid, $this->cfg['meta_languages'], true));
			$facils = $this->merge_terms($facils, get_post_meta($sid, $this->cfg['meta_facilities'], true));
		}

		update_post_meta($dest_id, 'bfr_school_count',      (int) $count);
		update_post_meta($dest_id, 'bfr_max_depth',         ($max_depth === null ? '' : $max_depth));
		update_post_meta($dest_id, 'bfr_min_course_price',  ($min_price === null ? '' : $min_price));

		$langs   = array_values( array_unique( array_filter($langs) ) );
		$facils  = array_values( array_unique( array_filter($facils) ) );

		update_post_meta($dest_id, 'bfr_languages_array',   wp_json_encode($langs));
		update_post_meta($dest_id, 'bfr_facilities_array',  wp_json_encode($facils));
		update_post_meta($dest_id, 'bfr_languages',         implode(',', $langs));
		update_post_meta($dest_id, 'bfr_facilities',        implode(',', $facils));
	}

	/* ================= Helpers ================= */

	private function get_schools_for_destination( $dest_id ) : array {
		$ids = [];

		// 1) Via JetEngine relation
		foreach ( $this->get_child_schools_via_relation($dest_id) as $rid ) {
			$ids[] = $rid;
		}

		// 2) Via meta fallback
		$via_meta = get_posts([
			'post_type'        => $this->cfg['school_cpt'],
			'post_status'      => 'any',
			'fields'           => 'ids',
			'numberposts'      => -1,
			'meta_query'       => [[
				'key'     => $this->cfg['meta_dest_id'],
				'value'   => (string) $dest_id,
				'compare' => '='
			]],
			'no_found_rows'    => true,
			'suppress_filters' => true,
		]);
		$ids = array_merge($ids, $via_meta);

		return array_values( array_unique( array_map('intval', $ids) ) );
	}

	private function get_child_schools_via_relation( $dest_id ) : array {
		$ids = [];
		if ( empty($this->cfg['je_relation']) ) return $ids;

		if ( function_exists('jet_engine') && isset( jet_engine()->relations ) ) {
			$rels = jet_engine()->relations;
			if ( method_exists( $rels, 'get_component' ) ) {
				$relation = $rels->get_component()->get_relation( $this->cfg['je_relation'] );
				if ( $relation ) {
					$children = $relation->get_children( $dest_id, [
						'post_type' => $this->cfg['school_cpt'],
						'fields'    => 'ids',
					] );
					if ( is_array($children) ) $ids = $children;
				}
			}
		}
		return $ids;
	}

	private function get_destinations_for_school( $school_id ) : array {
		$ids = [];

		// from meta
		$meta = get_post_meta($school_id, $this->cfg['meta_dest_id'], true);
		if ( is_numeric($meta) ) $ids[] = (int) $meta;

		// from relation (parents)
		if ( ! empty($this->cfg['je_relation']) && function_exists('jet_engine') && isset( jet_engine()->relations ) ) {
			$rels = jet_engine()->relations;
			if ( method_exists( $rels, 'get_component' ) ) {
				$relation = $rels->get_component()->get_relation( $this->cfg['je_relation'] );
				if ( $relation ) {
					$parents = $relation->get_parents( $school_id, [
						'post_type' => $this->cfg['dest_cpt'],
						'fields'    => 'ids',
					] );
					if ( is_array($parents) ) $ids = array_merge($ids, $parents);
				}
			}
		}
		return array_values( array_unique( array_map('intval', $ids) ) );
	}

	private function to_numeric( $val ) {
		if ( $val === '' || $val === null ) return null;
		$val = is_array($val) ? reset($val) : $val;
		$val = preg_replace('/[^\d\.\-]/', '', (string)$val);
		if ($val === '' || ! is_numeric($val)) return null;
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
					$parts[] = trim((string)$v);
				}
			}
		} else {
			$parts = [ (string) $raw ];
		}
		return array_merge($acc, $parts);
	}
}

BFR_Core::instance();