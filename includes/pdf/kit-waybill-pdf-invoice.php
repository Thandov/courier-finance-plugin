<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Default PDF invoice layout (simple client skeleton).
 */
function kit_waybill_pdf_default_template_id(): string
{
    return 'invoice_template4';
}

/**
 * @return list<string>
 */
function kit_waybill_pdf_allowed_template_ids(): array
{
    return ['invoice_template1', 'invoice_template2', 'invoice_template4'];
}

function kit_waybill_pdf_get_active_template_id(): string
{
    $template_id = function_exists('get_option')
        ? get_option('kit_pdf_invoice_template', kit_waybill_pdf_default_template_id())
        : kit_waybill_pdf_default_template_id();

    if (!is_string($template_id) || $template_id === '') {
        $template_id = kit_waybill_pdf_default_template_id();
    }

    if (!in_array($template_id, kit_waybill_pdf_allowed_template_ids(), true)) {
        $template_id = kit_waybill_pdf_default_template_id();
    }

    return $template_id;
}

function kit_waybill_pdf_template_path(string $template_id): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . $template_id . '.php';
}

/**
 * Load waybill/company data and computed totals for invoice templates.
 *
 * @return array<string, mixed>
 */
function kit_waybill_pdf_prepare_invoice_context(int $waybill_no): array
{
    $plugin_root = kit_waybill_pdf_plugin_root();

    // Color scheme (Settings & Configuration 60/30/10) - Load from dashboard settings
    $primary_color = '#2563eb';
    $secondary_color = '#111827';
    $accent_color = '#10b981';
    $darkBadge = '#043c7d';
    $lightBadge = '#e0e7ef';

    // Always try to load from colorSchema.json first
    $schema_path = $plugin_root . 'colorSchema.json';
    if (file_exists($schema_path)) {
      $schema_data = json_decode(file_get_contents($schema_path), true);
      if (is_array($schema_data)) {
        if (!empty($schema_data['primary'])) {
          $primary_color = $schema_data['primary'];
        }
        if (!empty($schema_data['secondary'])) {
          $secondary_color = $schema_data['secondary'];
        }
        if (!empty($schema_data['accent'])) {
          $accent_color = $schema_data['accent'];
        }
      }
    }

    // Fallback to KIT_Commons methods if available
    if (class_exists('KIT_Commons')) {
      try {
        if (method_exists('KIT_Commons', 'getPrimaryColor')) {
          $primary_color = KIT_Commons::getPrimaryColor();
        }
        if (method_exists('KIT_Commons', 'getSecondaryColor')) {
          $secondary_color = KIT_Commons::getSecondaryColor();
        } elseif (method_exists('KIT_Commons', 'getSecondaryColorCanonical')) {
          $secondary_color = KIT_Commons::getSecondaryColorCanonical();
        }
        if (method_exists('KIT_Commons', 'getAccentColor')) {
          $accent_color = KIT_Commons::getAccentColor();
        } elseif (method_exists('KIT_Commons', 'getAccentColorCanonical')) {
          $accent_color = KIT_Commons::getAccentColorCanonical();
        }
      } catch (Throwable $e) {
        // keep defaults
      }
    }

        $pTextColor = $primary_color;
        $stroke_color = $primary_color;

        // Load waybill with items
        if (!class_exists('KIT_Waybills')) {
            throw new RuntimeException('KIT_Waybills class not found.');
        }

    $quotation = KIT_Waybills::getFullWaybillWithItems($waybill_no);

    if (!$quotation || !isset($quotation->waybill)) {
      throw new RuntimeException('Waybill ' . $waybill_no . ' not found in database.');
    }

    // Generate QR code for waybill
    $qr_code_data = !empty($quotation->waybill['qr_code_data']) 
        ? $quotation->waybill['qr_code_data'] 
        : KIT_Waybills::generate_qr_code_data($waybill_no);
    $qr_code_image = '';
    if (!empty($qr_code_data)) {
        $qr_code_image = KIT_Waybills::generate_qr_code_image($qr_code_data, 200);
    }


    // Convert waybill to array and handle null values
    $waybill = (array) $quotation->waybill;
    $waybill = array_map(function ($value) {
      return $value === null ? '' : $value;
    }, $waybill);

    // Convert back to object for compatibility
    $waybill = (object) $waybill;

    $waybillItems = isset($quotation->items) && is_array($quotation->items) ? $quotation->items : false;


    // Company details (name, address, VAT, banking) — hardcoded 08600 identity merged with DB
    $company = class_exists('KIT_Company') ? KIT_Company::get_details_array() : [];
    // Use stored snapshot where possible to ensure PDF matches UI exactly
    $mass_charge = floatval($waybill->mass_charge ?? 0);
    $volume_charge = floatval($waybill->volume_charge ?? 0);

    // NEW: Read calculated totals from DB columns first (faster, more reliable)
    $misc_total_from_db = isset($waybill->misc_total) ? floatval($waybill->misc_total) : null;
    $border_clearing_total_from_db = isset($waybill->border_clearing_total) ? floatval($waybill->border_clearing_total) : null;
    $sad500_total_from_db = isset($waybill->sad500_amount) ? floatval($waybill->sad500_amount) : null;
    $sadc_total_from_db = isset($waybill->sadc_amount) ? floatval($waybill->sadc_amount) : null;
    $international_price_from_db = isset($waybill->international_price_rands) ? floatval($waybill->international_price_rands) : null;

    $stored_basis = '';
    $stored_volume_rate = 0.0;
    $stored_mass_rate = 0.0;
    if (!empty($waybill->miscellaneous)) {
      $md = maybe_unserialize($waybill->miscellaneous);
      if (is_array($md) && isset($md['others'])) {
        $stored_basis = isset($md['others']['used_charge_basis']) ? $md['others']['used_charge_basis'] : '';
        $stored_volume_rate = isset($md['others']['volume_rate_used']) ? floatval($md['others']['volume_rate_used']) : 0.0;
        if (isset($md['others']['mass_rate'])) {
          $stored_mass_rate = floatval($md['others']['mass_rate']);
        }
      }
    }

    // Priority: Manual override from waybill > Stored snapshot > Automatic selection
    $charge_basis = '';
    if (!empty($waybill->charge_basis)) {
      // Use manual override from waybill if set
      $charge_basis = $waybill->charge_basis;
    } elseif (!empty($stored_basis)) {
      // Use stored snapshot if available
      $charge_basis = $stored_basis;
    } else {
      // Automatic selection: use the higher charge
      $charge_basis = ($mass_charge > $volume_charge) ? 'mass' : 'volume';
    }
    $charge_basis = kit_waybill_pdf_normalize_charge_basis($charge_basis);

    if ($charge_basis === 'mass') {
      $charge = $mass_charge;
    } else {
      $charge = $volume_charge;
    }
    // Use DB column only (no calculations)
    $misc_total = ($misc_total_from_db !== null) ? floatval($misc_total_from_db) : 0.0;
    $misc_data = [];
    $misc_items = [];

    // Only unserialize miscellaneous for display purposes (misc_items array)
    if (!empty($waybill->miscellaneous)) {
      $misc_data = maybe_unserialize($waybill->miscellaneous);
      if (is_array($misc_data) && isset($misc_data['misc_items']) && is_array($misc_data['misc_items'])) {
        $misc_items = $misc_data['misc_items'];
      }
    }

    // If the misc_total DB column is stale (0) while misc line items exist in the
    // serialized snapshot, sum the items so TOTAL DUE matches the printed lines.
    if ($misc_total <= 0 && !empty($misc_items)) {
      $misc_items_sum = 0.0;
      foreach ($misc_items as $mi) {
        if (is_object($mi)) {
          $mi = (array) $mi;
        }
        $mi_qty = isset($mi['misc_quantity']) ? floatval($mi['misc_quantity']) : 1.0;
        $mi_price = isset($mi['misc_price']) ? floatval($mi['misc_price']) : 0.0;
        $misc_items_sum += $mi_qty * $mi_price;
      }
      if ($misc_items_sum > 0) {
        $misc_total = $misc_items_sum;
      }
    }

    // Use DB columns only (no calculations)
    $sad500_total = 0.0;
    if (!empty($waybill->include_sad500)) {
      if ($sad500_total_from_db !== null && $sad500_total_from_db > 0) {
        $sad500_total = floatval($sad500_total_from_db);
      } elseif (class_exists('KIT_Waybills')) {
        // Fallback to configured/default SAD500 charge when DB snapshot is missing/zero
        $sad500_total = floatval(KIT_Waybills::sadc_certificate());
      }
    }

    $sadc_total = 0.0;
    if (!empty($waybill->include_sadc)) {
      if ($sadc_total_from_db !== null && $sadc_total_from_db > 0) {
        $sadc_total = floatval($sadc_total_from_db);
      } elseif (class_exists('KIT_Waybills')) {
        // Fallback to configured/default SADC charge when DB snapshot is missing/zero
        $sadc_total = floatval(KIT_Waybills::sad());
      }
    }

    // International price / Agent Clearing: prefer DB snapshot, but if it's zero/missing
    // while the final_total includes an extra component beyond primary + misc + SAD/SADC,
    // derive it as the remainder so the PDF matches the DB total.
    $vat_included_flag = class_exists('KIT_Waybills')
      ? (KIT_Waybills::normalize_flag_int($waybill->vat_include ?? 0) === 1)
      : (!empty($waybill->vat_include) && intval($waybill->vat_include) === 1);

    $intl_amount_for_total = 0.0;
    if (!$vat_included_flag && $international_price_from_db !== null && $international_price_from_db > 0) {
      $intl_amount_for_total = floatval($international_price_from_db);
    }

    // Border clearing header total (black row): prefer stored DB snapshot, but if it's
    // missing or zero, fall back to sum of VAT-rate border clearing amounts.
    $vat_decimal = 0.10;
    if (class_exists('KIT_Waybills') && method_exists('KIT_Waybills', 'vatRate')) {
      $vat_pct = floatval(KIT_Waybills::vatRate());
      $vat_decimal = $vat_pct > 1 ? $vat_pct / 100.0 : $vat_pct;
    }

    $border_clearing_10_percent_total = ($border_clearing_total_from_db !== null) ? floatval($border_clearing_total_from_db) : 0.0;
    if ($vat_included_flag && $border_clearing_10_percent_total <= 0 && !empty($waybillItems)) {
      $recalc_border_total = 0.0;
      foreach ($waybillItems as $it) {
        if (is_object($it)) {
          $it = (array)$it;
        }
        $qty  = isset($it['quantity']) ? intval($it['quantity']) : 0;
        $unit = isset($it['unit_price']) ? floatval($it['unit_price']) : 0.0;
        if ($qty > 0 && $unit > 0) {
          $recalc_border_total += ($qty * $unit) * $vat_decimal;
        }
      }
      $border_clearing_10_percent_total = $recalc_border_total;
    }
    if (!$vat_included_flag) {
      $border_clearing_10_percent_total = 0.0;
    }
    $items_total = floatval($waybill->waybill_items_total ?? 0.0);

    // Get items for display only (calculate ten_percent for display)
    if (!empty($waybillItems)) {
      foreach ($waybillItems as $key => $wi) {
        if (is_object($wi)) {
          $waybillItems[$key] = (array) $wi;
          $wi = $waybillItems[$key];
        }
        $qty = isset($wi['quantity']) ? intval($wi['quantity']) : 0;
        $unit = isset($wi['unit_price']) ? floatval($wi['unit_price']) : 0.0;
        $subtotal = $qty * $unit;
        $waybillItems[$key]['ten_percent'] = $subtotal * $vat_decimal; // For display only
      }
    }

    // Use product_invoice_amount from DB as baseline
    $final_total = isset($waybill->product_invoice_amount)
      ? KIT_Waybills::normalize_amount($waybill->product_invoice_amount)
      : 0.0;

    // If VAT is not included and there is no explicit international price stored,
    // but the DB total includes more than primary + misc + SAD/SADC, treat the
    // remainder as the international price so the "Agent Clearing" line matches
    // the DB-backed final_total.
    if (!$vat_included_flag && $intl_amount_for_total <= 0) {
      $base_components = $charge + $misc_total + $sad500_total + $sadc_total;
      $remainder = $final_total - $base_components;
      if ($remainder > 0.01) {
        $intl_amount_for_total = $remainder;
      }
    }

    // Border Clearing (VAT) and Agent Clearing are mutually exclusive in the PDF.
    $show_border_clearing_vat = $vat_included_flag;
    $show_agent_clearing = !$show_border_clearing_vat;
    $has_sad500 = !empty($waybill->include_sad500) && intval($waybill->include_sad500) === 1;
    $has_sadc = !empty($waybill->include_sadc) && intval($waybill->include_sadc) === 1;
    // Parcel lines: VAT = declared values + 10% fee billed; SAD500/SADC = line totals billable.
    $show_border_clearing_items = $show_border_clearing_vat || $has_sad500 || $has_sadc;
    $border_items_bill_full_line = !$show_border_clearing_vat && ($has_sad500 || $has_sadc);
    $display_total = $charge + $misc_total + $sad500_total + $sadc_total + $intl_amount_for_total + ($show_border_clearing_vat ? $border_clearing_10_percent_total : 0.0);

    // Grand total on the invoice: sum of billable charge lines (not declared parcel value).
    $invoice_grand_total = $display_total > 0 ? $display_total : $final_total;
    if ($show_border_clearing_vat && $display_total > 0) {
        $invoice_grand_total = $display_total;
    }

    // Get the image file content and convert it to Base64
    $imagePath = $plugin_root . '/icons/pin.png';
    $pin = '';
    if (file_exists($imagePath)) {
      $imageData = file_get_contents($imagePath);
      if ($imageData !== false) {
        $pin = 'data:image/png;base64,' . base64_encode($imageData);
      }
    }

    $emailPath = $plugin_root . '/icons/email.png';
    $email = '';
    if (file_exists($emailPath)) {
      $imageData = file_get_contents($emailPath);
      if ($imageData !== false) {
        $email = 'data:image/png;base64,' . base64_encode($imageData);
      }
    }

    $webPath = $plugin_root . '/icons/web.png';
    $web = '';
    if (file_exists($webPath)) {
      $imageData = file_get_contents($webPath);
      if ($imageData !== false) {
        $web = 'data:image/png;base64,' . base64_encode($imageData);
      }
    }

    $contactPath = $plugin_root . '/icons/contact.png';
    $contact = '';
    if (file_exists($contactPath)) {
      $imageData = file_get_contents($contactPath);
      if ($imageData !== false) {
        $contact = 'data:image/png;base64,' . base64_encode($imageData);
      }
    }


    // Terms & Conditions: load from WP options with safe fallback
    $kit_terms = function_exists('get_option') ? get_option('kit_terms_conditions', '') : '';
    if (!is_string($kit_terms)) {
      $kit_terms = '';
    }
    $default_terms_html = '<ul class="terms-conditions">'
      . '<li>Payment terms: <strong>50% upfront required before dispatch</strong>, the rest on delivery.</li>'
      . '<li>Late payment: <strong>10% penalty</strong> if not paid within 7 days.</li>'
      . '<li>All prices are in <strong>South African Rand (ZAR)</strong>.</li>'
      . '<li>Delivery time: Approximately <strong>14 Days</strong> after dispatch and TRA release.</li>'
      . '<li>Insurance: Basic coverage included, additional coverage available.</li>'
      . '<li>Extra insurance available on request at additional cost.</li>'
      . '</ul>';
    $terms_html = trim($kit_terms) !== '' ? $kit_terms : $default_terms_html;

    // Waybill description (matches UI: description column, then miscellaneous snapshot)
    $waybill_description = trim((string) ($waybill->description ?? ''));
    if ($waybill_description === '' && is_array($misc_data) && isset($misc_data['others']['waybill_description'])) {
        $waybill_description = trim((string) $misc_data['others']['waybill_description']);
    }

    // Build display values used by the template
    $invoice_number = isset($waybill->product_invoice_number) ? trim((string) $waybill->product_invoice_number) : '';
    // Legacy date-sequence numbers (INV-YYYYMMDD-NNNNN) display in the current
    // INV13{waybill_no} format instead.
    if ($invoice_number === '' || preg_match('/^INV-\d{8}-\d+$/i', $invoice_number)) {
        $invoice_number = 'INV13' . $waybill_no;
    }
    $invoice_date_raw = isset($waybill->created_at) ? (string) $waybill->created_at : '';
    $invoice_date_display = $invoice_date_raw !== '' ? date('d F Y', strtotime($invoice_date_raw)) : date('d F Y');
    $tracking_number = isset($waybill->tracking_number) ? trim((string) $waybill->tracking_number) : '';
    // Tracking only becomes active once the shipment is moving; until then the
    // tracking number is hidden on the invoice.
    $tracking_is_active = in_array(
        strtolower(trim((string) ($waybill->status ?? ''))),
        ['in_transit', 'shipped', 'delivered', 'completed'],
        true
    );

    $invoice_status = isset($waybill->status) ? trim((string) $waybill->status) : '';
    $approval_status = isset($waybill->approval) ? trim((string) $waybill->approval) : '';
    $invoice_status_label = $invoice_status !== '' ? ucfirst($invoice_status) : 'Pending';
    $approval_status_label = $approval_status !== '' ? ($approval_status === 'pending' ? 'Awaiting Approval' : ucfirst($approval_status)) : 'Awaiting Approval';

    $charge_type = ($charge_basis === 'mass') ? 'Mass' : 'Volume';
    $charge_amount = ($charge_basis === 'mass') ? floatval($waybill->mass_charge ?? 0) : floatval($waybill->volume_charge ?? 0);

    $mass_kg = isset($waybill->total_mass_kg) ? floatval($waybill->total_mass_kg) : 0.0;
    $length_cm = isset($waybill->item_length) ? floatval($waybill->item_length) : 0.0;
    $width_cm = isset($waybill->item_width) ? floatval($waybill->item_width) : 0.0;
    $height_cm = isset($waybill->item_height) ? floatval($waybill->item_height) : 0.0;
    $volume_m3 = isset($waybill->total_volume) && floatval($waybill->total_volume) > 0
        ? floatval($waybill->total_volume)
        : ($length_cm * $width_cm * $height_cm / 1000000);

    if ($charge_basis === 'mass') {
        $transport_qty_display = number_format($mass_kg, 2) . ' kg';
        $transport_rate_value = $stored_mass_rate > 0
            ? $stored_mass_rate
            : ((!empty($misc_data) && isset($misc_data['others']['mass_rate'])) ? floatval($misc_data['others']['mass_rate']) : 0);
        if (!$transport_rate_value && $mass_kg > 0 && $charge_amount > 0) {
            $transport_rate_value = $charge_amount / $mass_kg;
        }
        $transport_rate_display = 'R ' . number_format($transport_rate_value, 2) . ' / kg';
    } else {
        $transport_qty_display = number_format($volume_m3, 3) . ' m³';
        $transport_rate_value = $stored_volume_rate > 0 ? $stored_volume_rate : 0;
        if (!$transport_rate_value && $volume_m3 > 0 && $charge_amount > 0) {
            $transport_rate_value = $charge_amount / $volume_m3;
        }
        $transport_rate_display = 'R ' . number_format($transport_rate_value, 2) . ' / m³';
    }

    $shipper_name = trim((string) ($company['company_name'] ?? '08600 Africa (Pty) Ltd'));
    $shipper_address = (string) ($company['company_address'] ?? '');
    $shipper_phone = (string) ($company['company_phone'] ?? '');
    $shipper_email = (string) ($company['company_email'] ?? '');
    $shipper_vat = (string) ($company['company_vat_number'] ?? '');

    $consignee_company = trim((string) ($waybill->company_name ?? ''));
    $consignee_name = trim(((string) ($waybill->customer_name ?? '')) . ' ' . ((string) ($waybill->customer_surname ?? '')));
    $consignee_address = (string) ($waybill->address ?? '');
    $consignee_country = (string) ($waybill->origin_country ?? '');
    $consignee_email = (string) ($waybill->email_address ?? '');
    $consignee_cell = (string) ($waybill->customer_cell ?? '');
    $destination_city = trim((string) ($waybill->customer_city ?? ''));
    $route_description = trim((string) ($waybill->route_description ?? ''));

    // Logo as base64 (dompdf prefers inline data URIs over external URLs)
    $logo_path = $plugin_root . 'img/logo.png';
    $logo_data_uri = '';
    if (file_exists($logo_path)) {
        $logo_bytes = @file_get_contents($logo_path);
        if ($logo_bytes !== false) {
            $logo_data_uri = 'data:image/png;base64,' . base64_encode($logo_bytes);
        }
    }
    return [
        'plugin_root' => $plugin_root,
        'waybill_no' => $waybill_no,
        'primary_color' => $primary_color,
        'secondary_color' => $secondary_color,
        'accent_color' => $accent_color,
        'darkBadge' => $darkBadge,
        'lightBadge' => $lightBadge,
        'pTextColor' => $pTextColor,
        'stroke_color' => $stroke_color,
        'waybill' => $waybill,
        'waybillItems' => $waybillItems,
        'company' => $company,
        'mass_charge' => $mass_charge,
        'volume_charge' => $volume_charge,
        'charge_basis' => $charge_basis,
        'charge' => $charge,
        'misc_total' => $misc_total,
        'misc_data' => $misc_data,
        'misc_items' => $misc_items,
        'stored_basis' => $stored_basis,
        'stored_mass_rate' => $stored_mass_rate,
        'stored_volume_rate' => $stored_volume_rate,
        'sad500_total' => $sad500_total,
        'sadc_total' => $sadc_total,
        'intl_amount_for_total' => $intl_amount_for_total,
        'border_clearing_10_percent_total' => $border_clearing_10_percent_total,
        'items_total' => $items_total,
        'show_border_clearing_vat' => $show_border_clearing_vat,
        'show_border_clearing_items' => $show_border_clearing_items,
        'border_items_bill_full_line' => $border_items_bill_full_line,
        'show_agent_clearing' => $show_agent_clearing,
        'final_total' => $final_total,
        'display_total' => $display_total,
        'invoice_grand_total' => $invoice_grand_total,
        'pin' => $pin,
        'email' => $email,
        'web' => $web,
        'contact' => $contact,
        'terms_html' => $terms_html,
        'qr_code_image' => $qr_code_image,
        'invoice_number' => $invoice_number,
        'invoice_date_display' => $invoice_date_display,
        'tracking_number' => $tracking_number,
        'tracking_is_active' => $tracking_is_active,
        'invoice_status_label' => $invoice_status_label,
        'approval_status_label' => $approval_status_label,
        'charge_type' => $charge_type,
        'charge_amount' => $charge_amount,
        'mass_kg' => $mass_kg,
        'length_cm' => $length_cm,
        'width_cm' => $width_cm,
        'height_cm' => $height_cm,
        'volume_m3' => $volume_m3,
        'transport_qty_display' => $transport_qty_display,
        'transport_rate_display' => $transport_rate_display,
        'shipper_name' => $shipper_name,
        'shipper_address' => $shipper_address,
        'shipper_phone' => $shipper_phone,
        'shipper_email' => $shipper_email,
        'shipper_vat' => $shipper_vat,
        'consignee_company' => $consignee_company,
        'consignee_name' => $consignee_name,
        'consignee_address' => $consignee_address,
        'consignee_country' => $consignee_country,
        'consignee_email' => $consignee_email,
        'consignee_cell' => $consignee_cell,
        'destination_city' => $destination_city,
        'route_description' => $route_description,
        'logo_data_uri' => $logo_data_uri,
        'waybill_description' => $waybill_description,
    ];
}

/**
 * Render invoice HTML for a waybill using the active or requested template.
 */
/**
 * Normalize charge_basis from DB/UI (e.g. "MASS", "Volume", "weight").
 */
function kit_waybill_pdf_normalize_charge_basis($basis): string
{
    $basis = strtolower(trim((string) $basis));
    if ($basis === 'weight') {
        return 'mass';
    }

    return $basis;
}

function kit_waybill_pdf_render_invoice_html(int $waybill_no, ?string $template_id = null): string
{
    $context = kit_waybill_pdf_prepare_invoice_context($waybill_no);
    $template_id = $template_id ?? kit_waybill_pdf_get_active_template_id();

    if (!in_array($template_id, kit_waybill_pdf_allowed_template_ids(), true)) {
        $template_id = kit_waybill_pdf_default_template_id();
    }

    $template_path = kit_waybill_pdf_template_path($template_id);
    if (!is_file($template_path)) {
        throw new RuntimeException('Invoice template not found: ' . $template_id);
    }

    extract($context, EXTR_SKIP);

    ob_start();
    include $template_path;
    $html = ob_get_clean();

    if (!is_string($html) || $html === '') {
        throw new RuntimeException('Invoice template rendered empty HTML.');
    }

    return $html;
}
