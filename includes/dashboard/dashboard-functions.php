<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dashboard helper functions: KPIs, upcoming deliveries, recent waybills, map data.
 */
class KIT_Dashboard {

    public static function init() {
        add_action('wp_ajax_kit_dashboard_map_deliveries', [self::class, 'ajax_map_deliveries']);
    }

    /**
     * AJAX: return deliveries for route map (next 7 days, scheduled/in_transit).
     */
    public static function ajax_map_deliveries() {
        if (!current_user_can('kit_view_waybills')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'kit_dashboard_map')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        wp_send_json_success(self::get_deliveries_for_map());
    }

    /**
     * Count waybills created today.
     *
     * @return int
     */
    public static function get_today_waybill_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_waybills';
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()"
        );
        return $count ? (int) $count : 0;
    }

    /**
     * Revenue today: sum of (product_invoice_amount + misc_total from miscellaneous JSON).
     *
     * @return float
     */
    public static function get_revenue_today() {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_waybills';
        $rows  = $wpdb->get_results("SELECT id, product_invoice_amount, miscellaneous FROM {$table} WHERE DATE(created_at) = CURDATE()");
        return self::sum_revenue_rows($rows);
    }

    /**
     * Revenue this month: same sum for current calendar month.
     *
     * @return float
     */
    public static function get_revenue_this_month() {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_waybills';
        $rows  = $wpdb->get_results(
            "SELECT id, product_invoice_amount, miscellaneous FROM {$table}
             WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
             AND created_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
        );
        return self::sum_revenue_rows($rows);
    }

    /**
     * Current month label in site timezone.
     *
     * @return string
     */
    public static function get_current_month_name() {
        return date_i18n('F Y', current_time('timestamp'));
    }

    /**
     * Top customer for current month (ranked by waybill count, then revenue).
     *
     * @return array{name:string,waybill_count:int,revenue:float,customer_id:int}
     */
    public static function get_top_customer_this_month() {
        global $wpdb;

        $w_table = $wpdb->prefix . 'kit_waybills';
        $c_table = $wpdb->prefix . 'kit_customers';

        $rows = $wpdb->get_results(
            "SELECT
                w.customer_id,
                w.product_invoice_amount,
                w.miscellaneous,
                COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) AS company_name,
                c.name,
                c.surname
             FROM {$w_table} w
             LEFT JOIN {$c_table} c ON w.customer_id = c.cust_id
             LEFT JOIN {$wpdb->prefix}kit_company_customers co ON w.company_id = co.company_id
             LEFT JOIN {$wpdb->prefix}kit_company_customers cust_co ON c.company_id = cust_co.company_id
             WHERE w.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
             AND w.created_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
        );

        if (!is_array($rows) || $rows === []) {
            return [
                'name' => 'No waybills this month',
                'waybill_count' => 0,
                'revenue' => 0.0,
                'customer_id' => 0,
            ];
        }

        $by_customer = [];

        foreach ($rows as $row) {
            $customer_id = isset($row->customer_id) ? (int) $row->customer_id : 0;
            $key = (string) $customer_id;

            if (!isset($by_customer[$key])) {
                $display_name = self::resolve_customer_display_name(
                    $row->company_name ?? '',
                    $row->name ?? '',
                    $row->surname ?? '',
                    $customer_id
                );

                $by_customer[$key] = [
                    'customer_id' => $customer_id,
                    'name' => $display_name,
                    'waybill_count' => 0,
                    'revenue' => 0.0,
                ];
            }

            $misc_total = 0.0;
            if (!empty($row->miscellaneous)) {
                $misc = json_decode((string) $row->miscellaneous, true);
                if (is_array($misc) && isset($misc['misc_total'])) {
                    $misc_total = (float) $misc['misc_total'];
                }
            }

            $by_customer[$key]['waybill_count']++;
            $by_customer[$key]['revenue'] += (float) ($row->product_invoice_amount ?? 0) + $misc_total;
        }

        $top = null;
        foreach ($by_customer as $entry) {
            if ($top === null) {
                $top = $entry;
                continue;
            }

            if ((int) $entry['waybill_count'] > (int) $top['waybill_count']) {
                $top = $entry;
                continue;
            }

            if (
                (int) $entry['waybill_count'] === (int) $top['waybill_count']
                && (float) $entry['revenue'] > (float) $top['revenue']
            ) {
                $top = $entry;
            }
        }

        return [
            'name' => (string) ($top['name'] ?? 'No waybills this month'),
            'waybill_count' => (int) ($top['waybill_count'] ?? 0),
            'revenue' => (float) ($top['revenue'] ?? 0),
            'customer_id' => (int) ($top['customer_id'] ?? 0),
        ];
    }

    /**
     * Customer list/view URL for dashboard links.
     *
     * @param int  $customer_id
     * @param bool $use_portal
     * @return string
     */
    public static function customer_view_url($customer_id, $use_portal = false) {
        $customer_id = (int) $customer_id;
        if ($use_portal && function_exists('kit_employee_portal_url')) {
            return $customer_id > 0
                ? kit_employee_portal_url('08600-customers', ['view_customer' => $customer_id])
                : kit_employee_portal_url('08600-customers');
        }

        return $customer_id > 0
            ? admin_url('admin.php?page=08600-customers&view_customer=' . $customer_id)
            : admin_url('admin.php?page=08600-customers');
    }

    /**
     * This-month commercial band: revenue + top customer.
     *
     * @param array $options {
     *   @type bool $include_revenue
     *   @type bool $use_portal_urls
     * }
     * @return string
     */
    public static function render_month_band(array $options = []) {
        $include_revenue = array_key_exists('include_revenue', $options)
            ? (bool) $options['include_revenue']
            : true;
        $use_portal = !empty($options['use_portal_urls']);

        $currency = class_exists('KIT_Commons') ? KIT_Commons::currency() : 'R';
        $month_name = self::get_current_month_name();
        $top = self::get_top_customer_this_month();
        $customer_id = (int) ($top['customer_id'] ?? 0);
        $customer_url = self::customer_view_url($customer_id, $use_portal);
        $waybills_url = $use_portal && function_exists('kit_employee_portal_url')
            ? kit_employee_portal_url('08600-waybill-manage')
            : admin_url('admin.php?page=08600-waybill-manage');

        $has_customer = $customer_id > 0 || ((int) ($top['waybill_count'] ?? 0) > 0);

        ob_start();
        ?>
        <article class="kit-dashboard-month-band">
            <header class="kit-dashboard-month-band-head">
                <h2 class="kit-dashboard-month-band-title"><?php echo esc_html__('This month', '08600-services-quotations'); ?></h2>
                <p class="kit-dashboard-month-band-period"><?php echo esc_html($month_name); ?></p>
            </header>
            <div class="kit-dashboard-month-band-body<?php echo $include_revenue ? '' : ' kit-dashboard-month-band-body--single'; ?>">
                <?php if ($include_revenue) : ?>
                    <a class="kit-dashboard-month-band-col" href="<?php echo esc_url($waybills_url); ?>">
                        <p class="kit-dashboard-month-band-label"><?php echo esc_html__('Revenue', '08600-services-quotations'); ?></p>
                        <p class="kit-dashboard-month-band-value"><?php echo esc_html($currency . ' ' . number_format((float) self::get_revenue_this_month(), 2)); ?></p>
                        <p class="kit-dashboard-month-band-meta"><?php echo esc_html(sprintf(
                            /* translators: %s: today's revenue amount */
                            __('Today: %s', '08600-services-quotations'),
                            $currency . ' ' . number_format((float) self::get_revenue_today(), 2)
                        )); ?></p>
                        <span class="kit-dashboard-month-band-link"><?php echo esc_html__('View waybills', '08600-services-quotations'); ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($has_customer) : ?>
                    <a class="kit-dashboard-month-band-col" href="<?php echo esc_url($customer_url); ?>">
                        <p class="kit-dashboard-month-band-label"><?php echo esc_html__('Top customer', '08600-services-quotations'); ?></p>
                        <p class="kit-dashboard-month-band-name"><?php echo esc_html((string) ($top['name'] ?? '')); ?></p>
                        <p class="kit-dashboard-month-band-meta">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: waybill count */
                                _n('%s waybill', '%s waybills', (int) ($top['waybill_count'] ?? 0), '08600-services-quotations'),
                                number_format((int) ($top['waybill_count'] ?? 0))
                            ));
                            if ($include_revenue) {
                                echo ' · ' . esc_html($currency . ' ' . number_format((float) ($top['revenue'] ?? 0), 2));
                            }
                            ?>
                        </p>
                        <span class="kit-dashboard-month-band-link"><?php echo esc_html__('View customer', '08600-services-quotations'); ?></span>
                    </a>
                <?php else : ?>
                    <div class="kit-dashboard-month-band-col">
                        <p class="kit-dashboard-month-band-label"><?php echo esc_html__('Top customer', '08600-services-quotations'); ?></p>
                        <p class="kit-dashboard-month-band-name"><?php echo esc_html__('No waybills this month', '08600-services-quotations'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Compact delivery pipeline pills.
     *
     * @param array $options {
     *   @type bool $use_portal_urls
     * }
     * @return string
     */
    public static function render_delivery_pipeline_strip(array $options = []) {
        $use_portal = !empty($options['use_portal_urls']);
        $deliveries_url = $use_portal && function_exists('kit_employee_portal_url')
            ? kit_employee_portal_url('kit-deliveries')
            : admin_url('admin.php?page=kit-deliveries');
        $counts = self::get_delivery_status_counts();

        $stats = [
            [
                'label' => __('Scheduled', '08600-services-quotations'),
                'value' => (int) $counts->scheduled,
            ],
            [
                'label' => __('In transit', '08600-services-quotations'),
                'value' => (int) $counts->in_transit,
            ],
            [
                'label' => __('Delivered', '08600-services-quotations'),
                'value' => (int) $counts->delivered,
            ],
        ];

        ob_start();
        ?>
        <p class="kit-dashboard-pipeline" aria-label="<?php echo esc_attr__('Delivery pipeline', '08600-services-quotations'); ?>">
            <span class="kit-dashboard-pipeline-label"><?php echo esc_html__('Pipeline', '08600-services-quotations'); ?></span>
            <?php foreach ($stats as $stat) : ?>
                <a class="kit-dashboard-pipeline-stat<?php echo ((int) $stat['value'] === 0) ? ' is-zero' : ''; ?>" href="<?php echo esc_url($deliveries_url); ?>">
                    <strong><?php echo esc_html(number_format($stat['value'])); ?></strong>
                    <?php echo esc_html($stat['label']); ?>
                </a>
            <?php endforeach; ?>
        </p>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Status pill modifier for waybill/delivery labels.
     *
     * @param string $status
     * @return string
     */
    public static function status_pill_modifier($status) {
        $key = strtolower(preg_replace('/[\s-]+/', '_', trim((string) $status)));
        switch ($key) {
            case 'pending':
                return 'kit-dashboard-status-pill--pending';
            case 'delivered':
                return 'kit-dashboard-status-pill--delivered';
            case 'in_transit':
            case 'intransit':
                return 'kit-dashboard-status-pill--transit';
            case 'warehouse':
                return 'kit-dashboard-status-pill--warehouse';
            case 'scheduled':
                return 'kit-dashboard-status-pill--scheduled';
            default:
                return 'kit-dashboard-status-pill--neutral';
        }
    }

    /**
     * Empty state for upcoming deliveries, with a warehouse CTA when stock is waiting.
     *
     * @param array $options {
     *   @type bool $use_portal_urls
     * }
     * @return string
     */
    public static function render_upcoming_empty(array $options = []) {
        $use_portal = !empty($options['use_portal_urls']);
        $warehouse_url = $use_portal && function_exists('kit_employee_portal_url')
            ? kit_employee_portal_url('warehouse-waybills')
            : admin_url('admin.php?page=warehouse-waybills');
        $deliveries_url = $use_portal && function_exists('kit_employee_portal_url')
            ? kit_employee_portal_url('kit-deliveries')
            : admin_url('admin.php?page=kit-deliveries');

        $in_warehouse = 0;
        if (class_exists('KIT_Warehouse')) {
            $stats = KIT_Warehouse::getWarehouseStats();
            $in_warehouse = (int) ($stats->in_warehouse ?? 0);
        }

        ob_start();
        ?>
        <div class="kit-dashboard-empty-state">
            <p class="kit-dashboard-empty-state-title"><?php echo esc_html__('Nothing scheduled', '08600-services-quotations'); ?></p>
            <?php if ($in_warehouse > 0) : ?>
                <p class="kit-dashboard-empty-state-text">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: number of waybills in warehouse */
                        _n(
                            '%s waybill is waiting in the warehouse.',
                            '%s waybills are waiting in the warehouse.',
                            $in_warehouse,
                            '08600-services-quotations'
                        ),
                        number_format($in_warehouse)
                    ));
                    ?>
                </p>
                <a class="kit-dashboard-empty-state-action" href="<?php echo esc_url($warehouse_url); ?>">
                    <?php echo esc_html__('Assign from warehouse', '08600-services-quotations'); ?>
                </a>
            <?php else : ?>
                <p class="kit-dashboard-empty-state-text"><?php echo esc_html__('No trucks on the calendar for the next 7 days.', '08600-services-quotations'); ?></p>
                <a class="kit-dashboard-empty-state-action" href="<?php echo esc_url($deliveries_url); ?>">
                    <?php echo esc_html__('View deliveries', '08600-services-quotations'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Sum revenue (product_invoice_amount + misc_total) from waybill rows.
     *
     * @param array $rows Rows with product_invoice_amount, miscellaneous
     * @return float
     */
    private static function sum_revenue_rows($rows) {
        $total = 0.0;
        if (!is_array($rows)) {
            return $total;
        }
        foreach ($rows as $r) {
            $invoice = (float) ($r->product_invoice_amount ?? 0);
            $misc    = 0.0;
            if (!empty($r->miscellaneous)) {
                $data = json_decode($r->miscellaneous, true);
                if (is_array($data) && isset($data['misc_total'])) {
                    $misc = (float) $data['misc_total'];
                }
            }
            $total += $invoice + $misc;
        }
        return $total;
    }

    /**
     * Build a human-readable customer name for stats.
     */
    private static function resolve_customer_display_name($company_name, $name, $surname, $customer_id) {
        $company_name = trim((string) $company_name);
        if ($company_name !== '') {
            return $company_name;
        }

        $full_name = trim(trim((string) $name) . ' ' . trim((string) $surname));
        if ($full_name !== '') {
            return $full_name;
        }

        if ((int) $customer_id > 0) {
            return 'Customer #' . (int) $customer_id;
        }

        return 'Unknown customer';
    }

    /**
     * Count deliveries with dispatch_date = today and status in (scheduled, in_transit).
     *
     * @return int
     */
    public static function get_today_deliveries_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_deliveries';
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE dispatch_date = CURDATE()
             AND status IN ('scheduled', 'in_transit')
             AND delivery_reference != 'pending'"
        );
        return $count ? (int) $count : 0;
    }

    /**
     * Delivery status counts: scheduled, in_transit, delivered (excluding pending reference).
     *
     * @return object { scheduled, in_transit, delivered }
     */
    public static function get_delivery_status_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_deliveries';
        $row = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) AS in_transit,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered
             FROM {$table}
             WHERE delivery_reference != 'pending'"
        );
        return (object) [
            'scheduled'   => $row && $row->scheduled !== null ? (int) $row->scheduled : 0,
            'in_transit' => $row && $row->in_transit !== null ? (int) $row->in_transit : 0,
            'delivered'  => $row && $row->delivered !== null ? (int) $row->delivered : 0,
        ];
    }

    /**
     * Upcoming deliveries: next 5–7 scheduled/in_transit, ordered by dispatch_date ASC.
     *
     * @param int $limit
     * @return array
     */
    public static function get_upcoming_deliveries($limit = 7) {
        global $wpdb;
        $d_table   = $wpdb->prefix . 'kit_deliveries';
        $sd_table  = $wpdb->prefix . 'kit_shipping_directions';
        $oc_table  = $wpdb->prefix . 'kit_operating_countries';
        $wb_table  = $wpdb->prefix . 'kit_waybills';
        $dr_table  = $wpdb->prefix . 'kit_drivers';

        $driver_join = '';
        $driver_cols = '';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$dr_table}'") === $dr_table) {
            $col = $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'driver_id'",
                DB_NAME,
                $d_table
            ));
            if ($col) {
                $driver_join = "LEFT JOIN {$dr_table} dr ON d.driver_id = dr.id";
                $driver_cols = ", dr.name AS driver_name";
            }
        }

        $sql = "SELECT
                d.id,
                d.delivery_reference,
                d.dispatch_date,
                d.truck_number,
                d.status,
                oc1.country_name AS origin_country_name,
                oc2.country_name AS destination_country_name,
                (SELECT COUNT(*) FROM {$wb_table} w WHERE w.delivery_id = d.id) AS waybill_count
                {$driver_cols}
             FROM {$d_table} d
             LEFT JOIN {$sd_table} sd ON d.direction_id = sd.id
             LEFT JOIN {$oc_table} oc1 ON sd.origin_country_id = oc1.id
             LEFT JOIN {$oc_table} oc2 ON sd.destination_country_id = oc2.id
             {$driver_join}
             WHERE d.delivery_reference != 'pending'
             AND d.status IN ('scheduled', 'in_transit')
             AND d.dispatch_date >= CURDATE()
             ORDER BY d.dispatch_date ASC
             LIMIT " . (int) $limit;

        return $wpdb->get_results($sql);
    }

    /**
     * Recent waybills: last N waybills with customer and destination info.
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent_waybills($limit = 10) {
        global $wpdb;
        $w_table  = $wpdb->prefix . 'kit_waybills';
        $c_table  = $wpdb->prefix . 'kit_customers';
        $cities   = $wpdb->prefix . 'kit_operating_cities';
        $sd_table = $wpdb->prefix . 'kit_shipping_directions';
        $oc_table = $wpdb->prefix . 'kit_operating_countries';

        $sql = "SELECT
                w.id AS waybill_id,
                w.waybill_no,
                w.status,
                w.created_at,
                w.customer_id AS customer_id,
                w.company_id AS company_id,
                c.name AS customer_name,
                c.surname AS customer_surname,
                COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) AS company_name,
                COALESCE(ci.city_name, oc.country_name, '') AS destination
             FROM {$w_table} w
             LEFT JOIN {$c_table} c ON w.customer_id = c.cust_id
             LEFT JOIN {$wpdb->prefix}kit_company_customers co ON w.company_id = co.company_id
             LEFT JOIN {$wpdb->prefix}kit_company_customers cust_co ON c.company_id = cust_co.company_id
             LEFT JOIN {$sd_table} sd ON w.direction_id = sd.id
             LEFT JOIN {$oc_table} oc ON sd.destination_country_id = oc.id
             LEFT JOIN {$cities} ci ON w.city_id = ci.id
             ORDER BY w.created_at DESC
             LIMIT " . (int) $limit;

        return $wpdb->get_results($sql);
    }

    /**
     * Party label for a recent-waybill row: person name, or company when that is the party.
     */
    public static function waybill_party_display_name($waybill): string
    {
        $name = trim((string) ($waybill->customer_name ?? ''));
        $surname = trim((string) ($waybill->customer_surname ?? ''));
        $company = trim((string) ($waybill->company_name ?? ''));

        if (class_exists('KIT_Company_Customers') && KIT_Company_Customers::is_placeholder_company($company)) {
            $company = '';
        }

        if (class_exists('KIT_Customers') && method_exists('KIT_Customers', 'person_name_for_display')) {
            return KIT_Customers::person_name_for_display($name, $surname, $company);
        }

        return trim($name . ' ' . $surname) ?: $company;
    }

    /**
     * Customer ID of the most recent waybill (for "Recent Customer" button on create waybill form).
     *
     * @return int Customer ID or 0 if none.
     */
    public static function get_last_waybill_customer_id() {
        $recent = self::get_recent_waybills(1);
        if (empty($recent) || empty($recent[0]->customer_id)) {
            return 0;
        }
        return (int) $recent[0]->customer_id;
    }

    /**
     * Deliveries for map: active (scheduled + in_transit) with origin/destination and city for geocoding.
     * Filter: today and next 7 days to avoid overcrowding.
     *
     * @return array
     */
    public static function get_deliveries_for_map() {
        global $wpdb;
        $d_table  = $wpdb->prefix . 'kit_deliveries';
        $sd_table = $wpdb->prefix . 'kit_shipping_directions';
        $oc_table = $wpdb->prefix . 'kit_operating_countries';
        $ci_table = $wpdb->prefix . 'kit_operating_cities';
        $dr_table = $wpdb->prefix . 'kit_drivers';

        $driver_join = '';
        $driver_col = '';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$dr_table}'") === $dr_table) {
            $col = $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'driver_id'",
                DB_NAME,
                $d_table
            ));
            if ($col) {
                $driver_join = "LEFT JOIN {$dr_table} dr ON d.driver_id = dr.id";
                $driver_col = ", dr.name AS driver_name";
            }
        }

        $sql = "SELECT
                d.id,
                d.delivery_reference,
                d.dispatch_date,
                d.truck_number,
                d.status,
                oc1.country_name AS origin_country,
                oc2.country_name AS destination_country,
                COALESCE(ci.city_name, '') AS destination_city
                {$driver_col}
             FROM {$d_table} d
             LEFT JOIN {$sd_table} sd ON d.direction_id = sd.id
             LEFT JOIN {$oc_table} oc1 ON sd.origin_country_id = oc1.id
             LEFT JOIN {$oc_table} oc2 ON sd.destination_country_id = oc2.id
             LEFT JOIN {$ci_table} ci ON d.destination_city_id = ci.id
             {$driver_join}
             WHERE d.delivery_reference != 'pending'
             AND d.status IN ('scheduled', 'in_transit')
             AND d.dispatch_date >= CURDATE()
             AND d.dispatch_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY d.dispatch_date ASC";

        $rows = $wpdb->get_results($sql);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'                  => (int) $r->id,
                'delivery_reference'  => $r->delivery_reference,
                'dispatch_date'       => $r->dispatch_date,
                'truck_number'        => $r->truck_number ?? '',
                'driver_name'         => isset($r->driver_name) ? $r->driver_name : '',
                'origin_country'      => $r->origin_country ?? '',
                'destination_country' => $r->destination_country ?? '',
                'destination_city'    => $r->destination_city ?? '',
            ];
        }
        return $out;
    }
}
