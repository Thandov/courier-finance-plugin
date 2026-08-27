<?php
/**
 * kit_company_customers sheet tab → wp_kit_company_customers.
 *
 * WHY THIS EXISTS
 * ---------------
 * Companies became a first-class entity with their own sheet tab and their own
 * table, but nothing imported that tab. Setup Seed read kit_waybills and
 * kit_customers only, so every company in the database had been invented at
 * seed time by KIT_Company_Customers::ensure_company() from whatever label a
 * waybill happened to carry. Measured before this file existed: 56 of the 57
 * companies in wp_kit_company_customers matched no row on the 288-row company
 * tab, and the tab's ids (8600-8887) were absent from the database entirely.
 *
 * Two consequences followed. The company list drifted from the client's own
 * records — "Rhino Lodge" existed as an invented row rather than as sheet
 * company 8610. And because invented ids were allocated as MAX(company_id) + 1
 * they landed inside the sheet's customer_id range, so resolving a waybill
 * party by id matched an unrelated company (see KIT_Company_Customers::
 * LOCAL_ID_BASE for the full failure).
 *
 * Importing the tab first makes the sheet the source of truth for who the
 * companies are and what their ids are, and leaves ensure_company() as a
 * fallback for labels the tab does not cover.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Company_Seeder
{
    const DEFAULT_SHEET_NAME  = 'kit_company_customers';
    const DEFAULT_SHEET_RANGE = 'A1:Z6000';

    /**
     * Read the company tab. Returns [] when the workbook has no such tab —
     * older workbooks kept companies inside kit_customers.
     *
     * @return array<int, array<int, mixed>> header + data rows
     */
    public static function read_sheet_rows(): array
    {
        if (!class_exists('Courier_Google_Sheets') || !Courier_Google_Sheets::is_configured()) {
            return [];
        }
        try {
            $rows = Courier_Google_Sheets::get_values('', self::DEFAULT_SHEET_RANGE, self::DEFAULT_SHEET_NAME);
        } catch (Throwable $e) {
            return [];
        }
        return is_array($rows) ? $rows : [];
    }

    /**
     * Upsert the company tab into wp_kit_company_customers, keyed on the
     * sheet's own company_id.
     *
     * @param array<int, array<int, mixed>> $rows header + data rows
     * @return array{sheet_rows:int,inserted:int,updated:int,skipped:int,errored:int}
     */
    public static function seed_from_sheet_rows(array $rows): array
    {
        global $wpdb;

        $stats = ['sheet_rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0];
        if (count($rows) < 2) {
            return $stats;
        }

        if (class_exists('Database') && method_exists('Database', 'create_company_customers_table')) {
            Database::create_company_customers_table();
        }

        $table = $wpdb->prefix . 'kit_company_customers';
        $col = self::header_map($rows[0]);
        if (!isset($col['company_id']) || !isset($col['company_name'])) {
            return $stats;
        }

        $existing = [];
        foreach ((array) $wpdb->get_results("SELECT id, company_id FROM {$table}", ARRAY_A) as $r) {
            $existing[(int) $r['company_id']] = (int) $r['id'];
        }

        $valid_countries = self::reference_ids($wpdb->prefix . 'kit_operating_countries');
        $valid_cities    = self::reference_ids($wpdb->prefix . 'kit_operating_cities');
        $now = current_time('mysql');

        foreach (array_slice($rows, 1) as $row) {
            $stats['sheet_rows']++;

            $company_id = self::int_cell($row, $col, ['company_id', 'cust_id', 'customer_id']);
            $company_name = self::text_cell($row, $col, ['company_name', 'company', 'name']);
            if (function_exists('kit_seed_strip_private_company_label')) {
                $company_name = kit_seed_strip_private_company_label($company_name);
            }
            if ($company_id <= 0 || $company_name === '') {
                $stats['skipped']++;
                continue;
            }
            // The local block belongs to companies this system invents; a sheet
            // row claiming one of those ids would collide with them.
            if (KIT_Company_Customers::is_local_id($company_id)) {
                $stats['skipped']++;
                continue;
            }

            $country_id = self::int_cell($row, $col, ['country_id', 'country']);
            $city_id    = self::int_cell($row, $col, ['city_id', 'city']);

            $payload = [
                'company_id'    => $company_id,
                'company_name'  => $company_name,
                'cell'          => self::text_cell($row, $col, ['cell', 'cellphone', 'mobile']) ?: null,
                'telephone'     => self::text_cell($row, $col, ['telephone', 'tel', 'phone']) ?: null,
                'email_address' => self::valid_email(self::text_cell($row, $col, ['email_address', 'email'])),
                'country_id'    => isset($valid_countries[$country_id]) ? $country_id : null,
                'city_id'       => isset($valid_cities[$city_id]) ? $city_id : null,
                'vat_number'    => self::text_cell($row, $col, ['vat_number', 'vat']) ?: null,
                'address'       => self::text_cell($row, $col, ['address']) ?: null,
            ];
            $formats = ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'];

            if (isset($existing[$company_id])) {
                $done = $wpdb->update($table, $payload, ['id' => $existing[$company_id]], $formats, ['%d']);
                if ($done === false) {
                    $stats['errored']++;
                    continue;
                }
                $stats['updated']++;
                continue;
            }

            // The label may already be here under an invented id from an older
            // seed. Move it onto the sheet id rather than creating a duplicate.
            $by_name = KIT_Company_Customers::find_by_name($company_name);
            // Same label under two sheet ids (River Trees 8603 and 8677) must
            // both exist — only collapse invented local ids onto the sheet id.
            if (
                $by_name
                && KIT_Company_Customers::is_local_id((int) $by_name['company_id'])
                && KIT_Company_Customers::renumber_company((int) $by_name['company_id'], $company_id)
            ) {
                unset($existing[(int) $by_name['company_id']]);
                $existing[$company_id] = (int) $by_name['id'];
                $done = $wpdb->update($table, $payload, ['id' => (int) $by_name['id']], $formats, ['%d']);
                if ($done === false) {
                    $stats['errored']++;
                    continue;
                }
                $stats['updated']++;
                continue;
            }

            $payload['created_at'] = $now;
            $formats[] = '%s';
            if (!$wpdb->insert($table, $payload, $formats)) {
                $stats['errored']++;
                continue;
            }
            $existing[$company_id] = (int) $wpdb->insert_id;
            $stats['inserted']++;
        }

        return $stats;
    }

    /**
     * Every company id the sheet actually assigns, with the label it belongs to.
     *
     * Used by KIT_Company_Customers::relocate_unclaimed_sheet_ids() to tell a
     * genuine sheet id apart from one an older seed invented. The company tab
     * is authoritative; a business-looking row on the customers tab also claims
     * its cust_id, because that is the id ensure_company() reuses for it.
     *
     * @param array<int, array<int, mixed>> $company_rows
     * @param array<int, array<int, mixed>> $customers_rows
     * @return array<int, string> id => label
     */
    public static function sheet_claimed_labels(array $company_rows, array $customers_rows = []): array
    {
        $labels = [];

        if (count($customers_rows) >= 2) {
            $col = self::header_map($customers_rows[0]);
            foreach (array_slice($customers_rows, 1) as $row) {
                $cust_id = self::int_cell($row, $col, ['cust_id', 'customer_id']);
                if ($cust_id <= 0) {
                    continue;
                }
                $label = trim(
                    self::text_cell($row, $col, ['name', 'customer_name'])
                    . ' ' . self::text_cell($row, $col, ['surname', 'last_name'])
                );
                if ($label === '') {
                    continue;
                }
                if (function_exists('kit_seed_customer_looks_like_business') && !kit_seed_customer_looks_like_business($label)) {
                    continue;
                }
                $labels[$cust_id] = $label;
            }
        }

        if (count($company_rows) >= 2) {
            $col = self::header_map($company_rows[0]);
            foreach (array_slice($company_rows, 1) as $row) {
                $company_id = self::int_cell($row, $col, ['company_id', 'cust_id', 'customer_id']);
                $name = self::text_cell($row, $col, ['company_name', 'company', 'name']);
                if ($company_id > 0 && $name !== '') {
                    $labels[$company_id] = $name;
                }
            }
        }

        return $labels;
    }

    /** @param array<int, mixed> $header_row @return array<string, int> */
    private static function header_map(array $header_row): array
    {
        $col = [];
        foreach ($header_row as $i => $h) {
            $key = str_replace(' ', '_', strtolower(trim((string) $h)));
            if ($key !== '' && !isset($col[$key])) {
                $col[$key] = $i;
            }
        }
        return $col;
    }

    /** @return array<int,int> id => id */
    private static function reference_ids(string $table): array
    {
        global $wpdb;
        $out = [];
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
            DB_NAME,
            $table
        ));
        if ($exists === 0) {
            return $out;
        }
        foreach ((array) $wpdb->get_col("SELECT id FROM {$table}") as $v) {
            $out[(int) $v] = (int) $v;
        }
        return $out;
    }

    private static function valid_email(string $value): ?string
    {
        $value = trim($value);
        return ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) ? mb_substr($value, 0, 255) : null;
    }

    /** @param array<int,mixed> $row @param array<string,int> $col @param array<int,string> $keys */
    private static function text_cell(array $row, array $col, array $keys): string
    {
        foreach ($keys as $k) {
            if (!isset($col[$k])) {
                continue;
            }
            $v = trim((string) ($row[$col[$k]] ?? ''));
            if ($v === '' || in_array(strtolower($v), ['n/a', 'na', 'none', 'null', '-', '--', '0'], true)) {
                continue;
            }
            if (function_exists('kit_seed_is_sheet_error_value') && kit_seed_is_sheet_error_value($v)) {
                continue;
            }
            return mb_substr($v, 0, 255);
        }
        return '';
    }

    /** @param array<int,mixed> $row @param array<string,int> $col @param array<int,string> $keys */
    private static function int_cell(array $row, array $col, array $keys): int
    {
        foreach ($keys as $k) {
            if (!isset($col[$k])) {
                continue;
            }
            $v = trim((string) ($row[$col[$k]] ?? ''));
            if ($v === '') {
                continue;
            }
            return function_exists('kit_parse_sheet_int') ? kit_parse_sheet_int($v, 0) : (int) preg_replace('/[^0-9]/', '', $v);
        }
        return 0;
    }
}
