<?php if (!defined('ABSPATH')) {
    exit;
}
// Access waybill items from global scope
global $waybill_items;
?>
<div class="bg-white p-6">
    <?= KIT_Commons::prettyHeading([
        'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
        'words' => 'Parcels & Review'
    ]) ?>
    <p class="text-xs text-gray-600 mb-6">
        Add parcel line items if needed, then confirm the summary before creating the waybill.
    </p>

    <!-- Pre-submit review
         Note: admin frontend.css has gap-6 / gap-x-8 but not gap-x-6.
         Avoid multi-column dt/dd grids that rely on missing gap utilities. -->
    <div id="kit-waybill-review" class="mb-6 border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Review before create</h3>
            <p class="mt-1 text-xs text-gray-500">Confirm customer, route, and billed freight before submitting.</p>
        </div>

        <div class="px-4 py-4 space-y-4">
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">Route</div>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs text-gray-500">Origin</div>
                        <div id="kit-review-origin" class="font-medium text-slate-900 break-words">—</div>
                    </div>
                    <div class="hidden text-gray-400 sm:block shrink-0" aria-hidden="true">→</div>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs text-gray-500">Destination</div>
                        <div id="kit-review-destination" class="font-medium text-slate-900 break-words">—</div>
                    </div>
                </div>
            </div>

            <dl class="border-t border-slate-200 pt-4 space-y-2 text-sm">
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 shrink-0">Waybill</dt>
                    <dd id="kit-review-waybill" class="m-0 font-medium text-slate-900 text-right tabular-nums">—</dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 shrink-0">Customer</dt>
                    <dd id="kit-review-customer" class="m-0 font-medium text-slate-900 text-right break-words">—</dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 shrink-0">Mode</dt>
                    <dd id="kit-review-mode" class="m-0 font-medium text-slate-900 text-right">—</dd>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 shrink-0">Charge basis</dt>
                    <dd id="kit-review-basis" class="m-0 font-medium text-slate-900 text-right">—</dd>
                </div>
            </dl>

            <div class="rounded border border-slate-200 bg-slate-50 px-3 py-3">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Billed freight</div>
                        <div id="kit-review-freight" class="mt-1 text-xl font-semibold text-slate-900 tabular-nums">R 0.00</div>
                    </div>
                    <div class="shrink-0 text-right text-xs text-gray-500 space-y-1">
                        <div>
                            Parcels
                            <span id="kit-review-parcels" class="ml-1 font-medium text-slate-900 tabular-nums">0</span>
                        </div>
                        <div>
                            Misc items
                            <span id="kit-review-misc" class="ml-1 font-medium text-slate-900 tabular-nums">0</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- VAT Validation Message -->
    <div id="vat-validation-message" class="mb-6 p-4 rounded-lg border-l-4 border-yellow-400 bg-yellow-50" style="display: none;">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">VAT Calculation Required</h3>
                <div class="mt-2 text-sm text-yellow-700">
                    <p>You selected VAT in Step 1. To calculate VAT correctly, you need to add parcels with their individual prices.</p>
                    <p class="mt-1 font-medium">Please add at least one parcel to proceed.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Container for multiple parcels sections -->
    <div id="waybill-items-sections" class="space-y-6">
        <!-- Default: Single parcels section (for backward compatibility) -->
        <div class="waybill-items-section border border-gray-200 rounded-lg p-6 bg-gray-50" data-waybill-index="0">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Waybill #1 Items</h3>
            </div>
            
            <?=
            KIT_Commons::dynamicItemsControl([
                'container_id' => 'custom-waybill-items-0',
                'button_id' => 'add-waybill-item-0',
                'group_name' => 'custom_items',
                'existing_items' => $waybill_items ?? [],
                'input_class' => 'border border-gray-300 rounded px-3 py-2 bg-white',
                'remove_btn_class' => 'bg-red-50 text-white px-3 py-2 rounded hover:bg-red-100 transition-colors duration-200',
                'add_btn_class' => 'bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition-colors duration-200',
                'specialClass' => '!text-[10px]',
                'item_type' => 'waybill',
                'title' => 'Items',
                'subtotal_id' => 'waybill-subtotal-0',
                'show_invoices' => true,
                'waybill_no' => '' // Will be set dynamically from form via JavaScript
            ]);
            ?>
        </div>
    </div>

    <!-- Navigation Buttons -->
    <div class="flex justify-between mt-8">
        <?php echo KIT_Commons::renderButton('Back', 'secondary', 'lg', [
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" />',
            'iconPosition' => 'left',
            'data-target' => 'step-3',
            'classes' => 'prev-step'
        ]); ?>
        
        <?php echo KIT_Commons::renderButton($is_edit_mode ? 'Update Waybill' : 'Create Waybill', 'success', 'lg', [
            'type' => 'submit',
            'classes' => 'submit-btn',
            'id' => 'next-step-3',
            'gradient' => true
        ]);
        ?>

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const vatCheckbox = document.getElementById('vat_include2') || document.getElementById('vat_include');
        const nextButton = document.getElementById('next-step-3');
        const vatMessage = document.getElementById('vat-validation-message');
        const sectionsContainer = document.getElementById('waybill-items-sections');

        function selectText(selectEl) {
            if (!selectEl || !selectEl.options || selectEl.selectedIndex < 0) return '';
            return (selectEl.options[selectEl.selectedIndex].textContent || '').trim();
        }

        function parseMoney(value) {
            if (value === null || value === undefined || value === '') return 0;
            var n = parseFloat(String(value).replace(/,/g, '.').replace(/[^\d.-]/g, ''));
            return Number.isFinite(n) ? n : 0;
        }

        function updateWaybillReview() {
            var setText = function(id, value) {
                var el = document.getElementById(id);
                if (el) el.textContent = value || '—';
            };

            setText('kit-review-waybill', (document.getElementById('waybill_no') || {}).value || '—');

            var company = (document.getElementById('company_name') || {}).value || '';
            var first = (document.getElementById('customer_name') || {}).value || '';
            var last = (document.getElementById('customer_surname') || {}).value || '';
            var person = [first, last].filter(Boolean).join(' ').trim();
            setText('kit-review-customer', company && person ? (company + ' · ' + person) : (company || person || '—'));

            var originCountry = selectText(
                document.getElementById('origin_country_select') ||
                document.getElementById('origin_country') ||
                document.querySelector('select[name="origin_country"]')
            );
            var originCity = selectText(
                document.getElementById('origin_city_select') ||
                document.getElementById('origin_city') ||
                document.querySelector('select[name="origin_city"]')
            );
            setText('kit-review-origin', [originCity, originCountry].filter(Boolean).join(', ') || '—');

            var destCountry = selectText(document.getElementById('stepDestinationSelect'));
            var destCity = selectText(document.getElementById('destination_city'));
            setText('kit-review-destination', [destCity, destCountry].filter(Boolean).join(', ') || '—');

            var pending = document.getElementById('pending_option');
            setText('kit-review-mode', pending && pending.checked ? 'Warehouse' : 'Truck');

            var basisEl = document.querySelector('input[name="charge_basis"]:checked');
            var basis = basisEl ? String(basisEl.value || 'auto') : 'auto';
            setText('kit-review-basis', basis.charAt(0).toUpperCase() + basis.slice(1));

            var mass = parseMoney((document.getElementById('mass_charge') || {}).value);
            var volume = parseMoney((document.getElementById('volume_charge') || {}).value);
            var overrideOn = !!(document.getElementById('enable_total_override') && document.getElementById('enable_total_override').checked);
            var overrideTotal = parseMoney((document.getElementById('override_total') || {}).value);
            var freight = overrideOn && overrideTotal > 0
                ? overrideTotal
                : (basis === 'mass' || basis === 'weight')
                    ? mass
                    : (basis === 'volume' ? volume : Math.max(mass, volume));
            setText('kit-review-freight', 'R ' + freight.toFixed(2));

            var parcelCount = sectionsContainer ? sectionsContainer.querySelectorAll('.dynamic-item').length : 0;
            setText('kit-review-parcels', String(parcelCount));

            var miscCount = document.querySelectorAll('#waybill-misc-sections-container .dynamic-item, #misc-items-0 .dynamic-item').length;
            setText('kit-review-misc', String(miscCount));
        }

        window.kitUpdateWaybillReview = updateWaybillReview;

        function isVatEnabled() {
            return vatCheckbox && vatCheckbox.checked;
        }

        function countWaybillItems() {
            if (!sectionsContainer) return 0;
            const allItemRows = sectionsContainer.querySelectorAll('.dynamic-item');
            return allItemRows.length;
        }

        function validateAndUpdateButton() {
            const hasVat = isVatEnabled();
            const itemCount = countWaybillItems();

            if (hasVat && itemCount === 0) {
                if (vatMessage) vatMessage.style.display = 'block';
                if (nextButton) {
                    nextButton.disabled = true;
                    nextButton.classList.add('opacity-50', 'cursor-not-allowed');
                }
            } else {
                if (vatMessage) vatMessage.style.display = 'none';
                if (nextButton) {
                    nextButton.disabled = false;
                    nextButton.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            }
            updateWaybillReview();
        }

        if (vatCheckbox) {
            vatCheckbox.addEventListener('change', validateAndUpdateButton);
        }

        if (sectionsContainer) {
            const observer = new MutationObserver(function() {
                validateAndUpdateButton();
            });

            observer.observe(sectionsContainer, {
                childList: true,
                subtree: true
            });
        }

        validateAndUpdateButton();

        window.waybillCount = window.waybillCount || 1;
        
        function updateWaybillItemsSections() {
            if (!sectionsContainer) return;
            
            const currentCount = window.waybillCount || 1;
            const existingSections = sectionsContainer.querySelectorAll('.waybill-items-section');
            const existingCount = existingSections.length;
            
            if (currentCount > existingCount) {
                for (let i = existingCount; i < currentCount; i++) {
                    addWaybillItemsSection(i);
                }
            }
            
            if (currentCount < existingCount) {
                for (let i = existingCount - 1; i >= currentCount; i--) {
                    const section = sectionsContainer.querySelector(`[data-waybill-index="${i}"]`);
                    if (section) section.remove();
                }
            }
            updateWaybillReview();
        }
        
        function addWaybillItemsSection(waybillIndex) {
            if (!sectionsContainer) return;
            
            const existing = sectionsContainer.querySelector(`[data-waybill-index="${waybillIndex}"]`);
            if (existing) return;
            
            const section = document.createElement('div');
            section.className = 'waybill-items-section border border-gray-200 rounded-lg p-6 bg-gray-50';
            section.setAttribute('data-waybill-index', waybillIndex);
            
            section.innerHTML = `
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Waybill #${waybillIndex + 1} Items</h3>
                </div>
                <div id="custom-waybill-items-${waybillIndex}">
                    <p class="text-gray-500 text-sm">Items for waybill #${waybillIndex + 1} will be added here</p>
                </div>
            `;
            
            sectionsContainer.appendChild(section);
        }
        
        const parcelsStepElement = document.getElementById('step-5');
        if (parcelsStepElement) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                        if (!parcelsStepElement.classList.contains('hidden')) {
                            updateWaybillItemsSections();
                            updateWaybillReview();
                        }
                    }
                });
            });
            observer.observe(parcelsStepElement, { attributes: true });
        }
        
        updateWaybillItemsSections();
    });
</script>
