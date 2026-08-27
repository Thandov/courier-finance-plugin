<?php
if (!defined('ABSPATH')) {
exit;
}

// Include user roles for strict access control
require_once plugin_dir_path(__FILE__) . '../user-roles.php';
// seed-json.php / seed-sql.php removed 2026-08-05: both generated seeds from
// waybill_excel/*.xlsx via shell_exec+pandas, that directory no longer exists,
// nothing referenced their functions, and they encoded the retired definition
// of product_invoice_amount (freight total rather than the parcels total).
// Ownership verification (expected sheet map vs DB after seed)
$seed_verification_path = plugin_dir_path(__FILE__) . '../seed/seed-verification.php';
if (file_exists($seed_verification_path)) {
    require_once $seed_verification_path;
}
require_once plugin_dir_path(__FILE__) . '../sync/kit-seed-user-resolve.php';

// Check if current user has admin capabilities
$wpdb_global_was_set = true; // marker
global $wpdb;

if (!function_exists('kit_get_seed_owner_user_id')) {
/**
 * Resolve owner for seeded/imported records.
 * Prefer user login `mel`, then name patterns. Optional wp-config: define('KIT_SEED_OWNER_USER_ID', 1594);
 */
function kit_get_seed_owner_user_id(): int
{
    if (defined('KIT_SEED_OWNER_USER_ID') && (int) constant('KIT_SEED_OWNER_USER_ID') > 0) {
        return (int) constant('KIT_SEED_OWNER_USER_ID');
    }

    global $wpdb;

    $mel_id = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->users}
            WHERE LOWER(user_login) IN ('mel', 'melwelmans')
            ORDER BY CASE LOWER(user_login) WHEN 'mel' THEN 0 WHEN 'melwelmans' THEN 1 ELSE 2 END
            LIMIT 1"
    );
    if ($mel_id > 0) {
        return $mel_id;
    }

    $mel_id = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->users}
            WHERE LOWER(display_name) IN ('mel welmans', 'mel')
            OR LOWER(display_name) LIKE '%mel%welmans%'
            ORDER BY
            (LOWER(display_name) = 'mel welmans') DESC,
            (LOWER(display_name) = 'mel') DESC
            LIMIT 1"
    );
    if ($mel_id > 0) {
        return $mel_id;
    }

    return 1;
}
}

if (!function_exists('kit_google_sheet_parse_bool_int')) {
/**
 * Normalize a Google Sheet / CSV cell to 0 or 1 for DB TINYINT flags.
 * Accepts TRUE/FALSE, 1/0, YES/NO, Y/N, ON/OFF, and numeric strings.
 *
 * Defined before settings access control so pull-sync (via require_once) always has it.
 */
function kit_google_sheet_parse_bool_int($raw): int
{
    if ($raw === null || $raw === false) {
        return 0;
    }
    if ($raw === true) {
        return 1;
    }
    $s = strtoupper(trim((string) $raw));
    if ($s === '' || $s === 'NULL' || $s === 'N/A' || $s === 'NA' || $s === '-' || $s === '--') {
        return 0;
    }
    if (in_array($s, ['1', 'TRUE', 'YES', 'Y', 'ON'], true)) {
        return 1;
    }
    if (in_array($s, ['0', 'FALSE', 'NO', 'N', 'OFF'], true)) {
        return 0;
    }
    if (is_numeric($s)) {
        return ((float) $s != 0.0) ? 1 : 0;
    }

    return 0;
}
}

if (!function_exists('kit_courier_debug_seed_ndjson')) {
/**
 * Debug NDJSON append for Google Sheet seed (session b868b3).
 * Resolves log path next to plugin root so local MAMP + deployed hosts both work.
 * Must load before POST handlers — run_google_sheet_seed() can run from line ~116 before the rest of this file executes.
 */
function kit_courier_debug_seed_ndjson(array $payload): void
{
    if (!defined('WP_DEBUG') || !WP_DEBUG || !defined('KIT_DEBUG_SEED_NDJSON')) {
        return;
    }
    $base = dirname(__FILE__, 3);
    $dir = $base . '/.cursor';
    $path = $dir . '/debug-b868b3.log';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $payload['sessionId'] = 'b868b3';
    $payload['timestamp'] = (int) round(microtime(true) * 1000);
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
}

// STRICT ACCESS CONTROL: Only specific administrators can access settings (skip for CLI seed simulation)
if (!defined('COURIER_SEED_SIMULATION') && !KIT_User_Roles::can_access_settings()) {
wp_die('Access denied. This page is only available to authorized administrators (Thando, Mel, Patricia).');
}

// Handle form submissions
if ($_POST && isset($_POST['action'])) {
// Clear plugin error logs (deactivation-debug.log in wp-content)
if ($_POST['action'] === 'clear_plugin_logs' && isset($_POST['clear_logs_nonce']) && wp_verify_nonce($_POST['clear_logs_nonce'], 'clear_plugin_logs')) {
    $clear_logs_result = courier_finance_clear_plugin_logs();
}

// Handle wipe tables: clear all plugin data tables
if ($_POST['action'] === 'wipe_tables' && isset($_POST['wipe_tables_nonce']) && wp_verify_nonce($_POST['wipe_tables_nonce'], 'wipe_tables')) {
    $wipe_tables_result = handle_wipe_tables();
}

// Setup seed runs via admin-post.php (kit_admin_post_google_sheet_seed_handler) so the long
// request completes before WordPress admin HTML is sent — avoids Apache/FastCGI 500 timeouts.

// Toggle automatic Google Sheet sync for create/update/delete actions.
if (
    $_POST['action'] === 'toggle_google_auto_sync'
    && isset($_POST['toggle_google_auto_sync_nonce'])
    && wp_verify_nonce($_POST['toggle_google_auto_sync_nonce'], 'toggle_google_auto_sync')
) {
    $enable_auto_sync = isset($_POST['enable_auto_sync']) && (int) $_POST['enable_auto_sync'] === 1;
    update_option('courier_google_auto_sync_enabled', $enable_auto_sync ? 1 : 0, false);
    $google_auto_sync_result = [
        'success' => true,
        'enabled' => $enable_auto_sync,
        'message' => $enable_auto_sync
            ? 'Auto sync is now ON. New changes will upload to Google Sheets.'
            : 'Auto sync is now OFF. New changes will stay local until you turn it back on.',
    ];
}

// Toggle fake filler for fast UI testing.
if (
    $_POST['action'] === 'toggle_fake_filler'
    && isset($_POST['toggle_fake_filler_nonce'])
    && wp_verify_nonce($_POST['toggle_fake_filler_nonce'], 'toggle_fake_filler')
) {
    $enable_fake_filler = isset($_POST['enable_fake_filler']) && (int) $_POST['enable_fake_filler'] === 1;
    update_option('courier_fake_filler_enabled', $enable_fake_filler ? 1 : 0, false);
    $fake_filler_result = [
        'success' => true,
        'enabled' => $enable_fake_filler,
        'message' => $enable_fake_filler
            ? 'Fake filler is now ON. A floating "Fake Fill" button will appear on waybill forms.'
            : 'Fake filler is now OFF. The floating fake-fill button is hidden.',
    ];
}

// Toggle maintenance mode (blurs plugin UI in admin and employee portal).
if (
    $_POST['action'] === 'toggle_maintenance_mode'
    && isset($_POST['toggle_maintenance_mode_nonce'])
    && wp_verify_nonce($_POST['toggle_maintenance_mode_nonce'], 'toggle_maintenance_mode')
) {
    $enable_maint = isset($_POST['enable_maintenance_mode']) && (int) $_POST['enable_maintenance_mode'] === 1;
    update_option('kit_maintenance_mode', $enable_maint ? 1 : 0, false);
    $maintenance_mode_result = [
        'success' => true,
        'enabled' => $enable_maint,
        'message' => $enable_maint
            ? 'Maintenance mode is ON. Plugin screens are blurred until you turn it off.'
            : 'Maintenance mode is OFF. Plugin screens are available again.',
    ];
}

// Handle banking, company, and charges forms (all use same nonce)
if (
    isset($_POST['settings_nonce']) && wp_verify_nonce($_POST['settings_nonce'], 'save_settings') &&
    isset($_POST['action']) && in_array($_POST['action'], ['save_banking', 'save_company', 'save_charges'])
) {
    global $wpdb;
    $table = $wpdb->prefix . 'kit_company_details';
    // Only update columns that were posted to avoid wiping existing values
    $sanitizers = [
        'company_name' => function ($v) {
            return sanitize_text_field($v);
        },
        'company_address' => function ($v) {
            return sanitize_textarea_field($v);
        },
        'company_email' => function ($v) {
            return sanitize_email($v);
        },
        'company_phone' => function ($v) {
            return sanitize_text_field($v);
        },
        'company_website' => function ($v) {
            return esc_url_raw($v);
        },
        'company_registration' => function ($v) {
            return sanitize_text_field($v);
        },
        'company_vat_number' => function ($v) {
            return sanitize_text_field($v);
        },
        'bank_name' => function ($v) {
            return sanitize_text_field($v);
        },
        'account_number' => function ($v) {
            return sanitize_text_field($v);
        },
        'branch_code' => function ($v) {
            return sanitize_text_field($v);
        },
        'account_type' => function ($v) {
            return sanitize_text_field($v);
        },
        'account_holder' => function ($v) {
            return sanitize_text_field($v);
        },
        'swift_code' => function ($v) {
            return sanitize_text_field($v);
        },
        'iban' => function ($v) {
            return sanitize_text_field($v);
        },
        'vat_percentage' => function ($v) {
            return (float)$v;
        },
        'sadc_charge' => function ($v) {
            return (float)$v;
        },
        'sad500_charge' => function ($v) {
            return (float)$v;
        },
        'international_price' => function ($v) {
            return (float)$v;
        },
    ];
    $fields = [];
    foreach ($sanitizers as $key => $fn) {
        if (array_key_exists($key, $_POST)) {
            $fields[$key] = $fn($_POST[$key]);
        }
    }
    // Always enforce hardcoded 08600 address, phone, and VAT.
    if (class_exists('KIT_Company')) {
        $fields = array_merge($fields, KIT_Company::hardcoded_identity());
    }
    // If nothing to update, do nothing
    if (!empty($fields)) {
        // Maintain a single-row table
        $exists = $wpdb->get_var("SELECT id FROM $table ORDER BY id ASC LIMIT 1");
        if ($exists) {
            $wpdb->update($table, $fields, ['id' => intval($exists)]);
            $message = 'Settings updated successfully.';
        } else {
            $wpdb->insert($table, $fields);
            $message = 'Settings saved successfully.';
        }
    }
}

// Save Terms & Conditions
if (isset($_POST['action']) && $_POST['action'] === 'save_terms' && wp_verify_nonce($_POST['settings_nonce'], 'save_settings')) {
    // Prefer list items if provided
    $built_html = '';
    if (!empty($_POST['terms_items']) && is_array($_POST['terms_items'])) {
        $items = array_map('sanitize_text_field', $_POST['terms_items']);
        $items = array_values(array_filter($items, function ($v) {
            return strlen(trim((string)$v)) > 0;
        }));
        if (!empty($items)) {
            $html = '<ul class="terms-conditions">';
            foreach ($items as $it) {
                $html .= '<li>' . esc_html($it) . '</li>';
            }
            $html .= '</ul>';
            $built_html = $html;
        }
    }

    // Fallback: accept pasted HTML (sanitized) if no list inputs were used
    if ($built_html === '') {
        $allowed_html_terms = wp_kses_allowed_html('post');
        $terms_raw = $_POST['terms_content'] ?? '';
        $built_html = wp_kses((string)$terms_raw, $allowed_html_terms);
    }

    update_option('kit_terms_conditions', $built_html);
    $message = 'Terms & Conditions saved successfully.';
}

// Save brand colors (60/30/10 rule)
if (isset($_POST['action']) && $_POST['action'] === 'save_colors' && wp_verify_nonce($_POST['settings_nonce'], 'save_settings')) {
    $primary   = isset($_POST['primary_color']) ? sanitize_hex_color($_POST['primary_color']) : '';
    $secondary = isset($_POST['secondary_color']) ? sanitize_hex_color($_POST['secondary_color']) : '';
    $accent    = isset($_POST['accent_color']) ? sanitize_hex_color($_POST['accent_color']) : '';

    // Fallbacks if invalid
    if (!$primary) {
        $primary   = '#2563eb';
    }
    if (!$secondary) {
        $secondary = '#111827';
    }
    if (!$accent) {
        $accent    = '#10b981';
    }

    $schema = [
        'primary'   => $primary,
        'secondary' => $secondary,
        'accent'    => $accent,
        'rule'      => '60/30/10',
        'updated_at' => current_time('mysql'),
    ];

    $json_path = plugin_dir_path(__FILE__) . '../../colorSchema.json';
    // Ensure we can write the file
    $written = @file_put_contents($json_path, wp_json_encode($schema, JSON_PRETTY_PRINT));
    if ($written !== false) {
        $message = 'Colors saved successfully.';
    } else {
        $message = 'Failed to save colors. Please check file permissions.';
    }
}
}

// Manual exporter: create newSQL.sql from current DB (drivers, customers, deliveries, waybills)
function kit_export_seed_sql_from_db(): array
{
global $wpdb;
$pluginRoot = plugin_dir_path(__FILE__) . '../../';
$target = $pluginRoot . 'newSQL.sql';

$drivers = $wpdb->get_results("SELECT name, is_active FROM {$wpdb->prefix}kit_drivers ORDER BY id ASC", ARRAY_A) ?: [];
$customers = $wpdb->get_results("SELECT cust_id, name, surname, company_id, country_id FROM {$wpdb->prefix}kit_customers ORDER BY id ASC", ARRAY_A) ?: [];
$deliveries = $wpdb->get_results("SELECT id, delivery_reference, direction_id, destination_city_id, dispatch_date, driver_id, status FROM {$wpdb->prefix}kit_deliveries ORDER BY id ASC", ARRAY_A) ?: [];
$waybills = $wpdb->get_results("SELECT description, direction_id, city_id, delivery_id, customer_id, waybill_no, warehouse, product_invoice_number, product_invoice_amount, waybill_items_total, total_mass_kg, total_volume, mass_charge, volume_charge, charge_basis, miscellaneous, include_sad500, include_sadc, vat_include, tracking_number, status, created_at, last_updated_at FROM {$wpdb->prefix}kit_waybills ORDER BY id ASC", ARRAY_A) ?: [];

$lines = [];
$lines[] = 'START TRANSACTION';
foreach ($drivers as $r) {
    $name = addslashes($r['name']);
    $active = (int)($r['is_active'] ?? 1);
    $lines[] = "INSERT INTO wp_kit_drivers (name, is_active) SELECT '{$name}', {$active} FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM wp_kit_drivers WHERE name = '{$name}')";
}
foreach ($customers as $r) {
    $cust_id = (int)$r['cust_id'];
    $name = addslashes($r['name']);
    $surname = addslashes($r['surname']);
    $company_id = (int) ($r['company_id'] ?? 0);
    $country = (int)$r['country_id'];
    $company_sql = $company_id > 0 ? (string) $company_id : 'NULL';
    $lines[] = "INSERT INTO wp_kit_customers (cust_id, name, surname, company_id, country_id) SELECT {$cust_id}, '{$name}', '{$surname}', {$company_sql}, {$country} FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM wp_kit_customers WHERE cust_id = {$cust_id})";
}
foreach ($deliveries as $r) {
    $ref = addslashes($r['delivery_reference']);
    $dir = (int)$r['direction_id'];
    $city = (int)$r['destination_city_id'];
    if (class_exists('KIT_Routes')) {
        $reconciled = KIT_Routes::reconcile_delivery_route($dir, $city);
        $dir = (int) $reconciled['direction_id'];
        $city = (int) $reconciled['destination_city_id'];
    }
    $date = addslashes($r['dispatch_date']);
    $driver_id = (int)$r['driver_id'];
    $status = addslashes($r['status']);
    $lines[] = "INSERT INTO wp_kit_deliveries (delivery_reference, direction_id, destination_city_id, dispatch_date, driver_id, status) SELECT '{$ref}', {$dir}, {$city}, '{$date}', (SELECT id FROM wp_kit_drivers d WHERE d.id = {$driver_id} LIMIT 1), '{$status}' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM wp_kit_deliveries WHERE delivery_reference = '{$ref}')";
}
foreach ($waybills as $r) {
    $cols = ['description', 'direction_id', 'city_id', 'delivery_id', 'customer_id', 'waybill_no', 'warehouse', 'product_invoice_number', 'product_invoice_amount', 'waybill_items_total', 'total_mass_kg', 'total_volume', 'mass_charge', 'volume_charge', 'charge_basis', 'miscellaneous', 'include_sad500', 'include_sadc', 'vat_include', 'tracking_number', 'status', 'created_at', 'last_updated_at'];
    $vals = [];
    $delivery_id = (int)($r['delivery_id'] ?? 0);
    foreach ($cols as $c) {
        if ($c === 'city_id') {
            $city_id = isset($r['city_id']) && (int)$r['city_id'] > 0 ? (int)$r['city_id'] : 0;
            if ($city_id === 0 && $delivery_id > 0) {
                $city_id = (int)$wpdb->get_var($wpdb->prepare("SELECT destination_city_id FROM {$wpdb->prefix}kit_deliveries WHERE id = %d", $delivery_id));
            }
            $direction_id = isset($r['direction_id']) ? (int) $r['direction_id'] : 0;
            if (class_exists('KIT_Routes')) {
                if ($direction_id <= 0) {
                    $direction_id = 2;
                }
                $city_id = KIT_Routes::resolve_destination_city_id($direction_id, $city_id);
            }
            $vals[] = $city_id > 0 ? (string)$city_id : '6';
            continue;
        }
        $v = $r[$c];
        if (is_null($v)) {
            $vals[] = 'NULL';
            continue;
        }
        if (is_numeric($v) && !in_array($c, ['description', 'product_invoice_number', 'tracking_number', 'status', 'charge_basis', 'miscellaneous', 'created_at', 'last_updated_at'])) {
            $vals[] = (string)$v;
        } else {
            $vals[] = "'" . addslashes((string)$v) . "'";
        }
    }
    $lines[] = 'INSERT INTO wp_kit_waybills (' . implode(',', $cols) . ') SELECT ' . implode(',', $vals) . ' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM wp_kit_waybills WHERE waybill_no = ' . (int)$r['waybill_no'] . ')';
}
$lines[] = 'COMMIT';

$ok = @file_put_contents($target, implode(";\n\n", $lines) . ";\n");
if ($ok === false) {
    return ['success' => false, 'message' => 'Failed to write newSQL.sql'];
}
return ['success' => true, 'message' => 'newSQL.sql exported from DB', 'path' => $target];
}
/**
 * Ensure a prefix-adjusted copy of assets/customers.sql exists as assets/customers_dynamic.sql
 * Recreates the file if it is missing or older than the source file.
 * Returns an array [success => bool, message => string, path => string|null]
 */
function kit_ensure_dynamic_customers_sql(): array
{
global $wpdb;
$assetsDir = plugin_dir_path(__FILE__) . '../../assets/';
$src = $assetsDir . 'customers.sql';
$dest = $assetsDir . 'customers_dynamic.sql';

if (!file_exists($src)) {
    return ['success' => false, 'message' => 'Source SQL not found: customers.sql', 'path' => null];
}

$needsRegen = !file_exists($dest) || (filemtime($dest) < filemtime($src));
if (!$needsRegen) {
    return ['success' => true, 'message' => 'Dynamic SQL up to date', 'path' => $dest];
}

$sql = file_get_contents($src);
if ($sql === false) {
    return ['success' => false, 'message' => 'Failed to read customers.sql', 'path' => null];
}

// Replace hardcoded wp_ with current WordPress prefix
$sql = preg_replace('/`wp_([a-zA-Z_]+)`/', '`' . $wpdb->prefix . '$1`', $sql);
$sql = preg_replace('/(?<![a-zA-Z0-9_])wp_([a-zA-Z_]+)/', $wpdb->prefix . '$1', $sql);

$written = @file_put_contents($dest, $sql);
if ($written === false) {
    return ['success' => false, 'message' => 'Failed to write customers_dynamic.sql (check permissions)', 'path' => null];
}

return ['success' => true, 'message' => 'customers_dynamic.sql regenerated', 'path' => $dest];
}

// Function to handle customer seeding
function handle_customer_seeding()
{
global $wpdb;

// Check if already seeded
$already_seeded = get_option('kit_customers_seeded', false);
if ($already_seeded) {
    return [
        'success' => false,
        'message' => 'Customers have already been seeded. This can only be done once per plugin installation.'
    ];
}

// Check if customers table has data
$existing_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kit_customers WHERE cust_id >= 100001");
if ($existing_count > 0) {
    return [
        'success' => false,
        'message' => 'Customer data already exists in the database. Seeding is not allowed.'
    ];
}

try {
    // Try to use dynamic SQL file first, fall back to original with prefix replacement
    $dynamic_sql_file = plugin_dir_path(__FILE__) . '../../assets/customers_dynamic.sql';
    $original_sql_file = plugin_dir_path(__FILE__) . '../../assets/customers.sql';

    // Ensure customers_dynamic.sql exists and is up to date
    $regen = kit_ensure_dynamic_customers_sql();
    if ($regen['success']) {
        $dynamic_sql_file = $regen['path'];
    }

    if ($dynamic_sql_file && file_exists($dynamic_sql_file)) {
        // Use pre-generated dynamic SQL file
        $sql_file = $dynamic_sql_file;
        $sql_content = file_get_contents($sql_file);
        error_log("Customer seeding using dynamic SQL file with prefix: " . $wpdb->prefix);
    } elseif (file_exists($original_sql_file)) {
        // Use original SQL file with dynamic prefix replacement
        $sql_file = $original_sql_file;
        $sql_content = file_get_contents($sql_file);

        // Replace hardcoded wp_ prefix with dynamic WordPress prefix
        // Use regex to replace table names more precisely
        $sql_content = preg_replace('/`wp_([a-zA-Z_]+)`/', '`' . $wpdb->prefix . '$1`', $sql_content);
        $sql_content = preg_replace('/wp_([a-zA-Z_]+)/', $wpdb->prefix . '$1', $sql_content);

        error_log("Customer seeding using original SQL file with prefix replacement: " . $wpdb->prefix);
    } else {
        return [
            'success' => false,
            'message' => 'Customer SQL file not found. Checked: ' . $dynamic_sql_file . ' and ' . $original_sql_file
        ];
    }

    // Split SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql_content)));

    $executed_count = 0;
    $error_count = 0;
    $errors = [];

    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue; // Skip empty statements and comments
        }

        $result = $wpdb->query($statement);
        if ($result === false) {
            $error_count++;
            $errors[] = $wpdb->last_error;
        } else {
            $executed_count++;
        }
    }

    if ($error_count > 0) {
        return [
            'success' => false,
            'message' => "Seeding completed with errors. Executed: $executed_count statements, Errors: $error_count. First error: " . ($errors[0] ?? 'Unknown error')
        ];
    }

    // Mark as seeded
    update_option('kit_customers_seeded', true);

    return [
        'success' => true,
        'message' => "Successfully seeded customers! Executed $executed_count SQL statements."
    ];
} catch (Exception $e) {
    return [
        'success' => false,
        'message' => 'Seeding failed: ' . $e->getMessage()
    ];
}
}

function handle_customer_unseeding()
{
global $wpdb;

// Get table names
$customers_table = $wpdb->prefix . 'kit_customers';
$waybills_table = $wpdb->prefix . 'kit_waybills';
$waybill_items_table = $wpdb->prefix . 'kit_waybill_items';

try {
    // Start transaction
    $wpdb->query('START TRANSACTION');

    // Count customers before deletion
    $customer_count = $wpdb->get_var("SELECT COUNT(*) FROM $customers_table");

    if ($customer_count == 0) {
        $wpdb->query('COMMIT');
        return [
            'success' => true,
            'message' => 'No customers found. Database is already clean.'
        ];
    }

    // Get all customer IDs for related data cleanup
    $customer_ids = $wpdb->get_col("SELECT cust_id FROM $customers_table");
    $customer_ids_str = implode(',', array_map('intval', $customer_ids));

    // Delete waybill items for these customers
    $waybill_items_deleted = 0;
    if (!empty($customer_ids_str)) {
        $waybill_ids = $wpdb->get_col("SELECT id FROM $waybills_table WHERE customer_id IN ($customer_ids_str)");
        if (!empty($waybill_ids)) {
            $waybill_ids_str = implode(',', array_map('intval', $waybill_ids));
            $waybill_items_deleted = $wpdb->query("DELETE FROM $waybill_items_table WHERE waybillno IN ($waybill_ids_str)");
        }
    }

    // Delete waybills for these customers
    $waybills_deleted = 0;
    if (!empty($customer_ids_str)) {
        $waybills_deleted = $wpdb->query("DELETE FROM $waybills_table WHERE customer_id IN ($customer_ids_str)");
    }

    // Delete warehouse tracking for these customers
    $warehouse_waybills_deleted = 0;
    if (!empty($customer_ids_str)) {
        $warehouse_waybills_deleted = $wpdb->query("DELETE FROM $waybills_table WHERE customer_id IN ($customer_ids_str) AND status IN ('pending', 'assigned', 'shipped', 'delivered')");
    }

    // Delete all customers
    $customers_deleted = $wpdb->query("DELETE FROM $customers_table");

    // Reset auto-increment counter
    $wpdb->query("ALTER TABLE $customers_table AUTO_INCREMENT = 1");

    // Reset seeding flag
    delete_option('kit_customers_seeded');

    // Verify deletion
    $remaining_customers = $wpdb->get_var("SELECT COUNT(*) FROM $customers_table");

    if ($remaining_customers == 0) {
        $wpdb->query('COMMIT');
        return [
            'success' => true,
            'message' => "Successfully unseeded customers! Deleted: $customers_deleted customers, $waybills_deleted waybills, $waybill_items_deleted waybill items, $warehouse_waybills_deleted warehouse waybills."
        ];
    } else {
        $wpdb->query('ROLLBACK');
        return [
            'success' => false,
            'message' => "Error: Some customers remain. Transaction rolled back. Remaining: $remaining_customers"
        ];
    }
} catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    return [
        'success' => false,
        'message' => 'Unseeding failed: ' . $e->getMessage()
    ];
}
}

// Function to handle waybill import
function handle_waybill_import()
{
// Include the import script
$import_file = plugin_dir_path(__FILE__) . '../../import_excel_waybills.php';
error_log("Attempting to load import script from: $import_file");

if (!file_exists($import_file)) {
    error_log("Import script file not found: $import_file");
    return [
        'success' => false,
        'message' => 'Import script file not found.'
    ];
}

require_once $import_file;
error_log("Import script loaded successfully");

// Check if file exists
$excel_file = plugin_dir_path(__FILE__) . '../../waybill_excel/Waybills_31-10-2025.xlsx';
error_log("Checking for Excel file at: $excel_file");

if (!file_exists($excel_file)) {
    error_log("Excel file not found: $excel_file");
    return [
        'success' => false,
        'message' => 'Excel file not found. Please ensure the file exists in the waybill_excel folder.'
    ];
}

error_log("Excel file found, starting import...");

try {
    // Run the import
    $importer = new Excel_Waybill_Importer($excel_file);
    $result = $importer->import();

    error_log("Import completed with success: " . ($result['success'] ? 'true' : 'false'));

    if ($result['success']) {
        return [
            'success' => true,
            'message' => 'Waybill import completed successfully!',
            'stats' => $result['stats']
        ];
    } else {
        return [
            'success' => false,
            'message' => $result['message'],
            'stats' => $result['stats']
        ];
    }
} catch (Exception $e) {
    error_log("Import exception: " . $e->getMessage());
    return [
        'success' => false,
        'message' => 'Import failed: ' . $e->getMessage()
    ];
} catch (Error $e) {
    error_log("Import fatal error: " . $e->getMessage());
    return [
        'success' => false,
        'message' => 'Import fatal error: ' . $e->getMessage()
    ];
}
}

/**
 * Clear plugin-related log files and optional sync audit tables.
 *
 * @return array{success:bool, message:string}
 */
function courier_finance_clear_plugin_logs()
{
    $cleared = [];
    $failed = [];

    $try_clear_file = static function (string $path, string $label) use (&$cleared, &$failed): void {
        if ($path === '' || !is_file($path)) {
            return;
        }
        if (!is_writable($path)) {
            $failed[] = $label . ' (not writable)';
            return;
        }
        if (@file_put_contents($path, '') !== false) {
            $cleared[] = $label;
        } else {
            $failed[] = $label . ' (write failed)';
        }
    };

    if (defined('WP_CONTENT_DIR')) {
        $try_clear_file(WP_CONTENT_DIR . '/debug.log', 'wp-content/debug.log');
        $try_clear_file(WP_CONTENT_DIR . '/deactivation-debug.log', 'deactivation-debug.log');
    }

    $plugin_root = defined('COURIER_FINANCE_PLUGIN_PATH')
        ? rtrim(COURIER_FINANCE_PLUGIN_PATH, '/\\')
        : dirname(__FILE__, 3);
    $cursor_dir = $plugin_root . '/.cursor';
    if (is_dir($cursor_dir)) {
        foreach (glob($cursor_dir . '/debug-*.log') ?: [] as $debug_file) {
            $try_clear_file($debug_file, '.cursor/' . basename($debug_file));
        }
    }

    if (class_exists('KIT_Sync_Run_Log')) {
        $sync_clear = KIT_Sync_Run_Log::clear_all();
        if (!empty($sync_clear['success'])) {
            $cleared[] = sprintf(
                'sync audit (%d runs, %d rows)',
                (int) ($sync_clear['runs_deleted'] ?? 0),
                (int) ($sync_clear['rows_deleted'] ?? 0)
            );
        }
    }

    if (empty($cleared) && empty($failed)) {
        return [
            'success' => true,
            'message' => 'No log files or sync audit rows found to clear.',
        ];
    }

    $message = empty($cleared) ? '' : ('Cleared: ' . implode('; ', $cleared));
    if (!empty($failed)) {
        $message .= ($message !== '' ? ' ' : '') . 'Could not clear: ' . implode('; ', $failed);
    }

    return [
        'success' => empty($failed) || !empty($cleared),
        'message' => trim($message),
    ];
}

// Wipe all plugin data tables (but preserve settings and reference data)
function handle_wipe_tables()
{
global $wpdb;

try {
    // Drop foreign keys first to avoid TRUNCATE/DELETE issues
    require_once(__DIR__ . '/../class-database.php');
    Database::drop_legacy_foreign_keys();

    // Disable foreign key checks temporarily
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

    // Tables to wipe (data tables that are seeded)
    // Order matters: wipe child tables first, then parent tables.
    // kit_waybills.delivery_id -> kit_deliveries.id: must wipe waybills (and dependents) before deliveries.
    $tables_to_wipe = [
        'kit_waybill_items',  // References waybills
        'kit_quotations',     // References waybills + deliveries
        'kit_invoices',       // References waybills
        'kit_waybills',       // References customers + deliveries
        'kit_deliveries',     // References drivers; wipe after waybills
        'kit_company_customers',
        'kit_customers',
        'kit_drivers',
    ];

    $wiped_count = 0;
    $errors = [];

    foreach ($tables_to_wipe as $table_name) {
        // Some hosts reset FK checks between statements; keep deletes reliable.
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

        $full_table_name = $wpdb->prefix . $table_name;

        // Check if table exists (case-insensitive: some MySQL setups vary table_name casing)
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND LOWER(table_name) = LOWER(%s)",
            DB_NAME,
            $full_table_name
        ));

        if ($table_exists > 0) {
            // Use DELETE instead of TRUNCATE (works better with foreign keys disabled)
            // DELETE works even when foreign keys exist (with checks disabled)
            $result = $wpdb->query("DELETE FROM `{$full_table_name}`");

            if ($result === false) {
                $errors[] = "Failed to wipe {$table_name}: " . ($wpdb->last_error ?: 'Unknown error');
            } else {
                // Reset auto-increment counter
                $wpdb->query("ALTER TABLE `{$full_table_name}` AUTO_INCREMENT = 1");
                $wiped_count++;
            }
        }
    }

    // Re-enable foreign key checks
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

    // Reset seeding flags
    delete_option('kit_customers_seeded');

    if (!empty($errors)) {
        return [
            'success' => false,
            'message' => 'Wiped ' . $wiped_count . ' tables, but encountered errors: ' . implode('; ', $errors),
            'stats' => ['wiped' => $wiped_count, 'errors' => $errors]
        ];
    }

    return [
        'success' => true,
        'message' => 'Successfully wiped ' . $wiped_count . ' data tables. You can now run Setup Seed.',
        'stats' => ['wiped' => $wiped_count]
    ];
} catch (Exception $e) {
    // Re-enable foreign key checks even on error
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    return [
        'success' => false,
        'message' => 'Wipe tables failed: ' . $e->getMessage()
    ];
}
}

