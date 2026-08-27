<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the unified 08600 Solution menu and submenu.
 * Visible items stay flat in WP (2 levels). Grouping for the 3rd flyout
 * lives in kit_admin_menu_nesting_groups() — add new slugs there too.
 */
function plugin_add_menu()
{
    // Main 08600 Solution Menu (Dashboard is the default landing page)
    add_menu_page(
        '08600 Solution',
        '08600 Solution',
        'kit_view_waybills',
        '08600-dashboard',
        'dashboard_page',
        'dashicons-admin-plugins',
        6
    );

    // Dashboard (first submenu = default when clicking parent)
    add_submenu_page(
        '08600-dashboard',
        'Dashboard',
        'Dashboard',
        'kit_view_waybills',
        '08600-dashboard',
        'dashboard_page'
    );

    // Create Waybill
    add_submenu_page(
        '08600-dashboard',
        'Create Waybill',
        'Create Waybill',
        'kit_view_waybills',
        '08600-waybill-create',
        'waybill_page'
    );

    // Manage Waybills
    add_submenu_page(
        '08600-dashboard',
        'Manage Waybills',
        'Manage Waybills',
        'kit_view_waybills',
        '08600-waybill-manage',
        'plugin_Waybill_list_page'
    );

    // Warehouse
    add_submenu_page(
        '08600-dashboard',
        'Warehouse',
        'Warehouse',
        'kit_view_waybills',
        'warehouse-waybills',
        'warehouse_waybills_page'
    );







    // Customers
    add_submenu_page(
        '08600-dashboard',
        'Customers',
        'Customers',
        'kit_view_waybills',
        '08600-customers',
        ['KIT_Customers', 'customer_dashboard_page']
    );

    add_submenu_page(
        '08600-dashboard',
        'Customer portal accounts',
        'Portal accounts',
        'kit_view_waybills',
        '08600-customer-portal-accounts',
        'kit_customer_portal_accounts_admin_page'
    );

    add_submenu_page(
        '08600-dashboard',
        'Booking requests',
        'Booking requests',
        'kit_view_waybills',
        '08600-booking-requests',
        'kit_booking_requests_admin_page'
    );

    // Add Customer (hidden page)
    add_submenu_page(
        null, // No parent menu
        'Add Customer',
        'Add Customer',
        'kit_view_waybills',
        '08600-add-customer',
        'add_customer_page'
    );

    // Edit Customer (hidden page)
    add_submenu_page(
        null, // No parent menu
        'Edit Customer',
        'Edit Customer',
        'kit_view_waybills',
        'edit-customer',
        'edit_customer_page'
    );



    // Routes & Destinations
    add_submenu_page(
        '08600-dashboard',
        'Routes & Destinations',
        'Routes & Destinations',
        'kit_view_waybills',
        'route-management',
        ['KIT_Routes', 'plugin_route_management_page']
    );

    // Countries
    add_submenu_page(
        '08600-dashboard',
        'Countries',
        'Countries',
        'kit_view_waybills',
        '08600-countries',
        'countries_management_page'
    );

    // Create Trip
    add_submenu_page(
        '08600-dashboard',
        'Create Trip',
        'Create Trip',
        'kit_view_waybills',
        '08600-trip-create',
        ['KIT_Deliveries', 'render_create_trip_page']
    );

    // Trips (existing deliveries list — slug kept so bookmarks and portal URLs stay valid)
    add_submenu_page(
        '08600-dashboard',
        'Trips',
        'Trips',
        'kit_view_waybills',
        'kit-deliveries',
        ['KIT_Deliveries', 'render_admin_page']
    );

    // Drivers
    add_submenu_page(
        '08600-dashboard',
        'Manage Drivers',
        'Drivers',
        'kit_view_waybills',
        'manage-drivers',
        'drivers_management_page'
    );
    
    // Warehouse Tracking - Now integrated into main warehouse page
    // add_submenu_page(
    //     '08600-waybills',
    //     'Warehouse Tracking',
    //     'Warehouse Tracking',
    //     'edit_pages',
    //     'warehouse-tracking',
    //     'warehouse_page'
    // );







    // Hidden pages for direct access
    // Google Sheets test (hidden)
    add_submenu_page(
        null,
        'Google Sheets Test',
        'Google Sheets Test',
        'kit_view_waybills',
        '08600-google-sheets-test',
        'courier_google_sheets_test_page'
    );

    add_submenu_page(
        null, // No parent menu
        'View Waybill',
        'View Waybill',
        'kit_view_waybills',
        '08600-Waybill-view',
        ['KIT_Waybills', 'waybillView']
    );
    
    add_submenu_page(
        null, // No parent menu
        'Create/Edit Route',
        'Create/Edit Route',
        'kit_view_waybills',
        'route-create',
        ['KIT_Routes', 'route_create_page']
    );
    
    add_submenu_page(
        null, // No parent menu
        'View Delivery',
        'View Delivery',
        'kit_view_waybills',
        'view-deliveries',
        ['KIT_Deliveries', 'view_deliveries_page']
    );

    // Note: Other hidden pages (Create Route, Edit Customer, etc.)
    // are accessed via direct URLs and don't need to be registered as submenu items
    // This prevents blank spaces in the menu structure

    // Quotations removed - not used

    // Settings (at bottom) - STRICTLY RESTRICTED ACCESS
    add_submenu_page(
        '08600-dashboard',
        'Settings',
        'Settings',
        'kit_access_settings',
        '08600-settings',
        'waybill_settings_page'
    );

    // Sync Runs (Step 4 audit log for the new KIT_Waybill_Seeder pipeline)
    add_submenu_page(
        '08600-dashboard',
        'Sync Runs',
        'Sync Runs',
        'kit_access_settings',
        '08600-sync-runs',
        'kit_sync_runs_page'
    );

    // Help (at bottom bottom)
    add_submenu_page(
        '08600-dashboard',
        'Help',
        'Help',
        'kit_view_waybills',
        '08600-help',
        'waybill_help_page'
    );

}

