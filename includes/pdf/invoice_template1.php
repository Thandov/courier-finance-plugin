<?php
/**
 * Invoice template 1 — classic waybill invoice (default).
 * Variables are provided by kit_waybill_pdf_prepare_invoice_context().
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<style>
  :root {
    --primary: <?php echo $primary_color; ?>;
    --secondary: <?php echo $secondary_color; ?>;
    --accent: <?php echo $accent_color; ?>;
    --pdf-font-base: 12px;
    --pdf-grey-dark: #374151;
    --pdf-grey-mid: #6b7280;
    --pdf-grey-light: #e9ecef;
    --pdf-grey-bar: #4b5563;
    --pdf-section: #6b7280;
  }

  @page {
    margin: 8mm 8mm 14mm 8mm;
  }

  /* Standardized typography for PDF */
  body {
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
    font-size: var(--pdf-font-base);
    line-height: 1.35;
    color: #333;
  }

  h1,
  h2,
  h3,
  h4 {
    margin: 0;
    color: var(--primary);
    font-weight: 700;
  }

  h4 {
    font-size: var(--pdf-font-base);
  }

  table {
    border-collapse: collapse;
    font-size: var(--pdf-font-base);
    table-layout: fixed;
    width: 100%;
  }

  .layout-fixed {
    table-layout: fixed;
    width: 100%;
  }

  .invoice-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 0;
    table-layout: fixed;
  }

  .charges-table {
    table-layout: fixed;
    width: 100%;
    margin-bottom: 5px;
  }

  .charges-table col.col-item {
    width: 50%;
  }

  .charges-table col.col-qty {
    width: 12%;
  }

  .charges-table col.col-price {
    width: 18%;
  }

  .charges-table col.col-subtotal {
    width: 20%;
  }

  .invoice-table th {
    background-color: var(--pdf-grey-light);
    color: var(--pdf-grey-dark);
    border: 1px solid #d1d5db;
    padding: 6px 4px;
    vertical-align: middle;
    font-weight: 700;
    text-transform: none;
  }

  .invoice-table td {
    background: #fff;
    border: 1px solid #d1d5db;
    padding: 6px 4px;
    vertical-align: top;
    overflow: hidden;
  }

  /* Item column: wrap text; row height grows, column width stays fixed */
  .charges-table .col-item,
  .charges-table th.col-item,
  .charges-table td.col-item {
    width: 50%;
    white-space: normal;
    word-wrap: break-word;
    overflow-wrap: break-word;
    text-align: left;
  }

  .charges-table .col-qty,
  .charges-table th.col-qty,
  .charges-table td.col-qty {
    width: 12%;
    white-space: nowrap;
    text-align: center;
  }

  .charges-table .col-price,
  .charges-table th.col-price,
  .charges-table td.col-price {
    width: 18%;
    white-space: nowrap;
    text-align: right;
  }

  .charges-table .col-subtotal,
  .charges-table th.col-subtotal,
  .charges-table td.col-subtotal {
    width: 20%;
    white-space: nowrap;
    text-align: right;
  }

  th {
    font-size: var(--pdf-font-base);
    font-weight: 700;
  }

  td {
    font-size: var(--pdf-font-base);
  }

  .smTableStyle {
    font-size: var(--pdf-font-base);
  }

  .smTableStyle tr {
    border-right: 1px solid #e0e7ef;
  }

  .smCellStyle {
    font-size: var(--pdf-font-base);
  }

  td.cellStyle {
    text-align: left;
    padding: 6px 6px;
    border: 1px solid #d1d5db;
    background: #fff;
    vertical-align: top;
  }

  td.cellStyle.alignleft {
    text-align: left;
  }

  td.cellStyle.aligncenter {
    text-align: center;
  }

  td.cellStyle.alignright {
    text-align: right;
  }

  .fstCol {
    width: 70px !important;
  }

  th.thr {
    font-size: var(--pdf-font-base);
    padding: 8px 6px;
    white-space: nowrap;
  }

  tr.tr {
    font-size: var(--pdf-font-base);
  }

  .section-heading {
    color: var(--pdf-section);
    font-weight: 700;
    text-transform: uppercase;
  }

  .doc-title-large {
    font-size: 26px;
    font-weight: 700;
    color: #c4c4c4;
    letter-spacing: 2px;
    text-transform: uppercase;
    line-height: 1.1;
    margin: 0;
  }

  .status-bar {
    background: var(--pdf-grey-light);
    padding: 6px 10px;
    margin-bottom: 10px;
    font-size: var(--pdf-font-base);
    color: #333;
  }

  tr.rowTotal td {
    color: #fff;
    font-weight: 700;
    background-color: var(--pdf-grey-bar) !important;
    border-color: var(--pdf-grey-bar) !important;
    vertical-align: middle;
  }

  tr.rowTotal td.col-item {
    font-size: var(--pdf-font-base);
    text-align: left;
    white-space: nowrap;
  }

  tr.rowTotal td.col-qty,
  tr.rowTotal td.col-price {
    font-size: 1px;
    line-height: 1px;
    color: transparent;
    border-color: var(--pdf-grey-bar) !important;
  }

  tr.rowTotal td.col-subtotal {
    font-size: var(--pdf-font-base);
    font-weight: 700;
    white-space: nowrap;
  }

  /* Ensure the TOTAL row is never split across pages */
  .rowTotal {
    page-break-inside: avoid;
  }

  .header-table col.col-logo {
    width: 38%;
  }

  .header-table col.col-title {
    width: 24%;
  }

  .header-table col.col-meta {
    width: 38%;
  }

  .address-table col.col-from,
  .address-table col.col-bill {
    width: 50%;
  }

  .banking-grid {
    table-layout: fixed;
    width: 100%;
  }

  .banking-grid col.col-label {
    width: 38%;
  }

  .banking-grid col.col-value {
    width: 62%;
  }

  .banking-grid td.col-label {
    color: #666;
    vertical-align: middle;
    white-space: nowrap;
    padding: 2px 6px 2px 0 !important;
  }

  /* Label and value stay together on one line */
  .banking-grid td.col-value {
    text-align: left;
    font-weight: 600;
    vertical-align: middle;
    white-space: nowrap;
    padding: 2px 4px !important;
  }

  .small-text {
    font-size: var(--pdf-font-base);
  }

  .muted {
    color: #666;
  }

  .total-row td {
    font-size: var(--pdf-font-base);
    font-weight: 700;
  }

  .thank-you-text {
    font-size: var(--pdf-font-base);
    margin-top: 10px;
    margin-bottom: 10px;
    color: var(--primary);
    font-weight: 600;
    text-align: center;
  }

  /* Tidy Terms & Conditions list */
  .terms-conditions,
  .terms-conditions ul {
    font-size: var(--pdf-font-base);
    margin: 0 0 6px 0;
    padding-left: 14px;
    list-style-type: disc;
    list-style-position: outside;
    color: #444;
  }

  .terms-conditions li {
    margin: 2px 0 4px;
    line-height: 1.4;
  }

  .terms-conditions li::marker {
    color: var(--pdf-grey-mid);
  }

  /* Keep two-card section compact and on same page */
  .banking-details {
    page-break-inside: avoid;
  }

  .banking-details tr td:nth-child(1) {
    vertical-align: top;
    padding: 0;
    width: 50%;
  }

  .banking-details tr td:nth-child(2) {
    vertical-align: top;
    padding: 0 0 0 12px;
    width: 50%;
    background-color: transparent;
  }

  /* Tighten inner Banking Details table spacing */
  .banking-details table {
    border-collapse: collapse;
  }

  .banking-details table td {
    padding: 2px 4px !important;
  }

  .banking-details table td.dCells {
    padding: 2px 6px 2px 0 !important;
  }

  /* Generic helpers to avoid dompdf overflow/overlap */
  .no-break {
    page-break-inside: avoid;
  }

  [style*='box-shadow'] {
    box-shadow: none !important;
  }

  .fine-print {
    font-size: 10px;
    color: #6b7280;
    line-height: 1.45;
    margin-top: 8px;
    border-top: 1px solid #e5e7eb;
    padding-top: 5px;
  }

  tr.parcel-section-head td {
    background: #f3f4f6;
    color: #374151;
    font-size: var(--pdf-font-base);
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    border: 1px solid #d1d5db;
    padding: 5px 6px;
  }

  tr.parcel-item-row td.col-item {
    padding-left: 12px;
    color: #4b5563;
    font-size: var(--pdf-font-base);
  }

  tr.parcel-item-row td.col-subtotal {
    color: #9ca3af;
    font-weight: 400;
    text-align: center;
  }

  tr.parcel-vat-row td.col-item {
    font-weight: 600;
  }

  tr.parcel-vat-row td.col-price {
    font-size: var(--pdf-font-base);
    color: #6b7280;
  }

  .reference-table {
    margin-top: 10px;
    margin-bottom: 0;
  }

  .reference-table th {
    background: #f9fafb;
    color: #374151;
    font-size: var(--pdf-font-base);
    font-weight: 600;
    border: 1px solid #d1d5db;
    padding: 4px 6px;
  }

  .reference-table td {
    border: 1px solid #e5e7eb;
    padding: 3px 6px;
    font-size: var(--pdf-font-base);
    color: #4b5563;
  }

  .reference-caption {
    font-size: var(--pdf-font-base);
    font-weight: 700;
    color: #374151;
    margin: 10px 0 4px 0;
  }

  .reference-note {
    font-size: var(--pdf-font-base);
    color: #6b7280;
    margin: 0 0 4px 0;
    font-style: italic;
  }

  .reference-total td {
    background: #f3f4f6;
    font-weight: 700;
    color: #111;
  }
