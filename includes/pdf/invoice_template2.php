<?php
/**
 * Invoice template 2 — professional tax invoice layout.
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
  }

  @page {
    margin: 12mm 12mm 18mm 12mm;
  }

  body {
    margin: 0;
    padding: 0;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10.5px;
    line-height: 1.4;
    color: #1f2937;
  }

  table {
    border-collapse: collapse;
  }

  /* ---------- HEADER ---------- */
  .doc-header {
    width: 100%;
    border-bottom: 2px solid var(--primary);
    padding-bottom: 10px;
    margin-bottom: 14px;
  }

  .doc-header .brand-name {
    font-size: 17px;
    font-weight: 700;
    color: var(--primary);
    margin: 0;
    line-height: 1.1;
  }

  .doc-header .brand-tagline {
    font-size: 9.5px;
    color: #6b7280;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    margin-top: 2px;
  }

  .doc-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--primary);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin: 0;
    line-height: 1;
  }

  .doc-meta {
    margin-top: 8px;
    font-size: 10px;
    color: #1f2937;
  }

  .doc-meta .meta-label {
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 8.5px;
    padding-right: 8px;
  }

  .doc-meta .meta-value {
    font-weight: 700;
    color: #111827;
  }

  /* ---------- PARTY CARDS ---------- */
  .party-block {
    width: 100%;
    margin-bottom: 12px;
  }

  .party-card {
    border: 1px solid #e5e7eb;
    border-top: 3px solid var(--primary);
    padding: 8px 10px;
    background: #fafafa;
  }

  .party-card .party-label {
    font-size: 8.5px;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--primary);
    font-weight: 700;
    margin-bottom: 4px;
  }

  .party-card .party-name {
    font-size: 11.5px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 2px;
  }

  .party-card .party-detail {
    font-size: 9.5px;
    color: #374151;
    line-height: 1.45;
  }

  .party-card .party-detail .field {
    color: #6b7280;
  }

  /* ---------- ROUTE STRIP ---------- */
  .route-strip {
    background: var(--primary);
    color: #fff;
    padding: 6px 10px;
    font-size: 11px;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    font-weight: 700;
    margin-bottom: 12px;
  }

  .route-strip .route-arrow {
    margin: 0 6px;
    opacity: 0.85;
  }

  /* ---------- SHIPMENT PARTICULARS ---------- */
  .section-title {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--secondary);
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 3px;
    margin-bottom: 6px;
  }

  .particulars {
    width: 100%;
    border: 1px solid #e5e7eb;
    margin-bottom: 14px;
  }

  .particulars td {
    padding: 6px 8px;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
  }

  .particulars td:last-child {
    border-right: 0;
  }

  .particulars tr:last-child td {
    border-bottom: 0;
  }

  .particulars .field {
    font-size: 8.5px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #6b7280;
    margin-bottom: 2px;
  }

  .particulars .value {
    font-size: 10.5px;
    font-weight: 700;
    color: #111827;
  }

  /* ---------- CHARGES TABLE ---------- */
  .charges {
    width: 100%;
    margin-bottom: 0;
  }

  .charges th {
    background: var(--secondary);
    color: #fff;
    text-align: left;
    font-size: 9.5px;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    padding: 8px 8px;
    border-bottom: 2px solid var(--primary);
  }

  .charges th.num {
    text-align: right;
  }

  .charges th.center {
    text-align: center;
  }

  .charges td {
    padding: 7px 8px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
    font-size: 10.5px;
  }

  .charges td.num {
    text-align: right;
    white-space: nowrap;
  }

  .charges td.center {
    text-align: center;
    white-space: nowrap;
  }

  .charges tr.line-row td {
    background: #ffffff;
  }

  .charges td .line-title {
    font-weight: 700;
    color: #111827;
  }

  .charges td .line-meta {
    display: block;
    font-size: 9px;
    color: #6b7280;
    margin-top: 1px;
  }

  /* ---------- TOTALS ---------- */
  .totals-wrap {
    width: 100%;
    margin-top: 0;
    margin-bottom: 14px;
  }

  .totals-table {
    width: 45%;
    margin-left: auto;
    margin-right: 0;
  }

  .totals-table td {
    padding: 4px 8px;
    font-size: 10.5px;
  }

  .totals-table td.label {
    text-align: right;
    color: #4b5563;
  }

  .totals-table td.value {
    text-align: right;
    font-weight: 700;
    color: #111827;
    white-space: nowrap;
  }

  .totals-table tr.grand td {
    background: var(--primary);
    color: #fff;
    font-size: 14px;
    padding: 9px 10px;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    border-top: 0;
  }

  .totals-table tr.grand td.value {
    color: #fff;
    font-size: 16px;
  }

  /* ---------- FOOTER (BANKING + TERMS) ---------- */
  .footer-grid {
    width: 100%;
    margin-top: 6px;
  }

  .footer-grid td.bank-col {
    width: 50%;
    padding-right: 10px;
    vertical-align: top;
  }

  .footer-grid td.terms-col {
    width: 50%;
    padding-left: 10px;
    vertical-align: top;
    border-left: 1px solid #e5e7eb;
  }

  .footer-grid .section-title {
    margin-bottom: 6px;
  }

  .bank-table {
    width: 100%;
  }

  .bank-table td {
    padding: 3px 0;
    font-size: 10px;
    vertical-align: top;
  }

  .bank-table td.b-label {
    color: #6b7280;
    width: 38%;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 9px;
  }

  .bank-table td.b-value {
    font-weight: 700;
    color: #111827;
    text-align: right;
  }

  .terms-body {
    font-size: 9.5px;
    color: #374151;
    line-height: 1.5;
  }

  .terms-body ul {
    margin: 0;
    padding-left: 16px;
  }

  .terms-body li {
    margin: 0 0 3px 0;
  }

  /* ---------- SIGN-OFF ---------- */
  .sign-off {
    margin-top: 18px;
    padding-top: 8px;
    border-top: 1px solid #e5e7eb;
    text-align: center;
    font-size: 9.5px;
    color: #6b7280;
  }

  .sign-off .thanks {
    color: var(--primary);
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    font-size: 10px;
    margin-bottom: 4px;
  }

  .reference-caption {
    font-size: 11px;
    font-weight: 700;
    color: #374151;
    margin: 12px 0 4px 0;
  }

  .reference-note {
    font-size: 9px;
    color: #6b7280;
    margin: 0 0 6px 0;
    font-style: italic;
  }

  .reference-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 0;
  }

  .reference-table th {
    background: #f9fafb;
    color: #374151;
    font-size: 9.5px;
    font-weight: 600;
    border: 1px solid #d1d5db;
    padding: 5px 8px;
  }

  .reference-table td {
    border: 1px solid #e5e7eb;
    padding: 4px 8px;
    font-size: 9.5px;
    color: #4b5563;
  }

  .reference-table tr.reference-total td {
    background: #f3f4f6;
    font-weight: 700;
    color: #111827;
  }