/**
 * Admin callback: Customer portal account provisioning.
 */
function kit_customer_portal_accounts_admin_page()
{
    require COURIER_FINANCE_PLUGIN_PATH . 'includes/admin-pages/customer-portal-accounts.php';
}

function kit_booking_requests_admin_page()
{
    require COURIER_FINANCE_PLUGIN_PATH . 'includes/admin-pages/booking-requests.php';
}

/**
 * Dashboard page callback (default landing for 08600 Solution)
 */
function dashboard_page() {
    include plugin_dir_path(__FILE__) . 'admin-pages/dashboard.php';
}

function courier_google_sheets_test_page() {
    require COURIER_FINANCE_PLUGIN_PATH . 'includes/admin-pages/google-sheets-test.php';
}

add_action('admin_menu', 'plugin_add_menu');

require_once __DIR__ . '/admin-menu-nesting.php';

/**
 * Hidden "View Waybill" submenu often yields an empty admin title; set Waybill #N.
 */
add_filter('admin_title', static function ($admin_title, $title) {
    if (!is_admin() || !isset($_GET['page']) || (string) $_GET['page'] !== '08600-Waybill-view') {
        return $admin_title;
    }

    $waybill_id = isset($_GET['waybill_id']) ? (int) $_GET['waybill_id'] : 0;
    $is_edit = isset($_GET['edit']) && (string) $_GET['edit'] === 'true';
    $label = $is_edit ? 'Edit Waybill' : 'View Waybill';
    if ($waybill_id > 0) {
        global $wpdb;
        $waybill_no = $wpdb->get_var($wpdb->prepare(
            "SELECT waybill_no FROM {$wpdb->prefix}kit_waybills WHERE id = %d LIMIT 1",
            $waybill_id
        ));
        if ($waybill_no !== null && $waybill_no !== '') {
            $label = ($is_edit ? 'Editing Waybill #' : 'Waybill #') . $waybill_no;
        }
    }

    $site = wp_strip_all_tags(get_bloginfo('name', 'display'));
    return $label . ' ‹ ' . $site . ' — WordPress';
}, 10, 2);

/**
 * Add "Create New Waybill" to the WordPress admin bar: top-level (visible) and under "New" dropdown.
 */
