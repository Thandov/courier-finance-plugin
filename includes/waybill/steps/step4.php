<?php
if (! defined('ABSPATH')) {
    exit;
}

$is_portal = function_exists('kit_using_employee_portal') && kit_using_employee_portal();
$destination_country_id = isset($_POST['destination_country']) ? intval($_POST['destination_country']) : 0;
$destination_city_id    = isset($_POST['destination_city']) ? intval($_POST['destination_city']) : 0;
$selected_country       = $destination_country_id > 0 ? $destination_country_id : null;

function displayWarehouseOrSelectDelivery()
{
    ob_start(); ?>
    <style>
        .kit-step4-mode-wrap .kit-step4-mode-segment {
            display: inline-flex;
            border-radius: 0.5rem;
            overflow: hidden;
            border: 1px solid #1e293b;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.12);
        }
        .kit-step4-mode-wrap .kit-step4-mode-btn {
            margin: 0;
            min-width: 7.25rem;
            padding: 0.625rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 600;
            line-height: 1.25rem;
            border: none;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
        }
        .kit-step4-mode-wrap .kit-step4-mode-btn + .kit-step4-mode-btn {
            border-left: 1px solid rgba(15, 23, 42, 0.45);
        }
        .kit-step4-mode-wrap .kit-step4-mode-btn:focus {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
            z-index: 1;
        }
        /* Inactive: muted slate gradient, always white label text */
        .kit-step4-mode-wrap .kit-step4-mode-btn.kit-step4-mode--inactive {
            background: linear-gradient(180deg, #64748b 0%, #475569 55%, #334155 100%);
            color: #ffffff;
            text-shadow: 0 1px 0 rgba(15, 23, 42, 0.25);
        }
        .kit-step4-mode-wrap .kit-step4-mode-btn.kit-step4-mode--inactive:hover {
            background: linear-gradient(180deg, #708196 0%, #526077 55%, #3d4a5c 100%);
            color: #ffffff;
        }
        /* Active: deep blue → indigo gradient; label uses warm amber (complement to blue) */
        .kit-step4-mode-wrap .kit-step4-mode-btn.kit-step4-mode--active {
            background: linear-gradient(115deg, #0b1220 0%, #0f2847 38%, #172554 72%, #1e1b4b 100%);
            color: #fde68a;
            text-shadow: 0 1px 1px rgba(15, 23, 42, 0.45);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }
        .kit-step4-mode-wrap .kit-step4-mode-btn.kit-step4-mode--active:hover {
            background: linear-gradient(115deg, #111827 0%, #15365a 38%, #1c2f66 72%, #25205e 100%);
            color: #fef3c7;
        }
    </style>
    <div class="kit-step4-mode-wrap flex w-full shrink-0 flex-col gap-1 sm:w-auto sm:items-end" role="group" aria-label="Delivery mode">
        <span class="<?= esc_attr(KIT_Commons::labelClass()); ?>">Select Delivery</span>
        <div class="kit-step4-mode-segment">
            <button type="button" id="kit-step4-mode-warehouse" class="kit-step4-mode-btn kit-step4-mode--active" aria-pressed="true">
                Warehouse
            </button>
            <button type="button" id="kit-step4-mode-truck" class="kit-step4-mode-btn kit-step4-mode--inactive" aria-pressed="false">
                Truck
            </button>
        </div>
        <p id="kit-step4-truck-hint" class="hidden text-right text-xs text-gray-500">
            <span id="kit-step4-truck-count-dup">0</span> trucks available
        </p>
    </div>
    <?php
    return ob_get_clean();
}
?>
<div class="bg-white p-6 space-y-5">
    <?php
    // Backend expects `pending` == 1 for warehouse storage; JS uses #pending_option.
    ?>
    <input type="checkbox" id="pending_option" name="pending" value="1" class="sr-only" checked="checked" tabindex="-1" aria-hidden="true" />

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 flex-1">
            <h3 class="text-lg font-semibold text-gray-900">Delivery &amp; Destination</h3>
            <p class="text-xs text-gray-600 mt-1">
                Choose destination country and city. In <strong class="font-medium">Truck</strong> mode, pick a scheduled delivery to assign the route.
            </p>
        </div>
    </div>

    <?php if ($is_portal) : ?>
        <?php
        $countries = class_exists('KIT_Deliveries') ? KIT_Deliveries::getCountriesObject() : [];
        ?>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div id="waybill-form-country" data-select-class="<?= esc_attr(KIT_Commons::selectClass()); ?>">
                <label for="stepDestinationSelect" class="<?= KIT_Commons::labelClass() ?>">Destination Country</label>
                <div id="kit-destination-country-mount"></div>
            </div>
            <div>
                <label for="destination_city" class="<?= KIT_Commons::labelClass() ?>">Destination City</label>
                <div id="destinationWrap" data-select-class="<?= esc_attr(KIT_Commons::selectClass()); ?>"></div>
            </div>
            <div>
                <?= displayWarehouseOrSelectDelivery(); ?>
            </div>
        </div>
        <script type="application/json" id="kit-step4-countries"><?php echo json_encode($countries); ?></script>
        <script>
        (function() {
            var mount = document.getElementById('kit-destination-country-mount');
            var wrap = document.getElementById('destinationWrap');
            var dataEl = document.getElementById('kit-step4-countries');
            var countries = dataEl ? (function(){ try { return JSON.parse(dataEl.textContent); } catch (e) { return []; } })() : [];
            var selClass = (document.getElementById('waybill-form-country') || {}).dataset && document.getElementById('waybill-form-country').dataset.selectClass;
            selClass = selClass || 'w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500';
            function run() {
                if (!mount || mount.querySelector('select')) return;
                var countrySelect = document.createElement('select');
                countrySelect.id = 'stepDestinationSelect';
                countrySelect.name = 'destination_country';
                countrySelect.className = selClass;
                countrySelect.required = true;
                countrySelect.addEventListener('change', function() {
                    var v = this.value;
                    if (typeof handleCountryChange === 'function') handleCountryChange(v, 'destination_country');
                    var back = document.getElementById('destination_country_backup');
                    if (back) back.value = v || '';
                });
                var o0 = document.createElement('option');
                o0.value = '';
                o0.textContent = 'Select Country';
                countrySelect.appendChild(o0);
                countries.forEach(function(c) {
                    var o = document.createElement('option');
                    o.value = c.id;
                    o.textContent = (c && c.country_name) ? c.country_name : '';
                    countrySelect.appendChild(o);
                });
                mount.appendChild(countrySelect);
                if (wrap && !wrap.querySelector('select')) {
                    var citySelect = document.createElement('select');
                    citySelect.id = 'destination_city';
                    citySelect.name = 'destination_city';
                    citySelect.className = (wrap.dataset && wrap.dataset.selectClass) || selClass;
                    citySelect.required = true;
                    var co = document.createElement('option');
                    co.value = '';
                    co.textContent = 'Select City';
                    citySelect.appendChild(co);
                    wrap.appendChild(citySelect);
                }
                if (typeof window.kitStep4SyncDestinationRequired === 'function') {
                    window.kitStep4SyncDestinationRequired();
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() { setTimeout(run, 150); });
            } else {
                setTimeout(run, 150);
            }
        })();
        </script>
    <?php else : ?>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label for="stepDestinationSelect" class="<?= KIT_Commons::labelClass() ?>">Destination Country</label>
                <?php echo KIT_Deliveries::CountrySelect('destination_country', 'stepDestinationSelect', $selected_country, true, false); ?>
            </div>
            <div>
                <label for="destination_city" class="<?= KIT_Commons::labelClass() ?>">Destination City</label>
                <?php echo KIT_Deliveries::selectAllCitiesByCountry('destination_city', 'destination_city', $destination_country_id, $destination_city_id, 'required'); ?>
            </div>
            <?= displayWarehouseOrSelectDelivery(); ?>
        </div>
    <?php endif; ?>

    <div id="displayDeliveryID" class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg" style="display: none;">
        <span class="font-medium text-blue-900">Delivery ID: </span>
        <span id="delivery_id_display" class="font-bold text-blue-700"></span>
    </div>
    <input type="hidden" name="delivery_id" id="selected_delivery_id" value="" />
    <?php
    $waybill_ctx_delivery = isset($atts['delivery_id']) ? (int) $atts['delivery_id'] : 0;
    if ($waybill_ctx_delivery <= 0) :
        ?>
        <input type="hidden" name="direction_id" id="direction_id" value="" />
        <?php
    endif;
    ?>
    <input type="hidden" name="destination_country_backup" id="destination_country_backup" value="" />

    <div id="kit-step4-truck-panel" class="hidden">
        <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/scheduledDeliveries.php'; ?>
    </div>

    <div class="flex justify-between mt-8 pt-4 border-t border-gray-100">
        <?php echo KIT_Commons::renderButton('Back', 'secondary', 'lg', [
            'icon'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" />',
            'iconPosition' => 'left',
            'data-target'  => 'step-1',
            'classes'      => 'prev-step',
        ]); ?>
        <?php echo KIT_Commons::renderButton('Next: Charges & Fees', 'primary', 'lg', [
            'id'           => 'step4NextBtn',
            'disabled'     => true,
            'icon'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />',
            'iconPosition' => 'right',
            'data-target'  => 'step-3',
            'classes'      => 'next-step',
            'gradient'     => true,
        ]); ?>
    </div>

    <script>
    (function () {
        function syncTruckCountDup() {
            var src = document.getElementById('delivery-count');
            var dup = document.getElementById('kit-step4-truck-count-dup');
            if (src && dup) {
                dup.textContent = src.textContent.trim() || '0';
            }
        }

        function setDestinationHtml5Required(warehouse) {
            var c = document.getElementById('stepDestinationSelect');
            var city = document.getElementById('destination_city');
            if (c) {
                if (warehouse) {
                    c.removeAttribute('required');
                } else {
                    c.setAttribute('required', 'required');
                }
            }
            if (city) {
                if (warehouse) {
                    city.removeAttribute('required');
                } else {
                    city.setAttribute('required', 'required');
                }
            }
        }

        function styleModeButtons(warehouseBtn, truckBtn, warehouseActive) {
            if (warehouseBtn) {
                warehouseBtn.classList.toggle('kit-step4-mode--active', warehouseActive);
                warehouseBtn.classList.toggle('kit-step4-mode--inactive', !warehouseActive);
                warehouseBtn.setAttribute('aria-pressed', warehouseActive ? 'true' : 'false');
            }
            if (truckBtn) {
                truckBtn.classList.toggle('kit-step4-mode--active', !warehouseActive);
                truckBtn.classList.toggle('kit-step4-mode--inactive', warehouseActive);
                truckBtn.setAttribute('aria-pressed', warehouseActive ? 'false' : 'true');
            }
        }

        function initStep4DeliveryMode() {
            var warehouseBtn = document.getElementById('kit-step4-mode-warehouse');
            var truckBtn = document.getElementById('kit-step4-mode-truck');
            var pending = document.getElementById('pending_option');
            var truckPanel = document.getElementById('kit-step4-truck-panel');
            var truckHint = document.getElementById('kit-step4-truck-hint');
            if (!warehouseBtn || !truckBtn || !pending) {
                return;
            }

            function setMode(warehouse) {
                pending.checked = !!warehouse;
                if (truckPanel) {
                    truckPanel.classList.toggle('hidden', !!warehouse);
                }
                if (truckHint) {
                    truckHint.classList.toggle('hidden', !!warehouse);
                }
                styleModeButtons(warehouseBtn, truckBtn, !!warehouse);
                setDestinationHtml5Required(!!warehouse);
                if (!warehouse) {
                    syncTruckCountDup();
                }
                pending.dispatchEvent(new Event('change', { bubbles: true }));
            }

            warehouseBtn.addEventListener('click', function () {
                setMode(true);
            });
            truckBtn.addEventListener('click', function () {
                setMode(false);
            });

            // Default: warehouse (panel hidden, pending checked).
            setMode(true);
        }

        window.kitStep4SyncDestinationRequired = function () {
            var pending = document.getElementById('pending_option');
            setDestinationHtml5Required(!!(pending && pending.checked));
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initStep4DeliveryMode);
        } else {
            initStep4DeliveryMode();
        }
        setTimeout(function () {
            window.kitStep4SyncDestinationRequired();
        }, 400);
    })();
    </script>
</div>
