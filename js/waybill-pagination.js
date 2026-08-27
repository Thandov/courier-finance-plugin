//The full path to this file including the folder name is:
// wp-content/plugins/my-plugin/js/waybill-pagination.js

// Simple non-AJAX pagination - just handle items per page dropdown
function initItemsPerPage() {
    var selector = document.getElementById('items-per-page');
    if (selector) {
        selector.addEventListener('change', function () {
            var itemsPerPage = this.value;
            var url = new URL(window.location.href);
            url.searchParams.set('items_per_page', itemsPerPage);
            url.searchParams.set('paged', 1); // Reset to first page
            window.location.href = url.toString();
        });
    }
}

/**
 * True when element is displayed (not display:none / visibility:hidden).
 */
function kitFinanceModalVisible(el) {
    if (!el) return false;
    var s = window.getComputedStyle(el);
    if (s.display === 'none' || s.visibility === 'hidden') return false;
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
}

/**
 * Prefer city <select> inside a visible modal (#create-waybill-modal, #edit-delivery-modal,
 * #add-delivery-truck-modal) so duplicate delivery-form IDs on kit-deliveries do not target the wrong select.
 */
function resolveCitySelectForField(isOrigin) {
    var primaryId = isOrigin ? 'origin_city_select' : 'destination_city_select';
    var fallbackId = isOrigin ? 'origin_city' : 'destination_city';

    function pickIn(container) {
        if (!container) return null;
        if (isOrigin) {
            return container.querySelector('#' + primaryId) || container.querySelector('#' + fallbackId);
        }
        return container.querySelector('#' + fallbackId) || container.querySelector('#' + primaryId);
    }

    var roots = [
        document.getElementById('create-waybill-modal'),
        document.getElementById('edit-delivery-modal'),
        document.getElementById('add-delivery-truck-modal'),
        document.getElementById('create-delivery-modal'),
        document.getElementById('add-delivery-modal')
    ];
    for (var i = 0; i < roots.length; i++) {
        if (!roots[i] || !kitFinanceModalVisible(roots[i])) continue;
        var found = pickIn(roots[i]);
        if (found) return found;
    }

    if (!isOrigin) {
        return document.getElementById(fallbackId) || document.getElementById(primaryId);
    }
    return document.getElementById(primaryId) || document.getElementById(fallbackId);
}

