<?php
/**
 * Admin page: Sync Runs (Step 4d of the new sync pipeline).
 *
 * Single screen that:
 *   - Toggles the seeder feature flag (off → on flips production cron writes on)
 *   - Toggles shadow mode (compute-only, no DB writes; safe to leave on while
 *     comparing the new seeder against the legacy run_google_sheet_seed path)
 *   - Triggers manual runs (Production or Shadow)
 *   - Lists the last 50 sync runs with counts
 *   - Drills into a single run's per-row audit (waybill_no + action + diff JSON
 *     + error string), so support can answer "why didn't waybill X show up?"
 *     without grepping debug.log
 *
 * Intentionally NOT styled with the Tailwind dashboard chrome — the runs grid
 * needs to be dense and copy-pasteable for triage. Plain WP admin tables.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

function kit_sync_runs_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'courier-finance-plugin'));
    }

    if (!class_exists('KIT_Waybill_Seeder') || !class_exists('KIT_Sync_Run_Log')) {
        echo '<div class="wrap"><h1>Sync Runs</h1><div class="notice notice-error"><p>Sync classes not loaded.</p></div></div>';
        return;
    }

    $notice = kit_sync_runs_handle_post();

    $run_id_view = isset($_GET['run_id']) ? (int) $_GET['run_id'] : 0;
    if ($run_id_view > 0) {
        kit_sync_runs_render_drilldown($run_id_view, $notice);
        return;
    }

    kit_sync_runs_render_dashboard($notice);
}

function kit_sync_runs_handle_post(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'kit_sync_runs_action')) {
        return '';
    }

    $action = isset($_POST['kit_action']) ? sanitize_text_field((string) $_POST['kit_action']) : '';

    switch ($action) {
        case 'toggle_enabled':
            KIT_Waybill_Seeder::set_enabled(!KIT_Waybill_Seeder::is_enabled());
            return KIT_Waybill_Seeder::is_enabled()
                ? 'Seeder enabled. Cron will run every 15 minutes (subject to shadow-mode setting).'
                : 'Seeder disabled. Cron will skip until re-enabled.';

        case 'toggle_shadow':
            KIT_Waybill_Seeder::set_shadow_mode(!KIT_Waybill_Seeder::is_shadow_mode());
            return KIT_Waybill_Seeder::is_shadow_mode()
                ? 'Shadow mode ON — seeder will compute and log but not write to wp_kit_waybills.'
                : 'Shadow mode OFF — seeder WILL write to wp_kit_waybills on next run. Production mode.';

        case 'run_now':
            $result = KIT_Waybill_Seeder::run('manual', KIT_Waybill_Seeder::is_shadow_mode());
            return $result['message'];

        case 'run_shadow':
            $result = KIT_Waybill_Seeder::run('manual', true);
            return $result['message'];

        case 'run_production':
            $result = KIT_Waybill_Seeder::run('manual', false);
            return $result['message'];

        case 'verify_now':
            if (!class_exists('KIT_Waybill_Verifier')) {
                return 'Verifier not loaded.';
            }
            // Standalone verification does its own fresh sheet read — this is the
            // "is the DB right now what the sheet says?" check, independent of
            // whether a seed just ran.
            $report = KIT_Waybill_Verifier::verify(null, 0, 'standalone');
            return $report['message'];

        case 'toggle_preflight_block':
            if (!class_exists('KIT_Waybill_Preflight')) {
                return 'Pre-flight not loaded.';
            }
            KIT_Waybill_Preflight::set_blocking(!KIT_Waybill_Preflight::is_blocking());
            return KIT_Waybill_Preflight::is_blocking()
                ? 'Pre-flight blocking ON — a structurally bad sheet aborts the seed before writing.'
                : 'Pre-flight blocking OFF — findings are logged but the seed writes anyway.';

        case 'toggle_strict_orphans':
            if (!class_exists('KIT_Waybill_Verifier')) {
                return 'Verifier not loaded.';
            }
            KIT_Waybill_Verifier::set_strict_orphans(!KIT_Waybill_Verifier::is_strict_orphans());
            return KIT_Waybill_Verifier::is_strict_orphans()
                ? 'Strict orphans ON — a waybill in the DB but not on the sheet now FAILS verification.'
                : 'Strict orphans OFF — DB-only waybills are reported but do not fail verification.';
    }

    return '';
}

/**
 * Pre-flight panel: what the sheet audit found BEFORE any write.
 *
 * Blocking findings mean the sheet itself is structurally wrong (a column
 * holding the waybill number, a value outside a column's domain). Warnings mean
 * the schema cannot hold the sheet's precision — a DB change, not a sheet fix.
 */