</style>

<!-- ============================================================
     08600 AFRICA — TAX INVOICE / WAYBILL
     ============================================================ -->

<!-- HEADER -->
<table class="doc-header" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:55%; vertical-align:top;">
      <?php if ($logo_data_uri !== ''): ?>
        <img src="<?= $logo_data_uri ?>" alt="08600 Africa" style="height:48px; width:auto; display:block; margin-bottom:6px;">
      <?php endif; ?>
      <div class="brand-name"><?= esc_html($shipper_name) ?></div>
      <div class="brand-tagline">Cross-Border Courier &amp; Freight Forwarding</div>
    </td>
    <td style="width:45%; vertical-align:top; text-align:right;">
      <div class="doc-title">Tax Invoice</div>
      <table class="doc-meta" cellpadding="0" cellspacing="0" style="margin-left:auto; margin-right:0;">
        <tr>
          <td class="meta-label" align="right">Invoice No.</td>
          <td class="meta-value" align="right"><?= esc_html($invoice_number) ?></td>
        </tr>
        <tr>
          <td class="meta-label" align="right">Issue Date</td>
          <td class="meta-value" align="right"><?= esc_html($invoice_date_display) ?></td>
        </tr>
        <tr>
          <td class="meta-label" align="right">Waybill No.</td>
          <td class="meta-value" align="right">#<?= esc_html((string) $waybill_no) ?></td>
        </tr>
        <tr>
          <td class="meta-label" align="right">Status</td>
          <td class="meta-value" align="right"><?= esc_html($invoice_status_label) ?> &middot; <?= esc_html($approval_status_label) ?></td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<!-- SHIPPER & CONSIGNEE -->
