<?php
/**
 * kit_customers sheet → wp_kit_customers (+ company_id link to kit_company_customers).
 * kit_company_customers sheet → wp_kit_company_customers.
 *
 * WHY THIS EXISTS
 * ---------------
 * The waybill seeder was the only PHP seeder in the new pipeline, so waybills
 * were being written against a customer table nothing kept up to date. Measured
 * on the live data: 980 of 1132 waybill rows named a customer_id that existed
 * only in the sheet. Those waybills either failed to resolve or attached
 * themselves to whatever row happened to hold that id — which is how one
 * client's freight ends up split across several billing records.
 *
 * Customers must therefore be seeded BEFORE waybills. See KIT_Seed_Pipeline.
 *
 * WHY IT IGNORES THE COLUMN HEADERS
 * ---------------------------------
 * The kit_customers tab's headers do not describe its contents. Profiled across
 * all 599 rows:
 *
 *   email_address        64% small integers,  2% actual emails
 *   city_id              56% the text "N/A"
 *   telephone            40% small integers,  3% actual emails
 *   combined_name_ignore 29% phone numbers
 *   name                 12% phone numbers
 *
 * The rows are not uniformly shifted either — row 2 has an email where row 3
 * has a country id — so no fixed column remap can be correct. What IS reliable
 * is the shape of each value: an email matches an email, a phone matches a
 * phone, and a country/city id must exist in the reference tables.
 *
 * So this reads identity from the columns that ARE dependable (cust_id, name,
 * surname, company_id) and recovers contact details by scanning the row for
 * values of the right shape. Anything ambiguous is left null rather than
 * guessed — a blank phone number is recoverable, a wrong one is not.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Customer_Seeder
{
    const DEFAULT_SHEET_NAME  = 'kit_customers';
    const DEFAULT_SHEET_RANGE = 'A1:Z6000';

    /** Values that mean "nothing here", not real data. */
    private static $placeholders = ['n/a', 'na', 'none', '-', '--', 'null', 'private', 'individual', '0'];

    /**
     * Seed customers from the sheet.
     *
     * @param bool $shadow when true, compute everything but write nothing
     * @return array{success:bool,message:string,counts:array,rows:array}
     */
    public static function run(bool $shadow = false): array
    {
        global $wpdb;

        $counts = [
            'sheet_rows' => 0, 'inserted' => 0, 'updated' => 0,
            'skipped' => 0, 'errored' => 0, 'companies' => 0,
        ];

        if (!class_exists('Courier_Google_Sheets') || !Courier_Google_Sheets::is_configured()) {
            return ['success' => false, 'message' => 'Google Sheets not configured.', 'counts' => $counts, 'rows' => []];
        }

        try {
            $rows = Courier_Google_Sheets::get_values('', self::DEFAULT_SHEET_RANGE, self::DEFAULT_SHEET_NAME);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Sheet read failed: ' . $e->getMessage(), 'counts' => $counts, 'rows' => []];
        }

        if (!is_array($rows) || count($rows) < 2) {
            return ['success' => false, 'message' => 'kit_customers sheet returned no data rows.', 'counts' => $counts, 'rows' => []];
        }

        $header = array_map(
            fn($c) => str_replace(' ', '_', strtolower(trim((string) $c))),
            $rows[0]
        );
        $col = [];
        foreach ($header as $i => $h) {
            if ($h !== '' && !isset($col[$h])) {
                $col[$h] = $i;
            }
        }

        if (class_exists('Database') && method_exists('Database', 'ensure_customer_company_id_column')) {
            Database::ensure_customer_company_id_column();
        }

        $customers_table = $wpdb->prefix . 'kit_customers';
        $companies_table = $wpdb->prefix . 'kit_company_customers';
        $has_company_name = (bool) $wpdb->get_var($wpdb->prepare(
            'SHOW COLUMNS FROM `' . $customers_table . '` LIKE %s',
            'company_name'
        ));

        $valid_countries = self::reference_ids($wpdb->prefix . 'kit_operating_countries', 'country_id');
        $valid_cities    = self::reference_ids($wpdb->prefix . 'kit_operating_cities', 'city_id');

        $existing = [];
        foreach ((array) $wpdb->get_results("SELECT id, cust_id FROM {$customers_table}", ARRAY_A) as $r) {
            $existing[(int) $r['cust_id']] = (int) $r['id'];
        }
        $existing_companies = [];
        $company_names = []; // normalize key → company_id
        foreach ((array) $wpdb->get_results("SELECT id, company_id, company_name FROM {$companies_table}", ARRAY_A) as $r) {
            $cid = (int) $r['company_id'];
            $existing_companies[$cid] = (int) $r['id'];
            $label = trim((string) ($r['company_name'] ?? ''));
            if ($label !== '' && class_exists('KIT_Customers')) {
                $nk = KIT_Customers::normalize_company_compare_key($label);
                if ($nk !== '') {
                    $company_names[$nk] = $cid;
                }
            }
        }

        // Seed company sheet first so person.company_id can resolve.
        $company_sheet = self::seed_company_customers_sheet($shadow, $existing_companies);
        // Refresh name map after company sheet seed.
        foreach ((array) $wpdb->get_results("SELECT company_id, company_name FROM {$companies_table}", ARRAY_A) as $r) {
            $cid = (int) $r['company_id'];
            $label = trim((string) ($r['company_name'] ?? ''));
            if ($label !== '' && class_exists('KIT_Customers')) {
                $nk = KIT_Customers::normalize_company_compare_key($label);
                if ($nk !== '') {
                    $company_names[$nk] = $cid;
                }
            }
        }

        $report = [];
        $now = current_time('mysql');
        $counts['linked'] = 0;

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $counts['sheet_rows']++;

            $cust_id = self::int_cell($row, $col, ['cust_id', 'customer_id']);
            if ($cust_id <= 0) {
                $counts['skipped']++;
                continue;
            }

            $name    = self::text_cell($row, $col, ['name', 'customer_name']);
            $surname = self::text_cell($row, $col, ['surname', 'last_name']);
            $company = self::text_cell($row, $col, ['company_name', 'company']);
            $company_id = self::int_cell($row, $col, ['company_id', 'linked_company_id']);
            // company_name leftover in the company_id column is not an id.
            if ($company_id <= 0 && $company === '') {
                $maybe_label = self::text_cell($row, $col, ['company_id']);
                if ($maybe_label !== '' && !self::is_placeholder($maybe_label) && !is_numeric($maybe_label)) {
                    $company = $maybe_label;
                }
            }

            // A phone number in the name column is data damage, not a name.
            if (self::looks_like_phone($name)) {
                $name = '';
            }
            if (self::looks_like_phone($surname)) {
                $surname = '';
            }
            if (self::is_placeholder($company)) {
                $company = '';
            }

            $found = self::harvest_row($row, $valid_countries, $valid_cities);

            if ($name === '' && $surname === '' && $company === '') {
                // Nothing identifies this customer — an id alone is not a customer.
                $counts['skipped']++;
                $report[] = ['cust_id' => $cust_id, 'source_row' => $r + 1, 'action' => 'skip', 'why' => 'no name, surname or company'];
                continue;
            }

            // Resolve person → company link (sheet company_id, else company_name match).
            if ($company_id > 0 && !isset($existing_companies[$company_id])) {
                $company_id = 0;
            }
            if ($company_id <= 0 && $company !== '' && class_exists('KIT_Customers')) {
                $nk = KIT_Customers::normalize_company_compare_key($company);
                if ($nk !== '' && isset($company_names[$nk])) {
                    $company_id = (int) $company_names[$nk];
                }
            }
            // Ensure company row when person carries an explicit company_name (never skip
            // trade names like HEMOINSA / Grumeti that lack Ltd/Lodge keywords).
            if ($company_id <= 0 && $company !== '' && class_exists('KIT_Company_Customers')) {
                if (!$shadow) {
                    $company_id = (int) KIT_Company_Customers::ensure_company($company);
                    if ($company_id > 0) {
                        $existing_companies[$company_id] = $existing_companies[$company_id] ?? 1;
                        if (class_exists('KIT_Customers')) {
                            $nk = KIT_Customers::normalize_company_compare_key($company);
                            if ($nk !== '') {
                                $company_names[$nk] = $company_id;
                            }
                        }
                        $counts['companies']++;
                    }
                }
            }

            $payload = [
                'cust_id'       => $cust_id,
                'name'          => $name,
                'surname'       => $surname,
                'cell'          => $found['cell'],
                'telephone'     => $found['telephone'],
                'email_address' => $found['email'],
                'country_id'    => $found['country_id'] ?: null,
                'city_id'       => $found['city_id'] ?: null,
                'vat_number'    => $found['vat'],
                'address'       => $found['address'],
            ];
            $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'];
            if ($has_company_name) {
                $payload['company_name'] = $company;
                $formats[] = '%s';
            }
            if ($company_id > 0) {
                $payload['company_id'] = $company_id;
                $formats[] = '%d';
            } else {
                $payload['company_id'] = null;
                $formats[] = '%s';
            }

            if ($shadow) {
                $counts[isset($existing[$cust_id]) ? 'updated' : 'inserted']++;
                if ($company_id > 0) {
                    $counts['linked']++;
                }
                $report[] = ['cust_id' => $cust_id, 'source_row' => $r + 1, 'action' => 'shadow', 'payload' => $payload];
                continue;
            }

            if (isset($existing[$cust_id])) {
                $done = $wpdb->update($customers_table, $payload, ['id' => $existing[$cust_id]], $formats, ['%d']);
                if ($done === false) {
                    $counts['errored']++;
                    $report[] = ['cust_id' => $cust_id, 'source_row' => $r + 1, 'action' => 'error', 'why' => $wpdb->last_error];
                    continue;
                }
                $counts['updated']++;
            } else {
                $payload['created_at'] = $now;
                $formats[] = '%s';
                $done = $wpdb->insert($customers_table, $payload, $formats);
                if (!$done) {
                    $counts['errored']++;
                    $report[] = ['cust_id' => $cust_id, 'source_row' => $r + 1, 'action' => 'error', 'why' => $wpdb->last_error];
                    continue;
                }
                $existing[$cust_id] = (int) $wpdb->insert_id;
                $counts['inserted']++;
            }
            if ($company_id > 0) {
                $counts['linked']++;
            }
        }

        // Company sheet already seeded above; merge its counts.
        $counts['companies'] += (int) ($company_sheet['companies'] ?? 0);
        $counts['sheet_rows'] += (int) ($company_sheet['sheet_rows'] ?? 0);
        $counts['errored'] += (int) ($company_sheet['errored'] ?? 0);
        if (!empty($company_sheet['rows'])) {
            $report = array_merge($report, $company_sheet['rows']);
        }

        $message = sprintf(
            '%s customers: %d sheet row(s), %d inserted, %d updated, %d skipped, %d errored, %d company row(s), %d linked.',
            $shadow ? 'Shadow' : 'Production',
            $counts['sheet_rows'], $counts['inserted'], $counts['updated'],
            $counts['skipped'], $counts['errored'], $counts['companies'],
            (int) ($counts['linked'] ?? 0)
        );

        return ['success' => $counts['errored'] === 0, 'message' => $message, 'counts' => $counts, 'rows' => $report];
    }

    /**
     * Seed wp_kit_company_customers from the kit_company_customers sheet.
     *
     * Delegates to KIT_Company_Seeder so the admin Setup Seed and this pipeline
     * import companies identically — two implementations of the same upsert is
     * how the two paths drifted apart in the first place.
     *
     * @param array<int,int> $existing_companies company_id => row id
     * @return array{sheet_rows:int,companies:int,errored:int,rows:array}
     */
    private static function seed_company_customers_sheet(bool $shadow, array &$existing_companies): array
    {
        global $wpdb;

        $out = ['sheet_rows' => 0, 'companies' => 0, 'errored' => 0, 'rows' => []];
        if (!class_exists('KIT_Company_Seeder')) {
            return $out;
        }

        $rows = KIT_Company_Seeder::read_sheet_rows();
        if (count($rows) < 2) {
            // Sheet missing is fine — older workbooks only had kit_customers.
            return $out;
        }

        if ($shadow) {
            $out['sheet_rows'] = count($rows) - 1;
            $out['companies'] = $out['sheet_rows'];
            return $out;
        }

        $stats = KIT_Company_Seeder::seed_from_sheet_rows($rows);
        $out['sheet_rows'] = (int) $stats['sheet_rows'];
        $out['companies'] = (int) $stats['inserted'] + (int) $stats['updated'];
        $out['errored'] = (int) $stats['errored'];

        $companies_table = $wpdb->prefix . 'kit_company_customers';
        foreach ((array) $wpdb->get_results("SELECT id, company_id FROM {$companies_table}", ARRAY_A) as $r) {
            $existing_companies[(int) $r['company_id']] = (int) $r['id'];
        }

        return $out;
    }

    /**
     * Recover contact details by the SHAPE of each value rather than by which
     * column it sits in, because the headers do not describe the contents.
     *
     * Deliberately conservative: the first value of each shape wins, ids must
     * exist in the reference tables, and anything ambiguous stays null. A blank
     * field can be filled in later; a confidently wrong one cannot be found again.
     *
     * @param array<int,int> $valid_countries
     * @param array<int,int> $valid_cities
     * @return array{cell:?string,telephone:?string,email:?string,country_id:int,city_id:int,vat:?string,address:?string}
     */
    private static function harvest_row(array $row, array $valid_countries, array $valid_cities): array
    {
        $phones = [];
        $email = null;
        $ints = [];
        $texts = [];

        foreach ($row as $cell) {
            $v = trim((string) $cell);
            if ($v === '' || self::is_placeholder($v)) {
                continue;
            }
            if ($email === null && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $email = mb_substr($v, 0, 255);
                continue;
            }
            if (self::looks_like_phone($v)) {
                $phones[] = mb_substr(preg_replace('/[^\d+]/', '', $v), 0, 20);
                continue;
            }
            if (preg_match('/^\d{1,4}$/', $v)) {
                $ints[] = (int) $v;
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                continue; // created_at
            }
            $texts[] = $v;
        }

        // Small ints are only meaningful when they name a real country/city.
        $country_id = 0;
        $city_id = 0;
        foreach ($ints as $n) {
            if ($country_id === 0 && isset($valid_countries[$n])) {
                $country_id = $n;
                continue;
            }
            if ($city_id === 0 && isset($valid_cities[$n])) {
                $city_id = $n;
            }
        }

        // A VAT number carries letters and digits; a plain word does not.
        $vat = null;
        foreach ($texts as $t) {
            if (preg_match('/\d/', $t) && preg_match('/[A-Za-z]/', $t) && mb_strlen($t) <= 50) {
                $vat = mb_substr($t, 0, 50);
                break;
            }
        }

        return [
            'cell'       => $phones[0] ?? null,
            'telephone'  => $phones[1] ?? null,
            'email'      => $email,
            'country_id' => $country_id,
            'city_id'    => $city_id,
            'vat'        => $vat,
            'address'    => null,
        ];
    }

    /** @return array<int,int> id => id, for O(1) membership tests */
    private static function reference_ids(string $table, string $column): array
    {
        global $wpdb;
        $out = [];
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table
        ));
        if ((int) $exists === 0) {
            return $out;
        }
        // The reference tables use either a bare id or a named id column.
        foreach (['id', $column] as $candidate) {
            $vals = $wpdb->get_col("SELECT `{$candidate}` FROM {$table}");
            if (is_array($vals) && $vals !== []) {
                foreach ($vals as $v) {
                    $out[(int) $v] = (int) $v;
                }
                break;
            }
        }
        return $out;
    }

    private static function looks_like_phone(string $v): bool
    {
        $digits = preg_replace('/\D/', '', $v);
        return $digits !== '' && strlen($digits) >= 9 && preg_match('/^\+?[\d\s\-()]+$/', $v) === 1;
    }

    private static function is_placeholder(string $v): bool
    {
        return in_array(strtolower(trim($v)), self::$placeholders, true);
    }

    private static function text_cell(array $row, array $col, array $keys): string
    {
        foreach ($keys as $k) {
            if (!isset($col[$k])) {
                continue;
            }
            $v = trim((string) ($row[$col[$k]] ?? ''));
            if ($v !== '') {
                return mb_substr($v, 0, 255);
            }
        }
        return '';
    }

    private static function int_cell(array $row, array $col, array $keys): int
    {
        foreach ($keys as $k) {
            if (!isset($col[$k])) {
                continue;
            }
            $v = trim((string) ($row[$col[$k]] ?? ''));
            if ($v !== '') {
                return function_exists('kit_parse_sheet_int') ? kit_parse_sheet_int($v, 0) : (int) $v;
            }
        }
        return 0;
    }
}