// Country change handler
// Global function for handling country changes (using working implementation from waybill)
function handleCountryChange(countryId, fieldName) {
    if (!countryId) return;

    var isOrigin = false;

    if (fieldName === 'origin' || fieldName === 'origin_country') {
        isOrigin = true;
    } else if (fieldName === 'destination' || fieldName === 'destination_country') {
        isOrigin = false;
    } else {
        return;
    }

    var citySelect = resolveCitySelectForField(isOrigin);
    if (!citySelect) return;

    // Try preloaded map first for instant update
    var citiesMap = (window.myPluginAjax && window.myPluginAjax.countryCities) || {};
    var cities = citiesMap && citiesMap[String(countryId)] ? citiesMap[String(countryId)] : [];

    if (Array.isArray(cities) && cities.length) {
        citySelect.innerHTML = '<option value="">Select City</option>';
        cities.forEach(function(city) {
            var option = document.createElement('option');
            option.value = city.id;
            option.textContent = city.city_name;
            citySelect.appendChild(option);
        });
        citySelect.disabled = false;
    } else {
        // Fallback: fetch cities via AJAX when preloaded JSON is missing/empty
        citySelect.innerHTML = '<option value=\"\">Loading cities...</option>';
        citySelect.disabled = true;
        var ajaxUrlCities = window.ajaxurl || (window.myPluginAjax && window.myPluginAjax.ajax_url);
        var nonces2 = window.myPluginAjax && window.myPluginAjax.nonces;
        // IMPORTANT: backend validates against 'get_waybills_nonce', so always use that
        var nonceCities = (nonces2 && nonces2.get_waybills_nonce) || '';
        if (ajaxUrlCities && nonceCities) {
            if (typeof jQuery !== 'undefined') {
                jQuery.ajax({
                    url: ajaxUrlCities,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'handle_get_cities_for_country',
                        country_id: countryId,
                        nonce: nonceCities
                    },
                    success: function (response) {
                        if (response && response.success && Array.isArray(response.data) && response.data.length) {
                            citySelect.innerHTML = '<option value=\"\">Select City</option>';
                            response.data.forEach(function (city) {
                                var option = document.createElement('option');
                                option.value = city.id;
                                option.textContent = city.city_name;
                                citySelect.appendChild(option);
                            });
                        } else {
                            citySelect.innerHTML = '<option value=\"\">No cities found</option>';
                        }
                        citySelect.disabled = false;
                    },
                    error: function () {
                        citySelect.innerHTML = '<option value=\"\">Error loading cities</option>';
                        citySelect.disabled = false;
                    }
                });
            } else {
                fetch(ajaxUrlCities, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=' + encodeURIComponent('handle_get_cities_for_country') +
                          '&country_id=' + encodeURIComponent(countryId) +
                          '&nonce=' + encodeURIComponent(nonceCities)
                }).then(function (r) { return r.json(); }).then(function (response) {
                    if (response && response.success && Array.isArray(response.data) && response.data.length) {
                        citySelect.innerHTML = '<option value=\"\">Select City</option>';
                        response.data.forEach(function (city) {
                            var option = document.createElement('option');
                            option.value = city.id;
                            option.textContent = city.city_name;
                            citySelect.appendChild(option);
                        });
                    } else {
                        citySelect.innerHTML = '<option value=\"\">No cities found</option>';
                    }
                    citySelect.disabled = false;
                }).catch(function () {
                    citySelect.innerHTML = '<option value=\"\">Error loading cities</option>';
                    citySelect.disabled = false;
                });
            }
        } else {
            citySelect.innerHTML = '<option value=\"\">No cities found</option>';
            citySelect.disabled = false;
        }
    }

    // Keep scheduled deliveries refresh for destination changes
    if (!isOrigin) {
        var ajaxUrl2 = window.ajaxurl || (window.myPluginAjax && window.myPluginAjax.ajax_url);
        var list = document.getElementById('scheduled-deliveries-list');
        if (list && ajaxUrl2) {
            list.innerHTML = '<div class="text-gray-500">Loading deliveries...</div>';
            var nonces = window.myPluginAjax && window.myPluginAjax.nonces;
            var nonce = (nonces && (nonces.deliveries_nonce || nonces.get_waybills_nonce)) || '';
            if (typeof jQuery !== 'undefined') {
                jQuery.ajax({
                    url: ajaxUrl2,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'get_deliveries_by_country_id',
                        country: countryId,
                        nonce: nonce
                    },
                    success: function(res){
                        if (res && res.success && res.html) {
                            list.innerHTML = res.html;
                        } else if (res && res.data && res.data.html) {
                            list.innerHTML = res.data.html;
                        } else {
                            list.innerHTML = '<div class="text-gray-500">No deliveries found</div>';
                        }
                    },
                    error: function(){
                        list.innerHTML = '<div class="text-red-600">Error loading deliveries</div>';
                    }
                });
            } else {
                fetch(ajaxUrl2, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_deliveries_by_country_id&country=' + encodeURIComponent(countryId) + '&nonce=' + encodeURIComponent(nonce)
                }).then(function(r) { return r.json(); }).then(function(res) {
                    if (res && res.success && res.html) {
                        list.innerHTML = res.html;
                    } else if (res && res.data && res.data.html) {
                        list.innerHTML = res.data.html;
                    } else {
                        list.innerHTML = '<div class="text-gray-500">No deliveries found</div>';
                    }
                }).catch(function() {
                    list.innerHTML = '<div class="text-red-600">Error loading deliveries</div>';
                });
            }
        }
    }
}
window.handleCountryChange = handleCountryChange;

