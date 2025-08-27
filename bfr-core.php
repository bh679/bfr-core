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
 * Register activation/deactivation.
 * Keep hooks in the main file so WordPress always finds them.
 */
register_activation_hook( BFR_CORE_FILE,  ['BFR_Aggregator', 'activate_static'] );
register_deactivation_hook( BFR_CORE_FILE, ['BFR_Aggregator', 'deactivate_static'] );

/** Boot everything once plugins are loaded */
add_action('plugins_loaded', function () {
    BFR_Aggregator::instance(); // logic, cron, triggers
    BFR_Admin::instance();      // settings UI
});