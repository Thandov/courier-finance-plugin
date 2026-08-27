<?php
//file location: 
/**
 * Plugin Name: 08600 Services and Quotations
 * Description: Plugin to manage services and quotations.
 * Version: 3.2.0
 * Author: Thando Hlophe kayise it
 * Author URI: https://kayiseit.com
 * Text Domain: 08600-services-quotations
 */

// PHP 8.1+ Deprecation Warning Suppression - ENABLED
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    // Suppress deprecation warnings at plugin level
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    @ini_set('display_errors', '0');

    // Set custom error handler to catch and suppress deprecation warnings
    set_error_handler(function($severity, $message, $file, $line) {
        // Suppress deprecation warnings completely
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            return true; // Don't execute PHP's internal error handler
        }
        return false; // Let other errors through normally
    });

    // Start output buffering to catch any warnings (skip REST API - would corrupt JSON)
    $is_rest_request = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'wp-json') !== false;
    $is_admin_post = isset($_SERVER['SCRIPT_NAME']) && basename((string) $_SERVER['SCRIPT_NAME']) === 'admin-post.php';
    $is_admin_ajax = isset($_SERVER['SCRIPT_NAME']) && basename((string) $_SERVER['SCRIPT_NAME']) === 'admin-ajax.php';
    if (!headers_sent() && !$is_rest_request && !$is_admin_post && !$is_admin_ajax) {
        ob_start(function($buffer) {
            if ($buffer === null || $buffer === '') {
                return $buffer;
            }
            // Skip regex on very large buffers to avoid timeout/memory exhaustion (DOM crash)
            if (strlen($buffer) > 4 * 1024 * 1024) {
                return $buffer;
            }
            // Remove deprecation warnings from the output buffer (safety net)
            $cleaned = preg_replace('/Deprecated:\s.*?(?:\n|<br\s*\/?>)/is', '', $buffer);
            return $cleaned !== null ? $cleaned : $buffer;
        });
    }
}

// Load the bootstrap file for proper WordPress loading and error handling
// Use __DIR__ instead of plugin_dir_path() to avoid calling WordPress functions before WordPress is loaded
$bootstrap_path = __DIR__ . '/bootstrap.php';
if (file_exists($bootstrap_path)) {
    require_once $bootstrap_path;
} else {
    die('Bootstrap file not found. Please ensure the plugin is installed correctly.');
}

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants are now defined in bootstrap.php



// Enqueue styles for the admin panel
function customStyling()
{
    // Avoid calling get_current_screen() in CLI or when admin context is not set (causes fatal/crash)
    if (php_sapi_name() === 'cli' || !function_exists('get_current_screen')) {
        return;
    }
    $screen = get_current_screen();
    if ($screen === null) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    // Only load CSS on our plugin's admin pages to avoid conflicts
    $is_routes_page = in_array($page, ['route-management', 'route-create'], true);
    $is_customer_page = in_array($page, ['edit-customer', '08600-add-customer'], true);
    // Hidden slugs (parent null) do not get an 08600_* screen id — still plugin pages.
    $hidden_plugin_pages = array(
        'view-deliveries',
        '08600-Waybill-view',
        'route-create',
        '08600-google-sheets-test',
    );
    $is_hidden_plugin_page = in_array($page, $hidden_plugin_pages, true);
    // Pages that should use the modern dashboard layout (and need dashboard.css)
    $dashboard_like_pages = array(
        '08600-dashboard',
        '08600-waybill-manage',
        'warehouse-waybills',
        'kit-deliveries',
        '08600-trip-create',
        '08600-customers',
        '08600-booking-requests',
        '08600-customer-portal-accounts',
    );
    $is_dashboard = in_array($page, $dashboard_like_pages, true);
    $is_plugin_page = ($screen && $screen->id && strpos($screen->id, '08600') !== false) || $is_routes_page || $is_customer_page || $is_dashboard || $is_hidden_plugin_page;
    if ($is_plugin_page) {
        wp_enqueue_style('autsincss', plugin_dir_url(__FILE__) . 'assets/css/austin.css', array(), '1.0');
        wp_enqueue_style('kit-tailwindcss', plugin_dir_url(__FILE__) . 'assets/css/frontend.css', array(), '1.1');
        wp_add_inline_style('autsincss', 'body.courier-finance-plugin #wpbody-content { padding-bottom: 50px; }');
        // Fix footer overflow/overlap only on Settings (Setup Seed) page
        if ($page === '08600-settings') {
            wp_add_inline_style('autsincss',
                '#wpbody-content { padding-bottom: 65px; }'
                . ' #wpfooter #footer-left { max-width: 100%; overflow-wrap: break-word; word-wrap: break-word; min-width: 0; }'
            );
        }
        if ($is_dashboard) {
            wp_enqueue_style('kit-dashboard-css', plugin_dir_url(__FILE__) . 'assets/css/dashboard.css', array('kit-tailwindcss'), '1.13');
            wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
            wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
            wp_enqueue_script('kit-dashboard-map', plugin_dir_url(__FILE__) . 'assets/js/dashboard-map.js', array('jquery', 'leaflet-js'), '1.1', true);
            $map_center_address = class_exists('KIT_Company')
                ? (string) (KIT_Company::get_details_array()['company_address'] ?? KIT_Company::COMPANY_ADDRESS)
                : 'Unit 1, Kya North Park, 28 Bernie St, Kya Sands, Randburg, 2188';
            /* Fallback coords for Kya Sands, Randburg when Nominatim fails */
            $map_fallback_lat = -26.0789;
            $map_fallback_lng = 28.0123;
            wp_localize_script('kit-dashboard-map', 'kitDashboardMap', array(
                'ajaxurl'              => admin_url('admin-ajax.php'),
                'nonce'                => wp_create_nonce('kit_dashboard_map'),
                'map_center_address'   => $map_center_address,
                'map_geocode_query'    => 'Kya Sands, Randburg, South Africa',
                'map_fallback_lat'     => $map_fallback_lat,
                'map_fallback_lng'     => $map_fallback_lng,
                'map_logo_url'         => plugin_dir_url(__FILE__) . 'img/logo.png',
            ));
        }
        // Add CSS class wrapper to admin body for scoping
        add_filter('admin_body_class', function($classes) {
            return $classes . ' courier-finance-plugin';
        });
    }
}

