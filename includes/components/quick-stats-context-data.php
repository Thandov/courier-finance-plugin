<?php
/**
 * Central stat payloads for KIT_QuickStats::render_for_context().
 *
 * All Quick Stat cards (see kit_quick_stats_card_ids()) are defined here; each screen passes a
 * context string to show the relevant subset.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical stat card IDs — titles/roles map to these keys in docs/tests.
 */
function kit_quick_stats_card_ids(): array
{
    return [
        'kpi_waybills_today',
        'kpi_in_warehouse',
        'kpi_todays_deliveries',
        'kpi_revenue_today',
        'kpi_revenue_month',
        'kpi_current_month',
        'kpi_top_customer_month',
        'delivery_scheduled',
        'delivery_in_transit',
        'delivery_delivered',
        'waybill_total',
        'waybill_recent',
        'waybill_pending',
        'waybill_warehouse',
        'warehouse_status_in',
        'warehouse_status_assigned',
        'warehouse_status_shipped',
        'warehouse_status_delivered',
    ];
}

/**
 * @param array $options See KIT_QuickStats::get_stats_for_context()
 * @return array<int, array<string, mixed>>
 */
function kit_quick_stats_get_stats_for_context(string $context, array $options = []): array
{
    switch ($context) {
        case KIT_QuickStats::CONTEXT_ADMIN_DASHBOARD:
            return kit_quick_stats_build_admin_dashboard($options);
        case KIT_QuickStats::CONTEXT_ADMIN_DASHBOARD_DELIVERY_STATUS:
            return kit_quick_stats_build_admin_dashboard_delivery_status($options);
        case KIT_QuickStats::CONTEXT_EMPLOYEE_DASHBOARD:
            return kit_quick_stats_build_employee_dashboard($options);
        case KIT_QuickStats::CONTEXT_EMPLOYEE_DASHBOARD_DELIVERY_STATUS:
            return kit_quick_stats_build_employee_dashboard_delivery_status($options);
        case KIT_QuickStats::CONTEXT_WAYBILL_MANAGE:
            return kit_quick_stats_build_waybill_manage($options);
        case KIT_QuickStats::CONTEXT_WAREHOUSE:
            return kit_quick_stats_build_warehouse($options);
        case KIT_QuickStats::CONTEXT_ASSIGN_WAYBILLS:
            return kit_quick_stats_build_assign_waybills($options);
        case KIT_QuickStats::CONTEXT_DRIVERS:
            return kit_quick_stats_build_drivers($options);
        case KIT_QuickStats::CONTEXT_COUNTRIES:
            return kit_quick_stats_build_countries($options);
        case KIT_QuickStats::CONTEXT_DELIVERIES:
            return kit_quick_stats_build_deliveries($options);
        case KIT_QuickStats::CONTEXT_CUSTOMERS:
            return kit_quick_stats_build_customers($options);
        default:
            return [];
    }
}

function kit_quick_stats_require_dashboard(): void
{
    $path = dirname(__DIR__) . '/dashboard/dashboard-functions.php';
    if (file_exists($path)) {
        require_once $path;
    }
}

function kit_quick_stats_require_warehouse(): void
{
    $path = dirname(__DIR__) . '/warehouse/warehouse-functions.php';
    if (file_exists($path)) {
        require_once $path;
    }
}

function kit_quick_stats_require_waybills(): void
{
    $path = dirname(__DIR__) . '/waybill/waybill-functions.php';
    if (file_exists($path)) {
        require_once $path;
    }
}

function kit_quick_stats_require_deliveries(): void
{
    $path = dirname(__DIR__) . '/deliveries/deliveries-functions.php';
    if (file_exists($path)) {
        require_once $path;
    }
}

function kit_quick_stats_admin_deliveries_url(): string
{
    return admin_url('admin.php?page=kit-deliveries');
}

function kit_quick_stats_admin_waybills_url(): string
{
    return admin_url('admin.php?page=08600-waybill-manage');
}

