<?php
/**
 * Ownership expected-map must mirror setup-seed (F business vs F person + I company).
 * php tests-seed-ownership-align.php
 */
define('ABSPATH', '/fake/');

require_once __DIR__ . '/includes/sync/kit-business-name.php';
require_once __DIR__ . '/includes/sync/kit-seed-number-parse.php';
require_once __DIR__ . '/includes/sync/kit-charge-basis.php';

if (!function_exists('kit_seed_header_col_map')) {
    function kit_seed_header_col_map(array $header_row): array
    {
        $col = [];
        foreach ($header_row as $i => $h) {
            $key = strtolower(trim((string) $h));
            $key = str_replace(' ', '_', $key);
            if ($key !== '') {
                $col[$key] = $i;
            }
        }
        return $col;
    }
}
if (!function_exists('kit_seed_row_cell')) {
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
}
if (!function_exists('kit_seed_normalize_waybill_no_key')) {
    function kit_seed_normalize_waybill_no_key(string $waybill_no): string
    {
        return strtolower(trim(preg_replace('/[^0-9A-Za-z\-]/', '', $waybill_no) ?? ''));
    }
}

// Minimal KIT_Customers stubs used by verification helpers.
class KIT_Customers
{
    public static function normalize_company_compare_key(string $company_name): string
    {
        $s = strtolower(trim(preg_replace('/[^a-z0-9\s]+/i', ' ', $company_name) ?? ''));
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        foreach ([' limited', ' ltd', ' pty ltd', ' pty'] as $suffix) {
            if (strlen($s) > strlen($suffix) && substr($s, -strlen($suffix)) === $suffix) {
                $s = trim(substr($s, 0, -strlen($suffix)));
            }
        }
        return $s;
    }

    public static function normalize_person_name_key(string $name, string $surname = ''): string
    {
        return trim(strtolower(trim($name) . ' ' . trim($surname)));
    }

    public static function customer_labels_are_similar(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        similar_text($a, $b, $pct);
        return $pct >= 88.0;
    }
}

require_once __DIR__ . '/includes/seed/seed-verification.php';

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

echo "\n1. Token-compatible company labels\n";
check(
    'Dorobo subset',
    kit_seed_verification_labels_equivalent('company', 'Dorobo Safaris', 'Dorobo Tours & Safaris'),
    true
);
check(
    'Sasakwa subset',
    kit_seed_verification_labels_equivalent('company', 'Sasakwa Lodge Grumeti', 'Sasakwa Lodge'),
    true
);
check(
    'unrelated companies',
    kit_seed_verification_labels_equivalent('company', 'Rhino Lodge', 'Gaia Ltd Tim Leach'),
    false
);
check(
    'Consolidate vs Consolidated Hotel',
    kit_seed_verification_labels_equivalent(
        'company',
        'Consolidate tourist & hotels investment limited',
        'Consolidated Tourist Hotel'
    ),
    true
);

check(
    'Jonz vs Jonz Express person',
    kit_seed_verification_labels_equivalent('individual', 'Jonz', 'Jonz Express'),
    true
);
check(
    'unrelated short person names',
    kit_seed_verification_labels_equivalent('individual', 'Jonz', 'Chris Joubert'),
    false
);

echo "\n2. Expected map: F business beats Company I\n";
$header = ['waybill_no', 'customer_id', 'cust_name_ignore', 'company_name'];
$rows = [
    $header,
    ['4647', '100', 'Gaia Ltd Tim Leach', 'Rhino Lodge'],
    ['5165', '200', 'Selous Impala Ltd', 'Tilke Investments'],
    ['4964', '8728', 'Lenet', 'Consolidate Tourist Hotel'],
    ['5077', '300', 'John Smith', 'Sasakwa Lodge'],
];
$expected = kit_seed_build_verification_from_sheet($rows, [], null);
check('Gaia not Rhino', $expected['by_waybill']['4647']['label'], 'Gaia Ltd Tim Leach');
check('Gaia is company', $expected['by_waybill']['4647']['party_type'], 'company');
check('Selous not Tilke', $expected['by_waybill']['5165']['label'], 'Selous Impala Ltd');
check('Lenet+company → company party', $expected['by_waybill']['4964']['party_type'], 'company');
check('Lenet company label', $expected['by_waybill']['4964']['label'], 'Consolidate Tourist Hotel');
check('person+lodge → company', $expected['by_waybill']['5077']['party_type'], 'company');
check('person+lodge label', $expected['by_waybill']['5077']['label'], 'Sasakwa Lodge');

echo "\n2b. Person + explicit Company column wins over customers-sheet affiliation\n";
$bianca_sheet = [
    ['cust_id', 'name', 'surname', 'company_name'],
    ['8739', 'Bianca', 'Thielke', 'Selous Impala Ltd'],
    ['8741', 'Roxanne', 'Crag', ''],
];
$wb_person_company = [
    ['waybill_no', 'customer_id', 'cust_name_ignore', 'company_name'],
    ['5165', '8739', '', ''],
    ['5410', '8741', '', ''],
    ['5283', '8741', '', ''],
];
$source_person_company = [
    'by_waybill' => [
        '5165' => ['customer' => 'Bianca Thielke', 'company' => 'Tilke Investments'],
        '5410' => ['customer' => 'Roxanne Cragg', 'company' => ''],
        '5283' => ['customer' => 'Roxanne Cragg', 'company' => 'The Uniques Tanzania Limited'],
    ],
];
$exp_pc = kit_seed_build_verification_from_sheet($wb_person_company, $bianca_sheet, $source_person_company);
check('5165 Tilke not Selous', $exp_pc['by_waybill']['5165']['label'], 'Tilke Investments');
check('5165 is company', $exp_pc['by_waybill']['5165']['party_type'], 'company');
check('5410 Roxanne stays individual', $exp_pc['by_waybill']['5410']['party_type'], 'individual');
check('5410 Roxanne label', $exp_pc['by_waybill']['5410']['label'], 'Roxanne Cragg');
check('5283 Roxanne+Uniques → company', $exp_pc['by_waybill']['5283']['party_type'], 'company');
check('5283 Uniques label', $exp_pc['by_waybill']['5283']['label'], 'The Uniques Tanzania Limited');

