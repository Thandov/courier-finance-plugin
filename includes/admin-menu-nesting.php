<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Source of truth for 08600 Solution L2 groups → L3 page slugs.
 * Pages stay registered via add_submenu_page(); JS nests the rendered submenu.
 *
 * @return list<array{id:string,label:string,pages:list<string>}>
 */
function kit_admin_menu_nesting_groups(): array
{
    return [
        [
            'id'    => 'waybills',
            'label' => __('Waybills', '08600-services-quotations'),
            'pages' => ['08600-waybill-create', '08600-waybill-manage', 'warehouse-waybills'],
        ],
        [
            'id'    => 'trips',
            'label' => __('Trips', '08600-services-quotations'),
            'pages' => ['08600-trip-create', 'kit-deliveries'],
        ],
        [
            'id'    => 'customers',
            'label' => __('Customers', '08600-services-quotations'),
            'pages' => ['08600-customers', '08600-customer-portal-accounts', '08600-booking-requests'],
        ],
        [
            'id'    => 'operations',
            'label' => __('Operations', '08600-services-quotations'),
            'pages' => ['route-management', '08600-countries', 'manage-drivers'],
        ],
        [
            'id'    => 'system',
            'label' => __('System', '08600-services-quotations'),
            'pages' => ['08600-settings', '08600-sync-runs', '08600-help'],
        ],
    ];
}

function kit_enqueue_admin_menu_nesting(): void
{
    if (!is_admin() || !current_user_can('kit_view_waybills')) {
        return;
    }

    $url = defined('COURIER_FINANCE_PLUGIN_URL')
        ? COURIER_FINANCE_PLUGIN_URL
        : plugin_dir_url(dirname(__DIR__) . '/08600-services-quotations.php');
    $ver = defined('COURIER_FINANCE_PLUGIN_VERSION') ? COURIER_FINANCE_PLUGIN_VERSION : '1.0';

    $css_path = dirname(__DIR__) . '/assets/css/admin-menu-nesting.css';
    wp_enqueue_style(
        'kit-admin-menu-nesting',
        $url . 'assets/css/admin-menu-nesting.css',
        [],
        is_readable($css_path) ? (string) filemtime($css_path) : $ver
    );
    wp_enqueue_script(
        'kit-admin-menu-nesting',
        $url . 'assets/js/admin-menu-nesting.js',
        [],
        $ver,
        true
    );
    wp_localize_script('kit-admin-menu-nesting', 'kitAdminMenuNesting', [
        'root'          => '#toplevel_page_08600-dashboard',
        'dashboardPage' => '08600-dashboard',
        'groups'        => kit_admin_menu_nesting_groups(),
    ]);
}
add_action('admin_enqueue_scripts', 'kit_enqueue_admin_menu_nesting');
