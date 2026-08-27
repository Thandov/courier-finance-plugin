<?php if (!defined('ABSPATH')) {
    exit;
}

$sadcChargeValue   = class_exists('KIT_Waybills') ? floatval(KIT_Waybills::sad()) : 0.0;
$sad500ChargeValue = class_exists('KIT_Waybills') ? floatval(KIT_Waybills::sadc_certificate()) : 0.0;
$vatRatePct        = class_exists('KIT_Waybills') ? floatval(KIT_Waybills::vatRate()) : 10.0;
$vatRateLabel      = rtrim(rtrim(number_format($vatRatePct, 2, '.', ''), '0'), '.') ?: '0';
$currencySymbol    = class_exists('KIT_Commons') ? KIT_Commons::currency() : 'R';
$sadcChargeDisplay = $currencySymbol . number_format($sadcChargeValue, 2);
$sad500ChargeDisplay = $currencySymbol . number_format($sad500ChargeValue, 2);
$vatIncluded = class_exists('KIT_Waybills')
    ? (KIT_Waybills::normalize_flag_int($waybill['vat_include'] ?? 0) === 1)
    : false;

if (!isset($optionChoice) || $optionChoice == 1) :
    // Modern `.delivery-card` style using renderButton for all options
    ?>
    <div class="delivery-card flex flex-col gap-2 bg-slate-100 border-dotted border-2 border-gray-300 rounded-lg p-4 mb-2"
         data-kit-charge-config
         data-sad500="<?= esc_attr((string) $sad500ChargeValue); ?>"
         data-sadc="<?= esc_attr((string) $sadcChargeValue); ?>"
         data-vat-percent="<?= esc_attr((string) $vatRatePct); ?>"
         data-international-price="<?= esc_attr((string) (class_exists('KIT_Waybills') ? floatval(KIT_Waybills::international_price_in_rands()) : 0)); ?>">
        <!-- Hidden inputs for form submission -->
        <input type="hidden" name="include_sadc" id="include_sadc_input" value="<?= (!empty($waybill['include_sadc']) && $waybill['include_sadc'] == 1) ? '1' : '0' ?>">
        <input type="hidden" name="include_sad500" id="include_sad500_input" value="<?= (!empty($waybill['include_sad500']) && $waybill['include_sad500'] == 1) ? '1' : '0' ?>">
        <input type="hidden" name="vat_include" id="vat_include_input" value="<?= $vatIncluded ? '1' : '0' ?>">
        
        <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center">
            <?php
            // SADC Certificate
            $sadcHighlight = (!empty($waybill['include_sadc']) && $waybill['include_sadc'] == 1);
            echo KIT_Commons::renderButton(
                'SADC Certificate +' . $sadcChargeDisplay,
                $sadcHighlight ? 'warning' : 'secondary',
                'sm',
                [
                    'classes'   => 'delivery-card-btn addition-charge-btn w-full md:w-auto ' . ($sadcHighlight ? 'bg-yellow-400 text-black hover:bg-yellow-500' : ''),
                    'gradient'  => $sadcHighlight,
                    'name'      => 'include_sadc',
                    'id'        => 'sadc_certificate',
                    'type'      => 'button',
                    'data-field' => 'include_sadc',
                ]
            );

            // SAD500
            $sad500Highlight = (!empty($waybill['include_sad500']) && $waybill['include_sad500'] == 1);
            echo KIT_Commons::renderButton(
                'SAD500 +' . $sad500ChargeDisplay,
                $sad500Highlight ? 'warning' : 'secondary',
                'sm',
                [
                    'classes'   => 'delivery-card-btn addition-charge-btn w-full md:w-auto ' . ($sad500Highlight ? 'bg-yellow-400 text-black hover:bg-yellow-500' : ''),
                    'gradient'  => $sad500Highlight,
                    'name'      => 'include_sad500',
                    'id'        => 'include_sad500',
                    'type'      => 'button',
                    'data-field' => 'include_sad500',
                ]
            );

            // VAT Included
            $vatHighlight = $vatIncluded;
            echo KIT_Commons::renderButton(
                'VAT Included (' . $vatRateLabel . '%)',
                $vatHighlight ? 'warning' : 'secondary',
                'sm',
                [
                    'classes'   => 'delivery-card-btn addition-charge-btn w-full md:w-auto ' . ($vatHighlight ? 'bg-yellow-400 text-black hover:bg-yellow-500' : ''),
                    'gradient'  => $vatHighlight,
                    'name'      => 'vat_include',
                    'id'        => 'vat_include',
                    'type'      => 'button',
                    'data-field' => 'vat_include',
                ]
            );
            ?>
        </div>
        <div>
            <p class="text-xs text-gray-500 mt-2">Note: VAT and SADC are mutually exclusive. SAD500 is independent.</p>
        </div>
    </div>
    <script>
    (function() {
        // Handle button mode (optionChoice == 1) mutual exclusivity
        const vatBtn = document.getElementById('vat_include');
        const sadcBtn = document.getElementById('sadc_certificate');
        const sad500Btn = document.getElementById('include_sad500');
        const vatInput = document.getElementById('vat_include_input');
        const sadcInput = document.getElementById('include_sadc_input');
        const sad500Input = document.getElementById('include_sad500_input');
        
        if (!vatBtn || !sadcBtn || !sad500Btn || !vatInput || !sadcInput || !sad500Input) return;
        
        function getButtonState(btn, input) {
            return input.value === '1';
        }
        
        function setButtonState(btn, input, checked) {
            input.value = checked ? '1' : '0';
            
            // Get all classes and filter out color-related ones
            const allClasses = Array.from(btn.classList);
            const colorPatterns = [
                /^bg-/, /^text-/, /^hover:bg-/, /^active:bg-/, /^border-/, /^hover:border-/,
                /^from-/, /^to-/, /^bg-gradient/,
                'warning', 'secondary', 'primary', 'success', 'danger'
            ];
            
            // Remove all color-related classes
            allClasses.forEach(cls => {
                const shouldRemove = colorPatterns.some(pattern => {
                    if (typeof pattern === 'string') {
                        return cls === pattern;
                    }
                    return pattern.test(cls);
                });
                if (shouldRemove) {
                    btn.classList.remove(cls);
                }
            });
            
            // Clear inline styles
            btn.style.backgroundColor = '';
            btn.style.backgroundImage = '';
            btn.style.color = '';
            btn.style.borderColor = '';
            
            if (checked) {
                // Apply checked/active state with gradient (orange to amber)
                btn.classList.add('bg-gradient-to-r', 'from-orange-600', 'to-amber-600', 
                                 'hover:from-orange-700', 'hover:to-amber-700',
                                 'text-white', 'font-semibold', 'shadow-lg', 'hover:shadow-xl');
            } else {
                // Apply unchecked/inactive state (gray/secondary) - can use gradient too if needed
                btn.classList.add('bg-gray-100', 'text-gray-700', 'hover:bg-gray-200', 
                                 'border', 'border-gray-300', 'hover:border-gray-400');
            }
        }
        
        function updateButtonStates() {
            const vatChecked = getButtonState(vatBtn, vatInput);
            const sadcChecked = getButtonState(sadcBtn, sadcInput);
            
            // VAT and SADC are mutually exclusive - handle disabled state only
            // Don't update visual states here - that's handled by setButtonState
            if (vatChecked) {
                sadcBtn.disabled = true;
                sadcBtn.style.opacity = '0.5';
                sadcBtn.style.cursor = 'not-allowed';
            } else {
                sadcBtn.disabled = false;
                sadcBtn.style.opacity = '1';
                sadcBtn.style.cursor = 'pointer';
            }
            
            if (sadcChecked) {
                vatBtn.disabled = true;
                vatBtn.style.opacity = '0.5';
                vatBtn.style.cursor = 'not-allowed';
            } else {
                vatBtn.disabled = false;
                vatBtn.style.opacity = '1';
                vatBtn.style.cursor = 'pointer';
            }
        }
        
        function notifyHeaderTotal() {
            if (typeof window.kitUpdateWaybillHeaderTotal === 'function') {
                window.kitUpdateWaybillHeaderTotal();
            } else {
                document.dispatchEvent(new CustomEvent('kit:waybill-total-dirty'));
            }
        }

        // Handle clicks - prevent delivery-card handler interference
        vatBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            
            if (this.disabled) return;
            
            const currentState = getButtonState(this, vatInput);
            const newState = !currentState;
            
            // If enabling VAT, disable SADC
            if (newState && getButtonState(sadcBtn, sadcInput)) {
                setButtonState(sadcBtn, sadcInput, false);
            }
            
            setButtonState(this, vatInput, newState);
            updateButtonStates();
            notifyHeaderTotal();
        });
        
        sadcBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            
            if (this.disabled) return;
            
            const currentState = getButtonState(this, sadcInput);
            const newState = !currentState;
            
            // Update this button's state first
            setButtonState(this, sadcInput, newState);
            
            // If enabling SADC, disable VAT
            if (newState && getButtonState(vatBtn, vatInput)) {
                setButtonState(vatBtn, vatInput, false);
            }
            
            // Update disabled states for mutual exclusivity
            updateButtonStates();
            notifyHeaderTotal();
        });
        
        sad500Btn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            
            const currentState = getButtonState(this, sad500Input);
            setButtonState(this, sad500Input, !currentState);
            // SAD500 doesn't affect others
            notifyHeaderTotal();
        });
        
        // Initialize visual states and disabled states
        function initializeStates() {
            // Set visual states based on current input values
            setButtonState(vatBtn, vatInput, getButtonState(vatBtn, vatInput));
            setButtonState(sadcBtn, sadcInput, getButtonState(sadcBtn, sadcInput));
            setButtonState(sad500Btn, sad500Input, getButtonState(sad500Btn, sad500Input));
            // Update disabled states for mutual exclusivity
            updateButtonStates();
        }
        
        // Initial state - run immediately on DOM ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeStates);
        } else {
            initializeStates();
        }
    })();
    </script>
