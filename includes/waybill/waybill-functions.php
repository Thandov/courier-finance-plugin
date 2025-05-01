<?php
// includes/waybill/waybill-functions.php

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Waybills {
    public static function init() {
        add_shortcode('kit_waybill_form', [__CLASS__, 'display_waybill_form']);
        add_action('kit_waybills_list', [__CLASS__, 'kit_get_all_waybills_table']);
        add_action('admin_post_add_waybill_action', [self::class, 'process_form']);
    }

    public static function save_waybill($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';

        $waybill_data = array(
            'waybill_id'              => random_int(1328, 9823),
            'customer_id'              => isset($data['cust_id']) ? sanitize_text_field($data['cust_id']) : 'N/A',
            'waybill_no'              => isset($data['waybill_no']) ? sanitize_text_field($data['waybill_no']) : 'N/A',
            'date_received'           => isset($data['date_received']) ? sanitize_text_field($data['date_received']) : 'N/A',
            'customer_name'           => isset($data['customer_name']) ? sanitize_text_field($data['customer_name']) : 'N/A',
            'is_new_customer'         => isset($data['is_new_customer']) ? 1 : 0,
            'destination_country'     => isset($data['destination_country']) ? sanitize_text_field($data['destination_country']) : 'N/A',
            'destination_city'        => isset($data['destination_city']) ? sanitize_text_field($data['destination_city']) : 'N/A',
            'consignor_name'          => isset($data['consignor_name']) ? sanitize_text_field($data['consignor_name']) : 'N/A',
            'consignor_code'          => isset($data['consignor_code']) ? sanitize_text_field($data['consignor_code']) : 'N/A',
            'warehouse'               => isset($data['warehouse']) ? sanitize_text_field($data['warehouse']) : 'N/A',
            'consignor_address'       => isset($data['consignor_address']) ? sanitize_text_field($data['consignor_address']) : 'N/A',
            'contact_name'            => isset($data['contact_name']) ? sanitize_text_field($data['contact_name']) : 'N/A',
            'vat_number'              => isset($data['vat_number']) ? sanitize_text_field($data['vat_number']) : 'N/A',
            'telephone'               => isset($data['telephone']) ? sanitize_text_field($data['telephone']) : 'N/A',
            'product_invoice_number'  => isset($data['product_invoice_number']) ? sanitize_text_field($data['product_invoice_number']) : 'N/A',
            'product_invoice_amount'  => isset($data['product_invoice_amount']) ? floatval($data['product_invoice_amount']) : 0,
            'item_length'             => isset($data['item_length']) ? floatval($data['item_length']) : 0,
            'item_width'              => isset($data['item_width']) ? floatval($data['item_width']) : 0,
            'item_height'             => isset($data['item_height']) ? floatval($data['item_height']) : 0,
            'total_volume'            => isset($data['total_volume']) ? floatval($data['total_volume']) : 0,
            'total_mass_kg'           => isset($data['total_mass_kg']) ? floatval($data['total_mass_kg']) : 0,
            'unit_volume'             => isset($data['unit_volume']) ? floatval($data['unit_volume']) : 0,
            'unit_mass'               => isset($data['unit_mass']) ? floatval($data['unit_mass']) : 0,
            'charge_basis'            => isset($data['charge_basis']) ? sanitize_text_field($data['charge_basis']) : 'N/A',
            'mass_charge'             => isset($data['mass_charge']) ? floatval($data['mass_charge']) : 0,
            'volume_charge'           => isset($data['volume_charge']) ? floatval($data['volume_charge']) : 0,
            'created_at'              => current_time('mysql'),
        );
    
        $result = $wpdb->insert($table_name, $waybill_data);
    
        return $result ? true : new WP_Error('db_error', __('Error saving waybill', 'kit'));
    }

    public static function get_waybills($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';

        $defaults = array(
            'number' => 20,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        $query = $wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY %s %s LIMIT %d OFFSET %d",
            $args['orderby'],
            $args['order'],
            $args['number'],
            $args['offset']
        );

        return $wpdb->get_results($query);
    }

    public static function display_waybill_form() {
        wp_enqueue_style('kit-tailwind');
        wp_enqueue_style('kit-quotations');
        wp_enqueue_script('kit-scripts');

        ob_start();
        include plugin_dir_path(__FILE__) . 'waybill-form.php';
        return ob_get_clean();
    }

    public static function process_form() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['waybill_no'])) {
            $result = self::save_waybill($_POST);

            if (is_wp_error($result)) {
                wp_redirect(add_query_arg('error', '1', wp_get_referer()));
            } else {
                wp_redirect(add_query_arg('success', '1', wp_get_referer()));
            }
            exit;
        }
    }

    
    public static function add_waybill_dash() {
        echo '<pre>';
        print_r($_POST);
        echo '</pre>';
        exit();
        exit;
    }

    // ✅ Shortcode to display all waybills
    public static function kit_get_all_waybills_table()
    {
        $waybills = self::get_waybills();
   
         // Start output buffering
        ob_start();
        ?>
        
            <div class="mx-auto p-4 mt-6">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <?php
                                    $headers = [
                                        'waybill_no' => 'Waybill No',
                                        'date_received' => 'Date Received',
                                        'customer_name' => 'Customer',
                                        'destination_city' => 'Destination City',
                                        'destination_country' => 'Destination Country',
                                        'charge_basis' => 'Charge Basis',
                                        'Quote' => 'Quote',
                                        'actions' => 'Actions'
                                    ];
                                    
                                    foreach ($headers as $key => $label) {
                                        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . $label . '</th>';
                                    }
                                    ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($waybills as $waybill) : ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= $waybill->waybill_no ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $waybill->date_received ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $waybill->customer_name ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $waybill->destination_city ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $waybill->destination_country ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $waybill->charge_basis ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= "" ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="?page=08600-Waybill-view&waybill_id=<?php echo esc_html($waybill->id); ?>"
                                        class="bg-blue-600 text-white px-4 py-2 rounded">
                                        <span class="dashicons dashicons-visibility" style="font-size: 18px;"></span>
                                    </a>
                                <a href="/waybills/delete/<?= $waybill->id ?>" class="ml-4 text-red-600 hover:text-red-900"
                                    onclick="return confirm('Are you sure you want to delete this waybill?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                <div class="text-sm text-gray-500">
                    Showing <span class="font-medium">1</span> to <span class="font-medium"><?= count($waybills) ?></span> of <span
                        class="font-medium"><?= count($waybills) ?></span> results
                </div>
                <div class="flex space-x-2">
                    <button
                        class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                        disabled>
                        Previous
                    </button>
                    <button
                        class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                        disabled>
                        Next
                    </button>
                </div>
            </div>
            <?php 
        return ob_get_clean();
    }

    public static function plugin_Waybill_view_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';
        $waybill_id = isset($_GET['waybill_id']) ? intval($_GET['waybill_id']) : 0;

        echo '<div class="mt-6">';
        if ($waybill_id) {
            $waybill = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $waybill_id));
            if ($waybill) {
                include plugin_dir_path(__FILE__) . 'waybill-form.php';
            } else {
                echo '<p>' . __('Waybill not found.', 'kit') . '</p>';
            }
        } else {
            echo '<p>' . __('Invalid waybill ID.', 'kit') . '</p>';
        }
        echo '</div>';
    }
    


}


// Initialize
KIT_Waybills::init();