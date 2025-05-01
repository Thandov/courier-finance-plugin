<?php
// waybill-form.php

if (!defined('ABSPATH')) {
    exit;
}

// Dummy customer list
if (isset($_GET['cust_id'])) {
    $customer_id = intval($_GET['cust_id']);
} else {
    $customer_id = 0;
    
}
$customers = tholaMaCustomer();

$selected_customer_key = $customer_id;
$is_existing_customer = false;

// Search through customers to find matching cust_id
foreach ($customers as $customer) {
    if ($customer->cust_id == $selected_customer_key) {
        $is_existing_customer = true;
        break;
    }
}

// Check if editing
$waybill_id = isset($_GET['waybill_id']) ? intval($_GET['waybill_id']) : 0;
$waybill = null;
$is_edit_mode = false;

if ($waybill_id > 0) {
    global $wpdb;
    $waybill = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}kit_waybills WHERE id = %d", $waybill_id)
    );
    $is_edit_mode = !is_null($waybill);
}


$form_action = $is_edit_mode 
    ? admin_url('admin-post.php?action=update_waybill_action') 
    : admin_url('admin-post.php?action=add_waybill_action');
?>

<body class="bg-gray-100">
    <div class="mx-auto p-4 max-w-4xl">
        <h1 class="text-2xl font-bold mb-6 text-blue-800">
            <?php echo $is_edit_mode ? 'Edit Waybill' : 'Capture New Waybill'; ?>
        </h1>

        <form method="POST" action="<?php echo esc_url($form_action); ?>" class="bg-white p-6 rounded-lg shadow-md">
            <?php if ($is_edit_mode): ?>
            <input type="hidden" name="waybill_id" value="<?php echo esc_attr($waybill_id); ?>">
            <?php endif; ?>

            <?php wp_nonce_field($is_edit_mode ? 'update_waybill_nonce' : 'add_waybill_nonce'); ?>

            <!-- Waybill Header -->
            <div class="mb-6">
                <h2 class="text-lg font-medium text-gray-700 mb-3">Waybill Header</h2>
                <p class="text-sm text-gray-500">
                    <?php echo $is_edit_mode ? 'Edit the waybill details below.' : 'Please fill in the details below to create a new waybill.'; ?>
                </p>
                <?php if (current_user_can('administrator') && (in_array(wp_get_current_user()->user_login, ['Thando', 'Mel', 'Admin']))) {
                echo " Your are Admin ";  
                ?>
                <div class="mt-8 text-right">
                    <a href="<?php echo plugins_url('pdf-generator.php', __FILE__); ?>?quotation_id=<?php echo $quotation_id; ?>"
                        class="bg-blue-600 text-white px-4 py-2 rounded">
                        Download PDF
                    </a>
                </div>
                <?php 
                }?>
            </div>

            <!-- Waybill Info Section -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Waybill No</label>
                    <input type="number" name="waybill_no" class="w-full px-3 py-2 border border-gray-300 rounded-md"
                        value="<?php echo esc_attr($waybill->waybill_no ?? rand(5677, 9999)); ?>">
                </div>
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Date Received</label>
                    <input type="date" name="date_received" class="w-full px-3 py-2 border border-gray-300 rounded-md"
                        value="<?php echo esc_attr($waybill->date_received ?? date('Y-m-d')); ?>">
                </div>
            </div>

            <!-- Customer Section -->
            <div class="mb-6">
                <h2 class="text-lg font-medium text-gray-700 mb-3">Customer</h2>

                <!-- Hidden field to store customer ID -->
                <input type="hidden" id="cust_id" name="cust_id" value="<?php echo esc_attr($customer_id); ?>">

                <!-- Customer selection dropdown -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2">Select Customer</label>
                    <select id="customer-select" name="customer_select"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md">
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo esc_attr($customer->cust_id); ?>"
                            <?php selected($customer->cust_id, $customer_id); ?>
                            data-name="<?php echo esc_attr($customer->name); ?>"
                            data-surname="<?php echo esc_attr($customer->surname); ?>"
                            data-cell="<?php echo esc_attr($customer->cell); ?>"
                            data-address="<?php echo esc_attr($customer->address); ?>">
                            <?php echo esc_html($customer->name . ' ' . $customer->surname); ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="new" <?php selected(0, $customer_id); ?>>+ Add New Customer</option>
                    </select>
                </div>

                <!-- Customer Details Form -->
                <div class="border rounded-md overflow-hidden mb-4">
                    <button type="button"
                        class="customer-accordion-toggle w-full text-left px-4 py-3 bg-gray-100 hover:bg-gray-200 font-medium">
                        Customer Details
                    </button>

                    <div class="customer-details-content px-4 py-3 bg-white">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-medium mb-2">Name</label>
                                <input type="text" id="customer_name" name="customer_name"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md"
                                    value="<?php echo esc_attr($is_existing_customer ? $customer->name : ''); ?>">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-medium mb-2">Surname</label>
                                <input type="text" id="surname" name="surname"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md"
                                    value="<?php echo esc_attr($is_existing_customer ? $customer->surname : ''); ?>">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-medium mb-2">Cell Number</label>
                                <input type="text" id="cell" name="cell"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md"
                                    value="<?php echo esc_attr($is_existing_customer ? $customer->cell : ''); ?>">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-medium mb-2">Address</label>
                                <input type="text" id="address" name="address"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md"
                                    value="<?php echo esc_attr($is_existing_customer ? $customer->address : ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    // Customer accordion toggle
                    const accordionToggle = document.querySelector('.customer-accordion-toggle');
                    const accordionContent = document.querySelector('.customer-details-content');

                    accordionToggle.addEventListener('click', function () {
                        accordionContent.classList.toggle('hidden');
                    });

                    // Customer selection handling
                    const customerSelect = document.getElementById('customer-select');
                    const custIdInput = document.getElementById('cust_id');
                    const nameInput = document.getElementById('customer_name');
                    const surnameInput = document.getElementById('surname');
                    const cellInput = document.getElementById('cell');
                    const addressInput = document.getElementById('address');

                    customerSelect.addEventListener('change', function () {
                        const selectedOption = this.options[this.selectedIndex];

                        if (this.value === 'new') {
                            // Clear all fields for new customer
                            nameInput.value = '';
                            surnameInput.value = '';
                            cellInput.value = '';
                            addressInput.value = '';
                            custIdInput.value = '0';
                        } else if (this.value) {
                            // Populate only the specified fields
                            nameInput.value = selectedOption.getAttribute('data-name') || '';
                            surnameInput.value = selectedOption.getAttribute('data-surname') || '';
                            cellInput.value = selectedOption.getAttribute('data-cell') || '';
                            addressInput.value = selectedOption.getAttribute('data-address') || '';
                            custIdInput.value = this.value;
                        }
                    });
                });
            </script>

            <!-- Item Section -->
            <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-700 mb-3">Item</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Destination Country</label>
                        <input type="text" id="destination_country" name="destination_country"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"
                            value="<?php echo esc_attr($is_existing_customer ? $customer->destination_country : ''); ?>">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Destination City</label>
                        <input type="text" id="destination_city" name="destination_city"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"
                            value="<?php echo esc_attr($is_existing_customer ? $customer->destination_city : ''); ?>">
                    </div>
                </div>
            </div>


            <!-- Item Section -->
            <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-700 mb-3">Item</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                    <?php foreach ([
                        'item_length' => 'Length (cm)',
                        'item_width' => 'Width (cm)',
                        'item_height' => 'Height (cm)',
                        'total_volume' => 'Total Volume'
                    ] as $field => $label): ?>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2"><?php echo $label; ?></label>
                        <input type="number" step="0.01" name="<?php echo esc_attr($field); ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"
                            value="<?php echo esc_attr($waybill->$field ?? ''); ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <?php foreach ([
                        'total_mass_kg' => 'Total Mass (Kg)',
                        'unit_volume' => 'Unit Volume (m³)',
                        'unit_mass' => 'Unit Mass'
                    ] as $field => $label): ?>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2"><?php echo $label; ?></label>
                        <input type="number" step="0.01" name="<?php echo esc_attr($field); ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"
                            value="<?php echo esc_attr($waybill->$field ?? ''); ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Charge Basis Section -->
            <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-700 mb-3">Charge Basis</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Charge Basis</label>
                        <select name="charge_basis" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            <?php foreach (['MASS', 'VOLUME', 'BOTH'] as $option): ?>
                            <option value="<?php echo esc_attr($option); ?>"
                                <?php echo isset($waybill->charge_basis) && $waybill->charge_basis === $option ? 'selected' : ''; ?>>
                                <?php echo esc_html($option); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Mass Charge (R)</label>
                        <input type="number" step="0.01" name="mass_charge"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"
                            value="<?php echo esc_attr($waybill->mass_charge ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Volume Charge (R)</label>
                        <input type="number" step="0.01" name="volume_charge"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"
                            value="<?php echo esc_attr($waybill->volume_charge ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end space-x-4">
                <a href="<?php echo admin_url('admin.php?page=08600-Waybill-list'); ?>"
                    class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400">Cancel</a>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    <?php echo $is_edit_mode ? 'Update' : 'Save'; ?>
                </button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Volume auto-calc (existing code)
            const lengthInput = document.querySelector('input[name="item_length"]');
            const widthInput = document.querySelector('input[name="item_width"]');
            const heightInput = document.querySelector('input[name="item_height"]');
            const totalVolumeInput = document.querySelector('input[name="total_volume"]');

            function calculateVolume() {
                if (lengthInput.value && widthInput.value && heightInput.value) {
                    const length = parseFloat(lengthInput.value) / 100;
                    const width = parseFloat(widthInput.value) / 100;
                    const height = parseFloat(heightInput.value) / 100;
                    const volume = length * width * height;
                    totalVolumeInput.value = volume.toFixed(2);
                }
            }

            [lengthInput, widthInput, heightInput].forEach(input => {
                input.addEventListener('change', calculateVolume);
            });

            // Customer selection handling
            const customerSelect = document.getElementById('customer-select');
            const customerFormFields = document.getElementById('customer-form-fields');
            const customerNameInput = document.querySelector('input[name="customer_name"]');
            const countryInput = document.querySelector('input[name="destination_country"]');
            const cityInput = document.querySelector('input[name="destination_city"]');

            const surnameInput = document.querySelector('input[name="surname"]');
            const cellInput = document.querySelector('input[name="cell"]');
            const custIdInput = document.getElementById('cust_id');
            // Define your customers data in JavaScript
            const customers = {
                <
                ? php foreach($customers as $key => $data) : ? >
                    '<?php echo esc_js($key); ?>' : {
                        customer_name: '<?php echo esc_js($data['
                        customer_name ']); ?>',
                        customer_surname: '<?php echo esc_js($data['
                        surname ']); ?>',
                        destination_country: '<?php echo esc_js($data['
                        destination_country ']); ?>',
                        destination_city: '<?php echo esc_js($data['
                        destination_city ']); ?>'
                    },
                <
                ? php endforeach; ? >
            };

            function handleCustomerSelection() {
                const selectedCustomer = customerSelect.value;

                if (selectedCustomer === '__new__') {
                    // Clear fields for new customer
                    customerNameInput.value = '';
                    countryInput.value = '';
                    surnameInput.value = '';
                    cityInput.value = '';
                } else if (selectedCustomer && customers[selectedCustomer]) {
                    // Populate fields with selected customer data
                    const customer = customers[selectedCustomer];
                    customerNameInput.value = customer.customer_name;
                    countryInput.value = customer.destination_country;
                    surnameInput.value = customer.destination_country;
                    cityInput.value = customer.destination_city;
                }
            }

            // Add event listener
            customerSelect.addEventListener('change', handleCustomerSelection);

            // Initialize with current selection
            handleCustomerSelection();
        });
    </script>
</body>