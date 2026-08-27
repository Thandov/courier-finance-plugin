<?php
/**
 * WordPress Bootstrap for Courier Finance Plugin
 * 
 * This file provides a robust way to load WordPress from the plugin directory
 * and handles common path issues that can cause deprecation errors.
 * 
 * @package CourierFinancePlugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // Try multiple possible paths to find WordPress root
    $possible_paths = [
        __DIR__ . '/../../../../',  // Plugin -> wp-content -> plugins -> root
        __DIR__ . '/../../../',     // Plugin -> wp-content -> root
        __DIR__ . '/../../',        // Plugin -> root
        dirname(__DIR__, 4) . '/',  // Go up 4 levels from plugin
        dirname(__DIR__, 3) . '/',  // Go up 3 levels from plugin
    ];
    
    $wp_load_found = false;
    
    foreach ($possible_paths as $path) {
        $wp_load_path = $path . 'wp-load.php';
        if (file_exists($wp_load_path)) {
            // Suppress errors during WordPress loading
            $old_error_reporting = error_reporting();
            error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);
            
            require_once $wp_load_path;
            
            // Restore error reporting
            error_reporting($old_error_reporting);
            $wp_load_found = true;
            break;
        }
    }
    
    if (!$wp_load_found) {
        die('WordPress not found. Please ensure the plugin is installed in the correct directory.');
    }
    
    // Verify that ABSPATH was successfully defined after loading WordPress
    if (!defined('ABSPATH')) {
        die('WordPress loaded but ABSPATH constant is not defined. WordPress may not have loaded correctly.');
    }
}

// Load Composer autoloader if available (for libraries like dompdf, google/apiclient, qr-code)
if (!class_exists('Composer\\Autoload\\ClassLoader')) {
    $vendorRoot = __DIR__ . '/vendor';
    $composerAutoload = $vendorRoot . '/autoload.php';
    $composerAutoloadReal = $vendorRoot . '/composer/autoload_real.php';

    if (!is_file($composerAutoload)) {
        $vendorRoot = dirname(__FILE__) . '/vendor';
        $composerAutoload = $vendorRoot . '/autoload.php';
        $composerAutoloadReal = $vendorRoot . '/composer/autoload_real.php';
    }

    if (is_file($composerAutoload) && is_file($composerAutoloadReal)) {
        require_once $composerAutoload;
    } elseif (is_file($composerAutoload) && !is_file($composerAutoloadReal)) {
        // Partial vendor upload: autoload.php exists but composer/ files are missing.
        if (function_exists('error_log')) {
            error_log('Courier Finance Plugin: incomplete vendor/ folder — run composer install or re-upload vendor/composer/');
        }
    }
}

// Load KIT_Commons for color helpers if available
if (class_exists('KIT_Commons')) {
    // KIT_Commons is already loaded
} else {
    // Verify ABSPATH is defined before using it
    if (defined('ABSPATH')) {
        // Try to load KIT_Commons from common locations
        $kit_paths = [
            ABSPATH . 'wp-content/plugins/kit-commons/kit-commons.php',
            ABSPATH . 'wp-content/mu-plugins/kit-commons.php',
            ABSPATH . 'wp-content/themes/kit-commons/kit-commons.php',
        ];
        
        foreach ($kit_paths as $kit_path) {
            if (file_exists($kit_path)) {
                require_once $kit_path;
                break;
            }
        }
    }
}

// Set up error handling for the plugin
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    // Custom error handler for plugin-specific issues
    set_error_handler(function($severity, $message, $file, $line) {
        // Only handle errors from our plugin
        if (strpos($file, 'courier-finance-plugin') !== false) {
            // Log the error but don't display it
            error_log("Courier Finance Plugin Error: $message in $file on line $line");
            
            // Suppress deprecation warnings
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                return true;
            }
        }
        
        return false; // Let other errors be handled normally
    }, E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_WARNING);
}

// Ensure WordPress is fully loaded
if (!function_exists('wp_get_current_user')) {
    // WordPress is not fully loaded, try to load it
    if (function_exists('wp_loaded')) {
        do_action('wp_loaded');
    }
}

// Plugin-specific constants
if (!defined('COURIER_FINANCE_PLUGIN_VERSION')) {
    define('COURIER_FINANCE_PLUGIN_VERSION', '3.2.0');
}

/**
 * When true (default), Apps Script owns Waybills → kit_* sheet projection and
 * Courier_Google_Sheets_Sync::push_all will not overwrite those tabs from MySQL.
 * Set to false only if Apps Script projection is retired.
 */
if (!defined('COURIER_APPS_SCRIPT_OWNS_KIT_SHEETS')) {
    define('COURIER_APPS_SCRIPT_OWNS_KIT_SHEETS', true);
}

if (!defined('COURIER_FINANCE_PLUGIN_PATH')) {
    define('COURIER_FINANCE_PLUGIN_PATH', plugin_dir_path(__FILE__));
}

if (!defined('COURIER_FINANCE_PLUGIN_URL')) {
    define('COURIER_FINANCE_PLUGIN_URL', plugin_dir_url(__FILE__));
}
