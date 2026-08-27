<?php
if (!defined('ABSPATH')) {
    exit;
}

// Include user roles for permission checking
require_once plugin_dir_path(__FILE__) . '../user-roles.php';

// Include quick stats component
if (!class_exists('KIT_QuickStats')) {
    require_once plugin_dir_path(__FILE__) . '../components/quickStats.php';
}

// Handle form submission
if (isset($_POST['assign_waybills']) && wp_verify_nonce($_POST['nonce'], 'assign_waybills_nonce')) {
    global $wpdb;
    
    $waybill_ids = $_POST['waybill_ids'] ?? [];
    $delivery_id = intval($_POST['delivery_id']);
    
    if (!empty($waybill_ids) && $delivery_id > 0) {
        $updated = 0;
        foreach ($waybill_ids as $waybill_id) {
            $result = $wpdb->update(
                $wpdb->prefix . 'kit_waybills',
                [
                    'delivery_id' => $delivery_id,
                    'status' => 'assigned',
                    'last_updated_at' => current_time('mysql'),
                    'last_updated_by' => get_current_user_id()
                ],
                ['id' => intval($waybill_id)]
            );
            if ($result !== false) {
                $updated++;
            }
        }
        
        if (!class_exists('KIT_Toast')) {
            require_once plugin_dir_path(__FILE__) . '../components/toast.php';
        }
        KIT_Toast::ensure_toast_loads();
        
        if ($updated > 0) {
            echo KIT_Toast::success('Successfully assigned ' . $updated . ' waybill(s) to delivery!', 'Success');
        } else {
            echo KIT_Toast::error('Failed to assign waybills. Please try again.', 'Error');
        }
    }
}

// Get warehouse waybills (waybills with warehouse status)
global $wpdb;
$warehouse_waybills_query = "
    SELECT DISTINCT w.*, c.name as customer_name, c.surname as customer_surname,
           COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) as company_name
    FROM {$wpdb->prefix}kit_waybills w
    LEFT JOIN {$wpdb->prefix}kit_customers c ON w.customer_id = c.cust_id
    LEFT JOIN {$wpdb->prefix}kit_company_customers co ON w.company_id = co.company_id
    LEFT JOIN {$wpdb->prefix}kit_company_customers cust_co ON c.company_id = cust_co.company_id
    WHERE w.warehouse IS NOT NULL AND w.warehouse != ''
    ORDER BY w.created_at DESC
";
$warehouse_waybills = $wpdb->get_results($warehouse_waybills_query);

// Get available deliveries (excluding warehouse delivery)
$deliveries_query = "
    SELECT d.*, 
           sd.description as route_description,
           oc1.country_name as origin_country,
           oc2.country_name as destination_country
    FROM {$wpdb->prefix}kit_deliveries d
    LEFT JOIN {$wpdb->prefix}kit_shipping_directions sd ON d.direction_id = sd.id
    LEFT JOIN {$wpdb->prefix}kit_operating_countries oc1 ON sd.origin_country_id = oc1.id
    LEFT JOIN {$wpdb->prefix}kit_operating_countries oc2 ON sd.destination_country_id = oc2.id
    WHERE d.status = 'scheduled' AND d.id != 1
    ORDER BY d.dispatch_date ASC
";
$available_deliveries = $wpdb->get_results($deliveries_query);

