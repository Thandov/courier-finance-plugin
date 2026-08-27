<?php
// Determine which charge is greater
$mass_charge = floatval($waybill['mass_charge'] ?? 0);
$volume_charge = floatval($waybill['volume_charge'] ?? 0);
$waybill_no = intval($waybill['waybill_no'] ?? 0);

$is_mass_greater = $mass_charge > $volume_charge;
$is_volume_greater = $volume_charge > $mass_charge;
$is_equal = $mass_charge === $volume_charge;
$preferred_charge = $is_mass_greater ? 'mass' : ($is_volume_greater ? 'volume' : 'mass');
$primary_charge = $preferred_charge === 'mass' ? $mass_charge : $volume_charge;
$vat_charge = 0.0;

// Normalize miscellaneous data (may be serialized string or array)
$rawMisc = $waybill['miscellaneous'] ?? [];
if (!is_array($rawMisc)) {
    $maybeMisc = function_exists('maybe_unserialize') ? maybe_unserialize($rawMisc) : @unserialize($rawMisc);
    $misc_data = is_array($maybeMisc) ? $maybeMisc : [];
} else {
    $misc_data = $rawMisc;
}
$misc_others = (isset($misc_data['others']) && is_array($misc_data['others'])) ? $misc_data['others'] : [];

// Resolve geography from waybill direction + city (not warehouse delivery direction).
$destinationCountryName = trim((string) ($waybill['destination_country'] ?? ''));
if ($destinationCountryName === '' && class_exists('KIT_Routes')) {
    $dest_country_id = (int) ($misc_others['destination_country_id'] ?? ($waybill['destination_country_id'] ?? 0));
    if ($dest_country_id <= 0 && !empty($waybill['direction_id'])) {
        $dest_country_id = KIT_Routes::get_direction_destination_country_id((int) $waybill['direction_id']);
    }
    if ($dest_country_id > 0) {
        $destinationCountryName = (string) KIT_Routes::get_country_name_by_id($dest_country_id);
    }
}
$destinationCityName = class_exists('KIT_Routes')
    ? (string) KIT_Routes::get_city_name_by_id((int) ($waybill['city_id'] ?? ($misc_others['destination_city_id'] ?? 0)))
    : '';

$originCountryName = trim((string) ($waybill['origin_country'] ?? ''));
if ($originCountryName === '' && class_exists('KIT_Routes')) {
    $origin_country_id = (int) ($misc_others['origin_country_id'] ?? ($waybill['origin_country_id'] ?? 0));
    if ($origin_country_id > 0) {
        $originCountryName = (string) KIT_Routes::get_country_name_by_id($origin_country_id);
    }
}
$origin_city_id = (int) ($misc_others['origin_city_id'] ?? 0);
if ($origin_city_id <= 0 && class_exists('KIT_Routes')) {
    $origin_country_for_city = (int) ($misc_others['origin_country_id'] ?? ($waybill['origin_country_id'] ?? 0));
    if ($origin_country_for_city > 0) {
        $origin_city_id = KIT_Routes::get_default_city_id_for_country($origin_country_for_city);
    }
}
$originCityName = ($origin_city_id > 0 && class_exists('KIT_Routes'))
    ? (string) KIT_Routes::get_city_name_by_id($origin_city_id)
    : '';

$kit_format_place = static function ($country, $city): string {
    $parts = [];
    foreach ([$country, $city] as $part) {
        $part = trim((string) $part);
        if ($part === '' || strcasecmp($part, 'N/A') === 0 || strcasecmp($part, 'null') === 0) {
            continue;
        }
        $parts[] = $part;
    }
    return $parts !== [] ? implode(', ', $parts) : 'Not set';
};
$originPlaceLabel = $kit_format_place($originCountryName, $originCityName);
$destinationPlaceLabel = $kit_format_place($destinationCountryName, $destinationCityName);

// Safely resolve totals and flags
$waybill_items_total = floatval($waybill['waybill_items_total'] ?? 0);
$include_sad500 = class_exists('KIT_Waybills') && (KIT_Waybills::normalize_flag_int($waybill['include_sad500'] ?? 0) === 1);
$include_sadc = class_exists('KIT_Waybills') && (KIT_Waybills::normalize_flag_int($waybill['include_sadc'] ?? 0) === 1);
$include_vat = class_exists('KIT_Waybills') && (KIT_Waybills::normalize_flag_int($waybill['vat_include'] ?? 0) === 1);