function plugin_add_admin_bar_waybill($wp_admin_bar) {
    if (!is_admin_bar_showing()) {
        return;
    }
    $can = current_user_can('kit_view_waybills') || current_user_can('manage_options') || current_user_can('edit_pages');
    if (!$can) {
        return;
    }
    $url = admin_url('admin.php?page=08600-waybill-create');
    $title = __('Create New Waybill', '08600-services-quotations');
    // Top-level item so it's visible on the bar without opening a dropdown
    $wp_admin_bar->add_node([
        'id'     => '08600-create-waybill',
        'title'  => $title,
        'href'   => $url,
        'parent' => false,
        'meta'   => ['title' => $title],
    ]);
    // Also under "New" dropdown for consistency with Post, Page, etc.
    $wp_admin_bar->add_node([
        'parent' => 'new-content',
        'id'     => '08600-new-waybill',
        'title'  => $title,
        'href'   => $url,
    ]);
}
add_action('admin_bar_menu', 'plugin_add_admin_bar_waybill', 25);

/**
 * AJAX handler for manual sync (Push to Sheet / Pull from Sheet)
 */
function handle_kit_google_sheet_sync() {
    // Ensure response is only JSON (no stray HTML from other code)
    while (ob_get_level()) {
        ob_end_clean();
    }
    // #region agent log
    $log_path = defined('COURIER_FINANCE_PLUGIN_PATH') ? COURIER_FINANCE_PLUGIN_PATH . '.cursor/debug-a8826f.log' : '';
    if ($log_path) {
        @file_put_contents($log_path, json_encode(['sessionId'=>'a8826f','hypothesisId'=>'B','location'=>'admin-menu.php:handle_kit_google_sheet_sync:entry','message'=>'sync handler entered','data'=>['entity'=>$_POST['entity']??'','direction'=>$_POST['direction']??'','has_nonce'=>isset($_POST['nonce'])],'timestamp'=>round(microtime(true)*1000)])."\n", FILE_APPEND | LOCK_EX);
    }
    // #endregion
    if (!current_user_can('kit_view_waybills')) {
        if ($log_path) { @file_put_contents($log_path, json_encode(['sessionId'=>'a8826f','hypothesisId'=>'B','location'=>'admin-menu.php:handle_kit_google_sheet_sync:auth','message'=>'sync rejected: unauthorized','data'=>[],'timestamp'=>round(microtime(true)*1000)])."\n", FILE_APPEND | LOCK_EX); }
        wp_send_json_error(['message' => 'Unauthorized']);
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kit_google_sheet_sync')) {
        if ($log_path) { @file_put_contents($log_path, json_encode(['sessionId'=>'a8826f','hypothesisId'=>'B','location'=>'admin-menu.php:handle_kit_google_sheet_sync:nonce','message'=>'sync rejected: invalid nonce','data'=>[],'timestamp'=>round(microtime(true)*1000)])."\n", FILE_APPEND | LOCK_EX); }
        wp_send_json_error(['message' => 'Invalid nonce']);
    }
    $entity = sanitize_text_field($_POST['entity'] ?? '');
    $direction = sanitize_text_field($_POST['direction'] ?? '');
    $allowed = ['drivers', 'customers', 'deliveries', 'waybills'];
    if (!in_array($entity, $allowed, true) || !in_array($direction, ['push', 'pull'], true)) {
        if ($log_path) { @file_put_contents($log_path, json_encode(['sessionId'=>'a8826f','hypothesisId'=>'B','location'=>'admin-menu.php:handle_kit_google_sheet_sync:invalid','message'=>'sync rejected: invalid entity or direction','data'=>['entity'=>$entity,'direction'=>$direction],'timestamp'=>round(microtime(true)*1000)])."\n", FILE_APPEND | LOCK_EX); }
        wp_send_json_error(['message' => 'Invalid entity or direction']);
    }
    if (!class_exists('Courier_Google_Sheets_Sync')) {
        if ($log_path) { @file_put_contents($log_path, json_encode(['sessionId'=>'a8826f','hypothesisId'=>'B','location'=>'admin-menu.php:handle_kit_google_sheet_sync:class','message'=>'sync rejected: class not found','data'=>[],'timestamp'=>round(microtime(true)*1000)])."\n", FILE_APPEND | LOCK_EX); }
        wp_send_json_error(['message' => 'Sync not available']);
    }
    // #region agent log
    if ($log_path) { @file_put_contents($log_path, json_encode(['sessionId'=>'a8826f','hypothesisId'=>'C','location'=>'admin-menu.php:handle_kit_google_sheet_sync:before_sync','message'=>'calling push_all/pull_all','data'=>['entity'=>$entity,'direction'=>$direction],'timestamp'=>round(microtime(true)*1000)])."\n", FILE_APPEND | LOCK_EX); }
    // #endregion
    if ($direction === 'push') {
        $result = Courier_Google_Sheets_Sync::push_all($entity);
    } else {
        if ($entity === 'waybills') {
            // Load seed function without running settings-page access check (user already passed kit_view_waybills)
            if (!defined('COURIER_SEED_SIMULATION')) {
                define('COURIER_SEED_SIMULATION', true);
            }
            $settings = COURIER_FINANCE_PLUGIN_PATH . 'includes/admin-pages/settings.php';
            if (file_exists($settings)) {
                ob_start();
                require_once $settings;
                ob_end_clean();
            }
        }
        $result = Courier_Google_Sheets_Sync::pull_all($entity);
    }
    // #region agent log
    if ($log_path) { @file_put_contents($log_path, json_encode(['sessionId'=>'a8826f','hypothesisId'=>'C','location'=>'admin-menu.php:handle_kit_google_sheet_sync:result','message'=>'sync result','data'=>['success'=>!empty($result['success']),'message'=>$result['message']??'','entity'=>$entity,'direction'=>$direction],'timestamp'=>round(microtime(true)*1000)])."\n", FILE_APPEND | LOCK_EX); }
    // #endregion
    if (!empty($result['success'])) {
        delete_option('courier_last_sync_error_bulk');
        while (ob_get_level()) {
            ob_end_clean();
        }
        wp_send_json_success($result);
    }
    $message = $result['message'] ?? 'Sync failed';
    update_option('courier_last_sync_error_bulk', [
        'message'   => $message,
        'entity'    => $entity,
        'direction' => $direction,
        'time'      => time(),
    ], false);
    if ($log_path) { @file_put_contents($log_path, json_encode(['sessionId'=>'a8826f','hypothesisId'=>'D','location'=>'admin-menu.php:handle_kit_google_sheet_sync:send_error','message'=>'sending JSON error response','data'=>['message'=>$message],'timestamp'=>round(microtime(true)*1000)])."\n", FILE_APPEND | LOCK_EX); }
    while (ob_get_level()) {
        ob_end_clean();
    }
    wp_send_json_error(['message' => $message]);
}
add_action('wp_ajax_kit_google_sheet_sync', 'handle_kit_google_sheet_sync');

