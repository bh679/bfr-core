<?php
/* (cron + hooks + aggregation) */

if ( ! defined('ABSPATH') ) exit;

final class BFR_Aggregator {

	private static $instance = null;
	private $cfg = [];

	/* ---------- Singleton ---------- */
	public static function instance() {
		return self::$instance ?: self::$instance = new self();
	}

	private function __construct() {
		// Load options merged with defaults
		$this->cfg = BFR_Helpers::get_opts();

		/* Cron */
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

	/* ---------- Defaults (single source of truth) ---------- */
	public function defaults(): array {
		return BFR_Helpers::get_opts();
	}

	/* ---------- Activation / Deactivation ---------- */
	public static function activate_static() {
		self::instance()->activate();
	}
	public static function deactivate_static() {
		self::instance()->deactivate();
	}

	public function activate() {
		// Ensure options exist with defaults the first time
		if ( false === get_option('bfr_core_options', false) ) {
			add_option('bfr_core_options', $this->defaults(), '', 'no');
		}
		// Schedule daily cron (03:17 server time; fallback +5min)
		if ( ! wp_next_scheduled('bfr_destinations_recalc') ) {
			$seed = strtotime('03:17:00');
			if ($seed === false) { $seed = time() + 300; }
			wp_schedule_event($seed, 'daily', 'bfr_destinations_recalc');
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook('bfr_destinations_recalc');
	}

	/* ---------- Cron job ---------- */
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

	/* ---------- Triggers ---------- */
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

	/* ---------- Aggregation ---------- */
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

		// Denormalized fields on Destination (keys configurable via Admin)
		update_post_meta($dest_id, $this->cfg['out_school_count'],     (int) $count);
		update_post_meta($dest_id, $this->cfg['out_max_depth'],        ($max_depth === null ? '' : $max_depth));
		update_post_meta($dest_id, $this->cfg['out_min_course_price'], ($min_price === null ? '' : $min_price));

		$langs  = array_values( array_unique( array_filter($langs) ) );
		$facils = array_values( array_unique( array_filter($facils) ) );

		update_post_meta($dest_id, $this->cfg['out_languages'],  implode(',', $langs));
		update_post_meta($dest_id, $this->cfg['out_facilities'], implode(',', $facils));
	}

	/* ---------- Helpers ---------- */

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