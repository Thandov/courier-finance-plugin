<?php
/**
 * Customer portal — self-service profile edit (admin-post handler + form).
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue scripts/styles for the edit-profile screen (runs on wp hook before shortcode output).
 */
function kit_customer_portal_enqueue_edit_profile_assets()
{
    if (!function_exists('is_singular') || !is_singular()) {
        return;
    }
    if (!isset($_GET['edit_profile']) || (string) $_GET['edit_profile'] !== '1') {
        return;
    }
    if (!is_user_logged_in() || !class_exists('KIT_User_Roles') || !KIT_User_Roles::is_portal_customer()) {
        return;
    }
    global $post;
    if (!$post || !is_string($post->post_content) || strpos($post->post_content, 'kit_customer_portal') === false) {
        return;
    }
    if (!defined('COURIER_FINANCE_PLUGIN_URL')) {
        return;
    }
    $base = trailingslashit(COURIER_FINANCE_PLUGIN_URL);
    wp_enqueue_style('kit-customer-portal-edit-austin', $base . 'assets/css/austin.css', array(), '1.0');
    wp_enqueue_style('kit-customer-portal-edit-fe', $base . 'assets/css/frontend.css', array('kit-customer-portal-edit-austin'), '1.0');

    $ajax_url = admin_url('admin-ajax.php');
    if (!class_exists('KIT_Deliveries')) {
        require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
    }
    $country_cities_map = method_exists('KIT_Deliveries', 'getCountryCitiesMap') ? KIT_Deliveries::getCountryCitiesMap() : array();

    wp_enqueue_script('jquery');
    wp_add_inline_script('jquery', 'window.ajaxurl = "' . esc_url($ajax_url) . '";', 'before');
    wp_enqueue_script('kitscript', $base . 'js/kitscript.js', array('jquery'), '1.0.0', true);
    wp_localize_script(
        'kitscript',
        'customerAjax',
        array(
            'ajaxurl' => $ajax_url,
            'nonce'   => wp_create_nonce('customer_nonce'),
        )
    );
    // Customer form uses onchange="handleCountryChange(...)" (waybill-pagination.js) + myPluginAjax.countryCities / get_waybills_nonce for AJAX fallback.
    wp_localize_script(
        'kitscript',
        'myPluginAjax',
        array(
            'ajax_url'      => $ajax_url,
            'countryCities' => $country_cities_map,
            'nonces'        => array(
                'get_waybills_nonce' => wp_create_nonce('get_waybills_nonce'),
            ),
        )
    );
    wp_enqueue_script('waybill-pagination', $base . 'js/waybill-pagination.js', array('jquery'), '1.0.0', true);
}

/**
 * Handle POST from customer profile edit form.
 */
function kit_customer_portal_handle_update_profile()
{
    if (!is_user_logged_in()) {
        wp_die('Forbidden', 403);
    }
    if (!class_exists('KIT_User_Roles') || !KIT_User_Roles::is_portal_customer() || !KIT_User_Roles::can_access_customer_portal()) {
        wp_die('Forbidden', 403);
    }

    if (!isset($_POST['cust_update_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cust_update_nonce'])), 'update_customer_nonce')) {
        wp_die('Security check failed', 403);
    }

    $cust_id = (int) ($_POST['customer_id'] ?? $_POST['cust_id'] ?? 0);
    $linked  = KIT_User_Roles::get_portal_customer_id();
    if ($cust_id <= 0 || $linked !== $cust_id) {
        wp_die('Forbidden', 403);
    }

    $redirect_base = function_exists('kit_customer_dashboard_url') ? kit_customer_dashboard_url() : home_url('/');

    $new_email = isset($_POST['email_address']) ? sanitize_email(trim(wp_unslash($_POST['email_address']))) : '';
    if ($new_email === '' || !is_email($new_email)) {
        wp_safe_redirect(add_query_arg('profile_error', 'invalid_email', $redirect_base));
        exit;
    }

    $existing_uid = email_exists($new_email);
    if ($existing_uid && (int) $existing_uid !== get_current_user_id()) {
        wp_safe_redirect(add_query_arg('profile_error', 'email_in_use', $redirect_base));
        exit;
    }

    $data = array(
        'name'          => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
        'surname'       => sanitize_text_field(wp_unslash($_POST['surname'] ?? '')),
        'cell'          => sanitize_text_field(wp_unslash($_POST['cell'] ?? '')),
        'address'       => sanitize_textarea_field(wp_unslash($_POST['address'] ?? '')),
        'email_address' => $new_email,
        'vat_number'    => sanitize_text_field(wp_unslash($_POST['vat_number'] ?? '')),
    );

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

    $updated = KIT_Customers::update_customer($cust_id, $data);

    if ($updated) {
        $display = trim($data['name'] . ' ' . $data['surname']);
        wp_update_user(
            array(
                'ID'             => get_current_user_id(),
                'user_email'     => $new_email,
                'display_name'   => $display !== '' ? $display : $data['name'],
                'first_name'     => $data['name'],
                'last_name'      => $data['surname'],
            )
        );
        wp_safe_redirect(add_query_arg('updated', '1', $redirect_base));
        exit;
    }

    $msg = KIT_Customers::get_last_customer_validation_error() ?: __('Could not update your details.', '08600-services-quotations');
    wp_safe_redirect(
        add_query_arg(
            array(
                'profile_error' => '1',
                'msg'           => rawurlencode($msg),
            ),
            $redirect_base
        )
    );
    exit;
}

