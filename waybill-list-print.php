<?php
/**
 * Printable delivery list (manifest) and packing list for selected waybills.
 */

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
        die('WordPress bootstrap failed for waybill list print.');
    }
}

$nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
if (! $nonce || ! wp_verify_nonce($nonce, 'waybill_list_print')) {
    wp_die('Invalid request', '', ['response' => 403]);
}

if (! function_exists('is_user_logged_in') || ! is_user_logged_in()) {
    wp_die('You must be logged in.', '', ['response' => 403]);
}

if (! class_exists('KIT_User_Roles')) {
    wp_die('Access denied', '', ['response' => 403]);
}
if (! (KIT_User_Roles::is_admin() || KIT_User_Roles::is_manager() || KIT_User_Roles::is_data_capturer())) {
    wp_die('Access denied', '', ['response' => 403]);
}

$can_see_prices = class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices();

$list_type = isset($_GET['list_type']) ? sanitize_key(wp_unslash($_GET['list_type'])) : '';
if (! in_array($list_type, ['delivery', 'packing'], true)) {
    wp_die('Invalid list type', '', ['response' => 400]);
}

$ids_raw = isset($_GET['ids']) ? wp_unslash($_GET['ids']) : '';
$ids = array_values(array_unique(array_filter(array_map('sanitize_text_field', array_map('trim', explode(',', $ids_raw))))));
if (empty($ids)) {
    wp_die('No waybills specified', '', ['response' => 400]);
}
if (count($ids) > 500) {
    $ids = array_slice($ids, 0, 500);
}

if (! class_exists('KIT_Waybills')) {
    require_once __DIR__ . '/includes/waybill/waybill-functions.php';
}

/**
 * @param object $wb
 */
function kit_wlp_row_description($wb)
{
    if (! empty($wb->description)) {
        return trim((string) $wb->description);
    }
    if (! empty($wb->miscellaneous)) {
        $misc = maybe_unserialize($wb->miscellaneous);
        if (is_array($misc) && isset($misc['others']['waybill_description'])) {
            return trim((string) $misc['others']['waybill_description']);
        }
    }
    return '';
}

global $wpdb;
$waybills_table            = $wpdb->prefix . 'kit_waybills';
$customers_table           = $wpdb->prefix . 'kit_customers';
$deliveries_table          = $wpdb->prefix . 'kit_deliveries';
$shipping_directions_table = $wpdb->prefix . 'kit_shipping_directions';
$cities_table              = $wpdb->prefix . 'kit_operating_cities';
$countries_table           = $wpdb->prefix . 'kit_operating_countries';

$placeholders = implode(',', array_fill(0, count($ids), '%s'));
$query        = $wpdb->prepare(
    "SELECT 
        w.waybill_no,
        w.customer_id,
        w.product_invoice_amount,
        w.miscellaneous,
        w.description,
        w.item_length,
        w.item_width,
        w.item_height,
        w.total_mass_kg,
        w.total_volume,
        c.name AS customer_name,
        c.surname AS customer_surname,
        COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) AS company_name,
        dest_country.country_name AS destination_country,
        orig_country.country_name AS origin_country,
        city.city_name AS destination_city,
        d.delivery_reference
    FROM {$waybills_table} w
    LEFT JOIN {$customers_table} c ON w.customer_id = c.cust_id
    LEFT JOIN {$wpdb->prefix}kit_company_customers co ON w.company_id = co.company_id
    LEFT JOIN {$wpdb->prefix}kit_company_customers cust_co ON c.company_id = cust_co.company_id
    LEFT JOIN {$deliveries_table} d ON w.delivery_id = d.id
    LEFT JOIN {$shipping_directions_table} sd ON d.direction_id = sd.id
    LEFT JOIN {$cities_table} city ON w.city_id = city.id
    LEFT JOIN {$countries_table} dest_country ON sd.destination_country_id = dest_country.id
    LEFT JOIN {$countries_table} orig_country ON sd.origin_country_id = orig_country.id
    WHERE w.waybill_no IN ($placeholders)",
    $ids
);

