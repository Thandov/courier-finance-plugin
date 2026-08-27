<?php
/**
 * Charge-basis seed/verification regression tests (no WordPress).
 *
 * php tests-charge-basis-seed.php
 */
define('ABSPATH', '/fake/');

require_once __DIR__ . '/includes/sync/kit-charge-basis.php';

$pass = 0;
$fail = 0;
function check(string $label, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label — got " . var_export($actual, true) . ", want " . var_export($expected, true) . "\n";
    }
}

echo "\n1. Empty basis → max(mass, volume)\n";
check('max volume', kit_seed_total_from_charge_basis(0.0, 178.97, ''), 178.97);
check('max mass', kit_seed_total_from_charge_basis(100.0, 50.0, ''), 100.0);

echo "\n2. Explicit MASS with mass=0 bills 0 (before sanitize)\n";
check('mass zero', kit_seed_total_from_charge_basis(0.0, 178.97, 'mass'), 0.0);

echo "\n3. Inverted basis sanitize clears to empty → max freight\n";
$s = kit_seed_sanitize_inverted_charge_basis('MASS', 0.0, 178.97);
check('sanitize mass→empty', $s, '');
check('freight after sanitize', kit_seed_total_from_charge_basis(0.0, 178.97, $s), 178.97);

$s2 = kit_seed_sanitize_inverted_charge_basis('volume', 28000.0, 0.0);
check('sanitize volume→empty', $s2, '');
check('freight after vol sanitize', kit_seed_total_from_charge_basis(28000.0, 0.0, $s2), 28000.0);

echo "\n4. Valid basis left alone\n";
check('keep mass', kit_seed_sanitize_inverted_charge_basis('mass', 100.0, 50.0), 'mass');
check('keep volume', kit_seed_sanitize_inverted_charge_basis('VOLUME', 10.0, 200.0), 'volume');
check('both zero mass stays', kit_seed_sanitize_inverted_charge_basis('mass', 0.0, 0.0), 'mass');

echo "\n5. Normalize never invents mass for blank\n";
check('blank', kit_seed_normalize_charge_basis(''), '');
check('auto', kit_seed_normalize_charge_basis('auto'), '');

echo "\n";
echo $fail === 0 ? "ALL PASSED ($pass)\n" : "FAILED: $fail / " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
