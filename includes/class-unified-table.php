<?php

/**
 * KIT Unified Table Class
 * 
 * A unified table system for displaying data with advanced features like
 * pagination, sorting, searching, and actions.
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Unified_Table
{
    /**
     * Default <tr> attributes for the Waybill Manage dashboard (KPI filters, etc.).
     *
     * @param array|object $row
     * @return array<string, string>
     */
    public static function defaultManageRowAttrs($row, $rowIndex)
    {
        $r = is_array($row) ? $row : (array) $row;
        if (!empty($r['__group_row'])) {
            return [];
        }
        $attrs = [];
        $wh = !empty($r['is_warehoused'])
            || (isset($r['warehouse']) && (int) $r['warehouse'] === 1);
        $attrs['data-warehouse'] = $wh ? '1' : '0';
        $st = isset($r['status']) ? strtolower((string) $r['status']) : '';
        $attrs['data-status'] = $st;
        if (!empty($r['created_at'])) {
            $attrs['data-created-at'] = (string) $r['created_at'];
        }
        return $attrs;
    }

    /**
     * Same option bundle as admin Waybill Manage (08600-waybill-manage). Merge per-page keys
     * (title, actions, sync_entity, empty_message, search_filters, etc.).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function optionsWithManageDefaults(array $overrides = [])
    {
        $base = [
            'table_class' => 'w-full table-auto border-collapse kit-waybill-dashboard-table',
            'searchable' => true,
            'sortable' => true,
            'exportable' => true,
            'bulk_management' => true,
            'bulk_actions_list' => ['delete', 'export', 'packing_list', 'move_to_warehouse'],
            'delivery_list_print' => true,
            'groupby' => 'city',
            'group_heading_prefix' => '',
            'preserve_order' => true,
            'group_collapsible' => true,
            'group_collapsed' => false,
            'table_type' => true,
            'row_attrs_callback' => [__CLASS__, 'defaultManageRowAttrs'],
        ];
        return array_merge($base, $overrides);
    }

    /**
     * Labels for unified-table bulk action select options.
     *
     * @return array<string, string>
     */
    public static function bulkActionLabels(): array
    {
        return [
            'delete' => __('Delete', '08600-services-quotations'),
            'export' => __('Export to PDF', '08600-services-quotations'),
            'packing_list' => __('Packing list', '08600-services-quotations'),
            'move_to_warehouse' => __('Move to Warehouse', '08600-services-quotations'),
            'status_active' => __('Set Active', '08600-services-quotations'),
            'status_inactive' => __('Set Inactive', '08600-services-quotations'),
        ];
    }

    /**
     * Sort rows by waybill number descending (numeric prefix, then string, then id).
     *
     * @param array|object $a
     * @param array|object $b
     */
    public static function sortRowsByWaybillNoDesc($a, $b): int
    {
        $aVal = is_object($a) ? get_object_vars($a) : (array) $a;
        $bVal = is_object($b) ? get_object_vars($b) : (array) $b;
        $aNo = (string) ($aVal['waybill_no'] ?? '');
        $bNo = (string) ($bVal['waybill_no'] ?? '');
        // Cast, never add: rows without a waybill number (deliveries, drivers)
        // pass an empty string here, and "" + 0 is a TypeError on PHP 8.
        $aNum = (float) $aNo;
        $bNum = (float) $bNo;
        if ($aNum !== $bNum) {
            return $bNum <=> $aNum;
        }
        $strCmp = strcmp($bNo, $aNo);
        if ($strCmp !== 0) {
            return $strCmp;
        }
        $aId = (int) ($aVal['waybill_id'] ?? $aVal['id'] ?? 0);
        $bId = (int) ($bVal['waybill_id'] ?? $bVal['id'] ?? 0);
        return $bId <=> $aId;
    }

    /**
     * WP admin uses appearance:none + a Dashicons ::before glyph.
     * Tailwind size/border/bg-white on these inputs clips that glyph, so
     * checked boxes look empty while the bulk bar still says "N selected".
     * Emit with the table so pages that do not load dashboard.css still work.
     */
    public static function bulk_checkbox_css(): string
    {
        return <<<'CSS'
input.bulk-row-checkbox[type="checkbox"],
input.bulk-select-all-checkbox[type="checkbox"] {
    -webkit-appearance: none !important;
    appearance: none !important;
    width: 1.125rem !important;
    height: 1.125rem !important;
    min-width: 1.125rem !important;
    margin: 0 !important;
    padding: 0 !important;
    display: inline-block !important;
    position: relative !important;
    vertical-align: middle;
    background: #fff !important;
    border: 2px solid #111 !important;
    border-radius: 4px !important;
    box-shadow: none !important;
    line-height: 0 !important;
    cursor: pointer;
    overflow: visible !important;
    opacity: 1 !important;
    visibility: visible !important;
    color: transparent !important;
}
input.bulk-row-checkbox[type="checkbox"]:checked,
input.bulk-select-all-checkbox[type="checkbox"]:checked {
    background: #1d4ed8 !important;
    border-color: #1d4ed8 !important;
}
input.bulk-row-checkbox[type="checkbox"]:checked::before,
input.bulk-row-checkbox[type="checkbox"]:checked:before,
input.bulk-select-all-checkbox[type="checkbox"]:checked::before,
input.bulk-select-all-checkbox[type="checkbox"]:checked:before {
    content: "" !important;
    display: block !important;
    position: absolute !important;
    left: 0.28rem;
    top: 0.05rem;
    width: 0.28rem !important;
    height: 0.5rem !important;
    margin: 0 !important;
    padding: 0 !important;
    float: none !important;
    border: solid #fff !important;
    border-width: 0 2px 2px 0 !important;
    transform: rotate(45deg);
    font-family: inherit !important;
    font-size: 0 !important;
    line-height: 0 !important;
    color: transparent !important;
    background: none !important;
    box-shadow: none !important;
    speak: never;
}
input.bulk-row-checkbox[type="checkbox"]:focus,
input.bulk-select-all-checkbox[type="checkbox"]:focus {
    outline: 2px solid #93c5fd;
    outline-offset: 2px;
    box-shadow: none !important;
}
th.kit-bulk-select-all-cell {
    overflow: visible !important;
    position: relative;
    z-index: 2;
    text-align: center;
    vertical-align: middle;
}
.kit-waybill-dashboard-table tr.kit-row-selected td,
table tr.kit-row-selected td {
    background-color: #eff6ff;
}
.kit-waybill-dashboard-table td.bulk-checkbox-cell,
.kit-waybill-dashboard-table td.kit-checkbox-only-cell,
td.bulk-checkbox-cell,
td.kit-checkbox-only-cell {
    overflow: visible;
}
CSS;
    }

    /**
     * Single-row table toolbar: view, search, and selection actions.
     */
    public static function toolbar_css(): string
    {
        return <<<'CSS'
.kit-table-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 10px;
    padding: 10px 16px;
    border-top: 1px solid #e5e7eb;
    background: #fafafa;
}
.kit-table-seg {
    display: inline-flex;
    flex-shrink: 0;
    border: 1px solid #d1d5db;
    background: #fff;
}
.kit-table-seg__btn {
    height: 36px;
    padding: 0 12px;
    margin: 0;
    border: 0;
    border-right: 1px solid #d1d5db;
    background: #fff;
    color: #111;
    font-size: 13px;
    font-weight: 600;
    line-height: 36px;
    cursor: pointer;
}
.kit-table-seg__btn:last-child { border-right: 0; }
.kit-table-seg__btn.is-active {
    background: #1d4ed8;
    color: #fff;
}
.kit-table-seg__btn:focus-visible {
    outline: 2px solid #93c5fd;
    outline-offset: -2px;
}
.kit-table-toolbar__find {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1 1 14rem;
    min-width: 14rem;
}
.kit-table-toolbar.is-selecting .kit-table-toolbar__find {
    display: none;
}
.kit-table-toolbar__search {
    flex: 1 1 auto;
    min-width: 10rem;
}
.kit-table-toolbar__search-input,
.kit-table-toolbar__search input[type="text"] {
    width: 100% !important;
    height: 36px !important;
    min-height: 36px !important;
    padding: 0 10px 0 32px !important;
    font-size: 13px !important;
    line-height: 36px !important;
    border: 1px solid #d1d5db !important;
    border-radius: 4px !important;
    background: #fff !important;
    box-sizing: border-box;
}
.kit-table-toolbar__filter,
.kit-table-toolbar__select {
    height: 36px !important;
    min-height: 36px !important;
    max-height: 36px !important;
    padding: 0 28px 0 10px !important;
    margin: 0 !important;
    font-size: 13px !important;
    line-height: 34px !important;
    border: 1px solid #d1d5db !important;
    border-radius: 4px !important;
    background: #fff !important;
    color: #111 !important;
    -webkit-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 20 20' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpath d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    cursor: pointer;
}
.kit-table-toolbar__select {
    flex: 0 1 13rem;
    min-width: 11rem;
    max-width: 16rem;
}
.kit-table-toolbar__filter {
    flex: 0 0 8.5rem;
    width: 8.5rem;
}
.kit-table-toolbar__bulk {
    display: none;
    align-items: center;
    gap: 8px;
    flex: 1 1 auto;
    min-width: 0;
}
.kit-table-toolbar.is-selecting .kit-table-toolbar__bulk {
    display: flex;
    flex-wrap: wrap;
}
.kit-table-toolbar__count {
    font-size: 13px;
    font-weight: 650;
    color: #111;
    white-space: nowrap;
}
.kit-table-toolbar__ok,
.kit-table-toolbar__ok.button,
button.kit-table-toolbar__ok {
    height: 36px !important;
    min-height: 36px !important;
    padding: 0 14px !important;
    min-width: 3.25rem !important;
    font-size: 13px !important;
    font-weight: 650 !important;
    line-height: 1 !important;
    background: #1d4ed8 !important;
    color: #fff !important;
    border: 1px solid #1d4ed8 !important;
    border-radius: 4px !important;
    cursor: pointer;
}
.kit-table-toolbar__ok:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.kit-table-toolbar__clear,
.kit-table-toolbar__print,
.kit-table-toolbar__icon-btn {
    height: 36px !important;
    min-height: 36px !important;
    padding: 0 10px !important;
    font-size: 13px !important;
    line-height: 1 !important;
    white-space: nowrap;
}
.kit-table-toolbar__print { flex-shrink: 0; }
@media (max-width: 640px) {
    .kit-table-toolbar__find { min-width: 100%; flex-basis: 100%; }
    .kit-table-toolbar__select { min-width: 9rem; }
}
CSS;
    }

    /**
     * Print table chrome CSS once per request (body-safe; works after admin head).
     */
    public static function ensure_bulk_checkbox_styles(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        echo '<style id="kit-unified-table-chrome">' . self::bulk_checkbox_css() . self::toolbar_css() . '</style>';
    }

    /**
     * Render a table with infinite scroll enabled.
     * Simple implementation that shows all data.
     */
    public static function infinite($data, $columns, $options = [])
    {
        $defaults = [
            'title' => '',
            'subtitle' => '',
            'actions' => [],
            'searchable' => false,
            'sortable' => true,
            'exportable' => false,
            'bulk_actions' => false,
            'selectable' => false,
            'bulk_management' => false,
            'bulk_actions_list' => [], // ['delete', 'export', 'packing_list', 'move_to_warehouse', 'status_active', 'status_inactive']
            'delivery_list_print' => false, // Waybills: toolbar "Print delivery list" (visible rows only)
            'bulk_action_handler' => null, // Callback function to handle bulk actions
            'empty_message' => 'No data found',
            'table_class' => 'w-full table-auto border-collapse',
            'header_base_class' => 'px-3 py-2 text-xs font-semibold text-left uppercase tracking-wide',
            'cell_base_class' => 'px-3 py-2 text-sm text-gray-900 whitespace-normal break-words align-top',
            'index_cell_class' => 'px-3 py-2 text-sm font-medium text-gray-600 whitespace-nowrap text-center',
            'actions_cell_class' => 'px-3 py-2 text-sm font-medium text-gray-900 whitespace-normal break-words align-top flex flex-wrap items-center gap-2',
            'primary_action' => null,
            'items_per_page' => 100,
            'current_page' => 1,
            // Optional: callback to add attributes to each <tr>. Signature: function($row, $rowIndex): array
            'row_attrs_callback' => null,
            'groupby' => null,
            'group_heading_prefix' => '',
            'group_heading_cell_class' => 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-700 bg-gray-100',
            'group_heading_row_class' => 'bg-gray-50 border-0',
            'preserve_order' => false,
            'group_collapsible' => false,
            'group_collapsed' => false,
            'group_toggle_button_class' => 'w-full text-left flex items-center justify-between gap-2',
            'group_toggle_icon_class' => 'transition-transform duration-150 ease-in-out',
            'group_toggle_icon_collapsed' => 'rotate-0',
            'group_toggle_icon_expanded' => 'rotate-90',
            'dropdowns' => false, // Enable dropdown components
            'dropdown_config' => [], // Configuration for dropdown components (e.g., customer bulk invoice)
            'table_type' => false, // Enable view toggle buttons (grouped vs infinite scroll)
            'search_placeholder' => 'Search...', // Custom search placeholder text
            'search_filters' => [], // Custom search filter options: [['value' => 'field', 'label' => 'Label'], ...]
            'search_default_filter' => null, // Default filter value
            'sync_entity' => null, // If set (drivers|customers|deliveries|waybills), show sync dropdown
            'max_height' => '', // e.g. '60vh' or '500px' — fixed-height scrollable table body
        ];

        $options = array_merge($defaults, $options);
        $table_max_height = is_string($options['max_height'] ?? '') ? trim((string) $options['max_height']) : '';
        $table_scroll_style = $table_max_height !== ''
            ? 'max-height:' . esc_attr($table_max_height) . ';overflow-y:auto;'
            : '';

        $bulk_actions_for_print = $options['bulk_actions_list'] ?? [];
        $needs_waybill_list_print_globals = (
            (! empty($options['delivery_list_print']) && filter_var($options['delivery_list_print'], FILTER_VALIDATE_BOOLEAN))
            || in_array('packing_list', $bulk_actions_for_print, true)
        );

        // Store original groupby value for view toggling
        $original_groupby = $options['groupby'];

        // Ensure bulk_management is boolean - explicitly convert to boolean
        // Handle string 'true', boolean true, integer 1, etc.
        if (isset($options['bulk_management'])) {
            $options['bulk_management'] = filter_var($options['bulk_management'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $options['bulk_management'] = false;
        }

        self::ensure_bulk_checkbox_styles();

        // Show ALL data initially - no pagination for infinite scroll
        $total_items = count($data);
        $display_data = $data; // Show all items

        // Default sort unless caller preserves order: waybill tables by waybill_no DESC; else created_at / id
        if (!empty($display_data) && !$options['preserve_order']) {
            $sample = reset($display_data);
            $sampleArray = is_object($sample) ? get_object_vars($sample) : (array) $sample;

            if (array_key_exists('waybill_no', $sampleArray)) {
                usort($display_data, [__CLASS__, 'sortRowsByWaybillNoDesc']);
            } else {
                $sortKey = null;
                if (array_key_exists('created_at', $sampleArray)) {
                    $sortKey = 'created_at';
                } elseif (array_key_exists('updated_at', $sampleArray)) {
                    $sortKey = 'updated_at';
                } elseif (array_key_exists('waybill_id', $sampleArray)) {
                    $sortKey = 'waybill_id';
                } elseif (array_key_exists('id', $sampleArray)) {
                    $sortKey = 'id';
                }

                if ($sortKey) {
                    usort($display_data, function ($a, $b) use ($sortKey) {
                        $aVal = is_object($a) ? get_object_vars($a) : (array) $a;
                        $bVal = is_object($b) ? get_object_vars($b) : (array) $b;

                        $aValue = $aVal[$sortKey] ?? null;
                        $bValue = $bVal[$sortKey] ?? null;

                        if ($aValue === $bValue) {
                            return 0;
                        }

                        // Attempt to compare as timestamps/numbers first
                        $aNumeric = is_numeric($aValue) ? (float) $aValue : strtotime((string) $aValue);
                        $bNumeric = is_numeric($bValue) ? (float) $bValue : strtotime((string) $bValue);

                        if ($aNumeric !== false && $bNumeric !== false) {
                            return $bNumeric <=> $aNumeric; // Descending
                        }

                        return strcasecmp((string) $bValue, (string) $aValue);
                    });
                }
            }
        }

        // View toggle (table_type): default is ungrouped ("All Waybills"). City grouping only when ?view=grouped.
        $view_param = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : null;
        if ($options['table_type'] === true) {
            if ($view_param !== 'grouped' || empty($original_groupby)) {
                $options['groupby'] = null;
            }
        }

        $groupField = !empty($options['groupby']) ? $options['groupby'] : null;
        if ($groupField) {
            $groupedDisplayData = [];
            $currentGroupLabel = null;
            $currentGroupId = '';
            $groupIndex = 0;
            $groupCounts = []; // Track counts per group
            $groupHeadersCreated = []; // Track which group headers we've already created

            // First pass: count items per group
            foreach ($display_data as $row) {
                $rowArray = is_object($row) ? get_object_vars($row) : (array) $row;
                $groupValue = $rowArray[$groupField] ?? '';

                if (is_scalar($groupValue)) {
                    $groupLabel = (string) $groupValue;
                } elseif (is_object($groupValue) && method_exists($groupValue, '__toString')) {
                    $groupLabel = (string) $groupValue;
                } else {
                    $groupLabel = '';
                }

                if ($groupLabel === '') {
                    $groupLabel = 'Unassigned City';
                }

                if (!isset($groupCounts[$groupLabel])) {
                    $groupCounts[$groupLabel] = 0;
                }
                $groupCounts[$groupLabel]++;
            }

            // Always sort by group field when grouping to ensure all items of the same group are together
            // This is necessary for proper grouping even if preserve_order is true
            // Also sort by customer_name within each group
            usort($display_data, function ($a, $b) use ($groupField) {
                $aArray = is_object($a) ? get_object_vars($a) : (array) $a;
                $bArray = is_object($b) ? get_object_vars($b) : (array) $b;

                $aValue = $aArray[$groupField] ?? '';
                $bValue = $bArray[$groupField] ?? '';

                if (is_scalar($aValue)) {
                    $aLabel = (string) $aValue;
                } elseif (is_object($aValue) && method_exists($aValue, '__toString')) {
                    $aLabel = (string) $aValue;
                } else {
                    $aLabel = '';
                }

                if (is_scalar($bValue)) {
                    $bLabel = (string) $bValue;
                } elseif (is_object($bValue) && method_exists($bValue, '__toString')) {
                    $bLabel = (string) $bValue;
                } else {
                    $bLabel = '';
                }

                if ($aLabel === '') $aLabel = 'Unassigned City';
                if ($bLabel === '') $bLabel = 'Unassigned City';

                // First sort by group field (city)
                $groupCompare = strcasecmp($aLabel, $bLabel);
                if ($groupCompare !== 0) {
                    return $groupCompare;
                }

                // Same group: latest waybill number first (match getAllWaybills / flat view)
                return self::sortRowsByWaybillNoDesc($a, $b);
            });

            // Second pass: build grouped data with counts
            foreach ($display_data as $row) {
                $rowArray = is_object($row) ? get_object_vars($row) : (array) $row;
                $groupValue = $rowArray[$groupField] ?? '';

                if (is_scalar($groupValue)) {
                    $groupLabel = (string) $groupValue;
                } elseif (is_object($groupValue) && method_exists($groupValue, '__toString')) {
                    $groupLabel = (string) $groupValue;
                } else {
                    $groupLabel = '';
                }

                if ($groupLabel === '') {
                    $groupLabel = 'Unassigned City';
                }

                // Only create a new group header if we haven't seen this group label yet
                if (!isset($groupHeadersCreated[$groupLabel])) {
                    $groupIndex++;
                    $currentGroupId = 'group-' . $groupIndex;
                    $groupCount = $groupCounts[$groupLabel] ?? 0;
                    $groupedDisplayData[] = [
                        '__group_row'   => true,
                        '__group_label' => $groupLabel,
                        '__group_id'    => $currentGroupId,
                        '__group_collapsed' => !empty($options['group_collapsed']),
                        '__group_count' => $groupCount,
                    ];
                    $groupHeadersCreated[$groupLabel] = $currentGroupId;
                    $currentGroupLabel = $groupLabel;
                } else {
                    // Use the existing group ID for this label
                    $currentGroupId = $groupHeadersCreated[$groupLabel];
                }

                if (is_array($row)) {
                    $row['__group_id'] = $currentGroupId;
                } elseif (is_object($row)) {
                    $row->__group_id = $currentGroupId;
                }

                $groupedDisplayData[] = $row;
            }

            $display_data = $groupedDisplayData;
        }

        $hasGroupRows = !empty($groupField);

        // Build unique IDs for infinite scroll
        $table_id = 'kit-infinite-table-' . uniqid();
        $container_id = 'kit-infinite-wrap-' . uniqid();

        ob_start();
?>
        <div id="<?php echo esc_attr($container_id); ?>" class="kit-unified-table-wrap w-full max-w-full min-w-0 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden" style="box-sizing: border-box;">
            <?php if ($options['title'] || $options['subtitle'] || $options['table_type'] || !empty($options['primary_action']) || !empty($options['sync_entity'])): ?>
                <div class="px-4 sm:px-6 pt-5 sm:pt-6 pb-3 grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                    <div class="min-w-0">
                        <?php if ($options['title']): ?>
                            <h3 class="text-lg font-semibold text-gray-900 leading-tight"><?php echo esc_html($options['title']); ?></h3>
                        <?php endif; ?>
                        <?php if ($options['subtitle']): ?>
                            <p class="text-sm text-gray-600 mt-0.5"><?php echo esc_html($options['subtitle']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap items-center justify-start sm:justify-end gap-2 sm:gap-3 min-w-0">
                        <?php if (!empty($options['primary_action'])): ?>
                            <!-- Primary Action Button -->
                            <a href="<?php echo esc_url($options['primary_action']['href'] ?? '#'); ?>"
                                class="<?php echo esc_attr($options['primary_action']['class'] ?? 'w-full sm:w-auto text-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition'); ?>">
                                <?php echo esc_html($options['primary_action']['label'] ?? 'Add New'); ?>
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($options['sync_entity']) && in_array($options['sync_entity'], ['drivers', 'customers', 'deliveries', 'waybills'], true)): ?>
                            <?php
                            $sync_path = dirname(__FILE__) . '/components/sync-buttons.php';
                            if (file_exists($sync_path)) {
                                require_once $sync_path;
                                if (function_exists('kit_render_sync_buttons')) {
                                    kit_render_sync_buttons(['entity' => $options['sync_entity'], 'label' => ucfirst($options['sync_entity'])]);
                                }
                            }
                            ?>
                        <?php endif; ?>
                        <?php if (!empty($options['dropdowns']) && $options['dropdowns'] === true): ?>
                            <?php
                            // Include customer bulk invoice dropdown component if configured
                            if (!empty($options['dropdown_config']['customer_bulk_invoice'])) {
                                $dropdown_path = dirname(__FILE__) . '/components/customerBulkInvoiceDropdown.php';
                                if (file_exists($dropdown_path)) {
                                    require_once $dropdown_path;

                                    $dropdown_config = $options['dropdown_config']['customer_bulk_invoice'];
                                    // Merge with default data from table
                                    if (!isset($dropdown_config['data'])) {
                                        $dropdown_config['data'] = $data;
                                    }

                                    echo render_customer_bulk_invoice_dropdown($dropdown_config);
                                }
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($display_data)): ?>
                <div class="px-6 py-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                    </svg>
                    <p class="text-sm text-gray-500"><?php echo wp_kses_post($options['empty_message']); ?></p>
                </div>
            <?php else: ?>
                <?php
                $show_toolbar = ($options['table_type'] === true) || ($options['searchable'] === true) || ($options['bulk_management'] === true);
                $bulk_actions = $options['bulk_actions_list'] ?? [];
                if (empty($bulk_actions)) {
                    $bulk_actions = ['delete', 'export'];
                }
                $bulk_action_labels = self::bulkActionLabels();
                $bulk_select_id = 'bulk-action-select-' . $table_id;
                $view_is_grouped = ($view_param === 'grouped' && $hasGroupRows);
                ?>
                <?php if ($show_toolbar): ?>
                <div
                    id="kit-table-toolbar-<?php echo esc_attr($table_id); ?>"
                    class="kit-table-toolbar"
                    data-table-id="<?php echo esc_attr($table_id); ?>"
                >
                    <?php if ($options['table_type'] === true): ?>
                        <div class="kit-table-seg" role="group" aria-label="<?php esc_attr_e('Table view', '08600-services-quotations'); ?>">
                            <button
                                type="button"
                                id="view-toggle-infinite-<?php echo esc_attr($table_id); ?>"
                                class="kit-table-seg__btn<?php echo $view_is_grouped ? '' : ' is-active'; ?>"
                                data-view="infinite"
                                data-table-id="<?php echo esc_attr($table_id); ?>"
                                aria-pressed="<?php echo $view_is_grouped ? 'false' : 'true'; ?>"
                            ><?php esc_html_e('All', '08600-services-quotations'); ?></button>
                            <button
                                type="button"
                                id="view-toggle-grouped-<?php echo esc_attr($table_id); ?>"
                                class="kit-table-seg__btn<?php echo $view_is_grouped ? ' is-active' : ''; ?>"
                                data-view="grouped"
                                data-table-id="<?php echo esc_attr($table_id); ?>"
                                aria-pressed="<?php echo $view_is_grouped ? 'true' : 'false'; ?>"
                            ><?php esc_html_e('By city', '08600-services-quotations'); ?></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($options['searchable'] === true): ?>
                        <div class="kit-table-toolbar__find">
                            <div class="kit-table-toolbar__search">
                                <?php echo KIT_Commons::Linput([
                                    'no_label' => true,
                                    'label' => '',
                                    'name' => '',
                                    'id' => 'infinite-table-search-' . $table_id,
                                    'type' => 'text',
                                    'value' => '',
                                    'placeholder' => $options['search_placeholder'],
                                    'aria_label' => $options['search_placeholder'],
                                    'preset' => '',
                                    'class' => 'kit-table-toolbar__search-input',
                                    'special' => 'autocomplete="off"',
                                    'icon' => '<svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width: 16px; height: 16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>',
                                ]); ?>
                            </div>
                            <?php if (!empty($options['search_filters'])): ?>
                                <?php $default_filter = $options['search_default_filter'] ?? ($options['search_filters'][0]['value'] ?? ''); ?>
                                <select
                                    id="search-filter-type-<?php echo esc_attr($table_id); ?>"
                                    class="kit-table-toolbar__filter"
                                    aria-label="<?php esc_attr_e('Search in', '08600-services-quotations'); ?>"
                                >
                                    <?php foreach ($options['search_filters'] as $filter): ?>
                                        <option value="<?php echo esc_attr($filter['value']); ?>" <?php echo ($filter['value'] === $default_filter) ? 'selected' : ''; ?>>
                                            <?php echo esc_html($filter['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <?php echo KIT_Commons::renderButton('', 'ghost', 'sm', [
                                'type' => 'button',
                                'id' => 'clear-infinite-search-' . esc_attr($table_id),
                                'classes' => 'kit-table-toolbar__icon-btn ' . KIT_Commons::unifiedTableSearchClearButtonExtraClasses(),
                                'title' => 'Clear search',
                                'ariaLabel' => 'Clear search',
                                'iconOnly' => true,
                                'noLoading' => true,
                                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>',
                            ]); ?>
                            <?php
                            if (! empty($options['delivery_list_print']) && filter_var($options['delivery_list_print'], FILTER_VALIDATE_BOOLEAN)) :
                                echo KIT_Commons::renderButton(__('Print', '08600-services-quotations'), 'secondary', 'sm', [
                                    'type' => 'button',
                                    'id' => 'print-delivery-list-' . esc_attr($table_id),
                                    'classes' => 'kit-table-toolbar__print',
                                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>',
                                    'iconPosition' => 'left',
                                    'noLoading' => true,
                                    'ariaLabel' => 'Print delivery list for visible waybills',
                                ]);
                            endif;
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($options['bulk_management'] === true): ?>
                        <div id="bulk-actions-bar-<?php echo esc_attr($table_id); ?>" class="kit-table-toolbar__bulk" hidden>
                            <span id="bulk-selected-count-<?php echo esc_attr($table_id); ?>" class="kit-table-toolbar__count">0 selected</span>
                            <label for="<?php echo esc_attr($bulk_select_id); ?>" class="sr-only"><?php esc_html_e('Action for selected rows', '08600-services-quotations'); ?></label>
                            <select
                                id="<?php echo esc_attr($bulk_select_id); ?>"
                                class="kit-table-toolbar__select"
                                data-bulk-action-select="1"
                                disabled
                            >
                                <option value=""><?php esc_html_e('Choose action…', '08600-services-quotations'); ?></option>
                                <?php foreach ($bulk_actions as $action): ?>
                                    <?php
                                    $action_key = is_string($action) ? $action : '';
                                    $action_label = $bulk_action_labels[$action_key] ?? '';
                                    if ($action_label === '') {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr($action_key); ?>"><?php echo esc_html($action_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button
                                type="button"
                                id="bulk-action-ok-<?php echo esc_attr($table_id); ?>"
                                class="kit-table-toolbar__ok"
                                disabled
                                aria-label="<?php esc_attr_e('Confirm action on selected waybills', '08600-services-quotations'); ?>"
                            ><?php esc_html_e('OK', '08600-services-quotations'); ?></button>
                            <?php echo KIT_Commons::renderButton(__('Clear', '08600-services-quotations'), 'ghost', 'sm', [
                                'type' => 'button',
                                'id' => 'bulk-clear-selection-' . esc_attr($table_id),
                                'classes' => 'kit-table-toolbar__clear',
                                'noLoading' => true,
                                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>',
                                'iconPosition' => 'left',
                            ]); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Table Container -->
                <div class="px-3 sm:px-6 overflow-x-auto max-w-full<?php echo $table_max_height !== '' ? ' kit-table-scroll' : ''; ?>"<?php echo $table_scroll_style !== '' ? ' style="' . $table_scroll_style . '"' : ''; ?>>
                            <table id="<?php echo esc_attr($table_id); ?>" class="<?php echo esc_attr($options['table_class']); ?> min-w-0" style="width:100%;" <?php if ($hasGroupRows): ?> data-has-group-rows="1" <?php endif; ?> data-original-groupby="<?php echo esc_attr($original_groupby ?? ''); ?>" data-current-view="<?php echo ($view_param === 'grouped' && $hasGroupRows) ? 'grouped' : 'infinite'; ?>">
                                <thead class="bg-gray-50<?php echo $table_max_height !== '' ? ' sticky top-0 z-10 shadow-sm' : ''; ?>">
                                    <tr>
                                        <?php if ($options['bulk_management'] === true): ?>
                                            <!-- Bulk selection checkbox header (native hit target only — do not expand cell click to mass-select) -->
                                            <th class="<?php echo esc_attr($options['header_base_class']); ?> w-12 text-center kit-bulk-select-all-cell" style="width: 48px;">
                                                <?php
                                                echo KIT_Commons::Lcheckbox([
                                                    'no_label' => true,
                                                    'label' => '',
                                                    'name' => '',
                                                    'id' => 'bulk-select-all-' . $table_id,
                                                    'value' => '1',
                                                    'class' => 'bulk-select-all-checkbox w-5 h-5 rounded border-2 border-black bg-white text-blue-700 focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 cursor-pointer transition-all shadow-sm',
                                                    'special' => 'title="Select all" aria-label="Select all rows" style="display: inline-block; margin: 0; cursor: pointer; pointer-events: auto; opacity: 1; visibility: visible; accent-color: #1d4ed8;"',
                                                ]);
                                                ?>
                                            </th>
                                        <?php endif; ?>
                                        <!-- Index column header -->
                                        <th class="<?php echo esc_attr($options['header_base_class']); ?> w-12 min-w-12 text-center">#</th>
                                        <?php foreach ($columns as $key => $column): ?>
                                            <?php
                                            $label = is_array($column) ? $column['label'] : $column;
                                            $columnSortable = is_array($column) && isset($column['sortable']) ? $column['sortable'] : $options['sortable'];
                                            // Skip sorting for checkbox column
                                            if ($key === 'checkbox') {
                                                $columnSortable = false;
                                            }
                                            $headerClass = $options['header_base_class'];
                                            if (is_array($column) && !empty($column['header_class'])) {
                                                $headerClass = trim($headerClass . ' ' . $column['header_class']);
                                            }
                                            // Row checkbox cells get kit-checkbox-only-cell; header select-all must not —
                                            // an expanded header hit target selects every row with one click.
                                            $headerStyle = '';
                                            if (is_array($column) && !empty($column['header_style'])) {
                                                $headerStyle = ' style="' . esc_attr($column['header_style']) . '"';
                                            }
                                            // Match sortable header flex to column alignment
                                            $isRightAligned = strpos($headerClass, 'text-right') !== false;
                                            $isCenterAligned = strpos($headerClass, 'text-center') !== false;
                                            $flexJustify = $isRightAligned ? 'justify-end' : ($isCenterAligned ? 'justify-center' : '');
                                            $headerCallback = is_array($column) && !empty($column['header_callback']) && is_callable($column['header_callback']);
                                            ?>
                                            <th<?php if ($columnSortable && !$headerCallback): ?> data-column="<?php echo esc_attr($key); ?>" <?php endif; ?> class="<?php echo esc_attr($headerClass); ?>"<?php echo $headerStyle; ?>>
                                                <?php if ($headerCallback): ?>
                                                    <?php echo call_user_func($column['header_callback'], $column, $key); ?>
                                                <?php elseif ($columnSortable): ?>
                                                    <?php echo KIT_Commons::renderButton($label, 'ghost', 'lg', [
                                                        'type' => 'button',
                                                        'classes' => 'sortable-header flex items-center gap-2 border-0 shadow-none ' . esc_attr($flexJustify) . ' text-gray-700 hover:text-gray-900 font-semibold transition-colors group w-full',
                                                        'icon' => '<path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />',
                                                        'iconPosition' => 'right',
                                                    ]); ?>
                                                <?php else: ?>
                                                    <span class="flex items-center gap-1 <?php echo esc_attr($flexJustify); ?> text-gray-700 font-semibold whitespace-normal break-words"><?php echo esc_html($label); ?></span>
                                                <?php endif; ?>
                                                </th>
                                            <?php endforeach; ?>
                                            <?php if (!empty($options['actions'])): ?>
                                                <?php
                                                // Get actions header class from options if provided
                                                if (isset($options['actions_header_class'])) {
                                                    $actions_header_class = $options['actions_header_class'];
                                                } else {
                                                    $actions_header_class = $options['header_base_class'];
                                                }
                                                ?>
                                                <th class="<?php echo esc_attr($actions_header_class); ?>">Actions</th>
                                            <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php
                                    $visibleRowCounter = 0;
                                    $totalColumns = (($options['bulk_management'] === true) ? 1 : 0) + 1 + count($columns) + (!empty($options['actions']) ? 1 : 0);
                                    ?> 
                                    <?php foreach ($display_data as $rowIndex => $row): ?>
                                        <?php
                                        if ((is_array($row) && !empty($row['__group_row'])) || (is_object($row) && !empty($row->__group_row))) {
                                            $groupLabel = is_array($row) ? ($row['__group_label'] ?? 'Unassigned City') : ($row->__group_label ?? 'Unassigned City');
                                            $groupId = is_array($row) ? ($row['__group_id'] ?? '') : ($row->__group_id ?? '');
                                            $groupCollapsed = is_array($row) ? (!empty($row['__group_collapsed'])) : (!empty($row->__group_collapsed));
                                            $groupCount = is_array($row) ? ($row['__group_count'] ?? 0) : ($row->__group_count ?? 0);
                                            $isCollapsible = !empty($options['group_collapsible']);
                                            $headingContent = trim(($options['group_heading_prefix'] ?? '') . $groupLabel);
                                            $headingCellClass = $options['group_heading_cell_class'];
                                            if ($isCollapsible) {
                                                $headingCellClass = 'p-0 align-middle bg-transparent border-0';
                                            }

                                            $gradientButtonClasses = class_exists('KIT_Commons')
                                                ? KIT_Commons::unifiedTableGroupHeaderToggleButtonClasses()
                                                : 'inline-flex w-full items-center justify-between text-left font-semibold px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 active:from-blue-800 active:to-indigo-800 text-white shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-offset-2 group-toggle';

                                            $iconClasses = 'group-toggle-icon w-4 h-4 text-white transition-transform duration-200';
                                        ?>
                                            <tr class="<?php echo esc_attr($options['group_heading_row_class']); ?>" data-group-row="1" <?php if ($groupId): ?> data-group-id="<?php echo esc_attr($groupId); ?>" <?php endif; ?> data-group-label="<?php echo esc_attr($groupLabel); ?>" data-collapsed="<?php echo $groupCollapsed ? '1' : '0'; ?>">
                                                <td colspan="<?php echo esc_attr($totalColumns); ?>" class="<?php echo esc_attr($headingCellClass); ?>">
                                                    <?php if ($isCollapsible): ?>
                                                        <?php
                                                        $groupBtnLabel = $groupCount > 0 ? '<span class="inline-flex items-center justify-center min-w-[1.5rem] h-6 px-2 text-xs font-semibold text-white bg-red-600 rounded-full leading-none">' . esc_html($groupCount) . '</span> ' . esc_html($headingContent) : 'Group';
                                                        echo KIT_Commons::renderButton($groupBtnLabel, 'primary', 'lg', [
                                                            'type' => 'button',
                                                            'classes' => $gradientButtonClasses,
                                                            'data-group-toggle' => $groupId,
                                                            'ariaExpanded' => $groupCollapsed ? 'false' : 'true',
                                                            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />',
                                                            'iconPosition' => 'right',
                                                            'rawHtml' => $groupCount > 0,
                                                        ]);
                                                        ?>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center font-semibold text-gray-700 gap-2">
                                                            <?php if ($groupCount > 0): ?>
                                                                <span class="inline-flex items-center justify-center min-w-[1.5rem] h-6 px-2 text-xs font-semibold text-white bg-red-600 rounded-full">
                                                                    <?php echo esc_html($groupCount); ?>
                                                                </span>
                                                                <?php echo esc_html($headingContent); ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php
                                            continue;
                                        }

                                        $visibleRowCounter++;

                                        $rowAttrStr = '';
                                        if (is_callable($options['row_attrs_callback'])) {
                                            $attrs = (array) call_user_func($options['row_attrs_callback'], $row, $visibleRowCounter - 1);
                                            foreach ($attrs as $attrKey => $attrVal) {
                                                $rowAttrStr .= ' ' . esc_attr($attrKey) . '="' . esc_attr((string) $attrVal) . '"';
                                            }
                                        }
                                        $rowGroupId = is_array($row) ? ($row['__group_id'] ?? '') : (is_object($row) ? ($row->__group_id ?? '') : '');
                                        if (!empty($rowGroupId)) {
                                            $rowAttrStr .= ' data-group-id="' . esc_attr($rowGroupId) . '"';
                                        }


                                        // Add data attributes for search functionality
                                        $waybillNo = is_array($row) ? ($row['waybill_no'] ?? $row['waybill_no_raw'] ?? '') : ($row->waybill_no ?? '');
                                        $customerName = is_array($row) ? ($row['customer_name'] ?? '') : ($row->customer_name ?? '');
                                        $email = is_array($row) ? ($row['email'] ?? $row['email_address'] ?? '') : ($row->email ?? $row->email_address ?? '');
                                        $destination = is_array($row) ? ($row['destination'] ?? $row['city'] ?? '') : ($row->destination ?? $row->city ?? '');

                                        // Driver-specific fields
                                        $driverName = is_array($row) ? ($row['name'] ?? '') : ($row->name ?? '');
                                        $phone = is_array($row) ? ($row['phone'] ?? '') : ($row->phone ?? '');
                                        $licenseNumber = is_array($row) ? ($row['license_number'] ?? '') : ($row->license_number ?? '');

                                        if ($waybillNo) {
                                            $rowAttrStr .= ' data-waybill-no="' . esc_attr($waybillNo) . '"';
                                        }
                                        if ($customerName) {
                                            $rowAttrStr .= ' data-customer-name="' . esc_attr($customerName) . '"';
                                        }
                                        if ($email) {
                                            $rowAttrStr .= ' data-email="' . esc_attr($email) . '"';
                                        }
                                        if ($destination) {
                                            $rowAttrStr .= ' data-destination="' . esc_attr($destination) . '"';
                                        }
                                        if ($driverName) {
                                            $rowAttrStr .= ' data-name="' . esc_attr($driverName) . '"';
                                        }
                                        if ($phone && $phone !== 'N/A') {
                                            $rowAttrStr .= ' data-phone="' . esc_attr($phone) . '"';
                                        }
                                        if ($licenseNumber && $licenseNumber !== 'N/A') {
                                            $rowAttrStr .= ' data-license-number="' . esc_attr($licenseNumber) . '"';
                                        }

                                        // Row styling
                                        $rowClass = 'hover:bg-blue-50/50 transition-colors duration-150';
                                        ?>
                                        <tr<?php echo $rowAttrStr; ?> class="<?php echo esc_attr($rowClass); ?>" data-row-id="<?php echo esc_attr(is_array($row) ? ($row['id'] ?? '') : (is_object($row) ? ($row->id ?? '') : '')); ?>">
                                            <?php if ($options['bulk_management'] === true): ?>
                                                <!-- Bulk selection checkbox cell -->
                                                <td class="<?php echo esc_attr($options['cell_base_class']); ?> w-12 text-center bulk-checkbox-cell kit-checkbox-only-cell cursor-pointer select-none" style="width: 48px; background-color: #f9fafb;">
                                                    <?php
                                                    // Prefer waybill number for waybills; otherwise numeric row id (drivers, deliveries, etc.)
                                                    if (is_array($row)) {
                                                        $row_id = $row['waybill_no'] ?? $row['waybill_no_raw'] ?? '';
                                                        if ($row_id === '' || $row_id === null) {
                                                            $row_id = isset($row['id']) ? (string) $row['id'] : '';
                                                        }
                                                    } else {
                                                        $row_id = $row->waybill_no ?? $row->waybill_no_raw ?? '';
                                                        if ($row_id === '' || $row_id === null) {
                                                            $row_id = isset($row->id) ? (string) $row->id : '';
                                                        }
                                                    }
                                                    ?>
                                                    <?php
                                                    echo KIT_Commons::Lcheckbox([
                                                        'no_label' => true,
                                                        'label' => '',
                                                        'name' => '',
                                                        'omit_id' => true,
                                                        'value' => (string) $row_id,
                                                        'class' => 'bulk-row-checkbox w-5 h-5 rounded border-2 border-black bg-white text-blue-700 focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 cursor-pointer transition-all shadow-sm',
                                                        'special' => 'data-row-id="' . esc_attr($row_id) . '" aria-label="Select row" style="display: inline-block; margin: 0; cursor: pointer; pointer-events: auto; opacity: 1; visibility: visible; accent-color: #1d4ed8;"',
                                                    ]);
                                                    ?>
                                                </td>
                                            <?php endif; ?>
                                            <!-- Index column cell -->
                                            <td class="<?php echo esc_attr($options['index_cell_class']); ?>"><?php echo esc_html($visibleRowCounter); ?></td>
                                            <?php foreach ($columns as $key => $column): ?>
                                                <?php
                                                $cellClass = $options['cell_base_class'];
                                                if (is_array($column) && isset($column['cell_class'])) {
                                                    $cellClass = trim($cellClass . ' ' . $column['cell_class']);
                                                }
                                                if ($key === 'checkbox') {
                                                    $cellClass = trim($cellClass . ' kit-checkbox-only-cell cursor-pointer select-none text-center');
                                                }
                                                $cellStyle = '';
                                                if (is_array($column) && !empty($column['cell_style'])) {
                                                    $cellStyle = ' style="' . esc_attr($column['cell_style']) . '"';
                                                }
                                                ?>
                                                <td class="<?php echo esc_attr($cellClass); ?>"<?php echo $cellStyle; ?>>
                                                    <?php
                                                    // Simple, robust value extraction
                                                    $value = '';

                                                    if (is_array($row)) {
                                                        $value = $row[$key] ?? '';
                                                    } elseif (is_object($row)) {
                                                        $value = $row->$key ?? '';
                                                    }

                                                    // Enforce price visibility for 'total' column
                                                    if ($key === 'total' && class_exists('KIT_User_Roles') && !KIT_User_Roles::can_see_prices()) {
                                                        $value = '***';
                                                    }

                                                    if (is_array($column) && isset($column['callback'])) {
                                                        echo $column['callback']($value, $row, $visibleRowCounter - 1);
                                                    } else {
                                                        echo esc_html($value);
                                                    }
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <?php if (!empty($options['actions'])): ?>
                                                <td class="<?php echo esc_attr($options['actions_cell_class']); ?>">
                                                    <?php foreach ($options['actions'] as $action): ?>
                                                        <?php
                                                        $href = $action['href'] ?? '#';
                                                        $class = $action['class'] ?? '';
                                                        $onclick = isset($action['onclick']) ? 'onclick="' . esc_attr($action['onclick']) . '"' : '';
                                                        $titleAttr = isset($action['title']) ? 'title="' . esc_attr($action['title']) . '" aria-label="' . esc_attr($action['title']) . '"' : '';
                                                        $target = isset($action['target']) ? 'target="' . esc_attr($action['target']) . '" rel="noopener"' : '';

                                                        // Use callback if provided, otherwise replace placeholders in href
                                                        if (isset($action['callback']) && is_callable($action['callback'])) {
                                                            $href = call_user_func($action['callback'], $href, $row, $visibleRowCounter - 1);
                                                        } else {
                                                            // Replace placeholders in href
                                                            if (is_array($row)) {
                                                                foreach ($row as $placeholder => $value) {
                                                                    $href = str_replace('{' . $placeholder . '}', $value, $href);
                                                                }
                                                            } elseif (is_object($row)) {
                                                                foreach ($row as $placeholder => $value) {
                                                                    $value = $value ?? ''; // Handle null values
                                                                    $href = str_replace('{' . $placeholder . '}', $value, $href);
                                                                }
                                                            }
                                                        }
                                                        ?>
                                                        <a href="<?php echo esc_url($href); ?>"
                                                            <?php echo $class ? 'class="' . esc_attr($class) . '"' : ''; ?>
                                                            <?php echo $onclick; ?> <?php echo $titleAttr; ?> <?php echo $target; ?>>
                                                            <?php
                                                            if (isset($action['is_html']) && $action['is_html']) {
                                                                echo $action['label'];
                                                            } else {
                                                                echo esc_html($action['label']);
                                                            }
                                                            ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </td>
                                            <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
            <?php endif; ?>
        </div>

        <!-- Infinite scroll specific JavaScript -->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const table = document.getElementById('<?php echo esc_js($table_id); ?>');
                if (!table) {
                    return;
                }

                const collapsedGroups = new Set();
                const searchInput = <?php echo $options['searchable'] ? "document.getElementById('infinite-table-search-" . esc_js($table_id) . "')" : 'null'; ?>;
                const searchFilterType = <?php echo $options['searchable'] ? "document.getElementById('search-filter-type-" . esc_js($table_id) . "')" : 'null'; ?>;
                const clearSearchBtn = <?php echo $options['searchable'] ? "document.getElementById('clear-infinite-search-" . esc_js($table_id) . "')" : 'null'; ?>;
                const hasBulkManagement = <?php echo ($options['bulk_management'] === true) ? 'true' : 'false'; ?>;
                const enableViewToggle = <?php echo ($options['table_type'] === true) ? 'true' : 'false'; ?>;
                const originalGroupby = table.dataset.originalGroupby || '';
                const tableData = <?php echo json_encode($data); ?>;
                const tableColumns = <?php echo json_encode($columns); ?>;
                const tableOptions = <?php echo json_encode($options); ?>;

                const reindexRows = () => {
                    let index = 1;
                    const rows = table.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        if (row.dataset.groupRow === '1') {
                            return;
                        }
                        if (row.style.display === 'none') {
                            return;
                        }
                        // Skip checkbox cell if bulk_management is enabled (first cell), index is second cell
                        const indexCellIndex = hasBulkManagement ? 1 : 0;
                        const indexCell = row.children[indexCellIndex];
                        if (indexCell && indexCell.tagName === 'TD') {
                            // Only update if it's not the checkbox cell (check for checkbox input)
                            if (!indexCell.querySelector('input[type="checkbox"]')) {
                                indexCell.textContent = index++;
                            }
                        }
                    });
                };

                // Function to get cell value by column key
                function getCellValueByColumn(row, columnKey) {
                    // Get column index from header
                    const headerRow = table.querySelector('thead tr');
                    if (!headerRow) return '';

                    const headers = Array.from(headerRow.querySelectorAll('th'));
                    let columnIndex = -1;

                    headers.forEach((th, index) => {
                        if (th.getAttribute('data-column') === columnKey) {
                            columnIndex = index;
                        }
                    });

                    if (columnIndex === -1) {
                        // Fallback: try to find by text content
                        headers.forEach((th, index) => {
                            const label = th.textContent.trim().toLowerCase();
                            const keyMap = {
                                'waybill': 'waybill_no',
                                'waybill #': 'waybill_no',
                                'customer': 'customer_name',
                                'customer name': 'customer_name',
                                'name': 'customer_name',
                                'email': 'email',
                                'destination': 'destination',
                                'city': 'destination'
                            };
                            for (let key in keyMap) {
                                if (label.includes(key) && keyMap[key] === columnKey) {
                                    columnIndex = index;
                                    break;
                                }
                            }
                        });
                    }

                    if (columnIndex === -1) return '';

                    // Adjust for bulk management checkbox (first column) and index column (second column)
                    const dataCellIndex = hasBulkManagement ? columnIndex - 1 : columnIndex - 1;
                    if (dataCellIndex < 0) return '';

                    const cell = row.children[dataCellIndex];
                    return cell ? (cell.textContent || '').trim() : '';
                }

                // Function to get search value from row based on filter type
                function getSearchValueFromRow(row, filterType) {
                    let searchValue = '';

                    switch (filterType) {
                        case 'waybill_no':
                            // Priority: data attribute > cell value > row ID
                            searchValue = (row.dataset.waybillNo || '') +
                                (row.dataset.rowId || '') +
                                getCellValueByColumn(row, 'waybill_no');
                            // Also check all cells for waybill number pattern
                            if (!searchValue || searchValue.trim() === '') {
                                Array.from(row.children).forEach(cell => {
                                    const text = cell.textContent || '';
                                    // Match waybill number patterns (numbers or alphanumeric like 4000a)
                                    const waybillMatch = text.match(/\b\d{3,}[a-z]?\b/);
                                    if (waybillMatch) {
                                        searchValue += waybillMatch[0] + ' ';
                                    }
                                });
                            }
                            break;
                        case 'name':
                            // Driver name search
                            searchValue = (row.dataset.name || '') +
                                getCellValueByColumn(row, 'name');
                            if (!searchValue || searchValue.trim() === '') {
                                Array.from(row.children).forEach(cell => {
                                    const text = cell.textContent || '';
                                    if (text.match(/^[A-Z][a-z]+\s+[A-Z]/) || text.match(/[A-Z][a-z]+\s+[A-Z][a-z]+/) || text.match(/^[A-Z][a-z]+/)) {
                                        searchValue += text + ' ';
                                    }
                                });
                            }
                            break;
                        case 'phone':
                            // Phone search
                            searchValue = (row.dataset.phone || '') +
                                getCellValueByColumn(row, 'phone');
                            if (!searchValue || searchValue.trim() === '') {
                                Array.from(row.children).forEach(cell => {
                                    const text = cell.textContent || '';
                                    // Match phone patterns
                                    if (text.match(/[\d\s\-\+\(\)]+/) && text.length > 5) {
                                        searchValue += text + ' ';
                                    }
                                });
                            }
                            break;
                        case 'license_number':
                            // License number search
                            searchValue = (row.dataset.licenseNumber || row.dataset.license_number || '') +
                                getCellValueByColumn(row, 'license_number');
                            break;
                        case 'customer_name':
                            // Priority: data attribute > cell value
                            searchValue = (row.dataset.customerName || '') +
                                getCellValueByColumn(row, 'customer_name');
                            // Fallback: search in all cells for name patterns
                            if (!searchValue || searchValue.trim() === '') {
                                Array.from(row.children).forEach(cell => {
                                    const text = cell.textContent || '';
                                    // Look for name-like patterns (two words, capitalized)
                                    if (text.match(/^[A-Z][a-z]+\s+[A-Z]/) || text.match(/[A-Z][a-z]+\s+[A-Z][a-z]+/)) {
                                        searchValue += text + ' ';
                                    }
                                });
                            }
                            break;
                        case 'email':
                            // Priority: data attribute > cell value
                            searchValue = (row.dataset.email || '') +
                                getCellValueByColumn(row, 'email');
                            // Fallback: search for email pattern in all cells
                            if (!searchValue || searchValue.trim() === '') {
                                Array.from(row.children).forEach(cell => {
                                    const text = cell.textContent || '';
                                    if (text.match(/@/)) {
                                        searchValue += text + ' ';
                                    }
                                });
                            }
                            break;
                        case 'destination':
                            // Priority: data attribute > cell value
                            searchValue = (row.dataset.destination || '') +
                                (row.dataset.city || '') +
                                getCellValueByColumn(row, 'destination') +
                                getCellValueByColumn(row, 'city');
                            break;
                        default:
                            searchValue = row.textContent || '';
                    }

                    return searchValue.toLowerCase();
                }

                let applyFilters = () => {
                    const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
                    const filterType = searchFilterType ? searchFilterType.value : 'waybill_no';
                    const rows = table.querySelectorAll('tbody tr');
                    const groupRowsMap = new Map(); // Track which groups have matching rows

                    // First pass: identify group headers and check if they have matching rows
                    rows.forEach(row => {
                        const isGroupRow = row.dataset.groupRow === '1';
                        if (isGroupRow) {
                            const groupId = row.dataset.groupId || '';
                            groupRowsMap.set(groupId, {
                                headerRow: row,
                                hasMatch: false
                            });
                            row.style.display = '';
                            return;
                        }

                        const groupId = row.dataset.groupId || '';
                        const isCollapsed = groupId !== '' && collapsedGroups.has(groupId);

                        // Get search value based on filter type
                        const searchValue = getSearchValueFromRow(row, filterType);
                        const matchesSearch = searchTerm === '' || searchValue.includes(searchTerm);

                        if (matchesSearch) {
                            const groupInfo = groupRowsMap.get(groupId);
                            if (groupInfo) {
                                groupInfo.hasMatch = true;
                            }
                            // Show/hide based on collapsed state
                            if (isCollapsed) {
                                row.style.display = 'none';
                            } else {
                                row.style.display = '';
                            }
                        } else {
                            row.style.display = 'none';
                        }
                    });

                    // Second pass: hide group headers that have no matching rows
                    groupRowsMap.forEach((info, groupId) => {
                        if (!info.hasMatch) {
                            info.headerRow.style.display = 'none';
                        }
                    });

                    reindexRows();
                };

                const updateGroupToggleVisual = (headerRow, isCollapsed) => {
                    if (!headerRow) {
                        return;
                    }
                    headerRow.dataset.collapsed = isCollapsed ? '1' : '0';
                    const toggleBtn = headerRow.querySelector('[data-group-toggle]');
                    if (toggleBtn) {
                        toggleBtn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                        const icon = toggleBtn.querySelector('.group-toggle-icon');
                        if (icon) {
                            icon.style.transform = isCollapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
                        }
                    }
                };

                const groupHeaders = table.querySelectorAll('tbody tr[data-group-row="1"]');
                groupHeaders.forEach(headerRow => {
                    const groupId = headerRow.dataset.groupId;
                    if (!groupId) {
                        return;
                    }
                    const defaultCollapsed = headerRow.dataset.collapsed === '1';
                    if (defaultCollapsed) {
                        collapsedGroups.add(groupId);
                    }
                    updateGroupToggleVisual(headerRow, collapsedGroups.has(groupId));

                    const toggleBtn = headerRow.querySelector('[data-group-toggle]');
                    if (toggleBtn) {
                        toggleBtn.addEventListener('click', function(event) {
                            event.preventDefault();
                            event.stopPropagation();

                            // Get the specific group ID from this header row
                            const clickedGroupId = headerRow.dataset.groupId;
                            if (!clickedGroupId) {
                                return;
                            }

                            const currentlyCollapsed = collapsedGroups.has(clickedGroupId);
                            if (currentlyCollapsed) {
                                collapsedGroups.delete(clickedGroupId);
                            } else {
                                collapsedGroups.add(clickedGroupId);
                            }

                            // Update only this specific group header
                            updateGroupToggleVisual(headerRow, collapsedGroups.has(clickedGroupId));

                            // Toggle only rows with this specific group ID
                            const allRowsWithGroupId = table.querySelectorAll(`tbody tr[data-group-id="${clickedGroupId}"]:not([data-group-row="1"])`);
                            allRowsWithGroupId.forEach(row => {
                                if (collapsedGroups.has(clickedGroupId)) {
                                    row.style.display = 'none';
                                } else {
                                    // Only show if it matches search
                                    const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
                                    const filterType = searchFilterType ? searchFilterType.value : 'waybill_no';
                                    const searchValue = getSearchValueFromRow(row, filterType);
                                    const matchesSearch = searchTerm === '' || searchValue.includes(searchTerm);
                                    row.style.display = matchesSearch ? '' : 'none';
                                }
                            });

                            reindexRows();
                        });
                    }
                });

                if (searchInput) {
                    searchInput.addEventListener('input', applyFilters);
                }

                if (searchFilterType) {
                    const defaultPlaceholder = <?php echo json_encode($options['search_placeholder']); ?>;
                    const defaultFilter = <?php echo json_encode($options['search_default_filter'] ?? ($options['search_filters'][0]['value'] ?? '')); ?>;

                    searchFilterType.addEventListener('change', function() {
                        // Update placeholder text based on filter type if configured
                        const filterOptions = <?php echo json_encode($options['search_filters'] ?? []); ?>;
                        const selectedFilter = filterOptions.find(f => f.value === this.value);
                        if (searchInput && selectedFilter && selectedFilter.placeholder) {
                            searchInput.placeholder = selectedFilter.placeholder;
                        } else if (searchInput) {
                            searchInput.placeholder = defaultPlaceholder;
                        }
                        applyFilters();
                    });
                }

                if (clearSearchBtn) {
                    const defaultPlaceholder = <?php echo json_encode($options['search_placeholder']); ?>;
                    const defaultFilter = <?php echo json_encode($options['search_default_filter'] ?? ($options['search_filters'][0]['value'] ?? '')); ?>;

                    clearSearchBtn.addEventListener('click', function() {
                        if (searchInput) {
                            searchInput.value = '';
                            searchInput.placeholder = defaultPlaceholder;
                        }
                        if (searchFilterType) {
                            searchFilterType.value = defaultFilter;
                        }
                        applyFilters();
                    });
                }

                applyFilters();

                // View Toggle Functionality
                if (enableViewToggle) {
                    const groupedBtn = document.getElementById('view-toggle-grouped-<?php echo esc_js($table_id); ?>');
                    const infiniteBtn = document.getElementById('view-toggle-infinite-<?php echo esc_js($table_id); ?>');

                    // Set data attributes for buttons (since renderButton doesn't support arbitrary data attributes)
                    if (groupedBtn) {
                        groupedBtn.setAttribute('data-view', 'grouped');
                        groupedBtn.setAttribute('data-table-id', '<?php echo esc_js($table_id); ?>');
                    }
                    if (infiniteBtn) {
                        infiniteBtn.setAttribute('data-view', 'infinite');
                        infiniteBtn.setAttribute('data-table-id', '<?php echo esc_js($table_id); ?>');
                    }

                    const tbody = table.querySelector('tbody');
                    // Check URL parameter to determine current view
                    const urlParams = new URLSearchParams(window.location.search);
                    const urlView = urlParams.get('view');
                    const currentView = (urlView === 'grouped' && originalGroupby) ? 'grouped' : 'infinite';

                    // Update table dataset to match current view
                    table.dataset.currentView = currentView;

                    function setButtonActive(btn, isActive) {
                        if (!btn) {
                            return;
                        }
                        btn.classList.toggle('is-active', !!isActive);
                        btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    }

                    // Set initial button states
                    setButtonActive(groupedBtn, currentView === 'grouped');
                    setButtonActive(infiniteBtn, currentView === 'infinite');

                    // Function to switch to grouped view
                    function switchToGroupedView() {
                        if (!originalGroupby) return;

                        // Update button states
                        setButtonActive(groupedBtn, true);
                        setButtonActive(infiniteBtn, false);

                        // Update URL without reload
                        const url = new URL(window.location.href);
                        url.searchParams.set('view', 'grouped');
                        window.history.pushState({
                            view: 'grouped'
                        }, '', url);

                        // Reload the page to rebuild with grouping (server-side grouping is needed)
                        window.location.reload();
                    }

                    // Function to switch to infinite scroll view
                    function switchToInfiniteView() {
                        // Update button states
                        setButtonActive(infiniteBtn, true);
                        setButtonActive(groupedBtn, false);

                        // Update URL and reload to rebuild without grouping
                        const url = new URL(window.location.href);
                        url.searchParams.set('view', 'infinite');
                        window.location.href = url.toString();
                    }

                    // Event listeners
                    groupedBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (table.dataset.currentView !== 'grouped') {
                            switchToGroupedView();
                        }
                    });

                    infiniteBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (table.dataset.currentView !== 'infinite') {
                            switchToInfiniteView();
                        }
                    });
                }

                // Select All functionality for infinite scroll
                if (<?php echo $options['selectable'] ? 'true' : 'false'; ?>) {
                    const selectAllCheckbox = document.getElementById('infinite-select-all-checkbox');
                    const rowCheckboxes = document.querySelectorAll('.infinite-row-checkbox');

                    if (selectAllCheckbox) {
                        selectAllCheckbox.addEventListener('change', function() {
                            const checked = this.checked;
                            rowCheckboxes.forEach(checkbox => {
                                const row = checkbox.closest('tr');
                                if (row && row.style.display !== 'none') {
                                    checkbox.checked = checked;
                                }
                            });
                        });
                    }

                    rowCheckboxes.forEach(checkbox => {
                        checkbox.addEventListener('change', function() {
                            const visibleCheckboxes = Array.from(rowCheckboxes).filter(cb => {
                                const row = cb.closest('tr');
                                return row && row.style.display !== 'none';
                            });

                            const checkedVisibleBoxes = visibleCheckboxes.filter(cb => cb.checked);

                            if (selectAllCheckbox) {
                                if (checkedVisibleBoxes.length === visibleCheckboxes.length && visibleCheckboxes.length > 0) {
                                    selectAllCheckbox.checked = true;
                                    selectAllCheckbox.indeterminate = false;
                                } else if (checkedVisibleBoxes.length === 0) {
                                    selectAllCheckbox.checked = false;
                                    selectAllCheckbox.indeterminate = false;
                                } else {
                                    selectAllCheckbox.checked = false;
                                    selectAllCheckbox.indeterminate = true;
                                }
                            }
                        });
                    });
                }

                // Bulk Management functionality
                if (<?php echo ($options['bulk_management'] === true) ? 'true' : 'false'; ?>) {
                    // Initialize bulk management using function from kitscript.js
                    if (typeof initBulkManagement === 'function') {
                        <?php if (function_exists('wp_create_nonce')): ?>
                            initBulkManagement('<?php echo esc_js($table_id); ?>', '<?php echo wp_create_nonce('bulk_action_nonce'); ?>');
                        <?php else: ?>
                            initBulkManagement('<?php echo esc_js($table_id); ?>', null);
                        <?php endif; ?>

                        // Update UI when filters change
                        if (typeof applyFilters !== 'undefined') {
                            const originalApplyFilters = applyFilters;
                            applyFilters = function() {
                                originalApplyFilters();
                                if (window.kitBulkManagement && window.kitBulkManagement['<?php echo esc_js($table_id); ?>']) {
                                    window.kitBulkManagement['<?php echo esc_js($table_id); ?>'].updateUI();
                                }
                            };
                        }
                    } else {
                        console.error('initBulkManagement function not found. Make sure kitscript.js is loaded.');
                    }
                }

                <?php if ($needs_waybill_list_print_globals && function_exists('wp_create_nonce')) : ?>
                window.kitWaybillListPrintUrl = <?php echo json_encode(dirname(plugin_dir_url(__FILE__)) . '/waybill-list-print.php'); ?>;
                window.kitWaybillListPrintNonce = <?php echo json_encode(wp_create_nonce('waybill_list_print')); ?>;

                <?php if (! empty($options['delivery_list_print']) && filter_var($options['delivery_list_print'], FILTER_VALIDATE_BOOLEAN)) : ?>
                (function() {
                    const printDeliveryListBtn = document.getElementById('print-delivery-list-<?php echo esc_js($table_id); ?>');
                    if (!printDeliveryListBtn) {
                        return;
                    }
                    printDeliveryListBtn.addEventListener('click', function() {
                        const rows = table.querySelectorAll('tbody tr');
                        const seen = new Set();
                        const ids = [];
                        rows.forEach(function(row) {
                            if (row.dataset.groupRow === '1') {
                                return;
                            }
                            if (row.style.display === 'none') {
                                return;
                            }
                            const wb = row.getAttribute('data-waybill-no');
                            if (wb && !seen.has(wb)) {
                                seen.add(wb);
                                ids.push(wb);
                            }
                        });
                        if (ids.length === 0) {
                            alert('No visible waybills to print.');
                            return;
                        }
                        const base = window.kitWaybillListPrintUrl || '';
                        const nonce = window.kitWaybillListPrintNonce || '';
                        let urlStr = base;
                        try {
                            const u = new URL(base, window.location.href);
                            u.searchParams.set('list_type', 'delivery');
                            u.searchParams.set('ids', ids.join(','));
                            u.searchParams.set('nonce', nonce);
                            urlStr = u.toString();
                        } catch (e) {
                            urlStr = base + (base.indexOf('?') === -1 ? '?' : '&')
                                + 'list_type=delivery&ids=' + encodeURIComponent(ids.join(','))
                                + '&nonce=' + encodeURIComponent(nonce);
                        }
                        window.open(urlStr, '_blank');
                    });
                })();
                <?php endif; ?>
                <?php endif; ?>

                // Sorting functionality - client-side sorting for infinite scroll
                if (<?php echo $options['sortable'] ? 'true' : 'false'; ?>) {
                    if (table.dataset && table.dataset.hasGroupRows === '1') return;

                    const sortHeaders = table.querySelectorAll('.sortable-header');
                    let currentSortColumn = '';
                    let currentSortDirection = 'asc';

                    sortHeaders.forEach(header => {
                        header.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();

                            const th = this.closest('th');
                            const column = th ? th.getAttribute('data-column') : null;
                            const icon = this.querySelector('.sort-icon');
                            const tbody = table.querySelector('tbody');

                            if (!tbody || !column) return;

                            // Determine sort direction
                            if (currentSortColumn === column) {
                                currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
                            } else {
                                currentSortDirection = 'asc';
                            }
                            currentSortColumn = column;

                            // Reset all icons
                            sortHeaders.forEach(h => {
                                const i = h.querySelector('.sort-icon');
                                if (i) i.style.transform = 'rotate(0deg)';
                            });

                            // Set current icon
                            if (icon) {
                                icon.style.transform = currentSortDirection === 'asc' ? 'rotate(180deg)' : 'rotate(0deg)';
                            }

                            // Get column index from the table header row
                            const headerRow = table.querySelector('thead tr');
                            const columnIndex = Array.from(headerRow.children).indexOf(th);

                            // Skip sorting if it's the checkbox column (first column when bulk_management is enabled) or index column
                            if (hasBulkManagement && columnIndex === 0) {
                                return; // Skip checkbox column
                            }
                            if (!hasBulkManagement && columnIndex === 0) {
                                return; // Skip index column when no bulk management
                            }
                            if (hasBulkManagement && columnIndex === 1) {
                                return; // Skip index column when bulk management is enabled
                            }

                            // Sort rows
                            const rows = Array.from(tbody.querySelectorAll('tr'));

                            rows.sort((a, b) => {
                                // Skip group rows
                                if (a.dataset.groupRow === '1' || b.dataset.groupRow === '1') {
                                    return 0;
                                }

                                const aCell = a.children[columnIndex];
                                const bCell = b.children[columnIndex];

                                if (!aCell || !bCell) return 0;

                                // A cell may publish an explicit sort key (dates
                                // render as "20 Aug 2026" but must sort as ISO).
                                const aKey = aCell.querySelector('[data-sort-value]');
                                const bKey = bCell.querySelector('[data-sort-value]');

                                let aVal = aKey ? aKey.getAttribute('data-sort-value').trim() : (aCell.textContent?.trim() || '');
                                let bVal = bKey ? bKey.getAttribute('data-sort-value').trim() : (bCell.textContent?.trim() || '');

                                // Try to parse as numbers for numeric sorting
                                const aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
                                const bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));

                                if (!isNaN(aNum) && !isNaN(bNum)) {
                                    // Numeric comparison
                                    aVal = aNum;
                                    bVal = bNum;
                                } else {
                                    // String comparison
                                    aVal = aVal.toLowerCase();
                                    bVal = bVal.toLowerCase();
                                }

                                if (currentSortDirection === 'asc') {
                                    return aVal > bVal ? 1 : aVal < bVal ? -1 : 0;
                                } else {
                                    return aVal < bVal ? 1 : aVal > bVal ? -1 : 0;
                                }
                            });

                            // Re-append sorted rows and update index numbers
                            rows.forEach((row, index) => {
                                // Skip checkbox cell if bulk_management is enabled (first cell), index is second cell
                                const indexCellIndex = hasBulkManagement ? 1 : 0;
                                const indexCell = row.children[indexCellIndex];
                                if (indexCell && indexCell.tagName === 'TD') {
                                    // Only update if it's not the checkbox cell (check for checkbox input)
                                    if (!indexCell.querySelector('input[type="checkbox"]')) {
                                        indexCell.textContent = index + 1;
                                    }
                                }
                                tbody.appendChild(row);
                            });
                        });
                    });
                }
            });
        </script>

    <?php
        return ob_get_clean();
    }

    /**
     * Render a server-side table (DataTables)
     */
    public static function server_side($columns, $options = [])
    {
        $defaults = [
            'title' => '',
            'subtitle' => '',
            'ajax_url' => '',
            'ajax_action' => '',
            'actions' => []
        ];

        $options = array_merge($defaults, $options);

        ob_start();
    ?>
        <div class="w-full">
            <div class="mb-3">
                <div class="flex items-center justify-between">
                    <div class="space-y-1">
                        <?php if ($options['title']): ?>
                            <h3 class="text-lg font-semibold text-gray-900"><?php echo esc_html($options['title']); ?></h3>
                        <?php endif; ?>
                        <?php if ($options['subtitle']): ?>
                            <p class="text-sm text-gray-600"><?php echo esc_html($options['subtitle']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="w-full overflow-hidden">
                <table id="server-side-table" class="w-full table-auto border-collapse" style="width:100%;">
                    <thead class="border-b border-gray-200">
                        <tr>
                            <?php foreach ($columns as $key => $column): ?>
                                <?php
                                $label = is_array($column) ? $column['label'] : $column;
                                ?>
                                <th class="px-3 py-2 text-xs font-semibold text-left uppercase tracking-wide text-gray-700">
                                    <span class="flex items-center gap-1 text-gray-700 whitespace-normal break-words"><?php echo esc_html($label); ?></span>
                                </th>
                            <?php endforeach; ?>
                            <?php if (!empty($options['actions'])): ?>
                                <th class="px-3 py-2 text-xs font-semibold text-left uppercase tracking-wide text-gray-700">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#server-side-table').DataTable({
                    processing: true,
                    serverSide: true,
                    ajax: {
                        url: '<?php echo esc_url($options['ajax_url']); ?>',
                        type: 'POST',
                        data: {
                            action: '<?php echo esc_attr($options['ajax_action']); ?>'
                        }
                    },
                    columns: [
                        <?php foreach ($columns as $key => $column): ?> {
                                data: '<?php echo esc_js($key); ?>'
                            },
                        <?php endforeach; ?>
                        <?php if (!empty($options['actions'])): ?> {
                                data: null,
                                orderable: false,
                                render: function(data, type, row) {
                                    let actions = '';
                                    <?php foreach ($options['actions'] as $action): ?>
                                        <?php
                                        $href = $action['href'] ?? '#';
                                        $class = $action['class'] ?? 'text-blue-600 hover:text-blue-800';
                                        $onclick = isset($action['onclick']) ? 'onclick="' . esc_attr($action['onclick']) . '"' : '';
                                        ?>
                                        <?php
                                        $label_output = (isset($action['is_html']) && $action['is_html']) ? $action['label'] : esc_html($action['label']);
                                        ?>
                                        actions += '<a href="<?php echo esc_url($href); ?>"<?php echo $class ? ' class="' . esc_attr($class) . '"' : ''; ?> <?php echo $onclick; ?>><?php echo $label_output; ?></a>';
                                        <?php if ($action !== end($options['actions'])): ?>
                                            actions += ' | ';
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    return actions;
                                }
                            }
                        <?php endif; ?>
                    ]
                });
            });
        </script>
<?php
        return ob_get_clean();
    }
}