echo "\n3. Blank cust_name_ignore + customer_id resolves via customers sheet\n";
$customer_sheet = [
    ['cust_id', 'name', 'surname', 'company_name'],
    ['8600', 'Alexandra', 'Soine', ''],
    ['8602', '', '', 'Lodge Creations'],
    ['8728', 'Lenet', '', 'Consolidate Tourist Hotel'],
];
$wb_blank_names = [
    ['waybill_no', 'customer_id', 'cust_name_ignore', 'company_name'],
    ['4618', '8600', '', ''],
    ['4602', '8602', '', ''],
    ['4619', '8600', 'Private', ''],
    ['4964', '8728', '', ''],
];
$expected_blank = kit_seed_build_verification_from_sheet($wb_blank_names, $customer_sheet, null);
check('blank name → individual via cust sheet', $expected_blank['by_waybill']['4618']['party_type'], 'individual');
check('blank name individual label', $expected_blank['by_waybill']['4618']['label'], 'Alexandra Soine');
check('blank name → company via cust sheet', $expected_blank['by_waybill']['4602']['party_type'], 'company');
check('blank name company label', $expected_blank['by_waybill']['4602']['label'], 'Lodge Creations');
check('Private+id still resolves named party', $expected_blank['by_waybill']['4619']['party_type'], 'individual');
check('Private+id label', $expected_blank['by_waybill']['4619']['label'], 'Alexandra Soine');
check('mixed person+company affiliation → company', $expected_blank['by_waybill']['4964']['party_type'], 'company');
check('mixed affiliation company label', $expected_blank['by_waybill']['4964']['label'], 'Consolidate Tourist Hotel');

echo "\n4. Source lookup company when kit_waybills customer blank\n";
$wb_source_only = [
    ['waybill_no', 'customer_id', 'cust_name_ignore', 'company_name'],
    ['4999', '0', '', ''],
];
$source_lookup = [
    'by_waybill' => [
        '4999' => ['customer' => '', 'company' => 'Asilia Lodges And Camps'],
    ],
];
$expected_src = kit_seed_build_verification_from_sheet($wb_source_only, [], $source_lookup);
check('source company → company party', $expected_src['by_waybill']['4999']['party_type'], 'company');
check('source company label', $expected_src['by_waybill']['4999']['label'], 'Asilia Lodges And Camps');

echo "\n5. Diff: near-duplicate company counts collapse\n";
$exp = [
    'totals' => ['waybills' => 3, 'individuals' => 0, 'companies' => 2, 'unassigned' => 0],
    'by_waybill' => [
        '4757' => ['waybill_no' => '4757', 'party_type' => 'company', 'sheet_party_id' => 0, 'label' => 'Dorobo Safaris'],
        '4781' => ['waybill_no' => '4781', 'party_type' => 'company', 'sheet_party_id' => 0, 'label' => 'Dorobo Safaris'],
        '5070' => ['waybill_no' => '5070', 'party_type' => 'company', 'sheet_party_id' => 0, 'label' => 'Dorobo Tours & Safaris'],
    ],
    'by_party' => [
        'company:label:dorobo safaris' => [
            'type' => 'company', 'sheet_id' => 0, 'label' => 'Dorobo Safaris',
            'waybill_nos' => ['4757', '4781'], 'count' => 2,
        ],
        'company:label:dorobo tours' => [
            'type' => 'company', 'sheet_id' => 0, 'label' => 'Dorobo Tours & Safaris',
            'waybill_nos' => ['5070'], 'count' => 1,
        ],
    ],
];
$act = [
    'totals' => ['waybills' => 3, 'individuals' => 0, 'companies' => 1, 'unassigned' => 0],
    'by_waybill' => [
        '4757' => ['waybill_no' => '4757', 'party_type' => 'company', 'sheet_party_id' => 55, 'label' => 'Dorobo Tours & Safaris', 'customer_id' => 0, 'company_id' => 55],
        '4781' => ['waybill_no' => '4781', 'party_type' => 'company', 'sheet_party_id' => 55, 'label' => 'Dorobo Tours & Safaris', 'customer_id' => 0, 'company_id' => 55],
        '5070' => ['waybill_no' => '5070', 'party_type' => 'company', 'sheet_party_id' => 55, 'label' => 'Dorobo Tours & Safaris', 'customer_id' => 0, 'company_id' => 55],
    ],
    'by_party' => [
        'company:55' => [
            'type' => 'company', 'sheet_id' => 55, 'label' => 'Dorobo Tours & Safaris',
            'waybill_nos' => ['4757', '4781', '5070'], 'count' => 3,
        ],
    ],
    'blank_individuals' => [],
];
$diff = kit_seed_diff_verification($exp, $act);
check('diff ok', $diff['ok'], true);
check('no party_count issues', count($diff['party_count_mismatches']), 0);
check('no hard mismatches', count($diff['mismatches']), 0);

echo "\n";
echo $fail === 0 ? "ALL PASSED ($pass)\n" : "FAILED: $fail / " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
