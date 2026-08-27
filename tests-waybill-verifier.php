<?php
/**
 * Tests for KIT_Waybill_Verifier — the post-seed sheet↔DB verification gate.
 *
 * Run from the plugin root, no WordPress needed:
 *     php tests-waybill-verifier.php
 *
 * Stubs the WP surface (get_option / $wpdb / current_time) so the comparison
 * rules can be asserted against realistic sheet rows and DB rows. Exits non-zero
 * on failure, so it drops straight into CI.
 *
 * Covers the cases that actually make the DB look different from the sheet:
 * VARCHAR truncation, ENUM coercion to '', currency formatting mangled during
 * parsing, missing rows, duplicate waybill numbers, and the false positives the
 * verifier must NOT report (equivalent number formats, seeder insert defaults,
 * WP-owned columns).
 */
define('ABSPATH', '/fake/');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['__options'] = [];
function get_option($k, $d = false) { return $GLOBALS['__options'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['__options'][$k] = $v; return true; }
function current_time($t) { return date('Y-m-d H:i:s'); }
function wp_json_encode($v) { return json_encode($v); }
function get_current_user_id() { return 1; }
function __($s, $d = null) { return $s; }

class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = [];
    public function get_results($sql, $mode = null) { return $this->rows; }
}
$wpdb = new FakeWpdb();

require_once __DIR__ . '/includes/sync/kit-seed-number-parse.php';
require_once __DIR__ . '/includes/sync/kit-charge-basis.php';
require_once __DIR__ . '/includes/sync/class-kit-waybill-seeder.php';
require_once __DIR__ . '/includes/sync/class-kit-waybill-verifier.php';

$pass = 0; $fail = 0;
function check(string $label, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label — got " . var_export($actual, true) . ", want " . var_export($expected, true) . "\n"; }
}

$header = ['waybill_no','description','direction_id','city_id','delivery_id','customer_id','approval',
           'product_invoice_amount','item_length','total_mass_kg','charge_basis','vat','warehouse','miscellaneous'];

function db_row(array $over = []): array {
    return array_merge([
        'id' => 1, 'waybill_no' => 'WB001', 'description' => 'Pallet of tiles',
        'direction_id' => '2', 'city_id' => '5', 'delivery_id' => '77', 'customer_id' => '8601',
        'approval' => 'approved', 'approval_userid' => null,
        'waybill_items_total' => '1200.50', 'product_invoice_amount' => '0.00',
        'sad500_amount' => '0.00', 'sadc_amount' => '0.00',
        'item_length' => '1.20', 'item_width' => '0.00', 'item_height' => '0.00',
        'total_mass_kg' => '340.00', 'total_volume' => '0.00',
        'mass_charge' => '0.00', 'volume_charge' => '0.00',
        'charge_basis' => 'mass', 'vat_include' => '1',
        'include_sad500' => '0', 'include_sadc' => '0', 'warehouse' => '0', 'miscellaneous' => '',
    ], $over);
}

function sheet(array $rows): array { global $header; return array_merge([$header], $rows); }

// waybill, desc, dir, city, del, cust, appr, amount, len, mass, basis, vat, warehouse, misc
$good_row = ['WB001','Pallet of tiles','2','5','77','8601','approved','1200.50','1.2','340','mass','TRUE','','' ];

echo "\n1. Clean match\n";
$wpdb->rows = [db_row()];
$r = KIT_Waybill_Verifier::verify(sheet([$good_row]));
check('passes', $r['passed'], true);
check('1 verified', $r['counts']['verified'], 1);
check('0 mismatch', $r['counts']['mismatch'], 0);

echo "\n2. Equivalent numeric formatting is NOT a mismatch (1200.5 == 1200.50)\n";
$wpdb->rows = [db_row(['waybill_items_total' => '1200.5'])];
$r = KIT_Waybill_Verifier::verify(sheet([$good_row]));
check('passes', $r['passed'], true);

echo "\n2b. Legacy rows mangled by the OLD parser are caught\n";
// This is the state the live DB is in right now: rows written before the
// to_decimal fix, when "R 1 200,50" was stripped to 120050. The verifier parses
// the cell correctly, so the stored value no longer matches and the row is
// flagged for re-seeding.
$row = $good_row; $row[7] = 'R 1 200,50';
$wpdb->rows = [db_row(['waybill_items_total' => '120050.00'])];
$r = KIT_Waybill_Verifier::verify(sheet([$row]));
check('fails', $r['passed'], false);
check('field', $r['failures'][0]['fields'][0]['field'], 'waybill_items_total');
check('expected is the real amount', $r['failures'][0]['fields'][0]['expected'], 1200.5);
check('shows the literal cell', $r['failures'][0]['fields'][0]['sheet_raw'], 'R 1 200,50');
check('shows the wrong stored value', $r['failures'][0]['fields'][0]['db'], '120050.00');

