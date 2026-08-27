<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include user roles for permission checking
require_once plugin_dir_path(__FILE__) . '../user-roles.php';

class KIT_Customers
{
    public static function init()
    {
        add_action('admin_post_update_customer', [self::class, 'handle_update_customer']);
        add_action('admin_post_kit_merge_customers', [self::class, 'handle_merge_customers']);
        // Customer records hold personal data (POPIA), so nothing here is exposed to
        // logged-out requests.
        add_action('wp_ajax_save_customer_ajax', [self::class, 'handle_save_customer_ajax']);
        add_action('wp_ajax_test_customer_ajax', [self::class, 'test_customer_ajax']);
        add_action('wp_ajax_get_cities_by_country', [self::class, 'handle_get_cities_by_country']);
    }
    public static function gamaCustomer($id)
    {

        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';
        return $wpdb->get_var("SELECT name FROM $table_name WHERE cust_id=" . $id);
    }
    public static function idCustomer($id)
    {

        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';
        return $wpdb->get_var("SELECT id FROM $table_name WHERE cust_id=" . $id);
    }

    /** @var string|null Message when save/update is rejected (duplicate name or company). */
    private static $last_customer_validation_error = null;

    public static function get_last_customer_validation_error(): ?string
    {
        return self::$last_customer_validation_error;
    }

    private static function set_customer_validation_error(?string $message): void
    {
        self::$last_customer_validation_error = $message;
    }

