<?php

/**
 * Delivery Truck PDF Generator
 *
 * Generates a consolidated PDF summarising a delivery and its associated waybills.
 */

// Bootstrap WordPress when accessed directly
if (! defined('ABSPATH')) {
    $possible_paths = [
        dirname(__FILE__, 4) . '/wp-load.php',
        dirname(__FILE__, 3) . '/wp-load.php',
        dirname(__FILE__, 2) . '/wp-load.php',
    ];

    $wp_loaded = false;
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $wp_loaded = true;
            break;
        }
    }

    if (! $wp_loaded) {
        die('WordPress bootstrap failed for delivery truck PDF.');
    }
}

// Validate nonce & permissions
$nonce = isset($_GET['delivery_nonce']) ? sanitize_text_field($_GET['delivery_nonce']) : '';
if (! $nonce || ! wp_verify_nonce($nonce, 'delivery_truck_pdf')) {
    wp_die('Invalid delivery PDF request', 403);
}

// Permission: Managers, Data Capturers, and Admins
if (! class_exists('KIT_User_Roles')) {
    wp_die('Access denied', 403);
}
if (! (KIT_User_Roles::is_admin() || KIT_User_Roles::is_manager() || KIT_User_Roles::is_data_capturer())) {
    wp_die('Access denied', 403);
}

// Check if user can see prices (for conditional display)
$can_see_prices = class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices();

$delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : 0;
if ($delivery_id <= 0) {
    wp_die('Missing delivery ID');
}

// Ensure required classes are available
if (! class_exists('KIT_Deliveries')) {
    require_once __DIR__ . '/includes/deliveries/deliveries-functions.php';
}
if (! class_exists('KIT_Waybills')) {
    require_once __DIR__ . '/includes/waybill/waybill-functions.php';
}
if (! class_exists('KIT_Routes')) {
    require_once __DIR__ . '/includes/routes/routes-functions.php';
}

$delivery = KIT_Deliveries::get_delivery($delivery_id);
if (! $delivery) {
    wp_die('Delivery not found');
}

$waybills = KIT_Waybills::truckWaybills($delivery_id);

// Get destination city name
$destination_city_name = '';
if (isset($delivery->destination_city_id) && intval($delivery->destination_city_id) > 0) {
    $destination_city_name = KIT_Routes::get_city_name_by_id(intval($delivery->destination_city_id)) ?: '';
}

// Enrich waybill data with description and totals
$total_amount = 0.0;
$waybill_rows = [];
foreach ($waybills as $row) {
    // Get waybill description - priority: 1) Direct 'description' column, 2) miscellaneous['others']['waybill_description']
    $waybill_description = '';
    if (!empty($row['description'])) {
        $waybill_description = trim($row['description']);
    } elseif (!empty($row['miscellaneous'])) {
        $misc_data = maybe_unserialize($row['miscellaneous']);
        if (is_array($misc_data) && isset($misc_data['others']['waybill_description'])) {
            $waybill_description = trim($misc_data['others']['waybill_description']);
        }
    }

    $mass   = isset($row['total_mass_kg']) ? floatval($row['total_mass_kg']) : 0.0;
    $volume = isset($row['total_volume']) ? floatval($row['total_volume']) : 0.0;
    $amount = isset($row['product_invoice_amount']) ? floatval($row['product_invoice_amount']) : 0.0;

    $miscellaneous_total = 0.0;
    if (! empty($row['miscellaneous'])) {
        $misc_data = maybe_unserialize($row['miscellaneous']);
        if (is_array($misc_data)) {
            if (isset($misc_data['misc_total'])) {
                $miscellaneous_total = floatval($misc_data['misc_total']);
            } elseif (isset($misc_data['misc_items']) && is_array($misc_data['misc_items'])) {
                foreach ($misc_data['misc_items'] as $item) {
                    $price = isset($item['misc_price']) ? floatval($item['misc_price']) : 0.0;
                    $qty   = isset($item['misc_quantity']) ? intval($item['misc_quantity']) : 0;
                    $miscellaneous_total += $price * $qty;
                }
            }
        }
    }

    $total_amount += ($amount + $miscellaneous_total);

    // Get city name for grouping
    $city_name = '';
    if (!empty($row['city'])) {
        $city_name = trim($row['city']);
    } elseif (!empty($row['city_id'])) {
        $city_name = KIT_Routes::get_city_name_by_id(intval($row['city_id'])) ?: '';
    }
    if (empty($city_name)) {
        $city_name = 'Unassigned City';
    }

    $waybill_rows[] = [
        'number'     => $row['waybill_no'] ?? '',
        'customer'   => trim(($row['customer_name'] ?? '') . ' ' . ($row['customer_surname'] ?? '')),
        'description' => $waybill_description ?: 'No description',
        'mass'       => $mass,
        'volume'     => $volume,
        'amount'     => $amount + $miscellaneous_total,
        'city'       => $city_name,
    ];
}

