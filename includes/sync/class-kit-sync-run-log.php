<?php
/**
 * Audit log for Stage 3 (PHP) sheet → DB seeding.
 *
 * Writes to two append-only tables (Step 4 of the new sync flow):
 *   - wp_kit_sync_runs       (one row per sync invocation)
 *   - wp_kit_sync_run_rows   (one row per waybill processed in a run)
 *
 * Sister to (but independent of) the legacy sync_errors sheet that Apps Script
 * writes to. The sheet log is fire-and-forget; this PHP log is queryable from
 * the WP admin UI (see includes/admin-pages/sync-runs.php) so support can
 * answer "why didn't waybill X show up?" with row-level evidence (action +
 * diff JSON) rather than tailing debug.log.
 *
 * Two important properties:
 *   1. Shadow runs (shadow=1) are recorded the same as production runs so we
 *      can compare them side-by-side without flipping the feature flag.
 *   2. record_row() never throws — audit failures must never break the seeder.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Sync_Run_Log
{
    const TABLE_RUNS = 'kit_sync_runs';
    const TABLE_ROWS = 'kit_sync_run_rows';

    /**
     * Idempotent create / upgrade of both audit tables via dbDelta. Safe to
     * call on every plugin load — dbDelta short-circuits when the schema
     * already matches.
     */
    public static function ensure_tables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $runs_table = $wpdb->prefix . self::TABLE_RUNS;
        $rows_table = $wpdb->prefix . self::TABLE_ROWS;

        $sql_runs = "CREATE TABLE {$runs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity VARCHAR(50) NOT NULL,
            trigger_source VARCHAR(50) NOT NULL,
            shadow TINYINT(1) NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            rows_total INT UNSIGNED NOT NULL DEFAULT 0,
            rows_inserted INT UNSIGNED NOT NULL DEFAULT 0,
            rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
            rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
            rows_errored INT UNSIGNED NOT NULL DEFAULT 0,
            message TEXT NULL,
            PRIMARY KEY (id),
            INDEX idx_started_at (started_at),
            INDEX idx_entity_status (entity, status)
        ) {$charset_collate};";

        $sql_rows = "CREATE TABLE {$rows_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            source_row INT UNSIGNED NULL,
            waybill_no VARCHAR(50) NULL,
            action VARCHAR(20) NOT NULL,
            diff LONGTEXT NULL,
            errors TEXT NULL,
            PRIMARY KEY (id),
            INDEX idx_run_id (run_id),
            INDEX idx_waybill_no (waybill_no)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_runs);
        dbDelta($sql_rows);
    }

    /**
     * Open a new run row and return its id. Caller must call finish_run() at the
     * end. If the audit table doesn't exist (first activation race), returns 0.
     *
     * @param string $entity         e.g. 'waybills'
     * @param string $trigger_source 'cron' | 'manual' | 'shadow' | 'cli'
     * @param bool   $shadow         true for non-writing dry-runs
     */
    public static function start_run(string $entity, string $trigger_source, bool $shadow): int
    {
        global $wpdb;

        $runs_table = $wpdb->prefix . self::TABLE_RUNS;
        $started_at = current_time('mysql');

        $ok = $wpdb->insert(
            $runs_table,
            [
                'entity'         => $entity,
                'trigger_source' => $trigger_source,
                'shadow'         => $shadow ? 1 : 0,
                'started_at'     => $started_at,
                'status'         => 'running',
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Record a per-row outcome. Never throws — audit failures must not break
     * the seeder. Passing $run_id <= 0 is a no-op for safety.
     *
     * @param int         $run_id     id returned by start_run
     * @param int|null    $source_row 1-based source-sheet row number (optional)
     * @param string|null $waybill_no waybill number (may be empty for unparseable rows)
     * @param string      $action     'insert' | 'update' | 'skip' | 'error' | 'shadow_insert' | 'shadow_update'
     * @param array|null  $diff       structured diff payload (will be JSON-encoded)
     * @param string      $errors     human-readable error/warning message (single line preferred)
     */
    public static function record_row(int $run_id, $source_row, $waybill_no, string $action, $diff = null, string $errors = ''): void
    {
        if ($run_id <= 0) {
            return;
        }
        global $wpdb;

        try {
            $rows_table = $wpdb->prefix . self::TABLE_ROWS;
            $payload = [
                'run_id'     => $run_id,
                'source_row' => $source_row !== null && $source_row !== '' ? (int) $source_row : null,
                'waybill_no' => $waybill_no !== null && $waybill_no !== '' ? (string) $waybill_no : null,
                'action'     => substr((string) $action, 0, 20),
                'diff'       => $diff !== null ? wp_json_encode($diff) : null,
                'errors'     => $errors !== '' ? substr($errors, 0, 65535) : null,
            ];
            $formats = ['%d', '%d', '%s', '%s', '%s', '%s'];
            $wpdb->insert($rows_table, $payload, $formats);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[KIT_Sync_Run_Log] record_row failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Close a run row. $counts contains per-action totals so the runs grid in
     * the admin UI can show "12 inserted / 3 updated / 1 errored" without
     * re-aggregating from the rows table on every page load.
     *
     * @param int                                                                                                                $run_id
     * @param array{total:int,inserted:int,updated:int,skipped:int,errored:int} $counts
     * @param string                                                                                                             $status   'ok' | 'partial' | 'failed'
     * @param string                                                                                                             $message  optional summary
     */
    public static function finish_run(int $run_id, array $counts, string $status = 'ok', string $message = ''): void
    {
        if ($run_id <= 0) {
            return;
        }
        global $wpdb;

        $runs_table = $wpdb->prefix . self::TABLE_RUNS;
        $wpdb->update(
            $runs_table,
            [
                'finished_at'   => current_time('mysql'),
                'status'        => substr($status, 0, 20),
                'rows_total'    => (int) ($counts['total'] ?? 0),
                'rows_inserted' => (int) ($counts['inserted'] ?? 0),
                'rows_updated'  => (int) ($counts['updated'] ?? 0),
                'rows_skipped'  => (int) ($counts['skipped'] ?? 0),
                'rows_errored'  => (int) ($counts['errored'] ?? 0),
                'message'       => $message !== '' ? substr($message, 0, 65535) : null,
            ],
            ['id' => $run_id],
            ['%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s'],
            ['%d']
        );
    }

    /**
     * Most recent runs, newest first. Used by the sync-runs admin grid.
     *
     * @return array<int, object>
     */
    public static function recent_runs(int $limit = 50, string $entity = ''): array
    {
        global $wpdb;
        $runs_table = $wpdb->prefix . self::TABLE_RUNS;
        $limit = max(1, min(500, $limit));

        if ($entity !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$runs_table} WHERE entity = %s ORDER BY started_at DESC LIMIT %d",
                $entity,
                $limit
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$runs_table} ORDER BY started_at DESC LIMIT %d",
                $limit
            ));
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Row-level audit detail for one run. Used by the drilldown view.
     *
     * @return array<int, object>
     */
    public static function rows_for_run(int $run_id, string $action_filter = ''): array
    {
        global $wpdb;
        $rows_table = $wpdb->prefix . self::TABLE_ROWS;

        if ($action_filter !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$rows_table} WHERE run_id = %d AND action = %s ORDER BY id ASC",
                $run_id,
                $action_filter
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$rows_table} WHERE run_id = %d ORDER BY id ASC",
                $run_id
            ));
        }

        return is_array($rows) ? $rows : [];
    }

    public static function get_run(int $run_id)
    {
        global $wpdb;
        $runs_table = $wpdb->prefix . self::TABLE_RUNS;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$runs_table} WHERE id = %d", $run_id));
    }
}
