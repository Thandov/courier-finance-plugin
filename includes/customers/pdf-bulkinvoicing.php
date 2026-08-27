<?php
// Bootstrap WordPress if accessed directly (robust multi-path search similar to pdf-generator.php)
if (! defined('ABSPATH')) {
    // pdf-bulkinvoicing.php is in includes/customers/, so we need to try several levels up to reach WordPress root
    $possible_paths = [
        dirname(__FILE__, 6) . '/wp-load.php', // .../wp-content/plugins/courier-finance-plugin/includes/customers -> WP root
        dirname(__FILE__, 5) . '/wp-load.php', // fallback if structure differs slightly
        dirname(__FILE__, 4) . '/wp-load.php',
    ];

    $wp_load_found = false;

    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $old_error_reporting = error_reporting();
            error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);
            require_once $path;
            error_reporting($old_error_reporting);
            $wp_load_found = true;
            break;
        }
    }

    if (! $wp_load_found) {
        die('WordPress not found. Please ensure the plugin is installed in the correct directory.');
    }
}

// Load DOMPDF setup (from plugin root /vendor directory)
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Ensure user is logged in
if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
    wp_die('You must be logged in to access this PDF.', 403);
}

use Dompdf\Dompdf;
use Dompdf\Options;

// Load waybill helpers if available (for SADC/SAD500 calculations)
if (! class_exists('KIT_Waybills')) {
    $kit_waybills_file = dirname(__DIR__) . '/waybill/waybill-functions.php';
    if (file_exists($kit_waybills_file)) {
        require_once $kit_waybills_file;
    }
}

// Load user roles class if not already loaded
if (! class_exists('KIT_User_Roles')) {
    $user_roles_file = dirname(__DIR__) . '/user-roles.php';
    if (file_exists($user_roles_file)) {
        require_once $user_roles_file;
    } else {
        // Try alternative path
        $alt_user_roles_file = dirname(__DIR__, 2) . '/includes/user-roles.php';
        if (file_exists($alt_user_roles_file)) {
            require_once $alt_user_roles_file;
        }
    }
}

// Permission: Managers, Data Capturers, and Admins
if (! class_exists('KIT_User_Roles')) {
    wp_die('Access denied: User roles class not found', 403);
}
if (! (KIT_User_Roles::is_admin() || KIT_User_Roles::is_manager() || KIT_User_Roles::is_data_capturer())) {
    wp_die('Access denied', 403);
}

// Check if user can see prices (for conditional display)
$can_see_prices = class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices();

// Clean any previous output
if (ob_get_level()) {
    ob_end_clean();
}

// Color scheme (matching pdf-generator.php)
$primary_color = '#2563eb';
$secondary_color = '#111827';
$accent_color   = '#10b981';
$darkBadge      = '#043c7d';
$lightBadge     = '#e0e7ef';

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
$pTextColor   = $primary_color;
$stroke_color = $primary_color;

