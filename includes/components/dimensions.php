<?php
if (!defined('ABSPATH')) {
    exit;
}

// Include user roles for permission checking
$kit_components_dir = function_exists('plugin_dir_path') ? plugin_dir_path(__FILE__) : (rtrim(__DIR__, '/\\') . '/');
require_once $kit_components_dir . '../user-roles.php';

// Use passed waybill data or fall back to global
$waybill = isset($dimensions_waybill) ? $dimensions_waybill : (isset($GLOBALS['waybill']) ? $GLOBALS['waybill'] : null);

// Enqueue required scripts for this component
KIT_Commons::enqueueComponentScripts(['kitscript']);

// Provide origin_country_id for AJAX rate fetching.
// The volume rate endpoint expects origin_country_id; weight.php provides it, but this component
// can be rendered without weight.php, so we ensure it exists here too.
$origin_country_id_value = 1; // Default to South Africa
if (isset($waybill) && is_array($waybill) && !empty($waybill['origin_country_id'])) {
    $origin_country_id_value = $waybill['origin_country_id'];
} elseif (isset($origin_country_id) && !empty($origin_country_id)) {
    $origin_country_id_value = $origin_country_id;
}

$dimensions = [
    ['field' => 'item_length', 'label' => 'Length (cm)'],
    ['field' => 'item_width', 'label' => 'Width (cm)'],
    ['field' => 'item_height', 'label' => 'Height (cm)'],
];

// Support both array and object $waybill when prefilling
$prefill_volume_charge = '0.00';
if (isset($waybill)) {
    $raw_volume_charge = 0.0;
    if (is_array($waybill) && isset($waybill['volume_charge'])) {
        $raw_volume_charge = floatval($waybill['volume_charge']);
    } elseif (is_object($waybill) && isset($waybill->volume_charge)) {
        $raw_volume_charge = floatval($waybill->volume_charge);
    }
    $prefill_volume_charge = number_format($raw_volume_charge, 2, '.', '');
}
$prefill_total_volume = '';
if (isset($waybill)) {
    $raw_total_volume = 0.0;
    if (is_array($waybill) && isset($waybill['total_volume'])) {
        $raw_total_volume = floatval($waybill['total_volume']);
    } elseif (is_object($waybill) && isset($waybill->total_volume)) {
        $raw_total_volume = floatval($waybill->total_volume);
    }
    if ($raw_total_volume > 0) {
        $prefill_total_volume = rtrim(rtrim(number_format($raw_total_volume, 6, '.', ''), '0'), '.');
    }
}

// Prefill display rate (R per m³). Prefer stored snapshot, otherwise derive from saved charge/volume.
$prefill_volume_rate_display = '0.00';
$prefill_volume_rate_used = null;
$misc_data = null;
if (isset($waybill)) {
    if (is_array($waybill) && isset($waybill['miscellaneous']) && !empty($waybill['miscellaneous'])) {
        $misc_data = maybe_unserialize($waybill['miscellaneous']);
    } elseif (is_object($waybill) && isset($waybill->miscellaneous) && !empty($waybill->miscellaneous)) {
        $misc_data = maybe_unserialize($waybill->miscellaneous);
    }
}
if (is_array($misc_data) && isset($misc_data['others']) && isset($misc_data['others']['volume_rate_used'])) {
    $prefill_volume_rate_used = floatval($misc_data['others']['volume_rate_used']);
    if ($prefill_volume_rate_used > 0) {
        $prefill_volume_rate_display = number_format($prefill_volume_rate_used, 2, '.', '');
    }
} elseif (isset($waybill)) {
    $volume_charge = 0;
    $total_volume = 0;
    if (is_array($waybill)) {
        $volume_charge = floatval($waybill['volume_charge'] ?? 0);
        $total_volume = floatval($waybill['total_volume'] ?? 0);
    } elseif (is_object($waybill)) {
        $volume_charge = floatval($waybill->volume_charge ?? 0);
        $total_volume = floatval($waybill->total_volume ?? 0);
    }
    if ($total_volume > 0 && $volume_charge > 0) {
        $prefill_volume_rate_display = number_format($volume_charge / $total_volume, 2, '.', '');
    }
}

