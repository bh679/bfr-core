<?php
/**
 * Plugin Name: BFR Core
 * Description: Site-specific logic and destination aggregates for Book Freediving Retreats.
 * Author: Brennan Hatton
 * Version: 0.5.0
 */

if ( ! defined('ABSPATH') ) exit;

define('BFR_CORE_FILE', __FILE__);
define('BFR_CORE_DIR',  plugin_dir_path(__FILE__));
define('BFR_CORE_URL',  plugin_dir_url(__FILE__));

require_once BFR_CORE_DIR . 'includes/class-bfr-aggregator.php';
require_once BFR_CORE_DIR . 'includes/class-bfr-admin.php';

/**
 * Activation / Deactivation hooks must live in the main file.
 */
register_activation_hook(   BFR_CORE_FILE, ['BFR_Aggregator', 'activate_static']   );
register_deactivation_hook( BFR_CORE_FILE, ['BFR_Aggregator', 'deactivate_static'] );

/**
 * Boot plugin services.
 */
add_action('plugins_loaded', function () {
	BFR_Aggregator::instance(); // logic, cron, triggers
	BFR_Admin::instance();      // settings UI
});

/**
 * Register destination aggregate meta so Elementor/REST can read them.
 * Runs every request on `init` so it always honors the current Destination CPT.
 */
add_action('init', function () {
	$opts = get_option('bfr_core_options', []);
	$cpt  = isset($opts['dest_cpt']) && is_string($opts['dest_cpt']) ? $opts['dest_cpt'] : 'destinations';

	$common = [
		'object_subtype' => $cpt,
		'single'         => true,
		'show_in_rest'   => true,
		'auth_callback'  => '__return_true',
	];

	register_meta('post', 'bfr_school_count',     $common + ['type' => 'integer']);
	register_meta('post', 'bfr_max_depth',        $common + ['type' => 'number']);
	register_meta('post', 'bfr_min_course_price', $common + ['type' => 'number']);

	// Stored as strings (CSV or pretty text). If you later store JSON arrays,
	// keep keys like bfr_languages_array/bfr_facilities_array and set type "string".
	register_meta('post', 'bfr_languages',        $common + ['type' => 'string']);
	register_meta('post', 'bfr_facilities',       $common + ['type' => 'string']);
});

/**
 * Make the JetEngine UI fields (if you create a meta box with the same keys)
 * appear read-only on Destination edit screens to avoid manual edits being saved.
 */
add_action('admin_print_styles', function () {
	if ( ! function_exists('get_current_screen') ) return;
	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' ) return;

	$opts     = get_option('bfr_core_options', []);
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
		// JetEngine admin inputs usually have name="__meta[KEY]" or data-field-name="KEY"
		echo '[name="__meta['.esc_attr($k).']"],'
		   . ' [data-field-name="'.esc_attr($k).'"] input,'
		   . ' [data-field-name="'.esc_attr($k).'"] textarea'
		   . ' { pointer-events:none; background:#f6f7f7; }';
	}
	echo '</style>';
});