function kit_quick_stats_admin_warehouse_url(): string
{
    return admin_url('admin.php?page=warehouse-waybills');
}

/**
 * @param array $options { use_portal_urls?: bool }
 */
function kit_quick_stats_resolve_deliveries_url(array $options): string
{
    if (!empty($options['use_portal_urls']) && function_exists('kit_employee_portal_url')) {
        return kit_employee_portal_url('kit-deliveries');
    }
    return kit_quick_stats_admin_deliveries_url();
}

function kit_quick_stats_resolve_waybills_url(array $options): string
{
    if (!empty($options['use_portal_urls']) && function_exists('kit_employee_portal_url')) {
        return kit_employee_portal_url('08600-waybill-manage');
    }
    return kit_quick_stats_admin_waybills_url();
}

function kit_quick_stats_resolve_warehouse_url(array $options): string
{
    if (!empty($options['use_portal_urls']) && function_exists('kit_employee_portal_url')) {
        return kit_employee_portal_url('warehouse-waybills');
    }
    return kit_quick_stats_admin_warehouse_url();
}

function kit_quick_stats_build_admin_dashboard(array $options): array
{
    kit_quick_stats_require_dashboard();
    kit_quick_stats_require_warehouse();

    $today_waybills = class_exists('KIT_Dashboard') ? KIT_Dashboard::get_today_waybill_count() : 0;
    $warehouse_stats = class_exists('KIT_Warehouse') ? KIT_Warehouse::getWarehouseStats() : null;
    $in_warehouse = (int) ($warehouse_stats->in_warehouse ?? 0);
    $today_deliveries = class_exists('KIT_Dashboard') ? KIT_Dashboard::get_today_deliveries_count() : 0;

    $du = kit_quick_stats_admin_deliveries_url();
    $wu = kit_quick_stats_admin_waybills_url();
    $whu = kit_quick_stats_admin_warehouse_url();

    return [
        [
            'title' => 'Waybills today',
            'value' => number_format($today_waybills),
            'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
            'color' => 'blue',
            'href' => $wu,
            'link_label' => 'View',
        ],
        [
            'title' => 'In warehouse',
            'value' => number_format($in_warehouse),
            'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
            'color' => 'orange',
            'href' => $whu,
            'link_label' => 'View',
        ],
        [
            'title' => 'Today\'s deliveries',
            'value' => number_format($today_deliveries),
            'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
            'color' => 'yellow',
            'href' => $du,
            'link_label' => 'View',
        ],
    ];
}

function kit_quick_stats_build_admin_dashboard_delivery_status(array $options): array
{
    kit_quick_stats_require_dashboard();
    $delivery_counts = class_exists('KIT_Dashboard') ? KIT_Dashboard::get_delivery_status_counts() : null;
    if (!$delivery_counts) {
        return [];
    }
    $du = kit_quick_stats_admin_deliveries_url();
    return [
        [
            'title' => 'Scheduled',
            'value' => number_format($delivery_counts->scheduled),
            'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'green',
            'clickable' => true,
            'onclick' => "window.location.href='" . esc_js($du) . "'",
        ],
        [
            'title' => 'In transit',
            'value' => number_format($delivery_counts->in_transit),
            'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
            'color' => 'yellow',
            'clickable' => true,
            'onclick' => "window.location.href='" . esc_js($du) . "'",
        ],
        [
            'title' => 'Delivered',
            'value' => number_format($delivery_counts->delivered),
            'icon' => 'M5 13l4 4L19 7',
            'color' => 'blue',
            'clickable' => true,
            'onclick' => "window.location.href='" . esc_js($du) . "'",
        ],
    ];
}

