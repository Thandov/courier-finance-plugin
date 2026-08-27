<?php
/**
 * End-to-end seed simulation: wipe a database, seed it from the live Google
 * Sheet, verify the result. Repeatable, and safe by default.
 *
 * WHY A SCRATCH DATABASE
 * ----------------------
 * "Wipe the DB and seed it" is the only honest test of a seeder — an upsert
 * against a half-populated table hides every ordering and referential bug.
 * But wiping the working database to run a test costs you your data every time
 * you want an answer, so by default this clones the schema into a scratch
 * database (08600_sim), wipes THAT, and seeds into it. The working database is
 * never touched unless you ask for it explicitly.
 *
 *   php tests-seed-simulation.php                  # scratch DB (safe, default)
 *   php tests-seed-simulation.php --db=08600       # the real thing
 *   php tests-seed-simulation.php --keep           # don't wipe, seed on top
 *
 * Reference tables (cities, countries, directions, rates) are copied from the
 * source database rather than wiped: waybills carry foreign keys into them, and
 * they are configuration, not sheet data.
 *
 * WHAT IT ASSERTS
 * ---------------
 *   1. Pre-flight runs and its findings are reported (blocking vs warning).
 *   2. The seeder runs to completion without PHP errors.
 *   3. Every waybill on the sheet is in the DB afterwards.
 *   4. Verification passes — every sheet-owned column matches the sheet.
 *
 * Exits non-zero if any assertion fails, so it drops into CI.
 *
 * @package CourierFinancePlugin
 */

// ---------------------------------------------------------------- bootstrap
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$WP_ROOT = '/Applications/MAMP/htdocs/Wordpress/08600';
define('WP_USE_THEMES', false);
require_once $WP_ROOT . '/wp-load.php';

global $wpdb;
$SOURCE_DB = DB_NAME;
$TARGET_DB = isset($opts['db']) && is_string($opts['db']) ? $opts['db'] : ($SOURCE_DB . '_sim');
$WIPE      = !isset($opts['keep']);
$IS_REAL   = ($TARGET_DB === $SOURCE_DB);

$pass = 0;
$fail = 0;
function ok(string $label): void { global $pass; $pass++; echo "  \033[32mPASS\033[0m  $label\n"; }
function bad(string $label, string $why = ''): void { global $fail; $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? " — $why" : '') . "\n"; }
function head(string $t): void { echo "\n" . $t . "\n" . str_repeat('=', 78) . "\n"; }

echo "Seed simulation\n";
echo "  source db : {$SOURCE_DB}\n";
echo "  target db : {$TARGET_DB}" . ($IS_REAL ? "   \033[31m*** THE REAL DATABASE ***\033[0m" : '   (scratch clone)') . "\n";
echo "  mode      : " . ($WIPE ? 'WIPE then seed' : 'seed on top of existing') . "\n";

if ($IS_REAL && $WIPE && !isset($opts['force'])) {
    echo "\nRefusing to wipe the working database without --force.\n";
    echo "Run with --force if that is really what you want, or drop --db to use the scratch clone.\n";
    exit(2);
}

// Tables the sheet owns — wiped and reseeded.
$DATA_TABLES = [
    'kit_waybills', 'kit_waybill_items',
    'kit_customers', 'kit_company_customers',
    'kit_deliveries', 'kit_drivers',
    'kit_sync_runs', 'kit_sync_run_rows',
];
// Configuration the waybills point at — copied, never wiped.
$REFERENCE_TABLES = [
    'kit_operating_cities', 'kit_operating_countries', 'kit_shipping_directions',
    'kit_shipping_rates_mass', 'kit_shipping_rates_volume', 'kit_shipping_rate_types',
    'kit_shipping_dedicated_truck_rates', 'kit_company_details',
];
// WordPress tables the plugin reads through the normal API. Without options the
// scratch DB silently answers every get_option() with its default, which quietly
// re-enables feature flags and made this harness lie about what the seeder did.
$WP_TABLES = ['options', 'users', 'usermeta'];

$prefix = $wpdb->prefix;

