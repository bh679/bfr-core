<?php
/**
 * Plugin Name:       BFR Core
 * Description:       Site-specific logic and destination aggregates for Book Freediving Retreats.
 * Author:            Brennan Hatton
 * Version:           0.5.1
 *
 * @package BFRCore
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Plugin-wide constants
 * Keep file/dir/url + stable keys in one place.
 */
define('BFR_CORE_FILE', __FILE__);
define('BFR_CORE_DIR',  plugin_dir_path(__FILE__));
define('BFR_CORE_URL',  plugin_dir_url(__FILE__));

/** Stable option + cron hook names (edit here if ever renamed) */
define('BFR_CORE_OPTION',   'bfr_core_options');
define('BFR_CORE_CRON_HOOK','bfr_destinations_recalc');

/**
 * Require classes (helpers first; admin needs them).
 * Keep requires top-level so opcode caches can optimize.
 */
require_once BFR_CORE_DIR . 'includes/class-bfr-helpers.php';
require_once BFR_CORE_DIR . 'includes/class-bfr-aggregator.php';
require_once BFR_CORE_DIR . 'admin/class-bfr-admin.php';

/** ----------------------------------------------------------------
 * Activation/Deactivation must live in the main file.
 * ---------------------------------------------------------------- */
register_activation_hook(   BFR_CORE_FILE, ['BFR_Aggregator', 'activate_static']   );
register_deactivation_hook( BFR_CORE_FILE, ['BFR_Aggregator', 'deactivate_static'] );

/** ----------------------------------------------------------------
 * Boot services as late as possible but before init (plugins_loaded).
 * ---------------------------------------------------------------- */
add_action('plugins_loaded', function () {
	BFR_Aggregator::instance(); // cron, triggers, compute logic
	BFR_Admin::instance();      // settings UI and actions
});

/** ----------------------------------------------------------------
 * Register destination aggregate meta so Elementor/REST can read them.
 * Runs on every request via `init` to follow current Destination CPT.
 * ---------------------------------------------------------------- */
add_action('init', function () {

	$opts = get_option(BFR_CORE_OPTION, []);
	$cpt  = isset($opts['dest_cpt']) && is_string($opts['dest_cpt']) ? $opts['dest_cpt'] : 'destinations';

	$common = [
		'object_subtype' => $cpt,
		'single'         => true,
		'show_in_rest'   => true,           // Needed for Elementor and REST usage
		'auth_callback'  => '__return_true' // Publicly readable
	];

	// Numbers/integers
	register_meta('post', 'bfr_school_count',     $common + ['type' => 'integer']);
	register_meta('post', 'bfr_max_depth',        $common + ['type' => 'number']);
	register_meta('post', 'bfr_min_course_price', $common + ['type' => 'number']);

	// Textual (CSV or pretty strings). If also storing JSON arrays, keep the *_array keys as "string".
	register_meta('post', 'bfr_languages',        $common + ['type' => 'string']);
	register_meta('post', 'bfr_facilities',       $common + ['type' => 'string']);
});

/** ----------------------------------------------------------------
 * Make JetEngine UI fields appear read-only on Destination edit screens
 * to prevent accidental manual edits of computed fields.
 * ---------------------------------------------------------------- */
add_action('admin_print_styles', function () {
	if ( ! function_exists('get_current_screen') ) return;
	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' ) return;

	$opts     = get_option(BFR_CORE_OPTION, []);
	$dest_cpt = isset($opts['dest_cpt']) && is_string($opts['dest_cpt']) ? $opts['dest_cpt'] : 'destinations';
	if ( $screen->post_type !== $dest_cpt ) return;

	$keys = [
		'bfr_school_count',
		'bfr_max_depth',
		'bfr_min_course_price',
		'bfr_languages',
		'bfr_facilities',
	];

	echo '<style>';
	foreach ($keys as $k) {
		// JetEngine admin inputs commonly: name="__meta[KEY]" or wrappers with data-field-name="KEY"
		echo '[name="__meta['.esc_attr($k).']"],'
		   . ' [data-field-name="'.esc_attr($k).'"] input,'
		   . ' [data-field-name="'.esc_attr($k).'"] textarea'
		   . ' { pointer-events:none; background:#f6f7f7; }';
	}
	echo '</style>';
});

/** ----------------------------------------------------------------
 * Nice-to-have: “Settings” link on the Plugins screen row.
 * ---------------------------------------------------------------- */
add_filter('plugin_action_links_' . plugin_basename(BFR_CORE_FILE), function($links){
	$url = admin_url('options-general.php?page=bfr-core');
	array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'bfr-core').'</a>');
	return $links;
});