<?php elseif (isset($optionChoice) && $optionChoice == 2):
        // Strict: only treat as selected when DB value is exactly 1 (not amount or other truthy)
        $vat_checked = $vatIncluded;
        $sadc_checked = (int)(isset($waybill['include_sadc']) ? $waybill['include_sadc'] : 0) === 1;
        $sad500_checked = (int)(isset($waybill['include_sad500']) ? $waybill['include_sad500'] : 0) === 1;
        // Enforce mutual exclusivity: never show both VAT and SADC checked (prefer VAT if both set in DB)
        if ($vat_checked && $sadc_checked) {
            $sadc_checked = false;
        }

        $chargeToggleLabelClass = 'charge-toggle-label block w-full p-4 rounded-lg border-2 border-gray-300 cursor-pointer text-center font-medium text-sm transition-all duration-200 hover:shadow-md hover:border-gray-400 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:shadow-lg peer-checked:text-blue-700';
        $sadcLabelDisabled = $vat_checked ? ' opacity-40 bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed pointer-events-none hover:shadow-none hover:border-gray-200' : '';
        $vatLabelDisabled = $sadc_checked ? ' opacity-40 bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed pointer-events-none hover:shadow-none hover:border-gray-200' : '';
?>
    <div class="gap-4" data-kit-charge-config
         data-sad500="<?= esc_attr((string) $sad500ChargeValue); ?>"
         data-sadc="<?= esc_attr((string) $sadcChargeValue); ?>"
         data-vat-percent="<?= esc_attr((string) $vatRatePct); ?>"
         data-international-price="<?= esc_attr((string) (class_exists('KIT_Waybills') ? floatval(KIT_Waybills::international_price_in_rands()) : 0)); ?>">
        <p class="text-xs text-gray-500 mb-2">VAT and SADC are mutually exclusive. Deselect one to enable the other. SAD500 is independent.</p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="flex">
                <input
                    type="checkbox"
                    name="include_sadc"
                    id="sadc_certificate"
                    value="1"
                    class="sr-only peer optionz charge-toggle-checkbox"
                    <?= $sadc_checked ? 'checked' : ''; ?>
                    <?= $vat_checked ? 'disabled' : ''; ?>>
                <label for="sadc_certificate" class="<?= esc_attr($chargeToggleLabelClass . $sadcLabelDisabled); ?>">
                    <span class="block">SADC Certificate</span>
                    <span class="block text-[11px] font-medium opacity-70 mt-0.5">+<?= esc_html($sadcChargeDisplay); ?></span>
                </label>
            </div>
            <div class="flex">
                <input
                    type="checkbox"
                    name="include_sad500"
                    id="include_sad500"
                    value="1"
                    class="sr-only peer charge-toggle-checkbox"
                    <?= $sad500_checked ? 'checked' : ''; ?>>
                <label for="include_sad500" class="<?= esc_attr($chargeToggleLabelClass); ?>">
                    <span class="block">SAD500</span>
                    <span class="block text-[11px] font-medium opacity-70 mt-0.5">+<?= esc_html($sad500ChargeDisplay); ?></span>
                </label>
            </div>
            <div class="flex">
                <input
                    type="checkbox"
                    name="vat_include"
                    id="vat_include"
                    value="1"
                    class="sr-only peer charge-toggle-checkbox"
                    <?= $vat_checked ? 'checked' : ''; ?>
                    <?= $sadc_checked ? 'disabled' : ''; ?>>
                <label for="vat_include" class="<?= esc_attr($chargeToggleLabelClass . $vatLabelDisabled); ?>">
                    <span class="block">VAT Included (<?= esc_html($vatRateLabel); ?>%)</span>
                </label>
            </div>
        </div>
    </div>
    <script>
    (function() {
        const vatCheckbox = document.getElementById('vat_include');
        const sadcCheckbox = document.getElementById('sadc_certificate');
        const sad500Checkbox = document.getElementById('include_sad500');

        if (!vatCheckbox || !sadcCheckbox || !sad500Checkbox) return;

        const disabledLabelClasses = [
            'opacity-40', 'bg-gray-100', 'text-gray-400', 'border-gray-200',
            'cursor-not-allowed', 'pointer-events-none',
            'hover:shadow-none', 'hover:border-gray-200'
        ];

        function setLabelDisabled(checkbox, disabled) {
            const label = document.querySelector('label[for="' + checkbox.id + '"]');
            checkbox.disabled = !!disabled;
            if (!label) return;
            disabledLabelClasses.forEach(function (cls) {
                label.classList.toggle(cls, !!disabled);
            });
            label.classList.toggle('cursor-pointer', !disabled);
        }

        function notifyHeaderTotal() {
            if (typeof window.kitUpdateWaybillHeaderTotal === 'function') {
                window.kitUpdateWaybillHeaderTotal();
            } else {
                document.dispatchEvent(new CustomEvent('kit:waybill-total-dirty'));
            }
        }

        function updateStates() {
            const vatChecked = vatCheckbox.checked;
            const sadcChecked = sadcCheckbox.checked;

            // SAD500 is always enabled
            setLabelDisabled(sad500Checkbox, false);

            // VAT and SADC mutually exclusive: disable the other when one is checked
            setLabelDisabled(sadcCheckbox, !!vatChecked);
            setLabelDisabled(vatCheckbox, !!sadcChecked);
        }

        function initCostDetailsCheckboxes() {
            updateStates();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initCostDetailsCheckboxes);
        } else {
            initCostDetailsCheckboxes();
        }

        vatCheckbox.addEventListener('change', function() {
            if (this.checked && sadcCheckbox.checked) {
                sadcCheckbox.checked = false;
            }
            updateStates();
            notifyHeaderTotal();
        });

        sadcCheckbox.addEventListener('change', function() {
            if (this.checked && vatCheckbox.checked) {
                vatCheckbox.checked = false;
            }
            updateStates();
            notifyHeaderTotal();
        });

        sad500Checkbox.addEventListener('change', notifyHeaderTotal);
    })();
    </script>
