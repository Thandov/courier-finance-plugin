<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

global $wpdb;
$table_quotations = $wpdb->prefix . "kit_quotations";

// ✅ Handle quotation data processing
function kit_handle_quotation_data($request_type = 'POST')
{
    $request_data = ($request_type === 'POST') ? $_POST : $_GET;

    // Get the values from the form (sanitized)
    $weightKg = isset($request_data['weight']) ? floatval($request_data['weight']) : 0;
    $length = isset($request_data['length']) ? floatval($request_data['length']) : 0;
    $width = isset($request_data['width']) ? floatval($request_data['width']) : 0;
    $height = isset($request_data['height']) ? floatval($request_data['height']) : 0;
    $volumeM3 = $length * $width * $height;

    // Get shipping method
    $shippingMethod = isset($request_data['shipping_method']) ? sanitize_text_field($request_data['shipping_method']) : 'weight';

    // Define the constant costs
    $sad500Fee = 350; // R350
    $sadcCertificateFee = 1000; // R1000

    // Get the values of the checkboxes
    $includeSAD500 = isset($request_data['include_sad500']);
    $includeSADC = isset($request_data['include_sadc']);
    $returnLoad = isset($request_data['return_load']);

    // Base cost calculation
    $baseCost = 0;
    $weightCost = 0;
    $volumeCost = 0;

    if ($shippingMethod === 'weight' && $weightKg > 0) {
        // Weight-based cost calculation
        if ($weightKg <= 500) $weightCost = $weightKg * 40;
        elseif ($weightKg <= 1000) $weightCost = $weightKg * 35;
        elseif ($weightKg <= 2500) $weightCost = $weightKg * 30;
        elseif ($weightKg <= 5000) $weightCost = $weightKg * 25;
        elseif ($weightKg <= 7500) $weightCost = $weightKg * 20;
        elseif ($weightKg <= 10000) $weightCost = $weightKg * 17.5;
        else $weightCost = $weightKg * 15;

        $baseCost = $weightCost;
    } elseif ($shippingMethod === 'volume' && $volumeM3 > 0) {
        // Volume-based cost calculation
        if ($volumeM3 <= 1) $volumeCost = 7500;
        elseif ($volumeM3 <= 2) $volumeCost = 7000;
        elseif ($volumeM3 <= 5) $volumeCost = 6500;
        elseif ($volumeM3 <= 10) $volumeCost = 5500;
        elseif ($volumeM3 <= 15) $volumeCost = 5000;
        elseif ($volumeM3 <= 20) $volumeCost = 4500;
        elseif ($volumeM3 <= 30) $volumeCost = 4000;
        else $volumeCost = 3500;

        $baseCost = $volumeCost;
    }

    // Calculate sub total (base cost + additional fees)
    $subTotal = $baseCost;

    // Add the constant costs if selected
    if ($includeSAD500) $subTotal += $sad500Fee;
    if ($includeSADC) $subTotal += $sadcCertificateFee;

    // Apply return load discount if selected (assuming 10% discount)
    $finalCost = $subTotal;
    if ($returnLoad) {
        $finalCost = $subTotal * 0.9; // 10% discount
    }

    return [
        'customer_name' => sanitize_text_field($request_data['customer_name']),
        'customer_email' => sanitize_email($request_data['customer_email']),
        'customer_phone' => sanitize_text_field($request_data['customer_phone']),

        'sender_name' => sanitize_text_field($request_data['sender_name']),
        'sender_email' => sanitize_email($request_data['sender_email']),
        'sender_phone' => sanitize_text_field($request_data['sender_phone']),
        'sender_address' => sanitize_textarea_field($request_data['sender_address']),

        'receiver_name' => sanitize_text_field($request_data['receiver_name']),
        'receiver_email' => sanitize_email($request_data['receiver_email']),
        'receiver_phone' => sanitize_text_field($request_data['receiver_phone']),
        'receiver_address' => sanitize_textarea_field($request_data['receiver_address']),

        'shipping_method' => $shippingMethod,
        'weight' => $weightKg,
        'length' => $length,
        'width' => $width,
        'height' => $height,
        'send_location' => sanitize_text_field($request_data['send_location']),

        'delivery_address' => sanitize_textarea_field($request_data['delivery_address']),

        'const_cost' => ($includeSAD500 ? $sad500Fee : 0) + ($includeSADC ? $sadcCertificateFee : 0),
        'sub_total' => $subTotal,
        'final_cost' => $finalCost,
        'include_sad500' => $includeSAD500 ? 1 : 0,
        'include_sadc' => $includeSADC ? 1 : 0,
        'return_load' => $returnLoad ? 1 : 0,
    ];
}