// Group waybills by city, then sort each city by customer name
$waybills_by_city = [];
foreach ($waybill_rows as $waybill) {
    $city = $waybill['city'];
    if (!isset($waybills_by_city[$city])) {
        $waybills_by_city[$city] = [];
    }
    $waybills_by_city[$city][] = $waybill;
}

// Sort cities alphabetically; waybills by customer name within each city
ksort($waybills_by_city);
foreach ($waybills_by_city as &$city_waybills) {
    usort($city_waybills, static function ($a, $b) {
        $cmp = strcasecmp($a['customer'], $b['customer']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcasecmp((string) $a['number'], (string) $b['number']);
    });
}
unset($city_waybills);

$totals = [
    'waybills' => KIT_Waybills::calculate_total_waybills($delivery_id),
    'mass'     => KIT_Waybills::calculate_total_mass($delivery_id),
    'volume'   => KIT_Waybills::calculate_total_volume($delivery_id),
    'amount'   => $total_amount,
];

// Company colours (with safe fallbacks)
$primary_color   = '#2563eb';
$secondary_color = '#111827';
$accent_color    = '#10b981';

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

// Prepare HTML
ob_start();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Delivery <?= esc_html($delivery->delivery_reference ?? ('#' . $delivery_id)); ?> Summary</title>
  <style>
    @page {
      margin-bottom: 25mm;
    }
    
    :root {
      --primary: <?= esc_html($primary_color); ?>;
      --secondary: <?= esc_html($secondary_color); ?>;
      --accent: <?= esc_html($accent_color); ?>;
    }

    body {
      font-family: Arial, sans-serif;
      font-size: 11px;
      color: #1f2937;
      margin: 0;
      padding: 0;
    }

    h1, h2, h3 {
      margin: 0;
      color: var(--primary);
    }

    table {
      border-collapse: collapse;
      width: 100%;
      margin-bottom: 14px;
    }

    th, td {
      border: 1px solid #e5e7eb;
      padding: 6px 8px;
      text-align: left;
    }

    th {
      background: #f3f4f6;
      font-weight: 600;
      color: #111827;
      font-size: 10px;
      letter-spacing: 0.2px;
    }

    .meta-table th {
      width: 24%;
    }

    .summary {
      display: flex;
      gap: 10px;
      margin: 12px 0 16px;
    }

    .summary-card {
      flex: 1;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      padding: 10px;
      background: #f9fafb;
    }

    .summary-card h3 {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      color: var(--secondary);
      margin-bottom: 6px;
    }

    .summary-card .value {
      font-size: 16px;
      font-weight: 700;
      color: var(--primary);
    }

    .text-muted {
      color: #6b7280;
      font-size: 10px;
    }

    h3 {
      margin-top: 16px;
      margin-bottom: 8px;
      padding-bottom: 4px;
      border-bottom: 2px solid var(--primary);
    }

    tfoot td {
      border-top: 2px solid #d1d5db;
      font-weight: 600;
    }

    thead {
      display: table-header-group;
    }

    tfoot {
      display: table-row-group;
    }

    tr {
      page-break-inside: avoid;
    }

    .city-section {
      page-break-inside: avoid;
      margin-bottom: 24px;
    }

    .city-page-break {
      page-break-before: always;
    }
  </style>
</head>
<body>
  <h1>Delivery Summary</h1>
  <p class="text-muted">Generated <?= esc_html(date_i18n('Y-m-d H:i')); ?></p>

  <table class="meta-table">
    <tr>
      <th>Delivery Reference</th>
      <td><?= esc_html($delivery->delivery_reference ?? ''); ?></td>
      <th>Dispatch Date</th>
      <td><?= esc_html($delivery->dispatch_date ? date('Y-m-d', strtotime($delivery->dispatch_date)) : ''); ?></td>
    </tr>
    <tr>
      <th>Route</th>
      <td><?= esc_html($delivery->direction_description ?? ''); ?></td>
      <th>Status</th>
      <td><?= esc_html(ucfirst(str_replace('_', ' ', $delivery->status ?? ''))); ?></td>
    </tr>
    <tr>
      <th>Origin</th>
      <td><?= esc_html($delivery->origin_country ?? ''); ?></td>
      <th>Destination</th>
      <td><?= esc_html($delivery->destination_country ?? ''); ?></td>
    </tr>
    <tr>
      <th>Driver</th>
      <td><?= esc_html($delivery->driver_name ?? ''); ?></td>
      <th>Driver Contact</th>
      <td><?= esc_html($delivery->driver_phone ?? ''); ?></td>
    </tr>
  </table>

  <div class="summary">
    <table style="width:100%; border-collapse:collapse; font-size:12px;">
      <tr>
        <td style="padding:8px 12px; border:1px solid #d1d5db; width:<?= $can_see_prices ? '25%' : '33%'; ?>;">
          <div style="font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:var(--secondary);">Total Waybills</div>
          <div style="font-size:16px; font-weight:700; color:var(--primary); margin-top:4px;">
            <?= number_format_i18n($totals['waybills']); ?>
          </div>
        </td>
        <td style="padding:8px 12px; border:1px solid #d1d5db; width:<?= $can_see_prices ? '25%' : '33%'; ?>;">
          <div style="font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:var(--secondary);">Total Mass (kg)</div>
          <div style="font-size:16px; font-weight:700; color:var(--primary); margin-top:4px;">
            <?= number_format_i18n($totals['mass'], 1); ?>
          </div>
        </td>
        <td style="padding:8px 12px; border:1px solid #d1d5db; width:<?= $can_see_prices ? '25%' : '33%'; ?>;">
          <div style="font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:var(--secondary);">Total Volume (m³)</div>
          <div style="font-size:16px; font-weight:700; color:var(--primary); margin-top:4px;">
            <?= number_format_i18n($totals['volume'], 2); ?>
          </div>
        </td>
        <?php if ($can_see_prices): ?>
        <td style="padding:8px 12px; border:1px solid #d1d5db; width:25%;">
          <div style="font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:var(--secondary);">Invoice Total (ZAR)</div>
          <div style="font-size:16px; font-weight:700; color:var(--primary); margin-top:4px;">
            <?= number_format_i18n($totals['amount'], 2); ?>
          </div>
        </td>
        <?php endif; ?>
      </tr>
    </table>
  </div>


  <h2>Waybills on Truck</h2>
  <?php if (! empty($waybills_by_city)) : ?>
    <?php
      $city_index = 0;
      foreach ($waybills_by_city as $city_name => $city_waybills) :
      $city_index++;
      // Calculate totals for this city
      $city_totals = [
        'waybills' => count($city_waybills),
        'mass'     => array_sum(array_column($city_waybills, 'mass')),
        'volume'   => array_sum(array_column($city_waybills, 'volume')),
        'amount'   => array_sum(array_column($city_waybills, 'amount')),
      ];
    ?>
      <div class="city-section <?= $city_index > 1 ? 'city-page-break' : ''; ?>">
        <h3 style="font-size: 13px; margin-bottom: 8px; color: var(--primary); font-weight: 600;">
          <?= esc_html($city_name); ?>
          <span style="font-size: 11px; font-weight: normal; color: #6b7280;">
            (<?= number_format_i18n($city_totals['waybills']); ?> waybill<?= $city_totals['waybills'] != 1 ? 's' : ''; ?>)
          </span>
        </h3>

        <table>
          <thead>
            <tr>
              <th style="width: <?= $can_see_prices ? '12%' : '16%'; ?>;">Waybill #</th>
              <th>Customer</th>
              <th style="width: <?= $can_see_prices ? '35%' : '45%'; ?>;">Description</th>
              <th style="width: <?= $can_see_prices ? '12%' : '16%'; ?>; text-align:right;">Mass (kg)</th>
              <th style="width: <?= $can_see_prices ? '12%' : '16%'; ?>; text-align:right;">Volume (m³)</th>
              <?php if ($can_see_prices): ?>
              <th style="width: 14%; text-align:right;">Amount (ZAR)</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($city_waybills as $item) : ?>
            <tr>
              <td><?= esc_html($item['number']); ?></td>
              <td><?= esc_html($item['customer']); ?></td>
              <td><?= esc_html($item['description']); ?></td>
              <td style="text-align:right;"><?= number_format_i18n($item['mass'], 1); ?></td>
              <td style="text-align:right;"><?= number_format_i18n($item['volume'], 2); ?></td>
              <?php if ($can_see_prices): ?>
              <td style="text-align:right;"><?= number_format_i18n($item['amount'], 2); ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background: #f9fafb; font-weight: 600;">
              <td colspan="3" style="text-align: right; padding-right: 12px;">City Total:</td>
              <td style="text-align:right;"><?= number_format_i18n($city_totals['mass'], 1); ?></td>
              <td style="text-align:right;"><?= number_format_i18n($city_totals['volume'], 2); ?></td>
              <?php if ($can_see_prices): ?>
              <td style="text-align:right;"><?= number_format_i18n($city_totals['amount'], 2); ?></td>
              <?php endif; ?>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endforeach; ?>
  <?php else : ?>
    <p class="text-muted">No waybills have been assigned to this delivery.</p>
  <?php endif; ?>
  
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
</body>
</html>
<?php
$html = ob_get_clean();

// Render PDF via Dompdf
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('isFontSubsettingEnabled', true);
$options->set('defaultFont', 'Arial');
$options->set('isPhpEnabled', true); // Enable PHP for footer scripts

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = sprintf('delivery-%s.pdf', $delivery->delivery_reference ?: $delivery_id);

// Set security headers before streaming PDF
if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . esc_attr($filename) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');
    // If site is HTTPS, add additional security headers
    if (is_ssl() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

$dompdf->stream($filename, ['Attachment' => false]);
exit;

