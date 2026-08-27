<?php
/**
 * Resolve kit_waybills.delivery_id from Google Sheet waybill rows.
 *
 * Shared by run_google_sheet_seed() and KIT_Waybill_Seeder so truck assignment
 * uses the same rules (reference → sheet truck id → DB id → warehouse).
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Sheet_Delivery_Resolve
{
    /**
     * Build lookup tables once per seed/sync pass.
     *
     * @param array<int, array<int, string>>|null $kit_deliveries_sheet_rows Optional kit_deliveries tab (row 0 = headers).
     * @return array<string, mixed>
     */
    public static function build_cache(?array $kit_deliveries_sheet_rows = null): array
    {
        global $wpdb;

        $prefix = $wpdb->prefix;
        $deliveries_t = $prefix . 'kit_deliveries';
        $today = date('Y-m-d');

        $seed_owner = function_exists('kit_get_seed_owner_user_id')
            ? (int) kit_get_seed_owner_user_id()
            : max(1, (int) get_current_user_id());
        if ($seed_owner <= 0) {
            $seed_owner = 1;
        }

        $warehouse_fallback_delivery_id = (int) $wpdb->get_var(
            "SELECT id FROM {$deliveries_t} WHERE delivery_reference = 'pending' LIMIT 1"
        );
        if ($warehouse_fallback_delivery_id <= 0) {
            $wpdb->insert(
                $deliveries_t,
                [
                    'delivery_reference' => 'pending',
                    'direction_id' => 1,
                    'destination_city_id' => 1,
                    'dispatch_date' => $today,
                    'driver_id' => 0,
                    'status' => 'scheduled',
                    'created_by' => $seed_owner,
                ],
                ['%s', '%d', '%d', '%s', '%d', '%s', '%d']
            );
            $warehouse_fallback_delivery_id = (int) $wpdb->insert_id;
        }

        $ref_to_delivery_id = [];
        $valid_delivery_ids = [];

        foreach ($wpdb->get_results("SELECT id, delivery_reference FROM {$deliveries_t} ORDER BY id ASC", ARRAY_A) ?: [] as $d) {
            $id = (int) $d['id'];
            $valid_delivery_ids[$id] = true;

            $dr = trim((string) ($d['delivery_reference'] ?? ''));
            if ($dr !== '') {
                $ref_to_delivery_id[$dr] = $id;
                $ref_to_delivery_id[strtoupper($dr)] = $id;
            }
        }

        $sheet_ref_by_id = self::parse_kit_deliveries_sheet_refs($kit_deliveries_sheet_rows);
        $africa = self::load_africa_waybills_maps();

        return [
            'ref_to_delivery_id' => $ref_to_delivery_id,
            'valid_delivery_ids' => $valid_delivery_ids,
            'warehouse_fallback_delivery_id' => $warehouse_fallback_delivery_id,
            'sheet_ref_by_id' => $sheet_ref_by_id,
            'waybill_to_ref' => $africa['by_waybill'],
            'trip_to_ref' => $africa['by_trip'],
        ];
    }

    /**
     * AfricaWaybills Deliveries tab: waybill number lists and Trip # → DEL-ref.
     * Live kit_waybills.delivery_id is a previous DB id and must not be trusted.
     *
     * @return array{by_waybill:array<string,string>,by_trip:array<int,string>,by_meta:array<string,array{driver:string,date:string,sheet_id:int}>}
     */
    public static function load_africa_waybills_maps(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }
        $empty = ['by_waybill' => [], 'by_trip' => [], 'by_meta' => []];
        if (!class_exists('Courier_Google_Sheets') || !Courier_Google_Sheets::is_configured()) {
            $cached = $empty;
            return $cached;
        }
        $sid = method_exists('Courier_Google_Sheets', 'get_drivers_spreadsheet_id')
            ? (string) Courier_Google_Sheets::get_drivers_spreadsheet_id()
            : '';
        if ($sid === '') {
            $cached = $empty;
            return $cached;
        }
        try {
            $rows = Courier_Google_Sheets::get_values($sid, 'A1:L200', 'Deliveries');
        } catch (Throwable $e) {
            $cached = $empty;
            return $cached;
        }
        $cached = self::parse_africa_waybills_delivery_rows(is_array($rows) ? $rows : []);
        return $cached;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return array{by_waybill:array<string,string>,by_trip:array<int,string>,by_meta:array<string,array{driver:string,date:string,sheet_id:int}>}
     */
    public static function parse_africa_waybills_delivery_rows(array $rows): array
    {
        $empty = ['by_waybill' => [], 'by_trip' => [], 'by_meta' => []];
        if (count($rows) < 2) {
            return $empty;
        }

        $header = [];
        foreach ($rows[0] as $i => $c) {
            $h = strtolower(trim((string) $c));
            $h = str_replace(' ', '_', $h);
            if ($h !== '') {
                $header[$h] = (int) $i;
            }
        }

        if (isset($header['waybills']) && (isset($header['trip_name']) || isset($header['waybill_count']))) {
            return self::parse_flat_africa_deliveries_rows($rows, $header);
        }

        return self::parse_block_africa_deliveries_rows($rows);
    }

    /**
     * Live 08600AfricaWaybills Deliveries tab: one row per trip
     * (Driver, Dispatch Date, Trip Name, Trip, Waybill_count, Waybills).
     *
     * @param array<int, array<int, mixed>> $rows
     * @param array<string, int> $header
     * @return array{by_waybill:array<string,string>,by_trip:array<int,string>,by_meta:array<string,array{driver:string,date:string,sheet_id:int,stated_count:int}>}
     */
    private static function parse_flat_africa_deliveries_rows(array $rows, array $header): array
    {
        $by_waybill = [];
        $by_trip = [];
        $by_meta = [];
        $ref_i = $header['trip_name'] ?? 3;
        $trip_i = $header['trip'] ?? 4;
        $count_i = $header['waybill_count'] ?? 5;
        $list_i = $header['waybills'] ?? 6;
        $driver_i = $header['driver'] ?? 1;
        $date_i = $header['dispatch_date'] ?? 2;

        foreach (array_slice($rows, 1) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = strtoupper(trim((string) ($row[$ref_i] ?? '')));
            if ($ref === '' || !preg_match('/^DEL-\d{8}-\d{3}$/', $ref)) {
                continue;
            }
            $trip = (int) preg_replace('/[^0-9]/', '', (string) ($row[$trip_i] ?? ''));
            if ($trip > 0 && $trip < 1000) {
                $by_trip[$trip] = $ref;
            }
            $stated = (int) preg_replace('/[^0-9]/', '', (string) ($row[$count_i] ?? ''));
            $by_meta[$ref] = [
                'driver' => trim((string) ($row[$driver_i] ?? '')),
                'date' => trim((string) ($row[$date_i] ?? '')),
                'sheet_id' => $trip,
                'stated_count' => $stated,
            ];
            foreach (self::extract_waybill_nos_from_list((string) ($row[$list_i] ?? '')) as $wb) {
                $by_waybill[$wb] = $ref;
            }
        }

        return ['by_waybill' => $by_waybill, 'by_trip' => $by_trip, 'by_meta' => $by_meta];
    }

    /**
     * Legacy two-row Trip / Waybills blocks (Code.gs trip membership layout).
     *
     * @param array<int, array<int, mixed>> $rows
     * @return array{by_waybill:array<string,string>,by_trip:array<int,string>,by_meta:array<string,array{driver:string,date:string,sheet_id:int,stated_count:int}>}
     */
    private static function parse_block_africa_deliveries_rows(array $rows): array
    {
        $by_waybill = [];
        $by_trip = [];
        $by_meta = [];
        $pending_driver = '';
        $pending_date = '';

        foreach (array_slice($rows, 1) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = strtolower(trim((string) ($row[1] ?? '')));
            if ($label === 'trip') {
                $pending_driver = trim((string) ($row[3] ?? ''));
                $pending_date = trim((string) ($row[4] ?? ''));
                continue;
            }

            $ref = '';
            foreach ($row as $cell) {
                $c = strtoupper(trim((string) $cell));
                if (preg_match('/^DEL-\d{8}-\d{3}$/', $c)) {
                    $ref = $c;
                    break;
                }
            }
            if ($ref === '') {
                continue;
            }

            $trip = (int) preg_replace('/[^0-9]/', '', (string) ($row[8] ?? ($row[4] ?? '')));
            if ($trip > 0 && $trip < 1000) {
                $by_trip[$trip] = $ref;
            }

            $stated = (int) preg_replace('/[^0-9]/', '', (string) ($row[6] ?? ($row[2] ?? '')));
            $by_meta[$ref] = [
                'driver' => $pending_driver,
                'date' => $pending_date,
                'sheet_id' => $trip,
                'stated_count' => $stated,
            ];

            $list = '';
            for ($c = 3; $c <= 6; $c++) {
                $t = trim((string) ($row[$c] ?? ''));
                if ($t !== '' && !preg_match('/^DEL-\d{8}-\d{3}$/i', $t)) {
                    $list .= ($list === '' ? '' : ', ') . $t;
                }
            }
            foreach (self::extract_waybill_nos_from_list($list) as $wb) {
                $by_waybill[$wb] = $ref;
            }
        }

        return ['by_waybill' => $by_waybill, 'by_trip' => $by_trip, 'by_meta' => $by_meta];
    }

    /**
     * @return array<int, string>
     */
    private static function extract_waybill_nos_from_list(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $part) {
            $wb = preg_replace('/[^0-9]/', '', (string) $part);
            if ($wb !== null && strlen($wb) >= 4 && strlen($wb) <= 6) {
                $out[$wb] = $wb;
            }
        }
        return array_values($out);
    }

    /**
     * Create kit_deliveries rows for AfricaWaybills DEL-refs missing from the live tab.
     * Live kit_deliveries dropped DEL-20260213-001 (id 17); the waybill list still owns those 53.
     *
     * @return array{created:int,existing:int}
     */
    public static function ensure_africa_trips_in_db(): array
    {
        global $wpdb;

        $maps = self::load_africa_waybills_maps();
        $refs = array_unique(array_values($maps['by_waybill']));
        $meta = is_array($maps['by_meta'] ?? null) ? $maps['by_meta'] : [];
        $stats = ['created' => 0, 'existing' => 0];
        if ($refs === []) {
            return $stats;
        }

        $table = $wpdb->prefix . 'kit_deliveries';
        $drivers_t = $wpdb->prefix . 'kit_drivers';
        $seed_owner = function_exists('kit_get_seed_owner_user_id')
            ? (int) kit_get_seed_owner_user_id()
            : max(1, (int) get_current_user_id());
        if ($seed_owner <= 0) {
            $seed_owner = 1;
        }

        $template = $wpdb->get_row(
            "SELECT direction_id, destination_city_id FROM {$table}
             WHERE delivery_reference != 'pending' ORDER BY id ASC LIMIT 1",
            ARRAY_A
        );
        $direction_id = (int) ($template['direction_id'] ?? 2);
        $city_id = (int) ($template['destination_city_id'] ?? 1);
        if ($direction_id <= 0) {
            $direction_id = 2;
        }
        if ($city_id <= 0) {
            $city_id = 1;
        }
        if (class_exists('KIT_Routes')) {
            $reconciled = KIT_Routes::reconcile_delivery_route($direction_id, $city_id);
            $direction_id = (int) $reconciled['direction_id'];
            $city_id = (int) $reconciled['destination_city_id'];
        }

        foreach ($refs as $ref) {
            $ref = strtoupper(trim((string) $ref));
            if ($ref === '' || !preg_match('/^DEL-\d{8}-\d{3}$/', $ref)) {
                continue;
            }

            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE UPPER(delivery_reference) = %s LIMIT 1",
                $ref
            ));
            if ($exists > 0) {
                $stats['existing']++;
                continue;
            }

            $m = is_array($meta[$ref] ?? null) ? $meta[$ref] : [];
            $driver_name = trim((string) ($m['driver'] ?? ''));
            $driver_id = 0;
            if ($driver_name !== '') {
                $driver_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$drivers_t} WHERE LOWER(TRIM(name)) = LOWER(%s) LIMIT 1",
                    $driver_name
                ));
            }

            $date = '';
            if (function_exists('kit_seed_normalize_dispatch_date')) {
                $date = kit_seed_normalize_dispatch_date((string) ($m['date'] ?? ''), $ref);
            } elseif (function_exists('kit_seed_date_from_delivery_ref')) {
                $date = kit_seed_date_from_delivery_ref($ref);
            }
            if ($date === '') {
                $date = current_time('Y-m-d');
            }

            $insert = [
                'delivery_reference' => $ref,
                'direction_id' => $direction_id,
                'destination_city_id' => $city_id,
                'dispatch_date' => $date,
                'driver_id' => $driver_id,
                'status' => 'scheduled',
                'created_by' => $seed_owner,
            ];
            $formats = ['%s', '%d', '%d', '%s', '%d', '%s', '%d'];

            $sheet_id = (int) ($m['sheet_id'] ?? 0);
            if ($sheet_id > 0) {
                $taken = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE id = %d LIMIT 1",
                    $sheet_id
                ));
                if ($taken <= 0) {
                    $insert = ['id' => $sheet_id] + $insert;
                    $formats = array_merge(['%d'], $formats);
                }
            }

            $wpdb->insert($table, $insert, $formats);
            $ok = ((int) $wpdb->insert_id > 0)
                || ($sheet_id > 0 && (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE id = %d LIMIT 1",
                    $sheet_id
                )) === $sheet_id);
            if ($ok) {
                $stats['created']++;
            }
        }

        return $stats;
    }

    /**
     * Re-point kit_waybills.delivery_id from AfricaWaybills Deliveries lists.
     *
     * @return array{updated:int,listed:int}
     */
    public static function apply_africa_waybill_lists_to_db(): array
    {
        global $wpdb;

        $maps = self::load_africa_waybills_maps();
        $by_waybill = $maps['by_waybill'];
        $stats = ['updated' => 0, 'listed' => count($by_waybill)];
        if ($by_waybill === []) {
            return $stats;
        }

        $ref_to_id = [];
        $deliveries_t = $wpdb->prefix . 'kit_deliveries';
        $waybills_t = $wpdb->prefix . 'kit_waybills';
        foreach ($wpdb->get_results("SELECT id, delivery_reference FROM {$deliveries_t}", ARRAY_A) ?: [] as $d) {
            $ref = strtoupper(trim((string) ($d['delivery_reference'] ?? '')));
            if ($ref !== '') {
                $ref_to_id[$ref] = (int) $d['id'];
            }
        }

        foreach ($by_waybill as $wb => $ref) {
            $did = (int) ($ref_to_id[strtoupper($ref)] ?? 0);
            if ($did <= 0) {
                continue;
            }
            $done = $wpdb->query($wpdb->prepare(
                "UPDATE {$waybills_t} SET delivery_id = %d WHERE waybill_no = %s AND IFNULL(delivery_id, 0) != %d",
                $did,
                (string) $wb,
                $did
            ));
            if (is_int($done) && $done > 0) {
                $stats['updated'] += $done;
            }
        }

        $warehouse_id = (int) $wpdb->get_var(
            "SELECT id FROM {$deliveries_t} WHERE delivery_reference = 'pending' LIMIT 1"
        );
        $unassigned = 0;
        if ($warehouse_id > 0) {
            $listed_by_ref = [];
            foreach ($by_waybill as $wb => $ref) {
                $listed_by_ref[strtoupper((string) $ref)][] = (string) $wb;
            }
            foreach ($ref_to_id as $ref => $did) {
                if ($did <= 0 || $did === $warehouse_id || !preg_match('/^DEL-\d{8}-\d{3}$/', $ref)) {
                    continue;
                }
                $keep = $listed_by_ref[$ref] ?? [];
                if ($keep === []) {
                    $moved = $wpdb->query($wpdb->prepare(
                        "UPDATE {$waybills_t} SET delivery_id = %d WHERE delivery_id = %d",
                        $warehouse_id,
                        $did
                    ));
                    if (is_int($moved) && $moved > 0) {
                        $unassigned += $moved;
                    }
                    continue;
                }
                $placeholders = implode(',', array_fill(0, count($keep), '%s'));
                $sql = "UPDATE {$waybills_t} SET delivery_id = %d WHERE delivery_id = %d AND waybill_no NOT IN ($placeholders)";
                $moved = $wpdb->query($wpdb->prepare($sql, array_merge([$warehouse_id, $did], $keep)));
                if (is_int($moved) && $moved > 0) {
                    $unassigned += $moved;
                }
            }
        }
        $stats['unassigned'] = $unassigned;

        return $stats;
    }

    /**
     * @param array<int, array<int, string>>|null $rows
     * @return array<int, string> Sheet row id (col A) → delivery_reference (col C)
     */
    private static function parse_kit_deliveries_sheet_refs(?array $rows): array
    {
        if (empty($rows) || count($rows) < 2) {
            return [];
        }

        $header = array_map(function ($c) {
            $h = strtolower(trim((string) $c));
            $h = preg_replace('/^#+\s*/', '', $h);
            return str_replace(' ', '_', $h);
        }, $rows[0]);

        $col = [];
        foreach ($header as $i => $h) {
            if ($h !== '') {
                $col[$h] = $i;
            }
        }

        $id_idx = $col['id'] ?? 0;
        $ref_idx = $col['delivery_reference'] ?? ($col['delivery_ref'] ?? 2);

        $map = [];
        foreach (array_slice($rows, 1) as $row) {
            $id = self::parse_delivery_id_cell($row[$id_idx] ?? '');
            $ref = trim((string) ($row[$ref_idx] ?? ''));
            if ($id > 0 && $ref !== '' && preg_match('/^DEL-\d{8}-\d{3}$/i', $ref)) {
                $map[$id] = $ref;
            }
        }

        return $map;
    }

    /**
     * Waybills Driver column (or status text) means warehouse — not a truck trip.
     * Matches Code.gs isWarehouseFromWaybillsColumnA_ / isNonDriverName_("warehouse").
     */
    public static function is_warehouse_driver_label(string $driver): bool
    {
        $driver = trim($driver);
        if ($driver === '') {
            return false;
        }
        if (function_exists('kit_seed_is_non_driver_name') && kit_seed_is_non_driver_name($driver)) {
            // Only the warehouse sentinel — not cancel/duplicate/etc.
            return (bool) preg_match('/\bwarehouse\b/i', $driver);
        }

        return (bool) preg_match('/\bwarehouse\b/i', $driver);
    }

    /**
     * @param array<int, mixed>  $row
     * @param array<string, int> $col
     * @param array<string, mixed> $cache From build_cache()
     * @return array{delivery_id:int, force_warehouse:bool}
     */
    public static function resolve(array $row, array $col, array $cache): array
    {
        $ref_to_delivery_id = $cache['ref_to_delivery_id'] ?? [];
        $valid_delivery_ids = $cache['valid_delivery_ids'] ?? [];
        $warehouse_fallback = (int) ($cache['warehouse_fallback_delivery_id'] ?? 0);
        $sheet_ref_by_id = $cache['sheet_ref_by_id'] ?? [];

        // Driver = "Warehouse" always wins over a stale kit_waybills.delivery_id
        // (merge used to overlay truck ids onto blank Waybills Delivery cells).
        $driver = self::cell($row, $col, ['driver', 'driver_name', 'truck_driver']);
        if (self::is_warehouse_driver_label($driver) && $warehouse_fallback > 0) {
            return [
                'delivery_id' => $warehouse_fallback,
                'force_warehouse' => true,
            ];
        }

        $delivery_id = 0;
        $waybill_to_ref = is_array($cache['waybill_to_ref'] ?? null) ? $cache['waybill_to_ref'] : [];
        $trip_to_ref = is_array($cache['trip_to_ref'] ?? null) ? $cache['trip_to_ref'] : [];

        $wb_raw = self::cell($row, $col, [
            'parcel_id', 'waybill_no', 'waybill_#', 'wb_no', 'waybill_number', 'waybill',
        ]);
        $wb_key = preg_replace('/[^0-9]/', '', $wb_raw) ?? '';
        if ($wb_key !== '' && isset($waybill_to_ref[$wb_key])) {
            $listed_ref = strtoupper((string) $waybill_to_ref[$wb_key]);
            if (isset($ref_to_delivery_id[$listed_ref])) {
                return [
                    'delivery_id' => (int) $ref_to_delivery_id[$listed_ref],
                    'force_warehouse' => false,
                ];
            }
        }

        $delRef = trim(self::cell($row, $col, ['delivery_reference', 'delivery_ref', 'del_ref', 'truck_delivery_ref']));
        if ($delRef !== '') {
            if (isset($ref_to_delivery_id[$delRef])) {
                $delivery_id = (int) $ref_to_delivery_id[$delRef];
            } else {
                $delRefU = strtoupper($delRef);
                if (isset($ref_to_delivery_id[$delRefU])) {
                    $delivery_id = (int) $ref_to_delivery_id[$delRefU];
                }
            }
        }

        if ($delivery_id === 0) {
            $raw_ids = [
                self::cell($row, $col, ['delivery_id']),
                self::cell($row, $col, ['del_id']),
            ];
            $delivery_col = self::cell($row, $col, ['delivery']);
            if ($delivery_col !== '' && self::parse_delivery_id_cell($delivery_col) > 0) {
                $raw_ids[] = $delivery_col;
            }

            foreach ($raw_ids as $raw) {
                $nid = self::parse_delivery_id_cell($raw);
                if ($nid <= 0) {
                    continue;
                }

                // AfricaWaybills Trip # (6 = DEL-20260612-001) is not kit_deliveries.id.
                if (isset($trip_to_ref[$nid])) {
                    $aref = strtoupper((string) $trip_to_ref[$nid]);
                    if (isset($ref_to_delivery_id[$aref])) {
                        $delivery_id = (int) $ref_to_delivery_id[$aref];
                        break;
                    }
                }

                if ($trip_to_ref !== []) {
                    continue;
                }

                if (isset($sheet_ref_by_id[$nid])) {
                    $sheet_ref = $sheet_ref_by_id[$nid];
                    if (isset($ref_to_delivery_id[$sheet_ref])) {
                        $delivery_id = (int) $ref_to_delivery_id[$sheet_ref];
                        break;
                    }
                    $sheet_ref_u = strtoupper($sheet_ref);
                    if (isset($ref_to_delivery_id[$sheet_ref_u])) {
                        $delivery_id = (int) $ref_to_delivery_id[$sheet_ref_u];
                        break;
                    }
                }

                if (!empty($valid_delivery_ids[$nid])) {
                    $delivery_id = $nid;
                    break;
                }
            }
        }

        $force_warehouse = false;
        if ($delivery_id === 0 && $warehouse_fallback > 0) {
            $delivery_id = $warehouse_fallback;
            $force_warehouse = true;
        }

        return [
            'delivery_id' => $delivery_id,
            'force_warehouse' => $force_warehouse,
        ];
    }

    /**
     * @param array<int, mixed>  $row
     * @param array<string, int> $col
     * @param string|array<string> $keys
     */
    public static function cell(array $row, array $col, $keys, string $default = ''): string
    {
        $keys = is_array($keys) ? $keys : [$keys];
        foreach ($keys as $k) {
            if (!isset($col[$k])) {
                continue;
            }
            $v = trim((string) ($row[$col[$k]] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return $default;
    }

    /**
     * Parse a sheet truck/delivery id; reject scientific notation and garbage.
     */
    public static function parse_delivery_id_cell($raw): int
    {
        $s = trim((string) $raw);
        if ($s === '' || strtolower($s) === 'null') {
            return 0;
        }
        if (stripos($s, 'e') !== false && preg_match('/e[+\-]?\d+/i', $s)) {
            return 0;
        }
        if (!is_numeric($s)) {
            return 0;
        }
        $n = (int) preg_replace('/[^0-9]/', '', $s);
        if ($n <= 0 || $n > 99999) {
            return 0;
        }

        return $n;
    }

    /**
     * @param array<int, string> $header_row
     * @return array<string, int>
     */
    public static function header_to_col_map(array $header_row): array
    {
        $col = [];
        foreach ($header_row as $i => $h) {
            $key = strtolower(trim(str_replace(' ', '_', (string) $h)));
            if ($key !== '' && !isset($col[$key])) {
                $col[$key] = $i;
            }
        }

        return $col;
    }
}
