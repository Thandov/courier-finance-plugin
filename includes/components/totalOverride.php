<?php
/**
 * Total Override Component
 * Allows clients to manually override the calculated total
 * RESTRICTED: Only superadmins (thando, mel, patricia) can use this feature
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if current user is a superadmin
$allowed_superadmins = ['thando', 'mel', 'patricia'];
$is_superadmin = false;

// Use WordPress global current user
global $current_user;

// Load WordPress user if not already loaded
if (!function_exists('wp_get_current_user')) {
    require_once ABSPATH . 'wp-includes/pluggable.php';
}

// Ensure we have a valid user object
if (!isset($current_user) || !is_object($current_user) || !isset($current_user->ID)) {
    // Call wp_get_current_user if it exists (always will in WP context)
    if (function_exists('wp_get_current_user')) {
        /** @var WP_User $current_user */
        $current_user = call_user_func('wp_get_current_user');
    }
}

// Check if user is a superadmin
if ($current_user && isset($current_user->ID) && $current_user->ID > 0 && isset($current_user->user_login)) {
    $is_superadmin = in_array(strtolower($current_user->user_login), $allowed_superadmins);
}

// If not superadmin, don't show this component at all
if (!$is_superadmin) {
    return;
}

// Default smalling_enabled to false if not set
$smalling_enabled = isset($smalling_enabled) ? $smalling_enabled : false;

if ($smalling_enabled) {
    //class to manage small width, so control stacking of elements
    $class = 'total-override-container-small';
} else {
    $class = 'total-override-container';
}

?>

<div class="<?php echo $class; ?> bg-white rounded-lg border border-gray-200 p-4">
    <div class="items-center justify-between mb-3">
        <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
            <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Total Override
        </h3>
        <div class="flex items-center gap-2 mt-2">
            <label class="inline-flex items-center">
                <?php
                echo KIT_Commons::Lcheckbox([
                    'no_label' => true,
                    'label' => '',
                    'id' => 'enable_total_override',
                    'name' => 'enable_total_override',
                    'value' => '1',
                    'class' => 'form-checkbox h-4 w-4 text-orange-600 focus:ring-orange-500 border-gray-300 rounded',
                    'special' => 'onchange="toggleTotalOverride(this.checked)"',
                ]);
                ?>
                <span class="ml-2 text-sm font-medium text-gray-700">Override Total</span>
            </label>
        </div>
    </div>
    
    <div id="total-override-input" class="hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="override_basis" class="block text-sm font-medium text-gray-700 mb-1">
                    Charge Basis
                </label>
                <select id="override_basis" name="override_basis" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-white">
                    <option value="auto">Auto detect</option>
                    <option value="mass">Mass</option>
                    <option value="volume">Volume</option>
                </select>
            </div>
            <!-- Calculated total (readonly) field removed from UI; use Linput if re-enabled. -->
            <div>
                <label for="override_total" class="block text-sm font-medium text-gray-700 mb-1">
                    Override Total (R) <span class="text-red-500">*</span>
                </label>
                <?php
                echo KIT_Commons::Lnumber([
                    'no_label' => true,
                    'label' => '',
                    'id' => 'override_total',
                    'name' => 'override_total',
                    'step' => '0.01',
                    'min' => '0',
                    'value' => '',
                    'placeholder' => '0.00',
                    'preset' => '',
                    'class' => 'w-full px-3 py-2 border border-orange-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500',
                ]);
                ?>
            </div>
        </div>
        
        <!-- Persisted meta for server: which basis was applied -->
        <input type="hidden" id="override_charge_basis" name="override_charge_basis" value="">
        <div id="override_error" class="hidden mt-3 p-2 bg-red-50 border border-red-200 text-red-700 rounded text-sm"></div>
        
        <div class="mt-3 p-3 bg-orange-50 border border-orange-200 rounded-md">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-orange-400 mt-0.5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
                <div class="text-sm text-orange-800">
                    <p class="font-medium mb-1">Override Warning</p>
                    <p>When you override the total, the system will use your manual entry instead of the calculated amount. This bypasses all automatic calculations including VAT, fees, and item totals.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript moved to kitscript.js - initTotalOverride() -->

