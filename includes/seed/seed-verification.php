<?php
/**
 * Google Sheet seed ownership + charge verification.
 *
 * Builds expected waybill→party ownership from sheet rows, actual ownership from DB,
 * and diffs them so Setup Seed can fail when assignments are wrong.
 *
 * Waybill freight total source of truth on kit_waybills sheet:
 *   Z  mass_charge / MASS COST
 *   AA volume_charge / VOL COST
 *   AB charge_basis → picks which charge is the billed waybill total
 */

if (!defined('ABSPATH')) {
    exit;
}

// Canonical separator-aware sheet number parser (kit_parse_sheet_decimal / _int).
require_once __DIR__ . '/../sync/kit-seed-number-parse.php';
// Canonical charge_basis helpers (normalize / total / inverted sanitize).
require_once __DIR__ . '/../sync/kit-charge-basis.php';

if (!function_exists('kit_seed_normalize_charge_basis')) {
    /**
     * Normalize sheet/DB charge_basis to 'mass' or 'volume'.
     * kit_waybills col AB is the source of truth (MASS / VOLUME).
     */
    function kit_seed_normalize_charge_basis(string $basis): string
    {
        $v = strtolower(trim($basis));
        if ($v === '' || in_array($v, ['auto', 'max', 'higher', 'best'], true)) {
            return '';
        }
        if (strpos($v, 'vol') === 0 || $v === 'v' || $v === 'cbm' || $v === 'm3') {
            return 'volume';
        }
        if (strpos($v, 'mass') === 0 || strpos($v, 'weight') === 0 || $v === 'w' || $v === 'kg') {
            return 'mass';
        }
        return '';
    }
}

if (!function_exists('kit_seed_total_from_charge_basis')) {
    /**
     * Billed freight total from mass/vol charges using charge_basis (AB).
     * Does not use max(mass, vol) when basis is set.
     */
    function kit_seed_total_from_charge_basis(float $mass_charge, float $volume_charge, string $charge_basis): float
    {
        $basis = kit_seed_normalize_charge_basis($charge_basis);
        if ($basis === 'volume') {
            return round($volume_charge, 2);
        }
        if ($basis === 'mass') {
            return round($mass_charge, 2);
        }
        // No explicit basis: fall back to higher charge (legacy / auto).
        return round(max($mass_charge, $volume_charge), 2);
    }
}

if (!function_exists('kit_seed_verification_normalize_waybill_key')) {
    function kit_seed_verification_normalize_waybill_key(string $waybill_no): string
    {
        if (function_exists('kit_seed_normalize_waybill_no_key')) {
            return kit_seed_normalize_waybill_no_key($waybill_no);
        }
        return strtolower(trim(preg_replace('/[^0-9A-Za-z\-]/', '', $waybill_no) ?? ''));
    }
}

if (!function_exists('kit_seed_verification_is_private_label')) {
    function kit_seed_verification_is_private_label(string $label): bool
    {
        $v = strtolower(trim($label));
        return $v === '' || in_array($v, ['private', 'individual', 'n/a', 'na', 'none', '-', '--'], true);
    }
}

if (!function_exists('kit_seed_verification_build_party_label_map')) {
    /**
     * Map sheet/DB party ids → display labels for ownership expected maps.
     * kit_waybills often has customer_id with blank cust_name_ignore; names live on
     * the customers tab (or already in DB after customer seed).
     *
     * @param array<int, array<int, mixed>> $customers_rows
     * @return array<int, array{label:string,type:string}>
     */
    function kit_seed_verification_build_party_label_map(array $customers_rows = []): array
    {
        $map = [];

        if (!empty($customers_rows) && count($customers_rows) >= 2 && function_exists('kit_seed_header_col_map') && function_exists('kit_seed_row_cell')) {
            $col = kit_seed_header_col_map($customers_rows[0]);
            foreach (array_slice($customers_rows, 1) as $row) {
                $cust_id = (int) preg_replace(
                    '/[^0-9]/',
                    '',
                    kit_seed_row_cell($row, $col, ['cust_id', 'customer_id'])
                );
                if ($cust_id <= 0) {
                    continue;
                }
                $name = kit_seed_row_cell($row, $col, ['name', 'customer_name']);
                $surname = kit_seed_row_cell($row, $col, ['surname', 'customer_surname', 'last_name']);
                $company = kit_seed_row_cell($row, $col, ['company_name', 'company']);
                $combined = kit_seed_row_cell($row, $col, ['combined_name_ignore', 'combined_name', 'customer']);
                $person = trim($name . ' ' . $surname);
                if ($person === '' && $combined !== '') {
                    $person = $combined;
                }
                $biz = $company !== '' ? $company : '';
                if ($biz === '' && $person !== ''
                    && function_exists('kit_seed_customer_looks_like_business')
                    && kit_seed_customer_looks_like_business($person)
                ) {
                    $biz = $person;
                    $person = '';
                }
                $biz_ok = $biz !== ''
                    && function_exists('kit_seed_customer_looks_like_business')
                    && kit_seed_customer_looks_like_business($biz);
                $person_ok = $person !== '' && !kit_seed_verification_is_private_label($person);
                // Mixed person + company_name (Lenet + Consolidate): keep person identity,
                // expose company_label so waybill expected ownership can still be company.
                if ($person_ok && $biz_ok && strcasecmp($person, $biz) !== 0) {
                    $map[$cust_id] = [
                        'label' => $person,
                        'type' => 'individual',
                        'company_label' => $biz,
                    ];
                    continue;
                }
                if ($biz_ok && !$person_ok) {
                    $map[$cust_id] = ['label' => $biz, 'type' => 'company', 'company_label' => $biz];
                    continue;
                }
                if ($person_ok) {
                    $map[$cust_id] = ['label' => $person, 'type' => 'individual', 'company_label' => ''];
                }
            }
        }

        // DB fallback (post-seed): customers sheet may be empty while waybill customer_id still points at named rows.
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            global $wpdb;
            $customers_t = $wpdb->prefix . 'kit_customers';
            $companies_t = $wpdb->prefix . 'kit_company_customers';
            $has_cust_company_id = (bool) $wpdb->get_var($wpdb->prepare(
                'SHOW COLUMNS FROM `' . $customers_t . '` LIKE %s',
                'company_id'
            ));
            $person_sql = $has_cust_company_id
                ? "SELECT c.cust_id, c.name, c.surname, c.company_id,
                          co.company_name AS linked_company_name
                   FROM {$customers_t} c
                   LEFT JOIN {$companies_t} co ON c.company_id = co.company_id"
                : "SELECT cust_id, name, surname, NULL AS company_id, NULL AS linked_company_name
                   FROM {$customers_t}";
            $person_rows = $wpdb->get_results($person_sql, ARRAY_A) ?: [];
            foreach ($person_rows as $prow) {
                $cid = (int) ($prow['cust_id'] ?? 0);
                if ($cid <= 0 || isset($map[$cid])) {
                    continue;
                }
                $person = trim(trim((string) ($prow['name'] ?? '')) . ' ' . trim((string) ($prow['surname'] ?? '')));
                $linked_company = trim((string) ($prow['linked_company_name'] ?? ''));
                $affiliation = $linked_company;
                $biz_ok = $affiliation !== ''
                    && function_exists('kit_seed_customer_looks_like_business')
                    && kit_seed_customer_looks_like_business($affiliation);
                $person_ok = $person !== '' && !kit_seed_verification_is_private_label($person);
                if ($person_ok && $biz_ok && strcasecmp($person, $affiliation) !== 0) {
                    $map[$cid] = [
                        'label' => $person,
                        'type' => 'individual',
                        'company_label' => $affiliation,
                    ];
                } elseif ($biz_ok && !$person_ok) {
                    $map[$cid] = ['label' => $affiliation, 'type' => 'company', 'company_label' => $affiliation];
                } elseif ($person_ok) {
                    $map[$cid] = ['label' => $person, 'type' => 'individual', 'company_label' => ''];
                }
            }

            // Companies last: a waybill's customer_id names a kit_customers row,
            // so a person holding that id outranks the company that shares it.
            $company_rows = $wpdb->get_results(
                "SELECT company_id, company_name FROM {$companies_t} WHERE TRIM(IFNULL(company_name,'')) != ''",
                ARRAY_A
            ) ?: [];
            foreach ($company_rows as $crow) {
                $cid = (int) ($crow['company_id'] ?? 0);
                $label = trim((string) ($crow['company_name'] ?? ''));
                if ($cid > 0 && $label !== '' && !isset($map[$cid])) {
                    $map[$cid] = ['label' => $label, 'type' => 'company', 'company_label' => $label];
                }
            }
        }

        return $map;
    }
}

