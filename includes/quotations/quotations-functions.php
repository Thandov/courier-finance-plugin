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
    return [
        'customer_name' => isset($request_data['customer_name']) ? sanitize_text_field($request_data['customer_name']) : ' ',
        'contact_details' => isset($request_data['contact_details']) ? sanitize_text_field($request_data['contact_details']) : ' ',
        'pickup_location' => isset($request_data['pickup_location']) ? sanitize_text_field($request_data['pickup_location']) : ' ',
        'delivery_location' => isset($request_data['delivery_location']) ? sanitize_text_field($request_data['delivery_location']) : ' ',
        'delivery_country' => isset($request_data['delivery_country']) ? floatval($request_data['delivery_country']) : ' ',
        'weight_kg' => isset($request_data['weight_kg']) ? floatval($request_data['weight_kg']) : ' ',
        'volume_m3' => isset($request_data['volume_m3']) ? floatval($request_data['volume_m3']) : ' ',
        'return_load' => isset($request_data['return_load']) ? sanitize_text_field($request_data['return_load']) : ' ',
        'special_requirements' => isset($request_data['special_requirements']) ? sanitize_text_field($request_data['special_requirements']) : ' ',
        'weight_cost' => isset($request_data['weight_cost']) ? floatval($request_data['weight_cost']) : ' ',
        'volume_cost' => isset($request_data['volume_cost']) ? floatval($request_data['volume_cost']) : ' ',
        'final_cost' => isset($request_data['final_cost']) ? floatval($request_data['final_cost']) : ' ',
        'additional_fees' => isset($request_data['additional_fees']) ? floatval($request_data['additional_fees']) : ' ',
        'total_cost' => isset($request_data['total_cost']) ? floatval($request_data['total_cost']) : ' ',
        'discount_percent' => isset($request_data['discount_percent']) ? floatval($request_data['discount_percent']) : ' ',
        'discounted_cost' => isset($request_data['discounted_cost']) ? floatval($request_data['discounted_cost']) : ' ',
        'status' => isset($request_data['status']) ? sanitize_text_field($request_data['status']) : ' ',
    ];
    
   
}

// ✅ Check if quotation exists (by customer name and email)
function kit_quotation_exists($customer_name, $customer_email)
{
    global $wpdb, $table_quotations;
    $query = $wpdb->prepare("SELECT COUNT(*) FROM $table_quotations WHERE customer_name = %s AND customer_email = %s", $customer_name, $customer_email);
    return ($wpdb->get_var($query) > 0);
}

// ✅ Get all quotations
function kit_get_all_quotations()
{
    global $wpdb, $table_quotations;
    return $wpdb->get_results("SELECT * FROM $table_quotations", ARRAY_A);
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
    $wpdb->insert($table_quotations, $data, ['%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s']);
    $last_inserted_id = $wpdb->insert_id;

    wp_redirect(admin_url('admin.php?page=kit-quotation-edit&quotation_id=' . $last_inserted_id . '&message=success'));
    exit;
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