    private static function normalize_customer_compare_string(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    /** Strip accents / diacritics for stable name comparison. */
    private static function strip_name_accents(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($trans) && $trans !== '') {
                $value = $trans;
            }
        }
        return $value;
    }

    /**
     * Particles / articles that should not create distinct people
     * (e.g. "van den Berg" vs "Van Der Berg").
     *
     * @return list<string>
     */
    private static function person_name_particles(): array
    {
        return ['van', 'den', 'der', 'de', 'du', 'la', 'le', 'ter', 'ten', 'von', 'of', 'the', 'da', 'dos', 'das', 'del', 'di'];
    }

    /**
     * Canonical person-name key: lowercase, no accents/punctuation, particles removed.
     * "Edwin van den Berg" and "Edwin Van Der Berg" both become "edwin berg".
     */
    public static function normalize_person_name_key(string $name, string $surname = ''): string
    {
        $full = trim($name . ' ' . $surname);
        $full = self::normalize_customer_compare_string($full);
        $full = self::strip_name_accents($full);
        $full = preg_replace('/[^a-z0-9\s]+/u', ' ', $full) ?? $full;
        $full = preg_replace('/\s+/u', ' ', $full) ?? $full;
        $full = trim($full);
        if ($full === '') {
            return '';
        }

        $particles = self::person_name_particles();
        $tokens = preg_split('/\s+/u', $full) ?: [];
        $kept = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || in_array($token, $particles, true)) {
                continue;
            }
            $kept[] = $token;
        }

        return implode(' ', $kept);
    }

    /**
     * Display name without repeating a surname already in the given name
     * ("Chris Joubert" + "Joubert" → "Chris Joubert").
     */
    public static function format_person_display_name(string $name, string $surname = ''): string
    {
        $name = trim($name);
        $surname = trim($surname);
        if ($name === '') {
            return $surname;
        }
        if ($surname === '') {
            return $name;
        }

        $name_l = self::normalize_customer_compare_string($name);
        $surname_l = self::normalize_customer_compare_string($surname);
        if ($surname_l === '' || $name_l === $surname_l) {
            return $name;
        }

        $suffix = ' ' . $surname_l;
        if (strlen($name_l) >= strlen($suffix) && substr($name_l, -strlen($suffix)) === $suffix) {
            return $name;
        }

        return trim($name . ' ' . $surname);
    }

    /**
     * Canonical company-name key: lowercase, no legal suffixes (ltd, pty, etc.).
     */
    public static function normalize_company_compare_key(string $company_name): string
    {
        $s = self::normalize_customer_compare_string($company_name);
        $s = self::strip_name_accents($s);
        $s = preg_replace('/[^a-z0-9\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);
        if ($s === '') {
            return '';
        }

        $suffixes = [
            'proprietary limited',
            'pty limited',
            'pty ltd',
            'pvt ltd',
            'private limited',
            'limited',
            'ltd',
            'inc',
            'incorporated',
            'llc',
            'plc',
            'cc',
            'co',
            'company',
            'corp',
            'corporation',
        ];
        foreach ($suffixes as $suffix) {
            $needle = ' ' . $suffix;
            if (strlen($s) >= strlen($needle) && substr($s, -strlen($needle)) === $needle) {
                $s = trim(substr($s, 0, -strlen($needle)));
            } elseif ($s === $suffix) {
                $s = '';
            }
        }

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /**
     * Near-duplicate check after normalization (typos / small spelling drift).
     */
    public static function customer_labels_are_similar(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $len_a = strlen($a);
        $len_b = strlen($b);
        $max = max($len_a, $len_b);
        $min = min($len_a, $len_b);
        if ($min < 4 || $max <= 0) {
            return false;
        }
        // Avoid matching short fragments to long multi-word company names.
        if (($min / $max) < 0.55) {
            return false;
        }

        similar_text($a, $b, $pct);
        if ($pct >= 88.0) {
            return true;
        }

        if (function_exists('levenshtein') && $max <= 255) {
            $dist = levenshtein($a, $b);
            if ($dist <= 2 && $max >= 8) {
                return true;
            }
            if ($dist / $max <= 0.15) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find existing individual customers that look like the same person.
     *
     * @return list<object{cust_id:int,name:string,surname:string,display:string,match:string}>
     */
    public static function find_similar_person_customers(string $name, string $surname, ?int $exclude_cust_id = null, int $limit = 8): array
    {
        $needle = self::normalize_person_name_key($name, $surname);
        if ($needle === '') {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'kit_customers';
        $rows = $wpdb->get_results("SELECT cust_id, name, surname FROM {$table}");
        if (empty($rows)) {
            return [];
        }

        $matches = [];
        foreach ($rows as $row) {
            $cust_id = (int) ($row->cust_id ?? 0);
            if ($exclude_cust_id !== null && $cust_id === (int) $exclude_cust_id) {
                continue;
            }
            $row_name = trim((string) ($row->name ?? ''));
            $row_surname = trim((string) ($row->surname ?? ''));
            $key = self::normalize_person_name_key($row_name, $row_surname);
            if ($key === '') {
                continue;
            }

            $match_type = '';
            if ($key === $needle) {
                $match_type = 'normalized';
            } elseif (self::customer_labels_are_similar($needle, $key)) {
                $match_type = 'similar';
            } else {
                continue;
            }

            $matches[] = (object) [
                'cust_id' => $cust_id,
                'name' => $row_name,
                'surname' => $row_surname,
                'display' => trim($row_name . ' ' . $row_surname),
                'match' => $match_type,
            ];
            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /** Company labels that may repeat across many customers (not enforced as unique). */
    private static function company_is_exempt_from_unique_check(string $company_name): bool
    {
        $s = self::normalize_customer_compare_string($company_name);
        if ($s === '' || in_array($s, ['individual', 'private'], true)) {
            return true;
        }
        return in_array($s, ['n/a', 'na', 'none', '-', '--', 'null', '0'], true);
    }

    /**
     * Another row already has this first + last name (exact or near-duplicate).
     *
     * @param int|null $exclude_cust_id Pass current cust_id when editing.
     */
    public static function customer_name_surname_exists(string $name, string $surname, ?int $exclude_cust_id = null): bool
    {
        $n = self::normalize_customer_compare_string($name);
        $s = self::normalize_customer_compare_string($surname);
        if ($n === '' && $s === '') {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'kit_customers';
        $sql = "SELECT COUNT(*) FROM {$table} WHERE LOWER(TRIM(COALESCE(name,''))) = %s AND LOWER(TRIM(COALESCE(surname,''))) = %s";
        $params = [$n, $s];
        if ($exclude_cust_id !== null && (int) $exclude_cust_id > 0) {
            $sql .= ' AND cust_id <> %d';
            $params[] = (int) $exclude_cust_id;
        }
        if ((int) $wpdb->get_var($wpdb->prepare($sql, $params)) > 0) {
            return true;
        }

        return !empty(self::find_similar_person_customers($name, $surname, $exclude_cust_id, 1));
    }

    /**
     * JOIN kit_company_customers onto a kit_customers alias via company_id.
     */
    public static function customer_company_join_sql(string $customer_alias = 'c', string $company_alias = 'co'): string
    {
        global $wpdb;
        return "LEFT JOIN {$wpdb->prefix}kit_company_customers {$company_alias} ON {$customer_alias}.company_id = {$company_alias}.company_id";
    }

    /**
     * Resolve company_id from payload (explicit id, else company_name → ensure_company).
     */
    public static function resolve_company_id_from_payload(array $data): int
    {
        $company_id = isset($data['company_id']) ? (int) $data['company_id'] : 0;
        if ($company_id > 0) {
            return $company_id;
        }
        $company_name = trim(sanitize_text_field((string) ($data['company_name'] ?? '')));
        if ($company_name === '' || !class_exists('KIT_Company_Customers') || KIT_Company_Customers::is_placeholder_company($company_name)) {
            return 0;
        }
        return (int) KIT_Company_Customers::ensure_company($company_name);
    }

    /**
     * Company label uniqueness lives on kit_company_customers.
     *
     * @param int|null $exclude_cust_id Unused; kept for call-site compatibility.
     */
    public static function company_name_exists(string $company_name, ?int $exclude_cust_id = null): bool
    {
        unset($exclude_cust_id);
        if (!class_exists('KIT_Company_Customers')) {
            return false;
        }
        return KIT_Company_Customers::company_name_exists($company_name, null);
    }

    /**
     * When company_name is set: copy it into name only if the person name is empty
     * (or is an incomplete prefix of the company, e.g. "Eye" vs "Eye Emporium").
     */
    public static function should_sync_name_to_company(string $name, string $surname, string $company_name): bool
    {
        $company_name = trim($company_name);
        if ($company_name === '' || self::company_is_exempt_from_unique_check($company_name)) {
            return false;
        }

        $name = trim($name);
        $surname = trim($surname);
        $full = trim($name . ' ' . $surname);

        if ($full === '') {
            return true;
        }
        if (strcasecmp($full, $company_name) === 0) {
            return true;
        }
        if ($surname === '' && $name !== '' && strlen($name) < strlen($company_name) && stripos($company_name, $name) === 0) {
            return true;
        }

        return false;
    }

    /**
     * @return array{name:string,surname:string,company_name:string}
     */
    public static function finalize_customer_name_fields(string $name, string $surname, string $company_name): array
    {
        $name = trim($name);
        $surname = trim($surname);
        $company_name = trim($company_name);
        if (self::company_is_exempt_from_unique_check($company_name)) {
            $company_name = '';
        }

        if (self::should_sync_name_to_company($name, $surname, $company_name)) {
            $name = $company_name;
            $surname = '';
        }

        return [
            'name' => $name,
            'surname' => $surname,
            'company_name' => $company_name,
        ];
    }

    /** Person name for list/display — applies the same company sync rule as seed. */
    public static function person_name_for_display(string $name, string $surname, string $company_name): string
    {
        $final = self::finalize_customer_name_fields($name, $surname, $company_name);
        $display = trim($final['name'] . ' ' . $final['surname']);

        return $display;
    }

    /**
     * Fix stored name/surname when company_name is set but name is empty or a short prefix.
     *
     * @return int Rows updated
     */
    public static function repair_customer_name_company_fields(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_customers';
        $companies_t = $wpdb->prefix . 'kit_company_customers';
        $rows = $wpdb->get_results(
            "SELECT c.cust_id, c.name, c.surname, co.company_name
             FROM {$table} c
             LEFT JOIN {$companies_t} co ON c.company_id = co.company_id"
        );
        if (empty($rows)) {
            return 0;
        }

        $updated = 0;
        foreach ($rows as $row) {
            $final = self::finalize_customer_name_fields(
                trim((string) ($row->name ?? '')),
                trim((string) ($row->surname ?? '')),
                trim((string) ($row->company_name ?? ''))
            );
            $cur_name = trim((string) ($row->name ?? ''));
            $cur_surname = trim((string) ($row->surname ?? ''));
            if ($final['name'] === $cur_name && $final['surname'] === $cur_surname) {
                continue;
            }
            $wpdb->update(
                $table,
                ['name' => $final['name'], 'surname' => $final['surname']],
                ['cust_id' => (int) $row->cust_id],
                ['%s', '%s'],
                ['%d']
            );
            if ($wpdb->rows_affected > 0) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Fix waybills that store kit_customers.id instead of cust_id in customer_id.
     */
    public static function repair_waybill_customer_id_links(): int
    {
        global $wpdb;
        $customers_t = $wpdb->prefix . 'kit_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';

        $rows = $wpdb->get_results(
            "SELECT w.id AS waybill_pk, c.cust_id
             FROM {$waybills_t} w
             INNER JOIN {$customers_t} c ON w.customer_id = c.id
             WHERE w.customer_id > 0 AND w.customer_id <> c.cust_id"
        );
        if (empty($rows)) {
            return 0;
        }

        $updated = 0;
        foreach ($rows as $row) {
            $wpdb->update(
                $waybills_t,
                ['customer_id' => (int) $row->cust_id],
                ['id' => (int) $row->waybill_pk],
                ['%d'],
                ['%d']
            );
            if ($wpdb->rows_affected > 0) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * SQL fragment: count waybills for customer c (matches cust_id; legacy id fallback).
     */
    public static function customer_waybill_count_sql(): string
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';

        return "(SELECT COUNT(*)
            FROM {$waybills_table} w
            WHERE w.customer_id = c.cust_id
               OR (w.customer_id = c.id AND c.id > 0 AND w.customer_id <> c.cust_id)
        ) AS total_waybills";
    }

    public static function save_customer($cust)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';

        self::set_customer_validation_error(null);

        $name = sanitize_text_field($cust['name'] ?? $cust['customer_name'] ?? '');
        $surname = sanitize_text_field($cust['surname'] ?? $cust['customer_surname'] ?? '');

        if (self::customer_name_surname_exists($name, $surname, null)) {
            $similar = self::find_similar_person_customers($name, $surname, null, 3);
            $hint = '';
            if (!empty($similar)) {
                $labels = array_map(static function ($m) {
                    return trim(($m->display ?? '') . ' (#' . (int) $m->cust_id . ')');
                }, $similar);
                $hint = ' Existing: ' . implode(', ', $labels) . '. Use Merge to transfer waybills, or select the existing customer.';
            }
            self::set_customer_validation_error(
                'A customer with this name (or a very similar spelling) already exists.' . $hint
            );
            return false;
        }

        // Sanitize location IDs from either customer_* or origin_* payloads.
        $country_raw = null;
        if (array_key_exists('country_id', $cust)) {
            $country_raw = $cust['country_id'];
        } elseif (array_key_exists('origin_country', $cust)) {
            $country_raw = $cust['origin_country'];
        }
        $city_raw = null;
        if (array_key_exists('city_id', $cust)) {
            $city_raw = $cust['city_id'];
        } elseif (array_key_exists('origin_city', $cust)) {
            $city_raw = $cust['origin_city'];
        }
        $country_id = ($country_raw !== null && $country_raw !== '') ? intval($country_raw) : 0;
        $city_id = ($city_raw !== null && $city_raw !== '') ? intval($city_raw) : null; // NULL for FK when empty
        $company_id = self::resolve_company_id_from_payload($cust);

        // Handle email - convert empty string to null for database
        $email_address = isset($cust['email_address']) && trim($cust['email_address']) !== ''
            ? sanitize_email(trim($cust['email_address']))
            : null;

        do {
            $new_cust_id = wp_rand(1000, 9999);
            $id_taken = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE cust_id = %d", $new_cust_id));
        } while ($id_taken > 0);

        $cust_data = [
            'cust_id'  => $new_cust_id,
            'name'     => $name,
            'surname'  => $surname,
            'cell'     => sanitize_text_field($cust['cell'] ?? ''),
            'email_address'  => $email_address,
            'address'  => sanitize_text_field($cust['address'] ?? ''),
            'country_id'  => $country_id,
            'city_id'  => $city_id,
            'vat_number'  => sanitize_text_field($cust['vat_number'] ?? ''),
        ];
        $formats = [
            '%d',
            '%s',
            '%s',
            '%s',
            ($email_address === null ? null : '%s'),
            '%s',
            '%d',
            ($city_id === null ? null : '%d'),
            '%s',
        ];
        if (class_exists('KIT_Company_Customers') && KIT_Company_Customers::customers_have_company_id_column()) {
            $cust_data['company_id'] = $company_id > 0 ? $company_id : null;
            $formats[] = $company_id > 0 ? '%d' : '%s';
        }

        $inserted = $wpdb->insert($table_name, $cust_data, $formats);

        if ($inserted === false) {
            return false; // Insert failed
        }

        if (class_exists('Courier_Google_Sheets_Sync')) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE cust_id = %d", $cust_data['cust_id']));
            if ($row) {
                Courier_Google_Sheets_Sync::sync_customer_add($row);
            }
        }
        return $cust_data['cust_id']; // Return the new customer ID
    }

    public static function update_customer($cust_id, $data)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';
        self::set_customer_validation_error(null);

        $cust_id = (int) $cust_id;
        if ($cust_id <= 0) {
            self::set_customer_validation_error('Invalid customer.');
            return false;
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE cust_id = %d", $cust_id));
        if (!$row) {
            self::set_customer_validation_error('Customer not found.');
            return false;
        }

        // Sanitize input data
        $update_data = [];
        if (array_key_exists('company_id', $data) || isset($data['company_name'])) {
            $company_id = self::resolve_company_id_from_payload($data);
            if (class_exists('KIT_Company_Customers') && KIT_Company_Customers::customers_have_company_id_column()) {
                $update_data['company_id'] = $company_id > 0 ? $company_id : null;
            }
        }
        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }
        if (isset($data['surname'])) {
            $update_data['surname'] = sanitize_text_field($data['surname']);
        }
        if (isset($data['cell'])) {
            $update_data['cell'] = sanitize_text_field($data['cell']);
        }
        if (isset($data['address'])) {
            $update_data['address'] = sanitize_text_field($data['address']);
        }
        if (isset($data['email_address'])) {
            // Handle email - convert empty string to null for database
            $email_value = trim($data['email_address']);
            $update_data['email_address'] = $email_value !== '' ? sanitize_email($email_value) : null;
        }
        // Update country_id/city_id when provided (including 0 to clear)
        if (array_key_exists('country_id', $data)) {
            $cid = $data['country_id'];
            $cid = ($cid !== '' && $cid !== null) ? (int) $cid : 0;
            $update_data['country_id'] = $cid > 0 ? $cid : null;
        }
        if (array_key_exists('city_id', $data)) {
            $xid = $data['city_id'];
            $xid = ($xid !== '' && $xid !== null) ? (int) $xid : 0;
            $update_data['city_id'] = $xid > 0 ? $xid : null;
        }
        if (array_key_exists('vat_number', $data)) {
            $update_data['vat_number'] = sanitize_text_field((string) $data['vat_number']);
        }
        if (array_key_exists('telephone', $data)) {
            $update_data['telephone'] = sanitize_text_field((string) $data['telephone']);
        }

        if (empty($update_data)) {
            return false;
        }

        $eff_name = isset($update_data['name']) ? (string) $update_data['name'] : (string) ($row->name ?? '');
        $eff_surname = isset($update_data['surname']) ? (string) $update_data['surname'] : (string) ($row->surname ?? '');
        if (self::customer_name_surname_exists($eff_name, $eff_surname, $cust_id)) {
            $similar = self::find_similar_person_customers($eff_name, $eff_surname, $cust_id, 3);
            $hint = '';
            if (!empty($similar)) {
                $labels = array_map(static function ($m) {
                    return trim(($m->display ?? '') . ' (#' . (int) $m->cust_id . ')');
                }, $similar);
                $hint = ' Existing: ' . implode(', ', $labels) . '. Merge duplicates instead of renaming into a clash.';
            }
            self::set_customer_validation_error(
                'Another customer already uses this name (or a very similar spelling).' . $hint
            );
            return false;
        }

        $updated = $wpdb->update(
            $table_name,
            $update_data,
            ['cust_id' => $cust_id]
        );
        if ($updated === false && $wpdb->last_error) {
            self::set_customer_validation_error('Database error: ' . $wpdb->last_error);
        }
        if ($updated !== false && class_exists('Courier_Google_Sheets_Sync')) {
            $row_after = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE cust_id = %d", $cust_id));
            if ($row_after) {
                Courier_Google_Sheets_Sync::sync_customer_update($row_after);
            }
        }
        return $updated !== false;
    }

    public static function handle_update_customer()
    {
        if (!isset($_POST['cust_update_nonce']) || !wp_verify_nonce($_POST['cust_update_nonce'], 'update_customer_nonce')) {
            wp_die('Nonce verification failed');
        }

        $cust_id = intval($_POST['cust_id'] ?? $_POST['customer_id'] ?? 0);

        $data = [
            'name'    => sanitize_text_field($_POST['name'] ?? ''),
            'surname' => sanitize_text_field($_POST['surname'] ?? ''),
            'cell'    => sanitize_text_field($_POST['cell'] ?? ''),
            'address' => sanitize_textarea_field($_POST['address'] ?? ''),
            'email_address' => sanitize_text_field($_POST['email_address'] ?? ''),
            'vat_number' => sanitize_text_field($_POST['vat_number'] ?? ''),
        ];
        if (isset($_POST['company_id']) && (int) $_POST['company_id'] > 0) {
            $data['company_id'] = (int) $_POST['company_id'];
        }

        // Match save_customer: empty country/city → NULL (not 0), so FK / city rows are not violated.
        if (isset($_POST['origin_country'])) {
            $data['country_id'] = $_POST['origin_country'] !== '' ? (int) $_POST['origin_country'] : null;
        } elseif (isset($_POST['country_id'])) {
            $data['country_id'] = $_POST['country_id'] !== '' ? (int) $_POST['country_id'] : null;
        }
        if (isset($_POST['origin_city'])) {
            $data['city_id'] = $_POST['origin_city'] !== '' ? (int) $_POST['origin_city'] : null;
        } elseif (isset($_POST['city_id'])) {
            $data['city_id'] = $_POST['city_id'] !== '' ? (int) $_POST['city_id'] : null;
        }

        // Convert individual → company only when the admin explicitly checks the box.
        $convert = !empty($_POST['kit_convert_to_company']);
        if ($convert && class_exists('KIT_Company_Customers')) {
            $data['company_name'] = sanitize_text_field($_POST['company_name'] ?? '');
            $company_id = KIT_Company_Customers::convert_customer_to_company($cust_id, $data);
            if ($company_id) {
                wp_redirect(admin_url('admin.php?page=08600-customers&edit_company=' . (int) $company_id . '&converted=1'));
                exit;
            }
            $msg = rawurlencode(KIT_Company_Customers::get_validation_error() ?: 'Could not convert customer to company.');
            wp_redirect(admin_url('admin.php?page=edit-customer&edit_customer=' . $cust_id . '&update_error=1&msg=' . $msg));
            exit;
        }

        $updated = KIT_Customers::update_customer($cust_id, $data);

        if ($updated) {
            // Redirect with success parameter (toast will be shown on redirected page)
            wp_redirect(admin_url('admin.php?page=08600-customers&view_customer=' . $cust_id . '&updated=1'));
            exit;
        }

        $msg = rawurlencode(self::get_last_customer_validation_error() ?: 'Could not update customer.');
        wp_redirect(admin_url('admin.php?page=edit-customer&edit_customer=' . $cust_id . '&update_error=1&msg=' . $msg));
        exit;
    }

    public static function handle_save_customer_ajax()
    {
        // Debug: Log the request
        error_log('Customer AJAX request received: ' . print_r($_POST, true));

        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'customer_nonce')) {
            error_log('Customer AJAX nonce failed');
            wp_send_json_error(['message' => 'Security check failed']);
        }

        // A valid nonce only proves the request came from our form, not that the
        // sender is staff — subscribers must not be able to write customer records.
        if (!current_user_can('manage_options') && !current_user_can('kit_update_data')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        // Check if required fields are present
        if (empty($_POST['name']) || empty($_POST['surname']) || empty($_POST['cell']) || empty($_POST['address'])) {
            error_log('Customer AJAX missing required fields');
            wp_send_json_error(['message' => 'Please fill in all required fields (First Name, Last Name, Cell, Address)']);
        }

        $country_input = isset($_POST['country_id']) && $_POST['country_id'] !== '' ? $_POST['country_id'] : ($_POST['origin_country'] ?? '');
        $city_input = isset($_POST['city_id']) && $_POST['city_id'] !== '' ? $_POST['city_id'] : ($_POST['origin_city'] ?? '');

        $email_address = isset($_POST['email_address']) && trim($_POST['email_address']) !== ''
            ? sanitize_email(trim($_POST['email_address']))
            : null;

        $cust_data = [
            'name'     => sanitize_text_field($_POST['name'] ?? ''),
            'surname'  => sanitize_text_field($_POST['surname'] ?? ''),
            'cell'     => sanitize_text_field($_POST['cell'] ?? ''),
            'address'  => sanitize_text_field($_POST['address'] ?? ''),
            'email_address' => $email_address,
            'country_id' => ($country_input !== '' ? intval($country_input) : 0),
            'city_id' => ($city_input !== '' ? intval($city_input) : 0),
            'vat_number' => sanitize_text_field($_POST['vat_number'] ?? ''),
        ];

        $new_id = self::save_customer($cust_data);

        if ($new_id === false) {
            wp_send_json_error([
                'message' => self::get_last_customer_validation_error() ?: 'Could not save customer.',
            ]);
        }

        wp_send_json_success(['message' => 'Customer saved successfully! 🎉', 'customer_id' => $new_id]);
    }

    public static function test_customer_ajax()
    {
        wp_send_json_success(['message' => 'AJAX is working!', 'post_data' => $_POST]);
    }

    public static function handle_get_cities_by_country()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'customer_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $country_id = intval($_POST['country_id'] ?? 0);

        if (!$country_id) {
            wp_send_json_error(['message' => 'Country ID is required']);
        }

        global $wpdb;
        $primary_cities_table = $wpdb->prefix . 'kit_operating_cities';
        $legacy_cities_table = $wpdb->prefix . 'kit_cities';

        // Prefer the current schema table. Fall back to legacy only if needed.
        $primary_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $primary_cities_table));
        $legacy_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $legacy_cities_table));
        $cities_table = $primary_exists ? $primary_cities_table : ($legacy_exists ? $legacy_cities_table : '');

        if ($cities_table === '') {
            wp_send_json_success([]);
        }

        $cities = $wpdb->get_results($wpdb->prepare(
            "SELECT id, city_name FROM $cities_table WHERE country_id = %d ORDER BY city_name ASC",
            $country_id
        ));

        if ($cities) {
            wp_send_json_success($cities);
        } else {
            wp_send_json_success([]); // Return empty array if no cities found
        }
    }

    /**
     * Render customer form for modal or inline use
     * 
     * @param array $atts Array of attributes:
     *   - form_action: Form action URL
     *   - customer: Customer data (null for new)
     *   - is_modal: Whether rendered in modal (boolean)
     * @return string HTML form content
     */
    public static function render_customer_form($atts = [])
    {
        $atts = shortcode_atts([
            'form_action' => admin_url('admin-post.php'),
            'customer' => null,
            'is_modal' => false,
        ], $atts);

        $form_action = esc_url($atts['form_action']);
        $customer = $atts['customer'];
        $is_modal = $atts['is_modal'];

        // Get countries
        require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
        $countries = KIT_Deliveries::getCountriesObject();

        ob_start();
?>
        <form id="add-customer-form" method="post" action="<?php echo $form_action; ?>" class="space-y-6">
            <input type="hidden" name="action" value="add_customer">
            <?php wp_nonce_field('add_customer_nonce', 'customer_nonce'); ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="company_name" class="block text-sm font-medium text-gray-700 mb-2">Company Name *</label>
                    <input type="text" name="company_name" id="company_name" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500"
                        value="<?php echo esc_attr($customer['company_name'] ?? $_POST['company_name'] ?? ''); ?>">
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">First Name *</label>
                    <input type="text" name="name" id="name" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500"
                        value="<?php echo esc_attr($customer['name'] ?? $_POST['name'] ?? ''); ?>">
                </div>

                <div>
                    <label for="surname" class="block text-sm font-medium text-gray-700 mb-2">Last Name *</label>
                    <input type="text" name="surname" id="surname" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500"
                        value="<?php echo esc_attr($customer['surname'] ?? $_POST['surname'] ?? ''); ?>">
                </div>

                <div>
                    <label for="cell" class="block text-sm font-medium text-gray-700 mb-2">Cell Phone *</label>
                    <input type="tel" name="cell" id="cell" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500"
                        value="<?php echo esc_attr($customer['cell'] ?? $_POST['cell'] ?? ''); ?>">
                </div>

                <div>
                    <label for="email_address" class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
                    <input type="email" name="email_address" id="email_address" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500"
                        value="<?php echo esc_attr($customer['email_address'] ?? $_POST['email_address'] ?? ''); ?>">
                </div>

                <div>
                    <label for="country_id" class="block text-sm font-medium text-gray-700 mb-2">Country</label>
                    <select name="country_id" id="country_id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <option value="">Select Country</option>
                        <?php foreach ($countries as $country): ?>
                            <option value="<?php echo esc_attr($country->id); ?>"
                                <?php selected($customer['country_id'] ?? $_POST['country_id'] ?? '', $country->id); ?>>
                                <?php echo esc_html($country->country_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="city_id" class="block text-sm font-medium text-gray-700 mb-2">City</label>
                    <select name="city_id" id="city_id"
                        data-selected-city="<?php echo esc_attr($customer['city_id'] ?? $_POST['city_id'] ?? ''); ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <option value="">Select City</option>
                    </select>
                </div>

                <div>
                    <label for="vat_number" class="block text-sm font-medium text-gray-700 mb-2">VAT Number</label>
                    <input type="text" name="vat_number" id="vat_number"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500"
                        placeholder="VAT registration number"
                        value="<?php echo esc_attr($customer['vat_number'] ?? $_POST['vat_number'] ?? ''); ?>">
                </div>
            </div>

            <div>
                <label for="address" class="block text-sm font-medium text-gray-700 mb-2">Address *</label>
                <textarea name="address" id="address" rows="3" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500"><?php echo esc_textarea($customer['address'] ?? $_POST['address'] ?? ''); ?></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-6 border-t border-gray-200">
                <?php if (!$is_modal): ?>
                    <a href="<?php echo admin_url('admin.php?page=08600-customers'); ?>"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        Cancel
                    </a>
                <?php endif; ?>
                <?php echo KIT_Commons::renderButton('Save Customer', 'primary', 'lg', ['type' => 'submit']); ?>
            </div>
        </form>

        <script>
            jQuery(document).ready(function($) {
                var ajaxEndpoint = (typeof ajaxurl !== 'undefined' && ajaxurl) ? ajaxurl : '/wp-admin/admin-ajax.php';

                $('form#add-customer-form').each(function() {
                    var $form = $(this);
                    var $countrySelect = $form.find('#country_id');
                    var $citySelect = $form.find('#city_id');
                    var initialSelectedCity = String($citySelect.data('selected-city') || '');

                    var loadCities = function(selectedCityId) {
                        var countryId = $countrySelect.val();

                        if (!countryId) {
                            $citySelect.html('<option value="">Select City</option>');
                            return;
                        }

                        $citySelect.html('<option value="">Loading cities...</option>');

                        $.ajax({
                            url: ajaxEndpoint,
                            type: 'POST',
                            data: {
                                action: 'get_cities_by_country',
                                country_id: countryId,
                                nonce: '<?php echo wp_create_nonce('customer_nonce'); ?>'
                            },
                            success: function(response) {
                                $citySelect.html('<option value="">Select City</option>');
                                if (response.success && response.data) {
                                    $.each(response.data, function(index, city) {
                                        var cityId = String(city.id);
                                        $citySelect.append($('<option>', {
                                            value: cityId,
                                            text: city.city_name,
                                            selected: selectedCityId !== '' && selectedCityId === cityId
                                        }));
                                    });
                                }
                            },
                            error: function() {
                                $citySelect.html('<option value="">Error loading cities</option>');
                            }
                        });
                    };

                    $countrySelect.on('change', function() {
                        loadCities('');
                    });

                    // If country is already selected (e.g. edit mode), load and preselect city.
                    if ($countrySelect.val()) {
                        loadCities(initialSelectedCity);
                    }
                });
            });
        </script>
    <?php
        return ob_get_clean();
    }

    //After the new customer is saved, go back to the customer table and update the cust_id to the new customer id
    public static function update_customer_id($customer_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';

        // Update the customer ID
        $updated = $wpdb->update($table_name, ['cust_id' => $customer_id, 'id' => $customer_id]);

        return $updated;
    }

    public static function delete_customer($id)
    {
        global $wpdb;
        $customer_id = intval($id);
        $table_name = $wpdb->prefix . 'kit_customers';
        $db_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE cust_id = %d", $customer_id));

        if (class_exists('Courier_Google_Sheets_Sync')) {
            Courier_Google_Sheets_Sync::sync_customer_delete($customer_id, $db_id);
        }
        // First, delete all waybills for this customer
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $wpdb->delete($waybills_table, ['customer_id' => $customer_id]);

        // Now delete the customer
        $customers_table = $wpdb->prefix . 'kit_customers';
        $deleted = $wpdb->delete($customers_table, ['cust_id' => $customer_id]);

        if ($deleted) {
            if (!class_exists('KIT_Toast')) {
                require_once plugin_dir_path(__FILE__) . '../components/toast.php';
            }
            KIT_Toast::ensure_toast_loads();
            echo KIT_Toast::success('Customer deleted successfully.', 'Customer Deleted');
            wp_redirect(admin_url('admin.php?page=customers-dashboard'));
            exit;
        } else {
            if (!class_exists('KIT_Toast')) {
                require_once plugin_dir_path(__FILE__) . '../components/toast.php';
            }
            KIT_Toast::ensure_toast_loads();
            echo KIT_Toast::warning('Customer not found or already deleted.', 'Warning');
        }
    }

    /**
     * Merge duplicate customer into keep: move waybills, retarget portal users, delete duplicate row.
     * Does NOT delete waybills.
     *
     * @return array{ok:bool,message:string,waybills_moved:int,keep_cust_id:int,drop_cust_id:int}
     */
    public static function merge_customers(int $keep_cust_id, int $drop_cust_id): array
    {
        $keep_cust_id = (int) $keep_cust_id;
        $drop_cust_id = (int) $drop_cust_id;
        $result = [
            'ok' => false,
            'message' => '',
            'waybills_moved' => 0,
            'keep_cust_id' => $keep_cust_id,
            'drop_cust_id' => $drop_cust_id,
        ];

        if ($keep_cust_id <= 0 || $drop_cust_id <= 0) {
            $result['message'] = 'Invalid customer IDs.';
            return $result;
        }
        if ($keep_cust_id === $drop_cust_id) {
            $result['message'] = 'Cannot merge a customer into itself.';
            return $result;
        }

        global $wpdb;
        $customers_t = $wpdb->prefix . 'kit_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';

        $keep = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$customers_t} WHERE cust_id = %d", $keep_cust_id));
        $drop = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$customers_t} WHERE cust_id = %d", $drop_cust_id));
        if (!$keep || !$drop) {
            $result['message'] = 'One or both customers were not found.';
            return $result;
        }

        // Move waybills linked by cust_id.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$waybills_t} SET customer_id = %d WHERE customer_id = %d",
            $keep_cust_id,
            $drop_cust_id
        ));
        $moved = (int) $wpdb->rows_affected;

        // Also fix legacy links that stored kit_customers.id instead of cust_id.
        $drop_db_id = (int) ($drop->id ?? 0);
        if ($drop_db_id > 0 && $drop_db_id !== $drop_cust_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$waybills_t} SET customer_id = %d WHERE customer_id = %d",
                $keep_cust_id,
                $drop_db_id
            ));
            $moved += (int) $wpdb->rows_affected;
        }

        // Retarget portal WordPress users from duplicate → keep.
        $portal_users = get_users([
            'meta_key' => 'kit_customer_id',
            'meta_value' => $drop_cust_id,
            'fields' => ['ID'],
            'number' => 50,
        ]);
        foreach ($portal_users as $user) {
            update_user_meta((int) $user->ID, 'kit_customer_id', $keep_cust_id);
        }

        if (class_exists('Courier_Google_Sheets_Sync')) {
            Courier_Google_Sheets_Sync::sync_customer_delete($drop_cust_id, $drop_db_id);
        }

        $deleted = $wpdb->delete($customers_t, ['cust_id' => $drop_cust_id], ['%d']);
        if (!$deleted) {
            $result['message'] = 'Waybills may have been moved, but the duplicate customer could not be deleted.';
            $result['waybills_moved'] = $moved;
            return $result;
        }

        $result['ok'] = true;
        $result['waybills_moved'] = $moved;
        $keep_label = trim((string) ($keep->name ?? '') . ' ' . (string) ($keep->surname ?? ''));
        $drop_label = trim((string) ($drop->name ?? '') . ' ' . (string) ($drop->surname ?? ''));
        $result['message'] = sprintf(
            'Merged “%s” into “%s”. Moved %d waybill(s) and removed the duplicate.',
            $drop_label !== '' ? $drop_label : ('#' . $drop_cust_id),
            $keep_label !== '' ? $keep_label : ('#' . $keep_cust_id),
            $moved
        );

        return $result;
    }

    public static function handle_merge_customers()
    {
        if (!isset($_POST['kit_merge_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kit_merge_nonce'])), 'kit_merge_customers')) {
            wp_die('Nonce verification failed');
        }
        if (!current_user_can('manage_options') && !current_user_can('administrator')) {
            wp_die('Sorry, you are not allowed to merge customers.');
        }

        $keep = (int) ($_POST['keep_cust_id'] ?? 0);
        $drop = (int) ($_POST['drop_cust_id'] ?? 0);
        $result = self::merge_customers($keep, $drop);

        $qs = [
            'page' => '08600-customers',
            'tab' => 'manage-customers',
        ];
        if ($result['ok']) {
            $qs['merged'] = 1;
            $qs['waybills_moved'] = (int) $result['waybills_moved'];
            $qs['keep'] = $keep;
        } else {
            $qs['merge_error'] = 1;
            $qs['msg'] = $result['message'] ?: 'Merge failed.';
            $qs['merge_customer'] = $drop;
        }

        wp_safe_redirect(add_query_arg($qs, admin_url('admin.php')));
        exit;
    }

    /**
     * Admin UI: choose which customer to keep when merging a duplicate.
     */
    public static function render_merge_customer_form(int $drop_cust_id): void
    {
        global $wpdb;
        $drop_cust_id = (int) $drop_cust_id;
        $customers_t = $wpdb->prefix . 'kit_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';

        $drop = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$customers_t} WHERE cust_id = %d", $drop_cust_id));
        if (!$drop) {
            echo '<div class="notice notice-error"><p>Customer not found.</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=08600-customers')) . '">&larr; Back to customers</a></p>';
            return;
        }

        $drop_name = trim((string) ($drop->name ?? '') . ' ' . (string) ($drop->surname ?? ''));
        $drop_waybills = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$waybills_t} WHERE customer_id = %d",
            $drop_cust_id
        ));

        $similar = self::find_similar_person_customers(
            (string) ($drop->name ?? ''),
            (string) ($drop->surname ?? ''),
            $drop_cust_id,
            20
        );

        $all = $wpdb->get_results($wpdb->prepare(
            "SELECT c.cust_id, c.name, c.surname,
                (SELECT COUNT(*) FROM {$waybills_t} w WHERE w.customer_id = c.cust_id) AS total_waybills
             FROM {$customers_t} c
             WHERE c.cust_id <> %d
             ORDER BY c.name ASC, c.surname ASC
             LIMIT 500",
            $drop_cust_id
        ));

        $suggested_ids = [];
        foreach ($similar as $m) {
            $suggested_ids[(int) $m->cust_id] = true;
        }

        echo '<div class="wrap kit-merge-customer" style="max-width:720px;">';
        echo '<h1>Merge duplicate customer</h1>';
        echo '<p>Transfer all waybills from the duplicate into the customer you keep, then delete the duplicate. Waybills are <strong>not</strong> deleted.</p>';
        echo '<div class="notice notice-info" style="padding:12px;"><p><strong>Duplicate (will be removed):</strong> '
            . esc_html($drop_name !== '' ? $drop_name : ('#' . $drop_cust_id))
            . ' · ' . esc_html((string) $drop_waybills) . ' waybill(s)</p></div>';

        if (!empty($similar)) {
            echo '<p><strong>Suggested matches</strong> (same person after normalizing spelling):</p><ul>';
            foreach ($similar as $m) {
                echo '<li>' . esc_html($m->display) . ' (#' . (int) $m->cust_id . ')</li>';
            }
            echo '</ul>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="kit_merge_customers" />';
        echo '<input type="hidden" name="drop_cust_id" value="' . esc_attr((string) $drop_cust_id) . '" />';
        wp_nonce_field('kit_merge_customers', 'kit_merge_nonce');
        echo '<p><label for="keep_cust_id"><strong>Keep this customer</strong></label><br />';
        echo '<select name="keep_cust_id" id="keep_cust_id" required style="min-width:100%;max-width:100%;">';
        echo '<option value="">— Select customer to keep —</option>';
        foreach ($all as $row) {
            $cid = (int) $row->cust_id;
            $label = trim((string) ($row->name ?? '') . ' ' . (string) ($row->surname ?? ''));
            if ($label === '') {
                $label = '#' . $cid;
            }
            $wb = (int) ($row->total_waybills ?? 0);
            $prefix = isset($suggested_ids[$cid]) ? '★ ' : '';
            echo '<option value="' . esc_attr((string) $cid) . '">'
                . esc_html($prefix . $label . ' (#' . $cid . ', ' . $wb . ' waybills)')
                . '</option>';
        }
        echo '</select></p>';
        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Move all waybills to the selected customer and delete the duplicate?\');">Merge &amp; delete duplicate</button> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=08600-customers')) . '">Cancel</a>';
        echo '</p></form></div>';
    }

    public static function tholaMaCustomer()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        return $wpdb->get_results("
        SELECT 
            c.id, 
            c.cust_id, 
            c.name as customer_name, 
            c.surname as customer_surname, 
            c.email_address, 
            c.cell, 
            c.address, 
            c.country_id, 
            c.city_id,
            country.country_name,
            city.city_name,
            c.company_id,
            co.company_name,
            COUNT(w.id) as total_waybills
        FROM $table_name c
        LEFT JOIN {$wpdb->prefix}kit_operating_countries country ON c.country_id = country.id
        LEFT JOIN {$wpdb->prefix}kit_operating_cities city ON c.city_id = city.id
        LEFT JOIN {$wpdb->prefix}kit_company_customers co ON c.company_id = co.company_id
        LEFT JOIN $waybills_table w ON w.customer_id = c.cust_id
            OR (w.customer_id = c.id AND c.id > 0 AND w.customer_id <> c.cust_id)
        GROUP BY c.id, c.cust_id, c.name, c.surname, c.email_address, c.cell, c.address, c.country_id, c.city_id, country.country_name, city.city_name, c.company_id, co.company_name
        ");
    }

    /**
     * Upload a CSV or Excel file and create customers for each row.
     * Accepts a file input named 'customers_file'.
     * Returns an array with 'created', 'errors'.
     */
    public static function upload_customers_csv_excel()
    {
        if (!isset($_FILES['customers_file']) || $_FILES['customers_file']['error'] !== UPLOAD_ERR_OK) {
            return ['created' => 0, 'errors' => ['No file uploaded or upload error']];
        }

        $file = $_FILES['customers_file']['tmp_name'];
        $filename = $_FILES['customers_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $rows = [];
        $errors = [];

        // Parse CSV
        if ($ext === 'csv') {
            if (($handle = fopen($file, 'r')) !== false) {
                // Skip first empty line
                fgetcsv($handle, 0, ';');

                // Get headers from second line
                $header = fgetcsv($handle, 0, ';');
                if ($header === false || count($header) < 6) {
                    fclose($handle);
                    return ['created' => 0, 'errors' => ['Invalid CSV format or headers']];
                }

                // Remove empty first column from headers
                $header = array_slice($header, 1);
                $header = array_map('trim', $header);

                // Process data rows
                $lineNumber = 2; // Start after header
                while (($data = fgetcsv($handle, 0, ';')) !== false) {
                    $lineNumber++;

                    // Skip empty rows
                    if (count($data) <= 1) continue;

                    // Remove empty first column
                    $data = array_slice($data, 1);
                    $data = array_map('trim', $data);

                    // Validate row
                    if (count($data) !== count($header)) {
                        $errors[] = "Line $lineNumber: Skipped - column count mismatch";
                        continue;
                    }

                    try {
                        $rowData = array_combine($header, $data);

                        // Map CSV fields to database fields
                        $mappedData = [
                            'cust_id' => $rowData['cust_id'] ?? '',
                            'customer_name' => $rowData['name'] ?? '',
                            'customer_surname' => $rowData['surname'] ?? '',
                            'cell' => $rowData['cell'] ?? '',
                            'email_address' => $rowData['email_address'] ?? '',
                            'address' => $rowData['address'] ?? '',
                            'country_id' => $rowData['country_id'] ?? '',
                            'city_id' => $rowData['city_id'] ?? '',
                            'company_name' => $rowData['company_name'] ?? '',


                        ];

                        // Validate required fields
                        if (empty($mappedData['customer_name']) || empty($mappedData['customer_surname'])) {
                            $errors[] = "Line $lineNumber: Skipped - missing name or surname";
                            continue;
                        }

                        $rows[] = $mappedData;
                    } catch (ValueError $e) {
                        $errors[] = "Line $lineNumber: Skipped - " . $e->getMessage();
                    }
                }
                fclose($handle);
            } else {
                return ['created' => 0, 'errors' => ['Failed to open CSV file']];
            }
        } elseif (in_array($ext, ['xlsx', 'xls'])) {
            if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                require_once ABSPATH . 'vendor/autoload.php';
            }
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
                $sheet = $spreadsheet->getActiveSheet();

                $header = [];
                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    $rowData = [];
                    foreach ($cellIterator as $cell) {
                        $rowData[] = $cell->getValue();
                    }

                    if ($rowIndex === 1) {
                        $header = $rowData;
                    } else {
                        if (count($rowData) === count($header)) {
                            try {
                                $rowData = array_combine($header, $rowData);
                                $rows[] = [
                                    'cust_id' => $rowData['cust_id'] ?? '',
                                    'customer_name' => $rowData['name'] ?? '',
                                    'customer_surname' => $rowData['surname'] ?? '',
                                    'cell' => $rowData['cell'] ?? '',
                                    'email_address' => $rowData['email_address'] ?? '',
                                    'address' => $rowData['address'] ?? '',
                                    'country_id'  => $rowData['country_id'] ?? '',
                                    'city_id'  => $rowData['city_id'] ?? '',
                                    'company_name' => $rowData['company_name'] ?? '',
                                ];
                            } catch (ValueError $e) {
                                $errors[] = "Excel row $rowIndex: " . $e->getMessage();
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                return ['created' => 0, 'errors' => ['Excel parse error: ' . $e->getMessage()]];
            }
        } else {
            return ['created' => 0, 'errors' => ['Unsupported file type']];
        }

        // Create customers
        $created = 0;
        foreach ($rows as $row) {
            $result = self::save_customer($row);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $created++;
            }
        }

        return [
            'created' => $created,
            'errors' => $errors,
            'total_rows' => count($rows)
        ];
    }

    /**
     * Render the customer CSV/Excel upload form and handle upload in the admin UI.
     */
    public static function render_upload_customers_form()
    {
        $output = '';
        // Handle form submission
        if (!empty($_POST['upload_customers_csv_excel_nonce']) && isset($_FILES['customers_file'])) {
            // Check nonce if in WordPress
            if (function_exists('wp_verify_nonce')) {
                if (!wp_verify_nonce($_POST['upload_customers_csv_excel_nonce'], 'upload_customers_csv_excel')) {
                    $output .= '<div class="notice notice-error"><p>Security check failed.</p></div>';
                } else {
                    $result = self::upload_customers_csv_excel();
                    if ($result['created'] > 0) {
                        $output .= '<div class="notice notice-success"><p>' . $result['created'] . ' customers created successfully.</p></div>';
                    }
                    if (!empty($result['errors'])) {
                        $output .= '<div class="notice notice-error"><ul>';
                        foreach ($result['errors'] as $err) {
                            $output .= '<li>' . htmlspecialchars($err) . '</li>';
                        }
                        $output .= '</ul></div>';
                    }
                }
            } else {
                // If not in WordPress, skip nonce check
                $result = self::upload_customers_csv_excel();
                if ($result['created'] > 0) {
                    $output .= '<div class="notice notice-success"><p>' . $result['created'] . ' customers created successfully.</p></div>';
                }
                if (!empty($result['errors'])) {
                    $output .= '<div class="notice notice-error"><ul>';
                    foreach ($result['errors'] as $err) {
                        $output .= '<li>' . htmlspecialchars($err) . '</li>';
                    }
                    $output .= '</ul></div>';
                }
            }
        }
        // Render the form
        $output .= '<form method="post" enctype="multipart/form-data">';
        if (function_exists('wp_nonce_field')) {
            $output .= wp_nonce_field('upload_customers_csv_excel', 'upload_customers_csv_excel_nonce', true, false);
        }
        $output .= '<h3>Bulk Upload Customers (CSV or Excel)</h3>';
        $output .= '<input type="file" name="customers_file" accept=".csv,.xlsx,.xls" required> ';
        $output .= KIT_Commons::renderButton('Upload', 'primary', 'lg', ['type' => 'submit', 'gradient' => true]);
        $output .= '</form>';
        return $output;
    }

    /**
     * Example: Integrate the upload form into the customer dashboard page.
     * Call this in your customer dashboard rendering logic.
     */
    public static function customer_dashboard_page()
    {
        // Enqueue required scripts
        wp_enqueue_script('kitscript', plugin_dir_url(__FILE__) . '../js/kitscript.js', ['jquery'], null, true);

        // Localize script with AJAX data
        wp_localize_script('kitscript', 'customerAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('customer_nonce')
        ));

        // Handle delete customer action
        if (isset($_GET['delete_customer']) && !empty($_GET['delete_customer'])) {
            $customer_id = intval($_GET['delete_customer']);

            // Check if user has permission to delete customers
            if (current_user_can('manage_options') || current_user_can('administrator')) {
                delete_customer($customer_id, true);
                wp_redirect(admin_url('admin.php?page=08600-customers&deleted=1&tab=manage-customers'));
                exit;
            } else {
                wp_die('Sorry, you are not allowed to delete customers.');
            }
        }

        // Handle merge duplicate customer UI
        if (isset($_GET['merge_customer']) && !empty($_GET['merge_customer'])) {
            if (!(current_user_can('manage_options') || current_user_can('administrator'))) {
                wp_die('Sorry, you are not allowed to merge customers.');
            }
            self::render_merge_customer_form((int) $_GET['merge_customer']);
            return;
        }

        // Handle delete company action
        if (isset($_GET['delete_company']) && !empty($_GET['delete_company'])) {
            $company_id = intval($_GET['delete_company']);
            if (current_user_can('manage_options') || current_user_can('administrator')) {
                if (class_exists('KIT_Company_Customers')) {
                    KIT_Company_Customers::delete_company($company_id);
                }
                wp_redirect(admin_url('admin.php?page=08600-customers&company_deleted=1'));
                exit;
            }
            wp_die('Sorry, you are not allowed to delete companies.');
        }

        // Handle edit company (inline on customers page)
        if (isset($_GET['edit_company']) && !empty($_GET['edit_company']) && class_exists('KIT_Company_Customers')) {
            if (!function_exists('kit_edit_company_form')) {
                require_once dirname(__FILE__) . '/company-customers-functions.php';
            }
            kit_edit_company_form(intval($_GET['edit_company']));
            return;
        }

        // Handle view company action
        if (isset($_GET['view_company']) && !empty($_GET['view_company']) && class_exists('KIT_Company_Customers')) {
            if (!function_exists('kit_view_company_detail')) {
                require_once dirname(__FILE__) . '/company-customers-functions.php';
            }
            kit_view_company_detail(intval($_GET['view_company']));
            return;
        }

        // Handle view customer action
        if (isset($_GET['view_customer']) && !empty($_GET['view_customer'])) {
            customer_detail_view($_GET['view_customer']);
            return;
        }

        // Handle edit customer action
        if (isset($_GET['edit_customer']) && !empty($_GET['edit_customer'])) {
            edit_customer_form($_GET['edit_customer']);
            return;
        }

        // Handle download PDF customer summary action
        if (isset($_GET['download_pdf_customer_summary']) && !empty($_GET['download_pdf_customer_summary'])) {
            $customer_id = intval($_GET['download_pdf_customer_summary']);

            // Get all waybill numbers for this customer
            global $wpdb;
            $waybills_table = $wpdb->prefix . 'kit_waybills';
            $waybill_nos = $wpdb->get_col($wpdb->prepare(
                "SELECT waybill_no FROM $waybills_table WHERE customer_id = %d ORDER BY waybill_no ASC",
                $customer_id
            ));

            if (!empty($waybill_nos)) {
                // Use pdf-customer-bulk.php to generate customer summary with all waybills
                // Go up 2 levels from includes/customers/ to plugin root
                $plugin_url = dirname(dirname(plugin_dir_url(__FILE__)));
                $pdf_url = add_query_arg([
                    'selected_ids' => implode(',', $waybill_nos),
                    'customer_id' => $customer_id
                ], $plugin_url . '/pdf-customer-bulk.php');

                wp_redirect($pdf_url);
                exit;
            } else {
                // No waybills found for this customer
                if (class_exists('KIT_Toast')) {
                    KIT_Toast::ensure_toast_loads();
                    echo KIT_Toast::error('No waybills found for this customer.', 'No Waybills');
                }
                wp_redirect(admin_url('admin.php?page=08600-customers&view_customer=' . $customer_id));
                exit;
            }
        }

        // Handle customer update form submission
        if (isset($_POST['action']) && $_POST['action'] === 'update_customer') {
            if (wp_verify_nonce($_POST['cust_update_nonce'], 'update_customer_nonce')) {
                $customer_id = intval($_POST['customer_id']);

                // Update customer data - build base array
                $update_data = array(
                    'name' => sanitize_text_field($_POST['name']),
                    'surname' => sanitize_text_field($_POST['surname']),
                    'cell' => sanitize_text_field($_POST['cell']),
                    'email_address' => sanitize_email($_POST['email_address']),
                    'address' => sanitize_textarea_field($_POST['address']),
                    'vat_number' => sanitize_text_field($_POST['vat_number'])
                );

                // Include country_id/city_id from form (even when 0 so we can clear wrong values)
                if (isset($_POST['origin_country'])) {
                    $update_data['country_id'] = $_POST['origin_country'] !== '' ? intval($_POST['origin_country']) : 0;
                } elseif (isset($_POST['country_id'])) {
                    $update_data['country_id'] = $_POST['country_id'] !== '' ? intval($_POST['country_id']) : 0;
                }
                if (isset($_POST['origin_city'])) {
                    $update_data['city_id'] = $_POST['origin_city'] !== '' ? intval($_POST['origin_city']) : 0;
                } elseif (isset($_POST['city_id'])) {
                    $update_data['city_id'] = $_POST['city_id'] !== '' ? intval($_POST['city_id']) : 0;
                }

                $updated = $wpdb->update(
                    $wpdb->prefix . 'kit_customers',
                    $update_data,
                    array('cust_id' => $customer_id)
                );

                if ($updated !== false) {
                    wp_redirect(admin_url('admin.php?page=08600-customers&view_customer=' . $customer_id . '&updated=1'));
                    exit;
                } else {
                    wp_redirect(admin_url('admin.php?page=08600-customers&view_customer=' . $customer_id . '&error=1'));
                    exit;
                }
            }
        }

        // Data
        $customers = tholaMaCustomer();
        $total_customers = is_array($customers) ? count($customers) : 0;
        $active_customers_count = is_array($customers)
            ? count(array_filter($customers, function ($c) {
                return !empty($c->company_id) || !empty($c->company_name);
            }))
            : 0;
        $inactive_customers = max(0, $total_customers - $active_customers_count);

        // Toast after delete success
        if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
            $deleted_waybills = isset($_GET['waybills_deleted']) ? intval($_GET['waybills_deleted']) : 0;
            $msg = 'Customer deleted successfully';
            if ($deleted_waybills > 0) {
                $msg .= ' • Deleted ' . $deleted_waybills . ' waybill(s)';
            }
            require_once plugin_dir_path(__FILE__) . '../components/toast.php';
            echo KIT_Toast::success($msg);
        }

        if (isset($_GET['merged']) && $_GET['merged'] == '1') {
            if (!class_exists('KIT_Toast')) {
                require_once plugin_dir_path(__FILE__) . '../components/toast.php';
            }
            KIT_Toast::ensure_toast_loads();
            $moved = isset($_GET['waybills_moved']) ? (int) $_GET['waybills_moved'] : 0;
            $keep = isset($_GET['keep']) ? (int) $_GET['keep'] : 0;
            $msg = 'Customers merged successfully';
            if ($moved > 0) {
                $msg .= ' • Moved ' . $moved . ' waybill(s)';
            }
            if ($keep > 0) {
                $msg .= ' • Kept #' . $keep;
            }
            echo KIT_Toast::success($msg, 'Merge complete');
        }

        if (isset($_GET['merge_error']) && $_GET['merge_error'] == '1') {
            if (!class_exists('KIT_Toast')) {
                require_once plugin_dir_path(__FILE__) . '../components/toast.php';
            }
            KIT_Toast::ensure_toast_loads();
            $msg = isset($_GET['msg']) ? sanitize_text_field(wp_unslash($_GET['msg'])) : 'Merge failed.';
            echo KIT_Toast::error($msg, 'Merge failed');
        }

        // Show success message if customer was just added
        if (isset($_GET['customer_added']) && $_GET['customer_added'] == '1') {
            if (!class_exists('KIT_Toast')) {
                require_once plugin_dir_path(__FILE__) . '../components/toast.php';
            }
            KIT_Toast::ensure_toast_loads();
            echo KIT_Toast::success('Customer added successfully!', 'Customer Added');
        }

        // Overview
        require_once plugin_dir_path(__FILE__) . '../components/quickStats.php';
        require_once plugin_dir_path(__FILE__) . '../components/dashboardQuickies.php';
        require_once plugin_dir_path(__FILE__) . '../components/iconButton.php';

        // Get customer statistics
        $total_customers = count($customers);
        $active_customers = array_filter($customers, function ($c) {
            return !empty($c->company_id) || !empty($c->company_name);
        });
        $active_customers_count = count($active_customers);

        // UI Shell


    ?>
        <div class="wrap customers-page">
            <div class="<?php echo KIT_Commons::containerClasses(); ?>">
                <?php
                // Include modal component
                require_once plugin_dir_path(__FILE__) . '../components/modal.php';

                // Render customer form for modal
                $customer_form_content = self::render_customer_form();

                // Render Add Customer Modal
                $add_customer_modal = KIT_Modal::render(
                    'add-customer-modal',
                    'Add New Customer',
                    $customer_form_content,
                    '3xl',
                    true,
                    'Add Customer'
                );

                echo KIT_Commons::showingHeader([
                    'title' => 'Customers Management',
                    'desc'  => '',
                    'content' => $add_customer_modal,
                    'icon' => KIT_Commons::icon('user-group'),
                ]);

                $customers_stats = [
                    [
                        'title' => 'Total Customers',
                        'value' => number_format($total_customers),
                        'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                        'color' => 'blue',
                        'class' => 'customers-stats-total'
                    ],
                    [
                        'title' => 'Active Customers',
                        'value' => number_format($active_customers_count),
                        'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                        'color' => 'green',
                        'class' => 'customers-stats-active'
                    ],
                    [
                        'title' => 'Inactive Customers',
                        'value' => number_format($inactive_customers),
                        'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
                        'color' => 'yellow',
                        'class' => 'customers-stats-inactive'
                    ],
                    [
                        'title' => 'Customers Served',
                        'value' => number_format($total_customers),
                        'icon' => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                        'color' => 'purple',
                        'class' => 'customers-stats-served'
                    ]
                ];
        
                // Render stats
                echo KIT_QuickStats::render($customers_stats, '', [
                    'grid_cols' => 'grid-cols-1 sm:grid-cols-4 md:grid-cols-4 lg:grid-cols-4',
                    'gap' => 'gap-4'
                ]);

                // Sort customers by name A-Z by default (only if no sort parameter is set)
                if (!isset($_GET['orderby']) || empty($_GET['orderby'])) {
                    usort($customers, function ($a, $b) {
                        // Handle both customer_name/customer_surname and name/surname formats
                        $name_a = trim(($a->customer_name ?? $a->name ?? '') . ' ' . ($a->customer_surname ?? $a->surname ?? ''));
                        $name_b = trim(($b->customer_name ?? $b->name ?? '') . ' ' . ($b->customer_surname ?? $b->surname ?? ''));
                        return strcasecmp($name_a, $name_b);
                    });
                }

                // --- Companies list ---
                $companies_list = [];
                if (class_exists('KIT_Company_Customers')) {
                    $companies_result = KIT_Company_Customers::list_companies('', 500, 0);
                    foreach ($companies_result['items'] as $co) {
                        $obj = (object) $co;
                        $obj->total_waybills = (int) ($co['total_waybills'] ?? 0);
                        $companies_list[] = $obj;
                    }
                }

                // Individuals = anyone with a real person name (mixed person+company stay visible).
                // Pure company stubs (no person name) belong on the Companies list only.
                $individual_customers = array_values(array_filter($customers, static function ($c) {
                    $name = is_object($c)
                        ? trim((string) ($c->customer_name ?? $c->name ?? ''))
                        : trim((string) ($c['customer_name'] ?? $c['name'] ?? ''));
                    $surname = is_object($c)
                        ? trim((string) ($c->customer_surname ?? $c->surname ?? ''))
                        : trim((string) ($c['customer_surname'] ?? $c['surname'] ?? ''));
                    $person = trim($name . ' ' . $surname);
                    if ($person !== '') {
                        if (
                            function_exists('kit_seed_customer_looks_like_business')
                            && kit_seed_customer_looks_like_business($person)
                            && trim((string) (is_object($c) ? ($c->company_name ?? '') : ($c['company_name'] ?? ''))) === ''
                        ) {
                            // Name field is itself a business label with no separate person — Companies list.
                            return false;
                        }
                        return true;
                    }
                    $company = is_object($c) ? trim((string) ($c->company_name ?? '')) : trim((string) ($c['company_name'] ?? ''));
                    if ($company === '') {
                        return true;
                    }
                    if (class_exists('KIT_Company_Customers') && KIT_Company_Customers::is_placeholder_company($company)) {
                        return true;
                    }
                    return false;
                }));

                $table_scroll_height = '60vh';
                $btn = static function (string $variant, string $href, string $title, string $icon, string $onclick = '') {
                    $attrs = 'href="' . esc_url($href) . '" class="' . esc_attr(KIT_Icon::buttonClasses($variant, 'sm')) . ' customers-list-action" title="' . esc_attr($title) . '" aria-label="' . esc_attr($title) . '"';
                    if ($onclick !== '') {
                        $attrs .= ' onclick="' . esc_attr($onclick) . '"';
                    }
                    return '<a ' . $attrs . '>' . KIT_Icon::svg($icon, 14) . '<span class="customers-list-action-label">' . esc_html($title) . '</span></a>';
                };

                // Build senior-friendly list rows (stacked fields — no side scroll)
                $individual_rows = [];
                foreach ($individual_customers as $c) {
                    $name = trim((string) ($c->customer_name ?? $c->name ?? ''));
                    $surname = trim((string) ($c->customer_surname ?? $c->surname ?? ''));
                    $full_name = trim($name . ' ' . $surname) ?: '—';
                    $country = trim((string) ($c->country_name ?? '')) ?: '—';
                    $waybills = (int) ($c->total_waybills ?? 0);
                    $cust_id = (int) ($c->cust_id ?? 0);
                    // #region agent log
                    if ($full_name === '—') {
                        static $dbg_blank_rows = 0;
                        if ($dbg_blank_rows < 10) {
                            $dbg_blank_rows++;
                            $dbg = [
                                'sessionId' => '3f0725',
                                'runId' => 'blank-cust',
                                'hypothesisId' => 'C',
                                'location' => 'customers-functions.php:individuals_list',
                                'message' => 'rendering blank individual dash',
                                'data' => [
                                    'cust_id' => $cust_id,
                                    'name' => $name,
                                    'surname' => $surname,
                                    'company_name' => trim((string) ($c->company_name ?? '')),
                                    'waybills' => $waybills,
                                    'db_empty' => ($name === '' && $surname === ''),
                                ],
                                'timestamp' => (int) round(microtime(true) * 1000),
                            ];
                            @file_put_contents(
                                (defined('COURIER_FINANCE_PLUGIN_PATH') ? rtrim(COURIER_FINANCE_PLUGIN_PATH, "/\\") : dirname(__FILE__, 2)) . '/.cursor/debug-3f0725.log',
                                json_encode($dbg) . "\n",
                                FILE_APPEND
                            );
                        }
                    }
                    // #endregion
                    $company_meta = trim((string) ($c->company_name ?? ''));
                    if (
                        $company_meta !== ''
                        && class_exists('KIT_Company_Customers')
                        && KIT_Company_Customers::is_placeholder_company($company_meta)
                    ) {
                        $company_meta = '';
                    }
                    $search = strtolower($full_name . ' ' . $company_meta . ' ' . $country . ' ' . $waybills);
                    $meta_bits = array_values(array_filter([
                        $company_meta !== '' ? $company_meta : '',
                        $country !== '—' ? $country : '',
                        $waybills . ' waybill' . ($waybills === 1 ? '' : 's'),
                    ]));
                    $individual_rows[] = [
                        'search' => $search,
                        'title' => $full_name,
                        'lines' => [implode(' · ', $meta_bits)],
                        'actions_html' => $btn('blue', '?page=edit-customer&edit_customer=' . $cust_id, 'Edit', 'edit')
                            . $btn('green', '?page=08600-customers&view_customer=' . $cust_id, 'View', 'eye')
                            . $btn('red', '?page=08600-customers&delete_customer=' . $cust_id, 'Delete', 'trash', 'return confirm("Delete this customer AND all their waybills?")'),
                    ];
                }

                $company_rows = [];
                foreach ($companies_list as $co) {
                    $company_name = trim((string) ($co->company_name ?? '')) ?: '—';
                    $cell = trim((string) ($co->cell ?? ''));
                    $email = trim((string) ($co->email_address ?? ''));
                    if (function_exists('kit_seed_is_sheet_error_value')) {
                        if (kit_seed_is_sheet_error_value($cell)) {
                            $cell = '';
                        }
                        if (kit_seed_is_sheet_error_value($email)) {
                            $email = '';
                        }
                    }
                    $cell = $cell !== '' ? $cell : '—';
                    $email = $email !== '' ? $email : '—';
                    $waybills = (int) ($co->total_waybills ?? 0);
                    $company_id = (int) ($co->company_id ?? 0);
                    $search = strtolower($company_name . ' ' . $cell . ' ' . $email . ' ' . $waybills);
                    $meta_bits = array_values(array_filter([
                        $cell !== '—' ? $cell : '',
                        $email !== '—' ? $email : '',
                        $waybills . ' waybill' . ($waybills === 1 ? '' : 's'),
                    ]));
                    $company_rows[] = [
                        'search' => $search,
                        'title' => $company_name,
                        'lines' => [implode(' · ', $meta_bits)],
                        'actions_html' => $btn('blue', '?page=08600-customers&edit_company=' . $company_id, 'Edit', 'edit')
                            . $btn('green', '?page=08600-customers&view_company=' . $company_id, 'View', 'eye')
                            . $btn('red', '?page=08600-customers&delete_company=' . $company_id, 'Delete', 'trash', 'return confirm("Delete this company?")'),
                    ];
                }

                echo '<div class="customers-tables-side-by-side grid grid-cols-1 lg:grid-cols-2 gap-4 items-stretch mb-8">';
                echo self::render_customers_list_panel('Individuals', $individual_rows, 'Search individuals…', $table_scroll_height, 'No individuals found');
                echo self::render_customers_list_panel('Companies', $company_rows, 'Search companies…', $table_scroll_height, 'No companies found');
                echo '</div>';

                ?>
            </div>
        </div>
    <?php
    }

    /**
     * Senior-friendly searchable list panel (stacked rows, no horizontal scroll).
     *
     * @param array<int, array{search:string,title:string,lines:array<int,string>,actions_html:string}> $rows
     */
    private static function render_customers_list_panel(string $title, array $rows, string $placeholder, string $max_height, string $empty_message): string
    {
        $panel_id = 'customers-list-' . wp_unique_id();
        $search_id = $panel_id . '-search';
        $list_id = $panel_id . '-items';
        $count = count($rows);

        ob_start();
        ?>
        <div class="customers-table-panel customers-list-panel min-w-0 flex flex-col">
            <div class="customers-list-card">
                <div class="customers-list-header">
                    <h3 class="customers-list-title"><?php echo esc_html($title); ?>
                        <span class="customers-list-count" data-list-count="<?php echo esc_attr($list_id); ?>"><?php echo esc_html((string) $count); ?></span>
                    </h3>
                    <label class="customers-list-search" for="<?php echo esc_attr($search_id); ?>">
                        <span class="sr-only"><?php echo esc_html($placeholder); ?></span>
                        <svg class="customers-list-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg>
                        <input type="search" id="<?php echo esc_attr($search_id); ?>" class="customers-list-search-input" placeholder="<?php echo esc_attr($placeholder); ?>" autocomplete="off" data-list-target="<?php echo esc_attr($list_id); ?>">
                    </label>
                </div>
                <div class="customers-list-scroll" id="<?php echo esc_attr($list_id); ?>" style="max-height:<?php echo esc_attr($max_height); ?>;">
                    <?php if ($count === 0): ?>
                        <p class="customers-list-empty"><?php echo esc_html($empty_message); ?></p>
                    <?php else: ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <div class="customers-list-row" data-search="<?php echo esc_attr($row['search']); ?>">
                                <div class="customers-list-index" aria-hidden="true"><?php echo esc_html((string) ($index + 1)); ?></div>
                                <div class="customers-list-body">
                                    <div class="customers-list-name"><?php echo esc_html($row['title']); ?></div>
                                    <?php foreach ($row['lines'] as $line): ?>
                                        <?php if (trim((string) $line) === '' || trim((string) $line) === '—') { continue; } ?>
                                        <div class="customers-list-meta"><?php echo esc_html($line); ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="customers-list-actions">
                                    <?php echo $row['actions_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built with esc_* above ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <p class="customers-list-empty customers-list-no-match" hidden>No matches</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var input = document.getElementById(<?php echo wp_json_encode($search_id); ?>);
            var list = document.getElementById(<?php echo wp_json_encode($list_id); ?>);
            if (!input || !list) return;
            var rows = list.querySelectorAll('.customers-list-row');
            var noMatch = list.querySelector('.customers-list-no-match');
            var countEl = document.querySelector('[data-list-count="' + list.id + '"]');
            input.addEventListener('input', function () {
                var q = (input.value || '').toLowerCase().trim();
                var visible = 0;
                rows.forEach(function (row, i) {
                    var hay = row.getAttribute('data-search') || '';
                    var show = !q || hay.indexOf(q) !== -1;
                    row.hidden = !show;
                    if (show) {
                        visible++;
                        var idx = row.querySelector('.customers-list-index');
                        if (idx) idx.textContent = String(visible);
                    }
                });
                if (noMatch) noMatch.hidden = visible > 0 || rows.length === 0;
                if (countEl) countEl.textContent = String(visible);
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}

// Initialize
KIT_Customers::init();

function customer_button_with_modal()
{
    ?>
    <div class="p-6">
        <!-- Trigger Button -->
        <?php echo KIT_Commons::renderButton('Open Modal', 'success', 'lg', ['onclick' => 'document.getElementById(\'thaboModal\').classList.remove(\'hidden\')', 'gradient' => true]); ?>

        <!-- Modal Overlay -->
        <div id="thaboModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
            <!-- Modal Content -->
            <div class="bg-white p-6 rounded-xl shadow-xl w-96 text-center">
                <h2 class="text-xl font-semibold mb-4">Hey Thabo 👋</h2>
                <p class="mb-6">Welcome to the modal!</p>
                <?php echo KIT_Commons::renderButton('Close', 'secondary', 'lg', ['onclick' => 'document.getElementById(\'thaboModal\').classList.add(\'hidden\')']); ?>
            </div>
        </div>
    </div>
<?php
}

function zamazama($customer = null)
{
    ob_start();
?>
    <div class="bg-yellow-100 text-yellow-800 p-4 rounded mb-4">Zamazama function called. Nothing to do here yet.</div>
    <input type="text" name="cust_id" value="232332">
<?php
    return ob_get_clean();
}

function theForm($customer = null)
{
    $company_name = $customer['company_name'] ?? '';
    $first_name = $customer['name'] ?? ($customer['customer_name'] ?? '');
    $surname = $customer['surname'] ?? ($customer['customer_surname'] ?? '');
    $cell = $customer['cell'] ?? '';
    $email = $customer['email_address'] ?? '';
    $address = $customer['address'] ?? '';

?>
    <input type="hidden" name="cust_id" id="cust_id" value="<?= esc_attr($customer['cust_id'] ?? '') ?>">

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section class="rounded-xl border border-gray-200 bg-white p-4 md:p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Customer Profile</h2>
            <div class="space-y-4">
                <div>
                    <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1.5">Company Name</label>
                    <input type="text" id="company_name" name="company_name" value="<?= esc_attr($company_name); ?>" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="customer_name" class="block text-sm font-medium text-gray-700 mb-1.5">First Name</label>
                        <input type="text" id="customer_name" name="name" value="<?= esc_attr($first_name); ?>" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="customer_surname" class="block text-sm font-medium text-gray-700 mb-1.5">Last Name</label>
                        <input type="text" id="customer_surname" name="surname" value="<?= esc_attr($surname); ?>" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500">
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-4 md:p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Contact</h2>
            <div class="space-y-4">
                <div>
                    <label for="cell" class="block text-sm font-medium text-gray-700 mb-1.5">Cell Phone</label>
                    <input type="text" id="cell" name="cell" value="<?= esc_attr($cell); ?>" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500">
                </div>
                <div>
                    <label for="email_address" class="block text-sm font-medium text-gray-700 mb-1.5">Email Address</label>
                    <input type="email" id="email_address" name="email_address" value="<?= esc_attr($email); ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500">
                </div>
                <div>
                    <label for="address" class="block text-sm font-medium text-gray-700 mb-1.5">Address</label>
                    <textarea id="address" name="address" rows="3" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500"><?= esc_textarea($address); ?></textarea>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-4 md:p-5 lg:col-span-2">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Location</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php
                $defaultCountryId = isset($customer['country_id']) && (string)$customer['country_id'] !== '' ? intval($customer['country_id']) : 0;
                $defaultCityId = isset($customer['city_id']) && (string)$customer['city_id'] !== '' ? intval($customer['city_id']) : 0;
                ?>
                <div>
                    <label for="origin_country_select" class="block text-sm font-medium text-gray-700 mb-1.5">Country</label>
                    <?php
                    $country_select = KIT_Deliveries::selectAllCountries('origin_country', 'origin_country_select', $defaultCountryId, "required", 'origin', []);
                    $country_select = str_replace(
                        'class="' . KIT_Commons::selectClass() . '"',
                        'class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500"',
                        $country_select
                    );
                    echo $country_select;
                    ?>
                </div>

                <div>
                    <label for="origin_city_select" class="block text-sm font-medium text-gray-700 mb-1.5">City</label>
                    <?php
                    $city_select = KIT_Deliveries::selectAllCitiesByCountry('origin_city', 'origin_city_select', $defaultCountryId, $defaultCityId);
                    $city_select = str_replace(
                        'class="' . KIT_Commons::selectClass() . '"',
                        'class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500"',
                        $city_select
                    );
                    echo $city_select;
                    ?>
                </div>

                <input type="hidden" id="origin_country_initial" value="<?= esc_attr($defaultCountryId); ?>">
                <input type="hidden" id="origin_city_initial" value="<?= esc_attr($defaultCityId); ?>">
            </div>
        </section>
    </div>
<?php
    // Keep existing country/city behavior and initial selected values.
}

function customer_form()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';

    // Edit mode
    $is_edit = false;
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $customer = null;

    if ($id) {
        $is_edit = true;
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
    }

    // Handle form submit
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_submit'])) {
        save_customer();
    }

    ob_start();
?>
    <div class="customer-form-container">
        <!-- Trigger Button -->


        <!-- Modal -->
        <div id="customerModal" class="fixed hidden inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-xl w-full max-w-xl relative">
                <!-- Close Button -->
                <?php echo KIT_Commons::renderButton('×', 'ghost', 'sm', ['id' => 'customerModalClose', 'classes' => 'absolute top-3 right-4 text-gray-600 hover:text-black text-xl']); ?>

                <h2 class="text-xl font-bold mb-4"><?= $is_edit ? 'Edit Customer' : 'Add Customer' ?></h2>
                <div class="">
                    <form method="post" class="space-y-4" id="customerForm" action="">
                        <?php if ($is_edit): ?>
                            <input type="hidden" name="customer_id" value="<?= esc_attr($id) ?>">
                        <?php endif; ?>

                        <div class="">
                            <?php echo theForm(null); ?>
                        </div>

                        <div class="flex justify-end space-x-2">
                            <?php echo KIT_Commons::renderButton('Cancel', 'secondary', 'lg', ['type' => 'button', 'id' => 'customerModalCloseBtn']); ?>
                            <?php echo KIT_Commons::renderButton($is_edit ? 'Update' : 'Save', 'success', 'lg', ['type' => 'submit', 'name' => 'customer_submit', 'id' => 'customerSubmitBtn', 'gradient' => true]); ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('customerModal');
            var openBtn = document.getElementById('customerModalButton');
            var closeBtn = document.getElementById('customerModalClose');
            var closeBtn2 = document.getElementById('customerModalCloseBtn');
            var form = document.getElementById('customerForm');
            var submitBtn = document.getElementById('customerSubmitBtn');

            // Open modal
            if (openBtn) {
                openBtn.addEventListener('click', function() {
                    modal.classList.remove('hidden');
                });
            }

            // Close modal functions
            function closeModal() {
                modal.classList.add('hidden');
            }

            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (closeBtn2) closeBtn2.addEventListener('click', closeModal);

            // Close on outside click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });

            // Form submission
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();

                    // Show loading state
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Saving...';

                    // Submit form via AJAX
                    var formData = new FormData(form);
                    // Ensure company_name defaults to 'Individual' if empty
                    var cn = (formData.get('company_name') || '').trim();
                    if (!cn) {
                        formData.set('company_name', 'Individual');
                    }
                    formData.append('action', 'save_customer_ajax');
                    formData.append('nonce', '<?php echo wp_create_nonce("save_customer_nonce"); ?>');

                    // Debug: Log what we're sending
                    console.log('Sending form data:');
                    for (var pair of formData.entries()) {
                        console.log(pair[0] + ': ' + pair[1]);
                    }

                    fetch(ajaxurl || '/wp-admin/admin-ajax.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(function(response) {
                            console.log('Response status:', response.status);
                            console.log('Response headers:', response.headers);
                            return response.json();
                        })
                        .then(function(data) {
                            console.log('Response data:', data);
                            if (data.success) {
                                // Show success message
                                var successDiv = document.createElement('div');
                                successDiv.className = 'bg-green-100 text-green-800 p-4 rounded mb-4';
                                successDiv.textContent = data.data.message;

                                // Insert before form
                                form.parentNode.insertBefore(successDiv, form);

                                // Close modal after 2 seconds
                                setTimeout(function() {
                                    closeModal();
                                    location.reload(); // Reload page to show new customer
                                }, 2000);
                            } else {
                                throw new Error(data.data.message || 'Unknown error');
                            }
                        })
                        .catch(function(error) {
                            console.error('Error:', error);
                            submitBtn.disabled = false;
                            submitBtn.textContent = '<?= $is_edit ? 'Update' : 'Save' ?>';

                            // Show error message
                            var errorDiv = document.createElement('div');
                            errorDiv.className = 'bg-red-100 text-red-800 p-4 rounded mb-4';
                            errorDiv.textContent = error.message || 'Failed to save customer. Please try again.';
                            form.parentNode.insertBefore(errorDiv, form);
                        });
                });
            }
        });
    </script>