/**
 * Run Google Sheet seed with provided rows. Used by handle_setup_seed_from_google_sheet and simulation.
 *
 * @deprecated 2026-05-19 Superseded by KIT_Waybill_Seeder::run() (see
 *   includes/sync/class-kit-waybill-seeder.php and the Sync Runs admin page).
 *   The new pipeline is idempotent on waybill_no, respects field ownership
 *   (only sheet-owned columns are touched on update), logs every row to
 *   wp_kit_sync_run_rows for queryable audit, and supports shadow runs.
 *
 *   This function is kept alive intentionally as the legacy fallback during
 *   cutover. DO NOT call it from new code. The "Setup Seed" admin action and
 *   pull_all('waybills') in class-google-sheets-sync.php still depend on it
 *   today; remove those last after KIT_Waybill_Seeder has run in production
 *   long enough to be trusted (suggested: 2 weeks of clean runs).
 *
 *   Cutover plan:
 *     1. Run KIT_Waybill_Seeder in SHADOW mode (default) for a week. Compare
 *        wp_kit_sync_run_rows diffs against what run_google_sheet_seed would
 *        produce on the same data.
 *     2. Flip Sync Runs admin → Shadow OFF (production writes).
 *     3. Watch wp_kit_sync_runs for one week of clean production runs.
 *     4. Disable the Setup Seed admin action that calls this function.
 *     5. Delete this function and its helpers.
 *
 * @param array $rows Two-dimensional array: first row is header, rest are data
 * @param bool  $simulate If true, wrap in transaction and rollback (no data committed)
 * @param array<int, array<int, string>>|null $kit_deliveries_sheet_rows Optional kit_deliveries tab for truck id → reference alignment
 * @param array<string, mixed>|null $waybills_source_lookup Optional Waybills-tab lookup (by waybill_no) when kit_waybills.cust_name_ignore is blank
 * @param array<string, mixed>       $options Optional: seed_started_at
 * @return array{success:bool,message:string,stats?:array}
 */
function run_google_sheet_seed(array $rows, $simulate = false, array $customer_id_map = [], ?array $kit_deliveries_sheet_rows = null, ?array $waybills_source_lookup = null, array $options = [])
{
global $wpdb;

kit_seed_extend_runtime_limits();

$seed_started_at = isset($options['seed_started_at']) ? (float) $options['seed_started_at'] : microtime(true);

if (empty($rows) || count($rows) < 2) {
    return ['success' => false, 'message' => 'Sheet has no data or invalid headers.'];
}

if ($simulate) {
    $wpdb->query('START TRANSACTION');
}

try {
    $header = array_map(function ($c) {
        $h = strtolower(trim((string) $c));
        $h = str_replace(' ', '_', $h);
        return $h;
    }, $rows[0]);
    $col = [];
    foreach ($header as $i => $h) {
        if ($h !== '') {
            $col[$h] = $i;
        }
    }
    $get = function ($row, $key, $default = '') use ($col) {
        $keys = is_array($key) ? $key : [$key];
        foreach ($keys as $k) {
            $idx = $col[$k] ?? null;
            if ($idx !== null) {
                $v = $row[$idx] ?? $default;
                $v = trim((string) $v);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return $default;
    };
    $is_placeholder = function ($value): bool {
        $v = strtolower(trim((string) $value));
        return $v === '' || in_array($v, ['0', 'null', 'n/a', 'na', 'none', '-', '--'], true);
    };
    $is_private_placeholder = function ($value): bool {
        $v = strtolower(trim((string) $value));
        return in_array($v, ['private', 'individual'], true);
    };
    $normalize_customer_label = function (string $label) use ($is_placeholder): string {
        $s = trim((string) $label);
        if ($s === '') {
            return '';
        }
        // Some sheets accidentally prefix the customer label with numeric junk (e.g. "0 Thielke", "8633 Branden").
        // Strip leading numeric tokens so we don't seed name="0".
        $s = preg_replace('/^\s*\d+\s+/', '', $s);
        // Client-type labels are not part of the customer name ("Individual Arusha", "Private Smith").
        $s = preg_replace('/^\s*(individual|private)\s+/i', '', $s);
        $s = trim((string) $s);
        if (in_array(strtolower($s), ['individual', 'private'], true)) {
            $s = '';
        }
        return $is_placeholder($s) ? '' : $s;
    };
    $apply_sheet_name_to_customer = function (int $cust_id, string $sheet_name) use ($wpdb, $is_placeholder, $is_private_placeholder, $normalize_customer_label): void {
        $customers_table = $wpdb->prefix . 'kit_customers';
        $sheet_name = trim((string) $sheet_name);
        if ($cust_id <= 0 || $sheet_name === '' || $is_private_placeholder($sheet_name)) {
            return;
        }
        $sheet_name = $normalize_customer_label($sheet_name);
        if ($sheet_name === '') {
            return;
        }

        $customer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.id, c.name, c.surname, c.company_id, co.company_name
                 FROM {$customers_table} c
                 LEFT JOIN {$wpdb->prefix}kit_company_customers co ON c.company_id = co.company_id
                 WHERE c.cust_id = %d LIMIT 1",
                $cust_id
            ),
            ARRAY_A
        );
        if (empty($customer)) {
            return;
        }

        $curr_name = trim((string) ($customer['name'] ?? ''));
        $curr_surname = trim((string) ($customer['surname'] ?? ''));
        $curr_company = trim((string) ($customer['company_name'] ?? ''));

        // Only backfill when the person name is missing/placeholder.
        // An empty surname alone must NOT rewrite an existing person (e.g. Tinus
        // with company "Rsc Industrial") into the waybill label "Rsc Industrial".
        $needs_update = (
            $is_placeholder($curr_name) ||
            strtolower($curr_name) === 'individual' ||
            in_array(strtolower($curr_company), ['individual', 'private'], true)
        );

        if (!$needs_update) {
            return;
        }

        $parts = preg_split('/\s+/', $sheet_name, 2);
        $first = trim((string) ($parts[0] ?? ''));
        $last = trim((string) ($parts[1] ?? ''));

        $normalized = kit_seed_normalize_customer_row($first, $last, $curr_company, '');
        if (!empty($normalized['skip'])) {
            return;
        }
        $first = $normalized['name'];
        $last = $normalized['surname'];
        $resolved_company = $normalized['company_name'];

        // Recover rows where a prior seed stored name="Individual" and the real label is the surname.
        if (strtolower($curr_name) === 'individual' && !$is_placeholder($curr_surname)) {
            if ($last === '' || strcasecmp($first, $curr_surname) === 0) {
                $first = '';
                $last = $curr_surname;
            }
        }

        $looks_like_business = $resolved_company !== '' || kit_seed_customer_looks_like_business($sheet_name);
        if (!$looks_like_business && kit_seed_customer_is_company_suffix_only($first)) {
            $looks_like_business = true;
            $sheet_name = trim($first . ' ' . $last);
            $resolved_company = kit_seed_customer_looks_like_business($sheet_name) ? $sheet_name : $resolved_company;
        }
        if (!$looks_like_business && ($last === '' || strlen($sheet_name) <= 24)) {
            // Personal-style name: prefer first/surname columns.
            $payload = [
                'name' => $first !== '' ? $first : ($is_placeholder($curr_name) ? '' : $curr_name),
                'surname' => $last !== '' ? $last : ($is_placeholder($curr_surname) ? '' : $curr_surname),
            ];
        } else {
            $payload = [
                'name' => $is_placeholder($curr_name) ? '' : $curr_name,
                'surname' => $is_placeholder($curr_surname) ? '' : $curr_surname,
            ];
            $biz_label = $resolved_company !== '' ? $resolved_company : $sheet_name;
            if ($biz_label !== '' && class_exists('KIT_Company_Customers')) {
                $linked = (int) KIT_Company_Customers::ensure_company($biz_label);
                if ($linked > 0) {
                    $payload['company_id'] = $linked;
                }
            }
        }

        if (kit_seed_customer_field_is_placeholder($payload['name'] ?? '') && ($payload['surname'] ?? '') === '' && empty($payload['company_id'])) {
            return;
        }

        $formats = array_fill(0, count($payload), '%s');
        if (isset($payload['company_id'])) {
            $formats[array_search('company_id', array_keys($payload), true)] = '%d';
        }
        $wpdb->update(
            $customers_table,
            $payload,
            ['cust_id' => $cust_id],
            $formats,
            ['%d']
        );
    };
    // Separator-aware — see includes/sync/kit-seed-number-parse.php. The sheet
    // sends FORMATTED text, so a currency cell arrives as "R 1 200,50": the old
    // is_numeric() guard rejected it and silently substituted the default (0),
    // while the digit-strip fallback would have produced 120050.
    $getNum = function ($row, $key, $default = 0) use ($get) {
        $v = $get($row, $key, '');
        if ($v === '') {
            return (float) $default;
        }
        return function_exists('kit_parse_sheet_decimal')
            ? kit_parse_sheet_decimal($v, (float) $default)
            : (is_numeric($v) ? (float) $v : (float) $default);
    };
    $getInt = function ($row, $key, $default = 0) use ($get) {
        $v = $get($row, $key, '');
        if ($v === '') {
            return (int) $default;
        }
        return function_exists('kit_parse_sheet_int')
            ? kit_parse_sheet_int($v, (int) $default)
            : (is_numeric($v) ? (int) $v : (int) $default);
    };

    // Default attribution for seeded records when sheet doesn't provide user ids.
    $seed_owner_user_id = function_exists('kit_get_seed_owner_user_id')
        ? (int) kit_get_seed_owner_user_id()
        : max(1, (int) get_current_user_id());
    if ($seed_owner_user_id <= 0) {
        $seed_owner_user_id = 1;
    }
    $resolve_wp_user_id = function ($raw) use ($wpdb): int {
        return function_exists('kit_resolve_wp_user_id')
            ? kit_resolve_wp_user_id($raw)
            : 0;
    };

    $prefix = $wpdb->prefix;
    $drivers_t = $prefix . 'kit_drivers';
    $customers_t = $prefix . 'kit_customers';
    $waybills_t = $prefix . 'kit_waybills';
    $waybill_items_t = $prefix . 'kit_waybill_items';
    $cities_t = $prefix . 'kit_operating_cities';

    $existing_waybill_map = [];
    foreach ($wpdb->get_results("SELECT id, waybill_no FROM {$waybills_t}", ARRAY_A) ?: [] as $wb_row) {
        $existing_waybill_map[(string) ($wb_row['waybill_no'] ?? '')] = (int) ($wb_row['id'] ?? 0);
    }
    $city_by_name = [];
    $city_country_map = [];
    foreach ($wpdb->get_results("SELECT id, city_name, country_id FROM {$cities_t}", ARRAY_A) ?: [] as $city_row) {
        $name_key = strtolower(trim((string) ($city_row['city_name'] ?? '')));
        if ($name_key !== '') {
            $city_by_name[$name_key] = (int) ($city_row['id'] ?? 0);
        }
        $city_country_map[(int) ($city_row['id'] ?? 0)] = (int) ($city_row['country_id'] ?? 1);
    }
    $customer_cust_id_set = [];
    foreach ($wpdb->get_col("SELECT cust_id FROM {$customers_t}") ?: [] as $cust_id_val) {
        $customer_cust_id_set[(int) $cust_id_val] = true;
    }

    kit_seed_update_progress('preparing', 0, max(0, count($rows) - 1));

    $driverNames = [];
    $stats = ['drivers' => 0, 'customers' => 0, 'deliveries' => 0, 'waybills' => 0, 'waybill_items' => 0, 'skipped' => 0, 'errors' => []];

    foreach (array_slice($rows, 1) as $row) {
        $waybill_no = $get($row, ['parcel_id', 'waybill_no', 'wb_no', 'waybill', 'waybill_#', 'newwb', 'parcel_no', 'no', 'number'], '');
        if ($waybill_no === '') {
            $parcel = $get($row, ['parcel', 'parcel_number', 'waybill_#', 'newwb'], '');
            if (preg_match('/WB[:\s-]*(\d+)/i', $parcel, $m)) {
                $waybill_no = $m[1];
            } elseif (preg_match('/^(\d{4,})/i', $parcel, $m)) {
                $waybill_no = $m[1];
            } elseif ($parcel !== '' && is_numeric(preg_replace('/[^0-9]/', '', $parcel))) {
                $waybill_no = preg_replace('/[^0-9]/', '', $parcel);
            }
        }
        if ($waybill_no === '') {
            $stats['skipped']++;
            continue;
        }

        $driverName = $get($row, ['driver', 'driver_name', 'truck_driver'], '');
        if ($driverName === '' || is_numeric($driverName) || kit_seed_is_non_driver_name($driverName)) {
            continue;
        }
        $driverNames[$driverName] = true;
    }

    $driverIds = [];
    foreach ($wpdb->get_results("SELECT id, name FROM {$drivers_t}", ARRAY_A) ?: [] as $d) {
        $driverIds[$d['name']] = (int) $d['id'];
    }

    $drivers_already_seeded = count($driverIds) > 0;
    if (!$drivers_already_seeded) {
        foreach (array_keys($driverNames) as $harvestedDriverName) {
            if (isset($driverIds[$harvestedDriverName]) && $driverIds[$harvestedDriverName] > 0) {
                continue;
            }
            $wpdb->insert($drivers_t, ['name' => $harvestedDriverName, 'is_active' => 1], ['%s', '%d']);
            if ($wpdb->insert_id) {
                $driverIds[$harvestedDriverName] = (int) $wpdb->insert_id;
                $stats['drivers']++;
            }
        }
    }

    // Deliveries come from the kit_deliveries tab (imported before this runs).
    // Waybills only reference them — never create trips here.
    $delivery_cache = class_exists('KIT_Sheet_Delivery_Resolve')
        ? KIT_Sheet_Delivery_Resolve::build_cache($kit_deliveries_sheet_rows)
        : ['warehouse_fallback_delivery_id' => 0, 'ref_to_delivery_id' => [], 'valid_delivery_ids' => [], 'sheet_ref_by_id' => []];

    $waybills_lookup = is_array($waybills_source_lookup) ? $waybills_source_lookup : ['by_waybill' => []];
    $customer_registry = kit_seed_build_customer_dedupe_registry(
        $rows,
        $col,
        $waybills_lookup,
        $get,
        $normalize_customer_label
    );
    if (!empty($customer_registry)) {
        kit_seed_prefill_customers_from_registry($customer_registry, $wpdb, $customers_t, $stats);
        $stats['customers_registry'] = count($customer_registry);
        $stats['customers'] = count($customer_registry);
    }
    if (!$simulate && class_exists('KIT_Customers')) {
        $stats['customers_name_repaired'] = KIT_Customers::repair_customer_name_company_fields();
    }

    $data_rows = array_slice($rows, 1);
    $total_data_rows = count($data_rows);
    kit_seed_update_progress('waybills', 0, $total_data_rows);

    $row_index = 0;
    foreach ($data_rows as $row) {
        $row_index++;
        if ($row_index === 1 || $row_index % 25 === 0 || $row_index === $total_data_rows) {
            kit_seed_update_progress('waybills', $row_index, $total_data_rows);
        }
        // parcel_id IS the waybill_no in the sheet; check it first
        $waybill_no = $get($row, ['parcel_id', 'waybill_no', 'wb_no', 'waybill', 'waybill_#', 'newwb', 'parcel_no', 'no', 'number'], '');
        if ($waybill_no === '') {
            $parcel = $get($row, ['parcel', 'parcel_number', 'waybill_#', 'newwb'], '');
            if (preg_match('/WB[:\s-]*(\d+)/i', $parcel, $m)) {
                $waybill_no = $m[1];
            } elseif (preg_match('/^(\d{4,})/i', $parcel, $m)) {
                $waybill_no = $m[1];
            } elseif ($parcel !== '' && is_numeric(preg_replace('/[^0-9]/', '', $parcel))) {
                $waybill_no = preg_replace('/[^0-9]/', '', $parcel);
            }
        }
        if ($waybill_no === '') {
            continue;
        }

        $sheet_created_at_raw = $get($row, ['created_at', 'date_received', 'date_recieved', 'received_date'], '');
        $sheet_created_at = kit_seed_normalize_created_at($sheet_created_at_raw);

        // Resolve delivery from kit_deliveries tab (trip id → DEL-ref → DB id).
        $force_warehouse = false;
        $delivery_id = 0;
        if (class_exists('KIT_Sheet_Delivery_Resolve')) {
            $resolved = KIT_Sheet_Delivery_Resolve::resolve($row, $col, $delivery_cache);
            $delivery_id = (int) ($resolved['delivery_id'] ?? 0);
            $force_warehouse = !empty($resolved['force_warehouse']);
        }
        if ($delivery_id === 0) {
            $stats['skipped']++;
            $stats['errors'][] = "WB {$waybill_no}: no delivery and no system warehouse delivery (delivery_reference='pending') found — run plugin activation/migration to create it";
            continue;
        }

        $custName = $normalize_customer_label($get($row, ['cust_name_ignore', 'cust_name_ig', 'customer', 'cust_name', 'client'], ''));
        $source_contact = null;
        if (!empty($waybills_source_lookup['by_waybill']) && is_array($waybills_source_lookup['by_waybill'])) {
            $wb_key = kit_seed_normalize_waybill_no_key((string) $waybill_no);
            if ($wb_key !== '') {
                $source_contact = $waybills_source_lookup['by_waybill'][$wb_key] ?? null;
            }
        }
        if ($custName === '' && is_array($source_contact) && !empty($source_contact['customer'])) {
            $custName = $normalize_customer_label((string) $source_contact['customer']);
        } elseif ($custName === '' && is_array($source_contact)) {
            $custName = $normalize_customer_label(kit_seed_pick_customer_label_from_source_row($source_contact));
        }
        $customer_dedupe_key = kit_seed_customer_dedupe_key($custName);
        $sheet_customer_id = $getInt($row, 'customer_id', 0);
        $customer_id = $sheet_customer_id;
        $seed_company_id = 0;
        $party_id_from_sheet = $sheet_customer_id > 0;
        // kit_waybills may stamp a customer_id that is not on kit_customers
        // (8860 "Gaia Ltd"). That id must not invent a 110th company.
        if ($sheet_customer_id > 0 && !isset($customer_cust_id_set[$sheet_customer_id])) {
            $customer_id = 0;
            $party_id_from_sheet = false;
        }

        if ($customer_id <= 0 && $customer_dedupe_key !== '' && isset($customer_registry[$customer_dedupe_key])) {
            $customer_id = (int) $customer_registry[$customer_dedupe_key]['cust_id'];
        }
        if ($customer_id > 0 && !isset($customer_cust_id_set[$customer_id])) {
            $customer_id = 0;
            $party_id_from_sheet = false;
        }

        $exists_by_cust_id = 0;
        if ($customer_id > 0) {
            $exists_by_cust_id = isset($customer_cust_id_set[$customer_id]) ? 1 : 0;
            // Some sheets store customer_id as kit_customers.id, not kit_customers.cust_id.
            if ($exists_by_cust_id === 0 && isset($customer_id_map[$customer_id])) {
                $customer_id = (int) $customer_id_map[$customer_id];
                $exists_by_cust_id = isset($customer_cust_id_set[$customer_id]) ? 1 : 0;
            }
        }

        // Resolve company party from sheet customer_id (preferred) or business label when not an individual.
        // Person + Company (Waybills F + I): keep person customer_id and also set company_id.
        $company_label = $get($row, ['company_name', 'company'], '');
        if ($company_label === '' && is_array($source_contact) && !empty($source_contact['company'])) {
            $company_label = trim((string) $source_contact['company']);
        }
        if (function_exists('kit_seed_strip_private_company_label')) {
            $company_label = kit_seed_strip_private_company_label($company_label);
        }
        if (class_exists('KIT_Company_Customers')) {
            // Only treat sheet customer_id as a company key when there is NO person row with
            // that cust_id. Mixed contacts (Bianca 8739 + Selous company_id 8739) must not
            // override an explicit Waybills Company column (e.g. Tilke Investments on 5165).
            //
            // $party_id_from_sheet is what keeps this honest. When the sheet
            // leaves customer_id blank the registry invents a number, and
            // looking that number up here matched whichever company happened to
            // hold it — waybill 5769 ("Epcm Tanzania") was billed to Rhino
            // Lodge, and to a different company again on the next run.
            $company_by_party_id = ($customer_id > 0 && $party_id_from_sheet && $exists_by_cust_id === 0)
                ? KIT_Company_Customers::find_by_company_id($customer_id)
                : null;
            // cust_id and company_id are numbered from one sequence, so the id
            // matching is not enough on its own: waybill 5335 carries customer
            // 8672 "Huzaifa Kudrati" and company 8672 is "Terra Africa". The
            // waybill's own labels have to agree before the company takes over.
            if ($company_by_party_id && !kit_seed_label_matches_company($company_by_party_id, $custName, $company_label)) {
                $company_by_party_id = null;
            }
            if ($company_by_party_id) {
                $seed_company_id = $customer_id;
                $customer_id = 0;
            } elseif (
                $exists_by_cust_id === 0
                && $custName !== ''
                && function_exists('kit_seed_customer_looks_like_business')
                && kit_seed_customer_looks_like_business($custName)
            ) {
                // Company-only label with no individual row. Prefer a sheet company
                // or an existing person (Waybills Company "Laba Laba" → Laba Laba Gaia)
                // over inventing a 110th company the kit_company_customers tab does not list.
                $existing_co = KIT_Company_Customers::find_by_name($custName);
                if ($existing_co) {
                    $seed_company_id = (int) $existing_co['company_id'];
                    $customer_id = 0;
                } else {
                    $fallback_cust = 0;
                    if ($company_label !== '' && function_exists('kit_seed_find_customer_id_by_display_label')) {
                        $fallback_cust = kit_seed_find_customer_id_by_display_label($company_label);
                    }
                    if ($fallback_cust <= 0 && function_exists('kit_seed_find_customer_id_by_display_label')) {
                        $fallback_cust = kit_seed_find_customer_id_by_display_label($custName);
                    }
                    if ($fallback_cust > 0) {
                        $customer_id = $fallback_cust;
                        $customer_cust_id_set[$customer_id] = true;
                        $exists_by_cust_id = 1;
                        $linked_id = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT company_id FROM {$customers_t} WHERE cust_id = %d LIMIT 1",
                            $customer_id
                        ));
                        if ($linked_id > 0 && KIT_Company_Customers::find_by_company_id($linked_id)) {
                            $seed_company_id = $linked_id;
                        }
                    } else {
                        $preferred_co = 0;
                        if ($sheet_customer_id > 0 && KIT_Company_Customers::find_by_company_id($sheet_customer_id)) {
                            $preferred_co = $sheet_customer_id;
                        }
                        $seed_company_id = (int) KIT_Company_Customers::ensure_company($custName, [], $preferred_co);
                        $customer_id = 0;
                    }
                }
            } elseif (
                $seed_company_id <= 0
                && $company_label !== ''
                && function_exists('kit_seed_customer_looks_like_business')
                && kit_seed_customer_looks_like_business($company_label)
            ) {
                // Per-waybill company (person stays on customer_id). Do NOT stamp
                // kit_customers.company_id here — affiliation comes from kit_customers sheet
                // only. Stamping caused Roxanne's Private/blank WBs to inherit Uniques.
                $seed_company_id = (int) KIT_Company_Customers::ensure_company($company_label, []);
            }

            // kit_waybills often has no company_name text (and Waybills source may be #REF!).
            // Inherit company from the person row's company_id FK.
            if ($seed_company_id <= 0 && $customer_id > 0 && $exists_by_cust_id > 0) {
                $linked_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT company_id FROM {$customers_t} WHERE cust_id = %d LIMIT 1",
                    $customer_id
                ));
                if ($linked_id > 0 && KIT_Company_Customers::find_by_company_id($linked_id)) {
                    $seed_company_id = $linked_id;
                }
            }
        }

        if ($customer_id > 0 && $exists_by_cust_id === 0 && $seed_company_id <= 0) {
            // #region agent log
            {
                $dbg = [
                    'sessionId' => '3f0725',
                    'runId' => 'blank-cust',
                    'hypothesisId' => 'E',
                    'location' => 'settings.php:run_google_sheet_seed:ensure_stub',
                    'message' => 'about to ensure customer stub from waybill',
                    'data' => [
                        'waybill_no' => (string) $waybill_no,
                        'customer_id' => $customer_id,
                        'custName' => $custName,
                        'sheet_customer_id' => $sheet_customer_id,
                        'seed_company_id' => $seed_company_id,
                    ],
                    'timestamp' => (int) round(microtime(true) * 1000),
                ];
                @file_put_contents(
                    (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-3f0725.log',
                    json_encode($dbg) . "\n",
                    FILE_APPEND
                );
            }
            // #endregion
            // kit_customers tab may be empty — create stub from waybill customer_id + label.
            $customer_id = kit_seed_ensure_customer_exists($wpdb, $customers_t, $customer_id, $custName, $stats, $company_label);
            if ($customer_id > 0) {
                $customer_cust_id_set[$customer_id] = true;
                $exists_by_cust_id = 1;
            }
        } elseif ($customer_id <= 0 && $seed_company_id <= 0 && $customer_dedupe_key !== '' && isset($customer_registry[$customer_dedupe_key])) {
            $customer_id = (int) $customer_registry[$customer_dedupe_key]['cust_id'];
        }
        if ($customer_id > 0 && is_array($source_contact)) {
            kit_seed_apply_source_contact_to_customer($customer_id, $source_contact, $custName);
        } elseif ($customer_id > 0 && $custName !== '' && !$is_private_placeholder($custName)) {
            $apply_sheet_name_to_customer($customer_id, $custName);
        }

        // If the seed sheet includes customer contact columns, backfill them onto existing customers
        // (only when the DB fields are currently blank).
        if ($customer_id > 0) {
            $seed_cell = $get($row, ['cell', 'cellphone', 'mobile', 'phone'], '');
            $seed_tel = $get($row, ['telephone', 'tel', 'landline'], '');
            $seed_email = $get($row, ['email_address', 'email'], '');
            $seed_address = $get($row, ['address', 'physical_address', 'postal_address'], '');
            if (is_array($source_contact)) {
                if ($seed_cell === '' && !empty($source_contact['cell'])) {
                    $seed_cell = trim((string) $source_contact['cell']);
                }
                if ($seed_tel === '' && !empty($source_contact['telephone'])) {
                    $seed_tel = trim((string) $source_contact['telephone']);
                }
                if ($seed_email === '' && !empty($source_contact['email'])) {
                    $seed_email = trim((string) $source_contact['email']);
                }
                if ($seed_address === '' && !empty($source_contact['address'])) {
                    $seed_address = trim((string) $source_contact['address']);
                }
            }
            if ($seed_cell !== '' || $seed_tel !== '' || $seed_email !== '' || $seed_address !== '') {
                $existing_customer = $wpdb->get_row(
                    $wpdb->prepare("SELECT cell, telephone, email_address, address FROM {$customers_t} WHERE cust_id = %d LIMIT 1", $customer_id),
                    ARRAY_A
                );
                if (!empty($existing_customer)) {
                    $update_customer = [];
                    if ($seed_cell !== '' && trim((string) ($existing_customer['cell'] ?? '')) === '') {
                        $update_customer['cell'] = $seed_cell;
                    }
                    if ($seed_tel !== '' && trim((string) ($existing_customer['telephone'] ?? '')) === '') {
                        $update_customer['telephone'] = $seed_tel;
                    }
                    if ($seed_email !== '' && trim((string) ($existing_customer['email_address'] ?? '')) === '') {
                        $update_customer['email_address'] = $seed_email;
                    }
                    if ($seed_address !== '' && trim((string) ($existing_customer['address'] ?? '')) === '') {
                        $update_customer['address'] = $seed_address;
                    }
                    if (!empty($update_customer)) {
                        $wpdb->update(
                            $customers_t,
                            $update_customer,
                            ['cust_id' => $customer_id],
                            array_fill(0, count($update_customer), '%s'),
                            ['%d']
                        );
                    }
                }
            }
        }

        if ($customer_id === 0 && $seed_company_id <= 0) {
            // Allow unassigned waybills (Private / blocked blank stub) instead of skipping the row
            // or creating a nameless individual. Keep a soft warning for diagnostics.
            if ($sheet_customer_id > 0) {
                $stats['errors'][] = "WB {$waybill_no}: customer_id {$sheet_customer_id} left unassigned (no person/company stub created)";
            } elseif ($custName !== '') {
                $stats['errors'][] = "WB {$waybill_no}: no customer match for label \"{$custName}\" — left unassigned";
            }
            $stats['unassigned_waybills'] = (int) ($stats['unassigned_waybills'] ?? 0) + 1;
        }

        $existing_waybill_id = (int) ($existing_waybill_map[(string) $waybill_no] ?? 0);

        $cityName = $get($row, ['city_name_ignore', 'city', 'city_name', 'destination_city'], '');
        if ($cityName === '' && !empty($waybills_lookup['by_waybill'])) {
            $wb_key_for_city = kit_seed_normalize_waybill_no_key((string) $waybill_no);
            $source_city = trim((string) (($waybills_lookup['by_waybill'][$wb_key_for_city]['city'] ?? '')));
            if ($source_city !== '') {
                $cityName = $source_city;
            }
        }
        $city_id = 0;
        if ($cityName !== '') {
            $city_id = (int) ($city_by_name[strtolower(trim($cityName))] ?? 0);
        }
        if ($city_id === 0) {
            $city_id = $getInt($row, 'city_id', 0);
        }

        $direction_id = $getInt($row, 'direction_id', 0);
        if ($direction_id <= 0) {
            $direction_id = 2;
        }
        if (class_exists('KIT_Routes')) {
            $reconciled = KIT_Routes::reconcile_delivery_route((int) $direction_id, (int) $city_id);
            $direction_id = (int) $reconciled['direction_id'];
            $city_id = (int) $reconciled['destination_city_id'];
        } elseif ($city_id === 0) {
            $city_id = 6;
        }

        $dest_country_id = 1;
        if ($city_id > 0) {
            $dest_country_id = (int) ($city_country_map[$city_id] ?? 1);
            if ($dest_country_id <= 0) {
                $dest_country_id = 1;
            }
        }

        // VAT: only TRUE/1/YES-style values count. Legacy single-column "vat" may be SAD500/SADC (not VAT).
        $vat_include_raw = $get($row, ['vat_include', 'vat_included'], '');
        $legacy_vat_raw = strtoupper(trim($get($row, ['vat', 'vat_legacy'], '')));
        if ($vat_include_raw !== '') {
            $vatFlag = kit_google_sheet_parse_bool_int($vat_include_raw);
        } elseif (in_array($legacy_vat_raw, ['SAD500', 'SADC'], true)) {
            $vatFlag = 0;
        } elseif ($legacy_vat_raw !== '') {
            $vatFlag = kit_google_sheet_parse_bool_int($legacy_vat_raw);
        } else {
            $vatFlag = 0;
        }

        // SAD500 / SADC: read dedicated columns (export layout) with legacy vat-cell fallback.
        $sad500_raw = $get($row, ['include_sad500', 'include_sad_500', 'sad500', 'sad_500'], '');
        $include_sad500 = ($sad500_raw !== '')
            ? kit_google_sheet_parse_bool_int($sad500_raw)
            : (($legacy_vat_raw === 'SAD500') ? 1 : 0);

        $sadc_raw = $get($row, ['include_sadc', 'sadc_included', 'sadc'], '');
        $include_sadc = ($sadc_raw !== '')
            ? kit_google_sheet_parse_bool_int($sadc_raw)
            : (($legacy_vat_raw === 'SADC') ? 1 : 0);

        // Same rule as KIT_Waybills::save_or_update_waybill — VAT and SADC do not apply together.
        if ($vatFlag === 1) {
            $include_sadc = 0;
        }

        // Per client (Mel Ltd, 2026-05-19): always pull the WAYBILL description into the
        // waybill's description column — never the item description. We removed
        // 'item_description' from the fallback chain so that a stray "Item description"
        // column in the source sheet can no longer overwrite the waybill description.
        $description = $get($row, ['description', 'waybill_description'], '');
        $parcel_desc = $get($row, ['parcel_id', 'parcel', 'parcel_number', 'waybill_#', 'newwb'], '');
        $item_len = $getNum($row, ['item_length', 'length'], 0);
        $item_w = $getNum($row, ['item_width', 'width'], 0);
        $item_h = $getNum($row, ['item_height', 'height'], 0);
        $total_mass = $getNum($row, ['total_mass_kg', 't_mass'], 0);
        $total_vol = $getNum($row, ['total_volume', 't_volume'], 0);
        $mass_charge = $getNum($row, ['mass_charge', 'mass_cost'], 0);
        $vol_charge = $getNum($row, ['volume_charge', 'vol_cost'], 0);
        // Empty basis must stay empty so kit_seed_total_from_charge_basis uses max(mass, vol).
        // Never invent MASS/VOLUME when AB is blank. Clear inverted MASS/VOLUME.
        $charge_basis_raw = $get($row, ['charge_basis', 'basis'], '');
        $charge_basis = function_exists('kit_seed_sanitize_inverted_charge_basis')
            ? kit_seed_sanitize_inverted_charge_basis((string) $charge_basis_raw, (float) $mass_charge, (float) $vol_charge)
            : (function_exists('kit_seed_normalize_charge_basis')
                ? kit_seed_normalize_charge_basis((string) $charge_basis_raw)
                : strtolower(trim((string) $charge_basis_raw)));
        // kit_waybills AB (charge_basis) picks Z mass vs AA volume as billed freight total.
        $freight_total = function_exists('kit_seed_total_from_charge_basis')
            ? kit_seed_total_from_charge_basis((float) $mass_charge, (float) $vol_charge, (string) $charge_basis)
            : (float) max($mass_charge, $vol_charge);
        // Seed-time starting value only — the freight component, never the sheet's
        // product_invoice_amount / Customer Inv (parcels), because a MASS basis with
        // mass_charge=0 must store 0 rather than the parcels total. The billed total
        // (freight + VAT + fees) is derived from it after the row loop.
        $product_invoice_amount = (float) $freight_total;
        $warehouse = kit_google_sheet_parse_bool_int($get($row, 'warehouse', '0'));
        if ($force_warehouse) {
            // Routed to the system warehouse delivery because we couldn't resolve a real truck.
            $warehouse = 1;
        }

        $item_desc_for_lines = $get($row, ['item_description', 'item_desc', 'item description'], '');
        $cust_inv_r = $getNum($row, ['customer_inv(_r)', 'customer_inv(r)', 'customer_inv', 'cust_inv', 'custinvr'], 0);
        // Prefer Waybills-tab item description when kit_waybills only has waybill-level text.
        if (is_array($waybills_source_lookup) && !empty($waybills_source_lookup['by_waybill'])) {
            $wb_key_items = function_exists('kit_seed_normalize_waybill_no_key')
                ? kit_seed_normalize_waybill_no_key((string) $waybill_no)
                : (string) $waybill_no;
            $src_wb = $waybills_source_lookup['by_waybill'][$wb_key_items] ?? [];
            if ($item_desc_for_lines === '' && !empty($src_wb['item_description'])) {
                $item_desc_for_lines = (string) $src_wb['item_description'];
            }
            if ($cust_inv_r <= 0 && !empty($src_wb['customer_inv_r'])) {
                $cust_inv_r = (float) $src_wb['customer_inv_r'];
            }
        }
        if ($item_desc_for_lines === '') {
            $item_desc_for_lines = $description ?: $parcel_desc ?: 'Item';
        }
        // Goods / line-item value — do not reuse freight mass/vol costs.
        $items_price_total = $getNum($row, ['waybill_items_total', 'total_price'], 0);
        if ($items_price_total <= 0) {
            $items_price_total = $cust_inv_r;
        }

        $sheet_invoice = kit_seed_normalize_sheet_invoice($get($row, ['product_invoice_number', 'client_invoice', 'inv_no', 'cl_inv_#'], ''));

        $custom_items = function_exists('kit_seed_build_custom_items_from_description')
            ? kit_seed_build_custom_items_from_description(
                $item_desc_for_lines,
                (string) $waybill_no,
                (float) $items_price_total,
                $sheet_invoice
            )
            : [[
                'item_name' => $item_desc_for_lines ?: 'Item',
                'quantity' => 1,
                'unit_price' => $items_price_total > 0 ? $items_price_total : 0,
                'unit_mass' => 0,
                'unit_volume' => 0,
                'total_price' => $items_price_total > 0 ? $items_price_total : 0,
                'client_invoice' => $sheet_invoice,
            ]];

        $save_data = [
            'delivery_id' => $delivery_id,
            'direction_id' => $direction_id,
            'destination_city' => $city_id,
            'destination_country' => $dest_country_id,
            'origin_city' => 1,
            'origin_country' => 1,
            'customer_id' => $customer_id,
            'cust_id' => $customer_id,
            'company_id' => $seed_company_id > 0 ? $seed_company_id : null,
            'client_type' => $seed_company_id > 0 ? 'business' : 'individual',
            'waybill_description' => $description ?: $parcel_desc,
            'waybill_no' => $waybill_no,
            'product_invoice_number' => $sheet_invoice,
            'total_mass_kg' => $total_mass,
            'total_volume' => $total_vol,
            'item_length' => $item_len,
            'item_width' => $item_w,
            'item_height' => $item_h,
            'mass_charge' => $mass_charge,
            'volume_charge' => $vol_charge,
            'charge_basis' => (string) $charge_basis,
            'vat_include' => $vatFlag,
            'include_sad500' => $include_sad500,
            'include_sadc' => $include_sadc,
            'warehouse' => $warehouse,
            'misc' => [],
            'custom_items' => $custom_items,
        ];
        if ($total_mass > 0) {
            $save_data['mass_rate'] = ($mass_charge > 0)
                ? $mass_charge / $total_mass
                : 40;
        }
        $save_data['_skip_google_sync'] = true;
        $row_created_by_raw = $get($row, ['created_by', 'created by', 'creator_id', 'createdby', 'creator', 'created_by_user'], '');
        $row_created_by = $resolve_wp_user_id($row_created_by_raw);
        if ($row_created_by <= 0) {
            $row_created_by = $seed_owner_user_id;
        }
        $row_last_updated_by_raw = $get($row, ['last_updated_by', 'last updated by', 'updated_by', 'lastupdatedby', 'last_updated', 'updatedby'], '');
        $row_last_updated_by = $resolve_wp_user_id($row_last_updated_by_raw);
        if ($row_last_updated_by <= 0) {
            $row_last_updated_by = $row_created_by;
        }
        $row_approval_userid_raw = $get($row, ['approval_userid', 'approval_user_id', 'approved_by', 'approval userid', 'approval_user'], '');
        $row_approval_userid = $resolve_wp_user_id($row_approval_userid_raw);
        if ($row_approval_userid <= 0) {
            // Seed requires approval_userid present; default to creator when missing/NULL in sheet.
            $row_approval_userid = $row_created_by;
        }

        $save_data['created_by'] = $row_created_by;
        $save_data['last_updated_by'] = $row_last_updated_by;
        $save_data['approval_userid'] = $row_approval_userid;

        if (!class_exists('KIT_Waybills')) {
            $stats['skipped']++;
            $stats['errors'][] = "WB {$waybill_no}: KIT_Waybills class not loaded";
            continue;
        }

        if ($existing_waybill_id > 0) {
            // Upsert: sheet-owned truck assignment + invoice/approval (re-seed must fix delivery_id + customer_id).
            // #region agent log
            static $dbg_update_logs = 0;
            if ($dbg_update_logs < 5) {
                $dbg_update_logs++;
                $db_created_at = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT created_at FROM {$waybills_t} WHERE id = %d LIMIT 1",
                    $existing_waybill_id
                ));
                @file_put_contents(
                    (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-0df36b.log',
                    json_encode([
                        'sessionId' => '0df36b',
                        'runId' => 'post-fix',
                        'hypothesisId' => 'H5',
                        'location' => 'settings.php:run_google_sheet_seed',
                        'message' => 'update path created_at handling',
                        'data' => [
                            'waybill_no' => (string) $waybill_no,
                            'sheet_created_at_raw' => $sheet_created_at_raw,
                            'normalized_created_at' => $sheet_created_at,
                            'db_created_at_before' => $db_created_at,
                            'will_update_created_at' => $sheet_created_at !== '',
                        ],
                        'timestamp' => (int) round(microtime(true) * 1000),
                    ]) . "\n",
                    FILE_APPEND
                );
            }
            // #endregion
            $upsert_payload = [
                'delivery_id' => $delivery_id,
                'direction_id' => $direction_id,
                'city_id' => $city_id,
            ];
            // Always write ownership from the sheet (clear stale company/customer on re-seed).
            // Prior seeds left company_id set when the sheet later had person-only / blank party.
            $upsert_payload['customer_id'] = $customer_id > 0 ? $customer_id : 0;
            $upsert_payload['company_id'] = $seed_company_id > 0 ? $seed_company_id : 0;
            $upsert_payload['warehouse'] = $warehouse;
            if ($sheet_invoice !== '') {
                $upsert_payload['product_invoice_number'] = $sheet_invoice;
            }
            // AB charge_basis selects Z/AA freight; the post-loop pass then adds VAT/fees.
            $upsert_payload['product_invoice_amount'] = (float) $product_invoice_amount;
            $upsert_payload['mass_charge'] = (float) $mass_charge;
            $upsert_payload['volume_charge'] = (float) $vol_charge;
            $upsert_payload['charge_basis'] = (string) $charge_basis;
            $upsert_payload['total_mass_kg'] = (float) $total_mass;
            $upsert_payload['total_volume'] = (float) $total_vol;
            $upsert_payload['item_length'] = (float) $item_len;
            $upsert_payload['item_width'] = (float) $item_w;
            $upsert_payload['item_height'] = (float) $item_h;
            $upsert_payload['approval'] = $get($row, ['approval'], 'pending') ?: 'pending';
            $upsert_payload['approval_userid'] = $row_approval_userid;
            $upsert_payload['created_by'] = $row_created_by;
            $upsert_payload['last_updated_by'] = $row_last_updated_by;
            $upsert_payload['last_updated_at'] = current_time('mysql');
            if ($sheet_created_at !== '') {
                $upsert_payload['created_at'] = $sheet_created_at;
            }
            $upsert_formats = [];
            foreach (array_keys($upsert_payload) as $field) {
                if (in_array($field, ['delivery_id', 'direction_id', 'city_id', 'customer_id', 'company_id', 'warehouse', 'approval_userid', 'created_by', 'last_updated_by'], true)) {
                    $upsert_formats[] = '%d';
                } elseif (in_array($field, ['product_invoice_amount', 'mass_charge', 'volume_charge', 'total_mass_kg', 'total_volume', 'item_length', 'item_width', 'item_height'], true)) {
                    $upsert_formats[] = '%f';
                } else {
                    $upsert_formats[] = '%s';
                }
            }
            $wpdb->update(
                $waybills_t,
                $upsert_payload,
                ['id' => $existing_waybill_id],
                $upsert_formats,
                ['%d']
            );
            $refreshed_total = KIT_Waybills::updateWaybillItems($save_data['custom_items'], (string) $waybill_no);
            $wpdb->update(
                $waybills_t,
                ['waybill_items_total' => (float) $refreshed_total],
                ['id' => $existing_waybill_id],
                ['%f'],
                ['%d']
            );
            $stats['waybills']++;
            $stats['waybill_items'] += is_array($save_data['custom_items']) ? count($save_data['custom_items']) : 0;
            $result = ['id' => $existing_waybill_id];
        } else {
            // #region agent log
            static $dbg_lean_ctx_logs = 0;
            if ($dbg_lean_ctx_logs < 5) {
                $dbg_lean_ctx_logs++;
                @file_put_contents(
                    (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-0df36b.log',
                    json_encode([
                        'sessionId' => '0df36b',
                        'runId' => 'post-fix',
                        'hypothesisId' => 'H2',
                        'location' => 'settings.php:run_google_sheet_seed',
                        'message' => 'lean_ctx created_at from sheet',
                        'data' => [
                            'waybill_no' => (string) $waybill_no,
                            'sheet_created_at_raw' => $sheet_created_at_raw,
                            'normalized_created_at' => $sheet_created_at,
                        ],
                        'timestamp' => (int) round(microtime(true) * 1000),
                    ]) . "\n",
                    FILE_APPEND
                );
            }
            // #endregion
            $lean_ctx = [
                'waybill_no' => (string) $waybill_no,
                'description' => $description ?: $parcel_desc,
                'direction_id' => $direction_id,
                'delivery_id' => $delivery_id,
                'customer_id' => $customer_id,
                'company_id' => $seed_company_id > 0 ? $seed_company_id : 0,
                'city_id' => $city_id,
                'product_invoice_number' => $sheet_invoice,
                'product_invoice_amount' => (float) $product_invoice_amount,
                'waybill_items_total' => (float) $items_price_total,
                'item_length' => $item_len,
                'item_width' => $item_w,
                'item_height' => $item_h,
                'total_mass_kg' => $total_mass,
                'total_volume' => $total_vol,
                'mass_charge' => $mass_charge,
                'volume_charge' => $vol_charge,
                'charge_basis' => (string) $charge_basis,
                'vat_include' => $vatFlag,
                'include_sad500' => $include_sad500,
                'include_sadc' => $include_sadc,
                'warehouse' => $warehouse,
                'approval' => $get($row, ['approval'], 'pending') ?: 'pending',
                'approval_userid' => $row_approval_userid,
                'created_by' => $row_created_by,
                'last_updated_by' => $row_last_updated_by,
                'custom_items' => $save_data['custom_items'],
            ];
            if ($sheet_created_at !== '') {
                $lean_ctx['created_at'] = $sheet_created_at;
            }
            $sheet_misc_serialized_early = $get($row, ['miscellaneous', 'misc'], '');
            if ($sheet_misc_serialized_early !== '' && preg_match('/^a:\d+:\{/', $sheet_misc_serialized_early)) {
                $lean_ctx['miscellaneous'] = $sheet_misc_serialized_early;
                $mt_raw = $get($row, ['misc_total', 'misc total'], '');
                $lean_ctx['misc_total'] = ($mt_raw !== '' && is_numeric(preg_replace('/[^0-9.\-]/', '', $mt_raw)))
                    ? (float) preg_replace('/[^0-9.\-]/', '', $mt_raw)
                    : 0.0;
            }
            $result = kit_seed_lean_insert_waybill($lean_ctx);
            if (!is_wp_error($result)) {
                $existing_waybill_map[(string) $waybill_no] = (int) ($result['id'] ?? 0);
            }
        }
        if (is_wp_error($result)) {
            $stats['skipped']++;
            $errMsg = $result->get_error_message();
            $stats['errors'][] = "WB {$waybill_no}: {$errMsg}";
            if (function_exists('error_log')) {
                error_log('[GoogleSheetSeed] save_waybill failed: ' . $errMsg);
            }
            continue;
        }
        if ($existing_waybill_id === 0) {
            $stats['waybills']++;
            $stats['waybill_items'] += is_array($save_data['custom_items'] ?? null) ? count($save_data['custom_items']) : 0;
        }

        // Sheet → DB: preserve PHP-serialized miscellaneous + misc_total (updates only; lean insert handles on create).
        if ($existing_waybill_id > 0) {
            $sheet_misc_serialized = $get($row, ['miscellaneous', 'misc'], '');
            if ($sheet_misc_serialized !== '' && preg_match('/^a:\d+:\{/', $sheet_misc_serialized)) {
                $maybe_misc = maybe_unserialize($sheet_misc_serialized);
                if (is_array($maybe_misc)) {
                    $new_wb_id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
                    if ($new_wb_id > 0) {
                        $mt_raw = $get($row, ['misc_total', 'misc total'], '');
                        $misc_total_val = ($mt_raw !== '' && is_numeric(preg_replace('/[^0-9.\-]/', '', $mt_raw)))
                            ? (float) preg_replace('/[^0-9.\-]/', '', $mt_raw)
                            : (float) ($maybe_misc['misc_total'] ?? 0);
                        $wpdb->update(
                            $waybills_t,
                            [
                                'miscellaneous' => $sheet_misc_serialized,
                                'misc_total'    => $misc_total_val,
                            ],
                            ['id' => $new_wb_id],
                            ['%s', '%f'],
                            ['%d']
                        );
                    }
                }
            }
        }
    }

    // Post-waybill steps must not abort an otherwise successful seed (e.g. missing helper after recovery).
    if (!$simulate) {
        try {
            if (!empty($customer_registry) && function_exists('kit_seed_relink_waybill_customer_ids')) {
                $stats['waybills_customer_relinked'] = kit_seed_relink_waybill_customer_ids(
                    $wpdb,
                    $waybills_t,
                    $customer_registry,
                    $rows,
                    $col,
                    $get,
                    $normalize_customer_label,
                    $waybills_source_lookup
                );
            }
            if (class_exists('KIT_Customers') && method_exists('KIT_Customers', 'repair_waybill_customer_id_links')) {
                $stats['waybills_id_links_repaired'] = (int) KIT_Customers::repair_waybill_customer_id_links();
            } elseif (class_exists('KIT_Customers')) {
                $stats['errors'][] = 'Skipped waybill customer_id repair: KIT_Customers::repair_waybill_customer_id_links() is missing';
            }
            // The loop above stored freight only. Derive the billed total with the
            // same calculator the waybill form saves through, so VAT-true waybills
            // carry their VAT instead of sitting at the freight figure.
            if (class_exists('KIT_Waybills')) {
                $totals_derived = 0;
                foreach (array_keys($existing_waybill_map) as $seeded_waybill_no) {
                    if ((string) $seeded_waybill_no === '') {
                        continue;
                    }
                    $recalc = KIT_Waybills::doubleCalcWaybillTotal([
                        'waybill_no'         => (string) $seeded_waybill_no,
                        'update_if_mismatch' => true,
                    ]);
                    if (!empty($recalc['updated'])) {
                        $totals_derived++;
                    }
                }
                $stats['waybill_totals_derived'] = $totals_derived;
            }
        } catch (Throwable $post_e) {
            $stats['errors'][] = 'Post-waybill customer link repair failed: ' . $post_e->getMessage();
            if (function_exists('error_log')) {
                error_log('[GoogleSheetSeed] post-waybill repair: ' . $post_e->getMessage());
            }
        }
    }

    if ($stats['skipped'] > 0 && $stats['waybills'] === 0 && !empty($header)) {
        $stats['detected_columns'] = array_values(array_filter($header, fn($h) => $h !== ''));
    }

    $elapsed_seconds = (int) round(microtime(true) - $seed_started_at);
    $stats['elapsed_seconds'] = $elapsed_seconds;

    $msg = $simulate
        ? sprintf(
            'Simulation completed. Would create: Drivers: %d, Customers: %d, Deliveries: %d, Waybills: %d, Items: %d. Skipped: %d.',
            $stats['drivers'],
            $stats['customers'],
            $stats['deliveries'],
            $stats['waybills'],
            $stats['waybill_items'],
            $stats['skipped']
        )
        : sprintf(
            'Setup seed from Google Sheet completed in %ds. Drivers: %d, Customers: %d, Deliveries: %d, Waybills: %d, Waybill items: %d. Skipped: %d.',
            $elapsed_seconds,
            $stats['drivers'],
            $stats['customers'],
            $stats['deliveries'],
            $stats['waybills'],
            $stats['waybill_items'],
            $stats['skipped']
        );
    if ($simulate) {
        $wpdb->query('ROLLBACK');
    }
    return ['success' => true, 'message' => $msg, 'stats' => $stats];
} catch (Throwable $e) {
    if ($simulate) {
        $wpdb->query('ROLLBACK');
    }
    return ['success' => false, 'message' => 'Google Sheet seed failed: ' . $e->getMessage()];
}
}

