<?php
/**
 * Bulk Customer Invoice PDF Generator
 * 
 * Generates a consolidated invoice for all waybills belonging to a customer in a specific delivery.
 * Aggregates items, misc items, SAD500, SADC, etc. across all waybills.
 */

// Bootstrap WordPress if accessed directly
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
    die('WordPress bootstrap failed for bulk customer invoice PDF.');
  }
}

// Support two modes:
// 1. Delivery-based: delivery_id + customer_id (requires nonce)
// 2. Selected waybills: selected_ids (waybill numbers) + customer_id (for checkbox selection)

$delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : 0;
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$selected_ids_string = isset($_GET['selected_ids']) ? $_GET['selected_ids'] : (isset($_POST['bulk_ids']) ? $_POST['bulk_ids'] : '');

// Validate nonce only for delivery-based requests
if ($delivery_id > 0) {
    $nonce = isset($_GET['delivery_nonce']) ? sanitize_text_field($_GET['delivery_nonce']) : '';
    if (! $nonce || ! wp_verify_nonce($nonce, 'delivery_truck_pdf')) {
        wp_die('Invalid delivery PDF request', 403);
    }
}

// Permission: staff (with price PDF policy) OR customer portal (own customer only; selected_ids mode)
if (!class_exists('KIT_User_Roles')) {
    wp_die('Access denied', 403);
}

$is_portal = is_user_logged_in()
    && KIT_User_Roles::is_portal_customer()
    && KIT_User_Roles::can_access_customer_portal()
    && KIT_User_Roles::get_portal_customer_id() > 0;

if ($is_portal) {
    if ((int) $customer_id !== KIT_User_Roles::get_portal_customer_id()) {
        wp_die('Access denied', 403);
    }
    if ($delivery_id > 0) {
        wp_die('Access denied', 403);
    }
} elseif (!(KIT_User_Roles::is_admin() || KIT_User_Roles::is_manager() || KIT_User_Roles::is_data_capturer())) {
    wp_die('Access denied', 403);
} elseif (!KIT_User_Roles::can_see_prices()) {
    wp_die('Access denied. Price viewing not permitted.', 403);
}

// Ensure required classes are available
if (! class_exists('KIT_Deliveries')) {
    require_once __DIR__ . '/includes/deliveries/deliveries-functions.php';
}
if (! class_exists('KIT_Waybills')) {
    require_once __DIR__ . '/includes/waybill/waybill-functions.php';
}
if (! class_exists('KIT_Customers')) {
    require_once __DIR__ . '/includes/customers/customers-functions.php';
}

// Get customer details (required for both modes)
if ($customer_id <= 0) {
    wp_die('Missing customer ID');
}

$customer = get_customer_details($customer_id);
if (! $customer) {
    wp_die('Customer not found');
}

// Get waybills based on mode
global $wpdb;
$waybills_table = $wpdb->prefix . 'kit_waybills';
$waybills_data = [];

if ($delivery_id > 0) {
    // Mode 1: Delivery-based (existing behavior)
    $delivery = KIT_Deliveries::get_delivery($delivery_id);
    if (! $delivery) {
        wp_die('Delivery not found');
    }
    
    $waybills_data = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $waybills_table 
        WHERE delivery_id = %d AND customer_id = %d
        ORDER BY waybill_no ASC
    ", $delivery_id, $customer_id), ARRAY_A);
} elseif (!empty($selected_ids_string)) {
    // Mode 2: Selected waybills (new behavior for checkbox selection)
    $selected_ids_array = array_map('trim', explode(',', $selected_ids_string));
    $selected_ids_array = array_filter($selected_ids_array, function($id) {
        return !empty($id);
    });
    $selected_ids_array = array_map('sanitize_text_field', $selected_ids_array);
    
    if (!empty($selected_ids_array)) {
        $placeholders = implode(',', array_fill(0, count($selected_ids_array), '%s'));
        $waybills_data = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $waybills_table 
            WHERE waybill_no IN ($placeholders) AND customer_id = %d
            ORDER BY waybill_no ASC
        ", array_merge($selected_ids_array, [$customer_id])), ARRAY_A);
    }
} else {
    wp_die('Missing delivery ID or selected waybill numbers');
}

