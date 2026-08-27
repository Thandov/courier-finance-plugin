<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Edit waybill template 2 — flat document + sticky totals rail.
 * Same POST fields and IDs as template 1 so save / kitscript stay unchanged.
 */

$charge_basis_file = COURIER_FINANCE_PLUGIN_PATH . 'includes/sync/kit-charge-basis.php';
if (file_exists($charge_basis_file)) {
    require_once $charge_basis_file;
}

$currency = class_exists('KIT_Commons') ? KIT_Commons::currency() : 'R';
$view_url = '?page=08600-Waybill-view&waybill_id=' . (int) $waybill_id;

$approval_status = (string) ($waybill['approval'] ?? 'pending');
$badge_class = match ($approval_status) {
    'pending'  => 'kit-t2-status--pending',
    'rejected' => 'kit-t2-status--rejected',
    default    => 'kit-t2-status--ok',
};
$approver = trim((string) ($waybill['approved_by_username'] ?? ''));
$updated_label = !empty($waybill['last_updated_at']) ? date('j M Y', strtotime($waybill['last_updated_at'])) : '—';

$edit_delivery_ref = trim((string) ($waybill['delivery_reference'] ?? ''));
$edit_is_pending_delivery = $edit_delivery_ref !== '' && strcasecmp($edit_delivery_ref, 'pending') === 0;
$edit_is_warehouse = !empty($waybill['warehouse']) && (int) $waybill['warehouse'] === 1;
$edit_effectively_warehouse = $edit_is_warehouse || $edit_is_pending_delivery;
$edit_truck = trim((string) ($waybill['truck_number'] ?? ''));

$origin_country = trim((string) ($waybill['origin_country'] ?? ''));
$destination_country = trim((string) ($waybill['destination_country'] ?? ''));
$origin_country_id = (int) ($waybill['miscellaneous']['others']['origin_country_id'] ?? 0);
$destination_country_id = (int) ($waybill['miscellaneous']['others']['destination_country_id'] ?? 0);
if ($origin_country_id <= 0) {
    $origin_country_id = (int) ($waybill['origin_country_id'] ?? 0);
}
if ($destination_country_id <= 0) {
    $destination_country_id = (int) ($waybill['destination_country_id'] ?? 0);
}
if ($origin_country === '' && $origin_country_id > 0 && class_exists('KIT_Routes')) {
    $origin_country = (string) KIT_Routes::get_country_name_by_id($origin_country_id);
}
if ($destination_country === '' && $destination_country_id > 0 && class_exists('KIT_Routes')) {
    $destination_country = (string) KIT_Routes::get_country_name_by_id($destination_country_id);
}
$route_line = ($origin_country === '' && $destination_country === '')
    ? 'Route not set'
    : (($origin_country !== '' ? $origin_country : 'Origin') . ' → ' . ($destination_country !== '' ? $destination_country : 'Destination'));

$waybill_description_value = '';
if (!empty($waybill['description'])) {
    $waybill_description_value = $waybill['description'];
} elseif (!empty($waybill['miscellaneous']['others']['waybill_description'])) {
    $waybill_description_value = $waybill['miscellaneous']['others']['waybill_description'];
}

$customer_city_id = $waybill['customer_city_id'] ?? '';
if ($customer_city_id === '' && !empty($waybill['customer_id'])) {
    $customer_city_id = $wpdb->get_var($wpdb->prepare(
        "SELECT city_id FROM {$wpdb->prefix}kit_customers WHERE cust_id = %d LIMIT 1",
        (int) $waybill['customer_id']
    ));
}
$customer_city_id = ($customer_city_id !== null && $customer_city_id !== '') ? (int) $customer_city_id : '';
$city_name = '';
if (!empty($customer_city_id) && class_exists('KIT_Routes')) {
    $city_name = (string) KIT_Routes::get_city_name_by_id($customer_city_id);
}