$rows = $wpdb->get_results($query);
if (empty($rows)) {
    wp_die('No matching waybills found', '', ['response' => 404]);
}

// Preserve request order.
$by_no = [];
foreach ($rows as $r) {
    $by_no[(string) $r->waybill_no] = $r;
}
$ordered = [];
foreach ($ids as $no) {
    if (isset($by_no[$no])) {
        $ordered[] = $by_no[$no];
    }
}
if (empty($ordered)) {
    wp_die('No matching waybills found', '', ['response' => 404]);
}

$title = $list_type === 'packing' ? 'Packing list' : 'Delivery list';

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title><?php echo esc_html($title); ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 8px; color: #1d4ed8; }
        .meta { color: #6b7280; font-size: 11px; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f8fafc; font-weight: 600; font-size: 11px; }
        h2.city-h { font-size: 14px; margin: 20px 0 8px; color: #111827; border-bottom: 2px solid #1d4ed8; padding-bottom: 4px; }
        .wb-block { page-break-inside: avoid; margin-bottom: 24px; }
        .wb-block h3 { font-size: 13px; margin: 0 0 8px; color: #2563eb; }
        tr { page-break-inside: avoid; }
    </style>
    <script>
        window.print && setTimeout(function () { window.print(); }, 200);
    </script>
</head>
<body>
    <h1><?php echo esc_html($title); ?></h1>
    <p class="meta">Generated <?php echo esc_html(date_i18n('Y-m-d H:i')); ?> · <?php echo esc_html((string) count($ordered)); ?> waybill(s)</p>

<?php if ($list_type === 'delivery') : ?>
    <?php
    $by_city = [];
    foreach ($ordered as $wb) {
        $city = $wb->destination_city ?? '';
        if ($city === '' || $city === null) {
            $city = 'Unassigned city';
        }
        $city = (string) $city;
        if (! isset($by_city[$city])) {
            $by_city[$city] = [];
        }
        $by_city[$city][] = $wb;
    }
    ksort($by_city);
    foreach ($by_city as $city_name => $city_rows) :
        ?>
        <h2 class="city-h"><?php echo esc_html($city_name); ?> <span style="font-weight:400;color:#6b7280">(<?php echo esc_html((string) count($city_rows)); ?>)</span></h2>
        <table>
            <thead>
                <tr>
                    <th>Waybill #</th>
                    <th>Description</th>
                    <th>Customer</th>
                    <th>Mass (kg)</th>
                    <th>Volume (m³)</th>
                    <th>Dims (cm)</th>
                    <?php if ($can_see_prices) : ?>
                        <th>Total (R)</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($city_rows as $wb) : ?>
                <?php
                $cust = trim((string)($wb->customer_name ?? '') . ' ' . (string)($wb->customer_surname ?? ''));
                if ($wb->company_name ?? '') {
                    $cust = $cust ? $cust . ' — ' . $wb->company_name : (string) $wb->company_name;
                }
                $desc = kit_wlp_row_description($wb);
                $mass = isset($wb->total_mass_kg) ? (float) $wb->total_mass_kg : 0.0;
                $vol  = isset($wb->total_volume) ? (float) $wb->total_volume : 0.0;
                $dims = trim((string)($wb->item_length ?? 0) . ' × ' . (string)($wb->item_width ?? 0) . ' × ' . (string)($wb->item_height ?? 0));

                $line_total = 0.0;
                if ($can_see_prices) {
                    $amt = isset($wb->product_invoice_amount) ? (float) $wb->product_invoice_amount : 0.0;
                    $misc_total = 0.0;
                    if (! empty($wb->miscellaneous)) {
                        $misc_data = maybe_unserialize($wb->miscellaneous);
                        if (is_array($misc_data)) {
                            if (isset($misc_data['misc_total'])) {
                                $misc_total = (float) $misc_data['misc_total'];
                            } elseif (isset($misc_data['misc_items']) && is_array($misc_data['misc_items'])) {
                                foreach ($misc_data['misc_items'] as $item) {
                                    $price = isset($item['misc_price']) ? (float) $item['misc_price'] : 0.0;
                                    $qty   = isset($item['misc_quantity']) ? (int) $item['misc_quantity'] : 0;
                                    $misc_total += $price * $qty;
                                }
                            }
                        }
                    }
                    $line_total = $amt + $misc_total;
                }
                ?>
                <tr>
                    <td><?php echo esc_html((string) $wb->waybill_no); ?></td>
                    <td><?php echo esc_html($desc ?: '—'); ?></td>
                    <td><?php echo esc_html($cust ?: '—'); ?></td>
                    <td><?php echo esc_html(number_format($mass, 2)); ?></td>
                    <td><?php echo esc_html(number_format($vol, 4)); ?></td>
                    <td><?php echo esc_html($dims); ?></td>
                    <?php if ($can_see_prices) : ?>
                        <td><?php echo esc_html(number_format($line_total, 2)); ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

<?php else : ?>
    <?php foreach ($ordered as $wb) : ?>
        <?php
        $full = KIT_Waybills::getFullWaybillWithItems($wb->waybill_no);
        $warr = $full && ! empty($full->waybill) ? (array) $full->waybill : [];
        $items = $full && is_array($full->items) ? $full->items : [];
        $desc_fallback = kit_wlp_row_description($wb);
        $cust = trim((string)($wb->customer_name ?? '') . ' ' . (string)($wb->customer_surname ?? ''));
        if ($wb->company_name ?? '') {
            $cust = $cust ? $cust . ' — ' . $wb->company_name : (string) $wb->company_name;
        }
        $city_label = $wb->destination_city ?: '—';
        ?>
        <div class="wb-block">
            <h3>Waybill #<?php echo esc_html((string) $wb->waybill_no); ?></h3>
            <table>
                <tr>
                    <th>Destination city</th>
                    <td><?php echo esc_html((string) $city_label); ?></td>
                    <th>Customer</th>
                    <td><?php echo esc_html($cust ?: '—'); ?></td>
                </tr>
                <?php if (! empty($wb->delivery_reference)) : ?>
                <tr>
                    <th>Delivery ref</th>
                    <td colspan="3"><?php echo esc_html((string) $wb->delivery_reference); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Mass (kg)</th>
                    <td><?php echo esc_html(number_format((float) ($warr['total_mass_kg'] ?? $wb->total_mass_kg ?? 0), 2)); ?></td>
                    <th>Volume (m³)</th>
                    <td><?php echo esc_html(number_format((float) ($warr['total_volume'] ?? $wb->total_volume ?? 0), 4)); ?></td>
                </tr>
                <tr>
                    <th>Dims (cm)</th>
                    <td colspan="3"><?php echo esc_html(trim((string)($warr['item_length'] ?? $wb->item_length ?? 0) . ' × ' . (string)($warr['item_width'] ?? $wb->item_width ?? 0) . ' × ' . (string)($warr['item_height'] ?? $wb->item_height ?? 0))); ?></td>
                </tr>
            </table>

            <?php if (! empty($items)) : ?>
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <?php if ($can_see_prices) : ?>
                                <th>Unit</th>
                                <th>Line</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $it) : ?>
                        <?php
                        $it = is_array($it) ? $it : (array) $it;
                        $iname = $it['item_name'] ?? $it['item'] ?? '';
                        $iqty  = isset($it['quantity']) ? $it['quantity'] : 0;
                        $iprice = isset($it['unit_price']) ? (float) $it['unit_price'] : 0.0;
                        $iline  = (float) $iqty * $iprice;
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) $iname); ?></td>
                            <td><?php echo esc_html((string) $iqty); ?></td>
                            <?php if ($can_see_prices) : ?>
                                <td><?php echo esc_html(number_format($iprice, 2)); ?></td>
                                <td><?php echo esc_html(number_format($iline, 2)); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><strong>Description:</strong> <?php echo esc_html($desc_fallback ?: '—'); ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