echo "\n2c. Independent parser handles the formats Sheets emits\n";
$ref = new ReflectionMethod('KIT_Waybill_Verifier', 'parse_number_independently');
$p = function ($s) use ($ref) { return $ref->invoke(null, $s); };
check('R 1 200,50', $p('R 1 200,50'), 1200.5);
check('R1,200.50', $p('R1,200.50'), 1200.5);
check('1 200.50', $p('1 200.50'), 1200.5);
check('1.200,50', $p('1.200,50'), 1200.5);
check('1.200.000', $p('1.200.000'), 1200000.0);
check('-R 45,00', $p('-R 45,00'), -45.0);
check('plain 340', $p('340'), 340.0);
check('1,200 (thousands)', $p('1,200'), 1200.0);
check('empty -> null', $p(''), null);
check('TRUE -> null', $p('TRUE'), null);
check('N/A -> null', $p('N/A'), null);

echo "\n3. Silent VARCHAR truncation IS caught (charge_basis VARCHAR(20))\n";
$row = $good_row; $row[10] = 'mass-and-volume-combined-basis';
$wpdb->rows = [db_row(['charge_basis' => 'mass-and-volume-comb'])];
$r = KIT_Waybill_Verifier::verify(sheet([$row]));
check('fails', $r['passed'], false);
check('1 mismatch', $r['counts']['mismatch'], 1);
check('field is charge_basis', $r['failures'][0]['fields'][0]['field'], 'charge_basis');
check('shows literal sheet cell', $r['failures'][0]['fields'][0]['sheet_raw'], 'mass-and-volume-combined-basis');
check('shows db value', $r['failures'][0]['fields'][0]['db'], 'mass-and-volume-comb');

echo "\n4. approval is WP-owned — sheet differences are ignored\n";
// The sheet's approval column holds the row's own waybill number on 82-87% of
// rows and "4618" is not a member of the ENUM, so WordPress owns approval and
// the seeder must not write it. A difference there is correct, not drift.
check('approval not sheet-owned', in_array('approval', KIT_Waybill_Seeder::sheet_owned_fields(), true), false);
check('approval_userid not sheet-owned', in_array('approval_userid', KIT_Waybill_Seeder::sheet_owned_fields(), true), false);
$row = $good_row; $row[6] = 'signed-off';
$wpdb->rows = [db_row(['approval' => 'pending'])];
$r = KIT_Waybill_Verifier::verify(sheet([$row]));
check('sheet/DB approval mismatch does not fail the run', $r['passed'], true);

echo "\n4b. A waybill number buried in description text is recovered\n";
// Two live rows carry the description in the waybill_no cell. waybill_no is
// VARCHAR(20), so an oversized value cannot be inserted and the row vanishes.
check('WB:- 5735 recovered',
    KIT_Waybill_Seeder::normalize_waybill_no('Tyres And Tubes + Rust Inhibitor WB:- 5735 Supplier:- Absolute Aircraft Parts'), '5735');
check('WB:- 5737 recovered',
    KIT_Waybill_Seeder::normalize_waybill_no('Assorted Cosmetics And Lotions WB:- 5737 Supplier:- Healing Earth Date:- 14/07/2026'), '5737');
check('a normal number is untouched', KIT_Waybill_Seeder::normalize_waybill_no('4618'), '4618');
check('empty stays empty', KIT_Waybill_Seeder::normalize_waybill_no(''), '');

echo "\n4c. Impossible numbers are rejected, not clamped\n";
// One live row carries item_height 9.19e14. MySQL would clamp it to the column
// maximum, which reads as a real measurement; 0 is visibly absent.
$hdr4 = ['waybill_no', 'item_height', 'total_mass_kg'];
$col4 = KIT_Waybill_Seeder::header_to_col_map(KIT_Waybill_Seeder::normalize_header($hdr4));
$m4 = KIT_Waybill_Seeder::map_sheet_row_to_db_payload(['WB800', '919000000000130', '45'], $col4)['update_payload'];
check('out-of-range height -> 0', $m4['item_height'], 0);
check('a sane value on the same row survives', $m4['total_mass_kg'], 45.0);

echo "\n5. Waybill on sheet, absent from DB\n";
$wpdb->rows = [];
$r = KIT_Waybill_Verifier::verify(sheet([$good_row]));
check('fails', $r['passed'], false);
check('1 missing', $r['counts']['missing'], 1);
check('type', $r['failures'][0]['type'], 'missing');