<?php
    return ob_get_clean();
}

function save_customer()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';

    // Generate a unique customer ID
    do {
        $cust_id = rand(1000, 9999);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT cust_id FROM $table_name WHERE cust_id = %d", $cust_id));
    } while ($exists);

    // Sanitize inputs
    $country_input = isset($_POST['country_id']) && $_POST['country_id'] !== '' ? $_POST['country_id'] : ($_POST['origin_country'] ?? '');
    $city_input = isset($_POST['city_id']) && $_POST['city_id'] !== '' ? $_POST['city_id'] : ($_POST['origin_city'] ?? '');
    $cust_data = [
        'cust_id'  => $cust_id,
        'name'     => sanitize_text_field($_POST['name']),
        'surname'  => sanitize_text_field($_POST['surname']),
        'cell'     => sanitize_text_field($_POST['cell']),
        'address'  => sanitize_text_field($_POST['address']),
        'email_address' => sanitize_text_field($_POST['email_address'] ?? ''),
        'country_id' => ($country_input !== '' ? intval($country_input) : 0),
        'city_id' => ($city_input !== '' ? intval($city_input) : 0),
    ];
    if (class_exists('KIT_Customers')) {
        $company_id = KIT_Customers::resolve_company_id_from_payload($_POST);
        if ($company_id > 0) {
            $cust_data['company_id'] = $company_id;
        }
    }

    // Insert into DB
    $inserted = $wpdb->insert($table_name, $cust_data);

    if ($inserted) {
        if (class_exists('Courier_Google_Sheets_Sync')) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE cust_id = %d", $cust_id));
            if ($row) {
                Courier_Google_Sheets_Sync::sync_customer_add($row);
            }
        }
        if (!class_exists('KIT_Toast')) {
            require_once plugin_dir_path(__FILE__) . '../components/toast.php';
        }
        KIT_Toast::ensure_toast_loads();
        echo KIT_Toast::success('Customer saved successfully.', 'Customer Saved');
        wp_redirect(admin_url('admin.php?page=customers-dashboard'));
        exit;
    } else {
        if (!class_exists('KIT_Toast')) {
            require_once plugin_dir_path(__FILE__) . '../components/toast.php';
        }
        KIT_Toast::ensure_toast_loads();
        $error_msg = $wpdb->last_error ? 'Database Error: ' . esc_html($wpdb->last_error) : 'Failed to save customer.';
        echo KIT_Toast::error($error_msg, 'Error');
    }
}