$misc_total = 0.0;
if (isset($misc_data['misc_total'])) {
    $misc_total = floatval($misc_data['misc_total']);
} elseif (isset($misc_data['misc_items']) && is_array($misc_data['misc_items'])) {
    foreach ($misc_data['misc_items'] as $misc_item) {
        $price = isset($misc_item['misc_price']) ? floatval($misc_item['misc_price']) : 0.0;
        $qty = isset($misc_item['misc_quantity']) ? floatval($misc_item['misc_quantity']) : 0.0;
        $misc_total += $price * $qty;
    }
}

$international_price_rands = isset($misc_data['others']['international_price_rands'])
    ? KIT_Waybills::normalize_amount($misc_data['others']['international_price_rands'])
    : 0.0;
$handling_fee = (!$include_vat && $international_price_rands > 0) ? $international_price_rands : 0.0;
$intl_amount = $handling_fee;

$stored_sad500 = isset($misc_data['others']['include_sad500']) ? KIT_Waybills::normalize_amount($misc_data['others']['include_sad500']) : 0.0;
$stored_sadc = isset($misc_data['others']['include_sadc']) ? KIT_Waybills::normalize_amount($misc_data['others']['include_sadc']) : 0.0;

$sad500_amount = $include_sad500 ? ($stored_sad500 > 0 ? $stored_sad500 : floatval(KIT_Waybills::sadc_certificate())) : 0.0;
$sadc_amount = $include_sadc ? ($stored_sadc > 0 ? $stored_sadc : floatval(KIT_Waybills::sad())) : 0.0;
$stored_total = floatval($waybill['product_invoice_amount'] ?? 0);
$additional_charges_total = null;
$additional_charges_summary = null;
$calculated_total = null;

$calculated_items_total = 0.0;
if (!empty($waybill['items']) && is_array($waybill['items'])) {
    foreach ($waybill['items'] as $item) {
        $qty = isset($item['quantity']) ? floatval($item['quantity']) : 0.0;
        $price = isset($item['unit_price']) ? floatval($item['unit_price']) : 0.0;
        $line_total = $qty * $price;
        if (isset($item['total_price']) && is_numeric($item['total_price'])) {
            $line_total = floatval($item['total_price']);
        }
        $calculated_items_total += $line_total;
    }
}
if ($calculated_items_total > 0) {
    $waybill_items_total = $calculated_items_total;
}
$vat_rate_pct = class_exists('KIT_Waybills') ? (float) KIT_Waybills::vatRate() : 10.0;
$vat_charge = ($include_vat && $waybill_items_total > 0)
    ? (class_exists('KIT_Waybills') ? KIT_Waybills::vatCharge($waybill_items_total) : $waybill_items_total * 0.10)
    : 0.0;