// Ensure admin styles are enqueued
add_action('admin_enqueue_scripts', 'customStyling');

// Enqueue styles for the frontend
function customStylingFrontend()
{
    wp_enqueue_style('autsincss-frontend', plugin_dir_url(__FILE__) . 'assets/css/austin.css', array(), '1.0');
}
add_action('wp_enqueue_scripts', 'customStylingFrontend');


// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/class-database.php';
include_once(plugin_dir_path(__FILE__) . 'includes/class-plugin.php');
require_once plugin_dir_path(__FILE__) . 'includes/class-server-connection.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-unified-table.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-kit-bulk-action-log.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-google-sheets.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-google-sheets-sync.php';

// New sync pipeline (Step 4 of the sheet → DB refactor). Independent of the
// legacy class-google-sheets-sync.php / run_google_sheet_seed pipeline — runs
// in shadow mode by default so it can be compared side-by-side before cutover.
// Canonical separator-aware parser for numbers read out of Google Sheets. The
// API returns FORMATTED values, so money cells arrive as display text
// ("R 1 200,50") — must load before anything that parses sheet rows.
require_once plugin_dir_path(__FILE__) . 'includes/sync/kit-seed-number-parse.php';
// charge_basis helpers, shared so cron and admin agree on the billed freight total.
require_once plugin_dir_path(__FILE__) . 'includes/sync/kit-charge-basis.php';
// Business-name detection, shared so cron/CLI seeds promote companies too.
require_once plugin_dir_path(__FILE__) . 'includes/sync/kit-business-name.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-sync-run-log.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/kit-sheet-delivery-resolve.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/kit-seed-date-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/kit-seed-user-resolve.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/kit-seed-waybill-items.php';
require_once plugin_dir_path(__FILE__) . 'includes/routes/routes-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-driver-seeder.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-delivery-seeder.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-waybill-seeder.php';
// Verification gate — a seed is not "successful" until every sheet waybill has
// been read back out of wp_kit_waybills and confirmed field-for-field.
// Must load after the seeder: it reuses the seeder's mapping + equality rules.
// Companies own a sheet tab and a table of their own; import it before
// customers and waybills so party resolution reads real company ids instead of
// inventing them. Must load before KIT_Customer_Seeder, which delegates to it.
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-company-seeder.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-customer-seeder.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-seed-pipeline.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-waybill-preflight.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-waybill-verifier.php';
// kit_parcels sheet bridge retired — line items seed via kit-seed-waybill-items.php → wp_kit_waybill_items
if (file_exists(plugin_dir_path(__FILE__) . 'includes/sync/class-kit-parcel-seeder.php')) {
    require_once plugin_dir_path(__FILE__) . 'includes/sync/class-kit-parcel-seeder.php';
}
require_once plugin_dir_path(__FILE__) . 'includes/admin-pages/sync-runs.php';