$kit_edit_empty = static function (string $value): string {
    $v = trim($value);
    if ($v === '' || strcasecmp($v, 'N/A') === 0 || strcasecmp($v, 'null') === 0 || $v === '-') {
        return 'Not provided';
    }
    return $v;
};
$edit_contact = trim((string) ($waybill['cell'] ?? ''));
if ($edit_contact === '') {
    $edit_contact = trim((string) ($waybill['telephone'] ?? ''));
}
$edit_email = trim((string) ($waybill['email_address'] ?? ''));
$customer_title = $is_business
    ? $kit_edit_empty($edit_company_name)
    : trim($kit_edit_empty((string) ($waybill['customer_name'] ?? '')) . ' ' . $kit_edit_empty((string) ($waybill['customer_surname'] ?? '')));
if (strpos($customer_title, 'Not provided') === 0 && trim($customer_title) === 'Not provided Not provided') {
    $customer_title = 'Customer';
}

$charge_basis_options = [
    'mass'   => 'Mass',
    'volume' => 'Volume',
    'auto'   => 'Higher',
];
$raw_basis = $waybill['charge_basis'] ?? '';
if (($raw_basis === '' || $raw_basis === null) && !empty($waybill['miscellaneous']['others']['used_charge_basis'])) {
    $raw_basis = $waybill['miscellaneous']['others']['used_charge_basis'];
}
$current_charge_basis = strtolower(trim((string) $raw_basis));
if (!array_key_exists($current_charge_basis, $charge_basis_options)) {
    $current_charge_basis = 'auto';
}

$mass_charge_val = floatval($waybill['mass_charge'] ?? 0);
$volume_charge_val = floatval($waybill['volume_charge'] ?? 0);
$billed_freight = function_exists('kit_seed_total_from_charge_basis')
    ? kit_seed_total_from_charge_basis($mass_charge_val, $volume_charge_val, (string) $raw_basis)
    : floatval($waybill['product_invoice_amount'] ?? 0);

$edit_hidden_mass_rate = floatval($waybill['miscellaneous']['others']['mass_rate'] ?? 0);
if ($edit_hidden_mass_rate <= 0) {
    $edit_mass_kg = floatval($waybill['total_mass_kg'] ?? 0);
    if ($edit_mass_kg > 0 && $mass_charge_val > 0) {
        $edit_hidden_mass_rate = $mass_charge_val / $edit_mass_kg;
    }
}

$misc = !empty($waybill['miscellaneous']) && is_array($waybill['miscellaneous']) ? $waybill['miscellaneous'] : [];
$misc_items = [];
if (!empty($misc['misc_items']) && is_array($misc['misc_items'])) {
    foreach ($misc['misc_items'] as $item) {
        $name     = isset($item['misc_item']) ? sanitize_text_field($item['misc_item']) : '';
        $price    = isset($item['misc_price']) ? floatval($item['misc_price']) : 0;
        $quantity = isset($item['misc_quantity']) ? intval($item['misc_quantity']) : 1;
        $misc_items[] = [
            'misc_item'     => $name,
            'misc_price'    => $price,
            'misc_quantity' => $quantity,
            'misc_subtotal' => $price * $quantity,
        ];
    }
}

$dispatch_display = '—';
if (!$edit_effectively_warehouse) {
    $d = $waybill['dispatch_date'] ?? '';
    $dispatch_display = $d ? date('d-m-Y', strtotime($d)) : 'Not set';
}
$driver_display = $edit_effectively_warehouse ? '—' : (($driver_name !== '' && $driver_name !== 'N/A') ? $driver_name : 'Not assigned');
$truck_display = $edit_effectively_warehouse ? '—' : ($edit_truck !== '' ? $edit_truck : 'Not assigned');

$fmt = static function (float $n) use ($currency): string {
    return $currency . ' ' . number_format($n, 2);
};
?>
<style>
    #wpfooter { position: relative !important; margin-top: 40px !important; }
    .kit-route-info-fields select { max-width: none !important; width: 100%; }
</style>

