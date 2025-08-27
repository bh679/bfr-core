<?php
/**
 * Aggregation engine: cron, triggers, and recompute logic.
 *
 * Responsibilities:
 *  - Keep destination aggregates in sync with schools.
 *  - Listen to save/trash/untrash/meta-change events.
 *  - Provide a daily cron as a safety net.
 */

if ( ! defined('ABSPATH') ) exit;

final class BFR_Aggregator {

	/** @var self|null */
	private static $instance = null;

	/** @var array Plugin configuration (merged defaults + saved options) */
	private $cfg = [];

	/* ===============================================================
	 *  Singleton
	 * =============================================================== */
	public static function instance() {
		return self::$instance ?: self::$instance = new self();
	}

	private function __construct() {
		// Load saved options merged with defaults.
		$stored   = get_option(defined('BFR_CORE_OPTION') ? BFR_CORE_OPTION : 'bfr_core_options', []);
		$this->cfg = wp_parse_args( is_array($stored) ? $stored : [], $this->defaults() );

		/* ---------- Cron ---------- */
		add_action(defined('BFR_CORE_CRON_HOOK') ? BFR_CORE_CRON_HOOK : 'bfr_destinations_recalc', [$this, 'cron_recalculate_all']);

		/* ---------- Post lifecycle triggers ---------- */
		add_action('save_post',      [$this, 'on_any_saved'], 20, 3);
		add_action('trashed_post',   [$this, 'on_any_trashed']);
		add_action('untrashed_post', [$this, 'on_any_untrashed']);

		/* ---------- Meta change (School → Destination) ---------- */
		add_action('added_post_meta',   [$this, 'on_meta_added'],   10, 4);
		add_action('updated_post_meta', [$this, 'on_meta_updated'], 10, 4);
		add_action('deleted_post_meta', [$this, 'on_meta_deleted'], 10, 4);
	}

	/**
	 * Defaults (single source of truth).
	 * Filterable to support sites with different slugs/keys.
	 *
	 * @return array
	 */
	public function defaults(): array {
		$defaults = [
			'dest_cpt'        => 'destinations',            // Destination CPT slug (Unified default)
			'school_cpt'      => 'freedive-school',         // School CPT slug
			'je_relation'     => 'destination-to-school',   // JetEngine relation slug ('' = disable relation path)
			'meta_dest_id'    => 'destination_id',          // School→Destination meta key
			'meta_max_depth'  => 'max_depth',               // School meta: numeric
			'meta_price'      => 'course_price',            // School meta: numeric
			'meta_languages'  => 'languages',               // School meta: array/csv
			'meta_facilities' => 'facilities',              // School meta: array/csv
		];

		/**
		 * Allow themes/snippets to change defaults without editing the plugin.
		 * Example:
		 * add_filter('bfr_core_defaults', function($d){ $d['school_cpt'] = 'schools'; return $d; });
		 */
		return apply_filters('bfr_core_defaults', $defaults);
	}

	/* ===============================================================
	 *  Activation / Deactivation
	 * =============================================================== */

	public static function activate_static() {
		self::instance()->activate();
	}

	public static function deactivate_static() {
		self::instance()->deactivate();
	}

	/**
	 * Ensure option exists & schedule a daily cron.
	 * Safe to call repeatedly.
	 */
	public function activate() {
		$opt_key = defined('BFR_CORE_OPTION') ? BFR_CORE_OPTION : 'bfr_core_options';

		if ( false === get_option($opt_key, false) ) {
			add_option($opt_key, $this->defaults(), '', 'no');
		}

		$hook = defined('BFR_CORE_CRON_HOOK') ? BFR_CORE_CRON_HOOK : 'bfr_destinations_recalc';

		if ( ! wp_next_scheduled($hook) ) {
			/**
			 * Filter the default daily cron time (server time, 03:17).
			 * Return a unix timestamp for the first run.
			 */
			$seed = apply_filters('bfr_core_cron_first_run', strtotime('03:17:00'));
			if ($seed === false) { $seed = time() + 300; } // fallback +5min
			wp_schedule_event($seed, 'daily', $hook);
		}
	}