/**
 * Settings page: push one entity DB → Google Sheet (Sync to Sheet button).
 */
function kit_ajax_sync_all_to_sheet_handler(): void
{
    if (!class_exists('KIT_User_Roles') || !KIT_User_Roles::can_access_settings()) {
        wp_send_json_error(['message' => 'Access denied'], 403);
    }
    check_ajax_referer('kit_sync_all_to_sheet', 'nonce');

    $entity = sanitize_text_field($_POST['entity'] ?? '');
    $allowed = ['drivers', 'customers', 'deliveries', 'waybills'];
    if (!in_array($entity, $allowed, true)) {
        wp_send_json_error(['message' => 'Invalid entity']);
    }
    if (!class_exists('Courier_Google_Sheets_Sync')) {
        wp_send_json_error(['message' => 'Sync not available']);
    }

    if (!defined('KIT_SETTINGS_SKIP_UI')) {
        define('KIT_SETTINGS_SKIP_UI', true);
    }
    require_once plugin_dir_path(__FILE__) . 'admin-pages/settings.php';

    kit_seed_extend_runtime_limits();
    $result = Courier_Google_Sheets_Sync::push_all($entity);

    if (!empty($result['success'])) {
        wp_send_json_success($result);
    }
    wp_send_json_error(['message' => $result['message'] ?? 'Sync failed']);
}
add_action('wp_ajax_kit_sync_all_to_sheet', 'kit_ajax_sync_all_to_sheet_handler');

