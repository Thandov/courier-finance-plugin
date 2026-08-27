<?php
/**
 * CLI harness for Setup Seed (dev only, not shipped).
 *
 *   php .cursor/seed-cli.php cache    # pull every sheet tab to .cursor/sheet-cache
 *   php .cursor/seed-cli.php run      # run handle_setup_seed_from_google_sheet()
 *   php .cursor/seed-cli.php verify   # re-run ownership verification only
 */

$WP_ROOT = '/Applications/MAMP/htdocs/Wordpress/08600';
define('WP_USE_THEMES', false);
define('COURIER_SEED_SIMULATION', true);
$_SERVER['HTTP_HOST'] = '08600.local';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=08600-settings';
$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin.php';
require_once $WP_ROOT . '/wp-load.php';

global $wpdb;

$CACHE_DIR = __DIR__ . '/sheet-cache';
if (!is_dir($CACHE_DIR)) {
    mkdir($CACHE_DIR, 0755, true);
}

$cmd = $argv[1] ?? 'run';

/** Load settings.php (defines run_google_sheet_seed + handle_setup_seed_from_google_sheet). */
function seed_cli_load_settings(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ob_start();
    require_once dirname(__DIR__) . '/includes/admin-pages/settings.php';
    ob_end_clean();
}

function seed_cli_cache_path(string $tab): string
{
    return __DIR__ . '/sheet-cache/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $tab) . '.json';
}

function seed_cli_fetch(string $tab, string $range): array
{
    $path = seed_cli_cache_path($tab);
    if (getenv('SEED_CLI_CACHE') === '1' && file_exists($path)) {
        return json_decode((string) file_get_contents($path), true) ?: [];
    }
    try {
        $rows = Courier_Google_Sheets::get_values('', $range, $tab);
    } catch (Throwable $e) {
        $rows = [];
    }
    file_put_contents($path, json_encode($rows));
    return is_array($rows) ? $rows : [];
}

if ($cmd === 'cache') {
    foreach ([
        'kit_waybills' => 'A1:AR5000',
        'kit_customers' => 'A1:Z6000',
        'kit_company_customers' => 'A1:Z6000',
        'kit_drivers' => 'A1:Z5000',
        'kit_deliveries' => 'A1:K200',
        'Waybills' => 'A1:BZ5000',
    ] as $tab => $range) {
        $rows = seed_cli_fetch($tab, $range);
        printf("%-24s %d row(s)\n", $tab, count($rows));
    }
    exit(0);
}

if ($cmd === 'verify') {
    seed_cli_load_settings();
    $rows = json_decode((string) @file_get_contents(seed_cli_cache_path('kit_waybills')), true) ?: [];
    $customers_rows = json_decode((string) @file_get_contents(seed_cli_cache_path('kit_customers')), true) ?: [];
    $resolution = kit_seed_resolve_seed_waybill_rows($rows);
    $rows = $resolution['rows'];
    $lookup = kit_seed_build_waybills_source_lookup();
    $v = kit_seed_run_ownership_verification($rows, $customers_rows, $lookup);
    printf("ok=%s mismatches=%d charges=%d\n", $v['ok'] ? 'YES' : 'NO', $v['mismatch_count'], (int) ($v['charges']['mismatch_count'] ?? 0));
    exit($v['ok'] ? 0 : 1);
}