$calculation_breakdown = null;
$using_breakdown = false;
if (class_exists('KIT_Waybills') && $waybill_no > 0) {
    $double_calc = KIT_Waybills::doubleCalcWaybillTotal([
        'waybill_no' => $waybill_no,
        'update_if_mismatch' => false,
    ]);
    if (is_array($double_calc) && empty($double_calc['error'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[viewWaybill] doubleCalc breakdown for waybill ' . $waybill_no . ': ' . print_r($double_calc, true));
            error_log('[viewWaybill] raw mass_charge=' . $mass_charge . ' volume_charge=' . $volume_charge . ' misc_total=' . $misc_total . ' waybill_items_total=' . $waybill_items_total);
        }
    }
    if (is_array($double_calc) && empty($double_calc['error'])) {
        $stored_total = floatval($double_calc['db_total'] ?? $stored_total);
        $calculated_total = floatval($double_calc['calc_total'] ?? $calculated_total);
        $calculation_breakdown = $double_calc['breakdown'] ?? null;
        $using_breakdown = is_array($calculation_breakdown);
    }
}

if ($using_breakdown) {
    $base_charges = $calculation_breakdown['base_charges'] ?? [];
    $additional_charges = $calculation_breakdown['additional_charges'] ?? [];
    $totals_section = $calculation_breakdown['totals'] ?? [];

    if (isset($base_charges['mass_charge'])) {
        $mass_charge = floatval($base_charges['mass_charge']);
    }
    if (isset($base_charges['volume_charge'])) {
        $volume_charge = floatval($base_charges['volume_charge']);
    }
    if (isset($base_charges['primary_charge']['amount'])) {
        $primary_charge = floatval($base_charges['primary_charge']['amount']);
    }
    if (isset($base_charges['primary_charge']['basis'])) {
        $preferred_charge = $base_charges['primary_charge']['basis'];
    }

    if (isset($additional_charges['misc_total'])) {
        $misc_total = floatval($additional_charges['misc_total']);
    }
    // Only apply calculator SAD500/SADC when DB flags are on — otherwise a stale breakdown or misc snapshot could show charges with include_sad500 = 0.
    if ($include_sad500 && isset($additional_charges['sad500'])) {
        $sad500_amount = floatval($additional_charges['sad500']);
    } else {
        $sad500_amount = 0.0;
    }
    if ($include_sadc && isset($additional_charges['sadc'])) {
        $sadc_amount = floatval($additional_charges['sadc']);
    } else {
        $sadc_amount = 0.0;
    }
    if (isset($additional_charges['vat'])) {
        $vat_charge = floatval($additional_charges['vat']);
    }
    if (isset($additional_charges['international_price'])) {
        $handling_fee = floatval($additional_charges['international_price']);
        $intl_amount = $handling_fee;
    }
    // Additional charges total should only include SAD500 + SADC (VAT and handling fee are shown separately)
    $additional_charges_total = $sad500_amount + $sadc_amount;
    // "Additional total" should match D. Additional (SAD500 + SADC only, excluding handling fee)
    $additional_charges_summary = $additional_charges_total;

    // Recompute line-item total from breakdown components so gated SAD500/SADC stays consistent (do not trust final_total if flags were off in DB).
    $calculated_total = $primary_charge + $misc_total + ($include_vat ? $vat_charge : $handling_fee) + $sad500_amount + $sadc_amount;
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[viewWaybill] breakdown primary=' . $primary_charge . ' mass=' . $mass_charge . ' volume=' . $volume_charge . ' additional_total=' . $additional_charges_total . ' calc_total=' . $calculated_total);
    }
}

$stored_total = floatval($stored_total);

$total_volume = 0.0;
if (isset($misc_data['others']['total_volume'])) {
    $total_volume = floatval($misc_data['others']['total_volume']);
} elseif (isset($waybill['total_volume'])) {
    $total_volume = floatval($waybill['total_volume']);
}

$total_mass_kg = floatval($waybill['total_mass_kg'] ?? 0);
$mass_rate = ($total_mass_kg > 0) ? ($mass_charge > 0 ? $mass_charge / $total_mass_kg : 0.0) : 0.0;

// Determine preferred charge and calculate totals
if (!$using_breakdown) {
    $epsilon = 0.005;
    $mass_gt = ($mass_charge - $volume_charge) > $epsilon;
    $vol_gt = ($volume_charge - $mass_charge) > $epsilon;
    $preferred_charge = $mass_gt ? 'mass' : ($vol_gt ? 'volume' : 'mass');
    $primary_charge = $preferred_charge === 'mass' ? $mass_charge : $volume_charge;
}

$volume_rate = 0.0;
$volume_display_charge = $volume_charge;
if ($total_volume > 0.0) {
    if ($volume_charge > 0.0) {
        $volume_rate = $volume_charge / $total_volume;
    } elseif (!empty($misc_data['others']['volume_rate_used'])) {
        $volume_rate = floatval($misc_data['others']['volume_rate_used']);
        $volume_display_charge = $volume_rate * $total_volume;
    } else {
        $volume_rate = 0.74;
        $volume_display_charge = $volume_rate * $total_volume;
    }
}

// A charge formula may only be printed when both of its terms are actually known.
// Seeded rows frequently carry a charge with no mass/volume captured, which would
// otherwise render as "— × R 0.00 = R 878.64".
$show_mass_formula = ($total_mass_kg > 0 && $mass_rate > 0);
$show_volume_formula = ($total_volume > 0 && $volume_rate > 0);

