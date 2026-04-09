<?php
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

// Determine client type based on company_name
$is_business = ! empty($waybill['company_name']) && $waybill['company_name'] !== '1ndividual';

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
    wp_localize_script('kitscript', 'EditWaybillData', [
        'customers' => $customers,
    ]);
}

// Debug: Check what's in the waybill data
echo "<!-- DEBUG: waybill data structure -->";
echo "<!-- DEBUG: miscellaneous = " . print_r($waybill['miscellaneous'], true) . " -->";
if (isset($waybill['miscellaneous']['others'])) {
    echo "<!-- DEBUG: others = " . print_r($waybill['miscellaneous']['others'], true) . " -->";
}
$smalling_enabled = true;

// Get driver name - check if it's in waybill data, otherwise get from delivery
$driver_name = 'N/A';
if (!empty($waybill['truck_driver']) && is_numeric($waybill['truck_driver'])) {
    global $wpdb;
    $drivers_table = $wpdb->prefix . 'kit_drivers';
    $driver = $wpdb->get_var($wpdb->prepare(
        "SELECT name FROM $drivers_table WHERE id = %d LIMIT 1",
        intval($waybill['truck_driver'])
    ));
    if ($driver) {
        $driver_name = $driver;
    }
} elseif (!empty($waybill['delivery_id'])) {
    // Try to get driver from delivery
    global $wpdb;
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

?>

<style>
    /* Fix WordPress footer floating/overlapping issue */
    #wpfooter {
        position: relative !important;
        margin-top: 40px !important;
        padding-top: 20px !important;
    }

    /* Ensure content has enough bottom spacing */
    .max-w-6xl {
        padding-bottom: 100px !important;
    }
</style>

