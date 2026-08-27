<?php
/**
 * Customer portal booking request form.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

function kit_customer_portal_enqueue_booking_assets()
{
    if (!function_exists('is_singular') || !is_singular()) {
        return;
    }
    if (!isset($_GET['book']) || (string) $_GET['book'] !== '1') {
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

function kit_customer_portal_handle_submit_booking()
{
    if (!is_user_logged_in()) {
        wp_die('Forbidden', 403);
    }
    if (!class_exists('KIT_User_Roles') || !KIT_User_Roles::is_portal_customer() || !KIT_User_Roles::can_access_customer_portal()) {
        wp_die('Forbidden', 403);
    }
    if (!current_user_can('kit_submit_booking_request')) {
        wp_die('Forbidden', 403);
    }
    if (!isset($_POST['kit_booking_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kit_booking_nonce'])), 'kit_portal_booking')) {
        wp_die('Security check failed', 403);
    }

    $cust_id = KIT_User_Roles::get_portal_customer_id();
    $posted_cust = (int) ($_POST['customer_id'] ?? 0);
    if ($cust_id <= 0 || $posted_cust !== $cust_id) {
        wp_die('Forbidden', 403);
    }

    $redirect_base = kit_customer_dashboard_url();

    $origin_country = (int) ($_POST['origin_country'] ?? 0);
    $origin_city = (int) ($_POST['origin_city'] ?? 0);
    $dest_country = (int) ($_POST['destination_country'] ?? 0);
    $dest_city = (int) ($_POST['destination_city'] ?? 0);

    if ($origin_country <= 0 || $origin_city <= 0 || $dest_country <= 0 || $dest_city <= 0) {
        wp_safe_redirect(add_query_arg(array('book' => '1', 'book_error' => 'route'), $redirect_base));
        exit;
    }

    $id = KIT_Booking_Requests::insert(
        array(
            'customer_id'            => $cust_id,
            'origin_country_id'      => $origin_country,
            'origin_city_id'         => $origin_city,
            'destination_country_id' => $dest_country,
            'destination_city_id'    => $dest_city,
            'parcel_count'           => $_POST['parcel_count'] ?? 1,
            'weight_kg'              => $_POST['weight_kg'] ?? '',
            'length_cm'              => $_POST['length_cm'] ?? '',
            'width_cm'               => $_POST['width_cm'] ?? '',
            'height_cm'              => $_POST['height_cm'] ?? '',
            'service_note'           => $_POST['service_note'] ?? '',
            'notes'                  => $_POST['notes'] ?? '',
        )
    );

    if (!$id) {
        wp_safe_redirect(add_query_arg(array('book' => '1', 'book_error' => '1'), $redirect_base));
        exit;
    }

    wp_safe_redirect(add_query_arg('booked', '1', $redirect_base));
    exit;
}

/**
 * @param int $cust_id
 * @return string
 */