/**
 * Placeholder values in kit_customers name/company columns (not real customer data).
 */
function kit_seed_customer_field_is_placeholder(string $value): bool
{
    $v = strtolower(trim($value));
    return $v === '' || in_array($v, ['0', 'null', 'n/a', 'na', 'none', '-', '--'], true);
}

// Canonical definitions live in includes/sync/kit-business-name.php (loaded early).
// Keep these guarded so admin settings can load safely if that file is missing.
if (!function_exists('kit_seed_strip_private_company_label')) {
    /** Strip Individual/Private / sheet-error pseudo-company labels from seeded rows. */
    function kit_seed_strip_private_company_label(string $company): string
    {
        $company = trim($company);
        if ($company === '') {
            return '';
        }
        $lower = strtolower($company);
        if (in_array($lower, ['individual', 'private', 'n/a', 'na', 'none', '-', '--', 'null', '0'], true)) {
            return '';
        }
        if (preg_match('/^#(ERROR|REF|N\/A|NULL|VALUE|DIV\/0|NAME\?|NUM!)!?\s*$/i', $company)) {
            return '';
        }
        return $company;
    }
}

if (!function_exists('kit_seed_customer_looks_like_business')) {
    /** True when the whole label reads as a business/company (Ltd, Lodge, Tours, etc.). */
    function kit_seed_customer_looks_like_business(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }
        if (strpos($t, '=') !== false) {
            return true;
        }
        if (preg_match('/\b(ltd|limited|pty|inc|corp|llc|plc|group|traders|logistics|camp|camps|safari|safaris|lodge|lodges|hotel|hotels|tours|tour|investments|technologies|industries|works|expeditions|foods|laundry|creations|holdings|adventure|adventures|emporium|emporiums|destinations|destination|enterprise|enterprises|wilderness|tanzania|kenya|zambia|africa|suppliers|supplier|imports|import|exports|export|motors|motor|furniture|hardware|wholesale|retail|construction|engineering|solutions|services|properties|property|estate|estates|trading|company|brewing|brewery|breweries)\b/i', $t)) {
            return true;
        }
        return kit_seed_customer_is_company_suffix_only($t);
    }
}

if (!function_exists('kit_seed_customer_is_company_suffix_only')) {
    /** Tokens that are company suffixes/fragments — never a person's name (e.g. "Ltd", "Limited"). */
    function kit_seed_customer_is_company_suffix_only(string $text): bool
    {
        $t = strtolower(trim($text, " \t\n\r\0\x0B.)("));
        return in_array($t, [
            'ltd', 'limited', 'pty', 'inc', 'corp', 'llc', 'plc', 'sa', 'cc', 'gmbh',
            'investments', 'technologies', 'industries', 'works', 'expeditions',
            'tours', 'safaris', 'lodge', 'lodges', 'hotels', 'foods', 'laundry', 'creations',
            'holdings', 'dom', 'limited', 'ltd.', 'adventures', 'adventure',
            'emporium', 'emporiums', 'destinations', 'destination', 'enterprise', 'enterprises',
            'tanzania', 'kenya', 'zambia', 'africa', 'wilderness',
        ], true);
    }
}

/**
 * Normalize kit_customers sheet fields before insert/update.
 *
 * @return array{name:string,surname:string,company_name:string,skip:bool}
 */
function kit_seed_normalize_customer_row(string $name, string $surname, string $company, string $combined = '', bool $promote_name_to_company = true): array
{
    $name = trim($name);
    $surname = trim($surname);
    $company = kit_seed_strip_private_company_label($company);
    $combined = trim($combined);

    // A lone company fragment ("Foods", "Investments") is not a person's name.
    // Only when it stands alone, though: beside a first name it is a surname —
    // "Marty Dom" lost its surname to a company called "Dom" because "dom" is on
    // the suffix list. A business split across both columns ("Tilke Investments")
    // is promoted whole further down.
    foreach (['name' => 'surname', 'surname' => 'name'] as $field => $other) {
        if (${$field} === '' || ${$other} !== '') {
            continue;
        }
        if (kit_seed_customer_is_company_suffix_only(${$field})) {
            if ($company === '') {
                $company = ${$field};
            }
            ${$field} = '';
        }
    }

    if (strtolower($surname) === 'import') {
        $surname = '';
    }

    if (kit_seed_customer_field_is_placeholder($name)) {
        $name = '';
    }

    if ($combined !== '' && $name === '') {
        if (kit_seed_customer_looks_like_business($combined)) {
            if ($company === '') {
                $company = $combined;
            }
            $name = '';
            $surname = '';
        } else {
            $parts = preg_split('/\s+/', $combined, 2);
            $name = trim((string) ($parts[0] ?? ''));
            $surname = trim((string) ($parts[1] ?? ''));
        }
    }

    // Drop duplicated personal-name company labels (e.g. name=Yusuf, company=Yusuf).
    if ($company !== '' && !kit_seed_customer_looks_like_business($company)) {
        $full_for_cmp = trim($name . ' ' . $surname);
        if (strcasecmp($company, $name) === 0
            || ($full_for_cmp !== '' && strcasecmp($company, $full_for_cmp) === 0)) {
            $company = '';
        }
    }

    // Incomplete rows: no person name and only a company fragment (Foods, Ltd, Investments, …).
    if ($name === '' && $surname === '') {
        if ($company === '' || kit_seed_customer_is_company_suffix_only($company)
            || !kit_seed_customer_looks_like_business($company)) {
            return [
                'name' => '',
                'surname' => '',
                'company_name' => '',
                'skip' => true,
            ];
        }
    }

    $full = trim($name . ' ' . $surname);
    if ($promote_name_to_company && $company === '' && $full !== '' && kit_seed_customer_looks_like_business($full)) {
        $company = $full;
        $name = '';
        $surname = '';
    }

    $result = [
        'name' => $name,
        'surname' => $surname,
        'company_name' => $company,
        'skip' => false,
    ];
    if (class_exists('KIT_Customers')) {
        $final = KIT_Customers::finalize_customer_name_fields($name, $surname, $company);
        $result['name'] = $final['name'];
        $result['surname'] = $final['surname'];
        $result['company_name'] = $final['company_name'];
    }

    return $result;
}

/**
 * Does a waybill's own text agree that this company is the billed party?
 *
 * Guards the one place a waybill is reassigned from a person to a company on the
 * strength of a shared id. Empty labels mean the waybill says nothing either
 * way, so the id is allowed to stand.
 *
 * @param array<string, mixed>|object $company row from KIT_Company_Customers
 */
function kit_seed_label_matches_company($company, string $customer_label, string $company_label): bool
{
    $name = trim((string) ((is_array($company) ? ($company['company_name'] ?? '') : ($company->company_name ?? ''))));
    if ($name === '') {
        return false;
    }
    if ($customer_label === '' && $company_label === '') {
        return true;
    }
    if (!class_exists('KIT_Customers')) {
        return strcasecmp($name, $customer_label) === 0 || strcasecmp($name, $company_label) === 0;
    }

    $target = KIT_Customers::normalize_company_compare_key($name);
    foreach ([$company_label, $customer_label] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        $key = KIT_Customers::normalize_company_compare_key($candidate);
        if ($key !== '' && ($key === $target || KIT_Customers::customer_labels_are_similar($key, $target))) {
            return true;
        }
    }

    return false;
}

/**
 * Normalize waybill number for lookup keys (digits only, no leading zeros stripped).
 */
function kit_seed_normalize_waybill_no_key(string $waybill_no): string
{
    $digits = preg_replace('/[^0-9]/', '', $waybill_no);
    return $digits !== '' ? $digits : trim($waybill_no);
}

/**
 * Read the legacy Waybills source tab and index rows by waybill number.
 *
 * kit_waybills often has customer_id populated but blank cust_name_ignore when the
 * Apps Script sync has not backfilled names; the Waybills tab still has CUSTOMER / Company.
 *
 * @return array{by_waybill:array<string,array<string,string>>}
 */
function kit_seed_build_waybills_source_lookup(): array
{
    $lookup = ['by_waybill' => []];
    $rows = kit_seed_read_waybills_source_sheet_rows();
    if (empty($rows) || count($rows) < 2) {
        return $lookup;
    }

    $col = kit_seed_header_col_map($rows[0]);

    foreach (array_slice($rows, 1) as $row) {
        $wb_key = kit_seed_normalize_waybill_no_key(kit_seed_row_cell($row, $col, [
            'waybill_#', 'waybill_no', 'waybillno', 'waybill_number', 'wb_no', 'waybill',
        ]));
        if ($wb_key === '') {
            continue;
        }
        $customer = kit_seed_row_cell($row, $col, ['customer', 'cust_name', 'client', 'customer_name']);
        $company = kit_seed_row_cell($row, $col, ['company', 'company_name']);
        $city = kit_seed_row_cell($row, $col, ['city', 'city_name', 'destination_city']);
        $cell = kit_seed_row_cell($row, $col, ['cell', 'cellphone', 'mobile', 'mobile_number']);
        $tel = kit_seed_row_cell($row, $col, ['telephone', 'tel', 'phone', 'phone_number']);
        $email = kit_seed_row_cell($row, $col, ['email', 'email_address', 'e-mail']);
        $address = kit_seed_row_cell($row, $col, ['address', 'physical_address', 'postal_address']);
        $item_description = kit_seed_row_cell($row, $col, ['item_description', 'item_desc', 'item description']);
        $customer_inv_r = kit_seed_row_cell($row, $col, ['customer_inv(_r)', 'customer_inv(r)', 'customer_inv', 'cust_inv', 'custinvr']);

        if ($customer === '' && $company === '' && $city === '' && $cell === '' && $email === '' && $item_description === '') {
            continue;
        }

        $existing = $lookup['by_waybill'][$wb_key] ?? [];
        if ($customer !== '' && empty($existing['customer'])) {
            $existing['customer'] = $customer;
        }
        if ($company !== '' && empty($existing['company'])) {
            $existing['company'] = $company;
        }
        if ($city !== '' && empty($existing['city'])) {
            $existing['city'] = $city;
        }
        if ($cell !== '' && empty($existing['cell'])) {
            $existing['cell'] = $cell;
        }
        if ($tel !== '' && empty($existing['telephone'])) {
            $existing['telephone'] = $tel;
        }
        if ($email !== '' && empty($existing['email'])) {
            $existing['email'] = $email;
        }
        if ($address !== '' && empty($existing['address'])) {
            $existing['address'] = $address;
        }
        if ($item_description !== '' && empty($existing['item_description'])) {
            $existing['item_description'] = $item_description;
        }
        if ($customer_inv_r !== '' && empty($existing['customer_inv_r'])) {
            $cleaned_inv = preg_replace('/[^\d.\-]/', '', $customer_inv_r);
            if ($cleaned_inv !== '' && is_numeric($cleaned_inv)) {
                $existing['customer_inv_r'] = (float) $cleaned_inv;
            }
        }
        $lookup['by_waybill'][$wb_key] = $existing;
    }

    return $lookup;
}

/**
 * Strip placeholder CL INV / product_invoice_number values from sheet rows.
 */
function kit_seed_normalize_sheet_invoice(string $raw): string
{
    $s = trim((string) $raw);
    if ($s === '') {
        return '';
    }
    $upper = strtoupper($s);
    if (in_array($upper, ['P', 'N/A', 'NA', 'NONE', '-', '--', '0', 'NULL'], true)) {
        return '';
    }
    // Google Sheets error tokens / column-overflow hashes (##########, #REF!, #VALUE!).
    if ($s[0] === '#' || preg_match('/^#+$/', $s)) {
        return '';
    }
    // Amounts that leaked into CL INV (e.g. 1,500.00) are not invoice numbers.
    if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $s) || preg_match('/^\d+\.\d{2}$/', $s)) {
        return '';
    }
    // Single-letter placeholders from legacy Waybills CL INV # column.
    if (strlen($s) <= 2 && !preg_match('/\d/', $s)) {
        return '';
    }
    return $s;
}

/**
 * Normalize sheet date/time strings into MySQL datetime for kit_waybills.created_at.
 * Accepts DD/MM/YYYY (Waybills tab), ISO dates, and yyyy-MM-dd HH:mm:ss.
 */
function kit_seed_normalize_created_at(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || preg_match('/^(warehouse|cancelled|duplicate|null|n\/a)$/i', $raw)) {
        return '';
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?)?)?$/', $raw, $m)) {
        $part_a = (int) $m[1];
        $part_b = (int) $m[2];
        $year = (int) $m[3];
        if ($part_a > 12 && $part_b <= 12) {
            $day = $part_a;
            $month = $part_b;
        } elseif ($part_b > 12 && $part_a <= 12) {
            $month = $part_a;
            $day = $part_b;
        } elseif ($part_a > 12 && $part_b > 12) {
            return '';
        } else {
            // Ambiguous — default to DD/MM (Waybills tab convention).
            $day = $part_a;
            $month = $part_b;
        }
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return '';
        }
        $time = isset($m[4]) && $m[4] !== '' ? $m[4] : '00:00:00';
        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $time .= ':00';
        }
        return sprintf('%04d-%02d-%02d %s', $year, $month, $day, $time);
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return '';
    }

    return date('Y-m-d H:i:s', $ts);
}

/**
 * Read raw rows from the legacy Waybills source tab (IMPORTRANGE-fed).
 *
 * @return array<int, array<int, mixed>>
 */
function kit_seed_read_waybills_source_sheet_rows(): array
{
    static $cached_rows = null;
    if ($cached_rows !== null) {
        return $cached_rows;
    }

    if (!class_exists('Courier_Google_Sheets') || !Courier_Google_Sheets::is_configured()) {
        $cached_rows = [];
        return $cached_rows;
    }

    $sheet_names = ['Waybills', 'waybills'];
    if (defined('COURIER_GOOGLE_SOURCE_WAYBILLS_SHEET') && COURIER_GOOGLE_SOURCE_WAYBILLS_SHEET !== '') {
        array_unshift($sheet_names, COURIER_GOOGLE_SOURCE_WAYBILLS_SHEET);
    }

    foreach ($sheet_names as $sheet_name) {
        try {
            $rows = Courier_Google_Sheets::get_values('', 'A1:AK5000', $sheet_name);
            if (!empty($rows) && count($rows) >= 2) {
                $cached_rows = $rows;
                return $cached_rows;
            }
        } catch (Exception $e) {
            continue;
        }
    }

    $cached_rows = [];
    return $cached_rows;
}

/**
 * Build header key → column index map from a sheet header row.
 *
 * @return array<string, int>
 */
function kit_seed_header_col_map(array $header_row): array
{
    $col = [];
    foreach ($header_row as $i => $c) {
        $h = strtolower(trim((string) $c));
        $h = str_replace(' ', '_', $h);
        if ($h !== '' && !isset($col[$h])) {
            $col[$h] = (int) $i;
        }
    }
    return $col;
}

/**
 * @param array<string, int> $col
 * @param array<int, mixed> $row
 * @param array<int, string> $keys
 */
