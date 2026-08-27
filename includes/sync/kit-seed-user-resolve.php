<?php
/**
 * Resolve WordPress user IDs from kit_* sheet audit columns.
 *
 * Sheet cells may hold a numeric user id or a display name / login
 * (e.g. Waybills "Created By" = "Sinazo Ntsomi").
 */

if (!function_exists('kit_resolve_wp_user_id')) {
    /**
     * @param mixed $raw Cell value from Google Sheet (id, name, or login).
     */
    function kit_resolve_wp_user_id($raw): int
    {
        static $cache = [];

        global $wpdb;

        $s = trim((string) $raw);
        if ($s === '' || strtolower($s) === 'null' || strtolower($s) === 'n/a') {
            return 0;
        }
        if (is_numeric($s)) {
            $id = (int) $s;

            return $id > 0 ? $id : 0;
        }

        $needle = strtolower($s);
        if (isset($cache[$needle])) {
            return (int) $cache[$needle];
        }

        $users_table = $wpdb->users;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$users_table} WHERE LOWER(user_login) = %s OR LOWER(display_name) = %s LIMIT 1",
            $needle,
            $needle
        ));
        if ($id > 0) {
            $cache[$needle] = $id;

            return $id;
        }

        $like = '%' . $wpdb->esc_like($needle) . '%';
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$users_table} WHERE LOWER(display_name) LIKE %s ORDER BY LENGTH(display_name) ASC LIMIT 1",
            $like
        ));
        $cache[$needle] = $id > 0 ? $id : 0;

        return $id > 0 ? $id : 0;
    }
}
