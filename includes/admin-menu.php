<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the plugin menu and submenu
 */
function plugin_add_menu()
{
    // Add main menu
    add_menu_page(
        '08600 Services & Quotations', // Page title
        '08600 Services', // Menu title
        'edit_pages', // Capability
        '08600-services-quotations', // Menu slug
        'plugin_main_page', // Callback function
        'dashicons-businessperson', // Icon
        6 // Position
    );

    // Add submenu for services
    add_submenu_page(
        '08600-services-quotations', // Parent slug
        'All Services', // Page title
        'All Services', // Menu title
        'edit_pages', // Capability
        '08600-services-list', // Submenu slug
        'plugin_services_list_page' // Callback function
    );

    // Add main menu
    add_menu_page(
        'Quotations', // Page title
        'Quotations', // Menu title
        'manage_options', // Capability
        'Quotations', // Menu slug
        'plugin_quotations_list_page', // Callback function
        'dashicons-businessperson', // Icon
        6 // Position
    );

    // Add submenu for services
    add_submenu_page(
        'Quotations', // Parent slug
        'Create Quotations', // Page title
        'Create Quotations', // Menu title
        'manage_options', // Capability
        '08600-quotations-insert', // Submenu slug
        'quotation_insert_page' // Callback function
    );
    // Add submenu for services
    add_submenu_page(
        'Quotations',       // Parent slug (e.g., under Pages)
        'View Quotation',                // Page title
        '',                // Menu title
        'manage_options',                // Capability
        'kit-quotation-edit',         // Menu slug
        'quotation_view_page'            // Callback function to display the page
    );
        // Add main menu
        add_menu_page(
            'Waybill', // Page title
            '08600 Waybill', // Menu title
            'edit_pages', // Capability
            '08600-Waybill', // Menu slug
            'plugin_Waybill_list_page', // Callback function
            'dashicons-admin-plugins', // Icon
            6 // Position
        );
    // Add submenu for services
    add_submenu_page(
        '08600-Waybill', // Parent slug
        'All Waybills', // Page title
        'All Waybills', // Menu title
        'edit_pages', // Capability
        '08600-Waybill-list', // Submenu slug
        'plugin_Waybill_list_page' // Callback function
    );
    // Add submenu for services
    add_submenu_page(
        '08600-Waybill', // Parent slug
        'Create Waybills', // Page title
        'Create Waybills', // Menu title
        'edit_pages', // Capability
        '08600-Waybill-create', // Submenu slug
        'waybill_page' // Callback function
    );
    // Add view submenu (hidden from main menu)
    add_submenu_page(
        '08600-Waybill', // Parent slug matches main menu
        'View Waybill',
        '', // Empty menu title hides it
        'edit_pages',
        '08600-Waybill-view',
        ['KIT_Waybills', 'plugin_Waybill_view_page'] // Array syntax for class method
    );

    add_menu_page(
        '08600 Customers',       // Page title
        '08600 Customers',       // Menu title
        'manage_options',       // Capability
        'customers-dashboard',    // Menu slug
        'customer_dashboard',    // Callback function
        'dashicons-admin-site', // Icon
        6                       // Position
    );

    add_submenu_page(
        'Customers',       // Parent slug
        'All Customers',
        'All Customers',
        'edit_pages',
        '08600-customers',
        'customer_dashboard'
    );
    add_submenu_page(
        'Customers',       // Parent slug
        '',
        'edit Customers',
        'edit_pages',
        'edit-customer',
        'edit_customer'
    );
}
add_action('admin_menu', 'plugin_add_menu');