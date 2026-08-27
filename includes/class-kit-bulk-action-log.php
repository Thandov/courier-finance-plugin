<?php
/**
 * Append-only audit log for confirmed bulk table actions.
 *
 * Records the WordPress user who clicked OK (get_current_user_id),
 * the action, and the affected ids. Sister to kit_waybills.last_updated_by
 * / status_userid — those columns are overwritten; this table keeps history.
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Bulk_Action_Log
{
    const TABLE = 'kit_bulk_action_log';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function ensure_table(): void
    {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(50) NOT NULL,
            entity VARCHAR(50) NOT NULL DEFAULT 'waybill',
            item_ids TEXT NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            context VARCHAR(191) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_action_created (action, created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @param string   $action
     * @param string[] $item_ids
     * @param string   $entity
     * @param string   $context  Short page/context label (e.g. view-deliveries:33)
     */
    public static function record(string $action, array $item_ids, string $entity = 'waybill', string $context = ''): int
    {
        global $wpdb;

        $action = sanitize_key($action);
        if ($action === '') {
            return 0;
        }

        self::ensure_table();

        $ids = array_values(array_unique(array_filter(array_map(static function ($id) {
            return sanitize_text_field((string) $id);
        }, $item_ids))));

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'user_id'    => (int) get_current_user_id(),
                'action'     => $action,
                'entity'     => sanitize_key($entity) ?: 'waybill',
                'item_ids'   => implode(',', $ids),
                'item_count' => count($ids),
                'context'    => substr(sanitize_text_field($context), 0, 191),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function current_context(): string
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $delivery_id = isset($_GET['delivery_id']) ? (int) $_GET['delivery_id'] : 0;
        if ($page !== '' && $delivery_id > 0) {
            return $page . ':' . $delivery_id;
        }
        return $page;
    }
}

add_action('wp_ajax_kit_log_bulk_action', static function () {
    if (!current_user_can('kit_view_waybills') && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'bulk_action_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    $action = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
    $raw = isset($_POST['bulk_ids']) ? sanitize_text_field(wp_unslash($_POST['bulk_ids'])) : '';
    $ids = array_filter(array_map('trim', explode(',', $raw)));
    $entity = isset($_POST['entity']) ? sanitize_key(wp_unslash($_POST['entity'])) : 'waybill';
    KIT_Bulk_Action_Log::record($action, $ids, $entity, KIT_Bulk_Action_Log::current_context());
    wp_send_json_success(['ok' => true, 'user_id' => (int) get_current_user_id()]);
});