/**
 * Default render options for staff (wp-admin customer detail).
 *
 * @return array<string,mixed>
 */
function kit_customer_detail_view_default_options_staff()
{
    return array(
        'context' => 'staff',
        'back_url' => admin_url('admin.php?page=08600-customers'),
        'allow_bulk_actions' => true,
        'allow_row_delete' => true,
        'allow_edit_customer' => true,
        'allow_primary_waybills_link' => true,
        'outer_wrap_class' => 'wrap',
        'allow_customer_summary_pdf' => true,
        'allow_waybill_invoice_pdf' => true,
    );
}

/**
 * Default render options for frontend customer portal (single linked cust_id).
 *
 * @return array<string,mixed>
 */
function kit_customer_detail_view_default_options_portal()
{
    return array(
        'context' => 'portal',
        'back_url' => '',
        'allow_bulk_actions' => false,
        'allow_row_delete' => false,
        'allow_edit_customer' => true,
        'allow_primary_waybills_link' => false,
        'outer_wrap_class' => 'kit-customer-portal-detail',
        'allow_customer_summary_pdf' => false,
        'allow_waybill_invoice_pdf' => false,
    );
}

/**
 * Render customer detail + waybill table (staff or portal).
 *
 * @param int   $customer_id kit_customers.cust_id
 * @param array $opts        Merged with defaults from context.
 */