<?php elseif (isset($optionChoice) && $optionChoice == 3): ?>
    <!-- Display only, no inputs -->
    <div class="flex flex-col gap-2">
        <div>
            <span class="font-semibold">SADC Certificate:</span>
            <span><?= !empty($waybill['include_sadc']) ? '<span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-green-500 text-green-100">
                                    Yes
                                </span>: +' . $sadcChargeDisplay : '<span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-red-500 text-red-100">
                                    No
                                </span>' ?></span>
        </div>
        <div>
            <span class="font-semibold">SAD500:</span>
            <span><?= !empty($waybill['include_sad500']) ? '<span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-green-500 text-green-100">
                                    Yes
                                </span> +' . $sad500ChargeDisplay : '<span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-red-500 text-red-100">
                                    No
                                </span>' ?></span>
        </div>
        <div>
            <span class="font-semibold">VAT Included:</span>
            <span><?= $vatIncluded ? '<span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-green-500 text-blue-100">
                                    Yes
                                </span> +' . $currencySymbol . ($waybill['miscellaneous']['others']['vat_total'] ?? 0) : '<span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-red-500 text-red-100">
                                    No
                                </span>' ?></span>
        </div>
        <?php if (!$vatIncluded): ?>
            <div>
                <span class="font-semibold">Agent Clearing & Documentation:</span>
                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-blue-500 text-blue-100">
                    Yes
                </span>: +<?= $currencySymbol . number_format(KIT_Waybills::international_price_in_rands(), 2) ?>
            </div>
        <?php endif; ?>
    </div>