if (empty($waybills_data)) {
    wp_die('No waybills found for this customer');
}

// Aggregate data across all waybills
$aggregated_items = []; // key: item_name, value: ['qty' => total, 'unit_price' => price, 'subtotal' => total]
$aggregated_misc = []; // key: misc_item, value: ['qty' => total, 'price' => price, 'subtotal' => total]
$waybill_transports = []; // Individual waybill transport charges: ['waybill_no' => '', 'charge_basis' => '', 'amount' => 0, 'rate' => 0, 'quantity' => 0, 'unit' => '']
$total_transport = 0.0;
$total_sad500 = 0.0;
$total_sadc = 0.0;
$total_intl = 0.0;
$total_misc = 0.0;
$waybill_numbers = [];
$total_mass = 0.0;
$total_volume = 0.0;
$charge_basis_counts = ['mass' => 0, 'volume' => 0];
$sad500_count = 0;
$sadc_count = 0;
$intl_count = 0;
$sad500_unit_price = 0.0;
$sadc_unit_price = 0.0;
$intl_unit_price = 0.0;

foreach ($waybills_data as $wb) {
    $waybill_no = intval($wb['waybill_no']);
    $waybill_numbers[] = $waybill_no;
    
    // Get full waybill with items
    $quotation = KIT_Waybills::getFullWaybillWithItems($waybill_no);
    if (!$quotation || !isset($quotation->waybill)) {
        continue;
    }
    
    $waybill = (array) $quotation->waybill;
    $waybill = array_map(function ($value) {
        return $value === null ? '' : $value;
    }, $waybill);
    $waybill = (object) $waybill;
    
    // Get stored rates and charge basis from waybill
    $mass_charge = floatval($waybill->mass_charge ?? 0);
    $volume_charge = floatval($waybill->volume_charge ?? 0);
    
    // Get stored basis and rates from miscellaneous data
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
    
    // Determine charge basis (same logic as pdf-generator.php)
    $charge_basis = '';
    if (!empty($waybill->charge_basis)) {
        $charge_basis = $waybill->charge_basis;
    } elseif (!empty($stored_basis)) {
        $charge_basis = $stored_basis;
    } else {
        $charge_basis = ($mass_charge > $volume_charge) ? 'mass' : 'volume';
    }
    $charge_basis = strtolower(trim((string) $charge_basis));
    if ($charge_basis === 'weight') {
        $charge_basis = 'mass';
    }
    
    // Get the charge amount and calculate rate
    $charge_amount = 0.0;
    $charge_quantity = 0.0;
    $charge_unit = '';
    $charge_rate = 0.0;
    
    if ($charge_basis === 'mass') {
        $charge_amount = $mass_charge;
        $charge_quantity = floatval($waybill->total_mass_kg ?? 0);
        $charge_unit = 'kg';
        
        // Calculate rate
        $charge_rate = $stored_mass_rate > 0 ? $stored_mass_rate : 0;
        if ($charge_rate <= 0 && !empty($waybill->miscellaneous)) {
            $md = maybe_unserialize($waybill->miscellaneous);
            if (is_array($md) && isset($md['others']['mass_rate'])) {
                $charge_rate = floatval($md['others']['mass_rate']);
            }
        }
        if ($charge_rate <= 0 && $charge_amount > 0 && $charge_quantity > 0) {
            $charge_rate = $charge_amount / $charge_quantity;
        }
        
        $total_transport += $charge_amount;
        $charge_basis_counts['mass']++;
        $total_mass += $charge_quantity;
    } else {
        $charge_amount = $volume_charge;
        $charge_quantity = floatval($waybill->total_volume ?? 0);
        if ($charge_quantity <= 0) {
            // Calculate from dimensions
            $length = floatval($waybill->item_length ?? 0);
            $width = floatval($waybill->item_width ?? 0);
            $height = floatval($waybill->item_height ?? 0);
            $charge_quantity = ($length * $width * $height) / 1000000; // Convert to m³
        }
        $charge_unit = 'm³';
        
        // Calculate rate
        $charge_rate = $stored_volume_rate > 0 ? $stored_volume_rate : 0;
        if ($charge_rate <= 0 && $charge_amount > 0 && $charge_quantity > 0) {
            $charge_rate = $charge_amount / $charge_quantity;
        }
        
        $total_transport += $charge_amount;
        $charge_basis_counts['volume']++;
        $total_volume += $charge_quantity;
    }
    
    // Store individual waybill transport charge
    $waybill_transports[] = [
        'waybill_no' => $waybill_no,
        'charge_basis' => $charge_basis,
        // Mel (2026-08-05): generic transport label only — no goods description.
        'charge_type' => 'Transport - ' . ucfirst($charge_basis) . ' Charge',
        'amount' => $charge_amount,
        'rate' => $charge_rate,
        'quantity' => $charge_quantity,
        'unit' => $charge_unit,
    ];
    
    // Aggregate waybill items (border clearing 10% applies only when VAT is included)
    if (!empty($quotation->items) && is_array($quotation->items)) {
        $waybill_vat_included = class_exists('KIT_Waybills')
            && (KIT_Waybills::normalize_flag_int($waybill->vat_include ?? 0) === 1);
        if ($waybill_vat_included) {
        foreach ($quotation->items as $item) {
            $item_name = $item['item_name'] ?? '';
            $qty = intval($item['quantity'] ?? 0);
            $unit_price = floatval($item['unit_price'] ?? 0);
            
            if (!empty($item_name)) {
                if (!isset($aggregated_items[$item_name])) {
                    $aggregated_items[$item_name] = [
                        'qty' => 0,
                        'unit_price' => $unit_price,
                        'subtotal' => 0.0
                    ];
                }
                $aggregated_items[$item_name]['qty'] += $qty;
                $aggregated_items[$item_name]['subtotal'] += ($qty * $unit_price);
            }
        }
        }
    }
    
    // Aggregate miscellaneous items
    if (!empty($waybill->miscellaneous)) {
        $misc_data = maybe_unserialize($waybill->miscellaneous);
        if (is_array($misc_data) && isset($misc_data['misc_items']) && is_array($misc_data['misc_items'])) {
            foreach ($misc_data['misc_items'] as $misc_item) {
                $misc_name = $misc_item['misc_item'] ?? '';
                $misc_qty = intval($misc_item['misc_quantity'] ?? 0);
                $misc_price = floatval($misc_item['misc_price'] ?? 0);
                
                if (!empty($misc_name)) {
                    if (!isset($aggregated_misc[$misc_name])) {
                        $aggregated_misc[$misc_name] = [
                            'qty' => 0,
                            'price' => $misc_price,
                            'subtotal' => 0.0
                        ];
                    }
                    $aggregated_misc[$misc_name]['qty'] += $misc_qty;
                    $aggregated_misc[$misc_name]['subtotal'] += ($misc_qty * $misc_price);
                }
            }
        }
        
        // Aggregate SAD500 - count waybills and get unit price
        if (!empty($waybill->include_sad500) && intval($waybill->include_sad500) === 1) {
            $sad500_amount = 0.0;
            if (isset($misc_data['others']['include_sad500'])) {
                $sad500_amount = KIT_Waybills::normalize_amount($misc_data['others']['include_sad500']);
            }
            if ($sad500_amount <= 0) {
                $sad500_amount = floatval(KIT_Waybills::sadc_certificate());
            }
            // Store unit price (use first non-zero value or default)
            if ($sad500_unit_price <= 0 && $sad500_amount > 0) {
                $sad500_unit_price = $sad500_amount;
            }
            $sad500_count++;
        }
        
        // Aggregate SADC - count waybills and get unit price
        if (!empty($waybill->include_sadc) && intval($waybill->include_sadc) === 1) {
            $sadc_amount = 0.0;
            if (isset($misc_data['others']['include_sadc'])) {
                $sadc_amount = KIT_Waybills::normalize_amount($misc_data['others']['include_sadc']);
            }
            if ($sadc_amount <= 0) {
                $sadc_amount = floatval(KIT_Waybills::sad());
            }
            // Store unit price (use first non-zero value or default)
            if ($sadc_unit_price <= 0 && $sadc_amount > 0) {
                $sadc_unit_price = $sadc_amount;
            }
            $sadc_count++;
        }
        
        // Aggregate international fees (customs clearing) - count waybills and get unit price
        if (class_exists('KIT_Waybills') && KIT_Waybills::normalize_flag_int($waybill->vat_include ?? 0) === 0) {
            $intl_amount = 0.0;
            if (isset($misc_data['others']['international_price_rands'])) {
                $intl_amount = floatval($misc_data['others']['international_price_rands']);
            }
            if ($intl_amount <= 0) {
                $intl_amount = floatval(KIT_Waybills::international_price_in_rands());
            }
            // Store unit price (use first non-zero value or default)
            if ($intl_unit_price <= 0 && $intl_amount > 0) {
                $intl_unit_price = $intl_amount;
            }
            $intl_count++;
        }
    }
}