// Prefill from stored snapshot if available
$prefill_use_custom = false;
$prefill_custom_rate = '';
if (is_array($misc_data) && isset($misc_data['others'])) {
    $prefill_use_custom = !empty($misc_data['others']['use_custom_volume_rate']);
    if (isset($misc_data['others']['custom_volume_rate_per_m3'])) {
        $prefill_custom_rate = $misc_data['others']['custom_volume_rate_per_m3'];
    }
}

$dimensions_js_prefill = [
    'baseRate' => ($prefill_volume_rate_used !== null && $prefill_volume_rate_used > 0) ? $prefill_volume_rate_used : null,
    'useCustomVolumeRate' => (bool) $prefill_use_custom,
];

$dimensions_js_prefill_json = function_exists('wp_json_encode')
    ? wp_json_encode($dimensions_js_prefill)
    : json_encode($dimensions_js_prefill);
?>
<input type="hidden" name="origin_country_id" value="<?= esc_attr($origin_country_id_value) ?>" id="countrydestination_id" />
<div class="">
    <?php echo KIT_Commons::prettyHeading([
        'words' => 'Volume',
        'classes' => 'mb-6'
    ]); ?>
    <div class="grid grid-cols-3 gap-4 mb-4">
        <?php foreach ($dimensions as $dim):
            $field = $dim['field'];
            $label = $dim['label'];
        ?>
            <div>
                <?= KIT_Commons::Lnumber([
                    'label' => $label,
                    'name'  => esc_attr($field),
                    'id'    => esc_attr($field),
                    'value' => esc_attr($waybill[$field] ?? null),
                    'preset' => 'default_input',
                    'class' => 'dimension-input w-[50px]',
                    'special' => '',
                ]); ?>
            </div>
        <?php endforeach; ?>

    </div>
    <?php if (KIT_User_Roles::can_see_prices()): ?>
        <div class="flex flex-row gap-4 justify-start items-start">
            <div>
                <?= KIT_Commons::Lnumber([
                    'label' => "Total Volume (m³)",
                    'name'  => 'total_volume',
                    'id'  => 'total_volume',
                    'value' => esc_attr($prefill_total_volume !== '' ? $prefill_total_volume : ($waybill['total_volume'] ?? null)),
                    'preset' => 'readonly_field',
                    'class' => 'bg-green-50',
                    'special' => 'readonly',
                ]); ?>
                <!-- Auto-calculated Volume -->
            </div>
            <div class="mt-6 h-10 flex items-center justify-center px-1 text-gray-600 font-semibold select-none" aria-hidden="true">×</div>
            <div>
            <?= KIT_Commons::Lnumber([
                'label' => 'Volume Rate (R per m³)',
                'name'  => 'volume_rate_per_m3',
                'id'    => 'volume_rate_per_m3',
                'value' => esc_attr($prefill_volume_rate_display),
                'preset' => 'readonly_field',
                'special' => 'readonly',
            ]); ?>
        </div>
            <div class="mt-6 h-10 flex items-center justify-center px-1 text-gray-600 font-semibold select-none" aria-hidden="true">=</div> 
            <div>
                <?= KIT_Commons::Linput([
                    'label' => 'Total Volume Charge (R)',
                    'name'  => 'volume_charge',
                    'id'  => 'volume_charge',
                    'type'  => 'text',
                    'value' => esc_attr($prefill_volume_charge),
                    'preset' => 'readonly_field',
                    'special' => 'readonly',
                ]); ?>
            </div>
        </div>
        <div id="volume-calculation-messages" class="min-h-[0px]"></div>
            <?php echo KIT_Commons::displayHere([
                'id' => 'volume-display-here',
                'variant' => 'neutral',
                'classes' => 'min-h-[0px]',
                'hidden' => true,
            ]); ?>

        <!-- Dimension Manipulator (Admin only) -->
        <div class="mt-4">
            <label class="inline-flex items-center">
                <?php
                echo KIT_Commons::Lcheckbox([
                    'no_label' => true,
                    'label' => '',
                    'id' => 'enable_volume_price_manipulator',
                    'name' => 'use_custom_volume_rate',
                    'value' => '1',
                    'checked' => (bool) $prefill_use_custom,
                    'class' => 'form-checkbox h-4 w-4 text-blue-600',
                ]);
                ?>
                <span class="ml-2 text-sm text-gray-700">Custom Volume Rate</span>
            </label>
            <div id="dimension_manipulator_input_container" style="display: <?= $prefill_use_custom ? 'block' : 'none'; ?>; margin-top: 1rem;">
                <?= KIT_Commons::Lnumber([
                    'label' => 'Volume Rate Manipulator (R)',
                    'name'  => 'custom_volume_rate_per_m3',
                    'id'    => 'custom_volume_rate_per_m3',
                    'min'   => '0',
                    'step'  => '0.01',
                    'value' => esc_attr($prefill_custom_rate),
                    'preset' => 'readonly_field',
                ]); ?>
            </div>
        </div>
        <div id="ttt" class="text-sm text-gray-700 col-span-2">
            = R<span id="volume_charge_display"><?= htmlspecialchars($prefill_volume_rate_display); ?></span> per m3
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const CUBIC_CM_TO_CUBIC_M = 1000000;
        const DEBOUNCE_DELAY_MS = 300;

        // Cache DOM elements
        const elements = {
            inputs: document.querySelectorAll('.dimension-input'),
            lengthInput: document.querySelector('input[name="item_length"]'),
            widthInput: document.querySelector('input[name="item_width"]'),
            heightInput: document.querySelector('input[name="item_height"]'),
            volumeField: document.getElementById('total_volume'),
            volumeCharge: document.getElementById('volume_charge'),
            volumeRate: document.getElementById('volume_rate_per_m3'),
            volumeChargeDisplay: document.getElementById('volume_charge_display'),
            countrySelect: document.getElementById('countrydestination_id'),
            // Manipulator controls (unique IDs/names for volume)
            manipCheckbox: document.getElementById('enable_volume_price_manipulator'),
            manipInput: document.getElementById('custom_volume_rate_per_m3'),
            manipInputContainer: document.getElementById('dimension_manipulator_input_container')
        };

        // Unified status strip (global .display-here component)
        const displayHere = document.getElementById('volume-display-here');
        const displayHereBaseClass = displayHere ? displayHere.className : '';

        function notifyHeaderTotal() {
            if (elements.volumeCharge) {
                try {
                    elements.volumeCharge.dispatchEvent(new Event('input', { bubbles: true }));
                    elements.volumeCharge.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (e) { /* non-fatal */ }
            }
            if (typeof window.kitUpdateWaybillHeaderTotal === 'function') {
                window.kitUpdateWaybillHeaderTotal();
            } else {
                document.dispatchEvent(new CustomEvent('kit:waybill-total-dirty'));
            }
        }

        function setDisplayHere(message, variant = 'neutral', autoHideMs = 0) {
            if (!displayHere) return;
            const skins = {
                neutral: 'text-gray-700 bg-gray-50 border-gray-200',
                error: 'text-red-700 bg-red-50 border-red-200',
                success: 'text-green-700 bg-green-50 border-green-200',
                warning: 'text-amber-800 bg-amber-50 border-amber-200',
                info: 'text-blue-800 bg-blue-50 border-blue-200',
            };
            const key = skins[variant] ? variant : 'neutral';

            // Preserve any custom classes passed from PHP (e.g. min-h-[0px])
            // while still forcing a consistent base + skin.
            const base = 'display-here text-sm mt-2 p-2 border rounded';
            const extra = (displayHereBaseClass || '').replace(/\b(text-[^\s]+|bg-[^\s]+|border-[^\s]+)\b/g, '').trim();
            displayHere.className = `${base} ${skins[key]} ${extra}`.trim();

            // Cancel any previous auto-hide so a persistent message is not cleared by an old timer.
            delete displayHere.dataset.hideAt;

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
                    if (displayHere.dataset.hideAt === String(hideAt)) {
                        displayHere.textContent = '';
                        displayHere.setAttribute('hidden', 'hidden');
                    }
                }, autoHideMs);
            }
        }

        function directionIdIsSet() {
            const el = document.getElementById('direction_id') || document.querySelector('input[name="direction_id"]');
            if (!el) return false;
            const v = String(el.value ?? '').trim();
            const n = parseInt(v, 10);
            return v !== '' && !Number.isNaN(n) && n > 0;
        }

        let ajaxAbortController = null;
        let debounceTimer = null;
        // Initialize with stored rate from database if available (for editing existing waybills)
        // But we'll always fetch from table when direction_id or volume changes
        const prefill = <?php echo $dimensions_js_prefill_json; ?>;
        let baseRate = prefill.baseRate;
        let lastBaseRate = prefill.baseRate;
        let lastDirectionId = null; // Track direction_id changes
        let lastRateLookupVolume = null; // Track volume used for last DB tier lookup
        // Preserve DB values on initial load, but once the user edits any dimension
        // we must recalculate volume + charge from the new inputs.
        const initialDims = {
            l: elements.lengthInput ? String(elements.lengthInput.value ?? '') : '',
            w: elements.widthInput ? String(elements.widthInput.value ?? '') : '',
            h: elements.heightInput ? String(elements.heightInput.value ?? '') : '',
        };
        let userEditedDimensions = false;

        function updateUserEditedFlag() {
            if (!elements.lengthInput || !elements.widthInput || !elements.heightInput) return;
            const l = String(elements.lengthInput.value ?? '');
            const w = String(elements.widthInput.value ?? '');
            const h = String(elements.heightInput.value ?? '');
            userEditedDimensions = (l !== initialDims.l) || (w !== initialDims.w) || (h !== initialDims.h);
        }

        function validateDimension(value) {
            // Handle both comma and dot decimal separators
            const normalizedValue = String(value).replace(',', '.');
            const num = parseFloat(normalizedValue);
            return !isNaN(num) && num > 0 ? num : null;
        }

        function calculateVolume(immediate = false) {
            const length = validateDimension(elements.lengthInput.value);
            const width = validateDimension(elements.widthInput.value);
            const height = validateDimension(elements.heightInput.value);

            // If any dimension is invalid/missing, do NOT overwrite existing
            // total_volume/volume_charge. This prevents charge-basis changes from
            // wiping a previously calculated volume.
            const allEmpty = (
                (elements.lengthInput.value === '' || elements.lengthInput.value === null) &&
                (elements.widthInput.value === '' || elements.widthInput.value === null) &&
                (elements.heightInput.value === '' || elements.heightInput.value === null)
            );
            if ([length, width, height].some(val => val === null)) {
                // Do not clear here. This function is also triggered by charge-basis
                // changes; if all fields are empty, we want to preserve any existing
                // prefilled volume/charge values. Actual clearing is handled in the
                // dimension input handler when the user empties the fields.
                return;
            }

            const volume = (length * width * height) / CUBIC_CM_TO_CUBIC_M;
            
            // CRITICAL: Don't overwrite saved volume if we have a saved volume_charge
            // This preserves the database values when editing existing waybills
            const hasSavedVolumeCharge = elements.volumeCharge && parseFloat(elements.volumeCharge.value.replace(',', '.')) > 0;
            if (elements.volumeField && (!hasSavedVolumeCharge || userEditedDimensions)) {
                elements.volumeField.value = volume.toFixed(6);
            }

            // Check if direction_id has changed - if so, always fetch from table
            const directionField = document.getElementById('direction_id') || document.querySelector('input[name="direction_id"]');
            const currentDirectionId = directionField ? directionField.value : '';
            const directionChanged = lastDirectionId !== null && lastDirectionId !== currentDirectionId;
            const volumeChanged = lastRateLookupVolume === null || Math.abs(volume - lastRateLookupVolume) > 1e-9;
            
            // Always fetch from table if:
            // 1. No stored rate exists
            // 2. Direction_id changed (rates may differ by direction)
            // 3. Volume changed significantly (might be in different tier)
            const shouldFetchFromTable = baseRate === null || baseRate <= 0 || directionChanged || volumeChanged;
            
            if (shouldFetchFromTable) {
                // Always fetch from wp_kit_shipping_rates_volume table using direction_id
                if (immediate) {
                    fetchVolumeRate(volume);
                } else {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => fetchVolumeRate(volume), DEBOUNCE_DELAY_MS);
                }
                lastDirectionId = currentDirectionId;
                lastRateLookupVolume = volume;
            } else {
                // Use stored rate only if direction hasn't changed
                updateVolumeChargeUI(volume, baseRate);
            }
        }

        function clearForm() {
            if (elements.volumeField) elements.volumeField.value = '';
            if (elements.volumeCharge) elements.volumeCharge.value = '';
            notifyHeaderTotal();
            if (elements.volumeChargeDisplay) elements.volumeChargeDisplay.textContent = '';
            if (elements.volumeRate) elements.volumeRate.value = '';
            setDisplayHere('', 'neutral', 0);
        }

        function clearVolumeLoaders() {
            elements.inputs.forEach(input => ComponentUtils.hideSpinner(input));
            if (elements.volumeRate) {
                ComponentUtils.hideInputLoader(elements.volumeRate);
            }
            if (elements.volumeCharge) {
                ComponentUtils.hideInputLoader(elements.volumeCharge);
            }
            if (typeof window.kitChargesReadyToContinue === 'function') {
                window.kitChargesReadyToContinue();
            }
        }

        function fetchVolumeRate(volume) {
            // Get direction_id — required; server resolves tiers only for this direction.
            const directionField = document.getElementById('direction_id') || document.querySelector('input[name="direction_id"]');
            const directionId = directionField ? String(directionField.value ?? '').trim() : '';
            const directionNum = parseInt(directionId, 10);
            if (!directionId || Number.isNaN(directionNum) || directionNum <= 0) {
                setDisplayHere('Select a delivery/direction to calculate the volume rate.', 'error', 0);
                clearVolumeLoaders();
                return;
            }
            const safeDirectionId = directionNum;

            // Skip duplicate lookups (direction_id input events can fire repeatedly).
            if (
                lastDirectionId !== null &&
                String(lastDirectionId) === String(safeDirectionId) &&
                lastRateLookupVolume !== null &&
                Math.abs(volume - lastRateLookupVolume) <= 1e-9 &&
                baseRate !== null &&
                baseRate > 0
            ) {
                updateVolumeChargeUI(volume, baseRate, { skipPreserve: true });
                clearVolumeLoaders();
                return;
            }

            elements.inputs.forEach(input => ComponentUtils.showSpinner(input));
            if (elements.volumeRate) {
                ComponentUtils.showInputLoader(elements.volumeRate, 'Calculating rate…');
            }
            if (elements.volumeCharge) {
                ComponentUtils.showInputLoader(elements.volumeCharge, 'Calculating charge…');
            }
            if (typeof window.kitChargesReadyToContinue === 'function') {
                window.kitChargesReadyToContinue();
            }

            if (ajaxAbortController) {
                try { ajaxAbortController.abort(); } catch (e) {}
            }
            ajaxAbortController = new AbortController();
            const requestController = ajaxAbortController;
            lastRateLookupVolume = volume;

            const safetyTimer = setTimeout(function() {
                if (requestController === ajaxAbortController) {
                    clearVolumeLoaders();
                }
            }, 12000);

            ComponentUtils.ajaxCall('handle_get_price_per_m3', {
                'origin_country_id': elements.countrySelect?.value || '',
                'direction_id': String(safeDirectionId),
                'total_volume_m3': volume
            }, function(data) {
                clearTimeout(safetyTimer);
                if (requestController !== ajaxAbortController) {
                    return;
                }
                if (data && data.aborted) {
                    return;
                }

                try {
                    if (data && data.success) {
                        const rate = parseFloat(data.data.rate_per_m3);
                        baseRate = rate;
                        lastBaseRate = rate;
                        lastDirectionId = String(safeDirectionId);
                        updateVolumeChargeUI(volume, rate, { skipPreserve: true });
                        setDisplayHere('Volume rate updated.', 'success', 1500);
                    } else {
                        console.error('Server reported error:', data && data.data && data.data.message);
                        setDisplayHere((data && data.data && data.data.message) ? data.data.message : 'Unable to fetch volume rate. Please try again.', 'error', 8000);
                        if (baseRate !== null && baseRate > 0) {
                            updateVolumeChargeUI(volume, baseRate);
                        } else {
                            clearForm();
                        }
                    }
                } finally {
                    clearVolumeLoaders();
                }
            }, { signal: requestController.signal });
        }

        function updateVolumeChargeUI(volume, fetchedRate, options) {
            options = options || {};
            const skipPreserve = options.skipPreserve === true;
            // CRITICAL: Don't overwrite saved volume_charge if it exists
            // This preserves database values when editing existing waybills
            const savedVolumeCharge = elements.volumeCharge ? parseFloat(elements.volumeCharge.value.replace(',', '.')) || 0 : 0;
            const savedVolume = elements.volumeField ? parseFloat(elements.volumeField.value.replace(',', '.')) || 0 : 0;
            const customManipValue = (
                elements.manipCheckbox &&
                elements.manipCheckbox.checked &&
                elements.manipInput
            ) ? parseFloat(String(elements.manipInput.value).replace(',', '.')) : NaN;
            const hasCustomManipRate = !isNaN(customManipValue) && customManipValue > 0;

            if (skipPreserve && elements.volumeField && volume > 0) {
                elements.volumeField.value = volume.toFixed(6);
            }

            // Preserve DB snapshot unless the user is explicitly overriding rate via the manipulator
            // (otherwise the early return skips manipulator logic entirely).
            if (!skipPreserve && !userEditedDimensions && savedVolumeCharge > 0 && savedVolume > 0 && !hasCustomManipRate) {
                // Use saved volume from database, not calculated volume
                const savedRate = savedVolumeCharge / savedVolume;
                if (elements.volumeChargeDisplay) {
                    elements.volumeChargeDisplay.textContent = savedRate.toFixed(2);
                }
                if (elements.volumeRate) {
                    elements.volumeRate.value = savedRate.toFixed(2);
                }
                return; // Don't update the volume_charge field - preserve saved value
            }
            
            let rate = fetchedRate;
            // If custom rate is enabled, use it as the full rate (not an addition)
            if (elements.manipCheckbox && elements.manipCheckbox.checked && elements.manipInput) {
                // Handle both comma and dot decimal separators
                const normalizedManipValue = String(elements.manipInput.value).replace(',', '.');
                const customRate = parseFloat(normalizedManipValue);
                if (!isNaN(customRate) && customRate > 0) {
                    // Use custom rate as full replacement rate
                    rate = customRate;
                } else {
                    // If no custom rate value, fall back to base rate
                    rate = baseRate !== null ? baseRate : fetchedRate;
                }
            }
            if (elements.volumeChargeDisplay) {
                elements.volumeChargeDisplay.textContent = rate.toFixed(2);
            }
            if (elements.volumeRate) {
                elements.volumeRate.value = rate.toFixed(2);
            }
            if (elements.volumeCharge) {
                elements.volumeCharge.value = (rate * volume).toFixed(2);
            }
            notifyHeaderTotal();
        }

        // Manipulator logic
        if (elements.manipCheckbox && elements.manipInput && elements.manipInputContainer) {
            // Show/hide manipulator input
            elements.manipCheckbox.addEventListener('change', function() {
                elements.manipInputContainer.style.display = this.checked ? 'block' : 'none';
                if (!this.checked) {
                    elements.manipInput.value = '';
                    // When disabling custom rate, revert to DB-derived rate (AJAX).
                    // lastBaseRate can be stale (e.g., direction/tiers changed), so force a re-fetch.
                    const length = validateDimension(elements.lengthInput.value);
                    const width = validateDimension(elements.widthInput.value);
                    const height = validateDimension(elements.heightInput.value);
                    if ([length, width, height].some(val => val === null)) return;
                    const volume = (length * width * height) / CUBIC_CM_TO_CUBIC_M;
                    // Force fetch from table for current direction_id + volume tier
                    baseRate = null;
                    fetchVolumeRate(volume);
                } else {
                    // When enabling, recalc with manipulator
                    const length = validateDimension(elements.lengthInput.value);
                    const width = validateDimension(elements.widthInput.value);
                    const height = validateDimension(elements.heightInput.value);
                    if ([length, width, height].some(val => val === null) || baseRate === null) return;
                    const volume = (length * width * height) / CUBIC_CM_TO_CUBIC_M;
                    updateVolumeChargeUI(volume, baseRate);
                }
            });

            // Manipulator input changes
            elements.manipInput.addEventListener('input', function() {
                if (!elements.manipCheckbox.checked) return;

                // Normalize the manipulator input value (handle comma/dot separators)
                const normalizedManipValue = String(this.value).replace(',', '.');
                this.value = normalizedManipValue;
                // Allow negative values for discounts/credits
                const parsed = parseFloat(this.value);
                if (isNaN(parsed)) {
                    this.value = '0';
                }

                const length = validateDimension(elements.lengthInput.value);
                const width = validateDimension(elements.widthInput.value);
                const height = validateDimension(elements.heightInput.value);
                if ([length, width, height].some(val => val === null) || baseRate === null) return;
                const volume = (length * width * height) / CUBIC_CM_TO_CUBIC_M;
                updateVolumeChargeUI(volume, baseRate);
            });

            // If editing and value exists, show input and check the box
            if (prefill.useCustomVolumeRate) {
                elements.manipCheckbox.checked = true;
                elements.manipInputContainer.style.display = 'block';
            }
        }

        // Listen for direction_id changes - re-fetch rate from table when direction changes
        const directionField = document.getElementById('direction_id') || document.querySelector('input[name="direction_id"]');
        function onDirectionIdInput() {
            if (directionIdIsSet()) {
                setDisplayHere('', 'neutral', 0);
            }
            const length = validateDimension(elements.lengthInput.value);
            const width = validateDimension(elements.widthInput.value);
            const height = validateDimension(elements.heightInput.value);
            if (length && width && height && directionIdIsSet()) {
                const volume = (length * width * height) / CUBIC_CM_TO_CUBIC_M;
                baseRate = null;
                fetchVolumeRate(volume);
            }
        }
        if (directionField) {
            // change only — input events on hidden direction_id can spam recalculation loaders.
            directionField.addEventListener('change', onDirectionIdInput);
            lastDirectionId = directionField.value || null;
        }

        // Set up event listeners
        elements.inputs.forEach(input => {
            input.addEventListener('input', function() {
                // Normalize comma decimal separators to dots for consistent parsing
                const normalizedValue = String(this.value).replace(',', '.');
                if (normalizedValue !== this.value) {
                    this.value = normalizedValue;
                }
                updateUserEditedFlag();

                // If user cleared all fields, explicitly clear computed volume/charge
                const l = elements.lengthInput.value;
                const w = elements.widthInput.value;
                const h = elements.heightInput.value;
                if ((l === '' || l === null) && (w === '' || w === null) && (h === '' || h === null)) {
                    clearForm();
                    return;
                }

                calculateVolume(false);
            });
            input.addEventListener('blur', () => {
                updateUserEditedFlag();
                calculateVolume(true);
            });
        });

        // Re-evaluate volume when charge basis changes so choosing 'volume' doesn't
        // accidentally zero-out fields
        // Detach charge-basis from dimension recalculation to avoid unintended clears
        // (preferred select no longer affects calculation choice)

        // Initial calculation - only if dimensions are filled
        const hasDimensions = elements.lengthInput.value && elements.widthInput.value && elements.heightInput.value;
        
        // Check if we're editing an existing waybill with saved volume_charge
        // CRITICAL: Don't recalculate if we have a saved volume_charge from the database
        const volumeChargeField = elements.volumeCharge;
        const savedVolumeCharge = volumeChargeField ? parseFloat(volumeChargeField.value.replace(',', '.')) || 0 : 0;
        const volumeField = elements.volumeField;
        const savedVolume = volumeField ? parseFloat(volumeField.value.replace(',', '.')) || 0 : 0;
        
        // If we have saved volume_charge, preserve DB snapshot (even when total_volume was not stored).
        if (savedVolumeCharge > 0 && savedVolume > 0) {
            const savedRate = savedVolumeCharge / savedVolume;
            let volumeMismatchWithDims = false;
            if (hasDimensions) {
                const L = validateDimension(elements.lengthInput.value);
                const W = validateDimension(elements.widthInput.value);
                const H = validateDimension(elements.heightInput.value);
                if (L !== null && W !== null && H !== null && savedVolume > 0) {
                    const computedVol = (L * W * H) / CUBIC_CM_TO_CUBIC_M;
                    const ratio = computedVol / savedVolume;
                    if (computedVol > 0 && (ratio > 10 || ratio < 0.1)) {
                        volumeMismatchWithDims = true;
                    }
                }
            }

            // Saved total_volume can disagree with L×W×H; implied rate charge/volume is then meaningless.
            if (volumeMismatchWithDims) {
                userEditedDimensions = true;
                setDisplayHere(
                    'Saved total volume does not match dimensions; volume and rate were recalculated from dimensions.',
                    'warning',
                    8000
                );
                calculateVolume(true);
            } else {
                // Only set baseRate if it's not already set from miscellaneous data
                if (baseRate === null || baseRate <= 0) {
                    baseRate = savedRate;
                    lastBaseRate = savedRate;
                }

                if (elements.volumeChargeDisplay) {
                    elements.volumeChargeDisplay.textContent = savedRate.toFixed(2);
                }
                if (elements.volumeRate) {
                    elements.volumeRate.value = savedRate.toFixed(2);
                }

                console.log('Preserving saved volume_charge:', savedVolumeCharge, 'with volume:', savedVolume, 'rate:', savedRate);
            }
        } else if (savedVolumeCharge > 0 && hasDimensions) {
            const L = validateDimension(elements.lengthInput.value);
            const W = validateDimension(elements.widthInput.value);
            const H = validateDimension(elements.heightInput.value);
            if (L !== null && W !== null && H !== null) {
                const computedVol = (L * W * H) / CUBIC_CM_TO_CUBIC_M;
                const savedRate = savedVolumeCharge / computedVol;
                if (elements.volumeField) {
                    elements.volumeField.value = computedVol.toFixed(6);
                }
                if (baseRate === null || baseRate <= 0) {
                    baseRate = savedRate;
                    lastBaseRate = savedRate;
                }
                if (elements.volumeChargeDisplay) {
                    elements.volumeChargeDisplay.textContent = savedRate.toFixed(2);
                }
                if (elements.volumeRate) {
                    elements.volumeRate.value = savedRate.toFixed(2);
                }
                lastRateLookupVolume = computedVol;
                console.log('Preserving saved volume_charge with computed volume:', savedVolumeCharge, computedVol, savedRate);
            }
        } else if (hasDimensions) {
            // Only recalculate if dimensions are filled and we don't have saved values
            calculateVolume(true);
        } else if (baseRate !== null && baseRate > 0 && elements.volumeField && elements.volumeField.value) {
            // If we have a stored rate and volume, calculate charge without fetching
            const volume = parseFloat(elements.volumeField.value.replace(',', '.')) || 0;
            if (volume > 0) {
                updateVolumeChargeUI(volume, baseRate);
            }
        }

        // Prefilled Custom Volume Rate: saved-charge branch above restores DB rate in the UI; re-apply manipulator once.
        if (elements.manipCheckbox && elements.manipCheckbox.checked && elements.manipInput && baseRate !== null) {
            const initCustomRate = parseFloat(String(elements.manipInput.value).replace(',', '.'));
            if (!isNaN(initCustomRate) && initCustomRate > 0) {
                const L = validateDimension(elements.lengthInput && elements.lengthInput.value);
                const W = validateDimension(elements.widthInput && elements.widthInput.value);
                const H = validateDimension(elements.heightInput && elements.heightInput.value);
                let volUse = savedVolume > 0 ? savedVolume : 0;
                if (L !== null && W !== null && H !== null) {
                    volUse = (L * W * H) / CUBIC_CM_TO_CUBIC_M;
                }
                if (volUse > 0) {
                    updateVolumeChargeUI(volUse, baseRate);
                }
            }
        }
    });
</script>