/**
 * Settings page: copy source spreadsheet tabs into the configured destination sheet.
 */
function kit_ajax_provision_google_sheet_handler(): void
{
    if (!class_exists('KIT_User_Roles') || !KIT_User_Roles::can_access_settings()) {
        wp_send_json_error(['message' => 'Access denied'], 403);
    }
    check_ajax_referer('kit_provision_google_sheet', 'nonce');

    if (!class_exists('Courier_Google_Sheets_Sync')) {
        wp_send_json_error(['message' => 'Sync not available']);
    }

    if (!defined('KIT_SETTINGS_SKIP_UI')) {
        define('KIT_SETTINGS_SKIP_UI', true);
    }
    require_once plugin_dir_path(__FILE__) . 'admin-pages/settings.php';
    kit_seed_extend_runtime_limits();
    $result = Courier_Google_Sheets_Sync::provision_sheet();

    if (!empty($result['success'])) {
        wp_send_json_success($result);
    }
    wp_send_json_error(['message' => $result['message'] ?? 'Provision failed']);
}
add_action('wp_ajax_kit_provision_google_sheet', 'kit_ajax_provision_google_sheet_handler');

/**
 * Google Sheet setup seed — respond immediately with JSON, finish in background, poll for status.
 */
if (!defined('KIT_SETUP_SEED_CRON_HOOK')) {
    define('KIT_SETUP_SEED_CRON_HOOK', 'kit_setup_seed_cron');
}

add_action('wp_ajax_kit_run_google_sheet_seed', 'kit_ajax_run_google_sheet_seed_handler');
add_action('wp_ajax_kit_google_sheet_seed_status', 'kit_ajax_google_sheet_seed_status_handler');
add_action(KIT_SETUP_SEED_CRON_HOOK, 'kit_run_setup_seed_cron', 10, 1);

function kit_ajax_google_sheet_seed_status_handler(): void
{
    if (!class_exists('KIT_User_Roles') || !KIT_User_Roles::can_access_settings()) {
        wp_send_json_error(['message' => 'Access denied'], 403);
    }
    check_ajax_referer('kit_seed_status', 'nonce');

    $uid = get_current_user_id();
    if (!defined('KIT_SETTINGS_SKIP_UI')) {
        define('KIT_SETTINGS_SKIP_UI', true);
    }
    require_once plugin_dir_path(__FILE__) . 'admin-pages/settings.php';
    kit_seed_release_stale_running_transient($uid);
    $running = get_transient('kit_seed_running_' . $uid);
    if ($running) {
        $progress = is_array($running)
            ? $running
            : ['started' => (int) $running, 'phase' => 'running', 'rows_done' => 0, 'rows_total' => 0];
        if (empty($progress['started'])) {
            $progress['started'] = time();
        }
        $progress['elapsed'] = max(0, time() - (int) $progress['started']);
        wp_send_json_success(['done' => false, 'running' => true, 'progress' => $progress]);
    }

    $flash = get_transient('kit_setup_seed_flash_' . $uid);
    if (is_array($flash)) {
        delete_transient('kit_setup_seed_flash_' . $uid);
        wp_send_json_success(['done' => true, 'running' => false, 'result' => $flash]);
    }

    wp_send_json_success(['done' => false, 'running' => false]);
}

/**
 * Run setup seed in background (shared by AJAX inline runner and WP cron fallback).
 */
function kit_execute_setup_seed_background(int $uid): void
{
    if (!defined('KIT_SETTINGS_SKIP_UI')) {
        define('KIT_SETTINGS_SKIP_UI', true);
    }
    require_once plugin_dir_path(__FILE__) . 'admin-pages/settings.php';

    kit_seed_extend_runtime_limits();
    kit_seed_set_progress_user($uid);
    kit_seed_update_progress('starting', 0, 0);

    try {
        $result = handle_setup_seed_from_google_sheet();
        $flash = kit_seed_compact_flash_result($result);
        set_transient('kit_setup_seed_flash_' . $uid, $flash, 600);
    } catch (Throwable $e) {
        set_transient('kit_setup_seed_flash_' . $uid, [
            'success' => false,
            'message' => $e->getMessage(),
        ], 600);
    } finally {
        delete_transient('kit_seed_running_' . $uid);
    }
}