// Calculate totals by multiplying unit price by count
// SAD500: unit_price * count
if ($sad500_count > 0) {
    if ($sad500_unit_price <= 0) {
        $sad500_unit_price = floatval(KIT_Waybills::sadc_certificate());
    }
    $total_sad500 = $sad500_unit_price * $sad500_count;
}

// SADC: unit_price * count
if ($sadc_count > 0) {
    if ($sadc_unit_price <= 0) {
        $sadc_unit_price = floatval(KIT_Waybills::sad());
    }
    $total_sadc = $sadc_unit_price * $sadc_count;
}

// Customs Clearing (International): unit_price * count
if ($intl_count > 0) {
    if ($intl_unit_price <= 0) {
        $intl_unit_price = floatval(KIT_Waybills::international_price_in_rands());
    }
    $total_intl = $intl_unit_price * $intl_count;
}

// Calculate final total
$items_total = 0.0;
foreach ($aggregated_items as $item) {
    $items_total += $item['subtotal'];
}

foreach ($aggregated_misc as $misc) {
    $total_misc += $misc['subtotal'];
}

// Calculate 10% fee for each border clearing item
$border_clearing_10_percent_total = 0.0;
foreach ($aggregated_items as $item_name => $item_data) {
    $ten_percent = $item_data['subtotal'] * 0.10;
    $aggregated_items[$item_name]['ten_percent'] = $ten_percent;
    $border_clearing_10_percent_total += $ten_percent;
}

