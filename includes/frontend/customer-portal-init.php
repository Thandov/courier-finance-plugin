<?php
/**
 * Customer portal — frontend pages under /customer/login/ and /customer/dashboard/.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'customer-login.php';
require_once plugin_dir_path(__FILE__) . 'customer-register.php';
require_once plugin_dir_path(__FILE__) . 'customer-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'customer-portal-profile.php';
require_once plugin_dir_path(__FILE__) . 'customer-booking.php';

/**
 * @return bool
 */
function kit_using_customer_portal()
{
    return !is_admin() && function_exists('kit_customer_dashboard_url');
}

/**
 * @return string
 */
function kit_customer_login_url()
{
    return apply_filters('kit_customer_login_url', home_url('/customer/login/'));
}

/**
 * @return string
 */
function kit_customer_dashboard_url()
{
    return apply_filters('kit_customer_dashboard_url', home_url('/customer/dashboard/'));
}

function kit_customer_register_url()
{
    return apply_filters('kit_customer_register_url', home_url('/customer/register/'));
}

/**
 * @return string[]
 */
function kit_customer_portal_hierarchical_paths()
{
    return array('customer/login', 'customer/dashboard', 'customer/register');
}

/**
 * @param array<string,mixed> $query_vars
 * @return array<string,mixed>
 */
function kit_customer_portal_fix_request($query_vars)
{
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    if ($uri === '' || $uri === '/') {
        return $query_vars;
    }
    $path = trim($uri, '/');
    if (!in_array($path, kit_customer_portal_hierarchical_paths(), true)) {
        return $query_vars;
    }
    $page = get_page_by_path($path, OBJECT, 'page');
    if (!$page || $page->post_status !== 'publish') {
        return $query_vars;
    }
    $query_vars['page_id'] = $page->ID;
    $query_vars['pagename'] = '';

    return $query_vars;
}

function kit_customer_portal_fix_404()
{
    if (!is_404()) {
        return;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $parsed = wp_parse_url($uri);
    $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
    if ($path === '') {
        return;
    }
    if (!in_array($path, kit_customer_portal_hierarchical_paths(), true)) {
        return;
    }
    $page = get_page_by_path($path, OBJECT, 'page');
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

function kit_customer_portal_admin_redirect()
{
    if (!is_user_logged_in()) {
        return;
    }
    if (!KIT_User_Roles::is_portal_customer() || current_user_can('manage_options')) {
        return;
    }

    $is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    $req_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if (strpos($req_uri, 'admin-ajax.php') !== false) {
        return;
    }
    if ($is_post && strpos($req_uri, 'admin-post.php') !== false) {
        return;
    }

    wp_safe_redirect(kit_customer_dashboard_url());
    exit;
}

function kit_customer_login_page_redirect()
{
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    if (!is_user_logged_in() || !is_singular()) {
        return;
    }
    global $post;
    if (!$post || !has_shortcode($post->post_content, 'kit_customer_login')) {
        return;
    }
    if (isset($_GET['show_login']) && $_GET['show_login'] === '1') {
        return;
    }
    if (KIT_User_Roles::is_portal_customer() && KIT_User_Roles::get_portal_customer_id() > 0) {
        wp_safe_redirect(kit_customer_dashboard_url());
        exit;
    }
}

function kit_customer_portal_enqueue()
{
    if (!function_exists('is_singular') || !is_singular()) {
        return;
    }
    global $post;
    if (!$post || !is_string($post->post_content)) {
        return;
    }
    $has_login = has_shortcode($post->post_content, 'kit_customer_login');
    $has_register = has_shortcode($post->post_content, 'kit_customer_register');
    $has_dash = has_shortcode($post->post_content, 'kit_customer_portal');
    if (!$has_login && !$has_dash && !$has_register) {
        return;
    }
    if (!defined('COURIER_FINANCE_PLUGIN_URL')) {
        return;
    }
    $base = trailingslashit(COURIER_FINANCE_PLUGIN_URL);
    wp_enqueue_style('kit-customer-portal', $base . 'assets/css/customer-portal.css', array(), '1.0');
    if ($has_dash && apply_filters('kit_customer_portal_enqueue_plugin_styles', true)) {
        wp_enqueue_style('kit-customer-portal-fe', $base . 'assets/css/frontend.css', array('kit-customer-portal'), '1.0');
    }
    if ($has_login || $has_register) {
        wp_enqueue_style('kit-customer-portal-login-fe', $base . 'assets/css/frontend.css', array('kit-customer-portal'), '1.0');
        if (function_exists('kit_employee_login_inline_css')) {
            wp_add_inline_style('kit-customer-portal-login-fe', kit_employee_login_inline_css());
        }
    }
}

function kit_customer_portal_init()
{
    add_shortcode('kit_customer_login', 'kit_customer_login_shortcode');
    add_shortcode('kit_customer_register', 'kit_customer_register_shortcode');
    add_shortcode('kit_customer_portal', 'kit_customer_portal_shortcode');

    add_filter('request', 'kit_customer_portal_fix_request', 2, 1);
    add_action('template_redirect', 'kit_customer_portal_fix_404', 2);
    add_action('admin_init', 'kit_customer_portal_admin_redirect', 1);
    add_action('template_redirect', 'kit_customer_login_page_redirect', 2);
    add_action('wp_enqueue_scripts', 'kit_customer_portal_enqueue', 25);
    add_action('admin_post_kit_portal_update_customer', 'kit_customer_portal_handle_update_profile');
    add_action('admin_post_kit_portal_submit_booking', 'kit_customer_portal_handle_submit_booking');
    add_action('wp', 'kit_customer_portal_enqueue_edit_profile_assets', 5);
    add_action('wp', 'kit_customer_portal_enqueue_booking_assets', 5);
    add_action('init', array('KIT_Booking_Requests', 'ensure_table'), 20);
}

kit_customer_portal_init();