if ($cmd === 'stages') {
    seed_cli_load_settings();
    $watch = ['5769', '5807', '5841', '5845', '5851', '5863', '4961', '4975'];
    $snap = function (string $stage) use ($wpdb, $watch) {
        $in = "'" . implode("','", $watch) . "'";
        $rows = $wpdb->get_results(
            "SELECT w.waybill_no, w.customer_id, w.company_id, co.company_name,
                    TRIM(CONCAT(COALESCE(c.name,''),' ',COALESCE(c.surname,''))) AS person
             FROM {$wpdb->prefix}kit_waybills w
             LEFT JOIN {$wpdb->prefix}kit_company_customers co ON co.company_id = w.company_id
             LEFT JOIN {$wpdb->prefix}kit_customers c ON c.cust_id = w.customer_id
             WHERE w.waybill_no IN ({$in}) ORDER BY w.waybill_no",
            ARRAY_A
        ) ?: [];
        echo "\n### {$stage}\n";
        foreach ($rows as $r) {
            printf(
                "  WB %-6s cust=%-6s comp=%-6s %s\n",
                $r['waybill_no'],
                $r['customer_id'],
                $r['company_id'],
                trim(($r['company_name'] ?? '') . ' ' . ($r['person'] ?? ''))
            );
        }
        printf("  companies=%d customers=%d\n",
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kit_company_customers"),
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kit_customers"));
    };

    $range = defined('COURIER_GOOGLE_SEED_RANGE') ? COURIER_GOOGLE_SEED_RANGE : 'A1:AR5000';
    $sheet_name = defined('COURIER_GOOGLE_SEED_SHEET_NAME') ? COURIER_GOOGLE_SEED_SHEET_NAME : 'kit_waybills';
    $rows = Courier_Google_Sheets::get_values('', $range, $sheet_name);
    $rows = kit_seed_resolve_seed_waybill_rows($rows)['rows'];
    $customers_rows = Courier_Google_Sheets::get_values('', 'A1:Z5000', 'kit_customers');
    $drivers_rows = Courier_Google_Sheets::get_values('', 'A1:Z5000', 'kit_drivers');
    $deliveries_rows = KIT_Delivery_Seeder::trim_sheet_rows(Courier_Google_Sheets::get_values('', 'A1:K200', 'kit_deliveries'));

    $snap('before anything');
    kit_seed_customers_from_sheet_rows($customers_rows);
    kit_seed_drivers_from_sheet_rows($drivers_rows);
    KIT_Delivery_Seeder::seed_from_sheet_rows($deliveries_rows, true);
    $snap('after entity tabs');

    $customer_id_map = kit_build_customer_sheet_id_map($customers_rows);
    $lookup = kit_seed_build_waybills_source_lookup();
    $result = run_google_sheet_seed($rows, false, $customer_id_map, $deliveries_rows, $lookup, []);
    echo "\nrun_google_sheet_seed success=" . (!empty($result['success']) ? 'yes' : 'no') . "\n";
    $snap('after run_google_sheet_seed');

    kit_seed_merge_duplicate_customers();
    $snap('after merge_duplicate_customers');
    kit_seed_prune_blank_customer_stubs();
    $snap('after prune_blank_customer_stubs');
    kit_seed_prune_company_mirror_person_stubs();
    $snap('after prune_company_mirror_person_stubs');
    KIT_Company_Customers::backfill_waybill_company_ids();
    $snap('after backfill_waybill_company_ids');

    $v = kit_seed_run_ownership_verification($rows, $customers_rows, $lookup);
    printf("\nverification ok=%s mismatches=%d\n", $v['ok'] ? 'YES' : 'NO', $v['mismatch_count']);
    exit($v['ok'] ? 0 : 1);
}

if ($cmd === 'run') {
    seed_cli_load_settings();
    $t0 = microtime(true);
    $result = handle_setup_seed_from_google_sheet();
    printf("\n=== SETUP SEED %s (%.1fs) ===\n", !empty($result['success']) ? 'SUCCESS' : 'FAILED', microtime(true) - $t0);
    echo ($result['message'] ?? '') . "\n\n";

    $v = $result['stats']['verification'] ?? null;
    if (is_array($v)) {
        echo "verification.ok = " . (!empty($v['ok']) ? 'YES' : 'NO') . "  mismatches=" . (int) ($v['mismatch_count'] ?? 0) . "\n";
        file_put_contents(__DIR__ . '/seed-cli-last-verification.json', json_encode($v, JSON_PRETTY_PRINT));
    }
    $errs = $result['stats']['errors'] ?? [];
    if (!empty($errs)) {
        echo "\nfirst seed errors (" . count($errs) . " total):\n";
        foreach (array_slice($errs, 0, 15) as $e) {
            echo "  - $e\n";
        }
    }
    exit(!empty($result['success']) ? 0 : 1);
}

fwrite(STDERR, "unknown command: {$cmd}\n");
exit(2);
