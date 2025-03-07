<?php

/**
 * Plugin Name: 08600 Services and Quotations
 * Description: Plugin to manage services and quotations.
 * Version: 1.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: 08600-services-quotations
 */

// Exit if accessed directly
if (! defined('ABSPATH')) {
    exit;
}

// Enqueue styles for the admin panel
function customStyling()
{
    wp_enqueue_style('kit-tailwindcss', plugin_dir_url(__FILE__) . 'assets/css/frontend.css', __FILE__);
}
add_action('admin_print_styles', 'customStyling');

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/class-database.php';
include_once(plugin_dir_path(__FILE__) . 'includes/class-plugin.php');

// Activate and deactivate hooks
register_activation_hook(__FILE__, array('Database', 'activate'));
register_deactivation_hook(__FILE__, array('Database', 'deactivate'));

// Initialize the plugin
Plugin::init();

// Include the service functions
include_once(plugin_dir_path(__FILE__) . 'includes/services/services-functions.php');
include_once(plugin_dir_path(__FILE__) . 'includes/quotations/quotations-functions.php');

function my_plugin_enqueue_scripts()
{
    // Make sure to adjust the file path to where your script.js is located
    wp_enqueue_script('my-plugin-quotation-script', plugin_dir_url(__FILE__) . 'js/script.js', array('jquery'), null, true);

    // Optionally, you can enqueue a CSS file if you need custom styles
    // wp_enqueue_style('my-plugin-styles', plugin_dir_url(__FILE__) . 'css/style.css');
}
add_action('admin_enqueue_scripts', 'my_plugin_enqueue_scripts');

/**
 * Register the plugin menu and submenu
 */
function plugin_add_menu()
{
    // Add main menu
    add_menu_page(
        '08600 Services & Quotations', // Page title
        '08600 Services', // Menu title
        'manage_options', // Capability
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
        'manage_options', // Capability
        '08600-services-list', // Submenu slug
        'plugin_services_list_page' // Callback function
    );

    // Add main menu
    add_menu_page(
        'Quotations', // Page title
        'Quotations', // Menu title
        'manage_options', // Capability
        'Quotations', // Menu slug
        'quotation_page', // Callback function
        'dashicons-businessperson', // Icon
        6 // Position
    );
    // Add submenu for services
    add_submenu_page(
        'Quotations', // Parent slug
        'All Quotations', // Page title
        'All Quotations', // Menu title
        'manage_options', // Capability
        '08600-quotations-list', // Submenu slug
        'plugin_quotations_list_page' // Callback function
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
}
add_action('admin_menu', 'plugin_add_menu');

/**
 * Main plugin page callback with form to insert new service
 */
function plugin_main_page()
{
    // Start output buffering
    ob_start();

    echo '<h1>Add New Service</h1>';
    // Check if the form is submitted
    if (isset($_POST['submit_service'])) {
        $service_name = sanitize_text_field($_POST['service_name']);
        $service_description = sanitize_textarea_field($_POST['service_description']);
        $service_image = sanitize_text_field($_POST['service_image']);

        // Check if the service name already exists
        if (service_name_exists($service_name)) {
            echo '<div class="error"><p><strong>Error:</strong> Service name already exists.</p></div>';
        } else {
            // Insert the service into the database
            insert_service($service_name, $service_description, $service_image);
            echo '<div class="updated"><p><strong>Service added successfully!</strong></p></div>';
        }
    }

    // Form for adding new service
?>
    <table>
        <tr>
            <td>
                <form method="POST" action="">
                    <table class="form-table">
                        <tr>
                            <td style="padding: 0; margin: 0"><label for="service_name">Service Name</label><br>
                                <input type="text" name="service_name" id="service_name" required />
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 0; margin: 0"><label for="service_description">Description</label><br>
                                <textarea name="service_description" id="service_description" required></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 0; margin: 0"><label for="service_image">Flat Icon</label><br>
                                <input type="text" name="service_image" id="service_image" />
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2"><input type="submit" name="submit_service" value="Add Service"
                                    class="button-primary" /></td>
                        </tr>
                    </table>
                </form>
            </td>
            <td style="padding-left: 20px" valign="top">
                <?php
                // Get all services from the database
                global $wpdb;
                $services_table_name = $wpdb->prefix . 'kit_services';

                // Query to retrieve services from the database
                $services = $wpdb->get_results("SELECT * FROM $services_table_name");

                // If no services are found, display a message
                if (empty($services)) {
                    echo '<p>No services found.</p>';
                } else {
                    plugin_services_list_page();
                }
                ?>
            </td>
        </tr>
    </table>


<?php
    // Output the buffered content
    echo ob_get_clean();
}

/**
 * Main plugin page callback with form to insert new service
 */
function quotation_page()
{
    // Start output buffering
    ob_start();
    echo '<h1>Quotations</h1>';

    // Output the buffered content
    echo ob_get_clean();
}

/**
 * Insert a new service into the database
 */
function insert_service($name, $description, $image)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_services';

    $wpdb->insert(
        $table_name,
        array(
            'name'        => $name,
            'description' => $description,
            'image'       => $image,
        ),
        array(
            '%s', // name
            '%s', // description
            '%s', // image
        )
    );
}