// Final total: Transport + Misc + Processing + Customs + ONLY the 10% of border clearing (not the subtotals)
$final_total = $total_transport + $total_misc + $total_sad500 + $total_sadc + $total_intl + $border_clearing_10_percent_total;

// Determine primary charge basis
$primary_charge_basis = ($charge_basis_counts['mass'] >= $charge_basis_counts['volume']) ? 'mass' : 'volume';

// Company colors
$primary_color = '#2563eb';
$secondary_color = '#111827';
$accent_color = '#10b981';
$darkBadge = '#043c7d';
$lightBadge = '#e0e7ef';

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

// Company details — hardcoded 08600 identity merged with DB
$company = class_exists('KIT_Company') ? KIT_Company::get_details_array() : [];

// Get images
$imagePath = plugin_dir_path(__FILE__) . '/icons/pin.png';
$pin = '';
if (file_exists($imagePath)) {
    $imageData = file_get_contents($imagePath);
    if ($imageData !== false) {
        $pin = 'data:image/png;base64,' . base64_encode($imageData);
    }
}

$emailPath = plugin_dir_path(__FILE__) . '/icons/email.png';
$email = '';
if (file_exists($emailPath)) {
    $imageData = file_get_contents($emailPath);
    if ($imageData !== false) {
        $email = 'data:image/png;base64,' . base64_encode($imageData);
    }
}

$webPath = plugin_dir_path(__FILE__) . '/icons/web.png';
$web = '';
if (file_exists($webPath)) {
    $imageData = file_get_contents($webPath);
    if ($imageData !== false) {
        $web = 'data:image/png;base64,' . base64_encode($imageData);
    }
}

$contactPath = plugin_dir_path(__FILE__) . '/icons/contact.png';
$contact = '';
if (file_exists($contactPath)) {
    $imageData = file_get_contents($contactPath);
    if ($imageData !== false) {
        $contact = 'data:image/png;base64,' . base64_encode($imageData);
    }
}

// Terms & Conditions
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

// Create PDF content
ob_start();
?>