add_action('init', function () {
    // Schema migrations used to run only on activation, so a widening applied
    // after install never reached an existing site (total_volume stayed
    // DECIMAL(10,2) and kept rounding 0.08649 to 0.09). Re-run them whenever the
    // plugin version changes.
    $installed = get_option('kit_schema_version');
    $current   = defined('COURIER_FINANCE_PLUGIN_VERSION') ? COURIER_FINANCE_PLUGIN_VERSION : '3.2.0';
    if ($installed !== $current && class_exists('Database')) {
        if (method_exists('Database', 'ensure_waybill_volume_precision')) {
            Database::ensure_waybill_volume_precision();
        }
        update_option('kit_schema_version', $current, false);
    }
    if (class_exists('KIT_Sync_Run_Log')) {
        KIT_Sync_Run_Log::ensure_tables();
    }
    if (class_exists('KIT_Waybill_Seeder')) {
        KIT_Waybill_Seeder::register_cron();
    }
});

register_deactivation_hook(__FILE__, function () {
    if (class_exists('KIT_Waybill_Seeder')) {
        KIT_Waybill_Seeder::unregister_cron();
    }
});
// Initialize GitHub-based updates if available
require_once plugin_dir_path(__FILE__) . 'includes/update-checker.php';

// Include core class files
require_once plugin_dir_path(__FILE__) . 'includes/commons.php';
require_once plugin_dir_path(__FILE__) . 'includes/user-roles.php';
require_once plugin_dir_path(__FILE__) . 'includes/customers/customers-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/customers/company-customers-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/deliveries/deliveries-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/warehouse/warehouse-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/waybill/waybill-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-pages.php';
require_once plugin_dir_path(__FILE__) . 'includes/dashboard/dashboard-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-menu.php';
require_once plugin_dir_path(__FILE__) . 'includes/waybillmultiform.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/employee-portal.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-kit-booking-requests.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/customer-portal-init.php';
require_once plugin_dir_path(__FILE__) . 'includes/api/class-kit-rest-api.php';

/**
 * Whether plugin maintenance mode is enabled (Settings → Setup Seed).
 */
function kit_maintenance_mode_enabled() {
    return (bool) get_option('kit_maintenance_mode', 0);
}

/**
 * True when the current admin screen is a Courier Finance plugin page (same rules as admin styles).
 */
function kit_is_courier_plugin_admin_screen() {
    if (php_sapi_name() === 'cli' || !function_exists('get_current_screen')) {
        return false;
    }
    $screen = get_current_screen();
    if ($screen === null) {
        return false;
    }
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    $is_routes_page = in_array($page, ['route-management', 'route-create'], true);
    $is_customer_page = in_array($page, ['edit-customer', '08600-add-customer'], true);
    $dashboard_like_pages = array(
        '08600-dashboard',
        '08600-waybill-manage',
        'warehouse-waybills',
        'kit-deliveries',
        '08600-trip-create',
        '08600-customers',
        '08600-booking-requests',
        '08600-customer-portal-accounts',
    );
    $is_dashboard = in_array($page, $dashboard_like_pages, true);
    return ($screen->id && strpos($screen->id, '08600') !== false) || $is_routes_page || $is_customer_page || $is_dashboard;
}

/**
 * Show full-screen maintenance layer on plugin admin pages (except Settings for authorized admins).
 */
function kit_courier_admin_should_show_maintenance_layer() {
    if (!kit_maintenance_mode_enabled()) {
        return false;
    }
    if (!is_user_logged_in() || !current_user_can('kit_view_waybills')) {
        return false;
    }
    if (!kit_is_courier_plugin_admin_screen()) {
        return false;
    }
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    if ($page === '08600-settings' && class_exists('KIT_User_Roles') && KIT_User_Roles::can_access_settings()) {
        return false;
    }
    return true;
}

/**
 * Blur #wpbody-content only; overlay sits inside #wpbody so the admin menu and rest of wp-admin stay usable.
 */