/**
 * Services list page callback
 */
function plugin_services_list_page()
{
    // Get all services
    $services = get_all_services();

    echo '<h1>All Services</h1>';

    if (! empty($services)) {
        echo '<table class="wp-list-table widefat fixed striped posts">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Name</th>';
        echo '<th>Description</th>';
        echo '<th>Image</th>';
        echo '<th>Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($services as $service) {
            echo '<tr>';
            echo '<td>' . esc_html($service->name) . '</td>';
            echo '<td>' . esc_html($service->description) . '</td>';
            echo '<td>' . esc_html($service->image) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=08600-services-edit&id=' . $service->id)) . '">Edit</a> | ';
            echo '<a href="' . esc_url(admin_url('admin-post.php?action=delete_service&id=' . $service->id)) . '" onclick="return confirm(\'Are you sure you want to delete this service?\')">Delete</a></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<p>No services found.</p>';
    }
}

/**
 * Handle service deletion
 */
function handle_service_deletion()
{
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $service_id = intval($_GET['id']);
        delete_service($service_id); // Delete the service from the database
    }

    wp_redirect(admin_url('admin.php?page=08600-services-list')); // Redirect back to the services list page
    exit;
}
add_action('admin_post_delete_service', 'handle_service_deletion');

/**
 * Services list page callback
 */
function plugin_quotations_list_page()
{
?>
    <div class="bg-red-100">
        <?php echo kit_get_all_quotations_table(); ?>
    </div>
    <?php
}

// Function to display the form and handle the form submission
function quotation_insert_page()
{
    // Handle form submission
    if (isset($_POST['submit_quotation'])) {
        // Process the form data if submitted
        $quotation_data = array(
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'contact_details' => sanitize_text_field($_POST['contact_details']),
            'pickup_location' => sanitize_text_field($_POST['pickup_location']),
            'delivery_location' => sanitize_text_field($_POST['delivery_location']),
            'delivery_country' => sanitize_text_field($_POST['delivery_country']),
            'weight_kg' => floatval($_POST['weight_kg']),
            'volume_m3' => floatval($_POST['volume_m3']),
            'return_load' => isset($_POST['return_load']) ? 1 : 0,
            'special_requirements' => sanitize_text_field($_POST['special_requirements']),
            'weight_cost' => floatval($_POST['weight_cost']),
            'volume_cost' => floatval($_POST['volume_cost']),
            'final_cost' => floatval($_POST['final_cost']),
            'additional_fees' => floatval($_POST['additional_fees']),
            'total_cost' => floatval($_POST['total_cost']),
            'discount_percent' => floatval($_POST['discount_percent']),
            'discounted_cost' => floatval($_POST['discounted_cost']),
            'status' => sanitize_text_field($_POST['status']),
        );

        // Insert the quotation into the database 
        $result = kit_add_quotation($quotation_data);
        if ($result) {
            echo '<div class="updated"><p>Quotation saved successfully!</p></div>';
        } else {
            echo '<div class="error"><p>Error saving quotation.</p></div>';
        }
    }

    // Form HTML
    echo quotation_form(null, 'add');
}

// Register a shortcode to display the form
function register_quotation_insert_page()
{
    add_shortcode('quotation_insert_page', 'quotation_insert_page');
}
add_action('init', 'register_quotation_insert_page');

