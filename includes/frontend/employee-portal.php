<?php
/**
 * Frontend Employee Portal — Website template (theme) context, NOT WordPress backend.
 *
 * This code runs on the public-facing website (frontend theme/template). It is NOT wp-admin.
 * Employees use the portal at URLs like /employee-dashboard/?section=08600-waybill-create.
 * Functions and assets that were written for the WordPress backend (wp-admin) are bridged here
 * so they work on the frontend: script localization (myPluginAjax, ajaxurl), enqueue order (jQuery
 * in head), and redirect/reload URLs (portal URLs instead of admin.php?page=...).
 *
 * When adding or changing behaviour used by the waybill form, deliveries, etc., ensure it works
 * in BOTH contexts: backend (admin.php?page=...) and frontend (employee-dashboard/?section=...).
 * Use kit_using_employee_portal() to choose portal URLs vs admin URLs for redirects and links.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether the current request is rendering the employee portal on the frontend (website template).
 * Use this when building redirect URLs, reload URLs, or links so they go to the portal instead of wp-admin.
 *
 * @return bool True if we are on the frontend and the portal is available (use portal URLs).
 */
function kit_using_employee_portal() {
    return !is_admin() && function_exists('kit_employee_portal_url');
}

// Load dependencies
require_once plugin_dir_path(__FILE__) . 'employee-login.php';
require_once plugin_dir_path(__FILE__) . 'employee-dashboard.php';

/**
 * Initialize employee portal
 */
function kit_employee_portal_init() {
    add_shortcode('kit_employee_login', 'kit_employee_login_shortcode');
    add_shortcode('kit_employee_dashboard', 'kit_employee_dashboard_shortcode');
    add_shortcode('kit_employee_portal', 'kit_employee_portal_shortcode');

    // Redirect employees after login
    add_filter('login_redirect', 'kit_employee_login_redirect', 10, 3);

    // Redirect employees from wp-admin to frontend dashboard when appropriate
    add_action('admin_init', 'kit_employee_admin_redirect', 1);

    // Force correct page when URL is /employee-login/ or /employee-dashboard/ (fixes wrong content / front page showing)
    add_filter('request', 'kit_employee_portal_fix_request', 1, 1);
    // Fix 404 for employee-login and employee-dashboard when pretty permalinks fail (e.g. empty .htaccess)
    add_action('template_redirect', 'kit_employee_portal_fix_404', 1);
    // Redirect logged-in employees away from login page (before shortcode renders)
    add_action('template_redirect', 'kit_employee_login_page_redirect');

    // Enqueue dashboard assets when page has dashboard or portal shortcode
    add_action('wp', 'kit_employee_maybe_enqueue_dashboard_assets');
    // Portal section assets (Tailwind, etc.) on high priority so they load after theme and apply correctly on /employee-dashboard/?section=...
    add_action('wp_enqueue_scripts', 'kit_employee_maybe_enqueue_portal_section_assets', 999);

    // Enqueue plugin admin styles when portal renders a section (waybills, warehouse, etc.)
    add_action('kit_employee_portal_enqueue_section_assets', 'kit_employee_portal_enqueue_section_assets', 10, 1);

    // Waybill create form in iframe = backend-only, no theme (no fancy selects)
    add_action('wp_ajax_kit_waybill_create_form', 'kit_waybill_create_form_frame');

    // Enqueue login form styles
    add_action('wp_enqueue_scripts', 'kit_employee_enqueue_login_styles', 20);
    // Portal standalone CSS loads after theme (Bootstrap) so selects and layout work when Tailwind is not applied
    add_action('wp_enqueue_scripts', 'kit_employee_enqueue_portal_standalone_css', 999);

    add_filter('body_class', 'kit_courier_portal_maintenance_body_class', 99);
    add_action('wp_footer', 'kit_courier_portal_maintenance_overlay', 999);
}

/**
 * Force the main query to load the employee-login or employee-dashboard page when the URL path matches.
 * Fixes WordPress showing the front page (or wrong content) when rewrites don't resolve the page.
 */
