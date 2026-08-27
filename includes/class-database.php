<?php

(!defined('ABSPATH')) ?? exit;

class Database
{
    public static function create_customers_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            cust_id MEDIUMINT(10) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            surname VARCHAR(255) NOT NULL,
            cell VARCHAR(20) NULL,
            telephone VARCHAR(20) NULL,
            email_address VARCHAR(255) NULL,
            country_id INT UNSIGNED NULL,
            city_id INT UNSIGNED NULL,
            vat_number VARCHAR(50) NULL,
            address TEXT NULL,
            company_id MEDIUMINT(10) UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cust_id (cust_id),
            KEY idx_customer_company_id (company_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Company customers (businesses) — separate from individual kit_customers.
     */
    public static function create_company_customers_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_company_customers';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id MEDIUMINT(10) UNSIGNED NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            cell VARCHAR(20) NULL,
            telephone VARCHAR(20) NULL,
            email_address VARCHAR(255) NULL,
            country_id INT UNSIGNED NULL,
            city_id INT UNSIGNED NULL,
            vat_number VARCHAR(50) NULL,
            address TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY company_id (company_id),
            KEY company_name (company_name)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Person → company link: kit_customers.company_id → kit_company_customers.company_id.
     */
    public static function ensure_customer_company_id_column(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        if ((int) $table_exists === 0) {
            return;
        }

        $col_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = %s AND table_name = %s AND column_name = 'company_id'",
            DB_NAME,
            $table_name
        ));
        if ((int) $col_exists > 0) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE {$table_name}
             ADD COLUMN company_id MEDIUMINT(10) UNSIGNED NULL DEFAULT NULL,
             ADD INDEX idx_customer_company_id (company_id)"
        );
    }

    /** @var bool|null */
    private static $customers_have_company_name_column = null;

    public static function customers_have_company_name_column(): bool
    {
        global $wpdb;
        if (self::$customers_have_company_name_column !== null) {
            return self::$customers_have_company_name_column;
        }
        self::$customers_have_company_name_column = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = %s AND table_name = %s AND column_name = 'company_name'",
            DB_NAME,
            $wpdb->prefix . 'kit_customers'
        ));
        return self::$customers_have_company_name_column;
    }

    /**
     * Drop kit_customers.company_name after backfilling company_id from leftover labels.
     * Company names live on kit_company_customers only.
     */
    public static function drop_customer_company_name_column(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        if ((int) $table_exists === 0 || !self::customers_have_company_name_column()) {
            return;
        }

        $file = dirname(__FILE__) . '/customers/company-customers-functions.php';
        if (file_exists($file)) {
            require_once $file;
        }
        if (class_exists('KIT_Company_Customers')) {
            $rows = $wpdb->get_results(
                "SELECT cust_id, company_name, company_id FROM {$table_name}
                 WHERE TRIM(IFNULL(company_name,'')) != ''",
                ARRAY_A
            ) ?: [];
            foreach ($rows as $row) {
                $label = trim((string) ($row['company_name'] ?? ''));
                if ($label === '' || KIT_Company_Customers::is_placeholder_company($label)) {
                    continue;
                }
                $cid = (int) ($row['company_id'] ?? 0);
                if ($cid <= 0) {
                    $cid = (int) KIT_Company_Customers::ensure_company($label);
                }
                if ($cid > 0 && (int) ($row['company_id'] ?? 0) !== $cid) {
                    $wpdb->update(
                        $table_name,
                        ['company_id' => $cid],
                        ['cust_id' => (int) $row['cust_id']],
                        ['%d'],
                        ['%d']
                    );
                }
            }
        }

        $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN company_name");
        self::$customers_have_company_name_column = false;
    }

    /**
     * Add nullable company_id on kit_waybills (company party FK).
     */
    public static function ensure_waybill_company_id_column()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        if ((int) $table_exists === 0) {
            return;
        }

        $col_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = %s AND table_name = %s AND column_name = 'company_id'",
            DB_NAME,
            $table_name
        ));
        if ((int) $col_exists > 0) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE {$table_name}
             ADD COLUMN company_id MEDIUMINT(10) UNSIGNED NULL DEFAULT NULL AFTER customer_id,
             ADD INDEX idx_company_id (company_id)"
        );
    }

    public static function create_services_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_services';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            image VARCHAR(255),
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        if (function_exists('kit_seed_default_services')) {
            kit_seed_default_services();
        }
    }

    public static function create_deliveries_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_deliveries';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            delivery_reference VARCHAR(100) NOT NULL,
            direction_id INT UNSIGNED NOT NULL,
            destination_city_id INT UNSIGNED NOT NULL,
            dispatch_date DATE,
            truck_number VARCHAR(50),
            status ENUM('scheduled', 'in_transit', 'delivered', 'unconfirmed') DEFAULT 'scheduled',
            created_by INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
            ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Seed default cross-border deliveries removed - using Excel seed data instead
    }

    public static function create_drivers_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_drivers';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(20) NULL,
            email VARCHAR(255) NULL,
            license_number VARCHAR(50) NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_active (is_active)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Add driver_id column to deliveries table and migrate truck_number data
     */
    public static function update_deliveries_table_for_drivers()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_deliveries';
        $drivers_table = $wpdb->prefix . 'kit_drivers';

        // Check if driver_id column exists
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'driver_id'",
                DB_NAME,
                $table_name
            )
        );

        if (empty($column_exists)) {
            // Add driver_id column
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN driver_id INT UNSIGNED NULL AFTER truck_number");
            
            // Add foreign key constraint
            $fk_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                    WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'fk_deliveries_driver'",
                    DB_NAME,
                    $table_name
                )
            );

            if (empty($fk_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD CONSTRAINT fk_deliveries_driver 
                    FOREIGN KEY (driver_id) REFERENCES $drivers_table(id) ON DELETE SET NULL");
            }

            // Note: We keep truck_number for backward compatibility but it won't be used in the UI
        }
    }

    public static function create_waybills_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        parcel_id INT UNSIGNED NULL,
        description TEXT NULL,
        direction_id INT UNSIGNED NOT NULL,
        city_id INT UNSIGNED NULL,
        delivery_id INT UNSIGNED NOT NULL,
        customer_id MEDIUMINT(10) UNSIGNED NOT NULL DEFAULT 0,
        company_id MEDIUMINT(10) UNSIGNED NULL DEFAULT NULL,
        approval ENUM('approved','pending','cancelled', 'rejected', 'completed') DEFAULT 'pending',
        approval_userid INT UNSIGNED NULL,
        waybill_no VARCHAR(20) NOT NULL,
        product_invoice_number VARCHAR(50),
        product_invoice_amount DECIMAL(10,2),
        waybill_items_total DECIMAL(10,2),
        misc_total DECIMAL(10,2) DEFAULT 0.00,
        border_clearing_total DECIMAL(10,2) DEFAULT 0.00,
        sad500_amount DECIMAL(10,2) DEFAULT 0.00,
        sadc_amount DECIMAL(10,2) DEFAULT 0.00,
        international_price_rands DECIMAL(10,2) DEFAULT 0.00,
        item_length DECIMAL(10,2),
        item_width DECIMAL(10,2),
        item_height DECIMAL(10,2),
        total_mass_kg DECIMAL(10,2),
        total_volume DECIMAL(10,2),
        mass_charge DECIMAL(10,2),
        volume_charge DECIMAL(10,2),
        charge_basis VARCHAR(20),
        vat_include VARCHAR(50),
        warehouse BOOLEAN DEFAULT FALSE,
        miscellaneous LONGTEXT,
        include_sad500 TINYINT(1) DEFAULT 0,
        include_sadc TINYINT(1) DEFAULT 0,
        return_load TINYINT(1) DEFAULT 0,
        tracking_number VARCHAR(50),
        qr_code_data LONGTEXT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        last_updated_by BIGINT UNSIGNED NOT NULL,
        status ENUM('pending', 'quoted', 'paid', 'assigned', 'scheduled', 'unconfirmed', 'in_transit', 'shipped', 'delivered', 'completed', 'invoiced', 'rejected') DEFAULT 'pending',
        status_userid BIGINT UNSIGNED DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX (status),
        INDEX (customer_id),
        INDEX idx_company_id (company_id),
        INDEX idx_parcel_id (parcel_id),
        UNIQUE KEY waybill_no (waybill_no),
        UNIQUE KEY product_invoice_number (product_invoice_number)
    ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }


    /**
     * Extend kit_waybills.status ENUM with delivery-aligned logistics values.
     */
    public static function ensure_waybill_logistics_status_values()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        if ((int) $table_exists === 0) {
            return;
        }

        $column_type = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
            DB_NAME,
            $table_name
        ));

        if (! is_string($column_type) || strpos($column_type, 'scheduled') !== false) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE {$table_name} MODIFY COLUMN status
             ENUM('pending', 'quoted', 'paid', 'assigned', 'scheduled', 'unconfirmed', 'in_transit', 'shipped', 'delivered', 'completed', 'invoiced', 'rejected')
             DEFAULT 'pending'"
        );
    }

    /**
     * Widen kit_waybills.total_volume so the sheet's real precision survives.
     *
     * The Google Sheet is the source of truth (client ruling, 2026-08-05).
     * total_volume was DECIMAL(10,2), but volumes are cubic metres — the sheet
     * carries values like 0.08649 and 46.99898103. On the live data 1054 of
     * 1132 rows lost precision on write, and 0.08649 was being stored as 0.09.
     *
     * That is not cosmetic: volume_charge is volume x rate, so a volume rounded
     * to 2dp produces a wrong charge, which is why sheet and DB charges diverge.
     *
     * DECIMAL(16,8) keeps 8 decimals — the most the live sheet actually uses —
     * and widening is lossless for the values already stored.
     */
    /**
     * Widen kit_waybills numeric columns so the sheet's real precision survives.
     *
     * The Google Sheet is the source of truth (client ruling, 2026-08-05). These
     * columns were DECIMAL(10,2), but volumes are cubic metres and the sheet
     * carries values like 0.08649 and 46.99898103. On the live data 1054 of 1132
     * rows lost precision on write, and 0.08649 was stored as 0.09.
     *
     * That is not cosmetic: volume_charge is volume x rate, so a volume rounded
     * to 2dp produces a wrong charge — which is exactly why sheet and DB charges
     * diverged.
     *
     * Each column is checked and altered independently. An earlier version gated
     * every ALTER on total_volume alone, so once that one column was widened the
     * others were skipped forever and stayed at DECIMAL(10,2).
     *
     * Widening is lossless for values already stored.
     */
    public static function ensure_waybill_volume_precision()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';

        // The CURRENT connection's database, not the DB_NAME constant — a
        // migration that inspects one database while altering another silently
        // does nothing.
        $schema = $wpdb->dbname ?: DB_NAME;

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            $schema,
            $table_name
        ));
        if ((int) $table_exists === 0) {
            return;
        }

        $targets = [
            'total_volume'  => 'DECIMAL(16,8) NULL',
            'volume_charge' => 'DECIMAL(16,8) NULL',
            'mass_charge'   => 'DECIMAL(16,8) NULL',
            'sad500_amount' => 'DECIMAL(16,8) NULL DEFAULT 0',
            'sadc_amount'   => 'DECIMAL(16,8) NULL DEFAULT 0',
        ];

        foreach ($targets as $column => $definition) {
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $schema,
                $table_name,
                $column
            ));
            if (!is_string($current) || strpos($current, '16,8') !== false) {
                continue;
            }
            $wpdb->query("ALTER TABLE {$table_name} MODIFY COLUMN `{$column}` {$definition}");
        }
    }

    /**
     * Ensure qr_code_data column can store large JSON strings
     */
    private static function ensure_qr_code_data_longtext()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';

        // Verify table exists first
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        if ((int)$table_exists === 0) {
            return;
        }

        // Fetch column type
        $column_info = $wpdb->get_row($wpdb->prepare(
            "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'qr_code_data'",
            DB_NAME,
            $table_name
        ));

        // If missing, add as LONGTEXT; if VARCHAR or TEXT, alter to LONGTEXT
        if (empty($column_info)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN qr_code_data LONGTEXT NULL AFTER tracking_number");
            return;
        }

        $data_type = isset($column_info->DATA_TYPE) ? strtolower($column_info->DATA_TYPE) : '';
        if ($data_type !== 'longtext') {
            $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN qr_code_data LONGTEXT NULL");
        }
    }


    public static function create_waybill_items_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybill_items';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            waybillno VARCHAR(20) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            unit_mass DECIMAL(10,2) DEFAULT 0,
            unit_volume DECIMAL(10,2) DEFAULT 0,
            total_price DECIMAL(10,2) DEFAULT 0,
            client_invoice VARCHAR(50) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }


    public static function create_invoices_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_invoices';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            waybill_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            invoice_number VARCHAR(50) NOT NULL,
            invoice_date DATE NOT NULL,
            due_date DATE NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL,
            vat_amount DECIMAL(10,2) DEFAULT 0,
            total DECIMAL(10,2) NOT NULL,
            status ENUM('unpaid', 'paid', 'overdue') DEFAULT 'unpaid',
            created_by INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_updated_by INT NULL,
            last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX (status)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    public static function create_quotations_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_quotations';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            delivery_id INT UNSIGNED NOT NULL,
            waybill_id INT UNSIGNED NOT NULL,
            waybillNo INT UNSIGNED NOT NULL,
            customer_id MEDIUMINT(10) UNSIGNED NOT NULL,
            subtotal DECIMAL(10,2) DEFAULT 0,
            vat_amount DECIMAL(10,2) DEFAULT 0,
            total DECIMAL(10,2) DEFAULT 0,
            quotation_notes TEXT,
            status ENUM('draft','sent','accepted','rejected') DEFAULT 'draft',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_updated_by BIGINT UNSIGNED NULL,
            last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function create_booking_requests_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_booking_requests';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id MEDIUMINT(10) UNSIGNED NOT NULL,
            origin_country_id INT UNSIGNED NULL,
            origin_city_id INT UNSIGNED NULL,
            destination_country_id INT UNSIGNED NULL,
            destination_city_id INT UNSIGNED NULL,
            parcel_count INT UNSIGNED DEFAULT 1,
            weight_kg DECIMAL(10,2) NULL,
            length_cm DECIMAL(10,2) NULL,
            width_cm DECIMAL(10,2) NULL,
            height_cm DECIMAL(10,2) NULL,
            service_note VARCHAR(255) NULL,
            notes TEXT,
            status VARCHAR(20) DEFAULT 'pending',
            waybill_id INT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX customer_id (customer_id),
            INDEX status (status)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function create_operating_countries_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kit_operating_countries';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            country_name VARCHAR(100) NOT NULL,
            country_code VARCHAR(10) NULL,
            charge_group TINYINT(1),
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY country_code (country_code)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Prepopulate common operating countries
        $countries = [
            ['South Africa', 'ZA', 1, 1],
            ['Tanzania', 'TZ', 1, 2],
            ['Botswana', 'BW', 0, 2],
            ['Zimbabwe', 'ZW', 0, 2],
            ['Mozambique', 'MZ', 0, 2],
            ['Zambia', 'ZM', 0, 2],
            ['Namibia', 'NA', 0, 2],
            ['Malawi', 'MW', 0, 2],
            ['Eswatini', 'SZ', 0, 2],
            ['Lesotho', 'LS', 0, 2],
            ['Uganda', 'UG', 0, 2],
        ];

        foreach ($countries as $country) {
            list($name, $code, $activecode, $charge_group) = $country;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE country_code = %s",
                $code
            ));

            if (!$exists) {
                $wpdb->insert($table_name, [
                    'country_name' => $name,
                    'country_code' => $code,
                    'charge_group' => $charge_group,
                    'is_active' => $activecode,
                    'created_at' => current_time('mysql'),
                ], [
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%s'
                ]);
            }
        }
    }
    public static function create_operating_cities_table()
    {
        global $wpdb;

        $city_table = $wpdb->prefix . 'kit_operating_cities';
        $country_table = $wpdb->prefix . 'kit_operating_countries';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $city_table (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            country_id INT UNSIGNED NOT NULL,
            city_name VARCHAR(100) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $cities = [
            'ZA' => ['Johannesburg', 'Cape Town', 'Durban', 'Pretoria', 'Port Elizabeth'],
            'TZ' => ['Dar es Salaam', 'Dodoma', 'Arusha', 'Mbeya', 'Mwanza', 'Iringa', 'Kilombero', 'Mafinga', 'Makambako', 'Masifio', 'Mfundi', 'Mikumi', 'Morogoro', 'Moshi', 'Pemba', 'Sao Hill', 'Sumbawanga', 'Tanga', 'Zanzibar', 'Gombe Kinshasa'],
            'BW' => ['Gaborone', 'Francistown', 'Maun', 'Molepolole', 'Serowe'],
            'ZW' => ['Harare', 'Bulawayo', 'Mutare', 'Gweru', 'Kwekwe'],
            'MZ' => ['Maputo', 'Matola', 'Beira', 'Nampula', 'Chimoio'],
            'ZM' => ['Lusaka', 'Kitwe', 'Ndola', 'Livingstone', 'Chingola'],
            'NA' => ['Windhoek', 'Swakopmund', 'Walvis Bay', 'Otjiwarongo', 'Rundu'],
            'MW' => ['Lilongwe', 'Blantyre', 'Mzuzu', 'Zomba', 'Kasungu'],
            'SZ' => ['Mbabane', 'Manzini', 'Lobamba', 'Siteki', 'Piggs Peak'],
            'LS' => ['Maseru', 'Teyateyaneng', 'Leribe', 'Maputsoe', 'Mohales Hoek'],
            'UG' => ['Kampala']
        ];

        foreach ($cities as $country_code => $city_list) {
            $country_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $country_table WHERE country_code = %s",
                $country_code
            ));

            if ($country_id) {
                foreach ($city_list as $city) {
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $city_table WHERE city_name = %s AND country_id = %d",
                        $city,
                        $country_id
                    ));

                    if (!$exists) {
                        $wpdb->insert($city_table, [
                            'country_id' => $country_id,
                            'city_name' => $city,
                            'is_active' => 1,
                            'created_at' => current_time('mysql')
                        ], [
                            '%d',
                            '%s',
                            '%d',
                            '%s'
                        ]);
                    }
                }
            }
        }
    }
    public static function create_shipping_directions_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_shipping_directions';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            origin_country_id INT UNSIGNED NOT NULL,
            destination_country_id INT UNSIGNED NOT NULL,
            description TEXT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY direction_pair (origin_country_id, destination_country_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Insert default active routes and a system delivery row for warehouse items
        $wpdb->insert($table_name, [
            'origin_country_id' => 1,
            'destination_country_id' => 1,
            'description' => 'Warehoused items',
            'is_active' => 1,
            'created_at' => current_time('mysql')
        ]);
        $sa_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}kit_operating_countries WHERE country_name = 'South Africa'");
        $tanzania_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}kit_operating_countries WHERE country_name = 'Tanzania'");

        if ($sa_id && $tanzania_id) {
            $existing_route_1 = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}kit_shipping_directions WHERE origin_country_id = %d AND destination_country_id = %d",
                $sa_id,
                $tanzania_id
            ));

            $existing_route_2 = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}kit_shipping_directions WHERE origin_country_id = %d AND destination_country_id = %d",
                $tanzania_id,
                $sa_id
            ));

            if (!$existing_route_1) {
                $wpdb->insert(
                    "{$wpdb->prefix}kit_shipping_directions",
                    [
                        'origin_country_id' => $sa_id,
                        'destination_country_id' => $tanzania_id,
                        'description' => 'South Africa to Tanzania',
                        'is_active' => 1
                    ]
                );
            }

            if (!$existing_route_2) {
                $wpdb->insert(
                    "{$wpdb->prefix}kit_shipping_directions",
                    [
                        'origin_country_id' => $tanzania_id,
                        'destination_country_id' => $sa_id,
                        'description' => 'Tanzania to South Africa',
                        'is_active' => 1
                    ]
                );
            }
        }
    }
    public static function create_shipping_rate_types_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_shipping_rate_types';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            type_name VARCHAR(50) NOT NULL,
            description TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY type_name (type_name)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Insert default rate types
        $rate_types = ['Consolidated', 'Dedicated'];
        foreach ($rate_types as $type) {
            $wpdb->insert($table_name, [
                'type_name' => $type,
                'description' => $type . ' shipping rate type',
                'created_at' => current_time('mysql')
            ]);
        }
    }
    public static function create_shipping_rates_mass_table()
    {
        global $wpdb;
        $table_name =  $wpdb->prefix . 'kit_shipping_rates_mass';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            direction_id INT UNSIGNED NOT NULL,
            rate_type_id INT UNSIGNED NOT NULL,
            min_weight DECIMAL(10,2) NOT NULL,
            max_weight DECIMAL(10,2) NOT NULL,
            rate_per_kg DECIMAL(10,2) NOT NULL,
            effective_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX weight_range (min_weight, max_weight)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $wpdb->query("
        INSERT INTO $table_name (direction_id, rate_type_id, min_weight, max_weight, rate_per_kg)
        VALUES 
        (1, 1, 10, 499, 40),
        (1, 1, 500, 999, 35),
        (1, 1, 1000, 2499, 30),
        (1, 1, 2500, 4999, 25),
        (1, 1, 5000, 7499, 20),
        (1, 1, 7500, 9999, 17.50),
        (1, 1, 10000, 999999.99, 15),
        (2, 1, 10, 499, 30),
        (2, 1, 500, 999, 25),
        (2, 1, 1000, 2499, 20),
        (2, 1, 2500, 4999, 15),
        (2, 1, 5000, 7499, 12.50),
        (2, 1, 7500, 9999, 10),
        (2, 1, 10000, 999999.99, 7.50)");
    }
    public static function create_shipping_rates_volume_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_shipping_rates_volume';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            direction_id INT UNSIGNED NOT NULL,
            rate_type_id INT UNSIGNED NOT NULL,
            min_volume DECIMAL(10,2) NOT NULL,
            max_volume DECIMAL(10,2) NOT NULL,
            rate_per_m3 DECIMAL(10,2) NOT NULL,
            effective_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX volume_range (min_volume, max_volume)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $wpdb->query("
        INSERT INTO $table_name
        (`direction_id`, `rate_type_id`, `min_volume`, `max_volume`, `rate_per_m3`, `is_active`) 
        VALUES
        (1, 1, 0, 1, 7500, 1),
        (1, 1, 1, 2, 7000, 1),
        (1, 1, 2, 5, 6500, 1),
        (1, 1, 5, 10, 5500, 1),
        (1, 1, 10, 15, 5000, 1),
        (1, 1, 15, 20, 4500, 1),
        (1, 1, 20, 30, 4000, 1),
        (1, 1, 30, 9999.99, 3500, 1),
        (2, 1, 0, 1, 4000, 1),
        (2, 1, 1, 2, 3500, 1),
        (2, 1, 2, 5, 3000, 1),
        (2, 1, 5, 10, 2500, 1),
        (2, 1, 10, 15, 2000, 1),
        (2, 1, 15, 20, 2000, 1),
        (2, 1, 20, 30, 2000, 1),
        (2, 1, 30, 9999.99, 2000, 1)
        ");
    }
    public static function create_shipping_dedicated_truck_rates_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_shipping_dedicated_truck_rates';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            direction_id INT UNSIGNED NOT NULL,
            truck_type VARCHAR(50) NOT NULL,
            capacity_kg DECIMAL(10,2) NOT NULL,
            flat_rate DECIMAL(10,2) NOT NULL,
            effective_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX truck_type (truck_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $wpdb->query("
        INSERT INTO $table_name 
        (direction_id, truck_type, capacity_kg, flat_rate) VALUES
        (1, '8 Ton Truck', 7500, 130000),
        (1, '15 Ton Truck', 14500, 155000),
        (1, '30 Ton Truck', 28000, 220000),
        (2, '8 Ton Truck', 7500, 78000),
        (2, '15 Ton Truck', 14500, 93000),
        (2, '30 Ton Truck', 28000, 132000);");
    }
    public static function create_discounts_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_discounts';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            discount_name VARCHAR(100),
            discount_type ENUM('percentage', 'fixed') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            condition_json TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function create_company_details_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_company_details';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_name VARCHAR(255) NOT NULL,
            company_address TEXT NULL,
            company_email VARCHAR(255) NULL,
            company_phone VARCHAR(50) NULL,
            company_website VARCHAR(255) NULL,
            company_registration VARCHAR(100) NULL,
            company_vat_number VARCHAR(100) NULL,
            bank_name VARCHAR(100) NULL,
            account_number VARCHAR(100) NULL,
            branch_code VARCHAR(50) NULL,
            account_type VARCHAR(50) NULL,
            account_holder VARCHAR(150) NULL,
            swift_code VARCHAR(100) NULL,
            iban VARCHAR(100) NULL,
            vat_percentage DECIMAL(5,2) DEFAULT 15.00,
            sadc_charge DECIMAL(10,2) DEFAULT 0.00,
            sad500_charge DECIMAL(10,2) DEFAULT 0.00,
            international_price DECIMAL(10,2) DEFAULT 100.00,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Ensure there is at least one row (seed defaults only when empty — do not overwrite edits)
        $exists = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        if (!$exists) {
            $company_name = get_bloginfo('name');
            // Ensure we have a valid string value, not null
            if (empty($company_name) || $company_name === null) {
                $company_name = '08600 Africa';
            }
            $insert = [
                'company_name' => $company_name,
            ];
            if (class_exists('KIT_Company')) {
                $insert = array_merge($insert, KIT_Company::hardcoded_identity());
            }
            $wpdb->insert($table_name, $insert);
        }
    }

    /**
     * Drop legacy foreign key constraints that were part of early schema versions.
     * Newer migrations manage relationships at application level, so FK enforcement
     * causes insert/update failures when related records are pruned.
     */
    public static function drop_legacy_foreign_keys()
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;

        global $wpdb;

        if (!isset($wpdb) || !defined('DB_NAME')) {
            return;
        }

        $tables = [
            'kit_waybill_items',
            'kit_waybills',
            'kit_quotations',
            'kit_invoices',
            'kit_deliveries',
            'kit_shipping_rates_mass',
            'kit_shipping_rates_volume',
            'kit_shipping_dedicated_truck_rates',
            'kit_operating_cities',
            'kit_shipping_directions',
        ];

        foreach ($tables as $table_suffix) {
            $table_name = $wpdb->prefix . $table_suffix;

            $constraints = $wpdb->get_col($wpdb->prepare(
                "SELECT CONSTRAINT_NAME
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = %s
                   AND TABLE_NAME = %s
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                DB_NAME,
                $table_name
            ));

            if (empty($constraints)) {
                continue;
            }

            foreach ($constraints as $constraint) {
                $result = $wpdb->query("ALTER TABLE `$table_name` DROP FOREIGN KEY `$constraint`");
                if ($result === false) {
                    self::write_log("Failed to drop foreign key $constraint on $table_name: " . $wpdb->last_error);
                } else {
                    self::write_log("Dropped foreign key $constraint on $table_name");
                }
            }
        }
    }

    /**
     * Write log message directly to file (bypasses error_reporting(0) and error_log issues)
     */
    private static function write_log($message) {
        if (!defined('WP_CONTENT_DIR')) {
            return; // WordPress not fully loaded
        }
        $log_file = WP_CONTENT_DIR . '/deactivation-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message" . PHP_EOL;
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
    }

    public static function delete_table($name)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . $name;
        
        try {
            self::write_log("Attempting to drop table: $table_name");
            
            // Check if table exists first
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            ));
            
            if ($table_exists == 0) {
                self::write_log("Table $table_name does not exist, skipping");
                return true;
            }
            
            $result = $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
            if ($result === false) {
                $error_msg = "Failed to drop table: $table_name - " . $wpdb->last_error;
                self::write_log($error_msg);
                return false;
            }
            self::write_log("Successfully dropped table: $table_name");
            return true;
        } catch (Exception $e) {
            $error_msg = "Error dropping table $table_name: " . $e->getMessage();
            self::write_log($error_msg);
            return false;
        } catch (Error $e) {
            $error_msg = "Fatal error dropping table $table_name: " . $e->getMessage();
            self::write_log($error_msg);
            return false;
        }
    }
    public static function activate()
    {
        self::drop_legacy_foreign_keys();
        self::create_customers_table();
        self::create_company_customers_table();
        self::ensure_customer_company_id_column();
        self::create_services_table();
        self::create_operating_countries_table();
        self::create_operating_cities_table();
        self::create_shipping_directions_table();
        self::create_shipping_rate_types_table();
        self::create_shipping_rates_mass_table();
        self::create_shipping_rates_volume_table();
        self::create_shipping_dedicated_truck_rates_table();
        self::create_drivers_table();
        self::create_deliveries_table();
        self::update_deliveries_table_for_drivers();
        
        // Create company_details table
        self::create_company_details_table();
        self::create_waybills_table();
        self::ensure_waybill_company_id_column();
        self::ensure_qr_code_data_longtext();
        self::ensure_waybill_logistics_status_values();
        self::ensure_waybill_volume_precision();

        self::create_waybill_items_table();
        self::add_product_invoice_number_unique(); // Ensure unique constraint exists
        self::create_quotations_table();
        self::create_booking_requests_table();
        self::create_invoices_table();
        self::create_discounts_table();
        if (class_exists('KIT_Bulk_Action_Log')) {
            KIT_Bulk_Action_Log::ensure_table();
        }
        self::maybe_repair_waybill_vat_flags();
        self::maybe_migrate_company_customers();
        self::drop_customer_company_name_column();
        self::maybe_backfill_waybill_company_ids();
    }

    /**
     * One-time split of business rows out of kit_customers into kit_company_customers.
     */
    public static function maybe_migrate_company_customers(): void
    {
        if (get_option('kit_company_customers_migrated_v1')) {
            return;
        }
        $file = dirname(__FILE__) . '/customers/company-customers-functions.php';
        if (file_exists($file)) {
            require_once $file;
        }
        if (class_exists('KIT_Company_Customers') && method_exists('KIT_Company_Customers', 'migrate_from_kit_customers')) {
            KIT_Company_Customers::migrate_from_kit_customers();
            update_option('kit_company_customers_migrated_v1', 1, false);
        }
    }

    /**
     * One-time: set kit_waybills.company_id from sheet customer_id ↔ company_id.
     */
    public static function maybe_backfill_waybill_company_ids(): void
    {
        if (get_option('kit_waybill_company_id_backfill_v1')) {
            return;
        }
        $file = dirname(__FILE__) . '/customers/company-customers-functions.php';
        if (file_exists($file)) {
            require_once $file;
        }
        if (class_exists('KIT_Company_Customers') && method_exists('KIT_Company_Customers', 'backfill_waybill_company_ids')) {
            KIT_Company_Customers::backfill_waybill_company_ids();
            update_option('kit_waybill_company_id_backfill_v1', 1, false);
        }
    }

    /**
     * Normalize legacy vat_include values and stale border_clearing_total (runs once).
     */
    public static function maybe_repair_waybill_vat_flags(): void
    {
        if (get_option('kit_waybill_vat_repair_v1')) {
            return;
        }
        if (!class_exists('KIT_Waybills') || !method_exists('KIT_Waybills', 'repair_waybill_vat_flags')) {
            $waybills_file = dirname(__FILE__) . '/waybill/waybill-functions.php';
            if (file_exists($waybills_file)) {
                require_once $waybills_file;
            }
        }
        if (!class_exists('KIT_Waybills') || !method_exists('KIT_Waybills', 'repair_waybill_vat_flags')) {
            return;
        }
        KIT_Waybills::repair_waybill_vat_flags();
        update_option('kit_waybill_vat_repair_v1', 1, false);
    }
    
    /**
     * Add unique constraint on product_invoice_number if it doesn't exist
     */
    public static function add_product_invoice_number_unique()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';
        
        // Check if unique constraint exists
        $constraint_exists = $wpdb->get_results($wpdb->prepare(
            "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s 
            AND CONSTRAINT_TYPE = 'UNIQUE' AND CONSTRAINT_NAME = 'product_invoice_number'",
            DB_NAME,
            $table_name
        ));
        
        if (empty($constraint_exists)) {
            // First, make sure there are no duplicate product_invoice_number values
            // Set NULL or unique values for duplicates
            $wpdb->query("
                UPDATE $table_name w1
                INNER JOIN (
                    SELECT product_invoice_number, MIN(id) as min_id
                    FROM $table_name
                    WHERE product_invoice_number IS NOT NULL 
                    AND product_invoice_number != ''
                    GROUP BY product_invoice_number
                    HAVING COUNT(*) > 1
                ) w2 ON w1.product_invoice_number = w2.product_invoice_number
                SET w1.product_invoice_number = CONCAT(w1.product_invoice_number, '-', w1.id)
                WHERE w1.id != w2.min_id
            ");
            
            // Add unique constraint
            $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY product_invoice_number (product_invoice_number)");
        }
    }

    public static function deactivate()
    {
        global $wpdb;
        
        self::write_log('=== DEACTIVATION STARTED ===');
        self::write_log('Database::deactivate() method called');
        
        $dropped_tables = [];
        $failed_tables = [];
        
        try {
            // Temporarily disable FK checks to prevent constraint errors during teardown
            $fk_disabled = $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
            if ($fk_disabled === false) {
                self::write_log('Warning: Failed to disable FOREIGN_KEY_CHECKS: ' . $wpdb->last_error);
            } else {
                self::write_log('FOREIGN_KEY_CHECKS disabled');
            }

            // 1) Drop deepest child tables first (those that reference parents)
            // Waybill items reference waybills
            if (self::delete_table('kit_waybill_items')) {
                $dropped_tables[] = 'kit_waybill_items';
            } else {
                $failed_tables[] = 'kit_waybill_items';
            }
            // Other children that may reference waybills
            if (self::delete_table('kit_quotations')) {
                $dropped_tables[] = 'kit_quotations';
            } else {
                $failed_tables[] = 'kit_quotations';
            }
            if (self::delete_table('kit_booking_requests')) {
                $dropped_tables[] = 'kit_booking_requests';
            } else {
                $failed_tables[] = 'kit_booking_requests';
            }
            if (self::delete_table('kit_invoices')) {
                $dropped_tables[] = 'kit_invoices';
            } else {
                $failed_tables[] = 'kit_invoices';
            }

            // 2) Now drop waybills (referenced by items/quotations/invoices)
            if (self::delete_table('kit_waybills')) {
                $dropped_tables[] = 'kit_waybills';
            } else {
                $failed_tables[] = 'kit_waybills';
            }

            // 3) Drop deliveries (referenced by waybills)
            if (self::delete_table('kit_deliveries')) {
                $dropped_tables[] = 'kit_deliveries';
            } else {
                $failed_tables[] = 'kit_deliveries';
            }

            // 4) Drop rate tables (depend on directions & rate types)
            if (self::delete_table('kit_shipping_rates_mass')) {
                $dropped_tables[] = 'kit_shipping_rates_mass';
            } else {
                $failed_tables[] = 'kit_shipping_rates_mass';
            }
            if (self::delete_table('kit_shipping_rates_volume')) {
                $dropped_tables[] = 'kit_shipping_rates_volume';
            } else {
                $failed_tables[] = 'kit_shipping_rates_volume';
            }
            if (self::delete_table('kit_shipping_dedicated_truck_rates')) {
                $dropped_tables[] = 'kit_shipping_dedicated_truck_rates';
            } else {
                $failed_tables[] = 'kit_shipping_dedicated_truck_rates';
            }

            // 5) Drop direction and rate type tables (parents of above)
            if (self::delete_table('kit_shipping_rate_types')) {
                $dropped_tables[] = 'kit_shipping_rate_types';
            } else {
                $failed_tables[] = 'kit_shipping_rate_types';
            }
            if (self::delete_table('kit_shipping_directions')) {
                $dropped_tables[] = 'kit_shipping_directions';
            } else {
                $failed_tables[] = 'kit_shipping_directions';
            }

            // 6) Drop cities (depend on countries)
            if (self::delete_table('kit_operating_cities')) {
                $dropped_tables[] = 'kit_operating_cities';
            } else {
                $failed_tables[] = 'kit_operating_cities';
            }

            // 7) Drop drivers
            if (self::delete_table('kit_drivers')) {
                $dropped_tables[] = 'kit_drivers';
            } else {
                $failed_tables[] = 'kit_drivers';
            }

            // 8) Drop countries
            if (self::delete_table('kit_operating_countries')) {
                $dropped_tables[] = 'kit_operating_countries';
            } else {
                $failed_tables[] = 'kit_operating_countries';
            }

            // 9) Drop remaining base tables
            if (self::delete_table('kit_company_customers')) {
                $dropped_tables[] = 'kit_company_customers';
            } else {
                $failed_tables[] = 'kit_company_customers';
            }
            if (self::delete_table('kit_customers')) {
                $dropped_tables[] = 'kit_customers';
            } else {
                $failed_tables[] = 'kit_customers';
            }
            if (self::delete_table('kit_services')) {
                $dropped_tables[] = 'kit_services';
            } else {
                $failed_tables[] = 'kit_services';
            }
            if (self::delete_table('kit_discounts')) {
                $dropped_tables[] = 'kit_discounts';
            } else {
                $failed_tables[] = 'kit_discounts';
            }
            if (self::delete_table('kit_company_details')) {
                $dropped_tables[] = 'kit_company_details';
            } else {
                $failed_tables[] = 'kit_company_details';
            }

            // Re-enable FK checks
            $fk_enabled = $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            if ($fk_enabled === false) {
                self::write_log('Warning: Failed to re-enable FOREIGN_KEY_CHECKS: ' . $wpdb->last_error);
            } else {
                self::write_log('FOREIGN_KEY_CHECKS re-enabled');
            }
            
            // Log results
            self::write_log('Dropped ' . count($dropped_tables) . ' tables: ' . implode(', ', $dropped_tables));
            if (!empty($failed_tables)) {
                self::write_log('Failed to drop ' . count($failed_tables) . ' tables: ' . implode(', ', $failed_tables));
            }
            
            self::write_log('=== DEACTIVATION COMPLETED ===');
            
        } catch (Exception $e) {
            // Log the error but don't crash the deactivation
            self::write_log('Plugin deactivation error: ' . $e->getMessage());
            self::write_log('Stack trace: ' . $e->getTraceAsString());
            
            // Ensure FK checks are re-enabled even if there was an error
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            self::write_log('=== DEACTIVATION ERROR ===');
        } catch (Error $e) {
            // Log fatal errors too
            self::write_log('Plugin deactivation fatal error: ' . $e->getMessage());
            self::write_log('Stack trace: ' . $e->getTraceAsString());
            
            // Ensure FK checks are re-enabled even if there was a fatal error
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            self::write_log('=== DEACTIVATION FATAL ERROR ===');
        }
    }
}