// Additional charges total should only include SAD500 + SADC (not VAT or handling fee, as those are shown separately)
$additional_charges_total = $additional_charges_total ?? ($sad500_amount + $sadc_amount);
// For display: D. Additional is the same as additional_charges_total (SAD500 + SADC only)
$additional_charges_display = $additional_charges_total;
// "Additional total" should match D. Additional (SAD500 + SADC only, excluding handling fee)
$additional_charges_summary = $additional_charges_summary ?? $additional_charges_total;
// Calculate total: Primary + Misc + (VAT OR Handling Fee) + Additional (SAD500 + SADC)
$calculated_total = $calculated_total ?? ($primary_charge + $misc_total + ($include_vat ? $vat_charge : $handling_fee) + $additional_charges_total);
$totals_match = abs($calculated_total - $stored_total) < 0.01;
$grand_total_display = $calculated_total;

// Viewing a waybill must never write to it. Reconciliation happens on save
// (KIT_Waybills::update_waybill_action) and via the explicit repair tools; here we
// only report a disagreement so the approver can see it instead of it being
// silently overwritten during a page load.
$totals_stored_total = $stored_total;
$totals_calculated_total = $calculated_total;
$totals_difference = round($calculated_total - $stored_total, 2);

// Shared view-model. Both layouts consume these; neither may re-derive a fact
// the other already shows, or switching layout hides information.
$kit_empty_label = static function (string $value): string {
    $v = trim($value);
    if ($v === '' || strcasecmp($v, 'N/A') === 0 || strcasecmp($v, 'null') === 0 || $v === '-') {
        return 'Not provided';
    }
    return $v;
};

$can_see_prices = class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices();
$waybill_id = (int) ($waybill['id'] ?? ($waybill_id ?? 0));
$edit_url = '?page=08600-Waybill-view&waybill_id=' . $waybill_id . '&edit=true';
$pdf_url = (defined('COURIER_FINANCE_PLUGIN_URL') ? COURIER_FINANCE_PLUGIN_URL : plugin_dir_url(__FILE__) . '../../')
    . 'pdf-generator.php?waybill_no=' . rawurlencode((string) ($waybill['waybill_no'] ?? ''))
    . '&pdf_nonce=' . wp_create_nonce('pdf_nonce');

$pdfVerifier = class_exists('KIT_Waybills') ? KIT_Waybills::pdfVerifier($waybill['waybill_no'] ?? '', null) : [];
$canAccessPDF = !empty($pdfVerifier['soWhat']) && $can_see_prices;
$can_edit = class_exists('KIT_User_Roles') && KIT_User_Roles::can_edit_approved_waybill($waybill['approval'] ?? 'pending');

$approval_status = (string) ($waybill['approval'] ?? 'pending');
$approval_labels = class_exists('KIT_Commons') ? KIT_Commons::approvalStatusLabels() : [];
$approval_label = $approval_labels[$approval_status] ?? $approval_status;
$is_approved = in_array($approval_status, ['approved', 'completed'], true);
$is_invoiced = (isset($waybill['status']) && $waybill['status'] === 'invoiced');
$show_approve_invoice = class_exists('KIT_User_Roles')
    && KIT_User_Roles::can_approve()
    && KIT_User_Roles::can_invoice()
    && !($is_approved && $is_invoiced);

$view_company_id = (int) ($waybill['company_id'] ?? 0);
$view_company_name = trim((string) ($waybill['company_name'] ?? ''));
$is_company_customer = $view_company_id > 0;
if (!$is_company_customer && $view_company_name !== '') {
    $is_company_customer = class_exists('KIT_Company_Customers')
        ? !KIT_Company_Customers::is_placeholder_company($view_company_name)
        : !in_array(strtolower($view_company_name), ['individual', '1ndividual', 'private'], true);
}
$view_vat = trim((string) ($waybill['vat_number'] ?? ''));
$view_cell = trim((string) ($waybill['cell'] ?? ''));
$view_telephone = trim((string) ($waybill['telephone'] ?? ''));
$view_email = trim((string) ($waybill['email_address'] ?? ''));
$view_contact = $view_cell !== '' ? $view_cell : $view_telephone;
$customer_title = $is_company_customer
    ? $kit_empty_label($view_company_name)
    : trim($kit_empty_label((string) ($waybill['customer_name'] ?? '')) . ' ' . $kit_empty_label((string) ($waybill['customer_surname'] ?? '')));