// Function to display a specific quotation
function quotation_view_page()
{
    // Check if ID is set in the URL
    if (isset($_GET['quotation_id'])) {
        $quotation_id = intval($_GET['quotation_id']); // Sanitize the ID
        global $wpdb;

        // Query to get the quotation from the database
        $quotation = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}kit_quotations WHERE id = %d", $quotation_id)
        );

        // If quotation is found, display it
        if ($quotation) {
    ?>

            <div class="max-w-4xl mx-auto my-10 p-6 bg-white shadow-lg rounded-lg border border-gray-200">
                <table class="w-full">
                    <!-- Header Section -->
                    <tr class="border-b border-gray-300 pb-6">
                        <td colspan="2" class="flex justify-between">
                            <div class="grid grid-cols-2">
                                <div class="text-4xl font-bold text-gray-800">
                                    <img
                                        src="https://sparksuite.github.io/simple-html-invoice-template/images/logo.png"
                                        alt="Company Logo"
                                        class="w-48" />
                                </div>
                                <div class="text-right text-sm text-gray-600">
                                    <p>Invoice #: <span class="font-semibold">123</span></p>
                                    <p>Created: <span class="font-semibold">January 1, 2023</span></p>
                                    <p>Due: <span class="font-semibold">February 1, 2023</span></p>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <!-- Information Section -->
                    <tr class="border-b border-gray-300 pb-6">
                        <td colspan="2">
                            <div class="flex justify-between">
                                <div class="text-sm text-gray-600">
                                    <p class="font-semibold">Sparksuite, Inc.</p>
                                    <p>12345 Sunny Road</p>
                                    <p>Sunnyville, CA 12345</p>
                                </div>
                                <div class="text-sm text-gray-600 text-right">
                                    <p class="font-semibold">Acme Corp.</p>
                                    <p>John Doe</p>
                                    <p>john@example.com</p>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <!-- Payment Method Section -->
                    <tr class="border-b border-gray-300">
                        <td class="font-semibold text-gray-800">Payment Method</td>
                        <td class="font-semibold text-gray-800 text-right">Check #</td>
                    </tr>
                    <tr class="border-b border-gray-300">
                        <td>Check</td>
                        <td class="text-right">1000</td>
                    </tr>

                    <!-- Items Section -->
                    <tr class="border-b border-gray-300">
                        <td class="font-semibold text-gray-800">Item</td>
                        <td class="font-semibold text-gray-800 text-right">Price</td>
                    </tr>
                    <tr class="border-b border-gray-200">
                        <td>Website design</td>
                        <td class="text-right">$300.00</td>
                    </tr>
                    <tr class="border-b border-gray-200">
                        <td>Hosting (3 months)</td>
                        <td class="text-right">$75.00</td>
                    </tr>
                    <tr class="border-b border-gray-200">
                        <td>Domain name (1 year)</td>
                        <td class="text-right">$10.00</td>
                    </tr>

                    <!-- Total Section -->
                    <tr>
                        <td></td>
                        <td class="font-semibold text-gray-800 border-t border-gray-300 text-right">Total: $385.00</td>
                    </tr>
                </table>
            </div>
    <?php
        } else {
            echo '<div class="error"><p>Quotation not found.</p></div>';
        }
    } else {
        echo '<div class="error"><p>Quotation ID is missing.</p></div>';
    }
}

// ✅ Reusable Quotation Form
function quotation_form($quotation = null, $type)
{
    global $wpdb;
    //$type = "add"

    $action = ($type === 'edit') ? 'kit_update_quotation' : 'kit_add_quotation';
    $submit_text = ($type === 'edit') ? 'Update quotation' : 'Add quotation';
    $nonce_action = ($type === 'edit') ? 'kit_edit_quotation_action' : 'kit_add_quotation_action';
    $nonce_name = ($type === 'edit') ? 'kit_quotation_nonce' : 'kit_add_quotation_nonce';

    ?>
    <div class="wrap">
        <h1>Create New Quotation</h1>
        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="space-y-4">
            <?php wp_nonce_field($nonce_action, $nonce_name); ?>
            <input type="hidden" name="action" value="<?php echo $action; ?>">
            <?php if ($type === 'edit' && $quotation): ?>
                <input type="hidden" name="quotation_id" value="<?php echo esc_attr($quotation->id); ?>">
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><label for="customer_name">Customer Name</label></th>
                    <td><input type="text" name="customer_name" id="customer_name" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="contact_details">Contact Details</label></th>
                    <td><input type="text" name="contact_details" id="contact_details" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="pickup_location">Pickup Location</label></th>
                    <td><input type="text" name="pickup_location" id="pickup_location" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="delivery_location">Delivery Location</label></th>
                    <td><input type="text" name="delivery_location" id="delivery_location" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="delivery_country">Delivery Country</label></th>
                    <td><input type="text" name="delivery_country" id="delivery_country" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="weight_kg">Weight (kg)</label></th>
                    <td><input type="number" name="weight_kg" id="weight_kg" step="0.01" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="volume_m3">Volume (m³)</label></th>
                    <td><input type="number" name="volume_m3" id="volume_m3" step="0.01" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="return_load">Return Load</label></th>
                    <td><input type="checkbox" name="return_load" id="return_load" value="1" /></td>
                </tr>
                <tr>
                    <th><label for="special_requirements">Special Requirements</label></th>
                    <td><input type="text" name="special_requirements" id="special_requirements" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label for="weight_cost">Weight Cost</label></th>
                    <td><input type="number" name="weight_cost" id="weight_cost" step="0.01" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="volume_cost">Volume Cost</label></th>
                    <td><input type="number" name="volume_cost" id="volume_cost" step="0.01" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="final_cost">Final Cost</label></th>
                    <td><input type="number" name="final_cost" id="final_cost" step="0.01" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="additional_fees">Additional Fees</label></th>
                    <td><input type="number" name="additional_fees" id="additional_fees" step="0.01" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label for="total_cost">Total Cost</label></th>
                    <td><input type="number" name="total_cost" id="total_cost" step="0.01" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="discount_percent">Discount Percent</label></th>
                    <td><input type="number" name="discount_percent" id="discount_percent" step="0.01" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label for="discounted_cost">Discounted Cost</label></th>
                    <td><input type="number" name="discounted_cost" id="discounted_cost" step="0.01" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label for="status">Status</label></th>
                    <td><input type="text" name="status" id="status" value="Pending" class="regular-text" /></td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="submit_quotation" id="submit_quotation" class="button-primary" value="Save Quotation" />
            </p>
        </form>
    </div>
<?php
}
