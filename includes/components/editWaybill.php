<?php
if (!defined('ABSPATH')) {
    exit;
}

// Validate waybill data
if (empty($waybill) || ! is_array($waybill)) {
    echo '<div class="max-w-6xl mx-auto p-6 bg-white rounded-lg shadow-md">';
    echo '<div class="text-center py-8">';
    echo '<h2 class="text-2xl font-bold text-red-600 mb-4">Error</h2>';
    echo '<p class="text-gray-600">Waybill data not found or invalid.</p>';
    echo '<a href="?page=08600-Waybill-list" class="mt-4 inline-block px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Back to Waybills</a>';
    echo '</div>';
    echo '</div>';
    return;
}

// Ensure required fields exist with defaults
$waybill = array_merge([
    'waybill_no'             => '',
    'customer_id'            => '',
    'direction_id'           => '',
    'customer_name'          => '',
    'customer_surname'       => '',
    'cell'                   => '',
    'email_address'          => '',
    'address'                => '',
    'company_name'           => '',
    'approval'               => 'pending',
    'approved_by_username'   => '',
    'last_updated_at'        => '',
    'product_invoice_number' => '',
    'product_invoice_amount' => 0,
    'tracking_number'        => '',
    'truck_number'           => '',
    'dispatch_date'          => '',
    'miscellaneous'          => [],
    'items'                  => [],
], $waybill);

// Determine client type from company_id and/or real company_name
$edit_company_id = (int) ($waybill['company_id'] ?? 0);
$edit_company_name = trim((string) ($waybill['company_name'] ?? ''));
$is_business = $edit_company_id > 0;
if (!$is_business && $edit_company_name !== '') {
    $is_business = class_exists('KIT_Company_Customers')
        ? !KIT_Company_Customers::is_placeholder_company($edit_company_name)
        : !in_array(strtolower($edit_company_name), ['individual', '1ndividual', 'private'], true);
}

// Ensure miscellaneous is an array
if (! is_array($waybill['miscellaneous'])) {
    $waybill['miscellaneous'] = [];
}

// Ensure items is an array
if (! is_array($waybill['items'])) {
    $waybill['items'] = [];
}

// Load customers for selection
try {
    $customers = tholaMaCustomer();
    if (empty($customers)) {
        error_log('tholaMaCustomer returned empty result');
        $customers = [];
    }
} catch (Exception $e) {
    error_log('Error calling tholaMaCustomer: ' . $e->getMessage());
    $customers = [];
}

if (class_exists('KIT_Commons')) {
    KIT_Commons::enqueueComponentScripts(['kitscript']);
} elseif (function_exists('wp_enqueue_script')) {
    wp_enqueue_script('kitscript', COURIER_FINANCE_PLUGIN_URL . 'js/kitscript.js', ['jquery'], null, true);
}

if (function_exists('wp_localize_script')) {
    $intl_snapshot = 0.0;
    if (!empty($waybill['miscellaneous']['others']['international_price_rands'])
        && is_numeric($waybill['miscellaneous']['others']['international_price_rands'])) {
        $intl_snapshot = floatval($waybill['miscellaneous']['others']['international_price_rands']);
    }
    if ($intl_snapshot <= 0 && class_exists('KIT_Waybills')) {
        $intl_snapshot = floatval(KIT_Waybills::international_price_in_rands());
    }

    wp_localize_script('kitscript', 'EditWaybillData', [
        'customers' => $customers,
        'charges' => [
            'sad500' => class_exists('KIT_Waybills') ? floatval(KIT_Waybills::sadc_certificate()) : 350.0,
            'sadc' => class_exists('KIT_Waybills') ? floatval(KIT_Waybills::sad()) : 1000.0,
            'vat_percent' => class_exists('KIT_Waybills') ? floatval(KIT_Waybills::vatRate()) : 10.0,
            'international_price' => $intl_snapshot,
        ],
    ]);
}

$smalling_enabled = true;

// Get driver name - check if it's in waybill data, otherwise get from delivery
$driver_name = 'N/A';
global $wpdb;
if (!empty($waybill['truck_driver']) && is_numeric($waybill['truck_driver'])) {
    $drivers_table = $wpdb->prefix . 'kit_drivers';
    $driver = $wpdb->get_var($wpdb->prepare(
        "SELECT name FROM $drivers_table WHERE id = %d LIMIT 1",
        intval($waybill['truck_driver'])
    ));
    if ($driver) {
        $driver_name = $driver;
    }
} elseif (!empty($waybill['delivery_id'])) {
    $deliveries_table = $wpdb->prefix . 'kit_deliveries';
    $drivers_table = $wpdb->prefix . 'kit_drivers';

    $delivery = $wpdb->get_row($wpdb->prepare(
        "SELECT driver_id FROM $deliveries_table WHERE id = %d LIMIT 1",
        intval($waybill['delivery_id'])
    ), ARRAY_A);

    if ($delivery && !empty($delivery['driver_id']) && is_numeric($delivery['driver_id'])) {
        $driver = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM $drivers_table WHERE id = %d LIMIT 1",
            intval($delivery['driver_id'])
        ));
        if ($driver) {
            $driver_name = $driver;
        }
    }
}

require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/components/edit-waybill/kit-edit-waybill-templates.php';

$kit_edit_template_id = kit_edit_waybill_get_active_template_id();
$kit_edit_template_path = kit_edit_waybill_template_path($kit_edit_template_id);
if (!is_readable($kit_edit_template_path)) {
    $kit_edit_template_id = kit_edit_waybill_default_template_id();
    $kit_edit_template_path = kit_edit_waybill_template_path($kit_edit_template_id);
}

if ($kit_edit_template_id === 'edit_waybill_template2' && function_exists('wp_enqueue_style')) {
    wp_enqueue_style(
        'kit-waybill-template2',
        COURIER_FINANCE_PLUGIN_URL . 'assets/css/kit-waybill-template2.css',
        [],
        '1.0.0'
    );
    if (function_exists('wp_print_styles')) {
        wp_print_styles(['kit-waybill-template2']);
    }
}

kit_edit_waybill_render_switcher($kit_edit_template_id);
require $kit_edit_template_path;
