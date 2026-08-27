<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Global component for displaying waybill information in a minimalistic, professional format
 * 
 * @param array $waybill Waybill data array (must include waybill_no, tracking_number, product_invoice_number, product_invoice_amount)
 *                       Optional: customer_id, customer_name, customer_surname, company_name/company,
 *                       delivery_id, delivery_reference (for delivery truck link)
 * @param string $waybill_id Optional waybill ID for linking
 * @param array $options Optional configuration:
 *   - 'show_amount' (bool): Whether to show the amount (default: true, respects KIT_User_Roles::can_see_prices())
 *   - 'amount' (float): Amount to display instead of the stored product_invoice_amount. Pass this
 *     when the caller has already resolved the authoritative total, so the strip cannot disagree
 *     with the total shown elsewhere on the same screen.
 *   - 'currency_symbol' (string): Currency symbol to use (default: KIT_Commons::currency())
 *   - 'class' (string): Additional CSS classes for the container
 *   - 'exclude' (array): Array of field names to exclude from display. Valid values: 'waybill', 'customer', 'tracking', 'invoice', 'amount', 'delivery'
 *     Aliases supported: 'grand_total', 'total', 'price' (all map to 'amount')
 *   - 'enable_js_updates' (bool): Whether to enable JavaScript updates for the amount field (default: false)
 *     When enabled, uses id="waybilltotalMockup" so existing JS can update it. Only enable for one instance per page.
 */

// Ensure waybill data exists
if (empty($waybill) || !is_array($waybill)) {
    return;
}

// Extract options - support both $options and $waybill_info_options variable names
// Priority: $waybill_info_options (most common) > $options (function parameter)
if (isset($waybill_info_options) && is_array($waybill_info_options)) {
    $options = $waybill_info_options;
} elseif (!isset($options) || !is_array($options)) {
    $options = [];
}
$show_amount = isset($options['show_amount']) ? $options['show_amount'] : true;
$amount_value = isset($options['amount'])
    ? (float) $options['amount']
    : (float) ($waybill['product_invoice_amount'] ?? 0);
$currency_symbol = isset($options['currency_symbol']) ? $options['currency_symbol'] : (class_exists('KIT_Commons') ? KIT_Commons::currency() : 'R');
$container_class = isset($options['class']) ? ' ' . esc_attr($options['class']) : '';
$exclude_fields = isset($options['exclude']) && is_array($options['exclude']) ? $options['exclude'] : [];
$enable_js_updates = isset($options['enable_js_updates']) ? (bool) $options['enable_js_updates'] : false;

// Normalize exclude fields (convert to lowercase for case-insensitive matching)
$exclude_fields = array_map('strtolower', $exclude_fields);

// Map aliases to actual field names (for backward compatibility)
$field_aliases = [
    'grand_total' => 'amount',
    'total' => 'amount',
    'price' => 'amount',
];

// Normalize exclude fields: replace aliases with actual field names
$normalized_exclude = [];
foreach ($exclude_fields as $field) {
    if (isset($field_aliases[$field])) {
        $normalized_exclude[] = $field_aliases[$field];
    } else {
        $normalized_exclude[] = $field;
    }
}
$exclude_fields = array_unique($normalized_exclude);

// Helper function to check if a field should be excluded
$is_excluded = function($field_name) use ($exclude_fields) {
    return in_array(strtolower($field_name), $exclude_fields);
};

// Check if user can see prices (if KIT_User_Roles exists)
$can_see_prices = true;
if (class_exists('KIT_User_Roles')) {
    $can_show_amount = $show_amount && KIT_User_Roles::can_see_prices();
} else {
    $can_show_amount = $show_amount;
}
?>

