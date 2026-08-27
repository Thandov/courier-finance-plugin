<?php
/**
 * Customer portal dashboard shortcode (single linked customer view).
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode: [kit_customer_portal]
 *
 * @return string
 */
function kit_customer_portal_shortcode()
{
    if (!is_user_logged_in()) {
        $login = kit_customer_login_url();
        ob_start();
        ?>
        <div class="kit-customer-portal-wrap kit-customer-portal-guest">
            <p><?php esc_html_e('Please log in to view your customer portal.', '08600-services-quotations'); ?></p>
            <a class="button button-primary" href="<?php echo esc_url($login); ?>"><?php esc_html_e('Go to customer login', '08600-services-quotations'); ?></a>
        </div>
        <?php
        return ob_get_clean();
    }

    if (!KIT_User_Roles::is_portal_customer() || !KIT_User_Roles::can_access_customer_portal()) {
        return '<div class="kit-customer-portal-wrap"><p>' . esc_html__('This page is only for customer portal accounts.', '08600-services-quotations') . '</p></div>';
    }

    $cust_id = KIT_User_Roles::get_portal_customer_id();
    if ($cust_id <= 0) {
        return '<div class="kit-customer-portal-wrap"><p>' . esc_html__('Your account is not linked to a customer record. Please contact support.', '08600-services-quotations') . '</p></div>';
    }

    if (isset($_GET['edit_profile']) && (string) $_GET['edit_profile'] === '1') {
        return kit_customer_portal_render_edit_profile($cust_id);
    }

    if (isset($_GET['book']) && (string) $_GET['book'] === '1') {
        return kit_customer_portal_render_booking_form($cust_id);
    }

    ob_start();
    ?>
    <div class="kit-customer-portal-wrap">
        <?php if (isset($_GET['booked'])) : ?>
            <div class="rounded-lg border border-green-200 bg-green-50 text-green-800 px-4 py-3 mb-4" role="status">
                <?php esc_html_e('Your quote request was sent. 08600 will follow up.', '08600-services-quotations'); ?>
            </div>
        <?php endif; ?>
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
        <div class="kit-customer-portal-header">
            <h1><?php esc_html_e('My account', '08600-services-quotations'); ?></h1>
            <div class="flex flex-wrap gap-2">
                <a class="button button-primary" href="<?php echo esc_url(add_query_arg('book', '1', kit_customer_dashboard_url())); ?>"><?php esc_html_e('Request a quote', '08600-services-quotations'); ?></a>
                <a class="button" href="<?php echo esc_url(wp_logout_url(kit_customer_login_url())); ?>"><?php esc_html_e('Log out', '08600-services-quotations'); ?></a>
            </div>
        </div>
        <?php
        kit_customer_portal_render_booking_list($cust_id);
        kit_customer_detail_view_render($cust_id, kit_customer_detail_view_default_options_portal());
        ?>
    </div>
    <?php
    return ob_get_clean();
}
