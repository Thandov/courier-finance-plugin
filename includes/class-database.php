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

        // SQL query to create the quotations table with additional fields
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50) NOT NULL,
            
            sender_name VARCHAR(255) NOT NULL,
            sender_email VARCHAR(255) NOT NULL,
            sender_phone VARCHAR(50) NOT NULL,
            sender_address TEXT NOT NULL,
            
            receiver_name VARCHAR(255) NOT NULL,
            receiver_email VARCHAR(255) NOT NULL,
            receiver_phone VARCHAR(50) NOT NULL,
            receiver_address TEXT NOT NULL,
            
            shipping_method ENUM('weight', 'volume') NOT NULL,
            weight DECIMAL(10, 2) DEFAULT NULL,
            length DECIMAL(10, 2) DEFAULT NULL,
            width DECIMAL(10, 2) DEFAULT NULL,
            height DECIMAL(10, 2) DEFAULT NULL,
            
            send_location VARCHAR(255) NOT NULL,
            delivery_address TEXT NOT NULL,
            const_cost DECIMAL(10, 2) NOT NULL,
            sub_total DECIMAL(10, 2) NOT NULL,
            final_cost DECIMAL(10, 2) NOT NULL,
            
            include_sad500 BOOLEAN DEFAULT 1,
            include_sadc BOOLEAN DEFAULT 1,
            return_load BOOLEAN DEFAULT 0,
            
            date_created DATETIME DEFAULT CURRENT_TIMESTAMP
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