<!-- Professional minimalistic waybill info display -->
<div class="rounded-lg border border-gray-200 bg-white shadow-sm p-4 min-w-0<?php echo $container_class; ?>">
<?php
// Wrapping row, not a nowrap row with overflow-x-auto: at ~1486px the old strip
// pushed the amount behind a horizontal scrollbar, hiding the one figure the page
// exists to state. Wrapping keeps every cell readable at any width. Dividers were
// dropped with the same change — a 1px rule cannot separate cells across two rows.
?>
<div class="flex flex-wrap items-center gap-6 text-sm min-w-0">
        <?php
        // Waybill Number
        if (!$is_excluded('waybill')) {
        ?>
        <div class="min-w-0">
            <div class="text-gray-500 text-xs font-medium uppercase tracking-wide">Waybill</div>
            <div class="text-gray-900 font-semibold text-base"><?php echo esc_html($waybill['waybill_no'] ?? 'N/A'); ?></div>
        </div>
        <?php
        }

        // Customer (linked to customer detail when possible)
        if (!$is_excluded('customer')) {
            $customer_id = intval($waybill['customer_id'] ?? $waybill['cust_id'] ?? 0);
            $cust_name = trim((string) ($waybill['customer_name'] ?? $waybill['name'] ?? ''));
            $cust_surname = trim((string) ($waybill['customer_surname'] ?? $waybill['surname'] ?? ''));
            $company = trim((string) ($waybill['company_name'] ?? $waybill['company'] ?? $waybill['customer_company'] ?? ''));
            $is_placeholder = static function ($value): bool {
                $v = strtolower(trim((string) $value));
                return $v === '' || in_array($v, ['0', 'null', 'n/a', 'na', 'none', '-', '--'], true);
            };
            if ($is_placeholder($cust_name)) {
                $cust_name = '';
            }
            if ($is_placeholder($cust_surname)) {
                $cust_surname = '';
            }
            if ($is_placeholder($company)) {
                $company = '';
            }
            $person_name = trim($cust_name . ' ' . $cust_surname);
            $company_is_displayable = ($company !== '' && !in_array(strtolower($company), ['individual', '1ndividual', 'private'], true));
            if (class_exists('KIT_Company_Customers') && $company !== '' && KIT_Company_Customers::is_placeholder_company($company)) {
                $company_is_displayable = false;
            }
            if ($company_is_displayable && $person_name !== '' && strcasecmp($person_name, $company) !== 0) {
                $customer_display = $person_name . ' · ' . $company;
            } elseif ($company_is_displayable) {
                $customer_display = $company;
            } else {
                $customer_display = $person_name;
            }
            if ($customer_display === '') {
                $customer_display = 'N/A';
            }
            $customer_url = $customer_id > 0
                ? '?page=08600-customers&view_customer=' . $customer_id
                : '';
        ?>
        <div class="min-w-0 max-w-xs">
            <div class="text-gray-500 text-xs font-medium uppercase tracking-wide">Customer</div>
            <?php if ($customer_url !== '' && $customer_display !== 'N/A'): ?>
                <a
                    href="<?php echo esc_url($customer_url); ?>"
                    class="font-semibold text-base text-blue-600 hover:text-blue-800 hover:underline inline-block max-w-full truncate"
                    target="_blank"
                    rel="noopener"
                    title="<?php echo esc_attr($customer_display); ?>"
                ><?php echo esc_html($customer_display); ?></a>
            <?php else: ?>
                <span class="text-gray-900 font-semibold text-base inline-block max-w-full truncate" title="<?php echo esc_attr($customer_display); ?>"><?php echo esc_html($customer_display); ?></span>
            <?php endif; ?>
        </div>
        <?php
        }
        
        // Tracking Number
        if (!$is_excluded('tracking')) {
        ?>
        <div class="min-w-0">
            <div class="text-gray-500 text-xs font-medium uppercase tracking-wide">Tracking</div>
            <div class="text-gray-900 font-semibold text-base"><?php echo esc_html($waybill['tracking_number'] ?? 'N/A'); ?></div>
        </div>
        <?php
        }
        
        // Invoice Number
        if (!$is_excluded('invoice')) {
        ?>
        <div class="min-w-0">
            <div class="text-gray-500 text-xs font-medium uppercase tracking-wide">Invoice</div>
            <div class="text-gray-900 font-semibold text-base"><?php echo esc_html($waybill['product_invoice_number'] ?? 'N/A'); ?></div>
        </div>
        <?php
        }
        
        // Delivery Truck
        if (!$is_excluded('delivery')) {
            $delivery_id = intval($waybill['delivery_id'] ?? 0);
            $delivery_reference = isset($waybill['delivery_reference']) ? (string) $waybill['delivery_reference'] : '';
            $warehouse_flag = isset($waybill['warehouse']) && (intval($waybill['warehouse']) === 1 || $waybill['warehouse'] === true || $waybill['warehouse'] === '1');
            $is_pending_delivery_ref = $delivery_reference !== '' && strcasecmp(trim($delivery_reference), 'pending') === 0;
            $show_as_warehouse = $warehouse_flag || $is_pending_delivery_ref;
            $tooltip_id = 'delivery-tooltip-' . uniqid();
        ?>
        <div class="min-w-0 max-w-xs relative">
            <div class="text-gray-500 text-xs font-medium uppercase tracking-wide">Delivery</div>
            <?php if ($show_as_warehouse): ?>
                <span
                    class="text-gray-900 font-semibold text-base custom-tooltip-trigger"
                    tabindex="0"
                    aria-describedby="<?php echo esc_attr($tooltip_id); ?>"
                    data-tooltip-id="<?php echo esc_attr($tooltip_id); ?>"
                    data-tooltip-text="<?php echo esc_attr($is_pending_delivery_ref ? 'Warehouse (system pending delivery)' : 'Waybill is stored in warehouse'); ?>"
                >Warehouse</span>
            <?php elseif ($delivery_id > 0 && !empty($delivery_reference)): ?>
                <a
                    href="?page=view-deliveries&delivery_id=<?php echo urlencode($delivery_id); ?>"
                    class="font-semibold text-base text-blue-600 hover:text-blue-800 hover:underline custom-tooltip-trigger"
                    target="_blank"
                    rel="noopener"
                    aria-describedby="<?php echo esc_attr($tooltip_id); ?>"
                    data-tooltip-id="<?php echo esc_attr($tooltip_id); ?>"
                    data-tooltip-text="Open delivery <?php echo esc_attr($delivery_reference); ?> in a new tab"
                >
                    <?php echo esc_html($delivery_reference); ?>
                </a>
            <?php else: ?>
                <span
                    class="text-gray-900 font-semibold text-base custom-tooltip-trigger"
                    <?php if (!empty($delivery_reference)): ?>
                        tabindex="0"
                        aria-describedby="<?php echo esc_attr($tooltip_id); ?>"
                        data-tooltip-id="<?php echo esc_attr($tooltip_id); ?>"
                        data-tooltip-text="<?php echo esc_attr($delivery_reference); ?>"
                    <?php endif; ?>
                >
                    <?php echo esc_html($delivery_reference ?: 'N/A'); ?>
                </span>
            <?php endif; ?>
            
            <!-- Custom Tooltip -->
            <div id="<?php echo esc_attr($tooltip_id); ?>" class="custom-tooltip" role="tooltip">
                <div class="custom-tooltip-arrow"></div>
                <div class="custom-tooltip-content">
                    <div class="custom-tooltip-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                    <div class="custom-tooltip-text">
                        <span class="tooltip-message"></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
        }
        
        // Grand total — same label as the Cost Summary line it repeats, and pushed to
        // the end of the row so the figure reads as the row's conclusion.
        if ($can_show_amount && !$is_excluded('amount')) {
        ?>
        <div class="w-full sm:w-auto sm:ml-auto text-left sm:text-right shrink-0">
            <div class="text-gray-500 text-xs font-medium uppercase tracking-wide whitespace-nowrap">Grand Total</div>
            <div class="text-gray-900 font-semibold text-xl leading-tight tabular-nums whitespace-nowrap">
                <span class="waybilltotalMockup"<?php echo $enable_js_updates ? ' id="waybilltotalMockup"' : ''; ?>><?php echo esc_html(KIT_Commons::money($amount_value)); ?></span>
            </div>
        </div>
        <?php
        }
        ?>
    </div>