function kit_run_setup_seed_cron(int $uid): void
{
    require_once plugin_dir_path(__FILE__) . 'admin-pages/settings.php';
    kit_execute_setup_seed_background($uid);
}

function kit_ajax_run_google_sheet_seed_handler(): void
{
    if (!class_exists('KIT_User_Roles') || !KIT_User_Roles::can_access_settings()) {
        wp_send_json_error(['message' => 'Access denied'], 403);
    }
    check_ajax_referer('seed_setup', 'setup_seed_nonce');

    $uid = get_current_user_id();
    if (!defined('KIT_SETTINGS_SKIP_UI')) {
        define('KIT_SETTINGS_SKIP_UI', true);
    }
    require_once plugin_dir_path(__FILE__) . 'admin-pages/settings.php';
    kit_seed_release_stale_running_transient($uid);
    if (get_transient('kit_seed_running_' . $uid)) {
        wp_send_json_success(['started' => false, 'poll' => true, 'message' => 'Seed already running']);
    }

    kit_seed_extend_runtime_limits();
    delete_transient('kit_setup_seed_flash_' . $uid);
    kit_seed_set_progress_user($uid);
    kit_seed_update_progress('queued', 0, 0);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $payload = wp_json_encode([
        'success' => true,
        'data' => [
            'started' => true,
            'poll' => true,
            'message' => 'Seed started in background',
        ],
    ]);

    status_header(200);
    header('Content-Type: application/json; charset=utf-8');

    if (function_exists('fastcgi_finish_request')) {
        echo $payload;
        fastcgi_finish_request();
        kit_execute_setup_seed_background($uid);
        exit;
    }

    // MAMP / mod_php: close the client connection, then continue seeding in-process.
    header('Connection: close');
    header('Content-Length: ' . (string) strlen($payload));
    echo $payload;
    if (function_exists('session_write_close')) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();

    kit_execute_setup_seed_background($uid);
    exit;
}

/** Legacy admin-post entry: redirect to Settings and auto-start AJAX seed. */
add_action('admin_post_kit_google_sheet_seed', 'kit_admin_post_google_sheet_seed_handler');

function kit_admin_post_google_sheet_seed_handler(): void
{
    if (!class_exists('KIT_User_Roles') || !KIT_User_Roles::can_access_settings()) {
        wp_die(esc_html__('Access denied.', '08600-services-quotations'), '', ['response' => 403]);
    }
    check_admin_referer('seed_setup', 'setup_seed_nonce');
    wp_safe_redirect(admin_url('admin.php?page=08600-settings&seed_autostart=1'));
    exit;
}

// Callback functions for menu pages





function waybill_settings_page() {
    include plugin_dir_path(__FILE__) . 'admin-pages/settings.php';
}

function waybill_help_page() {
    include plugin_dir_path(__FILE__) . 'admin-pages/help.php';
}

function warehouse_waybills_page() {
    include plugin_dir_path(__FILE__) . 'admin-pages/warehouse.php';
}

function add_customer_page() {
    include plugin_dir_path(__FILE__) . 'admin-pages/add-customer.php';
}

function drivers_management_page() {
    include plugin_dir_path(__FILE__) . 'admin-pages/drivers.php';
}

