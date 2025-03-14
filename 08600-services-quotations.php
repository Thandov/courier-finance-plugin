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
{ ?>
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

        echo '<pre>';
        print_r($quotation);
        echo '</pre>';
        $subtotal = 0;
        // If quotation is found, display it
        if ($quotation) { ?>
            <div class="max-w-3xl mx-auto p-6 bg-white rounded shadow-sm" id="invoice">

                <!-- Header -->
                <div class="grid grid-cols-2 items-center">
                    <div>
                        <img src="xxxxxxx" alt="company-logo" height="80" width="80">
                    </div>
                    <div class="text-right">
                        <p class="font-bold text-lg">Your Company Inc.</p>
                        <p class="text-gray-500 text-sm">info@yourcompany.com</p>
                        <p class="text-gray-500 text-sm">+123-456-7890</p>
                        <p class="text-gray-500 text-sm">VAT: 123456789</p>
                    </div>
                </div>

                <!-- Client Info -->
                <div class="grid grid-cols-2 mt-8">
                    <div>
                        <p class="font-bold">Bill To:</p>
                        <p id="client-name" class="text-gray-500"><?php echo esc_html($quotation->customer_name); ?></p>
                        <p id="client-address" class="text-gray-500"><?php echo esc_html($quotation->contact_details); ?></p>
                        <p id="client-email" class="text-gray-500"><?php echo (isset($quotation->client_email)) ? esc_html($quotation->client_email) : 'email_address'; ?></p>
                    </div>
                    <div class="text-right">
                        <p>Quotation #: <span id="invoice-number" class="text-gray-500"><?php echo (isset($quotation->quotation_number)) ? esc_html($quotation->quotation_number) : 'quotation_number'; ?></span></p>
                        <p>Quotation Date: <span id="invoice-date" class="text-gray-500"><?php echo (isset($quotation->quotation_date)) ? esc_html($quotation->quotation_date) : 'quotation_date'; ?></span></p>
                        <p>Due Date: <span id="due-date" class="text-gray-500"><?php echo (isset($quotation->due_date)) ? esc_html($quotation->due_date) : 'due_date'; ?></span></p>
                    </div>
                </div>

                <!-- Invoice Table 11111111111 -->
                <div class="mt-6">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-2">Item</th>
                                <th class="text-right py-2">Quantity</th>
                                <th class="text-right py-2">Price</th>
                                <th class="text-right py-2">Total</th>
                            </tr>
                        </thead>
                        <tbody id="invoice-items">
                            <?php
                            if (isset($quotation->items)):
                                $items = unserialize($quotation->items); // Assuming items are stored as serialized array
                                $subtotal = 0;
                                foreach ($items as $item) {
                                    $total = $item['quantity'] * $item['price'];
                                    $subtotal += $total;
                            ?>
                                    <tr class="border-b">
                                        <td class="text-left py-2"><?php echo esc_html($item['name']); ?></td>
                                        <td class="text-right py-2"><?php echo esc_html($item['quantity']); ?></td>
                                        <td class="text-right py-2">$<?php echo number_format($item['price'], 2); ?></td>
                                        <td class="text-right py-2">$<?php echo number_format($total, 2); ?></td>
                                    </tr>
                            <?php }
                            endif;
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="mt-6 text-right">
                    <?php $tax = $subtotal * 0.15; // Assuming 15% tax 
                    ?>
                    <p>Subtotal: <span id="subtotal" class="text-gray-500">$<?php echo number_format($subtotal, 2); ?></span></p>
                    <p>Tax (15%): <span id="tax" class="text-gray-500">$<?php echo number_format($tax, 2); ?></span></p>
                    <p class="font-bold">Total: <span id="total" class="text-gray-900">$<?php echo number_format($subtotal + $tax, 2); ?></span></p>
                </div>

                <!-- Buttons -->
                <div class="mt-6 text-right">
                    <button onclick="generatePDF()" class="bg-blue-600 text-white px-4 py-2 rounded">Download PDF</button>
                </div>
            </div>
    <?php
        } else {
            echo '<div class="error"><p>Quotation not found.</p></div>';
        }
    } else {
        echo '<div class="error"><p>Quotation ID is missing.</p></div>';
    }
}