if (trim($customer_title) === 'Not provided Not provided') {
    $customer_title = 'Customer';
}
$client_invoice = trim((string) ($misc_others['client_invoice'] ?? ''));

$delivery_ref = trim((string) ($waybill['delivery_reference'] ?? ''));
$is_pending_delivery = $delivery_ref !== '' && strcasecmp($delivery_ref, 'pending') === 0;
$is_warehouse = !empty($waybill['warehouse']) && ((int) $waybill['warehouse'] === 1 || $waybill['warehouse'] === true);
$effectively_warehouse = $is_warehouse || $is_pending_delivery;

$dispatch_display = '—';
if (!$effectively_warehouse) {
    $d = $waybill['dispatch_date'] ?? '';
    $dispatch_display = $d ? KIT_Commons::viewDate($d, 'Not set') : 'Not set';
}

$driver_name = trim((string) ($waybill['driver_name'] ?? ''));
if ($driver_name === '' && !empty($waybill['truck_driver']) && is_numeric($waybill['truck_driver'])) {
    global $wpdb;
    $driver_name = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}kit_drivers WHERE id = %d LIMIT 1",
        (int) $waybill['truck_driver']
    ));
}
$driver_display = $effectively_warehouse ? '—' : (($driver_name !== '') ? $driver_name : 'Not assigned');
$truck = trim((string) ($waybill['truck_number'] ?? ''));
$truck_display = $effectively_warehouse ? '—' : ($truck !== '' ? $truck : 'Not assigned');

$charge_basis_file = COURIER_FINANCE_PLUGIN_PATH . 'includes/sync/kit-charge-basis.php';
if (file_exists($charge_basis_file)) {
    require_once $charge_basis_file;
}
$raw_basis = $waybill['charge_basis'] ?? '';
if (($raw_basis === '' || $raw_basis === null) && !empty($misc_others['used_charge_basis'])) {
    $raw_basis = $misc_others['used_charge_basis'];
}
$basis_norm = function_exists('kit_seed_normalize_charge_basis')
    ? kit_seed_normalize_charge_basis((string) $raw_basis)
    : strtolower(trim((string) $raw_basis));
$billed_freight = function_exists('kit_seed_total_from_charge_basis')
    ? kit_seed_total_from_charge_basis((float) $mass_charge, (float) $volume_charge, (string) $raw_basis)
    : (float) $primary_charge;

$waybill_description = '';
if (!empty($waybill['description'])) {
    $waybill_description = trim((string) $waybill['description']);
}
if ($waybill_description === '' && !empty($misc_others['waybill_description'])) {
    $waybill_description = trim((string) $misc_others['waybill_description']);
}
$description_lines = [];
if ($waybill_description !== '') {
    $normalized_desc = preg_replace('/\s+/', ' ', $waybill_description);
    if (preg_match('/^(.*?)(?:\s+WB\s*:\s*-?\s*)(.+?)(?:\s+Supplier\s*:\s*-?\s*)(.+?)(?:\s+Date\s*:\s*-?\s*)(.+)$/i', $normalized_desc, $dm)) {
        $goods = trim($dm[1]);
        if ($goods !== '') {
            $description_lines[] = $goods;
        }
        $described_waybill_no = trim($dm[2]);
        if ($described_waybill_no !== '' && $described_waybill_no !== trim((string) ($waybill['waybill_no'] ?? ''))) {
            $description_lines[] = 'Waybill stated in description: ' . $described_waybill_no;
        }
        $description_lines[] = 'Supplier: ' . trim($dm[3]);
        $description_lines[] = 'Document date: ' . trim($dm[4]);
    } else {
        $description_lines[] = $waybill_description;
    }
}

$len = isset($waybill['item_length']) && $waybill['item_length'] !== '' ? floatval($waybill['item_length']) : 0.0;
$wid = isset($waybill['item_width']) && $waybill['item_width'] !== '' ? floatval($waybill['item_width']) : 0.0;
$hei = isset($waybill['item_height']) && $waybill['item_height'] !== '' ? floatval($waybill['item_height']) : 0.0;
$volume_val = $total_volume;
$dims_are_derived_cube = false;
if ($len > 0 && $wid > 0 && $hei > 0 && $volume_val > 0) {
    $sides_equal = abs($len - $wid) < 0.05 && abs($wid - $hei) < 0.05;
    $cube_side_cm = pow($volume_val, 1 / 3) * 100;
    $dims_are_derived_cube = $sides_equal && abs($len - $cube_side_cm) < 0.6;
}

