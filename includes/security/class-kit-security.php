<?php
/**
 * Site hardening: login rate limiting and reconnaissance blocking.
 *
 * Every login path (wp-login.php, the employee/customer portal shortcodes and the
 * REST /auth/login route) runs through the core `authenticate` filter, so throttling
 * there covers all of them with one mechanism.
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Security
{
    /** Failed attempts from one IP before it is locked out. */
    const MAX_ATTEMPTS = 5;

    /** How long a locked-out IP stays blocked. */
    const LOCKOUT_SECONDS = 15 * MINUTE_IN_SECONDS;

    /** Failures older than this stop counting towards a lockout. */
    const ATTEMPT_WINDOW = HOUR_IN_SECONDS;

    const ATTEMPT_PREFIX = 'kit_login_fail_';
    const LOCKOUT_PREFIX = 'kit_login_lock_';

    /** Set when this request was refused for being locked out. */
    private static $lockout_notice = '';

    public static function init()
    {
        add_filter('authenticate', [__CLASS__, 'block_locked_out'], 5, 3);
        add_action('wp_login_failed', [__CLASS__, 'record_failure']);
        add_action('wp_login', [__CLASS__, 'clear_failures'], 10, 2);
        add_filter('login_errors', [__CLASS__, 'generic_login_error']);

        // XML-RPC is unused here and is the most-hammered brute force surface.
        // Disabling the methods still leaves the endpoint answering system.* calls,
        // so the request itself is refused.
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('xmlrpc_methods', '__return_empty_array');
        add_filter('wp_headers', [__CLASS__, 'strip_pingback_header']);
        self::block_xmlrpc_request();

        // Username harvesting.
        add_action('template_redirect', [__CLASS__, 'block_author_enumeration']);
        add_filter('rest_endpoints', [__CLASS__, 'hide_user_endpoints']);
        add_filter('oembed_response_data', [__CLASS__, 'strip_oembed_author']);

        remove_action('wp_head', 'wp_generator');
    }

    /**
     * Client IP. Proxy headers are spoofable, so they are only trusted when the
     * site explicitly declares it sits behind a proxy that rewrites them.
     */
    public static function client_ip(): string
    {
        if (defined('KIT_TRUST_PROXY_HEADERS') && KIT_TRUST_PROXY_HEADERS) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                if (empty($_SERVER[$header])) {
                    continue;
                }
                $candidate = trim(explode(',', (string) $_SERVER[$header])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    private static function key(string $prefix): string
    {
        return $prefix . md5(self::client_ip());
    }

    public static function is_locked_out(): bool
    {
        if (self::is_exempt()) {
            return false;
        }

        return (bool) get_transient(self::key(self::LOCKOUT_PREFIX));
    }

    /**
     * Whitelisted IPs (office static IP, for example) skip throttling entirely.
     */
    private static function is_exempt(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        $allowed = apply_filters('kit_security_login_allowlist', []);

        return is_array($allowed) && in_array(self::client_ip(), $allowed, true);
    }

    /**
     * Reject the attempt before any password is checked.
     *
     * @param null|WP_User|WP_Error $user
     * @param string                $username
     * @param string                $password
     * @return null|WP_User|WP_Error
     */
    public static function block_locked_out($user, $username, $password)
    {
        // Cookie-based re-auth passes no credentials; nothing to throttle.
        if ($username === '' && $password === '') {
            return $user;
        }

        if (!self::is_locked_out()) {
            return $user;
        }

        self::$lockout_notice = sprintf(
            /* translators: %d: lockout length in minutes. */
            __('Too many failed login attempts. Try again in %d minutes.', '08600-services-quotations'),
            (int) ceil(self::LOCKOUT_SECONDS / MINUTE_IN_SECONDS)
        );

        return new WP_Error('kit_too_many_attempts', self::$lockout_notice);
    }

    public static function record_failure($username = '')
    {
        if (self::is_exempt()) {
            return;
        }

        $attempts = (int) get_transient(self::key(self::ATTEMPT_PREFIX)) + 1;
        set_transient(self::key(self::ATTEMPT_PREFIX), $attempts, self::ATTEMPT_WINDOW);

        if ($attempts < self::MAX_ATTEMPTS) {
            return;
        }

        set_transient(self::key(self::LOCKOUT_PREFIX), time(), self::LOCKOUT_SECONDS);
        delete_transient(self::key(self::ATTEMPT_PREFIX));

        do_action('kit_security_login_lockout', self::client_ip(), (string) $username);
    }

    public static function clear_failures($user_login = '', $user = null)
    {
        delete_transient(self::key(self::ATTEMPT_PREFIX));
    }

    /**
     * Core reveals whether a username exists. Bots use that to build target lists.
     */
    public static function generic_login_error($error)
    {
        if (self::$lockout_notice !== '') {
            return self::$lockout_notice;
        }

        return __('Invalid username, email address or password.', '08600-services-quotations');
    }

    /**
     * Refuse XML-RPC before the server is built. Filterable so a future integration
     * that genuinely needs it (Jetpack, the WordPress mobile app) can opt back in.
     */
    private static function block_xmlrpc_request(): void
    {
        if (!defined('XMLRPC_REQUEST') && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'xmlrpc.php') {
            return;
        }

        if (apply_filters('kit_security_allow_xmlrpc', false)) {
            return;
        }

        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "XML-RPC is disabled on this site.\n";
        exit;
    }

    public static function strip_pingback_header($headers)
    {
        unset($headers['X-Pingback']);

        return $headers;
    }

    /**
     * /?author=1 redirects to the author archive and leaks the login slug.
     */
    public static function block_author_enumeration()
    {
        if (is_admin() || is_user_logged_in()) {
            return;
        }

        if (!empty($_GET['author']) || is_author()) {
            wp_safe_redirect(home_url('/'), 301);
            exit;
        }
    }

    /**
     * Anonymous callers do not need the core user directory.
     */
    public static function hide_user_endpoints($endpoints)
    {
        if (is_user_logged_in()) {
            return $endpoints;
        }

        unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);

        return $endpoints;
    }

    public static function strip_oembed_author($data)
    {
        unset($data['author_name'], $data['author_url']);

        return $data;
    }
}