if (!function_exists('kit_seed_verification_classify_party')) {
    /**
     * Classify sheet party the same way seed should assign.
     *
     * @param array<int, array<string, mixed>> $company_ids_known company_id => true
     * @return array{party_type:string,sheet_party_id:int,label:string}
     */
    function kit_seed_verification_classify_party(int $sheet_party_id, string $label, array $company_ids_known = []): array
    {
        $label = trim($label);
        if (function_exists('kit_seed_strip_private_company_label')) {
            $label = kit_seed_strip_private_company_label($label);
        }
        if (kit_seed_verification_is_private_label($label) && $sheet_party_id <= 0) {
            return ['party_type' => 'unassigned', 'sheet_party_id' => 0, 'label' => ''];
        }

        $looks_business = $label !== ''
            && function_exists('kit_seed_customer_looks_like_business')
            && kit_seed_customer_looks_like_business($label);

        if ($looks_business) {
            return [
                'party_type' => 'company',
                'sheet_party_id' => $sheet_party_id > 0 ? $sheet_party_id : 0,
                'label' => $label,
            ];
        }

        // The id alone cannot decide the party type. kit_customers.cust_id and
        // kit_company_customers.company_id are numbered from one sequence, so
        // 8687 is both a person (Mariaan Van Zyl) and a company (Trailchasers
        // Limited) — trusting the id over the name called 462 person waybills
        // company-owned. The name decides; the id only fills in for a blank.
        if (
            $sheet_party_id > 0
            && isset($company_ids_known[$sheet_party_id])
            && kit_seed_verification_is_private_label($label)
        ) {
            return [
                'party_type' => 'company',
                'sheet_party_id' => $sheet_party_id,
                'label' => $label,
            ];
        }

        if (kit_seed_verification_is_private_label($label)) {
            // Sheet has customer_id but Private/empty name — do not expect a blank individual.
            return [
                'party_type' => 'unassigned',
                'sheet_party_id' => 0,
                'label' => '',
            ];
        }

        if ($sheet_party_id > 0 || $label !== '') {
            return [
                'party_type' => 'individual',
                'sheet_party_id' => $sheet_party_id > 0 ? $sheet_party_id : 0,
                'label' => $label,
            ];
        }

        return ['party_type' => 'unassigned', 'sheet_party_id' => 0, 'label' => ''];
    }
}

if (!function_exists('kit_seed_build_verification_from_sheet')) {
    /**
     * Expected ownership map from kit_waybills (+ optional customers sheet for known company ids).
     *
     * @param array<int, array<int, mixed>> $waybill_rows
     * @param array<int, array<int, mixed>> $customers_rows
     * @param array{by_waybill?: array<string, array<string, mixed>>}|null $waybills_source_lookup
     * @return array{totals:array,by_party:array,by_waybill:array}
     */
    function kit_seed_build_verification_from_sheet(array $waybill_rows, array $customers_rows = [], ?array $waybills_source_lookup = null): array
    {
        $by_waybill = [];
        $by_party = [];
        $totals = [
            'waybills' => 0,
            'individuals' => 0,
            'companies' => 0,
            'unassigned' => 0,
        ];

        // Only real kit_company_customers ids count as company keys.
        // Do NOT treat person cust_ids as companies just because company_name
        // looks like a business (e.g. Lenet + "Consolidate Tourist Hotel").
        $company_ids_known = [];
        $known_company_names = [];
        if (class_exists('KIT_Company_Customers') || isset($GLOBALS['wpdb'])) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb)) {
                $co_rows = $wpdb->get_results(
                    "SELECT company_id, company_name FROM {$wpdb->prefix}kit_company_customers",
                    ARRAY_A
                ) ?: [];
                foreach ($co_rows as $crow) {
                    $cid = (int) ($crow['company_id'] ?? 0);
                    $cname = trim((string) ($crow['company_name'] ?? ''));
                    if ($cid > 0) {
                        $company_ids_known[$cid] = true;
                    }
                    if ($cname !== '') {
                        $known_company_names[] = $cname;
                    }
                }
            }
        }
        $party_label_map = kit_seed_verification_build_party_label_map($customers_rows);
        foreach ($party_label_map as $pid => $meta) {
            if (($meta['type'] ?? '') === 'company') {
                $company_ids_known[(int) $pid] = true;
            }
        }

        if (empty($waybill_rows) || count($waybill_rows) < 2) {
            return ['totals' => $totals, 'by_party' => $by_party, 'by_waybill' => $by_waybill];
        }

        $col = function_exists('kit_seed_header_col_map')
            ? kit_seed_header_col_map($waybill_rows[0])
            : [];
        $source_by_wb = is_array($waybills_source_lookup['by_waybill'] ?? null)
            ? $waybills_source_lookup['by_waybill']
            : [];

        foreach (array_slice($waybill_rows, 1) as $row) {
            $waybill_no = function_exists('kit_seed_row_cell')
                ? kit_seed_row_cell($row, $col, ['parcel_id', 'waybill_no', 'wb_no', 'waybill', 'waybill_#', 'newwb'])
                : '';
            $wb_key = kit_seed_verification_normalize_waybill_key((string) $waybill_no);
            if ($wb_key === '') {
                continue;
            }

            $sheet_party_id = (int) preg_replace(
                '/[^0-9]/',
                '',
                function_exists('kit_seed_row_cell') ? kit_seed_row_cell($row, $col, ['customer_id', 'cust_id']) : '0'
            );
            $label = function_exists('kit_seed_row_cell')
                ? kit_seed_row_cell($row, $col, ['cust_name_ignore', 'cust_name_ig', 'customer', 'cust_name', 'client'])
                : '';
            $source_contact = is_array($source_by_wb[$wb_key] ?? null) ? $source_by_wb[$wb_key] : null;
            // Mirror run_google_sheet_seed: Waybills-tab CUSTOMER, then pick(customer/company).
            if ($label === '' && is_array($source_contact)) {
                if (!empty($source_contact['customer'])) {
                    $label = trim((string) $source_contact['customer']);
                } elseif (function_exists('kit_seed_pick_customer_label_from_source_row')) {
                    $label = trim((string) kit_seed_pick_customer_label_from_source_row($source_contact));
                }
            }
            $company_label = function_exists('kit_seed_row_cell')
                ? kit_seed_row_cell($row, $col, ['company_name', 'company'])
                : '';
            if ($company_label === '' && is_array($source_contact) && !empty($source_contact['company'])) {
                $company_label = trim((string) $source_contact['company']);
            }
            // kit_waybills has no company_name — use customers-sheet affiliation (Lenet → Consolidate).
            if ($company_label === '' && $sheet_party_id > 0
                && !empty($party_label_map[$sheet_party_id]['company_label'])
            ) {
                $company_label = trim((string) $party_label_map[$sheet_party_id]['company_label']);
            }
            if (function_exists('kit_seed_strip_private_company_label')) {
                $company_label = kit_seed_strip_private_company_label($company_label);
            }
            $normalized_label = trim((string) $label);

            // customer_id with blank cust_name_ignore → resolve name from customers sheet/DB
            // (same ID the seeder assigns). Avoid expecting blank individual stubs.
            if (kit_seed_verification_is_private_label($normalized_label) && $sheet_party_id > 0
                && isset($party_label_map[$sheet_party_id]['label'])
            ) {
                $resolved = trim((string) $party_label_map[$sheet_party_id]['label']);
                if ($resolved !== '' && !kit_seed_verification_is_private_label($resolved)) {
                    $normalized_label = $resolved;
                }
            }

            // Mirror setup-seed assignment:
            // - Customer F is a business → company party is F (seed ignores Company I)
            // - Customer F is a person + Company I is a business → company party is I
            // - else classify F (person / unassigned)
            $cust_looks_business = $normalized_label !== ''
                && function_exists('kit_seed_customer_looks_like_business')
                && kit_seed_customer_looks_like_business($normalized_label);
            $company_looks_business = $company_label !== ''
                && function_exists('kit_seed_customer_looks_like_business')
                && kit_seed_customer_looks_like_business($company_label);
            $cust_is_person = $normalized_label !== ''
                && !$cust_looks_business
                && !kit_seed_verification_is_private_label($normalized_label);

            if ($cust_looks_business) {
                $party = kit_seed_verification_classify_party($sheet_party_id, $normalized_label, $company_ids_known);
                $on_company_roster = false;
                foreach ($known_company_names as $cname) {
                    if (kit_seed_verification_labels_equivalent('company', (string) $party['label'], $cname)) {
                        $on_company_roster = true;
                        break;
                    }
                }
                // CUSTOMER "Gaia Ltd" is not on kit_company_customers; Company "Laba Laba"
                // is the existing person roster row. Do not expect an invented company.
                if (!$on_company_roster) {
                    $fallback = null;
                    if ($company_looks_business) {
                        foreach ($known_company_names as $cname) {
                            if (kit_seed_verification_labels_equivalent('company', $company_label, $cname)) {
                                $fallback = kit_seed_verification_classify_party(0, $company_label, $company_ids_known);
                                break;
                            }
                        }
                    }
                    if ($fallback === null) {
                        foreach ($party_label_map as $pid => $meta) {
                            if (($meta['type'] ?? '') !== 'individual') {
                                continue;
                            }
                            $plabel = trim((string) ($meta['label'] ?? ''));
                            if ($plabel === '') {
                                continue;
                            }
                            $ok = ($company_label !== '' && kit_seed_verification_labels_equivalent('individual', $company_label, $plabel))
                                || kit_seed_verification_labels_equivalent('individual', $normalized_label, $plabel);
                            if ($ok) {
                                $fallback = [
                                    'party_type' => 'individual',
                                    'sheet_party_id' => (int) $pid,
                                    'label' => $plabel,
                                ];
                                break;
                            }
                        }
                    }
                    if (is_array($fallback)) {
                        $party = $fallback;
                    }
                }
            } elseif ($cust_is_person && $company_looks_business) {
                $party = [
                    'party_type' => 'company',
                    'sheet_party_id' => 0,
                    'label' => $company_label,
                ];
            } elseif ($normalized_label === '' && $company_looks_business) {
                $party = [
                    'party_type' => 'company',
                    'sheet_party_id' => 0,
                    'label' => $company_label,
                ];
            } else {
                $party = kit_seed_verification_classify_party($sheet_party_id, $normalized_label, $company_ids_known);
            }
            $by_waybill[$wb_key] = [
                'waybill_no' => (string) $waybill_no,
                'party_type' => $party['party_type'],
                'sheet_party_id' => (int) $party['sheet_party_id'],
                'label' => (string) $party['label'],
            ];
            $totals['waybills']++;

            if ($party['party_type'] === 'unassigned') {
                $totals['unassigned']++;
                continue;
            }

            $party_key = $party['party_type'] . ':' . (int) $party['sheet_party_id'];
            if ($party['sheet_party_id'] <= 0) {
                $party_key = $party['party_type'] . ':label:' . strtolower((string) $party['label']);
            }
            if (!isset($by_party[$party_key])) {
                $by_party[$party_key] = [
                    'type' => $party['party_type'],
                    'sheet_id' => (int) $party['sheet_party_id'],
                    'label' => (string) $party['label'],
                    'waybill_nos' => [],
                    'count' => 0,
                ];
                if ($party['party_type'] === 'company') {
                    $totals['companies']++;
                } else {
                    $totals['individuals']++;
                }
            }
            $by_party[$party_key]['waybill_nos'][] = (string) $waybill_no;
            $by_party[$party_key]['count']++;
        }

        return [
            'totals' => $totals,
            'by_party' => $by_party,
            'by_waybill' => $by_waybill,
        ];
    }
}

