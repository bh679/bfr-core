<?php

/**
 * Aggregation engine for BFR Core.
 *
 * Responsibilities:
 * - Compute and store denormalized destination fields:
 *     bfr_school_count, bfr_max_depth, bfr_min_course_price,
 *     bfr_languages(_array), bfr_facilities(_array).
 * - Discover linked schools via:
 *     1) JetEngine relation (destination → schools) if configured.
 *     2) Fallback school meta (destination_id).
 * - React to data changes via WP hooks:
 *     save_post/trashed_post/untrashed_post for Schools,
 *     added/updated/deleted_post_meta for link changes.
 * - Daily safety-net reaggregation via a scheduled cron hook.
 *
 * Extension points (filters):
 * - bfr_core_defaults                     : override default slugs/meta keys.
 * - bfr_core_cron_first_run               : customize first cron timestamp.
 * - bfr_core_can_recalc_destination(bool) : veto recomputation per destination.
 *
 * No UI code belongs here; keep it headless/testable.
 *
 * @package   BFR\Core
 * @since     0.6.0
 * @internal  Loaded by Composer PSR-4 autoloading (namespace BFR\Core).
 */
namespace BFR\Core;

if (!defined('ABSPATH')) exit;

/**
 * Aggregates school data into destination meta.
 */
final class Aggregator {

	/** @var self|null */
	private static $instance = null;

	/** @var array */
	private $cfg = [];

	/* ========================= Singleton ========================= */

	public static function instance(): self {
		return self::$instance ?: self::$instance = new self();
	}

	private function __construct() {
		$stored   = get_option(\BFR_CORE_OPTION, []);
		$this->cfg = wp_parse_args(is_array($stored) ? $stored : [], $this->defaults());

		add_action(\BFR_CORE_CRON_HOOK, [$this, 'recalc_all']);

		add_action('save_post',      [$this, 'on_any_saved'], 20, 3);
		add_action('trashed_post',   [$this, 'on_any_trashed']);
		add_action('untrashed_post', [$this, 'on_any_untrashed']);

		add_action('added_post_meta',   [$this, 'on_meta_change'], 10, 4);
		add_action('updated_post_meta', [$this, 'on_meta_change'], 10, 4);
		add_action('deleted_post_meta', [$this, 'on_meta_change'], 10, 4);
	}

	/* ========================= Defaults ========================= */

	public function defaults(): array {
		$defaults = [
			'dest_cpt'        => 'destinations',
			'school_cpt'      => 'freedive-school',
			'je_relation'     => 'destination-to-school',

			'meta_dest_id'    => 'destination_id',
			'meta_max_depth'  => 'max_depth',
			'meta_price'      => 'course_price',
			'meta_languages'  => 'languages',
			'meta_facilities' => 'facilities',

			'dest_meta_school_count'      => 'bfr_school_count',
			'dest_meta_max_depth'         => 'bfr_max_depth',
			'dest_meta_min_course_price'  => 'bfr_min_course_price',
			'dest_meta_languages'         => 'bfr_languages',
			'dest_meta_facilities'        => 'bfr_facilities',
			'dest_meta_languages_array'   => 'bfr_languages_array',
			'dest_meta_facilities_array'  => 'bfr_facilities_array',
		];
		return apply_filters('bfr_core_defaults', $defaults);
	}

	/* ================= Activation / Deactivation ================ */

	public static function activate_static(): void { self::instance()->activate(); }
	public static function deactivate_static(): void { self::instance()->deactivate(); }

	public function activate(): void {
		if (false === get_option(\BFR_CORE_OPTION, false)) {
			add_option(\BFR_CORE_OPTION, $this->defaults(), '', 'no');
		}
		if (!wp_next_scheduled(\BFR_CORE_CRON_HOOK)) {
			$seed = apply_filters('bfr_core_cron_first_run', strtotime('03:17:00'));
			if ($seed === false) $seed = time() + 300;
			wp_schedule_event($seed, 'daily', \BFR_CORE_CRON_HOOK);
		}
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook(\BFR_CORE_CRON_HOOK);
	}

	/* =========================== Cron =========================== */

