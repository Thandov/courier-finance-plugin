<?php
/**
 * Stage 3 seeder: kit_waybills sheet → wp_kit_waybills DB table.
 *
 * Replaces the long-form run_google_sheet_seed() pipeline in settings.php with
 * a small, observable, idempotent upsert. Key differences from the legacy
 * seeder:
 *
 *   1. **Idempotent upsert on waybill_no.** Re-running the seeder produces the
 *      same DB state (no "clear + rewrite" that nukes manual WP edits).
 *
 *   2. **Field-ownership policy.** The seeder only writes columns the sheet
 *      owns (descriptions, dimensions, charges, flags). WP-owned fields
 *      (status, tracking_number, product_invoice_number, audit columns) are
 *      never overwritten on update. See $sheet_owned_update_fields below.
 *
 *   3. **Shadow mode.** When KIT_Waybill_Seeder::is_shadow_mode() is true, the
 *      seeder computes everything it WOULD do and records it to the audit log
 *      with shadow_insert / shadow_update actions — but never touches
 *      wp_kit_waybills. Used to validate the new pipeline in production
 *      without flipping the cutover switch.
 *
 *   4. **Audit log.** Every row processed gets a row in wp_kit_sync_run_rows
 *      with action + diff JSON + errors, queryable from the admin UI.
 *
 * Compatible with the existing Courier_Google_Sheets client and reuses the
 * shared boolean parser (kit_google_sheet_parse_bool_int) so flags map the
 * same way the old seeder does.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Waybill_Seeder
{
    const FEATURE_FLAG_OPTION = 'kit_waybill_seeder_enabled';
    const SHADOW_MODE_OPTION  = 'kit_waybill_seeder_shadow_mode';
    const CRON_HOOK           = 'kit_waybill_seeder_cron';
    const CRON_SCHEDULE       = 'kit_every_fifteen_minutes';

    /** Sheet name/range used to read kit_waybills (matches legacy seeder constants). */
    const DEFAULT_SHEET_NAME = 'kit_waybills';
    const DEFAULT_SHEET_RANGE = 'A1:AR5000';

    /**
     * Fields the SHEET owns. On insert these are populated; on update these
     * are overwritten with the latest sheet value. Anything not in this list
     * is either populated only on insert (audit columns) or never touched
     * (WP-owned columns like status, tracking_number, product_invoice_number).
     */
    private static $sheet_owned_update_fields = [
        'description',
        'direction_id',
        'city_id',
        'delivery_id',
        'customer_id',
        'company_id',
        // approval / approval_userid deliberately NOT sheet-owned: the sheet's
        // copies hold the row's own waybill number on 82-87% of rows, and
        // "4618" is not a member of the approval ENUM, so writing them blanks
        // the real status. WordPress owns approval state.
        // Client ruling (2026-08-05): the sheet is the source of truth, and the
        // sheet's product_invoice_amount column IS the total of the parcels.
        // In the DB that concept is waybill_items_total. Live data confirms the
        // mapping: sheet product_invoice_amount equals DB waybill_items_total on
        // 97.9% of rows and DB product_invoice_amount on 0.2%.
        'waybill_items_total',
        // DB product_invoice_amount is deliberately NOT sheet-owned. The sheet
        // carries freight components (Z mass_charge / AA volume_charge / AB
        // charge_basis) and a VAT flag, but no billed total — VAT is added by us,
        // so no sheet cell holds the figure the system charges. Seeding freight
        // here overwrote the VAT-inclusive total that the WP waybill form writes,
        // every 15 minutes, leaving VAT-true waybills short by the VAT. The
        // totals pass in seed() derives it instead. @see reconcile_totals()
        'sad500_amount',
        'sadc_amount',
        'item_length',
        'item_width',
        'item_height',
        'total_mass_kg',
        'total_volume',
        'mass_charge',
        'volume_charge',
        'charge_basis',
        'vat_include',
        'include_sad500',
        'include_sadc',
        'warehouse',
        'miscellaneous',
        'created_by',
        'last_updated_by',
    ];

    /**
     * Columns the seeder force-populates on INSERT when the sheet value is
     * empty/zero, because the DB schema demands them. The verifier must know
     * about these or it reports false mismatches on freshly-inserted rows
     * (sheet blank direction_id → expected 0, but the row really holds 1).
     *
     * @see add_insert_only_fields()
     */
    private static $insert_defaults_when_empty = [
        'direction_id' => 1,
        'delivery_id'  => 0,
        'customer_id'  => 0,
    ];

    /** Field list the verifier compares. Sheet is authoritative for exactly these. */
    public static function sheet_owned_fields(): array
    {
        return self::$sheet_owned_update_fields;
    }

    /** @return array<string, int> insert-time default applied when the sheet value is <= 0 */
    public static function insert_defaults_when_empty(): array
    {
        return self::$insert_defaults_when_empty;
    }

    public static function is_enabled(): bool
    {
        return (bool) get_option(self::FEATURE_FLAG_OPTION, 0);
    }

    public static function is_shadow_mode(): bool
    {
        return (bool) get_option(self::SHADOW_MODE_OPTION, 1); // default to shadow until manually flipped
    }

    public static function set_enabled(bool $on): void
    {
        update_option(self::FEATURE_FLAG_OPTION, $on ? 1 : 0, false);
    }

    public static function set_shadow_mode(bool $on): void
    {
        update_option(self::SHADOW_MODE_OPTION, $on ? 1 : 0, false);
    }

    /**
     * Register the 15-minute cron schedule and hook the seeder onto it.
     * Idempotent — call from plugin bootstrap.
     */
    public static function register_cron(): void
    {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'cron_callback']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function unregister_cron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public static function add_cron_schedule(array $schedules): array
    {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('Every 15 Minutes (Courier Sync)', 'courier-finance-plugin'),
            ];
        }
        return $schedules;
    }

    /**
     * Cron entrypoint. Only runs when the seeder is enabled — otherwise a
     * scheduled event is harmlessly skipped (the cron still wakes up so the
     * admin can flip the flag and see the next tick run without re-scheduling).
     */
    public static function cron_callback(): void
    {
        if (!self::is_enabled()) {
            return;
        }
        try {
            self::run('cron', self::is_shadow_mode());
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[KIT_Waybill_Seeder] cron run failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Run one seed pass.
     *
     * @param string $trigger_source 'cron' | 'manual' | 'shadow' | 'cli'
     * @param bool   $shadow         when true, do not write to wp_kit_waybills
     * @return array{success:bool,message:string,run_id:int,counts:array,shadow:bool}
     */
    public static function run(string $trigger_source = 'manual', bool $shadow = false): array
    {
        global $wpdb;

        if (!class_exists('Courier_Google_Sheets')) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Courier_Google_Sheets class missing — cannot read sheet.',
                'run_id'  => 0,
                'counts'  => self::empty_counts(),
                'shadow'  => $shadow,
            ];
        }
        if (!Courier_Google_Sheets::is_configured()) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Google Sheets not configured (credentials JSON missing).',
                'run_id'  => 0,
                'counts'  => self::empty_counts(),
                'shadow'  => $shadow,
            ];
        }

        $run_id = KIT_Sync_Run_Log::start_run('waybills', $trigger_source, $shadow);
        $counts = self::empty_counts();
        $sheet_name = defined('COURIER_GOOGLE_SEED_SHEET_NAME') ? COURIER_GOOGLE_SEED_SHEET_NAME : self::DEFAULT_SHEET_NAME;
        $range = defined('COURIER_GOOGLE_SEED_RANGE') ? COURIER_GOOGLE_SEED_RANGE : self::DEFAULT_SHEET_RANGE;

        try {
            $rows = Courier_Google_Sheets::get_values('', $range, $sheet_name);
        } catch (Throwable $e) {
            $msg = 'Sheet read failed: ' . $e->getMessage();
            KIT_Sync_Run_Log::finish_run($run_id, $counts, 'failed', $msg);
            return [
                'success' => false,
                'verified' => false,
                'message' => $msg,
                'run_id'  => $run_id,
                'counts'  => $counts,
                'shadow'  => $shadow,
            ];
        }

        if (empty($rows) || count($rows) < 2) {
            // Nothing read means nothing verified, and "0 waybills confirmed" is
            // not a successful seed — it is usually a broken range/sheet name or
            // a permissions problem. Surface it instead of passing silently.
            $msg = 'Sheet returned no data rows (range=' . $range . ', sheet=' . $sheet_name . ') — nothing to verify, seed not confirmed.';
            KIT_Sync_Run_Log::finish_run($run_id, $counts, 'failed', $msg);
            return [
                'success'  => false,
                'verified' => false,
                'message'  => $msg,
                'run_id'   => $run_id,
                'counts'   => $counts,
                'shadow'   => $shadow,
            ];
        }

        $header = self::normalize_header($rows[0]);
        $col = self::header_to_col_map($header);
        $waybills_table = $wpdb->prefix . 'kit_waybills';

        // ---- Pre-flight gate --------------------------------------------------
        // Structural audit of the sheet BEFORE the first write. Verification
        // alone is not enough: it reports damage after good DB values have
        // already been overwritten. A polluted column (one holding the waybill
        // number instead of real data) or a value outside a column's domain
        // aborts the run here, while the DB is still intact.
        $preflight = null;
        if (class_exists('KIT_Waybill_Preflight')) {
            $preflight = KIT_Waybill_Preflight::check($rows);
            foreach ($preflight['blocking'] as $finding) {
                KIT_Sync_Run_Log::record_row($run_id, null, '', 'preflight_block', $finding, $finding['detail']);
            }
            foreach ($preflight['warnings'] as $finding) {
                KIT_Sync_Run_Log::record_row($run_id, null, '', 'preflight_warn', $finding, $finding['detail']);
            }

            if (!$preflight['passed'] && !$shadow && KIT_Waybill_Preflight::is_blocking()) {
                $msg = $preflight['message'] . ' Fix the sheet columns listed above, or switch off pre-flight blocking to override.';
                KIT_Sync_Run_Log::finish_run($run_id, $counts, 'failed', $msg);
                return [
                    'success'   => false,
                    'verified'  => false,
                    'preflight' => $preflight,
                    'message'   => $msg,
                    'run_id'    => $run_id,
                    'counts'    => $counts,
                    'shadow'    => $shadow,
                ];
            }
        }

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $source_row_num = $r + 1;
            $counts['total']++;

            try {
                $mapped = self::map_sheet_row_to_db_payload($row, $col);
                $waybill_no = $mapped['waybill_no'];

                if ($waybill_no === '') {
                    $counts['skipped']++;
                    KIT_Sync_Run_Log::record_row($run_id, $source_row_num, '', 'skip', null, 'Missing or unparseable waybill_no');
                    continue;
                }

                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$waybills_table} WHERE waybill_no = %s LIMIT 1",
                    $waybill_no
                ), ARRAY_A);

                if ($existing === null) {
                    // INSERT path
                    if ($shadow) {
                        $counts['inserted']++;
                        KIT_Sync_Run_Log::record_row(
                            $run_id,
                            $source_row_num,
                            $waybill_no,
                            'shadow_insert',
                            ['would_insert' => $mapped['insert_payload']]
                        );
                    } else {
                        $insert_payload = self::add_insert_only_fields($mapped['insert_payload'], $waybill_no);
                        $insert_formats = self::formats_for_payload($insert_payload);
                        $ok = $wpdb->insert($waybills_table, $insert_payload, $insert_formats);
                        if ($ok) {
                            $counts['inserted']++;
                            KIT_Sync_Run_Log::record_row(
                                $run_id,
                                $source_row_num,
                                $waybill_no,
                                'insert',
                                ['inserted' => $insert_payload, 'new_id' => (int) $wpdb->insert_id]
                            );
                        } else {
                            $counts['errored']++;
                            KIT_Sync_Run_Log::record_row(
                                $run_id,
                                $source_row_num,
                                $waybill_no,
                                'error',
                                ['attempted_insert' => $insert_payload],
                                'wpdb->insert failed: ' . $wpdb->last_error
                            );
                        }
                    }
                } else {
                    // UPDATE path — sheet-owned fields only
                    $update_payload = self::diff_update_payload($existing, $mapped['update_payload']);
                    if (empty($update_payload)) {
                        $counts['skipped']++;
                        KIT_Sync_Run_Log::record_row(
                            $run_id,
                            $source_row_num,
                            $waybill_no,
                            'skip',
                            ['existing_id' => (int) $existing['id'], 'reason' => 'no sheet-owned fields changed']
                        );
                    } else {
                        if ($shadow) {
                            $counts['updated']++;
                            KIT_Sync_Run_Log::record_row(
                                $run_id,
                                $source_row_num,
                                $waybill_no,
                                'shadow_update',
                                ['existing_id' => (int) $existing['id'], 'would_update' => $update_payload]
                            );
                        } else {
                            $update_formats = self::formats_for_payload($update_payload);
                            $ok = $wpdb->update(
                                $waybills_table,
                                $update_payload,
                                ['id' => (int) $existing['id']],
                                $update_formats,
                                ['%d']
                            );
                            if ($ok !== false) {
                                $counts['updated']++;
                                KIT_Sync_Run_Log::record_row(
                                    $run_id,
                                    $source_row_num,
                                    $waybill_no,
                                    'update',
                                    ['existing_id' => (int) $existing['id'], 'updated' => $update_payload]
                                );
                            } else {
                                $counts['errored']++;
                                KIT_Sync_Run_Log::record_row(
                                    $run_id,
                                    $source_row_num,
                                    $waybill_no,
                                    'error',
                                    ['existing_id' => (int) $existing['id'], 'attempted_update' => $update_payload],
                                    'wpdb->update failed: ' . $wpdb->last_error
                                );
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $counts['errored']++;
                KIT_Sync_Run_Log::record_row(
                    $run_id,
                    $source_row_num,
                    '',
                    'error',
                    null,
                    'Row processing exception: ' . $e->getMessage()
                );
            }
        }

        $parcel_message = '';
        // kit_parcels bridge retired — write line items from description after waybill upserts.
        if (!$shadow && function_exists('kit_seed_build_custom_items_from_description') && class_exists('KIT_Waybills')) {
            $items_written = 0;
            $waybills_with_items = 0;
            foreach ($rows as $r_idx => $row) {
                if ($r_idx === 0) {
                    continue;
                }
                $wb_no = self::cell($row, $col, ['waybill_no', 'waybillno', 'waybill_number', 'wb_no']);
                if ($wb_no === '') {
                    continue;
                }
                $desc = self::cell($row, $col, ['description', 'waybill_description', 'item_description']);
                $items_total = self::to_decimal(self::cell($row, $col, [
                    'waybill_items_total', 'product_invoice_amount', 'customer_inv', 'cust_inv',
                ]));
                $invoice = self::cell($row, $col, ['product_invoice_number', 'client_invoice', 'inv_no']);
                $custom_items = kit_seed_build_custom_items_from_description($desc, $wb_no, $items_total, $invoice);
                if ($custom_items === []) {
                    continue;
                }
                $total = KIT_Waybills::updateWaybillItems($custom_items, $wb_no);
                // Only let the derived total win when it actually derived
                // something. The sheet's product_invoice_amount IS the parcels
                // total (client ruling), so a 0 here means the description split
                // failed — overwriting with 0 loses a real figure, and did so on
                // four live waybills carrying 1.3m-2.2m.
                if ((float) $total > 0) {
                    $wpdb->update(
                        $waybills_table,
                        ['waybill_items_total' => (float) $total],
                        ['waybill_no' => $wb_no],
                        ['%f'],
                        ['%s']
                    );
                }
                $waybills_with_items++;
                $items_written += count($custom_items);
            }
            $counts['parcel_waybills'] = $waybills_with_items;
            $counts['parcel_items'] = $items_written;
            $parcel_message = sprintf(' Waybill items: %d row(s) across %d waybill(s).', $items_written, $waybills_with_items);
        }

        // ---- Totals pass ------------------------------------------------------
        // Charge columns, VAT flag and line items are all in the DB now, so the
        // billed total can be derived. Runs after the items pass because VAT is
        // charged on the parcels value that pass writes.
        $totals_message = '';
        if (!$shadow && class_exists('KIT_Waybills')) {
            $totals_written = self::reconcile_totals($rows, $col);
            $counts['totals_recalculated'] = $totals_written;
            $totals_message = sprintf(' Totals derived: %d waybill(s) rewritten.', $totals_written);
        }

        // ---- Verification gate ------------------------------------------------
        // A seed is not successful until every sheet waybill has been read back
        // out of wp_kit_waybills and confirmed field-for-field. Verifying against
        // $rows (the snapshot we just seeded from) rather than a fresh sheet read
        // is deliberate: it proves OUR writes landed, and a client editing the
        // sheet mid-run cannot manufacture a phantom failure.
        //
        // Shadow runs verify too, but only as a report — the DB was intentionally
        // not written, so drift there is expected and must not fail the run.
        $verification = null;
        if (class_exists('KIT_Waybill_Verifier')) {
            $verification = KIT_Waybill_Verifier::verify($rows, $run_id, $shadow ? 'shadow_report' : 'post_seed');
            $counts['verified']   = (int) ($verification['counts']['verified'] ?? 0);
            $counts['unverified'] = (int) ($verification['counts']['missing'] ?? 0)
                + (int) ($verification['counts']['mismatch'] ?? 0)
                + (int) ($verification['counts']['sheet_dupe'] ?? 0)
                + (int) ($verification['counts']['db_dupe'] ?? 0);
        }

        $verify_passed = $shadow
            ? true
            : ($verification === null ? false : (bool) $verification['passed']);

        if (!$shadow && $verification === null) {
            $verify_message = ' VERIFICATION UNAVAILABLE — KIT_Waybill_Verifier not loaded; seed cannot be confirmed successful.';
        } else {
            $verify_message = ' ' . ($verification['message'] ?? '');
        }

        if ($counts['errored'] > 0) {
            $status = 'partial';
        } elseif (!$verify_passed) {
            $status = 'failed';
        } else {
            $status = 'ok';
        }

        $message = sprintf(
            '%s sync: %d total, %d inserted, %d updated, %d skipped, %d errored.%s%s%s',
            $shadow ? 'Shadow' : 'Production',
            $counts['total'],
            $counts['inserted'],
            $counts['updated'],
            $counts['skipped'],
            $counts['errored'],
            $parcel_message,
            $totals_message,
            $verify_message
        );
        KIT_Sync_Run_Log::finish_run($run_id, $counts, $status, $message);

        return [
            'success'      => $counts['errored'] === 0 && $verify_passed,
            'verified'     => $verify_passed,
            'preflight'    => $preflight,
            'verification' => $verification,
            'message'      => $message,
            'run_id'       => $run_id,
            'counts'       => $counts,
            'shadow'       => $shadow,
        ];
    }

    private static function empty_counts(): array
    {
        return ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0, 'verified' => 0, 'unverified' => 0, 'totals_recalculated' => 0];
    }

    /**
     * Derive each seeded waybill's billed total from the columns just written.
     *
     * Delegates to KIT_Waybills::doubleCalcWaybillTotal(), the same calculator the
     * WP waybill form saves through, so a seeded waybill and a hand-entered one
     * carrying identical charges land on identical totals — VAT included when the
     * sheet's VAT flag is set. Nothing here reimplements the money rules.
     *
     * @param array<int, array<int, string>> $rows sheet rows, row 0 being the header
     * @param array<string, int>             $col  header → column index
     * @return int waybills whose stored total was rewritten
     */
    private static function reconcile_totals(array $rows, array $col): int
    {
        $written = 0;

        foreach ($rows as $r_idx => $row) {
            if ($r_idx === 0) {
                continue;
            }

            $waybill_no = self::cell($row, $col, ['waybill_no', 'waybillno', 'waybill_number', 'wb_no']);
            if ($waybill_no === '') {
                continue;
            }

            $result = KIT_Waybills::doubleCalcWaybillTotal([
                'waybill_no'         => $waybill_no,
                'update_if_mismatch' => true,
            ]);

            if (!empty($result['updated'])) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Lower-cased, underscored header row so we can look up column indices by
     * stable keys (matches what run_google_sheet_seed() does in settings.php).
     *
     * @param array<int, string> $header_row
     * @return array<int, string>
     */
    public static function normalize_header(array $header_row): array
    {
        return array_map(function ($c) {
            $h = strtolower(trim((string) $c));
            return str_replace(' ', '_', $h);
        }, $header_row);
    }

    /**
     * @param array<int, string> $header
     * @return array<string, int>
     */
    public static function header_to_col_map(array $header): array
    {
        $col = [];
        foreach ($header as $i => $h) {
            if ($h !== '' && !isset($col[$h])) {
                $col[$h] = $i;
            }
        }
        return $col;
    }

    /**
     * Pull a value out of the row given any of the candidate header keys.
     *
     * @param array<int, mixed>     $row
     * @param array<string, int>    $col
     * @param string|array<string>  $keys
     * @return string
     */
    public static function cell($row, array $col, $keys, string $default = ''): string
    {
        $keys = is_array($keys) ? $keys : [$keys];
        foreach ($keys as $k) {
            if (!isset($col[$k])) {
                continue;
            }
            $idx = $col[$k];
            $v = $row[$idx] ?? '';
            $v = trim((string) $v);
            if ($v !== '') {
                return $v;
            }
        }
        return $default;
    }

    /**
     * Take one sheet row and produce two DB payloads:
     *   - insert_payload: full set of fields for first-insert (including audit fields added later)
     *   - update_payload: sheet-owned fields only (for upserts on existing rows)
     *
     * The split is deliberate: insert covers everything, update covers only
     * what the sheet is authoritative for (see $sheet_owned_update_fields).
     *
     * @param array<int, mixed>     $row
     * @param array<string, int>    $col
     * @return array{waybill_no:string, insert_payload:array<string, mixed>, update_payload:array<string, mixed>}
     */
    public static function map_sheet_row_to_db_payload($row, array $col): array
    {
        $waybill_no = self::normalize_waybill_no(
            self::cell($row, $col, ['waybill_no', 'waybillno', 'waybill_number', 'wb_no'])
        );

        $description     = self::cell($row, $col, ['description', 'waybill_description', 'item_description']);
        $direction_id    = self::to_int(self::cell($row, $col, ['direction_id']));
        $city_id         = self::to_int(self::cell($row, $col, ['city_id', 'destination_city_id']));
        $delivery_id     = self::to_int(self::cell($row, $col, ['delivery_id']));
        $customer_id     = self::to_int(self::cell($row, $col, ['customer_id', 'cust_id']));
        $company_id      = self::to_int(self::cell($row, $col, ['company_id']));
        $approval        = self::cell($row, $col, ['approval']);
        $approval_userid = self::to_int(self::cell($row, $col, ['approval_userid', 'approval_user_id']));

        // Sheet product_invoice_amount = total of the parcels (client ruling).
        // It feeds the DB's waybill_items_total, NOT the DB column of the same name.
        $parcels_total = self::to_decimal(self::cell($row, $col, ['waybill_items_total', 'product_invoice_amount']));
        $sad500_amount          = self::to_decimal(self::cell($row, $col, ['sad500_amount', 'sad500']));
        $sadc_amount            = self::to_decimal(self::cell($row, $col, ['sadc_amount', 'sadc']));
        $item_length            = self::to_decimal(self::cell($row, $col, ['item_length', 'length']));
        $item_width             = self::to_decimal(self::cell($row, $col, ['item_width', 'width']));
        $item_height            = self::to_decimal(self::cell($row, $col, ['item_height', 'height']));
        $total_mass_kg          = self::to_decimal(self::cell($row, $col, ['total_mass_kg', 't_mass']));
        $total_volume           = self::to_decimal(self::cell($row, $col, ['total_volume', 't_volume']));
        $mass_charge            = self::to_decimal(self::cell($row, $col, ['mass_charge']));
        $volume_charge          = self::to_decimal(self::cell($row, $col, ['volume_charge']));
        $charge_basis_raw       = self::cell($row, $col, ['charge_basis', 'basis']);
        // Normalize; empty/auto stay empty. Clear inverted MASS/VOLUME (zero side).
        $charge_basis = function_exists('kit_seed_sanitize_inverted_charge_basis')
            ? kit_seed_sanitize_inverted_charge_basis($charge_basis_raw, $mass_charge, $volume_charge)
            : (function_exists('kit_seed_normalize_charge_basis')
                ? kit_seed_normalize_charge_basis($charge_basis_raw)
                : strtolower(trim($charge_basis_raw)));

        // VAT / SAD500 / SADC: legacy enum on the VAT column still wins for
        // backward compat with rows that pre-date the dedicated columns.
        $vat_raw = self::cell($row, $col, ['vat_include', 'vat_included', 'vat']);
        $legacy_vat = strtoupper(trim($vat_raw));
        $vat_include = ($legacy_vat === 'SAD500' || $legacy_vat === 'SADC')
            ? 0
            : self::parse_bool($vat_raw);

        $sad500_flag_raw = self::cell($row, $col, ['include_sad500', 'sad500']);
        $sadc_flag_raw   = self::cell($row, $col, ['include_sadc', 'sadc']);
        $include_sad500 = ($legacy_vat === 'SAD500')
            ? 1
            : ($sad500_flag_raw !== '' ? self::parse_bool($sad500_flag_raw) : 0);
        $include_sadc = ($legacy_vat === 'SADC')
            ? 1
            : ($sadc_flag_raw !== '' ? self::parse_bool($sadc_flag_raw) : 0);

        $warehouse = self::parse_bool(self::cell($row, $col, ['warehouse']));
        $miscellaneous = self::cell($row, $col, ['miscellaneous']);

        $created_by_raw = self::cell($row, $col, ['created_by', 'created by']);
        $last_updated_by_raw = self::cell($row, $col, ['last_updated_by', 'last updated by']);
        $created_by = function_exists('kit_resolve_wp_user_id')
            ? kit_resolve_wp_user_id($created_by_raw)
            : self::to_int($created_by_raw);
        $last_updated_by = function_exists('kit_resolve_wp_user_id')
            ? kit_resolve_wp_user_id($last_updated_by_raw)
            : self::to_int($last_updated_by_raw);
        if ($last_updated_by <= 0 && $created_by > 0) {
            $last_updated_by = $created_by;
        }

        // product_invoice_amount is absent on purpose: the billed total is derived
        // after seeding, from these charge columns plus the VAT/SAD flags, by the
        // same calculator the WP waybill form uses. @see reconcile_totals()
        $update_payload = [
            'description'            => $description,
            'direction_id'           => $direction_id,
            'city_id'                => $city_id,
            'delivery_id'            => $delivery_id,
            'customer_id'            => $customer_id,
            'company_id'             => $company_id > 0 ? $company_id : null,
            'approval'               => $approval !== '' ? $approval : null,
            'approval_userid'        => $approval_userid > 0 ? $approval_userid : null,
            'waybill_items_total'    => $parcels_total,
            'sad500_amount'          => $sad500_amount,
            'sadc_amount'            => $sadc_amount,
            'item_length'            => $item_length,
            'item_width'             => $item_width,
            'item_height'            => $item_height,
            'total_mass_kg'          => $total_mass_kg,
            'total_volume'           => $total_volume,
            'mass_charge'            => $mass_charge,
            'volume_charge'          => $volume_charge,
            'charge_basis'           => $charge_basis,
            'vat_include'            => $vat_include,
            'include_sad500'         => $include_sad500,
            'include_sadc'           => $include_sadc,
            'warehouse'              => $warehouse,
            'miscellaneous'          => $miscellaneous,
        ];
        if ($created_by > 0) {
            $update_payload['created_by'] = $created_by;
        }
        if ($last_updated_by > 0) {
            $update_payload['last_updated_by'] = $last_updated_by;
        }

        $update_payload = self::filter_to_sheet_owned($update_payload);
        $update_payload = self::reject_out_of_range($update_payload);

        // Insert payload = update payload + waybill_no.
        $insert_payload = $update_payload + ['waybill_no' => $waybill_no];

        return [
            'waybill_no'     => $waybill_no,
            'insert_payload' => $insert_payload,
            'update_payload' => $update_payload,
        ];
    }

    /**
     * Waybill numbers longer than the column can hold are not waybill numbers —
     * they are description text that landed in the wrong cell. Those rows carry
     * the real number inline as "WB:- 5735", so recover it rather than losing
     * the row: an oversized value cannot be inserted at all (waybill_no is
     * VARCHAR(20)), which is how two live rows silently never reached the DB.
     */
    public static function normalize_waybill_no(string $raw): string
    {
        $v = trim($raw);
        if ($v === '' || mb_strlen($v) <= 20) {
            return $v;
        }
        if (preg_match('/\\bWB\\s*:?-?\\s*([A-Za-z0-9\\-\\/]{1,20})/i', $v, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\\b(\\d{3,10})\\b/', $v, $m)) {
            return $m[1];
        }
        return mb_substr($v, 0, 20);
    }

    /**
     * Largest value a DECIMAL(10,2) column can hold. A cell above this is a
     * data-entry accident (one live row carries an item_height of 9.19e14).
     * MySQL would clamp it silently to the maximum, which looks like a real
     * measurement; zero is obviously absent and gets noticed.
     */
    const MAX_DECIMAL = 99999999.99;

    /** @var array<int,string> decimal columns subject to MAX_DECIMAL */
    private static $decimal_fields = [
        'product_invoice_amount', 'waybill_items_total', 'sad500_amount', 'sadc_amount',
        'item_length', 'item_width', 'item_height', 'total_mass_kg', 'total_volume',
        'mass_charge', 'volume_charge',
    ];

    /**
     * Replace impossible numbers with 0 so the rest of the row still seeds.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function reject_out_of_range(array $payload): array
    {
        foreach (self::$decimal_fields as $f) {
            if (isset($payload[$f]) && is_numeric($payload[$f]) && abs((float) $payload[$f]) > self::MAX_DECIMAL) {
                $payload[$f] = 0;
            }
        }
        return $payload;
    }

    /**
     * Restrict an array to the columns the sheet is allowed to overwrite.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function filter_to_sheet_owned(array $payload): array
    {
        $allowed = array_flip(self::$sheet_owned_update_fields);
        return array_intersect_key($payload, $allowed);
    }

    /**
     * Add audit + WP-owned fields needed only on INSERT (not on update).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function add_insert_only_fields(array $payload, string $waybill_no): array
    {
        $payload['waybill_no'] = $waybill_no;

        $seed_owner = function_exists('kit_get_seed_owner_user_id')
            ? (int) kit_get_seed_owner_user_id()
            : max(1, (int) get_current_user_id());
        if ($seed_owner <= 0) {
            $seed_owner = 1;
        }

        if (empty($payload['created_by']) || (int) $payload['created_by'] <= 0) {
            $payload['created_by'] = $seed_owner;
        } else {
            $payload['created_by'] = (int) $payload['created_by'];
        }
        if (empty($payload['last_updated_by']) || (int) $payload['last_updated_by'] <= 0) {
            $payload['last_updated_by'] = (int) $payload['created_by'];
        } else {
            $payload['last_updated_by'] = (int) $payload['last_updated_by'];
        }
        $payload['status']          = 'pending';
        $payload['tracking_number'] = 'TRK-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $payload['created_at']      = current_time('mysql');
        $payload['last_updated_at'] = current_time('mysql');

        // Required NOT NULL columns the DB schema demands on insert.
        // Single source of truth — KIT_Waybill_Verifier reads the same map so a
        // forced default is never reported as a sheet↔DB mismatch.
        foreach (self::$insert_defaults_when_empty as $field => $default) {
            if (!isset($payload[$field]) || (int) $payload[$field] <= 0) {
                $payload[$field] = $default;
            }
        }

        return $payload;
    }

    /**
     * Reduce an update payload to only the columns that actually changed,
     * so the audit log shows real diffs (not "we wrote the same value back").
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $proposed
     * @return array<string, mixed>
     */
    private static function diff_update_payload(array $existing, array $proposed): array
    {
        $diff = [];
        foreach ($proposed as $field => $new_value) {
            $old_value = $existing[$field] ?? null;
            if (self::values_differ($old_value, $new_value)) {
                $diff[$field] = $new_value;
            }
        }
        return $diff;
    }

    public static function values_differ($old, $new): bool
    {
        if ($old === null && ($new === null || $new === '')) {
            return false;
        }
        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) > 0.00001;
        }
        return (string) $old !== (string) $new;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private static function formats_for_payload(array $payload): array
    {
        $int_fields = ['direction_id', 'city_id', 'delivery_id', 'customer_id', 'company_id', 'approval_userid', 'vat_include', 'include_sad500', 'include_sadc', 'warehouse', 'created_by', 'last_updated_by'];
        $float_fields = ['product_invoice_amount', 'waybill_items_total', 'sad500_amount', 'sadc_amount', 'item_length', 'item_width', 'item_height', 'total_mass_kg', 'total_volume', 'mass_charge', 'volume_charge'];

        $formats = [];
        foreach (array_keys($payload) as $field) {
            if (in_array($field, $int_fields, true)) {
                $formats[] = '%d';
            } elseif (in_array($field, $float_fields, true)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }

    /**
     * Sheet → int. Delegates to the canonical parser so a thousands separator
     * in an ID cell cannot silently change the value.
     *
     * @see kit_parse_sheet_int() in includes/sync/kit-seed-number-parse.php
     */
    private static function to_int(string $v): int
    {
        if ($v === '' || strtolower($v) === 'null') {
            return 0;
        }
        if (function_exists('kit_parse_sheet_int')) {
            return kit_parse_sheet_int($v, 0);
        }
        return (int) preg_replace('/[^0-9\-]/', '', $v);
    }

    /**
     * Sheet → decimal. The Sheets API hands us FORMATTED text, so "R 1 200,50"
     * must parse to 1200.50 and not to 120050 — see kit-seed-number-parse.php
     * for why this was the main source of DB↔sheet divergence.
     */
    private static function to_decimal(string $v): float
    {
        if ($v === '' || strtolower($v) === 'null') {
            return 0.0;
        }
        if (function_exists('kit_parse_sheet_decimal')) {
            return kit_parse_sheet_decimal($v, 0.0);
        }
        return (float) preg_replace('/[^0-9.\-]/', '', $v);
    }

    /**
     * Boolean coercion that mirrors Code.gs parseBooleanFlexible_ — accepts
     * native booleans (as strings post-Sheets-coercion), case-insensitive
     * text TRUE/FALSE/YES/NO/Y/N/1/0/ON, and positive numeric amounts.
     */
    private static function parse_bool($v): int
    {
        if ($v === true) {
            return 1;
        }
        if ($v === false || $v === null) {
            return 0;
        }
        if (is_numeric($v)) {
            return ((float) $v != 0.0) ? 1 : 0;
        }
        $s = strtoupper(trim((string) $v));
        if ($s === '' || $s === 'NULL' || $s === 'N/A' || $s === 'NA' || $s === '-') {
            return 0;
        }
        if (in_array($s, ['1', 'TRUE', 'YES', 'Y', 'ON'], true)) {
            return 1;
        }
        return 0;
    }
}