function kit_customer_detail_view_render($customer_id, array $opts = array())
{
    $customer_id = (int) $customer_id;
    $defaults = (($opts['context'] ?? '') === 'portal')
        ? kit_customer_detail_view_default_options_portal()
        : kit_customer_detail_view_default_options_staff();
    $opts = array_merge($defaults, $opts);

    if ($opts['context'] === 'portal') {
        if (!is_user_logged_in() || !KIT_User_Roles::is_portal_customer() || !KIT_User_Roles::can_access_customer_portal()) {
            echo '<p>' . esc_html__('Access denied.', '08600-services-quotations') . '</p>';
            return;
        }
        $linked = KIT_User_Roles::get_portal_customer_id();
        if ($linked !== $customer_id) {
            echo '<p>' . esc_html__('Access denied.', '08600-services-quotations') . '</p>';
            return;
        }
    }

    if (isset($_GET['updated']) && $_GET['updated'] == '1') {
        if (class_exists('KIT_Toast')) {
            KIT_Toast::ensure_toast_loads();
            echo KIT_Toast::success('Customer updated successfully!', 'Customer Update');
        }
    }

    if (isset($_GET['error']) && $_GET['error'] == '1') {
        if (class_exists('KIT_Toast')) {
            KIT_Toast::ensure_toast_loads();
            echo KIT_Toast::error('Failed to update customer. Please try again.', 'Customer Update');
        }
    }

    if (isset($_GET['bulk_deleted'])) {
        if (class_exists('KIT_Toast')) {
            KIT_Toast::ensure_toast_loads();
            echo KIT_Toast::success('Waybill(s) deleted successfully!', 'Bulk Delete');
        }
    }

    $customer = get_customer_details($customer_id);

    if (!$customer) {
        if (class_exists('KIT_Toast')) {
            KIT_Toast::ensure_toast_loads();
            echo KIT_Toast::error('Customer not found.', 'Customer Details');
        }
        return;
    }

    $waybills = array();
    if (class_exists('KIT_Waybills')) {
        $all_waybills = KIT_Waybills::getAllWaybills();
        foreach ($all_waybills as $wb) {
            if ((int) ($wb->customer_id ?? 0) === (int) $customer_id) {
                $waybills[] = $wb;
            }
        }
    }

    $outer_class = $opts['outer_wrap_class'] ? esc_attr($opts['outer_wrap_class']) : 'wrap';
    ?>
    <div class="<?php echo $outer_class; ?> kit-customer-detail-view">
        <div class="<?php echo KIT_Commons::containerClasses(); ?> min-w-0 max-w-full">
            <?php
            if (!empty($opts['back_url'])) {
                echo KIT_Commons::showingHeader(array(
                    'title' => 'Customer Details',
                    'desc'  => KIT_Commons::kitButton(array(
                        'color' => 'green',
                        'href'  => $opts['back_url'],
                    ), 'Back'),
                ));
            }
            ?>

            <div class="kit-customer-detail-grid grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6">
                <div class="kit-customer-detail-info min-w-0 bg-white border border-gray-200 shadow-sm rounded-xl p-4 md:p-6 mb-2 lg:mb-6">
                    <?php
                    $customer_full_name = trim(($customer['customer_name'] ?? '') . ' ' . ($customer['customer_surname'] ?? ''));
                    $customer_full_name = $customer_full_name !== '' ? $customer_full_name : 'Not provided';
                    $customer_company_name = !empty($customer['company_name']) ? $customer['company_name'] : 'Individual';
                    ?>
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-xl md:text-2xl font-semibold text-gray-900 tracking-tight">Customer Information</h2>
                            <p class="text-sm text-gray-500 mt-1">Contact and location details</p>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-blue-50 text-blue-700 px-3 py-1 text-xs font-semibold shrink-0">
                            <?php echo esc_html($customer_company_name); ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-5">
                        <section class="rounded-lg border border-gray-100 bg-gray-50 p-4 min-w-0">
                            <h3 class="text-base font-semibold text-gray-800 mb-4">Personal Details</h3>
                            <dl class="space-y-4">
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Full Name</dt>
                                    <dd class="text-base text-gray-900 break-words"><?php echo esc_html($customer_full_name); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Cell Phone</dt>
                                    <dd class="text-base text-gray-900 break-words"><?php echo esc_html($customer['cell'] ?? 'Not provided'); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Email</dt>
                                    <dd class="text-base text-gray-900 break-words"><?php echo esc_html($customer['email_address'] ?: 'Not provided'); ?></dd>
                                </div>
                            </dl>
                        </section>

                        <section class="rounded-lg border border-gray-100 bg-gray-50 p-4 min-w-0">
                            <h3 class="text-base font-semibold text-gray-800 mb-4">Location</h3>
                            <dl class="space-y-4">
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Country</dt>
                                    <dd class="text-base text-gray-900 break-words"><?php echo esc_html($customer['country_name'] ?: 'Not specified'); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">City</dt>
                                    <dd class="text-base text-gray-900 break-words"><?php echo esc_html($customer['city_name'] ?: 'Not specified'); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Address</dt>
                                    <dd class="text-base text-gray-900 break-words"><?php echo esc_html($customer['address'] ?: 'Not provided'); ?></dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <div class="mt-6 pt-4 border-t border-gray-200 flex flex-col sm:flex-row sm:flex-wrap sm:justify-end gap-2">
                        <?php
                        if (!empty($opts['allow_customer_summary_pdf'])) {
                            global $wpdb;
                            $waybills_table = $wpdb->prefix . 'kit_waybills';
                            $waybill_nos = $wpdb->get_col($wpdb->prepare(
                                "SELECT waybill_no FROM $waybills_table WHERE customer_id = %d ORDER BY waybill_no ASC",
                                $customer_id
                            ));

                            $pdf_url = '';
                            if (!empty($waybill_nos)) {
                                $plugin_url = dirname(dirname(plugin_dir_url(__FILE__)));
                                $pdf_url = add_query_arg(array(
                                    'selected_ids' => implode(',', $waybill_nos),
                                    'customer_id' => $customer_id,
                                ), $plugin_url . '/pdf-customer-bulk.php');
                            }

                            $pdf_icon = '<svg class="inline-block ml-1 -mt-0.5 w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 20 20"><path d="M12 16v-4m0 4l-2-2m2 2l2-2M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V6.828a2 2 0 00-.586-1.414l-3.828-3.828A2 2 0 0012.172 2H6z"></path></svg>';

                            if (!empty($pdf_url)) {
                                echo KIT_Commons::renderButton('Download PDF', 'primary', 'md', array(
                                    'href' => $pdf_url,
                                    'gradient' => true,
                                    'icon' => $pdf_icon,
                                    'target' => '_blank',
                                    'rel' => 'noopener',
                                    'classes' => 'w-full sm:w-auto justify-center',
                                ));
                            } else {
                                echo KIT_Commons::renderButton('Download PDF', 'primary', 'md', array(
                                    'href' => '#',
                                    'gradient' => true,
                                    'icon' => $pdf_icon,
                                    'disabled' => true,
                                    'title' => 'No waybills available for this customer',
                                    'classes' => 'w-full sm:w-auto justify-center',
                                ));
                            }
                        }
                        if (!empty($opts['allow_edit_customer'])) {
                            if (($opts['context'] ?? '') === 'portal' && function_exists('kit_customer_dashboard_url')) {
                                $edit_href = add_query_arg('edit_profile', '1', kit_customer_dashboard_url());
                                $edit_label = __('Edit your details', '08600-services-quotations');
                            } else {
                                $edit_href = '?page=08600-customers&edit_customer=' . $customer_id;
                                $edit_label = __('Edit Customer', '08600-services-quotations');
                            }
                            echo KIT_Commons::renderButton($edit_label, 'ghost-primary', 'md', array(
                                'href' => $edit_href,
                                'classes' => 'w-full sm:w-auto justify-center',
                            ));
                        }
                        ?>
                    </div>
                </div>
                <div class="kit-customer-detail-waybills min-w-0 max-w-full">
                    <?php
                    $summary_url = plugins_url('pdf-summary.php', dirname(dirname(__FILE__)));
                    $actions = array();
                    if (!empty($opts['allow_waybill_invoice_pdf'])) {
                        $actions[] = array(
                            'label' => 'Download',
                            'title' => 'Download PDF invoice',
                            'target' => '_blank',
                            'href' => $summary_url . '?waybill_no={waybill_no}',
                            'class' => 'text-xs font-medium text-green-600 hover:text-green-800 hover:underline',
                            'condition' => function ($row) {
                                $product_invoice_number = is_object($row)
                                    ? (isset($row->product_invoice_number) ? trim((string) $row->product_invoice_number) : '')
                                    : (isset($row['product_invoice_number']) ? trim((string) $row['product_invoice_number']) : '');
                                return !empty($product_invoice_number);
                            },
                        );
                    }
                    if (!empty($opts['allow_row_delete'])) {
                        $actions[] = array(
                            'label' => 'Delete',
                            'title' => 'Delete waybill',
                            'href' => '?page=08600-waybill-manage&delete_waybill={waybill_no}',
                            'class' => 'text-xs font-medium text-red-600 hover:text-red-800 hover:underline',
                            'onclick' => 'return confirm("Are you sure you want to delete this waybill?")',
                        );
                    }

                    $columns = KIT_Commons::getColumns(array(
                        'waybill_no',
                        'customer_city' => array(
                            'label' => 'City',
                            'header_class' => 'text-left whitespace-nowrap kit-col-hide-sm',
                            'cell_class' => 'text-left text-sm whitespace-nowrap kit-col-hide-sm',
                            'callback' => function ($value, $row, $rowIndex) {
                                return esc_html($value ?: '—');
                            },
                        ),
                        'truck_details' => array(
                            'label' => 'Truck Details',
                            'header_class' => 'text-left whitespace-nowrap kit-col-hide-md',
                            'cell_class' => 'text-left text-sm kit-col-hide-md',
                            'callback' => function ($value, $row, $rowIndex) {
                                $row = is_object($row) ? (array) $row : $row;
                                $truck_number = $row['truck_number'] ?? '';
                                $delivery_reference = $row['delivery_reference'] ?? '';
                                $dispatch_date = $row['dispatch_date'] ?? '';

                                if ($truck_number === '' && $delivery_reference === '' && $dispatch_date === '') {
                                    return '<span class="text-gray-400">No truck info</span>';
                                }

                                $html = '<div class="space-y-0.5">';

                                if ($truck_number !== '') {
                                    $truck_display = mb_strlen($truck_number) > 10 ? mb_substr($truck_number, 0, 8) . '..' : $truck_number;
                                    $html .= '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 font-medium" title="Truck: ' . esc_attr($truck_number) . '">';
                                    $html .= '<svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M8 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM15 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/><path d="M3 4a1 1 0 00-1 1v10a1 1 0 001 1h1.05a2.5 2.5 0 014.9 0H10a1 1 0 001-1V5a1 1 0 00-1-1H3zM14 7a1 1 0 00-1 1v6.05A2.5 2.5 0 0115.95 16H17a1 1 0 001-1V8a1 1 0 00-1-1h-3z"/></svg>';
                                    $html .= esc_html($truck_display) . '</span>';
                                }

                                if ($delivery_reference !== '') {
                                    $ref_display = mb_strlen($delivery_reference) > 12 ? mb_substr($delivery_reference, 0, 10) . '..' : $delivery_reference;
                                    $html .= ' <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-green-50 text-green-700 font-medium" title="Ref: ' . esc_attr($delivery_reference) . '">';
                                    $html .= '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>';
                                    $html .= esc_html($ref_display) . '</span>';
                                }

                                if ($dispatch_date !== '') {
                                    $formatted = function_exists('date_i18n') ? date_i18n('M j, Y', strtotime($dispatch_date)) : date('M j, Y', strtotime($dispatch_date));
                                    $html .= '<div class="text-[10px] text-gray-500 truncate">' . esc_html($formatted) . '</div>';
                                }

                                $html .= '</div>';
                                return $html;
                            },
                        ),
                        'created_at' => array(
                            'label' => 'Created',
                            'header_class' => 'text-left whitespace-nowrap kit-col-hide-sm',
                            'cell_class' => 'text-left text-xs text-gray-600 whitespace-nowrap kit-col-hide-sm',
                            'callback' => function ($value, $row, $rowIndex) {
                                if (empty($value)) {
                                    return '—';
                                }
                                $timestamp = strtotime($value);
                                if ($timestamp) {
                                    return esc_html(function_exists('date_i18n') ? date_i18n('M j, Y', $timestamp) : date('M j, Y', $timestamp));
                                }
                                return esc_html($value);
                            },
                        ),
                    ));

                    $table_options = array(
                        'title' => 'Waybills (' . count($waybills) . ')',
                        'actions' => $actions,
                        'searchable' => true,
                        'sortable' => true,
                        'bulk_management' => !empty($opts['allow_bulk_actions']),
                        'bulk_actions_list' => !empty($opts['allow_bulk_actions']) ? array('export', 'delete') : array(),
                        'empty_message' => 'No waybills found for this customer',
                        'preserve_order' => false,
                    );
                    if (!empty($opts['allow_primary_waybills_link'])) {
                        $table_options['primary_action'] = array(
                            'label' => 'View All Waybills',
                            'href' => '?page=08600-waybill-manage&customer_id=' . $customer_id,
                            'class' => 'w-full sm:w-auto text-center px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-md hover:from-blue-700 hover:to-indigo-700 transition whitespace-nowrap',
                        );
                    }

                    echo KIT_Unified_Table::infinite($waybills, $columns, $table_options);
                    ?>
                </div>
            </div>
        </div>
    </div>
    <?php if (!empty($opts['allow_bulk_actions'])) : ?>
    <script>
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                <?php if (!empty($waybills)) : ?>
                    const customerId = <?php echo intval($customer_id); ?>;
                    const pluginUrl = '<?php echo esc_js(dirname(dirname(plugin_dir_url(__FILE__)))); ?>';

                    document.querySelectorAll('[id^="kit-infinite-table-"]').forEach(function(table) {
                        const container = document.querySelector('[id^="kit-infinite-wrap-"]');
                        if (!container) return;

                        const exportBtn = container.querySelector('[data-bulk-action="export"]');
                        if (exportBtn) {
                            exportBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();

                                const checkboxes = table.querySelectorAll('.bulk-row-checkbox:checked');
                                const waybillNos = Array.from(checkboxes).map(cb => cb.value).filter(v => v);

                                if (waybillNos.length === 0) {
                                    alert('Please select at least one waybill to generate invoice.');
                                    return;
                                }

                                const pdfUrl = pluginUrl + '/pdf-customer-bulk.php?selected_ids=' + encodeURIComponent(waybillNos.join(',')) + '&customer_id=' + customerId;
                                window.open(pdfUrl, '_blank');
                            });
                        }

                        const deleteBtn = container.querySelector('[data-bulk-action="delete"]');
                        if (deleteBtn) {
                            deleteBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();

                                const checkboxes = table.querySelectorAll('.bulk-row-checkbox:checked');
                                const waybillNos = Array.from(checkboxes).map(cb => cb.value).filter(v => v);

                                if (waybillNos.length === 0) {
                                    alert('Please select at least one waybill.');
                                    return;
                                }

                                if (!confirm('Are you sure you want to delete ' + waybillNos.length + ' selected waybill(s)? This action cannot be undone.')) {
                                    return;
                                }

                                const form = document.createElement('form');
                                form.method = 'POST';
                                form.action = window.location.href;

                                const actionInput = document.createElement('input');
                                actionInput.type = 'hidden';
                                actionInput.name = 'bulk_action';
                                actionInput.value = 'delete';
                                form.appendChild(actionInput);

                                const idsInput = document.createElement('input');
                                idsInput.type = 'hidden';
                                idsInput.name = 'bulk_ids';
                                idsInput.value = waybillNos.join(',');
                                form.appendChild(idsInput);

                                document.body.appendChild(form);
                                form.submit();
                            });
                        }
                    });
                <?php endif; ?>
            });
        })();
    </script>
    <?php endif; ?>
    <?php
}