if (!function_exists('kit_seed_build_verification_from_db')) {
    /**
     * Actual ownership map from DB after seed.
     *
     * @return array{totals:array,by_party:array,by_waybill:array,blank_individuals:array<int,array>}
     */
    function kit_seed_build_verification_from_db(): array
    {
        global $wpdb;

        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $customers_t = $wpdb->prefix . 'kit_customers';
        $companies_t = $wpdb->prefix . 'kit_company_customers';

        $rows = $wpdb->get_results(
            "SELECT w.waybill_no, w.customer_id, w.company_id,
                    c.name AS customer_name, c.surname AS customer_surname,
                    co.company_name
             FROM {$waybills_t} w
             LEFT JOIN {$customers_t} c ON w.customer_id = c.cust_id
             LEFT JOIN {$companies_t} co ON w.company_id = co.company_id",
            ARRAY_A
        ) ?: [];

        $by_waybill = [];
        $by_party = [];
        $totals = [
            'waybills' => 0,
            'individuals' => 0,
            'companies' => 0,
            'unassigned' => 0,
        ];

        foreach ($rows as $row) {
            $waybill_no = (string) ($row['waybill_no'] ?? '');
            $wb_key = kit_seed_verification_normalize_waybill_key($waybill_no);
            if ($wb_key === '') {
                continue;
            }

            $company_id = (int) ($row['company_id'] ?? 0);
            $customer_id = (int) ($row['customer_id'] ?? 0);
            $company_name = trim((string) ($row['company_name'] ?? ''));
            $person = trim(trim((string) ($row['customer_name'] ?? '')) . ' ' . trim((string) ($row['customer_surname'] ?? '')));

            if ($company_id > 0) {
                $party_type = 'company';
                $sheet_party_id = $company_id;
                $label = $company_name;
            } elseif ($customer_id > 0) {
                $party_type = 'individual';
                $sheet_party_id = $customer_id;
                $label = $person;
            } else {
                $party_type = 'unassigned';
                $sheet_party_id = 0;
                $label = '';
            }

            $by_waybill[$wb_key] = [
                'waybill_no' => $waybill_no,
                'party_type' => $party_type,
                'sheet_party_id' => $sheet_party_id,
                'label' => $label,
                'customer_id' => $customer_id,
                'company_id' => $company_id,
            ];
            $totals['waybills']++;

            if ($party_type === 'unassigned') {
                $totals['unassigned']++;
                continue;
            }

            $party_key = $party_type . ':' . $sheet_party_id;
            if (!isset($by_party[$party_key])) {
                $by_party[$party_key] = [
                    'type' => $party_type,
                    'sheet_id' => $sheet_party_id,
                    'label' => $label,
                    'waybill_nos' => [],
                    'count' => 0,
                ];
                if ($party_type === 'company') {
                    $totals['companies']++;
                } else {
                    $totals['individuals']++;
                }
            }
            $by_party[$party_key]['waybill_nos'][] = $waybill_no;
            $by_party[$party_key]['count']++;
        }

        $blank_individuals = $wpdb->get_results(
            "SELECT cust_id, name, surname
             FROM {$customers_t}
             WHERE TRIM(IFNULL(name,'')) = ''
               AND TRIM(IFNULL(surname,'')) = ''
               AND (company_id IS NULL OR company_id = 0)",
            ARRAY_A
        ) ?: [];

        return [
            'totals' => $totals,
            'by_party' => $by_party,
            'by_waybill' => $by_waybill,
            'blank_individuals' => $blank_individuals,
        ];
    }
}

if (!function_exists('kit_seed_verification_party_identity_key')) {
    /**
     * Stable identity for ownership checks after near-duplicate merges
     * (e.g. "Edwin van den Berg" / "Edwin Van Der Berg" → person:edwin berg).
     */
    function kit_seed_verification_party_identity_key(string $party_type, int $sheet_party_id, string $label): string
    {
        $party_type = trim($party_type);
        $label = trim($label);

        if ($party_type === 'unassigned') {
            return 'unassigned:0';
        }

        if ($party_type === 'company') {
            if (class_exists('KIT_Customers')) {
                $key = KIT_Customers::normalize_company_compare_key($label);
                if ($key !== '') {
                    return 'company:' . $key;
                }
            }
            $fallback = strtolower(preg_replace('/\s+/', ' ', $label) ?? $label);
            return 'company:' . ($fallback !== '' ? $fallback : (string) max(0, $sheet_party_id));
        }

        if (class_exists('KIT_Customers')) {
            $key = KIT_Customers::normalize_person_name_key($label, '');
            if ($key !== '') {
                return 'person:' . $key;
            }
        }
        $fallback = strtolower(preg_replace('/\s+/', ' ', $label) ?? $label);
        return 'person:' . ($fallback !== '' ? $fallback : (string) max(0, $sheet_party_id));
    }
}