function kit_employee_portal_fix_request($query_vars) {
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    if ($uri === '' || $uri === '/') {
        return $query_vars;
    }
    $path = trim($uri, '/');
    $slug = basename(str_replace('\\', '/', $path));
    // Support new /login slug and legacy /employee-login
    $slugs = array('login', 'employee-login', 'employee-dashboard');
    if (!in_array($slug, $slugs, true)) {
        return $query_vars;
    }
    $page = get_page_by_path($slug, OBJECT, 'page');
    if (!$page || $page->post_status !== 'publish') {
        return $query_vars;
    }
    $query_vars['page_id'] = $page->ID;
    $query_vars['pagename'] = '';
    return $query_vars;
}

/**
 * When a 404 is for /employee-login/ or /employee-dashboard/, redirect to the page by ID so it loads.
 * Fixes broken pretty permalinks (e.g. empty .htaccess rewrite rules).
 * Preserves query string (e.g. ?section=warehouse-waybills) so section links still work.
 */
function kit_employee_portal_fix_404() {
    if (!is_404()) {
        return;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $parsed = wp_parse_url($uri);
    $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
    $path = trim($path, '/');
    if ($path === '') {
        return;
    }
    // Support new /login slug and legacy /employee-login
    $slugs = array('login', 'employee-login', 'employee-dashboard');
    $slug = basename(str_replace('\\', '/', $path));
    if (!in_array($slug, $slugs, true)) {
        return;
    }
    $page = get_page_by_path($slug, OBJECT, 'page');
    if (!$page || $page->post_status !== 'publish') {
        return;
    }
    $redirect = home_url('/?page_id=' . $page->ID);
    if (!empty($parsed['query'])) {
        $redirect = add_query_arg(wp_parse_args($parsed['query']), $redirect);
    }
    wp_safe_redirect($redirect, 302);
    exit;
}

/**
 * Redirect employees to frontend dashboard after login (when using wp-login.php)
 */
function kit_employee_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (is_wp_error($user) || !$user) {
        return $redirect_to;
    }

    $roles = (array) $user->roles;
    $is_employee = in_array('data_capturer', $roles) || in_array('manager', $roles);
    $is_admin = in_array('administrator', $roles) || user_can($user, 'manage_options');

    if ($is_employee && !$is_admin) {
        return apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
    }

    return $redirect_to;
}

/**
 * Redirect logged-in employees from login page to dashboard
 */
function kit_employee_login_page_redirect() {
    // Do not redirect during REST API requests (e.g. block editor save) or the response would not be JSON
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    if (!is_user_logged_in() || !is_singular()) {
        return;
    }
    global $post;
    if (!$post || !has_shortcode($post->post_content, 'kit_employee_login')) {
        return;
    }
    // Allow viewing the login form when already logged in (e.g. for testing) via ?show_login=1
    if (isset($_GET['show_login']) && $_GET['show_login'] === '1') {
        return;
    }
    if (!class_exists('KIT_User_Roles') || !KIT_User_Roles::can_view_waybills()) {
        return;
    }
    // Same login for all: admin → wp-admin, staff → front-end dashboard
    $dashboard_url = KIT_User_Roles::can_see_prices()
        ? admin_url('admin.php?page=08600-dashboard')
        : apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
    wp_safe_redirect($dashboard_url);
    exit;
}

/**
 * Redirect employees from wp-admin: non-admins can only use the frontend portal.
 * Allow POST to admin-post.php and admin-ajax.php so forms and AJAX still work.
 */
function kit_employee_admin_redirect() {
    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    $is_employee = in_array('data_capturer', $roles) || in_array('manager', $roles);
    $is_admin = in_array('administrator', $roles) || current_user_can('manage_options');

    if (!$is_employee || $is_admin) {
        return;
    }

    // Allow ALL requests to admin-ajax.php (our iframe + AJAX) and POSTs to admin-post.php (form submissions)
    $is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if (strpos($uri, 'admin-ajax.php') !== false) {
        return;
    }
    if ($is_post && strpos($uri, 'admin-post.php') !== false) {
        return;
    }
    // Warehouse assign posts to admin.php; handled on admin_init before this redirect runs.
    if ($is_post && strpos($uri, 'admin.php') !== false && isset($_REQUEST['page'], $_POST['assign_warehouse_items'])
        && sanitize_text_field(wp_unslash($_REQUEST['page'])) === 'warehouse-waybills') {
        return;
    }

    $dashboard_url = apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
    wp_safe_redirect($dashboard_url);
    exit;
}

