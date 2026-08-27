<?php
/**
 * Admin: customer booking requests.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('kit_view_waybills')) {
    wp_die(esc_html__('You do not have permission to access this page.', '08600-services-quotations'));
}

require_once plugin_dir_path(__FILE__) . '../class-unified-table.php';
require_once plugin_dir_path(__FILE__) . '../class-kit-booking-requests.php';
require_once plugin_dir_path(__FILE__) . '../components/quickStats.php';

KIT_Booking_Requests::ensure_table();

$view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : 'pending';
if (!in_array($view, array('pending', 'converted', 'all'), true)) {
    $view = 'pending';
}

$status_filter = $view === 'all' ? '' : $view;
$rows = KIT_Booking_Requests::list_all($status_filter);

global $wpdb;
$cust_table = $wpdb->prefix . 'kit_customers';

$count_pending = 0;
$count_converted = 0;
$all = KIT_Booking_Requests::list_all('');
foreach ($all as $r) {
    if (($r['status'] ?? '') === 'converted') {
        $count_converted++;
    } else {
        $count_pending++;
    }
}

$table_rows = array();
foreach ($rows as $row) {
    $cust_id = (int) $row['customer_id'];
    $cust = $wpdb->get_row(
        $wpdb->prepare("SELECT name, surname FROM {$cust_table} WHERE cust_id = %d LIMIT 1", $cust_id),
        ARRAY_A
    );
    $name = $cust ? trim(($cust['name'] ?? '') . ' ' . ($cust['surname'] ?? '')) : '#' . $cust_id;
    $from = KIT_Booking_Requests::city_label($row['origin_city_id'] ?? 0);
    $to = KIT_Booking_Requests::city_label($row['destination_city_id'] ?? 0);
    $convert_url = KIT_Booking_Requests::staff_create_waybill_url($cust_id, (int) $row['id']);
    $status = (string) ($row['status'] ?? 'pending');
    $actions = '';
    if ($status !== 'converted') {
        $actions = KIT_Commons::renderButton(
            __('Convert to waybill', '08600-services-quotations'),
            'primary',
            'sm',
            array('href' => $convert_url, 'noLoading' => true)
        );
    } elseif (!empty($row['waybill_id'])) {
        $view_url = admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . (int) $row['waybill_id']);
        $actions = KIT_Commons::renderButton(
            __('View waybill', '08600-services-quotations'),
            'secondary',
            'sm',
            array('href' => $view_url, 'noLoading' => true)
        );
    }

    $table_rows[] = array(
        'id'         => (int) $row['id'],
        'request_id' => (int) $row['id'],
        'customer'   => $name,
        'cust_id'    => $cust_id,
        'route'      => trim($from . ' → ' . $to),
        'parcels'    => (int) ($row['parcel_count'] ?? 1),
        'status'     => $status,
        'notes'      => (string) ($row['notes'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'actions'    => $actions,
        'city'       => $status,
    );
}

$page_url = admin_url('admin.php?page=08600-booking-requests');
$filter_base = $page_url;
?>
<div class="wrap">
<?php
echo KIT_Commons::showingHeader(array(
    'title' => __('Booking requests', '08600-services-quotations'),
    'desc'  => __('Customer quote requests. Convert to a waybill to capture rates and ops.', '08600-services-quotations'),
));

$stats = array(
    array(
        'title'     => __('Pending', '08600-services-quotations'),
        'value'     => number_format($count_pending),
        'subtitle'  => __('Awaiting conversion', '08600-services-quotations'),
        'icon'      => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        'color'     => 'yellow',
        'clickable' => true,
        'onclick'   => "window.location.href='" . esc_js(add_query_arg('view', 'pending', $filter_base)) . "'",
    ),
    array(
        'title'     => __('Converted', '08600-services-quotations'),
        'value'     => number_format($count_converted),
        'subtitle'  => __('Already a waybill', '08600-services-quotations'),
        'icon'      => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'color'     => 'green',
        'clickable' => true,
        'onclick'   => "window.location.href='" . esc_js(add_query_arg('view', 'converted', $filter_base)) . "'",
    ),
);

if (class_exists('KIT_QuickStats')) {
    echo KIT_QuickStats::render($stats);
}

$view_labels = array(
    'pending'   => __('Pending', '08600-services-quotations'),
    'converted' => __('Converted', '08600-services-quotations'),
    'all'       => __('All', '08600-services-quotations'),
);
?>
<div class="flex flex-wrap gap-2 mb-4">
    <?php foreach ($view_labels as $key => $label) : ?>
        <?php
        $tab_url = add_query_arg('view', $key, $filter_base);
        $active_tab = $view === $key;
        $tab_class = $active_tab
            ? 'inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200'
            : 'inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-white text-gray-600 border border-gray-200 hover:bg-gray-50';
        ?>
        <a href="<?php echo esc_url($tab_url); ?>" class="<?php echo esc_attr($tab_class); ?>"><?php echo esc_html($label); ?></a>
    <?php endforeach; ?>
</div>
<div class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
    <?php
    echo KIT_Unified_Table::infinite(
        $table_rows,
        array(
            'request_id' => array('label' => __('ID', '08600-services-quotations')),
            'customer'   => array('label' => __('Customer', '08600-services-quotations')),
            'route'      => array('label' => __('Route', '08600-services-quotations')),
            'parcels'    => array('label' => __('Parcels', '08600-services-quotations')),
            'status'     => array('label' => __('Status', '08600-services-quotations')),
            'created_at' => array('label' => __('Submitted', '08600-services-quotations')),
            'actions'    => array(
                'label'    => __('Actions', '08600-services-quotations'),
                'callback' => static function ($value) {
                    return $value;
                },
            ),
        ),
        array(
            'title'           => __('Booking requests', '08600-services-quotations'),
            'searchable'      => true,
            'sortable'        => true,
            'exportable'      => false,
            'bulk_management' => false,
            'groupby'         => null,
            'table_type'      => false,
            'preserve_order'  => true,
            'pagination'      => true,
            'items_per_page'  => 25,
            'empty_message'   => __('No booking requests in this view.', '08600-services-quotations'),
            'table_class'     => 'w-full table-auto border-collapse kit-waybill-dashboard-table',
        )
    );
    ?>
</div>
</div>
