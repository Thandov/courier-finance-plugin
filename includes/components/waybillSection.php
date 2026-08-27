<?php
/**
 * Repeatable Waybill Section Component
 * Combines Delivery/Destination (step 4) and Charges & Fees (step 5)
 * 
 * @param int $waybill_index The index of this waybill (0, 1, 2, etc.)
 */
if (!defined('ABSPATH')) {
    exit;
}

$waybill_index = $waybill_index ?? 0;
$is_first = $waybill_index === 0;

// Set global waybill_index for child components to use
$GLOBALS['current_waybill_index'] = $waybill_index;
?>
<div class="waybill-section border-2 border-gray-300 rounded-lg p-6 bg-white mb-6" data-waybill-index="<?php echo esc_attr($waybill_index); ?>">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Waybill #<?php echo esc_html($waybill_index + 1); ?></h2>
        <?php if (!$is_first): ?>
            <?php echo KIT_Commons::renderButton('Remove Waybill', 'danger', 'lg', ['type' => 'button', 'classes' => 'remove-waybill-section bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-md text-sm font-medium transition-colors']); ?>
        <?php endif; ?>
    </div>

    <!-- Different city option (hidden for first waybill, shown for subsequent waybills) -->
    <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg" id="different_city_section_<?php echo $waybill_index; ?>" style="<?php echo $is_first ? 'display: none;' : ''; ?>">
        <label class="flex items-center cursor-pointer mb-3">
            <?php
            echo KIT_Commons::Lcheckbox([
                'no_label' => true,
                'label' => '',
                'name' => 'waybills[' . (int) $waybill_index . '][different_city]',
                'id' => 'different_city_' . (int) $waybill_index,
                'value' => '1',
                'class' => 'different-city-checkbox mr-2 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded',
            ]);
            ?>
            <span class="text-sm font-medium text-gray-700">Different city?</span>
        </label>
        
        <!-- City dropdown (shown when "Different city?" is checked) -->
        <div id="different_city_dropdown_<?php echo $waybill_index; ?>" class="hidden mt-3">
            <label for="waybill_destination_city_<?php echo $waybill_index; ?>" class="<?= KIT_Commons::labelClass() ?>">Destination City</label>
            <select class="<?= KIT_Commons::selectClass(); ?>" 
                    name="waybills[<?php echo $waybill_index; ?>][destination_city]" 
                    id="waybill_destination_city_<?php echo $waybill_index; ?>">
                <option value="">Select City</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">Select a different destination city for this waybill.</p>
        </div>
        
        <input type="hidden" name="waybills[<?php echo $waybill_index; ?>][destination_country]" id="waybill_destination_country_<?php echo $waybill_index; ?>" value="" />
        <input type="hidden" name="waybills[<?php echo $waybill_index; ?>][delivery_id]" id="waybill_delivery_id_<?php echo $waybill_index; ?>" value="" />
        <input type="hidden" name="waybills[<?php echo $waybill_index; ?>][direction_id]" id="waybill_direction_id_<?php echo $waybill_index; ?>" value="" />
    </div>

    <!-- Step 5 Content: Charges & Fees -->
    <div class="border-t border-gray-300 pt-6">
        <?= KIT_Commons::prettyHeading([
            'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
            'words' => 'Charges & Fees'
        ]) ?>
        
        <div class="grid md:grid-cols-12 gap-4 mt-4">
            <div class="md:col-span-8 rounded-lg">
                <!-- Dimensions and Volume -->
                <div class="space-y-6 mb-6">
                    <div class="rounded bg-slate-100 p-6">
                        <div class="grid grid-cols-1 gap-4 justify-center align-middle">
                            <?php 
                            // Weight component - includes its own "Mass" heading
                            // Pass option 1 to weight.php via local scope variable.
                            // Note: require/include do not accept a 2nd "args" parameter in PHP.
                            $weightDisplayOption = 1;   // 1 for floating popup, 2 for inline
                            require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/weight.php'; 
                            ?>
                        </div>
                    </div>

                    <div class="rounded bg-slate-100 p-6">
                        <?php 
                        // Dimensions component - includes its own "Volume" heading
                        require(COURIER_FINANCE_PLUGIN_PATH . 'includes/components/dimensions.php'); 
                        ?>
                        <p class="text-sm text-gray-600 mb-4"><strong>Standard Volume (m³)</strong> = (Length × Width × Height) ÷ 1,000,000</p>
                    </div>
                </div>
            </div>

            <div class="md:col-span-4 space-y-6 mb-6">
                <div class="p-6 rounded-lg bg-slate-100 space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-2">Charge Basis</label>
                        <p class="text-xs text-gray-600 mb-3">Choose which freight charge to bill. Auto uses the higher of mass and volume.</p>
                        <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Charge basis">
                            <?php
                            $charge_basis_options = [
                                'auto'   => 'Auto',
                                'mass'   => 'Mass',
                                'volume' => 'Volume',
                            ];
                            foreach ($charge_basis_options as $value => $label) :
                                $input_id = 'charge_basis_' . $value . '_' . (int) $waybill_index;
                                ?>
                                <div>
                                    <input
                                        type="radio"
                                        name="charge_basis"
                                        id="<?php echo esc_attr($input_id); ?>"
                                        value="<?php echo esc_attr($value); ?>"
                                        class="sr-only peer kit-charge-basis-radio"
                                        <?php echo $value === 'auto' ? 'checked' : ''; ?>
                                    >
                                    <label
                                        for="<?php echo esc_attr($input_id); ?>"
                                        class="block w-full px-2 py-2 rounded border-2 border-gray-300 bg-white text-center text-xs font-semibold text-gray-700 cursor-pointer transition-all duration-200 hover:border-gray-400 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-700 peer-checked:shadow-lg"
                                    >
                                        <?php echo esc_html($label); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-4 rounded border border-slate-200 bg-white p-3">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Billed freight</div>
                            <div id="kit-billed-freight-display" class="mt-1 text-xl font-semibold text-slate-900">R 0.00</div>
                            <p id="kit-billed-freight-hint" class="mt-1 text-xs text-gray-500">Enter mass or volume to calculate.</p>
                        </div>
                    </div>
                    <div id="totalOverride-<?php echo $waybill_index; ?>">
                        <?php require(COURIER_FINANCE_PLUGIN_PATH . 'includes/components/totalOverride.php'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