/**
 * Frontend portal URL for a section (employees never use wp-admin).
 *
 * @param string $section Admin page slug (e.g. warehouse-waybills, 08600-waybill-create).
 * @param array  $query   Optional query args (e.g. ['waybill_id' => 123]).
 * @return string
 */
function kit_employee_portal_url($section, $query = array()) {
    $base = apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
    $query = array_merge(array('section' => $section), $query);
    return add_query_arg($query, $base);
}

/**
 * Section => callback map for rendering admin content on the frontend.
 * Only includes pages employees are allowed to access (kit_view_waybills).
 *
 * @return array [ section_slug => callable ]
 */
function kit_employee_portal_section_callbacks() {
    $map = array(
        '08600-waybill-create'     => 'waybill_page',
        '08600-waybill-manage'     => 'plugin_Waybill_list_page',
        'warehouse-waybills'       => 'warehouse_waybills_page',
        '08600-customers'          => array('KIT_Customers', 'customer_dashboard_page'),
        '08600-booking-requests'   => 'kit_booking_requests_admin_page',
        '08600-add-customer'       => 'add_customer_page',
        'edit-customer'            => 'edit_customer_page',
        'route-management'         => array('KIT_Routes', 'plugin_route_management_page'),
        'route-create'             => array('KIT_Routes', 'route_create_page'),
        '08600-countries'          => 'countries_management_page',
        '08600-trip-create'        => array('KIT_Deliveries', 'render_create_trip_page'),
        'kit-deliveries'           => array('KIT_Deliveries', 'render_admin_page'),
        'manage-drivers'           => 'drivers_management_page',
        '08600-Waybill-view'       => array('KIT_Waybills', 'waybillView'),
        'view-deliveries'          => array('KIT_Deliveries', 'view_deliveries_page'),
        '08600-help'               => 'waybill_help_page',
    );
    if (current_user_can('kit_access_settings')) {
        $map['08600-settings'] = 'waybill_settings_page';
    }
    return apply_filters('kit_employee_portal_section_callbacks', $map);
}

/**
 * Output the waybill-create form as a minimal HTML document (no theme).
 * Used as iframe src so only backend code runs — no theme "fancy" selects.
 */