<?php elseif (isset($optionChoice) && $optionChoice == 4):
    echo '<div class="flex gap-4">';

    // SADC Certificate button
    $sadcHighlight = (!empty($waybill['include_sadc']) && $waybill['include_sadc'] == 1);
    echo KIT_Commons::renderButton('SADC Certificate +' . $sadcChargeDisplay, $sadcHighlight ? 'warning' : 'secondary', 'lg', [
        'classes' => $sadcHighlight ? 'bg-yellow-400 text-black hover:bg-yellow-500' : '',
        'gradient' => $sadcHighlight
    ]);

    // SAD500 button
    $sad500Highlight = (!empty($waybill['include_sad500']) && $waybill['include_sad500'] == 1);
    echo KIT_Commons::renderButton('SAD500 +' . $sad500ChargeDisplay, $sad500Highlight ? 'warning' : 'secondary', 'lg', [
        'classes' => $sad500Highlight ? 'bg-yellow-400 text-black hover:bg-yellow-500' : '',
        'gradient' => $sad500Highlight
    ]);

    // VAT button
    $vatHighlight = $vatIncluded;
    echo KIT_Commons::renderButton('VAT +' . $vatRateLabel . '%', $vatHighlight ? 'warning' : 'secondary', 'lg', [
        'classes' => $vatHighlight ? 'bg-yellow-400 text-black hover:bg-yellow-500' : '',
        'gradient' => $vatHighlight
    ]);

    // Agent Clearing button
    if (!$vatIncluded) {
        echo KIT_Commons::renderButton('Agent Clearing & Documentation +' . $currencySymbol . number_format(KIT_Waybills::international_price_in_rands(), 2), 'warning', 'lg', [
            'classes' => 'bg-yellow-400 text-black hover:bg-yellow-500',
            'gradient' => true
        ]);
    }
    echo '</div>';
?>

<?php endif; ?>