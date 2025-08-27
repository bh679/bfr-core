<?php
/**
 * Plugin Name: BFR Core
 * Description: Site-specific logic and destination aggregates for Book Freediving Retreats.
 * Author: Brennan Hatton
 * Version: 0.6.0
 */

if ( ! defined('ABSPATH') ) exit;

define('BFR_CORE_FILE', __FILE__);
define('BFR_CORE_DIR',  plugin_dir_path(__FILE__));
define('BFR_CORE_URL',  plugin_dir_url(__FILE__));

require_once BFR_CORE_DIR . 'includes/class-bfr-helpers.php';
require_once BFR_CORE_DIR . 'includes/class-bfr-aggregator.php';
require_once BFR_CORE_DIR . 'includes/class-bfr-admin.php';

register_activation_hook(   BFR_CORE_FILE, ['BFR_Aggregator', 'activate_static']   );
register_deactivation_hook( BFR_CORE_FILE, ['BFR_Aggregator', 'deactivate_static'] );

add_action('plugins_loaded', function () {
	BFR_Aggregator::instance(); // logic, cron, triggers
	BFR_Admin::instance();      // settings UI
});

/**
 * Register destination aggregate meta so Elementor/REST can read them.
 * Honors the admin-configured keys.
 */
add_action('init', function () {
	$opts = BFR_Helpers::get_opts();
	$cpt  = $opts['dest_cpt'] ?? 'destinations';

	$common = [
		'object_subtype' => $cpt,
		'single'         => true,
		'show_in_rest'   => true,
		'auth_callback'  => '__return_true',
	];

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
add_action('admin_print_styles', function () {
	if ( ! function_exists('get_current_screen') ) return;
	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' ) return;

	$opts     = BFR_Helpers::get_opts();
	$dest_cpt = $opts['dest_cpt'] ?? 'destinations';
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
		if (!$k) continue;
		echo '[name="__meta['.esc_attr($k).']"],'
		   . ' [data-field-name="'.esc_attr($k).'"] input,'
		   . ' [data-field-name="'.esc_attr($k).'"] textarea'
		   . ' { pointer-events:none; background:#f6f7f7; }';
	}
	echo '</style>';
});