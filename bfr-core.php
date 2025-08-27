<?php
/**
<<<<<<< HEAD
 * Plugin Name:       BFR Core
 * Description:       Site-specific logic and destination aggregates for Book Freediving Retreats.
 * Author:            Brennan Hatton
 * Version:           0.5.1
 *
 * @package BFRCore
=======
 * Plugin Name: BFR Core
 * Description: Site-specific logic and destination aggregates for Book Freediving Retreats.
 * Author: Brennan Hatton
 * Version: 0.6.0
>>>>>>> main
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Plugin-wide constants
 * Keep file/dir/url + stable keys in one place.
 */
define('BFR_CORE_FILE', __FILE__);
define('BFR_CORE_DIR',  plugin_dir_path(__FILE__));
define('BFR_CORE_URL',  plugin_dir_url(__FILE__));

<<<<<<< HEAD
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
=======
require_once BFR_CORE_DIR . 'includes/class-bfr-helpers.php';
require_once BFR_CORE_DIR . 'includes/class-bfr-aggregator.php';
require_once BFR_CORE_DIR . 'includes/class-bfr-admin.php';

register_activation_hook(   BFR_CORE_FILE, ['BFR_Aggregator', 'activate_static']   );
register_deactivation_hook( BFR_CORE_FILE, ['BFR_Aggregator', 'deactivate_static'] );

>>>>>>> main
add_action('plugins_loaded', function () {
	BFR_Aggregator::instance(); // cron, triggers, compute logic
	BFR_Admin::instance();      // settings UI and actions
});

/** ----------------------------------------------------------------
 * Register destination aggregate meta so Elementor/REST can read them.
<<<<<<< HEAD
 * Runs on every request via `init` to follow current Destination CPT.
 * ---------------------------------------------------------------- */
add_action('init', function () {

	$opts = get_option(BFR_CORE_OPTION, []);
	$cpt  = isset($opts['dest_cpt']) && is_string($opts['dest_cpt']) ? $opts['dest_cpt'] : 'destinations';
=======
 * Honors the admin-configured keys.
 */
add_action('init', function () {
	$opts = BFR_Helpers::get_opts();
	$cpt  = $opts['dest_cpt'] ?? 'destinations';
>>>>>>> main

	$common = [
		'object_subtype' => $cpt,
		'single'         => true,
		'show_in_rest'   => true,           // Needed for Elementor and REST usage
		'auth_callback'  => '__return_true' // Publicly readable
	];

<<<<<<< HEAD
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
=======
	$map = [
		$opts['out_school_count']     => ['type'=>'integer'],
		$opts['out_max_depth']        => ['type'=>'number'],
		$opts['out_min_course_price'] => ['type'=>'number'],
		$opts['out_languages']        => ['type'=>'string'],
		$opts['out_facilities']       => ['type'=>'string'],
	];

	foreach ($map as $meta_key => $args) {
		if ( is_string($meta_key) && $meta_key !== '' ) {
			register_meta('post', $meta_key, $common + $args);
		}
	}
});

/**
 * Make the JetEngine UI fields (if you create a meta box with the same keys)
 * appear read-only on Destination edit screens.
 */
>>>>>>> main
add_action('admin_print_styles', function () {
	if ( ! function_exists('get_current_screen') ) return;
	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' ) return;

<<<<<<< HEAD
	$opts     = get_option(BFR_CORE_OPTION, []);
	$dest_cpt = isset($opts['dest_cpt']) && is_string($opts['dest_cpt']) ? $opts['dest_cpt'] : 'destinations';
=======
	$opts     = BFR_Helpers::get_opts();
	$dest_cpt = $opts['dest_cpt'] ?? 'destinations';
>>>>>>> main
	if ( $screen->post_type !== $dest_cpt ) return;

	$keys = [
		$opts['out_school_count'],
		$opts['out_max_depth'],
		$opts['out_min_course_price'],
		$opts['out_languages'],
		$opts['out_facilities'],
	];

	echo '<style>';
	foreach ($keys as $k) {
<<<<<<< HEAD
		// JetEngine admin inputs commonly: name="__meta[KEY]" or wrappers with data-field-name="KEY"
=======
		if (!$k) continue;
>>>>>>> main
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