	public function recalc_all(): void {
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

	/* ========================= Triggers ========================= */

	public function on_any_saved($post_ID, $post, $update): void {
		if ($post instanceof \WP_Post && $post->post_type === $this->cfg['school_cpt']) {
			foreach ($this->get_destinations_for_school($post_ID) as $dest_id) {
				$this->recalculate_destination($dest_id);
			}
		}
	}
	public function on_any_trashed($post_ID): void {
		$post = get_post($post_ID);
		if ($post && $post->post_type === $this->cfg['school_cpt']) {
			foreach ($this->get_destinations_for_school($post_ID) as $dest_id) {
				$this->recalculate_destination($dest_id);
			}
		}
	}
	public function on_any_untrashed($post_ID): void {
		$post = get_post($post_ID);
		if ($post && $post->post_type === $this->cfg['school_cpt']) {
			foreach ($this->get_destinations_for_school($post_ID) as $dest_id) {
				$this->recalculate_destination($dest_id);
			}
		}
	}

	public function on_meta_change($meta_id, $object_id, $meta_key, $meta_value): void {
		if ($meta_key === $this->cfg['meta_dest_id']) {
			$post = get_post($object_id);
			if ($post && $post->post_type === $this->cfg['school_cpt']) {
				foreach ($this->get_destinations_for_school($object_id) as $dest_id) {
				 $this->recalculate_destination($dest_id);
				}
			}
		}
	}

	/* ======================= Aggregation ======================== */

	public function recalculate_destination(int $dest_id): void {
		if (false === apply_filters('bfr_core_can_recalc_destination', true, $dest_id, $this->cfg)) {
			return;
		}

		$school_ids = $this->get_schools_for_destination($dest_id);

		$count = 0;
		$max_depth = null;
		$min_price = null;
		$langs = [];
		$facils = [];

		foreach ($school_ids as $sid) {
			$sp = get_post($sid);
			if (!$sp || $sp->post_status === 'trash') continue;

			$count++;

			$depth = $this->to_numeric(get_post_meta($sid, $this->cfg['meta_max_depth'], true));
			if ($depth !== null) {
				$max_depth = ($max_depth === null) ? $depth : max($max_depth, $depth);
			}

			$price = $this->to_numeric(get_post_meta($sid, $this->cfg['meta_price'], true));
			if ($price !== null) {
				$min_price = ($min_price === null) ? $price : min($min_price, $price);
			}

			$langs  = $this->merge_terms($langs,  get_post_meta($sid, $this->cfg['meta_languages'], true));
			$facils = $this->merge_terms($facils, get_post_meta($sid, $this->cfg['meta_facilities'], true));
		}

		// pull destination keys from config
		$k_count     = $this->cfg['dest_meta_school_count'];
		$k_maxdepth  = $this->cfg['dest_meta_max_depth'];
		$k_minprice  = $this->cfg['dest_meta_min_course_price'];
		$k_langs     = $this->cfg['dest_meta_languages'];
		$k_facils    = $this->cfg['dest_meta_facilities'];
		$k_langs_arr = $this->cfg['dest_meta_languages_array'];
		$k_facils_arr= $this->cfg['dest_meta_facilities_array'];

		update_post_meta($dest_id, $k_count,    (int) $count);
		update_post_meta($dest_id, $k_maxdepth, ($max_depth === null ? '' : $max_depth));
		update_post_meta($dest_id, $k_minprice, ($min_price === null ? '' : $min_price));

		$langs  = array_values(array_unique(array_filter($langs)));
		$facils = array_values(array_unique(array_filter($facils)));

		update_post_meta($dest_id, $k_langs_arr,  wp_json_encode($langs));
		update_post_meta($dest_id, $k_facils_arr, wp_json_encode($facils));
		update_post_meta($dest_id, $k_langs,      implode(',', $langs));
		update_post_meta($dest_id, $k_facils,     implode(',', $facils));
	}

	/* =================== Linking / Discovery ==================== */

	private function get_schools_for_destination(int $dest_id): array {
		$ids = [];

		foreach ($this->get_child_schools_via_relation($dest_id) as $rid) {
			$ids[] = (int) $rid;
		}

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
		foreach ($via_meta as $id) $ids[] = (int) $id;

		return array_values(array_unique($ids));
	}

	private function get_child_schools_via_relation(int $dest_id): array {
		$ids  = [];
		$slug = trim((string) $this->cfg['je_relation']);
		if ($slug === '') return $ids;

		if (function_exists('jet_engine') && isset(jet_engine()->relations)) {
			$rels = jet_engine()->relations;
			if (method_exists($rels, 'get_component')) {
				$relation = $rels->get_component()->get_relation($slug);
				if ($relation) {
					$children = $relation->get_children($dest_id, [
						'post_type' => $this->cfg['school_cpt'],
						'fields'    => 'ids',
					]);
					if (is_array($children)) $ids = array_map('intval', $children);
				}
			}
		}
		return $ids;
	}

	private function get_destinations_for_school(int $school_id): array {
		$ids = [];

		$meta = get_post_meta($school_id, $this->cfg['meta_dest_id'], true);
		if (is_numeric($meta)) $ids[] = (int) $meta;

		$slug = trim((string) $this->cfg['je_relation']);
		if ($slug !== '' && function_exists('jet_engine') && isset(jet_engine()->relations)) {
			$rels = jet_engine()->relations;
			if (method_exists($rels, 'get_component')) {
				$relation = $rels->get_component()->get_relation($slug);
				if ($relation) {
					$parents = $relation->get_parents($school_id, [
						'post_type' => $this->cfg['dest_cpt'],
						'fields'    => 'ids',
					]);
					if (is_array($parents)) foreach ($parents as $pid) $ids[] = (int) $pid;
				}
			}
		}

		return array_values(array_unique($ids));
	}

	/* =========================== Utils ========================== */

	private function to_numeric($val) {
		if ($val === '' || $val === null) return null;
		$val = is_array($val) ? reset($val) : $val;
		$val = preg_replace('/[^\d\.\-]/', '', (string) $val);
		if ($val === '' || !is_numeric($val)) return null;
		return 0 + $val;
	}

	private function merge_terms(array $acc, $raw): array {
		if (empty($raw)) return $acc;

		if (is_string($raw)) {
			$parts = array_map('trim', explode(',', $raw));
		} elseif (is_array($raw)) {
			$parts = [];
			foreach ($raw as $v) {
				$parts = array_merge($parts, is_array($v) ? array_map('trim', $v) : [trim((string) $v)]);
			}
		} else {
			$parts = [trim((string) $raw)];
		}

		return array_merge($acc, $parts);
	}
}