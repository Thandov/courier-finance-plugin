<?php if (! defined('ABSPATH')) {
    exit;
}
// Include user roles for permission checking
require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/user-roles.php';

// Enqueue required scripts for this component (rate fetch + calculations + localized myPluginAjax)
if (class_exists('KIT_Commons') && method_exists('KIT_Commons', 'enqueueComponentScripts')) {
    KIT_Commons::enqueueComponentScripts(['kitscript']);
}

// Get origin_country_id from waybill (should be 1 for South Africa)
$origin_country_id_value = 1; // Default to South Africa
if (isset($waybill) && isset($waybill['origin_country_id']) && ! empty($waybill['origin_country_id'])) {
    $origin_country_id_value = $waybill['origin_country_id'];
} elseif (isset($origin_country_id) && ! empty($origin_country_id)) {
    $origin_country_id_value = $origin_country_id;
}
$total_mass_kg = null;

// Safety check for $waybill variable
if (isset($waybill) && $waybill !== null) {
    if (is_object($waybill) && isset($waybill->total_mass_kg)) {
        $total_mass_kg = $waybill->total_mass_kg;
    } elseif (is_array($waybill) && isset($waybill['total_mass_kg'])) {
        $total_mass_kg = $waybill['total_mass_kg'];
    }
}

$weightDisplayOption = $weightDisplayOption ?? 1; // Default to 1 for floating popup
// Prefill Custom Pricing checkbox when edit/view passes $checkManny (see waybill-functions.php)
$checkManny = isset($checkManny) ? $checkManny : '';
$manny_mass_rate_value = 0;
if (isset($waybill) && is_array($waybill)) {
    if (! empty($waybill['miscellaneous']['others']['manny_mass_rate'])) {
        $manny_mass_rate_value = floatval($waybill['miscellaneous']['others']['manny_mass_rate']);
    } elseif (isset($waybill['mass_charge_manipulator']) && $waybill['mass_charge_manipulator'] !== '') {
        $manny_mass_rate_value = floatval($waybill['mass_charge_manipulator']);
    }
    if ($checkManny !== 'checked' && ! empty($waybill['miscellaneous']['others']['manny'])) {
        $checkManny = 'checked';
    }
}
$showManipulatorInput = ($checkManny === 'checked') || ($manny_mass_rate_value != 0);

// Resolve mass rate for display: misc snapshot, then derive from mass_charge / kg.
$display_mass_rate = '';
$display_mass_charge = '';
if (isset($waybill) && is_array($waybill)) {
    $stored_mass_rate = floatval($waybill['miscellaneous']['others']['mass_rate'] ?? ($waybill['mass_rate'] ?? 0));
    $mass_charge_val = floatval($waybill['mass_charge'] ?? 0);
    $mass_kg_val = floatval($total_mass_kg ?? ($waybill['total_mass_kg'] ?? 0));
    if ($stored_mass_rate <= 0 && $mass_kg_val > 0 && $mass_charge_val > 0) {
        $stored_mass_rate = $mass_charge_val / $mass_kg_val;
    }
    if ($stored_mass_rate > 0) {
        $display_mass_rate = number_format($stored_mass_rate, 2, '.', '');
    }
    if ($mass_charge_val > 0 || (isset($waybill['mass_charge']) && $waybill['mass_charge'] !== '' && $waybill['mass_charge'] !== null)) {
        $display_mass_charge = number_format($mass_charge_val, 2, '.', '');
    }
}
?>
<input type="hidden" name="origin_country_id" value="<?php echo esc_attr($origin_country_id_value) ?>" id="countrydestination_id" />
<?php

