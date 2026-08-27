<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="bg-white p-6">
    <?= KIT_Commons::prettyHeading([
        'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
        'words' => 'Charges & Fees'
    ]) ?>
    <p class="text-xs text-gray-600 mb-6">
        Enter mass and volume charges. Wait for rates to finish before continuing.
    </p>

    <!-- Waybill section -->
    <div id="waybill-sections-container" class="space-y-6">
        <?php 
        $waybill_index = 0;
        require(COURIER_FINANCE_PLUGIN_PATH . 'includes/components/waybillSection.php'); 
        ?>
    </div>

    <div id="kit-charge-rate-status" class="mt-4 hidden rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status" aria-live="polite"></div>

    <!-- Optional miscellaneous items (was its own forced step) -->
    <details id="kit-misc-accordion" class="mt-8 rounded-lg border border-gray-200 bg-gray-50 open:bg-white">
        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-gray-800 flex items-center justify-between">
            <span>Miscellaneous items <span class="font-normal text-gray-500">(optional)</span></span>
            <span class="text-xs font-normal text-gray-500">Add only if needed</span>
        </summary>
        <div class="border-t border-gray-200 px-2 pb-4" id="waybill-misc-sections-container">
            <?php
            $waybill_index = 0;
            require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/waybillMiscSection.php';
            ?>
        </div>
    </details>

    <!-- Navigation Buttons -->
    <div class="flex justify-between mt-8">
        <?php echo KIT_Commons::renderButton('Back', 'secondary', 'lg', [
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" />',
            'iconPosition' => 'left',
            'data-target' => 'step-2',
            'classes' => 'prev-step'
        ]); ?>
        <?php echo KIT_Commons::renderButton('Next: Parcels & Review', 'primary', 'lg', [
            'id' => 'kit-charges-next-btn',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />',
            'iconPosition' => 'right',
            'data-target' => 'step-5',
            'classes' => 'next-step',
            'gradient' => true
        ]); ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Ensure global waybillCount exists but do NOT reset an existing value
    if (typeof window.waybillCount === 'undefined') {
        window.waybillCount = 1;
    }

    function parseMoney(value) {
        if (value === null || value === undefined || value === '') return 0;
        var n = parseFloat(String(value).replace(/,/g, '.').replace(/[^\d.-]/g, ''));
        return Number.isFinite(n) ? n : 0;
    }

    function formatRand(amount) {
        return 'R ' + (Number.isFinite(amount) ? amount : 0).toFixed(2);
    }

    function selectedChargeBasis() {
        var checked = document.querySelector('input[name="charge_basis"]:checked');
        return checked ? String(checked.value || 'auto').toLowerCase() : 'auto';
    }

    function billedFreightFromCharges() {
        var mass = parseMoney(document.getElementById('mass_charge') && document.getElementById('mass_charge').value);
        var volume = parseMoney(document.getElementById('volume_charge') && document.getElementById('volume_charge').value);
        var basis = selectedChargeBasis();
        if (basis === 'mass' || basis === 'weight') return mass;
        if (basis === 'volume') return volume;
        return Math.max(mass, volume);
    }

    function updateBilledFreightDisplay() {
        var display = document.getElementById('kit-billed-freight-display');
        var hint = document.getElementById('kit-billed-freight-hint');
        if (!display) return;
        var mass = parseMoney(document.getElementById('mass_charge') && document.getElementById('mass_charge').value);
        var volume = parseMoney(document.getElementById('volume_charge') && document.getElementById('volume_charge').value);
        var billed = billedFreightFromCharges();
        var basis = selectedChargeBasis();
        display.textContent = formatRand(billed);
        if (hint) {
            if (mass <= 0 && volume <= 0) {
                hint.textContent = 'Enter mass or volume to calculate.';
            } else if (basis === 'auto') {
                hint.textContent = 'Auto: higher of mass (' + formatRand(mass) + ') and volume (' + formatRand(volume) + ').';
            } else if (basis === 'mass' || basis === 'weight') {
                hint.textContent = 'Billing mass charge.';
            } else {
                hint.textContent = 'Billing volume charge.';
            }
        }
    }

    function isRateLoading() {
        if (typeof window.kitClearOrphanRateLoaders === 'function') {
            window.kitClearOrphanRateLoaders();
        }
        var massInput = document.getElementById('total_mass_kg');
        if (massInput && massInput.classList.contains('loading')) return true;
        // Volume field id is volume_rate_per_m3 (not volume_rate).
        if (document.querySelector('#mass_rate.kit-input-loading, #mass_charge.kit-input-loading, #volume_rate_per_m3.kit-input-loading, #volume_charge.kit-input-loading, .kit-input-loading')) {
            return true;
        }
        return false;
    }

    function chargesReadyToContinue() {
        var status = document.getElementById('kit-charge-rate-status');
        var nextBtn = document.getElementById('kit-charges-next-btn');
        var massKg = parseMoney(document.getElementById('total_mass_kg') && document.getElementById('total_mass_kg').value);
        var volume = parseMoney(document.getElementById('total_volume') && document.getElementById('total_volume').value);
        var massCharge = parseMoney(document.getElementById('mass_charge') && document.getElementById('mass_charge').value);
        var volumeCharge = parseMoney(document.getElementById('volume_charge') && document.getElementById('volume_charge').value);
        var overrideOn = !!(document.getElementById('enable_total_override') && document.getElementById('enable_total_override').checked);
        var overrideTotal = parseMoney(document.getElementById('override_total') && document.getElementById('override_total').value);
        // If charges already resolved, never block on a stuck spinner overlay.
        if ((massKg <= 0 || massCharge > 0) && (volume <= 0 || volumeCharge > 0)) {
            if (typeof window.kitClearOrphanRateLoaders === 'function') {
                window.kitClearOrphanRateLoaders();
            }
        }
        var loading = isRateLoading();
        var message = '';
        var ok = true;

        if (loading && !((massKg <= 0 || massCharge > 0) && (volume <= 0 || volumeCharge > 0))) {
            ok = false;
            message = 'Rates are still calculating. Please wait…';
        } else if (overrideOn) {
            if (overrideTotal <= 0) {
                ok = false;
                message = 'Override is enabled — enter an override total, or turn override off.';
            }
        } else if (massKg > 0 && massCharge <= 0) {
            ok = false;
            message = 'Mass was entered but the rate has not resolved. Wait for the rate, or use Override Total.';
        } else if (volume > 0 && volumeCharge <= 0 && massKg <= 0) {
            ok = false;
            message = 'Volume was entered but the volume charge is still R0.00. Wait for the rate, or use Override Total.';
        }

        if (status) {
            if (!ok && message) {
                status.textContent = message;
                status.classList.remove('hidden');
            } else {
                status.textContent = '';
                status.classList.add('hidden');
            }
        }

        if (nextBtn) {
            nextBtn.disabled = !ok;
            nextBtn.classList.toggle('opacity-50', !ok);
            nextBtn.classList.toggle('cursor-not-allowed', !ok);
            nextBtn.classList.toggle('pointer-events-none', !ok);
        }

        updateBilledFreightDisplay();
        return ok;
    }

    window.kitChargesReadyToContinue = chargesReadyToContinue;
    window.kitUpdateBilledFreightDisplay = updateBilledFreightDisplay;

    ['total_mass_kg', 'mass_rate', 'mass_charge', 'total_volume', 'volume_charge', 'volume_rate_per_m3', 'override_total', 'enable_total_override'].forEach(function(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', chargesReadyToContinue);
        el.addEventListener('change', chargesReadyToContinue);
    });
    document.querySelectorAll('input[name="charge_basis"]').forEach(function(el) {
        el.addEventListener('change', chargesReadyToContinue);
    });

    // Re-check while loaders may be active
    setInterval(function() {
        var step = document.getElementById('step-3');
        if (step && !step.classList.contains('hidden')) {
            chargesReadyToContinue();
        }
    }, 400);

    chargesReadyToContinue();

    /**
     * "Different city" handling for parcel waybills.
     * NEVER touch global #direction_id / #selected_delivery_id from Step 2.
     */
    try {
        const mainDestinationCountry =
            document.getElementById('stepDestinationSelect')?.value ||
            document.getElementById('destination_country_backup')?.value ||
            '';

        const mainDestinationCitySelect = document.getElementById('destination_city');

        function populateWaybillCitySelect(selectEl, countryId, selectedCityId) {
            if (!selectEl) {
                return;
            }

            selectEl.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select City';
            selectEl.appendChild(placeholder);

            const citiesMap = (window.myPluginAjax && window.myPluginAjax.countryCities) || {};
            const cities = citiesMap && citiesMap[String(countryId)] ? citiesMap[String(countryId)] : [];

            if (Array.isArray(cities) && cities.length) {
                cities.forEach(function(city) {
                    const opt = document.createElement('option');
                    opt.value = city.id;
                    opt.textContent = city.city_name;
                    if (selectedCityId && String(selectedCityId) === String(city.id)) {
                        opt.selected = true;
                    }
                    selectEl.appendChild(opt);
                });
                return;
            }

            if (mainDestinationCitySelect && mainDestinationCitySelect.options.length > 0) {
                Array.prototype.forEach.call(mainDestinationCitySelect.options, function(optSrc) {
                    const opt = document.createElement('option');
                    opt.value = optSrc.value;
                    opt.textContent = optSrc.textContent;
                    if (selectedCityId && String(selectedCityId) === String(optSrc.value)) {
                        opt.selected = true;
                    }
                    selectEl.appendChild(opt);
                });
            }
        }

        document.querySelectorAll('.waybill-section').forEach(function(sectionEl) {
            const index = sectionEl.getAttribute('data-waybill-index');
            if (index === null || typeof index === 'undefined') {
                return;
            }

            const differentCityCheckbox = document.getElementById('different_city_' + index);
            const dropdownWrapper = document.getElementById('different_city_dropdown_' + index);
            const citySelect = document.getElementById('waybill_destination_city_' + index);
            const hiddenCountry = document.getElementById('waybill_destination_country_' + index);
            const hiddenDeliveryId = document.getElementById('waybill_delivery_id_' + index);
            const hiddenDirectionId = document.getElementById('waybill_direction_id_' + index);

            if (!differentCityCheckbox || !dropdownWrapper || !citySelect || !hiddenCountry) {
                return;
            }

            const globalDeliveryId = document.getElementById('selected_delivery_id')?.value || '';
            const globalDirectionId = document.getElementById('direction_id')?.value || '';

            if (hiddenCountry && !hiddenCountry.value && mainDestinationCountry) {
                hiddenCountry.value = mainDestinationCountry;
            }
            if (hiddenDeliveryId && !hiddenDeliveryId.value && globalDeliveryId) {
                hiddenDeliveryId.value = globalDeliveryId;
            }
            if (hiddenDirectionId && !hiddenDirectionId.value && globalDirectionId) {
                hiddenDirectionId.value = globalDirectionId;
            }

            function updateDifferentCityState() {
                const checked = !!differentCityCheckbox.checked;
                if (checked) {
                    dropdownWrapper.classList.remove('hidden');
                    dropdownWrapper.style.display = '';

                    if (hiddenCountry && !hiddenCountry.value && mainDestinationCountry) {
                        hiddenCountry.value = mainDestinationCountry;
                    }

                    populateWaybillCitySelect(
                        citySelect,
                        hiddenCountry ? hiddenCountry.value : mainDestinationCountry,
                        citySelect.value || ''
                    );
                } else {
                    dropdownWrapper.classList.add('hidden');
                    dropdownWrapper.style.display = 'none';
                    if (citySelect) {
                        citySelect.value = '';
                    }
                }
            }

            differentCityCheckbox.addEventListener('change', updateDifferentCityState);

            citySelect.addEventListener('change', function() {
                const selectedValue = citySelect.value || '';
                if (selectedValue && hiddenCountry && !hiddenCountry.value && mainDestinationCountry) {
                    hiddenCountry.value = mainDestinationCountry;
                }
            });

            updateDifferentCityState();
        });
    } catch (e) {
        if (window.console && console.error) {
            console.error('Waybill charges different-city initialisation error:', e);
        }
    }
});
</script>
