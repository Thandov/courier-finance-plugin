<?php
/**
 * Sync plugin entities (drivers, waybills, waybill items, customers, deliveries) to Google Sheets.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Courier_Google_Sheets_Sync {
    const AUTO_SYNC_OPTION = 'courier_google_auto_sync_enabled';

    /**
     * Entities whose kit_* tabs are written by the Apps Script Waybills projection
     * (apps-script/). Full DB→sheet push_all would wipe that source of truth.
     *
     * Override with define('COURIER_APPS_SCRIPT_OWNS_KIT_SHEETS', false) only if
     * Apps Script projection is fully retired and WP owns those tabs again.
     *
     * @var string[]
     */
    const APPS_SCRIPT_OWNED_ENTITIES = ['drivers', 'customers', 'deliveries', 'waybills'];

    /**
     * True when Apps Script owns Waybills → kit_* sheet projection.
     */
    public static function apps_script_owns_kit_sheets(): bool
    {
        if (defined('COURIER_APPS_SCRIPT_OWNS_KIT_SHEETS')) {
            return (bool) COURIER_APPS_SCRIPT_OWNS_KIT_SHEETS;
        }

        return true;
    }

    /**
     * @param string $entity
     */
    public static function is_apps_script_owned_entity(string $entity): bool
    {
        return self::apps_script_owns_kit_sheets()
            && in_array($entity, self::APPS_SCRIPT_OWNED_ENTITIES, true);
    }

    /**
     * Normalize a DB or sheet value to "0" or "1" for waybill flag columns (matches kit_google_sheet_parse_bool_int).
     *
     * @param mixed $v
     * @return string
     */
    private static function waybill_flag_for_sheet($v): string
    {
        if ($v === null || $v === false) {
            return '0';
        }
        if ($v === true) {
            return '1';
        }
        if (is_numeric($v)) {
            return ((float) $v != 0.0) ? '1' : '0';
        }
        $s = strtoupper(trim((string) $v));
        if ($s === '' || $s === 'NULL' || $s === 'N/A' || $s === 'NA' || $s === '-') {
            return '0';
        }
        if (in_array($s, ['1', 'TRUE', 'YES', 'Y', 'ON'], true)) {
            return '1';
        }

        return '0';
    }

    /**
     * Auto-sync state (false by default for safer testing).
     */
    public static function is_auto_sync_enabled() {
        return (bool) get_option(self::AUTO_SYNC_OPTION, 0);
    }

    /**
     * True when Google is configured and auto-sync is enabled.
     */
    public static function can_auto_sync() {
        return self::can_sync() && self::is_auto_sync_enabled();
    }

    /** Sync driver add (append to sheet) */
    public static function sync_driver_add($data) {
        if (!self::can_auto_sync()) {
            return false;
        }
        try {
            $sheet = self::get_sheet_name('drivers');
            $row = [
                '', $data['name'] ?? '', $data['phone'] ?? '', $data['email'] ?? '',
                $data['license_number'] ?? '', $data['is_active'] ?? 1,
                current_time('mysql'), current_time('mysql'),
            ];
            Courier_Google_Sheets::append_values([$row], '', 'A:H', $sheet);
            return true;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Driver sync failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /** Sync driver delete (remove from sheet) */
    public static function sync_driver_delete($driver_name) {
        if (!self::can_auto_sync()) {
            return false;
        }
        try {
            Courier_Google_Sheets::delete_row_by_value(trim((string) $driver_name), self::get_sheet_name('drivers'), 1);
            return true;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Driver delete sync failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    public static function get_sheet_name($entity) {
        $map = [
            'drivers'       => defined('COURIER_GOOGLE_DRIVERS_SHEET') ? COURIER_GOOGLE_DRIVERS_SHEET : 'kit_drivers',
            'waybills'      => defined('COURIER_GOOGLE_WAYBILLS_SHEET') ? COURIER_GOOGLE_WAYBILLS_SHEET : 'kit_waybills',
            'waybill_items' => defined('COURIER_GOOGLE_WAYBILL_ITEMS_SHEET') ? COURIER_GOOGLE_WAYBILL_ITEMS_SHEET : 'kit_waybill_items',
            'customers'     => defined('COURIER_GOOGLE_CUSTOMERS_SHEET') ? COURIER_GOOGLE_CUSTOMERS_SHEET : 'kit_customers',
            'deliveries'    => defined('COURIER_GOOGLE_DELIVERIES_SHEET') ? COURIER_GOOGLE_DELIVERIES_SHEET : 'kit_deliveries',
        ];
        return $map[$entity] ?? $entity;
    }

    public static function can_sync() {
        return class_exists('Courier_Google_Sheets') && Courier_Google_Sheets::is_configured();
    }

    /**
     * Google Sheets formula matching KIT_Deliveries::generateDeliveryRef() shape: DEL-YYYYMMDD-###.
     * Paste into kit_deliveries column C row 2, then fill down (or keep a copy in settings!A1 as documentation).
     * Uses dispatch_date (column F) when valid; otherwise today. Counter is row order within column C (001, 002, …).
     * Guards blank cells (Sheets treats empty as 0 → TEXT gives 18991230) and bad text in F.
     *
     * @return string Formula beginning with =
     */
    public static function get_delivery_reference_sheet_formula(): string {
        return '="DEL-"&TEXT(IF(ISBLANK(F2),TODAY(),IF(AND(ISNUMBER(F2),F2<1),TODAY(),IF(ISTEXT(F2),IFERROR(DATEVALUE(F2),TODAY()),F2))),"yyyymmdd")&"-"&TEXT(ROWS($C$2:C2),"000")';
    }

    /**
     * Build one kit_deliveries row for push/sync: id, del_id, delivery_reference, … (11 columns).
     *
     * @param object|array $delivery Row from kit_deliveries
     * @return array<int, mixed>
     */
    private static function build_delivery_sheet_row($delivery): array {
        $d = is_array($delivery) ? $delivery : (array) $delivery;
        $id = $d['id'] ?? '';
        return [
            $id,
            $id,
            self::sheet_val($d['delivery_reference'] ?? ''),
            self::sheet_id($d['direction_id'] ?? 0),
            self::sheet_id($d['destination_city_id'] ?? 0),
            self::sheet_val($d['dispatch_date'] ?? ''),
            self::sheet_val($d['truck_number'] ?? ''),
            self::sheet_id($d['driver_id'] ?? 0),
            self::sheet_val($d['status'] ?? ''),
            self::sheet_id($d['created_by'] ?? 0),
            self::sheet_val($d['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<int, string> $headerRow
     * @return array<string, int>|null Maps logical keys to 0-based column index
     */
    private static function parse_kit_deliveries_header_map(array $headerRow): ?array {
        $normalized = [];
        foreach ($headerRow as $i => $h) {
            $key = strtolower(trim(preg_replace('/\s+/', '_', (string) $h)));
            if ($key !== '') {
                $normalized[$key] = $i;
            }
        }
        $refIdx = $normalized['delivery_reference'] ?? $normalized['delivery_ref'] ?? null;
        if ($refIdx === null) {
            return null;
        }
        return [
            'ref' => $refIdx,
            'direction_id' => $normalized['direction_id'] ?? 3,
            'destination_city_id' => $normalized['destination_city_id'] ?? 4,
            'dispatch_date' => $normalized['dispatch_date'] ?? 5,
            'driver_id' => $normalized['driver_id'] ?? 7,
            'status' => $normalized['status'] ?? 8,
            'created_by' => $normalized['created_by'] ?? 9,
        ];
    }

    /**
     * @param mixed $v
     */
    private static function normalize_date_from_sheet($v): string {
        $v = trim((string) $v);
        if ($v === '' || preg_match('/^(warehouse|cancelled|duplicate)$/i', $v)) {
            return current_time('Y-m-d');
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        $ts = strtotime($v);

        return $ts ? date('Y-m-d', $ts) : current_time('Y-m-d');
    }

    /**
     * Legacy 9-col layout (B=ref) or extended layout (C=ref, B=del_id).
     *
     * @param array<int, string> $row
     * @return array{ref:string,direction_id:int,destination_city_id:int,dispatch_date:string,driver_id:?int,status:string,created_by:int}|null
     */
    private static function kit_deliveries_row_to_insert(array $row): ?array {
        $r1 = trim((string) ($row[1] ?? ''));
        $r2 = trim((string) ($row[2] ?? ''));
        if (preg_match('/^DEL-\d{8}-\d{3}$/i', $r2)) {
            $st = trim((string) ($row[8] ?? 'scheduled'));
            $allowed = ['scheduled', 'in_transit', 'delivered', 'unconfirmed'];

            return [
                'ref' => $r2,
                'direction_id' => (int) ($row[3] ?? 1),
                'destination_city_id' => max(1, (int) ($row[4] ?? 1)),
                'dispatch_date' => self::normalize_date_from_sheet($row[5] ?? ''),
                'driver_id' => isset($row[7]) && $row[7] !== '' ? (int) $row[7] : null,
                'status' => in_array($st, $allowed, true) ? $st : 'scheduled',
                'created_by' => (int) ($row[9] ?? get_current_user_id()),
            ];
        }
        if (preg_match('/^DEL-\d{8}-\d{3}$/i', $r1)) {
            $st = trim((string) ($row[6] ?? 'scheduled'));
            $allowed = ['scheduled', 'in_transit', 'delivered', 'unconfirmed'];

            return [
                'ref' => $r1,
                'direction_id' => (int) ($row[2] ?? 1),
                'destination_city_id' => max(1, (int) ($row[3] ?? 1)),
                'dispatch_date' => self::normalize_date_from_sheet($row[4] ?? ''),
                'driver_id' => isset($row[5]) && $row[5] !== '' ? (int) $row[5] : null,
                'status' => in_array($st, $allowed, true) ? $st : 'scheduled',
                'created_by' => (int) ($row[7] ?? get_current_user_id()),
            ];
        }

        return null;
    }

    /**
     * @param array<string, int> $map
     * @param array<int, string> $row
     * @return array{ref:string,direction_id:int,destination_city_id:int,dispatch_date:string,driver_id:?int,status:string,created_by:int}|null
     */
    private static function kit_deliveries_mapped_row_to_insert(array $map, array $row): ?array {
        $ref = trim((string) ($row[$map['ref']] ?? ''));
        if ($ref === '' || !preg_match('/^DEL-\d{8}-\d{3}$/i', $ref)) {
            return null;
        }
        $st = trim((string) ($row[$map['status']] ?? 'scheduled'));
        $allowed = ['scheduled', 'in_transit', 'delivered', 'unconfirmed'];

        return [
            'ref' => $ref,
            'direction_id' => (int) ($row[$map['direction_id']] ?? 1),
            'destination_city_id' => max(1, (int) ($row[$map['destination_city_id']] ?? 1)),
            'dispatch_date' => self::normalize_date_from_sheet($row[$map['dispatch_date']] ?? ''),
            'driver_id' => isset($row[$map['driver_id']]) && $row[$map['driver_id']] !== '' ? (int) $row[$map['driver_id']] : null,
            'status' => in_array($st, $allowed, true) ? $st : 'scheduled',
            'created_by' => (int) ($row[$map['created_by']] ?? get_current_user_id()),
        ];
    }

    /**
     * Insert new kit_deliveries rows from raw sheet rows (row 0 = headers when delivery_reference is present).
     * Used by pull sync and Setup Seed so the kit_deliveries tab is not ignored.
     *
     * @param array<int, array<int, string>> $all
     * @return int Rows inserted
     */
    /**
     * Read kit_deliveries tab for truck id → delivery_reference alignment during waybill seed.
     *
     * @return array<int, array<int, string>>
     */
    public static function fetch_deliveries_sheet_rows(): array {
        if (!self::can_sync()) {
            return [];
        }
        try {
            $sheet = self::get_sheet_name('deliveries');
            $all = Courier_Google_Sheets::get_values('', 'A1:K5000', $sheet);

            return is_array($all) ? $all : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function import_deliveries_from_sheet_rows(array $all): int {
        global $wpdb;
        $prefix = $wpdb->prefix;
        if (empty($all)) {
            return 0;
        }
        $headerRow = $all[0];
        $map = self::parse_kit_deliveries_header_map($headerRow);
        $dataRows = $all;
        if ($map !== null) {
            array_shift($dataRows);
        }
        $seed_owner = function_exists('kit_get_seed_owner_user_id') ? (int) kit_get_seed_owner_user_id() : max(1, (int) get_current_user_id());
        if ($seed_owner <= 0) {
            $seed_owner = 1;
        }
        $count = 0;
        foreach ($dataRows as $row) {
            $insert = $map !== null ? self::kit_deliveries_mapped_row_to_insert($map, $row) : self::kit_deliveries_row_to_insert($row);
            if ($insert === null) {
                continue;
            }
            $ref = $insert['ref'];
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}kit_deliveries WHERE delivery_reference = %s", $ref));
            if ($exists) {
                continue;
            }
            $created_by = (int) ($insert['created_by'] ?? 0);
            if ($created_by <= 0) {
                $created_by = $seed_owner;
            }
            $wpdb->insert($prefix . 'kit_deliveries', [
                'delivery_reference' => $ref,
                'direction_id' => $insert['direction_id'],
                'destination_city_id' => $insert['destination_city_id'],
                'dispatch_date' => $insert['dispatch_date'],
                'driver_id' => $insert['driver_id'],
                'status' => $insert['status'],
                'created_by' => $created_by,
            ]);
            if ($wpdb->insert_id) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Update or delete a delivery row by delivery_reference; try column C (index 2) then B (index 1).
     */
    private static function update_delivery_sheet_row(string $match_value, string $sheet, array $row_data): bool {
        $match_value = trim($match_value);
        if ($match_value === '') {
            return false;
        }
        if (Courier_Google_Sheets::update_row_by_value($match_value, $sheet, $row_data, 2)) {
            return true;
        }

        return Courier_Google_Sheets::update_row_by_value($match_value, $sheet, $row_data, 1);
    }

    private static function delete_delivery_sheet_row(string $delivery_reference): void {
        $ref = trim((string) $delivery_reference);
        if ($ref === '') {
            return;
        }
        $sheet = self::get_sheet_name('deliveries');
        if (!Courier_Google_Sheets::delete_row_by_value($ref, $sheet, 2)) {
            Courier_Google_Sheets::delete_row_by_value($ref, $sheet, 1);
        }
    }

    /** Normalize value for sheet: never write literal "NULL"; use empty string for null/empty. */
    private static function sheet_val($v) {
        if ($v === null || $v === '') {
            return '';
        }
        $s = is_string($v) ? trim($v) : (string) $v;
        return (strtoupper($s) === 'NULL') ? '' : $v;
    }

    /** Strip special characters from phone/customer number: keep only digits and optional leading +. */
    private static function strip_phone_special_chars($v) {
        $s = self::sheet_val($v);
        if ($s === '') {
            return '';
        }
        $s = (string) $s;
        $leading = (strpos($s, '+') === 0) ? '+' : '';
        $digits = preg_replace('/[^0-9]/', '', $s);
        return $leading . $digits;
    }

    /** Integer ID for sheet (country_id, city_id): 0 for null/empty, otherwise cast to int. */
    private static function sheet_id($v) {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_string($v) && strtoupper(trim($v)) === 'NULL') {
            return 0;
        }
        return (int) $v;
    }

    /**
     * Build waybill row for sheet. Order must match sheet headers (42 cols): id (auto increment from DB), parcel_id (always empty), description ... last_updated_at. All values scalar.
     */
    private static function build_waybill_row($w) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $city_name = '';
        $cust_name = '';
        if (!empty($w->city_id)) {
            $city_name = $wpdb->get_var($wpdb->prepare(
                "SELECT city_name FROM {$prefix}kit_operating_cities WHERE id = %d",
                (int) $w->city_id
            ));
        }
        $cust_id = isset($w->customer_id) ? (int) $w->customer_id : 0;
        if ($cust_id > 0) {
            $c = $wpdb->get_row($wpdb->prepare(
                "SELECT c.name, c.surname, co.company_name
                 FROM {$prefix}kit_customers c
                 LEFT JOIN {$prefix}kit_company_customers co ON c.company_id = co.company_id
                 WHERE c.cust_id = %d",
                $cust_id
            ), ARRAY_A);
            if ($c) {
                $cust_name = !empty($c['company_name']) ? trim((string) $c['company_name']) : trim(($c['name'] ?? '') . ' ' . ($c['surname'] ?? ''));
            }
            if ($cust_name === '' && !empty($w->company_id) && class_exists('KIT_Company_Customers')) {
                $co = KIT_Company_Customers::find_by_company_id((int) $w->company_id);
                $cust_name = trim((string) ($co['company_name'] ?? ''));
            }
        }
        $warehouse_val = self::waybill_flag_for_sheet($w->warehouse ?? 0);
        $charge_basis = $w->charge_basis ?? '';
        if (is_object($charge_basis) || is_array($charge_basis)) {
            $charge_basis = '';
        }
        $created_at = isset($w->created_at) && (string) $w->created_at !== '' ? (string) $w->created_at : current_time('mysql');
        $last_updated_at = isset($w->last_updated_at) && (string) $w->last_updated_at !== '' ? (string) $w->last_updated_at : $created_at;
        return [
            $w->id ?? '',                                                                 // A: id (auto increment from DB)
            '',                                                                           // B: parcel_id (always empty)
            (string) ($w->description ?? ''),                                             // C: description
            $w->direction_id ?? '',                                                       // D: direction_id
            (string) ($city_name ?: ''),                                                  // E: city_name_ignore
            $w->city_id ?? '',                                                             // F: city_id (skipped on write)
            $w->delivery_id ?? '',                                                         // G: delivery_id
            (string) ($cust_name ?: ''),                                                  // H: cust_name_ignore
            $w->customer_id ?? '',                                                         // I: customer_id
            (string) ($w->approval ?? ''),                                                 // J: approval
            $w->approval_userid ?? '',                                                     // K: approval_userid
            (string) ($w->waybill_no ?? ''),                                               // L: waybill_no (match for upsert)
            (string) ($w->product_invoice_number ?? ''),                                   // M: product_invoice_number
            $w->product_invoice_amount ?? '',                                              // N: product_invoice_amount
            $w->waybill_items_total ?? '',                                                 // O: waybill_items_total
            $w->misc_total ?? '',                                                          // P: misc_total
            $w->border_clearing_total ?? '',                                                // Q: border_clearing_total
            $w->sad500_amount ?? '',                                                       // R: sad500_amount
            $w->sadc_amount ?? '',                                                         // S: sadc_amount
            $w->international_price_rands ?? '',                                           // T: international_price_rands
            $w->item_length ?? '',                                                          // U: item_length
            $w->item_width ?? '',                                                          // V: item_width
            $w->item_height ?? '',                                                         // W: item_height
            $w->total_mass_kg ?? '',                                                       // X: total_mass_kg
            $w->total_volume ?? '',                                                        // Y: total_volume
            $w->mass_charge ?? '',                                                         // Z: mass_charge
            $w->volume_charge ?? '',                                                       // AA: volume_charge
            (string) $charge_basis,                                                        // AB: charge_basis (scalar only)
            self::waybill_flag_for_sheet($w->vat_include ?? 0),                            // AC: vat_include (1/0)
            $warehouse_val,                                                                // AD: warehouse (1 or 0)
            (string) ($w->miscellaneous ?? ''),                                            // AE: miscellaneous
            self::waybill_flag_for_sheet($w->include_sad500 ?? 0),                         // AF: include_sad500
            self::waybill_flag_for_sheet($w->include_sadc ?? 0),                          // AG: include_sadc
            $w->return_load ?? '',                                                         // AH: return_load
            (string) ($w->tracking_number ?? ''),                                          // AI: tracking_number
            (string) ($w->qr_code_data ?? ''),                                             // AJ: qr_code_data
            $w->created_by ?? '',                                                          // AK: created_by
            $w->last_updated_by ?? '',                                                     // AL: last_updated_by
            (string) ($w->status ?? ''),                                                   // AM: status
            $w->status_userid ?? '',                                                       // AN: status_userid
            $created_at,                                                                   // AO: created_at (never blank)
            $last_updated_at,                                                              // AP: last_updated_at
        ];
    }

    /**
     * Build one kit_waybills sheet row using preloaded city/customer name maps.
     * Used by push_all('waybills') to avoid N DB queries per row.
     *
     * @param object               $w         Row from wp_kit_waybills
     * @param array<int,string>    $city_map  id → city_name
     * @param array<int,string>    $cust_map  cust_id → display name
     * @return array<int, mixed>
     */
    private static function build_waybill_row_fast($w, array $city_map, array $cust_map): array
    {
        $city_name  = $city_map[(int) ($w->city_id ?? 0)] ?? '';
        $cust_name  = $cust_map[(int) ($w->customer_id ?? 0)] ?? '';
        $charge_basis = $w->charge_basis ?? '';
        if (is_object($charge_basis) || is_array($charge_basis)) {
            $charge_basis = '';
        }
        $created_at      = isset($w->created_at) && (string) $w->created_at !== '' ? (string) $w->created_at : current_time('mysql');
        $last_updated_at = isset($w->last_updated_at) && (string) $w->last_updated_at !== '' ? (string) $w->last_updated_at : $created_at;
        return [
            $w->id ?? '',
            '',
            (string) ($w->description ?? ''),
            $w->direction_id ?? '',
            (string) $city_name,
            $w->city_id ?? '',
            $w->delivery_id ?? '',
            (string) $cust_name,
            $w->customer_id ?? '',
            (string) ($w->approval ?? ''),
            $w->approval_userid ?? '',
            (string) ($w->waybill_no ?? ''),
            (string) ($w->product_invoice_number ?? ''),
            $w->product_invoice_amount ?? '',
            $w->waybill_items_total ?? '',
            $w->misc_total ?? '',
            $w->border_clearing_total ?? '',
            $w->sad500_amount ?? '',
            $w->sadc_amount ?? '',
            $w->international_price_rands ?? '',
            $w->item_length ?? '',
            $w->item_width ?? '',
            $w->item_height ?? '',
            $w->total_mass_kg ?? '',
            $w->total_volume ?? '',
            $w->mass_charge ?? '',
            $w->volume_charge ?? '',
            (string) $charge_basis,
            self::waybill_flag_for_sheet($w->vat_include ?? 0),
            self::waybill_flag_for_sheet($w->warehouse ?? 0),
            (string) ($w->miscellaneous ?? ''),
            self::waybill_flag_for_sheet($w->include_sad500 ?? 0),
            self::waybill_flag_for_sheet($w->include_sadc ?? 0),
            $w->return_load ?? '',
            (string) ($w->tracking_number ?? ''),
            (string) ($w->qr_code_data ?? ''),
            $w->created_by ?? '',
            $w->last_updated_by ?? '',
            (string) ($w->status ?? ''),
            $w->status_userid ?? '',
            $created_at,
            $last_updated_at,
        ];
    }

    /** Sync waybill row to sheet (upsert: update if waybill_no exists, else append). Match on column L (waybill_no). Skip column F (city_id) on update so sheet formulas work. */
    public static function sync_waybill($waybill, $is_new = true) {
        if (!self::can_auto_sync()) {
            if (function_exists('error_log')) {
                error_log('[Courier Google Sheets] Sync skipped: auto sync is disabled or Google Sheets is not configured.');
            }
            return;
        }
        $waybill_no = (string) ($waybill->waybill_no ?? '');
        $waybill_no = trim($waybill_no);
        if ($waybill_no === '' || $waybill_no === '0') {
            if (function_exists('error_log')) {
                error_log('[Courier Google Sheets] Sync skipped: waybill_no is empty or 0 (id=' . ($waybill->id ?? '') . ').');
            }
            return;
        }
        $sheet = self::get_sheet_name('waybills');
        $row = self::build_waybill_row($waybill);
        $row[1] = '';        // B: parcel_id always empty (mapping must not fill it)
        $row[11] = $waybill_no;  // L: waybill_no (match value for upsert)
        try {
            $updated = Courier_Google_Sheets::update_row_by_value_skip_columns(
                $waybill_no,
                $sheet,
                $row,
                11,
                [5],
                ''
            );
            if (!$updated) {
                Courier_Google_Sheets::append_row_skip_columns($row, $sheet, [5], '');
            }
            delete_option('courier_last_sync_error');
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier Google Sheets] Waybill sync failed for waybill_no=' . $waybill_no . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
            update_option('courier_last_sync_error', [
                'waybill_no' => $waybill_no,
                'message'    => $e->getMessage(),
                'time'       => time(),
            ], false);
        }
    }

    /** Remove waybill from sheet */
    public static function sync_waybill_delete($waybill_no) {
        if (!self::can_auto_sync()) {
            return;
        }
        try {
            Courier_Google_Sheets::delete_row_by_value((string) $waybill_no, self::get_sheet_name('waybills'), 11);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Waybill delete sync failed: ' . $e->getMessage());
            }
        }
    }

    /** Replace waybill items in sheet for given waybill_no */
    public static function sync_waybill_items($waybill_no, $items) {
        if (!self::can_auto_sync()) {
            return;
        }
        $sheet = self::get_sheet_name('waybill_items');
        try {
            Courier_Google_Sheets::delete_rows_by_value((string) $waybill_no, $sheet, 0);
            if (!empty($items) && is_array($items)) {
                $rows = [];
                foreach ($items as $item) {
                    $rows[] = [
                        $item['waybillno'] ?? $waybill_no,
                        $item['item_name'] ?? '',
                        (int) ($item['quantity'] ?? 1),
                        (float) ($item['unit_price'] ?? 0),
                        (float) ($item['unit_mass'] ?? 0),
                        (float) ($item['unit_volume'] ?? 0),
                        (float) ($item['total_price'] ?? 0),
                        $item['client_invoice'] ?? '',
                    ];
                }
                if (!empty($rows)) {
                    Courier_Google_Sheets::append_values($rows, '', 'A:H', $sheet);
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Waybill items sync failed: ' . $e->getMessage());
            }
        }
    }

    /** Remove waybill items from sheet */
    public static function sync_waybill_items_delete($waybill_no) {
        if (!self::can_auto_sync()) {
            return;
        }
        try {
            Courier_Google_Sheets::delete_rows_by_value((string) $waybill_no, self::get_sheet_name('waybill_items'), 0);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Waybill items delete sync failed: ' . $e->getMessage());
            }
        }
    }

    /** Linked company label for a kit_customers row (via company_id). */
    private static function customer_linked_company_name($customer): string
    {
        $company_id = (int) ($customer->company_id ?? 0);
        if ($company_id > 0 && class_exists('KIT_Company_Customers')) {
            $co = KIT_Company_Customers::find_by_company_id($company_id);
            return trim((string) ($co['company_name'] ?? ''));
        }
        return '';
    }

    /** Sync customer add (append to sheet) */
    public static function sync_customer_add($customer) {
        if (!self::can_auto_sync()) {
            return false;
        }
        try {
            $sheet = self::get_sheet_name('customers');
            $cust_id = $customer->cust_id ?? '';
            if (!is_numeric($cust_id)) {
                $cust_id = rand(1000, 9999);
            }
            // Column order must match sheet: A=id, B=cust_id, C=name, D=surname, E=cell, F=telephone, G=email_address, H=country_id, I=city_id, J=vat_number, K=address, L=company_id.
            $row = [
                self::sheet_val($customer->id ?? ''),
                $cust_id,
                self::sheet_val($customer->name ?? ''),
                self::sheet_val($customer->surname ?? ''),
                self::strip_phone_special_chars($customer->cell ?? ''),
                self::strip_phone_special_chars($customer->telephone ?? ''),
                self::sheet_val($customer->email_address ?? ''),
                self::sheet_id($customer->country_id ?? 0),
                self::sheet_id($customer->city_id ?? 0),
                self::sheet_val($customer->vat_number ?? ''),
                self::sheet_val($customer->address ?? ''),
                !empty($customer->company_id) ? (int) $customer->company_id : '',
            ];
            Courier_Google_Sheets::append_values([$row], '', 'A:L', $sheet, 'RAW');
            return true;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Customer sync failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /** Sync customer update */
    public static function sync_customer_update($customer) {
        if (!self::can_auto_sync()) {
            return;
        }
        $sheet = self::get_sheet_name('customers');
        $cust_id = $customer->cust_id ?? '';
        if (!is_numeric($cust_id)) {
            $cust_id = rand(1000, 9999);
        }
        // Column order must match sheet; country_id and city_id as integers
        $row = [
            self::sheet_val($customer->id ?? ''),
            $cust_id,
            self::sheet_val($customer->name ?? ''),
            self::sheet_val($customer->surname ?? ''),
            self::strip_phone_special_chars($customer->cell ?? ''),
            self::strip_phone_special_chars($customer->telephone ?? ''),
            self::sheet_val($customer->email_address ?? ''),
            self::sheet_id($customer->country_id ?? 0),
            self::sheet_id($customer->city_id ?? 0),
            self::sheet_val($customer->vat_number ?? ''),
            self::sheet_val($customer->address ?? ''),
            !empty($customer->company_id) ? (int) $customer->company_id : '',
        ];
        try {
            Courier_Google_Sheets::update_row_by_value((string) $cust_id, $sheet, $row, 1, '', 'RAW');
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Customer update sync failed: ' . $e->getMessage());
            }
        }
    }

    /** Sync customer delete - removes row from sheet. Tries cust_id (col B) first, then id (col A) as fallback. */
    public static function sync_customer_delete($cust_id, $db_id = null) {
        if (!self::can_auto_sync()) {
            return;
        }
        $sheet = self::get_sheet_name('customers');
        try {
            $deleted = Courier_Google_Sheets::delete_row_by_value((string) $cust_id, $sheet, 1);
            if (!$deleted && $db_id !== null && $db_id !== '') {
                Courier_Google_Sheets::delete_row_by_value((string) $db_id, $sheet, 0);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Customer delete sync failed: ' . $e->getMessage());
            }
        }
    }

    /** Sync delivery add (append to sheet) */
    public static function sync_delivery_add($delivery) {
        if (!self::can_auto_sync()) {
            return false;
        }
        try {
            $sheet = self::get_sheet_name('deliveries');
            $d = is_object($delivery) ? $delivery : (object) $delivery;
            if (empty($d->created_at)) {
                $d->created_at = current_time('mysql');
            }
            $row = self::build_delivery_sheet_row($d);
            Courier_Google_Sheets::append_values([$row], '', 'A:K', $sheet);
            return true;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Delivery sync failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /** Sync delivery update */
    public static function sync_delivery_update($delivery) {
        if (!self::can_auto_sync()) {
            return;
        }
        $sheet = self::get_sheet_name('deliveries');
        $d = is_object($delivery) ? $delivery : (object) $delivery;
        $row = self::build_delivery_sheet_row($d);
        try {
            self::update_delivery_sheet_row((string) ($d->delivery_reference ?? ''), $sheet, $row);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Delivery update sync failed: ' . $e->getMessage());
            }
        }
    }

    /** Sync delivery delete */
    public static function sync_delivery_delete($delivery_reference) {
        if (!self::can_auto_sync()) {
            return;
        }
        try {
            self::delete_delivery_sheet_row((string) $delivery_reference);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Delivery delete sync failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Push all rows from DB to Google Sheet (overwrites sheet data).
     * @param string $entity One of: drivers, customers, deliveries, waybills
     * @return array ['success' => bool, 'message' => string, 'count' => int]
     */
    public static function push_all($entity) {
        if (!self::can_sync()) {
            return ['success' => false, 'message' => 'Google Sheets not configured.', 'count' => 0];
        }
        $entity = (string) $entity;
        if (self::is_apps_script_owned_entity($entity)) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Push blocked: Apps Script owns the %s sheet (Waybills projection). Use apps-script/ runFullProjection, then KIT_Seed_Pipeline for DB. Define COURIER_APPS_SCRIPT_OWNS_KIT_SHEETS false only to re-enable DB→sheet push.',
                    $entity
                ),
                'count' => 0,
            ];
        }
        global $wpdb;
        $prefix = $wpdb->prefix;

        try {
            switch ($entity) {
                case 'drivers':
                    $sheet = self::get_sheet_name('drivers');
                    $rows = $wpdb->get_results("SELECT * FROM {$prefix}kit_drivers ORDER BY id ASC");
                    Courier_Google_Sheets::clear_range($sheet, 'A2:H10000');
                    $data = [];
                    foreach ($rows as $r) {
                        $data[] = ['', $r->name ?? '', $r->phone ?? '', $r->email ?? '', $r->license_number ?? '', $r->is_active ?? 1, $r->created_at ?? '', $r->updated_at ?? ''];
                    }
                    if (!empty($data)) {
                        Courier_Google_Sheets::append_values($data, '', 'A:H', $sheet);
                    }
                    return ['success' => true, 'message' => sprintf('Pushed %d drivers to sheet.', count($data)), 'count' => count($data)];

                case 'customers':
                    $sheet = self::get_sheet_name('customers');
                    $rows = $wpdb->get_results("SELECT * FROM {$prefix}kit_customers ORDER BY id ASC");
                    Courier_Google_Sheets::clear_range($sheet, 'A2:L');
                    $data = [];
                    foreach ($rows as $r) {
                        $cust_id = isset($r->cust_id) && is_numeric($r->cust_id) ? $r->cust_id : rand(1000, 9999);
                        $data[] = [
                            self::sheet_val($r->id ?? ''),
                            $cust_id,
                            self::sheet_val($r->name ?? ''),
                            self::sheet_val($r->surname ?? ''),
                            self::strip_phone_special_chars($r->cell ?? ''),
                            self::strip_phone_special_chars($r->telephone ?? ''),
                            self::sheet_val($r->email_address ?? ''),
                            self::sheet_id($r->country_id ?? 0),
                            self::sheet_id($r->city_id ?? 0),
                            self::sheet_val($r->vat_number ?? ''),
                            self::sheet_val($r->address ?? ''),
                            !empty($r->company_id) ? (int) $r->company_id : '',
                        ];
                    }
                    if (!empty($data)) {
                        Courier_Google_Sheets::append_values($data, '', 'A:L', $sheet, 'RAW');
                    }
                    return ['success' => true, 'message' => sprintf('Pushed %d customers to sheet.', count($data)), 'count' => count($data)];

                case 'deliveries':
                    $sheet = self::get_sheet_name('deliveries');
                    $rows = $wpdb->get_results("SELECT * FROM {$prefix}kit_deliveries ORDER BY id ASC");
                    Courier_Google_Sheets::clear_range($sheet, 'A2:K');
                    $data = [];
                    foreach ($rows as $r) {
                        $data[] = self::build_delivery_sheet_row($r);
                    }
                    if (!empty($data)) {
                        Courier_Google_Sheets::append_values($data, '', 'A:K', $sheet);
                    }
                    return ['success' => true, 'message' => sprintf('Pushed %d deliveries to sheet.', count($data)), 'count' => count($data)];

                case 'waybills':
                    $sheet = self::get_sheet_name('waybills');
                    $rows  = $wpdb->get_results("SELECT * FROM {$prefix}kit_waybills ORDER BY id ASC");
                    $count = count($rows);

                    // Preload city + customer name maps so we avoid N DB queries per waybill.
                    $city_map = [];
                    $city_rows = $wpdb->get_results("SELECT id, city_name FROM {$prefix}kit_operating_cities", ARRAY_A);
                    foreach ($city_rows as $cr) {
                        $city_map[(int) $cr['id']] = (string) $cr['city_name'];
                    }
                    $cust_map = [];
                    $cust_rows = $wpdb->get_results(
                        "SELECT c.cust_id, c.name, c.surname, co.company_name
                         FROM {$prefix}kit_customers c
                         LEFT JOIN {$prefix}kit_company_customers co ON c.company_id = co.company_id",
                        ARRAY_A
                    );
                    foreach ($cust_rows as $cr) {
                        $cid = (int) $cr['cust_id'];
                        $cust_map[$cid] = !empty($cr['company_name'])
                            ? trim((string) $cr['company_name'])
                            : trim(($cr['name'] ?? '') . ' ' . ($cr['surname'] ?? ''));
                    }

                    $data = [];
                    foreach ($rows as $r) {
                        $data[] = self::build_waybill_row_fast($r, $city_map, $cust_map);
                    }

                    // Full replace: clear data rows then write everything in ONE update call.
                    // update_range avoids the auto-increment ID scan that append_values
                    // triggers on every chunk call when column A header is 'id'.
                    Courier_Google_Sheets::clear_range($sheet, 'A2:AP');
                    if (!empty($data)) {
                        $end_row = count($data) + 1;
                        Courier_Google_Sheets::update_range($sheet, 'A2:AP' . $end_row, $data);
                    }

                    // Also push waybill items.
                    $items_sheet = self::get_sheet_name('waybill_items');
                    $items       = $wpdb->get_results("SELECT * FROM {$prefix}kit_waybill_items ORDER BY id ASC");
                    Courier_Google_Sheets::clear_range($items_sheet, 'A2:H10000');
                    $items_data = [];
                    foreach ($items as $i) {
                        $items_data[] = [$i->waybillno ?? '', $i->item_name ?? '', (int) $i->quantity, (float) $i->unit_price, (float) $i->unit_mass, (float) $i->unit_volume, (float) $i->total_price, $i->client_invoice ?? ''];
                    }
                    if (!empty($items_data)) {
                        $items_end_row = count($items_data) + 1;
                        Courier_Google_Sheets::update_range($items_sheet, 'A2:H' . $items_end_row, $items_data);
                    }
                    return ['success' => true, 'message' => sprintf('Pushed %d waybills and %d items to sheet.', $count, count($items_data)), 'count' => $count];

                default:
                    return ['success' => false, 'message' => 'Unknown entity: ' . $entity, 'count' => 0];
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] Push sync failed: ' . $e->getMessage());
            }
            return ['success' => false, 'message' => 'Push failed: ' . $e->getMessage(), 'count' => 0];
        }
    }

    /**
     * Pull all rows from Google Sheet into DB (insert new, skip existing by key).
     *
     * @deprecated 2026-05-19 for $entity='waybills'. Use KIT_Waybill_Seeder::run()
     *   instead — it is idempotent, supports shadow runs, and writes an audit
     *   log per row (wp_kit_sync_run_rows). The 'drivers' / 'customers' /
     *   'deliveries' entities are unaffected by this deprecation.
     *
     * @param string $entity One of: drivers, customers, deliveries, waybills
     * @return array ['success' => bool, 'message' => string, 'count' => int]
     */
    public static function pull_all($entity) {
        if (!self::can_sync()) {
            return ['success' => false, 'message' => 'Google Sheets not configured.', 'count' => 0];
        }
        global $wpdb;
        $prefix = $wpdb->prefix;

        try {
            switch ($entity) {
                case 'drivers':
                    $sheet = self::get_sheet_name('drivers');
                    $rows = Courier_Google_Sheets::get_values('', 'A2:H5000', $sheet);
                    $count = 0;
                    foreach ($rows as $row) {
                        $name = trim((string) ($row[1] ?? ''));
                        if ($name === '') continue;
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}kit_drivers WHERE name = %s", $name));
                        if ($exists) continue;
                        $wpdb->insert($prefix . 'kit_drivers', [
                            'name' => $name,
                            'phone' => $row[2] ?? '',
                            'email' => $row[3] ?? '',
                            'license_number' => $row[4] ?? '',
                            'is_active' => !empty($row[5]) ? 1 : 0,
                        ]);
                        if ($wpdb->insert_id) $count++;
                    }
                    return ['success' => true, 'message' => sprintf('Pulled %d new drivers from sheet.', $count), 'count' => $count];

                case 'customers':
                    $sheet = self::get_sheet_name('customers');
                    $rows = Courier_Google_Sheets::get_values('', 'A2:L5000', $sheet);
                    $count = 0;
                    foreach ($rows as $row) {
                        $is_placeholder = static function ($value): bool {
                            $v = strtolower(trim((string) $value));
                            return $v === '' || in_array($v, ['0', 'null', 'n/a', 'na', 'none', '-', '--'], true);
                        };

                        $cust_id_raw = trim((string) ($row[1] ?? ''));
                        $cust_id = is_numeric($cust_id_raw) ? (int) $cust_id_raw : 0;

                        // Expected sheet columns: A=id, B=cust_id, C=name, D=surname, ..., L=company_id.
                        // Some sheets contain shifted data (e.g. C contains 0, D contains the actual name).
                        $name = trim((string) ($row[2] ?? ''));
                        $surname = trim((string) ($row[3] ?? ''));

                        // Auto-correct common shifted layout: name is placeholder numeric but surname looks like a real name.
                        if ($is_placeholder($name) && !$is_placeholder($surname)) {
                            $name = $surname;
                            $surname = '';
                        }

                        if ($name === '' && $surname === '') continue;
                        if ($cust_id > 0) {
                            $exists = $wpdb->get_var($wpdb->prepare("SELECT cust_id FROM {$prefix}kit_customers WHERE cust_id = %d", $cust_id));
                        } else {
                            $cust_id = rand(1000, 9999);
                            $exists = $wpdb->get_var($wpdb->prepare("SELECT cust_id FROM {$prefix}kit_customers WHERE name = %s AND surname = %s", $name, $surname));
                        }
                        if ($exists) continue;
                        // Sheet columns: A=id, B=cust_id, C=name, D=surname, E=cell, F=telephone, G=email_address, H=country_id, I=city_id, J=vat_number, K=address, L=company_id
                        $company_id_raw = trim((string) ($row[11] ?? ''));
                        $company_id = is_numeric($company_id_raw) ? (int) $company_id_raw : 0;
                        $wpdb->insert($prefix . 'kit_customers', [
                            'cust_id' => $cust_id,
                            'name' => $name,
                            'surname' => $surname,
                            'cell' => $row[4] ?? '',
                            'telephone' => $row[5] ?? '',
                            'email_address' => $row[6] ?? null,
                            'address' => $row[10] ?? '',
                            'country_id' => (int) ($row[7] ?? 0),
                            'city_id' => !empty($row[8]) ? (int) $row[8] : null,
                            'company_id' => $company_id > 0 ? $company_id : null,
                            'vat_number' => $row[9] ?? '',
                        ]);
                        if ($wpdb->insert_id) $count++;
                    }
                    return ['success' => true, 'message' => sprintf('Pulled %d new customers from sheet.', $count), 'count' => $count];

                case 'deliveries':
                    $sheet = self::get_sheet_name('deliveries');
                    $all = Courier_Google_Sheets::get_values('', 'A1:K5000', $sheet);
                    if (empty($all)) {
                        return ['success' => true, 'message' => 'No rows in deliveries sheet.', 'count' => 0];
                    }
                    $count = self::import_deliveries_from_sheet_rows($all);
                    return ['success' => true, 'message' => sprintf('Pulled %d new deliveries from sheet.', $count), 'count' => $count];

                case 'waybills':
                    set_time_limit(120);
                    $sheet_name = defined('COURIER_GOOGLE_SEED_SHEET_NAME') ? COURIER_GOOGLE_SEED_SHEET_NAME : 'kit_waybills';
                    $range = defined('COURIER_GOOGLE_SEED_RANGE') ? COURIER_GOOGLE_SEED_RANGE : 'A1:AR5000';
                    // Normalize range: API requires start cell with row (e.g. A1). "AT:AR5000" is invalid; use A1:AR5000.
                    if (!preg_match('/^[A-Z]+\d+:[A-Z]+\d+$/i', $range) && preg_match('/^[A-Z]+:([A-Z]+\d+)$/i', $range, $m)) {
                        $range = 'A1:' . $m[1];
                    }
                    $rows = null;
                    try {
                        $rows = Courier_Google_Sheets::get_values('', $range, $sheet_name);
                    } catch (Throwable $e) {
                        $msg = $e->getMessage();
                        $is_range_or_sheet = (
                            stripos($msg, 'Unable to parse range') !== false
                            || stripos($msg, 'parse range') !== false
                            || (stripos($msg, 'INVALID_ARGUMENT') !== false && stripos($msg, 'range') !== false)
                            || stripos($msg, 'sheet') !== false
                        );
                        if ($is_range_or_sheet) {
                            try {
                                $available = Courier_Google_Sheets::get_sheet_names('');
                                if (!empty($available)) {
                                    $configured_ok = in_array($sheet_name, $available, true);
                                    if (!$configured_ok) {
                                        $try_sheet = $available[0];
                                        $rows = Courier_Google_Sheets::get_values('', $range, $try_sheet);
                                        if (!empty($rows) && count($rows) >= 2 && function_exists('run_google_sheet_seed')) {
                                            $del_rows = self::fetch_deliveries_sheet_rows();
                                            $result = run_google_sheet_seed($rows, false, [], $del_rows);
                                            $cnt = isset($result['stats']['waybills']) ? (int) $result['stats']['waybills'] : 0;
                                            return [
                                                'success' => !empty($result['success']),
                                                'message' => ($result['message'] ?? 'Waybills pull completed.') . ' (Used sheet "' . $try_sheet . '". Add define(\'COURIER_GOOGLE_SEED_SHEET_NAME\', \'' . $try_sheet . '\'); to wp-config.php to fix the warning.)',
                                                'count' => $cnt,
                                            ];
                                        }
                                    }
                                    $hint = 'Available sheet tabs: ' . implode(', ', array_map(function ($n) { return '"' . $n . '"'; }, $available)) . '. Set COURIER_GOOGLE_SEED_SHEET_NAME in wp-config.php to one of these.';
                                } else {
                                    $hint = 'No sheets found in the spreadsheet. Check COURIER_GOOGLE_SPREADSHEET_ID and that the sheet is shared with the service account.';
                                }
                            } catch (Throwable $inner) {
                                $hint = 'Could not list sheet names: ' . $inner->getMessage();
                            }
                            return [
                                'success' => false,
                                'message' => 'Invalid sheet range. ' . (isset($hint) ? $hint : 'Check that the sheet tab name matches COURIER_GOOGLE_SEED_SHEET_NAME (e.g. "kit_waybills") and COURIER_GOOGLE_SEED_RANGE is valid A1 notation (e.g. A1:AR5000).'),
                                'count' => 0,
                            ];
                        }
                        throw $e;
                    }
                    if (empty($rows) || count($rows) < 2) {
                        return ['success' => false, 'message' => 'Waybills pull uses seed sheet (e.g. kit_waybills). No data or invalid format.', 'count' => 0];
                    }
                    if (!function_exists('run_google_sheet_seed')) {
                        return ['success' => false, 'message' => 'Seed function not available.', 'count' => 0];
                    }
                    $del_rows = self::fetch_deliveries_sheet_rows();
                    $result = run_google_sheet_seed($rows, false, [], $del_rows);
                    $cnt = isset($result['stats']['waybills']) ? (int) $result['stats']['waybills'] : 0;
                    return [
                        'success' => !empty($result['success']),
                        'message' => $result['message'] ?? 'Waybills pull completed.',
                        'count' => $cnt,
                    ];

                default:
                    return ['success' => false, 'message' => 'Unknown entity: ' . $entity, 'count' => 0];
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $is_range_error = stripos($msg, 'Unable to parse range') !== false || stripos($msg, 'parse range') !== false || stripos($msg, 'INVALID_ARGUMENT') !== false || stripos($msg, 'sheet') !== false;
            $user_message = 'Pull failed: ' . $msg;
            if ($is_range_error) {
                try {
                    $available = Courier_Google_Sheets::get_sheet_names('');
                    $user_message = 'Invalid sheet range. Available sheet tabs: ' . (empty($available) ? 'none' : implode(', ', array_map(function ($n) { return '"' . $n . '"'; }, $available))) . '. Set COURIER_GOOGLE_SEED_SHEET_NAME in wp-config.php to match your tab, and COURIER_GOOGLE_SEED_RANGE to A1 notation (e.g. A1:AR5000).';
                } catch (Throwable $ignored) {
                    $user_message = 'Invalid sheet range. Check that the sheet tab name matches COURIER_GOOGLE_SEED_SHEET_NAME (e.g. "kit_waybills") and COURIER_GOOGLE_SEED_RANGE is valid A1 notation (e.g. A1:AR5000).';
                }
            }
            $log_line = '[' . gmdate('d-M-Y H:i:s') . ' UTC] [Courier] Pull sync failed: ' . $msg . "\n";
            if (function_exists('error_log')) {
                error_log(trim($log_line));
            }
            if (defined('WP_CONTENT_DIR')) {
                $debug_log = WP_CONTENT_DIR . '/debug.log';
                if (is_writable(WP_CONTENT_DIR) || (file_exists($debug_log) && is_writable($debug_log))) {
                    @file_put_contents($debug_log, $log_line, FILE_APPEND | LOCK_EX);
                }
            }
            return ['success' => false, 'message' => $user_message, 'count' => 0];
        }
    }

    /**
     * Push all DB entities to the sheet in dependency order:
     * drivers → customers → deliveries → waybills (+ waybill_items).
     * Does not require auto-sync to be enabled.
     * Skips Apps Script–owned entities when apps_script_owns_kit_sheets() is true.
     *
     * @return array{success: bool, message: string, totals: array<string,int>}
     */
    public static function push_all_entities(): array {
        if (!self::can_sync()) {
            return ['success' => false, 'message' => 'Google Sheets not configured. Add credentials and share the sheet with the service account email.', 'totals' => []];
        }
        $order   = ['drivers', 'customers', 'deliveries', 'waybills'];
        $totals  = [];
        $errors  = [];
        $skipped = [];
        foreach ($order as $entity) {
            if (self::is_apps_script_owned_entity($entity)) {
                $totals[$entity] = 0;
                $skipped[] = $entity;
                continue;
            }
            $r = self::push_all($entity);
            $totals[$entity] = $r['count'] ?? 0;
            if (!$r['success']) {
                $errors[] = $entity . ': ' . ($r['message'] ?? 'unknown error');
            }
        }
        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Some entities failed — ' . implode('; ', $errors), 'totals' => $totals];
        }
        if (!empty($skipped) && count($skipped) === count($order)) {
            return [
                'success' => true,
                'message' => 'No DB→sheet push: Apps Script owns kit_* tabs (' . implode(', ', $skipped) . '). Run apps-script projection, then seed pipeline.',
                'totals' => $totals,
            ];
        }
        $summary = implode(', ', array_map(function($e, $c) { return "$c $e"; }, array_keys($totals), $totals));
        $extra = $skipped ? ' Skipped Apps Script–owned: ' . implode(', ', $skipped) . '.' : '';
        return ['success' => true, 'message' => 'Synced to sheet: ' . $summary . '.' . $extra, 'totals' => $totals];
    }

    /**
     * Provision the destination spreadsheet by copying every tab from the source spreadsheet.
     * Uses Google Sheets copyTo API so all formulas, column widths, and formatting are preserved.
     * Skips tabs that already exist in the destination. Removes the default "Sheet1" blank tab
     * after all copies succeed (only if it was the only pre-existing tab).
     *
     * @param string $source_id  Spreadsheet to copy from. Defaults to the hardcoded backup sheet.
     * @return array{success: bool, message: string, created: string[], skipped: string[]}
     */
    public static function provision_sheet(string $source_id = '1w-9PfeN198UoLp-LO-ZFUYYjWiewuIfsp9r-2lY_Xec'): array {
        if (!self::can_sync()) {
            return ['success' => false, 'message' => 'Google Sheets not configured. Add credentials and share the sheet with the service account email.', 'created' => [], 'skipped' => []];
        }

        try {
            $dest_id  = Courier_Google_Sheets::get_default_spreadsheet_id();
            $service  = Courier_Google_Sheets::get_service();

            // --- Read source sheet tabs (id + title) ---
            $src_ss   = $service->spreadsheets->get($source_id, ['fields' => 'sheets(properties(sheetId,title))']);
            $src_tabs = [];
            foreach ($src_ss->getSheets() as $s) {
                $props = $s->getProperties();
                $src_tabs[(int) $props->getSheetId()] = $props->getTitle();
            }

            if (empty($src_tabs)) {
                return ['success' => false, 'message' => 'Source spreadsheet has no tabs.', 'created' => [], 'skipped' => []];
            }

            // --- Read destination existing tabs ---
            $dest_existing     = Courier_Google_Sheets::get_sheet_names($dest_id);
            $dest_existing_map = array_flip($dest_existing);

            // Track whether destination only has a blank Sheet1 before we start
            $has_only_blank_sheet1 = (count($dest_existing) === 1 && isset($dest_existing_map['Sheet1']));

            $created = [];
            $skipped = [];

            foreach ($src_tabs as $src_sheet_id => $title) {
                if (isset($dest_existing_map[$title])) {
                    $skipped[] = $title;
                    continue;
                }

                // Copy sheet from source to destination
                $copy_req = new \Google_Service_Sheets_CopySheetToAnotherSpreadsheetRequest([
                    'destinationSpreadsheetId' => $dest_id,
                ]);
                $copy_resp = $service->spreadsheets_sheets->copyTo($source_id, $src_sheet_id, $copy_req);

                // copyTo names the new tab "Copy of <title>" — rename it
                $new_sheet_id = $copy_resp->getSheetId();
                $rename_req   = new \Google_Service_Sheets_Request([
                    'updateSheetProperties' => [
                        'properties' => ['sheetId' => $new_sheet_id, 'title' => $title],
                        'fields'     => 'title',
                    ],
                ]);
                $batch = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => [$rename_req]]);
                $service->spreadsheets->batchUpdate($dest_id, $batch);

                $created[] = $title;
            }

            // Remove the default blank Sheet1 if destination only had it before we started
            if ($has_only_blank_sheet1 && !empty($created)) {
                try {
                    $dest_ss2 = $service->spreadsheets->get($dest_id, ['fields' => 'sheets(properties(sheetId,title))']);
                    foreach ($dest_ss2->getSheets() as $s) {
                        if ($s->getProperties()->getTitle() === 'Sheet1') {
                            $del_req = new \Google_Service_Sheets_Request([
                                'deleteSheet' => ['sheetId' => $s->getProperties()->getSheetId()],
                            ]);
                            $batch2 = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => [$del_req]]);
                            $service->spreadsheets->batchUpdate($dest_id, $batch2);
                            break;
                        }
                    }
                } catch (Throwable $ignored) {
                    // Non-fatal — Sheet1 removal failure doesn't break anything
                }
            }

            $msg_parts = [];
            if (!empty($created)) {
                $msg_parts[] = 'Copied from source: ' . implode(', ', $created);
            }
            if (!empty($skipped)) {
                $msg_parts[] = 'Already existed (skipped): ' . implode(', ', $skipped);
            }
            return [
                'success' => true,
                'message' => implode('. ', $msg_parts) ?: 'Nothing to do — all tabs already exist.',
                'created' => $created,
                'skipped' => $skipped,
            ];
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Courier] provision_sheet failed: ' . $e->getMessage());
            }
            return ['success' => false, 'message' => $e->getMessage(), 'created' => [], 'skipped' => []];
        }
    }
}