function kit_courier_admin_maintenance_overlay() {
    if (!kit_courier_admin_should_show_maintenance_layer()) {
        return;
    }
    echo '<style id="kit-courier-maint-admin-css">'
        . 'body.kit-courier-maintenance-lock #wpbody{position:relative;}'
        . 'body.kit-courier-maintenance-lock #wpbody-content{filter:blur(9px);pointer-events:none;user-select:none;}'
        . '#kit-courier-maintenance-overlay{position:absolute;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(248,250,252,0.72);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);}'
        . '</style>';
    echo '<div id="kit-courier-maintenance-overlay" role="alertdialog" aria-modal="true" aria-labelledby="kit-courier-maintenance-title">'
        . '<div style="text-align:center;padding:2rem 2.5rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 40px rgba(15,23,42,0.12);max-width:420px;">'
        . '<p id="kit-courier-maintenance-title" style="margin:0;font-size:1.35rem;font-weight:600;color:#0f172a;letter-spacing:-0.02em;">Maintenance mode</p>'
        . '<p style="margin:0.75rem 0 0 0;font-size:0.9rem;color:#64748b;line-height:1.5;">This plugin is temporarily unavailable. An authorized administrator can turn this off in Settings.</p>'
        . '</div></div>';
    echo '<script>(function(){var o=document.getElementById("kit-courier-maintenance-overlay"),b=document.getElementById("wpbody");if(o&&b){b.appendChild(o);}}());</script>';
}

add_action('admin_footer', 'kit_courier_admin_maintenance_overlay', 999);

add_filter('admin_body_class', function ($classes) {
    if (!kit_courier_admin_should_show_maintenance_layer()) {
        return $classes;
    }
    return $classes . ' kit-courier-maintenance-lock';
}, 25);

// Initialize classes
add_action('init', function() {
    if (class_exists('KIT_Commons')) {
        KIT_Commons::init();
    }
    if (class_exists('Database')) {
        Database::drop_legacy_foreign_keys();
        Database::create_company_customers_table();
        Database::ensure_customer_company_id_column();
        Database::ensure_waybill_company_id_column();
        Database::maybe_repair_waybill_vat_flags();
        Database::maybe_migrate_company_customers();
        Database::drop_customer_company_name_column();
        Database::maybe_backfill_waybill_company_ids();
    }
    if (class_exists('KIT_Waybills')) {
        KIT_Waybills::init();
    }
    if (class_exists('KIT_Deliveries')) {
        KIT_Deliveries::init();
    }
    if (class_exists('KIT_Dashboard')) {
        KIT_Dashboard::init();
    }
    if (class_exists('Plugin')) {
        Plugin::init();
    }
});

// Register admin menu
add_action('admin_menu', 'plugin_add_menu');

// Show Google Sheets sync error so user knows when waybill didn't insert into sheet
add_action('admin_notices', function () {
    if (!current_user_can('kit_view_waybills')) {
        return;
    }
    $err = get_option('courier_last_sync_error', null);
    if (!is_array($err) || empty($err['message'])) {
        return;
    }
    $waybill_no = isset($err['waybill_no']) ? esc_html($err['waybill_no']) : '';
    $msg = esc_html($err['message']);
    echo '<div class="notice notice-warning is-dismissible"><p><strong>Google Sheets sync failed</strong> for waybill ' . $waybill_no . ': ' . $msg . ' Check PHP error log for details.</p></div>';
});

// Register shortcodes
add_shortcode('waybill_multiform', 'kit_render_waybill_multiform');

// Activate and deactivate hooks (guard against unexpected output) - SIMPLIFIED FOR DEBUGGING
register_activation_hook(__FILE__, function() {
    try {
        Database::activate();
        
        // Schedule daily delivery status update cron job
        if (class_exists('KIT_Deliveries')) {
            KIT_Deliveries::schedule_daily_delivery_status_update();
        }

        // Create employee portal pages (login + dashboard)
        kit_create_employee_portal_pages();
        kit_create_customer_portal_pages();
    } catch (Exception $e) {
        // Log error but don't break activation
        error_log('Plugin activation error: ' . $e->getMessage());
    }
});

/**
 * Create employee login and dashboard pages if they don't exist.
 * Runs on activation and on init (one-time migration for existing installs).
 */
