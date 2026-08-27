<?php
if (!defined('ABSPATH')) {
    exit;
}

// Ensure warehouse helpers are available
if (!class_exists('KIT_Warehouse')) {
    $warehouse_functions = plugin_dir_path(__FILE__) . '../warehouse/warehouse-functions.php';
    if (file_exists($warehouse_functions)) {
        require_once $warehouse_functions;
    }
}

// This component is used inside editWaybill to control whether
// a waybill should stay on its current delivery, be moved back
// into the warehouse, or be reassigned to a different delivery
// based on the destination country/city.

// Expect $waybill to be available in scope.
$current_delivery_id  = intval($waybill['delivery_id'] ?? 0);
$current_delivery_ref = $waybill['delivery_reference'] ?? '';
$is_in_warehouse_flag = isset($waybill['warehouse']) ? intval($waybill['warehouse']) : 0;

// Determine destination context for delivery suggestions
// Read destination the same way selectsDestination.php does
$destination_country_id = 0;
$destination_city_id = 0;
$destination_country_name = '';
$destination_city_name = '';

// Check miscellaneous['others'] first (same as selectsDestination.php)
if (isset($waybill['miscellaneous']) && is_array($waybill['miscellaneous'])) {
    $misc = maybe_unserialize($waybill['miscellaneous']);
    if (is_array($misc) && isset($misc['others'])) {
        $destination_city_id = intval($misc['others']['destination_city_id'] ?? 0);
        $destination_country_id = intval($misc['others']['destination_country_id'] ?? 0);
    }
}

// Fallback to top-level waybill values
if (!$destination_city_id && isset($waybill['destination_city_id'])) {
    $destination_city_id = intval($waybill['destination_city_id']);
} elseif (!$destination_city_id && isset($waybill['destination_city'])) {
    $destination_city_id = intval($waybill['destination_city']);
} elseif (!$destination_city_id && isset($waybill['city_id'])) {
    $destination_city_id = intval($waybill['city_id']);
}

if (!$destination_country_id && isset($waybill['destination_country_id'])) {
    $destination_country_id = intval($waybill['destination_country_id']);
} elseif (!$destination_country_id && isset($waybill['destination_country'])) {
    $destination_country_id = intval($waybill['destination_country']);
}

// Convert IDs to names using KIT_Routes
if ($destination_country_id > 0 && class_exists('KIT_Routes')) {
    $destination_country_name = KIT_Routes::get_country_name_by_id($destination_country_id);
}
if ($destination_city_id > 0 && class_exists('KIT_Routes')) {
    $destination_city_name = KIT_Routes::get_city_name_by_id($destination_city_id);
}

// Get available scheduled deliveries for this destination country (and optionally city)
$available_deliveries = [];
if (!empty($destination_country_name) && class_exists('KIT_Warehouse')) {
    // Use helper that already powers the warehouse assignment UI
    $available_deliveries = KIT_Warehouse::getAvailableDeliveries($destination_country_name, $destination_city_name);
}

// System warehouse uses delivery_reference = 'pending' while warehouse=1 — treat as warehouse, not "On Delivery".
$is_pending_delivery_ref = $current_delivery_ref !== '' && strcasecmp(trim((string) $current_delivery_ref), 'pending') === 0;
$is_effectively_in_warehouse = ($is_in_warehouse_flag === 1) || $is_pending_delivery_ref;
$is_on_real_delivery = ($current_delivery_id > 0) && !$is_effectively_in_warehouse;

// Derive a simple status label for the UI
if ($is_on_real_delivery) {
    $location_label = 'On Delivery';
    $location_help  = 'This waybill is currently assigned to a scheduled delivery.';
} elseif ($is_effectively_in_warehouse) {
    $location_label = 'In Warehouse';
    $location_help  = 'This waybill is in the warehouse and can be assigned to a scheduled delivery.';
} else {
    $location_label = 'Unassigned';
    $location_help  = 'This waybill is not assigned to a delivery or warehouse.';
}
?>