<style>
  :root {
    --primary: <?php echo $primary_color; ?>;
    --secondary: <?php echo $secondary_color; ?>;
    --accent: <?php echo $accent_color; ?>;
  }

  @page {
    margin: 8mm;
  }

  body {
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
    font-size: 11px;
    line-height: 1.35;
    color: #333;
  }

  h1, h2, h3, h4 {
    margin: 0;
    color: var(--primary);
    font-weight: 700;
  }

  h4 {
    font-size: 12px;
  }

  table {
    border-collapse: collapse;
    font-size: 10.5px;
  }

  th {
    font-size: 10px;
    font-weight: 700;
  }

  td {
    font-size: 10.5px;
  }

  td.cellStyle {
    text-align: left;
    padding: 5px 6px;
    border-bottom: 1px solid #e0e7ef;
  }

  .fstCol {
    width: 70px !important;
  }

  th.thr {
    font-size: 12px;
    padding: 8px 6px;
    width: 130px;
  }

  .pcolor {
    background-color: <?php echo $primary_color; ?>;
  }

  .pTextColor {
    color: <?php echo $primary_color; ?>;
  }

  .wText {
    color: #fff;
  }

  .scolor {
    background-color: <?php echo $secondary_color; ?>;
  }

  .rowTotal>td {
    color: #fff;
    font-weight: 700;
    font-size: 18px;
  }

  .rowTotal {
    page-break-inside: avoid;
  }

  .terms-conditions {
    font-size: 10px;
    margin: 0 0 6px 0;
    padding-left: 14px;
    list-style-type: disc;
    color: #444;
  }

  .terms-conditions li {
    margin: 2px 0 4px;
    line-height: 1.4;
  }

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
  }

  .banking-details table td {
    padding: 2px 4px !important;
  }

  .banking-details table td.dCells {
    padding: 2px 6px 2px 0 !important;
  }

</style>

