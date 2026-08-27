<?php
/**
 * Post-seed verification gate: wp_kit_waybills MUST match the kit_waybills sheet.
 *
 * THE RULE
 * --------
 * A seed run is NOT successful until every waybill row in the sheet has been
 * read back out of the database and confirmed field-for-field. "The seeder
 * reported 0 errors" is not proof — wpdb->insert can succeed while MySQL
 * silently truncates a VARCHAR, coerces an out-of-range ENUM to '', or rounds a
 * DECIMAL. Those are exactly the cases where the DB "looks different from the
 * Google Sheet" even though the sync claimed success.
 *
 * So KIT_Waybill_Seeder::run() now finishes with verify(), and the run is
 * marked failed unless verification is clean.
 *
 * WHAT COUNTS AS A FAILURE
 * ------------------------
 *   missing        — waybill is in the sheet, absent from wp_kit_waybills
 *   mismatch       — waybill is in both, but a sheet-owned column differs
 *   sheet_dupe     — same waybill_no appears twice in the sheet (last write wins,
 *                    so one of the two rows is silently lost)
 *   db_dupe        — same waybill_no on two DB rows (breaks upsert-on-waybill_no)
 *   orphan         — in the DB, not in the sheet. Reported ALWAYS, but only fails
 *                    the run when the strict-orphans option is on, because a
 *                    waybill legitimately created in the WP admin is an orphan
 *                    by definition and must not break a sheet sync.
 *
 * WHAT IS COMPARED
 * ----------------
 * Exactly KIT_Waybill_Seeder::sheet_owned_fields() — the columns the sheet is
 * authoritative for. WP-owned columns (status, tracking_number,
 * product_invoice_number, audit columns) are deliberately NOT compared: the
 * seeder never writes them on update, so a difference there is correct
 * behaviour, not drift.
 *
 * Comparison reuses the seeder's own mapping + equality helpers on purpose, so
 * a value the seeder deliberately normalises does not generate noise. Every
 * reported mismatch carries the RAW sheet cell text next to the DB value, so a
 * human sees the literal difference rather than a normalised one.
 *
 * The one place it refuses to trust the seeder is numbers: because the Sheets
 * API returns FORMATTED values, money and measurement cells arrive as display
 * text ("R 1 200,50") and a naive parse can store 120050 while both sides of a
 * same-parser comparison happily agree. Those columns get an independent,
 * separator-aware re-read — see parse_number_independently().
 *
 * NOT the same thing as includes/seed/seed-verification.php
 * --------------------------------------------------------
 * That file verifies the LEGACY Setup Seed path (run_google_sheet_seed in
 * settings.php) and asks different questions: does the right customer/company
 * own each waybill, and does the freight total agree with the charge_basis
 * rule. This class asks "is every sheet-owned column in wp_kit_waybills
 * byte-for-byte what the sheet says" for the NEW KIT_Waybill_Seeder path.
 * They are complementary — keep both.
 *
 * Tested by tests-waybill-verifier.php in the plugin root (`php tests-waybill-verifier.php`).
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Waybill_Verifier
{
    /** When on, a DB waybill missing from the sheet fails the run. Off by default. */
    const STRICT_ORPHANS_OPTION = 'kit_waybill_verify_strict_orphans';

    /** Last report, for the admin panel. Non-autoloaded. */
    const LAST_REPORT_OPTION = 'kit_waybill_verify_last_report';

    /** Cap on per-row audit rows written per verification, so a totally broken run can't write 5000 log rows. */
    const MAX_LOGGED_FAILURES = 500;

    /** Cap on failures retained in the stored report for the admin UI. */
    const MAX_REPORTED_FAILURES = 200;

    public static function is_strict_orphans(): bool
    {
        return (bool) get_option(self::STRICT_ORPHANS_OPTION, 0);
    }

    public static function set_strict_orphans(bool $on): void
    {
        update_option(self::STRICT_ORPHANS_OPTION, $on ? 1 : 0, false);
    }

    public static function last_report(): array
    {
        $report = get_option(self::LAST_REPORT_OPTION, []);
        return is_array($report) ? $report : [];
    }

    /**
     * Verify wp_kit_waybills against the kit_waybills sheet.
     *
     * @param array<int, array>|null $rows    Sheet rows including header. Pass the snapshot the
     *                                       seeder just wrote from (so a concurrent edit to the
     *                                       sheet mid-run can't produce a phantom failure). Pass
     *                                       null for a standalone check — it re-reads the sheet.
     * @param int                    $run_id  Sync-run id to attach per-row audit entries to (0 = none).
     * @param string                 $context 'post_seed' | 'standalone'
     *
     * @return array{passed:bool, counts:array<string,int>, failures:array<int,array>, orphans:array<int,string>, message:string, checked_at:string, context:string}
     */
    public static function verify($rows = null, int $run_id = 0, string $context = 'standalone'): array
    {
        global $wpdb;

        $counts = [
            'sheet_rows' => 0,
            'verified'   => 0,
            'missing'    => 0,
            'mismatch'   => 0,
            'sheet_dupe' => 0,
            'db_dupe'    => 0,
            'orphan'     => 0,
            'skipped'    => 0,
        ];
        $failures = [];
        $orphans  = [];

        if ($rows === null) {
            $read = self::read_sheet();
            if ($read['error'] !== '') {
                return self::finalize($counts, $failures, $orphans, $context, $run_id, $read['error'], false);
            }
            $rows = $read['rows'];
        }

        if (!is_array($rows) || count($rows) < 2) {
            return self::finalize($counts, $failures, $orphans, $context, $run_id, 'Sheet returned no data rows to verify.', true);
        }

        $header = KIT_Waybill_Seeder::normalize_header($rows[0]);
        $col    = KIT_Waybill_Seeder::header_to_col_map($header);
        $fields = KIT_Waybill_Seeder::sheet_owned_fields();

        // ---- Build the expected state from the sheet -------------------------
        $expected_by_waybill = [];
        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $source_row = $r + 1;
            $counts['sheet_rows']++;

            $mapped = KIT_Waybill_Seeder::map_sheet_row_to_db_payload($row, $col);
            $waybill_no = $mapped['waybill_no'];

            if ($waybill_no === '') {
                // Same rows the seeder skipped. Not a verification failure.
                $counts['skipped']++;
                continue;
            }

            if (isset($expected_by_waybill[$waybill_no])) {
                $counts['sheet_dupe']++;
                $failures[] = [
                    'type'       => 'sheet_dupe',
                    'waybill_no' => $waybill_no,
                    'source_row' => $source_row,
                    'detail'     => sprintf(
                        'Waybill %s appears on sheet rows %d and %d — the later row overwrites the earlier one, so one is silently lost.',
                        $waybill_no,
                        $expected_by_waybill[$waybill_no]['source_row'],
                        $source_row
                    ),
                    'fields'     => [],
                ];
                // Later row wins in the DB (that is what the seeder does), so keep it.
            }

            $expected_by_waybill[$waybill_no] = [
                'source_row' => $source_row,
                'expected'   => $mapped['update_payload'],
                'raw'        => $row,
            ];
        }

        // ---- Load the actual DB state ---------------------------------------
        $table = $wpdb->prefix . 'kit_waybills';
        $select_cols = array_merge(['id', 'waybill_no'], $fields);
        $select_cols = array_map(function ($c) {
            return '`' . str_replace('`', '', $c) . '`';
        }, $select_cols);

        $db_rows = $wpdb->get_results(
            'SELECT ' . implode(', ', $select_cols) . " FROM {$table}",
            ARRAY_A
        );
        if (!is_array($db_rows)) {
            return self::finalize(
                $counts,
                $failures,
                $orphans,
                $context,
                $run_id,
                'Could not read ' . $table . ' for verification: ' . $wpdb->last_error,
                false
            );
        }

        $db_by_waybill = [];
        foreach ($db_rows as $db_row) {
            $wb = trim((string) ($db_row['waybill_no'] ?? ''));
            if ($wb === '') {
                continue;
            }
            if (isset($db_by_waybill[$wb])) {
                $counts['db_dupe']++;
                $failures[] = [
                    'type'       => 'db_dupe',
                    'waybill_no' => $wb,
                    'source_row' => $expected_by_waybill[$wb]['source_row'] ?? null,
                    'detail'     => sprintf(
                        'Waybill %s exists on two DB rows (id %d and id %d) — upsert-on-waybill_no cannot tell them apart.',
                        $wb,
                        (int) $db_by_waybill[$wb]['id'],
                        (int) $db_row['id']
                    ),
                    'fields'     => [],
                ];
                continue;
            }
            $db_by_waybill[$wb] = $db_row;
        }

        // ---- Compare, sheet row by sheet row --------------------------------
        foreach ($expected_by_waybill as $waybill_no => $entry) {
            if (!isset($db_by_waybill[$waybill_no])) {
                $counts['missing']++;
                $failures[] = [
                    'type'       => 'missing',
                    'waybill_no' => $waybill_no,
                    'source_row' => $entry['source_row'],
                    'detail'     => 'On the sheet but not in wp_kit_waybills.',
                    'fields'     => [],
                ];
                continue;
            }

            $db_row = $db_by_waybill[$waybill_no];
            $field_diffs = self::compare_fields($entry['expected'], $db_row, $entry['raw'], $col, $fields);

            if ($field_diffs === []) {
                $counts['verified']++;
                continue;
            }

            $counts['mismatch']++;
            $failures[] = [
                'type'       => 'mismatch',
                'waybill_no' => $waybill_no,
                'source_row' => $entry['source_row'],
                'db_id'      => (int) $db_row['id'],
                'detail'     => sprintf('%d sheet-owned column(s) differ from the sheet.', count($field_diffs)),
                'fields'     => $field_diffs,
            ];
        }

        // ---- Orphans: in the DB, not on the sheet ---------------------------
        foreach ($db_by_waybill as $wb => $db_row) {
            if (!isset($expected_by_waybill[$wb])) {
                $counts['orphan']++;
                if (count($orphans) < self::MAX_REPORTED_FAILURES) {
                    $orphans[] = $wb;
                }
            }
        }

        $strict = self::is_strict_orphans();
        $passed = ($counts['missing'] === 0)
            && ($counts['mismatch'] === 0)
            && ($counts['sheet_dupe'] === 0)
            && ($counts['db_dupe'] === 0)
            && (!$strict || $counts['orphan'] === 0);

        $message = sprintf(
            'Verification %s: %d sheet row(s), %d verified, %d missing, %d mismatched, %d sheet duplicate(s), %d DB duplicate(s), %d orphan(s)%s, %d skipped (no waybill_no).',
            $passed ? 'PASSED' : 'FAILED',
            $counts['sheet_rows'],
            $counts['verified'],
            $counts['missing'],
            $counts['mismatch'],
            $counts['sheet_dupe'],
            $counts['db_dupe'],
            $counts['orphan'],
            $strict ? ' (strict: counted as failures)' : ' (reported only)',
            $counts['skipped']
        );

        return self::finalize($counts, $failures, $orphans, $context, $run_id, $message, $passed);
    }

    /**
     * Field-level comparison for one waybill.
     *
     * @param array<string, mixed> $expected  normalised values the sheet implies
     * @param array<string, mixed> $db_row    the row as MySQL actually stored it
     * @param array<int, mixed>    $raw_row   the raw sheet row, for showing literal cell text
     * @param array<string, int>   $col       header → column index
     * @param array<int, string>   $fields    sheet-owned field list
     * @return array<int, array{field:string, sheet_raw:string, expected:mixed, db:mixed}>
     */
    private static function compare_fields(array $expected, array $db_row, $raw_row, array $col, array $fields): array
    {
        $insert_defaults = KIT_Waybill_Seeder::insert_defaults_when_empty();
        $diffs = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $expected)) {
                continue;
            }
            if (!array_key_exists($field, $db_row)) {
                // Column in the ownership list but not in this DB schema — schema drift.
                $diffs[] = [
                    'field'     => $field,
                    'sheet_raw' => self::raw_cell($raw_row, $col, $field),
                    'expected'  => $expected[$field],
                    'db'        => '(column not present in wp_kit_waybills)',
                ];
                continue;
            }

            $expected_value = $expected[$field];
            $db_value = $db_row[$field];
            $sheet_raw = self::raw_cell($raw_row, $col, $field);

            if (!KIT_Waybill_Seeder::values_differ($db_value, $expected_value)) {
                // Agreement here only proves the seeder's parse round-tripped —
                // both sides went through the same to_decimal(). For money and
                // measurements that is not enough: the Sheets API returns
                // FORMATTED values, so "R 1 200,50" reaches us as text and a
                // naive strip-non-digits turns it into 120050. Re-read the raw
                // cell with an independent, separator-aware parser and flag it
                // when the stored number is not what the cell actually says.
                $independent = self::parse_number_independently($sheet_raw);
                if ($independent !== null
                    && abs($independent) <= KIT_Waybill_Seeder::MAX_DECIMAL
                    && in_array($field, self::$numeric_sanity_fields, true)
                    && KIT_Waybill_Seeder::values_differ($db_value, $independent)) {
                    $diffs[] = [
                        'field'     => $field,
                        'sheet_raw' => $sheet_raw,
                        'expected'  => $independent,
                        'db'        => $db_value,
                        'note'      => 'Number formatting lost in parsing — the sheet cell reads '
                            . $independent . ' but the DB holds ' . $db_value . '.',
                    ];
                }
                continue;
            }

            // The seeder replaces impossible numbers with 0 on purpose (one live
            // row carries an item_height of 9.19e14). A zero standing in for a
            // rejected value is the writer doing its job, not drift.
            if (is_numeric($expected_value)
                && abs((float) $expected_value) > KIT_Waybill_Seeder::MAX_DECIMAL
                && (float) $db_value === 0.0) {
                continue;
            }

            // A column the seeder force-fills on insert is allowed to hold that
            // default when the sheet cell was empty/zero — that is the writer
            // doing its job, not the DB drifting from the sheet.
            if (isset($insert_defaults[$field])
                && (int) $expected_value <= 0
                && !KIT_Waybill_Seeder::values_differ($db_value, $insert_defaults[$field])) {
                continue;
            }

            $diffs[] = [
                'field'     => $field,
                'sheet_raw' => $sheet_raw,
                'expected'  => $expected_value,
                'db'        => $db_value,
            ];
        }

        return $diffs;
    }

    /**
     * Money / measurement columns where the sheet's display formatting can be
     * destroyed by naive parsing, so the raw cell gets an independent re-read.
     * ID and flag columns are excluded — they are never currency-formatted, and
     * re-parsing a checkbox as a number only creates noise.
     */
    private static $numeric_sanity_fields = [
        'waybill_items_total',
        'sad500_amount',
        'sadc_amount',
        'item_length',
        'item_width',
        'item_height',
        'total_mass_kg',
        'total_volume',
        'mass_charge',
        'volume_charge',
    ];

    /**
     * Separator-aware number parse of a raw sheet cell, deliberately NOT sharing
     * code with KIT_Waybill_Seeder::to_decimal() — the whole point is to disagree
     * with the writer when the writer got it wrong.
     *
     * Handles the shapes Google Sheets actually emits for formatted numbers:
     *   "R 1 200,50"  "R1,200.50"  "1 200.50"  "1.200,50"  "-R 45,00"  "340"
     *
     * Returns null when the cell holds no digits at all (empty, "TRUE", "N/A"),
     * meaning "cannot independently determine" — never a failure.
     *
     * Intentionally duplicates the semantics of kit_parse_sheet_number() rather
     * than calling it: a verifier that shares the writer's parser cannot catch
     * the writer's parsing bugs. tests-waybill-verifier.php asserts the two
     * implementations agree across a corpus, so they cannot drift apart unnoticed.
     */
    private static function parse_number_independently(string $raw): ?float
    {
        $s = trim($raw);
        if ($s === '' || !preg_match('/\d/', $s)) {
            return null;
        }

        // Sign only at the front ("-45", "R -45", "(45)"), so an embedded hyphen
        // in a reference-like value ("12-34") is not misread as a minus.
        $negative = (bool) preg_match('/^\(.*\)$/', $s) || (bool) preg_match('/^[^\d]*-/', $s);

        // Drop everything that is not a digit or a separator: currency symbols,
        // letters, ordinary spaces, and the non-breaking space Sheets emits.
        $s = preg_replace('/[^\d.,]/u', '', $s);
        if ($s === '' || $s === null) {
            return null;
        }

        $last_dot = strrpos($s, '.');
        $last_comma = strrpos($s, ',');

        if ($last_dot !== false && $last_comma !== false) {
            // Both present — whichever comes last is the decimal separator.
            if ($last_comma > $last_dot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($last_comma !== false) {
            // Comma only. A single comma with 1-2 trailing digits is a decimal
            // comma (1 200,50); anything else is a thousands separator (1,200).
            $tail = strlen($s) - $last_comma - 1;
            if (substr_count($s, ',') === 1 && $tail >= 1 && $tail <= 2) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($last_dot !== false) {
            // Dot only. Multiple dots can only be thousands separators (1.200.000).
            if (substr_count($s, '.') > 1) {
                $s = str_replace('.', '', $s);
            }
            // A single dot stays a decimal point — the standard reading.
        }

        if (!is_numeric($s)) {
            return null;
        }

        $value = (float) $s;
        return $negative ? -abs($value) : $value;
    }

    /**
     * The literal cell text behind a mapped field, so the report can show what
     * the client actually typed rather than only the normalised value.
     */
    private static function raw_cell($raw_row, array $col, string $field): string
    {
        // Mirrors the alias sets in KIT_Waybill_Seeder::map_sheet_row_to_db_payload().
        $aliases = [
            'description'            => ['description', 'waybill_description', 'item_description'],
            'direction_id'           => ['direction_id'],
            'city_id'                => ['city_id', 'destination_city_id'],
            'delivery_id'            => ['delivery_id'],
            'customer_id'            => ['customer_id', 'cust_id'],
            'approval'               => ['approval'],
            'approval_userid'        => ['approval_userid', 'approval_user_id'],
            // Sheet product_invoice_amount is the parcels total -> waybill_items_total.
            'waybill_items_total'    => ['waybill_items_total', 'product_invoice_amount'],
            // DB product_invoice_amount is derived from the charge columns, so the
            // closest literal cell to show a human is the basis-selected charge.
            'product_invoice_amount' => ['mass_charge', 'volume_charge'],
            'sad500_amount'          => ['sad500_amount', 'sad500'],
            'sadc_amount'            => ['sadc_amount', 'sadc'],
            'item_length'            => ['item_length', 'length'],
            'item_width'             => ['item_width', 'width'],
            'item_height'            => ['item_height', 'height'],
            'total_mass_kg'          => ['total_mass_kg', 't_mass'],
            'total_volume'           => ['total_volume', 't_volume'],
            'mass_charge'            => ['mass_charge'],
            'volume_charge'          => ['volume_charge'],
            'charge_basis'           => ['charge_basis', 'basis'],
            'vat_include'            => ['vat_include', 'vat_included', 'vat'],
            'include_sad500'         => ['include_sad500', 'sad500'],
            'include_sadc'           => ['include_sadc', 'sadc'],
            'warehouse'              => ['warehouse'],
            'miscellaneous'          => ['miscellaneous'],
        ];

        $keys = $aliases[$field] ?? [$field];
        return KIT_Waybill_Seeder::cell($raw_row, $col, $keys);
    }

    /**
     * Fresh read of the kit_waybills sheet, using the same sheet/range the
     * seeder reads. Used by the standalone "Verify now" path.
     *
     * @return array{rows:array, error:string}
     */
    private static function read_sheet(): array
    {
        if (!class_exists('Courier_Google_Sheets')) {
            return ['rows' => [], 'error' => 'Courier_Google_Sheets class missing — cannot read sheet to verify.'];
        }
        if (!Courier_Google_Sheets::is_configured()) {
            return ['rows' => [], 'error' => 'Google Sheets not configured (credentials JSON missing) — cannot verify.'];
        }

        $sheet_name = defined('COURIER_GOOGLE_SEED_SHEET_NAME')
            ? COURIER_GOOGLE_SEED_SHEET_NAME
            : KIT_Waybill_Seeder::DEFAULT_SHEET_NAME;
        $range = defined('COURIER_GOOGLE_SEED_RANGE')
            ? COURIER_GOOGLE_SEED_RANGE
            : KIT_Waybill_Seeder::DEFAULT_SHEET_RANGE;

        try {
            $rows = Courier_Google_Sheets::get_values('', $range, $sheet_name);
        } catch (Throwable $e) {
            return ['rows' => [], 'error' => 'Sheet read failed during verification: ' . $e->getMessage()];
        }

        return ['rows' => is_array($rows) ? $rows : [], 'error' => ''];
    }

    /**
     * Write the audit rows, persist the report for the admin panel, and return it.
     *
     * @param array<string,int>   $counts
     * @param array<int,array>    $failures
     * @param array<int,string>   $orphans
     */
    private static function finalize(array $counts, array $failures, array $orphans, string $context, int $run_id, string $message, bool $passed): array
    {
        $report = [
            'passed'     => $passed,
            'counts'     => $counts,
            'failures'   => array_slice($failures, 0, self::MAX_REPORTED_FAILURES),
            'orphans'    => $orphans,
            'message'    => $message,
            'checked_at' => current_time('mysql'),
            'context'    => $context,
            'run_id'     => $run_id,
            'truncated'  => count($failures) > self::MAX_REPORTED_FAILURES,
        ];

        if ($run_id > 0 && class_exists('KIT_Sync_Run_Log')) {
            $logged = 0;
            foreach ($failures as $failure) {
                if ($logged >= self::MAX_LOGGED_FAILURES) {
                    break;
                }
                KIT_Sync_Run_Log::record_row(
                    $run_id,
                    $failure['source_row'] ?? null,
                    $failure['waybill_no'] ?? '',
                    'verify_fail',
                    ['type' => $failure['type'], 'fields' => $failure['fields']],
                    $failure['detail']
                );
                $logged++;
            }
            KIT_Sync_Run_Log::record_row(
                $run_id,
                null,
                '',
                $passed ? 'verify_pass' : 'verify_fail',
                ['counts' => $counts, 'orphans' => array_slice($orphans, 0, 50), 'context' => $context],
                $message
            );
        }

        update_option(self::LAST_REPORT_OPTION, $report, false);

        return $report;
    }
}
