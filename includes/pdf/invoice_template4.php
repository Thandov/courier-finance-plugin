<?php
/**
 * Invoice template 4 — simple client skeleton (default).
 * DomPDF-safe: hex colours (no CSS vars on critical paints), tables for bands,
 * total in its own table (not a tbody row DomPDF can blank out).
 * Variables from kit_waybill_pdf_prepare_invoice_context().
 */
if (!defined('ABSPATH')) {
    exit;
}

$pdf_grand_total = isset($invoice_grand_total) ? floatval($invoice_grand_total) : floatval($final_total);
$logo_src = !empty($logo_data_uri)
    ? $logo_data_uri
    : esc_url(plugin_dir_url($plugin_root . 'pdf-generator.php') . 'img/logo.png');

$bill_email = trim((string) ($consignee_email ?? ''));
$bill_cell = trim((string) ($consignee_cell ?? ''));
if ($bill_email !== '' && (
    strcasecmp($bill_email, 'customer@customer.com') === 0
    || strcasecmp($bill_email, 'N/A') === 0
)) {
    $bill_email = '';
}
if ($bill_cell !== '' && (
    strcasecmp($bill_cell, 'N/A') === 0
    || strcasecmp($bill_cell, '0') === 0
)) {
    $bill_cell = '';
}

$route_parts = array_filter([
    trim((string) ($route_description ?? '')),
    trim((string) ($destination_city ?? '')),
], static function ($v) {
    return $v !== '';
});
$route_line = !empty($route_parts) ? implode(', ', $route_parts) : '';

$pay_ref = (isset($invoice_number) && trim((string) $invoice_number) !== '')
    ? (string) $invoice_number
    : 'WB-' . (string) $waybill_no;
?>
<style>
  @page {
    margin: 10mm 10mm 14mm 10mm;
  }

  body {
    margin: 0;
    padding: 0;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    line-height: 1.35;
    color: #333333;
  }

  table {
    border-collapse: collapse;
    width: 100%;
  }

  .section-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: #6b7280;
  }

  .party-name {
    font-weight: 700;
    color: #111111;
  }

  .charges {
    table-layout: fixed;
    margin: 0 0 0 0;
  }

  .charges th {
    background-color: #e9ecef;
    color: #374151;
    border: 1px solid #d1d5db;
    padding: 6px 6px;
    font-weight: 700;
    font-size: 11px;
  }

  .charges td {
    border: 1px solid #d1d5db;
    padding: 6px 6px;
    vertical-align: top;
    background-color: #ffffff;
    font-size: 11px;
  }

  .col-desc { width: 46%; text-align: left; }
  .col-qty { width: 16%; text-align: center; }
  .col-rate { width: 19%; text-align: right; }
  .col-amt { width: 19%; text-align: right; font-weight: 700; }

  .line-meta {
    display: block;
    color: #6b7280;
    font-size: 10px;
    font-weight: 400;
  }

  .totals {
    table-layout: fixed;
    margin: 0 0 14px 0;
  }

  .totals td {
    background-color: #4b5563;
    border: 1px solid #4b5563;
    color: #ffffff;
    font-weight: 700;
    font-size: 12px;
    padding: 8px 6px;
  }

  .pay-table {
    table-layout: fixed;
    margin: 2px 0 0 0;
  }

  .pay-table td {
    padding: 2px 0;
    vertical-align: top;
    font-size: 11px;
  }

  .pay-table .b-label {
    width: 22%;
    color: #6b7280;
  }

  .pay-table .b-value {
    width: 78%;
    font-weight: 700;
    color: #111111;
  }

  .terms-list,
  .terms-list ul {
    margin: 2px 0 0 0;
    padding-left: 16px;
  }

  .terms-list li {
    margin: 0 0 3px 0;
    color: #333333;
  }
</style>