function kit_seed_row_cell(array $row, array $col, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        $idx = $col[$key] ?? null;
        if ($idx !== null) {
            $value = trim((string) ($row[$idx] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }
    return $default;
}

/**
 * Convert Waybills source rows into kit_waybills-compatible seed rows (header + data).
 *
 * @param array<int, array<int, mixed>> $source_rows
 * @return array<int, array<int, mixed>>
 */
function kit_seed_convert_source_rows_to_seed_format(array $source_rows): array
{
    if (empty($source_rows) || count($source_rows) < 2) {
        return [];
    }

    $col = kit_seed_header_col_map($source_rows[0]);
    $seed_header = [
        'driver', 'dispatch_date', 'created_at', 'waybill_no', 'parcel_id', 'description',
        'direction_id', 'city_name_ignore', 'city_id', 'delivery_id', 'cust_name_ignore',
        'customer_id', 'cell', 'email_address', 'address', 'company_name',
        'product_invoice_number', 'product_invoice_amount',
        'item_length', 'item_width', 'item_height', 'total_mass_kg', 'total_volume',
        'mass_charge', 'volume_charge', 'charge_basis', 'vat_include', 'include_sad500', 'include_sadc',
        'approval', 'approval_userid', 'status',
    ];

    $out = [$seed_header];
    foreach (array_slice($source_rows, 1) as $row) {
        $waybill_no = kit_seed_row_cell($row, $col, [
            'waybill_#', 'waybill_no', 'waybillno', 'waybill_number', 'wb_#', 'wb_no', 'waybill',
        ]);
        if ($waybill_no === '') {
            continue;
        }

        $customer = kit_seed_row_cell($row, $col, ['customer', 'cust_name', 'client', 'customer_name']);
        $description = kit_seed_row_cell($row, $col, ['waybill_description', 'waybill_desc']);
        if ($description === '') {
            $description = kit_seed_row_cell($row, $col, ['item_description', 'item_desc']);
        }

        $out[] = [
            kit_seed_row_cell($row, $col, ['driver', 'driver_name', 'truck_driver']),
            kit_seed_row_cell($row, $col, ['dispatch_date', 'truck_dispatch_date', 'trip_date']),
            kit_seed_row_cell($row, $col, ['date_received', 'date_recieved', 'received_date']),
            $waybill_no,
            '',
            $description,
            kit_seed_row_cell($row, $col, ['direction_id', 'direction']),
            kit_seed_row_cell($row, $col, ['city', 'city_name', 'destination_city', 'city_name_ignore']),
            kit_seed_row_cell($row, $col, ['city_id', 'destination_city_id']),
            kit_seed_row_cell($row, $col, ['delivery_id', 'delivery', 'del_id']),
            $customer,
            kit_seed_row_cell($row, $col, ['customer_id', 'cust_id']),
            kit_seed_row_cell($row, $col, ['cell', 'cellphone', 'mobile']),
            kit_seed_row_cell($row, $col, ['email', 'email_address', 'e-mail']),
            kit_seed_row_cell($row, $col, ['address', 'physical_address', 'postal_address']),
            kit_seed_row_cell($row, $col, ['company', 'company_name']),
            kit_seed_row_cell($row, $col, ['cl_inv_#', 'cl_inv', 'client_invoice', 'inv_#', 'inv_no']),
            kit_seed_row_cell($row, $col, ['customer_inv(_r)', 'customer_inv(r)', 'customer_inv', 'cust_inv']),
            kit_seed_row_cell($row, $col, ['length', 'item_length']),
            kit_seed_row_cell($row, $col, ['width', 'item_width']),
            kit_seed_row_cell($row, $col, ['height', 'item_height']),
            kit_seed_row_cell($row, $col, ['t_mass', 'total_mass_kg', 'total_mass', 'mass_kg']),
            kit_seed_row_cell($row, $col, ['t_volume', 'total_volume', 'volume']),
            kit_seed_row_cell($row, $col, ['mass_cost', 'mass_charge']),
            kit_seed_row_cell($row, $col, ['vol_cost', 'volume_charge', 'vol_charge']),
            kit_seed_row_cell($row, $col, ['basis', 'charge_basis']),
            kit_seed_row_cell($row, $col, ['vat', 'vat_include']),
            kit_seed_row_cell($row, $col, ['sad500', 'include_sad500']),
            kit_seed_row_cell($row, $col, ['sadc', 'include_sadc']),
            kit_seed_row_cell($row, $col, ['approval'], 'pending'),
            kit_seed_row_cell($row, $col, ['approved_by', 'approval_userid', 'approval_user_id']),
            kit_seed_row_cell($row, $col, ['status']),
        ];
    }

    return count($out) >= 2 ? $out : [];
}

/**
 * Merge kit_waybills overlay rows onto a primary seed set (matched by waybill_no).
 *
 * @param array<int, array<int, mixed>> $primary_rows
 * @param array<int, array<int, mixed>> $overlay_rows
 * @return array<int, array<int, mixed>>
 */
function kit_seed_merge_waybill_rows(array $primary_rows, array $overlay_rows): array
{
    if (empty($primary_rows) || count($primary_rows) < 2) {
        return $overlay_rows;
    }
    if (empty($overlay_rows) || count($overlay_rows) < 2) {
        return $primary_rows;
    }

    $primary_header = kit_seed_header_col_map($primary_rows[0]);
    $overlay_header = kit_seed_header_col_map($overlay_rows[0]);
    $merged_keys = array_values(array_unique(array_merge(array_keys($overlay_header), array_keys($primary_header))));
    $merged_header = $merged_keys;

    $overlay_by_wb = [];
    foreach (array_slice($overlay_rows, 1) as $orow) {
        $wb = kit_seed_row_cell($orow, $overlay_header, ['waybill_no', 'parcel_id', 'waybill_#', 'wb_no']);
        if ($wb === '') {
            continue;
        }
        $assoc = [];
        foreach ($overlay_header as $key => $idx) {
            $assoc[$key] = trim((string) ($orow[$idx] ?? ''));
        }
        $overlay_by_wb[kit_seed_normalize_waybill_no_key($wb)] = $assoc;
    }

    $merged = [$merged_header];
    $primary_wb_keys = [];
    foreach (array_slice($primary_rows, 1) as $prow) {
        $assoc = [];
        foreach ($primary_header as $key => $idx) {
            $assoc[$key] = trim((string) ($prow[$idx] ?? ''));
        }
        $wb_key = kit_seed_normalize_waybill_no_key(
            kit_seed_row_cell($prow, $primary_header, ['waybill_no', 'parcel_id', 'waybill_#', 'wb_no'])
        );
        if ($wb_key !== '') {
            $primary_wb_keys[$wb_key] = true;
        }
        if ($wb_key !== '' && isset($overlay_by_wb[$wb_key])) {
            $primary_created_at = kit_seed_normalize_created_at((string) ($assoc['created_at'] ?? ''));
            $primary_city_name = trim((string) ($assoc['city_name_ignore'] ?? $assoc['city'] ?? $assoc['city_name'] ?? $assoc['destination_city'] ?? ''));
            $primary_driver = trim((string) ($assoc['driver'] ?? $assoc['driver_name'] ?? $assoc['truck_driver'] ?? ''));
            $primary_is_warehouse = class_exists('KIT_Sheet_Delivery_Resolve')
                ? KIT_Sheet_Delivery_Resolve::is_warehouse_driver_label($primary_driver)
                : (bool) preg_match('/\bwarehouse\b/i', $primary_driver);
            foreach ($overlay_by_wb[$wb_key] as $key => $value) {
                if ($value === '') {
                    continue;
                }
                // Waybills "Date Received" (primary) wins over kit_waybills sync timestamp — matches Code.gs.
                if ($key === 'created_at' && $primary_created_at !== '') {
                    continue;
                }
                // Waybills tab column K (city name) is authoritative — never let kit_waybills city_id overwrite it.
                if ($primary_city_name !== '' && in_array($key, ['city_name_ignore', 'city', 'city_name', 'destination_city', 'city_id'], true)) {
                    continue;
                }
                // Driver = Warehouse on Waybills wins — do not keep a stale truck delivery_id from kit_waybills.
                if ($primary_is_warehouse && in_array($key, ['delivery_id', 'delivery', 'del_id', 'warehouse'], true)) {
                    continue;
                }
                $assoc[$key] = $value;
            }
        }
        $line = [];
        foreach ($merged_header as $key) {
            $line[] = $assoc[$key] ?? '';
        }
        $merged[] = $line;
    }

    // Waybills source tab has blank WAYBILL # on ~315 rows (IMPORTRANGE gaps).
    // Those waybills live only on kit_waybills — Alexandre Costerg 4943, older
    // Aliasgher rows, etc. Dropping them made customers show "0 waybills" even
    // though kit_waybills had the freight.
    foreach ($overlay_by_wb as $wb_key => $assoc) {
        if ($wb_key === '' || isset($primary_wb_keys[$wb_key])) {
            continue;
        }
        $line = [];
        foreach ($merged_header as $key) {
            $line[] = $assoc[$key] ?? '';
        }
        $merged[] = $line;
    }

    return $merged;
}

/**
 * When kit_waybills is sparse vs the Waybills source tab, seed from the full source.
 *
 * @param array<int, array<int, mixed>> $kit_rows
 * @return array{rows:array<int,array<int,mixed>>,used_source:bool,kit_count:int,source_count:int,final_count:int}
 */
function kit_seed_resolve_seed_waybill_rows(array $kit_rows): array
{
    $kit_count = max(0, count($kit_rows) - 1);
    $source_rows = kit_seed_read_waybills_source_sheet_rows();
    $source_count = max(0, count($source_rows) - 1);

    if ($source_count <= 0) {
        return [
            'rows' => $kit_rows,
            'used_source' => false,
            'kit_count' => $kit_count,
            'source_count' => 0,
            'final_count' => $kit_count,
        ];
    }

    $converted = kit_seed_convert_source_rows_to_seed_format($source_rows);
    $converted_count = max(0, count($converted) - 1);

    if ($converted_count > 0) {
        $merged = kit_seed_merge_waybill_rows($converted, $kit_rows);
        $final_count = max(0, count($merged) - 1);
        return [
            'rows' => $merged,
            'used_source' => true,
            'kit_count' => $kit_count,
            'source_count' => $source_count,
            'final_count' => $final_count,
        ];
    }

    return [
        'rows' => $kit_rows,
        'used_source' => false,
        'kit_count' => $kit_count,
        'source_count' => $source_count,
        'final_count' => $kit_count,
    ];
}

/**
 * Pick the best customer display label from a Waybills source row.
 *
 * @param array<string, string> $row
 */
function kit_seed_pick_customer_label_from_source_row(array $row): string
{
    $customer = trim((string) ($row['customer'] ?? ''));
    $company = kit_seed_strip_private_company_label(trim((string) ($row['company'] ?? '')));

    if ($company !== '' && kit_seed_customer_looks_like_business($company)) {
        return $company;
    }
    if ($customer !== '' && !kit_seed_customer_field_is_placeholder($customer)) {
        return $customer;
    }
    return $company;
}

/**
 * Normalize company label for dedupe (typos, spacing) — keep aligned with Code.gs where possible.
 */
function kit_seed_normalize_company_label(string $label): string
{
    $label = strtolower(trim(preg_replace('/\s+/', ' ', $label)));
    if ($label === '') {
        return '';
    }
    $label = preg_replace('/\bwildernes\b/', 'wilderness', $label);
    $label = preg_replace('/\bdestinatinos\b/', 'destinations', $label);
    $label = preg_replace('/\s+/', ' ', $label);

    return trim($label);
}

/**
 * Dedupe key for customers — businesses use company: prefix + normalized label.
 */
function kit_seed_customer_dedupe_key(string $label, bool $force_company = false): string
{
    $label = strtolower(trim(preg_replace('/\s+/', ' ', $label)));
    if ($label === '' || kit_seed_customer_field_is_placeholder($label)) {
        return '';
    }
    if (in_array($label, ['individual', 'private'], true)) {
        return '';
    }
    if ($force_company || kit_seed_customer_looks_like_business($label)) {
        $norm = kit_seed_normalize_company_label($label);
        if (class_exists('KIT_Customers')) {
            $norm = KIT_Customers::normalize_company_compare_key($label);
        }
        return $norm !== '' ? 'company:' . $norm : '';
    }

    // Particle-aware person key so "van den Berg" / "Van Der Berg" share a group.
    if (class_exists('KIT_Customers')) {
        $person = KIT_Customers::normalize_person_name_key($label, '');
        return $person !== '' ? 'person:' . $person : '';
    }

    return 'person:' . $label;
}

/**
 * Extend PHP runtime for long Google Sheet seed (admin-post handler + legacy path).
 */
function kit_seed_extend_runtime_limits(): void
{
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ini_set('memory_limit', '512M');
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(true);
    }
}

/** WP cron hook for MAMP / environments without fastcgi_finish_request. */
if (!defined('KIT_SETUP_SEED_CRON_HOOK')) {
    define('KIT_SETUP_SEED_CRON_HOOK', 'kit_setup_seed_cron');
}

/**
 * Associate progress transients with a specific user (AJAX or cron runner).
 */
function kit_seed_set_progress_user(int $uid): void
{
    $GLOBALS['kit_seed_progress_uid'] = max(0, $uid);
}

/**
 * @return int
 */
function kit_seed_get_progress_user(): int
{
    if (!empty($GLOBALS['kit_seed_progress_uid'])) {
        return (int) $GLOBALS['kit_seed_progress_uid'];
    }
    return max(0, (int) get_current_user_id());
}

/**
 * Drop orphaned kit_seed_running_* transients (e.g. MAMP cron fallback never ran).
 */
function kit_seed_release_stale_running_transient(int $uid): bool
{
    if ($uid <= 0) {
        return false;
    }
    $key = 'kit_seed_running_' . $uid;
    $running = get_transient($key);
    if (!$running) {
        return false;
    }

    $started = is_array($running) ? (int) ($running['started'] ?? 0) : (int) $running;
    $phase = is_array($running) ? (string) ($running['phase'] ?? '') : '';
    $age = $started > 0 ? max(0, time() - $started) : 900;

    $cron_hook = defined('KIT_SETUP_SEED_CRON_HOOK') ? KIT_SETUP_SEED_CRON_HOOK : 'kit_setup_seed_cron';
    $cron_pending = function_exists('wp_next_scheduled') && wp_next_scheduled($cron_hook, [$uid]);

    $stale = ($phase === 'queued' && $age >= 30 && !$cron_pending)
        || ($phase === 'starting' && $age >= 120 && !$cron_pending)
        || $age >= 900;

    if (!$stale) {
        return false;
    }

    delete_transient($key);
    return true;
}

/**
 * Update in-flight seed progress (read by kit_ajax_google_sheet_seed_status_handler).
 */
function kit_seed_update_progress(string $phase, int $rows_done = 0, int $rows_total = 0): void
{
    $uid = kit_seed_get_progress_user();
    if ($uid <= 0) {
        return;
    }

    $existing = get_transient('kit_seed_running_' . $uid);
    $started = time();
    if (is_array($existing) && !empty($existing['started'])) {
        $started = (int) $existing['started'];
    } elseif (is_numeric($existing) && (int) $existing > 0) {
        $started = (int) $existing;
    }

    set_transient('kit_seed_running_' . $uid, [
        'started'    => $started,
        'phase'      => $phase,
        'rows_done'  => max(0, $rows_done),
        'rows_total' => max(0, $rows_total),
        'updated_at' => time(),
    ], 900);
}

/**
 * Lean waybill insert for bulk seed (avoids full save_or_update_waybill overhead).
 *
 * @param array<string, mixed> $ctx Built in run_google_sheet_seed main loop
 * @return array{id:int,waybill_no:string}|WP_Error
 */
function kit_seed_lean_insert_waybill(array $ctx)
{
    global $wpdb;

    $waybills_t = $wpdb->prefix . 'kit_waybills';
    $waybill_no = (string) ($ctx['waybill_no'] ?? '');
    if ($waybill_no === '') {
        return new WP_Error('seed_waybill', 'Missing waybill_no for lean insert');
    }

    $product_invoice = kit_seed_normalize_sheet_invoice((string) ($ctx['product_invoice_number'] ?? ''));
    if ($product_invoice !== '') {
        $taken = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$waybills_t} WHERE product_invoice_number = %s",
            $product_invoice
        ));
        if ($taken > 0) {
            $product_invoice = '';
        }
    }
    if ($product_invoice === '' && class_exists('KIT_Waybills')) {
        $product_invoice = KIT_Waybills::generate_product_invoice_number();
    }

    $waybill_items_total = (float) ($ctx['waybill_items_total'] ?? 0);
    $lean_company_id = (int) ($ctx['company_id'] ?? 0);
    $payload = [
        'waybill_no'               => $waybill_no,
        'description'              => (string) ($ctx['description'] ?? ''),
        'direction_id'             => max(1, (int) ($ctx['direction_id'] ?? 1)),
        'delivery_id'              => (int) ($ctx['delivery_id'] ?? 0),
        'customer_id'              => (int) ($ctx['customer_id'] ?? 0),
        'city_id'                  => max(1, (int) ($ctx['city_id'] ?? 1)),
        'product_invoice_number'   => $product_invoice,
        // Freight only — never default to parcels (waybill_items_total).
        'product_invoice_amount'   => (float) ($ctx['product_invoice_amount'] ?? 0),
        'waybill_items_total'      => $waybill_items_total,
        'item_length'              => (float) ($ctx['item_length'] ?? 0),
        'item_width'               => (float) ($ctx['item_width'] ?? 0),
        'item_height'              => (float) ($ctx['item_height'] ?? 0),
        'total_mass_kg'            => (float) ($ctx['total_mass_kg'] ?? 0),
        'total_volume'             => (float) ($ctx['total_volume'] ?? 0),
        'mass_charge'              => (float) ($ctx['mass_charge'] ?? 0),
        'volume_charge'            => (float) ($ctx['volume_charge'] ?? 0),
        'charge_basis'             => (string) ($ctx['charge_basis'] ?? ''),
        'vat_include'              => (int) ($ctx['vat_include'] ?? 0),
        'include_sad500'           => (int) ($ctx['include_sad500'] ?? 0),
        'include_sadc'             => (int) ($ctx['include_sadc'] ?? 0),
        'warehouse'                => (int) ($ctx['warehouse'] ?? 0),
        'approval'                 => (string) ($ctx['approval'] ?? 'pending'),
        'approval_userid'          => (int) ($ctx['approval_userid'] ?? 0),
        'status'                   => 'pending',
        'tracking_number'          => 'TRK-' . strtoupper(substr(md5(uniqid((string) $waybill_no, true)), 0, 8)),
        'created_by'               => (int) ($ctx['created_by'] ?? 1),
        'last_updated_by'          => (int) ($ctx['last_updated_by'] ?? 1),
        'created_at'               => kit_seed_normalize_created_at((string) ($ctx['created_at'] ?? '')) ?: current_time('mysql'),
        'last_updated_at'          => current_time('mysql'),
        'miscellaneous'            => (string) ($ctx['miscellaneous'] ?? ''),
        'misc_total'               => (float) ($ctx['misc_total'] ?? 0),
    ];
    if ($lean_company_id > 0) {
        $payload['company_id'] = $lean_company_id;
    }

    // #region agent log
    static $dbg_insert_logs = 0;
    if ($dbg_insert_logs < 5) {
        $dbg_insert_logs++;
        @file_put_contents(
            (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-0df36b.log',
            json_encode([
                'sessionId' => '0df36b',
                'runId' => 'post-fix',
                'hypothesisId' => 'H1',
                'location' => 'settings.php:kit_seed_lean_insert_waybill',
                'message' => 'insert payload created_at',
                'data' => [
                    'waybill_no' => $waybill_no,
                    'ctx_created_at' => (string) ($ctx['created_at'] ?? ''),
                    'payload_created_at' => (string) $payload['created_at'],
                    'used_current_time' => kit_seed_normalize_created_at((string) ($ctx['created_at'] ?? '')) === '',
                ],
                'timestamp' => (int) round(microtime(true) * 1000),
            ]) . "\n",
            FILE_APPEND
        );
    }
    // #endregion

    $formats = [];
    foreach (array_keys($payload) as $field) {
        if (in_array($field, ['direction_id', 'delivery_id', 'customer_id', 'company_id', 'city_id', 'vat_include', 'include_sad500', 'include_sadc', 'warehouse', 'approval_userid', 'created_by', 'last_updated_by'], true)) {
            $formats[] = '%d';
        } elseif (in_array($field, ['product_invoice_amount', 'waybill_items_total', 'item_length', 'item_width', 'item_height', 'total_mass_kg', 'total_volume', 'mass_charge', 'volume_charge', 'misc_total'], true)) {
            $formats[] = '%f';
        } else {
            $formats[] = '%s';
        }
    }

    $inserted = $wpdb->insert($waybills_t, $payload, $formats);
    if (!$inserted) {
        return new WP_Error('db_error', 'Waybill lean insert failed: ' . $wpdb->last_error);
    }

    $waybill_id = (int) $wpdb->insert_id;

    if (empty($ctx['defer_items']) && !empty($ctx['custom_items']) && is_array($ctx['custom_items']) && class_exists('KIT_Waybills')) {
        KIT_Waybills::save_waybill_items($ctx['custom_items'], $waybill_no, $waybill_id, (int) $payload['vat_include']);
    }

    return ['id' => $waybill_id, 'waybill_no' => $waybill_no];
}

/**
 * Shrink seed result for transient flash (avoid huge session payloads).
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function kit_seed_compact_flash_result(array $result): array
{
    if (!empty($result['stats']['errors']) && is_array($result['stats']['errors'])) {
        $total_errors = count($result['stats']['errors']);
        $result['stats']['errors'] = array_slice($result['stats']['errors'], 0, 25);
        if ($total_errors > 25) {
            $result['stats']['errors_truncated'] = $total_errors - 25;
        }
    }

    if (!empty($result['stats']['verification']) && is_array($result['stats']['verification'])) {
        $v = &$result['stats']['verification'];
        foreach (['mismatches', 'missing', 'extra'] as $k) {
            if (!empty($v[$k]) && is_array($v[$k])) {
                $v[$k] = array_slice($v[$k], 0, 15);
            }
        }
    }

    return $result;
}

/**
 * Finish a long admin-post seed: drain buffers, HTTP redirect when possible, else HTML/JS fallback.
 */
function kit_seed_finish_admin_redirect(string $redirect_url): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // #region agent log
    kit_debug_log_033ead('F', 'kit_seed_finish_admin_redirect', 'pre-redirect', [
        'headers_sent' => headers_sent(),
        'runId' => 'post-fix-v2',
    ]);
    // #endregion

    if (!headers_sent()) {
        wp_safe_redirect($redirect_url);
        exit;
    }

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Seed complete</title>'
        . '<meta http-equiv="refresh" content="0;url=' . esc_attr($redirect_url) . '">'
        . '</head><body><p>Setup seed finished. '
        . '<a href="' . esc_url($redirect_url) . '">Continue to Settings</a>.</p>'
        . '<script>location.replace(' . wp_json_encode($redirect_url) . ');</script>'
        . '</body></html>';
    exit;
}

// #region agent log
function kit_debug_log_033ead(string $hypothesis_id, string $location, string $message, array $data = []): void
{
    $entry = [
        'sessionId' => '033ead',
        'hypothesisId' => $hypothesis_id,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) round(microtime(true) * 1000),
    ];
    @file_put_contents(
        (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-033ead.log',
        wp_json_encode($entry) . "\n",
        FILE_APPEND
    );
}

function kit_debug_log_45951e(string $hypothesis_id, string $location, string $message, array $data = []): void
{
    $entry = [
        'sessionId' => '45951e',
        'hypothesisId' => $hypothesis_id,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) round(microtime(true) * 1000),
    ];
    @file_put_contents(
        (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-45951e.log',
        wp_json_encode($entry) . "\n",
        FILE_APPEND | LOCK_EX
    );
}
// #endregion

/**
 * Find existing cust_id by normalized person/company label (prevents duplicate customer rows).
 */
function kit_seed_find_customer_cust_id_by_label($wpdb, string $customers_t, string $name, string $surname, string $company = '', string $combined = ''): int
{
    $combined = trim($combined);
    if ($combined !== '') {
        $norm = kit_seed_normalize_customer_row('', '', '', $combined);
        if (empty($norm['skip'])) {
            if ($norm['name'] !== '') {
                $name = $norm['name'];
            }
            if ($norm['surname'] !== '') {
                $surname = $norm['surname'];
            }
            if ($norm['company_name'] !== '') {
                $company = $norm['company_name'];
            }
        }
    }

    $company = kit_seed_strip_private_company_label(trim($company));
    if ($company !== '' && kit_seed_customer_looks_like_business($company)) {
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT c.cust_id
             FROM {$customers_t} c
             INNER JOIN {$wpdb->prefix}kit_company_customers co ON c.company_id = co.company_id
             WHERE LOWER(TRIM(co.company_name)) = LOWER(TRIM(%s))
             LIMIT 1",
            $company
        ));
        if ($found > 0) {
            return $found;
        }
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT cust_id FROM {$customers_t} WHERE LOWER(TRIM(name)) = LOWER(TRIM(%s)) AND (surname = '' OR surname IS NULL) LIMIT 1",
            $company
        ));
        if ($found > 0) {
            return $found;
        }
        $norm_want = kit_seed_normalize_company_label($company);
        if ($norm_want !== '') {
            $candidates = $wpdb->get_results(
                "SELECT c.cust_id, c.name, c.surname, co.company_name
                 FROM {$customers_t} c
                 LEFT JOIN {$wpdb->prefix}kit_company_customers co ON c.company_id = co.company_id
                 WHERE TRIM(COALESCE(co.company_name, '')) != '' OR (TRIM(COALESCE(c.surname, '')) = '' AND TRIM(COALESCE(c.name, '')) != '')"
            );
            foreach ($candidates as $candidate) {
                $co = kit_seed_strip_private_company_label(trim((string) ($candidate->company_name ?? '')));
                $biz_label = $co !== '' ? $co : trim((string) ($candidate->name ?? ''));
                if ($biz_label !== '' && kit_seed_normalize_company_label($biz_label) === $norm_want) {
                    return (int) $candidate->cust_id;
                }
            }
        }
    }

    $name = trim($name);
    $surname = trim($surname);
    if ($name !== '' || $surname !== '') {
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT cust_id FROM {$customers_t} WHERE LOWER(TRIM(name)) = LOWER(TRIM(%s)) AND LOWER(TRIM(surname)) = LOWER(TRIM(%s)) LIMIT 1",
            $name,
            $surname
        ));
        if ($found > 0) {
            return $found;
        }
    }

    return 0;
}

/**
 * Find an existing kit_customers row whose display name matches $label
 * (including "Laba Laba" → "Laba Laba Gaia").
 */
function kit_seed_find_customer_id_by_display_label(string $label): int
{
    global $wpdb;
    $label = trim($label);
    if ($label === '' || !function_exists('kit_seed_verification_labels_equivalent')) {
        return 0;
    }
    $customers_t = $wpdb->prefix . 'kit_customers';
    $rows = $wpdb->get_results(
        "SELECT cust_id, name, surname FROM {$customers_t}",
        ARRAY_A
    ) ?: [];
    foreach ($rows as $row) {
        $person = trim(trim((string) ($row['name'] ?? '')) . ' ' . trim((string) ($row['surname'] ?? '')));
        if ($person === '') {
            continue;
        }
        if (
            kit_seed_verification_labels_equivalent('individual', $label, $person)
            || kit_seed_verification_labels_equivalent('company', $label, $person)
        ) {
            return (int) ($row['cust_id'] ?? 0);
        }
    }
    return 0;
}

/**
 * Merge duplicate kit_customers rows that share the same label; keep row with most waybills.
 *
 * @return array{merged:int,deleted:int,waybills_moved:int}
 */
function kit_seed_merge_duplicate_customers(): array
{
    global $wpdb;

    $customers_t = $wpdb->prefix . 'kit_customers';
    $waybills_t = $wpdb->prefix . 'kit_waybills';
    $rows = $wpdb->get_results(
        "SELECT c.cust_id, c.id, c.name, c.surname, co.company_name
         FROM {$customers_t} c
         LEFT JOIN {$wpdb->prefix}kit_company_customers co ON c.company_id = co.company_id"
    );
    $groups = [];

    foreach ($rows as $row) {
        $label = trim((string) ($row->name ?? '') . ' ' . (string) ($row->surname ?? ''));
        $company = kit_seed_strip_private_company_label(trim((string) ($row->company_name ?? '')));
        if ($company !== '' && kit_seed_customer_looks_like_business($company)) {
            $key = kit_seed_customer_dedupe_key($company, true);
        } elseif ($label !== '') {
            $key = kit_seed_customer_dedupe_key($label);
        } else {
            continue;
        }
        if ($key === '') {
            continue;
        }
        $waybill_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$waybills_t} WHERE customer_id = %d",
            (int) $row->cust_id
        ));
        if (!isset($groups[$key])) {
            $groups[$key] = [];
        }
        $groups[$key][] = [
            'cust_id' => (int) $row->cust_id,
            'waybills' => $waybill_count,
            'label' => $company !== '' && kit_seed_customer_looks_like_business($company) ? $company : $label,
            'company_name' => $company,
            'name' => trim((string) ($row->name ?? '')),
            'surname' => trim((string) ($row->surname ?? '')),
        ];
    }

    $merged = 0;
    $deleted = 0;
    $waybills_moved = 0;

    foreach ($groups as $key => $members) {
        if (count($members) < 2) {
            continue;
        }
        // Do not merge distinct people who only share a loose label key
        // (e.g. Tinus @ Rsc Industrial vs Rsc Industrial person row).
        // Use particle-aware normalization so "van den Berg" / "Van Der Berg" can merge.
        $distinct_people = [];
        foreach ($members as $member) {
            if (class_exists('KIT_Customers')) {
                $person = KIT_Customers::normalize_person_name_key(
                    (string) ($member['name'] ?? ''),
                    (string) ($member['surname'] ?? '')
                );
            } else {
                $person = strtolower(trim($member['name'] . ' ' . $member['surname']));
            }
            if ($person !== '') {
                $distinct_people[$person] = true;
            }
        }
        if (count($distinct_people) > 1) {
            continue;
        }
        usort($members, function ($a, $b) {
            if ($a['waybills'] !== $b['waybills']) {
                return $b['waybills'] <=> $a['waybills'];
            }
            return $a['cust_id'] <=> $b['cust_id'];
        });
        $canonical = (int) $members[0]['cust_id'];
        kit_debug_log_033ead('C', 'kit_seed_merge_duplicate_customers', 'duplicate group', [
            'key' => $key,
            'label' => $members[0]['label'],
            'canonical' => $canonical,
            'members' => $members,
        ]);
        for ($i = 1; $i < count($members); $i++) {
            $dup = (int) $members[$i]['cust_id'];
            $wpdb->update(
                $waybills_t,
                ['customer_id' => $canonical],
                ['customer_id' => $dup],
                ['%d'],
                ['%d']
            );
            $waybills_moved += (int) $wpdb->rows_affected;
            $wpdb->delete($customers_t, ['cust_id' => $dup], ['%d']);
            if ($wpdb->rows_affected > 0) {
                $deleted++;
            }
            $merged++;
        }
        if (strpos($key, 'company:') === 0) {
            $best_label = '';
            foreach ($members as $member) {
                $candidate = trim((string) ($member['company_name'] ?? ''));
                if ($candidate === '' && kit_seed_customer_looks_like_business(trim((string) ($member['name'] ?? '')))) {
                    $candidate = trim((string) ($member['name'] ?? ''));
                }
                if ($candidate === '') {
                    $candidate = trim((string) ($member['label'] ?? ''));
                }
                if ($candidate !== '' && strlen($candidate) > strlen($best_label)) {
                    $best_label = $candidate;
                }
            }
            if ($best_label !== '') {
                $keep_update = [
                    'name' => $best_label,
                    'surname' => '',
                ];
                $keep_formats = ['%s', '%s'];
                if (class_exists('KIT_Company_Customers')) {
                    $linked = (int) KIT_Company_Customers::ensure_company($best_label);
                    if ($linked > 0) {
                        $keep_update['company_id'] = $linked;
                        $keep_formats[] = '%d';
                    }
                }
                $wpdb->update(
                    $customers_t,
                    $keep_update,
                    ['cust_id' => $canonical],
                    $keep_formats,
                    ['%d']
                );
            }
        }
    }

    return ['merged' => $merged, 'deleted' => $deleted, 'waybills_moved' => $waybills_moved];
}

/**
 * Build cust_id registry: one entry per unique Waybills CUSTOMER label (~300 rows).
 *
 * @param array<int, array<int, mixed>> $rows
 * @param array<string, int> $col
 * @param array<string, mixed> $waybills_lookup
 * @return array<string, array{cust_id:int,label:string,source:array<string,string>}>
 */
