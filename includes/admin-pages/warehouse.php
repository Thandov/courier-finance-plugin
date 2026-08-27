<?php
if (!defined('ABSPATH')) {
    exit;
}

// Include user roles for permission checking
require_once plugin_dir_path(__FILE__) . '../user-roles.php';

// Include Dashboard Quickies component
require_once plugin_dir_path(__FILE__) . '../components/dashboardQuickies.php';
require_once plugin_dir_path(__FILE__) . '../components/quickStats.php';

// Include warehouse functions
require_once plugin_dir_path(__FILE__) . '../warehouse/warehouse-functions.php';
// Include modal component
require_once plugin_dir_path(__FILE__) . '../components/modal.php';
// Include deliveries functions for delivery form
require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
// Ensure unified table class is available for rendering tables
if (!class_exists('KIT_Unified_Table')) {
    $unified_path = plugin_dir_path(__FILE__) . '../class-unified-table.php';
    if (file_exists($unified_path)) {
        require_once $unified_path;
    }
}

$warehouse_assign_flash = KIT_Warehouse::consumeAssignFlash();
$tracking_rows = KIT_Warehouse::getWarehouseItems();
$countries_data = KIT_Warehouse::getCountriesWithAssignableDeliveries();
$deliveries = KIT_Warehouse::getAssignableDeliveries();

$country_options = ['' => __('Choose country…', '08600-services-quotations')];
if (! empty($countries_data)) {
    foreach ($countries_data as $country) {
        $label = $country['name'];
        if (! empty($country['deliveries'])) {
            $label .= ' (' . count($country['deliveries']) . ')';
        }
        $country_options[(string) $country['id']] = $label;
    }
} else {
    $country_options[''] = __('No active countries found.', '08600-services-quotations');
}

$delivery_options = ['' => __('Choose delivery…', '08600-services-quotations')];
foreach ($deliveries as $d) {
    $delivery_options[(string) $d->id] = $d->delivery_reference . ' — ' . ($d->dispatch_date ?: 'TBD');
}
?>

