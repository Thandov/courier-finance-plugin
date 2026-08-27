<?php
/**
 * Customer Bulk Invoice Dropdown Component
 * 
 * A reusable component for displaying a dropdown to download bulk invoices for customers
 * in a delivery or waybill list.
 * 
 * @param array $config Configuration array:
 *   - 'data' (array): Array of waybill/customer data to extract customers from
 *   - 'delivery_id' (int): Delivery ID for the bulk invoice
 *   - 'action_url' (string): URL to submit the form to (default: pdf-customer-bulk.php)
 *   - 'nonce_action' (string): Nonce action name (default: 'delivery_truck_pdf')
 *   - 'nonce_name' (string): Nonce field name (default: 'delivery_nonce')
 *   - 'title' (string): Title for the dropdown section (default: 'Download Customer Bulk Invoice')
 *   - 'description' (string): Description text (default: 'Select a customer to download...')
 *   - 'button_text' (string): Button text (default: 'Download Invoice')
 *   - 'customer_id_key' (string): Key in data array for customer ID (default: 'customer_id')
 *   - 'customer_name_key' (string): Key in data array for customer name (default: 'customer_name')
 *   - 'customer_surname_key' (string): Key in data array for customer surname (default: 'customer_surname')
 *   - 'can_see_prices' (bool): Whether user can see prices (default: true)
 */
if (!defined('ABSPATH')) {
    exit;
}

function render_customer_bulk_invoice_dropdown($config = []) {
    // Get plugin root file for constructing URLs
    // From includes/components/, go up 2 levels to plugin root
    $plugin_root = dirname(dirname(dirname(__FILE__)));
    $defaults = [
        'data' => [],
        'delivery_id' => 0,
        'action_url' => plugins_url('pdf-customer-bulk.php', $plugin_root . '/bootstrap.php'),
        'nonce_action' => 'delivery_truck_pdf',
        'nonce_name' => 'delivery_nonce',
        'title' => 'Download Customer Bulk Invoice',
        'description' => 'Select a customer to download a consolidated invoice for all their waybills in this delivery.',
        'button_text' => 'Download Invoice',
        'customer_id_key' => 'customer_id',
        'customer_name_key' => 'customer_name',
        'customer_surname_key' => 'customer_surname',
        'can_see_prices' => true,
        'open_in_new_tab' => true, // Open PDF in new tab
    ];
    
    $config = array_merge($defaults, $config);
    
    // Check if user can see prices
    if (!$config['can_see_prices']) {
        if (class_exists('KIT_User_Roles')) {
            $config['can_see_prices'] = KIT_User_Roles::can_see_prices();
        }
    }
    
    if (!$config['can_see_prices'] || empty($config['data']) || $config['delivery_id'] <= 0) {
        return '';
    }
    
    // Extract unique customers from data
    $customers = [];
    foreach ($config['data'] as $item) {
        // Handle both array and object formats
        if (is_array($item)) {
            $customer_id = intval($item[$config['customer_id_key']] ?? 0);
            $customer_name = trim(($item[$config['customer_name_key']] ?? '') . ' ' . ($item[$config['customer_surname_key']] ?? ''));
        } else {
            $customer_id = intval($item->{$config['customer_id_key']} ?? 0);
            $customer_name = trim(($item->{$config['customer_name_key']} ?? '') . ' ' . ($item->{$config['customer_surname_key']} ?? ''));
        }
        
        if ($customer_id > 0 && !empty($customer_name) && !isset($customers[$customer_id])) {
            $customers[$customer_id] = [
                'name' => $customer_name,
                'id' => $customer_id,
            ];
        }
    }
    
    if (empty($customers)) {
        return '';
    }
    
    // Get primary color for styling
    $primary_color = '#2563eb';
    if (class_exists('KIT_Commons')) {
        try {
            if (method_exists('KIT_Commons', 'getPrimaryColor')) {
                $primary_color = KIT_Commons::getPrimaryColor();
            }
        } catch (Throwable $e) {
            // keep default
        }
    }
    
    ob_start();
    ?>
    <div style="margin: 16px 0; padding: 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px;">
        <h3 style="margin: 0 0 8px; font-size: 13px; color: var(--secondary, #111827);"><?= esc_html($config['title']) ?></h3>
        <p style="margin: 0 0 8px; font-size: 11px; color: #6b7280;"><?= esc_html($config['description']) ?></p>
        <form method="GET" action="<?= esc_url($config['action_url']) ?>" <?= $config['open_in_new_tab'] ? 'target="_blank"' : '' ?> style="display: flex; gap: 8px; align-items: center;">
            <input type="hidden" name="delivery_id" value="<?= esc_attr($config['delivery_id']) ?>">
            <input type="hidden" name="<?= esc_attr($config['nonce_name']) ?>" value="<?= esc_attr(wp_create_nonce($config['nonce_action'])) ?>">
            <select name="customer_id" required style="flex: 1; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px;">
                <option value="">Select Customer...</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= esc_attr($customer['id']) ?>"><?= esc_html($customer['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php echo KIT_Commons::renderButton($config['button_text'], 'primary', 'lg', ['type' => 'submit', 'style' => 'padding: 6px 16px; background: ' . esc_attr($primary_color) . '; color: white; border: none; border-radius: 4px; font-size: 12px; font-weight: 600; cursor: pointer;']); ?>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

