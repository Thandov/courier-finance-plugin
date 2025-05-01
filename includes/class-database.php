<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Database
{

    /**
     * Create Customers Table
     */
    public static function create_customers_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kit_customers';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            cust_id mediumint(10),
            name varchar(255) NOT NULL,
            surname varchar(255) NOT NULL,
            cell varchar(255) NOT NULL,
            address varchar(255) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create Services Table
     */
    public static function create_services_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kit_services';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text NOT NULL,
            image varchar(255) DEFAULT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Create Quotations Table
     */
    public static function create_quotations_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kit_quotations'; // Use a unique table name
        $charset_collate = $wpdb->get_charset_collate();

        // SQL query to create the quotations table with additional fields
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            quoteid int(50) NOT NULL,
            customer_id varchar(50) NOT NULL,
            waybill_no varchar(50) NOT NULL,
            subtotal varchar(50) NOT NULL,
            total varchar(50) NOT NULL,
            date_received date NOT NULL,
            created_by varchar(255) NOT NULL,
            status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Include the upgrade.php to execute the query
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

   /**
     * Create Waybill Table
     */
    public static function create_waybills_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kit_waybills'; // Use a unique table name
        $charset_collate = $wpdb->get_charset_collate();

        // SQL query to create the waybill table with additional fields
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            waybill_id mediumint(10),
            customer_id varchar(50) NOT NULL,
            waybill_no varchar(50) NOT NULL,
            date_received date NOT NULL,
            customer_name varchar(100) NOT NULL,
            is_new_customer tinyint(1) DEFAULT 0,
            destination_country varchar(100) NOT NULL,
            destination_city varchar(100) NOT NULL,
            consignor_name varchar(100) NOT NULL,
            consignor_code varchar(50),
            warehouse varchar(50),
            consignor_address text,
            contact_name varchar(100),
            vat_number varchar(50),
            telephone varchar(20),
            product_invoice_number varchar(50),
            product_invoice_amount decimal(10,2),
            item_length decimal(10,2),
            item_width decimal(10,2),
            item_height decimal(10,2),
            total_volume decimal(10,2),
            total_mass_kg decimal(10,2),
            unit_volume decimal(10,2),
            unit_mass decimal(10,2),
            charge_basis varchar(20),
            mass_charge decimal(10,2),
            volume_charge decimal(10,2),
            created_by INT(10) NOT NULL,
            last_updated_by INT(10) NOT NULL,
            last_updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
            tracking_number varchar(50),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Include the upgrade.php to execute the query
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Delete Services Table
     */
    public static function delete_services_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_services';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }

    /**
     * Delete Quotations Table
     */
    public static function delete_quotations_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_quotations';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }
    /**
     * Delete Waybill Table
     */
    public static function delete_waybills_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }
    /**
     * Delete Customers Table
     */
    public static function delete_customers_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_customers';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }

    /**
     * Run on Plugin Activation
     */
    public static function activate()
    {
        self::create_customers_table();
        self::create_services_table();
        self::create_waybills_table();
        self::create_quotations_table();
    }

    /**
     * Run on Plugin Deactivation
     */
    public static function deactivate()
    {
        // Ensure the tables are deleted
        self::delete_customers_table();
        self::delete_quotations_table();
        self::delete_waybills_table();
        self::delete_services_table();
    }
}