function kit_create_employee_portal_pages() {
    $option_key = 'kit_employee_portal_pages_created';
    if (get_option($option_key) === 'yes') {
        // Ensure dashboard page exists even when option was set early (e.g. before dashboard was added)
    $dashboard_page = get_page_by_path('employee-dashboard', OBJECT, 'page');
    if (!$dashboard_page) {
        wp_insert_post([
            'post_title'   => 'Employee Dashboard',
            'post_name'    => 'employee-dashboard',
            'post_content' => '[kit_employee_portal]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ]);
        flush_rewrite_rules(false);
    }
    // One-time flush so pretty permalinks work if they were never flushed (e.g. existing installs)
    if (get_option('kit_employee_portal_rewrite_flushed') !== 'yes') {
        flush_rewrite_rules(true);
        update_option('kit_employee_portal_rewrite_flushed', 'yes');
    }
    return;
}

    // Prefer new /login slug but support legacy /employee-login for existing installs
    $login_page = get_page_by_path('login', OBJECT, 'page');
    if (!$login_page) {
        $login_page = get_page_by_path('employee-login', OBJECT, 'page');
    }
    if (!$login_page) {
        wp_insert_post([
            'post_title'   => 'Login',
            'post_name'    => 'login',
            'post_content' => '[kit_employee_login]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ]);
    }

    $dashboard_page = get_page_by_path('employee-dashboard', OBJECT, 'page');
    if (!$dashboard_page) {
        wp_insert_post([
            'post_title'   => 'Employee Dashboard',
            'post_name'    => 'employee-dashboard',
            'post_content' => '[kit_employee_portal]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ]);
    }

    update_option($option_key, 'yes');
    flush_rewrite_rules(false);
}
add_action('init', function() {
    kit_create_employee_portal_pages();
    kit_create_customer_portal_pages();
    kit_employee_portal_migrate_dashboard_shortcode();
}, 99);

/**
 * Create hierarchical customer portal pages: /customer/login/, /customer/dashboard/.
 */
function kit_create_customer_portal_pages()
{
    $option_key = 'kit_customer_portal_pages_created';
    $touched = false;

    $parent = get_page_by_path('customer', OBJECT, 'page');
    if (!$parent || $parent->post_status !== 'publish') {
        $parent_id = wp_insert_post(array(
            'post_title'   => 'Customer portal',
            'post_name'    => 'customer',
            'post_content' => '',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ));
        if (is_wp_error($parent_id)) {
            return;
        }
        $parent_id = (int) $parent_id;
        $touched = true;
    } else {
        $parent_id = (int) $parent->ID;
    }

    $login = get_page_by_path('customer/login', OBJECT, 'page');
    if (!$login || $login->post_status !== 'publish') {
        wp_insert_post(array(
            'post_title'   => 'Customer Login',
            'post_name'    => 'login',
            'post_parent'  => $parent_id,
            'post_content' => '[kit_customer_login]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ));
        $touched = true;
    }

    $dash = get_page_by_path('customer/dashboard', OBJECT, 'page');
    if (!$dash || $dash->post_status !== 'publish') {
        wp_insert_post(array(
            'post_title'   => 'Customer Dashboard',
            'post_name'    => 'dashboard',
            'post_parent'  => $parent_id,
            'post_content' => '[kit_customer_portal]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ));
        $touched = true;
    }

    $register = get_page_by_path('customer/register', OBJECT, 'page');
    if (!$register || $register->post_status !== 'publish') {
        wp_insert_post(array(
            'post_title'   => 'Customer Register',
            'post_name'    => 'register',
            'post_parent'  => $parent_id,
            'post_content' => '[kit_customer_register]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ));
        $touched = true;
    }

    if ($touched) {
        flush_rewrite_rules(false);
    }
    if (get_option($option_key) !== 'yes') {
        update_option($option_key, 'yes');
    }
}

/**
 * One-time migration: ensure Employee Dashboard page uses [kit_employee_portal] so ?section= works.
 */
function kit_employee_portal_migrate_dashboard_shortcode() {
    if (get_option('kit_employee_portal_shortcode_migrated') === 'yes') {
        return;
    }
    $page = get_page_by_path('employee-dashboard', OBJECT, 'page');
    if (!$page || $page->post_status !== 'publish') {
        update_option('kit_employee_portal_shortcode_migrated', 'yes');
        return;
    }
    $content = $page->post_content;
    if (strpos($content, '[kit_employee_portal]') !== false) {
        update_option('kit_employee_portal_shortcode_migrated', 'yes');
        return;
    }
    if (strpos($content, '[kit_employee_dashboard]') !== false) {
        $new_content = str_replace('[kit_employee_dashboard]', '[kit_employee_portal]', $content);
        wp_update_post(array(
            'ID'           => $page->ID,
            'post_content' => $new_content,
        ));
    }
    update_option('kit_employee_portal_shortcode_migrated', 'yes');
}

// When an admin visits Settings → Permalinks, flush rewrite rules so portal URLs work (fixes 404)
add_action('load-options-permalink.php', function() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $dashboard = get_page_by_path('employee-dashboard', OBJECT, 'page');
    // Check both new and legacy slugs so permalinks flush works after upgrades
    $login = get_page_by_path('login', OBJECT, 'page');
    if (!$login) {
        $login = get_page_by_path('employee-login', OBJECT, 'page');
    }
    $customer_login = get_page_by_path('customer/login', OBJECT, 'page');
    $customer_dash = get_page_by_path('customer/dashboard', OBJECT, 'page');
    $customer_register = get_page_by_path('customer/register', OBJECT, 'page');
    if ($dashboard || $login || $customer_login || $customer_dash || $customer_register) {
        flush_rewrite_rules(true);
    }
});

// CRITICAL: Register deactivation hook BEFORE any WordPress functions are called
// This ensures WordPress can find and execute the hook even if plugin fails to load
function courier_finance_plugin_deactivate() {
    $timestamp = date('Y-m-d H:i:s');
    
    // Determine log file path - try multiple locations
    $log_paths = [
        __DIR__ . '/../../deactivation-debug.log',  // wp-content/deactivation-debug.log
        __DIR__ . '/deactivation-debug.log',  // plugin directory (fallback)
        '/tmp/courier-finance-deactivation.log',  // System temp (guaranteed writable)
    ];
    
    // Try to get WP_CONTENT_DIR if WordPress is loaded
    if (defined('WP_CONTENT_DIR')) {
        array_unshift($log_paths, WP_CONTENT_DIR . '/deactivation-debug.log');
    }
    
    // Also try WordPress debug.log
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_CONTENT_DIR')) {
        $log_paths[] = WP_CONTENT_DIR . '/debug.log';
    }
    
    // Write to ALL possible log locations to ensure we capture it
    $log_message = "[$timestamp] ===== DEACTIVATION HOOK CALLED =====" . PHP_EOL;
    foreach ($log_paths as $path) {
        // Try multiple write methods
        @file_put_contents($path, $log_message, FILE_APPEND | LOCK_EX);
        @file_put_contents($path, $log_message, FILE_APPEND);
        
        // Also try error_log as backup
        @error_log($log_message);
    }
    
    // Try to load Database class
    $db_file = __DIR__ . '/includes/class-database.php';
    if (file_exists($db_file)) {
        foreach ($log_paths as $path) {
            @file_put_contents($path, "[$timestamp] Database file found: $db_file" . PHP_EOL, FILE_APPEND);
        }
        require_once $db_file;
    } else {
        foreach ($log_paths as $path) {
            @file_put_contents($path, "[$timestamp] ERROR: Database file NOT found: $db_file" . PHP_EOL, FILE_APPEND);
        }
    }
    
    // Check if Database class exists
    if (class_exists('Database')) {
        foreach ($log_paths as $path) {
            @file_put_contents($path, "[$timestamp] Database class found, calling deactivate()..." . PHP_EOL, FILE_APPEND);
        }
        try {
            Database::deactivate();
            foreach ($log_paths as $path) {
                @file_put_contents($path, "[$timestamp] Database::deactivate() completed successfully" . PHP_EOL, FILE_APPEND);
            }
        } catch (Exception $e) {
            $error_msg = "[$timestamp] Exception: " . $e->getMessage() . PHP_EOL;
            foreach ($log_paths as $path) {
                @file_put_contents($path, $error_msg, FILE_APPEND);
                @file_put_contents($path, "[$timestamp] Stack: " . $e->getTraceAsString() . PHP_EOL, FILE_APPEND);
            }
        } catch (Error $e) {
            $error_msg = "[$timestamp] Fatal Error: " . $e->getMessage() . PHP_EOL;
            foreach ($log_paths as $path) {
                @file_put_contents($path, $error_msg, FILE_APPEND);
                @file_put_contents($path, "[$timestamp] Stack: " . $e->getTraceAsString() . PHP_EOL, FILE_APPEND);
            }
        }
    } else {
        foreach ($log_paths as $path) {
            @file_put_contents($path, "[$timestamp] ERROR: Database class NOT found after require" . PHP_EOL, FILE_APPEND);
        }
    }

    // Always attempt to drop consolidated waybills and parcels tables on deactivation
    global $wpdb;
    if (isset($wpdb) && $wpdb instanceof wpdb) {
        $consolidated_table = $wpdb->prefix . 'kit_consolidated_waybills';
        $parcels_table      = $wpdb->prefix . 'kit_parcels';

        $drop_sql_1 = "DROP TABLE IF EXISTS {$consolidated_table}";
        $drop_sql_2 = "DROP TABLE IF EXISTS {$parcels_table}";

        $wpdb->query($drop_sql_1);
        $wpdb->query($drop_sql_2);

        foreach ($log_paths as $path) {
            @file_put_contents(
                $path,
                "[$timestamp] Dropped tables on deactivation (if existed): {$consolidated_table}, {$parcels_table}" . PHP_EOL,
                FILE_APPEND
            );
        }
    } else {
        foreach ($log_paths as $path) {
            @file_put_contents($path, "[$timestamp] WARNING: \$wpdb not available during deactivation; tables not dropped." . PHP_EOL, FILE_APPEND);
        }
    }
    
    foreach ($log_paths as $path) {
        @file_put_contents($path, "[$timestamp] ===== DEACTIVATION HOOK ENDED =====" . PHP_EOL, FILE_APPEND);
    }
}

// Register the hook - MUST be at top level, NOT inside a function
register_deactivation_hook(__FILE__, 'courier_finance_plugin_deactivate');

function kit_remove_manage_options_from_editor()
{
    $editor = get_role('editor');
    if ($editor && $editor->has_cap('manage_options')) {
        $editor->remove_cap('manage_options');
    }
}



// Initialize the plugin
Plugin::init();

// Register additional deprecation warning suppression after WordPress is loaded - ENABLED
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    add_action('init', function() {
        // Suppress deprecation warnings during WordPress initialization
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        @ini_set('display_errors', '0');
    }, 1);
    
    // Also suppress on admin pages where these warnings commonly appear
    add_action('admin_init', function() {
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        @ini_set('display_errors', '0');
    }, 1);
}

// Include the service functions
include_once plugin_dir_path(__FILE__) . 'includes/commons.php';
include_once plugin_dir_path(__FILE__) . 'includes/components/toast.php';
include_once plugin_dir_path(__FILE__) . 'includes/admin-menu.php';
include_once plugin_dir_path(__FILE__) . 'includes/admin-pages.php';
include_once(plugin_dir_path(__FILE__) . 'includes/services/services-functions.php');
// Quotations functions include removed
require_once plugin_dir_path(__FILE__) . 'includes/waybill/waybill-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/customers/customers-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/customers/company-customers-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/user-roles.php';
require_once plugin_dir_path(__FILE__) . 'includes/deliveries/deliveries-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/waybillmultiform.php';
require_once plugin_dir_path(__FILE__) . 'includes/countries/opc-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/routes/routes-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/components/quickActions.php';

// Hardening hooks must be registered on load, before any login or XML-RPC request is handled.
require_once plugin_dir_path(__FILE__) . 'includes/security/class-kit-security.php';
KIT_Security::init();

// Maintenance endpoints below are administrator-only: schema migrations and outbound
// connection tests must never be reachable by logged-out requests.
add_action('wp_ajax_migrate_international_price', 'migrate_international_price_callback');
add_action('wp_ajax_kit_migrate_schema', 'kit_migrate_schema_callback');
add_action('wp_ajax_test_server_connection', 'test_server_connection_callback');

/**
 * Shared guard for the maintenance endpoints above.
 */
function kit_require_admin_maintenance_request(string $nonce_action): void {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', $nonce_action)) {
        wp_send_json_error(['message' => 'Security check failed'], 403);
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions'], 403);
    }
}

function migrate_international_price_callback() {
    kit_require_admin_maintenance_request('migrate_international_price');
    
    try {
        // Include the database class
        require_once plugin_dir_path(__FILE__) . 'includes/class-database.php';

        // Run the migration
        Database::add_international_price_field();

        wp_send_json_success(['message' => 'International price field added successfully!']);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Migration failed: ' . $e->getMessage()]);
    }
}

function kit_migrate_schema_callback() {
    kit_require_admin_maintenance_request('kit_migrate_schema');

    try {
        require_once plugin_dir_path(__FILE__) . 'includes/class-database.php';

        // Re-run activation routines which are idempotent and use dbDelta
        Database::activate();

        wp_send_json_success(['message' => 'Database migration completed. Missing tables/columns were added if required.']);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Migration failed: ' . $e->getMessage()]);
    }
}

function test_server_connection_callback() {
    kit_require_admin_maintenance_request('test_server_connection');
    
    try {
        // Include the server connection class
        require_once plugin_dir_path(__FILE__) . 'includes/class-server-connection.php';
        
        // Get server connection instance
        $server_connection = KIT_Server_Connection::get_instance();
        
        // Prepare configuration from form data
        $config = [
            'server_name' => sanitize_text_field($_POST['server_name'] ?? ''),
            'server_type' => sanitize_text_field($_POST['server_type'] ?? ''),
            'server_host' => sanitize_text_field($_POST['server_host'] ?? ''),
            'server_port' => intval($_POST['server_port'] ?? 3306),
            'server_username' => sanitize_text_field($_POST['server_username'] ?? ''),
            'server_password' => sanitize_text_field($_POST['server_password'] ?? ''),
            'server_database' => sanitize_text_field($_POST['server_database'] ?? ''),
            'server_ssl' => intval($_POST['server_ssl'] ?? 0),
            'api_endpoint' => esc_url_raw($_POST['api_endpoint'] ?? ''),
            'api_timeout' => intval($_POST['api_timeout'] ?? 30),
            'api_headers' => sanitize_textarea_field($_POST['api_headers'] ?? ''),
            'api_retry_attempts' => intval($_POST['api_retry_attempts'] ?? 3),
            'webhook_url' => esc_url_raw($_POST['webhook_url'] ?? ''),
            'webhook_secret' => sanitize_text_field($_POST['webhook_secret'] ?? ''),
            'webhook_events' => array_map('sanitize_text_field', $_POST['webhook_events'] ?? [])
        ];
        
        // Test the connection
        $result = $server_connection->test_connection($config);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Connection test failed: ' . $e->getMessage()]);
    }
}

function my_plugin_enqueue_scripts()
{
    // Only load heavy plugin scripts/localized city map on this plugin's admin pages.
    if (php_sapi_name() === 'cli' || !function_exists('get_current_screen')) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    $screen = get_current_screen();
    $is_routes_page = in_array($page, ['route-management', 'route-create'], true);
    $is_customer_page = in_array($page, ['edit-customer', '08600-add-customer'], true);
    // Match customStyling(): any submenu under 08600-dashboard (e.g. manage-drivers) uses screen id "08600-…".
    $is_plugin_page = ($screen && $screen->id && strpos($screen->id, '08600') !== false)
        || strpos($page, '08600') === 0
        || $is_routes_page
        || $is_customer_page
        || in_array($page, ['warehouse-waybills', 'kit-deliveries', 'manage-drivers'], true);

    if (!$is_plugin_page) {
        return;
    }

    wp_enqueue_script('components', plugin_dir_url(__FILE__) . 'js/components.js', ['jquery'], '1.1.2', true);
    wp_enqueue_script('kitscript', plugin_dir_url(__FILE__) . 'js/kitscript.js', ['jquery', 'components'], '1.0.8', true);
    wp_enqueue_script('waybill-pagination', plugin_dir_url(__FILE__) . '/js/waybill-pagination.js', ['jquery', 'components'], null, true);

    // Preload cities map for instant city dropdown updates without AJAX
    if (!class_exists('KIT_Deliveries')) {
        require_once plugin_dir_path(__FILE__) . 'includes/deliveries/deliveries-functions.php';
    }
    $country_cities_map = method_exists('KIT_Deliveries', 'getCountryCitiesMap') ? KIT_Deliveries::getCountryCitiesMap() : [];

    $localize_data = [
        'ajax_url' => admin_url('admin-ajax.php'),
        'admin_url' => admin_url(),
        'countryCities' => $country_cities_map,
        'nonces' => [
            'add'    => wp_create_nonce('add_waybill_nonce'),
            'delete' => wp_create_nonce('delete_waybill_nonce'),
            'update' => wp_create_nonce('update_waybill_nonce'),
            'get_waybills_nonce' => wp_create_nonce('get_waybills_nonce'),
            'get_cities_nonce'   => wp_create_nonce('get_cities_nonce'),
            'kit_waybill_nonce'  => wp_create_nonce('kit_waybill_nonce'),
            'pdf_nonce'          => wp_create_nonce('pdf_nonce'),
            'email_waybill_pdf'  => wp_create_nonce('email_waybill_pdf'),
            'wp_debug'           => defined('WP_DEBUG') && WP_DEBUG,
        ],
    ];

    // Localize both
    wp_localize_script('waybill-pagination', 'myPluginAjax', $localize_data);
    wp_localize_script('kitscript', 'myPluginAjax', $localize_data);
}

// Quotations page function removed
// Services management functions removed - not used
// Quotation functions removed - not used