function kit_waybill_create_form_frame() {
    if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'kit_waybill_form_frame')) {
        status_header(403);
        echo '<!DOCTYPE html><html><body><p>Invalid request.</p></body></html>';
        exit;
    }
    if (!is_user_logged_in() || !class_exists('KIT_User_Roles') || !KIT_User_Roles::can_view_waybills()) {
        status_header(403);
        echo '<!DOCTYPE html><html><body><p>Access denied.</p></body></html>';
        exit;
    }

    if (function_exists('kit_maintenance_mode_enabled') && kit_maintenance_mode_enabled()) {
        header('Content-Type: text/html; charset=utf-8');
        status_header(503);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Maintenance mode</title></head>'
            . '<body style="margin:0;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;color:#64748b;">'
            . '<p style="font-size:1.15rem;margin:0;">Maintenance mode</p></body></html>';
        exit;
    }

    $plugin_url = defined('COURIER_FINANCE_PLUGIN_URL') ? COURIER_FINANCE_PLUGIN_URL : plugin_dir_url(dirname(dirname(dirname(__FILE__))));
    $ajax_url = admin_url('admin-ajax.php');

    $_GET['page'] = '08600-waybill-create';
    ob_start();
    if (!function_exists('waybill_page')) {
        require_once plugin_dir_path(__FILE__) . '../admin-pages.php';
    }
    waybill_page();
    $form_html = ob_get_clean();
    unset($_GET['page']);

    // Form submit should redirect the parent page, not the iframe
    $form_html = preg_replace('/<form(\s+[^>]*)?\s+method=/i', '<form$1 target="_parent" method=', $form_html, 1);
    if (strpos($form_html, 'target="_parent"') === false) {
        $form_html = preg_replace('/<form/i', '<form target="_parent"', $form_html, 1);
    }

    if (!class_exists('KIT_Deliveries')) {
        require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
    }
    $country_cities_map = method_exists('KIT_Deliveries', 'getCountryCitiesMap') ? KIT_Deliveries::getCountryCitiesMap() : array();
    // Mirror the nonces structure used on the portal/admin so AJAX paths (including process_waybill_form)
    // have the expected 'add' nonce, etc.
    $localize = array(
        'ajax_url'      => $ajax_url,
        'admin_url'     => admin_url(),
        // Even though this is rendered inside an iframe, it is still
        // being used from the employee portal context, so treat it
        // as a portal environment for JS redirects.
        'portal_base'   => home_url('/employee-dashboard/'),
        'is_portal'     => true,
        'countryCities' => $country_cities_map,
        'nonces'        => array(
            'add'                  => wp_create_nonce('add_waybill_nonce'),
            'delete'               => wp_create_nonce('delete_waybill_nonce'),
            'update'               => wp_create_nonce('update_waybill_nonce'),
            'get_waybills_nonce'   => wp_create_nonce('get_waybills_nonce'),
            'get_cities_nonce'     => wp_create_nonce('get_cities_nonce'),
            'kit_waybill_nonce'    => wp_create_nonce('kit_waybill_nonce'),
            'pdf_nonce'            => wp_create_nonce('pdf_nonce'),
            'deliveries_nonce'     => wp_create_nonce('deliveries_nonce'),
        ),
    );

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    // Load Tailwind-powered frontend styles so all plugin Tailwind classes work in the iframe,
    // completely isolated from the WordPress theme CSS.
    echo '<link rel="stylesheet" href="' . esc_url($plugin_url . 'assets/css/frontend.css') . '?v=' . time() . '">';
    echo '<link rel="stylesheet" href="' . esc_url($plugin_url . 'assets/css/dashboard.css') . '?v=' . time() . '">';
    echo '<link rel="stylesheet" href="' . esc_url($plugin_url . 'assets/css/portal.css') . '?v=' . time() . '">';
    echo '<script src="' . esc_url(includes_url('js/jquery/jquery.min.js')) . '"></script>';
    echo '<script>window.ajaxurl = "' . esc_js($ajax_url) . '"; window.myPluginAjax = ' . wp_json_encode($localize) . ';</script>';
    echo '<script src="' . esc_url($plugin_url . 'js/kitscript.js') . '?v=' . time() . '"></script>';
    echo '<script src="' . esc_url($plugin_url . 'js/waybill-pagination.js') . '?v=' . time() . '"></script>';
    echo '<script src="' . esc_url($plugin_url . 'js/components.js') . '?v=' . time() . '"></script>';
    echo '</head><body class="kit-waybill-form-frame"><div class="wrap" style="max-width:1400px;margin:0 auto;padding:1rem;">';
    echo $form_html;
    echo '</div></body></html>';
    exit;
}

/**
 * [kit_employee_portal] – Renders dashboard or any section on the frontend.
 * No section or section=08600-dashboard → dashboard; else runs the admin callback and rewrites admin URLs to portal URLs.
 */