<table class="party-block" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:50%; padding-right:6px; vertical-align:top;">
      <div class="party-card">
        <div class="party-label">Shipper / From</div>
        <div class="party-name"><?= esc_html($shipper_name) ?></div>
        <div class="party-detail">
          <?php if ($shipper_address !== ''): ?>
            <?= nl2br(esc_html($shipper_address)) ?><br>
          <?php endif; ?>
          <?php if ($shipper_phone !== ''): ?>
            <span class="field">Tel:</span> <?= esc_html($shipper_phone) ?><br>
          <?php endif; ?>
          <?php if ($shipper_email !== ''): ?>
            <span class="field">Email:</span> <?= esc_html($shipper_email) ?><br>
          <?php endif; ?>
          <?php if ($shipper_vat !== ''): ?>
            <span class="field">VAT No.:</span> <?= esc_html($shipper_vat) ?>
          <?php endif; ?>
        </div>
      </div>
    </td>
    <td style="width:50%; padding-left:6px; vertical-align:top;">
      <div class="party-card">
        <div class="party-label">Consignee / Bill To</div>
        <div class="party-name">
          <?php if ($consignee_company !== '' && strcasecmp($consignee_company, 'Individual') !== 0): ?>
            <?= esc_html($consignee_company) ?>
          <?php else: ?>
            <?= esc_html($consignee_name !== '' ? $consignee_name : 'Customer') ?>
          <?php endif; ?>
        </div>
        <div class="party-detail">
          <?php if ($consignee_company !== '' && $consignee_name !== '' && strcasecmp($consignee_company, 'Individual') !== 0): ?>
            <?= esc_html($consignee_name) ?><br>
          <?php endif; ?>
          <?php if ($consignee_address !== '' && strcasecmp($consignee_address, 'No Address') !== 0): ?>
            <?= nl2br(esc_html($consignee_address)) ?><br>
          <?php endif; ?>
          <?php if ($consignee_country !== ''): ?>
            <?= esc_html($consignee_country) ?><br>
          <?php endif; ?>
          <?php if ($consignee_email !== ''): ?>
            <span class="field">Email:</span> <?= esc_html($consignee_email) ?><br>
          <?php endif; ?>
          <?php if ($consignee_cell !== ''): ?>
            <span class="field">Cell:</span> <?= esc_html($consignee_cell) ?>
          <?php endif; ?>
        </div>
      </div>
    </td>
  </tr>
</table>

<!-- ROUTE STRIP -->
<?php
$route_left = $shipper_vat !== '' ? 'South Africa' : 'Origin';
$route_right_parts = array_filter([$destination_city, $route_description], function ($v) { return $v !== ''; });
$route_right = $route_right_parts ? implode(' &middot; ', array_map('esc_html', $route_right_parts)) : 'Destination';
?>
<div class="route-strip">
  Route &nbsp; <?= esc_html($route_left) ?> <span class="route-arrow">&rarr;</span> <?= $route_right ?>
</div>

<!-- SHIPMENT PARTICULARS -->
<div class="section-title">Shipment Particulars</div>
<table class="particulars" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:25%;">
      <div class="field">Waybill No.</div>
      <div class="value">#<?= esc_html((string) $waybill_no) ?></div>
    </td>
    <td style="width:25%;">
      <div class="field">Charge Basis</div>
      <div class="value"><?= esc_html(ucfirst($charge_type)) ?></div>
    </td>
    <td style="width:25%;">
      <div class="field">Total Mass</div>
      <div class="value"><?= number_format($mass_kg, 2) ?> kg</div>
    </td>
    <td style="width:25%;">
      <div class="field">Total Volume</div>
      <div class="value"><?= number_format($volume_m3, 3) ?> m&sup3;</div>
    </td>
  </tr>
  <tr>
    <td>
      <div class="field">Dimensions (L &times; W &times; H)</div>
      <div class="value"><?= number_format($length_cm, 0) ?> &times; <?= number_format($width_cm, 0) ?> &times; <?= number_format($height_cm, 0) ?> cm</div>
    </td>
    <td>
      <div class="field">Origin</div>
      <div class="value"><?= esc_html($route_left) ?></div>
    </td>
    <td colspan="2">
      <div class="field">Destination</div>
      <div class="value"><?= $route_right ?></div>
    </td>
  </tr>