</style>
<!-- PAGE CONTAINER -->
<table width="100%" cellpadding="3" cellspacing="0" style="max-width:700px; margin:0 auto; font-family:Arial,sans-serif;font-size:12px;color:#333;">
  <tr>
    <td colspan="3">
      <!-- HEADER: LOGO + TITLE + INVOICE INFO -->
      <?php
      $invoice_status = isset($waybill->status) ? strtolower(trim((string) $waybill->status)) : '';
      $approval_status = isset($waybill->approval) ? strtolower(trim((string) $waybill->approval)) : '';
      $invoice_labels = [
          'pending' => 'Pending',
          'scheduled' => 'Scheduled',
          'invoiced' => 'Invoiced',
          'rejected' => 'Rejected',
          'completed' => 'Completed',
          'delivered' => 'Delivered',
          'in_transit' => 'In Transit',
          'shipped' => 'Shipped',
      ];
      $approval_labels = [
          'pending' => 'Not Approved',
          'approved' => 'Approved',
          'rejected' => 'Rejected',
          'completed' => 'Completed',
      ];
      $inv_label = $invoice_labels[$invoice_status]
          ?? (isset($invoice_status_label) && $invoice_status_label !== '' ? $invoice_status_label : ($invoice_status !== '' ? ucfirst($invoice_status) : 'Pending'));
      $app_label = $approval_labels[$approval_status]
          ?? (isset($approval_status_label) && $approval_status_label !== '' ? $approval_status_label : ($approval_status !== '' ? ucfirst($approval_status) : 'Not Approved'));
      $logo_src = !empty($logo_data_uri)
          ? $logo_data_uri
          : esc_url(plugin_dir_url($plugin_root . 'pdf-generator.php') . 'img/logo.png');
      ?>
      <table class="layout-fixed header-table" width="100%" cellpadding="3" cellspacing="0" style="margin-bottom:6px; border-bottom:1px solid #e9ecef;">
        <colgroup>
          <col class="col-logo">
          <col class="col-title">
          <col class="col-meta">
        </colgroup>
        <tr>
          <td class="col-logo" style="vertical-align:top; padding-top:2px;">
            <img src="<?= $logo_src ?>" alt="Company Logo" style="display:block; width:200px; max-width:100%; height:auto;">
          </td>
          <td class="col-title" style="vertical-align:middle; text-align:center;">
            <div class="doc-title-large">INVOICE</div>
          </td>
          <td class="col-meta" style="vertical-align:top; text-align:right;">
            <div style="font-size:12px; color:#666;">
              <div><strong>Invoice #:</strong> <?= esc_html($invoice_number ?? $waybill->product_invoice_number) ?></div>
              <div><strong>Waybill #:</strong> <?= esc_html((string) ($waybill_no ?? $waybill->waybill_no ?? '')) ?></div>
              <div><strong>Date:</strong> <?= esc_html($invoice_date_display ?? date('d F Y', strtotime($waybill->created_at))) ?></div>
              <?php if (!empty($tracking_is_active) && trim((string) ($waybill->tracking_number ?? '')) !== ''): ?>
                <div><strong>Tracking #:</strong> <?= esc_html($waybill->tracking_number) ?></div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      </table>
      <div class="status-bar">
        <strong>Invoice Status:</strong> <?= esc_html($inv_label) ?>
        <span style="margin-left:14px;"><strong>Approval Status:</strong> <?= esc_html($app_label) ?></span>
      </div>

      <!-- ADDRESS ROW: FROM & BILL TO -->
      <table class="layout-fixed address-table" width="100%" cellpadding="0" cellspacing="3" style="margin-bottom:12px;">
        <colgroup>
          <col class="col-from">
          <col class="col-bill">
        </colgroup>
        <tr>
          <td class="col-from" style="background:transparent; border:0; border-radius:0; padding:0; vertical-align:top;">
            <div class="section-heading" style="font-size:12px; margin-bottom:4px;">From</div>
            <div style="font-size:12px; line-height:1.3;">
              <?= esc_html($company['company_name'] ?? '') ?><br>
              <?= nl2br(esc_html($company['company_address'] ?? '')) ?><br>
              <?= esc_html($company['company_phone'] ?? '') ?><br>
              <?= esc_html($company['company_email'] ?? '') ?><br>
              VAT: <?= esc_html($company['company_vat_number'] ?? '') ?>
            </div>
          </td>
          <td class="col-bill" style="background:transparent;border:0;border-radius:0;padding:0; vertical-align:top;">
            <div class="section-heading" style="font-size:12px; margin-bottom:4px;">Bill To</div>
            <div style="font-size:12px; line-height:1.3;">
              <?php
              $bill_to_company = trim((string) ($waybill->company_name ?? ''));
              $bill_to_address = trim((string) ($waybill->address ?? ''));
              ?>
              <?php if ($bill_to_company !== '' && strcasecmp($bill_to_company, 'Individual') !== 0): ?>
                <strong><?= esc_html($bill_to_company) ?></strong><br>
              <?php endif; ?>
              <?= esc_html(trim($waybill->customer_name . " " . $waybill->customer_surname)) ?><br>
              <?php if ($bill_to_address !== '' && strcasecmp($bill_to_address, 'No Address') !== 0): ?>
                <?= esc_html($bill_to_address) ?><br>
              <?php endif; ?>
              Email: <?= esc_html(($waybill->email_address) ?? 'customer@customer.com') ?><br>
              Cell: <?= esc_html($waybill->customer_cell ?? 'N/A') ?><br>
              <?php
              $dest_parts = array_filter([
                  trim((string) ($waybill->route_description ?? '')),
                  trim((string) ($waybill->customer_city ?? '')),
              ], static function ($v) {
                  return $v !== '';
              });
              if (!empty($dest_parts)):
              ?>
                Destination: <?= esc_html(implode(', ', $dest_parts)) ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      </table>

      <table class="invoice-table charges-table" cellpadding="0" cellspacing="0" style="margin-bottom:0;">
        <colgroup>
          <col class="col-item">
          <col class="col-qty">
          <col class="col-price">
          <col class="col-subtotal">
        </colgroup>
        <?php
        $charge_type = ($charge_basis == 'mass' || $charge_basis == 'weight') ? 'Mass' : 'Volume';
        $charge_amount = ($charge_basis == 'mass' || $charge_basis == 'weight') ? $waybill->mass_charge : $waybill->volume_charge;
        $qty_col_label = ($charge_basis == 'mass' || $charge_basis == 'weight') ? 'Mass' : 'Volume';
        ?>
        <tr style="font-size:12px;">
          <th class="col-item" align="left" style="padding-left:6px;">Description</th>
          <th class="col-qty" align="center"><?= esc_html($qty_col_label) ?></th>
          <th class="col-price" align="right">Price (R)</th>
          <th class="col-subtotal" align="right" style="padding-right:6px;">Subtotal (R)</th>
        </tr>
        
        <?php
        $charge_type_text = $charge_type . ' Charge';
        // Client request (Mel, 2026-08-05): goods descriptions must not appear on
        // the invoice — the customer is billed for transport, not told back what
        // they shipped. The description still lives on the waybill and the
        // waybill PDF; this is the invoice only.
        $primary_line_description = 'Transport - ' . $charge_type_text;
        ?>
        <!-- Transport / primary charge line -->
        <tr>
          <td class="cellStyle col-item"><?= esc_html($primary_line_description) ?></td>
          <td class="cellStyle col-qty">
            <?php if ($charge_basis == 'mass' || $charge_basis == 'weight'): ?>
              <?= isset($waybill->total_mass_kg) ? number_format($waybill->total_mass_kg, 2) . ' kg' : '0.00 kg' ?>
            <?php else: ?>
              <?= number_format($volume_m3 ?? 0, 3) ?> m³
            <?php endif; ?>
          </td>
          <td class="cellStyle col-price">
            <?php
            // Display the rate (either mass or volume rate) from stored snapshot first
            if ($charge_basis == 'mass' || $charge_basis == 'weight') {
              $rate = $stored_mass_rate > 0 ? $stored_mass_rate : ((!empty($misc_data) && isset($misc_data['others']['mass_rate'])) ? floatval($misc_data['others']['mass_rate']) : 0);
              if (!$rate && !empty($waybill->mass_charge) && !empty($waybill->total_mass_kg) && $waybill->total_mass_kg > 0) {
                $rate = $waybill->mass_charge / $waybill->total_mass_kg;
              }
              echo 'R ' . number_format($rate, 2) . '/kg';
            } else {
              $rate = $stored_volume_rate > 0 ? $stored_volume_rate : 0;
              if (!$rate && !empty($waybill->volume_charge) && !empty($waybill->total_volume) && $waybill->total_volume > 0) {
                $rate = $waybill->volume_charge / $waybill->total_volume;
              }
              echo 'R ' . number_format($rate, 2) . '/m³';
            }
            ?>
          </td>
          <td class="cellStyle col-subtotal" style="font-weight:700;">R <?= number_format($charge_amount, 2) ?></td>
        </tr>
        
        <!-- Miscellaneous Items -->
        <?php if (!empty($misc_items)): ?>
          <?php foreach ($misc_items as $item): ?>
            <?php 
            $misc_item_name = htmlspecialchars($item['misc_item'] ?? '');
            ?>
            <tr>
              <td class="cellStyle col-item">Miscellaneous - <?= $misc_item_name ?></td>
              <td class="cellStyle col-qty"><?= intval($item['misc_quantity']) ?></td>
              <td class="cellStyle col-price">R <?= number_format($item['misc_price'], 2) ?></td>
              <td class="cellStyle col-subtotal" style="font-weight:700;">R <?= number_format($item['misc_quantity'] * $item['misc_price'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- SAD500 -->
        <?php if (!empty($waybill->include_sad500) && $waybill->include_sad500 == 1): ?>
          <tr>
            <td class="cellStyle col-item">Processing - SAD500</td>
            <td class="cellStyle col-qty">1</td>
            <td class="cellStyle col-price">R <?= number_format($sad500_total, 2) ?></td>
            <td class="cellStyle col-subtotal" style="font-weight:700;">R <?= number_format($sad500_total, 2) ?></td>
          </tr>
        <?php endif; ?>
        
        <!-- SADC Certificate -->
        <?php if (!empty($waybill->include_sadc) && $waybill->include_sadc == 1): ?>
          <tr>
            <td class="cellStyle col-item">Processing - SADC Certificate</td>
            <td class="cellStyle col-qty">1</td>
            <td class="cellStyle col-price">R <?= number_format($sadc_total, 2) ?></td>
            <td class="cellStyle col-subtotal" style="font-weight:700;">R <?= number_format($sadc_total, 2) ?></td>
          </tr>
        <?php endif; ?>
        
        <!-- Agent Clearing & Documentation -->
        <?php if ($show_agent_clearing && $intl_amount_for_total > 0): ?>
          <?php
          // Use already calculated intl_amount_for_total (from DB or fallback)
          $intl_amount = $intl_amount_for_total;
          // Display rate for reference (calculate from stored value or use default)
          $intl_display_rate = $intl_amount > 0 ? $intl_amount : KIT_Waybills::international_price_in_rands();
          $agent_clearing_text = 'Agent Clearing & Documentation';
          ?>
          <tr>
            <td class="cellStyle col-item">Customs Clearing - <?= $agent_clearing_text ?></td>
            <td class="cellStyle col-qty">1</td>
            <td class="cellStyle col-price">R <?= number_format($intl_display_rate, 2) ?></td>
            <td class="cellStyle col-subtotal" style="font-weight:700;">R <?= number_format($intl_amount, 2) ?></td>
          </tr>
        <?php endif; ?>
        
        <!--
          Border Clearing fee — the 10% line only.

          The itemised parcel rows that used to render here listed every item
          name and its declared value. Those are the descriptions the client
          asked to have taken off the invoice (Mel, 2026-08-05), so they are
          gone: an invoice bills for the service, it does not enumerate the
          customer's own goods back at them.

          No amount changes. Declared parcel values were never part of
          $display_total (see kit-waybill-pdf-invoice.php) — they were shown
          alongside it — so TOTAL DUE is exactly what it was before.
        -->
        <?php if ($show_border_clearing_vat && $border_clearing_10_percent_total > 0): ?>
          <tr class="parcel-vat-row">
            <td class="cellStyle col-item">Border Clearing Fee (10% of declared value)</td>
            <td class="cellStyle col-qty">1</td>
            <td class="cellStyle col-price">10%</td>
            <td class="cellStyle col-subtotal" style="font-weight:700; color:#111;">R <?= number_format($border_clearing_10_percent_total, 2) ?></td>
          </tr>
        <?php endif; ?>

        <!-- TOTAL (sum of billable charge lines) -->
        <?php $pdf_grand_total = isset($invoice_grand_total) ? floatval($invoice_grand_total) : floatval($final_total); ?>
        <tr class="rowTotal">
          <td class="cellStyle col-item">TOTAL DUE</td>
          <td class="cellStyle col-qty">&nbsp;</td>
          <td class="cellStyle col-price">&nbsp;</td>
          <td class="cellStyle col-subtotal">R <?= number_format($pdf_grand_total, 2) ?></td>
        </tr>
      </table>

      <!-- Banking & Terms Section (side by side) -->
      <table class="banking-details" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;">
        <tr>
          <!-- Banking Details Card -->
          <td style="width: 40%;">
            <div style="padding:0 20px 0 0; border:0; background:transparent; box-shadow:none; border-radius:0;">
              <div class="section-heading" style="font-size:12px; margin:0 0 8px 0;">
                Banking Details
              </div>
              <table class="banking-grid" width="100%" cellpadding="0" cellspacing="0">
                <colgroup>
                  <col class="col-label">
                  <col class="col-value">
                </colgroup>
                <?php if (!empty($company['bank_name'])): ?>
                  <tr>
                    <td class="col-label dCells">Bank</td>
                    <td class="col-value"><?= esc_html($company['bank_name']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['account_holder'])): ?>
                  <tr>
                    <td class="col-label dCells">Account Holder</td>
                    <td class="col-value"><?= esc_html($company['account_holder']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['account_number'])): ?>
                  <tr>
                    <td class="col-label dCells">Account #</td>
                    <td class="col-value"><?= esc_html($company['account_number']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['branch_code'])): ?>
                  <tr>
                    <td class="col-label dCells">Branch Code</td>
                    <td class="col-value"><?= esc_html($company['branch_code']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['account_type'])): ?>
                  <tr>
                    <td class="col-label dCells">Account Type</td>
                    <td class="col-value"><?= esc_html($company['account_type']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['swift_code'])): ?>
                  <tr>
                    <td class="col-label dCells">SWIFT</td>
                    <td class="col-value"><?= esc_html($company['swift_code']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['iban'])): ?>
                  <tr>
                    <td class="col-label dCells">IBAN</td>
                    <td class="col-value"><?= esc_html($company['iban']); ?></td>
                  </tr>
                <?php endif; ?>
              </table>
            </div>
          </td>
          <!-- Terms & Conditions Card -->
          <td style="width: 60%;">
            <div class="fine-print" style="margin-top:0; border-top:0; padding-top:0;">
              <div class="section-heading" style="font-size:12px; margin:0 0 8px 0; padding-bottom:10px;">
                Terms &amp; Conditions
              </div>
              <?= trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $terms_html)))); ?>
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<script type="text/php">
    if (isset($pdf)) {
        // Add page number and footer text at bottom center
        // A4 page: width ~595 points, height ~842 points
        $font = $fontMetrics->getFont("Arial", "normal");
        $size = 9;
        $color = array(0.55, 0.55, 0.55); // Light gray
        $page_text = "Page {PAGE_NUM} of {PAGE_COUNT} | Generated by KAYISE IT";
        // Use approximate center position (A4 width is 595 points)
        // Estimate text width for "Page 999 of 999 | Generated by KAYISE IT" (~200 points)
        $text_width = 200;
        $x = (595 - $text_width) / 2; // Center on A4 width
        $y = 820; // Position near bottom (842 is full height)
        $pdf->page_text($x, $y, $page_text, $font, $size, $color);
    }
</script>