	public function deactivate() {
		$hook = defined('BFR_CORE_CRON_HOOK') ? BFR_CORE_CRON_HOOK : 'bfr_destinations_recalc';
		wp_clear_scheduled_hook($hook);
	}

	/* ===============================================================
	 *  Cron job
	 * =============================================================== */

	/**
	 * Recalculate all Destination aggregates.
	 * Bound to the daily cron, but also callable on-demand.
	 */
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

	/* ===============================================================
	 *  Triggers
	 * =============================================================== */

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

	/** Meta change handlers for School → Destination linking */
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

	/* ===============================================================
	 *  Aggregation
	 * =============================================================== */

	/**
	 * Compute and store destination aggregates from all linked schools.
	 *
	 * @param int $dest_id Destination post ID.
	 */
	public function recalculate_destination( $dest_id ) {

		/**
		 * Allow blocking recomputation for a destination (e.g., temp lock).
		 * Return false to skip.
		 */
		if ( false === apply_filters('bfr_core_can_recalc_destination', true, $dest_id, $this->cfg) ) {
			return;
		}

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

		// Denormalized fields on Destination (for Elementor/filters)
		update_post_meta($dest_id, 'bfr_school_count',      (int) $count);
		update_post_meta($dest_id, 'bfr_max_depth',         ($max_depth === null ? '' : $max_depth));
		update_post_meta($dest_id, 'bfr_min_course_price',  ($min_price === null ? '' : $min_price));

		$langs  = array_values( array_unique( array_filter($langs) ) );
		$facils = array_values( array_unique( array_filter($facils) ) );

		// Keep both human-friendly CSVs and JSON arrays for downstream flexibility
		update_post_meta($dest_id, 'bfr_languages_array',   wp_json_encode($langs));
		update_post_meta($dest_id, 'bfr_facilities_array',  wp_json_encode($facils));
		update_post_meta($dest_id, 'bfr_languages',         implode(',', $langs));
		update_post_meta($dest_id, 'bfr_facilities',        implode(',', $facils));
	}

	/* ===============================================================
	 *  Data linking helpers
	 * =============================================================== */

	/**
	 * List Schools linked to a Destination (via JetEngine relation OR School meta fallback).
	 *
	 * @param  int   $dest_id
	 * @return int[] School IDs
	 */
	private function get_schools_for_destination( $dest_id ) : array {
		$ids = [];

		// 1) JetEngine relation: Destination (parent) → Schools (children)
		foreach ( $this->get_child_schools_via_relation($dest_id) as $rid ) {
			$ids[] = (int) $rid;
		}

		// 2) Meta fallback: on School meta (destination_id)
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

	/**
	 * JetEngine: get children (schools) of a destination.
	 *
	 * @param  int   $dest_id
	 * @return int[]
	 */
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

	/**
	 * List Destination IDs linked to a School (meta + JetEngine parents).
	 *
	 * @param  int   $school_id
	 * @return int[]
	 */
	private function get_destinations_for_school( $school_id ) : array {
		$ids = [];

		// Meta (School → Destination)
		$meta = get_post_meta($school_id, $this->cfg['meta_dest_id'], true);
		if ( is_numeric($meta) ) {
			$ids[] = (int) $meta;
		}

		// Relation parents (School child → Destination parent)
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

	/* ===============================================================
	 *  Small utils
	 * =============================================================== */

	/**
	 * Normalize numeric-like values (strip currency/suffix, allow arrays).
	 *
	 * @param  mixed $val
	 * @return float|int|null
	 */
	private function to_numeric( $val ) {
		if ( $val === '' || $val === null ) return null;
		$val = is_array($val) ? reset($val) : $val;
		$val = preg_replace('/[^\d\.\-]/', '', (string) $val);
		if ( $val === '' || ! is_numeric($val) ) return null;
		return 0 + $val;
	}

	/**
	 * Merge languages/facilities values from CSVs or arrays into an accumulator.
	 *
	 * @param  string[] $acc
	 * @param  mixed    $raw
	 * @return string[]
	 */
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