</div>

<style>
.custom-tooltip {
    position: absolute;
    bottom: calc(100% + 12px);
    left: 50%;
    transform: translateX(-50%);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.2s ease, visibility 0.2s ease;
    z-index: 1000;
}

.custom-tooltip.active {
    opacity: 1;
    visibility: visible;
}

.custom-tooltip-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    padding: 16px 20px;
    min-width: 200px;
    max-width: 300px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.custom-tooltip-icon {
    display: none;
}

.custom-tooltip-text {
    flex: 1;
    color: #4b5563;
    font-size: 14px;
    line-height: 1.5;
}

.custom-tooltip-arrow {
    position: absolute;
    bottom: -8px;
    left: 50%;
    transform: translateX(-50%);
    width: 0;
    height: 0;
    border-left: 8px solid transparent;
    border-right: 8px solid transparent;
    border-top: 8px solid white;
}

.custom-tooltip-trigger {
    cursor: pointer;
    position: relative;
}

@media print {
    .custom-tooltip {
        display: none !important;
    }
}
</style>

<script>
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        const triggers = document.querySelectorAll('.custom-tooltip-trigger');
        
        triggers.forEach(function(trigger) {
            const tooltipId = trigger.getAttribute('data-tooltip-id');
            if (!tooltipId) return;
            
            const tooltip = document.getElementById(tooltipId);
            if (!tooltip) return;
            
            const tooltipText = trigger.getAttribute('data-tooltip-text');
            if (tooltipText && tooltip.querySelector('.tooltip-message')) {
                tooltip.querySelector('.tooltip-message').textContent = tooltipText;
            }

            function show() {
                tooltip.classList.add('active');
            }
            function hide() {
                tooltip.classList.remove('active');
            }
            
            trigger.addEventListener('mouseenter', show);
            trigger.addEventListener('mouseleave', hide);
            trigger.addEventListener('focus', show);
            trigger.addEventListener('blur', hide);
        });
    });
})();
</script>
