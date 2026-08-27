<?php
/**
 * Frontend customer portal login (08600_portal_customer role).
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode: [kit_customer_login]
 *
 * @return string
 */
function kit_customer_login_shortcode()
{
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return '';
    }

    $force_show = isset($_GET['show_login']) && $_GET['show_login'] === '1';
    if (!$force_show && is_user_logged_in()) {
        if (KIT_User_Roles::is_portal_customer() && KIT_User_Roles::get_portal_customer_id() > 0) {
            wp_safe_redirect(kit_customer_dashboard_url());
            exit;
        }
        if (KIT_User_Roles::can_view_waybills()) {
            wp_safe_redirect(apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/')));
            exit;
        }
        if (KIT_User_Roles::is_admin()) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    $error = '';
    $redirect_to = isset($_GET['redirect_to'])
        ? esc_url_raw(wp_unslash($_GET['redirect_to']))
        : kit_customer_dashboard_url();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kit_customer_login_nonce'])) {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kit_customer_login_nonce'])), 'kit_customer_login')) {
            $error = __('Security check failed. Please try again.', '08600-services-quotations');
        } else {
            $username = isset($_POST['log']) ? sanitize_user(wp_unslash($_POST['log'])) : '';
            $password = isset($_POST['pwd']) ? $_POST['pwd'] : '';

            if ($username === '' || $password === '') {
                $error = __('Please enter your username and password.', '08600-services-quotations');
            } else {
                $user = wp_signon(array(
                    'user_login'    => $username,
                    'user_password' => $password,
                    'remember'      => !empty($_POST['rememberme']),
                ), is_ssl());

                if (is_wp_error($user)) {
                    $error = $user->get_error_message();
                } else {
                    if (!KIT_User_Roles::is_portal_customer($user) || !KIT_User_Roles::can_access_customer_portal($user)) {
                        wp_logout();
                        $error = __('You do not have access to the customer portal.', '08600-services-quotations');
                    } elseif (KIT_User_Roles::get_portal_customer_id($user->ID) <= 0) {
                        wp_logout();
                        $error = __('Your account is not linked to a customer record. Please contact support.', '08600-services-quotations');
                    } else {
                        wp_safe_redirect($redirect_to);
                        exit;
                    }
                }
            }
        }
    }

    ob_start();
    ?>
    <div class="kit-customer-portal-login kit-employee-login-wrap">
        <div class="kit-employee-login-box">
            <h2 class="kit-employee-login-title"><?php esc_html_e('Customer login', '08600-services-quotations'); ?></h2>
            <p class="kit-employee-login-desc"><?php esc_html_e('Sign in to view your shipments and account details.', '08600-services-quotations'); ?></p>

            <?php if ($error) : ?>
                <div class="kit-employee-login-error"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

            <form name="kit_customer_login" action="<?php echo esc_url(get_permalink()); ?>" method="post" class="kit-employee-login-form">
                <?php wp_nonce_field('kit_customer_login', 'kit_customer_login_nonce'); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($redirect_to); ?>">

                <p>
                    <label for="kit_customer_user_login"><?php esc_html_e('Username or email', '08600-services-quotations'); ?></label>
                    <?php echo KIT_Commons::Linput(array(
                        'no_label' => true,
                        'label' => '',
                        'name' => 'log',
                        'id' => 'kit_customer_user_login',
                        'type' => 'text',
                        'value' => '',
                        'preset' => '',
                        'class' => 'input',
                        'special' => 'size="20" autocomplete="username" required',
                    )); ?>
                </p>

                <p>
                    <label for="kit_customer_user_pass"><?php esc_html_e('Password', '08600-services-quotations'); ?></label>
                    <input type="password" name="pwd" id="kit_customer_user_pass" class="input" size="20" autocomplete="current-password" required>
                </p>

                <p class="kit-employee-remember">
                    <label>
                        <?php
                        echo KIT_Commons::Lcheckbox(array(
                            'no_label' => true,
                            'label' => '',
                            'name' => 'rememberme',
                            'omit_id' => true,
                            'value' => 'forever',
                            'class' => 'input',
                        ));
                        ?>
                        <?php esc_html_e('Remember Me', '08600-services-quotations'); ?>
                    </label>
                </p>

                <p class="kit-employee-submit">
                    <input type="submit" name="wp-submit" value="<?php esc_attr_e('Log In', '08600-services-quotations'); ?>" class="button button-primary">
                </p>
                <p>
                    <a href="<?php echo esc_url(wp_lostpassword_url(kit_customer_login_url())); ?>"><?php esc_html_e('Forgot password?', '08600-services-quotations'); ?></a>
                </p>
                <p>
                    <a href="<?php echo esc_url(function_exists('kit_customer_register_url') ? kit_customer_register_url() : home_url('/customer/register/')); ?>"><?php esc_html_e('Create an account', '08600-services-quotations'); ?></a>
                </p>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