function kit_customer_portal_render_booking_form($cust_id)
{
    $cust_id = (int) $cust_id;
    if (!class_exists('KIT_Deliveries')) {
        require_once plugin_dir_path(__FILE__) . '../deliveries/deliveries-functions.php';
    }

    ob_start();
    ?>
    <div class="kit-customer-portal-wrap">
        <div class="kit-customer-portal-header">
            <h1><?php esc_html_e('Request a quote', '08600-services-quotations'); ?></h1>
            <div class="flex flex-wrap gap-2">
                <a class="button" href="<?php echo esc_url(kit_customer_dashboard_url()); ?>"><?php esc_html_e('Back to My account', '08600-services-quotations'); ?></a>
            </div>
        </div>
        <?php if (isset($_GET['book_error'])) : ?>
            <div class="rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 mb-4" role="alert">
                <?php
                if ((string) $_GET['book_error'] === 'route') {
                    esc_html_e('Please select origin and destination country and city.', '08600-services-quotations');
                } else {
                    esc_html_e('Could not send your request. Please try again.', '08600-services-quotations');
                }
                ?>
            </div>
        <?php endif; ?>
        <p class="text-sm text-gray-600 mb-4"><?php esc_html_e('Tell us where the shipment is going. 08600 will turn this into a waybill and confirm pricing.', '08600-services-quotations'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kit-booking-form space-y-5 bg-white border border-gray-200 rounded-lg p-4 md:p-6">
            <?php wp_nonce_field('kit_portal_booking', 'kit_booking_nonce'); ?>
            <input type="hidden" name="action" value="kit_portal_submit_booking">
            <input type="hidden" name="customer_id" value="<?php echo esc_attr((string) $cust_id); ?>">

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <h2 class="text-base font-semibold mb-2"><?php esc_html_e('From', '08600-services-quotations'); ?></h2>
                    <?php
                    echo KIT_Deliveries::selectAllCountries('origin_country', 'origin_country_select', 1, 'required', 'origin', array());
                    echo KIT_Deliveries::selectAllCitiesByCountry('origin_city', 'origin_city_select', 1, 0);
                    ?>
                </div>
                <div>
                    <h2 class="text-base font-semibold mb-2"><?php esc_html_e('To', '08600-services-quotations'); ?></h2>
                    <?php
                    echo KIT_Deliveries::selectAllCountries('destination_country', 'destination_country_select', 1, 'required', 'destination', array());
                    echo KIT_Deliveries::selectAllCitiesByCountry('destination_city', 'destination_city_select', 1, 0, 'required');
                    ?>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <p>
                    <label for="parcel_count"><?php esc_html_e('Number of parcels', '08600-services-quotations'); ?></label>
                    <input type="number" min="1" name="parcel_count" id="parcel_count" class="input w-full" value="1" required>
                </p>
                <p>
                    <label for="weight_kg"><?php esc_html_e('Total weight (kg)', '08600-services-quotations'); ?></label>
                    <input type="number" step="0.01" min="0" name="weight_kg" id="weight_kg" class="input w-full">
                </p>
                <p>
                    <label for="length_cm"><?php esc_html_e('Length (cm)', '08600-services-quotations'); ?></label>
                    <input type="number" step="0.01" min="0" name="length_cm" id="length_cm" class="input w-full">
                </p>
                <p>
                    <label for="width_cm"><?php esc_html_e('Width (cm)', '08600-services-quotations'); ?></label>
                    <input type="number" step="0.01" min="0" name="width_cm" id="width_cm" class="input w-full">
                </p>
                <p>
                    <label for="height_cm"><?php esc_html_e('Height (cm)', '08600-services-quotations'); ?></label>
                    <input type="number" step="0.01" min="0" name="height_cm" id="height_cm" class="input w-full">
                </p>
                <p>
                    <label for="service_note"><?php esc_html_e('Service note', '08600-services-quotations'); ?></label>
                    <input type="text" name="service_note" id="service_note" class="input w-full" maxlength="255" placeholder="<?php esc_attr_e('e.g. express, pallet', '08600-services-quotations'); ?>">
                </p>
            </div>
            <p>
                <label for="booking_notes"><?php esc_html_e('Notes', '08600-services-quotations'); ?></label>
                <textarea name="notes" id="booking_notes" class="input w-full" rows="4"></textarea>
            </p>
            <p>
                <?php
                echo KIT_Commons::renderButton(__('Send request', '08600-services-quotations'), 'primary', 'md', array(
                    'type'     => 'submit',
                    'gradient' => true,
                ));
                ?>
            </p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * @param int $cust_id
 */
function kit_customer_portal_render_booking_list($cust_id)
{
    $rows = KIT_Booking_Requests::list_for_customer((int) $cust_id);
    if (empty($rows)) {
        return;
    }
    echo '<div class="kit-booking-list mb-6">';
    echo '<h2 class="text-lg font-semibold mb-3">' . esc_html__('Your quote requests', '08600-services-quotations') . '</h2>';
    echo '<ul class="divide-y border border-gray-200 rounded-lg bg-white">';
    foreach ($rows as $row) {
        $from = KIT_Booking_Requests::city_label($row['origin_city_id'] ?? 0);
        $to = KIT_Booking_Requests::city_label($row['destination_city_id'] ?? 0);
        $status = (string) ($row['status'] ?? 'pending');
        echo '<li class="px-4 py-3 flex flex-wrap justify-between gap-2">';
        echo '<span>#' . (int) $row['id'] . ' — ' . esc_html($from) . ' → ' . esc_html($to) . '</span>';
        echo '<span class="text-sm text-gray-600">' . esc_html($status) . '</span>';
        echo '</li>';
    }
    echo '</ul></div>';
}