function kit_seed_build_customer_dedupe_registry(array $rows, array $col, array $waybills_lookup, callable $get, callable $normalize_customer_label): array
{
    global $wpdb;

    $registry = [];
    $customers_t = $wpdb->prefix . 'kit_customers';
    $min_id = defined('KIT_SEED_CUSTOMER_MIN_ID') ? (int) KIT_SEED_CUSTOMER_MIN_ID : 8600;
    $next_id = $min_id;

    $existing_cust_ids = array_map('intval', $wpdb->get_col("SELECT cust_id FROM {$customers_t}") ?: []);
    $existing_cust_id_set = array_fill_keys($existing_cust_ids, true);
    if (!empty($existing_cust_ids)) {
        $next_id = max($next_id, max($existing_cust_ids) + 1);
    }
    // An invented cust_id must not land on a company id either: the two are
    // looked up against each other when a waybill party is resolved, so a
    // shared number silently reassigns the waybill to that company.
    $companies_t = $wpdb->prefix . 'kit_company_customers';
    $taken_company_ids = array_fill_keys(
        array_map('intval', $wpdb->get_col("SELECT company_id FROM {$companies_t}") ?: []),
        true
    );

    $resolve_label = function (array $row) use ($col, $waybills_lookup, $get, $normalize_customer_label): string {
        $label = $normalize_customer_label($get($row, ['cust_name_ignore', 'cust_name_ig', 'customer', 'cust_name', 'client'], ''));
        if ($label !== '') {
            return $label;
        }
        $wb_key = kit_seed_normalize_waybill_no_key($get($row, ['waybill_no', 'parcel_id', 'waybill_#', 'wb_no'], ''));
        if ($wb_key === '') {
            return '';
        }
        $source = $waybills_lookup['by_waybill'][$wb_key] ?? null;
        if (is_array($source) && !empty($source['customer'])) {
            return $normalize_customer_label((string) $source['customer']);
        }
        return '';
    };

    $resolve_source = function (array $row) use ($col, $waybills_lookup, $get): array {
        $wb_key = kit_seed_normalize_waybill_no_key($get($row, ['waybill_no', 'parcel_id', 'waybill_#', 'wb_no'], ''));
        if ($wb_key === '') {
            return [];
        }
        $source = $waybills_lookup['by_waybill'][$wb_key] ?? null;
        return is_array($source) ? $source : [];
    };

    foreach (array_slice($rows, 1) as $row) {
        $label = $resolve_label($row);
        $key = kit_seed_customer_dedupe_key($label);
        if ($key === '') {
            continue;
        }
        $sheet_cust_id = (int) preg_replace('/[^0-9]/', '', $get($row, 'customer_id', '0'));
        if ($sheet_cust_id > 0) {
            $source = $resolve_source($row);
            $use_id = 0;
            if (isset($existing_cust_id_set[$sheet_cust_id])) {
                $use_id = $sheet_cust_id;
            } elseif (function_exists('kit_seed_find_customer_id_by_display_label')) {
                $company = trim((string) ($source['company'] ?? ''));
                if ($company !== '') {
                    $use_id = kit_seed_find_customer_id_by_display_label($company);
                }
                if ($use_id <= 0) {
                    $use_id = kit_seed_find_customer_id_by_display_label($label);
                }
            }
            if ($use_id > 0 && (!isset($registry[$key]) || (int) $registry[$key]['cust_id'] === $sheet_cust_id || (int) $registry[$key]['cust_id'] === $use_id)) {
                $registry[$key] = [
                    'cust_id' => $use_id,
                    'label' => $label,
                    'source' => $source,
                ];
            }
            if (isset($existing_cust_id_set[$sheet_cust_id])) {
                $next_id = max($next_id, $sheet_cust_id + 1);
            }
        }
    }

    foreach (array_slice($rows, 1) as $row) {
        $label = $resolve_label($row);
        $key = kit_seed_customer_dedupe_key($label);
        if ($key === '' || isset($registry[$key])) {
            continue;
        }

        $existing_cust_id = kit_seed_find_customer_cust_id_by_label($wpdb, $customers_t, '', '', '', $label);
        if ($existing_cust_id <= 0 && function_exists('kit_seed_find_customer_id_by_display_label')) {
            $src = $resolve_source($row);
            $company = trim((string) ($src['company'] ?? ''));
            if ($company !== '') {
                $existing_cust_id = kit_seed_find_customer_id_by_display_label($company);
            }
            if ($existing_cust_id <= 0) {
                $existing_cust_id = kit_seed_find_customer_id_by_display_label($label);
            }
        }
        if ($existing_cust_id > 0) {
            $registry[$key] = [
                'cust_id' => $existing_cust_id,
                'label' => $label,
                'source' => $resolve_source($row),
            ];
            continue;
        }

        while (isset($existing_cust_id_set[$next_id]) || isset($taken_company_ids[$next_id])) {
            $next_id++;
        }

        $registry[$key] = [
            'cust_id' => $next_id,
            'label' => $label,
            'source' => $resolve_source($row),
        ];
        $existing_cust_id_set[$next_id] = true;
        $next_id++;
    }

    return $registry;
}

/**
 * After seed, ensure each waybill.customer_id matches the dedupe registry cust_id for its sheet label.
 *
 * @param array<string, array{cust_id:int,label:string,source:array<string,string>}> $customer_registry
 * @param array<string, mixed>|null $waybills_lookup
 */
function kit_seed_relink_waybill_customer_ids($wpdb, string $waybills_t, array $customer_registry, array $rows, array $col, callable $get, callable $normalize_customer_label, ?array $waybills_lookup = null): int
{
    $updated = 0;
    $lookup = is_array($waybills_lookup) ? $waybills_lookup : ['by_waybill' => []];

    $resolve_label = function (array $row) use ($col, $lookup, $get, $normalize_customer_label): string {
        $label = $normalize_customer_label($get($row, ['cust_name_ignore', 'cust_name_ig', 'customer', 'cust_name', 'client'], ''));
        if ($label !== '') {
            return $label;
        }
        $wb_key = kit_seed_normalize_waybill_no_key($get($row, ['waybill_no', 'parcel_id', 'waybill_#', 'wb_no'], ''));
        if ($wb_key === '') {
            return '';
        }
        $source = $lookup['by_waybill'][$wb_key] ?? null;
        if (is_array($source) && !empty($source['customer'])) {
            return $normalize_customer_label((string) $source['customer']);
        }
        return '';
    };

    $customers_t = $wpdb->prefix . 'kit_customers';
    foreach (array_slice($rows, 1) as $row) {
        $waybill_no = $get($row, ['parcel_id', 'waybill_no', 'wb_no', 'waybill', 'waybill_#', 'newwb', 'parcel_no', 'no', 'number'], '');
        if ($waybill_no === '') {
            continue;
        }
        $waybill_no = (string) preg_replace('/[^0-9A-Za-z\-]/', '', (string) $waybill_no);
        if ($waybill_no === '') {
            continue;
        }

        // Prefer the sheet customer_id when that individual still exists — do not
        // steal the waybill onto another person who only shares a company_name label
        // (e.g. "Tyre Plus" id 9506 vs Ben Pelser company_name "Tyre Plus").
        $sheet_cust_id = (int) preg_replace('/[^0-9]/', '', $get($row, 'customer_id', '0'));
        $cust_id = 0;
        if ($sheet_cust_id > 0) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT cust_id FROM {$customers_t} WHERE cust_id = %d LIMIT 1",
                $sheet_cust_id
            ));
            if ($exists > 0) {
                $cust_id = $sheet_cust_id;
            }
        }
        if ($cust_id <= 0) {
            $label = $resolve_label($row);
            $key = kit_seed_customer_dedupe_key($label);
            if ($key === '' || !isset($customer_registry[$key])) {
                continue;
            }
            $cust_id = (int) ($customer_registry[$key]['cust_id'] ?? 0);
        }
        if ($cust_id <= 0) {
            continue;
        }
        $exists_final = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT cust_id FROM {$customers_t} WHERE cust_id = %d LIMIT 1",
            $cust_id
        ));
        if ($exists_final <= 0) {
            $label = $resolve_label($row);
            $wb_key = kit_seed_normalize_waybill_no_key((string) $waybill_no);
            $source = ($wb_key !== '' && isset($lookup['by_waybill'][$wb_key]) && is_array($lookup['by_waybill'][$wb_key]))
                ? $lookup['by_waybill'][$wb_key]
                : [];
            $cust_id = 0;
            if (function_exists('kit_seed_find_customer_id_by_display_label')) {
                $company = trim((string) ($source['company'] ?? ''));
                if ($company !== '') {
                    $cust_id = kit_seed_find_customer_id_by_display_label($company);
                }
                if ($cust_id <= 0 && $label !== '') {
                    $cust_id = kit_seed_find_customer_id_by_display_label($label);
                }
            }
            if ($cust_id <= 0) {
                continue;
            }
        }

        // Skip relink when waybill is company-owned.
        $company_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT company_id FROM {$waybills_t} WHERE waybill_no = %s LIMIT 1",
            $waybill_no
        ));
        if ($company_id > 0) {
            continue;
        }

        $wpdb->update(
            $waybills_t,
            ['customer_id' => $cust_id],
            ['waybill_no' => $waybill_no],
            ['%d'],
            ['%s']
        );
        if ($wpdb->rows_affected > 0) {
            $updated++;
        }
    }

    return $updated;
}

/**
 * Insert/update all customers from the dedupe registry before waybill rows run.
 *
 * @param array<string, array{cust_id:int,label:string,source:array<string,string>}> $registry
 */
function kit_seed_prefill_customers_from_registry(array $registry, $wpdb, string $customers_t, array &$stats): void
{
    foreach ($registry as $entry) {
        $cust_id = (int) ($entry['cust_id'] ?? 0);
        $label = trim((string) ($entry['label'] ?? ''));
        $source = is_array($entry['source'] ?? null) ? $entry['source'] : [];
        if ($cust_id <= 0 || $label === '') {
            continue;
        }
        if (in_array(strtolower($label), ['individual', 'private'], true)) {
            continue;
        }

        // Business labels / existing company ids: company table only (ensure_customer_exists routes too).
        $ensured = kit_seed_ensure_customer_exists(
            $wpdb,
            $customers_t,
            $cust_id,
            $label,
            $stats,
            trim((string) ($source['company'] ?? ''))
        );
        if ($ensured > 0 && !empty($source)) {
            kit_seed_apply_source_contact_to_customer($ensured, $source, $label);
        }
    }
}

/**
 * Apply Waybills source contact fields onto an existing kit_customers row (blank fields only).
 */
function kit_seed_apply_source_contact_to_customer(int $cust_id, array $source, string $fallback_label = ''): bool
{
    global $wpdb;

    if ($cust_id <= 0) {
        return false;
    }

    $customers_table = $wpdb->prefix . 'kit_customers';
    $customer = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT c.id, c.name, c.surname, c.cell, c.email_address, c.address, c.company_id, co.company_name
             FROM {$customers_table} c
             LEFT JOIN {$wpdb->prefix}kit_company_customers co ON c.company_id = co.company_id
             WHERE c.cust_id = %d LIMIT 1",
            $cust_id
        ),
        ARRAY_A
    );
    if (empty($customer)) {
        return false;
    }

    $source_customer = trim((string) ($source['customer'] ?? ''));
    $source_company = kit_seed_strip_private_company_label(trim((string) ($source['company'] ?? '')));
    if ($source_customer === '' && $source_company === '' && $fallback_label === '') {
        return false;
    }

    $name_parts = preg_split('/\s+/', $source_customer !== '' ? $source_customer : $fallback_label, 2);
    $first = trim((string) ($name_parts[0] ?? ''));
    $last = trim((string) ($name_parts[1] ?? ''));
    $normalized = kit_seed_normalize_customer_row($first, $last, $source_company, $fallback_label);
    if (!empty($normalized['skip'])) {
        return false;
    }

    $payload = [];
    $formats = [];
    $is_placeholder = static function ($value): bool {
        $v = strtolower(trim((string) $value));
        return $v === '' || in_array($v, ['0', 'null', 'n/a', 'na', 'none', '-', '--'], true);
    };

    $sync_name_to_company = class_exists('KIT_Customers')
        && KIT_Customers::should_sync_name_to_company(
            $normalized['name'],
            $normalized['surname'],
            $normalized['company_name']
        );

    if ($sync_name_to_company || ($is_placeholder($customer['name'] ?? '') && $normalized['name'] !== '')) {
        $payload['name'] = $normalized['name'];
        $formats[] = '%s';
    }
    if ($sync_name_to_company || ($is_placeholder($customer['surname'] ?? '') && $normalized['surname'] !== '')) {
        $payload['surname'] = $normalized['surname'];
        $formats[] = '%s';
    } elseif ($sync_name_to_company) {
        $payload['surname'] = '';
        $formats[] = '%s';
    }
    // Company affiliation for named people comes from kit_customers sheet, not a single
    // Waybills Company cell (Roxanne + Uniques on one WB must not rewrite her master row).
    $customer_has_person = !$is_placeholder($customer['name'] ?? '')
        || !$is_placeholder($customer['surname'] ?? '');
    if (
        !$customer_has_person
        && empty($customer['company_id'])
        && $normalized['company_name'] !== ''
        && class_exists('KIT_Company_Customers')
    ) {
        $linked = (int) KIT_Company_Customers::ensure_company($normalized['company_name']);
        if ($linked > 0) {
            $payload['company_id'] = $linked;
            $formats[] = '%d';
        }
    }
    if ($is_placeholder($customer['cell'] ?? '') && !empty($source['cell'])) {
        $payload['cell'] = trim((string) $source['cell']);
        $formats[] = '%s';
    }
    if ($is_placeholder($customer['email_address'] ?? '') && !empty($source['email'])) {
        $payload['email_address'] = trim((string) $source['email']);
        $formats[] = '%s';
    }
    if ($is_placeholder($customer['address'] ?? '') && !empty($source['address'])) {
        $payload['address'] = trim((string) $source['address']);
        $formats[] = '%s';
    }

    if (empty($payload)) {
        return false;
    }

    $wpdb->update($customers_table, $payload, ['cust_id' => $cust_id], $formats, ['%d']);
    return $wpdb->rows_affected > 0;
}

/**
 * Backfill kit_customers rows that still have blank name/company after seeding.
 *
 * @param array<string, mixed> $waybills_source_lookup
 */
function kit_seed_backfill_empty_customers_from_source(array $waybills_source_lookup): int
{
    global $wpdb;

    if (empty($waybills_source_lookup['by_waybill']) || !is_array($waybills_source_lookup['by_waybill'])) {
        return 0;
    }

    $customers_t = $wpdb->prefix . 'kit_customers';
    $waybills_t = $wpdb->prefix . 'kit_waybills';
    $empty_rows = $wpdb->get_results(
        "SELECT cust_id FROM {$customers_t}
         WHERE TRIM(COALESCE(name, '')) = ''
           AND TRIM(COALESCE(surname, '')) = ''
           AND (company_id IS NULL OR company_id = 0)"
    );
    if (empty($empty_rows)) {
        return 0;
    }

    $updated = 0;
    foreach ($empty_rows as $row) {
        $cust_id = (int) ($row->cust_id ?? 0);
        if ($cust_id <= 0) {
            continue;
        }
        $waybill_no = $wpdb->get_var($wpdb->prepare(
            "SELECT waybill_no FROM {$waybills_t} WHERE customer_id = %d ORDER BY id ASC LIMIT 1",
            $cust_id
        ));
        if (!$waybill_no) {
            continue;
        }
        $wb_key = kit_seed_normalize_waybill_no_key((string) $waybill_no);
        $source = $waybills_source_lookup['by_waybill'][$wb_key] ?? null;
        if (!is_array($source)) {
            continue;
        }

        $label = trim(kit_seed_pick_customer_label_from_source_row($source));
        if ($label === '' || kit_seed_customer_field_is_placeholder($label)) {
            continue;
        }

        if (kit_seed_apply_source_contact_to_customer($cust_id, $source, $label)) {
            $updated++;
        }
    }

    return $updated;
}

/**
 * Create a customer row when a waybill references customer_id but kit_customers is empty/missing that id.
 * Never creates blank person stubs. Business labels / existing company IDs go to kit_company_customers.
 *
 * @param array<string,mixed> $stats
 * @return int cust_id when an individual row exists/was created; 0 when routed to company or blocked
 */
function kit_seed_ensure_customer_exists($wpdb, string $customers_t, int $cust_id, string $cust_label, array &$stats, string $company_label = ''): int
{
    $company_label = trim($company_label);
    if (function_exists('kit_seed_strip_private_company_label')) {
        $company_label = kit_seed_strip_private_company_label($company_label);
    }

    if ($company_label !== '' && function_exists('kit_seed_find_customer_id_by_display_label')) {
        $via_company_col = kit_seed_find_customer_id_by_display_label($company_label);
        if ($via_company_col > 0) {
            return $via_company_col;
        }
    }

    if ($cust_id <= 0) {
        return 0;
    }

    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$customers_t} WHERE cust_id = %d LIMIT 1",
        $cust_id
    ));
    if ($exists > 0) {
        if ($cust_label !== '') {
            $matched = kit_seed_find_customer_cust_id_by_label($wpdb, $customers_t, '', '', '', $cust_label);
            if ($matched > 0) {
                return $matched;
            }
        }
        return $cust_id;
    }

    $label = trim($cust_label);
    if (in_array(strtolower($label), ['individual', 'private'], true)) {
        $label = '';
    }

    // Already a company party — do not mirror into kit_customers.
    if (class_exists('KIT_Company_Customers') && KIT_Company_Customers::find_by_company_id($cust_id)) {
        return 0;
    }

    // Business-only labels belong on kit_company_customers when that tab already
    // has them. Do not invent a company id the roster does not list (Gaia Ltd).
    if (
        $label !== ''
        && class_exists('KIT_Company_Customers')
        && function_exists('kit_seed_customer_looks_like_business')
        && kit_seed_customer_looks_like_business($label)
    ) {
        if (function_exists('kit_seed_find_customer_id_by_display_label')) {
            $via_label = kit_seed_find_customer_id_by_display_label($label);
            if ($via_label > 0) {
                return $via_label;
            }
        }
        $existing_co = KIT_Company_Customers::find_by_name($label);
        if ($existing_co) {
            return 0;
        }
        return 0;
    }

    $normalized = kit_seed_normalize_customer_row('', '', '', $label);
    if (!empty($normalized['skip'])) {
        $normalized = [
            'name' => '',
            'surname' => '',
            'company_name' => '',
            'skip' => false,
        ];
    }

    $insert_payload = [
        'cust_id' => $cust_id,
        'name' => $normalized['name'],
        'surname' => $normalized['surname'],
        'country_id' => 0,
    ];
    $is_blank = trim((string) $insert_payload['name']) === ''
        && trim((string) $insert_payload['surname']) === '';
    // #region agent log
    {
        $dbg = [
            'sessionId' => '3f0725',
            'runId' => 'post-fix',
            'hypothesisId' => $is_blank ? 'A' : 'A2',
            'location' => 'settings.php:kit_seed_ensure_customer_exists',
            'message' => $is_blank ? 'blocked BLANK customer stub' : 'inserting customer stub',
            'data' => [
                'cust_id' => $cust_id,
                'raw_label' => $cust_label,
                'stripped_label' => $label,
                'name' => $insert_payload['name'],
                'surname' => $insert_payload['surname'],
                'inserted' => !$is_blank,
            ],
            'timestamp' => (int) round(microtime(true) * 1000),
        ];
        @file_put_contents(
            (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-3f0725.log',
            json_encode($dbg) . "\n",
            FILE_APPEND
        );
    }
    // #endregion

    if ($is_blank) {
        return 0;
    }

    $wpdb->insert($customers_t, $insert_payload, ['%d', '%s', '%s', '%d']);
    if ($wpdb->insert_id) {
        $stats['customers'] = (int) ($stats['customers'] ?? 0) + 1;
    }

    return $cust_id;
}

/**
 * Seed customers from dedicated sheet rows (kit_customers/customers).
 *
 * @param array $rows Header + rows
 * @return array{inserted:int,updated:int,skipped:int}
 */
function kit_seed_customers_from_sheet_rows(array $rows): array
{
global $wpdb;

$stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
if (empty($rows) || count($rows) < 2) {
    return $stats;
}

$header = array_map(function ($c) {
    $h = strtolower(trim((string) $c));
    return str_replace(' ', '_', $h);
}, $rows[0]);
$col = [];
foreach ($header as $i => $h) {
    if ($h !== '') {
        $col[$h] = $i;
    }
}

$get = function (array $row, $keys, string $default = '') use ($col): string {
    $keys = is_array($keys) ? $keys : [$keys];
    foreach ($keys as $k) {
        $idx = $col[$k] ?? null;
        if ($idx !== null) {
            $value = trim((string) ($row[$idx] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }
    return $default;
};
$customers_t = $wpdb->prefix . 'kit_customers';
foreach (array_slice($rows, 1) as $row) {
    $cust_id = (int) preg_replace('/[^0-9]/', '', $get($row, ['cust_id', 'customer_id'], '0'));
    $combined = $get($row, ['combined_name_ignore', 'combined_name', 'customer'], '');
    $normalized = kit_seed_normalize_customer_row(
        $get($row, ['name', 'customer_name'], ''),
        $get($row, ['surname', 'customer_surname', 'last_name'], ''),
        $get($row, ['company_name'], ''),
        $combined,
        false
    );
    if (!empty($normalized['skip'])) {
        $stats['skipped']++;
        continue;
    }
    $name = $normalized['name'];
    $surname = $normalized['surname'];
    $company = $normalized['company_name'];
    $cell = $get($row, ['cell', 'phone', 'mobile'], '');
    $telephone = $get($row, ['telephone', 'tel'], '');
    $email = $get($row, ['email_address', 'email'], '');
    $country_id = (int) preg_replace('/[^0-9]/', '', $get($row, 'country_id', '0'));
    $city_id = (int) preg_replace('/[^0-9]/', '', $get($row, 'city_id', '0'));
    $vat_number = $get($row, 'vat_number', '');
    $address = $get($row, 'address', '');

    if ($name === '' && $surname === '' && $company === '' && $cell === '' && $email === '') {
        $stats['skipped']++;
        continue;
    }

    // Business rows go to kit_company_customers; keep a distinct person when present (mixed).
    // Explicit sheet company_name is never skipped just because it lacks Ltd/Lodge keywords
    // (HEMOINSA, Grumeti, Terra Tools, …). Name-only labels still need looks_like_business.
    $linked_company_id = 0;
    $explicit_company = $company !== '';
    $biz_label = $explicit_company ? $company : trim($name . ' ' . $surname);
    // kit_customers is the person roster. Only an explicit company_name column
    // routes a row to kit_company_customers — name-only labels like
    // "Eye Emporium" stay as customers so the tab count holds.
    $biz_ok = $explicit_company
        && $biz_label !== ''
        && class_exists('KIT_Company_Customers');
    if ($biz_ok) {
        $person_full = trim($name . ' ' . $surname);
        $has_real_person = $person_full !== ''
            && strcasecmp($person_full, $biz_label) !== 0
            && strcasecmp($name, $biz_label) !== 0
            && !(
                function_exists('kit_seed_customer_looks_like_business')
                && kit_seed_customer_looks_like_business($person_full)
            );
        // #region agent log
        if (in_array($cust_id, [9602, 3294], true) || strcasecmp($biz_label, 'Dom') === 0) {
            $dbg = [
                'sessionId' => '3f0725',
                'runId' => 'post-fix',
                'hypothesisId' => 'B',
                'location' => 'settings.php:kit_seed_customers_from_sheet_rows',
                'message' => $has_real_person ? 'company + keep person (mixed)' : 'company-only branch',
                'data' => [
                    'cust_id' => $cust_id,
                    'name' => $name,
                    'surname' => $surname,
                    'company' => $company,
                    'biz_label' => $biz_label,
                    'has_real_person' => $has_real_person,
                ],
                'timestamp' => (int) round(microtime(true) * 1000),
            ];
            @file_put_contents(
                (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-3f0725.log',
                json_encode($dbg) . "\n",
                FILE_APPEND
            );
        }
        // #endregion
        $preferred = $cust_id > 0 ? $cust_id : 0;
        $company_id = KIT_Company_Customers::ensure_company($biz_label, [
            'cell' => $cell,
            'telephone' => $telephone,
            'email_address' => $email,
            'country_id' => $country_id ?: null,
            'city_id' => $city_id ?: null,
            'vat_number' => $vat_number,
            'address' => $address,
        ], $preferred);
        if ($company_id > 0) {
            if ($preferred > 0 && KIT_Company_Customers::find_by_company_id($preferred)) {
                KIT_Company_Customers::update_company($company_id, [
                    'company_name' => $biz_label,
                    'cell' => $cell,
                    'telephone' => $telephone,
                    'email_address' => $email,
                    'vat_number' => $vat_number,
                    'address' => $address,
                    'country_id' => $country_id ?: null,
                    'city_id' => $city_id ?: null,
                ]);
                $stats['updated']++;
            } else {
                $stats['inserted']++;
            }
            KIT_Company_Customers::link_waybills_to_company($company_id, $preferred > 0 ? $preferred : $company_id);
        } else {
            $stats['skipped']++;
        }
        if (!$has_real_person) {
            continue;
        }
        // Mixed: person stays; company lives in company table + person.company_id FK.
        $company = '';
        $linked_company_id = $company_id > 0 ? (int) $company_id : 0;
    }

    $payload = [
        'name' => $name,
        'surname' => $surname,
        'cell' => $cell,
        'telephone' => $telephone,
        'email_address' => $email,
        'country_id' => $country_id,
        'city_id' => $city_id,
        'vat_number' => $vat_number,
        'address' => $address,
    ];
    $format = ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'];
    // Person→company FK: set from mixed business company_name; otherwise clear stale FKs
    // (e.g. Roxanne Crag wrongly linked to Uniques when sheet company_name is blank).
    static $customers_have_company_id_col = null;
    if ($customers_have_company_id_col === null) {
        $customers_have_company_id_col = (bool) $wpdb->get_var($wpdb->prepare(
            'SHOW COLUMNS FROM `' . $customers_t . '` LIKE %s',
            'company_id'
        ));
    }
    if ($customers_have_company_id_col) {
        if ($linked_company_id > 0) {
            $payload['company_id'] = $linked_company_id;
            $format[] = '%d';
        } else {
            $payload['company_id'] = null;
            $format[] = '%s';
        }
    }

    if ($cust_id > 0) {
        // Same person under two ids. The lower id wins and the other is merged
        // into it, which has to hold whichever row the loop sees first: the
        // sheet lists "Dr Sabine Marten" as both 8620 and 8884, so keeping the
        // id of the row being processed made the two rows delete each other
        // every run and left her waybills (which cite 8620) pointing at
        // nothing — and company 8620 "Kiwango Security" answered for them.
        $merge_stub_cust_id = 0;
        $existing_by_label = kit_seed_find_customer_cust_id_by_label($wpdb, $customers_t, $name, $surname, $company, $combined);
        if ($existing_by_label > 0 && $existing_by_label !== $cust_id) {
            kit_debug_log_033ead('A', 'kit_seed_customers_from_sheet_rows', 'duplicate label collapsed onto lower cust_id', [
                'sheet_cust_id' => $cust_id,
                'existing_cust_id' => $existing_by_label,
                'name' => $name,
                'surname' => $surname,
                'company' => $company,
            ]);
            $merge_stub_cust_id = max($cust_id, $existing_by_label);
            $cust_id = min($cust_id, $existing_by_label);
        }
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$customers_t} WHERE cust_id = %d LIMIT 1", $cust_id));
        if ($existing > 0) {
            $wpdb->update($customers_t, $payload, ['cust_id' => $cust_id], $format, ['%d']);
            $stats['updated']++;
        } else {
            // wpdb maps $format to $data in array order — cust_id must be first when format starts with %d.
            $insert_payload = ['cust_id' => $cust_id] + $payload;
            // #region agent log
            {
                $is_blank = trim((string) ($payload['name'] ?? '')) === ''
                    && trim((string) ($payload['surname'] ?? '')) === ''
                    && trim((string) ($payload['company_name'] ?? '')) === '';
                if ($is_blank || in_array($cust_id, [9602, 3294], true)) {
                    $dbg = [
                        'sessionId' => '3f0725',
                        'runId' => 'blank-cust',
                        'hypothesisId' => 'D',
                        'location' => 'settings.php:kit_seed_customers_from_sheet_rows:insert',
                        'message' => $is_blank ? 'person insert BLANK from sheet' : 'person insert from sheet',
                        'data' => [
                            'cust_id' => $cust_id,
                            'payload' => [
                                'name' => $payload['name'] ?? '',
                                'surname' => $payload['surname'] ?? '',
                                'company_name' => $payload['company_name'] ?? '',
                            ],
                        ],
                        'timestamp' => (int) round(microtime(true) * 1000),
                    ];
                    @file_put_contents(
                        (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-3f0725.log',
                        json_encode($dbg) . "\n",
                        FILE_APPEND
                    );
                }
            }
            // #endregion
            $wpdb->insert($customers_t, $insert_payload, array_merge(['%d'], $format));
            $stats['inserted']++;
        }
        if ($merge_stub_cust_id > 0 && class_exists('KIT_Customers') && method_exists('KIT_Customers', 'merge_customers')) {
            $merged = KIT_Customers::merge_customers($cust_id, $merge_stub_cust_id);
            if (!empty($merged['ok'])) {
                $stats['merged'] = (int) ($stats['merged'] ?? 0) + 1;
            }
        }
        continue;
    }

    $existing = 0;
    if ($name !== '' || $surname !== '') {
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$customers_t} WHERE name = %s AND surname = %s LIMIT 1",
            $name,
            $surname
        ));
    }
    if ($existing > 0) {
        $wpdb->update($customers_t, $payload, ['id' => $existing], $format, ['%d']);
        $stats['updated']++;
        continue;
    }

    $next_cust_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(cust_id), 999) + 1 FROM {$customers_t}");
    if ($next_cust_id <= 0) {
        $next_cust_id = rand(1000, 9999);
    }
    $insert_payload = ['cust_id' => $next_cust_id] + $payload;
    $wpdb->insert($customers_t, $insert_payload, array_merge(['%d'], $format));
    $stats['inserted']++;
}

return $stats;
}

/**
 * Waybills column A / kit_waybills status tokens that must never become kit_drivers rows.
 */
function kit_seed_non_driver_name_tokens(): array
{
    return [
        'import driver',
        'cancel',
        'cancelled',
        'canceled',
        'collected',
        'duplicate',
        'warehouse',
    ];
}

/**
 * True when a label is a sheet status/sentinel, not a real driver name.
 */
function kit_seed_is_non_driver_name(string $candidate): bool
{
    $needle = strtolower(trim($candidate));
    if ($needle === '') {
        return true;
    }
    if (in_array($needle, kit_seed_non_driver_name_tokens(), true)) {
        return true;
    }
    foreach (['duplicate', 'cancelled', 'canceled', 'cancel', 'warehouse'] as $fragment) {
        if (strpos($needle, $fragment) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Prefer 08600AfricaWaybills Drivers tab; fall back to the live workbook.
 *
 * @return array<int, array<int, mixed>>
 */
function kit_seed_read_driver_sheet_rows(): array
{
    if (!class_exists('Courier_Google_Sheets') || !Courier_Google_Sheets::is_configured()) {
        return [];
    }

    $ids = [];
    if (method_exists('Courier_Google_Sheets', 'get_drivers_spreadsheet_id')) {
        $ids[] = Courier_Google_Sheets::get_drivers_spreadsheet_id();
    }
    $ids[] = Courier_Google_Sheets::get_source_spreadsheet_id();
    $ids = array_values(array_unique(array_filter($ids)));

    $tabs = ['kit_drivers', 'Drivers', 'drivers'];
    $fallback = [];
    foreach ($ids as $sid) {
        foreach ($tabs as $tab) {
            try {
                $rows = Courier_Google_Sheets::get_values((string) $sid, 'A1:Z500', $tab);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($rows) || count($rows) < 2) {
                continue;
            }
            $names = [];
            foreach (array_slice($rows, 1) as $row) {
                $names[] = strtolower(trim((string) ($row[1] ?? $row[0] ?? '')));
            }
            $joined = ' ' . implode(' ', $names) . ' ';
            // Authoritative roster includes James / Luckson / Lloyd, not sync sentinels.
            if (strpos($joined, ' james ') !== false || strpos($joined, ' luckson ') !== false) {
                return $rows;
            }
            if ($fallback === []) {
                $fallback = $rows;
            }
        }
    }

    return $fallback;
}

/**
 * Seed drivers from dedicated sheet rows (kit_drivers/drivers).
 *
 * @param array $rows Header + rows
 * @return array{inserted:int,updated:int,skipped:int}
 */
function kit_seed_drivers_from_sheet_rows(array $rows): array
{
global $wpdb;

$stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
if (empty($rows) || count($rows) < 2) {
    return $stats;
}

$header = array_map(function ($c) {
    $h = strtolower(trim((string) $c));
    return str_replace(' ', '_', $h);
}, $rows[0]);
$col = [];
foreach ($header as $i => $h) {
    if ($h !== '') {
        $col[$h] = $i;
    }
}

$get = function (array $row, $keys, string $default = '') use ($col): string {
    $keys = is_array($keys) ? $keys : [$keys];
    foreach ($keys as $k) {
        $idx = $col[$k] ?? null;
        if ($idx !== null) {
            $value = trim((string) ($row[$idx] ?? ''));
            if ($value !== '' && $value[0] !== '#') {
                return $value;
            }
        }
    }
    return $default;
};

$drivers_t = $wpdb->prefix . 'kit_drivers';
$deliveries_t = $wpdb->prefix . 'kit_deliveries';

if (!isset($stats['errors'])) {
    $stats['errors'] = [];
}
foreach ($wpdb->get_results("SELECT id, name FROM {$drivers_t}", ARRAY_A) ?: [] as $bad_row) {
    $bad_name = (string) ($bad_row['name'] ?? '');
    if (!kit_seed_is_non_driver_name($bad_name)) {
        continue;
    }
    $bid = (int) ($bad_row['id'] ?? 0);
    if ($bid <= 0) {
        continue;
    }
    $ref_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$deliveries_t} WHERE driver_id = %d",
        $bid
    ));
    if ($ref_count > 0) {
        $stats['errors'][] = "Driver '{$bad_name}' (id {$bid}) still has {$ref_count} delivery(ies); manual cleanup needed.";
        continue;
    }
    $wpdb->delete($drivers_t, ['id' => $bid], ['%d']);
}

$keep_names = [];
foreach (array_slice($rows, 1) as $row) {
    $name = $get($row, ['name'], '');
    if ($name === '') {
        $stats['skipped']++;
        continue;
    }
    if (kit_seed_is_non_driver_name($name)) {
        $stats['skipped']++;
        continue;
    }
    $keep_names[strtolower($name)] = true;
    $sheet_id = (int) preg_replace('/[^0-9]/', '', $get($row, ['id', 'driver_id'], '0'));
    $is_active_raw = strtoupper($get($row, ['is_active', 'active', 'status'], '1'));
    $is_active = in_array($is_active_raw, ['1', 'TRUE', 'YES', 'ACTIVE'], true) ? 1 : 0;
    $payload = [
        'name' => $name,
        'is_active' => $is_active,
    ];
    $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$drivers_t} WHERE name = %s LIMIT 1", $name));
    if ($existing <= 0 && $sheet_id > 0) {
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$drivers_t} WHERE id = %d LIMIT 1", $sheet_id));
    }
    if ($existing > 0) {
        $wpdb->update($drivers_t, $payload, ['id' => $existing], ['%s', '%d'], ['%d']);
        $stats['updated']++;
    } else {
        if ($sheet_id > 0) {
            $payload['id'] = $sheet_id;
            $wpdb->insert($drivers_t, $payload, ['%s', '%d', '%d']);
        } else {
            $wpdb->insert($drivers_t, $payload, ['%s', '%d']);
        }
        $stats['inserted']++;
    }
}