echo "\n6. insert-default direction_id=1 is NOT a false mismatch when sheet is blank\n";
$row = $good_row; $row[2] = '';
$wpdb->rows = [db_row(['direction_id' => '1'])];
$r = KIT_Waybill_Verifier::verify(sheet([$row]));
check('passes', $r['passed'], true);

echo "\n6b. ...but a real direction_id difference still fails\n";
$row = $good_row; $row[2] = '3';
$wpdb->rows = [db_row(['direction_id' => '1'])];
$r = KIT_Waybill_Verifier::verify(sheet([$row]));
check('fails', $r['passed'], false);

echo "\n7. Duplicate waybill_no on the sheet\n";
$wpdb->rows = [db_row()];
$r = KIT_Waybill_Verifier::verify(sheet([$good_row, $good_row]));
check('fails', $r['passed'], false);
check('1 sheet_dupe', $r['counts']['sheet_dupe'], 1);

echo "\n8. Orphan: in DB, not on sheet — reported, does not fail by default\n";
$wpdb->rows = [db_row(), db_row(['id' => 2, 'waybill_no' => 'WB-MANUAL-99'])];
$r = KIT_Waybill_Verifier::verify(sheet([$good_row]));
check('still passes', $r['passed'], true);
check('1 orphan', $r['counts']['orphan'], 1);
check('orphan listed', $r['orphans'][0], 'WB-MANUAL-99');

echo "\n8b. ...unless strict orphans is on\n";
KIT_Waybill_Verifier::set_strict_orphans(true);
$r = KIT_Waybill_Verifier::verify(sheet([$good_row]));
check('now fails', $r['passed'], false);
KIT_Waybill_Verifier::set_strict_orphans(false);

echo "\n9. Rows with no waybill_no are skipped, not failed\n";
$blank = array_fill(0, count($header), '');
$wpdb->rows = [db_row()];
$r = KIT_Waybill_Verifier::verify(sheet([$good_row, $blank]));
check('passes', $r['passed'], true);
check('1 skipped', $r['counts']['skipped'], 1);

echo "\n10. WP-owned columns are not compared (status/tracking differ freely)\n";
$wpdb->rows = [db_row(['status' => 'delivered', 'tracking_number' => 'TRK-ZZZ'])];
$r = KIT_Waybill_Verifier::verify(sheet([$good_row]));
check('passes', $r['passed'], true);

echo "\n11. Duplicate waybill_no in the DB\n";
$wpdb->rows = [db_row(), db_row(['id' => 9])];
$r = KIT_Waybill_Verifier::verify(sheet([$good_row]));
check('fails', $r['passed'], false);
check('1 db_dupe', $r['counts']['db_dupe'], 1);

echo "\n12. Canonical parser (kit_parse_sheet_number) reads formatted cells correctly\n";
$corpus = [
    ['R 1 200,50',   1200.5],
    ['R1,200.50',    1200.5],
    ['1 200.50',     1200.5],
    ['1.200,50',     1200.5],
    ['1.200.000',    1200000.0],
    ['1,200',        1200.0],
    ['1,200,000.75', 1200000.75],
    ['-R 45,00',     -45.0],
    ['R -45,00',     -45.0],
    ['(45.00)',      -45.0],
    ['340',          340.0],
    ['340.5',        340.5],
    ['0',            0.0],
    ["R\xc2\xa01\xc2\xa0200,50", 1200.5], // non-breaking spaces, as Sheets emits
    ['12-34',        1234.0],             // embedded hyphen is not a minus sign
    ['',             null],
    ['TRUE',         null],
    ['N/A',          null],
    ['-',            null],
];
foreach ($corpus as [$in, $want]) {
    check("kit_parse_sheet_number('" . addcslashes($in, "\0..\37") . "')", kit_parse_sheet_number($in), $want);
}

echo "\n12b. Seeder's to_decimal now uses it (the actual bug fix)\n";
$to_decimal = new ReflectionMethod('KIT_Waybill_Seeder', 'to_decimal');
check('R 1 200,50 -> 1200.5 (was 120050)', $to_decimal->invoke(null, 'R 1 200,50'), 1200.5);
check('R1,200.50 -> 1200.5',               $to_decimal->invoke(null, 'R1,200.50'), 1200.5);
check('plain 340 -> 340.0',                $to_decimal->invoke(null, '340'), 340.0);
check('empty -> 0.0',                      $to_decimal->invoke(null, ''), 0.0);
$to_int = new ReflectionMethod('KIT_Waybill_Seeder', 'to_int');
check('to_int 8,601 -> 8601',              $to_int->invoke(null, '8,601'), 8601);
check('to_int empty -> 0',                 $to_int->invoke(null, ''), 0);

