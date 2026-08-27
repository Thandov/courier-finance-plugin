<?php
/**
 * Company customers (businesses) — separate from individual kit_customers.
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Company_Customers
{
    /**
     * First id handed out to a company this system invents rather than reads
     * from the sheet.
     *
     * The sheet numbers people (kit_customers.cust_id) and companies
     * (kit_company_customers.company_id) from the same 4-digit sequence, and
     * both tabs currently occupy 8600-8999. Allocating an invented company as
     * MAX(company_id) + 1 therefore handed it a number the sheet also uses for
     * a customer, and every "is this customer_id really a company?" lookup —
     * settings.php's party resolution and backfill_waybill_company_ids()'s
     * w.customer_id = co.company_id join — then matched an unrelated company.
     * Live symptom: waybill 5769 (Epcm Tanzania) billed to Rhino Lodge, and the
     * wrong company changed on every re-run as the invented ids shifted.
     *
     * Keeping invented ids in a range the sheet can never reach makes those
     * lookups sound by construction: an id below the base came from the sheet,
     * an id at or above it did not. mediumint unsigned tops out at 16,777,215,
     * so this leaves ~15.7M ids for locally created companies.
     */
    public const LOCAL_ID_BASE = 1000000;

    private static ?string $last_validation_error = null;

    /** True when this company id was invented here rather than read from the sheet. */
    public static function is_local_id(int $company_id): bool
    {
        return $company_id >= self::LOCAL_ID_BASE;
    }

    public static function init(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('admin_post_update_company', [self::class, 'handle_update_company']);
        add_action('admin_post_kit_update_company_customer', [self::class, 'handle_update_company_customer']);
        add_action('admin_post_kit_create_company_customer', [self::class, 'handle_create_company_customer']);
        add_action('admin_post_kit_detach_company_customer', [self::class, 'handle_detach_company_customer']);
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'kit_company_customers';
    }

    public static function set_validation_error(?string $message): void
    {
        self::$last_validation_error = $message;
    }

    public static function get_validation_error(): ?string
    {
        return self::$last_validation_error;
    }

    public static function is_placeholder_company(string $company_name): bool
    {
        $v = strtolower(trim($company_name));
        if ($v === '' || in_array($v, [
            '0', 'null', 'n/a', 'na', 'none', '-', '--',
            'individual', '1ndividual', 'private',
        ], true)) {
            return true;
        }
        return function_exists('kit_seed_is_sheet_error_value') && kit_seed_is_sheet_error_value($company_name);
    }

    public static function looks_like_business_row(string $name, string $surname, string $company_name): bool
    {
        $company_name = trim($company_name);
        $full = trim($name . ' ' . $surname);

        // Explicit company_name column is always a company affiliation (HEMOINSA, Grumeti, …).
        if (!self::is_placeholder_company($company_name)) {
            if (function_exists('kit_seed_strip_private_company_label')) {
                $company_name = kit_seed_strip_private_company_label($company_name);
            }
            return $company_name !== '';
        }

        if ($full !== '' && function_exists('kit_seed_customer_looks_like_business')) {
            return kit_seed_customer_looks_like_business($full);
        }
        if ($name !== '' && function_exists('kit_seed_customer_looks_like_business')) {
            return kit_seed_customer_looks_like_business($name);
        }

        return false;
    }

    public static function company_name_exists(string $company_name, ?int $exclude_company_id = null): bool
    {
        global $wpdb;
        $company_name = trim(sanitize_text_field($company_name));
        if ($company_name === '' || self::is_placeholder_company($company_name)) {
            return false;
        }
        $sql = "SELECT COUNT(*) FROM " . self::table() . " WHERE LOWER(TRIM(COALESCE(company_name,''))) = %s";
        $params = [strtolower($company_name)];
        if ($exclude_company_id !== null && $exclude_company_id > 0) {
            $sql .= " AND company_id <> %d";
            $params[] = $exclude_company_id;
        }
        if ((int) $wpdb->get_var($wpdb->prepare($sql, ...$params)) > 0) {
            return true;
        }

        if (!class_exists('KIT_Customers')) {
            return false;
        }
        $norm = KIT_Customers::normalize_company_compare_key($company_name);
        if ($norm === '') {
            return false;
        }
        $rows = $wpdb->get_results('SELECT company_id, company_name FROM ' . self::table());
        foreach ($rows as $row) {
            $cid = (int) ($row->company_id ?? 0);
            if ($exclude_company_id !== null && $cid === (int) $exclude_company_id) {
                continue;
            }
            $other = trim((string) ($row->company_name ?? ''));
            if ($other === '' || self::is_placeholder_company($other)) {
                continue;
            }
            $other_key = KIT_Customers::normalize_company_compare_key($other);
            if ($other_key === '') {
                continue;
            }
            if ($other_key === $norm || KIT_Customers::customer_labels_are_similar($norm, $other_key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Next id for a company this system is inventing. Always inside the local
     * block so it can never be confused with a sheet customer_id.
     *
     * @see LOCAL_ID_BASE
     */
    public static function next_company_id(): int
    {
        global $wpdb;
        $max = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(company_id) FROM " . self::table() . " WHERE company_id >= %d",
            self::LOCAL_ID_BASE
        ));
        return max(self::LOCAL_ID_BASE, $max + 1);
    }

    /**
     * Move one company onto a new id, carrying its waybill and person links.
     */
    public static function renumber_company(int $from_id, int $to_id): bool
    {
        global $wpdb;
        if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) {
            return false;
        }
        if (self::find_by_company_id($to_id) || !self::find_by_company_id($from_id)) {
            return false;
        }

        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $customers_t = $wpdb->prefix . 'kit_customers';

        if (false === $wpdb->update(self::table(), ['company_id' => $to_id], ['company_id' => $from_id], ['%d'], ['%d'])) {
            return false;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$waybills_t} SET company_id = %d WHERE company_id = %d",
            $to_id,
            $from_id
        ));
        if (self::customers_have_company_id_column()) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$customers_t} SET company_id = %d WHERE company_id = %d",
                $to_id,
                $from_id
            ));
        }
        return true;
    }

    /**
     * Move every company sitting on a sheet-range id the sheet does not
     * actually assign to it into the local block.
     *
     * Earlier seeds invented companies at MAX(company_id) + 1, which put them
     * inside the sheet's customer_id range. Until those rows are moved out,
     * resolving a waybill party by id keeps matching the wrong company. Run
     * this after the company tab has been imported, so $sheet_labels describes
     * every id the sheet genuinely claims.
     *
     * @param array<int, string> $sheet_labels sheet id => label it belongs to
     * @return array{relocated:int,kept:int}
     */
    public static function relocate_unclaimed_sheet_ids(array $sheet_labels): array
    {
        global $wpdb;

        $stats = ['relocated' => 0, 'kept' => 0];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT company_id, company_name FROM ' . self::table() . ' WHERE company_id < %d ORDER BY company_id',
                self::LOCAL_ID_BASE
            ),
            ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
            $company_id = (int) ($row['company_id'] ?? 0);
            $name = trim((string) ($row['company_name'] ?? ''));
            if ($company_id <= 0) {
                continue;
            }
            if (self::sheet_id_belongs_to_label($sheet_labels, $company_id, $name)) {
                $stats['kept']++;
                continue;
            }
            if (self::renumber_company($company_id, self::next_company_id())) {
                $stats['relocated']++;
            }
        }

        return $stats;
    }

    /**
     * True when the sheet assigns $company_id to a party that is this company.
     *
     * @param array<int, string> $sheet_labels
     */
    private static function sheet_id_belongs_to_label(array $sheet_labels, int $company_id, string $name): bool
    {
        $claimed = trim((string) ($sheet_labels[$company_id] ?? ''));
        if ($claimed === '' || $name === '') {
            return false;
        }
        if (strcasecmp($claimed, $name) === 0) {
            return true;
        }
        if (!class_exists('KIT_Customers')) {
            return false;
        }
        $a = KIT_Customers::normalize_company_compare_key($claimed);
        $b = KIT_Customers::normalize_company_compare_key($name);
        return $a !== '' && $b !== '' && ($a === $b || KIT_Customers::customer_labels_are_similar($a, $b));
    }

    public static function find_by_company_id(int $company_id): ?array
    {
        global $wpdb;
        if ($company_id <= 0) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table() . " WHERE company_id = %d LIMIT 1", $company_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function find_by_name(string $company_name): ?array
    {
        global $wpdb;
        $company_name = trim($company_name);
        if ($company_name === '' || self::is_placeholder_company($company_name)) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE LOWER(TRIM(company_name)) = %s LIMIT 1",
                strtolower($company_name)
            ),
            ARRAY_A
        );
        if ($row) {
            return $row;
        }

        // Collapse whitespace / Ltd drift ("The Uniques  Tanzania Limited" → existing Uniques).
        if (!class_exists('KIT_Customers')) {
            return null;
        }
        $norm = KIT_Customers::normalize_company_compare_key($company_name);
        if ($norm === '') {
            return null;
        }
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table(), ARRAY_A) ?: [];
        foreach ($rows as $candidate) {
            $other = trim((string) ($candidate['company_name'] ?? ''));
            if ($other === '' || self::is_placeholder_company($other)) {
                continue;
            }
            $other_key = KIT_Customers::normalize_company_compare_key($other);
            if ($other_key === '') {
                continue;
            }
            if ($other_key === $norm || KIT_Customers::customer_labels_are_similar($norm, $other_key)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Ensure a company row exists for a label; returns company_id.
     */
    public static function ensure_company(string $company_name, array $extra = [], int $preferred_id = 0): int
    {
        global $wpdb;
        $company_name = trim($company_name);
        if ($company_name === '' || self::is_placeholder_company($company_name)) {
            return 0;
        }

        $existing = self::find_by_name($company_name);
        if ($existing) {
            return (int) $existing['company_id'];
        }

        if ($preferred_id > 0 && !self::find_by_company_id($preferred_id)) {
            $company_id = $preferred_id;
        } else {
            $company_id = self::next_company_id();
        }

        $data = [
            'company_id' => $company_id,
            'company_name' => sanitize_text_field($company_name),
            'cell' => sanitize_text_field($extra['cell'] ?? ''),
            'telephone' => sanitize_text_field($extra['telephone'] ?? ''),
            'email_address' => sanitize_email($extra['email_address'] ?? $extra['email'] ?? ''),
            'country_id' => !empty($extra['country_id']) ? (int) $extra['country_id'] : null,
            'city_id' => !empty($extra['city_id']) ? (int) $extra['city_id'] : null,
            'vat_number' => sanitize_text_field($extra['vat_number'] ?? ''),
            'address' => sanitize_textarea_field($extra['address'] ?? ''),
        ];

        $wpdb->insert(self::table(), $data, ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
        if (!$wpdb->insert_id && $wpdb->last_error) {
            return 0;
        }
        return $company_id;
    }

    public static function save_company(array $data)
    {
        global $wpdb;
        self::set_validation_error(null);

        $company_name = sanitize_text_field($data['company_name'] ?? '');
        if ($company_name === '' || self::is_placeholder_company($company_name)) {
            self::set_validation_error('Company name is required.');
            return false;
        }
        if (self::company_name_exists($company_name, null)) {
            self::set_validation_error('A company with this name already exists.');
            return false;
        }

        $company_id = !empty($data['company_id']) ? (int) $data['company_id'] : self::next_company_id();
        while (self::find_by_company_id($company_id)) {
            $company_id++;
        }

        $row = [
            'company_id' => $company_id,
            'company_name' => $company_name,
            'cell' => sanitize_text_field($data['cell'] ?? ''),
            'telephone' => sanitize_text_field($data['telephone'] ?? ''),
            'email_address' => sanitize_email($data['email_address'] ?? $data['email'] ?? ''),
            'country_id' => !empty($data['country_id']) ? (int) $data['country_id'] : null,
            'city_id' => !empty($data['city_id']) ? (int) $data['city_id'] : null,
            'vat_number' => sanitize_text_field($data['vat_number'] ?? ''),
            'address' => sanitize_textarea_field($data['address'] ?? ''),
        ];

        $ok = $wpdb->insert(self::table(), $row, ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
        return $ok ? $company_id : false;
    }

    public static function update_company(int $company_id, array $data)
    {
        global $wpdb;
        self::set_validation_error(null);
        if ($company_id <= 0 || !self::find_by_company_id($company_id)) {
            self::set_validation_error('Company not found.');
            return false;
        }

        $update = [];
        $formats = [];
        if (isset($data['company_name'])) {
            $company_name = sanitize_text_field($data['company_name']);
            if ($company_name === '' || self::is_placeholder_company($company_name)) {
                self::set_validation_error('Company name is required.');
                return false;
            }
            if (self::company_name_exists($company_name, $company_id)) {
                self::set_validation_error('A company with this name already exists.');
                return false;
            }
            $update['company_name'] = $company_name;
            $formats[] = '%s';
        }
        foreach (['cell', 'telephone', 'vat_number'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = sanitize_text_field($data[$f] ?? '');
                $formats[] = '%s';
            }
        }
        if (array_key_exists('email_address', $data) || array_key_exists('email', $data)) {
            $update['email_address'] = sanitize_email($data['email_address'] ?? $data['email'] ?? '');
            $formats[] = '%s';
        }
        if (array_key_exists('address', $data)) {
            $update['address'] = sanitize_textarea_field($data['address'] ?? '');
            $formats[] = '%s';
        }
        if (array_key_exists('country_id', $data)) {
            $update['country_id'] = $data['country_id'] !== '' && $data['country_id'] !== null ? (int) $data['country_id'] : null;
            $formats[] = '%d';
        }
        if (array_key_exists('city_id', $data)) {
            $update['city_id'] = $data['city_id'] !== '' && $data['city_id'] !== null ? (int) $data['city_id'] : null;
            $formats[] = '%d';
        }

        if (empty($update)) {
            return true;
        }

        return false !== $wpdb->update(self::table(), $update, ['company_id' => $company_id], $formats, ['%d']);
    }

    public static function delete_company(int $company_id): bool
    {
        global $wpdb;
        if ($company_id <= 0) {
            return false;
        }
        // Clear waybill and person links first
        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $customers_t = $wpdb->prefix . 'kit_customers';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$waybills_t} SET company_id = NULL WHERE company_id = %d",
            $company_id
        ));
        if (self::customers_have_company_id_column()) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$customers_t} SET company_id = NULL WHERE company_id = %d",
                $company_id
            ));
        }
        return false !== $wpdb->delete(self::table(), ['company_id' => $company_id], ['%d']);
    }

    /**
     * Whether kit_customers.company_id exists.
     */
    public static function customers_have_company_id_column(): bool
    {
        global $wpdb;
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        if (class_exists('Database')) {
            Database::ensure_customer_company_id_column();
        }
        $has = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = %s AND table_name = %s AND column_name = 'company_id'",
            DB_NAME,
            $wpdb->prefix . 'kit_customers'
        ));
        return $has;
    }

    /**
     * People linked to this company via kit_customers.company_id.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list_linked_customers(int $company_id): array
    {
        global $wpdb;
        if ($company_id <= 0 || !self::customers_have_company_id_column()) {
            return [];
        }
        $customers_t = $wpdb->prefix . 'kit_customers';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cust_id, name, surname, cell, email_address, address, company_id
                 FROM {$customers_t}
                 WHERE company_id = %d
                 ORDER BY surname ASC, name ASC, cust_id ASC",
                $company_id
            ),
            ARRAY_A
        ) ?: [];
        return $rows;
    }

    /**
     * Individuals available to attach (no company link, or already on this company excluded from attach list).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list_attachable_customers(int $company_id, int $limit = 300): array
    {
        global $wpdb;
        if (!self::customers_have_company_id_column()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $customers_t = $wpdb->prefix . 'kit_customers';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cust_id, name, surname, cell, email_address, company_id
                 FROM {$customers_t}
                 WHERE (company_id IS NULL OR company_id = 0)
                   AND (
                     TRIM(CONCAT(COALESCE(name,''), ' ', COALESCE(surname,''))) <> ''
                   )
                 ORDER BY surname ASC, name ASC, cust_id ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];

        // Prefer real people over business-stub names.
        return array_values(array_filter($rows, static function ($row) {
            $name = trim((string) ($row['name'] ?? ''));
            $surname = trim((string) ($row['surname'] ?? ''));
            $full = trim($name . ' ' . $surname);
            if ($full === '') {
                return false;
            }
            if (function_exists('kit_seed_customer_looks_like_business') && kit_seed_customer_looks_like_business($full)) {
                return false;
            }
            return true;
        }));
    }

    /**
     * Attach an individual customer to a company.
     */
    public static function attach_customer(int $company_id, int $cust_id): bool
    {
        global $wpdb;
        self::set_validation_error(null);

        if ($company_id <= 0 || $cust_id <= 0) {
            self::set_validation_error('Invalid company or customer.');
            return false;
        }
        if (!self::find_by_company_id($company_id)) {
            self::set_validation_error('Company not found.');
            return false;
        }
        if (!self::customers_have_company_id_column()) {
            self::set_validation_error('Customer–company link is not available.');
            return false;
        }

        $customers_t = $wpdb->prefix . 'kit_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT cust_id, company_id, name, surname FROM {$customers_t} WHERE cust_id = %d LIMIT 1", $cust_id),
            ARRAY_A
        );
        if (!$row) {
            self::set_validation_error('Customer not found.');
            return false;
        }

        $current = (int) ($row['company_id'] ?? 0);
        if ($current === $company_id) {
            return true;
        }
        if ($current > 0 && $current !== $company_id) {
            self::set_validation_error('That customer is already linked to another company. Detach them first.');
            return false;
        }

        $updated = $wpdb->update(
            $customers_t,
            ['company_id' => $company_id],
            ['cust_id' => $cust_id],
            ['%d'],
            ['%d']
        );
        if ($updated === false) {
            self::set_validation_error('Could not attach customer.');
            return false;
        }

        // Point this person's waybills at the company when company_id is still empty.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$waybills_t}
             SET company_id = %d
             WHERE customer_id = %d
               AND (company_id IS NULL OR company_id = 0)",
            $company_id,
            $cust_id
        ));

        return true;
    }

    /**
     * Detach an individual from this company.
     */
    public static function detach_customer(int $company_id, int $cust_id): bool
    {
        global $wpdb;
        self::set_validation_error(null);

        if ($company_id <= 0 || $cust_id <= 0 || !self::customers_have_company_id_column()) {
            self::set_validation_error('Invalid request.');
            return false;
        }

        $customers_t = $wpdb->prefix . 'kit_customers';
        $current = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT company_id FROM {$customers_t} WHERE cust_id = %d LIMIT 1",
            $cust_id
        ));
        if ($current !== $company_id) {
            self::set_validation_error('Customer is not linked to this company.');
            return false;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$customers_t} SET company_id = NULL WHERE cust_id = %d AND company_id = %d",
            $cust_id,
            $company_id
        ));
        if ($updated === false) {
            self::set_validation_error('Could not detach customer.');
            return false;
        }
        return true;
    }

    /**
     * Create a new individual and attach to company.
     *
     * @param array{name?:string,surname?:string,cell?:string,email_address?:string} $data
     * @return int|false New cust_id
     */
    public static function create_and_attach_customer(int $company_id, array $data)
    {
        self::set_validation_error(null);

        if ($company_id <= 0 || !self::find_by_company_id($company_id)) {
            self::set_validation_error('Company not found.');
            return false;
        }
        if (!class_exists('KIT_Customers')) {
            self::set_validation_error('Customer module unavailable.');
            return false;
        }

        $name = sanitize_text_field((string) ($data['name'] ?? ''));
        $surname = sanitize_text_field((string) ($data['surname'] ?? ''));
        if (trim($name) === '' || trim($surname) === '') {
            self::set_validation_error('First name and surname are required.');
            return false;
        }

        $existing = self::list_linked_customers($company_id);
        if (!empty($existing)) {
            self::set_validation_error('This company already has an associated customer. Update their details instead.');
            return false;
        }

        $cust_id = KIT_Customers::save_customer([
            'name' => $name,
            'surname' => $surname,
            'cell' => sanitize_text_field((string) ($data['cell'] ?? '')),
            'email_address' => sanitize_email((string) ($data['email_address'] ?? '')),
            'address' => '',
            'vat_number' => '',
        ]);
        if (!$cust_id) {
            self::set_validation_error(
                KIT_Customers::get_last_customer_validation_error() ?: 'Could not create customer.'
            );
            return false;
        }

        if (!self::attach_customer($company_id, (int) $cust_id)) {
            return false;
        }
        return (int) $cust_id;
    }

    /**
     * Update a customer that is linked to this company.
     *
     * @param array{name?:string,surname?:string,cell?:string,email_address?:string,address?:string} $data
     */
    public static function update_linked_customer(int $company_id, int $cust_id, array $data): bool
    {
        self::set_validation_error(null);

        if ($company_id <= 0 || $cust_id <= 0) {
            self::set_validation_error('Invalid company or customer.');
            return false;
        }
        if (!self::customers_have_company_id_column()) {
            self::set_validation_error('Customer–company link is not available.');
            return false;
        }

        $linked = self::list_linked_customers($company_id);
        $is_linked = false;
        foreach ($linked as $person) {
            if ((int) ($person['cust_id'] ?? 0) === $cust_id) {
                $is_linked = true;
                break;
            }
        }
        if (!$is_linked) {
            self::set_validation_error('Customer is not linked to this company.');
            return false;
        }

        if (!class_exists('KIT_Customers')) {
            self::set_validation_error('Customer module unavailable.');
            return false;
        }

        $ok = KIT_Customers::update_customer($cust_id, [
            'name' => sanitize_text_field((string) ($data['name'] ?? '')),
            'surname' => sanitize_text_field((string) ($data['surname'] ?? '')),
            'cell' => sanitize_text_field((string) ($data['cell'] ?? '')),
            'email_address' => (string) ($data['email_address'] ?? ''),
            'address' => sanitize_text_field((string) ($data['address'] ?? '')),
        ]);
        if (!$ok) {
            self::set_validation_error(
                KIT_Customers::get_last_customer_validation_error() ?: 'Could not update customer.'
            );
            return false;
        }
        return true;
    }

    /**
     * When "use same email" is posted, copy the person email onto the company record.
     */
    public static function sync_company_email_from_post(int $company_id): bool
    {
        if ($company_id <= 0) {
            return false;
        }
        if (empty($_POST['sync_company_email'])) {
            return true;
        }
        $email = sanitize_email((string) wp_unslash($_POST['email_address'] ?? ''));
        return false !== self::update_company($company_id, ['email_address' => $email]);
    }

    public static function handle_update_company_customer(): void
    {
        if (!isset($_POST['kit_company_customer_nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['kit_company_customer_nonce'])),
            'kit_company_customer_link'
        )) {
            wp_die('Nonce verification failed');
        }
        if (!current_user_can('manage_options') && !current_user_can('kit_manage_customers') && !current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }

        $company_id = (int) ($_POST['company_id'] ?? 0);
        $cust_id = (int) ($_POST['cust_id'] ?? 0);
        $ok = self::update_linked_customer($company_id, $cust_id, [
            'name' => $_POST['name'] ?? '',
            'surname' => $_POST['surname'] ?? '',
            'cell' => $_POST['cell'] ?? '',
            'email_address' => $_POST['email_address'] ?? '',
            'address' => $_POST['address'] ?? '',
        ]);
        if ($ok) {
            self::sync_company_email_from_post($company_id);
        }
        $base = admin_url('admin.php?page=08600-customers&edit_company=' . $company_id);
        if ($ok) {
            wp_safe_redirect($base . '&customer_updated=1');
            exit;
        }
        $msg = rawurlencode(self::get_validation_error() ?: 'Could not update customer.');
        wp_safe_redirect($base . '&link_error=1&msg=' . $msg);
        exit;
    }

    public static function handle_create_company_customer(): void
    {
        if (!isset($_POST['kit_company_customer_nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['kit_company_customer_nonce'])),
            'kit_company_customer_link'
        )) {
            wp_die('Nonce verification failed');
        }
        if (!current_user_can('manage_options') && !current_user_can('kit_manage_customers') && !current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }

        $company_id = (int) ($_POST['company_id'] ?? 0);
        $cust_id = self::create_and_attach_customer($company_id, [
            'name' => $_POST['name'] ?? '',
            'surname' => $_POST['surname'] ?? '',
            'cell' => $_POST['cell'] ?? '',
            'email_address' => $_POST['email_address'] ?? '',
        ]);
        if ($cust_id) {
            self::sync_company_email_from_post($company_id);
        }
        $base = admin_url('admin.php?page=08600-customers&edit_company=' . $company_id);
        if ($cust_id) {
            wp_safe_redirect($base . '&customer_created=1');
            exit;
        }
        $msg = rawurlencode(self::get_validation_error() ?: 'Could not create customer.');
        wp_safe_redirect($base . '&link_error=1&msg=' . $msg);
        exit;
    }

    public static function handle_detach_company_customer(): void
    {
        if (!isset($_POST['kit_company_customer_nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['kit_company_customer_nonce'])),
            'kit_company_customer_link'
        )) {
            wp_die('Nonce verification failed');
        }
        if (!current_user_can('manage_options') && !current_user_can('kit_manage_customers') && !current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }

        $company_id = (int) ($_POST['company_id'] ?? 0);
        $cust_id = (int) ($_POST['cust_id'] ?? 0);
        $ok = self::detach_customer($company_id, $cust_id);
        $base = admin_url('admin.php?page=08600-customers&edit_company=' . $company_id);
        if ($ok) {
            wp_safe_redirect($base . '&customer_unlinked=1');
            exit;
        }
        $msg = rawurlencode(self::get_validation_error() ?: 'Could not detach customer.');
        wp_safe_redirect($base . '&link_error=1&msg=' . $msg);
        exit;
    }

    /**
     * @return array{items:array,total:int}
     */
    public static function list_companies(string $search = '', int $limit = 100, int $offset = 0): array
    {
        global $wpdb;
        $table = self::table();
        $where = '1=1';
        $list_where = '1=1';
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (company_name LIKE %s OR cell LIKE %s OR email_address LIKE %s OR vat_number LIKE %s)';
            $list_where .= ' AND (co.company_name LIKE %s OR co.cell LIKE %s OR co.email_address LIKE %s OR co.vat_number LIKE %s)';
            $params = [$like, $like, $like, $like];
        }
        $total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($total_sql, ...$params)) : $wpdb->get_var($total_sql));

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $count_sql = self::waybill_count_sql('co');
        $list_sql = "SELECT co.*, {$count_sql}
            FROM {$table} co
            WHERE {$list_where}
            ORDER BY co.company_name ASC
            LIMIT %d OFFSET %d";
        $list_params = array_merge($params, [$limit, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_params), ARRAY_A) ?: [];

        return ['items' => $items, 'total' => $total];
    }

    public static function search_companies(string $q, int $limit = 20): array
    {
        $result = self::list_companies($q, $limit, 0);
        return $result['items'];
    }

    public static function waybill_count_sql(string $alias = 'co'): string
    {
        global $wpdb;
        $waybills = $wpdb->prefix . 'kit_waybills';
        $customers = $wpdb->prefix . 'kit_customers';
        // Billed on company_id, or legacy sheet rows that stored the company id in customer_id.
        return "(SELECT COUNT(*) FROM {$waybills} w
            WHERE w.company_id = {$alias}.company_id
               OR (
                   w.customer_id = {$alias}.company_id
                   AND NOT EXISTS (
                       SELECT 1 FROM {$customers} c
                       WHERE c.cust_id = w.customer_id
                   )
               )
        ) AS total_waybills";
    }

    /**
     * Link waybills that still only have sheet customer_id to kit_company_customers.company_id.
     * Sheet source of truth: company_id is preserved as the sheet customer_id when possible.
     *
     * @return array{waybills_linked:int,customer_ids_cleared:int}
     */
    public static function backfill_waybill_company_ids(): array
    {
        global $wpdb;

        if (class_exists('Database')) {
            Database::create_company_customers_table();
            Database::ensure_waybill_company_id_column();
        }

        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $companies_t = self::table();
        $customers_t = $wpdb->prefix . 'kit_customers';

        $stats = [
            'waybills_linked' => 0,
            'customer_ids_cleared' => 0,
        ];

        // Sheet key match: waybill.customer_id == company.company_id.
        // Only valid when no person owns that id. The two sheet tabs number
        // people and companies from one sequence, so cust_id 8610 (a person)
        // and company_id 8610 (their employer, or somebody else entirely) both
        // exist — an unqualified join hands every one of those waybills to
        // whichever company shares the number.
        $linked = $wpdb->query($wpdb->prepare(
            "UPDATE {$waybills_t} w
             INNER JOIN {$companies_t} co ON w.customer_id = co.company_id
             LEFT JOIN {$customers_t} c ON c.cust_id = w.customer_id
             SET w.company_id = co.company_id
             WHERE w.customer_id > 0
               AND c.cust_id IS NULL
               AND co.company_id < %d
               AND (w.company_id IS NULL OR w.company_id = 0)",
            self::LOCAL_ID_BASE
        ));
        $stats['waybills_linked'] = (int) $linked;

        // Person→company FK: kit_customers.company_id → waybill.company_id
        // Only when company_name was cleared by mixed seed (Lenet→Consolidate).
        // Skip rows with a non-empty company_name that is not the linked company
        // (avoids stale FKs like Ashley/Terra Tools → EOS Limited).
        $has_cust_company_id = (bool) $wpdb->get_var($wpdb->prepare(
            'SHOW COLUMNS FROM `' . $customers_t . '` LIKE %s',
            'company_id'
        ));
        if ($has_cust_company_id) {
            $linked_via_person = $wpdb->query(
                "UPDATE {$waybills_t} w
                 INNER JOIN {$customers_t} c ON w.customer_id = c.cust_id
                 INNER JOIN {$companies_t} co ON c.company_id = co.company_id
                 SET w.company_id = co.company_id
                 WHERE w.customer_id > 0
                   AND c.company_id > 0
                   AND (w.company_id IS NULL OR w.company_id = 0)"
            );
            $stats['waybills_linked'] += (int) $linked_via_person;
        }

        // Company-only parties: clear customer_id when no individual row shares that id.
        $cleared = $wpdb->query(
            "UPDATE {$waybills_t} w
             INNER JOIN {$companies_t} co ON w.company_id = co.company_id
             LEFT JOIN {$customers_t} c ON c.cust_id = w.customer_id
             SET w.customer_id = 0
             WHERE w.company_id > 0
               AND w.customer_id > 0
               AND c.cust_id IS NULL"
        );
        $stats['customer_ids_cleared'] = (int) $cleared;

        return $stats;
    }

    /**
     * Point unmatched waybills at a company using the sheet customer_id key.
     */
    public static function link_waybills_to_company(int $company_id, int $sheet_customer_id = 0): int
    {
        global $wpdb;
        if ($company_id <= 0) {
            return 0;
        }
        $sheet_customer_id = $sheet_customer_id > 0 ? $sheet_customer_id : $company_id;
        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $customers_t = $wpdb->prefix . 'kit_customers';

        $updated = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$waybills_t}
             SET company_id = %d
             WHERE customer_id = %d
               AND (company_id IS NULL OR company_id = 0)",
            $company_id,
            $sheet_customer_id
        ));

        // Clear customer_id when no individual exists for that sheet id.
        $individual = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT cust_id FROM {$customers_t} WHERE cust_id = %d LIMIT 1",
            $sheet_customer_id
        ));
        if ($individual <= 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$waybills_t}
                 SET customer_id = 0
                 WHERE company_id = %d AND customer_id = %d",
                $company_id,
                $sheet_customer_id
            ));
        }

        return $updated;
    }

    /**
     * SQL fragments for joining company + individual onto waybills.
     *
     * @return array{join:string,company_name:string,customer_name:string,customer_surname:string}
     */
    public static function waybill_party_sql(string $waybill_alias = 'w', string $customer_alias = 'c', string $company_alias = 'co'): array
    {
        global $wpdb;
        $customers = $wpdb->prefix . 'kit_customers';
        $companies = $wpdb->prefix . 'kit_company_customers';
        return [
            'join' => "LEFT JOIN {$customers} AS {$customer_alias} ON {$waybill_alias}.customer_id = {$customer_alias}.cust_id
                LEFT JOIN {$companies} AS {$company_alias} ON {$waybill_alias}.company_id = {$company_alias}.company_id",
            'company_name' => "{$company_alias}.company_name",
            'customer_name' => "{$customer_alias}.name",
            'customer_surname' => "{$customer_alias}.surname",
        ];
    }

    /**
     * Display label for a waybill row that may have company and/or individual.
     */
    public static function display_label_from_row(array $row): string
    {
        $company = trim((string) ($row['company_name'] ?? $row['company'] ?? ''));
        if ($company !== '' && !self::is_placeholder_company($company)) {
            return $company;
        }
        $person = trim(trim((string) ($row['customer_name'] ?? $row['name'] ?? '')) . ' ' . trim((string) ($row['customer_surname'] ?? $row['surname'] ?? '')));
        return $person !== '' ? $person : $company;
    }

    /**
     * Convert one individual customer into a company: insert kit_company_customers,
     * re-point waybills (company_id set, customer_id cleared), delete kit_customers row.
     *
     * Prefers keeping company_id = cust_id when that id is free.
     *
     * @param int   $cust_id Individual cust_id
     * @param array $data    Optional overrides: company_name, cell, telephone, email_address, address, country_id, city_id, vat_number
     * @return int|false New company_id on success
     */
    public static function convert_customer_to_company(int $cust_id, array $data = [])
    {
        global $wpdb;
        self::set_validation_error(null);

        $cust_id = (int) $cust_id;
        if ($cust_id <= 0) {
            self::set_validation_error('Invalid customer.');
            return false;
        }

        if (class_exists('Database')) {
            Database::create_company_customers_table();
            Database::ensure_waybill_company_id_column();
        }

        $customers_t = $wpdb->prefix . 'kit_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$customers_t} WHERE cust_id = %d LIMIT 1", $cust_id),
            ARRAY_A
        );
        if (!$row) {
            self::set_validation_error('Customer not found.');
            return false;
        }

        $company_name = trim(sanitize_text_field((string) ($data['company_name'] ?? '')));
        if ($company_name === '' || self::is_placeholder_company($company_name)) {
            self::set_validation_error('Company name is required to convert this client.');
            return false;
        }

        if (self::company_name_exists($company_name, null)) {
            self::set_validation_error('A company with this name already exists.');
            return false;
        }

        $company_id = (!self::find_by_company_id($cust_id)) ? $cust_id : self::next_company_id();

        $pick = static function (string $key, $fallback = '') use ($data, $row) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
            return $row[$key] ?? $fallback;
        };

        $country_raw = $pick('country_id', null);
        $city_raw = $pick('city_id', null);

        $insert = [
            'company_id' => $company_id,
            'company_name' => $company_name,
            'cell' => sanitize_text_field((string) $pick('cell', '')),
            'telephone' => sanitize_text_field((string) $pick('telephone', '')),
            'email_address' => sanitize_email((string) ($pick('email_address', '') ?: $pick('email', ''))),
            'vat_number' => sanitize_text_field((string) $pick('vat_number', '')),
            'address' => sanitize_textarea_field((string) $pick('address', '')),
        ];

        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s'];

        $country_id = ($country_raw !== '' && $country_raw !== null) ? (int) $country_raw : 0;
        $city_id = ($city_raw !== '' && $city_raw !== null) ? (int) $city_raw : 0;
        if ($country_id > 0) {
            $insert['country_id'] = $country_id;
            $formats[] = '%d';
        }
        if ($city_id > 0) {
            $insert['city_id'] = $city_id;
            $formats[] = '%d';
        }

        $ok = $wpdb->insert(self::table(), $insert, $formats);
        if (!$ok) {
            self::set_validation_error(
                'Could not create company' . ($wpdb->last_error ? (': ' . $wpdb->last_error) : '.')
            );
            return false;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$waybills_t}
             SET company_id = %d, customer_id = 0
             WHERE customer_id = %d",
            $company_id,
            $cust_id
        ));

        $deleted = $wpdb->delete($customers_t, ['cust_id' => $cust_id], ['%d']);
        if ($deleted === false) {
            self::set_validation_error('Company created but the individual customer row could not be removed.');
            return false;
        }

        return $company_id;
    }

    /**
     * Migrate business rows from kit_customers into kit_company_customers and re-point waybills.
     *
     * @return array{companies_created:int,individuals_cleared:int,waybills_updated:int,customers_deleted:int}
     */
    public static function migrate_from_kit_customers(): array
    {
        global $wpdb;

        if (class_exists('Database')) {
            Database::create_company_customers_table();
            Database::ensure_waybill_company_id_column();
        }

        // Load seed helpers for classification when available.
        if (!function_exists('kit_seed_customer_looks_like_business')) {
            $settings = dirname(__DIR__) . '/admin-pages/settings.php';
            if (file_exists($settings) && !defined('COURIER_SEED_SIMULATION')) {
                define('COURIER_SEED_SIMULATION', true);
            }
            if (file_exists($settings)) {
                ob_start();
                require_once $settings;
                ob_end_clean();
            }
        }

        $customers_t = $wpdb->prefix . 'kit_customers';
        $waybills_t = $wpdb->prefix . 'kit_waybills';
        $rows = $wpdb->get_results("SELECT * FROM {$customers_t}", ARRAY_A) ?: [];

        $stats = [
            'companies_created' => 0,
            'individuals_cleared' => 0,
            'waybills_updated' => 0,
            'customers_deleted' => 0,
        ];

        foreach ($rows as $row) {
            $cust_id = (int) ($row['cust_id'] ?? 0);
            if ($cust_id <= 0) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $surname = trim((string) ($row['surname'] ?? ''));
            $company_name = class_exists('Database') && Database::customers_have_company_name_column()
                ? trim((string) ($row['company_name'] ?? ''))
                : '';
            $full = trim($name . ' ' . $surname);

            $is_business = self::looks_like_business_row($name, $surname, $company_name);
            $has_real_person = $full !== ''
                && strcasecmp($full, $company_name) !== 0
                && strcasecmp($name, $company_name) !== 0
                && !(function_exists('kit_seed_customer_looks_like_business') && kit_seed_customer_looks_like_business($full));

            if (!$is_business) {
                // Individual: clear leftover denormalized company_name when the column still exists.
                if (
                    class_exists('Database')
                    && Database::customers_have_company_name_column()
                    && self::is_placeholder_company($company_name)
                    && $company_name !== ''
                ) {
                    $wpdb->update(
                        $customers_t,
                        ['company_name' => ''],
                        ['cust_id' => $cust_id],
                        ['%s'],
                        ['%d']
                    );
                    $stats['individuals_cleared']++;
                }
                continue;
            }

            $biz_label = !self::is_placeholder_company($company_name) ? $company_name : ($full !== '' ? $full : $name);
            if ($biz_label === '' || self::is_placeholder_company($biz_label)) {
                continue;
            }

            $extra = [
                'cell' => $row['cell'] ?? '',
                'telephone' => $row['telephone'] ?? '',
                'email_address' => $row['email_address'] ?? '',
                'country_id' => $row['country_id'] ?? null,
                'city_id' => $row['city_id'] ?? null,
                'vat_number' => $row['vat_number'] ?? '',
                'address' => $row['address'] ?? '',
            ];

            $before = self::find_by_name($biz_label);
            $company_id = self::ensure_company($biz_label, $extra, $cust_id);
            if ($company_id <= 0) {
                continue;
            }
            if (!$before) {
                $stats['companies_created']++;
            }

            if ($has_real_person) {
                // Mixed: keep person row, set company_id, drop leftover company_name.
                $mixed_update = ['company_id' => $company_id];
                $mixed_formats = ['%d'];
                if (class_exists('Database') && Database::customers_have_company_name_column()) {
                    $mixed_update['company_name'] = '';
                    $mixed_formats[] = '%s';
                }
                $wpdb->update(
                    $customers_t,
                    $mixed_update,
                    ['cust_id' => $cust_id],
                    $mixed_formats,
                    ['%d']
                );
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$waybills_t}
                     SET company_id = %d
                     WHERE customer_id = %d AND (company_id IS NULL OR company_id = 0)",
                    $company_id,
                    $cust_id
                ));
                $stats['waybills_updated'] += (int) $updated;
                $stats['individuals_cleared']++;
            } else {
                // Company-only: move waybills to company_id, clear customer_id, delete customer row.
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$waybills_t}
                     SET company_id = %d, customer_id = 0
                     WHERE customer_id = %d",
                    $company_id,
                    $cust_id
                ));
                $stats['waybills_updated'] += (int) $updated;
                $wpdb->delete($customers_t, ['cust_id' => $cust_id], ['%d']);
                if ($wpdb->rows_affected > 0) {
                    $stats['customers_deleted']++;
                }
            }
        }

        return $stats;
    }

    /**
     * admin-post.php?action=update_company
     */
    public static function handle_update_company(): void
    {
        if (!isset($_POST['company_update_nonce']) || !wp_verify_nonce($_POST['company_update_nonce'], 'update_company_nonce')) {
            wp_die('Nonce verification failed');
        }

        $company_id = (int) ($_POST['company_id'] ?? 0);
        $data = [
            'company_name' => sanitize_text_field($_POST['company_name'] ?? ''),
            'cell' => sanitize_text_field($_POST['cell'] ?? ''),
            'address' => sanitize_textarea_field($_POST['address'] ?? ''),
            'email_address' => sanitize_text_field($_POST['email_address'] ?? ''),
            'vat_number' => sanitize_text_field($_POST['vat_number'] ?? ''),
            'telephone' => sanitize_text_field($_POST['telephone'] ?? ''),
        ];

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

        $updated = self::update_company($company_id, $data);
        if ($updated) {
            wp_redirect(admin_url('admin.php?page=08600-customers&company_updated=1&edit_company=' . $company_id));
            exit;
        }

        $msg = rawurlencode(self::get_validation_error() ?: 'Could not update company.');
        wp_redirect(admin_url('admin.php?page=08600-customers&edit_company=' . $company_id . '&update_error=1&msg=' . $msg));
        exit;
    }
}