function kit_quick_stats_build_employee_dashboard(array $options): array
{
    kit_quick_stats_require_dashboard();
    kit_quick_stats_require_warehouse();

    $today_waybills = class_exists('KIT_Dashboard') ? KIT_Dashboard::get_today_waybill_count() : 0;
    $warehouse_stats = class_exists('KIT_Warehouse') ? KIT_Warehouse::getWarehouseStats() : null;
    $in_warehouse = (int) ($warehouse_stats->in_warehouse ?? 0);
    $today_deliveries = class_exists('KIT_Dashboard') ? KIT_Dashboard::get_today_deliveries_count() : 0;

    $du = kit_quick_stats_resolve_deliveries_url($options);
    $wu = kit_quick_stats_resolve_waybills_url($options);
    $whu = kit_quick_stats_resolve_warehouse_url($options);

    return [
        [
            'title' => 'Waybills today',
            'value' => number_format($today_waybills),
            'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
            'color' => 'blue',
            'href' => $wu,
            'link_label' => 'View',
        ],
        [
            'title' => 'In warehouse',
            'value' => number_format($in_warehouse),
            'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
            'color' => 'orange',
            'href' => $whu,
            'link_label' => 'View',
        ],
        [
            'title' => 'Today\'s deliveries',
            'value' => number_format($today_deliveries),
            'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
            'color' => 'yellow',
            'href' => $du,
            'link_label' => 'View',
        ],
    ];
}

function kit_quick_stats_build_employee_dashboard_delivery_status(array $options): array
{
    kit_quick_stats_require_dashboard();
    $delivery_counts = class_exists('KIT_Dashboard') ? KIT_Dashboard::get_delivery_status_counts() : null;
    if (!$delivery_counts) {
        return [];
    }
    $du = kit_quick_stats_resolve_deliveries_url($options);
    return [
        [
            'title' => 'Scheduled',
            'value' => number_format($delivery_counts->scheduled),
            'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'green',
            'clickable' => true,
            'onclick' => "window.location.href='" . esc_js($du) . "'",
        ],
        [
            'title' => 'In transit',
            'value' => number_format($delivery_counts->in_transit),
            'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
            'color' => 'yellow',
            'clickable' => true,
            'onclick' => "window.location.href='" . esc_js($du) . "'",
        ],
        [
            'title' => 'Delivered',
            'value' => number_format($delivery_counts->delivered),
            'icon' => 'M5 13l4 4L19 7',
            'color' => 'blue',
            'clickable' => true,
            'onclick' => "window.location.href='" . esc_js($du) . "'",
        ],
    ];
}

function kit_quick_stats_build_waybill_manage(array $options): array
{
    kit_quick_stats_require_waybills();
    if (!class_exists('KIT_Waybills')) {
        return [];
    }

    $warehouse_total = KIT_Waybills::get_warehouse_waybill_count();
    $warehouse_recent = KIT_Waybills::get_recent_warehouse_waybill_count();

    return [
        [
            'title' => 'Total Waybills',
            'value' => number_format(KIT_Waybills::get_waybill_count()),
            'subtitle' => 'All records',
            'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
            'color' => 'blue',
            'card_filter' => 'all',
        ],
        [
            'title' => 'Recent Waybills',
            'value' => number_format(KIT_Waybills::get_recent_waybill_count()),
            'subtitle' => 'Created in last 7 days',
            'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'green',
            'card_filter' => 'recent',
        ],
        [
            'title' => 'Pending',
            'value' => number_format(KIT_Waybills::get_pending_waybill_count()),
            'subtitle' => 'Status: pending',
            'icon' => 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'yellow',
            'card_filter' => 'pending',
        ],
        [
            'title' => 'Warehouse',
            'value' => number_format($warehouse_total),
            'subtitle' => 'Flagged for warehouse',
            'subtitle_secondary' => $warehouse_recent > 0
                ? sprintf('New in last 7 days: %s', number_format($warehouse_recent))
                : 'No new warehouse flags (7 days)',
            'icon' => 'M20 7l-8-4-8-4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'color' => 'indigo',
            'toggle' => [
                'id' => 'kit-waybill-show-warehouse',
                'label' => 'Show warehouse rows in list',
                'checked' => true,
            ],
        ],
    ];
}

