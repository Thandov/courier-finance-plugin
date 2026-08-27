<?php
if (!defined('ABSPATH')) {
    exit;
}

// Include customer functions
require_once plugin_dir_path(__FILE__) . '../customers/customers-functions.php';
require_once plugin_dir_path(__FILE__) . '../customers/_customersForm.php';

// Form submission is handled in admin-menu.php

// Get error message if form submission failed
$error_message = '';
if (isset($_GET['error']) && $_GET['error'] == '1') {
    $dup = isset($_GET['dup']) ? sanitize_key((string) $_GET['dup']) : '';
    if ($dup === 'company') {
        $error_message = 'A customer with this company name (or a very similar spelling) already exists. Use the existing company or Merge duplicates.';
    } elseif ($dup === 'name') {
        $error_message = 'A customer with this name (or a very similar spelling) already exists — e.g. “van den” vs “Van Der”. Use the existing customer, or Merge to transfer waybills then delete the duplicate.';
        if (!empty($_GET['msg'])) {
            $error_message = sanitize_text_field(wp_unslash((string) $_GET['msg']));
        }
    } else {
        $error_message = 'Failed to save customer. Please try again.';
        if (!empty($_GET['msg'])) {
            $error_message = sanitize_text_field(wp_unslash((string) $_GET['msg']));
        }
    }
}
?>

<div class="wrap">
    <h1>Add Customer or Company</h1>
    
    <?php if ($error_message !== '') : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($error_message); ?></p>
        </div>
    <?php endif; ?>

    <p class="description">Choose <strong>Individual</strong> for a person (Individuals list) or <strong>Business</strong> for a company (Companies list).</p>
    
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6" style="max-width: 800px;">
            <?php
            //Since this function is a component, pass certain data to help set the form up, example this is add new customer, there is edit customer mode as well, so pass the mode to the function.
            echo kit_render_customers_form('add', null);
            ?>
    </div>
</div>