function kit_sync_runs_render_preflight_panel(): void
{
    if (!class_exists('KIT_Waybill_Preflight')) {
        return;
    }
    $report = KIT_Waybill_Preflight::last_report();
    $blocking_on = KIT_Waybill_Preflight::is_blocking();
    ?>
    <h2 style="margin-top:2em;">Pre-flight (sheet audit, before any write)</h2>
    <p>Runs at the start of every seed. A blocking finding aborts the run <em>before</em> the first write, while the
        database is still intact — verification alone reports damage only after good values have been overwritten.</p>

    <form method="post" style="display:inline;">
        <?php wp_nonce_field('kit_sync_runs_action'); ?>
        <input type="hidden" name="kit_action" value="toggle_preflight_block" />
        <button class="button" type="submit">
            Blocking: <?php echo $blocking_on ? 'ON (bad sheet aborts the seed)' : 'OFF (warn only — seed writes anyway)'; ?>
            — turn <?php echo $blocking_on ? 'off' : 'on'; ?>
        </button>
    </form>

    <?php if (empty($report)): ?>
        <p><em>No pre-flight has run yet.</em></p>
        <?php return; ?>
    <?php endif; ?>

    <p style="margin-top:1em;">
        <strong style="color:<?php echo !empty($report['passed']) ? '#0f5132' : '#842029'; ?>;">
            <?php echo esc_html((string) ($report['message'] ?? '')); ?>
        </strong><br />
        <span style="color:#787c82;">Checked at <?php echo esc_html((string) ($report['checked_at'] ?? '')); ?></span>
    </p>

    <?php foreach ([['blocking', 'Blocking — fix these in the sheet', '#842029'], ['warnings', 'Warnings — schema cannot hold the sheet\'s precision', '#664d03']] as [$key, $title, $colour]): ?>
        <?php $items = $report[$key] ?? []; ?>
        <?php if (!empty($items)): ?>
            <h3 style="color:<?php echo $colour; ?>;"><?php echo esc_html($title); ?> (<?php echo count($items); ?>)</h3>
            <table class="widefat striped">
                <thead>
                    <tr><th style="width:170px;">Check</th><th style="width:180px;">Column</th><th style="width:70px;">Rows</th><th>Detail</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $f): ?>
                        <tr>
                            <td><code><?php echo esc_html((string) ($f['check'] ?? '')); ?></code></td>
                            <td><strong><?php echo esc_html((string) ($f['column'] ?? '')); ?></strong></td>
                            <td><?php echo isset($f['rows']) ? (int) $f['rows'] : ''; ?></td>
                            <td><?php echo esc_html((string) ($f['detail'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php
}

/** Render a value for the sheet↔DB diff table without hiding empties. */
function kit_sync_runs_render_value($value): string
{
    if ($value === null) {
        return '<em style="color:#787c82;">NULL</em>';
    }
    if ($value === '') {
        return '<em style="color:#787c82;">(empty)</em>';
    }
    return '<code>' . esc_html(mb_strimwidth((string) $value, 0, 160, '…')) . '</code>';
}

/**
 * Verification panel: the sheet↔DB diff, field by field.
 *
 * This is the screen for "the DB looks different from the Google Sheet" — each
 * row shows the literal sheet cell, the value the seeder derived from it, and
 * what MySQL actually stored, so truncation/coercion is visible rather than
 * inferred.
 */
function kit_sync_runs_render_verification_panel(): void
{
    if (!class_exists('KIT_Waybill_Verifier')) {
        return;
    }

    $report = KIT_Waybill_Verifier::last_report();
    $strict = KIT_Waybill_Verifier::is_strict_orphans();
    ?>
    <h2 style="margin-top:2em;">Verification (sheet ↔ DB)</h2>
    <p>A seed run is only reported successful when every waybill on the <code>kit_waybills</code> sheet has been read
        back out of <code>wp_kit_waybills</code> and confirmed column by column. Sheet-owned columns only — WP-owned
        columns (<code>status</code>, <code>tracking_number</code>, <code>product_invoice_number</code>, audit columns)
        are never written by the seeder, so differences there are correct.</p>

    <form method="post" style="display:inline;">
        <?php wp_nonce_field('kit_sync_runs_action'); ?>
        <input type="hidden" name="kit_action" value="verify_now" />
        <button class="button" type="submit">Verify now (read-only, re-reads the sheet)</button>
    </form>
    <form method="post" style="display:inline; margin-left:.5em;">
        <?php wp_nonce_field('kit_sync_runs_action'); ?>
        <input type="hidden" name="kit_action" value="toggle_strict_orphans" />
        <button class="button" type="submit">
            Strict orphans: <?php echo $strict ? 'ON (DB-only waybills fail the run)' : 'OFF (reported only)'; ?>
            — turn <?php echo $strict ? 'off' : 'on'; ?>
        </button>
    </form>

    <?php if (empty($report)): ?>
        <p><em>No verification has run yet.</em></p>
        <?php return; ?>
    <?php endif; ?>

    <?php
    $counts = $report['counts'] ?? [];
    $passed = !empty($report['passed']);
    ?>
    <table class="widefat striped" style="max-width:900px; margin-top:1em;">
        <tbody>
            <tr>
                <th style="width:240px;">Result</th>
                <td>
                    <strong style="color:<?php echo $passed ? '#0f5132' : '#842029'; ?>;">
                        <?php echo $passed ? 'PASSED — DB matches the sheet' : 'FAILED — DB does not match the sheet'; ?>
                    </strong>
                </td>
            </tr>
            <tr><th>Checked at</th><td><?php echo esc_html((string) ($report['checked_at'] ?? '')); ?> (<?php echo esc_html((string) ($report['context'] ?? '')); ?>)</td></tr>
            <tr><th>Sheet rows</th><td><?php echo (int) ($counts['sheet_rows'] ?? 0); ?> (<?php echo (int) ($counts['skipped'] ?? 0); ?> skipped — no waybill_no)</td></tr>
            <tr><th>Verified identical</th><td style="color:#0f5132;"><?php echo (int) ($counts['verified'] ?? 0); ?></td></tr>
            <tr><th>Missing from DB</th><td style="<?php echo ((int) ($counts['missing'] ?? 0) > 0) ? 'color:#842029;font-weight:bold;' : ''; ?>"><?php echo (int) ($counts['missing'] ?? 0); ?></td></tr>
            <tr><th>Mismatched columns</th><td style="<?php echo ((int) ($counts['mismatch'] ?? 0) > 0) ? 'color:#842029;font-weight:bold;' : ''; ?>"><?php echo (int) ($counts['mismatch'] ?? 0); ?></td></tr>
            <tr><th>Duplicate waybill on sheet</th><td style="<?php echo ((int) ($counts['sheet_dupe'] ?? 0) > 0) ? 'color:#842029;font-weight:bold;' : ''; ?>"><?php echo (int) ($counts['sheet_dupe'] ?? 0); ?></td></tr>
            <tr><th>Duplicate waybill in DB</th><td style="<?php echo ((int) ($counts['db_dupe'] ?? 0) > 0) ? 'color:#842029;font-weight:bold;' : ''; ?>"><?php echo (int) ($counts['db_dupe'] ?? 0); ?></td></tr>
            <tr><th>In DB, not on sheet</th><td><?php echo (int) ($counts['orphan'] ?? 0); ?> <?php echo $strict ? '<strong>(failing the run)</strong>' : '<em>(reported only)</em>'; ?></td></tr>
        </tbody>
    </table>

    <?php $failures = $report['failures'] ?? []; ?>
    <?php if (!empty($failures)): ?>
        <h3 style="margin-top:1.5em;">Differences</h3>
        <p><em>Most <code>mismatch</code> rows are repaired by a single <strong>Run production</strong> above — the
            seeder overwrites sheet-owned columns with the current sheet value. Differences that survive a production
            run are real problems: a value the DB physically cannot store (too long for the column, outside an ENUM),
            or a duplicate waybill number.</em></p>
        <?php if (!empty($report['truncated'])): ?>
            <p><em>Showing the first <?php echo (int) KIT_Waybill_Verifier::MAX_REPORTED_FAILURES; ?> failures — see the run drilldown for the full audit.</em></p>
        <?php endif; ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:130px;">Waybill #</th>
                    <th style="width:70px;">Sheet row</th>
                    <th style="width:100px;">Type</th>
                    <th style="width:150px;">Column</th>
                    <th>Sheet cell (literal)</th>
                    <th>Expected (parsed)</th>
                    <th>In the DB</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($failures as $failure): ?>
                    <?php
                    $type = (string) ($failure['type'] ?? '');
                    $fields = $failure['fields'] ?? [];
                    $waybill = (string) ($failure['waybill_no'] ?? '');
                    $src_row = $failure['source_row'] ?? null;
                    ?>
                    <?php if (empty($fields)): ?>
                        <tr>
                            <td><code><?php echo esc_html($waybill); ?></code></td>
                            <td><?php echo $src_row !== null ? (int) $src_row : ''; ?></td>
                            <td style="color:#842029;font-weight:bold;"><?php echo esc_html($type); ?></td>
                            <td colspan="4"><?php echo esc_html((string) ($failure['detail'] ?? '')); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($fields as $i => $diff): ?>
                            <tr>
                                <?php if ($i === 0): ?>
                                    <td rowspan="<?php echo count($fields); ?>"><code><?php echo esc_html($waybill); ?></code></td>
                                    <td rowspan="<?php echo count($fields); ?>"><?php echo $src_row !== null ? (int) $src_row : ''; ?></td>
                                    <td rowspan="<?php echo count($fields); ?>" style="color:#842029;font-weight:bold;"><?php echo esc_html($type); ?></td>
                                <?php endif; ?>
                                <td>
                                    <strong><?php echo esc_html((string) ($diff['field'] ?? '')); ?></strong>
                                    <?php if (!empty($diff['note'])): ?>
                                        <br /><span style="font-size:11px; color:#842029;"><?php echo esc_html((string) $diff['note']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo kit_sync_runs_render_value($diff['sheet_raw'] ?? ''); ?></td>
                                <td><?php echo kit_sync_runs_render_value($diff['expected'] ?? ''); ?></td>
                                <td style="background:#fcf0f1;"><?php echo kit_sync_runs_render_value($diff['db'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php $orphans = $report['orphans'] ?? []; ?>
    <?php if (!empty($orphans)): ?>
        <h3 style="margin-top:1.5em;">In the DB but not on the sheet</h3>
        <p><em>Usually waybills created directly in WP admin. Turn on strict orphans above if the sheet is meant to be
            the only source of waybills.</em></p>
        <p><code><?php echo esc_html(implode(', ', array_map('strval', $orphans))); ?></code></p>
    <?php endif; ?>
    <?php
}

function kit_sync_runs_render_dashboard(string $notice): void
{
    $enabled = KIT_Waybill_Seeder::is_enabled();
    $shadow = KIT_Waybill_Seeder::is_shadow_mode();
    $runs = KIT_Sync_Run_Log::recent_runs(50, 'waybills');
    $next_cron = wp_next_scheduled(KIT_Waybill_Seeder::CRON_HOOK);
    ?>
    <div class="wrap">
        <h1>Sync Runs (Waybills)</h1>
        <p>Step 4 of the new sync pipeline: PHP <code>KIT_Waybill_Seeder</code> reads the <code>kit_waybills</code> sheet
            and upserts into <code>wp_kit_waybills</code>. Each row is logged to <code>wp_kit_sync_run_rows</code> with
            an action (insert / update / skip / error) and a diff payload so you can answer "why didn't waybill X show
            up?" by clicking through a run.</p>

        <?php if ($notice !== ''): ?>
            <div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <h2>Seeder state</h2>
        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <tr>
                    <th style="width:240px;">Enabled (cron will run)</th>
                    <td>
                        <strong><?php echo $enabled ? '<span style="color:#0f5132;">YES</span>' : '<span style="color:#842029;">NO</span>'; ?></strong>
                        <form method="post" style="display:inline; margin-left:1em;">
                            <?php wp_nonce_field('kit_sync_runs_action'); ?>
                            <input type="hidden" name="kit_action" value="toggle_enabled" />
                            <button class="button" type="submit"><?php echo $enabled ? 'Disable' : 'Enable'; ?></button>
                        </form>
                    </td>
                </tr>
                <tr>
                    <th>Shadow mode (no DB writes)</th>
                    <td>
                        <strong><?php echo $shadow ? '<span style="color:#664d03;">SHADOW (logs only)</span>' : '<span style="color:#842029;">PRODUCTION (writes to DB)</span>'; ?></strong>
                        <form method="post" style="display:inline; margin-left:1em;">
                            <?php wp_nonce_field('kit_sync_runs_action'); ?>
                            <input type="hidden" name="kit_action" value="toggle_shadow" />
                            <button class="button" type="submit"><?php echo $shadow ? 'Switch to PRODUCTION' : 'Switch to SHADOW'; ?></button>
                        </form>
                    </td>
                </tr>
                <tr>
                    <th>Next scheduled cron</th>
                    <td><?php echo $next_cron ? esc_html(date('Y-m-d H:i:s', $next_cron)) . ' (in ' . esc_html(human_time_diff(time(), $next_cron)) . ')' : '<em>not scheduled</em>'; ?></td>
                </tr>
                <tr>
                    <th>Sheet source</th>
                    <td>
                        <code><?php echo esc_html(defined('COURIER_GOOGLE_SEED_SHEET_NAME') ? COURIER_GOOGLE_SEED_SHEET_NAME : KIT_Waybill_Seeder::DEFAULT_SHEET_NAME); ?></code>
                        range
                        <code><?php echo esc_html(defined('COURIER_GOOGLE_SEED_RANGE') ? COURIER_GOOGLE_SEED_RANGE : KIT_Waybill_Seeder::DEFAULT_SHEET_RANGE); ?></code>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2>Run now</h2>
        <form method="post" style="display:inline;">
            <?php wp_nonce_field('kit_sync_runs_action'); ?>
            <input type="hidden" name="kit_action" value="run_shadow" />
            <button class="button" type="submit">Run shadow (logs only)</button>
        </form>
        <form method="post" style="display:inline; margin-left:.5em;">
            <?php wp_nonce_field('kit_sync_runs_action'); ?>
            <input type="hidden" name="kit_action" value="run_production" />
            <button class="button button-primary" type="submit" onclick="return confirm('Run a PRODUCTION sync now? This will write to wp_kit_waybills.');">Run production (writes to DB)</button>
        </form>
        <p style="margin-top:.5em;"><em>Every production run ends with a verification pass. If any waybill fails to
            match the sheet the run is marked <strong>failed</strong>, even when no insert or update errored.</em></p>

        <?php kit_sync_runs_render_preflight_panel(); ?>
        <?php kit_sync_runs_render_verification_panel(); ?>

        <h2 style="margin-top:2em;">Recent runs</h2>
        <?php if (empty($runs)): ?>
            <p><em>No sync runs yet. Trigger one above to see it appear here.</em></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Run #</th>
                        <th>Started</th>
                        <th>Duration</th>
                        <th>Trigger</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Inserted</th>
                        <th>Updated</th>
                        <th>Skipped</th>
                        <th>Errored</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $run): ?>
                        <?php
                        $duration = '';
                        if (!empty($run->finished_at)) {
                            $started = strtotime((string) $run->started_at);
                            $finished = strtotime((string) $run->finished_at);
                            if ($started && $finished) {
                                $duration = ($finished - $started) . 's';
                            }
                        }
                        $status_colour = '';
                        if ($run->status === 'ok') {
                            $status_colour = '#0f5132';
                        } elseif ($run->status === 'partial') {
                            $status_colour = '#664d03';
                        } elseif ($run->status === 'failed' || $run->status === 'running') {
                            $status_colour = '#842029';
                        }
                        $drill_url = esc_url(add_query_arg(['page' => '08600-sync-runs', 'run_id' => (int) $run->id], admin_url('admin.php')));
                        ?>
                        <tr>
                            <td><a href="<?php echo $drill_url; ?>">#<?php echo (int) $run->id; ?></a></td>
                            <td><?php echo esc_html((string) $run->started_at); ?></td>
                            <td><?php echo esc_html($duration); ?></td>
                            <td><?php echo esc_html((string) $run->trigger_source); ?></td>
                            <td><?php echo $run->shadow ? '<span style="color:#664d03;">SHADOW</span>' : '<span style="color:#0f5132;">PROD</span>'; ?></td>
                            <td style="color:<?php echo $status_colour; ?>;"><?php echo esc_html((string) $run->status); ?></td>
                            <td><?php echo (int) $run->rows_total; ?></td>
                            <td><?php echo (int) $run->rows_inserted; ?></td>
                            <td><?php echo (int) $run->rows_updated; ?></td>
                            <td><?php echo (int) $run->rows_skipped; ?></td>
                            <td style="<?php echo ((int) $run->rows_errored > 0) ? 'color:#842029;font-weight:bold;' : ''; ?>"><?php echo (int) $run->rows_errored; ?></td>
                            <td><?php echo esc_html(mb_strimwidth((string) $run->message, 0, 120, '…')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

function kit_sync_runs_render_drilldown(int $run_id, string $notice): void
{
    $run = KIT_Sync_Run_Log::get_run($run_id);
    if (!$run) {
        echo '<div class="wrap"><h1>Sync Run #' . (int) $run_id . '</h1><div class="notice notice-error"><p>Run not found.</p></div></div>';
        return;
    }
    $back_url = esc_url(add_query_arg('page', '08600-sync-runs', admin_url('admin.php')));
    $filter = isset($_GET['action_filter']) ? sanitize_text_field((string) $_GET['action_filter']) : '';
    $rows = KIT_Sync_Run_Log::rows_for_run($run_id, $filter);
    ?>
    <div class="wrap">
        <p><a href="<?php echo $back_url; ?>">&larr; Back to all runs</a></p>
        <h1>Sync Run #<?php echo (int) $run->id; ?></h1>
        <?php if ($notice !== ''): ?>
            <div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <tr><th style="width:240px;">Entity</th><td><?php echo esc_html((string) $run->entity); ?></td></tr>
                <tr><th>Trigger</th><td><?php echo esc_html((string) $run->trigger_source); ?></td></tr>
                <tr><th>Mode</th><td><?php echo $run->shadow ? 'SHADOW (no DB writes)' : 'PRODUCTION'; ?></td></tr>
                <tr><th>Status</th><td><?php echo esc_html((string) $run->status); ?></td></tr>
                <tr><th>Started</th><td><?php echo esc_html((string) $run->started_at); ?></td></tr>
                <tr><th>Finished</th><td><?php echo esc_html((string) $run->finished_at); ?></td></tr>
                <tr><th>Counts</th><td>Total: <?php echo (int) $run->rows_total; ?> | Inserted: <?php echo (int) $run->rows_inserted; ?> | Updated: <?php echo (int) $run->rows_updated; ?> | Skipped: <?php echo (int) $run->rows_skipped; ?> | Errored: <?php echo (int) $run->rows_errored; ?></td></tr>
                <tr><th>Message</th><td><?php echo esc_html((string) $run->message); ?></td></tr>
            </tbody>
        </table>

        <h2 style="margin-top:2em;">Rows
            <span style="font-weight:normal; font-size:.8em;">
                (Filter:
                <a href="<?php echo esc_url(add_query_arg(['page' => '08600-sync-runs', 'run_id' => $run_id], admin_url('admin.php'))); ?>"<?php echo $filter === '' ? ' style="font-weight:bold;"' : ''; ?>>all</a> |
                <?php $filters = ['insert', 'update', 'skip', 'error', 'shadow_insert', 'shadow_update', 'verify_fail', 'verify_pass', 'preflight_block', 'preflight_warn']; ?>
                <?php foreach ($filters as $fi => $a): ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => '08600-sync-runs', 'run_id' => $run_id, 'action_filter' => $a], admin_url('admin.php'))); ?>"<?php echo $filter === $a ? ' style="font-weight:bold;"' : ''; ?>><?php echo esc_html($a); ?></a><?php echo $fi === count($filters) - 1 ? '' : ' |'; ?>
                <?php endforeach; ?>
                )
            </span>
        </h2>

        <?php if (empty($rows)): ?>
            <p><em>No rows matched this filter.</em></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th style="width:80px;">Src row</th>
                        <th style="width:140px;">Waybill #</th>
                        <th style="width:110px;">Action</th>
                        <th>Diff / Payload</th>
                        <th>Errors</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $row_id = (int) $row->id;
                        $action = (string) $row->action;
                        $action_colour = '';
                        if (in_array($action, ['insert', 'update', 'verify_pass'], true)) {
                            $action_colour = '#0f5132';
                        } elseif (in_array($action, ['shadow_insert', 'shadow_update'], true)) {
                            $action_colour = '#664d03';
                        } elseif (in_array($action, ['error', 'verify_fail', 'preflight_block'], true)) {
                            $action_colour = '#842029';
                        }
                        ?>
                        <tr>
                            <td><?php echo $row_id; ?></td>
                            <td><?php echo $row->source_row !== null ? (int) $row->source_row : ''; ?></td>
                            <td><code><?php echo esc_html((string) ($row->waybill_no ?? '')); ?></code></td>
                            <td style="color:<?php echo $action_colour; ?>;font-weight:bold;"><?php echo esc_html($action); ?></td>
                            <td><pre style="margin:0; max-height:200px; overflow:auto; background:#f6f7f7; padding:.5em; font-size:11px;"><?php echo esc_html((string) ($row->diff ?? '')); ?></pre></td>
                            <td><?php echo esc_html((string) ($row->errors ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