<div class="kit-t2">
    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="update_waybill_action">
        <input type="hidden" name="waybill_id" value="<?php echo esc_attr($waybill_id); ?>">
        <input type="hidden" name="waybill_no" value="<?php echo esc_attr($waybill['waybill_no']); ?>">
        <input type="hidden" name="cust_id" value="<?php echo esc_attr($waybill['customer_id']); ?>">
        <input type="hidden" name="direction_id" value="<?php echo esc_attr($waybill['direction_id']); ?>">
        <input type="hidden" name="current_rate" value="<?php echo esc_attr($edit_hidden_mass_rate > 0 ? number_format($edit_hidden_mass_rate, 2, '.', '') : ''); ?>">
        <input type="hidden" name="kit_waybill_misc_ui" value="1">
        <?php wp_nonce_field('update_waybill_nonce'); ?>

        <div class="kit-t2-bar">
            <div>
                <div class="kit-t2-wb"><?php echo esc_html($waybill['waybill_no']); ?></div>
                <div class="kit-t2-meta">
                    <span class="kit-t2-status <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($approval_status); ?></span>
                    <span>
                        <?php
                        if (in_array($approval_status, ['approved', 'completed'], true) && $approver !== '') {
                            echo esc_html('Approved by ' . $approver);
                        } elseif ($approval_status === 'rejected' && $approver !== '') {
                            echo esc_html('Rejected by ' . $approver);
                        } elseif ($approval_status === 'pending') {
                            echo 'Awaiting approval';
                        } elseif ($approver !== '') {
                            echo esc_html(ucfirst($approval_status) . ' by ' . $approver);
                        }
                        ?>
                    </span>
                    <span>Saved <?php echo esc_html($updated_label); ?></span>
                </div>
            </div>
            <div class="kit-t2-actions">
                <?php echo KIT_Commons::renderButton('Cancel', 'secondary', 'lg', ['type' => 'button', 'href' => $view_url, 'noLoading' => true]); ?>
                <?php echo KIT_Commons::renderButton('Save', 'primary', 'lg', ['type' => 'submit']); ?>
            </div>
        </div>

        <div class="kit-t2-context">
            <span>Dispatch <b><?php echo esc_html($dispatch_display); ?></b></span>
            <span>Trip
                <b><?php
                if ($edit_effectively_warehouse) {
                    echo 'Warehouse';
                } elseif ($edit_delivery_ref !== '' && !empty($waybill['delivery_id'])) {
                    echo '<a href="' . esc_url('?page=view-deliveries&delivery_id=' . (int) $waybill['delivery_id']) . '" target="_blank" rel="noopener">' . esc_html($edit_delivery_ref) . '</a>';
                } else {
                    echo esc_html($edit_delivery_ref !== '' ? $edit_delivery_ref : 'Not assigned');
                }
                ?></b>
            </span>
            <span>Lane <b><?php echo esc_html($route_line); ?></b></span>
            <span>Driver <b><?php echo esc_html($driver_display); ?></b></span>
            <span>Truck <b><?php echo esc_html($truck_display); ?></b></span>
        </div>

        <div class="kit-t2-shell">
            <div class="kit-t2-doc">
                <section class="kit-t2-sec">
                    <div class="kit-t2-kicker">Customer</div>
                    <h2 class="kit-t2-title"><?php echo esc_html($customer_title); ?></h2>
                    <p class="kit-t2-sub">
                        <?php echo esc_html($kit_edit_empty($edit_email)); ?>
                        · <?php echo esc_html($kit_edit_empty($edit_contact)); ?>
                        · <?php echo esc_html($kit_edit_empty((string) $city_name)); ?>
                    </p>
                    <div id="customer-info-display-edit" class="kit-t2-facts">
                        <?php if ($is_business) : ?>
                            <div><dt>Company</dt><dd><?php echo esc_html($kit_edit_empty($edit_company_name)); ?></dd></div>
                            <div><dt>VAT</dt><dd><?php echo esc_html($kit_edit_empty((string) ($waybill['vat_number'] ?? ''))); ?></dd></div>
                        <?php else : ?>
                            <div><dt>Name</dt><dd><?php echo esc_html($kit_edit_empty((string) ($waybill['customer_name'] ?? ''))); ?></dd></div>
                            <div><dt>Surname</dt><dd><?php echo esc_html($kit_edit_empty((string) ($waybill['customer_surname'] ?? ''))); ?></dd></div>
                        <?php endif; ?>
                    </div>
                    <?php echo KIT_Commons::renderButton('Edit customer', 'ghost', 'sm', [
                        'type' => 'button',
                        'id' => 'toggle-customer-form-edit',
                        'classes' => 'mt-3 text-sm',
                        'contentId' => 'toggle-customer-form-text-edit',
                        'iconId' => 'toggle-customer-form-icon-edit',
                    ]); ?>
                    <div id="customer-form-edit" class="hidden min-w-0 mt-4">
                        <?php
                        $customer_for_form = [
                            'cust_id'          => $waybill['customer_id'] ?? '',
                            'company_id'       => $waybill['company_id'] ?? '',
                            'company_name'     => $waybill['company_name'] ?? '',
                            'name'             => $waybill['customer_name'] ?? '',
                            'customer_name'    => $waybill['customer_name'] ?? '',
                            'surname'          => $waybill['customer_surname'] ?? '',
                            'customer_surname' => $waybill['customer_surname'] ?? '',
                            'cell'             => $waybill['cell'] ?? '',
                            'email_address'    => $waybill['email_address'] ?? '',
                            'address'          => $waybill['address'] ?? '',
                            'vat_number'       => $waybill['vat_number'] ?? '',
                            'country_id'       => $waybill['country_id'] ?? '',
                            'city_id'          => $customer_city_id,
                        ];
                        if (is_array($waybill['customer'] ?? null)) {
                            $customer_for_form = array_merge($customer_for_form, $waybill['customer']);
                        }
                        theForm($customer_for_form, $waybill['customer_id'] ?? null);
                        ?>
                    </div>
                </section>

                <section class="kit-t2-sec">
                    <div class="kit-t2-kicker">Route</div>
                    <div class="kit-t2-route-grid kit-route-info-fields min-w-0">
                        <div class="kit-t2-route-col">
                            <div class="kit-t2-route-end">From</div>
                            <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsOrigin.php'; ?>
                        </div>
                        <div class="kit-t2-route-arrow" aria-hidden="true">→</div>
                        <div class="kit-t2-route-col">
                            <div class="kit-t2-route-end">To</div>
                            <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsDestination.php'; ?>
                        </div>
                    </div>
                    <div class="kit-t2-route-assign kit-route-info-fields">
                        <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsRoute.php'; ?>
                    </div>
                    <div class="mt-4">
                        <?php echo KIT_Commons::TextAreaField([
                            'label' => 'Description',
                            'name'  => 'waybill_description',
                            'id'    => 'waybill_description',
                            'type'  => 'textarea',
                            'value' => $waybill_description_value,
                            'height' => '72',
                        ]); ?>
                    </div>
                </section>

                <section class="kit-t2-sec">
                    <div class="kit-t2-kicker">Cargo</div>
                    <div class="kit-t2-cargo">
                        <div class="kit-t2-cargo-block">
                            <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/weight.php'; ?>
                        </div>
                        <div class="kit-t2-cargo-block">
                            <?php
                            $dimensions_waybill = $waybill;
                            require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/dimensions.php';
                            ?>
                        </div>
                    </div>
                    <div class="mt-6">
                        <?php echo KIT_Commons::dynamicItemsControl([
                            'container_id'     => 'custom-waybill-items',
                            'button_id'        => 'add-waybill-item',
                            'group_name'       => 'custom_items',
                            'existing_items'   => isset($waybill['items']) && is_array($waybill['items']) ? $waybill['items'] : [],
                            'input_class'      => 'border border-gray-300 rounded px-3 py-2 bg-white',
                            'remove_btn_class' => 'bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600',
                            'add_btn_class'    => 'bg-gray-900 text-white px-4 py-2 rounded hover:bg-black',
                            'specialClass'     => '!text-[10px]',
                            'item_type'        => 'waybill',
                            'title'            => 'Parcels',
                            'description'      => 'Lines on this waybill',
                            'subtotal_id'      => 'waybill-subtotal',
                            'currency_symbol'  => $currency,
                            'show_invoices'    => false,
                            'waybill_no'       => $waybill['waybill_no'],
                        ]); ?>
                    </div>
                </section>

                <section class="kit-t2-sec">
                    <div class="kit-t2-kicker">Extras</div>
                    <div class="addCharges">
                        <?php
                        $optionChoice = 2;
                        require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/additionCharges.php';
                        ?>
                    </div>
                    <div class="mt-4">
                        <?php echo KIT_Commons::dynamicItemsControl([
                            'container_id'    => 'misc-items',
                            'button_id'       => 'add-misc-item',
                            'group_name'      => 'misc',
                            'input_class'     => 'border border-gray-300 rounded px-3 py-2 bg-white',
                            'existing_items'  => $misc_items,
                            'item_type'       => 'misc',
                            'title'           => 'Miscellaneous',
                            'description'     => 'Other billed lines',
                            'subtotal_id'     => 'misc-total',
                            'currency_symbol' => $currency,
                            'show_subtotal'   => true,
                            'show_invoices'   => false,
                        ]); ?>
                    </div>
                </section>
            </div>

            <aside class="kit-t2-rail" aria-label="Waybill totals">
                <h2>What we charge</h2>
                <div class="kit-t2-row<?php echo $current_charge_basis === 'mass' ? ' is-active' : ''; ?>">
                    <span>Mass charge</span>
                    <span id="kit-t2-mass-charge"><?php echo esc_html($fmt($mass_charge_val)); ?></span>
                </div>
                <div class="kit-t2-row<?php echo $current_charge_basis === 'volume' ? ' is-active' : ''; ?>">
                    <span>Volume charge</span>
                    <span id="kit-t2-volume-charge"><?php echo esc_html($fmt($volume_charge_val)); ?></span>
                </div>

                <div class="kit-t2-basis" role="radiogroup" aria-label="Charge basis">
                    <?php foreach ($charge_basis_options as $value => $label) :
                        $input_id = 'charge_basis_' . $value;
                        $checked = ($current_charge_basis === $value) ? 'checked' : '';
                        ?>
                        <input type="radio" name="charge_basis" id="<?php echo esc_attr($input_id); ?>" value="<?php echo esc_attr($value); ?>" class="charge-basis-radio" <?php echo $checked; ?>>
                        <label for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($label); ?></label>
                    <?php endforeach; ?>
                </div>
                <p class="kit-t2-hint">Choose Mass or Volume. Higher uses whichever amount is more.</p>

                <div class="kit-t2-row">
                    <span>Billed freight</span>
                    <span id="kit-t2-billed-freight"><?php echo esc_html($fmt($billed_freight)); ?></span>
                </div>

                <div id="totalOverride">
                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/totalOverride.php'; ?>
                </div>

                <div class="kit-t2-total">
                    <span>Total</span>
                    <strong><?php echo esc_html($currency); ?> <span id="waybilltotalMockup"><?php echo esc_html(number_format((float) ($waybill['product_invoice_amount'] ?? 0), 2)); ?></span></strong>
                </div>
            </aside>
        </div>

        <div class="kit-t2-foot">
            <span class="kit-t2-foot-total"><?php echo esc_html($currency); ?> <span class="kit-t2-foot-mock"><?php echo esc_html(number_format((float) ($waybill['product_invoice_amount'] ?? 0), 2)); ?></span></span>
            <?php echo KIT_Commons::renderButton('Save changes', 'primary', 'lg', ['type' => 'submit']); ?>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('toggle-customer-form-edit');
    const toggleText = document.getElementById('toggle-customer-form-text-edit');
    const toggleIcon = document.getElementById('toggle-customer-form-icon-edit');
    const customerForm = document.getElementById('customer-form-edit');
    const customerDisplay = document.getElementById('customer-info-display-edit');

    function setCustomerFormActive(enabled) {
        if (!customerForm) return;
        customerForm.querySelectorAll('input, select, textarea, button').forEach(function (el) {
            if (el.id === 'toggle-customer-form-edit') return;
            if (enabled) {
                if (el.getAttribute('data-kit-customer-disabled') === '1') {
                    el.disabled = false;
                    el.removeAttribute('data-kit-customer-disabled');
                }
            } else if (!el.disabled) {
                el.setAttribute('data-kit-customer-disabled', '1');
                el.disabled = true;
            }
        });
        customerForm.querySelectorAll('[required], [data-kit-was-required="1"]').forEach(function (el) {
            if (enabled) {
                if (el.getAttribute('data-kit-was-required') === '1' || el.hasAttribute('required')) {
                    el.setAttribute('required', 'required');
                    el.removeAttribute('data-kit-was-required');
                }
            } else if (el.hasAttribute('required')) {
                el.setAttribute('data-kit-was-required', '1');
                el.removeAttribute('required');
            }
        });
    }

    const editWaybillForm = customerForm ? customerForm.closest('form') : null;
    if (customerForm && customerForm.classList.contains('hidden')) {
        setCustomerFormActive(false);
    }
    if (editWaybillForm && customerForm) {
        editWaybillForm.addEventListener('submit', function () {
            if (customerForm.classList.contains('hidden')) {
                setCustomerFormActive(false);
            }
        });
    }
    if (toggleBtn && customerForm && customerDisplay) {
        toggleBtn.addEventListener('click', function () {
            const isHidden = customerForm.classList.contains('hidden');
            if (isHidden) {
                customerForm.classList.remove('hidden');
                customerDisplay.classList.add('hidden');
                setCustomerFormActive(true);
                if (toggleText) toggleText.textContent = 'Hide customer';
                if (toggleIcon) toggleIcon.style.transform = 'rotate(180deg)';
            } else {
                customerForm.classList.add('hidden');
                customerDisplay.classList.remove('hidden');
                setCustomerFormActive(false);
                if (toggleText) toggleText.textContent = 'Edit customer';
                if (toggleIcon) toggleIcon.style.transform = 'rotate(0deg)';
            }
        });
    }

    const money = function (n) {
        const v = Number.isFinite(n) ? n : 0;
        return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    const currency = <?php echo wp_json_encode($currency); ?>;
    function billedFreight(mass, volume, basis) {
        if (basis === 'mass' || basis === 'weight') return mass;
        if (basis === 'volume') return volume;
        return Math.max(mass, volume);
    }
    function syncRail() {
        const mass = parseFloat(String(document.getElementById('mass_charge')?.value || '0').replace(',', '.')) || 0;
        const volume = parseFloat(String(document.getElementById('volume_charge')?.value || '0').replace(',', '.')) || 0;
        const basis = document.querySelector('input[name="charge_basis"]:checked')?.value || 'auto';
        const massEl = document.getElementById('kit-t2-mass-charge');
        const volEl = document.getElementById('kit-t2-volume-charge');
        const billEl = document.getElementById('kit-t2-billed-freight');
        if (massEl) massEl.textContent = currency + ' ' + money(mass);
        if (volEl) volEl.textContent = currency + ' ' + money(volume);
        if (billEl) billEl.textContent = currency + ' ' + money(billedFreight(mass, volume, basis));
        const mock = document.getElementById('waybilltotalMockup');
        const foot = document.querySelector('.kit-t2-foot-mock');
        if (mock && foot) foot.textContent = mock.textContent;
    }
    ['mass_charge', 'volume_charge'].forEach(function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', syncRail);
        el.addEventListener('change', syncRail);
    });
    document.querySelectorAll('.charge-basis-radio').forEach(function (radio) {
        radio.addEventListener('change', syncRail);
    });
    const mock = document.getElementById('waybilltotalMockup');
    if (mock && typeof MutationObserver !== 'undefined') {
        new MutationObserver(syncRail).observe(mock, { childList: true, characterData: true, subtree: true });
    }
    document.addEventListener('kit:waybill-total-dirty', syncRail);
    syncRail();
});
</script>
