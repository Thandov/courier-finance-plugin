//file location: js/kitscript.js
function toggleDropdownDeliveryStatus(delivery_id) {
    var dropdown = document.getElementById('delivery-status-dropdown-' + delivery_id);
    dropdown.classList.toggle('hidden');
}

function hello() {
    alert("Hello");
}

(function initializeCustomersData() {
    if (typeof window === 'undefined') {
        return;
    }

    const existingData = (window.CUSTOMERS_DATA && typeof window.CUSTOMERS_DATA === 'object') ? window.CUSTOMERS_DATA : {};
    const localizedCustomers = (typeof EditWaybillData !== 'undefined' && Array.isArray(EditWaybillData.customers)) ? EditWaybillData.customers : [];
    const customersFromLocalized = {};

    if (Array.isArray(localizedCustomers)) {
        localizedCustomers.forEach(customer => {
            if (customer && typeof customer === 'object' && customer.cust_id) {
                customersFromLocalized[customer.cust_id] = customer;
            }
        });
    }

    window.CUSTOMERS_DATA = Object.assign({}, existingData, customersFromLocalized);
})();

/** First non-empty direction_id on the waybill form (ignores duplicate empty hidden fields). */
window.kitResolveDirectionId = function kitResolveDirectionId() {
    var form = document.getElementById('multi-step-waybill-form');
    if (form) {
        var fields = form.querySelectorAll('input[name="direction_id"]');
        for (var i = 0; i < fields.length; i++) {
            var v = String(fields[i].value || '').trim();
            var n = parseInt(v, 10);
            if (v !== '' && !Number.isNaN(n) && n > 0) {
                return String(n);
            }
        }
    }
    var fallback = jQuery('#direction_id').val();
    if (fallback && String(fallback).trim() !== '') {
        var n2 = parseInt(String(fallback).trim(), 10);
        if (!Number.isNaN(n2) && n2 > 0) {
            return String(n2);
        }
    }
    return '';
};

/**
 * Prefill Create Waybill from a customer's last waybill template.
 * Skips mass and parcel line items.
 */