// ✅ Add Quotation
add_action('admin_post_kit_add_quotation', 'kit_add_quotation');

function kit_add_quotation()
{

    if (!isset($_POST['kit_add_quotation_nonce']) || !wp_verify_nonce($_POST['kit_add_quotation_nonce'], 'kit_add_quotation_action')) {
        wp_die('Security check failed');
    }
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    global $wpdb, $table_quotations;
    $data = kit_handle_quotation_data('POST');

    if (empty($data['customer_name'])) {
        wp_die('Required fields are missing.');
    }

    //xxxxxxxxxx
    $wpdb->insert($table_quotations, $data, ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s']);
    $last_inserted_id = $wpdb->insert_id;
    wp_redirect(admin_url('admin.php?page=kit-quotation-edit&quotation_id=' . $last_inserted_id . '&message=success'));
    exit;
}

// ✅ Get all quotations
function kit_get_all_quotations()
{
    global $wpdb, $table_quotations;
    return $wpdb->get_results("SELECT * FROM $table_quotations", ARRAY_A);
}

// ✅ Get Quotation by ID
function kit_get_quotation_by_id($id)
{
    global $wpdb, $table_quotations;
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_quotations WHERE id = %d", intval($id)),
        ARRAY_A
    );
}

// ✅ Update Quotation
add_action('admin_post_kit_update_quotation', 'kit_update_quotation');
function kit_update_quotation()
{
    if (!isset($_POST['kit_quotation_nonce']) || !wp_verify_nonce($_POST['kit_quotation_nonce'], 'kit_edit_quotation_action')) {
        wp_die('Security check failed');
    }
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    global $wpdb, $table_quotations;
    $quotation_id = intval($_POST['quotation_id']);
    $data = kit_handle_quotation_data('POST');

    if (empty($quotation_id)) {
        wp_die('Invalid quotation ID.');
    }
    
    $wpdb->update($table_quotations, $data, ['id' => $quotation_id], ['%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s'], ['%d']);
    wp_redirect(admin_url('admin.php?page=kit-quotation-edit&quotation_id=' . $quotation_id . '&message=updated'));
    exit;
}

// ✅ Shortcode to display all quotations
function kit_get_all_quotations_table()
{
    $quotations = kit_get_all_quotations();
    if (!$quotations) {
        return '<p class="text-gray-500 text-center">No quotations found.</p>';
    }
    ob_start();
    ?>
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white border border-gray-200 rounded-lg shadow-md">
                <thead>
                    <tr class="bg-gray-100 text-gray-700 text-left text-sm">
                        <th class="py-3 px-4 border-b">Customer Name</th>
                        <th class="py-3 px-4 border-b">Contact Details</th>
                        <th class="py-3 px-4 border-b">From > To</th>
                        <th class="py-3 px-4 border-b">Total Amount</th>
                        <th class="py-3 px-4 border-b"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quotations as $quotation): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 px-4"> <?php echo esc_html($quotation['customer_name']); ?> </td>
                            <td class="py-3 px-4"> <?php echo esc_html($quotation['contact_details']); ?> </td>
                            <td class="py-3 px-4"> <?php echo esc_html($quotation['pickup_location']) . " > " . esc_html($quotation['delivery_location']) . esc_html($quotation['delivery_country']); ?> </td>
                            <td class="py-3 px-4"> <?php echo esc_html(number_format($quotation['final_cost'], 2)); ?> </td>
                            <td class="py-3 px-4">action</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php
    return ob_get_clean();
}
add_shortcode('kit_quotations', 'kit_get_all_quotations_table');
