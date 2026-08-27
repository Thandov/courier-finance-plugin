<?php
// File location: /includes/waybillmultiform.php
function kit_render_waybill_multiform($atts)
{
    $atts = shortcode_atts([
        'form_action' => '',
        'waybill_id' => '',
        'is_edit_mode' => '0',
        'waybill' => '{}',
        'customer_id' => '0',
        'delivery_id' => '0',
        'is_existing_customer' => '0',
        'ajaxform' => 'false',
        'customer' => '{}',
        'is_modal' => false,
    ], $atts);

    $form_action = esc_url($atts['form_action']);
    $waybill_id = esc_attr($atts['waybill_id']);
    $is_edit_mode = $atts['is_edit_mode'] === '1';
    $is_existing_customer = $atts['is_existing_customer'];
    $is_ajaxform = filter_var($atts['ajaxform'], FILTER_VALIDATE_BOOLEAN);
    $customer_id = esc_attr($atts['customer_id']);
    $delivery_id = esc_attr($atts['delivery_id']);
    $direction_id = KIT_Deliveries::getDirectionId($delivery_id);

    // Decode JSON values safely (handles string JSON or already-parsed arrays)
    $waybill_raw = $atts['waybill'] ?? '{}';
    if (is_array($waybill_raw)) {
        $waybill = $waybill_raw;
    } else {
        $waybill = json_decode(stripslashes((string)$waybill_raw), true);
    }
    
    // Load waybill items if in edit mode
    $waybill_items = [];
    if ($is_edit_mode && !empty($waybill['waybill_no'])) {
        global $wpdb;
        $items_table = $wpdb->prefix . 'kit_waybill_items';
        $items_sql = $wpdb->prepare("SELECT * FROM $items_table WHERE waybillno = %d", $waybill['waybill_no']);
        $waybill_items = $wpdb->get_results($items_sql, ARRAY_A);
    }
    
    // Make waybill_items available globally for included files
    global $waybill_items;
    
    // Decode customer data from base64 encoded JSON
    $customer_raw = $atts['customer'] ?? '{}';
    if (is_string($customer_raw)) {
        $customers_data = json_decode(base64_decode($customer_raw), true);
    } else {
        $customers_data = $customer_raw;
    }
    
    
    // Make customers data and customer_id available globally for included files
    global $customers_data, $customer_id;
    
    // Find the specific customer if customer_id is provided
    $selected_customer = null;
    if (!empty($customer_id) && is_array($customers_data)) {
        foreach ($customers_data as $cust) {
            if (isset($cust['cust_id']) && $cust['cust_id'] == $customer_id) {
                $selected_customer = (object) $cust; // Convert to object for compatibility
                break;
            }
        }
    }
    
    // Make customer data available globally for included files
    global $customer;
    $customer = $selected_customer;

    // Frontend (website template) vs backend: use portal URLs for redirects when on frontend.
    // IMPORTANT: This base URL is used inside JavaScript (location.href), NOT directly in HTML,
    // so we must NOT HTML-escape ampersands (&). Using esc_url() here encodes & to &amp; which
    // then produces URLs like "...page=08600-Waybill-view#038;waybill_id=332" when concatenated
    // in JS. Use esc_url_raw() for safety without HTML encoding.
    if (function_exists('kit_using_employee_portal') && kit_using_employee_portal()) {
        $waybill_view_redirect_base = esc_url_raw(kit_employee_portal_url('08600-Waybill-view')) . '&waybill_id=';
    } else {
        $waybill_view_redirect_base = esc_url_raw(admin_url('admin.php?page=08600-Waybill-view&waybill_id='));
    }

    $fake_filler_enabled = (bool) get_option('courier_fake_filler_enabled', 0);
    $fake_filler_json_url = trailingslashit(COURIER_FINANCE_PLUGIN_URL) . 'fakefiller.json';

    // Recent customer for \"Recent Customers\" button in customerSelection.js
    $last_waybill_customer_id = 0;
    if (class_exists('KIT_Dashboard') && method_exists('KIT_Dashboard', 'get_last_waybill_customer_id')) {
        $last_waybill_customer_id = (int) KIT_Dashboard::get_last_waybill_customer_id();
    }

    $kit_create_flat = empty($atts['is_modal']) && !empty($GLOBALS['kit_waybill_create_flat']);

    ob_start(); ?>
    
    <form method="POST" action="<?php echo esc_attr($form_action); ?>" class="<?php echo $kit_create_flat ? 'kit-t2 kit-create-t2' : ''; ?>" id="multi-step-waybill-form" data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>" enctype="multipart/form-data" novalidate<?php echo $kit_create_flat ? ' data-kit-create-flat="1"' : ''; ?><?php if ($last_waybill_customer_id) : ?> data-last-waybill-customer-id="<?php echo esc_attr($last_waybill_customer_id); ?>"<?php endif; ?>>
        <?php if ($is_edit_mode): ?>
            <input type="hidden" name="waybill_id" value="<?php echo esc_attr($waybill_id); ?>">
        <?php endif; ?>
        <?php if ($is_existing_customer): ?>
            <input type="hidden" name="exsitingCust" value="<?php echo esc_attr($is_existing_customer); ?>">
        <?php endif; ?>
        <?php if (!empty($atts['delivery_id'])): ?>
            <input type="hidden" name="delivery_id" id="delivery_id" value="<?php echo esc_attr($atts['delivery_id']); ?>">
            <input type="hidden" name="direction_id" id="direction_id" value="<?php echo esc_attr($direction_id); ?>">
        <?php endif; ?>
        <?php if (isset($atts['is_modal']) && $atts['is_modal']): ?>
            <input type="hidden" name="is_modal" id="is_modal" value="1">
            <input type="hidden" name="original_page_url" id="original_page_url" value="">
        <?php endif; ?>
        <?php
        $booking_request_id = isset($_GET['booking_request_id']) ? (int) $_GET['booking_request_id'] : 0;
        if ($booking_request_id > 0) :
            ?>
            <input type="hidden" name="kit_booking_request_id" value="<?php echo esc_attr((string) $booking_request_id); ?>">
        <?php endif; ?>
        <?php wp_nonce_field($is_edit_mode ? 'update_waybill_nonce' : 'add_waybill_nonce'); ?>
        
        <!-- Error Display Container -->
        <div id="form-errors" class="hidden mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800">Please fix the following errors:</h3>
                    <div id="error-list" class="mt-2 text-sm text-red-700">
                        <!-- Error messages will be inserted here -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Success Display Container -->
        <div id="form-success" class="hidden mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-green-800">Success!</h3>
                    <div id="success-message" class="mt-2 text-sm text-green-700">
                        <!-- Success message will be inserted here -->
                    </div>
                </div>
            </div>
        </div>

        <?php
        $kit_waybill_steps = [
            1 => 'Customer',
            2 => 'Delivery',
            3 => 'Charges',
            4 => 'Review',
        ];
        ?>
        <nav id="kit-waybill-step-indicator" class="mb-6<?php echo $kit_create_flat ? ' hidden' : ''; ?>" aria-label="Waybill create progress">
            <ol class="flex flex-wrap items-center gap-2 sm:gap-0">
                <?php foreach ($kit_waybill_steps as $num => $label) : ?>
                    <li class="step-indicator flex items-center <?php echo $num < count($kit_waybill_steps) ? 'sm:flex-1' : ''; ?>" data-step="<?php echo (int) $num; ?>">
                        <div class="kit-step-circle <?php echo $num === 1 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-600'; ?> flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold"><?php echo (int) $num; ?></div>
                        <span class="kit-step-label <?php echo $num === 1 ? 'font-semibold text-blue-600' : 'text-gray-500'; ?> ml-2 text-sm truncate"><?php echo esc_html($label); ?></span>
                        <?php if ($num < count($kit_waybill_steps)) : ?>
                            <div class="mx-2 hidden h-px flex-1 bg-gray-200 sm:block" aria-hidden="true"></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>

        <!-- Step 1: Waybill Header & Customer Information -->
        <div class="step step-1 active" id="step-1">
            <?php require __DIR__ . '/waybill/steps/step1.php'; ?>
        </div>

        <!-- Step 2: Delivery/Destination -->
        <div class="step step-2<?php echo $kit_create_flat ? '' : ' hidden'; ?>" id="step-2">
            <?php require __DIR__ . '/waybill/steps/step4.php'; ?>
        </div>

        <!-- Step 3: Charges & Fees (+ optional misc) -->
        <div class="step step-3<?php echo $kit_create_flat ? '' : ' hidden'; ?>" id="step-3">
            <?php require __DIR__ . '/waybill/steps/step5.php'; ?>
        </div>

        <!-- Legacy step-4 slot kept for ID stability; misc lives under Charges now -->
        <div class="step step-4 hidden" id="step-4" aria-hidden="true"></div>

        <!-- Step 5: Parcels & Review -->
        <div class="step step-5<?php echo $kit_create_flat ? '' : ' hidden'; ?>" id="step-5">
            <?php require __DIR__ . '/waybill/steps/step3.php'; ?>
        </div>
    </form>

    <?php if ($fake_filler_enabled): ?>
        <script>
            window.KITFakeFillerConfig = {
                enabled: true,
                dataUrl: <?php echo wp_json_encode($fake_filler_json_url); ?>,
                locale: 'en-ZA'
            };
        </script>
        <script src="<?php echo esc_url(trailingslashit(COURIER_FINANCE_PLUGIN_URL) . 'assets/js/fakefiller.js'); ?>"></script>
    <?php endif; ?>

    <script>
        // Global error handling functions
        function showError(message, fieldId = null) {
            const errorContainer = document.getElementById('form-errors');
            const errorList = document.getElementById('error-list');
            
            if (!errorContainer || !errorList) return;
            
            errorContainer.classList.remove('hidden');
            const errorItem = document.createElement('div');
            errorItem.className = 'error-item';
            if (fieldId) {
                errorItem.innerHTML = `<a href="javascript:void(0)" data-field="${fieldId}" class="error-link text-red-600 hover:text-red-800 underline cursor-pointer">${message}</a>`;
                highlightField(fieldId, true);
            } else {
                errorItem.textContent = message;
            }
            errorList.appendChild(errorItem);
            
            // Scroll to error container
            errorContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function showSuccess(message) {
            const successContainer = document.getElementById('form-success');
            const successMessage = document.getElementById('success-message');
            
            if (!successContainer || !successMessage) return;
            
            successContainer.classList.remove('hidden');
            successMessage.textContent = message;
            
            // Auto-hide after 8 seconds
            setTimeout(() => {
                successContainer.classList.add('hidden');
            }, 8000);
        }

        function highlightField(fieldId, highlight = true) {
            const field = document.getElementById(fieldId) || document.querySelector(`[name="${fieldId}"]`);
            if (field) {
                if (highlight) {
                    field.classList.add('border-red-500', 'bg-red-50');
                } else {
                    field.classList.remove('border-red-500', 'bg-red-50');
                }
            }
        }

        // Initialize waybill form JavaScript (works for both regular pages and modals)
        function initializeWaybillForm() {
            // Prevent double initialization
            if (window.waybillFormInitialized) {
                return;
            }
            
            // Check if form exists in DOM
            const form = document.getElementById('multi-step-waybill-form');
            if (!form) {
                return;
            }

            window.waybillFormInitialized = true;
            const isCreateFlat = form.getAttribute('data-kit-create-flat') === '1';
            if (isCreateFlat) {
                ['step-1', 'step-2', 'step-3', 'step-5'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    el.classList.remove('hidden');
                    el.classList.add('active');
                });
                if (typeof window.kitUpdateWaybillReview === 'function') {
                    window.kitUpdateWaybillReview();
                    form.addEventListener('input', window.kitUpdateWaybillReview);
                    form.addEventListener('change', window.kitUpdateWaybillReview);
                }
            }
            
            // Capture original page URL for modal redirects
            const originalPageUrlField = document.getElementById('original_page_url');
            if (originalPageUrlField && document.referrer) {
                originalPageUrlField.value = document.referrer;
                console.log('📍 Captured original page URL:', document.referrer);
            }
            
            // Error handling and validation system
            const errorContainer = document.getElementById('form-errors');
            const errorList = document.getElementById('error-list');
            const successContainer = document.getElementById('form-success');
            const successMessage = document.getElementById('success-message');

            // Display server-provided errors when redirected from non-AJAX submit
            (function hydrateServerErrors() {
                try {
                    const params = new URLSearchParams(window.location.search);
                    const encoded = params.get('form_errors');
                    if (encoded) {
                        const decoded = JSON.parse(atob(decodeURIComponent(encoded)));
                        if (Array.isArray(decoded) && decoded.length) {
                            decoded.forEach(err => {
                                showError(err.message, err.field || null);
                            });
                        }
                        // Clean the URL to avoid re-showing on refresh
                        params.delete('form_errors');
                        const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                        window.history.replaceState({}, document.title, newUrl);
                    }
                } catch (e) {
                    // ignore URL decoding errors
                }
            })();

            // Display success toast from URL parameters (for non-AJAX redirects)
            (function hydrateSuccessToast() {
                try {
                    const params = new URLSearchParams(window.location.search);
                    const toastMessage = params.get('toast_message');
                    const success = params.get('success');
                    
                    if (success === '1' && toastMessage) {
                        const decodedMessage = decodeURIComponent(toastMessage);
                        // Use proper KIT Toast if available, fallback to old method
                        if (window.KITToast) {
                            window.KITToast.show(decodedMessage, 'success', 'Waybill Created');
                        } else {
                            showSuccess(decodedMessage);
                        }
                        
                        // Clean the URL to avoid re-showing on refresh
                        params.delete('success');
                        params.delete('toast_message');
                        const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                        window.history.replaceState({}, document.title, newUrl);
                    }
                } catch (e) {
                    // ignore URL decoding errors
                }
            })();

            // Validation rules
            function shouldRequireNewCustomerFields() {
                const custId = (document.getElementById('cust_id')?.value || '').trim();
                const customerSelect = (document.getElementById('customer-select')?.value || '').trim();
                // Match backend logic: require customer fields only when creating a new customer.
                return customerSelect === 'new' || (custId === '' || custId === '0') && customerSelect === '';
            }


            const validationRules = {
                'customer_name': {
                    required: shouldRequireNewCustomerFields,
                    message: 'Customer name is required'
                },
                'customer_surname': {
                    required: shouldRequireNewCustomerFields,
                    message: 'Customer surname is required'
                },
                'cell': {
                    required: shouldRequireNewCustomerFields,
                    message: 'Cell phone number is required'
                },
                'address': {
                    required: shouldRequireNewCustomerFields,
                    message: 'Address is required'
                },
                'email_address': {
                    required: false,
                    message: 'Email address is optional'
                },
                'origin_country': {
                    required: function() {
                        // Don't require origin fields if creating from delivery
                        return !document.getElementById('delivery_id') || !document.getElementById('delivery_id').value;
                    },
                    message: 'Origin country is required'
                },
                'origin_city': {
                    required: function() {
                        // Don't require origin fields if creating from delivery
                        return !document.getElementById('delivery_id') || !document.getElementById('delivery_id').value;
                    },
                    message: 'Origin city is required'
                },
                'destination_country': {
                    required: function() {
                        return !document.getElementById('pending_option')?.checked;
                    },
                    message: 'Destination country is required for non-pending items'
                },
                'destination_city': {
                    required: function() {
                        return !document.getElementById('pending_option')?.checked;
                    },
                    message: 'Destination city is required for non-pending items'
                },
                'total_mass_kg': {
                    required: true,
                    message: 'Total mass is required'
                },
                'direction_id': {
                    required: function() {
                        // Check if a delivery is selected OR warehouse is checked
                        // PRIMARY: Check field value (works even when step 4 is hidden)
                        const directionIdField = document.getElementById('direction_id');
                        const directionIdValue = directionIdField ? directionIdField.value.trim() : '';
                        const hasDirectionId = directionIdValue && directionIdValue !== '' && directionIdValue !== '0';
                        
                        // SECONDARY: Check visual selection (for UI feedback)
                        const hasDeliverySelected = document.querySelector('.delivery-card.selected') !== null;
                        const isWarehoused = document.getElementById('pending_option')?.checked || false;
                        
                        // If warehouse is checked, direction_id is not required (will be set to 1)
                        // Otherwise, require either field value OR visual selection
                        return !isWarehoused && !hasDirectionId && !hasDeliverySelected;
                    },
                    message: 'Please select a delivery or check the warehouse option'
                }
            };

            // Show error message
            function showError(message, fieldId = null) {
                errorContainer.classList.remove('hidden');
                const errorItem = document.createElement('div');
                errorItem.className = 'error-item';
                if (fieldId) {
                    errorItem.innerHTML = `<a href="javascript:void(0)" data-field="${fieldId}" class="error-link text-red-600 hover:text-red-800 underline cursor-pointer">${message}</a>`;
                    highlightField(fieldId, true);
                } else {
                    errorItem.textContent = message;
                }
                errorList.appendChild(errorItem);
                
                // Scroll to error container
                errorContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            // showSuccess is now defined globally above

            // Clear all errors
            function clearErrors() {
                errorContainer.classList.add('hidden');
                errorList.innerHTML = '';
                clearFieldHighlights();
            }

            // Clear success message
            function clearSuccess() {
                successContainer.classList.add('hidden');
                successMessage.textContent = '';
            }

            // Highlight field with error
            function highlightField(fieldId, hasError = false) {
                const field = document.getElementById(fieldId);
                if (field) {
                    if (hasError) {
                        field.classList.add('border-red-500', 'bg-red-50');
                        field.classList.remove('border-gray-300', 'focus:border-blue-500');
                    } else {
                        field.classList.remove('border-red-500', 'bg-red-50');
                        field.classList.add('border-gray-300', 'focus:border-blue-500');
                    }
                }
            }

            // Clear all field highlights
            function clearFieldHighlights() {
                document.querySelectorAll('.border-red-500').forEach(field => {
                    field.classList.remove('border-red-500', 'bg-red-50');
                    field.classList.add('border-gray-300', 'focus:border-blue-500');
                });
            }

            // Validate a single field
            function validateField(fieldId) {
                const field = document.getElementById(fieldId);
                if (!field) return true;

                const rule = validationRules[fieldId];
                if (!rule) return true;

                let isRequired = rule.required;
                if (typeof isRequired === 'function') {
                    isRequired = isRequired();
                }

                // More robust validation for empty values
                const fieldValue = field.value ? field.value.toString().trim() : '';
                const isEmpty = fieldValue === '' || fieldValue === '0' || fieldValue === 'null' || fieldValue === 'undefined';
                
                if (isRequired && isEmpty) {
                    highlightField(fieldId, true);
                    return false;
                } else {
                    highlightField(fieldId, false);
                    return true;
                }
            }

            // Validate all fields
            function validateForm() {
                console.log('🔍 Starting form validation...');
                clearErrors();
                let isValid = true;
                const errors = [];

                // Special validation for step 4 - delivery selection or warehouse
                // PRIMARY CHECK: Use direction_id field value as primary indicator
                const directionIdField = document.getElementById('direction_id');
                const directionIdValue = directionIdField ? directionIdField.value.trim() : '';
                
                // Also check selected_delivery_id as additional confirmation
                const selectedDeliveryIdField = document.getElementById('selected_delivery_id');
                const selectedDeliveryIdValue = selectedDeliveryIdField ? selectedDeliveryIdField.value.trim() : '';
                
                // SECONDARY CHECK: Check for visually selected delivery card (for UI feedback, but not required)
                const hasDeliverySelected = document.querySelector('.delivery-card.selected') !== null;
                const isWarehoused = document.getElementById('pending_option')?.checked || false;
                
                // Determine if delivery is actually selected (primary: direction_id field value)
                // If direction_id has a value, consider delivery as selected even if card class isn't visible
                const deliveryIsSelected = directionIdValue && directionIdValue !== '' && directionIdValue !== '0';
                
                // Get destination country from multiple possible sources
                let destinationCountry = document.getElementById('stepDestinationSelect')?.value || '';
                // Check backup field if main field is empty (for delivery card selections)
                if (!destinationCountry) {
                    const backupField = document.getElementById('destination_country_backup');
                    destinationCountry = backupField ? backupField.value : '';
                }
                // Also check the regular destination_country field
                if (!destinationCountry) {
                    const regularField = document.getElementById('destination_country');
                    destinationCountry = regularField ? regularField.value : '';
                }
                
                console.log('📋 Step 4 validation:', {
                    directionIdValue: directionIdValue || 'MISSING',
                    selectedDeliveryIdValue: selectedDeliveryIdValue || 'MISSING',
                    deliveryIsSelected,
                    hasDeliverySelected,
                    isWarehoused,
                    destinationCountry: destinationCountry || 'MISSING',
                    step4Visible: document.getElementById('step-4')?.classList.contains('hidden') === false
                });
                
                // Primary validation: check direction_id field value OR warehouse option
                // IMPORTANT: Check field value, not visual indicators (step 4 may be hidden)
                if (!deliveryIsSelected && !isWarehoused) {
                    isValid = false;
                    errors.push({
                        message: 'Please select a delivery or check the warehouse option',
                        fieldId: 'direction_id'
                    });
                }
                
                // Additional check: if direction_id is set, ensure it's valid
                if (deliveryIsSelected && (!directionIdValue || directionIdValue === '0')) {
                    isValid = false;
                    errors.push({
                        message: 'Delivery selection is incomplete - please try selecting again',
                        fieldId: 'direction_id'
                    });
                }
                
                // For warehouse items, ensure we have direction_id = 1 for South Africa rates
                if (isWarehoused) {
                    if (!directionIdValue || directionIdValue !== '1') {
                        // Auto-set direction_id to 1 for warehouse items if not set
                        if (directionIdField && !directionIdValue) {
                            directionIdField.value = '1';
                            console.log('🔧 Auto-set direction_id to 1 for warehouse item');
                        } else if (directionIdValue !== '1') {
                            isValid = false;
                            errors.push({
                                message: 'Warehouse rate calculation requires South Africa direction ID',
                                fieldId: 'direction_id'
                            });
                        }
                    }
                }
                
                // Only require destination fields if NOT pending
                if (!isWarehoused) {
                    if (!destinationCountry || destinationCountry === '' || destinationCountry === '0') {
                        isValid = false;
                        errors.push({
                            message: 'Destination country is required for non-pending items',
                            fieldId: 'stepDestinationSelect'
                        });
                    }
                    
                    // Check destination_city field value (not just if field exists)
                    const destinationCityField = document.getElementById('destination_city');
                    const destinationCity = destinationCityField ? destinationCityField.value.trim() : '';
                    
                    console.log('📋 Destination city validation:', {
                        fieldExists: !!destinationCityField,
                        cityValue: destinationCity || 'MISSING',
                        optionsCount: destinationCityField ? destinationCityField.options.length : 0,
                        hasValidValue: destinationCity && destinationCity !== '' && destinationCity !== '0'
                    });
                    
                    // Validate city value - must be set and not empty/zero
                    if (!destinationCity || destinationCity === '' || destinationCity === '0') {
                        isValid = false;
                        errors.push({
                            message: 'Destination city is required for non-pending items',
                            fieldId: 'destination_city'
                        });
                    }
                }
                
                // Special validation for VAT - if VAT is enabled, parcels are required
                const vatCheckbox = document.getElementById('vat_include2') || document.getElementById('vat_include');
                const waybillItemsContainer = document.getElementById('custom-waybill-items');
                const isVatEnabled = vatCheckbox && vatCheckbox.checked;
                
                if (isVatEnabled && waybillItemsContainer) {
                    const itemRows = waybillItemsContainer.querySelectorAll('.dynamic-item');
                    if (itemRows.length === 0) {
                        isValid = false;
                        errors.push({
                            message: 'At least one waybill item is required when VAT is enabled',
                            fieldId: 'custom-waybill-items'
                        });
                    }
                }

                // Validate each field
                Object.keys(validationRules).forEach(fieldId => {
                    if (!validateField(fieldId)) {
                        isValid = false;
                        errors.push({
                            message: validationRules[fieldId].message,
                            fieldId: fieldId
                        });
                    }
                });

                // Show errors if any
                if (!isValid) {
                    console.log('❌ Validation failed with errors:', errors);
                    errors.forEach(error => {
                        showError(error.message, error.fieldId);
                    });
                } else {
                    console.log('✅ Form validation passed!');
                }

                return isValid;
            }

            const __submitBtn = document.getElementById('next-step-3');
            if (__submitBtn) {
                __submitBtn.addEventListener('click', function() {
                    // keep hook reserved for future button-specific actions
                });
            }

            // Always run client-side validation before any submission (AJAX or non-AJAX)
            const multiStepForm = document.getElementById('multi-step-waybill-form');
            if (multiStepForm) {
                const isAjaxSubmitFlow = <?php echo $is_ajaxform ? 'true' : 'false'; ?>;

                // IMPORTANT: In non-AJAX flow, do not block submit on client-side full-form validation.
                // Server-side process_form handles authoritative validation and redirects with errors.
                if (isAjaxSubmitFlow) {
                    multiStepForm.addEventListener('submit', function(e) {
                        // If invalid, prevent navigation to admin-post and show inline errors
                        if (typeof validateForm === 'function' && !validateForm()) {
                            e.preventDefault();
                            e.stopPropagation();
                            return false;
                        }
                    }, { capture: true });
                }
            }

            // Defensive: delegate submit guard in case the direct listener misses
            document.addEventListener('submit', function(e) {
                const form = e.target;
                if (!form) return;
                if (form.id === 'multi-step-waybill-form' || form.getAttribute('data-role') === 'waybill-form') {
                    const isAjaxSubmitFlow = <?php echo $is_ajaxform ? 'true' : 'false'; ?>;
                    if (!isAjaxSubmitFlow) {
                        return;
                    }
                    if (typeof validateForm === 'function') {
                        if (!validateForm()) {
                            e.preventDefault();
                            e.stopPropagation();
                            return false;
                        }
                    } else {
                        // Minimal fallback: ensure all [required] have values
                        const requiredFields = form.querySelectorAll('[required]');
                        let ok = true;
                        requiredFields.forEach(function(f) {
                            if (ok && (!f.value || String(f.value).trim() === '')) {
                                ok = false;
                            }
                        });
                        if (!ok) {
                            e.preventDefault();
                            e.stopPropagation();
                            return false;
                        }
                    }
                }
            }, true);

            // Real-time validation on field blur
            Object.keys(validationRules).forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('blur', () => {
                        validateField(fieldId);
                    });
                    field.addEventListener('input', () => {
                        if (field.classList.contains('border-red-500')) {
                            validateField(fieldId);
                        }
                    });
                }
            });

            // Error link click handler
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('error-link')) {
                    e.preventDefault();
                    const fieldId = e.target.getAttribute('data-field');
                    if (fieldId) {
                        scrollToField(fieldId);
                    }
                }
            });

            // Function to scroll to field and switch to correct step if needed
            function scrollToField(fieldId) {
                const field = document.getElementById(fieldId);
                if (!field) return;

                // Find which step contains this field
                const step = field.closest('.step');
                if (step && !step.classList.contains('active')) {
                    // Switch to the step containing the field
                    const currentStep = document.querySelector('.step.active');
                    if (currentStep) {
                        switchStep(currentStep, step);
                    }
                }

                // Scroll to the field with a small delay to ensure step switch is complete
                setTimeout(() => {
                    field.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'center',
                        inline: 'nearest'
                    });
                    
                    // Add a brief flash effect to draw attention
                    field.style.transition = 'box-shadow 0.3s ease';
                    field.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.3)';
                    setTimeout(() => {
                        field.style.boxShadow = '';
                    }, 1000);
                }, 100);
            }

            // Warehoused checkbox change handler
            const pendingCheckbox = document.getElementById('pending_option');
            if (pendingCheckbox) {
                pendingCheckbox.addEventListener('change', () => {
                    // Re-validate destination fields when pending status changes
                    validateField('destination_country');
                    validateField('destination_city');
                });
            }

            // 🔧 FIX: Calculate direction_id when countries are selected
            function calculateDirectionId() {
                const originCountry = document.getElementById('origin_country')?.value;
                const destinationCountry = document.getElementById('destination_country')?.value;
                const directionIdField = document.getElementById('direction_id');
                
                if (originCountry && destinationCountry && directionIdField) {
                    // Make AJAX call to get/create direction_id
                    fetch(myPluginAjax.ajax_url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'get_direction_id',
                            origin_country_id: originCountry,
                            destination_country_id: destinationCountry,
                            _ajax_nonce: myPluginAjax.nonces.get_waybills_nonce
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.data.direction_id) {
                            directionIdField.value = data.data.direction_id;
                            console.log('Direction ID set to:', data.data.direction_id);
                        } else {
                            console.warn('Could not get direction_id:', data.data?.message || 'Unknown error');
                        }
                    })
                    .catch(error => {
                        console.error('Error getting direction_id:', error);
                    });
                }
            }

            // Add event listeners to country selectors
            const originCountrySelect = document.getElementById('origin_country');
            const destinationCountrySelect = document.getElementById('destination_country');
            const stepDestinationSelect = document.getElementById('stepDestinationSelect');
            
            // Function to load cities for destination country (global for modal compatibility)
            window.loadDestinationCities = function(countryId, cityIdToSelect = null) {
                if (!countryId) return;
                
                console.log('🔄 Loading cities for country:', countryId, cityIdToSelect ? 'with city ID:' + cityIdToSelect : '');
                
                const citySelect = document.getElementById('destination_city');
                if (!citySelect) {
                    console.warn('❌ destination_city select not found');
                    return;
                }
                
                // Try preloaded map first (fastest)
                const citiesMap = (window.myPluginAjax && window.myPluginAjax.countryCities) || {};
                const cities = citiesMap && citiesMap[String(countryId)] ? citiesMap[String(countryId)] : [];
                
                if (Array.isArray(cities) && cities.length) {
                    citySelect.innerHTML = '<option value="">Select City</option>';
                    cities.forEach(function(city) {
                        const option = document.createElement('option');
                        option.value = city.id;
                        option.textContent = city.city_name;
                        // Auto-select if cityIdToSelect matches
                        if (cityIdToSelect && String(city.id) === String(cityIdToSelect)) {
                            option.selected = true;
                        }
                        citySelect.appendChild(option);
                    });

                    citySelect.disabled = false;
                    
                    // Set the value if cityIdToSelect is provided (in case it wasn't in the options)
                    if (cityIdToSelect && citySelect.value !== String(cityIdToSelect)) {
                        citySelect.value = cityIdToSelect;
                        // Trigger change event
                        const changeEvent = new Event('change', { bubbles: true });
                        citySelect.dispatchEvent(changeEvent);
                    }
                    
                    console.log('✅ Cities loaded from preloaded map:', cities.length, cityIdToSelect ? '- City auto-selected: ' + cityIdToSelect : '');
                } else {
                    // Fallback: use handleCountryChange if available
                    if (typeof handleCountryChange === 'function') {
                        handleCountryChange(countryId, 'destination');
                        console.log('✅ Using handleCountryChange function');
                    } else {
                        // Last resort: AJAX call
                        const ajaxUrl = (window.myPluginAjax && window.myPluginAjax.ajax_url) || window.ajaxurl || '/wp-admin/admin-ajax.php';
                        const nonce = (window.myPluginAjax && window.myPluginAjax.nonces && window.myPluginAjax.nonces.get_waybills_nonce) || '';
                        
                        citySelect.innerHTML = '<option value="">Loading cities...</option>';
                        citySelect.disabled = true;
                        
                        fetch(ajaxUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                action: 'handle_get_cities_for_country',
                                country_id: countryId,
                                nonce: nonce
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && Array.isArray(data.data) && data.data.length) {
                                citySelect.innerHTML = '<option value="">Select City</option>';
                                data.data.forEach(function(city) {
                                    const option = document.createElement('option');
                                    option.value = city.id;
                                    option.textContent = city.city_name;
                                    // Auto-select if cityIdToSelect matches
                                    if (cityIdToSelect && String(city.id) === String(cityIdToSelect)) {
                                        option.selected = true;
                                    }
                                    citySelect.appendChild(option);
                                });
                                citySelect.disabled = false;
                                
                                // Set the value if cityIdToSelect is provided
                                if (cityIdToSelect && citySelect.value !== String(cityIdToSelect)) {
                                    citySelect.value = cityIdToSelect;
                                    // Trigger change event
                                    const changeEvent = new Event('change', { bubbles: true });
                                    citySelect.dispatchEvent(changeEvent);
                                }
                                
                                console.log('✅ Cities loaded via AJAX:', data.data.length, cityIdToSelect ? '- City auto-selected: ' + cityIdToSelect : '');
                            } else {
                                citySelect.innerHTML = '<option value="">No cities found</option>';
                                citySelect.disabled = false;
                                console.warn('⚠️ No cities found for country:', countryId);
                            }
                        })
                        .catch(error => {
                            console.error('❌ Error loading cities:', error);
                            citySelect.innerHTML = '<option value="">Error loading cities</option>';
                            citySelect.disabled = false;
                        });
                    }
                }
            };
            
            // Add event listeners for country changes
            if (originCountrySelect) {
                originCountrySelect.addEventListener('change', calculateDirectionId);
            }
            if (destinationCountrySelect) {
                destinationCountrySelect.addEventListener('change', function() {
                    calculateDirectionId();
                    loadDestinationCities(this.value);
                });
            }
            
            // CRITICAL: Add event listener for stepDestinationSelect (used in modal)
            if (stepDestinationSelect) {
                stepDestinationSelect.addEventListener('change', function() {
                    const countryId = this.value;
                    console.log('📍 stepDestinationSelect changed to:', countryId);
                    
                    // Update backup field
                    const backupField = document.getElementById('destination_country_backup');
                    if (backupField) {
                        backupField.value = countryId;
                    }
                    
                    // Load cities – but preserve an existing city selection when the
                    // country remains the same (e.g. clicking a delivery card after
                    // the user has already chosen a city).
                    const citySelect = document.getElementById('destination_city');
                    const currentCityId = citySelect ? (citySelect.value || null) : null;
                    loadDestinationCities(countryId, currentCityId);
                    
                    // Also trigger handleCountryChange if available (for compatibility)
                    if (typeof handleCountryChange === 'function') {
                        handleCountryChange(countryId, 'destination');
                    }
                    
                    // Check button conditions for step 4
                    if (typeof checkButtonConditions === 'function') {
                        setTimeout(() => checkButtonConditions(), 100);
                    }
                });
                
                // If country is already selected on load, load cities immediately
                if (stepDestinationSelect.value) {
                    setTimeout(() => {
                        loadDestinationCities(stepDestinationSelect.value);
                    }, 100);
                }
            } else {
                console.warn('⚠️ stepDestinationSelect not found in DOM');
            }

            // Accordion toggle
            const accordionToggle = document.querySelector('.customer-accordion-toggle');
            const accordionContent = document.querySelector('.customer-details-content');
            if (accordionToggle && accordionContent) {
                accordionToggle.addEventListener('click', function() {
                    accordionContent.classList.toggle('hidden');
                });
            }

            // Flexible input resolver (detects normal or "a" prefixed fields)
            function getCustomerInput(idBase) {
                return document.getElementById(idBase) || document.getElementById('a' + idBase);
            }

            const customerSelect = document.getElementById('customer-select');
            const custIdInput = document.getElementById('cust_id');

            const nameInput = getCustomerInput('name');
            const surnameInput = getCustomerInput('surname');
            const cellInput = getCustomerInput('cell');
            const addressInput = getCustomerInput('address');

            // Function to populate customer fields
            function populateCustomerDetails(customerId) {
                const option = customerSelect?.querySelector(`option[value="${customerId}"]`);
                if (option) {
                    if (nameInput) nameInput.value = option.getAttribute('data-name') || '';
                    if (surnameInput) surnameInput.value = option.getAttribute('data-surname') || '';
                    if (cellInput) cellInput.value = option.getAttribute('data-cell') || '';
                    if (addressInput) addressInput.value = option.getAttribute('data-address') || '';
                    
                    // Populate email field
                    const emailInput = getCustomerInput('email_address');
                    if (emailInput) {
                        emailInput.value = option.getAttribute('data-email') || '';
                    }
                    
                    // Populate company name field
                    const companyNameInput = getCustomerInput('company_name');
                    if (companyNameInput) {
                        companyNameInput.value = option.getAttribute('data-company-name') || '';
                    }
                    
                    if (custIdInput) custIdInput.value = customerId;
                }
            }

            if (customerSelect) {
                customerSelect.addEventListener('change', function() {
                    if (this.value === 'new') {
                        // Preserve user-entered values. Another customer UI script manages
                        // explicit "Add New Customer" resets; this fallback listener should
                        // never wipe fields implicitly.
                        if (custIdInput) custIdInput.value = '0';
                    } else if (this.value) {
                        populateCustomerDetails(this.value);
                    }
                });

                // Initial population on page load (for edit mode)
                const initialCustomerId = custIdInput?.value;
                if (initialCustomerId && initialCustomerId !== '0') {
                    populateCustomerDetails(initialCustomerId);
                }
            }

            const steps = document.querySelectorAll('.step');
            const nextButtons = document.querySelectorAll('.next-step');
            const prevButtons = document.querySelectorAll('.prev-step');
            const stepIndicators = document.querySelectorAll('.step-indicator');

            // Map DOM step ids → progress indicator index (misc folded into Charges)
            function logicalStepNumber(stepEl) {
                if (!stepEl) return 1;
                const id = stepEl.id || '';
                if (id === 'step-1') return 1;
                if (id === 'step-2') return 2;
                if (id === 'step-3' || id === 'step-4') return 3;
                if (id === 'step-5') return 4;
                const stepMatch = stepEl.className.match(/step-(\d)/);
                return stepMatch ? parseInt(stepMatch[1], 10) : 1;
            }

            // Common function to switch steps
            function switchStep(fromStep, toStep) {
                if (!toStep || !fromStep) return;
                // Legacy step-4 (misc) redirects to parcels/review
                if (toStep.id === 'step-4') {
                    toStep = document.getElementById('step-5') || toStep;
                }
                fromStep.classList.remove('active');
                fromStep.classList.add('hidden');
                toStep.classList.remove('hidden');
                toStep.classList.add('active');
                updateStepIndicator(toStep);
                if (toStep.id === 'step-5' && typeof window.kitUpdateWaybillReview === 'function') {
                    window.kitUpdateWaybillReview();
                }
                if (toStep.id === 'step-3' && typeof window.kitChargesReadyToContinue === 'function') {
                    window.kitChargesReadyToContinue();
                }
                try {
                    toStep.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } catch (e) {}
            }

            if (isCreateFlat) {
                if (typeof autoSelectRecentDelivery === 'function') {
                    setTimeout(autoSelectRecentDelivery, 300);
                }
            } else {
            // NEXT step buttons
            nextButtons.forEach(button => {
                button.addEventListener('click', function() {
                    if (this.disabled) {
                        return;
                    }
                    const currentStep = document.querySelector('.step.active:not(.md\\:block)');
                    const targetId = this.getAttribute('data-target');
                    let targetStep = targetId ? document.getElementById(targetId) : currentStep?.nextElementSibling;

                    if (currentStep && currentStep.id === 'step-3' && typeof window.kitChargesReadyToContinue === 'function') {
                        if (!window.kitChargesReadyToContinue()) {
                            return;
                        }
                    }

                    if (validateStep(currentStep)) {
                        switchStep(currentStep, targetStep);
                        
                        // If navigating to step 2 (delivery), auto-select recent delivery
                        if (targetId === 'step-2' || targetId === '2') {
                            setTimeout(() => {
                                autoSelectRecentDelivery();
                            }, 300);
                        }
                    }
                });
            });

            // PREVIOUS step buttons
            prevButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const currentStep = document.querySelector('.step.active');
                    const targetId = this.getAttribute('data-target');
                    const targetStep = targetId ? document.getElementById(targetId) : currentStep?.previousElementSibling;

                    switchStep(currentStep, targetStep);
                });
            });
            }

            // Enhanced step validation
            function validateStep(step) {
                // Clear any previous errors
                clearErrors();
                
                // Get all required fields in the current step
                const requiredFields = step.querySelectorAll('[required], .required');
                let isValid = true;
                const errors = [];

                requiredFields.forEach(field => {
                    if (!field.value || field.value.trim() === '') {
                        isValid = false;
                        const fieldName = field.getAttribute('name') || field.getAttribute('id') || 'this field';
                        errors.push({
                            message: `${fieldName.charAt(0).toUpperCase() + fieldName.slice(1)} is required`,
                            fieldId: field.getAttribute('id')
                        });
                    }
                });

                // Show errors if any
                if (!isValid) {
                    errors.forEach(error => {
                        showError(error.message, error.fieldId);
                    });
                    return false;
                }

                return true;
            }

            // Progress bar / Step indicators (4 logical steps)
            function updateStepIndicator(activeStep) {
                if (!activeStep) return;
                
                const stepNumber = logicalStepNumber(activeStep);

                stepIndicators.forEach((indicator, index) => {
                    if (!indicator) return;
                    
                    const circle = indicator.querySelector('.kit-step-circle');
                    const label = indicator.querySelector('.kit-step-label');
                    
                    if (!circle || !label) return;

                    const baseCircle = 'kit-step-circle flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold ';
                    const baseLabel = 'kit-step-label ml-2 text-sm truncate ';

                    if (index + 1 === stepNumber) {
                        circle.className = baseCircle + 'bg-blue-600 text-white';
                        label.className = baseLabel + 'font-semibold text-blue-600';
                    } else if (index + 1 < stepNumber) {
                        circle.className = baseCircle + 'bg-green-600 text-white';
                        label.className = baseLabel + 'font-semibold text-green-700';
                    } else {
                        circle.className = baseCircle + 'bg-gray-200 text-gray-600';
                        label.className = baseLabel + 'text-gray-500';
                    }
                });
            }

            updateStepIndicator(document.getElementById('step-1'));
            // Dynamic misc items
            const addMiscBtn = document.getElementById('add-misc-btn');
            const miscItemsContainer = document.getElementById('misc-items-container');

            if (addMiscBtn && miscItemsContainer) {
                addMiscBtn.addEventListener('click', () => {
                    const miscItemGroup = document.createElement('div');
                    miscItemGroup.className = 'misc-item';
                    miscItemGroup.style = 'display: flex; gap: 10px; margin-bottom: 10px; align-items: center;';
                    miscItemGroup.innerHTML = `
            <input type="text" name="misc_item[]" placeholder="Item description" style="flex: 2; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            <input type="number" name="misc_price[]" placeholder="Amount" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            <button type="button" class="remove-misc-btn bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-sm font-medium transition-colors duration-200">×</button>`;
                    miscItemsContainer.appendChild(miscItemGroup);
                });
            }

            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('remove-misc-btn')) {
                    e.target.closest('.misc-item').remove();
                }
            });

            // Add event listeners to existing remove buttons
            document.querySelectorAll('.remove-misc-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    this.closest('.misc-item').remove();
                });
            });

        }
        
        // Initialize on DOMContentLoaded (for regular pages)
        document.addEventListener('DOMContentLoaded', initializeWaybillForm);
        
        // Also initialize immediately if DOM is already loaded (for dynamically loaded modals)
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeWaybillForm);
        } else {
            // DOM already loaded, initialize immediately
            initializeWaybillForm();
        }
        
        // Initialize when modal opens (for modals loaded in DOM but hidden)
        jQuery(document).ready(function($) {
            // Listen for custom modal opened event
            $(document).on('modal:opened', function(e, modal) {
                if (modal && modal.attr('id') === 'create-waybill-modal') {
                    setTimeout(function() {
                        if (!window.waybillFormInitialized) {
                            initializeWaybillForm();
                        }
                    }, 100);
                }
            });
            
            // Also listen for clicks on the modal trigger button
            $(document).on('click', '[data-modal="create-waybill-modal"]', function() {
                setTimeout(function() {
                    if (!window.waybillFormInitialized) {
                        initializeWaybillForm();
                    }
                }, 150);
            });
            
            // Also check if modal is visible on page load
            const modal = $('#create-waybill-modal');
            if (modal.length && modal.hasClass('flex')) {
                setTimeout(function() {
                    if (!window.waybillFormInitialized) {
                        initializeWaybillForm();
                    }
                }, 100);
            }
            
            // Use MutationObserver to watch for modal visibility changes
            const modalElement = document.getElementById('create-waybill-modal');
            if (modalElement) {
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                            const target = mutation.target;
                            if (target.classList.contains('flex') && !target.classList.contains('hidden')) {
                                // Modal just became visible
                                setTimeout(function() {
                                    if (!window.waybillFormInitialized) {
                                        initializeWaybillForm();
                                    }
                                }, 100);
                            }
                        }
                    });
                });
                
                observer.observe(modalElement, {
                    attributes: true,
                    attributeFilter: ['class']
                });
            }
        });

        <?php if ($is_ajaxform): ?>
            // AJAX Form Submission
            jQuery(document).ready(function($) {
                $('#multi-step-waybill-form').on('submit', function(e) {
                    e.preventDefault();

                    var $form = $(this);
                    var $submitBtn = $form.find('button[type="submit"]');
                    var originalBtnText = $submitBtn.html();

                    // Clear previous messages
                    $('#form-errors').addClass('hidden');
                    $('#form-success').addClass('hidden');
                    $('.border-red-500').removeClass('border-red-500 bg-red-50').addClass('border-gray-300');

                    // Client-side validation
                    if (typeof validateForm === 'function' && !validateForm()) {
                        return false;
                    }

                    // Show loading state
                    $submitBtn.prop('disabled', true).html('<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...');

                    // Add the AJAX nonce to the form data
                    var formData = new FormData(this);
                    formData.append('action', 'process_waybill_form');
                    formData.append('_ajax_nonce', myPluginAjax.nonces.add);

                    $.ajax({
                            url: myPluginAjax.ajax_url,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            dataType: 'json'
                        })
                        .done(function(response) {
                            if (response.success) {
                                // Check if this is a modal submission (from server response or client-side)
                                const isModal = response.data?.is_modal || document.getElementById('is_modal')?.value === '1';
                                const referer = document.referrer;
                                const isFromDeliveryPage = referer && referer.includes('page=view-deliveries');
                                
                                console.log('🔍 Modal check:', { 
                                    serverIsModal: response.data?.is_modal, 
                                    clientIsModal: document.getElementById('is_modal')?.value === '1',
                                    isModal, 
                                    isFromDeliveryPage, 
                                    referer 
                                });
                                
                                if (isModal) {
                                    // Modal submission - redirect back to where you came from
                                    const waybillNo = response.data.waybill_no || '';
                                    
                                    // Try to get the original page URL from the hidden field first
                                    const originalPageUrlField = document.getElementById('original_page_url');
                                    let baseUrl = originalPageUrlField ? originalPageUrlField.value : referer;
                                    
                                    console.log('🔍 URL sources:', {
                                        originalPageUrlField: originalPageUrlField ? originalPageUrlField.value : 'not found',
                                        documentReferrer: referer,
                                        usingBaseUrl: baseUrl
                                    });
                                    
                                    // Enhanced referrer handling with fallbacks
                                    if (!baseUrl || baseUrl === '' || baseUrl === window.location.origin + '/wp-admin/') {
                                        // Fallback: construct the waybill management page URL
                                        baseUrl = window.location.origin + '/wp-admin/admin.php?page=08600-waybill-manage';
                                        console.log('⚠️ Using fallback URL:', baseUrl);
                                    }
                                    
                                    // Ensure we have a proper base URL
                                    if (!baseUrl.includes('admin.php')) {
                                        baseUrl = window.location.origin + '/wp-admin/admin.php?page=08600-waybill-manage';
                                        console.log('⚠️ Corrected URL to:', baseUrl);
                                    }
                                    
                                    const redirectUrl = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 
                                        'waybill_created=1&waybill_no=' + encodeURIComponent(waybillNo) + 
                                        '&message=' + encodeURIComponent('Waybill #' + waybillNo + ' created successfully!');
                                    
                                    console.log('🔍 Final redirect debug:', {
                                        originalReferrer: referer,
                                        capturedOriginalUrl: originalPageUrlField ? originalPageUrlField.value : 'not found',
                                        finalBaseUrl: baseUrl,
                                        finalRedirectUrl: redirectUrl
                                    });
                                    
                                    console.log('🔄 Modal redirect to:', redirectUrl);
                                    window.location.href = redirectUrl;
                                } else if (isFromDeliveryPage) {
                                    // Delivery page - redirect back with success message
                                    const waybillNo = response.data.waybill_no || '';
                                    const redirectUrl = referer + (referer.includes('?') ? '&' : '?') + 
                                        'waybill_created=1&waybill_no=' + encodeURIComponent(waybillNo) + 
                                        '&message=' + encodeURIComponent('Waybill #' + waybillNo + ' created successfully!');
                                    console.log('🔄 Delivery page redirect to:', redirectUrl);
                                    window.location.href = redirectUrl;
                                } else {
                                    // Regular waybill creation - admins go to waybill view; others stay with success toast
                                    const isAdmin = response.data.is_admin === true;
                                    let waybillId = response.data.waybill_id || '';
                                    
                                    // Ensure waybillId is a string/number, not an object
                                    if (typeof waybillId === 'object' && waybillId !== null) {
                                        waybillId = waybillId.id || waybillId.waybill_id || waybillId.toString() || '';
                                    }
                                    waybillId = String(waybillId).trim();
                                    
                                    if (isAdmin && waybillId && waybillId !== 'undefined' && waybillId !== 'null' && waybillId !== '[object Object]') {
                                        // Admin: redirect to waybill view
                                        console.log('🔄 Redirecting to waybill view:', waybillId);
                                        const targetWindow = (window.parent && window.parent !== window) ? window.parent : window;
                                        if (window.myPluginAjax && myPluginAjax.is_portal && myPluginAjax.portal_base) {
                                            const base = myPluginAjax.portal_base.replace(/\/+$/, '');
                                            const portalUrl = base + '?section=08600-Waybill-view'
                                                + '&waybill_id=' + encodeURIComponent(waybillId)
                                                + '&waybill_atts=view_waybill';
                                            targetWindow.location.href = portalUrl;
                                        } else {
                                            targetWindow.location.href = '<?php echo $waybill_view_redirect_base; ?>'
                                                + encodeURIComponent(waybillId)
                                                + '&waybill_atts=view_waybill';
                                        }
                                    } else {
                                        // Non-admin or invalid ID: show success and stay on create page
                                        showSuccess(response.data.message || 'Waybill created successfully!');
                                    }
                                }
                                
                                if (response.data.waybill_id) {
                                    // Refresh the waybills table if it exists
                                    fetch(window.location.href, {
                                            method: 'GET',
                                            credentials: 'same-origin'
                                        })
                                        .then(res => res.text())
                                        .then(html => {
                                            const parser = new DOMParser();
                                            const doc = parser.parseFromString(html, 'text/html');
                                            const newTableBody = doc.querySelector('#customerWaybills tbody');
                                            const currentTableBody = document.querySelector('#customerWaybills tbody');

                                            if (newTableBody && currentTableBody) {
                                                currentTableBody.innerHTML = newTableBody.innerHTML;
                                            }
                                        })
                                        .catch(err => {
                                            console.error('Error refreshing table:', err);
                                            showError('Waybill saved, but failed to refresh the table.');
                                        });
                                }
                                
                                // Reset form after successful submission
                                setTimeout(() => {
                                    $form[0].reset();
                                    clearErrors();
                                    clearSuccess();
                                    
                                    // AGGRESSIVE FORM CLEARING - Clear all input fields
                                    $form.find('input[type="text"], input[type="number"], input[type="email"], textarea, select').val('');
                                    $form.find('input[type="checkbox"], input[type="radio"]').prop('checked', false);
                                    
                                    // Go back to step 1
                                    $('.step').removeClass('active').addClass('hidden');
                                    $('#step-1').removeClass('hidden').addClass('active');
                                    
                                    // For non-price users, show additional encouragement
                                    if (!canSeePrices && waybillNo) {
                                        setTimeout(() => {
                                            showSuccess('Ready to create another waybill!');
                                        }, 1000);
                                    }
                                }, 3000);
                            } else {
                                // Error handling with field highlighting
                                if (response.data && response.data.errors) {
                                    // Handle field-specific errors
                                    response.data.errors.forEach(function(error) {
                                        showError(error.message, error.field);
                                    });
                                } else {
                                    showError(response.data.message || 'An error occurred while saving the waybill.');
                                }
                            }
                        })
                        .fail(function(xhr, status, error) {
                            let errorMessage = 'An error occurred while processing your request.';
                            
                            // Try to parse error response
                            if (xhr.responseJSON && xhr.responseJSON.data) {
                                errorMessage = xhr.responseJSON.data.message || errorMessage;
                            } else if (xhr.responseText) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    errorMessage = response.data?.message || errorMessage;
                                } catch (e) {
                                    errorMessage = 'Network error: ' + error;
                                }
                            }
                            
                            showError(errorMessage);
                        })
                        .always(function() {
                            $submitBtn.html(originalBtnText).prop('disabled', false);
                        });
                });

                // Initialize variables
                const ajaxurl = window.ajax_url || window.myPluginAjax?.ajax_url;
                const adminurl = window.myPluginAjax?.admin_url || window.location.origin + '/wp-admin/';

                // Refresh waybills table
                function refreshWaybillsTable(customerId) {
                    $('#waybillsTableBody').hide();
                    $('#waybillsLoading').show();
                    $('#waybillsEmpty').hide();

                    $.ajax({
                        url: myPluginAjax.ajax_url,
                        type: 'GET',
                        data: {
                            action: 'get_customer_waybills',
                            customer_id: customerId,
                            _ajax_nonce: myPluginAjax.nonces.getall
                        },
                        success: function(response) {
                            if (response.success && response.data?.waybills) {
                                renderWaybillsTable(response.data.waybills);
                            } else {
                                $('#waybillsEmpty').show();
                            }
                        },
                        error: function(xhr, status, error) {
                            showError('Error loading waybills: ' + error);
                        },
                        complete: function() {
                            $('#waybillsLoading').hide();
                        }
                    });
                }

                // Render waybills in table
                function renderWaybillsTable(waybills) {
                    const $tbody = $('#waybillsTableBody');
                    $tbody.empty();

                    if (waybills.length === 0) {
                        $('#waybillsEmpty').show();
                        return;
                    }

                    waybills.forEach(function(waybill) {
                        const statusClass = {
                            'pending': 'bg-yellow-100 text-yellow-800',
                            'shipped': 'bg-blue-100 text-blue-800',
                            'delivered': 'bg-green-100 text-green-800',
                            'cancelled': 'bg-red-100 text-red-800'
                        } [waybill.status.toLowerCase()] || 'bg-gray-100 text-gray-800';

                        $tbody.append(`
                <tr data-waybill-id="${waybill.waybill_id}">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-xs font-medium text-blue-600">${waybill.waybill_no}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-xs text-gray-500">${new Date(waybill.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${statusClass}">
                            ${waybill.status.charAt(0).toUpperCase() + waybill.status.slice(1).toLowerCase()}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-xs text-gray-500">${waybill.item_count || 0}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-xs font-medium">R${waybill.total_amount ? parseFloat(waybill.total_amount).toFixed(2) : '0.00'}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-500">
                        <a href="${adminurl}admin.php?page=08600-Waybill-view&waybill_id=${waybill.waybill_id}&waybill_atts=view_waybill" 
                           class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                        <a href="${adminurl}admin.php?page=08600-Waybill-print&waybill_id=${waybill.waybill_id}" 
                           class="text-indigo-600 hover:text-indigo-900" target="_blank">Print</a>
                        <button class="delete-waybill bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm font-medium transition-colors duration-200 ml-3" 
                                data-waybill-id="${waybill.waybill_id}">Delete</button>
                    </td>
                </tr>`);
                    });

                    $tbody.show();
                    checkEmptyTable();
                }

                // Check if table is empty
                function checkEmptyTable() {
                    if ($('#waybillsTableBody tr').length === 0) {
                        $('#waybillsEmpty').show();
                    } else {
                        $('#waybillsEmpty').hide();
                    }
                }
            });
        <?php endif; ?>
    </script>
<?php
    return ob_get_clean();
}
add_shortcode('waybill_multiform', 'kit_render_waybill_multiform');