function quote_instructions()
{
    ?>
    <div class="bg-white p-6 rounded-lg shadow-lg space-y-6">
        <h2 class="text-2xl font-semibold">Customer Pricing Details</h2>

        <div class="space-y-4">
            <h3 class="font-medium">Constant Costs</h3>
            <div class="flex justify-between">
                <span>SAD500 Fee</span>
                <span>R350</span>
            </div>
            <div class="flex justify-between">
                <span>SADC Certificate</span>
                <span>R1000</span>
            </div>
            <div class="flex justify-between">
                <span>TRA Clearing Fee (in USD)</span>
                <span>$100</span>
            </div>
        </div>

        <div class="space-y-4">
            <h3 class="font-medium">Shipping Rates</h3>
            <div class="flex flex-col space-y-2">
                <div class="flex justify-between">
                    <span>Weight-Based Pricing (R per kg)</span>
                    <span>10 kg - 500 kg: R40.00</span>
                </div>
                <div class="flex justify-between">
                    <span>Volume-Based Pricing (R per m³)</span>
                    <span>0 m³ - 1 m³: R7,500</span>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <h3 class="font-medium">Other Factors</h3>
            <p>Discounts are applied based on volume. Larger volumes receive greater discounts.</p>
        </div>
    </div>

<?php
}
// ✅ Reusable Quotation Form
function quotation_form($quotation = null, $type)
{
    $action = ($type === 'edit') ? 'kit_update_quotation' : 'kit_add_quotation';
    $submit_text = ($type === 'edit') ? 'Update quotation' : 'Add quotation';
    $nonce_action = ($type === 'edit') ? 'kit_edit_quotation_action' : 'kit_add_quotation_action';
    $nonce_name = ($type === 'edit') ? 'kit_quotation_nonce' : 'kit_add_quotation_nonce';
    ?>
        <form method="POST" id="quotationForm" action="<?php echo admin_url('admin-post.php'); ?>" class="max-w-2xl mx-auto bg-white shadow-lg rounded-lg p-6 space-y-4">
            <?php wp_nonce_field($nonce_action, $nonce_name); ?>
            <input type="hidden" name="action" value="<?php echo $action; ?>">

            <h2 class="text-2xl font-bold text-gray-700">Get a Shipping Quote</h2>

            <!-- Customer & Shipment Details -->
            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Name</label>
                <input type="text" name="customer_name" class="w-full p-2 border rounded-lg" placeholder="Enter your name" required>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Email</label>
                <input type="email" name="customer_email" class="w-full p-2 border rounded-lg" placeholder="Enter your email" required>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Phone</label>
                <input type="tel" name="customer_phone" class="w-full p-2 border rounded-lg" placeholder="Enter your phone number" required>
            </div>

            <!-- Shipping Method Selection -->
            <div class="mt-4">
                <h3 class="text-lg font-semibold">Shipping Method</h3>
                <div class="flex space-x-4">
                    <label class="flex items-center space-x-2">
                        <input type="radio" name="shipping_method" value="weight" class="form-radio" checked>
                        <span>Weight-Based</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="radio" name="shipping_method" value="volume" class="form-radio">
                        <span>Volume-Based</span>
                    </label>
                </div>
            </div>

            <!-- Weight-Based Input -->
            <div id="weightInput" class="mt-4">
                <label class="block text-sm font-medium text-gray-600">Weight (kg)</label>
                <input type="number" name="weight" min="1" class="w-full p-2 border rounded-lg" placeholder="Enter weight in kg">
            </div>

            <!-- Volume-Based Input -->
            <div id="volumeInput" class="hidden mt-4">
                <label class="block text-sm font-medium text-gray-600">Dimensions (m)</label>
                <div class="grid grid-cols-3 gap-2">
                    <input type="number" name="length" placeholder="Length" class="p-2 border rounded-lg">
                    <input type="number" name="width" placeholder="Width" class="p-2 border rounded-lg">
                    <input type="number" name="height" placeholder="Height" class="p-2 border rounded-lg">
                </div>
            </div>

            <!-- Additional Fees (Auto-Calculated) -->
            <div class="mt-4">
                <label class="flex items-center space-x-2">
                    <input type="checkbox" name="include_sad500" class="form-checkbox" checked>
                    <span>Include SAD500 Fee (R350)</span>
                </label>
                <label class="flex items-center space-x-2">
                    <input type="checkbox" name="include_sadc" class="form-checkbox" checked>
                    <span>Include SADC Certificate (R1000)</span>
                </label>
            </div>

            <!-- Return Load Discount -->
            <div class="mt-4">
                <label class="flex items-center space-x-2">
                    <input type="checkbox" name="return_load" class="form-checkbox">
                    <span>Apply Return Load Discount</span>
                </label>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="w-full bg-blue-600 text-white p-2 rounded-lg mt-4">Get Quote</button>
        </form>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const weightInput = document.getElementById('weightInput');
                const volumeInput = document.getElementById('volumeInput');
                const shippingMethods = document.querySelectorAll('input[name="shipping_method"]');

                shippingMethods.forEach(method => {
                    method.addEventListener('change', function() {
                        if (this.value === 'weight') {
                            weightInput.classList.remove('hidden');
                            volumeInput.classList.add('hidden');
                        } else {
                            weightInput.classList.add('hidden');
                            volumeInput.classList.remove('hidden');
                        }
                    });
                });
            });
        </script>
    <?php
}