if (isset($_GET['selected_ids']) || isset($_POST['bulk_ids'])) {
    // Support both GET (from URL) and POST (from form submission)
    $selected_ids_string = isset($_GET['selected_ids']) ? $_GET['selected_ids'] : (isset($_POST['bulk_ids']) ? $_POST['bulk_ids'] : '');
    
    // Clean and sanitize waybill numbers (they are strings, not integers)
    $selected_ids_array = array_map('trim', explode(',', $selected_ids_string));
    $selected_ids_array = array_filter($selected_ids_array, function($id) {
        return !empty($id);
    });
    $selected_ids_array = array_map('sanitize_text_field', $selected_ids_array);

    if (!empty($selected_ids_array)) {
        global $wpdb;

        // Get company details from database table (same as pdf-generator.php)
        $company = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}kit_company_details LIMIT 1", ARRAY_A);

        // Extract company details with fallbacks
        $company_name       = $company['company_name'] ?? '08600 Africa';
        $company_address    = $company['company_address'] ?? '';
        $company_email      = $company['company_email'] ?? 'info@08600africa.com';
        $company_phone      = $company['company_phone'] ?? '+27 82 777 0226';
        $company_vat_number = $company['company_vat_number'] ?? '';

        // Get VAT percentage from company details
        $vat_percentage = isset($company['vat_percentage']) ? floatval($company['vat_percentage']) : 15;

        // Fetch waybill details
        $waybills_table            = $wpdb->prefix . 'kit_waybills';
        $customers_table           = $wpdb->prefix . 'kit_customers';
        $deliveries_table          = $wpdb->prefix . 'kit_deliveries';
        $shipping_directions_table = $wpdb->prefix . 'kit_shipping_directions';
        $cities_table              = $wpdb->prefix . 'kit_operating_cities';
        $countries_table           = $wpdb->prefix . 'kit_operating_countries';

        // Query by waybill_no (waybill numbers) - checkboxes use waybill_no, not database IDs
        // Use %s for string placeholders since waybill_no is a string field
        $placeholders = implode(',', array_fill(0, count($selected_ids_array), '%s'));
        $query        = $wpdb->prepare("
            SELECT 
                w.id,
                w.waybill_no,
                w.customer_id,
                w.product_invoice_amount,
                w.miscellaneous,
                w.created_at,
                w.item_length,
                w.item_width,
                w.item_height,
                w.total_mass_kg,
                w.total_volume,
                w.mass_charge,
                w.volume_charge,
                w.charge_basis,
                w.include_sad500,
                w.include_sadc,
                w.vat_include,
                c.name AS customer_name,
                c.surname AS customer_surname,
                c.email_address AS customer_email,
                c.cell AS customer_phone,
                c.address AS customer_address,
                COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) AS company_name,
                c.cust_id AS cust_join_cust_id,
                c.id AS cust_join_row_id,
                c2.cust_id AS id_join_cust_id,
                c2.id AS id_join_row_id,
                sd.destination_country_id,
                sd.origin_country_id,
                dest_country.country_name AS destination_country,
                orig_country.country_name AS origin_country,
                city.city_name AS destination_city
            FROM $waybills_table w
            LEFT JOIN $customers_table c ON w.customer_id = c.cust_id
            LEFT JOIN {$wpdb->prefix}kit_company_customers co ON w.company_id = co.company_id
            LEFT JOIN {$wpdb->prefix}kit_company_customers cust_co ON c.company_id = cust_co.company_id
            LEFT JOIN $customers_table c2 ON w.customer_id = c2.id
            LEFT JOIN $deliveries_table d ON w.delivery_id = d.id
            LEFT JOIN $shipping_directions_table sd ON d.direction_id = sd.id
            LEFT JOIN $cities_table city ON w.city_id = city.id
            LEFT JOIN $countries_table dest_country ON sd.destination_country_id = dest_country.id
            LEFT JOIN $countries_table orig_country ON sd.origin_country_id = orig_country.id
            WHERE w.waybill_no IN ($placeholders)
            ORDER BY city.city_name ASC, w.waybill_no ASC
        ", $selected_ids_array);

        $waybills = $wpdb->get_results($query);

        if (!empty($waybills)) {
            // Bulk invoice must represent one customer only. Resolve each waybill's customer_id the same way
            // as the manage list / customer lookup: FK may be kit_customers.cust_id OR kit_customers.id (primary key).
            $resolve_canonical_cust_id = static function ($wb_row): int {
                $raw = isset($wb_row->customer_id) ? intval($wb_row->customer_id) : 0;
                if ($raw <= 0) {
                    return 0;
                }
                $c_match  = isset($wb_row->cust_join_row_id) && $wb_row->cust_join_row_id !== null && intval($wb_row->cust_join_row_id) > 0;
                $c2_match = isset($wb_row->id_join_row_id) && $wb_row->id_join_row_id !== null && intval($wb_row->id_join_row_id) > 0;
                $c_row_id  = $c_match ? intval($wb_row->cust_join_row_id) : 0;
                $c2_row_id = $c2_match ? intval($wb_row->id_join_row_id) : 0;
                if ($c_match && $c2_match && $c_row_id !== $c2_row_id) {
                    // Same as manage-waybill customer fallback: prefer cust_id match over id match (admin-pages ORDER BY).
                    return intval($wb_row->cust_join_cust_id);
                }
                if ($c_match) {
                    return intval($wb_row->cust_join_cust_id);
                }
                if ($c2_match) {
                    return intval($wb_row->id_join_cust_id);
                }

                return $raw;
            };

            $customer_ids = [];
            foreach ($waybills as $wb_row) {
                $cid = $resolve_canonical_cust_id($wb_row);
                if ($cid > 0) {
                    $customer_ids[$cid] = true;
                }
            }

            $try_merge_same_billing_identity = static function (array $cust_ids) use ($wpdb, $customers_table): ?int {
                $cust_ids = array_values(array_unique(array_filter(array_map('intval', $cust_ids), static function ($v) {
                    return $v > 0;
                })));
                if (count($cust_ids) <= 1) {
                    return $cust_ids[0] ?? null;
                }
                $fingerprint = static function (?array $row): ?string {
                    if (empty($row) || !is_array($row)) {
                        return null;
                    }
                    $email = strtolower(trim((string)($row['email_address'] ?? '')));
                    $co    = strtolower(trim((string)($row['company_name'] ?? '')));
                    $cell  = preg_replace('/\D+/', '', (string)($row['cell'] ?? ''));
                    $first = strtolower(trim((string)($row['name'] ?? '')));
                    $last  = strtolower(trim((string)($row['surname'] ?? '')));
                    if (in_array($co, ['', 'individual', '1ndividual', 'n/a', 'na', 'none', 'private'], true)) {
                        $co = '';
                    }
                    $parts = [];
                    if ($email !== '') {
                        $parts[] = 'e:' . $email;
                    }
                    if ($co !== '') {
                        $parts[] = 'c:' . $co;
                    }
                    if ($cell !== '') {
                        $parts[] = 'p:' . $cell;
                    }
                    $name_key = $first . '|' . $last;
                    if ($name_key !== '|') {
                        $parts[] = 'n:' . $name_key;
                    }
                    if (empty($parts)) {
                        return null;
                    }

                    return implode("\x1e", $parts);
                };

                $prints = [];
                foreach ($cust_ids as $cid) {
                    $row = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT email_address, company_name, cell, name, surname FROM $customers_table WHERE cust_id = %d LIMIT 1",
                            $cid
                        ),
                        ARRAY_A
                    );
                    $fp = $fingerprint($row);
                    if ($fp === null) {
                        return null;
                    }
                    $prints[] = $fp;
                }

                if (count(array_unique($prints)) !== 1) {
                    return null;
                }

                sort($cust_ids);

                return (int) $cust_ids[0];
            };

            $canonical_cust_ids          = array_keys($customer_ids);
            $invoice_cust_id             = 0;
            $bulk_invoice_multi_customer = false;
            $is_wp_admin_bulk            = (class_exists('KIT_User_Roles') && KIT_User_Roles::is_admin())
                || (function_exists('is_super_admin') && is_super_admin());

            if (count($canonical_cust_ids) > 1) {
                $merged = $try_merge_same_billing_identity($canonical_cust_ids);
                if ($merged !== null && $merged > 0) {
                    $invoice_cust_id = $merged;
                } elseif ($is_wp_admin_bulk) {
                    $bulk_invoice_multi_customer = true;
                    $invoice_cust_id             = 0;
                } else {
                    wp_die(
                        'Selected waybills belong to different customers. Please select waybills for one customer only.',
                        'Mixed Customers Not Allowed',
                        ['response' => 400]
                    );
                }
            } elseif (count($canonical_cust_ids) === 1) {
                $invoice_cust_id = (int) $canonical_cust_ids[0];
            }

            // Aggregate SADC across all selected waybills so that e.g. 3 waybills with SADC @ R350 show 3 × 350
            $sadc_count      = 0;
            $sadc_unit_price = 0.0;
            $total_sadc      = 0.0;

            if (class_exists('KIT_Waybills')) {
                foreach ($waybills as $wb) {
                    $misc_data = [];
                    if (!empty($wb->miscellaneous)) {
                        $tmp = maybe_unserialize($wb->miscellaneous);
                        if (is_array($tmp)) {
                            $misc_data = $tmp;
                        }
                    }

                    if (!empty($wb->include_sadc) && intval($wb->include_sadc) === 1) {
                        $sadc_amount = 0.0;
                        if (isset($misc_data['others']['include_sadc'])) {
                            $sadc_amount = KIT_Waybills::normalize_amount($misc_data['others']['include_sadc']);
                        }
                        if ($sadc_amount <= 0 && method_exists('KIT_Waybills', 'sad')) {
                            $sadc_amount = floatval(KIT_Waybills::sad());
                        }

                        if ($sadc_unit_price <= 0 && $sadc_amount > 0) {
                            $sadc_unit_price = $sadc_amount;
                        }

                        $sadc_count++;
                    }
                }

                if ($sadc_count > 0) {
                    if ($sadc_unit_price <= 0 && method_exists('KIT_Waybills', 'sad')) {
                        $sadc_unit_price = floatval(KIT_Waybills::sad());
                    }
                    $total_sadc = $sadc_unit_price * $sadc_count;
                }
            }

            // Get customer details (unified cust_id after duplicate merge, if applicable)
            $first_waybill = $waybills[0];
            $customer_id   = $invoice_cust_id;
            if ($customer_id <= 0) {
                $customer_id = $resolve_canonical_cust_id($first_waybill);
            }
            if ($customer_id <= 0) {
                $customer_id = isset($first_waybill->customer_id) ? intval($first_waybill->customer_id) : null;
            }
            $customer_details = null;

            if ($customer_id && !$bulk_invoice_multi_customer) {
                $customer_details = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM $customers_table WHERE cust_id = %d LIMIT 1",
                        $customer_id
                    ),
                    ARRAY_A
                );
            }

            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->set('isFontSubsettingEnabled', false);
            $options->set('defaultFont', 'Arial');
            $options->set('isPhpEnabled', true);

            // Calculate totals BEFORE starting output buffer.
            // Bulk invoice must only reflect already-charged waybill totals (no grouped VAT/SAD500/SADC add-ons).
            $subtotal = 0.0;
            $row_amounts = [];
            foreach ($waybills as $waybill) {
                $line_amount = floatval($waybill->product_invoice_amount ?? 0);
                if ($line_amount <= 0) {
                    $basis = strtolower(trim((string)($waybill->charge_basis ?? '')));
                    if ($basis === 'weight') {
                        $basis = 'mass';
                    }
                    if ($basis === 'mass') {
                        $line_amount = floatval($waybill->mass_charge ?? 0);
                    } elseif ($basis === 'volume') {
                        $line_amount = floatval($waybill->volume_charge ?? 0);
                    } else {
                        $line_amount = max(floatval($waybill->mass_charge ?? 0), floatval($waybill->volume_charge ?? 0));
                    }
                }
                $waybill_key = (string)($waybill->waybill_no ?? '');
                $row_amounts[$waybill_key] = $line_amount;
                $subtotal += $line_amount;
            }

            // Bulk invoice total must not apply VAT here; VAT is handled in single-waybill flow only.
            $vat_amount  = 0.0;
            $grand_total = $subtotal;
            
            // Ensure grand_total is a valid number
            $grand_total = floatval($grand_total);
            if ($grand_total < 0) {
                $grand_total = 0.0;
            }

            // One PDF section per destination city (operating city); blank → Unassigned city.
            $bulk_city_label = static function ($wb): string {
                $c = trim((string)($wb->destination_city ?? ''));

                return $c !== '' ? $c : 'Unassigned city';
            };
            $waybills_by_city = [];
            foreach ($waybills as $_wb) {
                $_ck = $bulk_city_label($_wb);
                if (!isset($waybills_by_city[$_ck])) {
                    $waybills_by_city[$_ck] = [];
                }
                $waybills_by_city[$_ck][] = $_wb;
            }

            $dompdf = new Dompdf($options);

            ob_start();

            $invoice_number = 'BULK-' . date('Ymd-His');
            $invoice_date   = date('F j, Y');

            $charges_breakdown_colspan = !empty($bulk_invoice_multi_customer) ? 8 : 7;
            $charges_total_label_colspan = !empty($bulk_invoice_multi_customer) ? 7 : 6;
            $waybill_row_customer_label  = static function ($wb): string {
                $co = trim((string)($wb->company_name ?? ''));
                $generic_co = ['individual', '1ndividual', 'private', 'n/a', 'na', 'none', '-', '--'];
                if ($co !== '' && !in_array(strtolower($co), $generic_co, true)) {
                    return $co;
                }

                return trim((string)(($wb->customer_name ?? '') . ' ' . ($wb->customer_surname ?? '')));
            };

            // Get terms from company or use default
            $kit_terms = !empty($company['terms_and_conditions']) ? $company['terms_and_conditions'] : '';
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
            ?>
                <style>
  :root {
    --primary: <?php echo $primary_color; ?>;
    --secondary: <?php echo $secondary_color; ?>;
    --accent: <?php echo $accent_color; ?>;
  }

  @page {
    margin: 10mm 8mm 15mm 8mm;
    margin-footer: 5mm;
  }

  * {
    font-family: Arial, sans-serif !important;
  }

  body {
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
    font-size: 11px;
    line-height: 1.35;
    color: #333;
  }

  table {
    border-collapse: collapse;
    font-size: 10.5px;
  }

  th {
    font-size: 9.5px;
    font-weight: 700;
  }

  td {
    font-size: 10.5px;
  }

  .cellStyle {
    text-align: left;
    padding: 5px 6px;
    border-bottom: 1px solid #e0e7ef;
  }

  .cellStyle.alignleft {
    text-align: left;
  }

  .cellStyle.aligncenter {
    text-align: center;
  }

  .cellStyle.alignright {
    text-align: right;
  }

  .fstCol {
    width: 70px !important;
  }

  .thr {
    font-size: 9.5px;
    padding: 7px 5px;
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
    font-size: 14px;
  }

  .rowTotal {
    page-break-inside: avoid;
  }
  
  table {
    page-break-inside: auto;
  }
  
  tr {
    page-break-inside: avoid;
    page-break-after: auto;
  }
  
  thead {
    display: table-header-group;
  }
  
  tfoot {
    display: table-footer-group;
  }
  
  img {
    background: transparent !important;
  }

  .small-text {
    font-size: 9px;
  }

  .muted {
    color: #666;
  }

  .terms-conditions,
  .terms-conditions ul {
    font-size: 10px;
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
    color: <?php echo $primary_color; ?>;
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
    background-color: transparent;
  }

  .banking-details table {
    border-collapse: collapse;
  }

  .banking-details table td {
    padding: 2px 4px !important;
  }

  .banking-details table td.dCells {
    padding: 2px 6px 2px 0 !important;
  }

  .charges-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin-bottom: 5px;
  }

  .charges-table th,
  .charges-table td {
    word-wrap: break-word;
    overflow-wrap: anywhere;
  }

  .charges-table .col-description { width: 12%; }
  .charges-table .col-waybill     { width: 11%; }
  .charges-table .col-customer   { width: 16%; }
  .charges-table .col-dimensions  { width: 18%; }
  .charges-table .col-mass        { width: 10%; }
  .charges-table .col-volume      { width: 10%; }
  .charges-table .col-amount      { width: 12%; }
  .charges-table .col-subtotal    { width: 12%; }

  [style*='box-shadow'] {
    box-shadow: none !important;
  }

  .bulk-invoice-city-section--break {
    page-break-before: always;
    break-before: page;
  }

  .bulk-invoice-city-heading {
    font-size: 13px;
    font-weight: 700;
    color: <?php echo $secondary_color; ?>;
    margin: 10px 0 6px 0;
    padding: 6px 0;
    border-bottom: 2px solid <?php echo $primary_color; ?>;
  }
