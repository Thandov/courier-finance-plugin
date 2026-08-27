<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Edit waybill template 1 — current card layout.
 */
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

    /* WP admin caps select max-width at 25rem; fill Route Information column */
    .kit-route-info-fields select {
        max-width: none !important;
        width: 100%;
    }
</style>

<div class="mx-auto p-3 md:p-6 space-y-4 md:space-y-6 bg-white rounded-lg shadow-md">
    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>">
        <input type="hidden" name="action" value="update_waybill_action">
        <input type="hidden" name="waybill_id" value="<?php echo esc_attr($waybill_id) ?>">
        <input type="hidden" name="waybill_no" value="<?php echo esc_attr($waybill['waybill_no']) ?>">
        <input type="hidden" name="cust_id" value="<?php echo esc_attr($waybill['customer_id']) ?>">
        <input type="hidden" name="direction_id" value="<?php echo esc_attr($waybill['direction_id']) ?>">
        <?php
        $edit_hidden_mass_rate = floatval($waybill['miscellaneous']['others']['mass_rate'] ?? 0);
        if ($edit_hidden_mass_rate <= 0) {
            $edit_mass_kg = floatval($waybill['total_mass_kg'] ?? 0);
            $edit_mass_charge = floatval($waybill['mass_charge'] ?? 0);
            if ($edit_mass_kg > 0 && $edit_mass_charge > 0) {
                $edit_hidden_mass_rate = $edit_mass_charge / $edit_mass_kg;
            }
        }
        ?>
        <input type="hidden" name="current_rate" value="<?php echo esc_attr($edit_hidden_mass_rate > 0 ? number_format($edit_hidden_mass_rate, 2, '.', '') : ''); ?>">
        <input type="hidden" name="kit_waybill_misc_ui" value="1">

        <?php wp_nonce_field('update_waybill_nonce'); ?>

        <div class="space-y-6">
            <!-- Header Section -->
            <div class="flex justify-between items-center mb-8 border-b pb-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Editing Waybill
                        #<?php echo esc_html($waybill['waybill_no']) ?></h1>
                    <div class="flex items-center mt-2">
                        <?php
                        $approval_status = (string) ($waybill['approval'] ?? 'pending');
                        $badge_class = match ($approval_status) {
                            'pending'   => 'bg-yellow-100 text-yellow-800',
                            'rejected'  => 'bg-red-100 text-red-800',
                            default     => 'bg-green-100 text-green-800',
                        };
                        $approver = trim((string) ($waybill['approved_by_username'] ?? ''));
                        ?>
                        <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo esc_attr($badge_class); ?>">
                            <?php echo esc_html(ucfirst($approval_status)); ?>
                        </span>
                        <span class="ml-2 text-xs text-gray-500">
                        <?php
                        if (in_array($approval_status, ['approved', 'completed'], true) && $approver !== '') {
                            echo esc_html('Approved by: ' . $approver);
                        } elseif ($approval_status === 'rejected' && $approver !== '') {
                            echo esc_html('Rejected by: ' . $approver);
                        } elseif ($approval_status === 'pending') {
                            echo 'Awaiting approval';
                        } elseif ($approver !== '') {
                            echo esc_html(ucfirst($approval_status) . ' by: ' . $approver);
                        }
                        ?>
                        </span>
                        <span class="ml-2 text-xs text-gray-500">
                            Last Updated: <?php echo ! empty($waybill['last_updated_at']) ? date('M j, Y', strtotime($waybill['last_updated_at'])) : 'N/A' ?>
                        </span>
                    </div>
                </div>
                <div class="flex space-x-3">
                    <!-- RenderButton component for cancel editing and save changes -->
                    <?php echo KIT_Commons::renderButton('Cancel Editing', 'secondary', 'lg', ['type' => 'button', 'href' => '?page=08600-Waybill-view&waybill_id=' . $waybill_id, 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />', 'iconPosition' => 'left']); ?>
                    <?php echo KIT_Commons::renderButton('Save Changes', 'primary', 'lg', ['type' => 'submit']); ?>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <?php echo KIT_Commons::prettyHeading([
                        'icon' => '<path d="M9 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM19 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0z" /><path d="M13 16V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10h1m9-1H9m4-8h2.6a1 1 0 0 1 .7.3l3.4 3.4a1 1 0 0 1 .3.7V16h-1" />',
                        'words' => 'Delivery Details',
                        'classes' => 'mb-6'
                    ]); ?>
                    <?php
                    $edit_delivery_ref = trim((string) ($waybill['delivery_reference'] ?? ''));
                    $edit_is_pending_delivery = $edit_delivery_ref !== '' && strcasecmp($edit_delivery_ref, 'pending') === 0;
                    $edit_is_warehouse = !empty($waybill['warehouse']) && (int) $waybill['warehouse'] === 1;
                    $edit_effectively_warehouse = $edit_is_warehouse || $edit_is_pending_delivery;
                    $edit_truck = trim((string) ($waybill['truck_number'] ?? ''));
                    ?>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Dispatch Date:</label>
                            <span class="font-medium"><?php
                            if ($edit_effectively_warehouse) {
                                echo '—';
                            } else {
                                $d = $waybill['dispatch_date'] ?? '';
                                echo esc_html($d ? date('d-m-Y', strtotime($d)) : 'Not set');
                            }
                            ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Delivery:</label>
                            <?php if ($edit_effectively_warehouse): ?>
                                <span class="font-medium">Warehouse</span>
                            <?php elseif ($edit_delivery_ref !== '' && !empty($waybill['delivery_id'])): ?>
                                <a
                                    href="?page=view-deliveries&delivery_id=<?php echo urlencode((string) $waybill['delivery_id']); ?>"
                                    class="font-medium text-blue-600 hover:underline"
                                    target="_blank"
                                    rel="noopener">
                                    <?php echo esc_html($edit_delivery_ref); ?>
                                </a>
                            <?php else: ?>
                                <span class="font-medium"><?php echo esc_html($edit_delivery_ref !== '' ? $edit_delivery_ref : 'Not assigned'); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Route:</label>
                            <span class="font-medium">
                                <?php
                                // Prefer direction-backed names from bonaWaybill, then misc IDs.
                                $origin_country = trim((string) ($waybill['origin_country'] ?? ''));
                                $destination_country = trim((string) ($waybill['destination_country'] ?? ''));
                                $origin_country_id = 0;
                                $destination_country_id = 0;

                                if (isset($waybill['miscellaneous']) && is_array($waybill['miscellaneous'])) {
                                    if (isset($waybill['miscellaneous']['others'])) {
                                        $origin_country_id      = intval($waybill['miscellaneous']['others']['origin_country_id'] ?? 0);
                                        $destination_country_id = intval($waybill['miscellaneous']['others']['destination_country_id'] ?? 0);
                                    }
                                }
                                if ($origin_country_id <= 0) {
                                    $origin_country_id = (int) ($waybill['origin_country_id'] ?? 0);
                                }
                                if ($destination_country_id <= 0) {
                                    $destination_country_id = (int) ($waybill['destination_country_id'] ?? 0);
                                }

                                if ($origin_country === '' && $origin_country_id > 0 && class_exists('KIT_Routes')) {
                                    $origin_country = (string) KIT_Routes::get_country_name_by_id($origin_country_id);
                                }
                                if ($destination_country === '' && $destination_country_id > 0 && class_exists('KIT_Routes')) {
                                    $destination_country = (string) KIT_Routes::get_country_name_by_id($destination_country_id);
                                }

                                if ($origin_country === '' && $destination_country === '') {
                                    echo 'Not set';
                                } else {
                                    echo esc_html(($origin_country !== '' ? $origin_country : 'Origin') . ' → ' . ($destination_country !== '' ? $destination_country : 'Destination'));
                                }
                                ?>
                            </span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Driver:</label>
                            <span class="font-medium"><?php
                            echo esc_html($edit_effectively_warehouse ? '—' : (($driver_name !== '' && $driver_name !== 'N/A') ? $driver_name : 'Not assigned'));
                            ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Truck:</label>
                            <span class="font-medium"><?php
                            echo esc_html($edit_effectively_warehouse ? '—' : ($edit_truck !== '' ? $edit_truck : 'Not assigned'));
                            ?></span>
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
                        'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /><path d="M8 13h8M8 17h5" />',
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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6 min-w-0">
            <!-- Customer Details -->
            <div class="bg-gray-50 p-4 rounded-lg min-w-0">
                <div class="flex items-center justify-between mb-4">
                    <?php echo KIT_Commons::prettyHeading([
                        'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
                        'words' => 'Customer Details',
                        'classes' => 'mb-0'
                    ]); ?>
                </div>

                <?php
                // Customer origin city (not waybill destination city_id).
                $customer_city_id = $waybill['customer_city_id'] ?? '';
                if ($customer_city_id === '' && !empty($waybill['customer_id'])) {
                    global $wpdb;
                    $customer_city_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT city_id FROM {$wpdb->prefix}kit_customers WHERE cust_id = %d LIMIT 1",
                        (int) $waybill['customer_id']
                    ));
                }
                $customer_city_id = ($customer_city_id !== null && $customer_city_id !== '') ? (int) $customer_city_id : '';

                // Get city name for display (customer city, not waybill destination).
                $city_name = '';
                if (!empty($customer_city_id) && class_exists('KIT_Routes')) {
                    $city_name = KIT_Routes::get_city_name_by_id($customer_city_id);
                }
                $kit_edit_empty = static function (string $value): string {
                    $v = trim($value);
                    if ($v === '' || strcasecmp($v, 'N/A') === 0 || strcasecmp($v, 'null') === 0 || $v === '-') {
                        return 'Not provided';
                    }
                    return $v;
                };
                $edit_contact = trim((string) ($waybill['cell'] ?? ''));
                if ($edit_contact === '') {
                    $edit_contact = trim((string) ($waybill['telephone'] ?? ''));
                }
                $edit_email = trim((string) ($waybill['email_address'] ?? ''));
                ?>

                <!-- Customer Info Display (Collapsed View) -->
                <div id="customer-info-display-edit">
                    <div class="grid grid-cols-2 gap-3">
                        <?php if ($is_business) : ?>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Company Name:</label>
                            <span class="font-medium"><?php echo esc_html($kit_edit_empty($edit_company_name)); ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">VAT Number:</label>
                            <span class="font-medium"><?php echo esc_html($kit_edit_empty((string) ($waybill['vat_number'] ?? ''))); ?></span>
                        </div>
                        <?php else : ?>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Name:</label>
                            <span class="font-medium"><?php echo esc_html($kit_edit_empty((string) ($waybill['customer_name'] ?? ''))); ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Surname:</label>
                            <span class="font-medium"><?php echo esc_html($kit_edit_empty((string) ($waybill['customer_surname'] ?? ''))); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Email:</label>
                            <span class="font-medium <?php echo $edit_email === '' ? 'text-gray-400' : ''; ?>"><?php echo esc_html($kit_edit_empty($edit_email)); ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">Contact:</label>
                            <span class="font-medium <?php echo $edit_contact === '' ? 'text-gray-400' : ''; ?>"><?php echo esc_html($kit_edit_empty($edit_contact)); ?></span>
                        </div>
                        <div class="flex flex-col">
                            <label class="<?php echo KIT_Commons::labelClass() ?>">City:</label>
                            <span class="font-medium"><?php echo esc_html($kit_edit_empty((string) $city_name)); ?></span>
                        </div>
                    </div>
                </div>
                <?php echo KIT_Commons::renderButton('Edit Customer', 'ghost', 'lg', ['type' => 'button', 'id' => 'toggle-customer-form-edit', 'classes' => 'px-4 py-2 text-sm mt-6 font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors flex items-center gap-2', 'contentId' => 'toggle-customer-form-text-edit', 'iconId' => 'toggle-customer-form-icon-edit', 'iconClasses' => 'transition-transform', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />', 'iconPosition' => 'right']); ?>

                <!-- Customer Form (Hidden by default) -->
                <div id="customer-form-edit" class="hidden min-w-0 mt-4">
                <?php
                $customer_for_form = [
                    'cust_id'          => $waybill['customer_id'] ?? '',
                    'company_id'       => $waybill['company_id'] ?? '',
                    'company_name'     => $waybill['company_name'] ?? '',
                    'name'             => $waybill['customer_name'] ?? '',
                    'customer_name'    => $waybill['customer_name'] ?? '',
                    'surname'          => $waybill['customer_surname'] ?? '',
                    'customer_surname' => $waybill['customer_surname'] ?? '',
                    'cell'             => $waybill['cell'] ?? '',
                    'email_address'    => $waybill['email_address'] ?? '',
                    'address'          => $waybill['address'] ?? '',
                    'vat_number'       => $waybill['vat_number'] ?? '',
                    'country_id'       => $waybill['country_id'] ?? '',
                    'city_id'          => $customer_city_id,
                ];
                if (is_array($waybill['customer'] ?? null)) {
                    $customer_for_form = array_merge($customer_for_form, $waybill['customer']);
                }
                theForm($customer_for_form, $waybill['customer_id'] ?? null);
                ?>
                </div>
            </div>
            <!-- End Customer Details -->

            <!-- Shipment Details (Read-only) -->
            <div class="bg-gray-50 p-4 rounded-lg">
                <?php echo KIT_Commons::prettyHeading([
                    'icon' => '<circle cx="12" cy="12" r="9" /><path d="M12 7v10M15 9.5c0-1.1-1.3-2-3-2s-3 .9-3 2 1.3 2 3 2 3 .9 3 2-1.3 2-3 2-3-.9-3-2" />',
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
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
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
            <div class="bg-gray-50 p-4 rounded-lg min-w-0">
                <?php echo KIT_Commons::prettyHeading([
                    'icon' => '<path d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7z" /><circle cx="12" cy="9" r="2.5" />',
                    'words' => 'Route Information',
                    'classes' => 'mb-6'
                ]); ?>
                <div class="space-y-3 min-w-0 kit-route-info-fields">
                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsOrigin.php'; ?>
                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsDestination.php'; ?>
                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsRoute.php'; ?>
                </div>
            </div>
            <!-- End grid-cols-3 main details -->
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const toggleBtn = document.getElementById('toggle-customer-form-edit');
                const toggleText = document.getElementById('toggle-customer-form-text-edit');
                const toggleIcon = document.getElementById('toggle-customer-form-icon-edit');
                const customerForm = document.getElementById('customer-form-edit');
                const customerDisplay = document.getElementById('customer-info-display-edit');

                // Hidden customer fields still participate in HTML5 validation (e.g. type=email).
                // Disable them when collapsed so Save is not blocked with no visible error.
                function setCustomerFormActive(enabled) {
                    if (!customerForm) return;
                    customerForm.querySelectorAll('input, select, textarea, button').forEach(function(el) {
                        if (el.id === 'toggle-customer-form-edit') {
                            return;
                        }
                        if (enabled) {
                            if (el.getAttribute('data-kit-customer-disabled') === '1') {
                                el.disabled = false;
                                el.removeAttribute('data-kit-customer-disabled');
                            }
                        } else if (!el.disabled) {
                            el.setAttribute('data-kit-customer-disabled', '1');
                            el.disabled = true;
                        }
                    });
                    customerForm.querySelectorAll('[required], [data-kit-was-required="1"]').forEach(function(el) {
                        if (enabled) {
                            if (el.getAttribute('data-kit-was-required') === '1' || el.hasAttribute('required')) {
                                el.setAttribute('required', 'required');
                                el.removeAttribute('data-kit-was-required');
                            }
                        } else if (el.hasAttribute('required')) {
                            el.setAttribute('data-kit-was-required', '1');
                            el.removeAttribute('required');
                        }
                    });
                }

                const editWaybillForm = customerForm ? customerForm.closest('form') : null;
                if (customerForm && customerForm.classList.contains('hidden')) {
                    setCustomerFormActive(false);
                }
                if (editWaybillForm && customerForm) {
                    editWaybillForm.addEventListener('submit', function() {
                        if (customerForm.classList.contains('hidden')) {
                            setCustomerFormActive(false);
                        }
                    });
                }

                if (toggleBtn && customerForm && customerDisplay) {
                    toggleBtn.addEventListener('click', function() {
                        const isHidden = customerForm.classList.contains('hidden');

                        if (isHidden) {
                            customerForm.classList.remove('hidden');
                            customerDisplay.classList.add('hidden');
                            setCustomerFormActive(true);
                            toggleText.textContent = 'Hide Form';
                            toggleIcon.style.transform = 'rotate(180deg)';
                        } else {
                            customerForm.classList.add('hidden');
                            customerDisplay.classList.remove('hidden');
                            setCustomerFormActive(false);
                            toggleText.textContent = 'Edit Customer';
                            toggleIcon.style.transform = 'rotate(0deg)';
                        }
                    });
                }
            });
        </script>

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
                'show_invoices'    => false,
                'waybill_no'       => $waybill['waybill_no'],
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
    </form>
</div>
<!-- End main container -->