if (!function_exists('kit_seed_verification_company_token_compatible')) {
    /**
     * True when shorter company key tokens are all present in the longer key
     * (Dorobo Safaris ⊂ Dorobo Tours Safaris; Sasakwa Lodge ⊂ Sasakwa Lodge Grumeti).
     */
    function kit_seed_verification_company_token_compatible(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '') {
            return false;
        }
        $ta = preg_split('/\s+/', $a) ?: [];
        $tb = preg_split('/\s+/', $b) ?: [];
        $ta = array_values(array_filter($ta, static function ($t) {
            return strlen((string) $t) >= 3;
        }));
        $tb = array_values(array_filter($tb, static function ($t) {
            return strlen((string) $t) >= 3;
        }));
        if ($ta === [] || $tb === []) {
            return false;
        }
        $shorter = count($ta) <= count($tb) ? $ta : $tb;
        $longer = count($ta) <= count($tb) ? $tb : $ta;
        if (count($shorter) < 2) {
            return false;
        }
        $token_match = static function (string $needle, array $haystack): bool {
            $needle = (string) $needle;
            $n_stem = rtrim($needle, 's');
            foreach ($haystack as $cand) {
                $cand = (string) $cand;
                if ($cand === $needle) {
                    return true;
                }
                $c_stem = rtrim($cand, 's');
                if ($c_stem === $n_stem) {
                    return true;
                }
                // consolidate ≈ consolidated
                if (str_starts_with($cand, $needle) || str_starts_with($needle, $cand)) {
                    $max = max(strlen($cand), strlen($needle));
                    $min = min(strlen($cand), strlen($needle));
                    if ($min >= 5 && ($min / $max) >= 0.75) {
                        return true;
                    }
                }
            }
            return false;
        };
        foreach ($shorter as $tok) {
            if (!$token_match((string) $tok, $longer)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('kit_seed_verification_labels_equivalent')) {
    /**
     * True when two party labels are the same person/company after normalization
     * (particles, case, light spelling drift).
     */
    function kit_seed_verification_labels_equivalent(string $party_type, string $expected_label, string $actual_label): bool
    {
        $expected_label = trim($expected_label);
        $actual_label = trim($actual_label);
        if ($expected_label === '' || $actual_label === '') {
            return false;
        }

        if ($party_type === 'company') {
            if (!class_exists('KIT_Customers')) {
                return strtolower($expected_label) === strtolower($actual_label);
            }
            $a = KIT_Customers::normalize_company_compare_key($expected_label);
            $b = KIT_Customers::normalize_company_compare_key($actual_label);
            if ($a === '' || $b === '') {
                return false;
            }
            if ($a === $b || KIT_Customers::customer_labels_are_similar($a, $b)) {
                return true;
            }
            // ensure_company often collapses "Dorobo Safaris" → "Dorobo Tours & Safaris".
            return kit_seed_verification_company_token_compatible($a, $b);
        }

        if ($party_type === 'individual') {
            if (!class_exists('KIT_Customers')) {
                return strtolower($expected_label) === strtolower($actual_label);
            }
            $a = KIT_Customers::normalize_person_name_key($expected_label, '');
            $b = KIT_Customers::normalize_person_name_key($actual_label, '');
            if ($a === '' || $b === '') {
                return false;
            }
            if ($a === $b || KIT_Customers::customer_labels_are_similar($a, $b)) {
                return true;
            }
            // Sheet "Jonz" vs seeded "Jonz Express" — same first token, extra words.
            $ta = preg_split('/\s+/', $a) ?: [];
            $tb = preg_split('/\s+/', $b) ?: [];
            $shorter = count($ta) <= count($tb) ? $ta : $tb;
            $longer = count($ta) <= count($tb) ? $tb : $ta;
            if (
                count($shorter) === 1
                && count($longer) >= 2
                && strlen((string) $shorter[0]) >= 4
                && $shorter[0] === $longer[0]
            ) {
                return true;
            }
            // "Laba Laba" on the waybill Company column vs customer "Laba Laba Gaia".
            return count($shorter) >= 2
                && array_slice($longer, 0, count($shorter)) === $shorter;
        }

        return false;
    }
}

if (!function_exists('kit_seed_diff_verification')) {
    /**
     * Ownership verification after seed + near-duplicate merge.
     * - Every waybill must exist on both sides
     * - party_type must match
     * - sheet_party_id should match; remaps OK when labels normalize to the same person/company
     * - per-party waybill counts compared by normalized identity (not raw sheet id alone)
     * - blank individual stubs fail
     *
     * @param array{by_waybill?:array,by_party?:array,totals?:array} $expected
     * @param array{by_waybill?:array,by_party?:array,totals?:array,blank_individuals?:array} $actual
     * @return array{ok:bool,mismatch_count:int,mismatches:array,missing:array,extra:array,party_count_mismatches:array,blank_individuals:array,totals:array}
     */
    function kit_seed_diff_verification(array $expected, array $actual): array
    {
        $exp_wb = is_array($expected['by_waybill'] ?? null) ? $expected['by_waybill'] : [];
        $act_wb = is_array($actual['by_waybill'] ?? null) ? $actual['by_waybill'] : [];
        $exp_parties = is_array($expected['by_party'] ?? null) ? $expected['by_party'] : [];
        $act_parties = is_array($actual['by_party'] ?? null) ? $actual['by_party'] : [];

        $mismatches = [];
        $missing = [];
        $extra = [];
        $party_count_mismatches = [];

        foreach ($exp_wb as $key => $exp) {
            if (!isset($act_wb[$key])) {
                $missing[] = [
                    'waybill_no' => (string) ($exp['waybill_no'] ?? $key),
                    'expected' => $exp,
                ];
                continue;
            }
            $act = $act_wb[$key];
            $exp_type = (string) ($exp['party_type'] ?? '');
            $act_type = (string) ($act['party_type'] ?? '');
            $exp_id = (int) ($exp['sheet_party_id'] ?? 0);
            $act_id = (int) ($act['sheet_party_id'] ?? 0);
            $exp_label = (string) ($exp['label'] ?? '');
            $act_label = (string) ($act['label'] ?? '');

            if ($exp_type === 'unassigned') {
                if ($act_type !== 'unassigned') {
                    $mismatches[] = [
                        'waybill_no' => (string) ($exp['waybill_no'] ?? $key),
                        'reason' => 'expected_unassigned',
                        'expected' => $exp,
                        'actual' => $act,
                    ];
                }
                continue;
            }

            if ($exp_type !== $act_type) {
                $cross_ok = false;
                if ($exp_label !== '' && $act_label !== '') {
                    $cross_ok = kit_seed_verification_labels_equivalent($exp_type, $exp_label, $act_label)
                        || kit_seed_verification_labels_equivalent($act_type, $exp_label, $act_label);
                }
                if (!$cross_ok) {
                    $mismatches[] = [
                        'waybill_no' => (string) ($exp['waybill_no'] ?? $key),
                        'reason' => 'party_type_mismatch',
                        'expected' => $exp,
                        'actual' => $act,
                    ];
                }
                continue;
            }

            if ($exp_id > 0 && $act_id > 0 && $exp_id !== $act_id) {
                // Near-duplicate merge: sheet had two IDs for the same person/company.
                if (!kit_seed_verification_labels_equivalent($exp_type, $exp_label, $act_label)) {
                    $mismatches[] = [
                        'waybill_no' => (string) ($exp['waybill_no'] ?? $key),
                        'reason' => 'party_id_mismatch',
                        'expected' => $exp,
                        'actual' => $act,
                    ];
                }
            } elseif ($exp_id <= 0 && $act_id > 0) {
                // Expected from label (no sheet id) — accept ensure_company remaps by label.
                if ($exp_label !== '' && $act_label !== ''
                    && !kit_seed_verification_labels_equivalent($exp_type, $exp_label, $act_label)
                ) {
                    $mismatches[] = [
                        'waybill_no' => (string) ($exp['waybill_no'] ?? $key),
                        'reason' => 'party_label_mismatch',
                        'expected' => $exp,
                        'actual' => $act,
                    ];
                }
            } elseif ($exp_id <= 0 && $act_id <= 0) {
                if ($exp_label === '' || $act_label === ''
                    || !kit_seed_verification_labels_equivalent($exp_type, $exp_label, $act_label)
                ) {
                    $mismatches[] = [
                        'waybill_no' => (string) ($exp['waybill_no'] ?? $key),
                        'reason' => 'missing_party_id',
                        'expected' => $exp,
                        'actual' => $act,
                    ];
                }
            }
        }

        foreach ($act_wb as $key => $act) {
            if (!isset($exp_wb[$key])) {
                $extra[] = [
                    'waybill_no' => (string) ($act['waybill_no'] ?? $key),
                    'actual' => $act,
                ];
            }
        }

        // Per-party counts by normalized identity (survives van den / Van Der merges).
        // Then union identities that share sheet party ids across expected vs actual so
        // sheet cust_name aliases (HEMOINSA) vs DB person names (Alex Olifiser) on the
        // same cust_id do not false-fail party counts.
        $aggregate_parties = static function (array $parties): array {
            $out = [];
            foreach ($parties as $pkey => $p) {
                $type = (string) ($p['type'] ?? '');
                if ($type === '' && strpos((string) $pkey, ':') !== false) {
                    $type = (string) strtok((string) $pkey, ':');
                }
                $sheet_id = (int) ($p['sheet_id'] ?? 0);
                $label = (string) ($p['label'] ?? '');
                $identity = kit_seed_verification_party_identity_key($type, $sheet_id, $label);
                if (!isset($out[$identity])) {
                    $out[$identity] = [
                        'identity' => $identity,
                        'type' => $type,
                        'label' => $label,
                        'count' => 0,
                        'waybill_nos' => [],
                        'sheet_ids' => [],
                    ];
                }
                $out[$identity]['count'] += (int) ($p['count'] ?? 0);
                $out[$identity]['waybill_nos'] = array_values(array_unique(array_merge(
                    $out[$identity]['waybill_nos'],
                    is_array($p['waybill_nos'] ?? null) ? $p['waybill_nos'] : []
                )));
                if ($sheet_id > 0) {
                    $out[$identity]['sheet_ids'][] = $sheet_id;
                }
                if ($label !== '' && strlen($label) > strlen((string) $out[$identity]['label'])) {
                    $out[$identity]['label'] = $label;
                }
            }
            foreach ($out as $identity => $row) {
                $out[$identity]['sheet_ids'] = array_values(array_unique(array_map('intval', $row['sheet_ids'] ?? [])));
            }
            return $out;
        };

        $exp_by_identity = $aggregate_parties($exp_parties);
        $act_by_identity = $aggregate_parties($act_parties);

        // Union-find over identity keys so shared sheet_ids (and equivalent labels)
        // collapse alias splits before count comparison.
        $parent = [];
        $find = static function (string $x) use (&$parent, &$find): string {
            if (!isset($parent[$x])) {
                $parent[$x] = $x;
            }
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }
            return $parent[$x];
        };
        $union = static function (string $a, string $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        foreach (array_keys($exp_by_identity) as $identity) {
            $find($identity);
        }
        foreach (array_keys($act_by_identity) as $identity) {
            $find($identity);
        }

        $sheet_owners = []; // type:sheet_id => identity[]
        $register_sheet_ids = static function (array $by_identity) use (&$sheet_owners): void {
            foreach ($by_identity as $identity => $row) {
                $type = (string) ($row['type'] ?? '');
                foreach ($row['sheet_ids'] as $sid) {
                    $sid = (int) $sid;
                    if ($sid <= 0 || $type === '') {
                        continue;
                    }
                    $sk = $type . ':' . $sid;
                    if (!isset($sheet_owners[$sk])) {
                        $sheet_owners[$sk] = [];
                    }
                    $sheet_owners[$sk][$identity] = true;
                }
            }
        };
        $register_sheet_ids($exp_by_identity);
        $register_sheet_ids($act_by_identity);
        foreach ($sheet_owners as $idents) {
            $list = array_keys($idents);
            $first = $list[0] ?? null;
            if ($first === null) {
                continue;
            }
            foreach (array_slice($list, 1) as $other) {
                $union($first, $other);
            }
        }

        // Near-duplicate remaps where ids differ but labels normalize together.
        foreach ($exp_by_identity as $e_id => $ep) {
            $e_type = (string) ($ep['type'] ?? '');
            $e_label = (string) ($ep['label'] ?? '');
            foreach ($act_by_identity as $a_id => $ap) {
                $a_type = (string) ($ap['type'] ?? '');
                $a_label = (string) ($ap['label'] ?? '');
                $same_type = $e_type === $a_type;
                $labels_ok = $e_label !== '' && $a_label !== ''
                    && (
                        kit_seed_verification_labels_equivalent($e_type !== '' ? $e_type : 'company', $e_label, $a_label)
                        || kit_seed_verification_labels_equivalent($a_type !== '' ? $a_type : 'company', $e_label, $a_label)
                    );
                if (!$same_type && !$labels_ok) {
                    continue;
                }
                if ($same_type && !$labels_ok) {
                    continue;
                }
                $union($e_id, $a_id);
            }
        }

        $components = [];
        foreach (array_keys($exp_by_identity + $act_by_identity) as $identity) {
            $root = $find($identity);
            if (!isset($components[$root])) {
                $components[$root] = [
                    'identities' => [],
                    'label' => '',
                    'exp_count' => 0,
                    'act_count' => 0,
                    'exp_waybills' => [],
                    'act_waybills' => [],
                ];
            }
            $components[$root]['identities'][] = $identity;
            if (isset($exp_by_identity[$identity])) {
                $components[$root]['exp_count'] += (int) ($exp_by_identity[$identity]['count'] ?? 0);
                $components[$root]['exp_waybills'] = array_values(array_unique(array_merge(
                    $components[$root]['exp_waybills'],
                    $exp_by_identity[$identity]['waybill_nos'] ?? []
                )));
                $lbl = (string) ($exp_by_identity[$identity]['label'] ?? '');
                if ($lbl !== '' && strlen($lbl) > strlen($components[$root]['label'])) {
                    $components[$root]['label'] = $lbl;
                }
            }
            if (isset($act_by_identity[$identity])) {
                $components[$root]['act_count'] += (int) ($act_by_identity[$identity]['count'] ?? 0);
                $components[$root]['act_waybills'] = array_values(array_unique(array_merge(
                    $components[$root]['act_waybills'],
                    $act_by_identity[$identity]['waybill_nos'] ?? []
                )));
                $lbl = (string) ($act_by_identity[$identity]['label'] ?? '');
                if ($lbl !== '' && strlen($lbl) > strlen($components[$root]['label'])) {
                    $components[$root]['label'] = $lbl;
                }
            }
        }

        foreach ($components as $root => $comp) {
            $exp_count = (int) ($comp['exp_count'] ?? 0);
            $act_count = (int) ($comp['act_count'] ?? 0);
            if ($exp_count === $act_count) {
                continue;
            }
            $party_count_mismatches[] = [
                'party_key' => $root,
                'label' => (string) ($comp['label'] ?? ''),
                'expected_count' => $exp_count,
                'actual_count' => $act_count,
                'expected_waybill_nos' => array_slice($comp['exp_waybills'] ?? [], 0, 20),
                'actual_waybill_nos' => array_slice($comp['act_waybills'] ?? [], 0, 20),
                'merged_identities' => array_values(array_unique($comp['identities'] ?? [])),
            ];
        }

        $blanks = is_array($actual['blank_individuals'] ?? null) ? $actual['blank_individuals'] : [];
        foreach ($blanks as $blank) {
            $mismatches[] = [
                'waybill_no' => '',
                'reason' => 'blank_individual_stub',
                'expected' => null,
                'actual' => $blank,
            ];
        }

        $exp_totals = is_array($expected['totals'] ?? null) ? $expected['totals'] : [];
        $act_totals = is_array($actual['totals'] ?? null) ? $actual['totals'] : [];
        foreach (['waybills', 'individuals', 'companies', 'unassigned'] as $tk) {
            if ((int) ($exp_totals[$tk] ?? 0) !== (int) ($act_totals[$tk] ?? 0)) {
                // Waybill + unassigned totals must match exactly.
                // Unique individual/company counts drift when ensure_company merges
                // near-duplicate labels — party_count_mismatches is the real check.
                if (in_array($tk, ['individuals', 'companies'], true)) {
                    $has_type_count_issue = false;
                    foreach ($party_count_mismatches as $pcm) {
                        $pkey = (string) ($pcm['party_key'] ?? '');
                        if ($tk === 'individuals' && strpos($pkey, 'person:') === 0) {
                            $has_type_count_issue = true;
                            break;
                        }
                        if ($tk === 'companies' && strpos($pkey, 'company:') === 0) {
                            $has_type_count_issue = true;
                            break;
                        }
                    }
                    if (!$has_type_count_issue) {
                        continue;
                    }
                }
                $mismatches[] = [
                    'waybill_no' => '',
                    'reason' => 'totals_mismatch_' . $tk,
                    'expected' => $exp_totals,
                    'actual' => $act_totals,
                ];
            }
        }

        $mismatch_count = count($mismatches) + count($missing) + count($extra) + count($party_count_mismatches);

        return [
            'ok' => $mismatch_count === 0,
            'mismatch_count' => $mismatch_count,
            'mismatches' => array_slice($mismatches, 0, 50),
            'missing' => array_slice($missing, 0, 50),
            'extra' => array_slice($extra, 0, 50),
            'party_count_mismatches' => array_slice($party_count_mismatches, 0, 50),
            'blank_individuals' => $blanks,
            'totals' => [
                'expected' => $exp_totals,
                'actual' => $act_totals,
            ],
            'mismatches_truncated' => max(0, count($mismatches) - 50),
            'missing_truncated' => max(0, count($missing) - 50),
            'extra_truncated' => max(0, count($extra) - 50),
            'party_count_mismatches_truncated' => max(0, count($party_count_mismatches) - 50),
        ];
    }
}

if (!function_exists('kit_seed_prune_blank_customer_stubs')) {
    /**
     * Delete nameless individual stubs with no usable identity.
     * Waybills pointing at them are cleared to customer_id = 0.
     */
    function kit_seed_prune_blank_customer_stubs(): int
    {
        global $wpdb;
        $customers_t = $wpdb->prefix . 'kit_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';

        $blanks = $wpdb->get_col(
            "SELECT cust_id FROM {$customers_t}
             WHERE TRIM(IFNULL(name,'')) = ''
               AND TRIM(IFNULL(surname,'')) = ''
               AND (company_id IS NULL OR company_id = 0)"
        ) ?: [];

        $deleted = 0;
        foreach ($blanks as $cust_id) {
            $cust_id = (int) $cust_id;
            if ($cust_id <= 0) {
                continue;
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE {$waybills_t} SET customer_id = 0 WHERE customer_id = %d",
                $cust_id
            ));
            if (false !== $wpdb->delete($customers_t, ['cust_id' => $cust_id], ['%d'])) {
                $deleted++;
            }
        }

        return $deleted;
    }
}

if (!function_exists('kit_seed_prune_company_mirror_person_stubs')) {
    /**
     * Remove kit_customers rows that only mirror a company (same id, business-only name).
     * Keeps mixed person rows (distinct personal name + company id).
     */
    function kit_seed_prune_company_mirror_person_stubs(): int
    {
        global $wpdb;
        if (!class_exists('KIT_Company_Customers')) {
            return 0;
        }

        $customers_t = $wpdb->prefix . 'kit_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $companies_t = $wpdb->prefix . 'kit_company_customers';

        $rows = $wpdb->get_results(
            "SELECT c.cust_id, c.name, c.surname, co.company_name AS co_name
             FROM {$customers_t} c
             INNER JOIN {$companies_t} co ON co.company_id = c.cust_id",
            ARRAY_A
        ) ?: [];

        $deleted = 0;
        foreach ($rows as $row) {
            $cust_id = (int) ($row['cust_id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            $surname = trim((string) ($row['surname'] ?? ''));
            $co_name = trim((string) ($row['co_name'] ?? ''));
            $person = trim($name . ' ' . $surname);

            $is_mirror = false;
            if ($person === '') {
                $is_mirror = true;
            } elseif (
                $surname === ''
                && $person !== ''
                && (
                    strcasecmp($person, $co_name) === 0
                    || (
                        function_exists('kit_seed_customer_looks_like_business')
                        && kit_seed_customer_looks_like_business($person)
                    )
                )
            ) {
                $is_mirror = true;
            }

            if (!$is_mirror) {
                continue;
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE {$waybills_t} SET customer_id = 0 WHERE customer_id = %d",
                $cust_id
            ));
            if (false !== $wpdb->delete($customers_t, ['cust_id' => $cust_id], ['%d'])) {
                $deleted++;
            }
        }

        return $deleted;
    }
}

if (!function_exists('kit_seed_trim_parties_to_sheet_tabs')) {
    /**
     * Keep the kit_customers / kit_company_customers tab ids. Extra rows
     * invented from waybill labels are merged onto a sheet party or deleted.
     *
     * @param array<int, array<int, mixed>> $customers_rows
     * @param array<int, array<int, mixed>> $company_rows
     * @return array{customers:int,companies:int}
     */
    function kit_seed_trim_parties_to_sheet_tabs(array $customers_rows, array $company_rows): array
    {
        global $wpdb;
        $stats = ['customers' => 0, 'companies' => 0];
        $customers_t = $wpdb->prefix . 'kit_customers';
        $companies_t = $wpdb->prefix . 'kit_company_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';

        $sheet_cust = [];
        if (count($customers_rows) >= 2 && function_exists('kit_seed_header_col_map')) {
            $col = kit_seed_header_col_map($customers_rows[0]);
            foreach (array_slice($customers_rows, 1) as $row) {
                $id = (int) preg_replace('/[^0-9]/', '', kit_seed_row_cell($row, $col, ['cust_id', 'customer_id']));
                if ($id > 0) {
                    $sheet_cust[$id] = true;
                }
            }
        }
        $sheet_co = [];
        if (count($company_rows) >= 2 && function_exists('kit_seed_header_col_map')) {
            $col = kit_seed_header_col_map($company_rows[0]);
            foreach (array_slice($company_rows, 1) as $row) {
                $id = (int) preg_replace('/[^0-9]/', '', kit_seed_row_cell($row, $col, ['company_id']));
                if ($id > 0) {
                    $sheet_co[$id] = true;
                }
            }
        }

        if ($sheet_co !== []) {
            $keep_list = implode(',', array_map('intval', array_keys($sheet_co)));
            $keepers = $wpdb->get_results(
                "SELECT company_id, company_name FROM {$companies_t} WHERE company_id IN ({$keep_list})",
                ARRAY_A
            ) ?: [];
            $extras = $wpdb->get_results(
                "SELECT company_id, company_name FROM {$companies_t} WHERE company_id NOT IN ({$keep_list})",
                ARRAY_A
            ) ?: [];
            foreach ($extras as $ex) {
                $eid = (int) ($ex['company_id'] ?? 0);
                $elabel = (string) ($ex['company_name'] ?? '');
                $target = 0;
                foreach ($keepers as $keep) {
                    $kid = (int) ($keep['company_id'] ?? 0);
                    $klabel = (string) ($keep['company_name'] ?? '');
                    if ($kid <= 0 || $elabel === '' || $klabel === '') {
                        continue;
                    }
                    if (function_exists('kit_seed_verification_labels_equivalent')
                        && kit_seed_verification_labels_equivalent('company', $elabel, $klabel)
                    ) {
                        $target = $kid;
                        break;
                    }
                }
                if ($target > 0) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$waybills_t} SET company_id = %d WHERE company_id = %d",
                        $target,
                        $eid
                    ));
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$customers_t} SET company_id = %d WHERE company_id = %d",
                        $target,
                        $eid
                    ));
                    if (class_exists('KIT_Company_Customers') && KIT_Company_Customers::delete_company($eid)) {
                        $stats['companies']++;
                    }
                    continue;
                }
                $wb_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$waybills_t} WHERE company_id = %d",
                    $eid
                ));
                if ($wb_count > 0) {
                    continue;
                }
                if (class_exists('KIT_Company_Customers') && KIT_Company_Customers::delete_company($eid)) {
                    $stats['companies']++;
                }
            }
        }

        if ($sheet_cust !== []) {
            $keep_list = implode(',', array_map('intval', array_keys($sheet_cust)));
            $keepers = $wpdb->get_results(
                "SELECT cust_id, name, surname FROM {$customers_t} WHERE cust_id IN ({$keep_list})",
                ARRAY_A
            ) ?: [];
            $extras = $wpdb->get_results(
                "SELECT cust_id, name, surname FROM {$customers_t} WHERE cust_id NOT IN ({$keep_list})",
                ARRAY_A
            ) ?: [];
            foreach ($extras as $ex) {
                $eid = (int) ($ex['cust_id'] ?? 0);
                $elabel = trim((string) ($ex['name'] ?? '') . ' ' . (string) ($ex['surname'] ?? ''));
                $target = 0;
                foreach ($keepers as $keep) {
                    $kid = (int) ($keep['cust_id'] ?? 0);
                    $klabel = trim((string) ($keep['name'] ?? '') . ' ' . (string) ($keep['surname'] ?? ''));
                    if ($kid <= 0 || $elabel === '' || $klabel === '') {
                        continue;
                    }
                    if (function_exists('kit_seed_verification_labels_equivalent')
                        && kit_seed_verification_labels_equivalent('individual', $elabel, $klabel)
                    ) {
                        $target = $kid;
                        break;
                    }
                }
                if ($target > 0 && class_exists('KIT_Customers') && method_exists('KIT_Customers', 'merge_customers')) {
                    KIT_Customers::merge_customers($target, $eid);
                    $stats['customers']++;
                    continue;
                }
                $co_target = 0;
                if ($elabel !== '' && $sheet_co !== []) {
                    $co_rows = $wpdb->get_results(
                        'SELECT company_id, company_name FROM ' . $companies_t,
                        ARRAY_A
                    ) ?: [];
                    foreach ($co_rows as $keep) {
                        $kid = (int) ($keep['company_id'] ?? 0);
                        if ($kid <= 0 || empty($sheet_co[$kid])) {
                            continue;
                        }
                        $klabel = (string) ($keep['company_name'] ?? '');
                        if ($klabel !== ''
                            && function_exists('kit_seed_verification_labels_equivalent')
                            && kit_seed_verification_labels_equivalent('company', $elabel, $klabel)
                        ) {
                            $co_target = $kid;
                            break;
                        }
                    }
                }
                if ($co_target > 0) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$waybills_t} SET company_id = %d, customer_id = 0 WHERE customer_id = %d",
                        $co_target,
                        $eid
                    ));
                    if (false !== $wpdb->delete($customers_t, ['cust_id' => $eid], ['%d'])) {
                        $stats['customers']++;
                    }
                    continue;
                }
                $wb_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$waybills_t} WHERE customer_id = %d",
                    $eid
                ));
                if ($wb_count > 0) {
                    continue;
                }
                if (false !== $wpdb->delete($customers_t, ['cust_id' => $eid], ['%d'])) {
                    $stats['customers']++;
                }
            }
        }

        return $stats;
    }
}

