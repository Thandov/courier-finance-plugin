<?php if (!defined('ABSPATH')) { exit; } ?>

<?php
// Include the reusable delivery card component
require_once __DIR__ . '/deliveryCard.php';

// Check if we should hide the header (for integrated use in editWaybill)
$hide_header = isset($atts['hide_header']) && $atts['hide_header'] === true;
$smallWidth = isset($atts['small_width']) && $atts['small_width'] === true;
?>
<style>
/* Simple delivery card styling */
.delivery-card {
    transition: all 0.2s ease;
}

.delivery-card.selected {
    background-color: #dbeafe !important;
    border: 2px solid #3b82f6 !important;
}

#scheduled-deliveries-container {
    background: transparent;
}

/* Match step4 Warehouse / Truck segmented control (dark active + amber text, slate inactive + white text) */
.kit-del-filter-wrap .kit-del-filter-segment {
    display: inline-flex;
    border-radius: 0.5rem;
    overflow: hidden;
    border: 1px solid #1e293b;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.12);
}
.kit-del-filter-wrap .kit-del-filter-btn {
    margin: 0;
    min-width: 5.75rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.8125rem;
    font-weight: 600;
    line-height: 1.25rem;
    border: none;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
}
.kit-del-filter-wrap .kit-del-filter-btn + .kit-del-filter-btn {
    border-left: 1px solid rgba(15, 23, 42, 0.45);
}
.kit-del-filter-wrap .kit-del-filter-btn:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
    z-index: 1;
}
.kit-del-filter-wrap .kit-del-filter-btn.kit-del-filter--inactive {
    background: linear-gradient(180deg, #64748b 0%, #475569 55%, #334155 100%);
    color: #ffffff;
    text-shadow: 0 1px 0 rgba(15, 23, 42, 0.25);
}
.kit-del-filter-wrap .kit-del-filter-btn.kit-del-filter--inactive:hover {
    background: linear-gradient(180deg, #708196 0%, #526077 55%, #3d4a5c 100%);
    color: #ffffff;
}
.kit-del-filter-wrap .kit-del-filter-btn.kit-del-filter--active {
    background: linear-gradient(115deg, #0b1220 0%, #0f2847 38%, #172554 72%, #1e1b4b 100%);
    color: #fde68a;
    text-shadow: 0 1px 1px rgba(15, 23, 42, 0.45);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
}
.kit-del-filter-wrap .kit-del-filter-btn.kit-del-filter--active:hover {
    background: linear-gradient(115deg, #111827 0%, #15365a 38%, #1c2f66 72%, #25205e 100%);
    color: #fef3c7;
}
</style>
<div id="scheduled-deliveries-container" class="<?= $hide_header ? '' : 'mt-4' ?>">
    <?php
    // Get current filter (default to 'scheduled')
    $current_filter = isset($_GET['delivery_filter']) ? sanitize_text_field($_GET['delivery_filter']) : 'scheduled';
    ?>
    <?php if (!$hide_header): ?>
    <div class="flex items-center justify-between mb-2">
        <h4 class="text-md font-semibold text-gray-700 mb-1 flex items-center gap-2">
            <span class="text-blue-600 text-base leading-none" aria-hidden="true">🚚</span>
            Select Delivery Truck
        </h4>
        <div class="text-sm text-gray-500">
            <span id="delivery-count"><?php echo count(KIT_Deliveries::filterDeliveries($current_filter)); ?></span> trucks available
        </div>
    </div>
    <p class="text-xs text-gray-500 mb-4">
        Choose a card below to assign this waybill to a truck, driver, and route.
    </p>
    <?php endif; ?>
    
    <!-- Filter tabs: same visual language as step4 Warehouse / Truck -->
    <div class="kit-del-filter-wrap mb-4" role="group" aria-label="Delivery filter toggle">
        <div class="kit-del-filter-segment">
            <?php
            $filter_types = [
                'scheduled' => 'Scheduled',
                'all'         => 'All Deliveries',
                'past'        => 'Past Waybills',
            ];
            foreach ($filter_types as $filter_key => $filter_label) :
                $is_active      = $current_filter === $filter_key;
                $state_class    = $is_active ? 'kit-del-filter--active' : 'kit-del-filter--inactive';
                ?>
                <button
                    type="button"
                    class="delivery-filter-btn kit-del-filter-btn <?= esc_attr($state_class); ?>"
                    data-filter="<?= esc_attr($filter_key); ?>"
                    data-active="<?= $is_active ? '1' : '0'; ?>"
                    aria-pressed="<?= $is_active ? 'true' : 'false'; ?>"
                ><?php echo esc_html($filter_label); ?></button>
                <?php
            endforeach;
            ?>
        </div>
    </div>
    
    <!-- Horizontal Row Layout - match the image layout -->
    <div id="scheduled-deliveries-list" class="grid grid-cols-1 <?= $smallWidth ? '' : 'md:grid-cols-4 lg:grid-cols-6' ?> gap-3 min-h-32 max-h-96 overflow-y-auto">
        <?php
        $delivery_going = KIT_Deliveries::filterDeliveries($current_filter);
        
        // Check if we should auto-select a delivery
        $auto_select_delivery_id = isset($_GET['auto_select_delivery']) ? intval($_GET['auto_select_delivery']) : 0;

        foreach ($delivery_going as $delivery):
            // Check if this delivery should be auto-selected
            $should_auto_select = ($auto_select_delivery_id > 0 && 
                                  ($delivery->id == $auto_select_delivery_id || 
                                   $delivery->direction_id == $auto_select_delivery_id));
            
            // Use our reusable component
            renderDeliveryCard($delivery, 'scheduled', true, 'handleDeliveryClick');
            
            // If this is the delivery to auto-select, add a data attribute and script
            if ($should_auto_select):
                $delivery_id_js = intval($delivery->id);
                $direction_id_js = intval($delivery->direction_id ?? 0);
                ?>
                <script>
                // Mark this card for auto-selection
                (function() {
                    const deliveryId = <?php echo $delivery_id_js; ?>;
                    const directionId = <?php echo $direction_id_js; ?>;
                    
                    function markCard() {
                        const card = document.querySelector('.delivery-card[data-delivery-id="' + deliveryId + '"]');
                        if (card) {
                            card.setAttribute('data-auto-select', 'true');
                            card.setAttribute('data-auto-select-direction-id', directionId);
                            console.log('✅ PHP marked delivery card for auto-selection:', deliveryId);
                            return true;
                        }
                        return false;
                    }
                    
                    // Try immediately if DOM is ready
                    if (document.readyState === 'complete' || document.readyState === 'interactive') {
                        if (!markCard()) {
                            // If not found, wait for DOMContentLoaded
                            document.addEventListener('DOMContentLoaded', markCard);
                        }
                    } else {
                        document.addEventListener('DOMContentLoaded', markCard);
                    }
                })();
                </script>
                <?php
            endif;
        endforeach; ?>
    </div>

    <!-- otherzz -->
     
    
    <!-- Empty State -->
    <div id="deliveries-empty-state" class="<?php echo empty($delivery_going) ? 'block' : 'hidden'; ?> text-center py-8">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-2">
            <?php
            $empty_messages = [
                'scheduled' => 'No scheduled deliveries',
                'all' => 'No deliveries found',
                'past' => 'No past waybills'
            ];
            echo esc_html($empty_messages[$current_filter] ?? 'No deliveries found');
            ?>
        </h3>
        <p class="text-gray-600">
            <?php
            $empty_descriptions = [
                'scheduled' => 'No deliveries are currently scheduled',
                'all' => 'No deliveries are available',
                'past' => 'No past waybills found'
            ];
            echo esc_html($empty_descriptions[$current_filter] ?? 'No deliveries are available');
            ?>
        </p>
    </div>
</div>

<!-- Simple JavaScript for Delivery Card Selection -->
<script>
(function() {
    'use strict';
    
    // Prevent multiple initializations
    if (window.KIT_ScheduledDeliveries_Initialized) {
        // Already initialized – silently skip to avoid duplicate listeners
        return;
    }
    window.KIT_ScheduledDeliveries_Initialized = true;
    
    // Handle delivery card clicks (wrapper to ensure compatibility)
    // Only define if not already defined
    if (typeof window.handleDeliveryClick === 'undefined') {
        window.handleDeliveryClick = function(cardElement, directionId) {
            if (typeof window.selectDeliveryCard === 'function') {
                window.selectDeliveryCard(cardElement, directionId);
            } else if (typeof selectDeliveryCard === 'function') {
                selectDeliveryCard(cardElement, directionId);
            } else {
                console.error('❌ selectDeliveryCard function not found');
            }
        };
    }
    
    // Also make it available globally for inline onclick handlers (only if not defined)
    if (typeof handleDeliveryClick === 'undefined') {
        var handleDeliveryClick = window.handleDeliveryClick;
    }

    // Simple function to select delivery cards
    // Only define if not already defined
    if (typeof selectDeliveryCard === 'undefined') {
        window.selectDeliveryCard = function(cardElement, directionId, skipDetailsDisplay = false) {
            console.log('Card clicked:', directionId, 'skipDetailsDisplay:', skipDetailsDisplay);
    
    // Remove selection from all cards
    document.querySelectorAll('.delivery-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    // Select the clicked card
    cardElement.classList.add('selected');
    console.log('Card selected:', directionId);
    
    // ✅ CRITICAL: Update the direction_id field for rate calculation
    const directionIdField = document.getElementById('direction_id');
    if (directionIdField) {
        directionIdField.value = directionId;
        console.log('Updated direction_id field to:', directionId);
        
        // Trigger rate fetch if mass is present
        const totalMassInput = document.getElementById('total_mass_kg');
        if (totalMassInput && totalMassInput.value && parseFloat(totalMassInput.value) > 0) {
            console.log('Mass present, triggering rate fetch...');
            if (typeof fetchRatePerKg === 'function') {
                try {
                    fetchRatePerKg();
                } catch(e) {
                    console.error('Rate fetch failed:', e);
                }
            }
        }
    } else {
        console.warn('direction_id field not found!');
    }
    
    // ✅ CRITICAL: Populate destination country field when delivery is selected
    const destinationCountryField = document.getElementById('stepDestinationSelect');
    let destinationCountryId = cardElement.getAttribute('data-destination-country-id');
    const destinationCountryName = cardElement.getAttribute('data-destination-country');
    
    // If country ID is missing, try to find it by country name in the dropdown
    if (!destinationCountryId || destinationCountryId === '' || destinationCountryId === '0') {
        // destination_country_id missing on card – fall back to matching by name
        if (destinationCountryName && destinationCountryField) {
            // Try to find country ID by matching the country name in the dropdown
            for (let option of destinationCountryField.options) {
                const optionText = option.textContent.trim();
                if (optionText === destinationCountryName.trim() || optionText.includes(destinationCountryName.trim())) {
                    destinationCountryId = option.value;
                    console.log('✅ Found country ID by name:', destinationCountryId, 'for country:', destinationCountryName);
                    break;
                }
            }
        }
    }
    
    if (destinationCountryField && destinationCountryId && destinationCountryId !== '' && destinationCountryId !== '0') {
        destinationCountryField.value = destinationCountryId;
        console.log('✅ Updated destination_country field to:', destinationCountryId);
        
        // Also set via name attribute selector as a backup (in case ID doesn't match)
        const destinationCountryByName = document.querySelector('select[name="destination_country"]');
        if (destinationCountryByName && destinationCountryByName !== destinationCountryField) {
            destinationCountryByName.value = destinationCountryId;
            console.log('✅ Updated destination_country field (by name) to:', destinationCountryId);
        }
        
        // Also set backup hidden field to ensure value is always submitted
        const destinationCountryBackup = document.getElementById('destination_country_backup');
        if (destinationCountryBackup) {
            destinationCountryBackup.value = destinationCountryId;
            console.log('✅ Set destination_country_backup field to:', destinationCountryId);
        }
        
        // Uncheck warehouse checkbox when delivery is selected
        const pendingOption = document.getElementById('pending_option');
        if (pendingOption && pendingOption.checked) {
            pendingOption.checked = false;
            console.log('✅ Unchecked warehouse option because delivery is selected');
        }

        // IMPORTANT: For delivery-card clicks we handle city loading ourselves
        // (see block below using loadDestinationCities / citiesMap). We MUST NOT
        // dispatch a 'change' event here, otherwise the global onCountrySelectChange
        // handler in waybill-pagination.js will call handleCountryChange and
        // rebuild #destination_city, wiping out any user-selected city.
    } else {
        console.warn('❌ Could not set destination_country_id - missing data. Country name:', destinationCountryName, 'Country ID:', destinationCountryId);
    }
    
    // Get destination city ID from delivery card
    const destinationCityId = cardElement.getAttribute('data-destination-city-id');
    const citySelect = document.getElementById('destination_city');
    const existingCityValue = citySelect ? (citySelect.value || '') : '';

    
    
    // CRITICAL: If the user has already chosen a city, do NOT reload or clear it
    // when clicking a delivery card. Just keep the current value and re-run
    // validation so the UI stays in sync.
    if (citySelect && existingCityValue) {
        console.log('✅ Keeping existing destination_city selection:', existingCityValue);

        const cityChangeEvent = new Event('change', { bubbles: true });
        citySelect.dispatchEvent(cityChangeEvent);
    } else {
        // No city chosen yet – we can safely load cities based on the delivery.
        // Prefer the delivery's destination city if present.
        const preferredCityId = destinationCityId || '';

        // Check if loadDestinationCities function exists (from waybillmultiform)
        if (typeof loadDestinationCities === 'function' && destinationCountryId) {
            setTimeout(() => {
                loadDestinationCities(destinationCountryId, preferredCityId);
            }, 50);
        } else if (destinationCountryId && citySelect) {
            // Fallback: manually load cities
            const citiesMap = (window.myPluginAjax && window.myPluginAjax.countryCities) || {};
            const cities = citiesMap && citiesMap[String(destinationCountryId)] ? citiesMap[String(destinationCountryId)] : [];
            
            if (Array.isArray(cities) && cities.length) {
                citySelect.innerHTML = '<option value="">Select City</option>';
                cities.forEach(function(city) {
                    const option = document.createElement('option');
                    option.value = city.id;
                    option.textContent = city.city_name;
                    if (preferredCityId && String(city.id) === String(preferredCityId)) {
                        option.selected = true;
                    }
                    citySelect.appendChild(option);
                });
                citySelect.disabled = false;
                
                if (preferredCityId && citySelect.value !== String(preferredCityId)) {
                    citySelect.value = String(preferredCityId);
                    const cityChangeEvent = new Event('change', { bubbles: true });
                    citySelect.dispatchEvent(cityChangeEvent);
                }
                
            // Cities loaded for delivery selection
            } else if (typeof handleCountryChange === 'function') {
                // Try handleCountryChange as fallback
                handleCountryChange(destinationCountryId, 'destination');
                
                if (preferredCityId) {
                    setTimeout(() => {
                        const citySelect2 = document.getElementById('destination_city');
                        if (citySelect2) {
                            citySelect2.value = preferredCityId;
                            const cityChangeEvent = new Event('change', { bubbles: true });
                            citySelect2.dispatchEvent(cityChangeEvent);
                            console.log('✅ Auto-selected destination city (delayed):', preferredCityId);
                        }
                    }, 200);
                }
            }
        }
    }
    
    // Persist selected delivery id for submission
    const selectedDeliveryHidden = document.getElementById('selected_delivery_id');
    if (selectedDeliveryHidden) {
        // Card data-index carries delivery id when available; fallback to directionId
        const deliveryId = cardElement.getAttribute('data-index') || String(directionId);
        selectedDeliveryHidden.value = deliveryId;
        console.log('Set hidden delivery_id to:', selectedDeliveryHidden.value);
    } else {
        console.warn('Hidden selected_delivery_id input not found');
    }

    // Show delivery details ONLY if it's a real user click (not auto-selection)
    if (!skipDetailsDisplay) {
        const deliveryDetails = document.getElementById('deliveryDetails');
        if (deliveryDetails) {
            deliveryDetails.classList.remove('hidden');
        
        // Get delivery information from the card (prefer data attributes)
        const dateElement = cardElement.querySelector('.text-xs.font-bold');
        const routeElements = cardElement.querySelectorAll('.font-medium');

        const date = cardElement.dataset.dispatchDate || (dateElement ? dateElement.textContent : 'Unknown');
        const origin = cardElement.dataset.originCountry || (routeElements[0] ? routeElements[0].textContent : 'Unknown');
        const destination = cardElement.dataset.destinationCountry || (routeElements[1] ? routeElements[1].textContent : 'Unknown');
        const direction = cardElement.dataset.directionId || String(directionId);
        const reference = cardElement.dataset.reference || '—';
        const truck = cardElement.dataset.truckNumber || '—';
        const driverId = cardElement.dataset.driverId || '';
        const driverName = cardElement.dataset.driverName || '';
        const driverPhone = cardElement.dataset.driverPhone || '';
        const status = cardElement.dataset.status || 'Scheduled';
        const description = cardElement.dataset.description || '';
        const originCode = cardElement.dataset.originCode || '';
        const destinationCode = cardElement.dataset.destinationCode || '';
        
        // Populate the details content
        const detailsContent = document.getElementById('delivery-details-content');
        if (detailsContent) {
            detailsContent.innerHTML = `
                <div class="bg-blue-50 p-4 rounded-lg mb-4">
                    <h5 class="font-medium text-blue-900 mb-2">Selected Delivery</h5>
                    <div class="text-sm text-blue-800">
                        <div class="font-medium">${date}</div>
                        <div>${origin} ${originCode ? '(' + originCode + ')' : ''} → ${destination} ${destinationCode ? '(' + destinationCode + ')' : ''}</div>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <span class="text-sm font-medium text-gray-500">Direction ID:</span>
                        <span class="text-sm text-gray-900 ml-2">${direction}</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500">Date:</span>
                        <span class="text-sm text-gray-900 ml-2">${date}</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500">Origin:</span>
                        <span class="text-sm text-gray-900 ml-2">${origin}</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500">Destination:</span>
                        <span class="text-sm text-gray-900 ml-2">${destination}</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500">Status:</span>
                        <span class="text-sm text-gray-900 ml-2">${status[0].toUpperCase() + status.slice(1)}</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500">Reference:</span>
                        <span class="text-sm text-gray-900 ml-2">${reference}</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500">Truck:</span>
                        <span class="text-sm text-gray-900 ml-2">${truck}</span>
                    </div>
                    ${driverName || driverId ? `
                    <div>
                        <span class="text-sm font-medium text-gray-500">Driver:</span>
                        <span class="text-sm text-gray-900 ml-2">${driverName || driverId}${driverPhone ? ' • ' + driverPhone : ''}</span>
                    </div>` : ''}
                    ${description ? `
                    <div class="col-span-2">
                        <span class="text-sm font-medium text-gray-500">Notes:</span>
                        <span class="text-sm text-gray-900 ml-2">${description}</span>
                    </div>` : ''}
                </div>
            `;
        }
        }
    }
    
            // Check all conditions after delivery selection
            if (typeof checkButtonConditions === 'function') {
                checkButtonConditions();
            }
        };
    }

    // Function to check all conditions for enabling the next button
    // Only define if not already defined
    if (typeof checkButtonConditions === 'undefined') {
        window.checkButtonConditions = function() {
    const nextButton = document.getElementById('step4NextBtn');
    if (!nextButton) return;
    
    // Check if a delivery is selected
    const hasDeliverySelected = document.querySelector('.delivery-card.selected') !== null;
    
    // Check if pending option is checked
    const isWarehoused = document.getElementById('pending_option')?.checked || false;
    
    // Check destination country (always required)
    const destinationCountry = document.getElementById('stepDestinationSelect')?.value || '';
    
    // Check destination city (only required if not pending)
    const destinationCity = document.getElementById('destination_city')?.value || '';
    
    // Determine if we can proceed based on the two valid scenarios:
    // 1. Delivery selected AND destination country set
    // 2. Warehoused checked AND destination country set
    let canProceed = false;
    
    if (hasDeliverySelected && destinationCountry) {
        // Scenario 1: Delivery selected + destination country
        canProceed = true;
        console.log('Condition 1 met: Delivery selected + destination country set');
    } else if (isWarehoused && destinationCountry) {
        // Scenario 2: Warehoused + destination country + direction_id = 1 for SA rates
        const directionIdField = document.getElementById('direction_id');
        const directionIdValue = directionIdField ? directionIdField.value.trim() : '';
        if (directionIdValue === '1') {
            canProceed = true;
            console.log('Condition 2 met: Warehoused + destination country + SA direction_id set');
        } else {
            console.log('Condition 2 failed: Warehoused but no SA direction_id for rate calculation');
        }
    }
    
    console.log('Button conditions check:', {
        hasDeliverySelected,
        isWarehoused,
        destinationCountry,
        destinationCity,
        canProceed
    });
    
    if (canProceed) {
        nextButton.disabled = false;
        nextButton.removeAttribute('disabled');
        nextButton.classList.remove('opacity-50', 'cursor-not-allowed');
        nextButton.classList.add('bg-blue-600', 'text-white', 'hover:bg-blue-700');
        console.log('Next button enabled - conditions met');
    } else {
        nextButton.disabled = true;
        nextButton.classList.add('opacity-50', 'cursor-not-allowed');
        nextButton.classList.remove('bg-blue-600', 'text-white', 'hover:bg-blue-700');
            console.log('Next button disabled - conditions not met');
        }
        };
    }

    // Set default rate for pending items (South Africa - direction_id = 1)
    // Only define if not already defined
    if (typeof setWarehouseDefaultRate === 'undefined') {
        window.setWarehouseDefaultRate = function() {
            console.log('🏠 Setting warehouse default rate to South Africa (direction_id = 1)');
    
    // Set direction_id to 1 for South Africa warehouse rates
    const directionIdField = document.getElementById('direction_id');
    if (directionIdField) {
        directionIdField.value = '1';
        console.log('Set direction_id to 1 for South Africa warehouse rate calculation');
    }
    
    // Set destination country to South Africa if not already set
    const destinationCountryField = document.getElementById('stepDestinationSelect');
    if (destinationCountryField && !destinationCountryField.value) {
        // Assuming 2 is South Africa ID in your countries table
        destinationCountryField.value = '2';
        console.log('Set destination country to South Africa for warehouse');
        
        // Trigger change event to load cities
        const changeEvent = new Event('change', { bubbles: true });
        destinationCountryField.dispatchEvent(changeEvent);
    }
    
    // Set destination city to a default warehouse city if not set
    const destinationCityField = document.getElementById('destination_city');
    if (destinationCityField && !destinationCityField.value) {
        // You might need to set this to the actual warehouse city ID
            // For now, we'll let the user select or it will be set when country changes
            console.log('Destination city will be set when country loads cities');
        }
        };
    }

    // Clear delivery selection
    // Only define if not already defined
    if (typeof clearDeliverySelection === 'undefined') {
        window.clearDeliverySelection = function() {
            const deliveryDetails = document.getElementById('deliveryDetails');
            if (deliveryDetails) {
                deliveryDetails.classList.add('hidden');
            }
            
            // Remove selection from all cards
            document.querySelectorAll('.delivery-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // ✅ CRITICAL: Clear the direction_id field
            const directionIdField = document.getElementById('direction_id');
            if (directionIdField) {
                directionIdField.value = '';
                console.log('Cleared direction_id field');
            }
            
            // Check button conditions after clearing selection
            if (typeof checkButtonConditions === 'function') {
                checkButtonConditions();
            }
        };
    }

    // Initialize scheduled deliveries functionality
    // Only define if not already defined
    if (typeof initializeScheduledDeliveries === 'undefined') {
        window.initializeScheduledDeliveries = function() {
            // Prevent double initialization
            if (window.scheduledDeliveriesInitialized) {
                console.warn('⚠️ initializeScheduledDeliveries already called, skipping');
                return;
            }
            
            window.scheduledDeliveriesInitialized = true;
    
    // Handle delivery filter buttons with AJAX
    const filterButtons = document.querySelectorAll('.delivery-filter-btn');
    const deliveriesList = document.getElementById('scheduled-deliveries-list');
    const deliveryCount = document.getElementById('delivery-count');
    const emptyState = document.getElementById('deliveries-empty-state');
    
    filterButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const filterType = this.getAttribute('data-filter');
            
            // Update button states (match step4 segmented control)
            filterButtons.forEach(btn => {
                const isActive = btn === button;
                btn.setAttribute('data-active', isActive ? '1' : '0');
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                btn.classList.toggle('kit-del-filter--active', isActive);
                btn.classList.toggle('kit-del-filter--inactive', !isActive);
            });
            
            // Show loading state
            if (deliveriesList) {
                deliveriesList.innerHTML = '<div class="col-span-full text-center py-8"><div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div><p class="mt-2 text-sm text-gray-500">Loading deliveries...</p></div>';
            }
            
            // Make AJAX request
            const formData = new FormData();
            formData.append('action', 'filter_deliveries');
            formData.append('filter_type', filterType);
            formData.append('nonce', '<?php echo wp_create_nonce('filter_deliveries_nonce'); ?>');
            
            const kitDelFilterAjaxUrl = (typeof window !== 'undefined' && window.ajaxurl)
                || (typeof window !== 'undefined' && window.myPluginAjax && window.myPluginAjax.ajax_url)
                || '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            
            fetch(kitDelFilterAjaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        throw new Error(text || ('HTTP ' + response.status));
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Update delivery list
                    if (deliveriesList) {
                        deliveriesList.innerHTML = data.data.html || '';
                    }
                    
                    // Update count
                    if (deliveryCount) {
                        deliveryCount.textContent = data.data.count || 0;
                    }
                    
                    // Show/hide empty state
                    if (emptyState) {
                        if (data.data.count === 0) {
                            emptyState.classList.remove('hidden');
                            emptyState.classList.add('block');
                            
                            // Update empty state messages
                            const emptyMessages = {
                                'scheduled': 'No scheduled deliveries',
                                'all': 'No deliveries found',
                                'past': 'No past waybills'
                            };
                            const emptyDescriptions = {
                                'scheduled': 'No deliveries are currently scheduled',
                                'all': 'No deliveries are available',
                                'past': 'No past waybills found'
                            };
                            
                            const heading = emptyState.querySelector('h3');
                            const description = emptyState.querySelector('p');
                            if (heading) heading.textContent = emptyMessages[filterType] || 'No deliveries found';
                            if (description) description.textContent = emptyDescriptions[filterType] || 'No deliveries are available';
                        } else {
                            emptyState.classList.remove('block');
                            emptyState.classList.add('hidden');
                        }
                    }
                    
                    // Cards already have inline onclick handlers from renderDeliveryCard
                    // Just ensure cursor styling is correct (reuse outer deliveriesList — do not redeclare; TDZ breaks .then)
                    if (deliveriesList) {
                        deliveriesList.querySelectorAll('.delivery-card').forEach(card => {
                            // Ensure cursor styling
                            if (!card.style.cursor || card.style.cursor === 'auto') {
                                card.style.cursor = 'pointer';
                            }
                        });
                    }
                } else {
                    console.error('Error filtering deliveries:', data.data?.message || 'Unknown error');
                    if (deliveriesList) {
                        deliveriesList.innerHTML = '<div class="col-span-full text-center py-8 text-red-600">Error loading deliveries. Please try again.</div>';
                    }
                }
            })
            .catch(error => {
                console.error('AJAX error:', error);
                if (deliveriesList) {
                    deliveriesList.innerHTML = '<div class="col-span-full text-center py-8 text-red-600">Error loading deliveries. Please try again.</div>';
                }
            });
        });
    });
    
    const groupingSelect = document.getElementById('grouping-option');
    if (groupingSelect) {
        groupingSelect.addEventListener('change', function() {
            const grouping = this.value;
            console.log('Grouping changed to:', grouping);
        });
    }
    
    // Add event listeners for destination fields and pending option
    const destinationCountry = document.getElementById('stepDestinationSelect');
    const destinationCity = document.getElementById('destination_city');
    const pendingOption = document.getElementById('pending_option');
    
    if (destinationCountry) {
        destinationCountry.addEventListener('change', checkButtonConditions);
    }
    
    if (destinationCity) {
        destinationCity.addEventListener('change', checkButtonConditions);
    }
    
    if (pendingOption) {
        pendingOption.addEventListener('change', function() {
            if (this.checked) {
                // Clear delivery selection when warehouse is checked
                console.log('📦 Warehouse checked - clearing delivery selection');
                clearDeliverySelection();
                
                // Set default rate charge group to South Africa for pending items
                setWarehouseDefaultRate();
                
                // Add a small delay to ensure clearing is complete before validation
                setTimeout(() => {
                    checkButtonConditions();
                }, 100);
            } else {
                console.log('📦 Warehouse unchecked - delivery selection can be made');
                checkButtonConditions();
            }
        });
    }
    
            // Initial check of button conditions
            if (typeof checkButtonConditions === 'function') {
                checkButtonConditions();
            }
            
            // Add manual button control functions for testing
            window.disableStep4Button = function() {
                const nextButton = document.getElementById('step4NextBtn');
                if (nextButton) {
                    nextButton.disabled = true;
                    nextButton.classList.add('opacity-50', 'cursor-not-allowed');
                    nextButton.classList.remove('bg-blue-600', 'text-white', 'hover:bg-blue-700');
                    console.log('Button manually disabled');
                }
            };
            
            window.enableStep4Button = function() {
                const nextButton = document.getElementById('step4NextBtn');
                if (nextButton) {
                    nextButton.disabled = false;
                    nextButton.classList.remove('opacity-50', 'cursor-not-allowed');
                    nextButton.classList.add('bg-blue-600', 'text-white', 'hover:bg-blue-700');
                    console.log('Button manually enabled');
                }
            };
        };
    }

    // Grouping functionality - initialize on DOMContentLoaded
    // Only add listeners if not already initialized
    if (!window.scheduledDeliveriesInitialized) {
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initializeScheduledDeliveries === 'function') {
                initializeScheduledDeliveries();
            }
        });

        // Also initialize immediately if DOM is already loaded (for modals)
        if (document.readyState !== 'loading') {
            if (typeof initializeScheduledDeliveries === 'function') {
                initializeScheduledDeliveries();
            }
        }

        // Initialize when modal opens (for modals loaded in DOM but hidden)
        if (typeof jQuery !== 'undefined') {
            jQuery(document).ready(function($) {
                // Listen for custom modal opened event
                $(document).on('modal:opened', function(e, modal) {
                    if (modal && modal.attr('id') === 'create-waybill-modal') {
                        setTimeout(function() {
                            if (typeof initializeScheduledDeliveries === 'function') {
                                initializeScheduledDeliveries();
                            }
                        }, 150);
                    }
                });
                
                // Also listen for clicks on the modal trigger button
                $(document).on('click', '[data-modal="create-waybill-modal"]', function() {
                    setTimeout(function() {
                        if (typeof initializeScheduledDeliveries === 'function') {
                            initializeScheduledDeliveries();
                        }
                    }, 200);
                });
                
                // Use MutationObserver to watch for modal visibility changes
                const modalElement = document.getElementById('create-waybill-modal');
                if (modalElement) {
                    const observer = new MutationObserver(function(mutations) {
                        mutations.forEach(function(mutation) {
                            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                                const target = mutation.target;
                                if (target.classList.contains('flex') && !target.classList.contains('hidden')) {
                                    // Modal just became visible
                                    setTimeout(function() {
                                        if (typeof initializeScheduledDeliveries === 'function') {
                                            initializeScheduledDeliveries();
                                        }
                                    }, 150);
                                }
                            }
                        });
                    });
                    
                    observer.observe(modalElement, {
                        attributes: true,
                        attributeFilter: ['class']
                    });
                }
            });
        }
    }
})(); // End of IIFE
</script>