</table>

<!-- CHARGES & SERVICES -->
<div class="section-title">Charges &amp; Services</div>
<table class="charges" cellpadding="0" cellspacing="0">
  <thead>
    <tr>
      <th style="width:50%;">Description</th>
      <th class="center" style="width:15%;">Qty</th>
      <th class="num" style="width:17.5%;">Rate (ZAR)</th>
      <th class="num" style="width:17.5%;">Amount (ZAR)</th>
    </tr>
  </thead>
  <tbody>
    <!-- Transport line -->
    <tr class="line-row">
      <td>
        <span class="line-title">Transport &mdash; <?= esc_html($charge_type) ?> Charge</span>
        <span class="line-meta">Cross-border road freight</span>
      </td>
      <td class="center"><?= esc_html($transport_qty_display) ?></td>
      <td class="num"><?= esc_html($transport_rate_display) ?></td>
      <td class="num">R <?= number_format($charge_amount, 2) ?></td>
    </tr>

    <!-- Miscellaneous items -->
    <?php if (!empty($misc_items)): ?>
      <?php foreach ($misc_items as $mi):
        $mi_name = htmlspecialchars($mi['misc_item'] ?? '');
        $mi_qty = intval($mi['misc_quantity'] ?? 0);
        $mi_price = floatval($mi['misc_price'] ?? 0);
        $mi_subtotal = $mi_qty * $mi_price;
      ?>
        <tr class="line-row">
          <td>
            <span class="line-title">Miscellaneous &mdash; <?= $mi_name ?></span>
          </td>
          <td class="center"><?= $mi_qty ?></td>
          <td class="num">R <?= number_format($mi_price, 2) ?></td>
          <td class="num">R <?= number_format($mi_subtotal, 2) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- SAD500 -->
    <?php if (!empty($waybill->include_sad500) && intval($waybill->include_sad500) === 1): ?>
      <tr class="line-row">
        <td>
          <span class="line-title">Processing &mdash; SAD500</span>
          <span class="line-meta">Customs export declaration</span>
        </td>
        <td class="center">1</td>
        <td class="num">R <?= number_format($sad500_total, 2) ?></td>
        <td class="num">R <?= number_format($sad500_total, 2) ?></td>
      </tr>
    <?php endif; ?>

    <!-- SADC Certificate -->
    <?php if (!empty($waybill->include_sadc) && intval($waybill->include_sadc) === 1): ?>
      <tr class="line-row">
        <td>
          <span class="line-title">Processing &mdash; SADC Certificate</span>
          <span class="line-meta">Certificate of Origin</span>
        </td>
        <td class="center">1</td>
        <td class="num">R <?= number_format($sadc_total, 2) ?></td>
        <td class="num">R <?= number_format($sadc_total, 2) ?></td>
      </tr>
    <?php endif; ?>

    <!-- Agent Clearing & Documentation -->
    <?php if ($show_agent_clearing && $intl_amount_for_total > 0): ?>
      <tr class="line-row">
        <td>
          <span class="line-title">Customs Clearing &mdash; Agent &amp; Documentation</span>
          <span class="line-meta">Destination clearing agent fees</span>
        </td>
        <td class="center">1</td>
        <td class="num">R <?= number_format($intl_amount_for_total, 2) ?></td>
        <td class="num">R <?= number_format($intl_amount_for_total, 2) ?></td>
      </tr>
    <?php endif; ?>

    <!--
      Border Clearing fee — the 10% line only.

      The itemised parcel rows removed here listed every item name and declared
      value: the descriptions the client asked to have off the invoice (Mel,
      2026-08-05). Kept in step with invoice_template1 so switching template
      cannot bring them back.

      No amount changes — declared parcel values were never part of the total.
    -->
    <?php if ($show_border_clearing_vat && $border_clearing_10_percent_total > 0): ?>
      <tr class="line-row">
        <td>
          <span class="line-title">Border Clearing Fee</span>
          <span class="line-meta">10% of declared parcel value</span>
        </td>
        <td class="center">1</td>
        <td class="num">10%</td>
        <td class="num">R <?= number_format($border_clearing_10_percent_total, 2) ?></td>
      </tr>
    <?php endif; ?>
  </tbody>