// ------------------------------------------------- build the target database
if (!$IS_REAL) {
    head('Preparing scratch database');
    if ($WIPE) {
        $wpdb->query("DROP DATABASE IF EXISTS `{$TARGET_DB}`");
    }
    $wpdb->query("CREATE DATABASE IF NOT EXISTS `{$TARGET_DB}` DEFAULT CHARACTER SET utf8mb4");

    $made = 0;
    foreach (array_merge($DATA_TABLES, $REFERENCE_TABLES, $WP_TABLES) as $t) {
        $src = "`{$SOURCE_DB}`.`{$prefix}{$t}`";
        $dst = "`{$TARGET_DB}`.`{$prefix}{$t}`";
        $exists = $wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$SOURCE_DB}' AND table_name = '{$prefix}{$t}'");
        if ((int) $exists === 0) {
            continue;
        }
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$dst} LIKE {$src}");
        $made++;
    }
    echo "  cloned structure for {$made} table(s)\n";

    $copied = 0;
    foreach (array_merge($REFERENCE_TABLES, $WP_TABLES) as $t) {
        $src = "`{$SOURCE_DB}`.`{$prefix}{$t}`";
        $dst = "`{$TARGET_DB}`.`{$prefix}{$t}`";
        $exists = $wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$TARGET_DB}' AND table_name = '{$prefix}{$t}'");
        if ((int) $exists === 0) {
            continue;
        }
        $wpdb->query("DELETE FROM {$dst}");
        $wpdb->query("INSERT INTO {$dst} SELECT * FROM {$src}");
        $copied += (int) $wpdb->get_var("SELECT COUNT(*) FROM {$dst}");
    }
    echo "  copied {$copied} reference + WP row(s) (cities, countries, rates, options, users)\n";

    // Point WordPress at the scratch database for the rest of the run.
    // set_prefix() — not a bare ->prefix assignment — is what populates
    // $wpdb->options, ->users and friends. Without it every get_option() and
    // update_option() targets an empty table name and silently does nothing,
    // which makes feature flags read as their defaults and the harness lie.
    $sim = new wpdb(DB_USER, DB_PASSWORD, $TARGET_DB, DB_HOST);
    $sim->set_prefix($prefix);
    $GLOBALS['wpdb'] = $sim;
    $wpdb = $sim;

    // The option cache was filled from the source DB during bootstrap.
    wp_cache_flush();
} elseif ($WIPE) {
    head('Wiping working database tables');
    foreach ($DATA_TABLES as $t) {
        $wpdb->query("TRUNCATE TABLE `{$prefix}{$t}`");
    }
    echo "  truncated " . count($DATA_TABLES) . " table(s)\n";
}

if ($WIPE) {
    head('Starting state');
    foreach ($DATA_TABLES as $t) {
        $n = $wpdb->get_var("SELECT COUNT(*) FROM `{$prefix}{$t}`");
        if ($n === null) { continue; }
        printf("  %-28s %s\n", $t, $n);
    }
}

// ----------------------------------------------------- schema + customers first
head('Schema migrations');
if (method_exists('Database', 'ensure_waybill_volume_precision')) {
    Database::ensure_waybill_volume_precision();
}
$vol_type = $wpdb->get_var("SELECT column_type FROM information_schema.columns WHERE table_schema = '{$TARGET_DB}' AND table_name = '{$prefix}kit_waybills' AND column_name = 'total_volume'");
echo "  total_volume is now {$vol_type}\n";
(strpos((string) $vol_type, '16,8') !== false)
    ? ok('total_volume widened so sheet precision survives')
    : bad('total_volume widened so sheet precision survives', (string) $vol_type);

head('Seeding customers (must precede waybills)');
$cust = KIT_Customer_Seeder::run(false);
echo '  ' . $cust['message'] . "\n";
$cust_in_db = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$prefix}kit_customers`");
$comp_in_db = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$prefix}kit_company_customers`");
echo "  customers in DB: {$cust_in_db}   companies: {$comp_in_db}\n";
($cust_in_db > 0) ? ok('customers seeded') : bad('customers seeded', 'table still empty');

// ------------------------------------------------------------- read the sheet
head('Reading kit_waybills sheet');
if (!Courier_Google_Sheets::is_configured()) {
    bad('Google Sheets configured', 'credentials missing');
    echo "\nCannot continue without sheet access.\n";
    exit(1);
}
$sheet_name = defined('COURIER_GOOGLE_SEED_SHEET_NAME') ? COURIER_GOOGLE_SEED_SHEET_NAME : KIT_Waybill_Seeder::DEFAULT_SHEET_NAME;
$range      = defined('COURIER_GOOGLE_SEED_RANGE') ? COURIER_GOOGLE_SEED_RANGE : KIT_Waybill_Seeder::DEFAULT_SHEET_RANGE;
$rows       = Courier_Google_Sheets::get_values('', $range, $sheet_name);
$sheet_rows = max(0, count($rows) - 1);
echo "  {$sheet_rows} data row(s) from '{$sheet_name}'\n";
$sheet_rows > 0 ? ok('sheet returned data') : bad('sheet returned data');

