<?php
/**
 * Plugin Name: BFR Core
 * Description: Site-specific logic and aggregates for Book Freediving Retreats.
 * Author: Brennan Hatton
 * Version: 0.2.0
 */

if ( ! defined('ABSPATH') ) exit;

final class BFR_Core {

	// ==== CONFIG ====
	const DEST_CPT                 = 'destination';     // your JetEngine CPT slug for Destinations
	const SCHOOL_CPT               = 'freedive-school'; // your CPT slug for Schools
	const META_DEST_ID             = 'destination_id';  // School meta key pointing to its Destination (fallback path)
	const META_MAX_DEPTH           = 'max_depth';       // School meta: numeric
	const META_COURSE_PRICE        = 'course_price';    // School meta: numeric (lowest course price)
	const META_LANGUAGES           = 'languages';       // School meta: array or comma-separated
	const META_FACILITIES          = 'facilities';      // School meta: array or comma-separated

	// If you use JetEngine Relations, set the relation slug here.
	// Relation direction assumed: Destination (parent) → School (child).
	// Leave empty string '' to disable relation path.
	const JE_RELATION_SLUG         = 'destination-to-school';

	// Destination meta keys we will write (denormalized)
	const DEST_AGG_COUNT           = 'bfr_school_count';
	const DEST_AGG_MAX_DEPTH       = 'bfr_max_depth';
	const DEST_AGG_MIN_PRICE       = 'bfr_min_course_price';
	const DEST_AGG_LANGS_ARRAY     = 'bfr_languages_array';   // JSON-encoded array
	const DEST_AGG_LANGS_FLAT      = 'bfr_languages';         // comma-separated for filters
	const DEST_AGG_FACILS_ARRAY    = 'bfr_facilities_array';  // JSON-encoded array
	const DEST_AGG_FACILS_FLAT     = 'bfr_facilities';        // comma-separated for filters

	private static $instance = null;

	public static function instance() {
		return self::$instance ?: self::$instance = new self();
	}

	private function __construct() {
		// Cron: schedule nightly sync (3:17am server time)
		register_activation_hook(__FILE__, [$this, 'activate']);
		register_deactivation_hook(__FILE__, [$this, 'deactivate']);
		add_action('bfr_destinations_recalc', [$this, 'cron_recalculate_all']);

		// React to School lifecycle changes
		add_action('save_post_' . self::SCHOOL_CPT,   [$this, 'on_school_saved'], 20, 3);
		add_action('trashed_post',                     [$this, 'on_any_trashed'], 10, 1);
		add_action('untrashed_post',                   [$this, 'on_any_untrashed'], 10, 1);

		// React specifically when destination meta changes on a School
		add_action('added_post_meta',                  [$this, 'on_meta_changed'], 10, 4);
		add_action('updated_post_meta',                [$this, 'on_meta_changed_updated'], 10, 4);
		add_action('deleted_post_meta',                [$this, 'on_meta_deleted'], 10, 4);
	}