<!-- HEADER: logo | INVOICE | meta -->
<table cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
  <tr>
    <td style="width:40%; vertical-align:top;">
      <img src="<?= $logo_src ?>" alt="08600 Couriers" style="display:block; width:170px; max-width:100%; height:auto;">
    </td>
    <td style="width:20%; vertical-align:middle; text-align:center;">
      <div style="font-size:22px; font-weight:700; color:#6b7280; letter-spacing:2px; text-transform:uppercase; line-height:1.1;">INVOICE</div>
    </td>
    <td style="width:40%; vertical-align:top; text-align:right;">
      <table cellpadding="0" cellspacing="0" style="width:100%;">
        <tr>
          <td style="color:#6b7280; text-align:right; padding:1px 8px 1px 0; white-space:nowrap;">Invoice #</td>
          <td style="font-weight:700; color:#111111; text-align:right; padding:1px 0; white-space:nowrap;"><?= esc_html($invoice_number ?? '') ?></td>
        </tr>
        <tr>
          <td style="color:#6b7280; text-align:right; padding:1px 8px 1px 0; white-space:nowrap;">Waybill #</td>
          <td style="font-weight:700; color:#111111; text-align:right; padding:1px 0; white-space:nowrap;"><?= esc_html((string) ($waybill_no ?? '')) ?></td>
        </tr>
        <tr>
          <td style="color:#6b7280; text-align:right; padding:1px 8px 1px 0; white-space:nowrap;">Date</td>
          <td style="font-weight:700; color:#111111; text-align:right; padding:1px 0; white-space:nowrap;"><?= esc_html($invoice_date_display ?? '') ?></td>
        </tr>
        <?php if (!empty($tracking_is_active) && trim((string) ($tracking_number ?? '')) !== ''): ?>
          <tr>
            <td style="color:#6b7280; text-align:right; padding:1px 8px 1px 0; white-space:nowrap;">Tracking #</td>
            <td style="font-weight:700; color:#111111; text-align:right; padding:1px 0; white-space:nowrap;"><?= esc_html($tracking_number) ?></td>
          </tr>
        <?php endif; ?>
      </table>
    </td>
  </tr>
</table>

<table cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
  <tr>
    <td style="border-top:1px solid #d1d5db; font-size:1px; line-height:1px; height:1px;">&nbsp;</td>
  </tr>
</table>

<!-- FROM / BILL TO — equal columns, DomPDF-friendly -->
<table cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
  <tr>
    <td style="width:48%; vertical-align:top; padding:0 10px 0 0;">
      <div class="section-label" style="margin-bottom:4px;">From</div>
      <div class="party-name" style="margin-bottom:2px;"><?= esc_html($shipper_name ?? '') ?></div>
      <div style="line-height:1.4; color:#333333;">
        <?php if (!empty($shipper_address)): ?>
          <?= nl2br(esc_html($shipper_address)) ?><br>
        <?php endif; ?>
        <?php if (!empty($shipper_phone)): ?>
          <?= esc_html($shipper_phone) ?><br>
        <?php endif; ?>
        <?php if (!empty($shipper_email)): ?>
          <?= esc_html($shipper_email) ?><br>
        <?php endif; ?>
        <?php if (!empty($shipper_vat)): ?>
          VAT <?= esc_html($shipper_vat) ?>
        <?php endif; ?>
      </div>
    </td>
    <td style="width:4%;">&nbsp;</td>
    <td style="width:48%; vertical-align:top; padding:0 0 0 10px; border-left:1px solid #e5e7eb;">
      <div class="section-label" style="margin-bottom:4px;">Bill To</div>
      <?php if ($consignee_company !== '' && strcasecmp($consignee_company, 'Individual') !== 0): ?>
        <div class="party-name" style="margin-bottom:2px;"><?= esc_html($consignee_company) ?></div>
      <?php endif; ?>
      <?php if ($consignee_name !== ''): ?>
        <?php if ($consignee_company === '' || strcasecmp($consignee_company, 'Individual') === 0): ?>
          <div class="party-name" style="margin-bottom:2px;"><?= esc_html($consignee_name) ?></div>
        <?php else: ?>
          <div style="margin-bottom:2px;"><?= esc_html($consignee_name) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <div style="line-height:1.4; color:#333333;">
        <?php if ($consignee_address !== '' && strcasecmp($consignee_address, 'No Address') !== 0): ?>
          <?= nl2br(esc_html($consignee_address)) ?><br>
        <?php endif; ?>
        <?php if ($bill_email !== ''): ?>
          <?= esc_html($bill_email) ?><br>
        <?php endif; ?>
        <?php if ($bill_cell !== ''): ?>
          <?= esc_html($bill_cell) ?>
        <?php endif; ?>
      </div>
    </td>
  </tr>