function kit_employee_portal_shortcode() {
    if (!is_user_logged_in() || !KIT_User_Roles::can_view_waybills()) {
        // New login URL is /login/, keep filter so themes can override
        $login_url = apply_filters('kit_employee_login_url', home_url('/login/'));
        ob_start();
        ?>
        <div class="kit-employee-portal-section min-h-[60vh] flex items-center justify-center bg-gray-50 py-10 px-4">
            <div class="max-w-lg w-full bg-white rounded-xl shadow-md border border-gray-200 p-6 sm:p-8 space-y-6">
                <div class="space-y-2 text-center">
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">
                        <?php esc_html_e('Employee Portal', '08600-services-quotations'); ?>
                    </h1>
                    <p class="text-sm sm:text-base text-gray-600">
                        <?php esc_html_e('Sign in to view waybills, deliveries, warehouse items and customer information.', '08600-services-quotations'); ?>
                    </p>
                </div>

                <div class="bg-blue-50 border border-blue-100 rounded-lg p-4 sm:p-5 flex items-start gap-3">
                    <div class="flex-shrink-0">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-white">
                            <!-- lock icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </span>
                    </div>
                    <div class="space-y-1 text-left">
                        <p class="text-sm font-semibold text-blue-900">
                            <?php esc_html_e('Please log in to access the portal.', '08600-services-quotations'); ?>
                        </p>
                        <p class="text-xs sm:text-sm text-blue-800">
                            <?php esc_html_e('Use your employee credentials to continue. If you do not have access, contact your manager or system administrator.', '08600-services-quotations'); ?>
                        </p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <a href="<?php echo esc_url($login_url); ?>"
                       class="inline-flex items-center justify-center px-5 py-2.5 rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 active:bg-blue-800 transition-colors">
                        <?php esc_html_e('Go to employee login', '08600-services-quotations'); ?>
                    </a>
                    <p class="text-xs text-gray-500 text-center sm:text-right">
                        <?php esc_html_e('You will be redirected back to the Employee Dashboard after logging in.', '08600-services-quotations'); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
    if ($section === '' || $section === '08600-dashboard') {
        return kit_employee_dashboard_shortcode();
    }

    $callbacks = kit_employee_portal_section_callbacks();
    if (!isset($callbacks[$section]) || !is_callable($callbacks[$section])) {
        return '<p>' . esc_html__('Section not found.', '08600-services-quotations') . ' <a href="' . esc_url(apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'))) . '">' . esc_html__('Back to dashboard', '08600-services-quotations') . '</a></p>';
    }

    // Only admin and manager (ADMAN) can access Countries, Drivers, Routes & Destinations
    $adman_only_sections = array('08600-countries', 'manage-drivers', 'route-management', 'route-create');
    if (in_array($section, $adman_only_sections, true) && !KIT_User_Roles::is_adman()) {
        $dashboard_url = apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
        $msg = __('You don\'t have access to this page. Only administrators and managers can use the Countries, Drivers, and Routes & Destinations pages.', '08600-services-quotations');
        $main_content = '<div class="kit-employee-portal-section-wrap"><div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg">' . esc_html($msg) . ' <a href="' . esc_url($dashboard_url) . '" class="underline font-medium">' . esc_html__('Back to dashboard', '08600-services-quotations') . '</a></div></div>';
        if (function_exists('kit_employee_portal_layout_with_sidebar')) {
            return kit_employee_portal_layout_with_sidebar($main_content, $section);
        }
        return '<div class="kit-employee-portal-section">' . $main_content . '</div>';
    }

    // Waybill create: show form in an iframe so only backend code runs (no theme = no fancy selects)
    if ($section === '08600-waybill-create') {
        $frame_nonce = wp_create_nonce('kit_waybill_form_frame');
        $frame_url = admin_url('admin-ajax.php?action=kit_waybill_create_form&nonce=' . $frame_nonce);
        $main_content = '<div class="kit-employee-portal-section" style="max-width:1400px;margin:0 auto;padding:1rem;">';
        $main_content .= '<iframe id="kit-waybill-create-frame" src="' . esc_url($frame_url) . '" style="width:100%;min-height:85vh;border:0;display:block;" title="Create waybill"></iframe>';
        $main_content .= '</div>';
        if (function_exists('kit_employee_portal_layout_with_sidebar')) {
            return kit_employee_portal_layout_with_sidebar($main_content, $section);
        }
        return '<div class="kit-employee-portal-section">' . $main_content . '</div>';
    }

    // Enqueue plugin styles for this section (same as admin)
    do_action('kit_employee_portal_enqueue_section_assets', $section);

    // Run admin callback with page set so code that checks $_GET['page'] works
    $_GET['page'] = $section;
    ob_start();
    call_user_func($callbacks[$section]);
    $output = ob_get_clean();
    unset($_GET['page']);

    $portal_base = apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
    $admin_base = admin_url('admin.php');
    $output = str_replace($admin_base, $portal_base, $output);
    $output = str_replace('?page=', '?section=', $output);

    // Simple layout wrapper for portal content; actual form/select styling comes from portal.css
    $styles = '<style>.kit-employee-portal-section-wrap{max-width:1400px;margin:0 auto;padding:1rem}</style>';
    $main_content = $styles . '<div class="kit-employee-portal-section">' . $output . '</div>';

    if (function_exists('kit_employee_portal_layout_with_sidebar')) {
        return kit_employee_portal_layout_with_sidebar($main_content, $section);
    }
    return '<div class="kit-employee-portal-section">' . $main_content . '</div>';
}

/**
 * Enqueue dashboard assets when page has dashboard or portal shortcode
 */
function kit_employee_maybe_enqueue_dashboard_assets() {
    if (!is_singular()) {
        return;
    }
    global $post;
    if (!$post || (!has_shortcode($post->post_content, 'kit_employee_dashboard') && !has_shortcode($post->post_content, 'kit_employee_portal'))) {
        return;
    }
    kit_employee_enqueue_dashboard_assets();
}

/**
 * Enqueue plugin styles for portal sections (waybills, warehouse, etc.) before wp_head.
 */
function kit_employee_maybe_enqueue_portal_section_assets() {
    if (!is_singular()) {
        return;
    }
    global $post;
    if (!$post || !has_shortcode($post->post_content, 'kit_employee_portal')) {
        return;
    }
    $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
    if ($section === '' || $section === '08600-dashboard') {
        return;
    }
    kit_employee_portal_enqueue_section_assets($section);
}

/**
 * Enqueue portal standalone CSS late (priority 999) so it loads after Bootstrap and overrides theme form/layout.
 * Works when theme uses Bootstrap and Tailwind is not applied on the frontend.
 */
function kit_employee_enqueue_portal_standalone_css() {
    if (function_exists('is_admin') && is_admin()) {
        return;
    }
    if (!function_exists('is_singular') || !is_singular()) {
        return;
    }
    global $post;
    if (!isset($post) || !is_object($post) || !isset($post->post_content) || !is_string($post->post_content)) {
        return;
    }
    if (!function_exists('has_shortcode') || !has_shortcode($post->post_content, 'kit_employee_portal')) {
        return;
    }
    $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
    if ($section === '' || $section === '08600-dashboard') {
        return;
    }
    if (!defined('COURIER_FINANCE_PLUGIN_URL')) {
        return;
    }
    $plugin_url = trailingslashit(COURIER_FINANCE_PLUGIN_URL);
    wp_enqueue_style('kit-portal-standalone', $plugin_url . 'assets/css/portal.css', array(), '1.0');
}

/**
 * Enqueue plugin styles when portal shows a non-dashboard section (waybills, warehouse, etc.)
 * Portal works with Bootstrap themes: portal.css is framework-agnostic and loads after theme so selects/layout work.
 */
function kit_employee_portal_enqueue_section_assets($section) {
    static $enqueued = false;
    if ($enqueued) {
        return;
    }
    $enqueued = true;
    $plugin_url = defined('COURIER_FINANCE_PLUGIN_URL') ? COURIER_FINANCE_PLUGIN_URL : plugin_dir_url(dirname(dirname(dirname(__FILE__))));
    $plugin_path = plugin_dir_path(dirname(dirname(dirname(__FILE__))));
    wp_enqueue_style('autsincss-portal', $plugin_url . 'assets/css/austin.css', array(), '1.0');
    wp_enqueue_style('kit-tailwindcss-portal', $plugin_url . 'assets/css/frontend.css', array(), '1.0');
    /* portal.css is enqueued late (wp_enqueue_scripts 999) so it loads after Bootstrap and overrides theme form styles */
    // jQuery and waybill scripts must load in header (in_footer false) so inline scripts in page body can use jQuery
    wp_enqueue_script('jquery');

    // Sections that need waybill scripts (kitscript, waybill-pagination, components) and myPluginAjax
    $waybill_sections = array('08600-waybill-create', '08600-waybill-manage', '08600-Waybill-view', 'warehouse-waybills', '08600-trip-create', 'kit-deliveries');
    if (in_array($section, $waybill_sections, true)) {
        wp_enqueue_script('kitscript', $plugin_url . 'js/kitscript.js', array('jquery'), '1.0.0', false);
        wp_enqueue_script('waybill-pagination', $plugin_url . 'js/waybill-pagination.js', array('jquery'), '1.0.0', false);
        wp_enqueue_script('components', $plugin_url . 'js/components.js', array('jquery'), '1.0.0', false);

        if (!class_exists('KIT_Deliveries')) {
            require_once $plugin_path . 'includes/deliveries/deliveries-functions.php';
        }
        $country_cities_map = method_exists('KIT_Deliveries', 'getCountryCitiesMap') ? KIT_Deliveries::getCountryCitiesMap() : array();
        $ajax_url = admin_url('admin-ajax.php');
        $localize_data = array(
            'ajax_url'   => $ajax_url,
            'admin_url'  => admin_url(),
            'portal_base' => home_url('/employee-dashboard/'),
            'is_portal'  => true,
            'countryCities' => $country_cities_map,
            'nonces'     => array(
                'add'                  => wp_create_nonce('add_waybill_nonce'),
                'delete'               => wp_create_nonce('delete_waybill_nonce'),
                'update'               => wp_create_nonce('update_waybill_nonce'),
                'get_waybills_nonce'   => wp_create_nonce('get_waybills_nonce'),
                'get_cities_nonce'     => wp_create_nonce('get_cities_nonce'),
                'kit_waybill_nonce'    => wp_create_nonce('kit_waybill_nonce'),
                'pdf_nonce'            => wp_create_nonce('pdf_nonce'),
                'deliveries_nonce'     => wp_create_nonce('deliveries_nonce'),
            ),
        );
        wp_localize_script('kitscript', 'myPluginAjax', $localize_data);
        wp_localize_script('waybill-pagination', 'myPluginAjax', $localize_data);
        // Frontend has no window.ajaxurl (wp-admin only); set it so inline scripts (e.g. scheduledDeliveries filter) work
        wp_add_inline_script('jquery', 'window.ajaxurl = "' . esc_url($ajax_url) . '";', 'before');
    }
    // Deliveries and other sections need ajaxurl for Edit Delivery modal and other AJAX
    $ajax_sections = array('kit-deliveries', '08600-trip-create', '08600-waybill-create', '08600-waybill-manage', '08600-Waybill-view', 'warehouse-waybills');
    if (in_array($section, $ajax_sections, true)) {
        $ajax_url = admin_url('admin-ajax.php');
        wp_add_inline_script('jquery', 'window.ajaxurl = "' . esc_url($ajax_url) . '";', 'before');
    }
}

/**
 * Enqueue dashboard CSS/JS for frontend dashboard
 */
function kit_employee_enqueue_dashboard_assets() {
    static $enqueued = false;
    if ($enqueued) {
        return;
    }
    $enqueued = true;

    /* Plugin root URL: __FILE__ is includes/frontend/employee-portal.php → go up 3 to plugin root */
    $plugin_url = defined('COURIER_FINANCE_PLUGIN_URL') ? COURIER_FINANCE_PLUGIN_URL : plugin_dir_url(dirname(dirname(dirname(__FILE__))));
    wp_enqueue_style('kit-tailwindcss', $plugin_url . 'assets/css/frontend.css', array(), '1.0');
    wp_enqueue_style('kit-dashboard-css', $plugin_url . 'assets/css/dashboard.css', array('kit-tailwindcss'), '1.0');
    wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
    wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
    wp_enqueue_script('kit-dashboard-map', $plugin_url . 'assets/js/dashboard-map.js', array('jquery', 'leaflet-js'), '1.0', true);

    $map_center_address = class_exists('KIT_Company')
        ? (string) (KIT_Company::get_details_array()['company_address'] ?? KIT_Company::COMPANY_ADDRESS)
        : 'Unit 1, Kya North Park, 28 Bernie St, Kya Sands, Randburg, 2188';

    wp_localize_script('kit-dashboard-map', 'kitDashboardMap', array(
        'ajaxurl'              => admin_url('admin-ajax.php'),
        'nonce'                => wp_create_nonce('kit_dashboard_map'),
        'map_center_address'   => $map_center_address,
        'map_geocode_query'    => 'Kya Sands, Randburg, South Africa',
        'map_fallback_lat'     => -26.0789,
        'map_fallback_lng'     => 28.0123,
        'map_logo_url'         => $plugin_url . 'img/logo.png',
    ));
}

/**
 * Enqueue login form styles
 */
function kit_employee_enqueue_login_styles() {
    global $post;
    if (!$post || !has_shortcode($post->post_content, 'kit_employee_login')) {
        return;
    }

    $plugin_url = defined('COURIER_FINANCE_PLUGIN_URL') ? COURIER_FINANCE_PLUGIN_URL : plugin_dir_url(dirname(dirname(dirname(__FILE__))));
    wp_enqueue_style('kit-employee-login', $plugin_url . 'assets/css/frontend.css', array(), '1.0');
    wp_add_inline_style('kit-employee-login', kit_employee_login_inline_css());
}

/**
 * Inline CSS for employee login form
 */
/**
 * Employee portal: maintenance mode body class for blur + overlay (Settings section exempt for authorized admins).
 */
function kit_courier_portal_maintenance_body_class($classes) {
    if (!function_exists('kit_maintenance_mode_enabled') || !kit_maintenance_mode_enabled()) {
        return $classes;
    }
    if (!is_user_logged_in() || !class_exists('KIT_User_Roles') || !KIT_User_Roles::can_view_waybills()) {
        return $classes;
    }
    if (!kit_courier_portal_is_dashboard_app_request()) {
        return $classes;
    }
    $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
    if ($section === '08600-settings' && KIT_User_Roles::can_access_settings()) {
        return $classes;
    }
    $classes[] = 'kit-courier-portal-maintenance';
    return $classes;
}

/**
 * True on employee dashboard URL or any singular page that renders the portal/dashboard shortcode.
 */
function kit_courier_portal_is_dashboard_app_request() {
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $path = trim((string) $uri, '/');
    $slug = $path === '' ? '' : basename(str_replace('\\', '/', $path));
    if ($slug === 'employee-dashboard') {
        return true;
    }
    if (!function_exists('is_singular') || !is_singular()) {
        return false;
    }
    global $post;
    if (!$post || !isset($post->post_content)) {
        return false;
    }
    return has_shortcode($post->post_content, 'kit_employee_portal')
        || has_shortcode($post->post_content, 'kit_employee_dashboard');
}

/**
 * Full-screen maintenance layer on employee dashboard (frontend).
 */
function kit_courier_portal_maintenance_overlay() {
    if (!function_exists('kit_maintenance_mode_enabled') || !kit_maintenance_mode_enabled()) {
        return;
    }
    if (!is_user_logged_in() || !class_exists('KIT_User_Roles') || !KIT_User_Roles::can_view_waybills()) {
        return;
    }
    if (!kit_courier_portal_is_dashboard_app_request()) {
        return;
    }
    $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
    if ($section === '08600-settings' && KIT_User_Roles::can_access_settings()) {
        return;
    }
    echo '<style id="kit-courier-maint-portal-css">'
        . '.kit-courier-portal-maintenance #kit-employee-dashboard-wrap{filter:blur(9px);pointer-events:none;user-select:none;}'
        . '</style>';
    echo '<div id="kit-courier-maintenance-overlay-portal" style="position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(248,250,252,0.72);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);" role="alertdialog" aria-modal="true" aria-labelledby="kit-courier-maintenance-portal-title">'
        . '<div style="text-align:center;padding:2rem 2.5rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 40px rgba(15,23,42,0.12);max-width:420px;">'
        . '<p id="kit-courier-maintenance-portal-title" style="margin:0;font-size:1.35rem;font-weight:600;color:#0f172a;">Maintenance mode</p>'
        . '<p style="margin:0.75rem 0 0 0;font-size:0.9rem;color:#64748b;line-height:1.5;">This plugin is temporarily unavailable. An authorized administrator can turn this off in Settings.</p>'
        . '</div></div>';
}

function kit_employee_login_inline_css() {
    return '
        .kit-employee-login-wrap { max-width: 400px; margin: 2rem auto; padding: 1rem; }
        .kit-employee-login-box { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .kit-employee-login-title { margin: 0 0 0.5rem; font-size: 1.5rem; font-weight: 600; }
        .kit-employee-login-desc { color: #6b7280; margin-bottom: 1.5rem; }
        .kit-employee-login-error { background: #fef2f2; color: #dc2626; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; }
        .kit-employee-login-form label { display: block; margin-bottom: 0.25rem; font-weight: 500; }
        .kit-employee-login-form input[type="text"],
        .kit-employee-login-form input[type="password"] { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; margin-bottom: 1rem; }
        .kit-employee-remember { margin: 1rem 0; }
        .kit-employee-submit { margin-top: 1.5rem; }
        .kit-employee-submit .button { padding: 0.5rem 1.5rem; cursor: pointer; }
        .kit-employee-dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding: 0.5rem 0; }
        .kit-employee-greeting { font-weight: 500; }
        .kit-employee-logout { color: #6b7280; text-decoration: none; }
        .kit-employee-logout:hover { color: #111; }
        .kit-employee-dashboard-wrap .kit-dashboard-wrap { max-width: 1400px; margin: 0 auto; padding: 1rem; }
    ';
}

add_action('init', 'kit_employee_portal_init', 20);