if (!function_exists('kit_seed_prune_parties_without_waybills')) {
    /**
     * The customer and company tabs list every contact the workbook has ever
     * known. Setup Seed imported them all, then waybills only attached to the
     * billed subset — leaving Alexandre Costerg, Almasi Green Energy, and
     * dozens more showing "0 waybills". A party with no waybill is not a
     * customer of this operation. Drop them after waybills are assigned.
     *
     * Safe: only rows with zero matching waybills are deleted, so the
     * customer-delete cascade never fires. Unused companies clear person FKs
     * first via KIT_Company_Customers::delete_company().
     *
     * @return array{customers:int,companies:int}
     */
    function kit_seed_prune_parties_without_waybills(): array
    {
        global $wpdb;

        $customers_t = $wpdb->prefix . 'kit_customers';
        $companies_t = $wpdb->prefix . 'kit_company_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $stats = ['customers' => 0, 'companies' => 0];

        $unused_customers = $wpdb->get_col(
            "SELECT c.cust_id
             FROM {$customers_t} c
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$waybills_t} w
                 WHERE w.customer_id = c.cust_id
                    OR (c.id > 0 AND w.customer_id = c.id AND w.customer_id <> c.cust_id)
             )
               AND NOT EXISTS (
                 SELECT 1 FROM {$waybills_t} w
                 INNER JOIN {$companies_t} co ON w.company_id = co.company_id
                 WHERE c.company_id > 0 AND co.company_id = c.company_id
             )"
        ) ?: [];

        foreach ($unused_customers as $cust_id) {
            $cust_id = (int) $cust_id;
            if ($cust_id <= 0) {
                continue;
            }
            if (false !== $wpdb->delete($customers_t, ['cust_id' => $cust_id], ['%d'])) {
                $stats['customers']++;
            }
        }

        $unused_companies = $wpdb->get_col(
            "SELECT co.company_id
             FROM {$companies_t} co
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$waybills_t} w
                 WHERE w.company_id = co.company_id
                    OR (
                        w.customer_id = co.company_id
                        AND NOT EXISTS (
                            SELECT 1 FROM {$customers_t} c WHERE c.cust_id = w.customer_id
                        )
                    )
             )"
        ) ?: [];

        foreach ($unused_companies as $company_id) {
            $company_id = (int) $company_id;
            if ($company_id <= 0) {
                continue;
            }
            if (class_exists('KIT_Company_Customers') && KIT_Company_Customers::delete_company($company_id)) {
                $stats['companies']++;
            }
        }

        return $stats;
    }
}

