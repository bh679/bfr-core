<?php
/**
 * Plugin Name: BFR Core (OOP Refactor)
 * Description: Clean OOP architecture for calculated meta fields (MaxDepth, Languages, Facilities, SchoolCount).
 * Version:     1.0.0
 * Author:      Book Freediving Retreats
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('BFR_CORE_VERSION', '1.0.0');
define('BFR_CORE_FILE', __FILE__);
define('BFR_CORE_DIR', plugin_dir_path(__FILE__));
define('BFR_CORE_URL', plugin_dir_url(__FILE__));

// Simple PSR-4-ish autoloader for the BFR\ namespace.
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class, 'BFR\\')) {
        $path = BFR_CORE_DIR . 'src/' . str_replace(['BFR\\', '\\'], ['', '/'], $class) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});

// Bootstrap
add_action('plugins_loaded', static function (): void {
    // Load default registry (can be overridden by options saved via Admin editor).
    $default_registry = require BFR_CORE_DIR . 'src/Config/registry.php';

    $loader = new BFR\Core\Loader($default_registry);
    $loader->register();
});