// Handle add customer form submission
function handle_add_customer_form() {
    if (wp_verify_nonce($_POST['customer_nonce'], 'add_customer_nonce')) {
        // Include customer functions
        require_once plugin_dir_path(__FILE__) . 'customers/customers-functions.php';
        require_once plugin_dir_path(__FILE__) . 'customers/company-customers-functions.php';
        
        $country_raw = $_POST['country_id'] ?? $_POST['origin_country'] ?? '';
        $city_raw = $_POST['city_id'] ?? $_POST['origin_city'] ?? '';
        $client_type = sanitize_key((string) ($_POST['kit_customer_client_type'] ?? 'individual'));
        $company_name = sanitize_text_field($_POST['company_name'] ?? '');

        // Business clients belong in kit_company_customers, not kit_customers.
        if ($client_type === 'business' && class_exists('KIT_Company_Customers')) {
            $company_id = KIT_Company_Customers::save_company([
                'company_name' => $company_name,
                'cell' => sanitize_text_field($_POST['cell'] ?? ''),
                'email_address' => sanitize_text_field($_POST['email_address'] ?? ''),
                'address' => sanitize_textarea_field($_POST['address'] ?? ''),
                'country_id' => ($country_raw !== '' && $country_raw !== null) ? intval($country_raw) : 0,
                'city_id' => ($city_raw !== '' && $city_raw !== null) ? intval($city_raw) : 0,
                'vat_number' => sanitize_text_field($_POST['vat_number'] ?? ''),
            ]);
            if ($company_id) {
                wp_redirect(admin_url('admin.php?page=08600-customers&company_added=1&edit_company=' . (int) $company_id));
                exit;
            }
            $hint = KIT_Company_Customers::get_validation_error() ?: '';
            $qs = 'error=1';
            if ($hint !== '') {
                $qs .= '&dup=company';
            }
            wp_redirect(admin_url('admin.php?page=08600-add-customer&' . $qs));
            exit;
        }

        $customer_data = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'surname' => sanitize_text_field($_POST['surname'] ?? ''),
            'cell' => sanitize_text_field($_POST['cell'] ?? ''),
            'email_address' => sanitize_text_field($_POST['email_address'] ?? ''),
            'address' => sanitize_textarea_field($_POST['address'] ?? ''),
            'country_id' => ($country_raw !== '' && $country_raw !== null) ? intval($country_raw) : 0,
            'city_id' => ($city_raw !== '' && $city_raw !== null) ? intval($city_raw) : 0,
            'vat_number' => sanitize_text_field($_POST['vat_number'] ?? ''),
        ];
        
        $customer_id = KIT_Customers::save_customer($customer_data);

        if ($customer_id) {
            wp_redirect(admin_url('admin.php?page=08600-customers&customer_added=1'));
            exit;
        }

        $hint = KIT_Customers::get_last_customer_validation_error() ?: '';
        $qs = 'error=1';
        if ($hint !== '') {
            $dup = (stripos($hint, 'company') !== false) ? 'company' : 'name';
            $qs .= '&dup=' . rawurlencode($dup);
            $qs .= '&msg=' . rawurlencode($hint);
        }
        wp_redirect(admin_url('admin.php?page=08600-add-customer&' . $qs));
        exit;
    } else {
        wp_redirect(admin_url('admin.php?page=08600-add-customer&error=1'));
        exit;
    }
}

// Register the action hook
add_action('admin_post_add_customer', 'handle_add_customer_form');

/**
 * Countries management page callback
 */
function countries_management_page() {
    include plugin_dir_path(__FILE__) . 'admin-pages/countries.php';
}

/**
 * AJAX handler for quick country status toggle
 */
function handle_toggle_country_status() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'toggle_country_status')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'kit_operating_countries';
    $id = intval($_POST['country_id']);
    $new_status = intval($_POST['new_status']);
    
    $result = $wpdb->update($table, ['is_active' => $new_status], ['id' => $id]);
    
    if ($result !== false) {
        wp_send_json_success(['message' => 'Status updated successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to update status']);
    }
}

// Register AJAX handlers
add_action('wp_ajax_toggle_country_status', 'handle_toggle_country_status');

/**
 * Edit customer page callback
 */