function customer_detail_view($customer_id)
{
    global $wpdb;
    $customer_id = intval($customer_id);

    if (isset($_POST['bulk_action']) && isset($_POST['bulk_ids']) && !empty($_POST['bulk_ids'])) {
        if (!current_user_can('kit_view_waybills')) {
            wp_die('Unauthorized');
        }

        $bulk_action = sanitize_text_field($_POST['bulk_action']);
        $bulk_ids = sanitize_text_field($_POST['bulk_ids']);
        $waybill_nos = array_map('trim', explode(',', $bulk_ids));
        $waybill_nos = array_filter($waybill_nos);

        if (!empty($waybill_nos)) {
            if ($bulk_action === 'delete') {
                if (isset($_POST['bulk_nonce'])) {
                    if (!wp_verify_nonce($_POST['bulk_nonce'], 'bulk_waybill_nonce')) {
                        wp_die('Security check failed');
                    }
                }

                if (class_exists('KIT_Waybills')) {
                    $deleted_count = 0;
                    foreach ($waybill_nos as $waybill_no) {
                        if (KIT_Waybills::delete_waybill($waybill_no)) {
                            $deleted_count++;
                        }
                    }

                    if ($deleted_count > 0) {
                        if (class_exists('KIT_Toast')) {
                            KIT_Toast::ensure_toast_loads();
                            echo KIT_Toast::success("Successfully deleted {$deleted_count} waybill(s).", 'Bulk Delete');
                        }
                        wp_safe_redirect(admin_url('admin.php?page=08600-customers&view_customer=' . $customer_id . '&bulk_deleted=' . $deleted_count));
                        exit;
                    }
                }
            } elseif ($bulk_action === 'export') {
                $plugin_url = dirname(dirname(plugin_dir_url(__FILE__)));
                $pdf_url = add_query_arg(array(
                    'selected_ids' => implode(',', $waybill_nos),
                    'customer_id' => $customer_id,
                ), $plugin_url . '/pdf-customer-bulk.php');

                wp_redirect($pdf_url);
                exit;
            }
        }
    }

    kit_customer_detail_view_render($customer_id, kit_customer_detail_view_default_options_staff());
}

