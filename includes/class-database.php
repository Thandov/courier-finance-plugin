<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Database
{
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

        // SQL query to create the quotations table
        $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        customer_name varchar(255) NOT NULL,
        contact_details varchar(255) NOT NULL,
        pickup_location varchar(255) NOT NULL,
        delivery_location varchar(255) NOT NULL,
        delivery_country varchar(255) NOT NULL,
        weight_kg decimal(10,2) NOT NULL,
        volume_m3 decimal(10,2) NOT NULL,
        return_load boolean DEFAULT 0,
        special_requirements varchar(255) DEFAULT NULL,
        weight_cost decimal(10,2) NOT NULL,
        volume_cost decimal(10,2) NOT NULL,
        final_cost decimal(10,2) NOT NULL,
        additional_fees decimal(10,2) DEFAULT 0,
        total_cost decimal(10,2) NOT NULL,
        discount_percent decimal(5,2) DEFAULT 0,
        discounted_cost decimal(10,2) DEFAULT 0,
        status varchar(50) DEFAULT 'Pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)) $charset_collate;";

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
     * Run on Plugin Activation
     */
    public static function activate()
    {
        self::create_services_table();
        self::create_quotations_table();
    }

    /**
     * Run on Plugin Deactivation
     */
    public static function deactivate()
    {
        // Ensure the tables are deleted
        self::delete_quotations_table();
        self::delete_services_table();
    }
}