$createdByName  = KIT_Commons::getNameOfUser($waybill['created_by'] ?? 0);
$createdAt      = !empty($waybill['created_at']) ? KIT_Commons::viewDate($waybill['created_at'], '') : '';
$approvedById   = (int) ($waybill['approved_by'] ?? $waybill['approval_userid'] ?? 0);
$approvedByName = $approvedById > 0 ? KIT_Commons::getNameOfUser($approvedById) : '';
$approvedAt     = !empty($waybill['approved_at']) ? KIT_Commons::viewDate($waybill['approved_at'], '') : '';
$lastUpdated    = !empty($waybill['last_updated_at']) ? KIT_Commons::viewDate($waybill['last_updated_at'], '') : $createdAt;
$lastUpdatedById = (int) ($waybill['last_updated_by'] ?? 0);
$lastUpdatedByName = $lastUpdatedById > 0 ? KIT_Commons::getNameOfUser($lastUpdatedById) : '';

$has_misc_items = !empty($misc_data['misc_items']) && is_array($misc_data['misc_items']);

$route_label = 'Not set';
if ($originCountryName !== '' || $destinationCountryName !== '') {
    $route_label = trim($originCountryName !== '' ? $originCountryName : 'Origin')
        . ' → '
        . trim($destinationCountryName !== '' ? $destinationCountryName : 'Destination');
}

require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/components/edit-waybill/kit-edit-waybill-templates.php';

$kit_edit_template_id = kit_edit_waybill_get_active_template_id();
$kit_view_template_id = ($kit_edit_template_id === 'edit_waybill_template2')
    ? 'view_waybill_template2'
    : 'view_waybill_template1';
$kit_view_template_path = COURIER_FINANCE_PLUGIN_PATH . 'includes/components/view-waybill/' . $kit_view_template_id . '.php';
if (!is_readable($kit_view_template_path)) {
    $kit_view_template_id = 'view_waybill_template1';
    $kit_view_template_path = COURIER_FINANCE_PLUGIN_PATH . 'includes/components/view-waybill/view_waybill_template1.php';
}

if (function_exists('wp_enqueue_style')) {
    if ($kit_view_template_id === 'view_waybill_template2') {
        wp_enqueue_style(
            'kit-waybill-template2',
            COURIER_FINANCE_PLUGIN_URL . 'assets/css/kit-waybill-template2.css',
            [],
            '1.0.2'
        );
    }
    wp_enqueue_style(
        'kit-waybill-print',
        COURIER_FINANCE_PLUGIN_URL . 'assets/css/kit-waybill-print.css',
        $kit_view_template_id === 'view_waybill_template2' ? ['kit-waybill-template2'] : [],
        '1.0.0'
    );
    if (function_exists('wp_print_styles')) {
        $print_handles = ['kit-waybill-print'];
        if ($kit_view_template_id === 'view_waybill_template2') {
            array_unshift($print_handles, 'kit-waybill-template2');
        }
        wp_print_styles($print_handles);
    }
}

kit_edit_waybill_render_switcher($kit_edit_template_id);

// Rendered here rather than inside a template so both layouts always surface a
// totals disagreement identically.
if (!$totals_match && class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()) : ?>
    <div class="bg-yellow-50 border border-yellow-400 border-l-4 p-4 mb-4" role="alert">
        <p class="text-sm font-semibold text-yellow-800">Totals do not agree</p>
        <p class="text-sm text-yellow-700 mt-1">
            The stored invoice amount is <strong><?= esc_html(KIT_Commons::money($totals_stored_total)) ?></strong>,
            but recalculating this waybill's charges gives <strong><?= esc_html(KIT_Commons::money($totals_calculated_total)) ?></strong>
            (a difference of <strong><?= esc_html(KIT_Commons::money(abs($totals_difference))) ?></strong>).
            Figures below show the recalculated amount. Save this waybill to bring the stored amount in line.
        </p>
    </div>
<?php endif;

require $kit_view_template_path;