if (!function_exists('kit_seed_run_ownership_verification')) {
    /**
     * Build expected/actual maps and diff. Persists JSON under plugin .cursor for inspection.
     * Also verifies freight totals using kit_waybills AB charge_basis (Z mass / AA volume).
     *
     * @param array<int, array<int, mixed>> $waybill_rows
     * @param array<int, array<int, mixed>> $customers_rows
     * @param array{by_waybill?: array}|null $waybills_source_lookup
     * @return array{ok:bool,mismatch_count:int,expected:array,actual:array,diff:array,json_path?:string,charges?:array}
     */
    function kit_seed_run_ownership_verification(array $waybill_rows, array $customers_rows = [], ?array $waybills_source_lookup = null): array
    {
        $expected = kit_seed_build_verification_from_sheet($waybill_rows, $customers_rows, $waybills_source_lookup);
        $actual = kit_seed_build_verification_from_db();
        $diff = kit_seed_diff_verification($expected, $actual);
        $charges = kit_seed_verify_charge_totals($waybill_rows);

        $ownership_ok = !empty($diff['ok']);
        $charges_ok = !empty($charges['ok']);
        $ok = $ownership_ok && $charges_ok;
        $mismatch_count = (int) ($diff['mismatch_count'] ?? 0) + (int) ($charges['mismatch_count'] ?? 0);

        $payload = [
            'generated_at' => gmdate('c'),
            'expected' => [
                'totals' => $expected['totals'],
                'by_waybill_count' => count($expected['by_waybill']),
                'by_party_count' => count($expected['by_party']),
            ],
            'actual' => [
                'totals' => $actual['totals'],
                'by_waybill_count' => count($actual['by_waybill']),
                'by_party_count' => count($actual['by_party']),
                'blank_individuals' => $actual['blank_individuals'],
            ],
            'diff' => $diff,
            'charges' => [
                'ok' => $charges_ok,
                'checked' => (int) ($charges['checked'] ?? 0),
                'mismatch_count' => (int) ($charges['mismatch_count'] ?? 0),
                'mismatches' => array_slice($charges['mismatches'] ?? [], 0, 50),
                'rule' => 'kit_waybills AB charge_basis selects Z mass_charge or AA volume_charge as billed total (product_invoice_amount)',
            ],
            'ok' => $ok,
            'mismatch_count' => $mismatch_count,
        ];

        $json_path = '';
        $base = defined('COURIER_FINANCE_PLUGIN_PATH')
            ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\")
            : dirname(__DIR__, 2);
        $dir = $base . '/.cursor';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json_path = $dir . '/seed-verification-last.json';
        @file_put_contents($json_path, wp_json_encode($payload, JSON_PRETTY_PRINT));

        // #region agent log
        {
            $dbg = [
                'sessionId' => '3f0725',
                'runId' => 'post-fix',
                'hypothesisId' => 'V',
                'location' => 'seed-verification.php:kit_seed_run_ownership_verification',
                'message' => $ok ? 'verification OK' : 'verification FAILED',
                'data' => [
                    'ok' => $ok,
                    'ownership_ok' => $ownership_ok,
                    'charges_ok' => $charges_ok,
                    'mismatch_count' => $mismatch_count,
                    'charge_mismatch_count' => (int) ($charges['mismatch_count'] ?? 0),
                    'expected_waybills' => $expected['totals']['waybills'] ?? 0,
                    'actual_waybills' => $actual['totals']['waybills'] ?? 0,
                    'blank_individuals' => count($actual['blank_individuals'] ?? []),
                    'sample_mismatches' => array_slice($diff['mismatches'] ?? [], 0, 5),
                    'sample_charge_mismatches' => array_slice($charges['mismatches'] ?? [], 0, 5),
                ],
                'timestamp' => (int) round(microtime(true) * 1000),
            ];
            @file_put_contents($dir . '/debug-3f0725.log', json_encode($dbg) . "\n", FILE_APPEND);
        }
        // #endregion

        return [
            'ok' => $ok,
            'mismatch_count' => $mismatch_count,
            'expected' => $expected,
            'actual' => $actual,
            'diff' => $diff,
            'charges' => $charges,
            'json_path' => $json_path,
            'waybill_items' => function_exists('kit_seed_verify_waybill_items')
                ? kit_seed_verify_waybill_items()
                : null,
        ];
    }
}