<div class="mt-3 space-y-3">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                Waybill Location
            </p>
            <p class="text-sm font-medium text-gray-900">
                <?php echo esc_html($location_label); ?>
            </p>
            <p class="text-xs text-gray-500 mt-1">
                <?php echo esc_html($location_help); ?>
            </p>
            <?php if ($is_on_real_delivery && $current_delivery_ref): ?>
                <p class="text-xs text-gray-500 mt-1">
                    Current Delivery:&nbsp;
                    <span class="font-medium text-gray-900">
                        <?php echo esc_html($current_delivery_ref); ?>
                    </span>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_effectively_in_warehouse || $current_delivery_id === 0): ?>
        <!-- Show assignment option for warehouse waybills or unassigned waybills -->
        <div class="mt-4">
            <label for="route_delivery_select" class="<?php echo KIT_Commons::labelClass(); ?>">
                Assign to Delivery
            </label>
            <?php if (empty($destination_country_name)): ?>
                <div class="mt-2 p-3 border border-amber-200 rounded-md bg-amber-50 text-xs text-gray-700">
                    <p class="font-semibold mb-1">⚠️ Destination Required</p>
                    <p>Please set the <strong>Destination Country</strong> and <strong>Destination City</strong> above first. Once set, available deliveries will appear here.</p>
                </div>
                <select
                    id="route_delivery_select"
                    name="delivery_id"
                    class="mt-2 block w-full rounded-lg border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-500"
                    disabled
                >
                    <option value="">Set destination above to see available deliveries</option>
                </select>
            <?php elseif (empty($available_deliveries)): ?>
                <div class="mt-2 p-3 border border-yellow-200 rounded-md bg-yellow-50 text-xs text-gray-700">
                    <p class="font-semibold mb-1">ℹ️ No Deliveries Available</p>
                    <p>No scheduled deliveries found for <strong><?php echo esc_html($destination_country_name); ?><?php echo $destination_city_name ? ' - ' . esc_html($destination_city_name) : ''; ?></strong>. 
                    Create a delivery first, or check back later.</p>
                </div>
                <select
                    id="route_delivery_select"
                    name="delivery_id"
                    class="mt-2 block w-full rounded-lg border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-500"
                >
                    <option value="">No deliveries available</option>
                </select>
            <?php else: ?>
                <select
                    id="route_delivery_select"
                    name="delivery_id"
                    class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                >
                    <option value="">Do not assign to a delivery</option>
                    <?php foreach ($available_deliveries as $delivery): ?>
                        <?php
                        $delivery_id   = intval($delivery->delivery_id ?? $delivery->id ?? 0);
                        $delivery_name = $delivery->delivery_name ?? $delivery->delivery_reference ?? '';
                        $dispatch_date = isset($delivery->dispatch_date) && $delivery->dispatch_date
                            ? date('M j, Y', strtotime($delivery->dispatch_date))
                            : 'TBD';
                        $label = trim($delivery_name . ' — ' . $dispatch_date);
                        ?>
                        <option
                            value="<?php echo esc_attr($delivery_id); ?>"
                            <?php selected($delivery_id, $current_delivery_id); ?>
                        >
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-gray-500">
                    These deliveries are scheduled for
                    <strong><?php echo esc_html($destination_country_name); ?><?php echo $destination_city_name ? ' - ' . esc_html($destination_city_name) : ''; ?></strong>.
                    Choosing one will assign this waybill to that truck when you save.
                </p>
            <?php endif; ?>
        </div>
    <?php elseif ($is_on_real_delivery && !empty($available_deliveries)): ?>
        <!-- Show reassignment option if waybill is already on a real delivery -->
        <div class="mt-4">
            <label for="route_delivery_select" class="<?php echo KIT_Commons::labelClass(); ?>">
                Reassign to Different Delivery
            </label>
            <select
                id="route_delivery_select"
                name="delivery_id"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
            >
                <option value="<?php echo esc_attr($current_delivery_id); ?>">
                    Keep current delivery: <?php echo esc_html($current_delivery_ref); ?>
                </option>
                <?php foreach ($available_deliveries as $delivery): ?>
                    <?php
                    $delivery_id   = intval($delivery->delivery_id ?? $delivery->id ?? 0);
                    $delivery_name = $delivery->delivery_name ?? $delivery->delivery_reference ?? '';
                    $dispatch_date = isset($delivery->dispatch_date) && $delivery->dispatch_date
                        ? date('M j, Y', strtotime($delivery->dispatch_date))
                        : 'TBD';
                    $label = trim($delivery_name . ' — ' . $dispatch_date);
                    ?>
                    <option
                        value="<?php echo esc_attr($delivery_id); ?>"
                        <?php selected($delivery_id, $current_delivery_id); ?>
                    >
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-gray-500">
                Select a different delivery to reassign this waybill.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($is_on_real_delivery): ?>
        <div class="mt-3 p-3 border border-amber-200 rounded-md bg-amber-50">
            <label class="flex items-start gap-2 cursor-pointer">
                <?php
                echo KIT_Commons::Lcheckbox([
                    'no_label' => true,
                    'label' => '',
                    'name' => 'move_to_warehouse',
                    'omit_id' => true,
                    'value' => '1',
                    'class' => 'mt-0.5 h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500',
                ]);
                ?>
                <span class="text-xs text-gray-700">
                    <span class="font-semibold">Move this waybill back to Warehouse</span><br>
                    <span class="text-gray-500">
                        This will remove the delivery assignment and mark the waybill as a warehouse item.
                        You can later assign it to a new delivery from the Warehouse screen.
                    </span>
                </span>
            </label>
        </div>
    <?php endif; ?>
</div>