	// ----- Activation / Deactivation -----
	public function activate() {
		if ( ! wp_next_scheduled('bfr_destinations_recalc') ) {
			wp_schedule_event( strtotime('03:17:00'), 'daily', 'bfr_destinations_recalc' );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook('bfr_destinations_recalc');
	}

	// ----- Cron job -----
	public function cron_recalculate_all() {
		$dest_ids = get_posts([
			'post_type'      => self::DEST_CPT,
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'suppress_filters' => true,
		]);
		foreach ($dest_ids as $dest_id) {
			$this->recalculate_destination((int) $dest_id);
		}
	}

	// ----- Hooks for School changes -----
	public function on_school_saved($post_ID, $post, $update) {
		if ( $post->post_type !== self::SCHOOL_CPT ) return;

		$dest_ids = $this->get_destinations_for_school($post_ID);
		foreach ($dest_ids as $dest_id) {
			$this->recalculate_destination($dest_id);
		}

		// If using JetEngine relations, also ask relation layer who else references this school
		$maybe_parent = $this->get_destination_parents_via_relation_from_school($post_ID);
		foreach ($maybe_parent as $dest_id) {
			$this->recalculate_destination($dest_id);
		}
	}

	public function on_any_trashed($post_ID) {
		$post = get_post($post_ID);
		if ( ! $post || $post->post_type !== self::SCHOOL_CPT ) return;

		// When a School is trashed, recalc its Destination(s)
		$dest_ids = array_unique( array_merge(
			$this->get_destinations_for_school($post_ID),
			$this->get_destination_parents_via_relation_from_school($post_ID)
		));
		foreach ($dest_ids as $dest_id) {
			$this->recalculate_destination($dest_id);
		}
	}

	public function on_any_untrashed($post_ID) {
		$post = get_post($post_ID);
		if ( ! $post || $post->post_type !== self::SCHOOL_CPT ) return;

		$dest_ids = array_unique( array_merge(
			$this->get_destinations_for_school($post_ID),
			$this->get_destination_parents_via_relation_from_school($post_ID)
		));
		foreach ($dest_ids as $dest_id) {
			$this->recalculate_destination($dest_id);
		}
	}

	// Meta added (e.g., first time setting destination_id)
	public function on_meta_changed($meta_id, $object_id, $meta_key, $_meta_value) {
		$this->maybe_recalc_for_meta_change($object_id, $meta_key, null, $_meta_value);
	}

	// Meta updated (we have old/new)
	public function on_meta_changed_updated($meta_id, $object_id, $meta_key, $meta_value) {
		$old = get_metadata_raw( 'post', $object_id, $meta_key, true ); // already updated; we need previous value if available
		// WordPress doesn't hand us the previous value directly; but we still recalc both old & current via destinations below.
		$this->maybe_recalc_for_meta_change($object_id, $meta_key, $old, $meta_value);
	}

	public function on_meta_deleted($meta_ids, $object_id, $meta_key, $_meta_value) {
		$this->maybe_recalc_for_meta_change($object_id, $meta_key, $_meta_value, null);
	}

	private function maybe_recalc_for_meta_change($post_id, $key, $old, $new) {
		$post = get_post($post_id);
		if ( ! $post || $post->post_type !== self::SCHOOL_CPT ) return;
		if ( $key !== self::META_DEST_ID ) return;

		$targets = [];
		if ( $old && is_numeric($old) ) $targets[] = (int) $old;
		if ( $new && is_numeric($new) ) $targets[] = (int) $new;
		$targets = array_unique(array_filter($targets));

		foreach ($targets as $dest_id) {
			$this->recalculate_destination($dest_id);
		}
	}

	// ----- Core: recompute a Destination’s aggregates -----
	public function recalculate_destination( $dest_id ) {
		$school_ids = $this->get_schools_for_destination($dest_id);

		$count           = 0;
		$max_depth       = null;
		$min_course      = null;
		$langs           = [];
		$facils          = [];

		foreach ($school_ids as $sid) {
			// Skip trashed
			$sp = get_post($sid);
			if ( ! $sp || $sp->post_status === 'trash' ) continue;

			$count++;

			// Max depth
			$depth = $this->to_numeric( get_post_meta($sid, self::META_MAX_DEPTH, true) );
			if ( $depth !== null ) {
				if ($max_depth === null || $depth > $max_depth) $max_depth = $depth;
			}

			// Min course price
			$price = $this->to_numeric( get_post_meta($sid, self::META_COURSE_PRICE, true) );
			if ( $price !== null ) {
				if ($min_course === null || $price < $min_course) $min_course = $price;
			}

			// Languages
			$langs = $this->merge_terms($langs, get_post_meta($sid, self::META_LANGUAGES, true));

			// Facilities
			$facils = $this->merge_terms($facils, get_post_meta($sid, self::META_FACILITIES, true));
		}

		// Normalize + store
		update_post_meta($dest_id, self::DEST_AGG_COUNT,       (int) $count);
		update_post_meta($dest_id, self::DEST_AGG_MAX_DEPTH,   ($max_depth === null ? '' : $max_depth));
		update_post_meta($dest_id, self::DEST_AGG_MIN_PRICE,   ($min_course === null ? '' : $min_course));

		$langs = array_values( array_unique( array_filter( $langs ) ) );
		$facils = array_values( array_unique( array_filter( $facils ) ) );

		update_post_meta($dest_id, self::DEST_AGG_LANGS_ARRAY,  wp_json_encode($langs));
		update_post_meta($dest_id, self::DEST_AGG_FACILS_ARRAY, wp_json_encode($facils));
		update_post_meta($dest_id, self::DEST_AGG_LANGS_FLAT,   implode(',', $langs));
		update_post_meta($dest_id, self::DEST_AGG_FACILS_FLAT,  implode(',', $facils));
	}

	// ----- Helpers -----
	private function get_schools_for_destination( $dest_id ) : array {
		$ids = [];

		// 1) JetEngine Relation path
		foreach ( $this->get_child_schools_via_relation($dest_id) as $rid ) {
			$ids[] = $rid;
		}

		// 2) Fallback/meta path (schools with meta destination_id = $dest_id)
		$via_meta = get_posts([
			'post_type'      => self::SCHOOL_CPT,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'numberposts'    => -1,
			'meta_query'     => [
				[
					'key'     => self::META_DEST_ID,
					'value'   => (string) $dest_id,
					'compare' => '='
				]
			],
			'no_found_rows'  => true,
			'suppress_filters' => true,
		]);
		$ids = array_merge($ids, $via_meta);

		return array_values( array_unique( array_map('intval', $ids) ) );
	}

	private function get_child_schools_via_relation( $dest_id ) : array {
		$ids = [];
		if ( ! self::JE_RELATION_SLUG ) return $ids;

		if ( function_exists('jet_engine') && isset( jet_engine()->relations ) ) {
			$rels = jet_engine()->relations;
			if ( method_exists( $rels, 'get_component' ) ) {
				$relation = $rels->get_component()->get_relation( self::JE_RELATION_SLUG );
				if ( $relation ) {
					$children = $relation->get_children( $dest_id, [
						'post_type' => self::SCHOOL_CPT,
						'fields'    => 'ids',
					] );
					if ( is_array($children) ) $ids = $children;
				}
			}
		}
		return $ids;
	}

	private function get_destination_parents_via_relation_from_school( $school_id ) : array {
		$ids = [];
		if ( ! self::JE_RELATION_SLUG ) return $ids;

		if ( function_exists('jet_engine') && isset( jet_engine()->relations ) ) {
			$rels = jet_engine()->relations;
			if ( method_exists( $rels, 'get_component' ) ) {
				$relation = $rels->get_component()->get_relation( self::JE_RELATION_SLUG );
				if ( $relation ) {
					$parents = $relation->get_parents( $school_id, [
						'post_type' => self::DEST_CPT,
						'fields'    => 'ids',
					] );
					if ( is_array($parents) ) $ids = $parents;
				}
			}
		}
		return $ids;
	}

	private function get_destinations_for_school( $school_id ) : array {
		$ids = [];
		$meta = get_post_meta($school_id, self::META_DEST_ID, true);
		if ( is_numeric($meta) ) $ids[] = (int) $meta;

		// Also include relation parents if configured
		$ids = array_merge($ids, $this->get_destination_parents_via_relation_from_school($school_id));
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