/**
 * Edit company screen (customers dashboard ?edit_company=).
 */
function kit_edit_company_form(int $company_id): void
{
    if (!function_exists('kit_render_company_form')) {
        require_once dirname(__FILE__) . '/_customersForm.php';
    }

    $company = KIT_Company_Customers::find_by_company_id($company_id);
    if (!$company) {
        echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Company not found.', '08600') . '</p></div></div>';
        return;
    }

    $back_url = admin_url('admin.php?page=08600-customers');
    $view_url = admin_url('admin.php?page=08600-customers&view_company=' . $company_id);
    $header_actions = KIT_Commons::renderButton(
        __('Back to list', '08600'),
        'secondary',
        'md',
        [
            'href' => $back_url,
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />',
            'iconPosition' => 'left',
            'noLoading' => true,
        ]
    ) . KIT_Commons::renderButton(
        __('View company', '08600'),
        'secondary',
        'md',
        [
            'href' => $view_url,
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />',
            'iconPosition' => 'left',
            'noLoading' => true,
            'classes' => 'ml-2',
        ]
    );

    echo '<div class="wrap customers-page kit-edit-company-page">';
    echo '<div class="' . esc_attr(KIT_Commons::containerClasses()) . '">';

    echo KIT_Commons::showingHeader([
        'title'   => __('Edit Company', '08600'),
        'desc'    => __('Update profile and linked people.', '08600'),
        'icon'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />',
        'content' => $header_actions,
    ]);
    echo '<hr class="wp-header-end">';

    if (!empty($_GET['update_error']) && isset($_GET['msg'])) {
        $msg = sanitize_text_field(rawurldecode((string) wp_unslash($_GET['msg'])));
        if ($msg !== '') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }
    if (!empty($_GET['link_error']) && isset($_GET['msg'])) {
        $msg = sanitize_text_field(rawurldecode((string) wp_unslash($_GET['msg'])));
        if ($msg !== '') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }
    if (!empty($_GET['company_updated'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Company updated.', '08600') . '</p></div>';
    }
    if (!empty($_GET['converted'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Client converted to a company. Waybills were re-linked.', '08600') . '</p></div>';
    }
    if (!empty($_GET['customer_unlinked'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Customer detached from this company.', '08600') . '</p></div>';
    }
    if (!empty($_GET['customer_created'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Associated customer created.', '08600') . '</p></div>';
    }
    if (!empty($_GET['customer_updated'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Associated customer updated.', '08600') . '</p></div>';
    }

    echo '<div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6 items-start">';
    echo '<div class="bg-white border border-gray-200 rounded-lg p-4 md:p-5 min-w-0">';
    echo kit_render_company_form($company);
    echo '</div>';
    echo '<div class="bg-white border border-gray-200 rounded-lg p-4 md:p-5 min-w-0">';
    echo kit_render_company_associated_customers_panel($company_id, $company);
    echo '</div>';
    echo '</div></div></div>';
}

/**
 * Associated person panel for company edit mode.
 * No link → create form. Linked → editable details in the same fields.
 *
 * @param array<string,mixed> $company
 */
function kit_render_company_associated_customers_panel(int $company_id, array $company = []): string
{
    $company_name = trim((string) ($company['company_name'] ?? '')) ?: __('this company', '08600');
    $linked = KIT_Company_Customers::list_linked_customers($company_id);
    $person = !empty($linked) ? $linked[0] : null;
    $cust_id = $person ? (int) ($person['cust_id'] ?? 0) : 0;
    $is_edit = $cust_id > 0;
    $nonce = wp_create_nonce('kit_company_customer_link');
    $action_url = admin_url('admin-post.php');

    $person_email = $is_edit ? trim((string) ($person['email_address'] ?? '')) : '';
    $company_email = trim((string) ($company['email_address'] ?? ''));
    $sync_email_default = ($person_email !== '' && strcasecmp($person_email, $company_email) === 0);

    ob_start();
    ?>
    <div class="kit-company-associated-customers">
        <?php
        echo KIT_Commons::prettyHeading([
            'words' => __('Associated customers', '08600'),
            'icon' => KIT_Commons::icon('user-group'),
            'size' => 'sm',
            'tag' => 'h2',
            'classes' => 'mb-2',
        ]);
        ?>
        <p class="text-sm text-gray-600 mb-3">
            <?php
            if ($is_edit) {
                echo esc_html(sprintf(
                    /* translators: %s: company name */
                    __('Contact person for %s. Update their details below.', '08600'),
                    $company_name
                ));
            } else {
                echo esc_html(sprintf(
                    /* translators: %s: company name */
                    __('No contact person for %s yet. Create one below.', '08600'),
                    $company_name
                ));
            }
            ?>
        </p>

        <?php if (!KIT_Company_Customers::customers_have_company_id_column()) : ?>
            <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                <?php echo esc_html__('Customer–company linking is not available on this install yet.', '08600'); ?>
            </p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url($action_url); ?>" class="grid grid-cols-1 md:grid-cols-2 gap-4" id="kit-assoc-customer-form">
                <input type="hidden" name="action" value="<?php echo esc_attr($is_edit ? 'kit_update_company_customer' : 'kit_create_company_customer'); ?>">
                <input type="hidden" name="kit_company_customer_nonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="company_id" value="<?php echo esc_attr((string) $company_id); ?>">
                <?php if ($is_edit) : ?>
                    <input type="hidden" name="cust_id" value="<?php echo esc_attr((string) $cust_id); ?>">
                <?php endif; ?>

                <div class="min-w-0">
                    <?php
                    echo KIT_Commons::Linput([
                        'label' => 'First Name',
                        'name' => 'name',
                        'id' => 'assoc_customer_name',
                        'type' => 'text',
                        'value' => $is_edit ? (string) ($person['name'] ?? '') : '',
                        'special' => 'required autocomplete="given-name"',
                    ]);
                    ?>
                </div>
                <div class="min-w-0">
                    <?php
                    echo KIT_Commons::Linput([
                        'label' => 'Surname',
                        'name' => 'surname',
                        'id' => 'assoc_customer_surname',
                        'type' => 'text',
                        'value' => $is_edit ? (string) ($person['surname'] ?? '') : '',
                        'special' => 'required autocomplete="family-name"',
                    ]);
                    ?>
                </div>
                <div class="min-w-0">
                    <?php
                    echo KIT_Commons::Linput([
                        'label' => 'Cell Phone',
                        'name' => 'cell',
                        'id' => 'assoc_customer_cell',
                        'type' => 'tel',
                        'value' => $is_edit ? (string) ($person['cell'] ?? '') : '',
                        'special' => 'inputmode="tel" autocomplete="tel"',
                    ]);
                    ?>
                </div>
                <div class="min-w-0">
                    <?php
                    echo KIT_Commons::Linput([
                        'label' => 'Email',
                        'name' => 'email_address',
                        'id' => 'assoc_customer_email',
                        'type' => 'email',
                        'value' => $person_email,
                        'special' => 'autocomplete="email"',
                    ]);
                    ?>
                </div>
                <div class="md:col-span-2">
                    <div class="kit-email-sync">
                        <label class="kit-email-sync-toggle" for="kit_sync_company_email">
                            <input type="checkbox"
                                class="kit-email-sync-toggle__input"
                                name="sync_company_email"
                                id="kit_sync_company_email"
                                value="1"
                                <?php checked($sync_email_default); ?>>
                            <span class="kit-email-sync-toggle__switch" aria-hidden="true">
                                <span class="kit-email-sync-toggle__thumb"></span>
                            </span>
                            <span class="kit-email-sync-toggle__copy">
                                <span class="kit-email-sync-toggle__title"><?php echo esc_html__('Same email as company', '08600'); ?></span>
                                <span class="kit-email-sync-toggle__hint"><?php echo esc_html__('Also fill and save the company Email Address with this value.', '08600'); ?></span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="md:col-span-2 flex flex-wrap items-center justify-between gap-3">
                    <?php if ($is_edit) : ?>
                        <button type="submit"
                            name="action" value="kit_detach_company_customer"
                            class="text-xs font-medium text-red-600 hover:text-red-800 hover:underline bg-transparent border-0 p-0 cursor-pointer"
                            onclick="return confirm('<?php echo esc_js(__('Remove this customer from the company? Their record is kept.', '08600')); ?>');">
                            <?php echo esc_html__('Remove from company', '08600'); ?>
                        </button>
                    <?php else : ?>
                        <span></span>
                    <?php endif; ?>
                    <?php
                    echo KIT_Commons::renderButton(
                        $is_edit ? __('Save customer', '08600') : __('Create customer', '08600'),
                        'primary',
                        'md',
                        [
                            'type' => 'submit',
                            'classes' => 'justify-center',
                        ]
                    );
                    ?>
                </div>
            </form>
            <script>
            (function () {
                var toggle = document.getElementById('kit_sync_company_email');
                var personEmail = document.getElementById('assoc_customer_email');
                var companyEmail = document.getElementById('company_email_address');
                if (!toggle || !personEmail || !companyEmail) return;

                function syncToCompany() {
                    if (!toggle.checked) return;
                    companyEmail.value = personEmail.value;
                    companyEmail.dispatchEvent(new Event('input', { bubbles: true }));
                    companyEmail.dispatchEvent(new Event('change', { bubbles: true }));
                }

                toggle.addEventListener('change', syncToCompany);
                personEmail.addEventListener('input', syncToCompany);
                personEmail.addEventListener('change', syncToCompany);
                if (toggle.checked) syncToCompany();
            })();
            </script>

            <?php if ($is_edit && count($linked) > 1) : ?>
                <div class="mt-4 pt-3 border-t border-gray-100">
                    <p class="text-xs text-gray-500 mb-2">
                        <?php echo esc_html__('Additional people linked to this company:', '08600'); ?>
                    </p>
                    <ul class="space-y-1">
                        <?php foreach (array_slice($linked, 1) as $extra) :
                            $extra_id = (int) ($extra['cust_id'] ?? 0);
                            $extra_name = trim(trim((string) ($extra['name'] ?? '')) . ' ' . trim((string) ($extra['surname'] ?? '')));
                            ?>
                            <li class="flex items-center justify-between gap-2 text-sm">
                                <span class="text-gray-700">
                                    <?php echo esc_html($extra_name !== '' ? $extra_name : __('(Unnamed)', '08600')); ?>
                                    <span class="text-xs text-gray-400">#<?php echo esc_html((string) $extra_id); ?></span>
                                </span>
                                <form method="post" action="<?php echo esc_url($action_url); ?>" class="inline m-0">
                                    <input type="hidden" name="action" value="kit_detach_company_customer">
                                    <input type="hidden" name="kit_company_customer_nonce" value="<?php echo esc_attr($nonce); ?>">
                                    <input type="hidden" name="company_id" value="<?php echo esc_attr((string) $company_id); ?>">
                                    <input type="hidden" name="cust_id" value="<?php echo esc_attr((string) $extra_id); ?>">
                                    <button type="submit" class="text-xs font-medium text-red-600 hover:underline bg-transparent border-0 p-0 cursor-pointer"
                                        onclick="return confirm('<?php echo esc_js(__('Remove this customer from the company?', '08600')); ?>');">
                                        <?php echo esc_html__('Remove', '08600'); ?>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * View company detail + linked waybills (customers dashboard ?view_company=).
 */
function kit_view_company_detail(int $company_id): void
{
    global $wpdb;

    $company = KIT_Company_Customers::find_by_company_id($company_id);
    if (!$company) {
        echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Company not found.', '08600') . '</p></div></div>';
        return;
    }

    $country_id = (int) ($company['country_id'] ?? 0);
    $city_id = (int) ($company['city_id'] ?? 0);
    $country_name = '';
    $city_name = '';
    if ($country_id > 0) {
        $country_name = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT country_name FROM {$wpdb->prefix}kit_operating_countries WHERE id = %d LIMIT 1",
            $country_id
        ));
    }
    if ($city_id > 0) {
        $city_name = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT city_name FROM {$wpdb->prefix}kit_operating_cities WHERE id = %d LIMIT 1",
            $city_id
        ));
    }

    $waybills_t = $wpdb->prefix . 'kit_waybills';
    $waybill_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, waybill_no, description, product_invoice_number, product_invoice_amount,
                    product_invoice_amount AS total, status, approval, created_at, company_id, customer_id
             FROM {$waybills_t}
             WHERE company_id = %d
             ORDER BY created_at DESC, id DESC",
            $company_id
        ),
        ARRAY_A
    ) ?: [];
    $waybills = $waybill_rows;
    $waybill_count = count($waybills);

    $company_name = trim((string) ($company['company_name'] ?? '')) ?: __('(Unnamed company)', '08600');
    $cell = trim((string) ($company['cell'] ?? ''));
    $telephone = trim((string) ($company['telephone'] ?? ''));
    $email = trim((string) ($company['email_address'] ?? ''));
    $address = trim((string) ($company['address'] ?? ''));
    $vat = trim((string) ($company['vat_number'] ?? ''));
    $phone = $cell !== '' ? $cell : $telephone;
    $location_line = trim(implode(', ', array_filter([$city_name, $country_name], static function ($part) {
        return trim((string) $part) !== '';
    })));
    $profile_fields = [
        'phone' => $phone,
        'email' => $email,
        'address' => $address,
        'location' => $location_line,
        'vat' => $vat,
    ];
    $filled_count = count(array_filter($profile_fields, static function ($value) {
        return trim((string) $value) !== '';
    }));
    $profile_incomplete = $filled_count === 0;
    $fmt_value = static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '<span class="text-gray-400 font-normal">' . esc_html__('Not provided', '08600') . '</span>';
        }
        return esc_html($value);
    };
    $back_url = admin_url('admin.php?page=08600-customers');
    $edit_url = admin_url('admin.php?page=08600-customers&edit_company=' . $company_id);
    $summary_url = plugins_url('pdf-summary.php', dirname(dirname(__FILE__)));
    $building_icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />';

    $header_actions = KIT_Commons::renderButton(
        __('Back to list', '08600'),
        'secondary',
        'md',
        [
            'href' => $back_url,
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />',
            'iconPosition' => 'left',
            'noLoading' => true,
        ]
    ) . KIT_Commons::renderButton(
        __('Edit Company', '08600'),
        'secondary',
        'md',
        [
            'href' => $edit_url,
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />',
            'iconPosition' => 'left',
            'noLoading' => true,
            'classes' => 'ml-2',
        ]
    );
    ?>
    <div class="wrap customers-page kit-customer-detail-view kit-company-detail-view">
        <div class="<?php echo esc_attr(KIT_Commons::containerClasses()); ?> min-w-0 max-w-full">
            <?php
            echo KIT_Commons::showingHeader([
                'title'   => __('Company Details', '08600'),
                'desc'    => sprintf(
                    /* translators: %s: company name */
                    esc_html__('Viewing %s. Companies are stored separately from individual customers.', '08600'),
                    esc_html($company_name)
                ),
                'icon'    => $building_icon,
                'content' => $header_actions,
            ]);
            ?>
            <hr class="wp-header-end">

            <div class="kit-customer-detail-grid grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6">
                <div class="kit-customer-detail-info min-w-0 bg-white border border-gray-200 rounded-lg p-3 md:p-4 mb-2 lg:mb-6">
                    <?php
                    echo KIT_Commons::prettyHeading([
                        'words' => __('Company Information', '08600'),
                        'icon' => $building_icon,
                        'size' => 'sm',
                        'tag' => 'h2',
                        'classes' => 'mb-3',
                    ]);
                    ?>

                    <div class="mb-4 pb-3 border-b border-gray-100">
                        <div class="text-lg font-semibold text-gray-900 break-words"><?php echo esc_html($company_name); ?></div>
                        <div class="mt-1 text-xs text-gray-500">
                            <?php
                            echo esc_html(sprintf(__('ID %d', '08600'), $company_id));
                            echo ' · ';
                            echo esc_html(sprintf(
                                /* translators: %d: waybill count */
                                _n('%d waybill', '%d waybills', $waybill_count, '08600'),
                                $waybill_count
                            ));
                            ?>
                        </div>
                    </div>

                    <?php if ($profile_incomplete) : ?>
                        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                            <?php echo esc_html__('No contact or location details yet. Add them so invoices and deliveries have the right company info.', '08600'); ?>
                        </div>
                    <?php endif; ?>

                    <div class="space-y-3">
                        <div class="flex justify-between items-start gap-3">
                            <span class="text-sm text-gray-700 shrink-0"><?php echo esc_html__('Phone', '08600'); ?></span>
                            <span class="text-sm font-semibold text-gray-900 text-right break-words min-w-0">
                                <?php
                                if ($phone !== '') {
                                    echo '<a class="text-blue-600 hover:text-blue-800 hover:underline" href="tel:' . esc_attr(preg_replace('/\s+/', '', $phone)) . '">' . esc_html($phone) . '</a>';
                                } else {
                                    echo $fmt_value('');
                                }
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-start gap-3">
                            <span class="text-sm text-gray-700 shrink-0"><?php echo esc_html__('Email', '08600'); ?></span>
                            <span class="text-sm font-semibold text-gray-900 text-right break-words min-w-0">
                                <?php
                                if ($email !== '') {
                                    echo '<a class="text-blue-600 hover:text-blue-800 hover:underline" href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
                                } else {
                                    echo $fmt_value('');
                                }
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-start gap-3">
                            <span class="text-sm text-gray-700 shrink-0"><?php echo esc_html__('Address', '08600'); ?></span>
                            <span class="text-sm font-semibold text-gray-900 text-right break-words min-w-0"><?php echo $fmt_value($address); ?></span>
                        </div>
                        <div class="flex justify-between items-start gap-3">
                            <span class="text-sm text-gray-700 shrink-0"><?php echo esc_html__('Location', '08600'); ?></span>
                            <span class="text-sm font-semibold text-gray-900 text-right break-words min-w-0"><?php echo $fmt_value($location_line); ?></span>
                        </div>
                        <div class="flex justify-between items-start gap-3">
                            <span class="text-sm text-gray-700 shrink-0"><?php echo esc_html__('VAT Number', '08600'); ?></span>
                            <span class="text-sm font-semibold text-gray-900 text-right break-words min-w-0"><?php echo $fmt_value($vat); ?></span>
                        </div>
                    </div>

                    <div class="mt-5 pt-3 border-t border-gray-200 flex flex-col sm:flex-row sm:justify-end gap-2">
                        <?php
                        echo KIT_Commons::renderButton(
                            $profile_incomplete ? __('Complete profile', '08600') : __('Edit Company', '08600'),
                            $profile_incomplete ? 'primary' : 'ghost-primary',
                            'md',
                            [
                                'href' => $edit_url,
                                'classes' => 'w-full sm:w-auto justify-center',
                            ]
                        );
                        ?>
                    </div>
                </div>

                <div class="kit-customer-detail-waybills min-w-0 max-w-full">
                    <?php
                    if (class_exists('KIT_Unified_Table')) {
                        $columns = KIT_Commons::getColumns([
                            'waybill_no',
                            'total' => [
                                'header_class' => 'text-right whitespace-nowrap kit-col-hide-sm',
                                'cell_class' => 'text-right text-sm font-medium whitespace-nowrap kit-col-hide-sm',
                            ],
                            'created_at' => [
                                'label' => 'Created',
                                'header_class' => 'text-left whitespace-nowrap kit-col-hide-sm',
                                'cell_class' => 'text-left text-xs text-gray-600 whitespace-nowrap kit-col-hide-sm',
                                'callback' => static function ($value) {
                                    if (empty($value)) {
                                        return '—';
                                    }
                                    $timestamp = strtotime((string) $value);
                                    if ($timestamp) {
                                        return esc_html(function_exists('date_i18n') ? date_i18n('M j, Y', $timestamp) : date('M j, Y', $timestamp));
                                    }
                                    return esc_html((string) $value);
                                },
                            ],
                        ]);
                        $actions = [
                            [
                                'label' => 'View',
                                'title' => 'View waybill',
                                'href' => admin_url('admin.php?page=08600-Waybill-view&waybill_id={id}&waybill_atts=view_waybill'),
                                'class' => 'text-xs font-medium text-blue-600 hover:text-blue-800 hover:underline',
                            ],
                            [
                                'label' => 'Download',
                                'title' => 'Download PDF invoice',
                                'target' => '_blank',
                                'href' => $summary_url . '?waybill_no={waybill_no}',
                                'class' => 'text-xs font-medium text-green-600 hover:text-green-800 hover:underline',
                            ],
                        ];
                        echo KIT_Unified_Table::infinite($waybills, $columns, [
                            'title' => 'Waybills (' . $waybill_count . ')',
                            'actions' => $actions,
                            'searchable' => true,
                            'sortable' => true,
                            'empty_message' => __('No waybills found for this company', '08600'),
                        ]);
                    } else {
                        echo '<p class="text-sm text-gray-600">' . esc_html(sprintf(__('%d waybill(s) linked to this company.', '08600'), $waybill_count)) . '</p>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

if (did_action('plugins_loaded')) {
    KIT_Company_Customers::init();
} else {
    add_action('plugins_loaded', ['KIT_Company_Customers', 'init']);
}