function delete_customer($id, $redirect = false)
{
    global $wpdb;
    $customer_id = intval($id);
    $table_name = $wpdb->prefix . 'kit_customers';
    $db_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE cust_id = %d", $customer_id));

    if (class_exists('Courier_Google_Sheets_Sync')) {
        Courier_Google_Sheets_Sync::sync_customer_delete($customer_id, $db_id);
    }
    // First, get all waybill IDs for this customer
    $waybills_table = $wpdb->prefix . 'kit_waybills';
    $waybill_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $waybills_table WHERE customer_id = %d",
        $customer_id
    ));

    if (!empty($waybill_ids)) {
        // Delete waybill items for all waybills of this customer
        $waybill_items_table = $wpdb->prefix . 'kit_waybill_items';
        foreach ($waybill_ids as $waybill_id) {
            $wpdb->delete($waybill_items_table, ['waybillno' => $waybill_id]);
        }

        // Delete all waybills for this customer
        $wpdb->delete($waybills_table, ['customer_id' => $customer_id]);
    }

    // Also remove warehouse tracking linked to this customer (FK lacks ON DELETE CASCADE)
    $waybills_table = $wpdb->prefix . 'kit_waybills';
    // Delete warehouse waybills for this customer
    $wpdb->delete($waybills_table, [
        'customer_id' => $customer_id,
        'status' => ['pending', 'assigned', 'shipped', 'delivered']
    ]);

    // Now delete the customer
    $customers_table = $wpdb->prefix . 'kit_customers';
    $deleted = $wpdb->delete($customers_table, ['cust_id' => $customer_id]);

    if ($deleted && $redirect) {
        $deleted_count = count($waybill_ids);
        $message = "Customer deleted successfully!";
        if ($deleted_count > 0) {
            $message .= " Also deleted $deleted_count waybill(s) and their associated items.";
        }
        $target_url = admin_url('admin.php?page=08600-customers&deleted=1&waybills_deleted=' . $deleted_count . '&tab=manage-customers');
        if (!headers_sent()) {
            wp_safe_redirect($target_url);
            exit;
        } else {
            echo '<script>window.location.replace(' . json_encode($target_url) . ');</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($target_url) . '"></noscript>';
            exit;
        }
    }

    // Return true/false for bulk operations instead of echoing HTML
    return (bool) $deleted;
}