<div class="wrap kit-warehouse-page">
    <div class="<?php echo KIT_Commons::containerClasses(); ?>">
        <?php
        echo KIT_Commons::showingHeader([
            'title' => __('Warehouse', '08600-services-quotations'),
            'desc' => __('Assign waybills in the warehouse queue to a scheduled delivery.', '08600-services-quotations'),
            'icon' => KIT_Commons::icon('warehouse'),
        ]);
        ?>
        <hr class="wp-header-end">

        <?php
        if (! empty($warehouse_assign_flash['message'])) {
            if (! class_exists('KIT_Toast')) {
                require_once plugin_dir_path(__FILE__) . '../components/toast.php';
            }
            KIT_Toast::ensure_toast_loads();
            if (($warehouse_assign_flash['type'] ?? '') === 'success') {
                echo KIT_Toast::success($warehouse_assign_flash['message'], 'Success');
            } else {
                echo KIT_Toast::error($warehouse_assign_flash['message'], 'Error');
            }
        }

        echo KIT_QuickStats::render_for_context(KIT_QuickStats::CONTEXT_WAREHOUSE, []);
        ?>

        <div class="mb-8 space-y-6">
            <?php if (empty($tracking_rows)): ?>
                <div class="border border-gray-200 bg-white px-6 py-10 text-center">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2"><?php echo esc_html__('Warehouse queue is empty', '08600-services-quotations'); ?></h3>
                    <p class="text-gray-600 mb-6 max-w-md mx-auto"><?php echo esc_html__('No waybills are waiting in warehouse. Create a warehouse waybill, or review the full waybill list.', '08600-services-quotations'); ?></p>
                    <div class="flex gap-3 justify-center flex-wrap">
                        <?php echo KIT_Commons::renderButton(__('Create waybill', '08600-services-quotations'), 'primary', 'md', [
                            'href' => '?page=08600-waybill-create',
                            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />',
                            'iconPosition' => 'left',
                        ]); ?>
                        <?php echo KIT_Commons::renderButton(__('Manage waybills', '08600-services-quotations'), 'secondary', 'md', [
                            'href' => '?page=08600-waybill-manage',
                            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />',
                            'iconPosition' => 'left',
                        ]); ?>
                    </div>
                </div>
            <?php else: ?>
                    <form method="post" id="assign-warehouse-form" class="kit-warehouse-assign-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-kit-no-submit-loading="1">
                        <?php wp_nonce_field('assign_warehouse_items', 'assign_nonce'); ?>
                        <input type="hidden" name="action" value="kit_warehouse_assign">
                        <input type="hidden" name="assign_warehouse_items" value="1">
                        <input type="hidden" name="warehouse_page" value="warehouse-waybills">
                        <input type="hidden" name="waybill_ids_bulk" id="waybill_ids_bulk" value="">
                        <input type="hidden" name="delivery_id" id="delivery_id" value="">

                        <div id="warehouse-two-col-grid" class="grid grid-cols-1 md:grid-cols-12 gap-6">
                            <div id="warehouse-left-col" class="col-span-1 md:col-span-3 min-w-0">
                                <div id="warehouse-assign-panel" class="bg-white border border-gray-200 px-4 py-4 space-y-4">
                                    <div>
                                        <h2 class="text-base font-semibold text-gray-900 m-0"><?php echo esc_html__('Assign to delivery', '08600-services-quotations'); ?></h2>
                                        <p class="mt-1 text-sm text-gray-600 m-0"><?php echo esc_html__('Choose a truck, select waybills, then assign.', '08600-services-quotations'); ?></p>
                                    </div>

                                    <div class="w-full space-y-3">
                                        <div class="w-full">
                                            <?php KIT_Commons::simpleSelect(__('Destination country', '08600-services-quotations'), 'country_id', 'country_id_select', $country_options, null); ?>
                                        </div>
                                        <div class="w-full">
                                            <?php KIT_Commons::simpleSelect(__('Delivery', '08600-services-quotations'), 'delivery_id_select', 'delivery_id_select', $delivery_options, null); ?>
                                        </div>

                                        <div id="selected-delivery-info" class="hidden p-2.5 bg-gray-50 border border-gray-200">
                                            <p class="text-sm font-medium text-gray-900 m-0"><?php echo esc_html__('Selected', '08600-services-quotations'); ?></p>
                                            <p id="selected-delivery-text" class="text-xs text-gray-600 mt-1 m-0"></p>
                                        </div>
                                    </div>

                                    <div class="pt-3 border-t border-gray-200 space-y-3">
                                        <?php echo KIT_Commons::renderButton(__('Assign to delivery', '08600-services-quotations'), 'primary', 'md', [
                                            'type' => 'submit',
                                            'id' => 'assign-btn',
                                            'classes' => 'disabled:opacity-50 disabled:cursor-not-allowed w-full',
                                            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />',
                                            'iconPosition' => 'left',
                                        ]); ?>

                                        <div id="assign-loading" class="hidden text-center">
                                            <div class="inline-flex items-center gap-2 text-sm text-gray-700 font-medium">
                                                <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                <span><?php echo esc_html__('Assigning…', '08600-services-quotations'); ?></span>
                                            </div>
                                        </div>

                                        <div class="flex flex-col gap-2">
                                            <div class="flex items-center justify-between gap-2 flex-wrap">
                                                <div class="flex items-center gap-3">
                                                    <span id="sel-count" class="text-sm font-medium text-gray-600">0 selected</span>
                                                    <span id="sel-badge" class="hidden inline-flex items-center text-xs font-semibold text-green-800">
                                                        <?php echo esc_html__('Ready', '08600-services-quotations'); ?>
                                                    </span>
                                                </div>
                                                <a href="#" data-modal="add-delivery-modal" class="text-sm font-medium text-gray-800 hover:text-black underline-offset-2 hover:underline">
                                                    <?php echo esc_html__('New delivery', '08600-services-quotations'); ?>
                                                </a>
                                            </div>
                                            <div class="flex flex-wrap gap-3 text-sm">
                                                <button type="button" id="select-all" class="font-medium text-gray-800 hover:text-black bg-transparent border-0 p-0 cursor-pointer underline-offset-2 hover:underline"><?php echo esc_html__('Select all', '08600-services-quotations'); ?></button>
                                                <button type="button" id="deselect-all" class="font-medium text-gray-600 hover:text-gray-900 bg-transparent border-0 p-0 cursor-pointer underline-offset-2 hover:underline"><?php echo esc_html__('Clear', '08600-services-quotations'); ?></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right column: standard unified table card (matches Waybill Manage / Drivers) -->
                            <div id="warehouse-table-col" class="col-span-1 md:col-span-9 w-full min-w-0">
                                <div class="overflow-hidden min-w-0">
                                    <?php
                                    // Helper function to get week label from date
                                    function get_week_label($date_string)
                                    {
                                        if (empty($date_string)) {
                                            return 'Unassigned Week';
                                        }
                                        $date = new DateTime($date_string);
                                        $day_of_week = (int)$date->format('w');
                                        $days_to_monday = $day_of_week == 0 ? 6 : $day_of_week - 1;
                                        $monday = clone $date;
                                        $monday->modify("-{$days_to_monday} days");
                                        $sunday = clone $monday;
                                        $sunday->modify('+6 days');
                                        $monday_str = $monday->format('M j');
                                        $sunday_str = $sunday->format('M j, Y');
                                        if ($monday->format('M Y') === $sunday->format('M Y')) {
                                            return 'Week of ' . $monday_str . ' - ' . $sunday->format('j, Y');
                                        }
                                        return 'Week of ' . $monday_str . ' - ' . $sunday_str;
                                    }

                                    // Convert warehouse items to data array for unified table
                                    $table_data = [];
                                    foreach ($tracking_rows as $row) {
                                        $date_string = $row->last_updated_at ?? $row->created_at ?? '';
                                        $waybill_no = $row->waybill_no ?? '';

                                        // Get dimensions
                                        $item_length = isset($row->item_length) ? floatval($row->item_length) : 0;
                                        $item_width = isset($row->item_width) ? floatval($row->item_width) : 0;
                                        $item_height = isset($row->item_height) ? floatval($row->item_height) : 0;

                                        // Show actual dimensions
                                        $dimension_display = ($item_length > 0 && $item_width > 0 && $item_height > 0)
                                            ? number_format($item_length, 1) . ' × ' . number_format($item_width, 1) . ' × ' . number_format($item_height, 1) . ' cm'
                                            : 'N/A';

                                        // Format total mass
                                        $total_mass_display = number_format((float)($row->total_mass_kg ?? 0), 2);

                                        $table_data[] = [
                                            'waybill_id' => $waybill_no,
                                            'waybill_no' => $waybill_no,
                                            'description' => $row->description ?? '',
                                            'waybill_db_id' => intval($row->id ?? 0),
                                            'customer_name' => trim(($row->customer_name ?? '') . ' ' . ($row->customer_surname ?? '')),
                                            'total_mass_kg' => $total_mass_display,
                                            'dimension' => $dimension_display,
                                            'action' => ucfirst($row->status ?? ''),
                                            'created_at' => $date_string,
                                            'week' => get_week_label($date_string),
                                        ];
                                    }

                                    // Define columns for the unified table
                                    $columns = [
                                        'checkbox' => [
                                            'label' => '',
                                            'header_class' => 'text-center w-12 min-w-12',
                                            'header_callback' => function () {
                                                return KIT_Commons::Lcheckbox([
                                                    'no_label' => true,
                                                    'label' => '',
                                                    'id' => 'warehouse-select-all-checkbox',
                                                    'name' => '',
                                                    'value' => '1',
                                                    'class' => 'w-5 h-5 rounded border-2 border-gray-300 bg-white text-blue-600 focus:ring-2 focus:ring-blue-500 cursor-pointer',
                                                    'special' => 'title="' . esc_attr__('Select all', '08600-services-quotations') . '" aria-label="' . esc_attr__('Select all waybills', '08600-services-quotations') . '"',
                                                ]);
                                            },
                                            'callback' => function ($value, $row) {
                                                return KIT_Commons::Lcheckbox([
                                                    'no_label' => true,
                                                    'label' => '',
                                                    'name' => 'waybill_ids[]',
                                                    'omit_id' => true,
                                                    'value' => (string) intval($row['waybill_db_id']),
                                                    'class' => 'wi waybill-checkbox w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500',
                                                ]);
                                            }
                                        ],
                                        'waybill_no' => [
                                            'label' => 'Waybill',
                                            'callback' => function ($value, $row) {
                                                $db_id = intval($row['waybill_db_id'] ?? 0);
                                                $url = admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . urlencode($db_id));
                                                return '<a href="' . esc_url($url) . '" class="font-semibold text-blue-600 hover:text-blue-800 hover:underline transition-colors">#' . esc_html($value) . '</a>';
                                            }
                                        ],
                                        'description' => [
                                            'label' => 'Description',
                                            'header_class' => 'text-left align-top',
                                            'header_style' => 'width:16rem;max-width:16rem;',
                                            'cell_class' => 'text-left text-sm align-top whitespace-normal break-words',
                                            'cell_style' => 'width:16rem;max-width:16rem;vertical-align:top;',
                                            'callback' => function ($value, $row) {
                                                $desc = trim((string) ($row['description'] ?? $value ?? ''));
                                                if ($desc === '') {
                                                    return '<span class="text-gray-400">—</span>';
                                                }
                                                return '<span class="block break-words whitespace-normal leading-snug">' . esc_html($desc) . '</span>';
                                            }
                                        ],
                                        'customer_name' => 'Customer',
                                        'total_mass_kg' => 'Weight (kg)',
                                        'dimension' => 'Dimension',
                                        'action' => 'Status',
                                        'created_at' => 'Updated'
                                    ];

                                    echo KIT_Unified_Table::infinite($table_data, $columns, KIT_Unified_Table::optionsWithManageDefaults([
                                        'title' => __('Queue', '08600-services-quotations'),
                                        'subtitle' => __('Select waybills for the delivery on the left.', '08600-services-quotations'),
                                        'table_class' => 'w-full table-fixed border-collapse',
                                        'searchable' => true,
                                        'sortable' => true,
                                        'search_placeholder' => __('Search waybills…', '08600-services-quotations'),
                                        'empty_message' => __('No items currently in warehouse', '08600-services-quotations'),
                                        'bulk_management' => false,
                                        'bulk_actions_list' => [],
                                        'delivery_list_print' => false,
                                        'exportable' => false,
                                        'table_type' => false,
                                        'row_attrs_callback' => null,
                                        'groupby' => 'week',
                                        'preserve_order' => false,
                                        'group_collapsible' => true,
                                        'group_collapsed' => false,
                                    ]));
                                    ?>
                                </div>
                            </div>
                        </div>
                    </form>
            <?php endif; ?>
        </div>

        <!-- Add New Delivery Modal -->
        <?php
        $delivery_form_content = KIT_Deliveries::deliveryForm(null, true);
        echo KIT_Modal::render(
            'add-delivery-modal',
            'Add New Delivery',
            $delivery_form_content,
            'lg',
            false
        );
        ?>

        <style>
            @media (min-width: 768px) {
                #assign-warehouse-form #warehouse-two-col-grid {
                    display: grid !important;
                    grid-template-columns: repeat(12, 1fr);
                    gap: 1.5rem;
                    /* Stretch so left col is as tall as the queue — required for sticky. */
                    align-items: stretch;
                }

                #assign-warehouse-form #warehouse-left-col {
                    grid-column: span 3;
                    min-height: 100%;
                }

                #assign-warehouse-form #warehouse-table-col {
                    grid-column: span 9;
                    min-width: 0;
                }

                /* Stick while the queue scrolls; offset for WP admin bar. */
                #assign-warehouse-form #warehouse-assign-panel {
                    position: sticky;
                    top: 48px;
                    z-index: 10;
                    max-height: calc(100vh - 64px);
                    overflow-y: auto;
                }
            }

            @media screen and (max-width: 782px) {
                #assign-warehouse-form #warehouse-assign-panel {
                    position: static;
                    max-height: none;
                    overflow: visible;
                }
            }

            .kit-warehouse-page tr.waybill-selected {
                background-color: #f3f4f6 !important;
                box-shadow: inset 3px 0 0 #111827;
            }

            .kit-warehouse-page tr.waybill-selected:hover {
                background-color: #e5e7eb !important;
            }

            @keyframes kit-warehouse-spin {
                to { transform: rotate(360deg); }
            }

            .kit-warehouse-page .animate-spin {
                animation: kit-warehouse-spin 1s linear infinite;
            }
        </style>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const headerSelect = document.getElementById('warehouse-select-all-checkbox');
                const selectAll = document.getElementById('select-all');
                const deselectAll = document.getElementById('deselect-all');
                const count = document.getElementById('sel-count');
                const assignBtn = document.getElementById('assign-btn');
                const deliverySelect = document.getElementById('delivery_id');
                const deliverySelectFallback = document.getElementById('delivery_id_select');
                const countrySelect = document.getElementById('country_id_select');
                const selectedDeliveryInfo = document.getElementById('selected-delivery-info');
                const selectedDeliveryText = document.getElementById('selected-delivery-text');
                const countriesData = <?php echo wp_json_encode($countries_data ?? []); ?> || [];

                function getWaybillCheckboxes() {
                    const selectors = [
                        'input[name="waybill_ids[]"]',
                        'input.waybill-checkbox',
                        'input.wi.waybill-checkbox',
                        'input.wi[name="waybill_ids[]"]'
                    ];
                    const checkboxes = [];
                    selectors.forEach(selector => {
                        document.querySelectorAll(selector).forEach(cb => {
                            if (!checkboxes.includes(cb)) {
                                checkboxes.push(cb);
                            }
                        });
                    });
                    return checkboxes;
                }

                function refresh() {
                    const boxes = getWaybillCheckboxes();
                    const n = boxes.filter(b => b.checked).length;
                    const deliverySelected = (deliverySelect && deliverySelect.value && deliverySelect.value !== '') ||
                        (deliverySelectFallback && deliverySelectFallback.value && deliverySelectFallback.value !== '');

                    if (count) {
                        if (n === 0) {
                            count.textContent = '0 selected';
                            count.className = 'text-sm font-medium text-gray-500';
                        } else {
                            count.textContent = `${n} waybill${n !== 1 ? 's' : ''} selected`;
                            count.className = 'text-sm font-semibold text-gray-900';
                        }
                    }

                    const badge = document.getElementById('sel-badge');
                    if (badge) {
                        if (n > 0 && deliverySelected) {
                            badge.classList.remove('hidden');
                            badge.classList.add('inline-flex');
                        } else {
                            badge.classList.add('hidden');
                            badge.classList.remove('inline-flex');
                        }
                    }

                    if (assignBtn) {
                        assignBtn.disabled = !(n > 0 && deliverySelected);
                    }

                    // Highlight selected rows
                    boxes.forEach(box => {
                        const row = box.closest('tr');
                        if (row) {
                            if (box.checked) {
                                row.classList.add('waybill-selected');
                            } else {
                                row.classList.remove('waybill-selected');
                            }
                        }
                    });

                    if (headerSelect && boxes.length) {
                        const checked = boxes.filter(b => b.checked).length;
                        headerSelect.checked = checked === boxes.length;
                        headerSelect.indeterminate = checked > 0 && checked < boxes.length;
                    } else if (headerSelect) {
                        headerSelect.checked = false;
                        headerSelect.indeterminate = false;
                    }
                }

                // Selection handlers using event delegation for dynamic content
                if (headerSelect) {
                    headerSelect.addEventListener('change', () => {
                        const state = headerSelect.checked;
                        const boxes = getWaybillCheckboxes();
                        boxes.forEach(b => b.checked = state);
                        refresh();
                    });
                }

                if (selectAll) {
                    selectAll.addEventListener('click', () => {
                        const boxes = getWaybillCheckboxes();
                        boxes.forEach(b => b.checked = true);
                        if (headerSelect) headerSelect.checked = true;
                        refresh();
                    });
                }

                if (deselectAll) {
                    deselectAll.addEventListener('click', () => {
                        const boxes = getWaybillCheckboxes();
                        boxes.forEach(b => b.checked = false);
                        if (headerSelect) headerSelect.checked = false;
                        refresh();
                    });
                }

                // Use event delegation for checkboxes (handles dynamically loaded content)
                document.addEventListener('change', function(e) {
                    if (e.target && (e.target.classList.contains('wi') || e.target.classList.contains('waybill-checkbox') || e.target.name === 'waybill_ids[]')) {
                        refresh();
                    }
                });

                // Delivery selection handlers
                function updateDeliverySelection() {
                    // Check both hidden field and dropdown for delivery ID
                    const selectedDeliveryId = (deliverySelect && deliverySelect.value) || (deliverySelectFallback && deliverySelectFallback.value) || '';
                    let selectedOptionText = '';

                    // Get selected option text from the visible dropdown
                    if (deliverySelectFallback && deliverySelectFallback.selectedIndex >= 0) {
                        const selectedOption = deliverySelectFallback.options[deliverySelectFallback.selectedIndex];
                        if (selectedOption && selectedOption.value) {
                            selectedOptionText = selectedOption.text;
                            // Ensure hidden field is also set
                            if (deliverySelect && !deliverySelect.value) {
                                deliverySelect.value = selectedOption.value;
                            }
                        }
                    }

                    if (selectedDeliveryId && selectedOptionText) {
                        if (selectedDeliveryInfo) {
                            selectedDeliveryInfo.classList.remove('hidden');
                        }
                        if (selectedDeliveryText) {
                            selectedDeliveryText.textContent = selectedOptionText;
                        }
                    } else {
                        if (selectedDeliveryInfo) {
                            selectedDeliveryInfo.classList.add('hidden');
                        }
                    }
                    // IMPORTANT: Call refresh to update button state
                    refresh();
                }

                if (deliverySelectFallback) {
                    deliverySelectFallback.addEventListener('change', function() {
                        if (deliverySelect) {
                            deliverySelect.value = this.value;
                        }
                        updateDeliverySelection();
                    });
                }

                let filterDeliveriesByCountryFn = null;
                let allDeliveryOptions = [];

                if (deliverySelectFallback && countrySelect) {
                    for (let i = 1; i < deliverySelectFallback.options.length; i++) {
                        allDeliveryOptions.push({
                            value: deliverySelectFallback.options[i].value,
                            text: deliverySelectFallback.options[i].text,
                            element: deliverySelectFallback.options[i].cloneNode(true)
                        });
                    }

                    filterDeliveriesByCountryFn = function(countryId) {
                        if (!deliverySelectFallback) {
                            return;
                        }

                        while (deliverySelectFallback.options.length > 1) {
                            deliverySelectFallback.remove(1);
                        }

                        if (countryId && Array.isArray(countriesData) && countriesData.length > 0) {
                            const country = countriesData.find(c => parseInt(c.id, 10) === parseInt(countryId, 10));

                            if (country && country.deliveries && country.deliveries.length > 0) {
                                country.deliveries.forEach(delivery => {
                                    const option = document.createElement('option');
                                    option.value = delivery.id;
                                    option.textContent = delivery.reference + ' — ' + (delivery.dispatch_date || 'TBD');
                                    deliverySelectFallback.appendChild(option);
                                });
                            } else {
                                const option = document.createElement('option');
                                option.value = '';
                                option.textContent = 'No deliveries available for this country';
                                option.disabled = true;
                                deliverySelectFallback.appendChild(option);
                            }
                        } else {
                            allDeliveryOptions.forEach(opt => {
                                deliverySelectFallback.appendChild(opt.element.cloneNode(true));
                            });
                        }
                    };
                }

                if (countrySelect) {
                    countrySelect.addEventListener('change', function() {
                        const countryId = parseInt(this.value, 10);

                        if (deliverySelect) {
                            deliverySelect.value = '';
                        }
                        if (deliverySelectFallback) {
                            deliverySelectFallback.value = '';
                        }

                        if (filterDeliveriesByCountryFn) {
                            filterDeliveriesByCountryFn(countryId);
                        }

                        updateDeliverySelection();
                        refresh();
                    });
                }

                // Initialize: If a country is already selected on page load, filter the dropdown
                if (countrySelect && countrySelect.value && filterDeliveriesByCountryFn) {
                    const initialCountryId = parseInt(countrySelect.value);
                    if (initialCountryId) {
                        filterDeliveriesByCountryFn(initialCountryId);
                    }
                }

                const assignForm = document.getElementById('assign-warehouse-form');
                const waybillIdsBulk = document.getElementById('waybill_ids_bulk');
                if (assignForm) {
                    assignForm.addEventListener('submit', function(e) {
                        if (deliverySelectFallback && deliverySelectFallback.value && deliverySelect) {
                            deliverySelect.value = deliverySelectFallback.value;
                        }

                        const checkedIds = getWaybillCheckboxes()
                            .filter(function(b) { return b.checked; })
                            .map(function(b) { return b.value; })
                            .filter(function(v) { return v && String(v).trim() !== ''; });

                        if (waybillIdsBulk) {
                            waybillIdsBulk.value = checkedIds.join(',');
                        }

                        if (checkedIds.length === 0) {
                            e.preventDefault();
                            window.alert('Please select at least one waybill to assign.');
                            return;
                        }

                        if (!deliverySelect || !deliverySelect.value) {
                            e.preventDefault();
                            window.alert('Please select a delivery before assigning.');
                            return;
                        }

                        const assignBtnEl = document.getElementById('assign-btn');
                        if (assignBtnEl && typeof KitButton !== 'undefined') {
                            KitButton.setLoading(assignBtnEl, true);
                        }
                    });
                }

                // Initial refresh
                refresh();

                // Watch for dynamically loaded table content
                const tableContainer = document.querySelector('.unified-table-container, [id*="unified-table"], table');
                if (tableContainer) {
                    const observer = new MutationObserver(function(mutations) {
                        // When table content changes, refresh the selection state
                        let shouldRefresh = false;
                        mutations.forEach(function(mutation) {
                            if (mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0) {
                                shouldRefresh = true;
                            }
                        });
                        if (shouldRefresh) {
                            refresh();
                        }
                    });

                    observer.observe(tableContainer, {
                        childList: true,
                        subtree: true
                    });
                }
            });
        </script>
    </div>
</div>