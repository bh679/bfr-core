<?php

/**
 * Plugin bootstrap for BFR Core.
 *
 * - Defines global plugin constants (paths/URLs/option/cron IDs).
 * - Loads Composer's PSR-4 autoloader (vendor/autoload.php).
 * - Wires WordPress activation/deactivation hooks.
 * - Boots main services on `plugins_loaded`:
 *     • \BFR\Core\Aggregator  — aggregation engine (cron + triggers).
 *     • \BFR\Core\Admin       — settings UI + admin actions.
 * - Registers public destination aggregate meta on `init`.
 * - Makes computed fields read-only in the destination editor.
 *
 * Keep this file tiny: no business logic here.
 *
 * @package   BFR\Core
 * @since     0.6.1
 */
/**
 * Plugin Name:       BFR Core
 * Description:       Site-specific logic and destination aggregates for Book Freediving Retreats.
 * Author:            Brennan Hatton
 * Version:           0.6.0
 */

if (!defined('ABSPATH')) exit;

/** Global plugin constants (kept global on purpose for broad access) */
define('BFR_CORE_FILE', __FILE__);
define('BFR_CORE_DIR',  plugin_dir_path(__FILE__));
define('BFR_CORE_URL',  plugin_dir_url(__FILE__));

/** Stable option/cron identifiers */
define('BFR_CORE_OPTION',    'bfr_core_options');
define('BFR_CORE_CRON_HOOK', 'bfr_destinations_recalc');

/**
 * Minimal PSR-4 style autoloader for our namespace.
 * Maps: BFR\Core\Foo\Bar  ->  /src/Foo/Bar.php
 */
spl_autoload_register(function ($class) {
	$prefix = 'BFR\\Core\\';
	if (strpos($class, $prefix) !== 0) return;

	$relative = substr($class, strlen($prefix));          // "Aggregator" or "Foo\Bar"
	$relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
	$file     = BFR_CORE_DIR . 'src/' . $relative . '.php';

	if (is_readable($file)) require $file;
});

/** Use fully-qualified class names so we don’t depend on `use` ordering */
register_activation_hook(   BFR_CORE_FILE, ['BFR\\Core\\Aggregator', 'activate_static']);
register_deactivation_hook( BFR_CORE_FILE, ['BFR\\Core\\Aggregator', 'deactivate_static']);

/** Boot services */
add_action('plugins_loaded', function () {
	BFR\Core\Aggregator::instance(); // cron, triggers, compute logic
	BFR\Core\Admin::instance();      // settings UI + admin actions
});

/** Register aggregate meta for Destinations (Elementor/REST) */
add_action('init', function () {
	$defs = BFR\Core\Aggregator::instance()->defaults();
	$cfg  = wp_parse_args(get_option(BFR_CORE_OPTION, []), $defs);
	$cpt  = is_string($cfg['dest_cpt']) ? $cfg['dest_cpt'] : 'destinations';

	$common = [
		'object_subtype' => $cpt,
		'single'         => true,
		'show_in_rest'   => true,
		'auth_callback'  => '__return_true',
	];

	register_meta('post', $cfg['dest_meta_school_count'],     $common + ['type' => 'integer']);
	register_meta('post', $cfg['dest_meta_max_depth'],        $common + ['type' => 'number']);
	register_meta('post', $cfg['dest_meta_min_course_price'], $common + ['type' => 'number']);
	register_meta('post', $cfg['dest_meta_languages'],        $common + ['type' => 'string']);
	register_meta('post', $cfg['dest_meta_facilities'],       $common + ['type' => 'string']);
});

/** Make computed fields read-only in the Destinations editor */
add_action('admin_print_styles', function () {
	if (!function_exists('get_current_screen')) return;
	$screen = get_current_screen();
	if (!$screen || $screen->base !== 'post') return;

	$defs = BFR\Core\Aggregator::instance()->defaults();
	$cfg  = wp_parse_args(get_option(BFR_CORE_OPTION, []), $defs);
	$dest_cpt = is_string($cfg['dest_cpt']) ? $cfg['dest_cpt'] : 'destinations';

	if ($screen->post_type !== $dest_cpt) return;

	$keys = [
		$cfg['dest_meta_school_count'],
		$cfg['dest_meta_max_depth'],
		$cfg['dest_meta_min_course_price'],
		$cfg['dest_meta_languages'],
		$cfg['dest_meta_facilities'],
	];

	echo '<style>';
	foreach ($keys as $k) {
		echo '[name="__meta['.esc_attr($k).']"],'
		   . ' [data-field-name="'.esc_attr($k).'"] input,'
		   . ' [data-field-name="'.esc_attr($k).'"] textarea'
		   . ' { pointer-events:none; background:#f6f7f7; }';
	}
	echo '</style>';
});

/** Convenience: Settings link in plugin row */
add_filter('plugin_action_links_' . plugin_basename(BFR_CORE_FILE), function($links){
	$url = admin_url('options-general.php?page=bfr-core');
	array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'bfr-core').'</a>');
	return $links;
});