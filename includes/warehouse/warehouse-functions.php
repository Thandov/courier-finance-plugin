<?php
/**
 * Warehouse Management Functions
 * Handles warehouse operations using kit_waybills table
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Warehouse
{
    /**
     * Add waybill to warehouse.
     *
     * `warehouse` is TINYINT(1): 1 = in warehouse, 0 = not. Location strings must not be stored here.
     *
     * @param int         $waybill_id
     * @param string|null $warehouse_location Unused legacy argument (ignored).
     */
    public static function addToWarehouse($waybill_id, $warehouse_location = null)
    {
        global $wpdb;
        unset($warehouse_location);

        $waybills_table = $wpdb->prefix . 'kit_waybills';

        $result = $wpdb->update(
            $waybills_table,
            [
                'status' => 'pending',
                'warehouse' => 1,
            ],
            ['id' => (int) $waybill_id],
            ['%s', '%d'],
            ['%d']
        );

        if ($result === false) {
            error_log('Failed to add waybill to warehouse: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Failed to add waybill to warehouse');
        }

        return true;
    }

    /**
     * Move waybills off a truck into warehouse (clears delivery_id).
     *
     * @param string[] $tokens waybill_no values and/or numeric kit_waybills.id values
     * @return int Number of waybills updated
     */
    public static function moveWaybillsToWarehouse(array $tokens): int
    {
        global $wpdb;

        $tokens = array_values(array_unique(array_filter(array_map(static function ($token) {
            return sanitize_text_field((string) $token);
        }, $tokens))));

        if ($tokens === []) {
            return 0;
        }

        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $numeric_tokens = array_values(array_filter($tokens, static function ($token) {
            return ctype_digit($token);
        }));

        $or_clauses = [];
        $query_params = [];

        $string_placeholders = implode(',', array_fill(0, count($tokens), '%s'));
        $or_clauses[] = "waybill_no IN ($string_placeholders)";
        $query_params = array_merge($query_params, $tokens);

        if ($numeric_tokens !== []) {
            $int_placeholders = implode(',', array_fill(0, count($numeric_tokens), '%d'));
            $or_clauses[] = "id IN ($int_placeholders)";
            $query_params = array_merge($query_params, array_map('intval', $numeric_tokens));
        }

        $query = $wpdb->prepare(
            "SELECT id FROM $waybills_table WHERE " . implode(' OR ', $or_clauses),
            $query_params
        );
        $ids = array_values(array_unique(array_map('intval', (array) $wpdb->get_col($query))));

        if ($ids === []) {
            return 0;
        }

        $updated = 0;
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        foreach ($ids as $waybill_id) {
            if ($waybill_id <= 0) {
                continue;
            }
            $result = $wpdb->update(
                $waybills_table,
                [
                    'delivery_id'     => 0,
                    'warehouse'       => 1,
                    'status'          => 'pending',
                    'status_userid'   => $user_id,
                    'last_updated_at' => $now,
                    'last_updated_by' => $user_id,
                ],
                ['id' => $waybill_id],
                ['%d', '%d', '%s', '%d', '%s', '%d'],
                ['%d']
            );
            if ($result !== false) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Get waybills currently in warehouse (warehouse = 1).
     *
     * @param int|null $waybill_id Optional. When set, return that waybill only if it is in warehouse.
     *                             Callers historically passed an ID here; never a location string.
     * @return array<object>
     */
    public static function getWarehouseItems($waybill_id = null)
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $customers_table = $wpdb->prefix . 'kit_customers';

        $where_conditions = ['w.warehouse = 1'];
        $params = [];

        $waybill_id = $waybill_id !== null && $waybill_id !== '' ? (int) $waybill_id : 0;
        if ($waybill_id > 0) {
            $where_conditions[] = 'w.id = %d';
            $params[] = $waybill_id;
        }

        $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);

        $query = "
            SELECT
                w.id,
                w.id as waybill_id,
                w.waybill_no,
                w.description,
                w.product_invoice_amount,
                w.total_mass_kg,
                w.item_length,
                w.item_width,
                w.item_height,
                w.city_id,
                w.direction_id,
                w.status,
                w.created_at,
                w.last_updated_at,
                w.created_by,
                creator.display_name as created_by_name,
                creator.user_login as created_by_login,
                c.name as customer_name,
                c.surname as customer_surname,
                COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) as company_name,
                c.cell as customer_cell,
                c.email_address as customer_email,
                ocity.city_name as destination_city,
                dest_c.id as destination_country_id,
                dest_c.country_name as destination_country
            FROM {$waybills_table} w
            LEFT JOIN {$wpdb->users} creator ON w.created_by = creator.ID
            LEFT JOIN {$customers_table} c ON w.customer_id = c.cust_id
            LEFT JOIN {$wpdb->prefix}kit_company_customers co ON w.company_id = co.company_id
            LEFT JOIN {$wpdb->prefix}kit_company_customers cust_co ON c.company_id = cust_co.company_id
            LEFT JOIN {$wpdb->prefix}kit_operating_cities ocity ON w.city_id = ocity.id
            LEFT JOIN {$wpdb->prefix}kit_operating_countries dest_c ON ocity.country_id = dest_c.id
            {$where_clause}
            ORDER BY w.created_at DESC
        ";

        if (! empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, $params));
        }

        return $wpdb->get_results($query);
    }
    
    /**
     * Get all warehouse items with status from waybills table
     */
    public static function getAllWarehouseItems($status = null, $warehouse_location = null)
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $customers_table = $wpdb->prefix . 'kit_customers';
        
        $where_conditions = [];
        
        // Only show waybills where warehouse = 1 (actual warehouse waybills)
        // warehouse is BOOLEAN (TINYINT(1)): 1 = in warehouse, 0/NULL = not in warehouse
        if ($status) {
            $where_conditions[] = $wpdb->prepare("w.status = %s", $status);
            $where_conditions[] = "w.warehouse = 1";
        } else {
            $where_conditions[] = "w.warehouse = 1";
        }
        
        // Legacy $warehouse_location ignored: warehouse column is boolean, not a place name.
        unset($warehouse_location);

        $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);

        $individual_query = "
            SELECT
                w.*,
                w.id,
                w.id as waybill_id,
                w.product_invoice_amount,
                w.total_mass_kg,
                w.status,
                w.created_at,
                w.last_updated_at,
                c.name as customer_name,
                c.surname as customer_surname,
                COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) as company_name,
                c.cell as customer_cell,
                c.email_address as customer_email,
                d.delivery_reference,
                d.dispatch_date
             FROM {$waybills_table} w
             LEFT JOIN {$customers_table} c ON w.customer_id = c.cust_id
             LEFT JOIN {$wpdb->prefix}kit_company_customers co ON w.company_id = co.company_id
             LEFT JOIN {$wpdb->prefix}kit_company_customers cust_co ON c.company_id = cust_co.company_id
             LEFT JOIN {$wpdb->prefix}kit_deliveries d ON w.delivery_id = d.id
             $where_clause
             ORDER BY w.created_at DESC
        ";

        return $wpdb->get_results($individual_query);
    }
    
    
    /**
     * Assign waybill to delivery
     */
    public static function assignToDelivery($waybill_id, $delivery_id, $assigned_by = null)
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        
        if (!$assigned_by) {
            $assigned_by = get_current_user_id();
        }
        
        $delivery_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}kit_deliveries WHERE id = %d",
            $delivery_id
        ));
        $waybill_status = 'assigned';
        if (class_exists('KIT_Deliveries') && method_exists('KIT_Deliveries', 'map_delivery_status_to_waybill_status')) {
            $mapped = KIT_Deliveries::map_delivery_status_to_waybill_status((string) $delivery_status);
            if (is_string($mapped) && $mapped !== '') {
                $waybill_status = $mapped;
            }
        }

        // Update waybill status to match delivery and set warehouse to 0 (not in warehouse)
        // warehouse is BOOLEAN (TINYINT(1)): 1 = in warehouse, 0 = not in warehouse
        $result = $wpdb->update(
            $waybills_table,
            [
                'status' => $waybill_status,
                'delivery_id' => $delivery_id,
                'warehouse' => 0, // Set to 0 to indicate it's no longer in warehouse
                'last_updated_by' => $assigned_by,
                'last_updated_at' => current_time('mysql')
            ],
            ['id' => $waybill_id],
            ['%s', '%d', '%d', '%d', '%s'],
            ['%d']
        );
        
        if ($result === false) {
            error_log('Failed to assign waybill to delivery: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Failed to assign waybill to delivery');
        }

        if ($result === 0) {
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT delivery_id, warehouse, status FROM {$waybills_table} WHERE id = %d",
                $waybill_id
            ));
            $already_assigned = $current
                && (int) $current->delivery_id === (int) $delivery_id
                && (int) $current->warehouse === 0
                && in_array($current->status, ['assigned', 'scheduled', 'unconfirmed', 'in_transit', 'shipped', 'delivered'], true);
            if ($already_assigned) {
                return true;
            }
            error_log("assignToDelivery: 0 rows updated for waybill {$waybill_id} to delivery {$delivery_id}");
            return new WP_Error('no_rows', 'Waybill could not be assigned (no database changes).');
        }

        return true;
    }

    /**
     * Redirect URL after warehouse assign (employee portal vs wp-admin).
     *
     * @param array $args Query args for the warehouse screen.
     */
    public static function assignRedirectUrl(array $args = [])
    {
        if (function_exists('kit_employee_portal_url')) {
            $user = wp_get_current_user();
            $roles = (array) $user->roles;
            $is_employee = in_array('data_capturer', $roles, true) || in_array('manager', $roles, true);
            $is_admin = in_array('administrator', $roles, true) || current_user_can('manage_options');
            if ($is_employee && ! $is_admin) {
                return kit_employee_portal_url('warehouse-waybills', $args);
            }
        }

        return add_query_arg($args, admin_url('admin.php?page=warehouse-waybills'));
    }

    /**
     * Process warehouse assign POST; store flash message and redirect (call on admin_init early).
     */
    public static function maybeHandleAssignPost()
    {
        if (! isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (empty($_POST['assign_warehouse_items'])) {
            return;
        }

        $is_admin_post_action = isset($_POST['action']) && $_POST['action'] === 'kit_warehouse_assign';
        $page = '';
        if (isset($_REQUEST['page'])) {
            $page = sanitize_text_field(wp_unslash($_REQUEST['page']));
        } elseif (isset($_POST['warehouse_page'])) {
            $page = sanitize_text_field(wp_unslash($_POST['warehouse_page']));
        }
        if (! $is_admin_post_action && $page !== 'warehouse-waybills') {
            return;
        }

        if (! current_user_can('kit_view_waybills') && ! current_user_can('edit_pages')) {
            return;
        }

        if (! isset($_POST['assign_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['assign_nonce'])), 'assign_warehouse_items')) {
            self::setAssignFlash('error', 'Security verification failed. Please refresh the page and try again.');
            wp_safe_redirect(self::assignRedirectUrl(['warehouse_error' => '1']));
            exit;
        }

        global $wpdb;

        $waybill_ids = [];
        if (! empty($_POST['waybill_ids_bulk'])) {
            $raw_bulk = sanitize_text_field(wp_unslash($_POST['waybill_ids_bulk']));
            $waybill_ids = array_filter(array_map('intval', explode(',', $raw_bulk)));
        } elseif (isset($_POST['waybill_ids'])) {
            $waybill_ids = is_array($_POST['waybill_ids']) ? $_POST['waybill_ids'] : [$_POST['waybill_ids']];
        }

        $delivery_id = 0;
        if (isset($_POST['delivery_id']) && $_POST['delivery_id'] !== '') {
            $delivery_id = (int) $_POST['delivery_id'];
        } elseif (isset($_POST['delivery_id_select']) && $_POST['delivery_id_select'] !== '') {
            $delivery_id = (int) $_POST['delivery_id_select'];
        }

        if (empty($waybill_ids)) {
            self::setAssignFlash('error', 'No waybills selected. Please select at least one waybill to assign.');
            wp_safe_redirect(self::assignRedirectUrl(['warehouse_error' => '1']));
            exit;
        }

        if ($delivery_id <= 0) {
            self::setAssignFlash('error', 'No delivery selected. Please select a delivery from the dropdown.');
            wp_safe_redirect(self::assignRedirectUrl(['warehouse_error' => '1']));
            exit;
        }

        $updated = 0;
        $errors = [];

        foreach ($waybill_ids as $waybill_id) {
            $waybill_id = (int) $waybill_id;
            if ($waybill_id <= 0) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}kit_waybills WHERE id = %d",
                $waybill_id
            ));
            if (! $exists) {
                $errors[] = 'Waybill ID ' . $waybill_id . ': not found';
                continue;
            }

            $result = self::assignToDelivery($waybill_id, $delivery_id, get_current_user_id());
            if (is_wp_error($result)) {
                $errors[] = 'Waybill ID ' . $waybill_id . ': ' . $result->get_error_message();
            } else {
                $updated++;
            }
        }

        if ($updated > 0) {
            $message = 'Successfully assigned ' . $updated . ' waybill(s) to delivery!';
            if (! empty($errors)) {
                $message .= ' (' . count($errors) . ' failed)';
            }
            self::setAssignFlash('success', $message);
            wp_safe_redirect(self::assignRedirectUrl(['warehouse_assigned' => (string) $updated]));
            exit;
        }

        $error_msg = 'Failed to assign waybills. ';
        if (! empty($errors)) {
            $error_msg .= implode(', ', array_slice($errors, 0, 3));
            if (count($errors) > 3) {
                $error_msg .= ' (and ' . (count($errors) - 3) . ' more)';
            }
        } else {
            $error_msg .= 'Please check that the waybills and delivery are valid.';
        }
        self::setAssignFlash('error', $error_msg);
        wp_safe_redirect(self::assignRedirectUrl(['warehouse_error' => '1']));
        exit;
    }

    /**
     * @param string $type success|error
     */
    public static function setAssignFlash($type, $message)
    {
        $key = 'kit_warehouse_flash_' . get_current_user_id();
        set_transient($key, ['type' => $type, 'message' => $message], 60);
    }

    /**
     * @return array{type: string, message: string}|null
     */
    public static function consumeAssignFlash()
    {
        $key = 'kit_warehouse_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if ($flash) {
            delete_transient($key);
        }
        return is_array($flash) ? $flash : null;
    }
    
    /**
     * Mark waybill as shipped
     */
    public static function markAsShipped($waybill_id)
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        
        $result = $wpdb->update(
            $waybills_table,
            [
                'status' => 'shipped',
                'last_updated_at' => current_time('mysql')
            ],
            ['id' => $waybill_id],
            ['%s', '%s'],
            ['%d']
        );
        
        if ($result === false) {
            error_log('Failed to mark waybill as shipped: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Failed to mark waybill as shipped');
        }
        
        return true;
    }
    
    /**
     * Mark waybill as delivered
     */
    public static function markAsDelivered($waybill_id)
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        
        $result = $wpdb->update(
            $waybills_table,
            [
                'status' => 'delivered',
                'last_updated_at' => current_time('mysql')
            ],
            ['id' => $waybill_id],
            ['%s', '%s'],
            ['%d']
        );
        
        if ($result === false) {
            error_log('Failed to mark waybill as delivered: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Failed to mark waybill as delivered');
        }
        
        return true;
    }
    
    /**
     * Get available deliveries for warehouse assignment
     */
    public static function getAvailableDeliveries($destination_country, $destination_city = '')
    {
        global $wpdb;
        $deliveries_table = $wpdb->prefix . 'kit_deliveries';
        
        if (empty($destination_city)) {
            // Match by country only
            $deliveries = $wpdb->get_results($wpdb->prepare(
                "SELECT d.id as delivery_id, d.delivery_reference as delivery_name, 
                        oc2.country_name as destination_country, 
                        '' as destination_city, 
                        d.dispatch_date 
                 FROM $deliveries_table d
                 LEFT JOIN {$wpdb->prefix}kit_shipping_directions sd ON d.direction_id = sd.id
                 LEFT JOIN {$wpdb->prefix}kit_operating_countries oc2 ON sd.destination_country_id = oc2.id
                 WHERE oc2.country_name = %s 
                 AND oc2.is_active = 1
                 AND d.dispatch_date >= CURDATE()
                 AND d.status = 'scheduled'
                 AND d.delivery_reference != 'pending'
                 ORDER BY d.dispatch_date ASC",
                $destination_country
            ));
        } else {
            // Match by country and city (city matching not available in current schema)
            $deliveries = $wpdb->get_results($wpdb->prepare(
                "SELECT d.id as delivery_id, d.delivery_reference as delivery_name, 
                        oc2.country_name as destination_country, 
                        '' as destination_city, 
                        d.dispatch_date 
                 FROM $deliveries_table d
                 LEFT JOIN {$wpdb->prefix}kit_shipping_directions sd ON d.direction_id = sd.id
                 LEFT JOIN {$wpdb->prefix}kit_operating_countries oc2 ON sd.destination_country_id = oc2.id
                 WHERE oc2.country_name = %s 
                 AND oc2.is_active = 1
                 AND d.dispatch_date >= CURDATE()
                 AND d.status = 'scheduled'
                 AND d.delivery_reference != 'pending'
                 ORDER BY d.dispatch_date ASC",
                $destination_country
            ));
        }
        
        return $deliveries;
    }
    
    /**
     * Warehouse workbench stats (queue only — not global waybill pipeline).
     *
     * warehouse is TINYINT(1): 1 = in warehouse. Assign clears it (0), so assigned/shipped
     * counts do not belong on this screen.
     *
     * @return object{
     *   total_items:int,
     *   in_warehouse:int,
     *   total_mass_kg:float,
     *   scheduled_deliveries:int,
     *   oldest_days:int
     * }
     */
    public static function getWarehouseStats()
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $deliveries_table = $wpdb->prefix . 'kit_deliveries';

        $queue = $wpdb->get_row(
            "SELECT
                COUNT(*) AS in_warehouse,
                COALESCE(SUM(total_mass_kg), 0) AS total_mass_kg,
                MIN(COALESCE(NULLIF(last_updated_at, '0000-00-00 00:00:00'), created_at)) AS oldest_at
             FROM {$waybills_table}
             WHERE warehouse = 1"
        );

        $scheduled = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$deliveries_table}
             WHERE status = 'scheduled'
             AND delivery_reference != 'pending'"
        );

        $in_warehouse = (int) ($queue->in_warehouse ?? 0);
        $oldest_days = 0;
        if (! empty($queue->oldest_at)) {
            $ts = strtotime((string) $queue->oldest_at);
            if ($ts) {
                $oldest_days = max(0, (int) floor((time() - $ts) / DAY_IN_SECONDS));
            }
        }

        return (object) [
            'total_items' => $in_warehouse,
            'in_warehouse' => $in_warehouse,
            'total_mass_kg' => (float) ($queue->total_mass_kg ?? 0),
            'scheduled_deliveries' => $scheduled,
            'oldest_days' => $oldest_days,
        ];
    }

    /**
     * Scheduled deliveries that can receive warehouse assignments (excludes system "pending").
     *
     * @return array<object{id:int,delivery_reference:string,dispatch_date:string}>
     */
    public static function getAssignableDeliveries()
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT id, delivery_reference, dispatch_date
             FROM {$wpdb->prefix}kit_deliveries
             WHERE status = 'scheduled'
             AND delivery_reference != 'pending'
             ORDER BY dispatch_date ASC, id ASC"
        );
    }

    /**
     * Active countries with their scheduled (assignable) deliveries for the warehouse UI.
     *
     * @return array<int, array{id:int,name:string,code:string,deliveries:array<int,array<string,mixed>>}>
     */
    public static function getCountriesWithAssignableDeliveries()
    {
        global $wpdb;

        $drivers_table = $wpdb->prefix . 'kit_drivers';
        $drivers_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $drivers_table));
        $driver_id_exists = false;
        if ($drivers_table_exists) {
            $driver_id_exists = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'driver_id'",
                DB_NAME,
                $wpdb->prefix . 'kit_deliveries'
            ));
        }

        $driver_join = '';
        $driver_select = '';
        if ($drivers_table_exists && $driver_id_exists) {
            $driver_join = "LEFT JOIN {$drivers_table} dr ON d.driver_id = dr.id";
            $driver_select = ', dr.name AS driver_name';
        }

        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $rows = $wpdb->get_results(
            "SELECT
                oc.id as country_id,
                oc.country_name,
                oc.country_code,
                d.id as delivery_id,
                d.delivery_reference,
                d.dispatch_date,
                d.truck_number,
                d.status as delivery_status,
                dest.city_name AS destination_city,
                COALESCE(wb.waybill_count, 0) AS waybill_count
                {$driver_select}
            FROM {$wpdb->prefix}kit_operating_countries oc
            LEFT JOIN {$wpdb->prefix}kit_shipping_directions sd ON oc.id = sd.destination_country_id
            LEFT JOIN {$wpdb->prefix}kit_deliveries d
                ON sd.id = d.direction_id
                AND d.status = 'scheduled'
                AND d.delivery_reference != 'pending'
            LEFT JOIN {$wpdb->prefix}kit_operating_cities dest ON d.destination_city_id = dest.id
            LEFT JOIN (
                SELECT delivery_id, COUNT(*) AS waybill_count
                FROM {$waybills_table}
                GROUP BY delivery_id
            ) wb ON wb.delivery_id = d.id
            {$driver_join}
            WHERE oc.is_active = 1
            ORDER BY oc.country_name ASC, d.dispatch_date ASC"
        );

        $countries_data = [];
        foreach ($rows as $row) {
            if (! isset($countries_data[$row->country_id])) {
                $countries_data[$row->country_id] = [
                    'id' => (int) $row->country_id,
                    'name' => $row->country_name,
                    'code' => $row->country_code,
                    'deliveries' => [],
                ];
            }

            if ($row->delivery_id) {
                $countries_data[$row->country_id]['deliveries'][] = [
                    'id' => (int) $row->delivery_id,
                    'reference' => $row->delivery_reference,
                    'dispatch_date' => $row->dispatch_date,
                    'truck_number' => $row->truck_number,
                    'status' => $row->delivery_status,
                    'destination_country' => $row->country_name,
                    'destination_city' => $row->destination_city,
                    'driver_name' => $row->driver_name ?? null,
                    'waybill_count' => (int) ($row->waybill_count ?? 0),
                ];
            }
        }

        if (empty($countries_data)) {
            $countries_only = $wpdb->get_results(
                "SELECT id, country_name, country_code
                 FROM {$wpdb->prefix}kit_operating_countries
                 WHERE is_active = 1
                 ORDER BY country_name ASC"
            );
            foreach ($countries_only as $country) {
                $countries_data[$country->id] = [
                    'id' => (int) $country->id,
                    'name' => $country->country_name,
                    'code' => $country->country_code,
                    'deliveries' => [],
                ];
            }
        }

        return array_values($countries_data);
    }
    
    /**
     * Get warehouse items by waybill number
     */
    public static function getWarehouseItemByWaybillNo($waybill_no)
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $customers_table = $wpdb->prefix . 'kit_customers';
        
        // Only return waybills where warehouse = 1 (actual warehouse waybills)
        return $wpdb->get_row($wpdb->prepare(
            "SELECT w.*, c.name as customer_name, c.surname as customer_surname
             FROM $waybills_table w
             LEFT JOIN $customers_table c ON w.customer_id = c.cust_id
             WHERE w.waybill_no = %d
             AND w.warehouse = 1",
            $waybill_no
        ));
    }
    
    /**
     * Remove waybill from warehouse
     */
    public static function removeFromWarehouse($waybill_id)
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        
        $result = $wpdb->update(
            $waybills_table,
            [
                'status' => 'pending',
                'warehouse' => 0,
                'last_updated_at' => current_time('mysql'),
            ],
            ['id' => $waybill_id],
            ['%s', '%d', '%s'],
            ['%d']
        );
        
        if ($result === false) {
            error_log('Failed to remove waybill from warehouse: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Failed to remove waybill from warehouse');
        }
        
        return true;
    }
    
    /**
     * Create realistic warehouse waybills like a human would
     */
    public static function createRealisticWarehousedWaybills($count = 10)
    {
        global $wpdb;
        
        // Get real customers from database
        $customers = $wpdb->get_results("
            SELECT cust_id, name, surname, email 
            FROM {$wpdb->prefix}kit_customers 
            WHERE cust_id > 0 
            ORDER BY RAND() 
            LIMIT 20
        ");
        
        if (empty($customers)) {
            return new WP_Error('no_customers', 'No customers found. Please create customers first.');
        }
        
        // Get delivery options (warehouse delivery)
        $warehouse_delivery = $wpdb->get_row("
            SELECT id FROM {$wpdb->prefix}kit_deliveries 
            WHERE delivery_reference = 'pending' 
            LIMIT 1
        ");
        
        if (!$warehouse_delivery) {
            return new WP_Error('no_warehouse_delivery', 'Warehouse delivery not found.');
        }
        
        // Get shipping directions for realistic data
        $directions = $wpdb->get_results("
            SELECT id FROM {$wpdb->prefix}kit_shipping_directions 
            ORDER BY RAND() 
            LIMIT 5
        ");
        
        $created_count = 0;
        $errors = [];
        
        // Realistic product descriptions
        $product_descriptions = [
            'Electronics - Laptops and Accessories',
            'Clothing - Winter Collection',
            'Books - Educational Materials',
            'Home Appliances - Kitchen Items',
            'Sports Equipment - Fitness Gear',
            'Furniture - Office Chairs',
            'Automotive Parts - Engine Components',
            'Medical Supplies - First Aid Kits',
            'Tools - Construction Equipment',
            'Toys - Educational Games'
        ];
        
        // Realistic warehouse locations
        $warehouse_locations = [
            'Johannesburg Warehouse',
            'Cape Town Distribution Center',
            'Durban Storage Facility',
            'Pretoria Logistics Hub',
            'Port Elizabeth Warehouse'
        ];
        
        for ($i = 1; $i <= $count; $i++) {
            $customer = $customers[array_rand($customers)];
            $direction = $directions[array_rand($directions)];
            $product_desc = $product_descriptions[array_rand($product_descriptions)];
            $warehouse_location = $warehouse_locations[array_rand($warehouse_locations)];
            
            // Generate realistic waybill number
            $waybill_no = 'WB-' . date('Y') . '-' . str_pad($i + rand(1000, 9999), 6, '0', STR_PAD_LEFT);
            
            // Realistic dimensions and weights
            $length = rand(20, 120); // cm
            $width = rand(15, 80);   // cm  
            $height = rand(10, 60);  // cm
            $weight = rand(5, 150);   // kg
            $volume = ($length * $width * $height) / 1000000; // m³
            
            // Realistic invoice amounts
            $invoice_amount = rand(250, 8500);
            
            $waybill_data = [
                'direction_id' => $direction->id,
                'delivery_id' => $warehouse_delivery->id,
                'customer_id' => $customer->cust_id,
                'approval' => 'approved',
                'waybill_no' => $waybill_no,
                'product_invoice_number' => class_exists('KIT_Waybills') ? KIT_Waybills::generate_product_invoice_number($waybill_no) : 'INV13' . $waybill_no,
                'product_invoice_amount' => $invoice_amount,
                'waybill_items_total' => $invoice_amount,
                'item_length' => $length,
                'item_width' => $width,
                'item_height' => $height,
                'total_mass_kg' => $weight,
                'total_volume' => $volume,
                'mass_charge' => $weight * 2.5, // Realistic charge per kg
                'volume_charge' => $volume * 150, // Realistic charge per m³
                'charge_basis' => $weight > ($volume * 200) ? 'mass' : 'volume',
                'vat_include' => 0,
                'warehouse' => 1,
                'miscellaneous' => "Product: {$product_desc}\nCustomer: {$customer->name} {$customer->surname}\nEmail: {$customer->email}",
                'include_sad500' => rand(0, 1),
                'include_sadc' => rand(0, 1),
                'return_load' => rand(0, 1),
                'tracking_number' => 'TRK-' . strtoupper(wp_generate_password(8, false, false)),
                'status' => 'pending',
                'created_by' => get_current_user_id() ?: 1,
                'last_updated_by' => get_current_user_id() ?: 1,
                'created_at' => current_time('mysql'),
                'last_updated_at' => current_time('mysql')
            ];
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'kit_waybills',
                $waybill_data
            );
            
            if ($result) {
                $created_count++;
                
                // Also create waybill items for more realism
                $item_count = rand(1, 5);
                for ($j = 1; $j <= $item_count; $j++) {
                    $item_data = [
                        'waybillno' => $waybill_no,
                        'item_description' => "Item {$j}: " . $product_descriptions[array_rand($product_descriptions)],
                        'quantity' => rand(1, 10),
                        'weight_kg' => $weight / $item_count,
                        'length_cm' => $length,
                        'width_cm' => $width,
                        'height_cm' => $height,
                        'volume_cm3' => $volume * 1000000 / $item_count,
                        'unit_price' => $invoice_amount / $item_count,
                        'total_price' => $invoice_amount / $item_count
                    ];
                    
                    $wpdb->insert($wpdb->prefix . 'kit_waybill_items', $item_data);
                }
            } else {
                $errors[] = "Failed to create waybill {$waybill_no}: " . $wpdb->last_error;
            }
        }
        
        return [
            'success' => true,
            'created_count' => $created_count,
            'total_requested' => $count,
            'errors' => $errors
        ];
    }

    /**
     * AJAX: assign one or more warehouse waybills to a delivery (delivery-view modal).
     */
    public static function ajaxAssignToDelivery()
    {
        if (! current_user_can('kit_view_waybills') && ! current_user_can('edit_pages')) {
            wp_send_json_error(['message' => 'You do not have permission to assign waybills.'], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (! wp_verify_nonce($nonce, 'kit_warehouse_load_truck')) {
            wp_send_json_error(['message' => 'Security check failed. Refresh and try again.'], 403);
        }

        $delivery_id = isset($_POST['delivery_id']) ? (int) $_POST['delivery_id'] : 0;
        $raw_ids = $_POST['waybill_ids'] ?? [];
        if (! is_array($raw_ids)) {
            $raw_ids = explode(',', (string) $raw_ids);
        }
        $waybill_ids = array_values(array_unique(array_filter(array_map('intval', $raw_ids))));

        if ($delivery_id <= 0) {
            wp_send_json_error(['message' => 'No delivery selected.']);
        }
        if (empty($waybill_ids)) {
            wp_send_json_error(['message' => 'Select at least one waybill.']);
        }

        global $wpdb;
        $delivery_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}kit_deliveries WHERE id = %d",
            $delivery_id
        ));
        if (! $delivery_exists) {
            wp_send_json_error(['message' => 'Delivery not found.']);
        }

        $assigned = [];
        $errors = [];

        foreach ($waybill_ids as $waybill_id) {
            $in_warehouse = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}kit_waybills WHERE id = %d AND warehouse = 1",
                $waybill_id
            ));
            if (! $in_warehouse) {
                $errors[] = 'Waybill ' . $waybill_id . ' is not in warehouse.';
                continue;
            }

            $result = self::assignToDelivery($waybill_id, $delivery_id, get_current_user_id());
            if (is_wp_error($result)) {
                $errors[] = 'Waybill ' . $waybill_id . ': ' . $result->get_error_message();
                continue;
            }
            $assigned[] = $waybill_id;
        }

        if (empty($assigned)) {
            wp_send_json_error([
                'message' => ! empty($errors) ? implode(' ', $errors) : 'Could not assign waybills.',
                'errors'  => $errors,
            ]);
        }

        $count = count($assigned);
        $message = sprintf(
            _n('%d waybill loaded onto this truck.', '%d waybills loaded onto this truck.', $count, '08600-services-quotations'),
            $count
        );
        if (! empty($errors)) {
            $message .= ' ' . count($errors) . ' failed.';
        }

        wp_send_json_success([
            'message'      => $message,
            'assigned_ids' => $assigned,
            'assigned'     => $count,
            'errors'       => $errors,
        ]);
    }
}

add_action('admin_init', ['KIT_Warehouse', 'maybeHandleAssignPost'], 0);
add_action('admin_post_kit_warehouse_assign', ['KIT_Warehouse', 'maybeHandleAssignPost']);
add_action('wp_ajax_kit_warehouse_load_truck', ['KIT_Warehouse', 'ajaxAssignToDelivery']);