$total_warehouse_waybills = count($warehouse_waybills);
$total_available_deliveries = count($available_deliveries);
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Assign Waybills to Deliveries</h1>
    <hr class="wp-header-end">

    <!-- Statistics Cards -->
    <div class="mb-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php
            $assign_stats = [
                [
                    'title' => 'Warehouse Waybills',
                    'value' => number_format($total_warehouse_waybills),
                    'subtitle' => 'Ready for assignment',
                    'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                    'color' => 'blue'
                ],
                [
                    'title' => 'Available Deliveries',
                    'value' => number_format($total_available_deliveries),
                    'subtitle' => 'Scheduled trucks',
                    'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                    'color' => 'green'
                ]
            ];
            echo KIT_QuickStats::render($assign_stats, '', [
                'grid_cols' => 'grid-cols-1 md:grid-cols-2',
                'gap' => 'gap-6'
            ]);
            ?>
            
            <!-- Quick Actions Card (kept separate as it's not a standard stat) -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                <h3 class="text-sm font-medium text-gray-600 mb-4">Quick Actions</h3>
                <div class="flex flex-col gap-2">
                    <a href="?page=warehouse-waybills" class="inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                        View Warehouse
                    </a>
                    <a href="?page=kit-deliveries" class="inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium">
                        Manage Deliveries
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($total_warehouse_waybills > 0 && $total_available_deliveries > 0): ?>
        <!-- Assignment Form -->
        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 mb-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Assign Waybills to Delivery</h2>
            
            <form method="post" id="assign-waybills-form">
                <?php wp_nonce_field('assign_waybills_nonce', 'nonce'); ?>
                
                <!-- Delivery Selection -->
                <div class="mb-6">
                    <label for="delivery_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Select Delivery (Truck)
                    </label>
                    <select name="delivery_id" id="delivery_id" required 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Choose a delivery...</option>
                        <?php foreach ($available_deliveries as $delivery): ?>
                            <option value="<?php echo $delivery->id; ?>">
                                🚛 <?php echo esc_html($delivery->delivery_reference); ?> - 
                                <?php echo esc_html($delivery->route_description); ?> 
                                (<?php echo esc_html($delivery->origin_country); ?> → <?php echo esc_html($delivery->destination_country); ?>)
                                - <?php echo date('M j, Y', strtotime($delivery->dispatch_date)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Waybills Selection -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Select Waybills to Assign
                    </label>
                    <div class="bg-gray-50 p-4 rounded-lg max-h-96 overflow-y-auto">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($warehouse_waybills as $waybill): ?>
                                <div class="waybill-card bg-white p-4 rounded-lg border border-gray-200 hover:border-blue-300 transition-colors">
                                    <label class="flex items-start space-x-3 cursor-pointer">
                                        <input type="checkbox" name="waybill_ids[]" value="<?php echo $waybill->id; ?>" 
                                               class="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between">
                                                <p class="text-sm font-medium text-gray-900">
                                                    #<?php echo $waybill->waybill_no; ?>
                                                </p>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                    R<?php echo KIT_User_Roles::can_see_prices() ? number_format($waybill->product_invoice_amount, 2) : '***'; ?>
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <?php echo esc_html($waybill->customer_name . ' ' . $waybill->customer_surname); ?>
                                            </p>
                                            <?php if ($waybill->company_name): ?>
                                                <p class="text-xs text-gray-500">
                                                    <?php echo esc_html($waybill->company_name); ?>
                                                </p>
                                            <?php endif; ?>
                                            <div class="flex items-center mt-2 text-xs text-gray-500">
                                                <span>📦 <?php echo $waybill->total_mass_kg; ?>kg</span>
                                                <span class="mx-2">•</span>
                                                <span>📅 <?php echo date('M j', strtotime($waybill->created_at)); ?></span>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-4">
                        <?php echo KIT_Commons::renderButton('Select All', 'primary', 'sm', ['id' => 'select-all-btn', 'type' => 'button']); ?>
                        <?php echo KIT_Commons::renderButton('Deselect All', 'secondary', 'sm', ['id' => 'deselect-all-btn', 'type' => 'button']); ?>
                        <span id="selected-count" class="text-sm text-gray-500">0 waybills selected</span>
                    </div>
                    
                    <?php echo KIT_Commons::renderButton('Assign to Delivery', 'primary', 'lg', [
                        'type' => 'submit',
                        'name' => 'assign_waybills',
                        'id' => 'assign-btn',
                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>',
                        'iconPosition' => 'left',
                        'classes' => 'disabled:opacity-50 disabled:cursor-not-allowed',
                        'gradient' => true
                    ]); ?>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- Empty State -->
        <div class="bg-white p-12 rounded-lg shadow-sm border border-gray-200 text-center">
            <?php if ($total_warehouse_waybills === 0): ?>
                <div class="text-gray-400 text-6xl mb-4">📦</div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No waybills in warehouse</h3>
                <p class="text-gray-500 mb-4">All warehouse waybills have been assigned or there are no waybills in the warehouse.</p>
                <a href="?page=warehouse-waybills" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    View Warehouse
                </a>
            <?php elseif ($total_available_deliveries === 0): ?>
                <div class="text-gray-400 text-6xl mb-4">🚛</div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No available deliveries</h3>
                <p class="text-gray-500 mb-4">There are no scheduled deliveries available for assignment.</p>
                <a href="?page=kit-deliveries" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    Create Delivery
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Quick Links -->
    <div class="quick-links mt-8 grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="?page=warehouse-waybills" class="block p-4 bg-blue-600 text-white no-underline rounded-md text-center hover:bg-blue-700 transition-colors">
            <strong>View Warehouse</strong>
        </a>
        <a href="?page=kit-deliveries" class="block p-4 bg-blue-600 text-white no-underline rounded-md text-center hover:bg-blue-700 transition-colors">
            <strong>Manage Deliveries</strong>
        </a>
        <a href="?page=08600-waybill-manage" class="block p-4 bg-blue-600 text-white no-underline rounded-md text-center hover:bg-blue-700 transition-colors">
            <strong>All Waybills</strong>
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllBtn = document.getElementById('select-all-btn');
    const deselectAllBtn = document.getElementById('deselect-all-btn');
    const selectedCountSpan = document.getElementById('selected-count');
    const assignBtn = document.getElementById('assign-btn');
    const deliverySelect = document.getElementById('delivery_id');
    const waybillCheckboxes = document.querySelectorAll('input[name="waybill_ids[]"]');
    
    // Update selected count
    function updateSelectedCount() {
        const selectedCount = document.querySelectorAll('input[name="waybill_ids[]"]:checked').length;
        selectedCountSpan.textContent = `${selectedCount} waybill${selectedCount !== 1 ? 's' : ''} selected`;
        
        // Enable/disable assign button
        const hasSelection = selectedCount > 0;
        const hasDelivery = deliverySelect.value !== '';
        assignBtn.disabled = !hasSelection || !hasDelivery;
    }
    
    // Select all waybills
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            waybillCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectedCount();
        });
    }
    
    // Deselect all waybills
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            waybillCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectedCount();
        });
    }
    
    // Listen for checkbox changes
    waybillCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    
    // Listen for delivery selection changes
    if (deliverySelect) {
        deliverySelect.addEventListener('change', updateSelectedCount);
    }
    
    // Form validation
    const form = document.getElementById('assign-waybills-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const selectedWaybills = document.querySelectorAll('input[name="waybill_ids[]"]:checked');
            const deliveryId = deliverySelect.value;
            
            if (selectedWaybills.length === 0) {
                e.preventDefault();
                alert('Please select at least one waybill to assign.');
                return;
            }
            
            if (!deliveryId) {
                e.preventDefault();
                alert('Please select a delivery.');
                return;
            }
            
            // Confirm assignment
            if (!confirm(`Are you sure you want to assign ${selectedWaybills.length} waybill(s) to this delivery?`)) {
                e.preventDefault();
                return;
            }
        });
    }
    
    // Initial count update
    updateSelectedCount();
    
    // Enhanced hover effects for waybill cards
    const waybillCards = document.querySelectorAll('.waybill-card');
    waybillCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '';
        });
    });
});
</script>

<style>
    /* Hide WordPress admin footer text that's overlapping */
    .wp-footer, 
    .wp-admin .wp-footer,
    .wp-admin .wp-footer a,
    .wp-admin .wp-footer p {
        display: none !important;
    }
    
    /* Hide any overlapping WordPress notices */
    .notice,
    .updated,
    .error,
    .warning {
        position: relative !important;
        z-index: 1 !important;
    }
    
    /* Ensure content has proper z-index */
    .wrap {
        position: relative;
        z-index: 10;
    }
    
    /* Hide specific overlapping text */
    .wp-admin .wrap:after,
    .wp-admin .wrap:before {
        display: none !important;
    }
    
    /* Hide WordPress footer text specifically */
    .wp-admin .wrap p:contains("Thank you for creating with WordPress"),
    .wp-admin .wrap p:contains("Version"),
    .wp-admin .wrap:contains("Thank you for creating with WordPress"),
    .wp-admin .wrap:contains("Version 6.8.2") {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
    }
    
    /* Force hide any overlapping elements */
    .wp-admin .wrap > *:last-child:not(.quick-links) {
        display: none !important;
    }
</style>