</style>
<!-- PAGE CONTAINER -->
<table width="100%" cellpadding="3" cellspacing="0" style="max-width:700px; margin:0 auto; font-family:Arial,sans-serif;font-size:12px;color:#333;">
  <tr>
    <td colspan="2">
      <!-- HEADER: LOGO + INVOICE INFO -->
      <table width="100%" cellpadding="3" cellspacing="0" style="margin-bottom:8px; border-bottom:1px solid #e9ecef;">
        <tr>
          <td style="width:40%;vertical-align:middle; background:transparent;">
            <img src="<?php echo dirname(dirname(plugin_dir_url(__FILE__))); ?>/img/logo.jpeg" alt="Company Logo" style="display:block; width:100%; height:auto; background:transparent;">
          </td>
          <td style="width:60%;vertical-align:middle;text-align:right;">
            <div style="font-size:18px;font-weight:bold;color:<?= $pTextColor ?> ;text-transform:uppercase; margin-bottom:4px;">
              <?= !empty($bulk_invoice_multi_customer) ? 'Combined bulk invoice' : 'Bulk Invoice' ?>
            </div>
            <div style="font-size:10px;color:#666; padding:6px;">
              <div><strong>Invoice #:</strong> <?= $invoice_number ?></div>
              <div><strong>Date:</strong> <?= $invoice_date ?></div>
              <div><strong>Waybills:</strong> <?= count($waybills) ?></div>
              <?php if (!empty($bulk_invoice_multi_customer)): ?>
              <div><strong>Customers:</strong> <?= (int) count($canonical_cust_ids) ?></div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      </table>

      <!-- ADDRESS ROW: FROM & BILL TO -->
      <table width="100%" cellpadding="0" cellspacing="3" style="margin-bottom:12px;">
        <tr>
          <td style="width:50%; background:#f8f9fa; border:1px solid #e9ecef; border-radius:4px; padding:8px;">
            <div style="font-size:13px;font-weight:bold;color:<?= $pTextColor ?> ;text-transform:uppercase; margin-bottom:4px;">From</div>
            <div style="font-size:10px; line-height:1.3;">
              <?= esc_html($company_name) ?><br>
              <?= nl2br(esc_html($company_address)) ?><br>
              <?= esc_html($company_phone) ?><br>
              <?= esc_html($company_email) ?><br>
              VAT: <?= esc_html($company_vat_number) ?>
            </div>
          </td>
          <td style="width:50%;background:#f8f9fa;border:1px solid #e9ecef;border-radius:4px;padding:8px;">
            <div style="font-size:13px;font-weight:bold;color:<?= $pTextColor ?> ;text-transform:uppercase; margin-bottom:4px;">Bill To</div>
            <div style="font-size:10px; line-height:1.3;">
              <?php if (!empty($bulk_invoice_multi_customer)): ?>
                <strong>Multiple customers</strong><br>
                <span class="muted">Bill-to lines in this table are shown per waybill.</span>
              <?php elseif ($customer_details): ?>
                <strong><?= esc_html($customer_details['company_name'] ?? trim(($customer_details['name'] ?? '') . ' ' . ($customer_details['surname'] ?? ''))) ?></strong><br>
                <?php if (!empty($customer_details['company_name'])): ?>
                  <?= esc_html(trim(($customer_details['name'] ?? '') . ' ' . ($customer_details['surname'] ?? ''))) ?><br>
                <?php endif; ?>
                <?= nl2br(esc_html($customer_details['address'] ?? '')) ?><br>
                <?php if (!empty($first_waybill->destination_country)): ?>
                  <?= esc_html($first_waybill->destination_country) ?><br>
                <?php endif; ?>
                Email: <?= esc_html($customer_details['email_address'] ?? '') ?><br>
                Cell: <?= esc_html($customer_details['cell'] ?? '') ?>
              <?php else: ?>
                <strong><?= esc_html(trim(($first_waybill->customer_name ?? '') . ' ' . ($first_waybill->customer_surname ?? ''))) ?></strong><br>
                <?= nl2br(esc_html($first_waybill->customer_address ?? '')) ?><br>
                Email: <?= esc_html($first_waybill->customer_email ?? '') ?><br>
                Cell: <?= esc_html($first_waybill->customer_phone ?? '') ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      </table>

      <?php
        $bulk_city_idx = 0;
      foreach ($waybills_by_city as $bulk_city_title => $city_waybills) :
            $bulk_city_idx++;
            $city_line_total = 0.0;
            foreach ($city_waybills as $_cw) {
                $_wk = (string)($_cw->waybill_no ?? '');
                $city_line_total += floatval($row_amounts[$_wk] ?? 0);
            }
      ?>
      <div class="bulk-invoice-city-section<?= $bulk_city_idx > 1 ? ' bulk-invoice-city-section--break' : '' ?>">
        <div class="bulk-invoice-city-heading"><?= esc_html('City: ' . $bulk_city_title) ?> — <?= (int) count($city_waybills) ?> waybill<?php echo count($city_waybills) === 1 ? '' : 's'; ?></div>
        <table class="charges-table" cellpadding="0" cellspacing="0">
        <tr>
          <td colspan="<?= (int) $charges_breakdown_colspan ?>" style="font-size:12px;font-weight:bold;color:<?= $pTextColor ?> ;padding-bottom:4px;">Charges breakdown</td>
        </tr>
        <tr class="scolor" style="color:#fff;font-size:11px;">
          <th align="left" class="thr col-description">Description</th>
          <th class="thr col-waybill" align="left">Waybill #</th>
          <?php if (!empty($bulk_invoice_multi_customer)): ?>
          <th class="thr col-customer" align="left">Customer</th>
          <?php endif; ?>
          <th class="thr col-dimensions" align="center">Dimensions (cm)</th>
          <th class="thr col-mass" align="center">Mass (kg)</th>
          <th class="thr col-volume" align="center">Volume (m³)</th>
          <th class="thr col-amount" align="right">Amount (R)</th>
          <th class="thr col-subtotal" align="right">Subtotal (R)</th>
        </tr>

        <?php foreach ($city_waybills as $waybill) :
          $length = floatval($waybill->item_length ?? 0);
          $width  = floatval($waybill->item_width ?? 0);
          $height = floatval($waybill->item_height ?? 0);
          $mass   = floatval($waybill->total_mass_kg ?? 0);
          $volume = floatval($waybill->total_volume ?? 0);
            $dimensions_display = ($length > 0 && $width > 0 && $height > 0)
            ? number_format($length, 0) . ' × ' . number_format($width, 0) . ' × ' . number_format($height, 0)
            : 'N/A';
            ?>
          <tr style="font-size:13px; background:#f9fafb; border-bottom:1px solid #e0e7ef;">
            <td class="cellStyle fstCol">
              <?php
              $bulk_basis = strtolower(trim((string) ($waybill->charge_basis ?? '')));
              if ($bulk_basis === 'weight') {
                  $bulk_basis = 'mass';
              }
              if ($bulk_basis !== 'mass' && $bulk_basis !== 'volume') {
                  $mc = floatval($waybill->mass_charge ?? 0);
                  $vc = floatval($waybill->volume_charge ?? 0);
                  $bulk_basis = ($mc >= $vc) ? 'mass' : 'volume';
              }
              // Mel (2026-08-05): generic transport label only — no goods description.
              $bulk_transport_label = 'Transport - ' . ucfirst($bulk_basis) . ' Charge';
              ?>
              <span style="display:inline-block;background:<?= $lightBadge ?>; color:<?= $pTextColor ?>; font-size:11px;padding:2px 8px;border-radius:6px;font-weight:600;"><?= esc_html($bulk_transport_label) ?></span>
            </td>
            <td class="cellStyle">
              <span style="font-weight:600;">#<?= esc_html($waybill->waybill_no) ?></span>
            </td>
            <?php if (!empty($bulk_invoice_multi_customer)): ?>
            <td class="cellStyle" style="font-size:10px;">
              <?= esc_html($waybill_row_customer_label($waybill) ?: '—') ?>
            </td>
            <?php endif; ?>
            <td class="cellStyle aligncenter" style="font-size:10px;">
              <?= esc_html($dimensions_display) ?>
            </td>
            <td class="cellStyle aligncenter" style="font-size:10px;">
              <?= $mass > 0 ? number_format($mass, 2) : 'N/A' ?>
            </td>
            <td class="cellStyle aligncenter" style="font-size:10px;">
              <?= $volume > 0 ? number_format($volume, 3) : 'N/A' ?>
            </td>
            <td class="cellStyle" style="text-align:right;">
              <?php if ($can_see_prices): ?>
                <?php
                $wb_key = (string)($waybill->waybill_no ?? '');
                echo number_format(floatval($row_amounts[$wb_key] ?? 0), 2);
                ?>
              <?php else: ?>
                N/A
              <?php endif; ?>
            </td>
            <td class="cellStyle" style="font-weight:600; text-align:right;">
              <?php if ($can_see_prices): ?>
                <?php
                $wb_key = (string)($waybill->waybill_no ?? '');
                echo number_format(floatval($row_amounts[$wb_key] ?? 0), 2);
                ?>
              <?php else: ?>
                N/A
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if ($can_see_prices): ?>
        <tr style="background:#eef2f7; font-weight:600;">
          <td class="cellStyle" colspan="<?= (int) $charges_total_label_colspan ?>" style="text-align:right;font-size:11px;">City subtotal — <?= esc_html($bulk_city_title) ?></td>
          <td class="cellStyle" style="text-align:right;font-size:11px; white-space:nowrap;">R <?= number_format($city_line_total, 2, '.', ',') ?></td>
        </tr>
        <?php else: ?>
        <tr style="background:#eef2f7;">
          <td class="cellStyle" colspan="<?= (int) $charges_total_label_colspan ?>" style="text-align:right;font-size:11px;">City subtotal — <?= esc_html($bulk_city_title) ?></td>
          <td class="cellStyle" style="text-align:right;font-size:11px;">N/A</td>
        </tr>
        <?php endif; ?>
        </table>
      </div>
      <?php endforeach; ?>

      <table class="charges-table" cellpadding="0" cellspacing="0" style="margin-top:10px;">
        <?php if ($can_see_prices): ?>
        <tr class="rowTotal pcolor">
          <td class="cellStyle" colspan="<?= (int) $charges_total_label_colspan ?>" style="font-size:14px; font-weight:700;">INVOICE TOTAL (all cities)</td>
          <td class="cellStyle" style="text-align:right; font-size:14px; font-weight:700; white-space:nowrap;">
            <?php
            $display_total = isset($grand_total) ? number_format(floatval($grand_total), 2, '.', ',') : '0.00';
            echo 'R ' . $display_total;
            ?>
          </td>
        </tr>
        <?php else: ?>
        <tr class="rowTotal pcolor">
          <td class="cellStyle" colspan="<?= (int) $charges_total_label_colspan ?>" style="font-size:14px; font-weight:700;">INVOICE TOTAL (all cities)</td>
          <td class="cellStyle" style="text-align:right; font-size:14px; font-weight:700; white-space:nowrap;">N/A</td>
        </tr>
        <?php endif; ?>
      </table>

      <!-- Modern Banking & Terms Section -->
      <table class="banking-details" width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <!-- Banking Details Card -->
          <td>
            <div style="background:#f8fafc; border-radius:8px; box-shadow:0 2px 8px rgba(37,99,235,0.07); padding:18px 20px 14px 20px; border:1.5px solid #e0e7ef;">
              <div style="font-size:13px; font-weight:700; color:<?= $pTextColor ?> ; margin-bottom:10px; letter-spacing:0.5px; display:flex; align-items:center;">
                <svg width="18" height="18" style="margin-right:7px;" fill="none" stroke="<?= $stroke_color ?>" stroke-width="2" viewBox="0 0 24 24">
                  <rect x="3" y="7" width="18" height="10" rx="2" />
                  <path d="M3 10h18" />
                </svg>
                Banking Details
              </div>
              <table width="100%">
                <?php if (!empty($company['bank_name'])): ?>
                  <tr>
                    <td class="dCells"><span style="color:#666;">Bank</span></td>
                    <td style="text-align:right; font-weight:600;"><?= esc_html($company['bank_name']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['account_holder'])): ?>
                  <tr>
                    <td class="dCells"><span style="color:#666;">Account Holder</span></td>
                    <td style="text-align:right;"><?= esc_html($company['account_holder']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['account_number'])): ?>
                  <tr>
                    <td class="dCells"><span style="color:#666;">Account #</span></td>
                    <td style="text-align:right; letter-spacing:1px;"><?= esc_html($company['account_number']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['branch_code'])): ?>
                  <tr>
                    <td class="dCells"><span style="color:#666;">Branch Code</span></td>
                    <td style="text-align:right;"><?= esc_html($company['branch_code']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['account_type'])): ?>
                  <tr>
                    <td class="dCells"><span style="color:#666;">Account Type</span></td>
                    <td style="text-align:right;"><?= esc_html($company['account_type']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['swift_code'])): ?>
                  <tr>
                    <td class="dCells"><span style="color:#666;">SWIFT</span></td>
                    <td style="text-align:right;"><?= esc_html($company['swift_code']); ?></td>
                  </tr>
                <?php endif; ?>
                <?php if (!empty($company['iban'])): ?>
                  <tr>
                    <td class="dCells"><span style="color:#666;">IBAN</span></td>
                    <td style="text-align:right;"><?= esc_html($company['iban']); ?></td>
                  </tr>
                <?php endif; ?>
              </table>
            </div>
          </td>
          <!-- Terms & Conditions Card -->
          <td>
            <div style="background:#f8fafc; border-radius:8px; box-shadow:0 2px 8px rgba(37,99,235,0.07); padding:18px 20px 14px 20px; border:1.5px solid #e0e7ef;">
              <div style="font-size:13px; font-weight:700; color:<?= $pTextColor ?> ; margin-bottom:10px; letter-spacing:0.5px; display:flex; align-items:center;">
                <svg width="18" height="18" style="margin-right:7px;" fill="none" stroke="<?= $stroke_color ?>" stroke-width="2" viewBox="0 0 24 24">
                  <rect x="4" y="4" width="16" height="16" rx="2" />
                  <path d="M8 8h8M8 12h8M8 16h4" />
                </svg>
                Terms &amp; Conditions
              </div>
              <div class="terms-conditions" style="padding-left:0; list-style-position:inside;">
                <?= $terms_html ?>
              </div>
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
<?php
            $html = ob_get_clean();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Set security headers before streaming PDF
            if (!headers_sent()) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="bulk-invoice-' . esc_attr($invoice_number) . '.pdf"');
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                header('X-Content-Type-Options: nosniff');
                if (is_ssl() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
                    header('Strict-Transport-Security: max-age=31536000');
                }
            }

            $dompdf->stream("bulk-invoice-{$invoice_number}.pdf", [
                "Attachment" => true
            ]);
            exit;
        } else {
            wp_die('No waybills found for the selected IDs.', 'No Waybills Found', ['response' => 404]);
        }
    } else {
        wp_die('No waybill IDs provided.', 'Invalid Request', ['response' => 400]);
    }
} else {
    wp_die('Invalid request. Please provide selected_ids parameter.', 'Invalid Request', ['response' => 400]);
}
?>