function kit_quick_stats_build_warehouse(array $options): array
{
    kit_quick_stats_require_warehouse();
    $stats = class_exists('KIT_Warehouse') ? KIT_Warehouse::getWarehouseStats() : null;
    if (!$stats) {
        return [];
    }

    $oldest_days = (int) ($stats->oldest_days ?? 0);
    $oldest_label = $oldest_days === 0
        ? __('Today', '08600-services-quotations')
        : sprintf(
            /* translators: %d: number of days the oldest warehouse waybill has been waiting */
            _n('%d day', '%d days', $oldest_days, '08600-services-quotations'),
            $oldest_days
        );

    return [
        [
            'title' => __('In warehouse', '08600-services-quotations'),
            'value' => number_format((int) ($stats->in_warehouse ?? 0)),
            'subtitle' => __('Ready to assign', '08600-services-quotations'),
            'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'color' => 'blue',
        ],
        [
            'title' => __('Total weight', '08600-services-quotations'),
            'value' => number_format((float) ($stats->total_mass_kg ?? 0), 1) . ' kg',
            'subtitle' => __('Mass in queue', '08600-services-quotations'),
            'icon' => 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3',
            'color' => 'gray',
        ],
        [
            'title' => __('Scheduled deliveries', '08600-services-quotations'),
            'value' => number_format((int) ($stats->scheduled_deliveries ?? 0)),
            'subtitle' => __('Assignable trucks', '08600-services-quotations'),
            'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
            'color' => 'green',
        ],
        [
            'title' => __('Oldest waiting', '08600-services-quotations'),
            'value' => $oldest_label,
            'subtitle' => ((int) ($stats->in_warehouse ?? 0) > 0)
                ? __('Longest time in warehouse', '08600-services-quotations')
                : __('No items waiting', '08600-services-quotations'),
            'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'yellow',
        ],
    ];
}

function kit_quick_stats_build_assign_waybills(array $options): array
{
    global $wpdb;
    $wh_count = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT w.id) FROM {$wpdb->prefix}kit_waybills w
         WHERE w.warehouse IS NOT NULL AND w.warehouse != ''"
    );

    $avail_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kit_deliveries WHERE status = 'scheduled' AND id != 1"
    );

    return [
        [
            'title' => 'Warehouse Waybills',
            'value' => number_format($wh_count),
            'subtitle' => 'Ready for assignment',
            'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'color' => 'blue',
        ],
        [
            'title' => 'Available Deliveries',
            'value' => number_format($avail_count),
            'subtitle' => 'Scheduled trucks',
            'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'green',
        ],
    ];
}

function kit_quick_stats_build_drivers(array $options): array
{
    global $wpdb;
    $drivers = isset($options['drivers']) && is_array($options['drivers'])
        ? $options['drivers']
        : $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kit_drivers ORDER BY name ASC");

    $total_drivers = count($drivers);
    $active_drivers = count(array_filter($drivers, function ($d) {
        return !empty($d->is_active);
    }));
    $inactive_drivers = $total_drivers - $active_drivers;
    $total_countries = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kit_operating_countries");

    return [
        [
            'title' => 'Total Drivers',
            'value' => number_format($total_drivers),
            'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'color' => 'blue',
            'class' => 'drivers-stats-total',
        ],
        [
            'title' => 'Active Drivers',
            'value' => number_format($active_drivers),
            'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'green',
            'class' => 'drivers-stats-active',
        ],
        [
            'title' => 'Inactive Drivers',
            'value' => number_format($inactive_drivers),
            'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
            'color' => 'yellow',
            'class' => 'drivers-stats-inactive',
        ],
        [
            'title' => 'Drivers Served',
            'value' => number_format($total_countries),
            'icon' => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'purple',
            'class' => 'countries-stats-served',
        ],
    ];
}

