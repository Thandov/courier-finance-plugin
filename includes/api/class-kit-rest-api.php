<?php
/**
 * REST API for the 08600 desktop app (headless — no WordPress UI).
 *
 * @deprecated Superseded by the standalone Node API in 08600Solution/server/.
 *             The Electron desktop app no longer uses this layer. Kept temporarily
 *             for backward compatibility during migration; safe to remove once all
 *             clients use the Node API on apiPort (default 18761).
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_REST_API {
    const NS = 'kit/v1';
    const TOKEN_PREFIX = 'kit_api_token_';
    const TOKEN_TTL = DAY_IN_SECONDS;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('rest_pre_serve_request', [__CLASS__, 'send_cors_headers'], 15);
    }

    public static function send_cors_headers($value) {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
        $allowed = [
            'http://127.0.0.1:5173',
            'http://localhost:5173',
        ];
        if ($origin && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
            header('Access-Control-Allow-Credentials: true');
        }
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(204);
            exit;
        }
        return $value;
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/auth/login', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/auth/me', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'me'],
            'permission_callback' => [__CLASS__, 'require_auth'],
        ]);

        register_rest_route(self::NS, '/auth/logout', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'logout'],
            'permission_callback' => [__CLASS__, 'require_auth'],
        ]);

        register_rest_route(self::NS, '/waybills/create-meta', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'waybill_create_meta'],
            'permission_callback' => [__CLASS__, 'require_auth'],
        ]);

        register_rest_route(self::NS, '/waybills', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_waybill'],
            'permission_callback' => [__CLASS__, 'require_auth'],
        ]);

        register_rest_route(self::NS, '/customers/search', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'search_customers'],
            'permission_callback' => [__CLASS__, 'require_auth'],
        ]);
    }

    public static function login(WP_REST_Request $request) {
        $username = sanitize_user((string) $request->get_param('username'));
        $password = (string) $request->get_param('password');

        if ($username === '' || $password === '') {
            return new WP_Error('missing_credentials', 'Username and password are required.', ['status' => 400]);
        }

        $user = wp_authenticate($username, $password);
        if (is_wp_error($user)) {
            return new WP_Error('invalid_login', 'Invalid username or password.', ['status' => 401]);
        }

        $roles = (array) $user->roles;
        $allowed = in_array('administrator', $roles, true)
            || in_array('data_capturer', $roles, true)
            || in_array('manager', $roles, true);

        if (!$allowed) {
            return new WP_Error('forbidden', 'You do not have access to the employee portal.', ['status' => 403]);
        }

        $token = wp_generate_password(48, false, false);
        set_transient(self::TOKEN_PREFIX . $token, (int) $user->ID, self::TOKEN_TTL);

        return rest_ensure_response([
            'token' => $token,
            'user' => self::format_user($user),
        ]);
    }

    public static function me(WP_REST_Request $request) {
        $user = wp_get_current_user();
        return rest_ensure_response(['user' => self::format_user($user)]);
    }

    public static function logout(WP_REST_Request $request) {
        $token = self::extract_bearer_token($request);
        if ($token) {
            delete_transient(self::TOKEN_PREFIX . $token);
        }
        return rest_ensure_response(['ok' => true]);
    }

    public static function waybill_create_meta(WP_REST_Request $request) {
        if (!class_exists('KIT_Waybills')) {
            return new WP_Error('unavailable', 'Waybill service unavailable.', ['status' => 500]);
        }

        return rest_ensure_response([
            'next_waybill_no' => KIT_Waybills::generate_waybill_number(),
            'sadc_certificate_charge' => (float) KIT_Waybills::sadc_certificate(),
            'sad500_charge' => (float) KIT_Waybills::sad(),
            'currency' => class_exists('KIT_Commons') ? KIT_Commons::currency() : 'R',
        ]);
    }

    public static function create_waybill(WP_REST_Request $request) {
        if (!current_user_can('kit_update_data') && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'You are not allowed to create waybills.', ['status' => 403]);
        }

        $cust_id = (int) $request->get_param('cust_id');
        if ($cust_id <= 0) {
            return new WP_Error('validation_error', 'Please select a customer.', ['status' => 400]);
        }

        $description = sanitize_textarea_field((string) $request->get_param('waybill_description'));

        $data = [
            'pending' => 1,
            'cust_id' => $cust_id,
            'customer_select' => 'existing',
            'waybill_description' => $description,
            'include_sad500' => $request->get_param('include_sad500') ? 1 : 0,
            'include_sadc' => $request->get_param('include_sadc') ? 1 : 0,
            'vat_include' => $request->get_param('vat_include') ? 1 : 0,
            'status' => 'pending',
        ];

        $result = KIT_Waybills::save_or_update_waybill($data);
        if (is_wp_error($result)) {
            return new WP_Error(
                $result->get_error_code() ?: 'create_failed',
                $result->get_error_message(),
                ['status' => 400]
            );
        }

        return rest_ensure_response([
            'ok' => true,
            'waybill_no' => $result['waybill_no'] ?? null,
            'waybill_id' => $result['id'] ?? null,
            'message' => sprintf(
                'Waybill #%s created successfully.',
                $result['waybill_no'] ?? ''
            ),
        ]);
    }

    public static function search_customers(WP_REST_Request $request) {
        global $wpdb;

        $q = sanitize_text_field((string) $request->get_param('q'));
        $table = $wpdb->prefix . 'kit_customers';
        $companies = $wpdb->prefix . 'kit_company_customers';
        $like = '%' . $wpdb->esc_like($q) . '%';

        if ($q === '') {
            $rows = $wpdb->get_results(
                "SELECT c.cust_id, c.name, c.surname, co.company_name, c.cell, c.email_address
                 FROM {$table} c
                 LEFT JOIN {$companies} co ON c.company_id = co.company_id
                 ORDER BY co.company_name ASC, c.name ASC
                 LIMIT 25",
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT c.cust_id, c.name, c.surname, co.company_name, c.cell, c.email_address
                     FROM {$table} c
                     LEFT JOIN {$companies} co ON c.company_id = co.company_id
                     WHERE c.name LIKE %s OR c.surname LIKE %s OR co.company_name LIKE %s OR c.cell LIKE %s
                     ORDER BY co.company_name ASC, c.name ASC
                     LIMIT 25",
                    $like,
                    $like,
                    $like,
                    $like
                ),
                ARRAY_A
            );
        }

        $customers = array_map(static function ($row) {
            $label = trim((string) ($row['company_name'] ?? ''));
            if ($label === '') {
                $label = trim(((string) ($row['name'] ?? '')) . ' ' . ((string) ($row['surname'] ?? '')));
            }
            return [
                'cust_id' => (int) $row['cust_id'],
                'label' => $label,
                'name' => (string) ($row['name'] ?? ''),
                'surname' => (string) ($row['surname'] ?? ''),
                'company_name' => (string) ($row['company_name'] ?? ''),
                'cell' => (string) ($row['cell'] ?? ''),
                'email_address' => (string) ($row['email_address'] ?? ''),
            ];
        }, $rows ?: []);

        return rest_ensure_response(['customers' => $customers]);
    }

    public static function require_auth(WP_REST_Request $request) {
        $user_id = self::resolve_user_id($request);
        if (!$user_id) {
            return new WP_Error('unauthorized', 'Authentication required.', ['status' => 401]);
        }
        wp_set_current_user($user_id);
        return true;
    }

    private static function resolve_user_id(WP_REST_Request $request) {
        $token = self::extract_bearer_token($request);
        if (!$token) {
            return 0;
        }
        $user_id = get_transient(self::TOKEN_PREFIX . $token);
        return $user_id ? (int) $user_id : 0;
    }

    private static function extract_bearer_token(WP_REST_Request $request) {
        $header = (string) $request->get_header('authorization');
        if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return sanitize_text_field($matches[1]);
        }
        return '';
    }

    private static function format_user(WP_User $user) {
        return [
            'id' => (int) $user->ID,
            'username' => $user->user_login,
            'display_name' => $user->display_name,
            'roles' => array_values((array) $user->roles),
        ];
    }
}

KIT_REST_API::init();
