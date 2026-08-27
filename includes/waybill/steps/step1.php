<?php
if (!defined('ABSPATH')) {
    exit;
}

// Customer fields on this step are rendered only via `kit_render_customers_form_fields()` in `includes/customers/_customersForm.php` (through `customerSelection.php`). Do not duplicate customer field markup here.
require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/customers/_customersForm.php';
?>
<div class="space-y-4 md:space-y-0 md:grid md:grid-cols-2 md:gap-6">
    <div class="space-y-4 bg-white shadow rounded-lg p-3 md:p-6">
        <div class="md:bg-white rounded-lg">
            <div class="">
                <?= KIT_Commons::prettyHeading([
                    'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
                    'words' => 'Create New Waybill'
                ]) ?>
                <p class="text-xs text-gray-600">
                    <?php echo $is_edit_mode ? 'Edit the waybill details below.' : 'Please fill in the details below to create a new waybill.'; ?>
                </p>
            </div>
            <!-- Waybill Number -->
            <div class="w-full">
                <?php
                echo KIT_Commons::Linput([
                    'label' => 'Waybill No',
                    'name'  => 'waybill_no',
                    'id'    => 'waybill_no',
                    'type'  => 'text',
                    'value' => esc_attr($waybill->waybill_no ?? KIT_Waybills::generate_waybill_number()),
                    'preset' => 'readonly_field',
                    'special' => 'readonly',
                ]);
                ?>
                <div class="w-full">
                    <?php
                    echo KIT_Commons::TextAreaField([
                        'label' => 'Waybill Description',
                        'name'  => 'waybill_description',
                        'id'    => 'waybill_description',
                        'type'  => 'textarea',
                        'value' => esc_attr(
                            (is_object($waybill) ? ($waybill->waybill_description ?? '') : '')
                            ?: (is_array($waybill) ? ($waybill['waybill_description'] ?? '') : '')
                            ?: ($GLOBALS['kit_booking_waybill_notes'] ?? '')
                        ),
                    ]);
                    ?>
                </div>
            </div>
        </div>

        <!-- Additional Fees otherz-->
         
        <?php $optionChoice = 1; // 2 is the option to show the addition charges in the waybill form ?>
        <?php require(COURIER_FINANCE_PLUGIN_PATH . 'includes/components/additionCharges.php'); ?>
    </div>

    <div class="bg-white shadow rounded-lg p-3 md:p-4">
        <!-- Customer Information (now part of Step 1) -->
        <?php require(COURIER_FINANCE_PLUGIN_PATH . 'includes/components/customerSelection.php'); ?>
    </div>

    <!-- Navigation Buttons -->
    <div class="flex justify-between mt-8">
        <?php echo KIT_Commons::renderButton('Next: Delivery & Destination', 'primary', 'lg', [
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />',
            'iconPosition' => 'right',
            'data-target' => 'step-2',
            'classes' => 'next-step',
            'gradient' => true
        ]); ?>
    </div>
</div>