if (!function_exists('kit_seed_verify_charge_totals')) {
    /**
     * Compare sheet mass/vol/basis freight totals to DB after seed.
     * Expected billed total = AB charge_basis picking Z mass or AA volume.
     *
     * @param array<int, array<int, mixed>> $waybill_rows kit_waybills sheet rows (header + data)
     * @return array{ok:bool,checked:int,mismatch_count:int,mismatches:array<int,array>}
     */
    function kit_seed_verify_charge_totals(array $waybill_rows): array
    {
        global $wpdb;

        $empty = ['ok' => true, 'checked' => 0, 'mismatch_count' => 0, 'mismatches' => []];
        if (empty($waybill_rows) || count($waybill_rows) < 2) {
            return $empty;
        }
        if (!function_exists('kit_seed_header_col_map') || !function_exists('kit_seed_row_cell')) {
            return $empty;
        }

        $col = kit_seed_header_col_map($waybill_rows[0]);
        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $db_rows = $wpdb->get_results(
            "SELECT waybill_no, mass_charge, volume_charge, charge_basis, product_invoice_amount
             FROM {$waybills_t}",
            ARRAY_A
        ) ?: [];

        $by_wb = [];
        foreach ($db_rows as $db) {
            $key = kit_seed_verification_normalize_waybill_key((string) ($db['waybill_no'] ?? ''));
            if ($key === '') {
                continue;
            }
            $by_wb[$key] = $db;
        }

        $mismatches = [];
        $checked = 0;
        $eps = 0.02;

        foreach (array_slice($waybill_rows, 1) as $row) {
            $waybill_no = kit_seed_row_cell($row, $col, [
                'parcel_id', 'waybill_no', 'wb_no', 'waybill', 'waybill_#', 'newwb',
            ]);
            $wb_key = kit_seed_verification_normalize_waybill_key((string) $waybill_no);
            if ($wb_key === '') {
                continue;
            }

            $mass_raw = kit_seed_row_cell($row, $col, ['mass_charge', 'mass_cost']);
            $vol_raw = kit_seed_row_cell($row, $col, ['volume_charge', 'vol_cost', 'vol_charge']);
            // Empty basis stays empty → kit_seed_total_from_charge_basis uses max(mass, vol).
            $basis_raw = kit_seed_row_cell($row, $col, ['charge_basis', 'basis'], '');

            // Separator-aware: the sheet sends FORMATTED text ("R 1 200,50"), so a
            // naive digit strip would compare the DB against a 100x-wrong expectation.
            $exp_mass = function_exists('kit_parse_sheet_decimal')
                ? kit_parse_sheet_decimal($mass_raw, 0.0)
                : (float) preg_replace('/[^0-9.\-]/', '', (string) $mass_raw);
            $exp_vol = function_exists('kit_parse_sheet_decimal')
                ? kit_parse_sheet_decimal($vol_raw, 0.0)
                : (float) preg_replace('/[^0-9.\-]/', '', (string) $vol_raw);
            // Same inverted-basis clear as seed (MASS+mass=0 → empty → max).
            $exp_basis = function_exists('kit_seed_sanitize_inverted_charge_basis')
                ? kit_seed_sanitize_inverted_charge_basis((string) $basis_raw, $exp_mass, $exp_vol)
                : kit_seed_normalize_charge_basis((string) $basis_raw);
            $exp_total = kit_seed_total_from_charge_basis($exp_mass, $exp_vol, $exp_basis);

            $checked++;
            $db = $by_wb[$wb_key] ?? null;
            if ($db === null) {
                $mismatches[] = [
                    'waybill_no' => (string) $waybill_no,
                    'reason' => 'missing_in_db',
                    'expected' => [
                        'charge_basis' => $exp_basis,
                        'mass_charge' => $exp_mass,
                        'volume_charge' => $exp_vol,
                        'billed_total' => $exp_total,
                    ],
                    'actual' => null,
                ];
                continue;
            }

            $act_mass = (float) ($db['mass_charge'] ?? 0);
            $act_vol = (float) ($db['volume_charge'] ?? 0);
            // Empty basis stays empty — never invent MASS (matches seed + charge-basis rules).
            $act_basis = kit_seed_normalize_charge_basis((string) ($db['charge_basis'] ?? ''));
            $act_total = (float) ($db['product_invoice_amount'] ?? 0);
            $act_basis_total = kit_seed_total_from_charge_basis($act_mass, $act_vol, $act_basis);

            $reasons = [];
            if (abs($exp_mass - $act_mass) > $eps) {
                $reasons[] = 'mass_charge';
            }
            if (abs($exp_vol - $act_vol) > $eps) {
                $reasons[] = 'volume_charge';
            }
            if ($exp_basis !== $act_basis) {
                $reasons[] = 'charge_basis';
            }
            // product_invoice_amount is deliberately not compared against the
            // basis-selected freight. It is the billed total, derived after seeding
            // by KIT_Waybills::doubleCalcWaybillTotal(), so on a VAT-true waybill it
            // legitimately exceeds freight by the VAT. What the sheet is
            // authoritative for — mass_charge, volume_charge, charge_basis — is
            // checked above; both figures are still reported below for context.

            if ($reasons !== []) {
                $mismatches[] = [
                    'waybill_no' => (string) $waybill_no,
                    'reason' => implode(',', array_unique($reasons)),
                    'expected' => [
                        'charge_basis' => $exp_basis,
                        'mass_charge' => $exp_mass,
                        'volume_charge' => $exp_vol,
                        'billed_total' => $exp_total,
                    ],
                    'actual' => [
                        'charge_basis' => $act_basis,
                        'mass_charge' => $act_mass,
                        'volume_charge' => $act_vol,
                        'product_invoice_amount' => $act_total,
                        'basis_selected_total' => $act_basis_total,
                    ],
                ];
            }
        }

        return [
            'ok' => $mismatches === [],
            'checked' => $checked,
            'mismatch_count' => count($mismatches),
            'mismatches' => $mismatches,
        ];
    }
}

