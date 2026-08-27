<?php
// Lightweight PDF/HTML summary for waybill accessible to Manager & Data Capturer

if (! defined('ABSPATH')) {
    require_once dirname(__FILE__, 4) . '/wp-load.php';
}

if (!class_exists('KIT_User_Roles')) {
    wp_die('Access denied', 403);
}

$waybill_no = isset($_GET['waybill_no']) ? (int) $_GET['waybill_no'] : 0;
if (! $waybill_no) {
    wp_die('Missing waybill_no');
}

$data = KIT_Waybills::getFullWaybillWithItems($waybill_no);
if (! $data || empty($data->waybill)) {
    wp_die('Waybill not found');
}

$w = (object) $data->waybill;

$is_portal = is_user_logged_in()
    && KIT_User_Roles::is_portal_customer()
    && KIT_User_Roles::can_access_customer_portal()
    && KIT_User_Roles::get_portal_customer_id() > 0;

if ($is_portal) {
    if ((int) ($w->customer_id ?? 0) !== KIT_User_Roles::get_portal_customer_id()) {
        wp_die('Access denied', 403);
    }
} elseif (!(KIT_User_Roles::is_admin() || KIT_User_Roles::is_manager() || KIT_User_Roles::is_data_capturer())) {
    wp_die('Access denied', 403);
}

$items = is_array($data->items) ? $data->items : array();

if (! class_exists('KIT_Deliveries')) {
    require_once __DIR__ . '/includes/deliveries/deliveries-functions.php';
}

$delivery = (!empty($w->delivery_id)) ? KIT_Deliveries::get_delivery((int) $w->delivery_id) : null;

$totals = KIT_Waybills::calculate_waybill_totals($w, $items);
$charge_basis = $totals['charge_basis'];
$transport_total = $totals['transport_total'];
$misc_total = $totals['misc_total'];
$misc_items = $totals['misc_items'];
$sad500_total = $totals['sad500_total'];
$sadc_total = $totals['sadc_total'];
$intl_amount = $totals['intl_amount'];
$items_total = $totals['items_total'];
$invoice_total = $totals['final_total'];

// Extract description and miscellaneous items from serialized misc field
$description = '';
if (!empty($w->miscellaneous)) {
    $misc_data = maybe_unserialize($w->miscellaneous);
    if (is_array($misc_data)) {
        if (isset($misc_data['others']['waybill_description'])) {
            $description = (string) $misc_data['others']['waybill_description'];
        }
    }
}