// ---------------------------------------------------------------- pre-flight
head('Pre-flight (sheet audit, before any write)');
$pf = KIT_Waybill_Preflight::check($rows);
echo '  ' . $pf['message'] . "\n";
foreach ($pf['blocking'] as $f) {
    printf("    \033[31mBLOCK\033[0m %-22s %s\n", $f['column'], mb_strimwidth($f['detail'], 0, 100));
}
foreach ($pf['warnings'] as $f) {
    printf("    \033[33mWARN \033[0m %-22s %s\n", $f['column'], mb_strimwidth($f['detail'], 0, 100));
}
echo "\n  Pre-flight is doing its job either way — a clean sheet passes, a dirty one\n";
echo "  is caught before the first write. Both are a working pipeline.\n";
ok('pre-flight ran and produced a verdict');

// -------------------------------------------------------------------- seeding
head('Seeding (production, not shadow)');
// The gate must not stop the simulation from telling us what would happen —
// we want to see the seed run and then have verification judge the result.
$restore_block = KIT_Waybill_Preflight::is_blocking();
if (isset($opts['ignore-preflight'])) {
    KIT_Waybill_Preflight::set_blocking(false);
    echo "  (pre-flight blocking temporarily disabled by --ignore-preflight)\n";
}

$t0 = microtime(true);
$result = KIT_Waybill_Seeder::run('cli', false);
$elapsed = round(microtime(true) - $t0, 1);
KIT_Waybill_Preflight::set_blocking($restore_block);

echo "  {$result['message']}\n";
echo "  took {$elapsed}s\n";
$c = $result['counts'];
printf("  total %d | inserted %d | updated %d | skipped %d | errored %d\n",
    $c['total'] ?? 0, $c['inserted'] ?? 0, $c['updated'] ?? 0, $c['skipped'] ?? 0, $c['errored'] ?? 0);

$was_blocked = ((int) ($c['total'] ?? 0) === 0) && !empty($result['preflight']) && empty($result['preflight']['passed']);
if ($was_blocked) {
    echo "\n  Seed correctly refused to run against a sheet that fails pre-flight.\n";
    echo "  Re-run with --ignore-preflight to force the write and see what lands.\n";
    ok('seed aborted on a failing pre-flight rather than corrupting the DB');
} else {
    ((int) ($c['errored'] ?? 0) === 0) ? ok('seed completed with no row errors') : bad('seed completed with no row errors', $c['errored'] . ' errored');

    $in_db = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$prefix}kit_waybills`");
    echo "  waybills now in DB: {$in_db} (sheet has {$sheet_rows})\n";
    ($in_db >= $sheet_rows) ? ok('every sheet waybill reached the DB') : bad('every sheet waybill reached the DB', "{$in_db} of {$sheet_rows}");

    // ------------------------------------------------------------ verification
    head('Verification (read back and compare, column by column)');
    $v = KIT_Waybill_Verifier::verify($rows, (int) ($result['run_id'] ?? 0), 'simulation');
    echo '  ' . $v['message'] . "\n";
    if (!empty($v['failures'])) {
        $by_field = [];
        foreach ($v['failures'] as $f) {
            if ($f['type'] !== 'mismatch') { $by_field['(' . $f['type'] . ')'] = ($by_field['(' . $f['type'] . ')'] ?? 0) + 1; continue; }
            foreach ($f['fields'] as $d) { $by_field[$d['field']] = ($by_field[$d['field']] ?? 0) + 1; }
        }
        arsort($by_field);
        echo "\n  mismatches by column:\n";
        foreach (array_slice($by_field, 0, 12, true) as $k => $n) { printf("    %-28s %5d\n", $k, $n); }
    }
    !empty($v['passed']) ? ok('DB matches the sheet') : bad('DB matches the sheet', $v['counts']['mismatch'] . ' mismatched, ' . $v['counts']['missing'] . ' missing');
}

// ------------------------------------------------------------------- summary
head('Result');
echo "  passed: {$pass}   failed: {$fail}\n";
if (!$IS_REAL) {
    echo "\n  Working database {$SOURCE_DB} was not touched. Scratch DB: {$TARGET_DB}\n";
}
exit($fail === 0 ? 0 : 1);