window.kitApplyLastWaybillTemplate = function kitApplyLastWaybillTemplate(template) {
    if (!template || typeof template !== 'object') {
        return;
    }

    function setVal(id, value) {
        var el = document.getElementById(id);
        if (!el || value === undefined || value === null) return;
        el.value = String(value);
        try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
        try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
    }

    function setChargeFlag(inputId, buttonId, on) {
        var input = document.getElementById(inputId);
        var btn = document.getElementById(buttonId);
        var want = !!on;
        if (input) {
            var current = String(input.value || '0') === '1';
            if (current !== want && btn) {
                try { btn.click(); } catch (e) {}
            } else {
                input.value = want ? '1' : '0';
            }
        } else if (btn && btn.type === 'checkbox') {
            if (btn.checked !== want) {
                btn.checked = want;
                try { btn.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
            }
        }
    }

    function showNotice(message, isError) {
        var host = document.getElementById('kit-last-waybill-prefill-notice');
        if (!host) {
            var badge = document.getElementById('selected-customer-badge');
            host = document.createElement('div');
            host.id = 'kit-last-waybill-prefill-notice';
            if (badge && badge.parentNode) {
                badge.parentNode.insertBefore(host, badge.nextSibling);
            } else {
                var form = document.getElementById('multi-step-waybill-form');
                if (form) form.insertBefore(host, form.firstChild);
            }
        }
        host.className = isError
            ? 'mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900'
            : 'mb-4 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700';
        host.textContent = message || '';
        host.style.display = message ? 'block' : 'none';
    }

    // Description
    setVal('waybill_description', template.description || '');

    // Document flags (SADC / SAD500 / VAT)
    setChargeFlag('include_sadc_input', 'sadc_certificate', template.include_sadc);
    setChargeFlag('include_sad500_input', 'include_sad500', template.include_sad500);
    setChargeFlag('vat_include_input', 'vat_include', template.vat_include);

    // Origin — prefer last waybill snapshot over customer defaults.
    if (template.origin_country_id) {
        var originCountry = document.getElementById('origin_country_select') || document.getElementById('origin_country');
        if (originCountry) {
            originCountry.value = String(template.origin_country_id);
            try { originCountry.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
        }
        if (typeof window.kitLoadCitiesForCountry === 'function') {
            window.kitLoadCitiesForCountry(template.origin_country_id, 'origin', template.origin_city_id || null);
        } else {
            setTimeout(function () {
                if (template.origin_city_id) {
                    setVal('origin_city_select', template.origin_city_id);
                }
            }, 350);
        }
    }

    // Mode: warehouse vs truck
    var warehouseBtn = document.getElementById('kit-step4-mode-warehouse');
    var truckBtn = document.getElementById('kit-step4-mode-truck');
    if (template.warehouse) {
        if (warehouseBtn) {
            try { warehouseBtn.click(); } catch (e) {}
        } else {
            var pending = document.getElementById('pending_option');
            if (pending) {
                pending.checked = true;
                try { pending.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
            }
        }
    } else if (truckBtn) {
        try { truckBtn.click(); } catch (e) {}
    }

    // Destination country/city + direction
    function applyDestination() {
        var destCountry = document.getElementById('stepDestinationSelect') || document.getElementById('destination_country');
        if (destCountry && template.destination_country_id) {
            destCountry.value = String(template.destination_country_id);
            // Prefer explicit city loader with target city id — avoid racing a bare change handler.
            if (typeof window.loadDestinationCities === 'function') {
                window.loadDestinationCities(template.destination_country_id, template.destination_city_id || null);
            } else {
                try { destCountry.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
                setTimeout(function () {
                    setVal('destination_city', template.destination_city_id || '');
                }, 400);
            }
            // Re-assert city after any async city-list render.
            if (template.destination_city_id) {
                setTimeout(function () {
                    setVal('destination_city', template.destination_city_id);
                }, 500);
            }
        } else if (template.destination_city_id) {
            setVal('destination_city', template.destination_city_id);
        }

        if (template.direction_id) {
            var dirFields = document.querySelectorAll('#multi-step-waybill-form input[name="direction_id"], #direction_id');
            dirFields.forEach(function (field) {
                field.value = String(template.direction_id);
                try { field.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
                try { field.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
            });
        }

        if (!template.warehouse && template.delivery_id) {
            setVal('selected_delivery_id', template.delivery_id);
            var card = document.querySelector('.delivery-card[data-delivery-id="' + template.delivery_id + '"]');
            if (card && typeof window.selectDeliveryCard === 'function') {
                var dir = card.getAttribute('data-direction-id') || template.direction_id;
                try { window.selectDeliveryCard(card, dir, true); } catch (e) {}
            }
        }
    }
    setTimeout(applyDestination, 250);

    // Charge basis
    var basis = String(template.charge_basis || 'auto').toLowerCase();
    if (basis === 'weight') basis = 'mass';
    var basisRadio = document.querySelector('input[name="charge_basis"][value="' + basis + '"]');
    if (basisRadio) {
        basisRadio.checked = true;
        try { basisRadio.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
    }

    // Volume dims / charge — leave mass empty
    setVal('total_mass_kg', '');
    setVal('mass_rate', '');
    setVal('mass_charge', '0.00');
    setVal('base_rate', '');
    setVal('current_rate', '');

    if (template.item_length > 0) setVal('item_length', template.item_length);
    if (template.item_width > 0) setVal('item_width', template.item_width);
    if (template.item_height > 0) setVal('item_height', template.item_height);

    if (template.total_volume > 0) {
        setVal('total_volume', Number(template.total_volume).toFixed(6));
    }
    if (template.volume_rate_used > 0) {
        setVal('volume_rate_per_m3', Number(template.volume_rate_used).toFixed(2));
    }
    if (template.volume_charge > 0) {
        setVal('volume_charge', Number(template.volume_charge).toFixed(2));
    }

    if (template.use_custom_volume_rate && template.custom_volume_rate_per_m3 > 0) {
        var customCb = document.getElementById('enable_volume_price_manipulator') || document.querySelector('input[name="use_custom_volume_rate"]');
        if (customCb) {
            customCb.checked = true;
            try { customCb.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
        }
        setVal('custom_volume_rate_per_m3', template.custom_volume_rate_per_m3);
    }

    // Misc items (not parcels)
    if (Array.isArray(template.misc_items) && template.misc_items.length) {
        setTimeout(function () {
            var addBtn = document.getElementById('add-misc-item-0');
            var container = document.getElementById('misc-items-0');
            if (!addBtn || !container) return;

            // Clear existing misc rows first
            container.querySelectorAll('.dynamic-item .remove-item').forEach(function (btn) {
                try { btn.click(); } catch (e) {}
            });

            template.misc_items.forEach(function (item) {
                try { addBtn.click(); } catch (e) {}
                var rows = container.querySelectorAll('.dynamic-item');
                var row = rows[rows.length - 1];
                if (!row) return;
                var desc = row.querySelector('input[name*="[misc_item]"]');
                var qty = row.querySelector('input[name*="[misc_quantity]"]');
                var price = row.querySelector('input[name*="[misc_price]"]');
                if (desc) {
                    desc.value = item.misc_item || '';
                    try { desc.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
                }
                if (qty) {
                    qty.value = String(item.misc_quantity || 1);
                    try { qty.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
                }
                if (price) {
                    price.value = String(item.misc_price != null ? item.misc_price : 0);
                    try { price.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
                }
            });
        }, 500);
    }

    if (typeof window.kitUpdateBilledFreightDisplay === 'function') {
        setTimeout(window.kitUpdateBilledFreightDisplay, 600);
    }
    if (typeof window.kitChargesReadyToContinue === 'function') {
        setTimeout(window.kitChargesReadyToContinue, 700);
    }
    if (typeof window.kitUpdateWaybillReview === 'function') {
        setTimeout(window.kitUpdateWaybillReview, 800);
    }
    if (typeof window.kitClearOrphanRateLoaders === 'function') {
        setTimeout(window.kitClearOrphanRateLoaders, 900);
    }

    var wbNo = template.source_waybill_no || template.source_waybill_id || '';
    showNotice(
        wbNo
            ? ('Prefilling from waybill #' + wbNo + '. Mass and parcel items were left blank.')
            : 'Prefilling from this customer’s last waybill. Mass and parcel items were left blank.'
    );
};

window.kitPrefillFromCustomerLastWaybill = function kitPrefillFromCustomerLastWaybill(customerId) {
    var custId = parseInt(customerId, 10) || 0;
    if (custId <= 0) return;
    if (typeof myPluginAjax === 'undefined' || !myPluginAjax.ajax_url) return;

    var nonce = (myPluginAjax.nonces && myPluginAjax.nonces.get_waybills_nonce) || myPluginAjax.nonce || '';
    var body = new URLSearchParams({
        action: 'kit_get_customer_last_waybill_template',
        customer_id: String(custId),
        nonce: nonce
    });

    fetch(myPluginAjax.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
    })
        .then(function (r) { return r.json(); })
        .then(function (payload) {
            if (payload && payload.success && payload.data) {
                window.kitApplyLastWaybillTemplate(payload.data);
            } else {
                var host = document.getElementById('kit-last-waybill-prefill-notice');
                if (!host) {
                    var badge = document.getElementById('selected-customer-badge');
                    host = document.createElement('div');
                    host.id = 'kit-last-waybill-prefill-notice';
                    if (badge && badge.parentNode) {
                        badge.parentNode.insertBefore(host, badge.nextSibling);
                    }
                }
                if (host) {
                    host.className = 'mb-4 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600';
                    host.textContent = (payload && payload.data && payload.data.message)
                        ? payload.data.message
                        : 'No previous waybill found to prefill for this customer.';
                    host.style.display = 'block';
                }
            }
        })
        .catch(function () {
            // Silent fail — customer selection still works without prefill.
        });
};

/** Re-fetch mass rate when direction_id becomes available after mass was entered. */
window.kitRefreshMassRatesIfNeeded = function kitRefreshMassRatesIfNeeded() {
    if (typeof window.kitResolveDirectionId !== 'function' || typeof fetchRatePerKg !== 'function') {
        return;
    }
    if (!window.kitResolveDirectionId()) {
        return;
    }
    function parseNumber(value) {
        if (!value || value === '') return 0;
        var normalized = value.toString().replace(',', '.');
        var parsed = parseFloat(normalized);
        return Number.isFinite(parsed) ? parsed : 0;
    }
    document.querySelectorAll('.kit-mass-component').forEach(function(scope) {
        var massInput = scope.querySelector('.kit-total-mass') || scope.querySelector('#total_mass_kg');
        var rateInput = scope.querySelector('.kit-mass-rate') || scope.querySelector('#mass_rate');
        if (!massInput || !rateInput) {
            return;
        }
        var mass = parseNumber(massInput.value);
        var rate = parseNumber(rateInput.value);
        if (mass > 0 && rate <= 0) {
            fetchRatePerKg(scope);
        }
    });
};

window.kitClearOrphanRateLoaders = function kitClearOrphanRateLoaders() {
    function parseLoose(value) {
        var n = parseFloat(String(value || '').replace(',', '.'));
        return Number.isFinite(n) ? n : 0;
    }
    try {
        // Prefer clearing known charge fields once a numeric value is present —
        // stuck aria-busy overlays were leaving "Calculating…" over valid rates.
        var knownIds = ['mass_rate', 'mass_charge', 'volume_rate_per_m3', 'volume_charge', 'volume_rate'];
        knownIds.forEach(function (id) {
            var el = document.getElementById(id);
            if (!el || !el.classList.contains('kit-input-loading')) return;
            if (parseLoose(el.value) > 0 && window.ComponentUtils && typeof window.ComponentUtils.hideInputLoader === 'function') {
                window.ComponentUtils.hideInputLoader(el);
            }
        });
        document.querySelectorAll('.kit-input-loading').forEach(function (el) {
            if (parseLoose(el.value) > 0 && window.ComponentUtils && typeof window.ComponentUtils.hideInputLoader === 'function') {
                window.ComponentUtils.hideInputLoader(el);
            }
        });
        document.querySelectorAll('.kit-total-mass.loading, #total_mass_kg.loading').forEach(function (el) {
            el.classList.remove('loading');
        });
    } catch (e) {}
};

function fetchRatePerKg(scopeEl) {
    try {
        var $scope = scopeEl ? jQuery(scopeEl) : jQuery(document);
        var rawMass = ($scope.find('.kit-total-mass').val() || $scope.find('#total_mass_kg').val() || '');
        var total_mass_kg = parseFloat(String(rawMass).replace(',', '.')) || 0;
        var direction_id = window.kitResolveDirectionId ? window.kitResolveDirectionId() : ($scope.find('#direction_id').val() || jQuery('#direction_id').val() || '');
        var origin_country_id = ($scope.find('#countrydestination_id').val() || jQuery('#countrydestination_id').val() || '');

        if (rawMass !== '' && (isNaN(total_mass_kg) || total_mass_kg <= 0)) {
            showRateFetchError('Invalid mass value. Please enter a positive number.');
            return;
        }
        if (total_mass_kg > 10000) {
            showRateFetchError('Mass exceeds maximum limit of 10,000 kg.');
            return;
        }
        if (!direction_id) {
            showRateFetchError('Missing direction information. Please refresh the page.');
            return;
        }
        if (!origin_country_id) {
            showRateFetchError('Missing origin country information. Please refresh the page.');
            return;
        }
        if (!(total_mass_kg > 0 && direction_id && origin_country_id)) {
            return;
        }

        var massInput = ($scope.find('.kit-total-mass').get(0) || document.getElementById('total_mass_kg'));
        var massRateEl = ($scope.find('.kit-mass-rate').get(0) || document.getElementById('mass_rate'));
        var massChargeEl = ($scope.find('.kit-mass-charge').get(0) || document.getElementById('mass_charge'));

        function clearMassLoaders() {
            if (massInput) {
                massInput.classList.remove('loading');
            }
            try {
                if (window.ComponentUtils && typeof window.ComponentUtils.hideInputLoader === 'function') {
                    if (massRateEl) window.ComponentUtils.hideInputLoader(massRateEl);
                    if (massChargeEl) window.ComponentUtils.hideInputLoader(massChargeEl);
                }
            } catch (e) {}
        }

        var savedCharge = parseFloat(String($scope.find('.kit-mass-charge').val() || jQuery('#mass_charge').val() || '').replace(',', '.')) || 0;
        var existingRate = parseFloat(String($scope.find('.kit-mass-rate').val() || jQuery('#mass_rate').val() || '').replace(',', '.')) || 0;
        if (existingRate > 0) {
            clearMassLoaders();
            if (typeof window.kitUpdateBilledFreightDisplay === 'function') {
                window.kitUpdateBilledFreightDisplay();
            }
            if (typeof window.kitChargesReadyToContinue === 'function') {
                window.kitChargesReadyToContinue();
            }
            return;
        }
        if (savedCharge > 0 && total_mass_kg > 0) {
            var derivedRate = savedCharge / total_mass_kg;
            var $massChargeTargets = ($scope.find('.kit-mass-charge').length ? $scope.find('.kit-mass-charge') : jQuery('#mass_charge'));
            var $massRateTargets = ($scope.find('.kit-mass-rate').length ? $scope.find('.kit-mass-rate') : jQuery('#mass_rate'));
            var $baseRateTargets = ($scope.find('#base_rate').length ? $scope.find('#base_rate') : jQuery('#base_rate'));
            var $currentRateTargets = ($scope.find('#current_rate').length ? $scope.find('#current_rate') : jQuery('#current_rate'));
            $massRateTargets.val(derivedRate.toFixed(2));
            $baseRateTargets.val(derivedRate.toFixed(2));
            $currentRateTargets.val(derivedRate.toFixed(2));
            $massChargeTargets.val(savedCharge.toFixed(2));
            clearMassLoaders();
            try { $massRateTargets.trigger('change'); } catch (e) {}
            try { $massChargeTargets.trigger('change'); } catch (e) {}
            try {
                if (typeof window.kitUpdateWaybillHeaderTotal === 'function') {
                    window.kitUpdateWaybillHeaderTotal();
                }
            } catch (e) {}
            if (typeof window.kitUpdateBilledFreightDisplay === 'function') {
                window.kitUpdateBilledFreightDisplay();
            }
            if (typeof window.kitChargesReadyToContinue === 'function') {
                window.kitChargesReadyToContinue();
            }
            return;
        }

        // Abort any in-flight mass rate request for this scope.
        if (fetchRatePerKg._xhr && typeof fetchRatePerKg._xhr.abort === 'function') {
            try { fetchRatePerKg._xhr.abort(); } catch (e) {}
        }
        fetchRatePerKg._seq = (fetchRatePerKg._seq || 0) + 1;
        var requestSeq = fetchRatePerKg._seq;

        if (massInput) {
            massInput.classList.add('loading');
        }
        try {
            if (window.ComponentUtils && typeof window.ComponentUtils.showInputLoader === 'function') {
                if (massRateEl) window.ComponentUtils.showInputLoader(massRateEl, 'Calculating rate…');
                if (massChargeEl) window.ComponentUtils.showInputLoader(massChargeEl, 'Calculating charge…');
            }
        } catch (e) {}

        if (typeof window.kitChargesReadyToContinue === 'function') {
            window.kitChargesReadyToContinue();
        }

        var safetyTimer = setTimeout(function () {
            if (requestSeq !== fetchRatePerKg._seq) return;
            clearMassLoaders();
            if (typeof window.kitChargesReadyToContinue === 'function') {
                window.kitChargesReadyToContinue();
            }
        }, 12000);

        fetchRatePerKg._xhr = jQuery.ajax({
            url: myPluginAjax.ajax_url,
            type: 'POST',
            dataType: 'json',
            timeout: 10000,
            data: {
                action: 'handle_get_price_per_kg',
                total_mass_kg: total_mass_kg,
                direction_id: direction_id,
                origin_country_id: origin_country_id,
                nonce: myPluginAjax.nonces.get_waybills_nonce
            },
            success: function (response) {
                if (requestSeq !== fetchRatePerKg._seq) return;

                if (response && response.success && response.data) {
                    var rate = parseFloat(response.data.rate_per_kg);
                    var total_charge = parseFloat(response.data.total_charge);

                    if (!Number.isFinite(rate) || rate <= 0) {
                        showRateFetchError('Invalid rate received from server.');
                    } else if (!Number.isFinite(total_charge) || total_charge <= 0) {
                        showRateFetchError('Invalid charge calculation received from server.');
                    } else {
                        var $massChargeTargets = ($scope.find('.kit-mass-charge').length ? $scope.find('.kit-mass-charge') : jQuery('#mass_charge'));
                        var $massRateTargets = ($scope.find('.kit-mass-rate').length ? $scope.find('.kit-mass-rate') : jQuery('#mass_rate'));
                        var $baseRateTargets = ($scope.find('#base_rate').length ? $scope.find('#base_rate') : jQuery('#base_rate'));
                        var $currentRateTargets = ($scope.find('#current_rate').length ? $scope.find('#current_rate') : jQuery('#current_rate'));
                        $massChargeTargets.val(total_charge.toFixed(2));
                        $massRateTargets.val(rate.toFixed(2));
                        $baseRateTargets.val(rate.toFixed(2));
                        $currentRateTargets.val(rate.toFixed(2));
                        $massChargeTargets.attr('data-base-charge', total_charge.toFixed(2));
                        jQuery('#mass_charge_display').text(rate);
                        try { $massRateTargets.trigger('change'); } catch (e) {}
                        try { $massChargeTargets.trigger('change'); } catch (e) {}
                        try {
                            if (typeof window.kitUpdateWaybillHeaderTotal === 'function') {
                                window.kitUpdateWaybillHeaderTotal();
                            }
                        } catch (e) {}
                        jQuery('#rate-fetch-error').remove();
                        if (typeof calculateMassCharge === 'function') {
                            calculateMassCharge();
                        }
                    }
                } else {
                    var errorMsg = (response && response.data && response.data.message)
                        ? response.data.message
                        : 'Unable to fetch rate from server.';
                    showRateFetchError(errorMsg);
                }

                if (typeof window.kitUpdateBilledFreightDisplay === 'function') {
                    window.kitUpdateBilledFreightDisplay();
                }
            },
            error: function (xhr, status) {
                if (requestSeq !== fetchRatePerKg._seq) return;
                if (status === 'abort') return;

                var errorMessage = 'Network error. Please check your connection and try again.';
                if (status === 'timeout') {
                    errorMessage = 'Request timed out. Please try again.';
                } else if (xhr && xhr.status === 0) {
                    errorMessage = 'Network connection lost. Please check your internet connection.';
                } else if (xhr && xhr.status >= 500) {
                    errorMessage = 'Server error. Please try again later.';
                } else if (xhr && xhr.status === 403) {
                    errorMessage = 'Access denied. Please refresh the page and try again.';
                }
                showRateFetchError(errorMessage);
            },
            complete: function () {
                if (requestSeq !== fetchRatePerKg._seq) return;
                clearTimeout(safetyTimer);
                clearMassLoaders();
                if (typeof window.kitChargesReadyToContinue === 'function') {
                    window.kitChargesReadyToContinue();
                }
            }
        });
    } catch (error) {
        console.error('Rate fetch function error:', error);
        if (typeof window.kitClearOrphanRateLoaders === 'function') {
            window.kitClearOrphanRateLoaders();
        }
        showRateFetchError('An unexpected error occurred. Please try again.');
        if (typeof window.kitChargesReadyToContinue === 'function') {
            window.kitChargesReadyToContinue();
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Bind per component instance (supports multiple waybill sections)
    jQuery(document).on('click', '.kit-total-mass, #total_mass_kg', function () {
        var scope = jQuery(this).closest('.kit-mass-component').get(0);
        fetchRatePerKg(scope);
    });

    var timeout;
    jQuery(document).on('input', '.kit-total-mass, #total_mass_kg', function () {
        clearTimeout(timeout);
        var scope = jQuery(this).closest('.kit-mass-component').get(0);
        timeout = setTimeout(function () { fetchRatePerKg(scope); }, 500); // Wait 500ms after user stops typing
    });

    // When direction_id is set (delivery / warehouse / country), refresh mass rates if mass was already entered.
    jQuery(document).on('change input', '#multi-step-waybill-form input[name="direction_id"]', function () {
        if (typeof window.kitRefreshMassRatesIfNeeded === 'function') {
            window.kitRefreshMassRatesIfNeeded();
        }
    });
    
    // ✅ BULLETPROOF: Auto-trigger rate fetch on page load if mass is present but rate is missing
    jQuery(document).ready(function() {
        // Helper function to properly parse numbers with comma decimal separators
        function parseNumber(value) {
            if (!value || value === '') return 0;
            // Convert comma to dot for decimal separator
            var normalized = value.toString().replace(',', '.');
            var parsed = parseFloat(normalized);
            return Number.isFinite(parsed) ? parsed : 0;
        }
        
        setTimeout(function() {
            if (typeof window.kitRefreshMassRatesIfNeeded === 'function') {
                window.kitRefreshMassRatesIfNeeded();
            }
        }, 1000); // 1 second delay to ensure all elements are loaded
    });
    // Dispatch date validation - REMOVED: Allow editing of old deliveries
    // Previously restricted dates to today or later, but users need to edit past deliveries
    var dispatchDateInput = document.getElementById('dispatch_date');
    
    if (dispatchDateInput) {
        // Remove any min date restriction to allow editing old deliveries
        dispatchDateInput.removeAttribute('min');
        
        // Removed date validation on form submission - allow past dates
    }

    // VAT checkbox functionality (skip on Edit Waybill page - that form uses its own script)
    var vatCheckbox2 = document.getElementById('vat_include2');
    var vatCheckbox = document.getElementById('vat_include');
    var SADC = document.getElementById('sadc_certificate');
    var optionz = document.querySelectorAll('.optionz');
    var nextStep3 = document.getElementById('next-step-3');
    var addWaybillItemBtn = document.getElementById('add-waybill-item-btn');

    var formWithVat = (vatCheckbox && vatCheckbox.closest('form')) || (vatCheckbox2 && vatCheckbox2.closest('form'));
    var isEditWaybillForm = formWithVat && formWithVat.querySelector('input[name="action"][value="update_waybill_action"]');

    if (!isEditWaybillForm) {
        function toggleVatDisabled() {
            if (!vatCheckbox2) {
                return; // Exit early if vatCheckbox2 doesn't exist
            }
            
            if (SADC && SADC.checked) {
                vatCheckbox2.checked = false;
                vatCheckbox2.disabled = true;
            } else if (SADC) {
                vatCheckbox2.checked = true;
                vatCheckbox2.disabled = false;
            }
        }
        function toggleOptionzDisabled() {
            // Only run this function if we're on a step that has VAT checkboxes
            if (!vatCheckbox && !vatCheckbox2) {
                return; // Exit early if no VAT checkboxes exist on this step
            }
            
            if (vatCheckbox && vatCheckbox.checked || vatCheckbox2 && vatCheckbox2.checked) {
                //bg-gray-300 text-gray-500 rounded-md hover:bg-gray-400
                if (nextStep3) {
                    nextStep3.disabled = true;
                    nextStep3.classList.add('bg-gray-300', 'text-gray-500');
                    nextStep3.classList.remove('bg-blue-600', 'text-white');
                }

                if (SADC) {
                    SADC.disabled = true;
                    SADC.checked = 0;
                }
            } else {
                
                if (nextStep3) {
                nextStep3.disabled = false;
                nextStep3.classList.remove('bg-gray-300', 'text-gray-500');
                nextStep3.classList.add('bg-blue-600', 'text-white');
                }
                if (SADC) {
                    SADC.disabled = false;
                }
            }
        }

        if (vatCheckbox2) {
            vatCheckbox2.addEventListener('change', toggleOptionzDisabled);
            // Run on page load in case VAT is pre-checked
            toggleOptionzDisabled();
        }

        if (vatCheckbox) {
            vatCheckbox.addEventListener('change', toggleOptionzDisabled);
            // Run on page load in case VAT is pre-checked
            toggleOptionzDisabled();
        }

        if (SADC) {
            SADC.addEventListener('change', toggleVatDisabled);
            // Run on page load in case VAT is pre-checked
            toggleOptionzDisabled();
        }
    }


    // Delete waybill
    jQuery(document).on('click', '.delete-waybill', function (e) {
        e.preventDefault();

        var $form = jQuery(this).closest('form');
        var waybillId = $form.find('input[name="waybill_id"]').val();
        var waybillNo = $form.find('input[name="waybill_no"]').val();
        var deliveryId = $form.find('input[name="delivery_id"]').val();
        var userId = $form.find('input[name="user_id"]').val();
        var nonce = $form.find('input[name="_wpnonce"]').val();
        var $row = jQuery(this).closest('tr');

        if (!confirm('Are you sure you want to delete this waybill?')) {
            return;
        }

        jQuery.ajax({
            url: myPluginAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'delete_waybill',
                waybill_id: waybillId,
                waybill_no: waybillNo,
                delivery_id: deliveryId,
                user_id: userId,
                _ajax_nonce: nonce
            },
            beforeSend: function () {
                $row.css('opacity', '0.5');
            },
            success: function (response) {
                if (response.success) {
                    /* After deleting waybill reload the table #waybill-table-container */
                    jQuery('#waybill-table-parent').load(window.location.href + ' #waybill-table-container');
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to delete waybill'));
                    $row.css('opacity', '1');
                }
            },
            error: function (xhr, status, error) {
                alert('Error: ' + error);
                $row.css('opacity', '1');
            }
        });
    });

    // Select all rows
    jQuery(document).on('change', '.selectRow', function () {
        // Check if all rows are checked
        var allChecked = jQuery('.selectRow:checked').length === jQuery('.selectRow').length;
        jQuery('.selectAllRows').prop('checked', allChecked);
    });
    jQuery(document).on('click', '#add-waybill-item', function () {
        var nextStep3 = document.getElementById('next-step-3');
        if (nextStep3) {
            nextStep3.disabled = false;
            //hidden md:block next-step px-4 py-2 rounded-md hover:bg-blue-700 bg-gray-300 text-gray-500
            nextStep3.classList.remove('bg-gray-300', 'text-gray-500');
            nextStep3.classList.add('bg-blue-600', 'text-white');
        }
    });

    // Select all rows
    jQuery(document).on('change', '.selectAllRows', function () {
        // Check if all rows are checked
        var allChecked = jQuery(this).prop('checked');
        jQuery('.selectRow').prop('checked', allChecked);
    });

    // Delete delivery
    jQuery(document).on('click', '.delete-delivery', function (e) {
        e.preventDefault();
        var deliveryId = jQuery(this).data('delivery-id');
        if (!confirm('Are you sure you want to delete this delivery?')) {
            return;
        }
    });

    // Helpers for Step 4 next button styling
    function setStep4NextState(enabled) {
        var btn = document.getElementById('step4NextBtn');
        if (!btn) return;
        var active = [
            'inline-flex','items-center','px-6','py-3','bg-gradient-to-r','from-blue-600','to-indigo-600',
            'hover:from-blue-700','hover:to-indigo-700','text-white','font-semibold','rounded-xl','shadow-lg',
            'hover:shadow-xl','transform','hover:-translate-y-0.5','transition-all','duration-200'
        ];
        var disabled = ['opacity-50','cursor-not-allowed','pointer-events-none','bg-blue-400','bg-gray-300','text-gray-500'];
        if (enabled) {
            btn.classList.add(...active);
            btn.classList.remove(...disabled);
            btn.disabled = false;
            btn.setAttribute('aria-disabled','false');
        } else {
            btn.classList.remove(...active);
            btn.classList.add(...disabled);
            btn.disabled = true;
            btn.setAttribute('aria-disabled','true');
        }
    }

    // Warehoused checkbox (waybill Step 4 only — Edit Delivery modal reuses same ids; scope to form)
    jQuery(document).on('change', '#multi-step-waybill-form #pending_option', function () {
        var form = document.getElementById('multi-step-waybill-form');
        if (!form) return;
        var scheduledDeliveriesList = form.querySelector('#scheduled-deliveries-list') || document.getElementById('scheduled-deliveries-list');
        var destinationCountryHelp = form.querySelector('#destination-country-help');
        var destinationCityHelp = form.querySelector('#destination-city-help');
        
        if (jQuery(this).is(':checked')) {
            // Hide truck / scheduled deliveries when warehouse (pending) is checked
            var truckPanel = form.querySelector('#kit-step4-truck-panel');
            if (truckPanel) {
                truckPanel.classList.add('hidden');
            } else if (scheduledDeliveriesList) {
                scheduledDeliveriesList.classList.add('hidden');
            }
            
            // Enable the next button for pending items
            setStep4NextState(true);
            // Keep destination selections intact when warehoused is checked.
            // Users may toggle warehoused on/off and expect previously selected
            // country/city values to remain available.
            
            // Hide helper text for destination fields
            if (destinationCountryHelp) destinationCountryHelp.style.display = 'none';
            if (destinationCityHelp) destinationCityHelp.style.display = 'none';
            
        } else {
            // Show truck panel or scheduled list when switching to delivery / truck mode
            var truckPanelShow = form.querySelector('#kit-step4-truck-panel');
            if (truckPanelShow) {
                truckPanelShow.classList.remove('hidden');
            } else if (scheduledDeliveriesList) {
                scheduledDeliveriesList.classList.remove('hidden');
            }
            
            // Show helper text for destination fields
            if (destinationCountryHelp) destinationCountryHelp.style.display = 'block';
            if (destinationCityHelp) destinationCityHelp.style.display = 'block';
            
            // Validate destination selection
            validateDestinationSelection();
        }
    });
    
    // Destination country change handler (waybill form only)
    jQuery(document).on('change', '#multi-step-waybill-form #stepDestinationSelect', function () {
        validateDestinationSelection();
    });
    
    // Destination city change handler (waybill form only)
    jQuery(document).on('change', '#multi-step-waybill-form #destination_city', function () {
        validateDestinationSelection();
    });
    
    // Function to validate destination selection (waybill Step 4 only)
    function validateDestinationSelection() {
        var form = document.getElementById('multi-step-waybill-form');
        if (!form) return;
        var pendingCheckbox = form.querySelector('#pending_option');
        var destinationCountry = form.querySelector('#stepDestinationSelect');
        var destinationCity = form.querySelector('#destination_city');
        var destinationCountryHelp = form.querySelector('#destination-country-help');
        var destinationCityHelp = form.querySelector('#destination-city-help');
        
        // If pending is checked, no validation needed
        if (pendingCheckbox && pendingCheckbox.checked) {
            setStep4NextState(true);
            return;
        }
        
        // Check if both country and city are selected
        var countrySelected = destinationCountry && destinationCountry.value && destinationCountry.value !== '';
        var citySelected = destinationCity && destinationCity.value && destinationCity.value !== '';
        
        // Update helper text colors based on selection
        if (destinationCountryHelp) {
            if (countrySelected) {
                destinationCountryHelp.classList.remove('text-red-500');
                destinationCountryHelp.classList.add('text-green-500');
                destinationCountryHelp.textContent = '✓ Country selected';
            } else {
                destinationCountryHelp.classList.remove('text-green-500');
                destinationCountryHelp.classList.add('text-red-500');
                destinationCountryHelp.textContent = '✗ Country required';
            }
        }
        
        if (destinationCityHelp) {
            if (citySelected) {
                destinationCityHelp.classList.remove('text-red-500');
                destinationCityHelp.classList.add('text-green-500');
                destinationCityHelp.textContent = '✓ City selected';
            } else {
                destinationCityHelp.classList.remove('text-green-500');
                destinationCityHelp.classList.add('text-red-500');
                destinationCityHelp.textContent = '✗ City required';
            }
        }
        
        setStep4NextState(countrySelected && citySelected);
    }
    
    // Initialize validation on page load (waybill form only)
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('multi-step-waybill-form');
        if (!form) return;
        validateDestinationSelection();
        
        // Set initial state for helper text visibility
        var pendingCheckbox = form.querySelector('#pending_option');
        var destinationCountryHelp = form.querySelector('#destination-country-help');
        var destinationCityHelp = form.querySelector('#destination-city-help');
        
        if (pendingCheckbox && pendingCheckbox.checked) {
            if (destinationCountryHelp) destinationCountryHelp.style.display = 'none';
            if (destinationCityHelp) destinationCityHelp.style.display = 'none';
        }
    });

    (function initEditWaybillEditPage() {
        const editForm = document.querySelector('form[action*="update_waybill_action"]');
        const customerSearch = document.getElementById('customer-search-edit');

        if (!editForm || !customerSearch) {
            return;
        }

        const customersData = (window.CUSTOMERS_DATA && typeof window.CUSTOMERS_DATA === 'object') ? window.CUSTOMERS_DATA : {};
        const customerSelect = document.getElementById('customer-select-edit');
        const customerResults = document.getElementById('customer-results-edit');
        const custIdInput = document.querySelector('input[name="cust_id"]');
        const addNewCustomerBtn = document.getElementById('add-new-customer-btn-edit');
        const recentCustomersBtn = document.getElementById('recent-customers-btn-edit');

        const originDefaults = {
            country: (document.getElementById('origin_country_initial')?.value || '').trim(),
            city: (() => {
                const val = (document.getElementById('origin_city_initial')?.value || '').trim();
                return val === '0' ? '' : val;
            })()
        };
        let allowAutoOriginFromCustomer = !originDefaults.city;
        let originRestoreApplied = false;

        const destinationDefaults = {
            country: (document.getElementById('destination_country_initial')?.value || '').trim(),
            city: (() => {
                const val = (document.getElementById('destination_city_initial')?.value || '').trim();
                return val === '0' ? '' : val;
            })()
        };
        let destinationRestoreApplied = false;

        function getCustomerInput(idBase) {
            return document.getElementById(idBase) ||
                document.getElementById('a' + idBase) ||
                document.getElementById('a_' + idBase) ||
                document.querySelector('[name="' + idBase + '"]');
        }

        const nameInput = getCustomerInput('customer_name_edit');
        const surnameInput = getCustomerInput('customer_surname_edit');
        const cellInput = getCustomerInput('cell_edit');
        const addressInput = getCustomerInput('address_edit');
        const emailInput = getCustomerInput('email_address_edit');
        const telephoneInput = getCustomerInput('telephone_edit');
        const companyNameInput = document.getElementById('company_name_edit');
        const companyNameWrapper = document.getElementById('company_name_wrapper_edit');
        const clientTypeRadios = document.querySelectorAll('.client-type-radio-edit');

        function updateCompanyNameVisibility() {
            const selectedType = document.querySelector('input[name="client_type"]:checked')?.value;
            if (selectedType === 'individual') {
                if (companyNameWrapper) companyNameWrapper.style.display = 'none';
                if (companyNameInput) companyNameInput.value = '1ndividual';
            } else if (companyNameWrapper) {
                companyNameWrapper.style.display = '';
            }
        }

        clientTypeRadios.forEach(function(radio) {
            radio.addEventListener('change', updateCompanyNameVisibility);
        });
        updateCompanyNameVisibility();

        function restoreOriginSelections(force) {
            if (originRestoreApplied && !force) {
                return;
            }

            const originCountrySelect = document.getElementById('origin_country_select');
            const originCitySelect = document.getElementById('origin_city_select');
            if (!originCountrySelect) {
                return;
            }

            if (originDefaults.country) {
                originCountrySelect.value = originDefaults.country;
            }

            const applyCitySelection = () => {
                if (!originCitySelect || !originDefaults.city) {
                    return false;
                }
                const targetValue = String(originDefaults.city);
                if (String(originCitySelect.value) === targetValue) {
                    return true;
                }
                const match = Array.from(originCitySelect.options || []).some(option => String(option.value) === targetValue);
                if (match) {
                    originCitySelect.value = targetValue;
                    return true;
                }
                return false;
            };

            if (originCitySelect && originDefaults.city) {
                const observer = new MutationObserver((mutations, obs) => {
                    if (applyCitySelection()) {
                        obs.disconnect();
                    }
                });
                observer.observe(originCitySelect, { childList: true });
                applyCitySelection();
            }

            if (originDefaults.country && typeof loadCitiesForCountry === 'function') {
                loadCitiesForCountry(originDefaults.country, 'origin', originDefaults.city || '');
            } else if (originDefaults.country && originCountrySelect.onchange) {
                originCountrySelect.onchange();
                setTimeout(applyCitySelection, 250);
            } else {
                applyCitySelection();
            }

            originRestoreApplied = true;
        }

        function restoreDestinationSelections(force) {
            if (destinationRestoreApplied && !force) {
                return;
            }

            const destinationCountrySelect = document.getElementById('destination_country_select');
            const destinationCitySelect = document.getElementById('destination_city_select');
            if (!destinationCountrySelect) {
                return;
            }

            if (destinationDefaults.country) {
                destinationCountrySelect.value = destinationDefaults.country;
            }

            const applyDestinationCity = () => {
                if (!destinationCitySelect || !destinationDefaults.city) {
                    return false;
                }
                const targetValue = String(destinationDefaults.city);
                if (String(destinationCitySelect.value) === targetValue) {
                    return true;
                }
                const match = Array.from(destinationCitySelect.options || []).some(option => String(option.value) === targetValue);
                if (match) {
                    destinationCitySelect.value = targetValue;
                    return true;
                }
                return false;
            };

            const observeDestination = destinationCitySelect && destinationDefaults.city;
            if (observeDestination) {
                const observer = new MutationObserver((mutations, obs) => {
                    if (applyDestinationCity()) {
                        obs.disconnect();
                    }
                });
                observer.observe(destinationCitySelect, { childList: true });
                applyDestinationCity();
            }

            if (destinationDefaults.country && typeof loadCitiesForCountry === 'function') {
                loadCitiesForCountry(destinationDefaults.country, 'destination', destinationDefaults.city || '');
            } else if (destinationDefaults.country && destinationCountrySelect.onchange) {
                destinationCountrySelect.onchange();
                setTimeout(applyDestinationCity, 250);
            } else {
                applyDestinationCity();
            }

            destinationRestoreApplied = true;
        }

        function searchCustomers(query) {
            if (!customerResults) {
                return;
            }
            if (!query || query.length < 2) {
                customerResults.classList.add('hidden');
                return;
            }
            const results = [];
            const searchTerm = query.toLowerCase();

            Object.values(customersData).forEach(customer => {
                const name = (customer.customer_name || '').toLowerCase();
                const surname = (customer.customer_surname || '').toLowerCase();
                const company = (customer.company_name || '').toLowerCase();
                const cell = (customer.cell || '').toLowerCase();
                const email = (customer.email_address || '').toLowerCase();

                if (
                    name.includes(searchTerm) ||
                    surname.includes(searchTerm) ||
                    company.includes(searchTerm) ||
                    cell.includes(searchTerm) ||
                    email.includes(searchTerm) ||
                    `${name} ${surname}`.includes(searchTerm)
                ) {
                    results.push(customer);
                }
            });

            displaySearchResults(results.slice(0, 10));
        }

        function displaySearchResults(results) {
            if (!customerResults) {
                return;
            }
            customerResults.innerHTML = '';
            if (results.length === 0) {
                customerResults.innerHTML = '<div class="px-4 py-2 text-gray-500 text-sm">No customers found</div>';
            } else {
                results.forEach(customer => {
                    const item = document.createElement('div');
                    item.className = 'px-4 py-2 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0';
                    item.innerHTML = `
                        <div class="font-medium text-gray-900">${customer.customer_name || ''} ${customer.customer_surname || ''}</div>
                        <div class="text-sm text-gray-500">${customer.company_name || customer.cell || ''}</div>
                    `;
                    item.addEventListener('click', () => selectCustomer(customer));
                    customerResults.appendChild(item);
                });
            }
            customerResults.classList.remove('hidden');
        }

        function selectCustomer(customer) {
            allowAutoOriginFromCustomer = true;
            originDefaults.country = '';
            originDefaults.city = '';
            originRestoreApplied = true;

            customerSearch.value = `${customer.customer_name || ''} ${customer.customer_surname || ''}`.trim();
            if (customerSelect) customerSelect.value = customer.cust_id || '';
            if (custIdInput) custIdInput.value = customer.cust_id || '';
            if (customerResults) customerResults.classList.add('hidden');
            populateCustomerDetails(customer.cust_id);
        }

        function addNewCustomer() {
            customerSearch.value = '';
            if (customerSelect) customerSelect.value = 'new';
            if (custIdInput) custIdInput.value = '0';
            if (customerResults) customerResults.classList.add('hidden');
            clearCustomerFields();
            allowAutoOriginFromCustomer = true;
            originDefaults.country = '';
            originDefaults.city = '';
            originRestoreApplied = true;
        }

        function showRecentCustomers() {
            if (!customerResults) return;
            const recentCustomers = Object.values(customersData)
                .sort((a, b) => (b.created_at || '').localeCompare(a.created_at || ''))
                .slice(0, 10);
            displaySearchResults(recentCustomers);
        }

        function populateCustomerDetails(customerId) {
            if (!customersData || !customersData[customerId]) {
                return;
            }
            const customer = customersData[customerId];
            const dn = customer.customer_name || '';
            const ds = customer.customer_surname || '';
            const dc = customer.cell || '';
            const da = customer.address || '';
            const de = customer.email_address || '';
            const dco = customer.company_name || customer.customer_name || '';
            if (nameInput) nameInput.value = dn;
            if (surnameInput) surnameInput.value = ds;
            if (cellInput) cellInput.value = dc;
            if (telephoneInput) telephoneInput.value = dc;
            if (addressInput) addressInput.value = da;
            if (emailInput) emailInput.value = de;
            if (companyNameInput) companyNameInput.value = dco;
            if (custIdInput) custIdInput.value = customerId;
            updateCompanyNameVisibility();
            populateOriginFromCustomer(customerId);

            const confirmationDiv = document.createElement('div');
            confirmationDiv.className = 'mt-2 p-2 bg-green-100 border border-green-300 rounded text-sm text-green-700 customer-change-confirmation';
            confirmationDiv.innerHTML = '✅ Customer updated! Click "Save Changes" to apply.';

            const existingConfirmation = document.querySelector('.customer-change-confirmation');
            if (existingConfirmation) {
                existingConfirmation.remove();
            }

            customerSearch.parentNode.appendChild(confirmationDiv);

            setTimeout(() => {
                if (confirmationDiv.parentNode) {
                    confirmationDiv.remove();
                }
            }, 3000);
        }

        function populateOriginFromCustomer(customerId) {
            if (!customersData || !customersData[customerId] || !allowAutoOriginFromCustomer) {
                return;
            }
            const customer = customersData[customerId];
            const customerData = {
                country_id: customer.country_id || '',
                city_id: customer.city_id || ''
            };
            const originCountryId = customerData.country_id || 1;
            const originCountrySelect = document.getElementById('origin_country_select');
            if (originCountrySelect) {
                originCountrySelect.value = originCountryId;
                if (typeof loadCitiesForCountry === 'function') {
                    loadCitiesForCountry(originCountryId, 'origin', customerData.city_id);
                } else if (originCountrySelect.onchange) {
                    originCountrySelect.onchange();
                }
            }
        }

        function clearCustomerFields() {
            if (nameInput) nameInput.value = '';
            if (surnameInput) surnameInput.value = '';
            if (cellInput) cellInput.value = '';
            if (telephoneInput) telephoneInput.value = '';
            if (addressInput) addressInput.value = '';
            if (emailInput) emailInput.value = '';
            if (companyNameInput) companyNameInput.value = '';
            if (custIdInput) custIdInput.value = '0';
            const businessRadio = document.getElementById('client_type_business_edit');
            if (businessRadio) businessRadio.checked = true;
            updateCompanyNameVisibility();
        }

        if (customerSearch) {
            customerSearch.addEventListener('input', function() {
                searchCustomers(this.value);
            });
            customerSearch.addEventListener('focus', function() {
                if (this.value.length >= 2) {
                    searchCustomers(this.value);
                }
            });
            document.addEventListener('click', function(e) {
                if (!customerSearch.contains(e.target) && !customerResults?.contains(e.target)) {
                    customerResults?.classList.add('hidden');
                }
            });
            customerSearch.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    customerResults?.classList.add('hidden');
                }
            });
        }

        if (addNewCustomerBtn) {
            addNewCustomerBtn.addEventListener('click', addNewCustomer);
        }
        if (recentCustomersBtn) {
            recentCustomersBtn.addEventListener('click', showRecentCustomers);
        }

        const initialCustomerId = custIdInput?.value;
        if (initialCustomerId && initialCustomerId !== '0' && customersData[initialCustomerId]) {
            const customer = customersData[initialCustomerId];
            customerSearch.value = `${customer.customer_name || ''} ${customer.customer_surname || ''}`.trim();
            if (customerSelect) customerSelect.value = initialCustomerId;
            populateCustomerDetails(initialCustomerId);
        } else if (initialCustomerId === '0' || !initialCustomerId) {
            customerSearch.value = '';
            if (customerSelect) customerSelect.value = 'new';
        }

        if (!allowAutoOriginFromCustomer) {
            setTimeout(() => restoreOriginSelections(false), 0);
        }
        setTimeout(() => restoreDestinationSelections(false), 0);

        const warehouseCheckbox = document.getElementById('pending_option');
        if (warehouseCheckbox) {
            warehouseCheckbox.addEventListener('change', function() {
                const isWarehoused = this.checked;
                if (typeof window.waybillData === 'undefined') {
                    window.waybillData = {};
                }
                window.waybillData.warehouse = isWarehoused;

                const warehouseStatusField = document.getElementById('warehouse_status');
                if (warehouseStatusField) {
                    warehouseStatusField.value = isWarehoused ? '1' : '0';
                }

                const confirmationDiv = document.createElement('div');
                confirmationDiv.className = 'mt-2 p-2 bg-blue-100 border border-blue-300 rounded text-sm text-blue-700 warehouse-confirmation';
                confirmationDiv.innerHTML = isWarehoused ?
                    '✅ Waybill marked as warehoused' :
                    '✅ Waybill removed from warehouse';

                const existingConfirmation = document.querySelector('.warehouse-confirmation');
                if (existingConfirmation) {
                    existingConfirmation.remove();
                }

                this.parentNode.appendChild(confirmationDiv);

                setTimeout(() => {
                    if (confirmationDiv.parentNode) {
                        confirmationDiv.remove();
                    }
                }, 3000);
            });
        }

        window.selectDeliveryCard = function(cardElement, directionId) {
            document.querySelectorAll('.delivery-card').forEach(card => {
                card.classList.remove('selected');
            });

            cardElement.classList.add('selected');

            const dispatchDate = cardElement.getAttribute('data-dispatch-date') || '';
            const truckNumber = cardElement.getAttribute('data-truck-number') || '';
            const driverId = cardElement.getAttribute('data-driver-id') || '';
            const deliveryId = cardElement.getAttribute('data-delivery-id') || '';

            const deliveryIdField = document.getElementById('delivery_id');
            const directionIdField = document.getElementById('direction_id');

            if (deliveryIdField) {
                deliveryIdField.value = deliveryId || directionId;
            }
            if (directionIdField) {
                directionIdField.value = directionId;
            }

            const dispatchDateField = document.getElementById('dispatch_date_edit');
            if (dispatchDateField && dispatchDate) {
                dispatchDateField.value = dispatchDate;
            }

            const truckNumberField = document.getElementById('truck_number_edit');
            if (truckNumberField && truckNumber) {
                truckNumberField.value = truckNumber;
            }

            const truckDriverField = document.getElementById('truck_driver_edit');
            if (truckDriverField && driverId) {
                truckDriverField.value = driverId;
            }

            const confirmationDiv = document.createElement('div');
            confirmationDiv.className = 'mt-2 p-2 bg-green-100 border border-green-300 rounded text-sm text-green-700 delivery-assignment-confirmation';
            confirmationDiv.innerHTML = '✅ Delivery, dispatch date, truck, and driver updated! Click "Save Changes" to apply.';

            const existingConfirmation = document.querySelector('.delivery-assignment-confirmation');
            if (existingConfirmation) {
                existingConfirmation.remove();
            }

            cardElement.parentNode.appendChild(confirmationDiv);

            setTimeout(() => {
                if (confirmationDiv.parentNode) {
                    confirmationDiv.remove();
                }
            }, 3000);
        };

        if (typeof window.handleDeliveryClick === 'undefined') {
            window.handleDeliveryClick = function(cardElement, directionId) {
                if (typeof window.selectDeliveryCard === 'function') {
                    window.selectDeliveryCard(cardElement, directionId);
                } else {
                    console.error('selectDeliveryCard function not found');
                }
            };
        }

        const currentDeliveryId = document.getElementById('current_delivery_id')?.value;
        if (currentDeliveryId) {
            setTimeout(() => {
                const deliveryCards = document.querySelectorAll('.delivery-card');
                deliveryCards.forEach(card => {
                    const cardId = card.getAttribute('data-index');
                    if (cardId === currentDeliveryId && typeof window.selectDeliveryCard === 'function') {
                        window.selectDeliveryCard(card, cardId);
                    }
                });
            }, 500);
        }

        setTimeout(() => {
            const deliveryCards = document.querySelectorAll('.delivery-card');
            deliveryCards.forEach(card => {
                if (!card.hasAttribute('onclick')) {
                    card.addEventListener('click', function(e) {
                        e.preventDefault();
                        const directionId = this.getAttribute('data-index') ||
                            this.getAttribute('data-delivery-id') ||
                            this.getAttribute('data-direction-id');
                        if (directionId && typeof window.selectDeliveryCard === 'function') {
                            window.selectDeliveryCard(this, directionId);
                        }
                    });
                }
            });
        }, 100);
    })();

    // Remove legacy manipulator handler that overwrote rate; handled in weight.php now
});

// Global spinner management system
var SpinnerManager = {
    // Store active spinners to prevent duplicates
    activeSpinners: new Set(),

    // CSS for the spinner (injected once)
    injectStyles: function () {
        if (!document.getElementById('spinner-styles')) {
            var style = document.createElement('style');
            style.id = 'spinner-styles';
            style.textContent = `
                .input-spinner {
                    position: relative;
                }
                .input-spinner::after {
                    content: '';
                    position: absolute;
                    right: 8px;
                    top: 50%;
                    transform: translateY(-50%);
                    width: 16px;
                    height: 16px;
                    border: 2px solid rgba(0,0,0,0.1);
                    border-radius: 50%;
                    border-top-color: #3498db;
                    animation: spin 1s linear infinite;
                }
                @keyframes spin {
                    to { transform: translateY(-50%) rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
        }
    },

    // Show spinner on specific input
    show: function (inputElement) {
        this.injectStyles();
        if (!this.activeSpinners.has(inputElement)) {
            inputElement.classList.add('input-spinner');
            this.activeSpinners.add(inputElement);
        }
    },

    // Hide spinner from specific input
    hide: function (inputElement) {
        if (this.activeSpinners.has(inputElement)) {
            inputElement.classList.remove('input-spinner');
            this.activeSpinners.delete(inputElement);
        }
    },

    // Hide all spinners
    hideAll: function () {
        var self = this;
        this.activeSpinners.forEach(function(input) {
            input.classList.remove('input-spinner');
        });
        this.activeSpinners.clear();
    }
};

// Customer Dashboard Tab Functionality
function switchCustomerTab(tabName) {
    // Hide all customer tab contents only
    var customerTabContents = document.querySelectorAll('.customer-tabs .tab-content');
    customerTabContents.forEach(function(content) {
        content.style.display = 'none';
    });

    // Remove active class from all customer tab buttons only
    var customerTabButtons = document.querySelectorAll('.customer-tabs .tab-btn');
    customerTabButtons.forEach(function(btn) {
        btn.classList.remove('active');
        btn.style.background = 'transparent';
        btn.style.color = '#6b7280';
    });

    // Show selected tab content
    var selectedContent = document.getElementById(tabName + '-content');
    if (selectedContent) {
        selectedContent.style.display = 'block';
    }

    // Add active class to selected tab button
    var selectedButton = document.getElementById(tabName + '-tab');
    if (selectedButton) {
        selectedButton.classList.add('active');
        selectedButton.style.background = 'white';
        selectedButton.style.color = '#374151';
    }
}

// Initialize customer dashboard tabs
document.addEventListener('DOMContentLoaded', function() {
    // Customer dashboard tab functionality
    var customerTabButtons = document.querySelectorAll('.customer-tabs .tab-btn');
    customerTabButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tabName = this.id.replace('-tab', '');
            switchCustomerTab(tabName);
        });
    });

    // Handle inline customer form submission
    var inlineCustomerForm = document.getElementById('inlineCustomerForm');
    if (inlineCustomerForm) {
        inlineCustomerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var submitBtn = document.getElementById('saveCustomerBtn');
            var messagesDiv = document.getElementById('customerFormMessages');
            if (!messagesDiv) {
                // Create a messages container if it doesn't exist
                messagesDiv = document.createElement('div');
                messagesDiv.id = 'customerFormMessages';
                messagesDiv.style.marginBottom = '12px';
                // Insert before the form
                inlineCustomerForm.parentNode.insertBefore(messagesDiv, inlineCustomerForm);
            }
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';
            
            // Collect form data
            var formData = new FormData(this);
            formData.append('action', 'save_customer_ajax');
            formData.append('nonce', customerAjax.nonce);
            var safeVal = function(id) {
                var el = document.getElementById(id);
                return el ? el.value : '';
            };
            formData.append('name', safeVal('name'));
            formData.append('surname', safeVal('surname'));
            formData.append('cell', safeVal('cell'));
            formData.append('email_address', safeVal('email_address'));
            formData.append('address', safeVal('address'));
            var cn = (safeVal('company_name') || '').trim();
            formData.append('company_name', cn !== '' ? cn : 'Individual');
            formData.append('country_id', safeVal('country_id'));
            formData.append('city_id', safeVal('city_id'));
            formData.append('vat_number', safeVal('vat_number'));
            
            // Debug: Log form data
            console.log('Submitting customer form with data:', Object.fromEntries(formData));
            
            // Submit via AJAX
            fetch(ajaxurl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    // Show success message
                    messagesDiv.innerHTML = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">' + data.data.message + '</div>';
                    
                    // Reset form
                    inlineCustomerForm.reset();
                    
                    // Redirect to the manage-customers tab after 1.5 seconds
                    setTimeout(function() {
                        messagesDiv.innerHTML = '';
                        // Redirect to the manage-customers tab with a refresh parameter
                        var currentUrl = new URL(window.location);
                        currentUrl.searchParams.set('tab', 'manage-customers');
                        currentUrl.searchParams.set('customer_added', '1');
                        window.location.href = currentUrl.toString();
                    }, 1500);
                } else {
                    // Show error message
                    messagesDiv.innerHTML = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">Error: ' + (data.data || 'Unknown error occurred') + '</div>';
                }
            })
            .catch(function(error) {
                messagesDiv.innerHTML = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">Network error: ' + error.message + '</div>';
            })
            .finally(function() {
                // Reset button state
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Customer';
            });
        });
    }

    // Handle country change for city loading
    var countrySelect = document.getElementById('country_id');
    var citySelect = document.getElementById('city_id');
    
    if (countrySelect && citySelect) {
        countrySelect.addEventListener('change', function() {
            var countryId = this.value;
            
            // Clear city options
            citySelect.innerHTML = '<option value="">Select City</option>';
            
            if (countryId) {
                // Load cities for selected country
                fetch(ajaxurl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'get_cities_by_country',
                        country_id: countryId,
                        nonce: customerAjax.nonce
                    })
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        data.data.forEach(function(city) {
                            var option = document.createElement('option');
                            option.value = city.id;
                            option.textContent = city.city_name;
                            citySelect.appendChild(option);
                        });
                    }
                })
                .catch(function(error) {
                    console.error('Error loading cities:', error);
                });
            }
        });
    }
    
    // Set default active tab to manage-customers
    switchCustomerTab('manage-customers');
});

// ✅ ADD ERROR HANDLING FUNCTION
function showRateFetchError(message) {
    // Prefer the global .displayHere strip when present (Mass component)
    try {
        var displayHere = document.getElementById('mass-display-here');
        if (displayHere) {
            var base = 'display-here text-sm mt-2 p-2 border rounded';
            // Match KIT_Commons::displayHere() skins
            var skin = 'text-red-700 bg-red-50 border-red-200';
            displayHere.className = base + ' ' + skin;
            displayHere.textContent = message || '';
            if (!message) {
                displayHere.setAttribute('hidden', 'hidden');
                return;
            }
            displayHere.removeAttribute('hidden');

            // Auto-hide after 5 seconds (same behavior as legacy rate-fetch-error)
            var hideAt = Date.now() + 5000;
            displayHere.dataset.hideAt = String(hideAt);
            setTimeout(function () {
                if (displayHere.dataset.hideAt === String(hideAt)) {
                    displayHere.textContent = '';
                    displayHere.setAttribute('hidden', 'hidden');
                }
            }, 5000);
            return;
        }
    } catch (e) {
        // Fall back to legacy inline error div
    }

    var errorDiv = jQuery('#rate-fetch-error');
    if (errorDiv.length === 0) {
        errorDiv = jQuery('<div id="rate-fetch-error" class="text-red-600 text-sm mt-2"></div>');
        jQuery('#mass_rate').parent().append(errorDiv);
    }
    errorDiv.text(message);
    
    // Auto-hide after 5 seconds
    setTimeout(function() {
        errorDiv.fadeOut(500, function() {
            jQuery(this).remove();
        });
    }, 5000);
}

// Bulk Management functionality for KIT Unified Table
function initBulkManagement(tableId, bulkNonce) {
    if (!tableId) {
        console.warn('initBulkManagement: tableId is required');
        return;
    }

    const table = document.getElementById(tableId);
    if (!table) {
        console.warn('initBulkManagement: Table not found:', tableId);
        return;
    }

    // Get elements
    const bulkSelectAll = document.getElementById('bulk-select-all-' + tableId);
    const bulkActionsBar = document.getElementById('bulk-actions-bar-' + tableId);
    const bulkSelectedCount = document.getElementById('bulk-selected-count-' + tableId);
    const bulkClearBtn = document.getElementById('bulk-clear-selection-' + tableId);
    const bulkActionOk = document.getElementById('bulk-action-ok-' + tableId);
    const tableToolbar = document.getElementById('kit-table-toolbar-' + tableId);

    // Function to get checkboxes (will be called dynamically)
    const getBulkRowCheckboxes = function() {
        return table.querySelectorAll('.bulk-row-checkbox');
    };

    const bulkActionSelect = document.getElementById('bulk-action-select-' + tableId);

    // Function to get bulk action buttons (legacy) and the action select
    const getBulkActionButtons = function() {
        if (!bulkActionsBar) return [];
        const byDataAttr = bulkActionsBar.querySelectorAll('[data-bulk-action]');
        if (byDataAttr.length > 0) return byDataAttr;
        return bulkActionsBar.querySelectorAll('button[id^="bulk-delete-"], button[id^="bulk-export-"], button[id^="bulk-packing-"], button[id^="bulk-active-"], button[id^="bulk-inactive-"]');
    };

    const getSelectedRowIds = function() {
        const bulkRowCheckboxes = getBulkRowCheckboxes();
        return Array.from(bulkRowCheckboxes).filter(function(cb) {
            const row = cb.closest('tr');
            return cb.checked && row && row.style.display !== 'none' && !row.dataset.groupRow;
        }).map(function(cb) {
            return cb.value;
        }).filter(function(id) {
            return id;
        });
    };

    // Debug: Log if elements are found
    if (!bulkActionsBar) {
        console.warn('Bulk actions bar not found:', 'bulk-actions-bar-' + tableId);
    }
    const initialCheckboxes = getBulkRowCheckboxes();
    if (initialCheckboxes.length === 0) {
        console.warn('No bulk row checkboxes found initially');
    } else {
        console.log('Found', initialCheckboxes.length, 'bulk row checkboxes');
    }

    const updateBulkUI = function() {
        // Get fresh checkboxes list in case DOM changed
        const bulkRowCheckboxes = getBulkRowCheckboxes();
        const bulkActionButtonsList = getBulkActionButtons();

        const visibleCheckboxes = Array.from(bulkRowCheckboxes).filter(function(cb) {
            const row = cb.closest('tr');
            return row && row.style.display !== 'none' && !row.dataset.groupRow;
        });
        const checkedBoxes = visibleCheckboxes.filter(function(cb) {
            return cb.checked;
        });
        const count = checkedBoxes.length;

        visibleCheckboxes.forEach(function(cb) {
            const row = cb.closest('tr');
            if (row) {
                row.classList.toggle('kit-row-selected', !!cb.checked);
            }
        });

        console.log('updateBulkUI called - count:', count, 'bulkActionsBar:', bulkActionsBar, 'checkboxes found:', bulkRowCheckboxes.length);

        // Update count
        if (bulkSelectedCount) {
            bulkSelectedCount.textContent = count + ' selected';
        }

        if (tableToolbar) {
            tableToolbar.classList.toggle('is-selecting', count > 0);
        }
        if (bulkActionsBar) {
            if (count > 0) {
                bulkActionsBar.hidden = false;
                bulkActionsBar.style.display = '';
                bulkActionsBar.classList.remove('hidden');
            } else {
                bulkActionsBar.hidden = true;
                bulkActionsBar.classList.add('hidden');
            }
        }

        // Enable/disable bulk action buttons / select when any row is selected
        bulkActionButtonsList.forEach(function(btn) {
            const shouldDisable = count === 0;
            btn.disabled = shouldDisable;
            if (shouldDisable) {
                btn.setAttribute('disabled', 'disabled');
                btn.classList.add('opacity-50', 'cursor-not-allowed', 'pointer-events-none');
            } else {
                btn.removeAttribute('disabled');
                btn.classList.remove('opacity-50', 'cursor-not-allowed', 'pointer-events-none');
            }
        });
        if (bulkActionSelect) {
            bulkActionSelect.disabled = count === 0;
            if (count === 0) {
                bulkActionSelect.value = '';
                bulkActionSelect.setAttribute('disabled', 'disabled');
            } else {
                bulkActionSelect.removeAttribute('disabled');
            }
        }
        if (bulkActionOk) {
            const canConfirm = count > 0 && bulkActionSelect && bulkActionSelect.value !== '';
            bulkActionOk.disabled = !canConfirm;
            if (canConfirm) {
                bulkActionOk.removeAttribute('disabled');
            } else {
                bulkActionOk.setAttribute('disabled', 'disabled');
            }
        }

        // Update select all checkbox state
        if (bulkSelectAll && visibleCheckboxes.length > 0) {
            if (count === 0) {
                bulkSelectAll.checked = false;
                bulkSelectAll.indeterminate = false;
            } else if (count === visibleCheckboxes.length) {
                bulkSelectAll.checked = true;
                bulkSelectAll.indeterminate = false;
            } else {
                bulkSelectAll.checked = false;
                bulkSelectAll.indeterminate = true;
            }
        }
    };

    // Select all checkbox
    if (bulkSelectAll) {
        bulkSelectAll.addEventListener('change', function() {
            console.log('Select all checkbox changed:', this.checked);
            const checked = this.checked;
            const bulkRowCheckboxes = getBulkRowCheckboxes();
            bulkRowCheckboxes.forEach(function(checkbox) {
                const row = checkbox.closest('tr');
                if (row && row.style.display !== 'none' && !row.dataset.groupRow) {
                    checkbox.checked = checked;
                }
            });
            updateBulkUI();
        });
    }

    // Individual row checkboxes - use event delegation for dynamic checkboxes
    // This works even if checkboxes are added/removed dynamically
    if (table) {
        table.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('bulk-row-checkbox')) {
                console.log('Row checkbox changed:', e.target.checked, e.target.value);
                updateBulkUI();
            }
        });

        // Also handle direct checkbox click events in case change doesn't fire
        table.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('bulk-row-checkbox')) {
                // Small delay to let the checkbox state update
                setTimeout(function() {
                    updateBulkUI();
                }, 10);
            }
        });
    }

    // Clear selection button
    if (bulkClearBtn) {
        bulkClearBtn.addEventListener('click', function() {
            const bulkRowCheckboxes = getBulkRowCheckboxes();
            bulkRowCheckboxes.forEach(function(cb) {
                cb.checked = false;
            });
            if (bulkSelectAll) {
                bulkSelectAll.checked = false;
                bulkSelectAll.indeterminate = false;
            }
            updateBulkUI();
        });
    }

    const runSelectedBulkAction = function(action) {
        if (!action) {
            return;
        }
        const selectedIds = getSelectedRowIds();
        if (selectedIds.length === 0) {
            alert('Please select at least one item.');
            return;
        }
        handleBulkAction(action, selectedIds, bulkNonce);
        window.dispatchEvent(new CustomEvent('kit-bulk-action', {
            detail: { action: action, selectedIds: selectedIds, tableId: tableId }
        }));
    };

    if (bulkActionSelect) {
        bulkActionSelect.addEventListener('change', function() {
            updateBulkUI();
        });
    }
    if (bulkActionOk) {
        bulkActionOk.addEventListener('click', function() {
            if (this.disabled || !bulkActionSelect) {
                return;
            }
            const action = bulkActionSelect.value;
            if (!action) {
                alert('Choose an action first.');
                return;
            }
            runSelectedBulkAction(action);
        });
    }

    // Legacy bulk action buttons - use event delegation
    if (bulkActionsBar) {
        bulkActionsBar.addEventListener('click', function(e) {
            const btn = e.target.closest('[data-bulk-action]');
            if (!btn || btn.disabled) return;
            runSelectedBulkAction(btn.dataset.bulkAction);
        });
    }

    // Default bulk action handler
    function appendConfirmedBy(form) {
        const confirmed = document.createElement('input');
        confirmed.type = 'hidden';
        confirmed.name = 'bulk_confirmed';
        confirmed.value = '1';
        form.appendChild(confirmed);
    }

    function logBulkConfirmation(action, selectedIds, nonce) {
        if (typeof myPluginAjax === 'undefined' || !myPluginAjax.ajax_url) {
            return;
        }
        const body = new FormData();
        body.append('action', 'kit_log_bulk_action');
        body.append('nonce', nonce || '');
        body.append('bulk_action', action);
        body.append('bulk_ids', selectedIds.join(','));
        body.append('entity', 'waybill');
        fetch(myPluginAjax.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' });
    }

    function handleBulkAction(action, selectedIds, nonce) {
        const ids = selectedIds.join(',');

        switch(action) {
            case 'delete':
                if (!confirm('Delete ' + selectedIds.length + ' selected item(s)? This cannot be undone.')) {
                    return;
                }
                const deleteForm = document.createElement('form');
                deleteForm.method = 'POST';
                deleteForm.action = window.location.href;

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'bulk_action';
                actionInput.value = 'delete';
                deleteForm.appendChild(actionInput);

                const idsInput = document.createElement('input');
                idsInput.type = 'hidden';
                idsInput.name = 'bulk_ids';
                idsInput.value = ids;
                deleteForm.appendChild(idsInput);

                if (nonce) {
                    const nonceInput = document.createElement('input');
                    nonceInput.type = 'hidden';
                    nonceInput.name = 'bulk_nonce';
                    nonceInput.value = nonce;
                    deleteForm.appendChild(nonceInput);
                }

                appendConfirmedBy(deleteForm);
                document.body.appendChild(deleteForm);
                deleteForm.submit();
                break;

            case 'export':
                logBulkConfirmation('export', selectedIds, nonce);
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('export_selected', ids);
                // Ensure page parameter is preserved (should be 08600-waybill-manage)
                if (!currentUrl.searchParams.has('page')) {
                    currentUrl.searchParams.set('page', '08600-waybill-manage');
                }
                window.open(currentUrl.toString(), '_blank');
                break;

            case 'move_to_warehouse': {
                const warehouseForm = document.createElement('form');
                warehouseForm.method = 'POST';
                warehouseForm.action = window.location.href;

                const warehouseActionInput = document.createElement('input');
                warehouseActionInput.type = 'hidden';
                warehouseActionInput.name = 'bulk_action';
                warehouseActionInput.value = 'move_to_warehouse';
                warehouseForm.appendChild(warehouseActionInput);

                const warehouseIdsInput = document.createElement('input');
                warehouseIdsInput.type = 'hidden';
                warehouseIdsInput.name = 'bulk_ids';
                warehouseIdsInput.value = ids;
                warehouseForm.appendChild(warehouseIdsInput);

                if (nonce) {
                    const warehouseNonceInput = document.createElement('input');
                    warehouseNonceInput.type = 'hidden';
                    warehouseNonceInput.name = 'bulk_nonce';
                    warehouseNonceInput.value = nonce;
                    warehouseForm.appendChild(warehouseNonceInput);
                }

                appendConfirmedBy(warehouseForm);
                document.body.appendChild(warehouseForm);
                warehouseForm.submit();
                break;
            }

            case 'packing_list': {
                logBulkConfirmation('packing_list', selectedIds, nonce);
                const printBase = window.kitWaybillListPrintUrl || '';
                const printNonce = window.kitWaybillListPrintNonce || '';
                if (!printBase || !printNonce) {
                    alert('Print URL is not configured for this table.');
                    return;
                }
                let packingUrl = printBase;
                try {
                    const pu = new URL(printBase, window.location.href);
                    pu.searchParams.set('list_type', 'packing');
                    pu.searchParams.set('ids', ids);
                    pu.searchParams.set('nonce', printNonce);
                    packingUrl = pu.toString();
                } catch (e2) {
                    packingUrl = printBase + (printBase.indexOf('?') === -1 ? '?' : '&')
                        + 'list_type=packing&ids=' + encodeURIComponent(ids)
                        + '&nonce=' + encodeURIComponent(printNonce);
                }
                window.open(packingUrl, '_blank');
                break;
            }

            case 'status_active':
            case 'status_inactive':
                const status = action === 'status_active' ? '1' : '0';
                const statusForm = document.createElement('form');
                statusForm.method = 'POST';
                statusForm.action = window.location.href;

                const statusActionInput = document.createElement('input');
                statusActionInput.type = 'hidden';
                statusActionInput.name = 'bulk_action';
                statusActionInput.value = 'update_status';
                statusForm.appendChild(statusActionInput);

                const statusIdsInput = document.createElement('input');
                statusIdsInput.type = 'hidden';
                statusIdsInput.name = 'bulk_ids';
                statusIdsInput.value = ids;
                statusForm.appendChild(statusIdsInput);

                const statusValueInput = document.createElement('input');
                statusValueInput.type = 'hidden';
                statusValueInput.name = 'status_value';
                statusValueInput.value = status;
                statusForm.appendChild(statusValueInput);

                if (nonce) {
                    const statusNonceInput = document.createElement('input');
                    statusNonceInput.type = 'hidden';
                    statusNonceInput.name = 'bulk_nonce';
                    statusNonceInput.value = nonce;
                    statusForm.appendChild(statusNonceInput);
                }

                document.body.appendChild(statusForm);
                statusForm.submit();
                break;
        }
    }

    // Store updateBulkUI function for external access if needed
    if (typeof window.kitBulkManagement === 'undefined') {
        window.kitBulkManagement = {};
    }
    window.kitBulkManagement[tableId] = {
        updateUI: updateBulkUI,
        getCheckboxes: getBulkRowCheckboxes,
        getActionButtons: getBulkActionButtons
    };

    // Initial update
    updateBulkUI();
}

// ============================================================================
// Live header Amount updater (edit waybill)
// Mirrors KIT_Bulletproof_Calculator: primary(mass|volume|auto) + misc
// + SAD500? + SADC? + (VAT on parcels OR international fee)
// ============================================================================
(function initWaybillHeaderTotalLiveUpdate() {
    function parseAmount(value) {
        if (value === undefined || value === null) return 0;
        const n = parseFloat(String(value).replace(/\s/g, '').replace(/,/g, '.'));
        return Number.isFinite(n) ? n : 0;
    }

    function getChargeConfig() {
        const fromData = (typeof EditWaybillData !== 'undefined' && EditWaybillData.charges)
            ? EditWaybillData.charges
            : {};
        const host = document.querySelector('[data-kit-charge-config]');
        const ds = host ? host.dataset : {};
        return {
            sad500: parseAmount(fromData.sad500 != null ? fromData.sad500 : (ds.sad500 || 350)),
            sadc: parseAmount(fromData.sadc != null ? fromData.sadc : (ds.sadc || 1000)),
            vatPercent: parseAmount(fromData.vat_percent != null ? fromData.vat_percent : (ds.vatPercent || 10)),
            internationalPrice: parseAmount(
                fromData.international_price != null
                    ? fromData.international_price
                    : (ds.internationalPrice || 0)
            ),
        };
    }

    function isFlagOn(checkboxId, hiddenInputId) {
        const checkbox = document.getElementById(checkboxId);
        if (checkbox && typeof checkbox.checked === 'boolean' && checkbox.type === 'checkbox') {
            return !!checkbox.checked;
        }
        const hidden = document.getElementById(hiddenInputId);
        if (hidden) {
            return String(hidden.value) === '1';
        }
        return false;
    }

    function sumDynamicItems(containerId, priceName, qtyName) {
        const container = document.getElementById(containerId);
        if (!container) return 0;
        let total = 0;
        container.querySelectorAll('.dynamic-item').forEach(function (row) {
            if (row.classList.contains('override-adjustment-row')) return;
            const priceInput = row.querySelector('input[name*="[' + priceName + ']"]');
            const qtyInput = row.querySelector('input[name*="[' + qtyName + ']"]');
            const price = parseAmount(priceInput ? priceInput.value : 0);
            const qty = parseAmount(qtyInput ? qtyInput.value : 0);
            total += price * qty;
        });
        return total;
    }

    function primaryCharge(massCharge, volumeCharge, basis) {
        if (basis === 'mass' || basis === 'weight') return massCharge;
        if (basis === 'volume') return volumeCharge;
        return Math.max(massCharge, volumeCharge);
    }

    function calculateLiveWaybillTotal() {
        const config = getChargeConfig();
        const massCharge = parseAmount(document.getElementById('mass_charge')?.value);
        const volumeCharge = parseAmount(document.getElementById('volume_charge')?.value);
        const selectedBasis = document.querySelector('input[name="charge_basis"]:checked')?.value || 'auto';
        const primary = primaryCharge(massCharge, volumeCharge, selectedBasis);

        const parcelsTotal = sumDynamicItems('custom-waybill-items', 'unit_price', 'quantity');
        const miscTotal = sumDynamicItems('misc-items', 'misc_price', 'misc_quantity');

        let includeVat = isFlagOn('vat_include', 'vat_include_input');
        let includeSadc = isFlagOn('sadc_certificate', 'include_sadc_input');
        const includeSad500 = isFlagOn('include_sad500', 'include_sad500_input');

        // VAT and SADC are mutually exclusive (prefer VAT when both somehow on)
        if (includeVat) includeSadc = false;

        let total = primary + miscTotal;
        if (includeSad500) total += config.sad500;
        if (includeSadc) total += config.sadc;

        if (includeVat) {
            const vatRate = config.vatPercent > 1 ? (config.vatPercent / 100) : config.vatPercent;
            total += parcelsTotal * vatRate;
        } else {
            total += config.internationalPrice;
        }

        return total;
    }

    function updateWaybillHeaderTotal() {
        const waybilltotalMockup = document.getElementById('waybilltotalMockup');
        if (!waybilltotalMockup) return;

        // Respect total override UI: when override is enabled the mockup is hidden
        const enableOverride = document.getElementById('enable_total_override');
        if (enableOverride && enableOverride.checked) return;

        const total = calculateLiveWaybillTotal();
        waybilltotalMockup.textContent = total.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    window.kitUpdateWaybillHeaderTotal = updateWaybillHeaderTotal;
    window.kitCalculateLiveWaybillTotal = calculateLiveWaybillTotal;

    document.addEventListener('DOMContentLoaded', function () {
        const waybilltotalMockup = document.getElementById('waybilltotalMockup');
        if (!waybilltotalMockup) return;

        const scheduleUpdate = (function () {
            let raf = null;
            return function () {
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(function () {
                    raf = null;
                    updateWaybillHeaderTotal();
                });
            };
        })();

        document.querySelectorAll('.charge-basis-radio').forEach(function (radio) {
            radio.addEventListener('change', scheduleUpdate);
        });

        ['mass_charge', 'volume_charge', 'mass_rate', 'total_mass_kg', 'total_volume', 'volume_rate'].forEach(function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('input', scheduleUpdate);
            el.addEventListener('change', scheduleUpdate);
        });

        ['vat_include', 'sadc_certificate', 'include_sad500'].forEach(function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', scheduleUpdate);
            el.addEventListener('click', scheduleUpdate);
        });

        ['vat_include_input', 'include_sadc_input', 'include_sad500_input'].forEach(function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', scheduleUpdate);
            // Hidden inputs may be updated programmatically without events
            try {
                const descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
                if (descriptor && descriptor.set) {
                    Object.defineProperty(el, 'value', {
                        configurable: true,
                        enumerable: true,
                        get: function () { return descriptor.get.call(this); },
                        set: function (v) {
                            descriptor.set.call(this, v);
                            scheduleUpdate();
                        }
                    });
                }
            } catch (e) { /* non-fatal */ }
        });

        // Parcels + misc row edits / add / remove
        ['custom-waybill-items', 'misc-items'].forEach(function (containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.addEventListener('input', scheduleUpdate);
            container.addEventListener('change', scheduleUpdate);
            container.addEventListener('click', function (e) {
                if (e.target.closest('.remove-item')) scheduleUpdate();
            });
            try {
                const observer = new MutationObserver(scheduleUpdate);
                observer.observe(container, { childList: true, subtree: true });
            } catch (e) { /* non-fatal */ }
        });

        document.addEventListener('kit:waybill-total-dirty', scheduleUpdate);

        // jQuery .val() updates (mass/volume AJAX) often skip native input events
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on(
                'input change',
                '#mass_charge, #volume_charge, .kit-mass-charge, .kit-volume-charge',
                scheduleUpdate
            );
        }

        scheduleUpdate();
    });
})();

// ============================================================================
// Total Override Handler (from totalOverride.php)
// Allows superadmins to override calculated totals
// ============================================================================
(function initTotalOverride() {
    document.addEventListener('DOMContentLoaded', function() {
        const enableOverrideCheckbox = document.getElementById('enable_total_override');
        
        // Only run if override checkbox exists (total override component check)
        if (!enableOverrideCheckbox) return;
        
        const overrideInputContainer = document.getElementById('total-override-input');
        const calculatedTotalInput = document.getElementById('calculated_total');
        const overrideTotalInput = document.getElementById('override_total');
        
        // Calculate current total from form fields (excluding override adjustment row)
        function calculateCurrentTotal() {
            let total = 0;
            
            const massCharge = parseFloat(document.getElementById('mass_charge')?.value || '0');
            total += massCharge;
            
            const volumeCharge = parseFloat(document.getElementById('volume_charge')?.value || '0');
            total += volumeCharge;
            
            const waybillSubtotal = parseFloat(document.getElementById('waybill-subtotal')?.textContent?.replace(/[^\d.-]/g, '') || '0');
            total += waybillSubtotal;
            
            // Sum misc items by iterating rows and excluding the override row
            let miscTotal = 0;
            document.querySelectorAll('#misc-items .dynamic-item').forEach(function(row){
                if (row.classList.contains('override-adjustment-row')) return;
                const priceInput = row.querySelector('input[name*="[misc_price]"]');
                const qtyInput = row.querySelector('input[name*="[misc_quantity]"]');
                const price = parseFloat(priceInput ? priceInput.value : '0') || 0;
                const qty = parseFloat(qtyInput ? qtyInput.value : '0') || 0;
                miscTotal += price * qty;
            });
            total += miscTotal;
            
            return total;
        }

        function detectChargeBasis() {
            const selector = document.getElementById('override_basis');
            const choice = selector ? selector.value : 'auto';
            if (choice === 'mass' || choice === 'volume') return choice;
            const mass = parseFloat(document.getElementById('mass_charge')?.value || '0');
            const volume = parseFloat(document.getElementById('volume_charge')?.value || '0');
            if (mass > 0 && volume <= 0) return 'mass';
            if (volume > 0 && mass <= 0) return 'volume';
            // default to mass when ambiguous
            return 'mass';
        }

        function clearError() {
            const err = document.getElementById('override_error');
            if (err) { err.classList.add('hidden'); err.textContent = ''; }
        }
        function showError(msg) {
            const err = document.getElementById('override_error');
            if (err) { err.textContent = msg; err.classList.remove('hidden'); }
        }

        function parseNum(val){
            if (val === undefined || val === null) return 0;
            const s = String(val).replace(/\s/g,'').replace(',', '.');
            const n = parseFloat(s);
            return Number.isFinite(n) ? n : 0;
        }

        function restoreMassToBase() {
            const baseRateField = document.getElementById('base_rate');
            const totalMassField = document.getElementById('total_mass_kg');
            const massRateField = document.getElementById('mass_rate');
            const massChargeField = document.getElementById('mass_charge');
            const massBaseDisplay = document.getElementById('mass_base_display');
            const baseRate = parseFloat(baseRateField?.value || '0');
            const totalMass = parseFloat(totalMassField?.value || '0');
            if (baseRate > 0) {
                if (massRateField) massRateField.value = baseRate.toFixed(2);
                if (massBaseDisplay) massBaseDisplay.textContent = baseRate.toFixed(2);
                if (massChargeField) massChargeField.value = ((Number.isFinite(totalMass) ? totalMass : 0) * baseRate).toFixed(2);
            }
            // Clear manipulator numeric value if present
            const manipInput = document.getElementById('mass_charge_manipulator');
            if (manipInput) manipInput.value = '0';
        }

        function clearVolumeManipulator() {
            const volManipInput = document.getElementById('custom_volume_rate_per_m3');
            if (volManipInput) volManipInput.value = '';
            // Do not force-fetch new base here; volume section will recalc on next input/blur
        }

        // Helper: disable/enable price manipulators when override toggled
        function setManipulatorsDisabled(disabled) {
            const massManip = document.getElementById('enable_price_manipulator');
            const massContainer = document.getElementById('price_manipulator_input_container');
            if (massManip) {
                if (disabled) {
                    massManip.checked = false;
                    massManip.setAttribute('disabled', 'disabled');
                    if (massContainer) massContainer.style.display = 'none';
                    restoreMassToBase();
                } else {
                    massManip.removeAttribute('disabled');
                }
            }
            const volManip = document.getElementById('enable_volume_price_manipulator');
            const volContainer = document.getElementById('dimension_manipulator_input_container');
            if (volManip) {
                if (disabled) {
                    volManip.checked = false;
                    volManip.setAttribute('disabled', 'disabled');
                    if (volContainer) volContainer.style.display = 'none';
                    clearVolumeManipulator();
                } else {
                    volManip.removeAttribute('disabled');
                }
            }
        }

        // Apply override by back-calculating the rate for the chosen basis
        function applyOverrideToBasis() {
            if (!enableOverrideCheckbox || !enableOverrideCheckbox.checked) return;
            clearError();
            const desiredTotal = parseNum(overrideTotalInput?.value || '0');
            if (!Number.isFinite(desiredTotal) || desiredTotal <= 0) return;
            const basis = detectChargeBasis();
            const basisInput = document.getElementById('override_charge_basis');
            if (basisInput) basisInput.value = basis;
            
            if (basis === 'mass') {
                const massInput = document.getElementById('total_mass_kg');
                const massRateInput = document.getElementById('mass_rate');
                const massChargeInput = document.getElementById('mass_charge');
                const massBaseDisplay = document.getElementById('mass_base_display');
                const massVal = parseNum(massInput?.value || '0');
                if (!massVal || massVal <= 0) { showError('Enter Total Mass (Kg) to apply override using Mass'); return; }
                const newRate = desiredTotal / massVal;
                if (massRateInput) massRateInput.value = newRate.toFixed(2);
                if (massBaseDisplay) massBaseDisplay.textContent = newRate.toFixed(2);
                if (massChargeInput) massChargeInput.value = desiredTotal.toFixed(2);
            } else {
                const volInput = document.getElementById('total_volume');
                const volChargeInput = document.getElementById('volume_charge');
                const volDisplay = document.getElementById('volume_charge_display');
                const volVal = parseNum(volInput?.value || '0');
                if (!volVal || volVal <= 0) { showError('Enter Total Volume (m³) to apply override using Volume'); return; }
                const newRate = desiredTotal / volVal;
                if (volDisplay) volDisplay.textContent = newRate.toFixed(2);
                if (volChargeInput) volChargeInput.value = desiredTotal.toFixed(2);
            }
        }
        
        // Update calculated total display (basis-specific)
        function updateCalculatedTotal() {
            if (enableOverrideCheckbox && enableOverrideCheckbox.checked) {
                const o = parseNum(overrideTotalInput?.value || '0');
                if (calculatedTotalInput) calculatedTotalInput.value = o.toFixed(2);
                return;
            }
            const basis = detectChargeBasis();
            let current = 0;
            if (basis === 'mass') {
                current = parseNum(document.getElementById('mass_charge')?.value || '0');
            } else if (basis === 'volume') {
                current = parseNum(document.getElementById('volume_charge')?.value || '0');
            }
            if (calculatedTotalInput) {
                calculatedTotalInput.value = current.toFixed(2);
            }
        }
        
        // Toggle override functionality (global function for inline onclick handlers)
        window.toggleTotalOverride = function(enabled) {
            if (enabled) {
                overrideInputContainer.classList.remove('hidden');
                setManipulatorsDisabled(true);
                updateCalculatedTotal();
                applyOverrideToBasis();
                
                // Hide the waybilltotalMockup when override is enabled
                const waybilltotalMockup = document.getElementById('waybilltotalMockup');
                if (waybilltotalMockup) {
                    waybilltotalMockup.style.display = 'none';
                }
                
                // Focus on override input
                setTimeout(() => {
                    if (overrideTotalInput) {
                        overrideTotalInput.focus();
                    }
                }, 100);
            } else {
                overrideInputContainer.classList.add('hidden');
                setManipulatorsDisabled(false);
                if (overrideTotalInput) {
                    overrideTotalInput.value = '';
                }
                const basisInput = document.getElementById('override_charge_basis');
                if (basisInput) basisInput.value = '';
                clearError();
                
                // Show the waybilltotalMockup when override is disabled
                const waybilltotalMockup = document.getElementById('waybilltotalMockup');
                if (waybilltotalMockup) {
                    waybilltotalMockup.style.display = '';
                }

                // Restore original calculated values from base rates
                const baseRateField = document.getElementById('base_rate');
                const totalMassField = document.getElementById('total_mass_kg');
                const massRateField = document.getElementById('mass_rate');
                const massChargeField = document.getElementById('mass_charge');
                const massBaseDisplay = document.getElementById('mass_base_display');

                const baseRate = parseFloat(baseRateField?.value || '0');
                const totalMass = parseFloat(totalMassField?.value || '0');
                if (baseRate > 0) {
                    if (massRateField) massRateField.value = baseRate.toFixed(2);
                    if (massBaseDisplay) massBaseDisplay.textContent = baseRate.toFixed(2);
                    if (massChargeField) {
                        const charge = (Number.isFinite(totalMass) ? totalMass : 0) * baseRate;
                        massChargeField.value = charge.toFixed(2);
                    }
                }
            }
        };

        // Recompute when user manually switches basis
        const overrideBasisSelect = document.getElementById('override_basis');
        if (overrideBasisSelect) {
            overrideBasisSelect.addEventListener('change', function(){
                if (enableOverrideCheckbox && enableOverrideCheckbox.checked) {
                    // Start from base before applying on the new basis
                    restoreMassToBase();
                    clearVolumeManipulator();
                    applyOverrideToBasis();
                    updateCalculatedTotal();
                }
            });
        }
        
        // Listen for changes to form fields that affect total calculation
        const totalAffectingFields = [
            'mass_charge',
            'volume_charge'
        ];
        
        totalAffectingFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('input', function() {
                    if (enableOverrideCheckbox && enableOverrideCheckbox.checked) {
                        updateCalculatedTotal();
                        applyOverrideToBasis();
                    }
                });
            }
        });
        
        // Listen for waybill and misc items changes
        const waybillContainer = document.getElementById('custom-waybill-items');
        const miscContainer = document.getElementById('misc-items');
        
        if (waybillContainer) {
            waybillContainer.addEventListener('input', function() {
                if (enableOverrideCheckbox && enableOverrideCheckbox.checked) {
                    setTimeout(function(){
                        updateCalculatedTotal();
                        applyOverrideToBasis();
                    }, 100); // allow other calculations to complete
                }
            });
        }
        
        if (miscContainer) {
            miscContainer.addEventListener('input', function() {
                if (enableOverrideCheckbox && enableOverrideCheckbox.checked) {
                    setTimeout(function(){
                        updateCalculatedTotal();
                        applyOverrideToBasis();
                    }, 100);
                }
            });
        }
        
        // Validation for override total
        if (overrideTotalInput) {
            overrideTotalInput.addEventListener('input', function() {
                const value = parseFloat(this.value);
                if (isNaN(value) || value < 0) {
                    this.setCustomValidity('Please enter a valid positive number');
                } else {
                    this.setCustomValidity('');
                }
                if (enableOverrideCheckbox && enableOverrideCheckbox.checked) {
                    updateCalculatedTotal();
                    applyOverrideToBasis();
                }
            });
            
            overrideTotalInput.addEventListener('blur', function() {
                if (this.value && !isNaN(parseFloat(this.value))) {
                    this.value = parseFloat(this.value).toFixed(2);
                    if (enableOverrideCheckbox && enableOverrideCheckbox.checked) {
                        applyOverrideToBasis();
                    }
                }
            });
        }
        
        // Initial calculation if override is already enabled
        if (enableOverrideCheckbox && enableOverrideCheckbox.checked) {
            setManipulatorsDisabled(true);
            updateCalculatedTotal();
            applyOverrideToBasis();
        }
    });
})();

/**
 * Auto-select recently created delivery when navigating to step 4
 * This function is called when clicking next-step button with data-target="step-4"
 */
function autoSelectRecentDelivery() {
    // Get recently created delivery ID from sessionStorage
    if (typeof Storage === 'undefined') {
        return;
    }
    
    const recentDeliveryId = sessionStorage.getItem('recentlyCreatedDeliveryId');
    if (!recentDeliveryId) {
        console.log('No recently created delivery ID found');
        return;
    }
    
    console.log('🎯 Auto-selecting recently created delivery:', recentDeliveryId);
    
    // Wait for delivery cards to be loaded
    const waitForCards = (attempts = 0) => {
        const maxAttempts = 20;
        const deliveryCards = document.querySelectorAll('.delivery-card');
        
        if (deliveryCards.length === 0 && attempts < maxAttempts) {
            setTimeout(() => waitForCards(attempts + 1), 200);
            return;
        }
        
        // Find the card matching the delivery ID
        let cardToSelect = null;
        let directionId = null;
        
        for (let card of deliveryCards) {
            const cardDeliveryId = card.getAttribute('data-delivery-id');
            const cardDirectionId = card.getAttribute('data-direction-id');
            const cardIndex = card.getAttribute('data-index');
            
            if (cardDeliveryId === recentDeliveryId || 
                cardDirectionId === recentDeliveryId || 
                cardIndex === recentDeliveryId) {
                cardToSelect = card;
                directionId = cardDirectionId || cardIndex || cardDeliveryId;
                console.log('✅ Found matching delivery card:', {
                    deliveryId: recentDeliveryId,
                    directionId: directionId,
                    card: card
                });
                break;
            }
        }
        
        if (cardToSelect && directionId) {
            // Scroll card into view
            cardToSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Select the card using selectDeliveryCard function
            setTimeout(() => {
                if (typeof selectDeliveryCard === 'function') {
                    selectDeliveryCard(cardToSelect, directionId, true); // true = skipDetailsDisplay
                    console.log('✅ Auto-selected delivery card via selectDeliveryCard');
                } else if (typeof window.selectDeliveryCard === 'function') {
                    window.selectDeliveryCard(cardToSelect, directionId, true);
                    console.log('✅ Auto-selected delivery card via window.selectDeliveryCard');
                } else {
                    // Fallback: manual selection
                    console.warn('⚠️ selectDeliveryCard not found, doing manual selection');
                    document.querySelectorAll('.delivery-card').forEach(c => c.classList.remove('selected'));
                    cardToSelect.classList.add('selected');
                    const directionIdField = document.getElementById('direction_id');
                    if (directionIdField) {
                        directionIdField.value = directionId;
                    }
                }
                
                // Clear the stored delivery ID after selection
                sessionStorage.removeItem('recentlyCreatedDeliveryId');
            }, 300);
        } else {
            console.warn('⚠️ Could not find delivery card with ID:', recentDeliveryId);
        }
    };
    
    waitForCards();
}

/**
 * View Waybill: email PDF to customer (admin AJAX + wp_mail).
 */
(function () {
    if (typeof jQuery === 'undefined') {
        return;
    }
    jQuery(function ($) {
        // Matches the server-rendered notices from KIT_Commons::renderActionNotices():
        // core notice markup, dismissed by the user rather than on a timer.
        function kitShowWaybillActionMessage(type, html) {
            var $root = $('#wpbody-content .wrap').first();
            if (!$root.length) {
                $root = $('#wpbody-content').first();
            }
            if (!$root.length) {
                window.alert($('<div>').html(html).text());
                return;
            }

            var classes = {
                success: 'notice-success',
                error: 'notice-error',
                info: 'notice-info'
            };

            $root.find('.kit-action-notice.is-js').remove();
            $('<div/>', {
                'class': 'notice ' + (classes[type] || classes.info) + ' is-dismissible kit-action-notice is-js',
                html: '<p>' + html + '</p>' +
                    '<button type="button" class="notice-dismiss">' +
                    '<span class="screen-reader-text">Dismiss this notice.</span></button>'
            }).prependTo($root);
        }

        $(document).on('click', '.kit-action-notice.is-js .notice-dismiss', function () {
            $(this).closest('.kit-action-notice').remove();
        });

        $(document).on('click', '.js-kit-email-waybill-pdf', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.data('kitEmailSending')) {
                return;
            }
            if (typeof myPluginAjax === 'undefined' || !myPluginAjax.ajax_url) {
                kitShowWaybillActionMessage('error', '<strong>Error!</strong> Email action is not available. Refresh the page and try again.');
                return;
            }
            var waybillNo = parseInt($btn.attr('data-waybill-no') || '0', 10);
            if (!waybillNo) {
                return;
            }
            var nonce = ($btn.attr('data-email-nonce') || '').trim();
            if (!nonce && typeof myPluginAjax !== 'undefined' && myPluginAjax.nonces && myPluginAjax.nonces.email_waybill_pdf) {
                nonce = myPluginAjax.nonces.email_waybill_pdf;
            }
            if (!nonce) {
                kitShowWaybillActionMessage('error', '<strong>Error!</strong> Security token missing. Refresh the page.');
                return;
            }
            $btn.data('kitEmailSending', true).prop('disabled', true).addClass('opacity-60 cursor-wait');
            $.post(myPluginAjax.ajax_url, {
                action: 'kit_email_waybill_pdf',
                nonce: nonce,
                waybill_no: waybillNo
            })
                .done(function (res) {
                    if (res && res.success) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Email sent.';
                        kitShowWaybillActionMessage('success', '<strong>Success!</strong> ' + $('<div>').text(msg).html());
                    } else {
                        var err = (res && res.data && res.data.message) ? res.data.message : 'Email failed.';
                        kitShowWaybillActionMessage('error', '<strong>Error!</strong> ' + $('<div>').text(err).html());
                    }
                })
                .fail(function (xhr) {
                    var err = 'Request failed.';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        err = xhr.responseJSON.data.message;
                    }
                    kitShowWaybillActionMessage('error', '<strong>Error!</strong> ' + $('<div>').text(err).html());
                })
                .always(function () {
                    $btn.data('kitEmailSending', false).prop('disabled', false).removeClass('opacity-60 cursor-wait');
                });
        });
    });
})();

/**
 * Click anywhere in a row checkbox cell to toggle that row only.
 * Select-all header checkboxes keep native/small hit targets so a padding
 * click does not mass-select every row ("all as 1 click").
 */
(function initKitCheckboxOnlyCellClicks() {
    function isSelectAllCheckbox(checkbox) {
        if (!checkbox) {
            return false;
        }
        if (checkbox.classList.contains('bulk-select-all-checkbox')) {
            return true;
        }
        var id = checkbox.id || '';
        return id.indexOf('bulk-select-all-') === 0
            || id.indexOf('select-all') !== -1
            || id === 'warehouse-select-all-checkbox'
            || id === 'infinite-select-all-checkbox';
    }

    function isCheckboxOnlyRowCell(cell) {
        // Row cells only — never expand hit target on header select-all.
        if (!cell || cell.tagName !== 'TD') {
            return false;
        }
        if (cell.classList.contains('kit-checkbox-only-cell') || cell.classList.contains('bulk-checkbox-cell')) {
            return !!cell.querySelector('input[type="checkbox"]');
        }
        var boxes = cell.querySelectorAll('input[type="checkbox"]');
        if (boxes.length !== 1) {
            return false;
        }
        // Reject cells that mix the checkbox with other controls/links.
        return !cell.querySelector('a[href], button, input:not([type="checkbox"]), select, textarea');
    }

    document.addEventListener('click', function (e) {
        var cell = e.target && e.target.closest ? e.target.closest('td') : null;
        if (!cell || !isCheckboxOnlyRowCell(cell)) {
            return;
        }

        // Native toggle when the checkbox or its label is the click target.
        if (e.target.closest('input[type="checkbox"], label')) {
            return;
        }

        var checkbox = cell.querySelector('input[type="checkbox"]');
        if (!checkbox || checkbox.disabled || isSelectAllCheckbox(checkbox)) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        checkbox.dispatchEvent(new Event('input', { bubbles: true }));
    });
})();