foreach ($wpdb->get_results("SELECT id, name FROM {$drivers_t}", ARRAY_A) ?: [] as $extra) {
    $extra_name = strtolower(trim((string) ($extra['name'] ?? '')));
    if ($extra_name !== '' && isset($keep_names[$extra_name])) {
        continue;
    }
    $eid = (int) ($extra['id'] ?? 0);
    if ($eid <= 0) {
        continue;
    }
    $wpdb->query($wpdb->prepare("UPDATE {$deliveries_t} SET driver_id = 0 WHERE driver_id = %d", $eid));
    $wpdb->delete($drivers_t, ['id' => $eid], ['%d']);
}

return $stats;
}

/**
 * Build mapping from customers sheet internal id -> cust_id.
 *
 * @param array $rows Header + rows
 * @return array<int,int>
 */
function kit_build_customer_sheet_id_map(array $rows): array
{
$map = [];
if (empty($rows) || count($rows) < 2) {
    return $map;
}

$header = array_map(function ($c) {
    $h = strtolower(trim((string) $c));
    return str_replace(' ', '_', $h);
}, $rows[0]);
$col = [];
foreach ($header as $i => $h) {
    if ($h !== '') {
        $col[$h] = $i;
    }
}
$id_idx = $col['id'] ?? null;
$cust_id_idx = $col['cust_id'] ?? ($col['customer_id'] ?? null);
if ($id_idx === null || $cust_id_idx === null) {
    return $map;
}

foreach (array_slice($rows, 1) as $row) {
    $sheet_id = (int) preg_replace('/[^0-9]/', '', (string) ($row[$id_idx] ?? '0'));
    $cust_id = (int) preg_replace('/[^0-9]/', '', (string) ($row[$cust_id_idx] ?? '0'));
    if ($sheet_id > 0 && $cust_id > 0) {
        $map[$sheet_id] = $cust_id;
    }
}
return $map;
}

/**
 * Seed database from Google Sheet. Maps sheet columns to kit_drivers, kit_customers,
 * kit_deliveries, kit_waybills, kit_waybill_items.
 */
function handle_setup_seed_from_google_sheet()
{
global $wpdb;

if (!class_exists('Courier_Google_Sheets') || !Courier_Google_Sheets::is_configured()) {
    return ['success' => false, 'message' => 'Google Sheets not configured. Add credentials and share the sheet with the service account email.'];
}

kit_seed_extend_runtime_limits();
$seed_started_at = microtime(true);
kit_seed_update_progress('reading_sheets', 0, 0);

try {
    $range = defined('COURIER_GOOGLE_SEED_RANGE') ? COURIER_GOOGLE_SEED_RANGE : 'A1:AR5000';
    $sheet_name = defined('COURIER_GOOGLE_SEED_SHEET_NAME') ? COURIER_GOOGLE_SEED_SHEET_NAME : 'kit_waybills';
    try {
        $rows = Courier_Google_Sheets::get_values('', $range, $sheet_name);
    } catch (Exception $e) {
        // Header-only or missing kit_waybills is not fatal — resolve falls back
        // to the Waybills source tab. Do not retry without a tab name (that
        // would read the spreadsheet's first sheet with the wrong range).
        $rows = [];
    }
    if (!is_array($rows)) {
        $rows = [];
    }

    $waybill_row_resolution = kit_seed_resolve_seed_waybill_rows($rows);
    $rows = $waybill_row_resolution['rows'];
    if (empty($rows) || count($rows) < 2) {
        return ['success' => false, 'message' => 'No waybill rows available to seed (kit_waybills and Waybills source tabs are both empty).'];
    }

    // #region agent log
    {
        $dbg_hdr = kit_seed_header_col_map($rows[0]);
        $dbg_samples = [];
        foreach (array_slice($rows, 1, 3) as $dbg_row) {
            $dbg_samples[] = [
                'waybill_no' => kit_seed_row_cell($dbg_row, $dbg_hdr, ['waybill_no', 'parcel_id', 'waybill_#']),
                'sheet_created_at' => kit_seed_row_cell($dbg_row, $dbg_hdr, ['created_at', 'date_received', 'date_recieved', 'received_date']),
            ];
        }
        @file_put_contents(
            (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 3)) . '/.cursor/debug-0df36b.log',
            json_encode([
                'sessionId' => '0df36b',
                'runId' => 'post-fix',
                'hypothesisId' => 'H3',
                'location' => 'settings.php:handle_setup_seed_from_google_sheet',
                'message' => 'resolved seed rows sample created_at from sheet',
                'data' => [
                    'used_source' => !empty($waybill_row_resolution['used_source']),
                    'kit_count' => (int) ($waybill_row_resolution['kit_count'] ?? 0),
                    'source_count' => (int) ($waybill_row_resolution['source_count'] ?? 0),
                    'final_count' => (int) ($waybill_row_resolution['final_count'] ?? 0),
                    'samples' => $dbg_samples,
                ],
                'timestamp' => (int) round(microtime(true) * 1000),
            ]) . "\n",
            FILE_APPEND
        );
    }
    // #endregion

    kit_seed_update_progress('entity_tabs', 0, 0);

    // Seed dedicated entity tabs first so full datasets are imported.
    $customers_rows = [];
    foreach (['kit_customers', 'customers'] as $customers_sheet_name) {
        try {
            $customers_rows = Courier_Google_Sheets::get_values('', 'A1:Z5000', $customers_sheet_name);
            if (!empty($customers_rows) && count($customers_rows) >= 2) {
                break;
            }
        } catch (Exception $e) {
            $customers_rows = [];
        }
    }
    $drivers_rows = function_exists('kit_seed_read_driver_sheet_rows')
        ? kit_seed_read_driver_sheet_rows()
        : [];
    if (empty($drivers_rows) || count($drivers_rows) < 2) {
        foreach (['kit_drivers', 'Drivers', 'drivers'] as $drivers_sheet_name) {
            try {
                $drivers_rows = Courier_Google_Sheets::get_values('', 'A1:Z5000', $drivers_sheet_name);
                if (!empty($drivers_rows) && count($drivers_rows) >= 2) {
                    break;
                }
            } catch (Exception $e) {
                $drivers_rows = [];
            }
        }
    }

    // Companies first: they are the party most waybills bill to, and until the
    // company tab is in the database every company has to be invented from a
    // waybill label — with an id that collides with the sheet's customer_id
    // range. Relocating afterwards clears out the ids earlier seeds invented.
    kit_seed_update_progress('companies', 0, 0);
    $company_rows = class_exists('KIT_Company_Seeder') ? KIT_Company_Seeder::read_sheet_rows() : [];
    $company_seed_stats = ['sheet_rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0];
    $company_id_relocation = ['relocated' => 0, 'kept' => 0];
    if (class_exists('KIT_Company_Seeder')) {
        $company_seed_stats = KIT_Company_Seeder::seed_from_sheet_rows($company_rows);
        $company_id_relocation = KIT_Company_Customers::relocate_unclaimed_sheet_ids(
            KIT_Company_Seeder::sheet_claimed_labels($company_rows, $customers_rows)
        );
    }

    kit_seed_update_progress('customers', 0, 0);
    $customer_seed_stats = kit_seed_customers_from_sheet_rows($customers_rows);
    kit_seed_update_progress('drivers', 0, 0);
    $driver_seed_stats = kit_seed_drivers_from_sheet_rows($drivers_rows);
    $customer_id_map = kit_build_customer_sheet_id_map($customers_rows);

    $deliveries_imported = 0;
    $deliveries_rows = [];
    $deliveries_sheet_valid = 0;
    $deliveries_in_db = 0;
    $deliveries_deleted = 0;
    if (class_exists('Courier_Google_Sheets_Sync')) {
        $deliveries_sheet = Courier_Google_Sheets_Sync::get_sheet_name('deliveries');
        try {
            kit_seed_update_progress('deliveries', 0, 0);
            $deliveries_rows = Courier_Google_Sheets::get_values('', 'A1:K200', $deliveries_sheet);
            if (class_exists('KIT_Delivery_Seeder')) {
                $deliveries_rows = KIT_Delivery_Seeder::trim_sheet_rows($deliveries_rows);
            }
            if (!empty($deliveries_rows) && count($deliveries_rows) >= 2 && class_exists('KIT_Delivery_Seeder')) {
                $deliveries_sheet_valid = KIT_Delivery_Seeder::count_valid_rows($deliveries_rows);
                $delivery_seed = KIT_Delivery_Seeder::seed_from_sheet_rows($deliveries_rows, true);
                $deliveries_imported = (int) ($delivery_seed['inserted'] ?? 0);
                $deliveries_deleted = (int) ($delivery_seed['deleted'] ?? 0);
                $deliveries_in_db = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}kit_deliveries WHERE delivery_reference != 'pending'"
                );
            }
        } catch (Exception $e) {
            $deliveries_rows = [];
        }
    }

    $waybills_source_lookup = kit_seed_build_waybills_source_lookup();
    $africa_trips_created = 0;
    if (class_exists('KIT_Sheet_Delivery_Resolve')
        && method_exists('KIT_Sheet_Delivery_Resolve', 'ensure_africa_trips_in_db')
    ) {
        $africa_trips = KIT_Sheet_Delivery_Resolve::ensure_africa_trips_in_db();
        $africa_trips_created = (int) ($africa_trips['created'] ?? 0);
    }
    $result = run_google_sheet_seed($rows, false, $customer_id_map, $deliveries_rows, $waybills_source_lookup, [
        'seed_started_at' => $seed_started_at,
    ]);
    if (isset($result['stats']) && is_array($result['stats'])) {
        $result['stats']['africa_waybills_trips_created'] = $africa_trips_created;
    }
    if (class_exists('KIT_Sheet_Delivery_Resolve')
        && method_exists('KIT_Sheet_Delivery_Resolve', 'apply_africa_waybill_lists_to_db')
    ) {
        $truck_fix = KIT_Sheet_Delivery_Resolve::apply_africa_waybill_lists_to_db();
        if (isset($result['stats']) && is_array($result['stats'])) {
            $result['stats']['africa_waybills_trucks_updated'] = (int) ($truck_fix['updated'] ?? 0);
            $result['stats']['africa_waybills_trucks_listed'] = (int) ($truck_fix['listed'] ?? 0);
        }
    }
    if (!empty($result['success'])) {
        kit_seed_update_progress('merging', 0, 0);
        $final_merge = kit_seed_merge_duplicate_customers();
        if (isset($result['stats']) && is_array($result['stats'])) {
            $result['stats']['customers_merged_final'] = (int) ($final_merge['merged'] ?? 0);
            $result['stats']['customers_duplicates_deleted_final'] = (int) ($final_merge['deleted'] ?? 0);
        }
        kit_debug_log_033ead('B', 'handle_setup_seed_from_google_sheet', 'final duplicate merge after customer sheet import', $final_merge);

        // Remove nameless / company-mirror person stubs left by older seed paths.
        if (function_exists('kit_seed_prune_blank_customer_stubs')) {
            $pruned = kit_seed_prune_blank_customer_stubs();
            if (isset($result['stats']) && is_array($result['stats'])) {
                $result['stats']['blank_customers_pruned'] = $pruned;
            }
        }
        // Do not prune company-mirror person rows during setup seed: kit_customers
        // and kit_company_customers are independent sheet rosters (250 / 109).
        // Shared numeric ids (legacy customer_id space) are not duplicates.

        // Propagate person→company FK onto waybills before ownership verification.
        if (class_exists('KIT_Company_Customers') && method_exists('KIT_Company_Customers', 'backfill_waybill_company_ids')) {
            $backfill = KIT_Company_Customers::backfill_waybill_company_ids();
            if (isset($result['stats']) && is_array($result['stats'])) {
                $result['stats']['waybills_company_backfilled'] = (int) ($backfill['waybills_linked'] ?? 0);
            }
        }

        // Entity tabs are the roster (109 companies / 250 customers). Trim
        // waybill-invented extras back to those ids; keep unused sheet parties.
        if (function_exists('kit_seed_trim_parties_to_sheet_tabs')) {
            $trimmed = kit_seed_trim_parties_to_sheet_tabs($customers_rows, $company_rows);
            if (isset($result['stats']) && is_array($result['stats'])) {
                $result['stats']['customers_trimmed'] = (int) ($trimmed['customers'] ?? 0);
                $result['stats']['companies_trimmed'] = (int) ($trimmed['companies'] ?? 0);
            }
        }

        // Ownership + charge_basis freight verification: sheet expected vs DB actual.
        if (function_exists('kit_seed_run_ownership_verification')) {
            kit_seed_update_progress('verifying', 0, 0);
            $verification = kit_seed_run_ownership_verification($rows, $customers_rows, $waybills_source_lookup);
            if (isset($result['stats']) && is_array($result['stats'])) {
                $result['stats']['verification'] = [
                    'ok' => !empty($verification['ok']),
                    'mismatch_count' => (int) ($verification['mismatch_count'] ?? 0),
                    'totals' => $verification['diff']['totals'] ?? [],
                    'mismatches' => array_slice($verification['diff']['mismatches'] ?? [], 0, 25),
                    'missing' => array_slice($verification['diff']['missing'] ?? [], 0, 25),
                    'extra' => array_slice($verification['diff']['extra'] ?? [], 0, 15),
                    'blank_individuals' => $verification['diff']['blank_individuals'] ?? [],
                    'charges' => [
                        'ok' => !empty($verification['charges']['ok']),
                        'checked' => (int) ($verification['charges']['checked'] ?? 0),
                        'mismatch_count' => (int) ($verification['charges']['mismatch_count'] ?? 0),
                        'mismatches' => array_slice($verification['charges']['mismatches'] ?? [], 0, 25),
                    ],
                    'json_path' => $verification['json_path'] ?? '',
                ];
            }
            if (empty($verification['ok'])) {
                $result['success'] = false;
                $mc = (int) ($verification['mismatch_count'] ?? 0);
                $cc = (int) ($verification['charges']['mismatch_count'] ?? 0);
                $result['message'] = sprintf(
                    'Setup seed wrote data but verification failed (%d mismatch(es); %d charge/total mismatch(es)). See stats.verification / .cursor/seed-verification-last.json.',
                    $mc,
                    $cc
                );
            }
        }
    }
    // Attach diagnostic stats for both successful and verification-failed runs.
    if (isset($result['stats']) && is_array($result['stats']) && (!empty($result['success']) || isset($result['stats']['verification']))) {
        $result['stats']['customers'] = (int) ($result['stats']['customers'] ?? 0) + (int) ($customer_seed_stats['inserted'] ?? 0);
        $result['stats']['drivers'] = (int) ($result['stats']['drivers'] ?? 0) + (int) ($driver_seed_stats['inserted'] ?? 0);
        $result['stats']['deliveries_imported_from_sheet'] = $deliveries_imported;
        $result['stats']['deliveries_deleted_stale'] = $deliveries_deleted;
        $result['stats']['deliveries_sheet_valid'] = $deliveries_sheet_valid;
        $result['stats']['deliveries_in_db'] = $deliveries_in_db;
        $result['stats']['deliveries'] = $deliveries_in_db;
        $result['stats']['customers_updated_from_sheet'] = (int) ($customer_seed_stats['updated'] ?? 0);
        $result['stats']['drivers_updated_from_sheet'] = (int) ($driver_seed_stats['updated'] ?? 0);
        $result['stats']['companies_from_sheet'] = $company_seed_stats;
        $result['stats']['company_ids_relocated'] = (int) ($company_id_relocation['relocated'] ?? 0);
        $customers_backfilled = 0;
        if (count($customers_rows) < 2) {
            $customers_backfilled = kit_seed_backfill_empty_customers_from_source($waybills_source_lookup);
            $result['stats']['customers_backfilled_from_waybills'] = $customers_backfilled;
        }
        $spreadsheet_id = '';
        if (class_exists('Courier_Google_Sheets')) {
            $spreadsheet_id = method_exists('Courier_Google_Sheets', 'get_source_spreadsheet_id')
                ? Courier_Google_Sheets::get_source_spreadsheet_id()
                : Courier_Google_Sheets::get_default_spreadsheet_id();
        }
        $counts_msg = sprintf(
            'Drivers: %d, Customers: %d, Deliveries: %d, Waybills: %d, Waybill items: %d. Skipped: %d.',
            (int) ($result['stats']['drivers'] ?? 0),
            (int) ($result['stats']['customers'] ?? 0),
            (int) ($result['stats']['deliveries'] ?? 0),
            (int) ($result['stats']['waybills'] ?? 0),
            (int) ($result['stats']['waybill_items'] ?? 0),
            (int) ($result['stats']['skipped'] ?? 0)
        );
        if (!empty($result['success'])) {
            $result['message'] = sprintf(
                'Setup seed from Google Sheet (%s) completed in %ds. %s',
                $spreadsheet_id,
                (int) round(microtime(true) - $seed_started_at),
                $counts_msg
            );
        } else {
            $result['message'] = trim((string) ($result['message'] ?? 'Setup seed verification failed.'))
                . ' ' . $counts_msg;
        }
        if (count($customers_rows) < 2) {
            $source_count = count($waybills_source_lookup['by_waybill'] ?? []);
            if ($source_count > 0) {
                $result['message'] .= sprintf(
                    ' Note: kit_customers tab has no data rows — customer names were resolved from the Waybills source tab (%d waybill contact(s)).',
                    $source_count
                );
                if ($customers_backfilled > 0) {
                    $result['message'] .= sprintf(' Backfilled %d customer(s) that were still blank.', $customers_backfilled);
                }
            } else {
                $result['message'] .= ' Warning: kit_customers tab has no data rows and the Waybills source tab could not be read — customer names may be incomplete.';
            }
        }
        if (!empty($waybill_row_resolution['used_source'])) {
            $result['stats']['waybill_rows_from_source'] = (int) ($waybill_row_resolution['final_count'] ?? 0);
            $result['stats']['kit_waybills_row_count'] = (int) ($waybill_row_resolution['kit_count'] ?? 0);
            $result['message'] .= sprintf(
                ' Waybill rows: used Waybills source tab (%d rows) merged with kit_waybills (%d rows).',
                (int) ($waybill_row_resolution['source_count'] ?? 0),
                (int) ($waybill_row_resolution['kit_count'] ?? 0)
            );
        }
        if ($deliveries_sheet_valid > 0 && $deliveries_in_db !== $deliveries_sheet_valid) {
            $result['message'] .= sprintf(
                ' Warning: kit_deliveries tab has %d valid trip row(s) but %d exist in DB — wipe tables and re-seed, or remove stale delivery rows.',
                $deliveries_sheet_valid,
                $deliveries_in_db
            );
        }
    }
    return $result;
} catch (Throwable $e) {
    return ['success' => false, 'message' => 'Google Sheet seed failed: ' . $e->getMessage()];
}
}

// Generate newSQL.sql from Excel file (waybill_excel/*.xlsx)
function kit_generate_seed_sql_from_excel(): array
{
$excel_file = plugin_dir_path(__FILE__) . '../../waybill_excel/Waybills_31-10-2025.xlsx';
$pluginRoot = plugin_dir_path(__FILE__) . '../../';
$target_sql = $pluginRoot . 'newSQL.sql';

if (!file_exists($excel_file)) {
    return ['success' => false, 'message' => 'Excel file not found', 'path' => null];
}

$python = "\nimport pandas as pd\nimport json\nimport sys\n\ntry:\n    df = pd.read_excel('" . $excel_file . "', sheet_name='waybills')\n    for col in df.columns:\n        if str(df[col].dtype).startswith('datetime'):\n            df[col] = df[col].astype(str)\n    df = df.fillna('')\n    print(json.dumps(df.to_dict('records')))\nexcept Exception as e:\n    print(json.dumps({'error': str(e)}))\n    sys.exit(0)\n";

if (!function_exists('shell_exec')) {
    return ['success' => false, 'message' => 'shell_exec disabled; cannot read Excel', 'path' => null];
}
$tmp = tempnam(sys_get_temp_dir(), 'seedgen_');
@file_put_contents($tmp, $python);
$json = @shell_exec('python3 ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
if (!$json) {
    return ['success' => false, 'message' => 'Python failed to read Excel', 'path' => null];
}
$rows = json_decode($json, true);
if (!is_array($rows) || isset($rows['error'])) {
    return ['success' => false, 'message' => 'Excel parse error', 'path' => null];
}

$drivers = [];
$deliveries = [];
$customers = [];
$waybillRows = [];
foreach ($rows as $r) {
    $driver = trim((string)($r['Driver'] ?? ''));
    $date = trim((string)($r['Truck Dispatch Date'] ?? ''));
    if ($date === '' || strtolower($date) === 'nat' || strtolower($date) === 'nan' || $date === '0000-00-00') {
        $date = date('Y-m-d');
    }
    $customer = trim((string)($r['Customer'] ?? ''));
    if ($driver === '' || $date === '' || $customer === '') {
        continue;
    }
    $drivers[$driver] = true;
    $deliveries[$driver . '|' . $date] = ['driver' => $driver, 'date' => $date];
    $customers[$customer] = true;
    $waybillRows[] = $r;
}

$sql = [];
$sql[] = 'START TRANSACTION';
foreach (array_keys($drivers) as $name) {
    $esc = addslashes($name);
    $sql[] = "INSERT INTO wp_kit_drivers (name, is_active) \nSELECT '{$esc}', 1 FROM DUAL \nWHERE NOT EXISTS (SELECT 1 FROM wp_kit_drivers WHERE name = '{$esc}')";
}
foreach ($deliveries as $d) {
    $escName = addslashes($d['driver']);
    $escDate = addslashes($d['date']);
    if (!class_exists('KIT_Deliveries')) {
        require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
    }
    if (!class_exists('KIT_Routes')) {
        require_once plugin_dir_path(__FILE__) . '../routes/routes-functions.php';
    }
    $ref = addslashes(KIT_Deliveries::generateDeliveryRef());
    $seed_direction_id = 2;
    $seed_city_id = class_exists('KIT_Routes')
        ? KIT_Routes::resolve_destination_city_id($seed_direction_id, 0)
        : 6;
    $sql[] = "INSERT INTO wp_kit_deliveries (delivery_reference, direction_id, destination_city_id, dispatch_date, driver_id, status)\nSELECT '{$ref}', {$seed_direction_id}, {$seed_city_id}, '{$escDate}', d.id, 'scheduled'\nFROM wp_kit_drivers d\nWHERE d.name = '{$escName}'\nAND NOT EXISTS (SELECT 1 FROM wp_kit_deliveries WHERE driver_id = d.id AND dispatch_date = '{$escDate}')";
}
foreach (array_keys($customers) as $fullname) {
    $label = trim((string) $fullname);
    $label = preg_replace('/^\s*(individual|private)\s+/i', '', $label);
    $label = trim((string) $label);
    if ($label === '' || in_array(strtolower($label), ['individual', 'private'], true)) {
        continue;
    }
    $parts = explode(' ', $label, 2);
    $first = addslashes($parts[0] ?? '');
    $last = addslashes($parts[1] ?? 'LastName');
    $custId = rand(1000, 9999);
    $sql[] = "INSERT INTO wp_kit_customers (cust_id, name, surname, country_id) \nSELECT {$custId}, '{$first}', '{$last}', 0 FROM DUAL \nWHERE NOT EXISTS (SELECT 1 FROM wp_kit_customers WHERE name = '{$first}' AND surname = '{$last}')";
}
foreach ($waybillRows as $row) {
    // --- Extract fields with defensive fallbacks ---
    $driver = addslashes(trim((string)($row['Driver'] ?? '')));
    $dateVal = trim((string)($row['Truck Dispatch Date'] ?? ''));
    if ($dateVal === '' || strtolower($dateVal) === 'nat' || strtolower($dateVal) === 'nan' || $dateVal === '0000-00-00') {
        $dateVal = date('Y-m-d');
    }
    $date = addslashes($dateVal);

    $customer = trim((string)($row['Customer'] ?? ''));
    $parts = explode(' ', $customer, 2);
    $first = addslashes($parts[0] ?? '');
    $last  = addslashes($parts[1] ?? 'LastName');

    // Quantities / totals
    $qty       = (int)($row['QUANTITY'] ?? 0);
    $mass      = (float)($row['T MASS'] ?? 0);
    $vol       = (float)($row['T VOLUME'] ?? 0);
    $length    = (float)($row['LENGTH'] ?? 0);
    $width     = (float)($row['WIDTH'] ?? 0);
    $height    = (float)($row['HEIGHT'] ?? 0);
    $basis     = trim((string)($row['BASIS'] ?? $row['charge_basis'] ?? $row['CHARGE_BASIS'] ?? ''));

    $massCost  = (float) preg_replace('/[^0-9\.]/', '', (string)($row['MASS COST'] ?? '0'));
    $volCost   = (float) preg_replace('/[^0-9\.]/', '', (string)($row['VOL COST'] ?? '0'));
    $totalCost = function_exists('kit_seed_total_from_charge_basis')
        ? kit_seed_total_from_charge_basis($massCost, $volCost, $basis)
        : (float) max($massCost, $volCost);
    $massCostSql = addslashes((string) $massCost);
    $volCostSql = addslashes((string) $volCost);
    $basisSql = $basis !== ''
        ? "'" . addslashes(function_exists('kit_seed_normalize_charge_basis') ? (kit_seed_normalize_charge_basis($basis) ?: strtolower($basis)) : strtolower($basis)) . "'"
        : "'mass'";

    // Waybill description from Excel column exactly as provided
    $waybill_description = addslashes(trim((string)($row['Waybill  description'] ?? $row['Waybill description'] ?? $row['Waybill_description'] ?? '')));

    // Get city_id from CITY column - look up city name in operating_cities table
    $city_name = trim((string)($row['CITY'] ?? ''));
    $city_id = 9; // Default fallback
    if ($city_name !== '') {
        global $wpdb;
        $city_id_result = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}kit_operating_cities WHERE city_name = %s LIMIT 1",
            $city_name
        ));
        if ($city_id_result) {
            $city_id = (int)$city_id_result;
        }
    }

    // VAT / SADC / SAD500 mapping from Excel (dedicated columns + legacy VAT cell).
    $vat_include_raw = trim((string)($row['vat_include'] ?? $row['VAT_INCLUDE'] ?? $row['Vat_include'] ?? ''));
    $legacy_vat = strtoupper(trim((string)($row['VAT'] ?? $row['Vat'] ?? '')));
    if ($vat_include_raw !== '') {
        $vatFlag = kit_google_sheet_parse_bool_int($vat_include_raw);
    } elseif (in_array($legacy_vat, ['SAD500', 'SADC'], true)) {
        $vatFlag = 0;
    } elseif ($legacy_vat !== '') {
        $vatFlag = kit_google_sheet_parse_bool_int($legacy_vat);
    } else {
        $vatFlag = 0;
    }
    $s500 = trim((string)($row['include_sad500'] ?? $row['INCLUDE_SAD500'] ?? $row['SAD500'] ?? $row['SAD_500'] ?? ''));
    $sadc_col = trim((string)($row['include_sadc'] ?? $row['INCLUDE_SADC'] ?? $row['SADC'] ?? ''));
    $include_sad500 = ($s500 !== '') ? kit_google_sheet_parse_bool_int($s500) : (($legacy_vat === 'SAD500') ? 1 : 0);
    $include_sadc = ($sadc_col !== '') ? kit_google_sheet_parse_bool_int($sadc_col) : (($legacy_vat === 'SADC') ? 1 : 0);
    if ($vatFlag === 1) {
        $include_sadc = 0;
    }

    // Item fields (small boxes) — must use "Item description" (NOT waybill description)
    $itemDesc = trim((string)($row['Item description'] ?? $row['ITEM DESCRIPTION'] ?? $row['Item_Description'] ?? ''));
    $itemDescSql = $itemDesc !== '' ? ("'" . addslashes($itemDesc) . "'") : "NULL";
    $itemQty  = (int)($row['QUANTITY'] ?? 0);

    // Client invoice (W) and total price (Y) — tolerant to header variants
    $clientInv = trim((string)($row['CL INV #'] ?? $row['CLIENT INVOICE'] ?? $row['Client invoice'] ?? $row['Client_Invoice'] ?? ''));
    $clientInvSql = $clientInv !== '' ? ("'" . addslashes($clientInv) . "'") : "NULL";
    $totalPriceRaw = trim((string)($row['TOTAL PRICE'] ?? $row['Total price'] ?? $row['Total_Price'] ?? $row['TOTAL'] ?? ''));
    $totalPrice = preg_replace('/[^0-9\.]/', '', $totalPriceRaw);
    $totalPriceSql = ($totalPrice === '' ? 'NULL' : $totalPrice);

    // 1) Insert WAYBILL (no product_invoice_number; PHP generates it later if you use save_waybill route)
    // Use hardcoded city_id from CSV CITY column lookup
    $sql[] = "INSERT INTO wp_kit_waybills (description, direction_id, city_id, delivery_id, customer_id, waybill_no, warehouse, product_invoice_number, product_invoice_amount, waybill_items_total, total_mass_kg, total_volume, item_length, item_width, item_height, mass_charge, volume_charge, charge_basis, miscellaneous, include_sad500, include_sadc, vat_include, tracking_number, status, created_at, last_updated_at)\n"
        . "SELECT " . ($waybill_description ? "'{$waybill_description}'" : "NULL") . ", 1, {$city_id}, del.id, cust.cust_id, FLOOR(RAND()*900000)+100000, 0, NULL, {$totalCost}, {$totalCost}, {$mass}, {$vol}, {$length}, {$width}, {$height}, {$massCostSql}, {$volCostSql}, {$basisSql}, '', {$include_sad500}, {$include_sadc}, {$vatFlag}, CONCAT('TRK-', LEFT(UUID(),8)), 'pending', NOW(), NOW()\n"
        . "FROM wp_kit_deliveries del\n"
        . "JOIN wp_kit_drivers d ON d.id = del.driver_id AND d.name = '{$driver}' AND del.dispatch_date = '{$date}'\n"
        . "JOIN wp_kit_customers cust ON cust.name = '{$first}' AND cust.surname = '{$last}'\n"
        . "WHERE NOT EXISTS (SELECT 1 FROM wp_kit_waybills w JOIN wp_kit_customers c2 ON w.customer_id = c2.cust_id WHERE c2.name = '{$first}' AND c2.surname = '{$last}' AND w.delivery_id = del.id)";

    // 2) Insert WAYBILL ITEM using Item description (NOT the waybill description)
    //    We reference the waybill just inserted via the same join keys (customer+delivery) to get its waybill_no
    $sql[] = "INSERT INTO wp_kit_waybill_items (waybillno, item_name, quantity, unit_price, unit_mass, unit_volume, total_price, client_invoice)\n"
        . "SELECT w.waybill_no, {$itemDescSql}, " . ($itemQty > 0 ? $itemQty : 1) . ", 0.00, 0.00, 0.00, {$totalPriceSql}, {$clientInvSql}\n"
        . "FROM wp_kit_waybills w\n"
        . "JOIN wp_kit_deliveries del ON del.id = w.delivery_id\n"
        . "JOIN wp_kit_drivers d ON d.id = del.driver_id AND d.name = '{$driver}' AND del.dispatch_date = '{$date}'\n"
        . "JOIN wp_kit_customers cust ON cust.cust_id = w.customer_id AND cust.name = '{$first}' AND cust.surname = '{$last}'\n"
        . "WHERE NOT EXISTS (SELECT 1 FROM wp_kit_waybill_items i WHERE i.waybillno = w.waybill_no AND i.item_name = {$itemDescSql})";
}
$sql[] = 'COMMIT';
$ok = @file_put_contents($target_sql, implode(";\n\n", $sql) . ";\n");
if ($ok === false) {
    return ['success' => false, 'message' => 'Failed to write newSQL.sql', 'path' => null];
}
return ['success' => true, 'message' => 'Generated from Excel', 'path' => $target_sql];
}