/**
 * Render edit profile form for portal customer.
 *
 * @param int $cust_id Linked kit_customers.cust_id.
 * @return string HTML.
 */
function kit_customer_portal_render_edit_profile($cust_id)
{
    global $wpdb;
    $cust_id = (int) $cust_id;
    $table    = $wpdb->prefix . 'kit_customers';
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE cust_id = %d", $cust_id), ARRAY_A);

    if (!$customer) {
        return '<p>' . esc_html__('Customer record not found.', '08600-services-quotations') . '</p>';
    }

    $customer = array_map(
        function ($v) {
            return $v === null ? '' : $v;
        },
        $customer
    );

    if (!class_exists('KIT_Deliveries')) {
        require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
    }

    ob_start();
    ?>
    <div class="kit-customer-portal-wrap">
        <div class="kit-customer-portal-header">
            <h1><?php esc_html_e('Edit your details', '08600-services-quotations'); ?></h1>
            <div class="flex flex-wrap gap-2">
                <a class="button" href="<?php echo esc_url(kit_customer_dashboard_url()); ?>"><?php esc_html_e('Back to My account', '08600-services-quotations'); ?></a>
                <a class="button" href="<?php echo esc_url(wp_logout_url(kit_customer_login_url())); ?>"><?php esc_html_e('Log out', '08600-services-quotations'); ?></a>
            </div>
        </div>

        <?php if (isset($_GET['profile_error'])) : ?>
            <div class="rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 mb-4" role="alert">
                <?php
                $code = sanitize_text_field(wp_unslash($_GET['profile_error']));
                if ($code === 'invalid_email') {
                    esc_html_e('Please enter a valid email address.', '08600-services-quotations');
                } elseif ($code === 'email_in_use') {
                    esc_html_e('That email address is already used by another account.', '08600-services-quotations');
                } elseif ($code === '1' && !empty($_GET['msg'])) {
                    echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['msg']))));
                } else {
                    esc_html_e('Something went wrong. Please try again.', '08600-services-quotations');
                }
                ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden max-w-5xl mx-auto">
            <div class="px-4 py-4 md:px-6 md:py-5 border-b border-gray-200 bg-gray-50">
                <p class="text-sm text-gray-600"><?php esc_html_e('Update your contact and location information. Your login email will match the email you save here.', '08600-services-quotations'); ?></p>
            </div>
            <div class="px-4 py-5 md:px-6 md:py-6">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="space-y-5">
                    <?php wp_nonce_field('update_customer_nonce', 'cust_update_nonce'); ?>
                    <input type="hidden" name="action" value="kit_portal_update_customer" />
                    <input type="hidden" name="customer_id" value="<?php echo esc_attr((string) $cust_id); ?>" />
                    <?php
                    if (function_exists('theForm')) {
                        theForm($customer);
                    }
                    ?>
                    <div class="flex flex-col sm:flex-row justify-end gap-2 pt-5 border-t border-gray-200">
                        <a class="button w-full sm:w-auto text-center" href="<?php echo esc_url(kit_customer_dashboard_url()); ?>"><?php esc_html_e('Cancel', '08600-services-quotations'); ?></a>
                        <?php
                        echo KIT_Commons::renderButton(__('Save changes', '08600-services-quotations'), 'primary', 'md', array(
                            'type'      => 'submit',
                            'name'      => 'kit_portal_customer_submit',
                            'value'     => '1',
                            'gradient'  => true,
                            'classes'   => 'w-full sm:w-auto justify-center',
                        ));
                        ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