function tholaMaCustomer()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';
    $waybills_table = $wpdb->prefix . 'kit_waybills';
    return $wpdb->get_results("
    SELECT 
        c.id, 
        c.cust_id, 
        c.name as customer_name, 
        c.surname as customer_surname, 
        c.email_address, 
        c.cell, 
        c.address, 
        c.country_id, 
        c.city_id,
        country.country_name,
        city.city_name,
        c.company_id,
        co.company_name,
        COUNT(w.id) as total_waybills
    FROM $table_name c
    LEFT JOIN {$wpdb->prefix}kit_operating_countries country ON c.country_id = country.id
    LEFT JOIN {$wpdb->prefix}kit_operating_cities city ON c.city_id = city.id
    LEFT JOIN {$wpdb->prefix}kit_company_customers co ON c.company_id = co.company_id
    LEFT JOIN $waybills_table w ON w.customer_id = c.cust_id
        OR (w.customer_id = c.id AND c.id > 0 AND w.customer_id <> c.cust_id)
    GROUP BY c.id, c.cust_id, c.name, c.surname, c.email_address, c.cell, c.address, c.country_id, c.city_id, country.country_name, city.city_name, c.company_id, co.company_name
");
}

function gamaCustomer()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';
    $results = $wpdb->get_results("SELECT * FROM $table_name");
    $customers = array_map(fn($row) => $row->name, $results);
    return $customers;
}

function get_customer_details($customer_id)
{
    global $wpdb;

    // Sanitize the input
    $customer_id = absint($customer_id);
    if (!$customer_id) {
        return false;
    }

    $table_name = $wpdb->prefix . 'kit_customers';

    // Prepare and execute a parameterized query
    $query = $wpdb->prepare(
        "SELECT 
        c.id, 
        c.cust_id, 
        c.name as customer_name, 
        c.surname as customer_surname, 
        c.email_address, 
        c.cell, 
        c.address, 
        c.country_id, 
        c.city_id,
        country.country_name,
        city.city_name,
        c.company_id,
        co.company_name
        FROM $table_name c
        LEFT JOIN {$wpdb->prefix}kit_operating_countries country ON c.country_id = country.id
        LEFT JOIN {$wpdb->prefix}kit_operating_cities city ON c.city_id = city.id
        LEFT JOIN {$wpdb->prefix}kit_company_customers co ON c.company_id = co.company_id
        WHERE c.cust_id = %d",
        $customer_id
    );

    $customer = $wpdb->get_row($query, ARRAY_A);

    // Convert null values to empty strings to prevent deprecation warnings
    if ($customer) {
        $customer = array_map(function ($value) {
            return $value === null ? '' : $value;
        }, $customer);
    }
    // Return false if no customer found
    if (empty($customer)) {
        return false;
    }
    return $customer;
}

function edit_customer_form($customer_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE cust_id = %d", $customer_id), ARRAY_A);

    require_once dirname(__FILE__) . '/_customersForm.php';

    // Enqueue CSS for the edit customer page
    wp_enqueue_style('autsincss', plugin_dir_url(__FILE__) . '../assets/css/austin.css', array(), '1.0');
    wp_enqueue_style('kit-tailwindcss', plugin_dir_url(__FILE__) . '../assets/css/frontend.css', array(), '1.0');

    // Add CSS class wrapper to admin body for scoping
    add_filter('admin_body_class', function ($classes) {
        return $classes . ' courier-finance-plugin';
    });

    // Enqueue JavaScript for country/city selection
    wp_enqueue_script('kitscript', plugin_dir_url(__FILE__) . '../js/kitscript.js', ['jquery'], null, true);

    // Convert null values to empty strings to prevent deprecation warnings
    if ($customer) {
        $customer = array_map(function ($value) {
            return $value === null ? '' : $value;
        }, $customer);
    }

    if (!$customer) {
        wp_die('Customer not found');
    }

?>
    <div class="wrap customers-page kit-edit-customer-page">
        <div class="<?php echo KIT_Commons::containerClasses(); ?>">
            <?php
            $header_actions = KIT_Commons::renderButton(
                __('Back', '08600'),
                'secondary',
                'md',
                [
                    'href' => admin_url('admin.php?page=08600-customers&view_customer=' . $customer_id),
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />',
                    'iconPosition' => 'left',
                    'noLoading' => true,
                ]
            );
            echo KIT_Commons::showingHeader([
                'title'   => __('Edit Individual Customer', '08600'),
                'desc'    => __('Update this person’s profile, contact, and location. Check “Convert this client into a company” only if you want to move them into the Companies list.', '08600'),
                'icon'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />',
                'content' => $header_actions,
            ]);
            ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mt-2 mb-6" style="max-width: 800px;">
                <div class="px-4 py-5 md:px-6 md:py-6">
                    <?php echo kit_render_customers_form('edit', $customer); ?>
                </div>
            </div>
        </div>
    </div>
<?php
}

function customer_waybills($customer_id)
{
        global $wpdb;

        // Sanitize and validate the customer ID
        $customer_id = intval($customer_id);  // Use intval to ensure it's a valid integer

        // Query to get all waybills for the given customer ID
        $table_name = $wpdb->prefix . 'kit_waybills';
        // TABLE NAMES
        $waybills_table   = $wpdb->prefix . 'kit_waybills';
        $customers_table  = $wpdb->prefix . 'kit_customers';
        $deliveries_table = $wpdb->prefix . 'kit_deliveries';
        $directions_table = $wpdb->prefix . 'kit_shipping_directions';
        $countries_table  = $wpdb->prefix . 'kit_operating_countries';
        $items_table      = $wpdb->prefix . 'kit_waybill_items';

        // PHASE 1: Waybill + related joins
        $waybill_sql = $wpdb->prepare(" SELECT 
        b.id AS waybill_id,
        c.id AS customer_id,
        d.id AS delivery_id,
        dir.id AS direction_id,
        b.direction_id,
        b.customer_id,
        b.approval,
        b.approval_userid,
        b.waybill_no,
        b.product_invoice_number,
        b.product_invoice_amount,
        b.item_length,
        b.item_width,
        b.item_height,
        b.total_mass_kg,
        b.total_volume,
        b.mass_charge,
        b.volume_charge,
        b.charge_basis,
        b.warehouse,
        b.miscellaneous,
        b.include_sad500,
        b.include_sadc,
        c.name AS customer_name,
        c.surname AS customer_surname,
        c.cell AS customer_cell,
        d.delivery_reference,
        d.direction_id,
        d.dispatch_date,
        d.truck_number,
        d.status AS delivery_status,
        dir.description AS route_description,
        origin.country_name AS origin_country,
        dest.country_name AS destination_country
        FROM $waybills_table b
        LEFT JOIN $customers_table c ON b.customer_id = c.cust_id
        LEFT JOIN $deliveries_table d ON b.direction_id = d.id
        LEFT JOIN $directions_table dir ON b.direction_id = dir.id
        LEFT JOIN $countries_table origin ON dir.origin_country_id = origin.id
        LEFT JOIN $countries_table dest ON dir.destination_country_id = dest.id
        WHERE b.customer_id = %d", $customer_id);


        $waybill = $wpdb->get_results($waybill_sql);

        return $waybill;
    }



    function view_customer_waybills()
    {
        if (isset($_GET['cust_id'])) {

            $customer_id = intval($_GET['cust_id']);
            $customer = get_customer_details($customer_id); // You'll need to implement this
            $waybills = customer_waybills($customer_id);

            echo KIT_Commons::showingHeader([
                'title' => 'Customers Waybills',
                'desc' => "342234",
            ]);

            $customers   = KIT_Customers::tholaMaCustomer();
            $form_action = admin_url('admin-post.php?action=add_waybill_action');

            $modal_path = realpath(plugin_dir_path(__FILE__) . '../components/modal.php');

            if (file_exists($modal_path)) {
                require_once $modal_path;
            } else {
                error_log("Modal.php not found at: " . $modal_path);
                // Optional: Show a safe error or fallback content
            }

            if (isset($_GET['selected_ids'])) {

                $selected_ids_string = $_GET['selected_ids']; // "4000,4003,4004"
                $selected_ids_array = explode(',', $selected_ids_string);

                if (!empty($selected_ids_array)) {
                    // Generate PDF and return it as download
                    include plugin_dir_path(__FILE__) . 'pdf-bulkinvoicing.php';
                    exit;
                }
            }
        ?>
            <div class="<?= KIT_Commons::container() ?> flex gap-4 min-h-screen bg-gray-100">
                <!-- Left Sidebar - Customer Details -->
                <div class="w-1/3">
                    <div class="bg-white shadow-md rounded-lg p-6 mb-6">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Custome23r Details</h2>

                        <?php if ($customer) :

                            /* We will add a edit button here to edit the customer details.
                        if $edit_customer is set, we will show the edit form.
                        if $edit_customer is not set, we will show the customer details. */
                            if (!isset($_GET['edit_customer'])) {

                                /* We must display theForm but not as a form, do not use theForm function, but cusrtomer details */
                                echo '<div class="divide-y divide-gray-200">';
                                // Only add Company if it's not empty
                                $fields = array_filter([
                                    'Company'  => $customer['company_name'] ?? '',
                                    'Name'     => $customer['customer_name'] ?? '',
                                    'Surname'  => $customer['customer_surname'] ?? '',
                                    'Cell'     => $customer['cell'] ?? '',
                                    'Address'  => $customer['address'] ?? '',
                                    'Email'  => $customer['email_address'] ?? '',
                                    'Country'  => $customer['country_name'] ?? '',
                                    'City'     => $customer['city_name'] ?? '',
                                ], function ($value) {
                                    return $value !== null && $value !== '';
                                });

                                foreach ($fields as $key => $value) {
                                    echo '<div class="flex items-center py-2">';
                                    echo '<span class="w-[70px] font-semibold text-gray-700">' . esc_html($key) . ':</span>';
                                    echo '<span class="flex-1 text-gray-900">' . esc_html($value) . '</span>';
                                    echo '</div>';
                                }
                                echo '</div>';
                                //We will add a delete button here to delete the customer.
                                echo KIT_Commons::kitButton([
                                    'color' => 'red',
                                    'href' => admin_url('admin.php?page=customers-dashboard&delete_customer=' . $customer['cust_id'])
                                ], 'Delete Customer');

                                //We will add a edit button here to edit the customer details.
                                echo KIT_Commons::kitButton([
                                    'color' => 'blue',
                                    'href' => admin_url('admin.php?page=08600-customers&edit_customer=' . $customer['cust_id'])
                                ], 'Edit Customer');
                            } else {
                                /* We will display the edit form here */
                                $formHtml = '';
                                $formHtml .= '<form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '" class="space-y-4">';
                                ob_start();
                                wp_nonce_field('update_customer_nonce', 'cust_update_nonce');
                                $formHtml .= ob_get_clean();
                                $formHtml .= '<input type="hidden" name="action" value="update_customer" />';
                                ob_start();
                                theForm($customer);
                                $formHtml .= ob_get_clean();
                                $formHtml .= '<div class="flex justify-end">';
                                $formHtml .= KIT_Commons::kitButton([
                                    'color' => 'blue',
                                    'type' => 'submit',
                                    'name' => 'customer_update_btn',
                                ], 'Update Customer');
                                $formHtml .= '</div>';
                                $formHtml .= '</form>';
                                echo $formHtml;
                            }

                        ?>

                        <?php else : ?>
                            <p class="text-red-500">Customer not found</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Content - Waybills Table -->
                <div class="w-2/3">
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold text-gray-800">Waybill History</h2>
                            <?php
                            echo KIT_Modal::render(
                                'create-waybill-modal',
                                'Create New Waybill',
                                kit_render_waybill_multiform([
                                    'form_action'          => $form_action,
                                    'waybill_id'           => '',
                                    'is_edit_mode'         => '0',
                                    'waybill'              => '{}',
                                    'customer_id'          => $customer_id,
                                    'is_existing_customer' => '',
                                    'customer'             => $customers
                                ]),
                                '6xl'
                            );
                            ?>

                        </div>

                        <?php if (!empty($waybills)) :

                            $options = [
                                'itemsPerPage' => 20,
                                'currentPage' => $_GET['paged'] ?? 1,
                                'tableClass' => 'min-w-full text-left text-sm text-gray-700',
                                'emptyMessage' => 'No customers records found',
                                'id' => 'customerTable',
                                'role' => 'waybills'
                            ];

                            $columns = [
                                'waybill_no'     => ['label' => 'Waybill #', 'align' => 'text-left'],
                                'customer_name'  => ['label' => 'Name', 'align' => 'text-left'],
                                'approval'       => ['label' => 'Approval', 'align' => 'text-left'],
                                'total'          => ['label' => 'Total', 'align' => 'text-right'],
                            ];
                            $cell_callback = function ($key, $row) {
                                if ($key === 'waybill_no') {
                                    // Return a link to the waybill view page
                                    return '<a target="_blank" href="?page=08600-Waybill-view&waybill_id=' . $row->waybill_id . '&waybill_atts=view_waybill" class="text-blue-600 hover:underline">#' . $row->waybill_no . '</a>';
                                }
                                if ($key === 'total') {
                                    //total is the sum of the product_invoice_amount and the miscellaneous
                                    if (KIT_User_Roles::can_see_prices()) {
                                        return KIT_Commons::currency() . ' ' . ((int)$row->product_invoice_amount + ((int)$row->miscellaneous ?? 0));
                                    } else {
                                        return '***';
                                    }
                                }
                                if ($key === 'approval') {
                                    return $row->approval;
                                }
                                return htmlspecialchars(($row->$key ?? '') ?: '');
                            };

                            echo KIT_Unified_Table::infinite($waybills, $columns, [
                                'title' => 'Customer Waybills',
                            ]);
                        ?>
                        <?php else : ?>
                            <div class="text-center py-12">
                                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                                    </path>
                                </svg>
                                <h3 class="mt-2 text-sm font-medium text-gray-900">No waybills found</h3>
                                <p class="mt-1 text-sm text-gray-500">This customer doesn't have any waybills yet.</p>
                                <div class="mt-6">
                                    <?php
                                    echo KIT_Commons::kitButton([
                                        'color' => 'blue',
                                        'modal' => 'create-waybill-modal',
                                        'icon' => 'plus',
                                    ], 'Create New Waybill'); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

    <?php
        } else {
            return '<div class="p-6 text-red-500">No customer selected.</div>';
        }
    }

    // Fallback for sanitize_text_field if not in WordPress
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str)
        {
            return is_string($str) ? trim(strip_tags($str)) : $str;
        }
    }

    // Fallback for is_wp_error if not in WordPress
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return false;
        }
    }