<div class="max-w-6xl mx-auto p-6 bg-white rounded-lg shadow-md">
    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="edit-waybill-form">
        <input type="hidden" name="action" value="update_waybill_action">
        <input type="hidden" name="waybill_id" value="<?php echo esc_attr($waybill_id) ?>">
        <input type="hidden" name="waybill_no" value="<?php echo esc_attr($waybill['waybill_no']) ?>">
        <input type="hidden" name="cust_id" value="<?php echo esc_attr($waybill['customer_id']) ?>">
        <input type="hidden" name="direction_id" value="<?php echo esc_attr($waybill['direction_id']) ?>">
        <input type="hidden" name="current_rate" value="<?php echo esc_attr($waybill['miscellaneous']['others']['mass_rate'] ?? '') ?>">

        <?php wp_nonce_field('update_waybill_nonce'); ?>

        <div class="space-y-6">
            <!-- Header Section -->
            <div class="flex justify-between items-center mb-8 border-b pb-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Editing Waybill
                        #<?php echo esc_html($waybill['waybill_no']) ?></h1>
                    <div class="flex items-center mt-2">
                        <span
                            class="px-3 py-1 rounded-full text-xs font-medium <?php echo ($waybill['approval'] ?? 'pending') === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800' ?>">
                            <?php echo ucfirst($waybill['approval'] ?? 'pending') ?> Approval
                        </span>
                        <span class="ml-2 text-xs text-gray-500">
                            <?php
                            $approval    = $waybill['approval'] ?? 'pending';
                            $action_text = $approval === 'pending' ? 'Pending' : ($approval === 'rejected' ? 'Rejected' : ($approval === 'completed' ? 'Completed' : 'Approved'));
                            echo $action_text . ' By: ' . esc_html($waybill['approved_by_username'] ?? 'N/A');
                            ?>
                        </span>
                        <span class="ml-2 text-xs text-gray-500">
                            Last Updated: <?php echo ! empty($waybill['last_updated_at']) ? date('M j, Y', strtotime($waybill['last_updated_at'])) : 'N/A' ?>
                        </span>
                    </div>
                </div>
                <div class="flex space-x-3">
                    <a href="?page=08600-Waybill-view&waybill_id=<?php echo $waybill_id ?>"
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Cancel Editing
                    </a>
                    <?php echo KIT_Commons::renderButton('Save Changes', 'primary', 'lg', ['type' => 'submit']); ?>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <?php echo KIT_Commons::prettyHeading([
                        'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
                        'words' => 'Delivery Details',
                        'classes' => 'mb-6'
                    ]); ?>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Dispatch Date:</label>
                            <span class="font-medium"><?php echo esc_html(date('d-m-Y', strtotime($waybill['dispatch_date'] ?? ''))) ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Delivery:</label>
                            <?php if (!empty($waybill['delivery_reference']) && !empty($waybill['delivery_id'])): ?>
                                <a
                                    href="?page=view-deliveries&delivery_id=<?php echo urlencode($waybill['delivery_id']); ?>"
                                    class="font-medium text-blue-600 hover:underline"
                                    target="_blank"
                                    rel="noopener">
                                    <?php echo esc_html($waybill['delivery_reference']); ?>
                                </a>
                            <?php else: ?>
                                <span class="font-medium"><?php echo esc_html($waybill['delivery_reference'] ?? 'N/A') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Route:</label>
                            <span class="font-medium">
                                <?php
                                // Show route: [Origin Country] to [Destination Country]
                                // Get country IDs from miscellaneous['others'] (same as Route Information section)
                                $origin_country_id = 0;
                                $destination_country_id = 0;

                                if (isset($waybill['miscellaneous']) && is_array($waybill['miscellaneous'])) {
                                    $misc = maybe_unserialize($waybill['miscellaneous']);
                                    if (is_array($misc) && isset($misc['others'])) {
                                        $origin_country_id = intval($misc['others']['origin_country_id'] ?? 0);
                                        $destination_country_id = intval($misc['others']['destination_country_id'] ?? 0);
                                    }
                                }

                                // Get country names using KIT_Routes (same as viewWaybill.php)
                                $origin_country = 'N/A';
                                $destination_country = 'N/A';

                                if ($origin_country_id > 0 && class_exists('KIT_Routes')) {
                                    $origin_country = KIT_Routes::get_country_name_by_id($origin_country_id);
                                }

                                if ($destination_country_id > 0 && class_exists('KIT_Routes')) {
                                    $destination_country = KIT_Routes::get_country_name_by_id($destination_country_id);
                                }

                                echo esc_html($origin_country) . ' → ' . esc_html($destination_country);
                                ?>
                            </span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Driver:</label>
                            <span class="font-medium"><?php echo esc_html($driver_name) ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Truck:</label>
                            <span class="font-medium"><?php echo esc_html($waybill['truck_number'] ?? 'N/A') ?></span>
                        </div>
                    </div>
                </div>
                <div class="w-full md:col-span-2 ps bg-gray-50 p-4 rounded-lg">
                    <?php
                    // Get waybill description - priority: 1) Direct 'description' column, 2) miscellaneous['others']['waybill_description']
                    $waybill_description_value = '';
                    if (! empty($waybill['description'])) {
                        $waybill_description_value = $waybill['description'];
                    } elseif (! empty($waybill['miscellaneous']['others']['waybill_description'])) {
                        $waybill_description_value = $waybill['miscellaneous']['others']['waybill_description'];
                    }

                    // Pretty heading for Waybill Description
                    echo KIT_Commons::prettyHeading([
                        'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
                        'words' => 'Waybill Description',
                        'classes' => 'mb-6'
                    ]);

                    echo KIT_Commons::TextAreaField([
                        'label' => '',
                        'name'  => 'waybill_description',
                        'id'    => 'waybill_description',
                        'type'  => 'textarea',
                        'value' => $waybill_description_value,
                        'height' => '100',
                    ]);
                    ?>

                    <!-- Professional minimalistic waybill info display -->
                    <?php
                    $waybill_info_options = [
                        'show_amount' => true,
                        'class' => 'mt-4',
                        'enable_js_updates' => true  // Enable JS updates when mass/dimensions change
                    ];
                    require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/waybillInfoDisplay.php';
                    ?>
                </div>
            </div>
        </div>
        <!-- Waybill Information Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
            <!-- Customer Details -->
            <div class="bg-gray-50 p-4 rounded-lg">
                <div class="flex items-center justify-between mb-4">
                    <?php echo KIT_Commons::prettyHeading([
                        'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
                        'words' => 'Customer Details',
                        'classes' => 'mb-0'
                    ]); ?>

                </div>

                <?php
                // Get city name
                $city_name = '';
                if (!empty($waybill['city_id']) && class_exists('KIT_Routes')) {
                    $city_name = KIT_Routes::get_city_name_by_id($waybill['city_id']);
                }
                if (empty($city_name)) {
                    $city_name = 'N/A';
                }
                ?>

                <!-- Customer Info Display (Collapsed View) -->
                <div id="customer-info-display-edit">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Name:</label>
                            <span class="font-medium"><?php echo esc_html($waybill['customer_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Surname:</label>
                            <span class="font-medium"><?php echo esc_html($waybill['customer_surname'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Email:</label>
                            <span class="font-medium"><?php echo esc_html($waybill['email_address'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Contact:</label>
                            <span class="font-medium"><?php echo esc_html($waybill['cell'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">City:</label>
                            <span class="font-medium"><?php echo esc_html($city_name); ?></span>
                        </div>
                    </div>
                </div>
                <?php echo KIT_Commons::renderButton('Edit Customer', 'ghost', 'lg', ['type' => 'button', 'id' => 'toggle-customer-form-edit', 'classes' => 'px-4 py-2 text-sm mt-6 font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors flex items-center gap-2', 'contentId' => 'toggle-customer-form-text-edit', 'iconId' => 'toggle-customer-form-icon-edit', 'iconClasses' => 'transition-transform', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />', 'iconPosition' => 'right']); ?>

                <!-- Customer Form (Hidden by default) -->
                <div id="customer-form-edit" class="hidden">
                    <!-- Customer Search Section -->
                    <div class="mb-4">
                        <label for="customer-search-edit" class="block text-sm font-semibold text-gray-700 mb-2">Select Customer</label>
                        <div class="relative">
                            <input
                                type="text"
                                id="customer-search-edit"
                                name="customer_search"
                                placeholder="Type to search customers..."
                                class="block w-full rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition px-4 py-2 bg-white text-gray-800"
                                autocomplete="off">
                            <input type="hidden" id="customer-select-edit" name="customer_select" value="<?php echo esc_attr(! empty($waybill['customer_id']) ? $waybill['customer_id'] : 'new') ?>">

                            <div id="customer-results-edit" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg hidden max-h-60 overflow-y-auto">
                                <!-- Results will be populated by JavaScript -->
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <?php echo KIT_Commons::renderButton('+ Add New Customer', 'ghost-primary', 'sm', ['type' => 'button', 'id' => 'add-new-customer-btn-edit', 'classes' => 'px-3 py-1 text-sm bg-blue-100 text-blue-700 rounded-full hover:bg-blue-200 transition']); ?>
                            <?php echo KIT_Commons::renderButton('Recent Customers', 'ghost', 'sm', ['type' => 'button', 'id' => 'recent-customers-btn-edit', 'classes' => 'px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded-full hover:bg-gray-200 transition']); ?>
                        </div>
                    </div>

                    <!-- Client Type -->
                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Is the client a business or an individual?</label>
                        <div class="flex gap-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="client_type" id="client_type_business_edit" value="business" class="client-type-radio-edit" <?php echo $is_business ? 'checked' : '' ?>>
                                <span class="ml-2">Business</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="client_type" id="client_type_individual_edit" value="individual" class="client-type-radio-edit" <?php echo ! $is_business ? 'checked' : '' ?>>
                                <span class="ml-2">Individual</span>
                            </label>
                        </div>
                    </div>

                    <!-- Customer Details Form -->
                    <div class="rounded-xl border border-gray-200 bg-gradient-to-br from-blue-50 to-white shadow-inner">
                        <div class="grid grid-cols-2 gap-3">
                            <div id="company_name_wrapper_edit">
                                <?php echo KIT_Commons::Linput([
                                    'label'   => 'Company Name',
                                    'name'    => 'company_name',
                                    'id'      => 'company_name_edit',
                                    'type'    => 'text',
                                    'value'   => esc_attr($waybill['company_name'] ?? ''),
                                    'class'   => 'w-full rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 px-3 py-2 text-gray-800 bg-white transition',
                                    'special' => 'autocomplete="organization"',
                                ]); ?>
                            </div>
                            <div>
                                <?php echo KIT_Commons::Linput([
                                    'label'   => 'Customer Name',
                                    'name'    => 'customer_name',
                                    'id'      => 'customer_name_edit',
                                    'type'    => 'text',
                                    'value'   => esc_attr($waybill['customer_name'] ?? ''),
                                    'class'   => 'w-full rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 px-3 py-2 text-gray-800 bg-white transition',
                                    'special' => 'autocomplete="given-name"',
                                ]); ?>
                            </div>
                            <div>
                                <?php echo KIT_Commons::Linput([
                                    'label'   => 'Customer Surname',
                                    'name'    => 'customer_surname',
                                    'id'      => 'customer_surname_edit',
                                    'type'    => 'text',
                                    'value'   => esc_attr($waybill['customer_surname'] ?? ''),
                                    'class'   => 'w-full rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 px-3 py-2 text-gray-800 bg-white transition',
                                    'special' => 'autocomplete="family-name"',
                                ]); ?>
                            </div>
                            <div>
                                <?php echo KIT_Commons::Linput([
                                    'label'   => 'Telephone',
                                    'name'    => 'telephone',
                                    'id'      => 'telephone_edit',
                                    'type'    => 'tel',
                                    'value'   => esc_attr($waybill['cell'] ?? ''),
                                    'class'   => 'w-full rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 px-3 py-2 text-gray-800 bg-white transition',
                                    'special' => 'autocomplete="tel"',
                                ]); ?>
                            </div>
                            <div>
                                <?php echo KIT_Commons::Linput([
                                    'label'   => 'Cell',
                                    'name'    => 'cell',
                                    'id'      => 'cell_edit',
                                    'type'    => 'tel',
                                    'value'   => esc_attr($waybill['cell'] ?? ''),
                                    'class'   => 'w-full rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 px-3 py-2 text-gray-800 bg-white transition',
                                    'special' => 'autocomplete="tel"',
                                ]); ?>
                            </div>
                            <div>
                                <?php echo KIT_Commons::Linput([
                                    'label'   => 'Email',
                                    'name'    => 'email_address',
                                    'id'      => 'email_address_edit',
                                    'type'    => 'email',
                                    'value'   => esc_attr($waybill['email_address'] ?? ''),
                                    'class'   => 'w-full rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 px-3 py-2 text-gray-800 bg-white transition',
                                    'special' => 'autocomplete="email"',
                                ]); ?>
                            </div>
                            <div class="col-span-2">
                                <?php echo KIT_Commons::Linput([
                                    'label'   => 'Address',
                                    'name'    => 'address',
                                    'id'      => 'address_edit',
                                    'type'    => 'text',
                                    'value'   => esc_attr($waybill['address'] ?? ''),
                                    'class'   => 'w-full rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 px-3 py-2 text-gray-800 bg-white transition',
                                    'special' => 'autocomplete="street-address"',
                                ]); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End Customer Details -->

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const toggleBtn = document.getElementById('toggle-customer-form-edit');
                    const toggleText = document.getElementById('toggle-customer-form-text-edit');
                    const toggleIcon = document.getElementById('toggle-customer-form-icon-edit');
                    const customerForm = document.getElementById('customer-form-edit');
                    const customerDisplay = document.getElementById('customer-info-display-edit');

                    if (toggleBtn && customerForm && customerDisplay) {
                        toggleBtn.addEventListener('click', function() {
                            const isHidden = customerForm.classList.contains('hidden');

                            if (isHidden) {
                                customerForm.classList.remove('hidden');
                                customerDisplay.classList.add('hidden');
                                toggleText.textContent = 'Hide Form';
                                toggleIcon.style.transform = 'rotate(180deg)';
                            } else {
                                customerForm.classList.add('hidden');
                                customerDisplay.classList.remove('hidden');
                                toggleText.textContent = 'Edit Customer';
                                toggleIcon.style.transform = 'rotate(0deg)';
                            }
                        });
                    }
                });
            </script>
            <!-- Shipment Details (Read-only) -->
            <div class="bg-gray-50 p-4 rounded-lg">
                <?php echo KIT_Commons::prettyHeading([
                    'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
                    'words' => 'Cost Details',
                    'classes' => 'mb-6'
                ]); ?>
                <div class="space-y-3">
                    <div class="flex items-center">
                        <label class="<?php echo KIT_Commons::labelClass() ?>"></label>
                        <span class="text-xs text-gray-500 italic">
                            Waybill Amount + Misc Total
                        </span>
                    </div>
                    <div class="addCharges">
                        <?php
                        $optionChoice = 2;
                        require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/additionCharges.php'; ?>
                    </div>
                    <div class="items-center">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Preferred Charge Basis</label>
                        <div class="flex flex-col gap-3">
                            <?php
                            $charge_basis_options = [
                                'mass'   => 'Mass',
                                'volume' => 'Volume',
                                'auto'   => 'Auto',
                            ];
                            // Use DB value: waybill column first, then miscellaneous.others.used_charge_basis; normalize to lowercase
                            $raw_basis = $waybill['charge_basis'] ?? '';
                            if (($raw_basis === '' || $raw_basis === null) && !empty($waybill['miscellaneous']['others']['used_charge_basis'])) {
                                $raw_basis = $waybill['miscellaneous']['others']['used_charge_basis'];
                            }
                            $current_charge_basis = strtolower(trim((string) $raw_basis));
                            if (!array_key_exists($current_charge_basis, $charge_basis_options)) {
                                $current_charge_basis = 'auto';
                            }

                            foreach ($charge_basis_options as $value => $label):
                                $input_id = 'charge_basis_' . $value;
                                $checked = ($current_charge_basis === $value) ? 'checked' : '';
                            ?>
                                <div class="flex">
                                    <input
                                        type="radio"
                                        name="charge_basis"
                                        id="<?php echo esc_attr($input_id); ?>"
                                        value="<?php echo esc_attr($value); ?>"
                                        class="sr-only peer charge-basis-radio"
                                        <?php echo $checked; ?>>
                                    <label
                                        for="<?php echo esc_attr($input_id); ?>"
                                        class="block w-full p-4 rounded-lg border-2 border-gray-300 cursor-pointer text-center font-medium text-sm transition-all duration-200 hover:shadow-md hover:border-gray-400 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:shadow-lg peer-checked:text-blue-700">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div id="totalOverride">
                        <!-- total override, client wants to override the total and manually enter the total -->
                        <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/totalOverride.php'; ?>
                    </div>
                </div>
            </div>
            <!-- Route Information (Editable) -->
            <div class="bg-gray-50 p-4 rounded-lg">
                <?php echo KIT_Commons::prettyHeading([
                    'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
                    'words' => 'Route Information',
                    'classes' => 'mb-6'
                ]); ?>
                <div class="space-y-3">
                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsOrigin.php'; ?>
                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsDestination.php'; ?>
                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsRoute.php'; ?>
                </div>
            </div>
            <!-- End grid-cols-3 main details -->
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div class="bg-slate-100 p-6 rounded">
                <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/weight.php'; ?>
            </div>
            <div class="bg-slate-100 p-6 rounded">
                <?php
                // Pass waybill data to dimensions component
                $dimensions_waybill = $waybill;
                require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/dimensions.php';
                ?>
                Below is what total volune is saved on the DB, which doesnt match above
                <?php
                $waybill_info_options = [
                    'show_amount' => true,
                    'class' => 'mt-4',
                    'exclude' => ['waybill', 'invoice', 'tracking', 'delivery']
                ];
                echo "Database volume_charge:" . $waybill['volume_charge'];

                //require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/waybillInfoDisplay.php';
                ?>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4 mt-4 mb-16">
            <?php
            echo '<div class="bg-slate-100 p-6 rounded">';
            echo KIT_Commons::dynamicItemsControl([
                'container_id'     => 'custom-waybill-items',
                'button_id'        => 'add-waybill-item',
                'group_name'       => 'custom_items',
                'existing_items'   => isset($waybill['items']) && is_array($waybill['items']) ? $waybill['items'] : [],
                'input_class'      => 'border border-gray-300 rounded px-3 py-2 bg-white',
                'remove_btn_class' => 'bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600',
                'add_btn_class'    => 'bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700',
                'specialClass'     => '!text-[10px]',
                'item_type'        => 'waybill',
                'title'            => 'Parcels',
                'description'      => 'Add parcels being shipped with details and pricing',
                'subtotal_id'      => 'waybill-subtotal',
                'currency_symbol'  => KIT_Commons::currency(),
                'show_invoices'    => true,
                'waybill_no'       => $waybill['waybill_no'] ?? 'TEMP-' . uniqid(),
            ]);
            echo '</div>';
            echo '<div class="bg-slate-100 p-6 rounded">';
            $misc = [];

            if (! empty($waybill['miscellaneous'])) {
                $misc = $waybill['miscellaneous'] ?? [];
            }
            $misc_items = [];

            // Check if misc data exists and has items
            if (! empty($misc) && is_array($misc) && isset($misc['misc_items']) && is_array($misc['misc_items'])) {
                $misc_total = floatval($misc['misc_total'] ?? 0);

                foreach ($misc['misc_items'] as $item) {
                    $name     = isset($item['misc_item']) ? sanitize_text_field($item['misc_item']) : '';
                    $price    = isset($item['misc_price']) ? floatval($item['misc_price']) : 0;
                    $quantity = isset($item['misc_quantity']) ? intval($item['misc_quantity']) : 1;
                    $subtotal = $price * $quantity;

                    $misc_items[] = [
                        'misc_item'     => sanitize_text_field($name),
                        'misc_price'    => $price,
                        'misc_quantity' => $quantity,
                        'misc_subtotal' => $subtotal,
                    ];
                }
            } else {
                $misc_total = 0;
            }
            ?>
            
            <!-- ✅ Hidden flag: misc section was rendered (for UPDATE mode logic) -->
            <input type="hidden" name="misc_section_rendered" value="1">

            <?php echo KIT_Commons::dynamicItemsControl([
                'container_id'    => 'misc-items',
                'button_id'       => 'add-misc-item',
                'group_name'      => 'misc',
                'input_class'     => 'border border-gray-300 rounded px-3 py-2 bg-white',
                'existing_items'  => $misc_items,
                'item_type'       => 'misc',
                'title'           => 'Miscellaneous Items',
                'description'     => 'Add miscellaneous items with details and pricing',
                'subtotal_id'     => 'misc-total',
                'currency_symbol' => KIT_Commons::currency(),
                'show_subtotal'   => true,
                'show_invoices'   => false,
            ]);
            echo '</div>';
            echo '</div>';
            ?>
            <?php echo KIT_Commons::renderButton('Save Changes', 'primary', 'lg', ['type' => 'submit']); ?>
        </div>
        
        <!-- ✅ CLEANUP SCRIPT: Remove empty misc/waybill items before form submission -->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const form = document.getElementById('edit-waybill-form');
                if (!form) return;
                
                form.addEventListener('submit', function(e) {
                    // Clean up empty misc items
                    const miscContainer = document.getElementById('misc-items');
                    if (miscContainer) {
                        const miscRows = miscContainer.querySelectorAll('input[name*="[misc_item]"]');
                        miscRows.forEach(input => {
                            const row = input.closest('tr.dynamic-item');
                            if (row && !input.value.trim()) {
                                // Item name is empty = deleted row, remove it
                                const invoiceRow = row.nextElementSibling;
                                if (invoiceRow && invoiceRow.classList.contains('dynamic-item-invoice')) {
                                    invoiceRow.remove();
                                }
                                row.remove();
                            }
                        });
                    }
                    
                    // Also clean up custom waybill items
                    const customItemsContainer = document.getElementById('custom-waybill-items');
                    if (customItemsContainer) {
                        const customRows = customItemsContainer.querySelectorAll('input[name*="[item_name]"]');
                        customRows.forEach(input => {
                            const row = input.closest('tr.dynamic-item');
                            if (row && !input.value.trim()) {
                                const invoiceRow = row.nextElementSibling;
                                if (invoiceRow && invoiceRow.classList.contains('dynamic-item-invoice')) {
                                    invoiceRow.remove();
                                }
                                row.remove();
                            }
                        });
                    }
                });
            });
        </script>
    </form>
</div>
<!-- End main container -->