// originCountry change handler for cities and destinationCountry change handler for cities
function gaybitch(value, type) {
    var citySelect = document.getElementById(type + '_city_select');
    if (!citySelect) return;

    var citiesMap = (window.myPluginAjax && myPluginAjax.countryCities) || {};
    var cities = citiesMap && citiesMap[String(value)] ? citiesMap[String(value)] : [];

    citySelect.innerHTML = '';
    var defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Select City';
    citySelect.appendChild(defaultOption);

    cities.forEach(function (city, index) {
        var option = document.createElement('option');
        option.value = city.id;
        option.textContent = city.city_name;
        if (index === 0) option.selected = true;
        citySelect.appendChild(option);
    });
}



// Modal handling
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('customerModal');
    var openBtn = document.getElementById('customerModalButton');
    var closeBtn = document.getElementById('customerModalClose');

    if (openBtn && closeBtn && modal) {
        openBtn.addEventListener('click', function() { modal.classList.remove('hidden'); });
        closeBtn.addEventListener('click', function() { modal.classList.add('hidden'); });

        // Close when clicking outside modal
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.add('hidden');
            }
        });
    }
});

function handleDeliveryChange(delivery_id) {
    
    // Find and check the radio button for this delivery
    var radioButton = document.querySelector(`input[name="direction_id"][value="${delivery_id}"]`);
    if (radioButton) {
        radioButton.checked = true;
        // Trigger change event to ensure CSS classes update
        radioButton.dispatchEvent(new Event('change', { bubbles: true }));
    }
    
    var deliveryInput = document.querySelector('input[name="delivery_id"]');
    var specialDeliveryBtn = document.getElementById('specialDeliveryBtn');
    
    if (specialDeliveryBtn) {
        specialDeliveryBtn.disabled = false;
        specialDeliveryBtn.classList.remove('bg-gray-300', 'text-gray-500');
        specialDeliveryBtn.classList.add('bg-blue-600', 'text-white');
    }

    if (deliveryInput) {
        deliveryInput.value = delivery_id;
    }
}

// Delivery button click handler
document.addEventListener('click', function (e) {
    if (e.target.closest('.deliveryBtn')) {
        var input = e.target.closest('.deliveryBtn').querySelector('input[name="delivery_id"]');
        var selectedDeliveryId = input ? input.value : null;
        if (selectedDeliveryId) {
            handleDeliveryChange(selectedDeliveryId);
        }
    }
});



// Event delegation: country dropdowns work even when form/step is added after DOMContentLoaded (e.g. shortcode chain, step 4 hidden)
function onCountrySelectChange(e) {
    var el = e && e.target;
    if (!el || el.tagName !== 'SELECT' || typeof handleCountryChange !== 'function') return;
    if (el.id === 'stepDestinationSelect' || el.name === 'destination_country') {
        handleCountryChange(el.value, 'destination_country');
    } else if (el.id === 'origin_country' || el.name === 'origin_country') {
        handleCountryChange(el.value, 'origin_country');
    }
}
document.addEventListener('change', onCountrySelectChange, true);

// Initialize on page load
document.addEventListener('DOMContentLoaded', function () {
    initItemsPerPage();
    // Bind country dropdowns so they work even when inline onchange is stripped (e.g. frontend/CSP)
    var destCountrySelect = document.getElementById('stepDestinationSelect') || document.querySelector('select[name="destination_country"]');
    var originCountrySelect = document.getElementById('origin_country') || document.querySelector('select[name="origin_country"]');
    if (destCountrySelect && typeof handleCountryChange === 'function') {
        destCountrySelect.removeEventListener('change', destCountrySelect._kitCountryChange);
        destCountrySelect._kitCountryChange = function() {
            handleCountryChange(destCountrySelect.value, 'destination_country');
        };
        destCountrySelect.addEventListener('change', destCountrySelect._kitCountryChange);
    }
    if (originCountrySelect && typeof handleCountryChange === 'function') {
        originCountrySelect.removeEventListener('change', originCountrySelect._kitCountryChange);
        originCountrySelect._kitCountryChange = function() {
            handleCountryChange(originCountrySelect.value, 'origin_country');
        };
        originCountrySelect.addEventListener('change', originCountrySelect._kitCountryChange);
    }
});