</table>

<!-- ROUTE — single table cell so background wraps label + value -->
<?php if ($route_line !== ''): ?>
  <table cellpadding="0" cellspacing="0" style="margin-bottom:12px;">
    <tr>
      <td style="background-color:#e9ecef; padding:7px 10px;">
        <span class="section-label">Route</span><br>
        <span style="font-weight:700; font-size:12px; color:#111111;"><?= esc_html($route_line) ?></span>
      </td>
    </tr>
  </table>
<?php endif; ?>

<!-- CHARGES (line items only — total is separate) -->
<table class="charges" cellpadding="0" cellspacing="0">
  <colgroup>
    <col class="col-desc">
    <col class="col-qty">
    <col class="col-rate">
    <col class="col-amt">
  </colgroup>
  <tr>
    <th class="col-desc" align="left">Description</th>
    <th class="col-qty" align="center">Qty / Unit</th>
    <th class="col-rate" align="right">Rate</th>
    <th class="col-amt" align="right">Amount (R)</th>
  </tr>
  <tr>
    <td class="col-desc">Transport - <?= esc_html($charge_type) ?> Charge</td>
    <td class="col-qty" align="center"><?= esc_html($transport_qty_display) ?></td>
    <td class="col-rate" align="right"><?= esc_html($transport_rate_display) ?></td>
    <td class="col-amt" align="right">R <?= number_format($charge_amount, 2) ?></td>
  </tr>

  <?php if (!empty($misc_items)): ?>
    <?php foreach ($misc_items as $item):
      $mi_name = htmlspecialchars($item['misc_item'] ?? '');
      $mi_qty = intval($item['misc_quantity'] ?? 0);
      $mi_price = floatval($item['misc_price'] ?? 0);
      $mi_subtotal = $mi_qty * $mi_price;
    ?>
      <tr>
        <td class="col-desc">Miscellaneous - <?= $mi_name ?></td>
        <td class="col-qty" align="center"><?= $mi_qty ?></td>
        <td class="col-rate" align="right">R <?= number_format($mi_price, 2) ?></td>
        <td class="col-amt" align="right">R <?= number_format($mi_subtotal, 2) ?></td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($waybill->include_sad500) && intval($waybill->include_sad500) === 1): ?>
    <tr>
      <td class="col-desc">Processing - SAD500</td>
      <td class="col-qty" align="center">1</td>
      <td class="col-rate" align="right">R <?= number_format($sad500_total, 2) ?></td>
      <td class="col-amt" align="right">R <?= number_format($sad500_total, 2) ?></td>
    </tr>
  <?php endif; ?>

  <?php if (!empty($waybill->include_sadc) && intval($waybill->include_sadc) === 1): ?>
    <tr>
      <td class="col-desc">Processing - SADC Certificate</td>
      <td class="col-qty" align="center">1</td>
      <td class="col-rate" align="right">R <?= number_format($sadc_total, 2) ?></td>
      <td class="col-amt" align="right">R <?= number_format($sadc_total, 2) ?></td>
    </tr>
  <?php endif; ?>

  <?php if ($show_agent_clearing && $intl_amount_for_total > 0): ?>
    <tr>
      <td class="col-desc">Customs Clearing - Agent &amp; Documentation</td>
      <td class="col-qty" align="center">1</td>
      <td class="col-rate" align="right">R <?= number_format($intl_amount_for_total, 2) ?></td>
      <td class="col-amt" align="right">R <?= number_format($intl_amount_for_total, 2) ?></td>
    </tr>
  <?php endif; ?>

  <?php
  // Border clearing fee only — no parcel description lines (Mel, 2026-08-05).
  if ($show_border_clearing_vat && $border_clearing_10_percent_total > 0):
  ?>
    <tr>
      <td class="col-desc">
        Border Clearing Fee<br>
        <span class="line-meta">10% of declared value</span>
      </td>
      <td class="col-qty" align="center">1</td>
      <td class="col-rate" align="right">10%</td>
      <td class="col-amt" align="right">R <?= number_format($border_clearing_10_percent_total, 2) ?></td>
    </tr>
  <?php endif; ?>
