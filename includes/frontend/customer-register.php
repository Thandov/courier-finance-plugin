<?php
/**
 * Public customer portal registration.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return int Positive cust_id or 0.
 */
function kit_customer_find_cust_id_by_email($email)
{
    global $wpdb;
    $email = sanitize_email($email);
    if ($email === '' || !is_email($email)) {
        return 0;
    }
    $table = $wpdb->prefix . 'kit_customers';
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT cust_id FROM {$table} WHERE email_address = %s LIMIT 1",
            $email
        )
    );
}

/**
 * Shortcode: [kit_customer_register]
 *
 * @return string
 */
function kit_customer_register_shortcode()
{
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return '';
    }

    if (is_user_logged_in()) {
        if (class_exists('KIT_User_Roles') && KIT_User_Roles::is_portal_customer() && KIT_User_Roles::get_portal_customer_id() > 0) {
            wp_safe_redirect(kit_customer_dashboard_url());
            exit;
        }
        if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_view_waybills()) {
            wp_safe_redirect(apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/')));
            exit;
        }
    }

    $error = '';
    $posted = array(
        'name'     => '',
        'surname'  => '',
        'email'    => '',
        'cell'     => '',
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kit_customer_register_nonce'])) {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kit_customer_register_nonce'])), 'kit_customer_register')) {
            $error = __('Security check failed. Please try again.', '08600-services-quotations');
        } else {
            $posted['name'] = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $posted['surname'] = sanitize_text_field(wp_unslash($_POST['surname'] ?? ''));
            $posted['email'] = sanitize_email(trim(wp_unslash($_POST['email'] ?? '')));
            $posted['cell'] = sanitize_text_field(wp_unslash($_POST['cell'] ?? ''));
            $password = isset($_POST['pwd']) ? (string) $_POST['pwd'] : '';
            $password2 = isset($_POST['pwd_confirm']) ? (string) $_POST['pwd_confirm'] : '';

            if ($posted['name'] === '' || $posted['surname'] === '') {
                $error = __('Please enter your first and last name.', '08600-services-quotations');
            } elseif ($posted['email'] === '' || !is_email($posted['email'])) {
                $error = __('Please enter a valid email address.', '08600-services-quotations');
            } elseif ($posted['cell'] === '') {
                $error = __('Please enter a phone number.', '08600-services-quotations');
            } elseif (strlen($password) < 8) {
                $error = __('Password must be at least 8 characters.', '08600-services-quotations');
            } elseif ($password !== $password2) {
                $error = __('Passwords do not match.', '08600-services-quotations');
            } elseif (email_exists($posted['email'])) {
                $error = sprintf(
                    /* translators: %s: login URL */
                    __('That email already has a login. Please <a href="%s">log in</a> or reset your password.', '08600-services-quotations'),
                    esc_url(kit_customer_login_url())
                );
            } else {
                $existing_cust = kit_customer_find_cust_id_by_email($posted['email']);
                if ($existing_cust > 0) {
                    $error = __('This email is already on file. Ask 08600 to activate your portal login, then sign in. Do not create a second profile.', '08600-services-quotations');
                } else {
                    $cust_id = KIT_Customers::save_customer(
                        array(
                            'name'          => $posted['name'],
                            'surname'       => $posted['surname'],
                            'email_address' => $posted['email'],
                            'cell'          => $posted['cell'],
                        )
                    );
                    if (!$cust_id) {
                        $msg = KIT_Customers::get_last_customer_validation_error();
                        $error = $msg ?: __('Could not create your customer profile. Please contact support.', '08600-services-quotations');
                    } else {
                        $login = 'cust_' . (int) $cust_id;
                        if (username_exists($login)) {
                            $login = 'cust_' . (int) $cust_id . '_' . wp_generate_password(4, false, false);
                        }
                        $uid = wp_insert_user(
                            array(
                                'user_login' => $login,
                                'user_email' => $posted['email'],
                                'user_pass'  => $password,
                                'first_name' => $posted['name'],
                                'last_name'  => $posted['surname'],
                                'role'       => '08600_portal_customer',
                            )
                        );
                        if (is_wp_error($uid)) {
                            $error = $uid->get_error_message();
                        } else {
                            update_user_meta((int) $uid, 'kit_customer_id', (int) $cust_id);
                            wp_set_current_user((int) $uid);
                            wp_set_auth_cookie((int) $uid, true);
                            wp_safe_redirect(kit_customer_dashboard_url());
                            exit;
                        }
                    }
                }
            }
        }
    }

    ob_start();
    ?>
    <div class="kit-customer-portal-login kit-employee-login-wrap">
        <div class="kit-employee-login-box">
            <h2 class="kit-employee-login-title"><?php esc_html_e('Create your account', '08600-services-quotations'); ?></h2>
            <p class="kit-employee-login-desc"><?php esc_html_e('Register to request quotes and manage your details.', '08600-services-quotations'); ?></p>

            <?php if ($error) : ?>
                <div class="kit-employee-login-error"><?php echo wp_kses($error, array('a' => array('href' => array()))); ?></div>
            <?php endif; ?>

            <form name="kit_customer_register" action="<?php echo esc_url(get_permalink()); ?>" method="post" class="kit-employee-login-form">
                <?php wp_nonce_field('kit_customer_register', 'kit_customer_register_nonce'); ?>
                <p>
                    <label for="kit_reg_name"><?php esc_html_e('First name', '08600-services-quotations'); ?></label>
                    <input type="text" name="name" id="kit_reg_name" class="input" required value="<?php echo esc_attr($posted['name']); ?>" autocomplete="given-name">
                </p>
                <p>
                    <label for="kit_reg_surname"><?php esc_html_e('Last name', '08600-services-quotations'); ?></label>
                    <input type="text" name="surname" id="kit_reg_surname" class="input" required value="<?php echo esc_attr($posted['surname']); ?>" autocomplete="family-name">
                </p>
                <p>
                    <label for="kit_reg_email"><?php esc_html_e('Email', '08600-services-quotations'); ?></label>
                    <input type="email" name="email" id="kit_reg_email" class="input" required value="<?php echo esc_attr($posted['email']); ?>" autocomplete="email">
                </p>
                <p>
                    <label for="kit_reg_cell"><?php esc_html_e('Phone', '08600-services-quotations'); ?></label>
                    <input type="text" name="cell" id="kit_reg_cell" class="input" required value="<?php echo esc_attr($posted['cell']); ?>" autocomplete="tel">
                </p>
                <p>
                    <label for="kit_reg_pwd"><?php esc_html_e('Password', '08600-services-quotations'); ?></label>
                    <input type="password" name="pwd" id="kit_reg_pwd" class="input" required autocomplete="new-password" minlength="8">
                </p>
                <p>
                    <label for="kit_reg_pwd2"><?php esc_html_e('Confirm password', '08600-services-quotations'); ?></label>
                    <input type="password" name="pwd_confirm" id="kit_reg_pwd2" class="input" required autocomplete="new-password" minlength="8">
                </p>
                <p class="kit-employee-submit">
                    <input type="submit" value="<?php esc_attr_e('Create account', '08600-services-quotations'); ?>" class="button button-primary">
                </p>
                <p>
                    <a href="<?php echo esc_url(kit_customer_login_url()); ?>"><?php esc_html_e('Already have an account? Log in', '08600-services-quotations'); ?></a>
                </p>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
