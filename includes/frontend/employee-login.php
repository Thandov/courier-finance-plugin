<?php
/**
 * Frontend Employee Login
 * Custom login form for employees (data_capturer, manager) - no wp-admin access.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render employee login form shortcode
 *
 * @return string
 */
function kit_employee_login_shortcode() {
    // Never redirect during REST API requests (block editor save/preview) or the response would not be valid JSON
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return '';
    }
    // Allow viewing the login form when already logged in (e.g. for testing) via ?show_login=1
    $force_show_login = isset($_GET['show_login']) && $_GET['show_login'] === '1';
    // Same login for all: admins → wp-admin, staff → front-end dashboard (check admin first)
    if (!$force_show_login && is_user_logged_in()) {
        if (KIT_User_Roles::is_admin()) {
            wp_safe_redirect(admin_url());
            exit;
        }
        if (KIT_User_Roles::can_view_waybills()) {
            $dashboard_url = apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
            wp_safe_redirect($dashboard_url);
            exit;
        }
    }

    $error = '';
    $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kit_employee_login_nonce'])) {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kit_employee_login_nonce'])), 'kit_employee_login')) {
            $error = __('Security check failed. Please try again.', '08600-services-quotations');
        } else {
            $username = isset($_POST['log']) ? sanitize_user(wp_unslash($_POST['log'])) : '';
            $password = isset($_POST['pwd']) ? $_POST['pwd'] : '';

            if (empty($username) || empty($password)) {
                $error = __('Please enter your username and password.', '08600-services-quotations');
            } else {
                $user = wp_signon([
                    'user_login'    => $username,
                    'user_password' => $password,
                    'remember'      => !empty($_POST['rememberme']),
                ], is_ssl());

                if (is_wp_error($user)) {
                    $error = $user->get_error_message();
                } else {
                    // Check if user has plugin access (kit_view_waybills)
                    $roles = (array) $user->roles;
                    $has_access = in_array('administrator', $roles) || in_array('data_capturer', $roles) || in_array('manager', $roles);

                    if (!$has_access) {
                        wp_logout();
                        $error = __('You do not have access to the employee portal.', '08600-services-quotations');
                    } else {
                        $dashboard_url = apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
                        if (KIT_User_Roles::is_admin()) {
                            $dashboard_url = admin_url();
                        }
                        wp_safe_redirect($dashboard_url);
                        exit;
                    }
                }
            }
        }
    }

    ob_start();
    ?>
    <div class="kit-employee-login-wrap">
        <div class="kit-employee-login-box">
            <h2 class="kit-employee-login-title"><?php esc_html_e('Login', '08600-services-quotations'); ?></h2>
            <p class="kit-employee-login-desc"><?php esc_html_e('Sign in to access the 08600 dashboard', '08600-services-quotations'); ?></p>

            <?php if ($error) : ?>
                <div class="kit-employee-login-error"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

            <form name="kit_employee_login" action="<?php echo esc_url(get_permalink()); ?>" method="post" class="kit-employee-login-form">
                <?php wp_nonce_field('kit_employee_login', 'kit_employee_login_nonce'); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($redirect_to); ?>">

                <p>
                    <label for="kit_user_login"><?php esc_html_e('Username', '08600-services-quotations'); ?></label>
                    <?php echo KIT_Commons::Linput([
                        'no_label' => true,
                        'label' => '',
                        'name' => 'log',
                        'id' => 'kit_user_login',
                        'type' => 'text',
                        'value' => '',
                        'preset' => '',
                        'class' => 'input',
                        'special' => 'size="20" autocomplete="username" required',
                    ]); ?>
                </p>

                <p>
                    <label for="kit_user_pass"><?php esc_html_e('Password', '08600-services-quotations'); ?></label>
                    <input type="password" name="pwd" id="kit_user_pass" class="input" size="20" autocomplete="current-password" required>
                </p>

                <p class="kit-employee-remember">
                    <label>
                        <?php
                        echo KIT_Commons::Lcheckbox([
                            'no_label' => true,
                            'label' => '',
                            'name' => 'rememberme',
                            'omit_id' => true,
                            'value' => 'forever',
                            'class' => 'input',
                        ]);
                        ?>
                        <?php esc_html_e('Remember Me', '08600-services-quotations'); ?>
                    </label>
                </p>

                <p class="kit-employee-submit">
                    <input type="submit" name="wp-submit" value="<?php esc_attr_e('Log In', '08600-services-quotations'); ?>" class="button button-primary">
                </p>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