</table>

<!-- TOTALS -->
<?php $pdf_grand_total = isset($invoice_grand_total) ? floatval($invoice_grand_total) : floatval($final_total); ?>
<table class="totals-wrap" cellpadding="0" cellspacing="0">
  <tr>
    <td>
      <table class="totals-table" cellpadding="0" cellspacing="0">
        <tr class="grand">
          <td class="label">Total Due</td>
          <td class="value">R <?= number_format($pdf_grand_total, 2) ?></td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<!-- FOOTER: BANKING + TERMS -->
<table class="footer-grid" cellpadding="0" cellspacing="0">
  <tr>
    <td class="bank-col">
      <div class="section-title">Banking Details</div>
      <table class="bank-table" cellpadding="0" cellspacing="0">
        <?php if (!empty($company['bank_name'])): ?>
          <tr><td class="b-label">Bank</td><td class="b-value"><?= esc_html($company['bank_name']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($company['account_holder'])): ?>
          <tr><td class="b-label">Account Holder</td><td class="b-value"><?= esc_html($company['account_holder']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($company['account_number'])): ?>
          <tr><td class="b-label">Account No.</td><td class="b-value"><?= esc_html($company['account_number']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($company['branch_code'])): ?>
          <tr><td class="b-label">Branch Code</td><td class="b-value"><?= esc_html($company['branch_code']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($company['account_type'])): ?>
          <tr><td class="b-label">Account Type</td><td class="b-value"><?= esc_html($company['account_type']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($company['swift_code'])): ?>
          <tr><td class="b-label">SWIFT / BIC</td><td class="b-value"><?= esc_html($company['swift_code']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($company['iban'])): ?>
          <tr><td class="b-label">IBAN</td><td class="b-value"><?= esc_html($company['iban']) ?></td></tr>
        <?php endif; ?>
        <tr>
          <td class="b-label">Reference</td>
          <td class="b-value"><?= esc_html($invoice_number !== '' ? $invoice_number : 'WB-' . (string) $waybill_no) ?></td>
        </tr>
      </table>
    </td>
    <td class="terms-col">
      <div class="section-title">Terms &amp; Conditions</div>
      <div class="terms-body">
        <?php
        $terms_clean = trim((string) $terms_html);
        if ($terms_clean !== '') {
            $terms_lines = preg_split('/\R+|<br\s*\/?>/i', strip_tags($terms_clean, '<ul><ol><li><strong><em><b><i>'));
            $allowed = '<ul><ol><li><strong><em><b><i>';
            $has_list_tags = stripos($terms_clean, '<li') !== false;
            if ($has_list_tags) {
                echo strip_tags($terms_clean, $allowed);
            } else {
                echo '<ul>';
                foreach ($terms_lines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        echo '<li>' . $line . '</li>';
                    }
                }
                echo '</ul>';
            }
        }
        ?>
      </div>
    </td>
  </tr>
</table>

<!-- SIGN OFF -->
<div class="sign-off">
  <div class="thanks">Thank you for shipping with 08600 Africa</div>
  All amounts are in South African Rand (ZAR) and inclusive of applicable charges. This invoice is computer-generated and valid without signature.
</div>

<script type="text/php">
    if (isset($pdf)) {
        // Add page number and footer text at bottom center
        // A4 page: width ~595 points, height ~842 points
        $font = $fontMetrics->getFont("Arial", "normal");
        $size = 9;
        $color = array(0.4, 0.4, 0.4); // Gray color
        $page_text = "Page {PAGE_NUM} of {PAGE_COUNT} | Generated by KAYISE IT";
        // Use approximate center position (A4 width is 595 points)
        // Estimate text width for "Page 999 of 999 | Generated by KAYISE IT" (~200 points)
        $text_width = 200;
        $x = (595 - $text_width) / 2; // Center on A4 width
        $y = 820; // Position near bottom (842 is full height)
        $pdf->page_text($x, $y, $page_text, $font, $size, $color);
    }
</script>