if (!function_exists('kit_seed_verify_waybill_items')) {
    /**
     * Ensure waybills with multi-part ("+") descriptions have matching kit_waybill_items rows.
     *
     * @return array{ok:bool,checked:int,missing:array<int,array{waybill_no:string,expected:int,actual:int}>,items_total:int}
     */
    function kit_seed_verify_waybill_items(): array
    {
        global $wpdb;
        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $items_t = $wpdb->prefix . 'kit_waybill_items';

        $rows = $wpdb->get_results(
            "SELECT waybill_no, description, miscellaneous, waybill_items_total FROM {$waybills_t}",
            ARRAY_A
        ) ?: [];

        $missing = [];
        $checked = 0;
        $items_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$items_t}");

        foreach ($rows as $row) {
            $wb = (string) ($row['waybill_no'] ?? '');
            if ($wb === '') {
                continue;
            }
            $desc = trim((string) ($row['description'] ?? ''));
            if ($desc === '' && !empty($row['miscellaneous'])) {
                $misc = maybe_unserialize($row['miscellaneous']);
                if (is_array($misc) && !empty($misc['others']['waybill_description'])) {
                    $desc = (string) $misc['others']['waybill_description'];
                }
            }
            if ($desc === '' || strpos($desc, '+') === false) {
                continue;
            }
            if (!function_exists('kit_seed_split_item_description')) {
                continue;
            }
            $parts = kit_seed_split_item_description($desc);
            $expected = count($parts);
            if ($expected < 2) {
                continue;
            }
            $checked++;
            $actual = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$items_t} WHERE waybillno = %s",
                $wb
            ));
            if ($actual < $expected) {
                $missing[] = [
                    'waybill_no' => $wb,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        }

        return [
            'ok' => $missing === [],
            'checked' => $checked,
            'missing' => array_slice($missing, 0, 25),
            'items_total' => $items_total,
        ];
    }
}
