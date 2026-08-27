<?php
/**
 * Seed wp_kit_deliveries from the kit_deliveries Google Sheet tab only.
 *
 * The sheet is the single source of truth for delivery trips. Waybill seeding
 * must never create deliveries — it only references rows imported here.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('KIT_Routes') && defined('COURIER_FINANCE_PLUGIN_PATH')) {
    require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/routes/routes-functions.php';
}

class KIT_Delivery_Seeder
{
    /** @var string[] */
    private const ALLOWED_STATUS = ['scheduled', 'in_transit', 'delivered', 'unconfirmed'];

    /**
     * Drop trailing empty rows and stop at the first fully blank data row.
     *
     * @param array<int, array<int, string>> $rows
     * @return array<int, array<int, string>>
     */
    public static function trim_sheet_rows(array $rows): array
    {
        if (count($rows) < 2) {
            return $rows;
        }

        $out = [$rows[0]];
        foreach (array_slice($rows, 1) as $row) {
            $has_data = false;
            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    $has_data = true;
                    break;
                }
            }
            if (!$has_data) {
                break;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Import kit_deliveries tab rows into wp_kit_deliveries.
     *
     * @param array<int, array<int, string>> $rows Row 0 = headers
     * @param bool $replace_existing When true, delete all non-pending deliveries first (setup seed).
     * @return array{inserted:int,updated:int,skipped:int,deleted:int,trip_map:array<int,int>}
     */
    public static function seed_from_sheet_rows(array $rows, bool $replace_existing = false): array
    {
        global $wpdb;

        $stats = [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'deleted' => 0,
            'trip_map' => [],
        ];

        $rows = self::trim_sheet_rows($rows);

        if (empty($rows) || count($rows) < 2) {
            return $stats;
        }

        $col = self::header_map($rows[0]);
        if ($col === null) {
            return $stats;
        }

        $table = $wpdb->prefix . 'kit_deliveries';
        $seed_owner = function_exists('kit_get_seed_owner_user_id')
            ? (int) kit_get_seed_owner_user_id()
            : max(1, (int) get_current_user_id());
        if ($seed_owner <= 0) {
            $seed_owner = 1;
        }

        // Waybills store sheet delivery ids as kit_deliveries.id. Inserts must
        // therefore keep the sheet's id column (trip_id), not rely on
        // AUTO_INCREMENT — an empty table can still have AI >> 1 after prior deletes.
        $sheet_trip_ids = [];

        foreach (array_slice($rows, 1) as $row) {
            $parsed = self::parse_row($row, $col, $seed_owner);
            if ($parsed === null) {
                $stats['skipped']++;
                continue;
            }

            if ($parsed['trip_id'] > 0) {
                $sheet_trip_ids[] = $parsed['trip_id'];
            }

            $update_fields = [
                'delivery_reference' => $parsed['delivery_reference'],
                'direction_id' => $parsed['direction_id'],
                'destination_city_id' => $parsed['destination_city_id'],
                'dispatch_date' => $parsed['dispatch_date'],
                'driver_id' => $parsed['driver_id'],
                'status' => $parsed['status'],
            ];
            $update_formats = ['%s', '%d', '%d', '%s', '%d', '%s'];

            $existing_id = 0;
            if ($parsed['trip_id'] > 0) {
                $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE id = %d LIMIT 1",
                    $parsed['trip_id']
                ));
            }
            if ($existing_id <= 0) {
                $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE delivery_reference = %s LIMIT 1",
                    $parsed['delivery_reference']
                ));
            }

            if ($existing_id > 0) {
                $updated = $wpdb->update(
                    $table,
                    $update_fields,
                    ['id' => $existing_id],
                    $update_formats,
                    ['%d']
                );
                if ($updated > 0) {
                    $stats['updated']++;
                }
                if ($parsed['trip_id'] > 0) {
                    $stats['trip_map'][$parsed['trip_id']] = $existing_id;
                }
                continue;
            }

            $insert_row = $update_fields + ['created_by' => $parsed['created_by']];
            $insert_formats = array_merge($update_formats, ['%d']);
            if ($parsed['trip_id'] > 0) {
                $insert_row = ['id' => $parsed['trip_id']] + $insert_row;
                $insert_formats = array_merge(['%d'], $insert_formats);
            }

            $wpdb->insert($table, $insert_row, $insert_formats);

            if ($wpdb->insert_id || ($parsed['trip_id'] > 0 && self::row_exists($table, $parsed['trip_id']))) {
                $db_id = $parsed['trip_id'] > 0 ? $parsed['trip_id'] : (int) $wpdb->insert_id;
                $stats['inserted']++;
                if ($parsed['trip_id'] > 0) {
                    $stats['trip_map'][$parsed['trip_id']] = $db_id;
                }
            }
        }

        // replace_existing = sheet is source of truth: drop trips not on the sheet
        // (keep the warehouse "pending" fallback row).
        if ($replace_existing && !empty($sheet_trip_ids)) {
            $ids = array_values(array_unique(array_map('intval', $sheet_trip_ids)));
            $in = implode(',', $ids);
            $stats['deleted'] = (int) $wpdb->query(
                "DELETE FROM {$table}
                 WHERE delivery_reference != 'pending'
                   AND id NOT IN ({$in})"
            );
        }

        return $stats;
    }

    private static function row_exists(string $table, int $id): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d LIMIT 1",
            $id
        )) === $id;
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    public static function count_valid_rows(array $rows): int
    {
        $rows = self::trim_sheet_rows($rows);

        if (empty($rows) || count($rows) < 2) {
            return 0;
        }
        $col = self::header_map($rows[0]);
        if ($col === null) {
            return 0;
        }
        $seed_owner = function_exists('kit_get_seed_owner_user_id') ? (int) kit_get_seed_owner_user_id() : 1;
        $count = 0;
        foreach (array_slice($rows, 1) as $row) {
            if (self::parse_row($row, $col, $seed_owner) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int, string> $headerRow
     * @return array<string, int>|null
     */
    private static function header_map(array $headerRow): ?array
    {
        $col = [];
        foreach ($headerRow as $i => $h) {
            $key = self::normalize_header((string) $h);
            if ($key !== '' && !isset($col[$key])) {
                $col[$key] = (int) $i;
            }
        }

        if (!isset($col['delivery_reference']) && !isset($col['delivery_ref'])) {
            return null;
        }

        return $col;
    }

    private static function normalize_header(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/^#+\s*/', '', $header);
        $header = preg_replace('/\s+/', '_', $header);

        return trim($header, '_');
    }

    /**
     * @param array<int, string> $row
     * @param array<string, int> $col
     * @return array{trip_id:int,delivery_reference:string,direction_id:int,destination_city_id:int,dispatch_date:string,driver_id:int,status:string,created_by:int}|null
     */
    private static function parse_row(array $row, array $col, int $seed_owner): ?array
    {
        $ref_idx = $col['delivery_reference'] ?? $col['delivery_ref'];
        $ref = trim((string) ($row[$ref_idx] ?? ''));
        // Sheet id 29 is the warehouse "pending" trip (referenced by waybills).
        // Accept it; only invent a pending row elsewhere when the sheet lacks one.
        $is_pending = (strcasecmp($ref, 'pending') === 0);
        if ($ref === '' || (!$is_pending && !preg_match('/^DEL-\d{8}-\d{3}$/i', $ref))) {
            return null;
        }

        $trip_id = 0;
        if (isset($col['id'])) {
            $trip_id = self::parse_int_cell($row[$col['id']] ?? '');
        }
        if ($trip_id <= 0 && isset($col['del_id'])) {
            $trip_id = self::parse_int_cell($row[$col['del_id']] ?? '');
        }

        $status = 'scheduled';
        if (isset($col['status'])) {
            $status = trim((string) ($row[$col['status']] ?? 'scheduled'));
        }
        if (!in_array($status, self::ALLOWED_STATUS, true)) {
            $status = 'scheduled';
        }

        $created_by = isset($col['created_by'])
            ? self::parse_int_cell($row[$col['created_by']] ?? '')
            : 0;
        if ($created_by <= 0) {
            $created_by = $seed_owner;
        }

        $driver_id = isset($col['driver_id'])
            ? self::parse_int_cell($row[$col['driver_id']] ?? '')
            : 0;

        $direction_id = isset($col['direction_id'])
            ? self::parse_int_cell($row[$col['direction_id']] ?? '')
            : 0;
        if ($direction_id <= 0) {
            // Most seeded trucks are South Africa → Tanzania when the sheet omits direction_id.
            $direction_id = 2;
        }

        $destination_city_id = isset($col['destination_city_id'])
            ? self::parse_int_cell($row[$col['destination_city_id']] ?? '')
            : 0;

        $dispatch_raw = isset($col['dispatch_date'])
            ? (string) ($row[$col['dispatch_date']] ?? '')
            : '';

        if (class_exists('KIT_Routes')) {
            $reconciled = KIT_Routes::reconcile_delivery_route($direction_id, $destination_city_id);
            $direction_id = $reconciled['direction_id'];
            $destination_city_id = $reconciled['destination_city_id'];
        } else {
            $direction_id = max(1, $direction_id);
            $destination_city_id = max(1, $destination_city_id);
        }

        return [
            'trip_id' => $trip_id,
            'delivery_reference' => $ref,
            'direction_id' => $direction_id,
            'destination_city_id' => $destination_city_id,
            'dispatch_date' => self::normalize_date($dispatch_raw, $ref),
            'driver_id' => $driver_id,
            'status' => $status,
            'created_by' => $created_by,
        ];
    }

    private static function parse_int_cell($raw): int
    {
        $s = trim((string) $raw);
        if ($s === '' || !is_numeric($s)) {
            return 0;
        }

        return max(0, (int) $s);
    }

    private static function normalize_date(string $raw, string $delivery_ref = ''): string
    {
        if (function_exists('kit_seed_normalize_dispatch_date')) {
            return kit_seed_normalize_dispatch_date($raw, $delivery_ref);
        }

        $raw = trim($raw);
        if ($raw === '') {
            return current_time('Y-m-d');
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2]);
        }
        $ts = strtotime($raw);

        return $ts ? date('Y-m-d', $ts) : current_time('Y-m-d');
    }
}