</table>

<!-- TOTAL DUE — own table so DomPDF cannot blank the last tbody row -->
<table class="totals" cellpadding="0" cellspacing="0">
  <colgroup>
    <col style="width:81%;">
    <col style="width:19%;">
  </colgroup>
  <tr>
    <td style="background-color:#4b5563; border:1px solid #4b5563; color:#ffffff; font-weight:700; font-size:12px; padding:8px 6px; text-align:left;">TOTAL DUE</td>
    <td style="background-color:#4b5563; border:1px solid #4b5563; color:#ffffff; font-weight:700; font-size:12px; padding:8px 6px; text-align:right;">R <?= number_format($pdf_grand_total, 2) ?></td>
  </tr>
</table>

<!-- PAY TO -->
<div class="section-label" style="margin:0 0 4px 0;">Pay To</div>
<table class="pay-table" cellpadding="0" cellspacing="0" style="margin-bottom:12px;">
  <?php if (!empty($company['bank_name'])): ?>
    <tr>
      <td class="b-label">Bank</td>
      <td class="b-value"><?= esc_html($company['bank_name']) ?></td>
    </tr>
  <?php endif; ?>
  <?php if (!empty($company['account_holder'])): ?>
    <tr>
      <td class="b-label">Account holder</td>
      <td class="b-value"><?= esc_html($company['account_holder']) ?></td>
    </tr>
  <?php endif; ?>
  <?php if (!empty($company['account_number'])): ?>
    <tr>
      <td class="b-label">Account #</td>
      <td class="b-value"><?= esc_html($company['account_number']) ?></td>
    </tr>
  <?php endif; ?>
  <?php if (!empty($company['branch_code'])): ?>
    <tr>
      <td class="b-label">Branch code</td>
      <td class="b-value"><?= esc_html($company['branch_code']) ?></td>
    </tr>
  <?php endif; ?>
  <?php if (!empty($company['account_type'])): ?>
    <tr>
      <td class="b-label">Account type</td>
      <td class="b-value"><?= esc_html($company['account_type']) ?></td>
    </tr>
  <?php endif; ?>
  <?php if (!empty($company['swift_code'])): ?>
    <tr>
      <td class="b-label">SWIFT</td>
      <td class="b-value"><?= esc_html($company['swift_code']) ?></td>
    </tr>
  <?php endif; ?>
  <?php if (!empty($company['iban'])): ?>
    <tr>
      <td class="b-label">IBAN</td>
      <td class="b-value"><?= esc_html($company['iban']) ?></td>
    </tr>
  <?php endif; ?>
  <tr>
    <td class="b-label">Reference</td>
    <td class="b-value"><?= esc_html($pay_ref) ?></td>
  </tr>
</table>

<!-- TERMS -->
<div class="section-label" style="margin:0 0 4px 0;">Terms &amp; Conditions</div>
<?php
$terms_clean = trim((string) ($terms_html ?? ''));
if ($terms_clean !== '') {
    $allowed = '<ul><ol><li><strong><em><b><i>';
    $has_list_tags = stripos($terms_clean, '<li') !== false;
    if ($has_list_tags) {
        echo '<div class="terms-list">' . strip_tags($terms_clean, $allowed) . '</div>';
    } else {
        $terms_lines = preg_split('/\R+|<br\s*\/?>/i', strip_tags($terms_clean));
        echo '<ul class="terms-list">';
        foreach ($terms_lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                echo '<li>' . esc_html($line) . '</li>';
            }
        }
        echo '</ul>';
    }
}
?>

<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont("Arial", "normal");
        $size = 9;
        $color = array(0.55, 0.55, 0.55);
        $page_text = "Page {PAGE_NUM} of {PAGE_COUNT} | Generated by KAYISE IT";
        $text_width = 200;
        $x = (595 - $text_width) / 2;
        $y = 820;
        $pdf->page_text($x, $y, $page_text, $font, $size, $color);
    }
</script>