<table width="100%" cellpadding="3" cellspacing="0" style="max-width:700px; margin:0 auto; font-family:Arial,sans-serif;font-size:12px;color:#333;">
  <tr>
    <td colspan="3">
      <!-- HEADER -->
      <table width="100%" cellpadding="3" cellspacing="0" style="margin-bottom:8px; border-bottom:1px solid #e9ecef;">
        <tr>
          <td style="width:40%;vertical-align:middle;">
            <img src="<?php echo plugin_dir_url(__FILE__); ?>/img/logo.jpeg" alt="Company Logo" style="display:block; width:100%; height:auto;">
          </td>
          <td style="width:60%;vertical-align:middle;text-align:right;">
            <div style="font-size:18px;font-weight:bold;color:<?= $pTextColor ?>;text-transform:uppercase; margin-bottom:4px;">Bulk Invoice: <?= count($waybill_numbers) ?> Waybill<?= count($waybill_numbers) > 1 ? 's' : '' ?></div>
            <div style="font-size:10px;color:#666; padding:6px;">
              <div><strong>Invoice #:</strong> #BULK-<?= date('Ymd-His') ?></div>
              <?php if ($delivery_id > 0 && isset($delivery)): ?>
              <div><strong>Date:</strong> <?= date('d/m/Y', strtotime($delivery->dispatch_date ?? 'now')) ?></div>
              <div><strong>Delivery Ref:</strong> <?= esc_html($delivery->delivery_reference ?? '') ?></div>
              <?php else: ?>
              <div><strong>Date:</strong> <?= date('d/m/Y') ?></div>
              <div><strong>Waybills:</strong> <?= count($waybills_data) ?></div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      </table>

      <!-- ADDRESS ROW -->
      <table width="100%" cellpadding="0" cellspacing="3" style="margin-bottom:12px;">
        <tr>
          <td style="width:50%; background:#f8f9fa; border:1px solid #e9ecef; border-radius:4px; padding:8px;">
            <div style="font-size:13px;font-weight:bold;color:<?= $pTextColor ?>;text-transform:uppercase; margin-bottom:4px;">From</div>
            <div style="font-size:10px; line-height:1.3;">
              <?= esc_html($company['company_name'] ?? '') ?><br>
              <?= nl2br(esc_html($company['company_address'] ?? '')) ?><br>
              <?= esc_html($company['company_phone'] ?? '') ?><br>
              <?= esc_html($company['company_email'] ?? '') ?><br>
              VAT: <?= esc_html($company['company_vat_number'] ?? '') ?>
            </div>
          </td>
          <td style="width:50%;background:#f8f9fa;border:1px solid #e9ecef;border-radius:4px;padding:8px;">
            <div style="font-size:13px;font-weight:bold;color:<?= $pTextColor ?>;text-transform:uppercase; margin-bottom:4px;">Bill To</div>
            <div style="font-size:10px; line-height:1.3;">
              <strong><?= esc_html($customer['company_name'] ?? '') ?></strong><br>
              <?= esc_html(trim(($customer['customer_name'] ?? '') . ' ' . ($customer['customer_surname'] ?? ''))) ?><br>
              <?= esc_html($customer['address'] ?? 'No Address') ?><br>
              <?= esc_html($customer['country_name'] ?? '') ?><br>
              Email: <?= esc_html($customer['email_address'] ?? 'customer@customer.com') ?><br>
              Cell: <?= esc_html($customer['cell'] ?? 'N/A') ?><br>
              City: <?= esc_html($customer['city_name'] ?? 'N/A') ?>
            </div>
          </td>
        </tr>
      </table>

      <!-- DESCRIPTION intentionally omitted (Mel, 2026-08-05): goods descriptions
           stay on the waybill, not the customer invoice. -->

      <!-- BULK INVOICE SUMMARY -->
      <table width="100%" cellpadding="5" cellspacing="0" style="margin-bottom:12px; border-collapse:collapse;">
        <tr>
          <td style="font-size:12px;font-weight:bold;color:<?= $pTextColor ?>;padding-bottom:4px;">Total Waybills</td>
          <td style="font-size:12px;font-weight:bold;color:<?= $pTextColor ?>;padding-bottom:4px;">Total Mass</td>
          <td style="font-size:12px;font-weight:bold;color:<?= $pTextColor ?>;padding-bottom:4px;">Total Volume</td>
        </tr>
        <tr style="font-size:11px; background:#f9fafb; border-bottom:1px solid #e9ecef;">
          <td style="padding:6px;font-weight:600;">
            <?= count($waybill_numbers) ?> waybill<?= count($waybill_numbers) > 1 ? 's' : '' ?>
          </td>
          <td style="padding:6px;font-weight:600;">
            <?= $total_mass > 0 ? number_format($total_mass, 2) . ' kg' : '-' ?>
          </td>
          <td style="padding:6px;font-weight:600;">
            <?= $total_volume > 0 ? number_format($total_volume, 3) . ' m³' : '-' ?>
          </td>
        </tr>
      </table>

      <!-- CHARGES BREAKDOWN - TABLE 1: Transport, Processing, Customs Clearing -->
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:0;">
        <tr>
          <td colspan="5" style="font-size:12px;font-weight:bold;color:<?= $pTextColor ?>;padding-bottom:4px;">Charges Breakdown</td>
        </tr>
        <tr class="scolor" style="color:#fff;font-size:11px;">
          <th align="left" class="thr" style="width:18%;">Description</th>
          <th class="thr" align="left" style="width:32%;">Item</th>
          <th class="thr" align="center" style="width:12%;">Qty</th>
          <th class="thr" align="right" style="width:19%;">Price (R)</th>
          <th class="thr" align="right" style="width:19%;">Subtotal (R)</th>
        </tr>
        
        <!-- Transport Charges - Individual waybills -->
        <?php foreach ($waybill_transports as $transport): ?>
          <tr style="font-size:12px; background:#f9fafb; border-bottom:1px solid #e0e7ef;">
            <td class="cellStyle fstCol">
              <span style="display:inline-block;background:<?= $lightBadge ?>; color:<?= $pTextColor ?>; font-size:11px;padding:2px 8px;border-radius:6px;font-weight:600;">Transport</span>
            </td>
            <td class="cellStyle">
              <span style="font-weight:600;">WB <?= esc_html($transport['waybill_no']) ?> — <?= esc_html($transport['charge_type']) ?></span>
            </td>
            <td class="cellStyle" style="text-align:center;">
              <?= number_format($transport['quantity'], $transport['unit'] == 'm³' ? 3 : 2) ?> <?= esc_html($transport['unit']) ?>
            </td>
            <td class="cellStyle" style="text-align:right;">
              R <?= number_format($transport['rate'], 2) ?>/<?= esc_html($transport['unit']) ?>
            </td>
            <td class="cellStyle" style="font-weight:700; text-align:right;">R <?= number_format($transport['amount'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        
        <!-- Miscellaneous Items -->
        <?php if (!empty($aggregated_misc)): ?>
          <?php foreach ($aggregated_misc as $misc_name => $misc_data): ?>
            <tr style="font-size:12px; background:#f9fafb; border-bottom:1px solid #e0e7ef;">
              <td class="cellStyle fstCol">
                <span style="display:inline-block;background:<?= $lightBadge ?>;color:#64748b;font-size:11px;padding:2px 8px;border-radius:6px;">Miscellaneous</span>
              </td>
              <td class="cellStyle"><?= htmlspecialchars($misc_name) ?></td>
              <td class="cellStyle" style="text-align:center;"><?= $misc_data['qty'] ?></td>
              <td class="cellStyle" style="text-align:right;"><?= number_format($misc_data['price'], 2) ?></td>
              <td class="cellStyle" style="text-align:right; font-weight: 700;">R <?= number_format($misc_data['subtotal'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- SAD500 (aggregated) -->
        <?php if ($total_sad500 > 0 && $sad500_count > 0): ?>
          <tr style="font-size:13px; background:#f9fafb; border-bottom:1px solid #e0e7ef;">
            <td class="cellStyle fstCol">
              <span style="display:inline-block;background:<?= $lightBadge ?>;color:#64748b;font-size:11px;padding:2px 8px;border-radius:6px;">Processing</span>
            </td>
            <td class="cellStyle">SAD500</td>
            <td class="cellStyle" style="text-align:center;"><?= $sad500_count ?></td>
            <td class="cellStyle" style="text-align:right;"><?= number_format($sad500_unit_price, 2) ?></td>
            <td class="cellStyle" style="text-align:right; font-weight: 700;">R <?= number_format($total_sad500, 2) ?></td>
          </tr>
        <?php endif; ?>
        
        <!-- SADC Certificate (aggregated) -->
        <?php if ($total_sadc > 0 && $sadc_count > 0): ?>
          <tr style="font-size:13px; background:#f9fafb; border-bottom:1px solid #e0e7ef;">
            <td class="cellStyle fstCol">
              <span style="display:inline-block;background:<?= $lightBadge ?>;color:#64748b;font-size:11px;padding:2px 8px;border-radius:6px;">Processing</span>
            </td>
            <td class="cellStyle">SADC Certificate</td>
            <td class="cellStyle" style="text-align:center;"><?= $sadc_count ?></td>
            <td class="cellStyle" style="text-align:right;"><?= number_format($sadc_unit_price, 2) ?></td>
            <td class="cellStyle" style="text-align:right; font-weight: 700;">R <?= number_format($total_sadc, 2) ?></td>
          </tr>
        <?php endif; ?>
        
        <!-- International Fees (Customs Clearing) -->
        <?php if ($total_intl > 0 && $intl_count > 0): ?>
          <tr style="font-size:13px; background:#f9fafb; border-bottom:1px solid #e0e7ef;">
            <td class="cellStyle fstCol">
              <span style="display:inline-block;background:<?= $lightBadge ?>;color:#64748b;font-size:11px;padding:2px 8px;border-radius:6px;">Customs Clearing</span>
            </td>
            <td class="cellStyle">Agent Clearing & Documentation</td>
            <td class="cellStyle" style="text-align:center;"><?= $intl_count ?></td>
            <td class="cellStyle" style="text-align:right;"><?= number_format($intl_unit_price, 2) ?></td>
            <td class="cellStyle" style="text-align:right; font-weight: 700;">R <?= number_format($total_intl, 2) ?></td>
          </tr>
        <?php endif; ?>
      </table>
      
      <!--
        Border Clearing fee — the 10% line only.

        Itemised parcel rows (item_name + declared value) used to render here.
        Those are the goods descriptions Mel asked off the invoice (2026-08-05).
        No amount changes: declared parcel values were never summed into
        $final_total — only their 10% fee was.
      -->
      <?php if ($border_clearing_10_percent_total > 0): ?>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:5px;">
        <tr style="font-size:12px; background:#f9fafb; border-bottom:1px solid #e0e7ef;">
          <td class="cellStyle" style="width:18%;">
            <span style="display:inline-block; background: <?= $darkBadge ?>; color:#fff; font-weight: 700; font-size:11px;padding:2px 8px;border-radius:6px;">Border Clearing</span>
          </td>
          <td class="cellStyle" style="width:32%;">Border Clearing Fee (10% of declared value)</td>
          <td class="cellStyle" style="text-align:center; width:12%;">1</td>
          <td class="cellStyle" style="text-align:right; width:19%;">10%</td>
          <td class="cellStyle" style="text-align:right; font-weight: 700; width:19%;">R <?= number_format($border_clearing_10_percent_total, 2) ?></td>
        </tr>
      </table>
      <?php endif; ?>
      
      <!-- TOTAL ROW -->
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:5px;">
        <tr class="rowTotal pcolor">
          <td class="cellStyle" style="font-size:18px; font-weight:700;">TOTAL</td>
          <td class="cellStyle" style="text-align:right;">R <?= number_format($final_total, 2) ?></td>
        </tr>
      </table>

      <!-- BANK ACCOUNT & PAYMENT TERMS -->
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:15px;">
        <tr>
          <!-- Left: Bank Account -->
          <td style="width:50%; padding:0; padding-right:10px; vertical-align:top;">
            <div style="background:#f8fafc; border-radius:8px; padding:18px 20px 14px 20px; border:1.5px solid #e0e7ef;">
              <div style="font-size:11px; font-weight:700; color:<?= $pTextColor ?>; margin-bottom:8px;">Bank Account</div>
              <div style="font-size:10px; color:#444; line-height:1.6;">
                <?php if (!empty($company['bank_name'])): ?>
                  <?= esc_html($company['bank_name']) ?><br>
                <?php endif; ?>
                <?php if (!empty($company['account_holder'])): ?>
                  <?= esc_html($company['account_holder']) ?><br>
                <?php endif; ?>
                <?php if (!empty($company['account_number'])): ?>
                  Account #: <?= esc_html($company['account_number']) ?><br>
                <?php endif; ?>
                <?php if (!empty($company['branch_code'])): ?>
                  Branch Code: <?= esc_html($company['branch_code']) ?>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <!-- Right: Payment Terms & Reference -->
          <td style="width:50%; padding:0; padding-left:10px; vertical-align:top;">
            <div style="background:#f8fafc; border-radius:8px; padding:18px 20px 14px 20px; border:1.5px solid #e0e7ef;">
              <!-- Payment Terms -->
              <div style="margin-bottom:12px;">
                <div style="font-size:11px; font-weight:700; color:<?= $pTextColor ?>; margin-bottom:4px;">Payment Terms</div>
                <div style="font-size:10px; color:#444;">Payment due within 30 days of invoice date.</div>
              </div>
              
              <!-- Reference -->
              <div>
                <div style="font-size:11px; font-weight:700; color:<?= $pTextColor ?>; margin-bottom:4px;">Reference</div>
                <div style="font-size:10px; color:#444;">Invoice #BULK-<?= date('Ymd-His') ?></div>
              </div>
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>


<?php
$html = ob_get_clean();

// Generate PDF
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('isFontSubsettingEnabled', true);
$options->set('defaultFont', 'Arial');
$options->set('isPhpEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$customer_name = sanitize_file_name(trim(($customer['customer_name'] ?? '') . '-' . ($customer['customer_surname'] ?? '')));
if ($delivery_id > 0 && isset($delivery)) {
    $filename = sprintf('bulk-invoice-%s-delivery-%s.pdf', $customer_name, $delivery->delivery_reference ?: $delivery_id);
} else {
    $filename = sprintf('bulk-invoice-%s-%s.pdf', $customer_name, date('Ymd-His'));
}

// Set security headers
if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . esc_attr($filename) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');
    if (is_ssl() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

$dompdf->stream($filename, ['Attachment' => false]);
exit;