function kit_quick_stats_build_countries(array $options): array
{
    $countries = isset($options['countries']) && is_array($options['countries'])
        ? $options['countries']
        : [];
    if ($countries === []) {
        global $wpdb;
        $countries = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kit_operating_countries ORDER BY country_name ASC");
    }

    $total_countries = count($countries);
    $active_countries = count(array_filter($countries, function ($c) {
        return !empty($c->is_active);
    }));
    $inactive_countries = $total_countries - $active_countries;

    return [
        [
            'title' => 'Total Countries',
            'value' => number_format($total_countries),
            'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'color' => 'blue',
            'class' => 'countries-stats-total',
        ],
        [
            'title' => 'Active Countries',
            'value' => number_format($active_countries),
            'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'green',
            'class' => 'countries-stats-active',
        ],
        [
            'title' => 'Inactive Countries',
            'value' => number_format($inactive_countries),
            'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
            'color' => 'yellow',
            'class' => 'countries-stats-inactive',
        ],
        [
            'title' => 'Countries Served',
            'value' => number_format($total_countries),
            'icon' => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'purple',
            'class' => 'countries-stats-served',
        ],
    ];
}

function kit_quick_stats_build_deliveries(array $options): array
{
    kit_quick_stats_require_deliveries();
    $deliveries = isset($options['deliveries']) ? $options['deliveries'] : null;
    if ($deliveries === null && class_exists('KIT_Deliveries')) {
        $deliveries = KIT_Deliveries::get_all_deliveries();
    }
    if (!is_array($deliveries)) {
        return [];
    }
    $list = $deliveries;

    $total_deliveries = count($list);
    $scheduled_count = count(array_filter($list, function ($d) {
        return ($d->status ?? '') === 'scheduled';
    }));
    $in_transit_count = count(array_filter($list, function ($d) {
        return ($d->status ?? '') === 'in_transit';
    }));
    $delivered_countries = array_unique(array_column($list, 'destination_country_name'));
    $countries_count = count($delivered_countries);

    return [
        [
            'title' => 'Total Deliveries',
            'value' => number_format($total_deliveries),
            'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'color' => 'blue',
            'class' => 'deliveries-stats-total',
        ],
        [
            'title' => 'Scheduled',
            'value' => number_format($scheduled_count),
            'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'green',
            'class' => 'deliveries-stats-scheduled',
        ],
        [
            'title' => 'In Transit',
            'value' => number_format($in_transit_count),
            'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
            'color' => 'yellow',
            'class' => 'deliveries-stats-in-transit',
        ],
        [
            'title' => 'Countries Served',
            'value' => number_format($countries_count),
            'icon' => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'purple',
            'class' => 'deliveries-stats-countries',
        ],
    ];
}

function kit_quick_stats_build_customers(array $options): array
{
    $customers = isset($options['customers']) && is_array($options['customers'])
        ? $options['customers']
        : [];
    if ($customers === [] && function_exists('tholaMaCustomer')) {
        $customers = tholaMaCustomer();
    }
    if (!is_array($customers) || $customers === []) {
        return [];
    }

    $total_customers = count($customers);
    $active_customers_count = count(array_filter($customers, function ($c) {
        return !empty($c->company_name);
    }));
    $inactive_customers = max(0, $total_customers - $active_customers_count);

    return [
        [
            'title' => 'Total Customers',
            'value' => number_format($total_customers),
            'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'color' => 'blue',
            'class' => 'customers-stats-total',
        ],
        [
            'title' => 'Active Customers',
            'value' => number_format($active_customers_count),
            'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'green',
            'class' => 'customers-stats-active',
        ],
        [
            'title' => 'Inactive Customers',
            'value' => number_format($inactive_customers),
            'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
            'color' => 'yellow',
            'class' => 'customers-stats-inactive',
        ],
        [
            'title' => 'Customers Served',
            'value' => number_format($total_customers),
            'icon' => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => 'purple',
            'class' => 'customers-stats-served',
        ],
    ];
}