echo "\n13. Writer and verifier parsers agree (drift guard)\n";
// The verifier deliberately keeps its own implementation so it can catch the
// writer's bugs. That is only safe if the two agree on well-formed input —
// otherwise the verifier invents failures. Assert it across the whole corpus.
$indep = new ReflectionMethod('KIT_Waybill_Verifier', 'parse_number_independently');
$divergent = [];
foreach ($corpus as [$in, $_]) {
    $a = kit_parse_sheet_number($in);
    $b = ($in === '' || !preg_match('/\d/', $in)) ? null : $indep->invoke(null, $in);
    if ($a !== $b) { $divergent[] = "$in (writer=" . var_export($a, true) . ", verifier=" . var_export($b, true) . ")"; }
}
check('no divergence across corpus', $divergent, []);

echo "\n14. A correctly-parsed currency cell no longer fails verification\n";
// End-to-end: sheet says "R 1 200,50", seeder now stores 1200.50, verifier agrees.
$row = $good_row; $row[7] = 'R 1 200,50';
$wpdb->rows = [db_row(['waybill_items_total' => '1200.50'])];
$r = KIT_Waybill_Verifier::verify(sheet([$row]));
check('passes', $r['passed'], true);

echo "\n15. Client ruling: sheet product_invoice_amount IS the parcels total\n";
// Sheet product_invoice_amount -> DB waybill_items_total.
// DB product_invoice_amount -> freight total selected by charge_basis.
$hdr = ['waybill_no', 'product_invoice_amount', 'mass_charge', 'volume_charge', 'charge_basis'];
$mapRow = ['WB900', '1000', '1800', '55.44', 'MASS'];
$colmap = KIT_Waybill_Seeder::header_to_col_map(KIT_Waybill_Seeder::normalize_header($hdr));
$mapped = KIT_Waybill_Seeder::map_sheet_row_to_db_payload($mapRow, $colmap)['update_payload'];
check('parcels total -> waybill_items_total', $mapped['waybill_items_total'], 1000.0);
check('MASS basis -> invoice amount = mass_charge', $mapped['product_invoice_amount'], 1800.0);

$mapRow2 = ['WB901', '2000', '40', '55.44', 'VOLUME'];
$mapped2 = KIT_Waybill_Seeder::map_sheet_row_to_db_payload($mapRow2, $colmap)['update_payload'];
check('parcels total unaffected by basis', $mapped2['waybill_items_total'], 2000.0);
check('VOLUME basis -> invoice amount = volume_charge', $mapped2['product_invoice_amount'], 55.44);

echo "\n16. Empty / auto charge_basis → max(mass, volume)\n";
$mapEmpty = ['WB902', '500', '100', '999.50', ''];
$mappedEmpty = KIT_Waybill_Seeder::map_sheet_row_to_db_payload($mapEmpty, $colmap)['update_payload'];
check('empty basis -> higher charge', $mappedEmpty['product_invoice_amount'], 999.5);
check('empty basis stored empty', $mappedEmpty['charge_basis'], '');

$mapAuto = ['WB903', '500', '2000', '50', 'auto'];
$mappedAuto = KIT_Waybill_Seeder::map_sheet_row_to_db_payload($mapAuto, $colmap)['update_payload'];
check('auto basis -> higher charge', $mappedAuto['product_invoice_amount'], 2000.0);

check('helper empty → max', kit_seed_total_from_charge_basis(0.0, 38704.84, ''), 38704.84);
check('helper MASS with zero mass stays 0', kit_seed_total_from_charge_basis(0.0, 38704.84, 'MASS'), 0.0);
check('helper VOLUME with zero vol stays 0', kit_seed_total_from_charge_basis(96000.0, 0.0, 'VOLUME'), 0.0);

echo "\n17. Inverted charge_basis cleared on seed → max freight\n";
$mapInv = ['WB904', '12316.40', '0', '178.97', 'MASS'];
$mappedInv = KIT_Waybill_Seeder::map_sheet_row_to_db_payload($mapInv, $colmap)['update_payload'];
check('inverted MASS → empty basis', $mappedInv['charge_basis'], '');
check('inverted MASS → volume freight', $mappedInv['product_invoice_amount'], 178.97);
check('parcels still from sheet amount', $mappedInv['waybill_items_total'], 12316.4);

$mapInv2 = ['WB905', '3000', '28000', '0', 'VOLUME'];
$mappedInv2 = KIT_Waybill_Seeder::map_sheet_row_to_db_payload($mapInv2, $colmap)['update_payload'];
check('inverted VOLUME → empty basis', $mappedInv2['charge_basis'], '');
check('inverted VOLUME → mass freight', $mappedInv2['product_invoice_amount'], 28000.0);

echo "\n" . str_repeat('-', 50) . "\n";
echo "passed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