?>
<input type="hidden" name="current_rate" id="current_rate" value="<?php echo esc_attr($display_mass_rate); ?>">
<input type="hidden" name="base_rate" id="base_rate" value="<?php echo esc_attr($display_mass_rate); ?>">
<?php if ((int) $weightDisplayOption == 1): ?>
    <div class="kit-mass-component">
        <div class="flex justify-between items-center">
            <?php echo KIT_Commons::prettyHeading(['words' => 'Mass', 'classes' => 'mb-6']) ?>
            <span class="kit-tooltip" data-tooltip="Open Mass Calculator">
                <button class="btn btn-primary" type="button" aria-label="Open Mass Calculator">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                </button>
            </span>

        </div>

        <div class="flex flex-col gap-4 justify-center align-middle">
            <div class="flex flex-col gap-2">
            <div class="flex flex-row gap-4 justify-start items-start">
                <div>
                    <?php echo KIT_Commons::Lnumber([
                        'label'   => "Total Mass (Kg)",
                        'name'    => "total_mass_kg",
                        'id'      => "total_mass_kg",
                        'value'   => $total_mass_kg !== null && $total_mass_kg !== '' ? number_format((float) $total_mass_kg, 2, '.', '') : $total_mass_kg,
                        'preset'  => 'default_input',
                        'class'   => 'kit-total-mass',
                        'special' => '',
                        'onclick' => '',
                    ]); ?>
                </div>
                <div class="mt-6 h-10 flex items-center justify-center px-1 text-gray-600 font-semibold select-none" aria-hidden="true">×</div>
                <?php if (KIT_User_Roles::can_see_prices()): ?>
                    <div>
                        <?php echo KIT_Commons::Lnumber([
                            'label'    => 'Mass Rate (R)',
                            'name'     => 'mass_rate',
                            'id'       => 'mass_rate',
                            'value'    => esc_attr($display_mass_rate),
                            'preset'   => 'readonly_field',
                            'class'    => 'kit-mass-rate',
                            'special'  => 'readonly',
                            'tabindex' => '1',
                        ]); ?>
                        
                    </div>
                <?php endif; ?>
                <div class="mt-6 h-10 flex items-center justify-center px-1 text-gray-600 font-semibold select-none" aria-hidden="true">=</div>
                <?php if (KIT_User_Roles::can_see_prices()): ?>
                    <div>
                        <?php echo KIT_Commons::Linput([
                            'label'   => 'Mass Total Cost (R)',
                            'name'    => 'mass_charge',
                            'id'      => 'mass_charge',
                            'type'    => 'text',
                            'value'   => esc_attr($display_mass_charge),
                            'preset'  => 'readonly_field',
                            'class'   => 'kit-mass-charge',
                            'special' => 'readonly',
                        ]); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div id="mass-calculation-messages" class="min-h-[0px]"></div>
            <?php echo KIT_Commons::displayHere([
                'id' => 'mass-display-here',
                'variant' => 'neutral',
                'classes' => 'min-h-[0px]',
                'hidden' => true,
            ]); ?>
            <!-- Calculation messages: legacy blocks append to #mass-calculation-messages; use #mass-display-here for unified status text via JS -->
            </div>

            <div class="flex flex-row gap-4 justify-start align-start">
                <?php if (KIT_User_Roles::can_see_prices()): ?>
                    <div>
                        <label for="enable_price_manipulator">
                            <div>
                                <span class="block text-xs font-medium text-gray-700 ">Custom Pricing</span>

                                <?php
                                echo KIT_Commons::Lcheckbox([
                                    'no_label' => true,
                                    'label' => '',
                                    'id' => 'enable_price_manipulator',
                                    'name' => 'enable_price_manipulator',
                                    'value' => '1',
                                    'checked' => ($checkManny === 'checked'),
                                    'class' => 'form-checkbox my-2 text-blue-600',
                                ]);
                                ?>
                            </div>
                        </label>
                        <?php $showing = $showManipulatorInput ? 'block' : 'none'; ?>
                        <div id="price_manipulator_input_container" style="display: <?php echo esc_attr($showing); ?>;">
                            <?php
                            echo KIT_Commons::Lnumber([
                                'label'      => 'Add to Charge  (R)',
                                'name'       => 'mass_charge_manipulator',
                                'id'         => 'mass_charge_manipulator',
                                'value'      => $manny_mass_rate_value,
                                'preset'     => 'default_input',
                            ]);
                            ?>
                        </div>
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const roots = Array.prototype.slice.call(document.querySelectorAll('.kit-mass-component'));
                            const targets = roots.length ? roots : [null]; // fallback for legacy pages
                            targets.forEach(function(root, idx) {
                                if (root && root.dataset && root.dataset.kitMassInit === '1') return;
                                if (root && root.dataset) root.dataset.kitMassInit = '1';
                                const q = (sel) => root ? root.querySelector(sel) : document.querySelector(sel);
                                const qa = (sel) => root ? root.querySelectorAll(sel) : document.querySelectorAll(sel);
                                // Get all required elements (scoped per component)
                                const checkbox = q('#enable_price_manipulator');
                                const inputContainer = q('#price_manipulator_input_container');
                                const totalMassInput = q('.kit-total-mass, #total_mass_kg');
                                const massRateInput = q('.kit-mass-rate, #mass_rate');
                                const massChargeInput = q('.kit-mass-charge, #mass_charge');
                                const massBaseDisplay = q('#mass_base_display');
                                const massManipDisplay = q('#mass_manip_display');
                                const massEqualsDisplay = q('#mass_equals_display');
                                const manipulatorInput = q('#mass_charge_manipulator');

                                function syncManipulatorContainerVisibility(isChecked) {
                                    if (!inputContainer) return;
                                    inputContainer.style.display = isChecked ? 'block' : 'none';
                                }

                                function focusManipulatorInput() {
                                    if (!manipulatorInput) return;
                                    setTimeout(function() {
                                        manipulatorInput.focus();
                                        manipulatorInput.select();
                                        manipulatorInput.classList.add('manipulator-focused');
                                        setTimeout(function() {
                                            manipulatorInput.classList.remove('manipulator-focused');
                                        }, 3000);
                                    }, 50);
                                }

                                function handlePriceManipulatorToggle(triggerEl) {
                                    const cb = triggerEl || checkbox;
                                    if (!cb || !inputContainer) return;

                                    const enabling = !!cb.checked;
                                    syncManipulatorContainerVisibility(enabling);

                                    if (enabling) {
                                        const currentRate = getEffectiveRate();
                                        if (currentRate > 0) {
                                            rateAnchor = currentRate;
                                        } else {
                                            const visibleRate = parseNumber(massRateInput && massRateInput.value);
                                            if (visibleRate > 0) {
                                                rateAnchor = visibleRate;
                                            } else {
                                                showCalculationError('Please set a valid mass rate before using custom pricing.');
                                                cb.checked = false;
                                                rateAnchor = null;
                                                syncManipulatorContainerVisibility(false);
                                                calculateMassCharge(false);
                                                return;
                                            }
                                        }
                                        focusManipulatorInput();
                                    } else {
                                        rateAnchor = null;
                                        if (massRateInput) {
                                            const br = getBaseRate();
                                            if (br > 0) {
                                                massRateInput.value = br.toFixed(2);
                                            }
                                        }
                                    }

                                    const massVal = parseNumber(totalMassInput && totalMassInput.value);
                                    const rateVal = parseNumber(massRateInput && massRateInput.value);
                                    if (enabling && massVal > 0 && rateVal <= 0 && typeof fetchRatePerKg === 'function') {
                                        try {
                                            fetchRatePerKg(root);
                                        } catch (e) {
                                            console.error('Rate fetch failed:', e);
                                            showRateError('Unable to fetch rate. Please check your connection.');
                                        }
                                    }
                                    calculateMassCharge(false);
                                }

                                const baseRateField = q('#base_rate') || document.getElementById('base_rate');
                                // Helper function to properly parse numbers with comma decimal separators
                                function parseNumber(value) {
                                    if (!value || value === '') return 0;
                                    // Convert comma to dot for decimal separator
                                    const normalized = value.toString().replace(',', '.');
                                    const parsed = parseFloat(normalized);
                                    return Number.isFinite(parsed) ? parsed : 0;
                                }

                                function getBaseRate() {
                                    return parseNumber(baseRateField && baseRateField.value);
                                }

                                // Always use the latest visible rate if present; fallback to the snapshot
                                function getEffectiveRate() {
                                    const liveRate = parseNumber(massRateInput && massRateInput.value);
                                    const baseRate = getBaseRate();

                                    // Debug logging (production-safe)
                                    if (window.console && console.log && ((typeof myPluginAjax !== 'undefined' && myPluginAjax.wp_debug) || (typeof window !== 'undefined' && window.WP_DEBUG))) {
                                        console.log('Rate calculation:', {
                                            liveRate: liveRate,
                                            baseRate: baseRate,
                                            effectiveRate: (liveRate > 0) ? liveRate : baseRate
                                        });
                                    }

                                    return (liveRate > 0) ? liveRate : baseRate;
                                }

                                // Anchor the BASE RATE when manipulator is enabled so edits don't compound
                                let rateAnchor = null;
                                // ✅ BULLETPROOF: Function to calculate mass charge with comprehensive validation
                                function calculateMassCharge(showRateError = false) {
                                    // Clear any existing errors first
                                    clearCalculationErrors();
                                    // ✅ BULLETPROOF: Comprehensive input validation and sanitization
                                    const rawMass = totalMassInput ? totalMassInput.value : '';
                                    const totalMass = parseNumber(rawMass);

                                    // ✅ BULLETPROOF: Input validation with detailed error handling
                                    if (rawMass !== '' && totalMass < 0) {
                                        console.warn('Invalid mass value detected:', rawMass);
                                        showCalculationError('Invalid mass value. Please enter a positive number.');
                                        if (massChargeInput) massChargeInput.value = '0.00';
                                        return;
                                    }

                                    if (totalMass === 0) {
                                        clearCalculationDisplay();
                                        return;
                                    }

                                    // ✅ BULLETPROOF: Validate mass limits
                                    if (totalMass > 10000) {
                                        showCalculationError('Mass exceeds maximum limit of 10,000 kg.');
                                        return;
                                    }

                                    // ✅ BULLETPROOF: Safe manipulator value extraction
                                    const rawManipulator = manipulatorInput ? manipulatorInput.value : '';
                                    const addAmount = (checkbox && checkbox.checked) ? parseNumber(rawManipulator) : 0;

                                    // Debug logging (production-safe)
                                    if (window.console && console.log && ((typeof myPluginAjax !== 'undefined' && myPluginAjax.wp_debug) || (typeof window !== 'undefined' && window.WP_DEBUG))) {
                                        console.log('Manipulator calculation:', {
                                            checkboxChecked: checkbox && checkbox.checked,
                                            rawManipulator: rawManipulator,
                                            addAmount: addAmount,
                                            manipulatorInputExists: !!manipulatorInput
                                        });
                                    }

                                    // ✅ BULLETPROOF: Validate manipulator value (allow negative numbers for discounts)
                                    if (checkbox && checkbox.checked && rawManipulator !== '' && !Number.isFinite(addAmount)) {
                                        showCalculationError('Invalid manipulator value. Please enter a valid number.');
                                        return;
                                    }

                                    // ✅ BULLETPROOF: Safe rate calculation with comprehensive validation
                                    try {
                                        const effectiveRate = getEffectiveRate();

                                        // ✅ BULLETPROOF: Validate effective rate - only show error if explicitly requested
                                        if (!Number.isFinite(effectiveRate) || effectiveRate <= 0) {
                                            // Only show error if we have a mass value but no valid rate AND showRateError is true
                                            if (totalMass > 0 && showRateError) {
                                                showCalculationError('Invalid rate. Please ensure a valid rate is set.');
                                            }
                                            return;
                                        }

                                        let baseRateForManip = (checkbox && checkbox.checked) ?
                                            (Number.isFinite(rateAnchor) && rateAnchor !== null && rateAnchor > 0 ? rateAnchor : effectiveRate) :
                                            effectiveRate;
                                        // Ensure we have a valid base rate for manipulation
                                        if (checkbox && checkbox.checked && baseRateForManip <= 0) {
                                            // Try to get rate from the visible input as fallback
                                            const visibleRate = parseNumber(massRateInput && massRateInput.value);
                                            if (visibleRate > 0) {
                                                // Use the visible rate as the base rate
                                                rateAnchor = visibleRate;
                                                baseRateForManip = visibleRate;
                                            } else {
                                                showCalculationError('Please set a valid mass rate before using custom pricing.');
                                                return;
                                            }
                                        }

                                        const newRate = baseRateForManip + (checkbox && checkbox.checked ? addAmount : 0);

                                        // Debug logging (production-safe)
                                        if (window.console && console.log && ((typeof myPluginAjax !== 'undefined' && myPluginAjax.wp_debug) || (typeof window !== 'undefined' && window.WP_DEBUG))) {
                                            console.log('Final calculation:', {
                                                baseRateForManip: baseRateForManip,
                                                addAmount: addAmount,
                                                newRate: newRate,
                                                checkboxChecked: checkbox && checkbox.checked
                                            });
                                        }

                                        // ✅ BULLETPROOF: Validate new rate
                                        if (!Number.isFinite(newRate) || newRate <= 0) {
                                            showCalculationError('Invalid calculated rate. Please check your inputs.');
                                            return;
                                        }

                                        const finalCharge = totalMass * newRate;

                                        // ✅ BULLETPROOF: Validate final charge (allow negative for credits/discounts)
                                        if (!Number.isFinite(finalCharge)) {
                                            showCalculationError('Invalid calculated charge. Please check your inputs.');
                                            return;
                                        }

                                        // ✅ BULLETPROOF: Update displays with validation
                                        if (massBaseDisplay) {
                                            massBaseDisplay.textContent = baseRateForManip.toFixed(2);
                                        }
                                        if (massChargeInput) {
                                            massChargeInput.value = finalCharge.toFixed(2);
                                        }

                                        if (checkbox && checkbox.checked) {
                                            if (massManipDisplay) massManipDisplay.textContent = ` + R${addAmount.toFixed(2)}`;
                                            if (massEqualsDisplay) massEqualsDisplay.textContent = ` = R${newRate.toFixed(2)}`;
                                            if (massRateInput) massRateInput.value = newRate.toFixed(2);
                                        } else {
                                            if (massManipDisplay) massManipDisplay.textContent = '';
                                            if (massEqualsDisplay) massEqualsDisplay.textContent = '';
                                            if (massRateInput && Number.isFinite(effectiveRate) && effectiveRate > 0) {
                                                massRateInput.value = effectiveRate.toFixed(2);
                                            }
                                        }

                                        // Clear any previous calculation errors
                                        clearCalculationErrors();

                                    } catch (error) {
                                        console.error('Calculation error:', error);
                                        showCalculationError('An error occurred during calculation. Please try again.');
                                    }
                                }

                                // Event listeners
                                if (checkbox && inputContainer) {
                                    checkbox.addEventListener('click', function() {
                                        if (this.checked && manipulatorInput) {
                                            focusManipulatorInput();
                                        }
                                    });

                                    checkbox.addEventListener('change', function() {
                                        handlePriceManipulatorToggle(this);
                                    });

                                    // If AJAX updates the base rate while custom pricing is enabled, refresh the anchor.
                                    if (massRateInput) {
                                        massRateInput.addEventListener('change', function() {
                                            if (!(checkbox && checkbox.checked)) return;
                                            const newBase = getEffectiveRate();
                                            if (newBase > 0) {
                                                rateAnchor = newBase;
                                                calculateMassCharge(false);
                                            }
                                        });
                                    }

                                    // Keep container visibility aligned with checkbox on load.
                                    syncManipulatorContainerVisibility(checkbox.checked);

                                    // If editing and a saved manipulator exists, ensure the field is visible.
                                    <?php if ($showManipulatorInput): ?>
                                        checkbox.checked = true;
                                        syncManipulatorContainerVisibility(true);
                                        setTimeout(function() {
                                            focusManipulatorInput();
                                        }, 200);
                                    <?php endif; ?>
                                }

                                // Add debounced calculation for better UX
                                let calculationTimeout;

                                function debouncedCalculate() {
                                    clearTimeout(calculationTimeout);
                                    calculationTimeout = setTimeout(function() {
                                        calculateMassCharge(false); // Don't show rate error during typing
                                    }, 500); // 500ms delay
                                }

                                // Add event listeners for real-time calculation (with debouncing)
                                if (totalMassInput) {
                                    totalMassInput.addEventListener('input', function() {
                                        // Clear any rate error when user starts typing
                                        clearCalculationErrors();
                                        debouncedCalculate();
                                    });
                                    totalMassInput.addEventListener('blur', function() {
                                        calculateMassCharge(true); // Show rate error on blur
                                    });
                                }
                                if (manipulatorInput) {
                                    manipulatorInput.addEventListener('input', debouncedCalculate);
                                    manipulatorInput.addEventListener('blur', function() {
                                        calculateMassCharge(false); // Don't show rate error for manipulator
                                    });
                                }
                                if (massRateInput) {
                                    massRateInput.addEventListener('input', debouncedCalculate);
                                    massRateInput.addEventListener('blur', function() {
                                        calculateMassCharge(false); // Don't show rate error for rate input
                                    });
                                    massRateInput.addEventListener('change', function() {
                                        calculateMassCharge(false); // Don't show rate error on change
                                    });
                                }

                                // Unified status strip (global .display-here component)
                                const displayHere = document.getElementById('mass-display-here');

                                function setDisplayHere(message, variant = 'neutral', autoHideMs = 0) {
                                    if (!displayHere) return;
                                    const skins = {
                                        neutral: 'text-gray-700 bg-gray-50 border-gray-200',
                                        error: 'text-red-700 bg-red-50 border-red-200',
                                        success: 'text-green-700 bg-green-50 border-green-200',
                                        warning: 'text-amber-800 bg-amber-50 border-amber-200',
                                        info: 'text-blue-800 bg-blue-50 border-blue-200',
                                    };
                                    const base = 'display-here text-sm mt-2 p-2 border rounded';
                                    const key = skins[variant] ? variant : 'neutral';
                                    displayHere.className = `${base} ${skins[key]}`;
                                    displayHere.textContent = message || '';
                                    if (!message) {
                                        displayHere.setAttribute('hidden', 'hidden');
                                        return;
                                    }
                                    displayHere.removeAttribute('hidden');
                                    if (autoHideMs && autoHideMs > 0) {
                                        const hideAt = Date.now() + autoHideMs;
                                        displayHere.dataset.hideAt = String(hideAt);
                                        setTimeout(() => {
                                            // Only hide if nothing newer replaced it.
                                            if (displayHere.dataset.hideAt === String(hideAt)) {
                                                displayHere.textContent = '';
                                                displayHere.setAttribute('hidden', 'hidden');
                                            }
                                        }, autoHideMs);
                                    }
                                }

                                // ✅ BULLETPROOF: Comprehensive error handling functions
                                function showRateError(message) {
                                    setDisplayHere(message, 'error', 8000);
                                }

                                function showCalculationError(message) {
                                    setDisplayHere(message, 'error', 3000);
                                }

                                function clearCalculationDisplay() {
                                    if (massChargeInput) massChargeInput.value = '0.00';
                                    if (massBaseDisplay) massBaseDisplay.textContent = '0.00';
                                    if (massManipDisplay) massManipDisplay.textContent = '';
                                    if (massEqualsDisplay) massEqualsDisplay.textContent = '';
                                    setDisplayHere('', 'neutral', 0);
                                }

                                function clearRateErrors() {
                                    setDisplayHere('', 'neutral', 0);
                                }

                                function clearCalculationErrors() {
                                    setDisplayHere('', 'neutral', 0);
                                }

                                // ✅ IMPROVED RATE FETCHING WITH DEBOUNCING
                                let rateFetchTimeout;
                                if (totalMassInput) {
                                    totalMassInput.addEventListener('blur', function() {
                                        const mass = parseNumber(this.value);
                                        if (mass > 0) {
                                            clearTimeout(rateFetchTimeout);
                                            rateFetchTimeout = setTimeout(function() {
                                                if (typeof fetchRatePerKg === 'function') {
                                                    try {
                                                        fetchRatePerKg();
                                                    } catch (e) {
                                                        console.error('Rate fetch failed:', e);
                                                        showRateError('Unable to fetch rate. Please try again.');
                                                    }
                                                }
                                            }, 300); // 300ms debounce
                                        }
                                    });
                                }

                                // Initial calculation
                                calculateMassCharge(false); // Don't show rate error on page load

                                // ✅ BULLETPROOF: Auto-trigger rate fetch on page load if mass is present
                                function autoTriggerRateFetch() {
                                    const initialMass = parseNumber(totalMassInput && totalMassInput.value);
                                    const initialRate = parseNumber(massRateInput && massRateInput.value);
                                    const directionId = (typeof window.kitResolveDirectionId === 'function')
                                        ? window.kitResolveDirectionId()
                                        : (document.getElementById('direction_id') ? document.getElementById('direction_id').value : '');
                                    const originCountryId = document.getElementById('countrydestination_id') ? document.getElementById('countrydestination_id').value : '';

                                    console.log('Auto-trigger check:', {
                                        mass: initialMass,
                                        rate: initialRate,
                                        directionId: directionId,
                                        originCountryId: originCountryId
                                    });

                                    if (initialMass > 0 && initialRate <= 0 && directionId) {
                                        console.log('Auto-triggering rate fetch from DB...');
                                        if (typeof fetchRatePerKg === 'function') {
                                            try {
                                                fetchRatePerKg();
                                            } catch (e) {
                                                console.error('Auto rate fetch failed:', e);
                                            }
                                        }
                                    } else if (initialMass > 0 && initialRate <= 0 && !directionId) {
                                        // Silently skip rate fetch if direction_id is missing - this is normal behavior
                                        console.log('Rate fetch skipped: direction_id not available yet');
                                    }
                                }

                                // Trigger immediately if conditions are met
                                autoTriggerRateFetch();

                                // Also trigger after a delay to ensure all elements are loaded
                                setTimeout(autoTriggerRateFetch, 1000);

                                // ✅ BULLETPROOF: Additional trigger for edit mode scenarios
                                // Check if we're in edit mode (waybill data exists but rate is missing)
                                <?php if (isset($waybill) && ! empty($waybill) && (! isset($waybill['miscellaneous']['others']['mass_rate']) || empty($waybill['miscellaneous']['others']['mass_rate']))): ?>
                                    setTimeout(function() {
                                        console.log('Edit mode detected - triggering rate fetch...');
                                        autoTriggerRateFetch();
                                    }, 2000);
                                <?php endif; ?>

                                // ✅ BULLETPROOF: Fallback - try to find direction_id from form or simulate click
                                setTimeout(function() {
                                    const massValue = parseNumber(totalMassInput && totalMassInput.value);
                                    const rateValue = parseNumber(massRateInput && massRateInput.value);

                                    if (massValue > 0 && rateValue <= 0) {
                                        // Try to find direction_id from form inputs
                                        const formDirectionId = document.querySelector('input[name="direction_id"]');
                                        if (formDirectionId && formDirectionId.value) {
                                            console.log('Found direction_id in form, updating hidden input...');
                                            const directionIdInput = document.getElementById('direction_id');
                                            if (directionIdInput) {
                                                directionIdInput.value = formDirectionId.value;
                                                console.log('Updated direction_id, retrying rate fetch...');
                                                autoTriggerRateFetch();
                                                return;
                                            }
                                        }

                                        console.log('Fallback: Simulating click on mass input...');
                                        if (totalMassInput) {
                                            // Trigger both click and focus events
                                            totalMassInput.click();
                                            totalMassInput.focus();
                                            totalMassInput.blur();
                                        }
                                    }
                                }, 3000);
                            });
                        });
                        </script>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php if ((int) $weightDisplayOption == 2): ?>
    <div class="md:grid md:grid-cols-2 gap-4 justify-center align-middle">
        <div class="items-center">
            <?php echo KIT_Commons::Lnumber([
                'label'   => "Total Mass (Kg)",
                'name'    => "total_mass_kg",
                'id'      => "total_mass_kg",
                'value'   => $total_mass_kg,
                'class'   => '',
                'special' => '',
                'onclick' => '',
            ]); ?>
        </div>
    </div>
<?php endif; ?>