function edit_customer_page() {
    require_once plugin_dir_path(__FILE__) . 'customers/customers-functions.php';
    require_once plugin_dir_path(__FILE__) . 'customers/company-customers-functions.php';
    require_once plugin_dir_path(__FILE__) . 'customers/_customersForm.php';

    $customer_id = isset($_GET['edit_customer']) ? (int) $_GET['edit_customer'] : 0;

    if ($customer_id <= 0) {
        if (!class_exists('KIT_Toast')) {
            require_once plugin_dir_path(__FILE__) . 'components/toast.php';
        }
        KIT_Toast::ensure_toast_loads();
        echo '<div class="wrap"><div class="' . esc_attr(KIT_Commons::containerClasses()) . '">';
        echo KIT_Commons::showingHeader([
            'title'   => __('Edit Individual Customer', '08600'),
            'desc'    => __('Update an individual customer profile.', '08600'),
            'icon'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />',
            'content' => KIT_Commons::renderButton(
                __('Back to list', '08600'),
                'secondary',
                'md',
                ['href' => admin_url('admin.php?page=08600-customers'), 'noLoading' => true]
            ),
        ]);
        echo KIT_Toast::error('Invalid customer ID.', 'Error');
        echo '</div></div>';
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'kit_customers';
    $customer_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE cust_id = %d", $customer_id));

    // If this ID was converted (or only exists as a company), send admin to company edit.
    if (!$customer_row && class_exists('KIT_Company_Customers') && KIT_Company_Customers::find_by_company_id($customer_id)) {
        wp_safe_redirect(admin_url('admin.php?page=08600-customers&edit_company=' . $customer_id));
        exit;
    }

    if (!$customer_row) {
        if (!class_exists('KIT_Toast')) {
            require_once plugin_dir_path(__FILE__) . 'components/toast.php';
        }
        KIT_Toast::ensure_toast_loads();
        $list_url = admin_url('admin.php?page=08600-customers');
        echo '<div class="wrap customers-page kit-edit-customer-page">';
        echo '<div class="' . esc_attr(KIT_Commons::containerClasses()) . '">';
        echo KIT_Commons::showingHeader([
            'title'   => __('Edit Individual Customer', '08600'),
            'desc'    => __('Update an individual customer profile, or convert them into a company when needed.', '08600'),
            'icon'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />',
            'content' => KIT_Commons::renderButton(
                __('Back to list', '08600'),
                'secondary',
                'md',
                [
                    'href' => $list_url,
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />',
                    'iconPosition' => 'left',
                    'noLoading' => true,
                ]
            ),
        ]);
        echo '<hr class="wp-header-end">';
        echo KIT_Toast::error(
            sprintf(
                /* translators: %d: customer id */
                __('No individual customer with ID %d was found. They may have been converted to a company, removed, or the ID changed after a reseed.', '08600'),
                $customer_id
            ),
            __('Customer not found', '08600')
        );
        echo '<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8" style="max-width: 800px;">';
        echo '<p class="text-sm text-gray-600 mb-4">' . esc_html__('Open the Customers page and pick the person from the Individuals list.', '08600') . '</p>';
        echo KIT_Commons::renderButton(__('Go to Customers', '08600'), 'primary', 'md', [
            'href' => $list_url,
            'gradient' => true,
            'noLoading' => true,
        ]);
        echo '</div></div></div>';
        return;
    }

    $back_url = admin_url('admin.php?page=08600-customers');
    $view_url = admin_url('admin.php?page=08600-customers&view_customer=' . $customer_id);
    $header_actions = KIT_Commons::renderButton(
        __('Back to list', '08600'),
        'secondary',
        'md',
        [
            'href' => $back_url,
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />',
            'iconPosition' => 'left',
            'noLoading' => true,
        ]
    ) . KIT_Commons::renderButton(
        __('View profile', '08600'),
        'secondary',
        'md',
        [
            'href' => $view_url,
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />',
            'iconPosition' => 'left',
            'noLoading' => true,
            'classes' => 'ml-2',
        ]
    );

    echo '<div class="wrap customers-page kit-edit-customer-page">';
    echo '<div class="' . esc_attr(KIT_Commons::containerClasses()) . '">';

    echo KIT_Commons::showingHeader([
        'title'   => __('Edit Individual Customer', '08600'),
        'desc'    => __('Update this person’s profile, contact, and location. Check “Convert this client into a company” only if you want to move them into the Companies list.', '08600'),
        'icon'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />',
        'content' => $header_actions,
    ]);
    echo '<hr class="wp-header-end">';

    if (!empty($_GET['update_error']) && isset($_GET['msg'])) {
        $msg = sanitize_text_field(rawurldecode((string) wp_unslash($_GET['msg'])));
        if ($msg !== '') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }

    echo '<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8" style="max-width: 800px;">';
    echo kit_render_customers_form('edit', $customer_row);
    echo '</div></div></div>';
}