// Render minimal HTML summary (can be printed to PDF via browser)
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Waybill <?= htmlspecialchars($w->waybill_no ?? '') ?> Summary</title>
  <style>
    body{font-family:Arial,sans-serif;font-size:12px;color:#111;margin:24px}
    h1{font-size:18px;margin:0 0 8px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #e5e7eb;padding:6px;text-align:left}
    th{background:#f8fafc}
    .meta{margin:4px 0 12px}
  </style>
  <script>window.print && setTimeout(()=>window.print(), 200);</script>
  </head>
<body>
  <h1>Waybill Summary</h1>
  <div class="meta">
    <strong>Waybill #:</strong> <?= htmlspecialchars($w->waybill_no ?? '') ?>
    &nbsp;&nbsp;|&nbsp;&nbsp; <strong>Status:</strong> <?= htmlspecialchars($w->status ?? 'pending') ?>
    &nbsp;&nbsp;|&nbsp;&nbsp; <strong>Route:</strong> <?= htmlspecialchars(($w->route_description ?? '')) ?>
    <?php if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()): ?>
      &nbsp;&nbsp;|&nbsp;&nbsp; <strong>Total:</strong> R<?= number_format($invoice_total, 2) ?>
    <?php endif; ?>
  </div>

  <?php if ($description !== ''): ?>
    <div class="meta"><strong>Description:</strong> <?= nl2br(htmlspecialchars($description)) ?></div>
  <?php endif; ?>

  <table>
    <tr>
      <th>Customer</th>
      <td><?= htmlspecialchars(trim(($w->customer_name ?? '') . ' ' . ($w->customer_surname ?? ''))) ?></td>
      <th>Company</th>
      <td><?= htmlspecialchars($w->company_name ?? '') ?></td>
    </tr>
    <tr>
      <th>Email</th>
      <td><?= htmlspecialchars($w->email_address ?? '') ?></td>
      <th>Cell</th>
      <td><?= htmlspecialchars($w->customer_cell ?? '') ?></td>
    </tr>
    <tr>
      <th>Origin</th>
      <td><?= htmlspecialchars($w->origin_country ?? '') ?></td>
      <th>Destination</th>
      <td><?= htmlspecialchars($w->destination_country ?? '') ?></td>
    </tr>
    <tr>
      <th>Dimensions (cm)</th>
      <td><?= htmlspecialchars(($w->item_length ?? 0) . ' × ' . ($w->item_width ?? 0) . ' × ' . ($w->item_height ?? 0)) ?></td>
      <th>Mass (kg)</th>
      <td><?= htmlspecialchars(number_format((float)($w->total_mass_kg ?? 0), 2)) ?></td>
    </tr>
  </table>

  <?php if ($delivery): ?>
    <h3>Delivery</h3>
    <table>
      <tr>
        <th>Reference</th>
        <td><?= htmlspecialchars($delivery->delivery_reference ?? '') ?></td>
        <th>Dispatch Date</th>
        <td><?= htmlspecialchars($delivery->dispatch_date ?? '') ?></td>
      </tr>
      <tr>
        <th>Truck</th>
        <td><?= htmlspecialchars($delivery->truck_number ?? '') ?></td>
        <th>Delivery Status</th>
        <td><?= htmlspecialchars($delivery->status ?? '') ?></td>
      </tr>
      <tr>
        <th>Origin</th>
        <td><?= htmlspecialchars($delivery->origin_country ?? '') ?></td>
        <th>Destination</th>
        <td><?= htmlspecialchars($delivery->destination_country ?? '') ?></td>
      </tr>
    </table>

    <?php if (!empty($delivery->driver_name) || !empty($delivery->driver_phone) || !empty($delivery->driver_email)): ?>
      <h3>Driver</h3>
      <table>
        <tr>
          <th>Name</th>
          <td><?= htmlspecialchars($delivery->driver_name ?? 'N/A') ?></td>
          <th>Phone</th>
          <td><?= htmlspecialchars($delivery->driver_phone ?? 'N/A') ?></td>
        </tr>
        <tr>
          <th>Email</th>
          <td colspan="3"><?= htmlspecialchars($delivery->driver_email ?? 'N/A') ?></td>
        </tr>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  <h3>Items</h3>
  <?php if (!empty($items)): ?>
    <table>
      <thead><tr>
        <th>Item</th>
        <th>Qty</th>
        <?php if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()): ?>
          <th>Unit Price</th>
          <th>Subtotal</th>
        <?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= htmlspecialchars($it['item_name'] ?? '') ?></td>
          <td><?= (int)($it['quantity'] ?? 0) ?></td>
          <?php if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()): ?>
            <td><?= number_format((float)($it['unit_price'] ?? 0), 2) ?></td>
            <td><?= number_format(((float)($it['unit_price'] ?? 0)) * ((int)($it['quantity'] ?? 0)), 2) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p style="color:#6b7280; margin:6px 0 14px;">No items captured for this waybill.</p>
  <?php endif; ?>

  <h3>Miscellaneous</h3>
  <?php if (!empty($misc_items)): ?>
    <table>
      <thead><tr>
        <th>Item</th>
        <th>Qty</th>
        <?php if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()): ?>
          <th>Price</th>
          <th>Subtotal</th>
        <?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($misc_items as $mi): ?>
        <tr>
          <td><?= htmlspecialchars($mi['misc_item'] ?? '') ?></td>
          <td><?= (int)($mi['misc_quantity'] ?? 0) ?></td>
          <?php if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()): ?>
            <td><?= number_format((float)($mi['misc_price'] ?? 0), 2) ?></td>
            <td><?= number_format(((float)($mi['misc_price'] ?? 0)) * ((int)($mi['misc_quantity'] ?? 0)), 2) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p style="color:#6b7280; margin:6px 0 14px;">No miscellaneous charges captured for this waybill.</p>
  <?php endif; ?>


  <?php if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()): ?>
    <h3>Charges Summary</h3>
    <table>
      <tbody>
        <tr>
          <th>Transport Charge (<?= htmlspecialchars(ucfirst($charge_basis)) ?>)</th>
          <td>R<?= number_format($transport_total, 2) ?></td>
        </tr>
        <tr>
          <th>Items Total</th>
          <td>R<?= number_format($items_total, 2) ?></td>
        </tr>
        <?php if ($misc_total > 0): ?>
          <tr>
            <th>Miscellaneous</th>
            <td>R<?= number_format($misc_total, 2) ?></td>
          </tr>
        <?php endif; ?>
        <?php if ($sadc_total > 0): ?>
          <tr>
            <th>SADC Certificate</th>
            <td>R<?= number_format($sadc_total, 2) ?></td>
          </tr>
        <?php endif; ?>
        <?php if ($sad500_total > 0): ?>
          <tr>
            <th>SAD500</th>
            <td>R<?= number_format($sad500_total, 2) ?></td>
          </tr>
        <?php endif; ?>
        <?php if ($intl_amount > 0): ?>
          <tr>
            <th>International Fees</th>
            <td>R<?= number_format($intl_amount, 2) ?></td>
          </tr>
        <?php endif; ?>
        <tr>
          <th style="font-size:14px;">Invoice Total</th>
          <td style="font-size:14px; font-weight:700;">R<?= number_format($invoice_total, 2) ?></td>
        </tr>
      </tbody>
    </table>
  <?php endif; ?>


  <p style="margin-top:12px;color:#555;">This is a customer summary, not a tax invoice.</p>
</body>
</html>

