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
    if (!isset($_GET['quotation_id'])) {
        echo '<div class="error"><p>Quotation ID is missing.</p></div>';
        return;
    }

    $quotation_id = intval($_GET['quotation_id']);
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_quotations';

    $quotation = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $quotation_id)
    );

    if (!$quotation) {
        echo '<div class="error"><p>Quotation not found.</p></div>';
        return;
    }
        $pin_path = plugin_dir_url(__FILE__) . 'icons/pin.svg';
        echo "Debug: SVG Path is: " . esc_url($pin_path);
        echo '<pre>';
    print_r($quotation);
    echo '</pre>';
    
    // Calculate totals from stored values
    $subtotal = $quotation->sub_total;
    $final_cost = $quotation->final_cost;
    $tax = $final_cost - $subtotal; // Assuming tax is included in final cost
?>

    <div class="max-w-4xl mx-auto bg-white rounded-xl shadow-lg overflow-hidden">
        <!-- Header Section -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-400 p-8">
            <h1 class="text-4xl font-bold text-white">QUOTATION</h1>
            <div class="mt-4 flex justify-between text-blue-100">
                <div>
                    <p class="font-semibold">Quotation #: <?php echo $quotation->id; ?></p>
                    <p>Date: <?php echo date('F d, Y', strtotime($quotation->date_created)); ?></p>
                </div>
                <div class="text-right">
                    <p>Valid Until: <?php echo date('F d, Y', strtotime('+7 days')); ?></p>
                </div>
            </div>
        </div>

        <!-- Company & Client Info -->
        <div class="grid grid-cols-2 gap-8 p-8 border-b">
            <!-- Left Column - Company Details -->
            <div class="space-y-2">
                <div class="items-center mb-4">
                    <img class="w-[150px]" src="<?php echo plugin_dir_url(__FILE__) . 'img/logo.png'; ?>" alt="Logo">
                    <h2 class="text-xl font-bold text-gray-800">08600 Logistics</h2>
                </div>
                <p class="text-gray-600">123 Business Street</p>
                <p class="text-gray-600">Johannesburg, 2000</p>
                <p class="text-gray-600">South Africa</p>
                <div class="mt-4">
                    <p class="text-blue-600">Tel: +27 11 123 4567</p>
                    <p class="text-blue-600">Email: info@08600.co.za</p>
                    <p class="text-blue-600">VAT: 123456789</p>
                </div>
            </div>

            <!-- Right Column - Bill To -->
            <div class="bg-gray-50 p-6 rounded-lg">
                <h3 class="text-lg font-semibold text-gray-700 mb-4">BILL TO</h3>
                <p class="font-medium text-gray-800"><?php echo $quotation->customer_name; ?></p>
                <p class="text-gray-600"><?php echo nl2br(esc_html($quotation->sender_address)); ?></p>
                <div class="mt-3">
                    <p class="text-sm text-gray-600">
                        <span class="font-medium">Email:</span> <?php echo $quotation->customer_email; ?>
                    </p>
                    <p class="text-sm text-gray-600">
                        <span class="font-medium">Phone:</span> <?php echo $quotation->customer_phone; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <div class="px-8 py-6">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left py-4 px-4 font-semibold text-gray-700">DESCRIPTION</th>
                        <th class="text-right py-4 px-4 font-semibold text-gray-700">AMOUNT (ZAR)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <!-- Base Shipping -->
                    <tr class="hover:bg-gray-50">
                        <td class="py-4 px-4">
                            <p class="font-medium text-gray-800"><?php echo strtoupper($quotation->shipping_method); ?>-BASED SHIPPING</p>
                            <?php if ($quotation->shipping_method === 'weight') : ?>
                                <p class="text-sm text-gray-600"><?php echo $quotation->weight; ?> kg</p>
                            <?php else : ?>
                                <p class="text-sm text-gray-600">Dimensions: <?php echo $quotation->length; ?>m × <?php echo $quotation->width; ?>m × <?php echo $quotation->height; ?>m</p>
                            <?php endif; ?>
                        </td>
                        <td class="py-4 px-4 text-right">R <?php echo number_format($quotation->sub_total, 2); ?></td>
                    </tr>

                    <!-- Additional Fees -->
                    <?php if ($quotation->include_sad500) : ?>
                        <tr class="hover:bg-gray-50">
                            <td class="py-4 px-4 text-gray-600">SAD500 Documentation Fee</td>
                            <td class="py-4 px-4 text-right">R 350.00</td>
                        </tr>
                    <?php endif; ?>

                    <?php if ($quotation->include_sadc) : ?>
                        <tr class="hover:bg-gray-50">
                            <td class="py-4 px-4 text-gray-600">SADC Certificate</td>
                            <td class="py-4 px-4 text-right">R 1000.00</td>
                        </tr>
                    <?php endif; ?>

                    <!-- Discount -->
                    <?php if ($quotation->return_load) : ?>
                        <tr class="hover:bg-gray-50 bg-blue-50">
                            <td class="py-4 px-4 text-blue-600 font-medium">Return Load Discount (10%)</td>
                            <td class="py-4 px-4 text-right text-blue-600">- R <?php echo number_format($quotation->sub_total * 0.1, 2); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Total Section -->
            <div class="mt-8 flex justify-end">
                <div class="w-64">
                    <div class="flex justify-between items-center mb-2">
                        <span class="font-medium text-gray-700">Subtotal:</span>
                        <span class="text-gray-600">R <?php echo number_format($quotation->sub_total, 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="font-medium text-gray-700">Total:</span>
                        <span class="text-xl font-bold text-blue-600">R <?php echo number_format($quotation->final_cost, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Notes -->
        <div class="bg-gray-50 p-8 border-t">
            <div class="grid grid-cols-2 gap-8">
                <div class="text-sm text-gray-600">
                    <p class="font-medium mb-2">Payment Details:</p>
                    <p>Bank: Standard Bank</p>
                    <p>Account: 123 456 789</p>
                    <p>Branch: 000000</p>
                </div>
                <div class="text-sm text-gray-600">
                    <p class="font-medium mb-2">Notes:</p>
                    <p>Payment due within 14 days</p>
                    <p>VAT included where applicable</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-8 text-right">
            <a href="<?php echo plugins_url('pdf-generator.php', __FILE__); ?>?quotation_id=<?php echo $quotation_id; ?>"
                class="bg-blue-600 text-white px-4 py-2 rounded">
                Download PDF
            </a>
        </div>
    </div>

    <?php
}


function quote_instructions()
{
    ?>§
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

        <!-- Customer Information -->
        <div class="space-y-4">
            <h3 class="text-lg font-semibold">Your Information</h3>
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
        </div>

        <!-- Sender Information -->
        <div class="space-y-4 mt-6">
            <h3 class="text-lg font-semibold">Sender Information</h3>
            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Sender Name</label>
                <input type="text" name="sender_name" class="w-full p-2 border rounded-lg" placeholder="Sender's name" required>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Sender Email</label>
                <input type="email" name="sender_email" class="w-full p-2 border rounded-lg" placeholder="Sender's email" required>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Sender Phone</label>
                <input type="tel" name="sender_phone" class="w-full p-2 border rounded-lg" placeholder="Sender's phone number" required>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Sender Address</label>
                <textarea name="sender_address" class="w-full p-2 border rounded-lg" placeholder="Full sender address" required></textarea>
            </div>
        </div>

        <!-- Receiver Information -->
        <div class="space-y-4 mt-6">
            <h3 class="text-lg font-semibold">Receiver Information</h3>
            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Receiver Name</label>
                <input type="text" name="receiver_name" class="w-full p-2 border rounded-lg" placeholder="Receiver's name" required>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Receiver Email</label>
                <input type="email" name="receiver_email" class="w-full p-2 border rounded-lg" placeholder="Receiver's email" required>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Receiver Phone</label>
                <input type="tel" name="receiver_phone" class="w-full p-2 border rounded-lg" placeholder="Receiver's phone number" required>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Receiver Address</label>
                <textarea name="receiver_address" class="w-full p-2 border rounded-lg" placeholder="Full receiver address" required></textarea>
            </div>
        </div>

        <!-- Shipping Details -->
        <div class="space-y-4 mt-6">
            <h3 class="text-lg font-semibold">Shipping Details</h3>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Send Location</label>
                <input type="text" name="send_location" class="w-full p-2 border rounded-lg" placeholder="Where is the package being sent from?" required>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-600">Delivery Address</label>
                <textarea name="delivery_address" class="w-full p-2 border rounded-lg" placeholder="Full delivery address" required></textarea>
            </div>

            <!-- Shipping Method Selection -->
            <div class="mt-4">
                <h4 class="text-md font-medium">Shipping Method</h4>
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
                <input type="number" name="weight" min="0.1" step="0.1" class="w-full p-2 border rounded-lg" placeholder="Enter weight in kg">
            </div>

            <!-- Volume-Based Input -->
            <div id="volumeInput" class="hidden mt-4">
                <label class="block text-sm font-medium text-gray-600">Dimensions (m)</label>
                <div class="grid grid-cols-3 gap-2">
                    <input type="number" name="length" min="0.1" step="0.01" placeholder="Length" class="p-2 border rounded-lg">
                    <input type="number" name="width" min="0.1" step="0.01" placeholder="Width" class="p-2 border rounded-lg">
                    <input type="number" name="height" min="0.1" step="0.01" placeholder="Height" class="p-2 border rounded-lg">
                </div>
            </div>
        </div>

        <!-- Additional Fees -->
        <div class="space-y-4 mt-6">
            <h3 class="text-lg font-semibold">Additional Fees</h3>
            <label class="flex items-center space-x-2">
                <input type="checkbox" name="include_sad500" class="form-checkbox">
                <span>Include SAD500 Fee (R350)</span>
            </label>
            <label class="flex items-center space-x-2">
                <input type="checkbox" name="include_sadc" class="form-checkbox">
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
        <button type="submit" class="w-full bg-blue-600 text-white p-2 rounded-lg mt-6">Get Quote</button>
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
