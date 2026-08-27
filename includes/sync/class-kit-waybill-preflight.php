<?php
/**
 * Pre-flight structural audit of the kit_waybills sheet, run BEFORE any write.
 *
 * WHY
 * ---
 * KIT_Waybill_Verifier catches a bad seed after the fact. That is too late when
 * the sheet itself is structurally wrong: by the time verification runs, good DB
 * values have already been overwritten with garbage.
 *
 * Measured on the live sheet (1034 waybills present in both sheet and DB):
 *
 *   - column `parcel_id`        held the waybill number in 100% of rows
 *   - column `approval`         held the waybill number in  86% of rows
 *   - column `approval_userid`  held the waybill number in  86% of rows
 *   - column `last_updated_by`  held the waybill number in  86% of rows
 *
 * `approval` is an ENUM('approved','pending','cancelled','rejected','completed').
 * Writing "4618" into it does not error — MySQL coerces it to '' in the default
 * non-strict mode. That is precisely the reported symptom: "when he runs the
 * script it pulls out the wrong information from the spreadsheet".
 *
 * WHAT IT CHECKS
 * --------------
 *   1. Column pollution — a sheet column whose value equals the row's own
 *      waybill number across a large share of rows is not real data. It is a
 *      dragged formula or a bad export, and seeding it destroys the DB copy.
 *   2. ENUM domain — every value bound for an ENUM column must be a member of
 *      that ENUM, read live from the DB schema (never hardcoded).
 *   3. DECIMAL range — a value larger than the column can hold is clamped
 *      silently by MySQL; that is data corruption, so it blocks.
 *   4. DECIMAL precision — a value with more decimal places than the column's
 *      scale gets rounded (total_volume DECIMAL(10,2) turns 0.08649 into 0.09).
 *      Reported as a warning with the scale the data actually needs, because it
 *      is a schema problem to fix deliberately, not a reason to block a run.
 *
 * Blocking failures abort the seed before the first write. Set the
 * kit_waybill_preflight_block option to 0 to downgrade them to warnings.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Waybill_Preflight
{
    /** When on (default), a blocking finding aborts the seed before any write. */
    const BLOCK_OPTION = 'kit_waybill_preflight_block';

    /** Last report, for the admin panel. */
    const LAST_REPORT_OPTION = 'kit_waybill_preflight_last_report';

    /**
     * Share of rows where a column must equal its own waybill number before we
     * call the column polluted. Set well above incidental collisions (a genuine
     * customer_id that happens to equal a waybill number) but below the ~86%
     * and 100% rates seen on the live sheet.
     */
    const POLLUTION_THRESHOLD = 0.25;

    public static function is_blocking(): bool
    {
        return (bool) get_option(self::BLOCK_OPTION, 1);
    }

    public static function set_blocking(bool $on): void
    {
        update_option(self::BLOCK_OPTION, $on ? 1 : 0, false);
    }

    public static function last_report(): array
    {
        $r = get_option(self::LAST_REPORT_OPTION, []);
        return is_array($r) ? $r : [];
    }

    /**
     * @param array<int, array> $rows sheet rows including header
     * @return array{passed:bool, blocking:array, warnings:array, counts:array, message:string, checked_at:string}
     */
    public static function check(array $rows): array
    {
        $blocking = [];
        $warnings = [];
        $counts = ['sheet_rows' => 0, 'columns_checked' => 0];

        if (count($rows) < 2) {
            return self::finalize($blocking, $warnings, $counts, 'Pre-flight: no data rows to check.');
        }

        $header = KIT_Waybill_Seeder::normalize_header($rows[0]);
        $col = KIT_Waybill_Seeder::header_to_col_map($header);
        $fields = KIT_Waybill_Seeder::sheet_owned_fields();
        $schema = self::column_schema();
        $counts['columns_checked'] = count($fields);

        // Are we even pointed at the right document? Until 2026-08-05 the plugin
        // read a spreadsheet titled "08600BackUp" — a backup copy holding 1040
        // waybills against the live sheet's 1132, so 98 waybills could never
        // arrive however often the seed ran. A wrong-document swap is silent
        // otherwise: every row still parses, the seed still "succeeds".
        $title = self::spreadsheet_title();
        if ($title !== '' && preg_match('/\b(backup|back\s*up|copy|old|archive|test)\b/i', $title)) {
            $blocking[] = [
                'check'  => 'suspect_spreadsheet',
                'column' => '(document)',
                'detail' => sprintf(
                    'The configured spreadsheet is titled "%s", which reads as a backup or copy rather than the live '
                    . 'document. Seeding from a stale copy silently drops whatever it does not contain. Set '
                    . 'COURIER_GOOGLE_SPREADSHEET_ID in wp-config.php to the live sheet if this is wrong.',
                    $title
                ),
            ];
        }

        $wb_idx = null;
        foreach (['waybill_no', 'waybillno', 'waybill_number', 'wb_no'] as $k) {
            if (isset($col[$k])) { $wb_idx = $col[$k]; break; }
        }
        if ($wb_idx === null) {
            $blocking[] = [
                'check'  => 'structure',
                'column' => 'waybill_no',
                'detail' => 'The sheet has no waybill_no column — nothing can be matched to the DB.',
            ];
            return self::finalize($blocking, $warnings, $counts, 'Pre-flight FAILED: no waybill_no column.');
        }

        // Per-column tallies over the whole sheet.
        $pollution = array_fill_keys($fields, 0);
        $enum_bad = [];
        $varchar_bad = [];
        $range_bad = [];
        $scale_needed = [];
        $rows_with_wb = 0;

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $counts['sheet_rows']++;
            $wb = trim((string) ($row[$wb_idx] ?? ''));
            if ($wb === '') {
                continue;
            }
            $rows_with_wb++;

            $mapped = KIT_Waybill_Seeder::map_sheet_row_to_db_payload($row, $col);
            $payload = $mapped['update_payload'];

            // waybill_no is the join key, not a sheet-owned column, so it is not
            // in the loop below — but an oversized value cannot be inserted at
            // all, which is how a row silently never reaches the DB.
            $wb_normalized = KIT_Waybill_Seeder::normalize_waybill_no($wb);
            if (isset($schema['waybill_no']) && $schema['waybill_no']['precision'] > 0
                && mb_strlen($wb_normalized) > $schema['waybill_no']['precision']) {
                $varchar_bad['waybill_no'][] = [
                    'waybill_no' => mb_strimwidth($wb, 0, 40, '…'),
                    'source_row' => $r + 1,
                    'len'        => mb_strlen($wb),
                    'max'        => $schema['waybill_no']['precision'],
                    'value'      => mb_strimwidth($wb, 0, 60, '…'),
                ];
            }

            foreach ($fields as $field) {
                if (!isset($col[$field])) {
                    continue;
                }
                $raw = trim((string) ($row[$col[$field]] ?? ''));

                // 1. Pollution: the cell literally repeats the row's waybill number.
                if ($raw !== '' && $raw === $wb) {
                    $pollution[$field]++;
                }

                if (!isset($schema[$field])) {
                    continue;
                }
                $meta = $schema[$field];
                $value = $payload[$field] ?? null;

                // 2. ENUM domain.
                if ($meta['type'] === 'enum' && $value !== null && $value !== '') {
                    if (!in_array((string) $value, $meta['allowed'], true)) {
                        $enum_bad[$field][] = ['waybill_no' => $wb, 'source_row' => $r + 1, 'value' => (string) $value];
                    }
                }

                // 3. VARCHAR overflow — MySQL truncates silently in non-strict mode.
                if ($meta['type'] === 'varchar' && $meta['precision'] > 0 && $value !== null) {
                    $len = mb_strlen((string) $value);
                    if ($len > $meta['precision']) {
                        $varchar_bad[$field][] = [
                            'waybill_no' => $wb,
                            'source_row' => $r + 1,
                            'len'        => $len,
                            'max'        => $meta['precision'],
                            'value'      => mb_strimwidth((string) $value, 0, 60, '…'),
                        ];
                    }
                }

                // 4 & 5. DECIMAL range and scale.
                if ($meta['type'] === 'decimal' && is_numeric($value)) {
                    $max = pow(10, $meta['precision'] - $meta['scale']) - pow(10, -$meta['scale']);
                    if (abs((float) $value) > $max) {
                        $range_bad[$field][] = ['waybill_no' => $wb, 'source_row' => $r + 1, 'value' => (string) $value, 'max' => $max];
                    }
                    $needed = self::decimals_in((string) $raw);
                    if ($needed > $meta['scale']) {
                        if (!isset($scale_needed[$field])) {
                            $scale_needed[$field] = ['rows' => 0, 'max_decimals' => 0, 'example' => '', 'scale' => $meta['scale'], 'type' => $meta['raw']];
                        }
                        $scale_needed[$field]['rows']++;
                        if ($needed > $scale_needed[$field]['max_decimals']) {
                            $scale_needed[$field]['max_decimals'] = $needed;
                            $scale_needed[$field]['example'] = $raw . ' -> ' . number_format((float) $value, $meta['scale'], '.', '');
                        }
                    }
                }
            }
        }

        // ---- Referential integrity ------------------------------------------
        // The sheet is the source of truth, so a customer_id it names must
        // already exist in the DB by the time waybills are seeded. When it does
        // not, the waybill lands on a customer that is missing or — worse — on
        // whatever row happens to hold that id, which is how one client's
        // freight ends up split across several billing records.
        //
        // Measured on the live data: 869 of 1132 waybill rows named a
        // customer_id absent from wp_kit_customers. All 229 distinct ids were
        // present in the kit_customers SHEET tab, so the fix is ordering —
        // customers must be seeded before waybills, not a data correction.
        $ref = self::check_customer_references($rows, $col, $wb_idx);
        if ($ref['missing_rows'] > 0) {
            $blocking[] = [
                'check'  => 'missing_customers',
                'column' => 'customer_id',
                'rows'   => $ref['missing_rows'],
                'detail' => sprintf(
                    '%d waybill row(s) reference %d customer_id(s) that do not exist in the database yet (e.g. %s). '
                    . 'Seed customers from the kit_customers sheet FIRST — seeding waybills now splits these clients '
                    . 'across records.',
                    $ref['missing_rows'],
                    count($ref['missing_ids']),
                    implode(', ', array_slice($ref['missing_ids'], 0, 10))
                ),
            ];
        }

        // ---- Turn tallies into findings -------------------------------------
        foreach ($pollution as $field => $hits) {
            if ($rows_with_wb > 0 && ($hits / $rows_with_wb) >= self::POLLUTION_THRESHOLD) {
                $blocking[] = [
                    'check'  => 'column_pollution',
                    'column' => $field,
                    'rows'   => $hits,
                    'pct'    => round($hits / $rows_with_wb * 100, 1),
                    'detail' => sprintf(
                        'Sheet column "%s" contains the row\'s own waybill number in %d of %d rows (%.1f%%). '
                        . 'That is not real data — seeding it would overwrite the DB value with a waybill number.',
                        $field, $hits, $rows_with_wb, $hits / $rows_with_wb * 100
                    ),
                ];
            }
        }

        foreach ($enum_bad as $field => $hits) {
            $sample = array_slice($hits, 0, 3);
            $blocking[] = [
                'check'   => 'enum_domain',
                'column'  => $field,
                'rows'    => count($hits),
                'allowed' => $schema[$field]['allowed'],
                'detail'  => sprintf(
                    '%d row(s) carry a value "%s" is not allowed to hold. Allowed: %s. MySQL silently coerces these to an empty value. e.g. %s',
                    count($hits),
                    $field,
                    implode(', ', $schema[$field]['allowed']),
                    implode('; ', array_map(fn($h) => 'wb ' . $h['waybill_no'] . ' = "' . $h['value'] . '"', $sample))
                ),
            ];
        }

        foreach ($varchar_bad as $field => $hits) {
            $blocking[] = [
                'check'  => 'text_too_long',
                'column' => $field,
                'rows'   => count($hits),
                'detail' => sprintf(
                    '%d row(s) hold text too long for %s (max %d chars). MySQL truncates silently, and an oversized '
                    . 'waybill_no cannot be inserted at all — the row never reaches the DB. e.g. sheet row %d has %d '
                    . 'chars: "%s"',
                    count($hits), $field, $hits[0]['max'], $hits[0]['source_row'], $hits[0]['len'], $hits[0]['value']
                ),
            ];
        }

        foreach ($range_bad as $field => $hits) {
            $warnings[] = [
                'check'  => 'decimal_range',
                'column' => $field,
                'rows'   => count($hits),
                'detail' => sprintf(
                    '%d row(s) exceed what %s can store (max %s) — the seeder writes 0 for these rather than letting '
                    . 'MySQL clamp them silently, and the rest of the row still lands. e.g. wb %s = %s',
                    count($hits), $field, (string) $hits[0]['max'], $hits[0]['waybill_no'], $hits[0]['value']
                ),
            ];
        }

        foreach ($scale_needed as $field => $info) {
            $warnings[] = [
                'check'  => 'decimal_precision',
                'column' => $field,
                'rows'   => $info['rows'],
                'detail' => sprintf(
                    '%d row(s) carry more decimal places than %s (%s) can keep — values are rounded on write. '
                    . 'Data needs %d decimals. e.g. %s. Fix by widening the column, not by changing the sheet.',
                    $info['rows'], $field, $info['type'], $info['max_decimals'], $info['example']
                ),
            ];
        }

        $passed = empty($blocking);
        $message = $passed
            ? sprintf('Pre-flight PASSED: %d row(s) checked, %d warning(s).', $counts['sheet_rows'], count($warnings))
            : sprintf(
                'Pre-flight FAILED: %d blocking problem(s) in the sheet, %d warning(s). Seed aborted before writing.',
                count($blocking),
                count($warnings)
            );

        return self::finalize($blocking, $warnings, $counts, $message);
    }

    /**
     * Which customer_ids named by the waybills sheet are not yet in the DB?
     * Accepts a match in either wp_kit_customers or wp_kit_company_customers,
     * since a waybill may be billed to a company rather than an individual.
     *
     * @return array{missing_rows:int, missing_ids:array<int,int>}
     */
    private static function check_customer_references(array $rows, array $col, int $wb_idx): array
    {
        global $wpdb;

        $cust_idx = null;
        foreach (['customer_id', 'cust_id'] as $k) {
            if (isset($col[$k])) { $cust_idx = $col[$k]; break; }
        }
        if ($cust_idx === null) {
            return ['missing_rows' => 0, 'missing_ids' => []];
        }

        $known = [];
        foreach ((array) $wpdb->get_col("SELECT cust_id FROM {$wpdb->prefix}kit_customers") as $v) {
            $known[(int) $v] = true;
        }
        $companies_table = $wpdb->prefix . 'kit_company_customers';
        $has_companies = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $companies_table
        ));
        if ((int) $has_companies > 0) {
            foreach ((array) $wpdb->get_col("SELECT company_id FROM {$companies_table}") as $v) {
                $known[(int) $v] = true;
            }
        }

        $missing_rows = 0;
        $missing_ids = [];
        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            if (trim((string) ($row[$wb_idx] ?? '')) === '') {
                continue;
            }
            $raw = trim((string) ($row[$cust_idx] ?? ''));
            if ($raw === '') {
                continue;
            }
            $cid = function_exists('kit_parse_sheet_int') ? kit_parse_sheet_int($raw, 0) : (int) $raw;
            if ($cid <= 0 || isset($known[$cid])) {
                continue;
            }
            $missing_rows++;
            $missing_ids[$cid] = true;
        }

        return ['missing_rows' => $missing_rows, 'missing_ids' => array_keys($missing_ids)];
    }

    /**
     * Live column metadata for wp_kit_waybills. Read from the DB, never
     * hardcoded, so a schema change cannot leave this check validating against
     * a stale definition.
     *
     * @return array<string, array{type:string, raw:string, allowed:array, precision:int, scale:int}>
     */
    private static function column_schema(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_waybills';
        $out = [];

        $cols = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if (!is_array($cols)) {
            return $out;
        }

        foreach ($cols as $c) {
            $field = $c['Field'];
            $type = strtolower((string) $c['Type']);
            $entry = ['type' => 'other', 'raw' => $type, 'allowed' => [], 'precision' => 0, 'scale' => 0];

            if (strpos($type, 'enum(') === 0) {
                $entry['type'] = 'enum';
                if (preg_match_all("/'((?:[^']|'')*)'/", $type, $m)) {
                    $entry['allowed'] = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
                }
            } elseif (preg_match('/^decimal\((\d+),(\d+)\)/', $type, $m)) {
                $entry['type'] = 'decimal';
                $entry['precision'] = (int) $m[1];
                $entry['scale'] = (int) $m[2];
            } elseif (preg_match('/^varchar\((\d+)\)/', $type, $m)) {
                $entry['type'] = 'varchar';
                $entry['precision'] = (int) $m[1];
            }

            $out[$field] = $entry;
        }

        return $out;
    }

    /**
     * Title of the spreadsheet actually being read. Empty when it cannot be
     * determined — never a failure on its own, since the title is only a hint.
     */
    private static function spreadsheet_title(): string
    {
        if (!class_exists('Courier_Google_Sheets') || !Courier_Google_Sheets::is_configured()) {
            return '';
        }
        try {
            $id = Courier_Google_Sheets::get_default_spreadsheet_id();
            if (!is_string($id) || $id === '') {
                return '';
            }
            $meta = Courier_Google_Sheets::get_service()->spreadsheets->get($id);
            return (string) $meta->getProperties()->getTitle();
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Decimal places present in a raw cell, ignoring thousands separators. */
    private static function decimals_in(string $raw): int
    {
        $s = preg_replace('/[^\d.,]/u', '', trim($raw));
        if ($s === '' || $s === null) {
            return 0;
        }
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        $sep = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);
        if ($sep < 0) {
            return 0;
        }
        $tail = substr($s, $sep + 1);
        // A 3-digit tail after a lone separator is a thousands group, not decimals.
        if (strlen($tail) === 3 && substr_count($s, '.') + substr_count($s, ',') === 1) {
            return 0;
        }
        return strlen(preg_replace('/\D/', '', $tail));
    }

    private static function finalize(array $blocking, array $warnings, array $counts, string $message): array
    {
        $report = [
            'passed'     => empty($blocking),
            'blocking'   => $blocking,
            'warnings'   => $warnings,
            'counts'     => $counts,
            'message'    => $message,
            'checked_at' => current_time('mysql'),
        ];
        update_option(self::LAST_REPORT_OPTION, $report, false);
        return $report;
    }
}