if (!isset($setup_seed_result) && isset($_GET['seed_done']) && $_GET['seed_done'] === '1') {
    $seed_flash = get_transient('kit_setup_seed_flash_' . get_current_user_id());
    if (is_array($seed_flash)) {
        $setup_seed_result = $seed_flash;
        delete_transient('kit_setup_seed_flash_' . get_current_user_id());
    }
}

if (!function_exists('kit_settings_notice_html_seed')) {
/**
 * Build inner HTML for setup seed result notices.
 *
 * @param array<string,mixed> $result
 */
function kit_settings_notice_html_seed(array $result): string
{
    $html = '<p>' . esc_html($result['message'] ?? '') . '</p>';
    if (empty($result['stats']) || !is_array($result['stats'])) {
        return $html;
    }

    $stats = $result['stats'];
    $html .= '<div class="mt-3 space-y-1">';
    $html .= '<p><strong>Statistics:</strong></p>';
    if (isset($stats['executed'])) {
        $html .= '<p>• Statements executed: ' . intval($stats['executed']) . '</p>';
    }
    if (isset($stats['drivers'])) {
        $html .= '<p>• Drivers: ' . intval($stats['drivers'])
            . ', Customers: ' . intval($stats['customers'])
            . ', Deliveries: ' . intval($stats['deliveries'])
            . ', Waybills: ' . intval($stats['waybills'])
            . ', Items: ' . intval($stats['waybill_items'])
            . ', Skipped: ' . intval($stats['skipped'] ?? 0) . '</p>';
    }
    if (!empty($stats['errors'])) {
        $html .= '<p>• Errors: ' . count($stats['errors']) . '</p>';
        $html .= '<div class="mt-2 text-xs"><p><strong>Error details:</strong></p>';
        foreach (array_slice($stats['errors'], 0, 10) as $error) {
            $html .= '<p class="text-red-600">• ' . esc_html($error) . '</p>';
        }
        $html .= '</div>';
    }
    if (!empty($stats['detected_columns']) && ($stats['waybills'] ?? 0) === 0) {
        $html .= '<div class="mt-2 text-xs">';
        $html .= '<p><strong>Detected sheet columns:</strong> ' . esc_html(implode(', ', $stats['detected_columns'])) . '</p>';
        $html .= '<p class="text-amber-600 mt-1">Tip: Ensure you have waybill_no/wb_no/parcel_id, driver/delivery, customer, and dispatch_date/truck_dispatch_date columns (or similar). See Sheet-to-DB Mapping below.</p>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Build inner HTML for wipe tables result notices.
 *
 * @param array<string,mixed> $result
 */
function kit_settings_notice_html_wipe(array $result): string
{
    $html = '<p>' . esc_html($result['message'] ?? '') . '</p>';
    if (empty($result['stats']) || !is_array($result['stats'])) {
        return $html;
    }

    $html .= '<div class="mt-3 space-y-1">';
    $html .= '<p><strong>Statistics:</strong></p>';
    $html .= '<p>• Tables wiped: ' . intval($result['stats']['wiped'] ?? 0) . '</p>';
    if (!empty($result['stats']['errors'])) {
        $html .= '<p>• Errors: ' . count($result['stats']['errors']) . '</p>';
        $html .= '<div class="mt-2 text-xs"><p><strong>Error details:</strong></p>';
        foreach (array_slice($result['stats']['errors'], 0, 5) as $error) {
            $html .= '<p class="text-red-600">• ' . esc_html($error) . '</p>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Resolve a single page notice from post-back / redirect results.
 *
 * @param array<string,mixed> $vars
 * @return array{type:string,title:string,html:string}|null
 */
function kit_settings_resolve_page_notice(array $vars): ?array
{
    if (!empty($vars['setup_seed_result']) && is_array($vars['setup_seed_result'])) {
        $result = $vars['setup_seed_result'];
        return [
            'type' => !empty($result['success']) ? 'success' : 'error',
            'title' => !empty($result['success']) ? 'Setup Seed Successful' : 'Setup Seed Failed',
            'html' => kit_settings_notice_html_seed($result),
        ];
    }

    if (!empty($vars['wipe_tables_result']) && is_array($vars['wipe_tables_result'])) {
        $result = $vars['wipe_tables_result'];
        return [
            'type' => !empty($result['success']) ? 'success' : 'error',
            'title' => !empty($result['success']) ? 'Tables Wiped Successfully' : 'Wipe Tables Failed',
            'html' => kit_settings_notice_html_wipe($result),
        ];
    }

    if (!empty($vars['clear_logs_result']) && is_array($vars['clear_logs_result'])) {
        $result = $vars['clear_logs_result'];
        return [
            'type' => !empty($result['success']) ? 'success' : 'warning',
            'title' => 'Plugin Logs',
            'html' => '<p>' . esc_html($result['message'] ?? '') . '</p>',
        ];
    }

    if (!empty($vars['google_auto_sync_result']) && is_array($vars['google_auto_sync_result'])) {
        $result = $vars['google_auto_sync_result'];
        return [
            'type' => 'success',
            'title' => 'Auto Sync Updated',
            'html' => '<p>' . esc_html($result['message'] ?? '') . '</p>',
        ];
    }

    if (!empty($vars['fake_filler_result']) && is_array($vars['fake_filler_result'])) {
        $result = $vars['fake_filler_result'];
        return [
            'type' => 'success',
            'title' => 'Fake Filler Updated',
            'html' => '<p>' . esc_html($result['message'] ?? '') . '</p>',
        ];
    }

    if (!empty($vars['maintenance_mode_result']) && is_array($vars['maintenance_mode_result'])) {
        $result = $vars['maintenance_mode_result'];
        return [
            'type' => 'warning',
            'title' => 'Maintenance Mode Updated',
            'html' => '<p>' . esc_html($result['message'] ?? '') . '</p>',
        ];
    }

    if (!empty($vars['message'])) {
        $message = (string) $vars['message'];
        $is_error = stripos($message, 'fail') !== false;
        return [
            'type' => $is_error ? 'error' : 'success',
            'title' => $is_error ? 'Error' : 'Success',
            'html' => '<p>' . esc_html($message) . '</p>',
        ];
    }

    return null;
}
}

$kit_settings_notice = kit_settings_resolve_page_notice(get_defined_vars());

if (defined('KIT_SETTINGS_SKIP_UI') && KIT_SETTINGS_SKIP_UI) {
    return;
}

// #region agent log
kit_debug_log_45951e('D', 'settings.php:render', 'settings page UI render start', [
    'user_id' => get_current_user_id(),
    'seed_running' => (bool) get_transient('kit_seed_running_' . get_current_user_id()),
]);
// #endregion
?>

<div class="wrap kit-settings-page">
<div class="<?php echo KIT_Commons::containerClasses(); ?> kit-settings-container">
    <?php
    echo KIT_Commons::showingHeader([
        'title' => 'Settings & Configuration',
        'desc' => 'Manage your company settings, banking details, and system charges',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />',
    ]);
    ?>

    <?php
    $kit_notice_styles = [
        'success' => 'bg-green-50 border-green-200 text-green-800',
        'error' => 'bg-red-50 border-red-200 text-red-800',
        'info' => 'bg-blue-50 border-blue-200 text-blue-800',
        'warning' => 'bg-amber-50 border-amber-200 text-amber-900',
    ];
    $kit_notice_type = $kit_settings_notice['type'] ?? 'info';
    $kit_notice_class = $kit_notice_styles[$kit_notice_type] ?? $kit_notice_styles['info'];
    ?>
    <div id="kit-settings-notice"
        class="mb-6 p-4 rounded-lg border text-sm <?php echo esc_attr($kit_notice_class); ?>"
        role="alert"
        <?php echo $kit_settings_notice ? '' : 'style="display:none;"'; ?>>
        <?php if ($kit_settings_notice): ?>
            <p class="font-medium"><?php echo esc_html($kit_settings_notice['title']); ?></p>
            <div class="mt-2"><?php echo $kit_settings_notice['html']; ?></div>
        <?php endif; ?>
    </div>

    <!-- Tab Navigation -->
    <div class="border-b border-gray-200 mb-8">
        <nav class="kit-settings-tabs" aria-label="Tabs">
            <?php echo KIT_Commons::renderButton('Banking Details', 'ghost', 'lg', ['id' => 'tab-banking', 'classes' => 'tab-button active', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>', 'iconPosition' => 'left']); ?>
            <?php echo KIT_Commons::renderButton('Company Details', 'ghost', 'lg', ['id' => 'tab-company', 'classes' => 'tab-button', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>', 'iconPosition' => 'left']); ?>
            <?php echo KIT_Commons::renderButton('VAT & Charges', 'ghost', 'lg', ['id' => 'tab-charges', 'classes' => 'tab-button', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>', 'iconPosition' => 'left']); ?>
            <?php echo KIT_Commons::renderButton('Color Scheme', 'ghost', 'lg', ['id' => 'tab-colors', 'classes' => 'tab-button', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7a4 4 0 018 0v10a4 4 0 11-8 0V7zm8 0a4 4 0 018 0v4a4 4 0 01-4 4h-4" />', 'iconPosition' => 'left']); ?>
            <?php echo KIT_Commons::renderButton('Setup Seed', 'ghost', 'lg', ['id' => 'tab-setup-seed', 'classes' => 'tab-button', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />', 'iconPosition' => 'left']); ?>
            <?php echo KIT_Commons::renderButton('Terms & Conditions', 'ghost', 'lg', ['id' => 'tab-terms', 'classes' => 'tab-button', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>', 'iconPosition' => 'left']); ?>
        </nav>
    </div>

    <!-- Tab Content -->
    <div class="tab-contents">
        <!-- Banking Details Tab -->
        <div id="content-banking" class="tab-panel active">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Banking Details</h2>
                    <p class="text-sm text-gray-600 mt-1">Configure your company's banking information for payments and invoices</p>
                </div>

                <div class="p-6">
                    <form method="post" action="">
                        <?php wp_nonce_field('save_settings', 'settings_nonce'); ?>
                        <input type="hidden" name="action" value="save_banking">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="form-group">
                                <?php
                                $current_bank = (string) $wpdb->get_var('SELECT bank_name FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1');
                                $sa_banks = KIT_Commons::get_sa_banks();
                                ?>
                                <label for="bank_name" class="block text-sm font-medium text-gray-700 mb-2">Bank Name</label>
                                <select id="bank_name" name="bank_name"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Select a bank…</option>
                                    <?php foreach ($sa_banks as $bank) : ?>
                                        <option value="<?php echo esc_attr($bank); ?>" <?php selected($current_bank, $bank); ?>><?php echo esc_html($bank); ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($current_bank !== '' && !in_array($current_bank, $sa_banks, true)) : ?>
                                        <option value="<?php echo esc_attr($current_bank); ?>" selected><?php echo esc_html($current_bank); ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <?php echo KIT_Commons::Linput([
                                    'label' => 'Account Number',
                                    'name' => 'account_number',
                                    'id' => 'account_number',
                                    'type' => 'text',
                                    'value' => $wpdb->get_var('SELECT account_number FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1'),
                                    'preset' => '',
                                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                ]); ?>
                            </div>

                            <div class="form-group">
                                <?php echo KIT_Commons::Linput([
                                    'label' => 'Branch Code',
                                    'name' => 'branch_code',
                                    'id' => 'branch_code',
                                    'type' => 'text',
                                    'value' => $wpdb->get_var('SELECT branch_code FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1'),
                                    'preset' => '',
                                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                ]); ?>
                            </div>

                            <div class="form-group">
                                <label for="account_type" class="block text-sm font-medium text-gray-700 mb-2">Account Type</label>
                                <select id="account_type" name="account_type"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <?php $acct = $wpdb->get_var('SELECT account_type FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1'); ?>
                                    <option value="savings" <?php selected($acct, 'savings'); ?>>Savings Account</option>
                                    <option value="current" <?php selected($acct, 'current'); ?>>Current Account</option>
                                    <option value="business" <?php selected($acct, 'business'); ?>>Business Account</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <?php echo KIT_Commons::Linput([
                                    'label' => 'Account Holder Name',
                                    'name' => 'account_holder',
                                    'id' => 'account_holder',
                                    'type' => 'text',
                                    'value' => $wpdb->get_var('SELECT account_holder FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1'),
                                    'preset' => '',
                                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                ]); ?>
                            </div>

                            <div class="form-group">
                                <?php echo KIT_Commons::Linput([
                                    'label' => 'Swift Code',
                                    'name' => 'swift_code',
                                    'id' => 'swift_code',
                                    'type' => 'text',
                                    'value' => $wpdb->get_var('SELECT swift_code FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1'),
                                    'preset' => '',
                                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                ]); ?>
                            </div>

                            <div class="form-group md:col-span-2">
                                <?php echo KIT_Commons::Linput([
                                    'label' => 'IBAN (International Bank Account Number)',
                                    'name' => 'iban',
                                    'id' => 'iban',
                                    'type' => 'text',
                                    'value' => $wpdb->get_var('SELECT iban FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1'),
                                    'preset' => '',
                                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                ]); ?>
                            </div>
                        </div>

                        <div class="mt-8 pt-6 border-t border-gray-200">
                            <?php echo KIT_Commons::renderButton('Save Banking Details', 'primary', 'lg', ['type' => 'submit', 'gradient' => true]); ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Company Details Tab -->
        <div id="content-company" class="tab-panel hidden">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Company Details</h2>
                    <p class="text-sm text-gray-600 mt-1">Update your company information and contact details</p>
                </div>

                <div class="p-6">
                    <form method="post" action="">
                        <?php wp_nonce_field('save_settings', 'settings_nonce'); ?>
                        <input type="hidden" name="action" value="save_company">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="form-group md:col-span-2">
                                <?php echo KIT_Commons::Linput([
                                    'label' => 'Company Name',
                                    'name' => 'company_name',
                                    'id' => 'company_name',
                                    'type' => 'text',
                                    'value' => $wpdb->get_var('SELECT company_name FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1'),
                                    'preset' => '',
                                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                ]); ?>
                            </div>

                            <div class="form-group md:col-span-2">
                                <label for="company_address" class="block text-sm font-medium text-gray-700 mb-2">Company Address</label>
                                <textarea id="company_address" rows="3" readonly
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm bg-gray-50 text-gray-700"><?php echo esc_textarea(class_exists('KIT_Company') ? KIT_Company::COMPANY_ADDRESS : ''); ?></textarea>
                                <p class="mt-1 text-xs text-gray-500">Fixed 08600 company address.</p>
                            </div>

                            <div class="form-group">
                                <label for="company_email" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                                <input type="email" id="company_email" name="company_email"
                                    value="<?php echo esc_attr($wpdb->get_var('SELECT company_email FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1')); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>

                            <div class="form-group">
                                <label for="company_phone" class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                                <input type="tel" id="company_phone" readonly
                                    value="<?php echo esc_attr(class_exists('KIT_Company') ? KIT_Company::COMPANY_PHONE : ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm bg-gray-50 text-gray-700">
                                <p class="mt-1 text-xs text-gray-500">Fixed 08600 company phone.</p>
                            </div>

                            <div class="form-group">
                                <label for="company_website" class="block text-sm font-medium text-gray-700 mb-2">Website</label>
                                <input type="url" id="company_website" name="company_website"
                                    value="<?php echo esc_attr($wpdb->get_var('SELECT company_website FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1')); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>

                            <div class="form-group">
                                <?php echo KIT_Commons::Linput([
                                    'label' => 'Company Registration Number',
                                    'name' => 'company_registration',
                                    'id' => 'company_registration',
                                    'type' => 'text',
                                    'value' => $wpdb->get_var('SELECT company_registration FROM ' . $wpdb->prefix . 'kit_company_details LIMIT 1'),
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                    'preset' => '',
                                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                ]); ?>
                            </div>

                            <div class="form-group">
                                <label for="company_vat_number" class="block text-sm font-medium text-gray-700 mb-2">VAT Registration Number</label>
                                <input type="text" id="company_vat_number" readonly
                                    value="<?php echo esc_attr(class_exists('KIT_Company') ? KIT_Company::COMPANY_VAT_NUMBER : ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm bg-gray-50 text-gray-700">
                                <p class="mt-1 text-xs text-gray-500">Fixed 08600 VAT number.</p>
                            </div>
                        </div>

                        <div class="mt-8 pt-6 border-t border-gray-200">
                            <?= KIT_Commons::renderButton('Save Company Details', 'primary', 'lg', ['type' => 'submit', 'gradient' => true]) ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- VAT & Charges Tab -->
        <div id="content-charges" class="tab-panel hidden">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">VAT & Charges</h2>
                    <p class="text-sm text-gray-600 mt-1">Configure VAT percentage and SADC/SAD500 charges</p>
                </div>

                <div class="p-6">
                    <form method="post" action="">
                        <?php wp_nonce_field('save_settings', 'settings_nonce'); ?>
                        <input type="hidden" name="action" value="save_charges">

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="form-group">
                                <label for="vat_percentage" class="block text-sm font-medium text-gray-700 mb-2">
                                    VAT Percentage (%)
                                </label>
                                <div class="relative">
                                    <?php
                                    echo KIT_Commons::Lnumber([
                                        'no_label' => true,
                                        'label' => '',
                                        'id' => 'vat_percentage',
                                        'name' => 'vat_percentage',
                                        'value' => (string) KIT_Waybills::vatRate(),
                                        'step' => '0.01',
                                        'min' => '0',
                                        'max' => '100',
                                        'preset' => '',
                                        'class' => 'w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                        'special' => 'disabled',
                                    ]);
                                    ?>
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                                        <span class="text-gray-500 text-sm">%</span>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">VAT rate is fixed at 10% (managed in code)</p>
                            </div>

                            <div class="form-group">
                                <label for="sadc_charge" class="block text-sm font-medium text-gray-700 mb-2">
                                    SADC Charge (R)
                                </label>
                                <div class="relative">
                                    <?php
                                    echo KIT_Commons::Lnumber([
                                        'no_label' => true,
                                        'label' => '',
                                        'id' => 'sadc_charge',
                                        'name' => 'sadc_charge',
                                        'value' => (string) KIT_Waybills::sad(),
                                        'step' => '0.01',
                                        'min' => '0',
                                        'preset' => '',
                                        'class' => 'w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                    ]);
                                    ?>
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                                        <span class="text-gray-500 text-sm">R</span>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">SADC documentation charge</p>
                            </div>

                            <div class="form-group">
                                <label for="sad500_charge" class="block text-sm font-medium text-gray-700 mb-2">
                                    SAD500 Charge (R)
                                </label>
                                <div class="relative">
                                    <?php
                                    echo KIT_Commons::Lnumber([
                                        'no_label' => true,
                                        'label' => '',
                                        'id' => 'sad500_charge',
                                        'name' => 'sad500_charge',
                                        'value' => (string) KIT_Waybills::sadc_certificate(),
                                        'step' => '0.01',
                                        'min' => '0',
                                        'preset' => '',
                                        'class' => 'w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                    ]);
                                    ?>
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                                        <span class="text-gray-500 text-sm">R</span>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">SAD500 customs declaration charge</p>
                            </div>

                            <div class="form-group">
                                <label for="international_price" class="block text-sm font-medium text-gray-700 mb-2">
                                    International Price (USD)
                                </label>
                                <div class="relative">
                                    <?php
                                    echo KIT_Commons::Lnumber([
                                        'no_label' => true,
                                        'label' => '',
                                        'id' => 'international_price',
                                        'name' => 'international_price',
                                        'value' => (string) KIT_Waybills::international_price(),
                                        'step' => '0.01',
                                        'min' => '0',
                                        'preset' => '',
                                        'class' => 'w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                    ]);
                                    ?>
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                                        <span class="text-gray-500 text-sm">$</span>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">International shipping fee in USD (converted to Rands)</p>

                                <!-- Live Exchange Rate Display -->
                                <div class="mt-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-blue-700 font-medium">Live Exchange Rate:</span>
                                        <span class="text-blue-800 font-semibold" id="exchange-rate">Loading...</span>
                                    </div>
                                    <div class="mt-2 flex items-center justify-between text-sm">
                                        <span class="text-blue-600">Converted to ZAR:</span>
                                        <span class="text-blue-900 font-bold text-lg" id="zar-amount">R 0.00</span>
                                    </div>
                                    <div class="mt-1 text-xs text-blue-500">
                                        <span id="last-updated">Last updated: Never</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Preview Section -->
                        <div class="mt-8 p-4 bg-gray-50 rounded-lg">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Charge Preview</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">VAT Rate:</span>
                                    <span class="font-medium" id="vat-preview">15%</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">SADC Charge:</span>
                                    <span class="font-medium" id="sadc-preview">R 0.00</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">SAD500 Charge:</span>
                                    <span class="font-medium" id="sad500-preview">R 0.00</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">International Price:</span>
                                    <span class="font-medium" id="international-preview">$0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-8 pt-6 border-t border-gray-200">
                            <?php echo KIT_Commons::renderButton('Save VAT & Charges', 'primary', 'lg', ['type' => 'submit', 'gradient' => true]); ?>

                            <!-- Database Migration Button -->
                            <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                <h4 class="text-sm font-medium text-yellow-800 mb-2">Database Migration</h4>
                                <p class="text-xs text-yellow-700 mb-3">Use these tools to add missing fields/tables safely without wiping data.</p>
                                <div class="flex flex-wrap gap-3">
                                    <?php echo KIT_Commons::renderButton('Add International Price Field', 'warning', 'lg', ['id' => 'migrate-db', 'type' => 'button', 'gradient' => true]); ?>
                                    <?php echo KIT_Commons::renderButton('Run Full DB Migration (Add missing columns/tables)', 'secondary', 'lg', ['id' => 'kit-run-migration', 'type' => 'button']); ?>
                                </div>
                                <div id="migration-result" class="mt-2 text-sm"></div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Brand Colors (60/30/10) Tab -->
        <div id="content-colors" class="tab-panel hidden">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Brand Colors (60 / 30 / 10)</h2>
                    <p class="text-sm text-gray-600 mt-1">Set your primary (60%), secondary (30%), and accent (10%) brand colors.</p>
                </div>
                <div class="p-6">
                    <?php
                    $schema_path = plugin_dir_path(__FILE__) . '../../colorSchema.json';
                    $schema = ['primary' => '#2563eb', 'secondary' => '#111827', 'accent' => '#10b981'];
                    if (file_exists($schema_path)) {
                        $loaded = json_decode(file_get_contents($schema_path), true);
                        if (is_array($loaded)) {
                            $schema = array_merge($schema, $loaded);
                        }
                    }
                    ?>
                    <form method="post" action="">
                        <?php wp_nonce_field('save_settings', 'settings_nonce'); ?>
                        <input type="hidden" name="action" value="save_colors">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="form-group">
                                <?php
                                echo KIT_Commons::Lcolor([
                                    'label' => 'Primary (60%)',
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                    'id' => 'primary_color',
                                    'name' => 'primary_color',
                                    'value' => (string) $schema['primary'],
                                    'preset' => 'form_color',
                                    'class' => 'w-20 h-10 p-0 border rounded',
                                ]);
                                ?>
                            </div>
                            <div class="form-group">
                                <?php
                                echo KIT_Commons::Lcolor([
                                    'label' => 'Secondary (30%)',
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                    'id' => 'secondary_color',
                                    'name' => 'secondary_color',
                                    'value' => (string) $schema['secondary'],
                                    'preset' => 'form_color',
                                    'class' => 'w-20 h-10 p-0 border rounded',
                                ]);
                                ?>
                            </div>
                            <div class="form-group">
                                <?php
                                echo KIT_Commons::Lcolor([
                                    'label' => 'Accent (10%)',
                                    'label_class' => 'block text-sm font-medium text-gray-700 mb-2',
                                    'id' => 'accent_color',
                                    'name' => 'accent_color',
                                    'value' => (string) $schema['accent'],
                                    'preset' => 'form_color',
                                    'class' => 'w-20 h-10 p-0 border rounded',
                                ]);
                                ?>
                            </div>
                        </div>
                        <div class="mt-8 pt-6 border-t border-gray-200">
                            <?php echo KIT_Commons::renderButton('Save Colors', 'primary', 'lg', ['type' => 'submit', 'gradient' => true]); ?>
                        </div>
                    </form>
                    <div class="mt-6 grid grid-cols-3 gap-4">
                        <div class="rounded-lg border p-3" style="background: <?= esc_attr($schema['primary']); ?>; color: #fff;">Primary</div>
                        <div class="rounded-lg border p-3" style="background: <?= esc_attr($schema['secondary']); ?>; color: #fff;">Secondary</div>
                        <div class="rounded-lg border p-3" style="background: <?= esc_attr($schema['accent']); ?>; color: #fff;">Accent</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Setup Seed Tab -->
        <div id="content-setup-seed" class="tab-panel hidden">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Setup Seed</h2>
                    <p class="text-sm text-gray-600 mt-1">Seed drivers, customers, deliveries and waybills from your connected Google Sheet.</p>
                </div>

                <div class="p-6">
                    <?php
                    $gs_configured = class_exists('Courier_Google_Sheets') && Courier_Google_Sheets::is_configured();
                    $auto_sync_enabled = (bool) get_option('courier_google_auto_sync_enabled', 0);
                    $fake_filler_enabled = (bool) get_option('courier_fake_filler_enabled', 0);
                    $maintenance_mode_enabled = (bool) get_option('kit_maintenance_mode', 0);
                    ?>
                    <p class="text-sm text-gray-700 mb-4">
                        <strong>Pull source:</strong>
                        <?php if (class_exists('Courier_Google_Sheets')): ?>
                            <code class="text-xs bg-gray-100 px-1 rounded break-all"><?php
                                $pull_id = method_exists('Courier_Google_Sheets', 'get_source_spreadsheet_id')
                                    ? Courier_Google_Sheets::get_source_spreadsheet_id()
                                    : Courier_Google_Sheets::get_default_spreadsheet_id();
                                echo esc_html($pull_id);
                            ?></code>
                        <?php else: ?>
                            <span class="text-gray-500">(Google Sheets class not loaded)</span>
                        <?php endif; ?>
                        <span class="text-gray-500"> — tab/range from <code class="text-xs bg-gray-100 px-1 rounded">COURIER_GOOGLE_SEED_SHEET_NAME</code> / <code class="text-xs bg-gray-100 px-1 rounded">COURIER_GOOGLE_SEED_RANGE</code></span>
                    </p>
                    <p class="text-sm text-gray-700 mb-4">
                        <strong>Push destination:</strong>
                        <?php if (class_exists('Courier_Google_Sheets')): ?>
                            <code class="text-xs bg-gray-100 px-1 rounded break-all"><?php
                                $push_id = method_exists('Courier_Google_Sheets', 'get_sync_spreadsheet_id')
                                    ? Courier_Google_Sheets::get_sync_spreadsheet_id()
                                    : Courier_Google_Sheets::get_default_spreadsheet_id();
                                echo esc_html($push_id);
                            ?></code>
                        <?php else: ?>
                            <span class="text-gray-500">(Google Sheets class not loaded)</span>
                        <?php endif; ?>
                        <span class="text-gray-500"> — set <code class="text-xs bg-gray-100 px-1 rounded">COURIER_GOOGLE_SYNC_SPREADSHEET_ID</code> in wp-config; defaults to pull source when unset</span>
                    </p>

                    <div class="mb-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-2 gap-4">
                        <div class="p-4 rounded-lg border <?php echo $gs_configured ? 'bg-blue-50 border-blue-200' : 'bg-yellow-50 border-yellow-200'; ?>">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <?php if ($gs_configured): ?>
                                        <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    <?php else: ?>
                                        <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-3 text-sm">
                                    <p class="font-medium <?php echo $gs_configured ? 'text-blue-800' : 'text-yellow-800'; ?>"><?php echo $gs_configured ? 'Google Sheet Connected' : 'Google Sheet Not Configured'; ?></p>
                                    <?php if ($gs_configured): ?>
                                        <p class="mt-1 text-blue-700">Sheet shared with service account</p>
                                    <?php else: ?>
                                        <p class="mt-1 text-yellow-700">Add credentials and share sheet with service account email</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="p-4 rounded-lg border <?php echo $auto_sync_enabled ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200'; ?>">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <?php if ($auto_sync_enabled): ?>
                                        <svg class="h-5 w-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    <?php else: ?>
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-12.728 12.728M5.636 5.636l12.728 12.728" />
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-3 text-sm">
                                    <p class="font-medium <?php echo $auto_sync_enabled ? 'text-green-800' : 'text-gray-700'; ?>">
                                        <?php echo $auto_sync_enabled ? 'Auto Sync Active' : 'Auto Sync Paused'; ?>
                                    </p>
                                    <p class="mt-1 <?php echo $auto_sync_enabled ? 'text-green-700' : 'text-gray-600'; ?>">
                                        <?php echo $auto_sync_enabled ? 'Waybill changes are uploading automatically to the push destination sheet.' : 'Waybill changes are not sent to the push destination Google Sheet.'; ?>
                                    </p>
                                </div>
                            </div>
                            <form method="post" action="" class="mt-3">
                                <?php wp_nonce_field('toggle_google_auto_sync', 'toggle_google_auto_sync_nonce'); ?>
                                <input type="hidden" name="action" value="toggle_google_auto_sync">
                                <input type="hidden" name="enable_auto_sync" value="<?php echo $auto_sync_enabled ? '0' : '1'; ?>">
                                <?php echo KIT_Commons::renderButton(
                                    $auto_sync_enabled ? 'Turn Auto Sync Off' : 'Turn Auto Sync On',
                                    $auto_sync_enabled ? 'danger' : 'secondary',
                                    'lg',
                                    ['type' => 'submit']
                                ); ?>
                            </form>
                        </div>
                        <div class="p-4 rounded-lg border <?php echo $fake_filler_enabled ? 'bg-indigo-50 border-indigo-200' : 'bg-gray-50 border-gray-200'; ?>">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <?php if ($fake_filler_enabled): ?>
                                        <svg class="h-5 w-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                    <?php else: ?>
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-12.728 12.728M5.636 5.636l12.728 12.728" />
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-3 text-sm">
                                    <p class="font-medium <?php echo $fake_filler_enabled ? 'text-indigo-800' : 'text-gray-700'; ?>">
                                        <?php echo $fake_filler_enabled ? 'Fake Filler Active' : 'Fake Filler Disabled'; ?>
                                    </p>
                                    <p class="mt-1 <?php echo $fake_filler_enabled ? 'text-indigo-700' : 'text-gray-600'; ?>">
                                        <?php echo $fake_filler_enabled ? 'Floating Fake Fill button is enabled on waybill forms. It prioritizes existing Google Sheet/table customers, then uses fakefiller.json seed data.' : 'Enable this to quickly auto-fill inputs/selects for debugging using fakefiller.json + existing customers.'; ?>
                                    </p>
                                </div>
                            </div>
                            <form method="post" action="" class="mt-3">
                                <?php wp_nonce_field('toggle_fake_filler', 'toggle_fake_filler_nonce'); ?>
                                <input type="hidden" name="action" value="toggle_fake_filler">
                                <input type="hidden" name="enable_fake_filler" value="<?php echo $fake_filler_enabled ? '0' : '1'; ?>">
                                <?php echo KIT_Commons::renderButton(
                                    $fake_filler_enabled ? 'Turn Fake Filler Off' : 'Turn Fake Filler On',
                                    $fake_filler_enabled ? 'danger' : 'secondary',
                                    'lg',
                                    ['type' => 'submit']
                                ); ?>
                            </form>
                        </div>
                        <div class="p-4 rounded-lg border <?php echo $maintenance_mode_enabled ? 'bg-amber-50 border-amber-300' : 'bg-gray-50 border-gray-200'; ?>">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <?php if ($maintenance_mode_enabled): ?>
                                        <svg class="h-5 w-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-1.964-1.333-2.732 0L3.732 16c-.77 1.333.192 3 1.732 3z" />
                                        </svg>
                                    <?php else: ?>
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-3 text-sm">
                                    <p class="font-medium <?php echo $maintenance_mode_enabled ? 'text-amber-900' : 'text-gray-700'; ?>">
                                        <?php echo $maintenance_mode_enabled ? 'Maintenance mode is ON' : 'Maintenance mode is OFF'; ?>
                                    </p>
                                    <p class="mt-1 <?php echo $maintenance_mode_enabled ? 'text-amber-800' : 'text-gray-600'; ?>">
                                        <?php echo $maintenance_mode_enabled ? 'All plugin admin and portal pages show a blurred “Maintenance mode” screen. This Settings page stays usable so you can disable it.' : 'When enabled, employees see a maintenance screen instead of waybills, dashboard, warehouse, etc.'; ?>
                                    </p>
                                </div>
                            </div>
                            <form method="post" action="" class="mt-3">
                                <?php wp_nonce_field('toggle_maintenance_mode', 'toggle_maintenance_mode_nonce'); ?>
                                <input type="hidden" name="action" value="toggle_maintenance_mode">
                                <input type="hidden" name="enable_maintenance_mode" value="<?php echo $maintenance_mode_enabled ? '0' : '1'; ?>">
                                <?php echo KIT_Commons::renderButton(
                                    $maintenance_mode_enabled ? 'Turn Maintenance Mode Off' : 'Turn Maintenance Mode On',
                                    $maintenance_mode_enabled ? 'secondary' : 'primary',
                                    'lg',
                                    ['type' => 'submit']
                                ); ?>
                            </form>
                        </div>
                    </div>

                    <?php
                    $kit_seed_pending_flash = null;
                    $kit_seed_still_running = false;
                    if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_access_settings()) {
                        $kit_seed_uid = get_current_user_id();
                        kit_seed_release_stale_running_transient($kit_seed_uid);
                        $kit_seed_still_running = (bool) get_transient('kit_seed_running_' . $kit_seed_uid);
                        if (!$kit_seed_still_running) {
                            $kit_seed_pending_flash = get_transient('kit_setup_seed_flash_' . $kit_seed_uid);
                            if (is_array($kit_seed_pending_flash) && !empty($kit_seed_pending_flash)) {
                                delete_transient('kit_setup_seed_flash_' . $kit_seed_uid);
                            }
                        }
                    }
                    ?>

                    <div class="mb-4 flex gap-3 items-center flex-wrap">
                        <button id="provision-sheet-btn" type="button"
                            style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:8px;background:#0d9488;color:#fff;font-size:14px;font-weight:500;border:none;cursor:pointer;"
                            <?php echo ($gs_configured ? '' : 'disabled title="Google Sheets not configured" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:8px;background:#9ca3af;color:#fff;font-size:14px;font-weight:500;border:none;cursor:not-allowed;"'); ?>>
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Provision Sheet Tabs
                        </button>
                        <button id="sync-all-to-sheet-btn" type="button"
                            style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:8px;background:#2563eb;color:#fff;font-size:14px;font-weight:500;border:none;cursor:pointer;"
                            <?php echo ($gs_configured ? '' : 'disabled title="Google Sheets not configured" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:8px;background:#9ca3af;color:#fff;font-size:14px;font-weight:500;border:none;cursor:not-allowed;"'); ?>>
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            Sync to Sheet
                        </button>
                        <span id="provision-sheet-spinner" style="display:none;font-size:13px;color:#6b7280;">Creating tabs…</span>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" id="seed-setup-form" class="inline m-0">
                            <?php wp_nonce_field('seed_setup', 'setup_seed_nonce'); ?>
                            <input type="hidden" name="action" value="kit_run_google_sheet_seed">
                            <input type="hidden" name="seed_source" value="google_sheet">
                            <?php echo KIT_Commons::renderButton('Run Setup Seed', 'primary', 'lg', ['type' => 'submit', 'gradient' => true, 'id' => 'run-setup-seed-button']); ?>
                        </form>

                        <form method="post" action="" id="wipe-tables-form" class="inline m-0">
                            <?php wp_nonce_field('wipe_tables', 'wipe_tables_nonce'); ?>
                            <input type="hidden" name="action" value="wipe_tables">
                            <?php echo KIT_Commons::renderButton('Wipe Tables', 'danger', 'lg', [
                                'type' => 'submit',
                                'id' => 'wipe-tables-button',
                                'title' => 'Clears row data from: kit_waybill_items, kit_quotations, kit_invoices, kit_waybills, kit_deliveries, kit_company_customers, kit_customers, kit_drivers (order respects foreign keys).',
                            ]); ?>
                        </form>

                        <form method="post" action="" class="inline m-0">
                            <?php wp_nonce_field('clear_plugin_logs', 'clear_logs_nonce'); ?>
                            <input type="hidden" name="action" value="clear_plugin_logs">
                            <button type="submit" class="px-4 py-2 rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50" title="Clears wp-content/debug.log, plugin .cursor/debug-*.log files, and wp_kit_sync_runs / wp_kit_sync_run_rows audit tables." onclick="return confirm('Clear debug.log, plugin debug files, and sync audit tables?');">Clear plugin logs</button>
                        </form>
                    </div>

                    <script>
                    (function(){
                        var noticeEl = document.getElementById('kit-settings-notice');
                        var noticeStyles = {
                            success: 'mb-6 p-4 rounded-lg border text-sm bg-green-50 border-green-200 text-green-800',
                            error: 'mb-6 p-4 rounded-lg border text-sm bg-red-50 border-red-200 text-red-800',
                            info: 'mb-6 p-4 rounded-lg border text-sm bg-blue-50 border-blue-200 text-blue-800',
                            warning: 'mb-6 p-4 rounded-lg border text-sm bg-amber-50 border-amber-200 text-amber-900'
                        };

                        function kitSettingsShowNotice(type, title, html) {
                            if (!noticeEl) return;
                            noticeEl.style.display = 'block';
                            noticeEl.className = noticeStyles[type] || noticeStyles.info;
                            noticeEl.innerHTML = '<p class="font-medium">' + title + '</p><div class="mt-2">' + html + '</div>';
                            noticeEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }

                        // --- Sync to Sheet (entity-by-entity to avoid PHP timeout) ---
                        var syncBtn    = document.getElementById('sync-all-to-sheet-btn');
                        var syncNonce  = '<?php echo wp_create_nonce('kit_sync_all_to_sheet'); ?>';
                        if (syncBtn) {
                            syncBtn.addEventListener('click', function() {
                                if (!confirm('This will overwrite all data in the Google Sheet with current DB values. Continue?')) return;
                                var entities = ['drivers', 'customers', 'deliveries', 'waybills'];
                                var labels   = { drivers: 'Drivers', customers: 'Customers', deliveries: 'Deliveries', waybills: 'Waybills + Items' };
                                syncBtn.disabled = true;
                                syncBtn.style.background = '#9ca3af';
                                kitSettingsShowNotice('info', 'Syncing to Sheet', '<strong>Syncing…</strong><br>');

                                function syncNext(idx) {
                                    if (idx >= entities.length) {
                                        syncBtn.disabled = false;
                                        syncBtn.style.background = '#2563eb';
                                        noticeEl.innerHTML = '<p class="font-medium">Sync to Sheet Complete</p><div class="mt-2" style="line-height:1.8;">' + noticeEl.querySelector('.mt-2').innerHTML + '<br><strong style="color:#065f46;">All done.</strong></div>';
                                        noticeEl.className = noticeStyles.success;
                                        return;
                                    }
                                    var entity = entities[idx];
                                    var body = noticeEl.querySelector('.mt-2');
                                    body.innerHTML += '↻ Syncing ' + labels[entity] + '…<br>';
                                    noticeEl.scrollTop = noticeEl.scrollHeight;
                                    var fd = new FormData();
                                    fd.append('action', 'kit_sync_all_to_sheet');
                                    fd.append('nonce', syncNonce);
                                    fd.append('entity', entity);
                                    fetch(ajaxurl, { method: 'POST', body: fd })
                                        .then(function(r){ return r.json(); })
                                        .then(function(data){
                                            if (data.success) {
                                                body.innerHTML += '✓ ' + (data.data.message || labels[entity] + ' done') + '<br>';
                                                syncNext(idx + 1);
                                            } else {
                                                var msg = data.data && data.data.message ? data.data.message : JSON.stringify(data);
                                                body.innerHTML += '<span style="color:#991b1b;">✗ ' + labels[entity] + ' failed: ' + msg + '</span><br>';
                                                noticeEl.className = noticeStyles.error;
                                                syncBtn.disabled = false;
                                                syncBtn.style.background = '#2563eb';
                                            }
                                        })
                                        .catch(function(err){
                                            body.innerHTML += '<span style="color:#991b1b;">✗ ' + labels[entity] + ' request error: ' + err + '</span><br>';
                                            noticeEl.className = noticeStyles.error;
                                            syncBtn.disabled = false;
                                            syncBtn.style.background = '#2563eb';
                                        });
                                }
                                syncNext(0);
                            });
                        }

                        // --- Setup Seed (admin-ajax — avoids MAMP admin-post 500 timeout) ---
                        var seedForm = document.getElementById('seed-setup-form');
                        var seedBtn = document.getElementById('run-setup-seed-button');

                        function showSeedResult(ok, html) {
                            kitSettingsShowNotice(ok ? 'success' : 'error', ok ? 'Setup Seed' : 'Setup Seed Failed', html);
                        }

                        function resetSeedBtn() {
                            if (seedBtn) {
                                seedBtn.disabled = false;
                                seedBtn.textContent = 'Run Setup Seed';
                            }
                        }

                        function renderSeedFlash(d) {
                            if (!d) return;
                            if (d.success) {
                                var html = '<strong>Setup Seed Successful</strong><p>' + (d.message || '') + '</p>';
                                if (d.stats) {
                                    html += '<p>Drivers: ' + (d.stats.drivers || 0) + ', Customers: ' + (d.stats.customers || 0)
                                        + ', Waybills: ' + (d.stats.waybills || 0) + ', Skipped: ' + (d.stats.skipped || 0) + '</p>';
                                }
                                showSeedResult(true, html);
                            } else {
                                showSeedResult(false, '<strong>Setup Seed Failed</strong><p>' + (d.message || 'Unknown error') + '</p>');
                            }
                        }

                        var seedStatusNonce = '<?php echo wp_create_nonce('kit_seed_status'); ?>';
                        var seedPollMaxTicks = 200;
                        var seedPollIntervalMs = 3000;

                        var seedPhaseLabels = {
                            queued: 'Queued…',
                            starting: 'Starting…',
                            reading_sheets: 'Reading Google Sheets…',
                            entity_tabs: 'Loading entity tabs…',
                            customers: 'Importing customers…',
                            drivers: 'Importing drivers…',
                            deliveries: 'Importing deliveries…',
                            preparing: 'Preparing waybill data…',
                            waybills: 'Seeding waybills',
                            parcels: 'Writing parcel line items…',
                            merging: 'Merging duplicate customers…',
                            running: 'Running…'
                        };

                        function formatSeedProgress(progress, tick) {
                            var elapsed = (progress && progress.elapsed) ? progress.elapsed : (tick * (seedPollIntervalMs / 1000));
                            var phase = (progress && progress.phase) ? progress.phase : 'running';
                            var label = seedPhaseLabels[phase] || phase;
                            var line = '<strong>Google Sheet seed running…</strong> (' + elapsed + 's elapsed)<br>' + label;
                            if (phase === 'waybills' && progress && progress.rows_total > 0) {
                                line += ' — ' + progress.rows_done + ' / ' + progress.rows_total;
                            }
                            return line;
                        }

                        function pollSeedStatus(tick) {
                            tick = tick || 0;
                            var fd = new FormData();
                            fd.append('action', 'kit_google_sheet_seed_status');
                            fd.append('nonce', seedStatusNonce);
                            fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                                .then(function(r) { return r.json(); })
                                .then(function(j) {
                                    if (j && j.success && j.data && j.data.done && j.data.result) {
                                        resetSeedBtn();
                                        renderSeedFlash(j.data.result);
                                        return;
                                    }
                                    if (j && j.success && j.data && j.data.running) {
                                        if (tick < seedPollMaxTicks) {
                                            showSeedResult(true, formatSeedProgress(j.data.progress, tick));
                                            setTimeout(function() { pollSeedStatus(tick + 1); }, seedPollIntervalMs);
                                            return;
                                        }
                                    }
                                    if (tick < seedPollMaxTicks) {
                                        showSeedResult(true, formatSeedProgress(null, tick));
                                        setTimeout(function() { pollSeedStatus(tick + 1); }, seedPollIntervalMs);
                                    } else {
                                        resetSeedBtn();
                                        showSeedResult(false, '<strong>Timed out waiting for UI</strong><p>Seed may still be running. Refresh this page in a minute to see the result.</p>');
                                    }
                                })
                                .catch(function() {
                                    if (tick < seedPollMaxTicks) {
                                        setTimeout(function() { pollSeedStatus(tick + 1); }, seedPollIntervalMs);
                                    } else {
                                        resetSeedBtn();
                                        showSeedResult(false, '<strong>Could not reach server</strong><p>Check Settings again shortly.</p>');
                                    }
                                });
                        }

                        function runSetupSeed() {
                            if (!seedForm) return;
                            if (!confirm('Run setup seed from Google Sheet? This may take several minutes on first run.')) return;
                            if (seedBtn) {
                                seedBtn.disabled = true;
                                seedBtn.textContent = 'Seeding…';
                            }
                            showSeedResult(true, '<strong>Starting Google Sheet seed…</strong>');
                            var fd = new FormData(seedForm);
                            fd.set('action', 'kit_run_google_sheet_seed');
                            fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                                .then(function(r) { return r.text(); })
                                .then(function(text) {
                                    var data = null;
                                    try {
                                        data = JSON.parse(text);
                                    } catch (e) {
                                        pollSeedStatus(0);
                                        return;
                                    }
                                    if (data && data.success && data.data && (data.data.started || data.data.poll)) {
                                        pollSeedStatus(0);
                                        return;
                                    }
                                    resetSeedBtn();
                                    if (data && data.success && data.data) {
                                        renderSeedFlash(data.data);
                                    } else {
                                        var msg = (data && data.data && data.data.message) ? data.data.message : text.substring(0, 200);
                                        showSeedResult(false, '<strong>Setup Seed Failed</strong><p>' + msg + '</p>');
                                    }
                                })
                                .catch(function() {
                                    pollSeedStatus(0);
                                });
                        }

                        if (seedForm) {
                            seedForm.addEventListener('submit', function(e) {
                                e.preventDefault();
                                runSetupSeed();
                            });
                        }
                        if (new URLSearchParams(location.search).get('seed_autostart') === '1') {
                            var clean = new URL(location.href);
                            clean.searchParams.delete('seed_autostart');
                            history.replaceState({}, '', clean.toString());
                            runSetupSeed();
                        }

                        <?php if (is_array($kit_seed_pending_flash) && !empty($kit_seed_pending_flash)) : ?>
                        renderSeedFlash(<?php echo wp_json_encode($kit_seed_pending_flash); ?>);
                        <?php elseif ($kit_seed_still_running) : ?>
                        if (seedBtn) {
                            seedBtn.disabled = true;
                            seedBtn.textContent = 'Seeding…';
                        }
                        pollSeedStatus(0);
                        <?php endif; ?>

                        // --- Provision Sheet Tabs ---
                        var btn = document.getElementById('provision-sheet-btn');
                        var spinner = document.getElementById('provision-sheet-spinner');
                        if (btn) {
                        btn.addEventListener('click', function() {
                            btn.disabled = true;
                            btn.style.background = '#9ca3af';
                            spinner.style.display = 'inline';
                            if (noticeEl) noticeEl.style.display = 'none';
                            var fd = new FormData();
                            fd.append('action', 'kit_provision_google_sheet');
                            fd.append('nonce', '<?php echo wp_create_nonce('kit_provision_google_sheet'); ?>');
                            fetch(ajaxurl, { method: 'POST', body: fd })
                                .then(function(r){ return r.json(); })
                                .then(function(data){
                                    spinner.style.display = 'none';
                                    btn.disabled = false;
                                    btn.style.background = '#0d9488';
                                    if (data.success) {
                                        var created = (data.data.created || []).join(', ') || 'none';
                                        var skipped = (data.data.skipped || []).join(', ') || 'none';
                                        kitSettingsShowNotice('success', 'Sheet Tabs Provisioned', 'Created: ' + created + '<br>Already existed: ' + skipped);
                                    } else {
                                        var msg = data.data && data.data.message ? data.data.message : JSON.stringify(data);
                                        kitSettingsShowNotice('error', 'Provision Failed', msg);
                                    }
                                })
                                .catch(function(err){
                                    spinner.style.display = 'none';
                                    btn.disabled = false;
                                    btn.style.background = '#0d9488';
                                    kitSettingsShowNotice('error', 'Request Failed', String(err));
                                });
                        });
                        }
                    })();
                    </script>

                    <details class="mt-8 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <summary class="cursor-pointer font-medium text-gray-900">Sheet-to-DB Mapping</summary>
                        <p class="text-sm text-gray-600 mt-2 mb-3">How Google Sheet columns map to database tables when you run Setup Seed.</p>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead>
                                    <tr class="bg-gray-100">
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">Sheet Column</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">DB Table</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">DB Column</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr>
                                        <td class="px-3 py-2">waybill_no, wb_no, parcel_id, parcel (WB:- 4600 → 4600)</td>
                                        <td>kit_waybills</td>
                                        <td>waybill_no</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">delivery_reference, delivery_ref, del_ref (optional)</td>
                                        <td>kit_waybills</td>
                                        <td>delivery_id → kit_deliveries</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">delivery_id, del_id, delivery (numeric truck id)</td>
                                        <td>kit_waybills</td>
                                        <td>delivery_id → kit_deliveries.id (refs from kit_deliveries tab when ids drift)</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">delivery, driver, driver_name, truck_driver</td>
                                        <td>kit_drivers, kit_deliveries</td>
                                        <td>derived</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">dispatch_date, truck_dispatch_date, created_at</td>
                                        <td>kit_deliveries</td>
                                        <td>dispatch_date</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">cust_name_ignore, customer, cust_name, client</td>
                                        <td>kit_customers</td>
                                        <td>name / surname</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">city_name_ignore, city, city_name</td>
                                        <td>kit_operating_cities</td>
                                        <td>lookup → city_id</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">description, waybill_description</td>
                                        <td>kit_waybills</td>
                                        <td>description</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">description</td>
                                        <td>kit_waybill_items</td>
                                        <td>item_name</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">direction_id</td>
                                        <td>kit_waybills</td>
                                        <td>direction_id</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">waybill_no</td>
                                        <td>kit_waybills</td>
                                        <td>waybill_no</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">product_invoice_number</td>
                                        <td>kit_waybills</td>
                                        <td>product_invoice_number</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">product_invoice_amount</td>
                                        <td>kit_waybills</td>
                                        <td>product_invoice_amount</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">waybill_items_total</td>
                                        <td>kit_waybills</td>
                                        <td>waybill_items_total</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">misc_total</td>
                                        <td>kit_waybills</td>
                                        <td>misc_total</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">border_clearing_total</td>
                                        <td>kit_waybills</td>
                                        <td>border_clearing_total</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">sad500_amount</td>
                                        <td>kit_waybills</td>
                                        <td>sad500_amount</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">sadc_amount</td>
                                        <td>kit_waybills</td>
                                        <td>sadc_amount</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">international_price_rands</td>
                                        <td>kit_waybills</td>
                                        <td>international_price_rands</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">item_length, item_width, item_height</td>
                                        <td>kit_waybills</td>
                                        <td>item_length, item_width, item_height</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">total_mass_kg</td>
                                        <td>kit_waybills</td>
                                        <td>total_mass_kg</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">total_volume</td>
                                        <td>kit_waybills</td>
                                        <td>total_volume</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">mass_charge, volume_charge</td>
                                        <td>kit_waybills</td>
                                        <td>mass_charge, volume_charge</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">charge_basis</td>
                                        <td>kit_waybills</td>
                                        <td>charge_basis</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">vat_include</td>
                                        <td>kit_waybills</td>
                                        <td>vat_include</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">warehouse</td>
                                        <td>kit_waybills</td>
                                        <td>warehouse</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">miscellaneous</td>
                                        <td>kit_waybills</td>
                                        <td>miscellaneous</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">include_sad500, include_sadc</td>
                                        <td>kit_waybills</td>
                                        <td>include_sad500, include_sadc</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">return_load</td>
                                        <td>kit_waybills</td>
                                        <td>return_load</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">tracking_number</td>
                                        <td>kit_waybills</td>
                                        <td>tracking_number</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">status</td>
                                        <td>kit_waybills</td>
                                        <td>status</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2">dispatch_date / created_at</td>
                                        <td>kit_deliveries</td>
                                        <td>dispatch_date</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </div>
        </div>

        <!-- Import Tab removed -->

        <!-- Terms & Conditions Tab -->
        <div id="content-terms" class="tab-panel hidden">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Terms & Conditions</h2>
                    <p class="text-sm text-gray-600 mt-1">Manage the Terms & Conditions shown to customers.</p>
                </div>

                <div class="p-6">
                    <form method="post" action="">
                        <?php wp_nonce_field('save_settings', 'settings_nonce'); ?>
                        <input type="hidden" name="action" value="save_terms">

                        <?php
                        $existing_terms_html = get_option('kit_terms_conditions', '');
                        $existing_items = [];
                        if (is_string($existing_terms_html) && preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $existing_terms_html, $m)) {
                            foreach ($m[1] as $seg) {
                                $existing_items[] = wp_strip_all_tags($seg);
                            }
                        }
                        if (empty($existing_items)) {
                            $existing_items = [''];
                        }
                        $kit_terms_item_input_empty = KIT_Commons::Linput([
                            'no_label' => true,
                            'omit_id' => true,
                            'name' => 'terms_items[]',
                            'type' => 'text',
                            'value' => '',
                            'placeholder' => 'Enter a term item',
                            'preset' => '',
                            'class' => 'flex-1 px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                        ]);
                        ?>

                        <div class="form-group">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Terms & Conditions Items</label>
                            <div id="terms-list" class="space-y-2">
                                <?php foreach ($existing_items as $txt): ?>
                                    <div class="flex items-center gap-2">
                                        <?php echo KIT_Commons::Linput([
                                            'no_label' => true,
                                            'omit_id' => true,
                                            'name' => 'terms_items[]',
                                            'type' => 'text',
                                            'value' => $txt,
                                            'placeholder' => 'Enter a term item',
                                            'preset' => '',
                                            'class' => 'flex-1 px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                                        ]); ?>
                                        <?php echo KIT_Commons::renderButton('Remove', 'secondary', 'lg', ['type' => 'button', 'classes' => 'remove-term-item px-3 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100']); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3">
                                <?php echo KIT_Commons::renderButton('Add Item', 'secondary', 'lg', ['type' => 'button', 'id' => 'add-term-item', 'classes' => 'px-4 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100']); ?>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Items will be saved as a bullet list in PDFs. You can still paste full HTML below if needed.</p>
                        </div>

                        <div class="form-group mt-4">
                            <label for="terms_content" class="block text-sm font-medium text-gray-700 mb-2">Or paste Terms HTML (optional)</label>
                            <textarea id="terms_content" name="terms_content" rows="6" class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="<ul><li>Example term</li></ul>"></textarea>
                            <p class="text-xs text-gray-500 mt-1">If provided, pasted HTML will be used when list items are empty.</p>
                        </div>

                        <div class="mt-8 pt-6 border-t border-gray-200">
                            <?php echo KIT_Commons::renderButton('Save Terms & Conditions', 'primary', 'lg', ['type' => 'submit', 'gradient' => true]); ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Security Notice -->
    <div class="mt-8 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">Security Notice</h3>
                <div class="mt-2 text-sm text-yellow-700">
                    <p>This settings page is restricted to authorized administrators only. All changes are logged for security purposes.</p>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<style>
.kit-settings-page {
    background: linear-gradient(180deg, #f7fafc 0%, #eef2f7 100%);
    margin-left: -20px;
    padding: 24px 20px 40px;
}

.kit-settings-container {
    max-width: 1200px;
}

.kit-settings-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    padding-bottom: 10px;
}

.tab-button {
    border: 1px solid transparent;
    border-radius: 999px;
    color: #4b5563;
    background: #ffffff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    transition: all 0.2s ease;
}

.tab-button:hover {
    color: #111827;
    border-color: #d1d5db;
    transform: translateY(-1px);
}

.tab-button.active {
    color: #1d4ed8;
    border-color: #bfdbfe;
    background: #eff6ff;
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.2);
}

.tab-panel {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.tab-panel:not(.hidden) {
    opacity: 1;
    transform: translateY(0);
}

.form-group {
    display: grid;
    gap: 6px;
}

.kit-settings-page .bg-white.rounded-xl.shadow-sm.border {
    border-color: #dbe3ef;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
}

.kit-settings-page input[type="text"],
.kit-settings-page input[type="email"],
.kit-settings-page input[type="number"],
.kit-settings-page input[type="url"],
.kit-settings-page input[type="tel"],
.kit-settings-page select,
.kit-settings-page textarea {
    border-color: #cbd5e1;
    background-color: #f8fafc;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.kit-settings-page input:focus,
.kit-settings-page select:focus,
.kit-settings-page textarea:focus {
    border-color: #3b82f6 !important;
    background-color: #fff;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab functionality
    const tabs = document.querySelectorAll('.tab-button');
    const panels = document.querySelectorAll('.tab-panel');
    const storageKey = 'kit_settings_active_tab';

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.id.replace('tab-', 'content-');

            // Remove active class from all tabs and panels
            tabs.forEach(t => t.classList.remove('active'));
            panels.forEach(p => p.classList.add('hidden'));

            // Add active class to clicked tab and show target panel
            tab.classList.add('active');
            document.getElementById(target).classList.remove('hidden');

            // Persist active tab id
            try {
                localStorage.setItem(storageKey, tab.id);
            } catch (e) {}
        });
    });

    // Restore previously active tab on load
    try {
        const savedTabId = localStorage.getItem(storageKey);
        const savedTab = savedTabId ? document.getElementById(savedTabId) : null;
        if (savedTab && savedTab.classList.contains('tab-button')) {
            // Simulate click to apply classes/panels logic
            savedTab.click();
        }
    } catch (e) {}

    // Live preview for charges
    const vatInput = document.getElementById('vat_percentage');
    const sadcInput = document.getElementById('sadc_charge');
    const sad500Input = document.getElementById('sad500_charge');
    const internationalInput = document.getElementById('international_price');

    function updatePreview() {
        document.getElementById('vat-preview').textContent = (vatInput.value || 0) + '%';
        document.getElementById('sadc-preview').textContent = 'R ' + parseFloat(sadcInput.value || 0).toFixed(2);
        document.getElementById('sad500-preview').textContent = 'R ' + parseFloat(sad500Input.value || 0).toFixed(2);
        document.getElementById('international-preview').textContent = '$' + parseFloat(internationalInput.value || 0).toFixed(2);
    }

    vatInput.addEventListener('input', updatePreview);
    sadcInput.addEventListener('input', updatePreview);
    sad500Input.addEventListener('input', updatePreview);
    internationalInput.addEventListener('input', updatePreview);

    // Initialize preview
    updatePreview();

    // Live Exchange Rate Functionality
    let currentExchangeRate = 18.50; // Default fallback rate

    async function fetchExchangeRate() {
        try {
            // Try to fetch from a free exchange rate API
            const response = await fetch('https://api.exchangerate-api.com/v4/latest/USD');
            if (response.ok) {
                const data = await response.json();
                currentExchangeRate = data.rates.ZAR;
                updateExchangeRateDisplay();
                updateLastUpdated();
            } else {
                throw new Error('Failed to fetch exchange rate');
            }
        } catch (error) {
            console.log('Using fallback exchange rate:', currentExchangeRate);
            updateExchangeRateDisplay();
            updateLastUpdated();
        }
    }

    function updateExchangeRateDisplay() {
        const usdAmount = parseFloat(internationalInput.value) || 0;
        const zarAmount = usdAmount * currentExchangeRate;

        document.getElementById('exchange-rate').textContent = `1 USD = R ${currentExchangeRate.toFixed(2)}`;
        document.getElementById('zar-amount').textContent = `R ${zarAmount.toFixed(2)}`;
    }

    function updateLastUpdated() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', {
            hour12: false,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        document.getElementById('last-updated').textContent = `Last updated: ${timeString}`;
    }

    // Update exchange rate display when international price changes
    internationalInput.addEventListener('input', updateExchangeRateDisplay);

    // Fetch exchange rate on page load and every 5 minutes
    fetchExchangeRate();
    setInterval(fetchExchangeRate, 5 * 60 * 1000); // Update every 5 minutes

    // Seeding form confirmation
    const seedingForm = document.getElementById('seeding-form');
    const seedButton = document.getElementById('seed-button');

    if (seedingForm && seedButton) {
        seedingForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const confirmed = confirm(
                'Are you sure you want to seed customer data?\n\n' +
                'This action:\n' +
                '• Cannot be undone\n' +
                '• Can only be performed once per plugin installation\n' +
                '• Will insert customer data from the CSV file\n\n' +
                'Click OK to continue or Cancel to abort.'
            );

            if (confirmed) {
                // Show loading state
                seedButton.disabled = true;
                seedButton.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Seeding...';

                // Submit the form
                this.submit();
            }
        });
    }

    // Database Migration Functionality
    const migrateButton = document.getElementById('migrate-db');
    const runMigrationButton = document.getElementById('kit-run-migration');
    const migrationResult = document.getElementById('migration-result');

    if (migrateButton) {
        migrateButton.addEventListener('click', async function() {
            migrateButton.disabled = true;
            migrateButton.textContent = 'Adding Field...';
            migrationResult.innerHTML = '<span class="text-blue-600">⏳ Adding international_price field to database...</span>';

            try {
                // Create a simple AJAX request to run the migration
                const formData = new FormData();
                formData.append('action', 'migrate_international_price');
                formData.append('nonce', '<?php echo wp_create_nonce("migrate_international_price"); ?>');

                const response = await fetch(ajaxurl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    migrationResult.innerHTML = '<span class="text-green-600">✅ ' + result.data.message + '</span>';
                    // Refresh the page after successful migration
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    migrationResult.innerHTML = '<span class="text-red-600">❌ ' + (result.data.message || 'Migration failed') + '</span>';
                }
            } catch (error) {
                migrationResult.innerHTML = '<span class="text-red-600">❌ Error: ' + error.message + '</span>';
            } finally {
                migrateButton.disabled = false;
                migrateButton.textContent = 'Add International Price Field to Database';
            }
        });
    }

    if (runMigrationButton) {
        runMigrationButton.addEventListener('click', async function() {
            runMigrationButton.disabled = true;
            runMigrationButton.textContent = 'Running Migration...';
            migrationResult.innerHTML = '<span class="text-blue-600">⏳ Running database migration (adding missing tables/columns)...</span>';

            try {
                const formData = new FormData();
                formData.append('action', 'kit_migrate_schema');
                formData.append('nonce', '<?php echo wp_create_nonce("kit_migrate_schema"); ?>');

                const response = await fetch(ajaxurl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    migrationResult.innerHTML = '<span class="text-green-600">✅ ' + result.data.message + '</span>';
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    migrationResult.innerHTML = '<span class="text-red-600">❌ ' + (result.data.message || 'Migration failed') + '</span>';
                }
            } catch (error) {
                migrationResult.innerHTML = '<span class="text-red-600">❌ Error: ' + error.message + '</span>';
            } finally {
                runMigrationButton.disabled = false;
                runMigrationButton.textContent = 'Run Full DB Migration (Add missing columns/tables)';
            }
        });
    }

    // Removed: Server Connection Test Functionality (deprecated)

    // Terms items add/remove
    const addBtn = document.getElementById('add-term-item');
    const list = document.getElementById('terms-list');
    const kitTermsItemInputTpl = <?php echo wp_json_encode($kit_terms_item_input_empty); ?>;
    if (addBtn && list) {
        addBtn.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'flex items-center gap-2';
            row.innerHTML = kitTermsItemInputTpl + '\n<button type="button" class="remove-term-item px-3 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">Remove</button>';
            list.appendChild(row);
        });
        list.addEventListener('click', (e) => {
            if (e.target && e.target.classList.contains('remove-term-item')) {
                const row = e.target.closest('.flex');
                if (row && list.children.length > 1) {
                    row.remove();
                } else if (row) {
                    // Clear the last remaining input instead of removing
                    const input = row.querySelector('input[name="terms_items[]"]');
                    if (input) input.value = '';
                }
            }
        });
    }

    // Wipe tables form confirmation
    const wipeTablesForm = document.getElementById('wipe-tables-form');
    const wipeTablesButton = document.getElementById('wipe-tables-button');

    if (wipeTablesForm && wipeTablesButton) {
        wipeTablesButton.addEventListener('click', function(e) {
            e.preventDefault();

            const confirmed = confirm(
                '⚠️ WARNING: This will DELETE ALL DATA from the following tables:\n\n' +
                '• Waybill Items\n' +
                '• Quotations\n' +
                '• Invoices\n' +
                '• Waybills\n' +
                '• Deliveries\n' +
                '• Customers\n\n' +
                'This action:\n' +
                '• CANNOT be undone\n' +
                '• Will permanently delete all data\n' +
                '• Settings and reference data will be preserved\n\n' +
                'Are you absolutely sure you want to continue?\n\n' +
                'Click OK to wipe all tables or Cancel to abort.'
            );

            if (confirmed) {
                // Double confirmation for safety
                const doubleConfirmed = confirm(
                    'FINAL CONFIRMATION:\n\n' +
                    'You are about to PERMANENTLY DELETE all plugin data.\n\n' +
                    'This is your last chance to cancel.\n\n' +
                    'Click OK to proceed with wiping all tables.'
                );

                if (doubleConfirmed) {
                    // Show loading state
                    wipeTablesButton.disabled = true;
                    wipeTablesButton.textContent = 'Wiping Tables...';

                    // Submit the form programmatically
                    wipeTablesForm.submit();
                }
            }
        });
    }

    // Waybill import form confirmation
    const importForm = document.getElementById('import-form');
    const importButton = document.getElementById('import-button');

    if (importForm && importButton) {
        importForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const confirmed = confirm(
                'Are you sure you want to import waybills from Excel?\n\n' +
                'This action:\n' +
                '• Will create drivers, customers, deliveries, and waybills\n' +
                '• Will skip duplicate waybill numbers\n' +
                '• May take several minutes depending on file size\n\n' +
                'Click OK to continue or Cancel to abort.'
            );

            if (confirmed) {
                // Show loading state
                importButton.disabled = true;
                importButton.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Importing...';

                // Submit the form
                this.submit();
            }
        });
    }
});
</script>