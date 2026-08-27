<?php
// includes/routes/routes-functions.php

if (! defined('ABSPATH')) {
    exit;
}

class KIT_Routes
{
    public static function init()
    {
        // Removed duplicate admin_menu registration — routes menu lives in admin-menu.php
        // Route management is staff-only, so no nopriv variants are registered.
        // delete_route / update_route / get_route / get_route_by_name / get_route_by_description
        // were registered without ever being implemented — calling them fataled.
        add_action('admin_post_create_route', [self::class, 'create_route']);
        add_action('wp_ajax_create_route', [self::class, 'create_route']);
        // DataTables server-side endpoint for routes
        add_action('wp_ajax_routes_datatable', [self::class, 'routes_datatable']);
        // Export waybills CSV
        add_action('admin_post_kit_export_waybills_csv', [self::class, 'export_waybills_csv']);
        // Route status toggle
        add_action('wp_ajax_toggle_route_status', [self::class, 'handle_toggle_route_status']);
    }

    public static function get_country_name_by_id($country_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_operating_countries';
        $country_name = $wpdb->get_var($wpdb->prepare("SELECT country_name FROM $table WHERE id = %d", $country_id));
        return $country_name;
    }

    public static function get_city_name_by_id($city_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_operating_cities';
        $city_name = $wpdb->get_var($wpdb->prepare("SELECT city_name FROM $table WHERE id = %d", $city_id));
        return $city_name;
    }

    /**
     * Primary operating city for a country (e.g. Johannesburg for SA, Dar es Salaam for TZ).
     */
    public static function get_default_city_id_for_country(int $country_id): int
    {
        if ($country_id <= 0) {
            return 1;
        }

        static $cache = [];
        if (isset($cache[$country_id])) {
            return $cache[$country_id];
        }

        global $wpdb;
        $city_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}kit_operating_cities WHERE country_id = %d ORDER BY id ASC LIMIT 1",
            $country_id
        ));

        $cache[$country_id] = $city_id > 0 ? $city_id : 1;

        return $cache[$country_id];
    }

    public static function get_direction_destination_country_id(int $direction_id): int
    {
        if ($direction_id <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT destination_country_id FROM {$wpdb->prefix}kit_shipping_directions WHERE id = %d LIMIT 1",
            $direction_id
        ));
    }

    public static function city_belongs_to_country(int $city_id, int $country_id): bool
    {
        if ($city_id <= 0 || $country_id <= 0) {
            return false;
        }

        global $wpdb;
        $city_country_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT country_id FROM {$wpdb->prefix}kit_operating_cities WHERE id = %d LIMIT 1",
            $city_id
        ));

        return $city_country_id === $country_id;
    }

    /**
     * Ensure a delivery destination city matches the route's destination country.
     * Falls back to that country's default city when missing or mismatched (e.g. JHB on SA→TZ).
     */
    public static function resolve_destination_city_id(int $direction_id, int $requested_city_id = 0): int
    {
        $dest_country_id = self::get_direction_destination_country_id($direction_id);
        if ($dest_country_id <= 0) {
            return max(1, $requested_city_id);
        }

        $default = self::get_default_city_id_for_country($dest_country_id);
        if ($requested_city_id > 0 && self::city_belongs_to_country($requested_city_id, $dest_country_id)) {
            return $requested_city_id;
        }

        return $default;
    }

    public static function get_city_country_id(int $city_id): int
    {
        if ($city_id <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT country_id FROM {$wpdb->prefix}kit_operating_cities WHERE id = %d LIMIT 1",
            $city_id
        ));
    }

    public static function find_direction_id_for_destination_country(int $destination_country_id): int
    {
        if ($destination_country_id <= 0) {
            return 1;
        }

        global $wpdb;
        $direction_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}kit_shipping_directions
             WHERE destination_country_id = %d
             ORDER BY id ASC LIMIT 1",
            $destination_country_id
        ));

        return $direction_id > 0 ? $direction_id : 1;
    }

    /**
     * Align kit_deliveries direction + destination city during seed/sync.
     *
     * @return array{direction_id:int,destination_city_id:int}
     */
    public static function reconcile_delivery_route(int $direction_id, int $destination_city_id): array
    {
        $direction_id = max(1, $direction_id);
        $destination_city_id = self::resolve_destination_city_id($direction_id, $destination_city_id);

        $city_country = self::get_city_country_id($destination_city_id);
        $dir_dest_country = self::get_direction_destination_country_id($direction_id);
        if ($city_country > 0 && $dir_dest_country > 0 && $city_country !== $dir_dest_country) {
            $direction_id = self::find_direction_id_for_destination_country($city_country);
            $destination_city_id = self::resolve_destination_city_id($direction_id, $destination_city_id);
        }

        return [
            'direction_id' => $direction_id,
            'destination_city_id' => $destination_city_id,
        ];
    }

    public static function get_routes()
    {
        global $wpdb;
        //Left join the wp_kit_operating_countries table to the wp_kit_shipping_directions table on the origin_country_id and destination_country_id
        $routes = $wpdb->get_results("SELECT sd.id as route_id, sd.origin_country_id, sd.destination_country_id, sd.description, sd.is_active, oc.country_name as origin_country_name, dc.country_name as destination_country_name
        FROM {$wpdb->prefix}kit_shipping_directions sd
        LEFT JOIN {$wpdb->prefix}kit_operating_countries oc ON sd.origin_country_id = oc.id
        LEFT JOIN {$wpdb->prefix}kit_operating_countries dc ON sd.destination_country_id = dc.id");

        return $routes;
    }

    /**
     * DataTables server-side provider for Routes
     */
    public static function routes_datatable()
    {
        // Allow any user with waybill viewing capability to see routes (admins and managers/data capturers)
        if (! (current_user_can('manage_options') || current_user_can('kit_view_waybills'))) {
            wp_send_json(['draw' => intval($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        global $wpdb;

        $table = $wpdb->prefix . 'kit_shipping_directions';
        $countries = $wpdb->prefix . 'kit_operating_countries';

        $draw   = intval($_POST['draw'] ?? 0);
        $start  = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 10);
        $search = isset($_POST['search']['value']) ? sanitize_text_field($_POST['search']['value']) : '';

        $base_sql = "FROM {$table} sd
            LEFT JOIN {$countries} oc ON sd.origin_country_id = oc.id
            LEFT JOIN {$countries} dc ON sd.destination_country_id = dc.id";

        // Total records
        $records_total = intval($wpdb->get_var("SELECT COUNT(*) {$base_sql}"));

        // Filtering
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = "WHERE (oc.country_name LIKE %s OR dc.country_name LIKE %s OR sd.description LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params = [$like, $like, $like];
        }

        // Ordering
        $order_col_index = intval($_POST['order'][0]['column'] ?? 0);
        $order_dir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $order_columns = ['sd.id', 'oc.country_name', 'dc.country_name', 'sd.description', 'sd.is_active'];
        $order_by = $order_columns[$order_col_index] ?? 'sd.id';

        // Records filtered
        if ($where) {
            $records_filtered = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$base_sql} {$where}", ...$params)));
        } else {
            $records_filtered = $records_total;
        }

        // Data query
        $limit_sql = $wpdb->prepare(" LIMIT %d OFFSET %d", $length, $start);
        $select = "SELECT sd.id as route_id, sd.description, sd.is_active, oc.country_name as origin_country_name, dc.country_name as destination_country_name";
        $sql = "{$select} {$base_sql} {$where} ORDER BY {$order_by} {$order_dir} {$limit_sql}";
        $rows = $where ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);

        $data = array_map(function ($r) {
            return [
                'route_id' => intval($r->route_id),
                'origin_country_name' => esc_html($r->origin_country_name),
                'destination_country_name' => esc_html($r->destination_country_name),
                'description' => esc_html($r->description),
                'status' => $r->is_active ? '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">Active</span>' : '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">Inactive</span>',
                'actions' => '<a href="?page=route-create&route_id=' . intval($r->route_id) . '&route_atts=edit_route" class="text-blue-600 hover:text-blue-800">Edit</a>'
            ];
        }, $rows);

        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => $records_total,
            'recordsFiltered' => $records_filtered,
            'data' => $data,
        ]);
    }

    /**
     * Export waybills as CSV with optional from/to filters.
     */
    public static function export_waybills_csv()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';

        $from = isset($_GET['from']) && $_GET['from'] !== '' ? sanitize_text_field($_GET['from']) : null;
        $to   = isset($_GET['to']) && $_GET['to'] !== '' ? sanitize_text_field($_GET['to']) : null;

        $where = [];
        $params = [];
        if ($from) {
            $where[] = 'created_at >= %s';
            $params[] = $from . ' 00:00:00';
        }
        if ($to) {
            $where[] = 'created_at <= %s';
            $params[] = $to   . ' 23:59:59';
        }

        $sql = "SELECT id, waybill_no, created_at, status, product_invoice_amount FROM {$waybills_table}" . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY created_at DESC";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=waybills.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, array_keys($rows[0] ?? ['id', 'waybill_no', 'created_at', 'status', 'product_invoice_amount']));
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    public static function plugin_route_management_page()
    {
        if (
            isset($_POST['bulk_action'], $_POST['bulk_ids'], $_POST['bulk_nonce'])
            && sanitize_text_field(wp_unslash($_POST['bulk_action'])) === 'delete'
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bulk_nonce'])), 'bulk_action_nonce')
        ) {
            if (!current_user_can('kit_view_waybills')) {
                wp_die('Unauthorized');
            }
            global $wpdb;
            $table = $wpdb->prefix . 'kit_shipping_directions';
            $ids = array_filter(array_map('intval', explode(',', sanitize_text_field(wp_unslash($_POST['bulk_ids'])))));
            foreach ($ids as $rid) {
                $wpdb->delete($table, ['id' => $rid], ['%d']);
            }
            $redirect = wp_get_referer() ?: admin_url('admin.php?page=route-management');
            wp_safe_redirect(remove_query_arg(['_wp_http_referer'], $redirect));
            exit;
        }

        // Enqueue DataTables assets for this admin page
        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css', [], '1.13.6');
        }
        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('jquery');
            wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', ['jquery'], '1.13.6', true);
        }
        $countries = KIT_Commons::get_countries();
        $cities = KIT_Commons::get_cities();

        $origin_country = isset($_GET['origin_country']) ? $_GET['origin_country'] : '';
        $destination_country = isset($_GET['destination_country']) ? $_GET['destination_country'] : '';
        $status = isset($_GET['status']) ? $_GET['status'] : '';

        $routes = self::get_routes();
        $total_routes = count($routes);
        $active_routes = count(array_filter($routes, function ($route) {
            return $route->is_active == 1;
        }));
        $inactive_routes = count(array_filter($routes, function ($route) {
            return $route->is_active == 0;
        }));

        // Get recent routes for overview
        $recent_routes = array_slice($routes, 0, 5);
?>

        <div class="wrap">
            <div class="<?php echo KIT_Commons::containerClasses(); ?>">
                <?php
                echo KIT_Commons::showingHeader([
                    'title' => 'Route Management',
                    'desc' => 'Manage shipping routes and destinations',
                ]);
                ?>

                <div class="<?= KIT_Commons::container() ?>">
                    <!-- Overview Section -->
                    <div>
                        <!-- Statistics Cards -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                            <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e5e7eb;">
                                <div style="display: flex; align-items: center; gap: 16px;">
                                    <div style="width: 48px; height: 48px; background: #dbeafe; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                        <span style="font-size: 24px; color: #2563eb;">🗺️</span>
                                    </div>
                                    <div>
                                        <p style="font-size: 14px; color: #6b7280; margin: 0 0 4px 0;">Total Routes</p>
                                        <p style="font-size: 28px; font-weight: 700; color: #111827; margin: 0;"><?php echo $total_routes; ?></p>
                                    </div>
                                </div>
                            </div>

                            <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e5e7eb;">
                                <div style="display: flex; align-items: center; gap: 16px;">
                                    <div style="width: 48px; height: 48px; background: #dcfce7; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                        <span style="font-size: 24px; color: #16a34a;">✅</span>
                                    </div>
                                    <div>
                                        <p style="font-size: 14px; color: #6b7280; margin: 0 0 4px 0;">Active Routes</p>
                                        <p style="font-size: 28px; font-weight: 700; color: #111827; margin: 0;"><?php echo $active_routes; ?></p>
                                    </div>
                                </div>
                            </div>

                            <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e5e7eb;">
                                <div style="display: flex; align-items: center; gap: 16px;">
                                    <div style="width: 48px; height: 48px; background: #fee2e2; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                        <span style="font-size: 24px; color: #dc2626;">❌</span>
                                    </div>
                                    <div>
                                        <p style="font-size: 14px; color: #6b7280; margin: 0 0 4px 0;">Inactive Routes</p>
                                        <p style="font-size: 28px; font-weight: 700; color: #111827; margin: 0;"><?php echo $inactive_routes; ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>



                    </div>

                    <!-- All Routes Section -->
                    <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; margin-top: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <?= KIT_Commons::bossText([
                                'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
                                'words' => 'All Shipping Routes',
                                'size' => '2xl',
                                'color' => 'black',
                                'classes' => '',
                                'tag' => 'h2'
                            ]) ?> <div style="display: flex; gap: 12px;">

                                <?php echo KIT_Commons::renderButton('Create New', 'primary', 'lg', ['onclick' => 'window.location.href=\'?page=route-create\'', 'gradient' => true]); ?>
                                <?php echo KIT_Commons::renderButton('Manage Countries', 'secondary', 'lg', ['onclick' => 'window.location.href=\'?page=08600-countries\'', 'gradient' => false]); ?>
                            </div>
                        </div>


                        <?php
                        // Get routes data
                        $routes = self::get_routes();

                        // Convert to array format for unified table
                        $routeData = [];
                        foreach ($routes as $route) {
                            $routeData[] = [
                                'id' => $route->route_id,
                                'route_id' => $route->route_id,
                                'origin_country_name' => $route->origin_country_name,
                                'destination_country_name' => $route->destination_country_name,
                                'description' => $route->description,
                                'is_active' => $route->is_active,
                                'city' => $route->destination_country_name ?: 'Unassigned City',
                                'status' => !empty($route->is_active) ? 'active' : 'inactive',
                            ];
                        }

                        // Define columns
                        $columns = [
                            'route_id' => 'Route ID',
                            'origin_country_name' => 'Origin',
                            'destination_country_name' => 'Destination',
                            'description' => 'Description',
                            'is_active' => [
                                'label' => 'Status',
                                'sortable' => true,
                                'searchable' => false,
                                'callback' => function ($value, $row) {
                                    $status_text = $value ? 'Active' : 'Inactive';
                                    $status_class = $value ? 'status-active' : 'status-inactive';
                                    $status_color = $value ? '#16a34a' : '#dc2626';
                                    $toggle_text = $value ? 'Deactivate' : 'Activate';
                                    $new_status = $value ? 0 : 1;

                                    return '
                                    <div class="flex items-center gap-2">
                                        <span class="' . $status_class . '" style="color: ' . $status_color . '; font-weight: 600;">● ' . $status_text . '</span>
                                        ' . KIT_Commons::renderButton($toggle_text, 'ghost', 'lg', [
                                        'type' => 'button',
                                        'classes' => 'route-toggle-btn text-xs px-2 py-1 rounded border hover:bg-gray-50',
                                        'data-route-id' => $row['route_id'],
                                        'data-new-status' => $new_status,
                                        'style' => 'color: ' . esc_attr($status_color) . '; border-color: ' . esc_attr($status_color) . ';',
                                    ]) . '
                                    </div>
                                ';
                                }
                            ]
                        ];

                        // Define actions
                        $actions = [
                            [
                                'label' => 'Edit',
                                'href' => '?page=route-create&route_id={route_id}&route_atts=edit_route',
                                'class' => 'text-blue-600 hover:text-blue-800'
                            ]
                        ];

                        // Render unified table with advanced features
                        echo KIT_Unified_Table::infinite($routeData, $columns, KIT_Unified_Table::optionsWithManageDefaults([
                            'title' => 'All Routes',
                            'actions' => $actions,
                            'bulk_actions_list' => ['delete', 'export'],
                            'empty_message' => 'No routes found',
                            'search_placeholder' => 'Search routes...',
                            'search_filters' => [
                                ['value' => 'origin_country_name', 'label' => 'Origin', 'placeholder' => 'Search origin...'],
                                ['value' => 'destination_country_name', 'label' => 'Destination', 'placeholder' => 'Search destination...'],
                                ['value' => 'description', 'label' => 'Description', 'placeholder' => 'Search description...'],
                            ],
                            'search_default_filter' => 'destination_country_name',
                        ]));
                        ?>
                    </div>

                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Quick toggle functionality for routes
                $(document).on('click', '.route-toggle-btn', function(e) {
                    e.preventDefault();

                    const $btn = $(this);
                    const routeId = $btn.data('route-id');
                    const newStatus = $btn.data('new-status');
                    const $row = $btn.closest('tr');

                    // Disable button during request
                    $btn.prop('disabled', true).text('Updating...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'toggle_route_status',
                            route_id: routeId,
                            new_status: newStatus,
                            nonce: '<?php echo wp_create_nonce('toggle_route_status'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                // Reload the page to show updated status
                                location.reload();
                            } else {
                                alert('Error: ' + (response.data.message || 'Failed to update route status'));
                                $btn.prop('disabled', false).text($btn.data('original-text'));
                            }
                        },
                        error: function() {
                            alert('Error: Failed to update route status');
                            $btn.prop('disabled', false).text($btn.data('original-text'));
                        }
                    });
                });

                // Store original button text
                $('.route-toggle-btn').each(function() {
                    $(this).data('original-text', $(this).text());
                });
            });
        </script>

        <script>
            function editRoute(routeId) {
                window.location.href = '?page=route-create&route_id=' + routeId + '&route_atts=edit_route';
            }

            function testToast() {
                if (window.KITToast) {
                    // Test different toast types
                    window.KITToast.show('This is a success message!', 'success', 'Test Success');
                    setTimeout(() => {
                        window.KITToast.show('This is an error message!', 'error', 'Test Error');
                    }, 1000);
                    setTimeout(() => {
                        window.KITToast.show('This is a warning message!', 'warning', 'Test Warning');
                    }, 2000);
                    setTimeout(() => {
                        window.KITToast.show('This is an info message!', 'info', 'Test Info');
                    }, 3000);
                } else {
                    alert('Toast system not loaded. Please refresh the page.');
                }
            }
        </script>


    <?php
    }

    /**
     * Render the Route form for create/edit.
     * @param array|null $routeData
     */
    public static function routeForm($routeData = null)
    {
        // Get countries and cities
        if ($routeData !== null) {
            $origin_country_id = kit_Commons::verifyint($routeData->origin_country_id);
            $destination_country_id = kit_Commons::verifyint($routeData->destination_country_id);
            $route_status = $routeData->is_active;
            $currentCountryId = $origin_country_id;
        } else {
            $route_status = 'active'; // or 1, depending on your logic
        }
    ?>
        <div style="display: flex; flex-direction: column; gap: 24px;">
            <!-- Origin Section -->
            <div>
                <h3 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 16px 0;">Origin Details</h3>
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin: 0 0 8px 0;">Origin Country & City</label>
                        <div style="background: #f9fafb; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">
                            <?php
                            $kit_origin_selects = [
                                'country_name' => 'country_id',
                                'city_name' => 'city_id',
                                'route_data' => ($routeData ?? null),
                                'country_select_options' => [
                                    'show_all_countries' => true,
                                    'show_inactive_indicators' => true,
                                ],
                            ];
                            require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsOrigin.php';
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Destination Section -->
            <div>
                <h3 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 16px 0;">Destination Details</h3>
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin: 0 0 8px 0;">Destination Country & City</label>
                        <div style="background: #f9fafb; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">
                            <?php require(COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsDestination.php'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Route Status -->
            <div>
                <h3 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 16px 0;">Route Status</h3>
                <div style="display: flex; gap: 16px;">
                    <?php
                    $statuses = [
                        'active' => 'Active',
                        'inactive' => 'Inactive'
                    ];
                    foreach ($statuses as $value => $label) {
                        $isChecked = ($route_status === $value || $route_status === ($value === 'active' ? 1 : 0));
                    ?>
                        <label class="route-status-radio" style="display: block; width: 100px; height: 80px; border-radius: 12px; border: 2px solid; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; transition: all 0.2s ease; user-select: none; <?php echo $isChecked ? 'border-color: #2563eb; background: #dbeafe; color: #1e40af; box-shadow: 0 4px 6px rgba(37, 99, 235, 0.1);' : 'border-color: #d1d5db; background: white; color: #6b7280;'; ?>">
                            <input
                                type="radio"
                                name="route_status"
                                id="route_status_<?php echo esc_attr($value); ?>"
                                value="<?php echo esc_attr($value); ?>"
                                style="display: none;"
                                <?php checked($isChecked); ?> />
                            <span><?php echo esc_html($label); ?></span>
                        </label>
                    <?php } ?>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const radios = document.querySelectorAll('input[name="route_status"]');
                radios.forEach(function(radio) {
                    radio.addEventListener('change', function() {
                        document.querySelectorAll('.route-status-radio').forEach(function(label) {
                            label.style.borderColor = '#d1d5db';
                            label.style.background = 'white';
                            label.style.color = '#6b7280';
                            label.style.boxShadow = 'none';
                        });
                        if (radio.checked && radio.parentElement) {
                            radio.parentElement.style.borderColor = '#2563eb';
                            radio.parentElement.style.background = '#dbeafe';
                            radio.parentElement.style.color = '#1e40af';
                            radio.parentElement.style.boxShadow = '0 4px 6px rgba(37, 99, 235, 0.1)';
                        }
                    });
                });
            });
        </script>
    <?php
    }

    public static function get_route_by_id($route_id)
    {
        global $wpdb;
        $route = $wpdb->get_row($wpdb->prepare(
            "SELECT sd.*, oc.country_name as origin_country_name, dc.country_name as destination_country_name
        FROM {$wpdb->prefix}kit_shipping_directions sd
        LEFT JOIN {$wpdb->prefix}kit_operating_countries oc ON sd.origin_country_id = oc.id
        LEFT JOIN {$wpdb->prefix}kit_operating_countries dc ON sd.destination_country_id = dc.id
        WHERE sd.id = %d",
            (int) $route_id
        ));

        return $route;
    }

    public static function route_create_page()
    {
        //route_id=1&route_atts=edit_route if route_id is set, then we are editing a route
        $route_id = isset($_GET['route_id']) ? $_GET['route_id'] : '';
        $route_atts = isset($_GET['route_atts']) ? $_GET['route_atts'] : '';
        if ($route_id && $route_atts == 'edit_route') {
            $route = self::get_route_by_id($route_id);
        }
        $is_page = isset($_GET['page']) ? $_GET['page'] : null;
        $is_route_id = isset($_GET['route_id']) ? $_GET['route_id'] : null;
        $is_route_atts = isset($_GET['route_atts']) ? $_GET['route_atts'] : null;

        $countries = KIT_Commons::get_countries();
        $cities = KIT_Commons::get_cities();

        $origin_country = isset($_GET['origin_country']) ? $_GET['origin_country'] : '';
        $origin_city = isset($_GET['origin_city']) ? $_GET['origin_city'] : '';
        $destination_country = isset($_GET['destination_country']) ? $_GET['destination_country'] : '';
        $destination_city = isset($_GET['destination_city']) ? $_GET['destination_city'] : '';
        $route_description = isset($_GET['route_description']) ? $_GET['route_description'] : '';
        $route_status = isset($_GET['route_status']) ? $_GET['route_status'] : '';

        $is_edit_mode = ($route_id && $route_atts == 'edit_route');
        $page_title = $is_edit_mode ? 'Edit Route' : 'Createf Route';
        $page_description = $is_edit_mode ? 'Update route details and settings' : 'Create a new shipping route';

    ?>
        <div class="wrap">
            <?php
            echo KIT_Commons::showingHeader([
                'title' => $page_title,
                'desc' => $page_description,
            ]);
            ?>

            <div class="<?= KIT_Commons::container() ?>">
                <div style="display: grid; grid-template-columns: 1fr 360px; gap: 24px; align-items: start;">
                    <!-- Form Card -->
                    <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e5e7eb;">
                        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <div style="display:flex; gap:8px; align-items:center;">
                                <?php echo KIT_Commons::renderButton('Back to Routes', 'secondary', 'lg', ['type' => 'button', 'onclick' => 'window.location.href=\'?page=route-management\'']); ?>
                                <span style="color:#9ca3af;">/</span>
                                <span style="color:#374151; font-weight:600;"><?php echo $is_edit_mode ? 'Edit' : 'Create'; ?> Route</span>
                            </div>

                        </div>

                        <form id="route-create-form" method="post" action="<?php echo admin_url('admin-ajax.php'); ?>">
                            <input type="hidden" name="action" value="create_route">
                            <input type="hidden" name="security" value="<?php echo wp_create_nonce('create_route'); ?>">
                            <?php if ($is_edit_mode) { ?>
                                <input type="hidden" name="route_id" value="<?php echo $route_id; ?>">
                            <?php } ?>

                            <?php if ($is_edit_mode) { ?>
                                <?php echo self::routeForm($route); ?>
                            <?php } else { ?>
                                <?php echo self::routeForm(); ?>
                            <?php } ?>

                            <div style="display: flex; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 1px solid #e5e7eb;">
                                <?php echo KIT_Commons::renderButton($is_edit_mode ? 'Update Route' : 'Create Route', 'primary', 'lg', ['type' => 'submit', 'gradient' => true]); ?>
                                <?php echo KIT_Commons::renderButton('Cancel', 'secondary', 'lg', ['type' => 'button', 'onclick' => 'window.location.href=\'?page=route-management\'']); ?>
                            </div>
                        </form>
                    </div>

                    <!-- Preview / Tips Card -->
                    <div style="position: sticky; top: 20px; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e5e7eb;">
                        <h3 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 12px 0;">Route Preview</h3>
                        <div id="route-preview" style="background:#f9fafb; border:1px dashed #e5e7eb; border-radius:8px; padding:12px; margin-bottom:16px;">
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; color:#374151;">
                                <span>Origin:</span>
                                <strong id="preview-origin">Not set</strong>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; color:#374151;">
                                <span>Destination:</span>
                                <strong id="preview-destination">Not set</strong>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px; color:#374151;">
                                <span>Status:</span>
                                <strong id="preview-status">Active</strong>
                            </div>
                        </div>

                        <h4 style="font-size: 14px; font-weight: 600; color: #111827; margin: 0 0 8px 0;">Tips</h4>
                        <ul style="margin:0; padding-left: 18px; color:#6b7280; font-size:13px; line-height:1.5;">
                            <li>Choose active operating countries for both origin and destination.</li>
                            <li>The description will auto-generate from your selections.</li>
                            <li>You can edit a route later from Route Management.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var form = document.getElementById('route-create-form');
                    if (!form) return;

                    function getSelectedText(name) {
                        var el = form.querySelector('[name="' + name + '"]');
                        if (!el) return '';
                        var opt = el.options ? el.options[el.selectedIndex] : null;
                        return opt ? opt.text.trim() : '';
                    }

                    function updatePreview() {
                        var origin = getSelectedText('country_id');
                        var destination = getSelectedText('destination_country');
                        var statusRadio = form.querySelector('input[name="route_status"]:checked');
                        var status = statusRadio ? (statusRadio.value === 'active' ? 'Active' : 'Inactive') : 'Active';

                        var previewOrigin = document.getElementById('preview-origin');
                        var previewDestination = document.getElementById('preview-destination');
                        var previewStatus = document.getElementById('preview-status');

                        if (previewOrigin) previewOrigin.textContent = origin || 'Not set';
                        if (previewDestination) previewDestination.textContent = destination || 'Not set';
                        if (previewStatus) previewStatus.textContent = status;
                    }

                    form.addEventListener('change', function(e) {
                        if (['country_id', 'destination_country', 'route_status'].includes(e.target.name)) {
                            updatePreview();
                        }
                    });

                    // Initialize preview only if form elements exist
                    if (form.querySelector('[name="country_id"]') && form.querySelector('[name="destination_country"]')) {
                        updatePreview();
                    }

                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        var originInput = form.querySelector('[name="country_id"]');
                        var destinationInput = form.querySelector('[name="destination_country"]');

                        var origin = originInput ? originInput.value : '';
                        var destination = destinationInput ? destinationInput.value : '';

                        if (!origin || !destination) {
                            if (window.KITToast) {
                                window.KITToast.show('Please select both origin and destination countries.', 'warning', 'Validation');
                            } else {
                                alert('Please select both origin and destination countries.');
                            }
                            return;
                        }

                        var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                        submitButtons.forEach(function(btn) {
                            btn.disabled = true;
                        });

                        var formData = new FormData(form);
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: formData
                        }).then(function(res) {
                            // Check if response is ok
                            if (!res.ok) {
                                throw new Error('HTTP ' + res.status + ': ' + res.statusText);
                            }
                            return res.json();
                        }).then(function(json) {
                            if (json && json.success) {
                                if (window.KITToast) {
                                    window.KITToast.show('Route created successfully.', 'success', 'Success');
                                }
                                window.location.href = '<?php echo admin_url('admin.php?page=route-management'); ?>';
                            } else {
                                var message = (json && json.data) ? json.data : 'Failed to create route.';
                                if (window.KITToast) {
                                    window.KITToast.show(message, 'error', 'Error');
                                } else {
                                    alert(message);
                                }
                            }
                        }).catch(function(error) {
                            console.error('Route creation error:', error);
                            var message = 'Network error. Please try again.';
                            if (window.KITToast) {
                                window.KITToast.show(message, 'error', 'Error');
                            } else {
                                alert(message);
                            }
                        }).finally(function() {
                            submitButtons.forEach(function(btn) {
                                btn.disabled = false;
                            });
                        });
                    });
                });
            </script>
        </div>
<?php
    }

    public static function create_route()
    {
        global $wpdb;

        // Verify nonce for security
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'create_route')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options') && !current_user_can('kit_update_data')) {
            wp_die('You do not have permission to manage routes.', 'Forbidden', ['response' => 403]);
        }

        // Sanitize/validate input
        $route_name = isset($_POST['route_name']) ? sanitize_text_field($_POST['route_name']) : '';
        // Routes form uses country_id / city_id; accept origin_* as fallback
        $origin_country_id = isset($_POST['country_id']) ? intval($_POST['country_id']) : (isset($_POST['origin_country']) ? intval($_POST['origin_country']) : 0);
        $origin_city_id = isset($_POST['city_id']) ? intval($_POST['city_id']) : (isset($_POST['origin_city']) ? intval($_POST['origin_city']) : 0);
        $destination_country_id = isset($_POST['destination_country']) ? intval($_POST['destination_country']) : 0;
        $destination_city_id = isset($_POST['destination_city']) ? intval($_POST['destination_city']) : 0;

        // Debug logging for troubleshooting
        error_log('Route creation debug - POST data: ' . print_r($_POST, true));
        error_log('Route creation debug - Origin country ID: ' . $origin_country_id);
        error_log('Route creation debug - Destination country ID: ' . $destination_country_id);

        // Validate required fields
        if (!$origin_country_id || !$destination_country_id) {
            $error_msg = 'Origin and destination countries are required';
            error_log('Route creation error: ' . $error_msg);
            if (defined('DOING_AJAX') && DOING_AJAX) {
                wp_send_json_error($error_msg);
            } else {
                wp_die($error_msg);
            }
        }

        // Tables
        $operating_countries_table = $wpdb->prefix . 'kit_operating_countries';
        $shipping_directions_table = $wpdb->prefix . 'kit_shipping_directions';

        $origin_country_name = '';
        $destination_country_name = '';

        // ✅ Fetch and patch country names and is_active = 1
        if ($origin_country_id && $destination_country_id) {
            // Get origin country
            $origin_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT country_name, is_active FROM $operating_countries_table WHERE id = %d",
                    $origin_country_id
                )
            );

            // Get destination country
            $destination_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT country_name, is_active FROM $operating_countries_table WHERE id = %d",
                    $destination_country_id
                )
            );

            // Update is_active = 1 if needed
            if ($origin_row && $origin_row->is_active == 0) {
                $wpdb->update(
                    $operating_countries_table,
                    ['is_active' => 1],
                    ['id' => $origin_country_id],
                    ['%d'],
                    ['%d']
                );
            }

            if ($destination_row && $destination_row->is_active == 0) {
                $wpdb->update(
                    $operating_countries_table,
                    ['is_active' => 1],
                    ['id' => $destination_country_id],
                    ['%d'],
                    ['%d']
                );
            }

            $origin_country_name = $origin_row ? $origin_row->country_name : '';
            $destination_country_name = $destination_row ? $destination_row->country_name : '';
        }

        // Create route description
        $route_description = $origin_country_name . ' to ' . $destination_country_name;

        $route_status = isset($_POST['route_status']) && $_POST['route_status'] === 'active' ? 1 : 0;

        $data = [
            'origin_country_id'      => $origin_country_id,
            'destination_country_id' => $destination_country_id,
            'description'            => $route_description,
            'is_active'              => $route_status,
            'created_at'             => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
        ];

        $is_ajax = defined('DOING_AJAX') && DOING_AJAX;

        // Check for duplicate route
        $existing_route = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $shipping_directions_table WHERE origin_country_id = %d AND destination_country_id = %d",
            $origin_country_id,
            $destination_country_id
        ));

        if ($existing_route) {
            $error_msg = 'Route already exists between these countries';
            error_log('Route creation error: ' . $error_msg);
            if ($is_ajax) {
                wp_send_json_error($error_msg);
            } else {
                wp_die($error_msg);
            }
        }

        $result = $wpdb->insert($shipping_directions_table, $data);

        if ($is_ajax) {
            if ($result) {
                wp_send_json_success('Route created successfully');
            } else {
                wp_send_json_error('Failed to create route: ' . ($wpdb->last_error ?: 'Unknown error'));
            }
        } else {
            if ($result) {
                // Add toast notification for non-AJAX requests
                if (class_exists('KIT_Toast')) {
                    KIT_Toast::ensure_toast_loads();
                    echo KIT_Toast::db_success('Route creation', 'Route created successfully');
                }
                wp_redirect(admin_url('admin.php?page=route-management'));
                exit;
            } else {
                // Add toast notification for non-AJAX errors
                if (class_exists('KIT_Toast')) {
                    KIT_Toast::ensure_toast_loads();
                    echo KIT_Toast::db_error('Route creation', $wpdb->last_error ?: 'Unknown error');
                }
            }
        }
    }

    /**
     * AJAX handler for quick route status toggle
     */
    public static function handle_toggle_route_status()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'toggle_route_status')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'kit_shipping_directions';
        $id = intval($_POST['route_id']);
        $new_status = intval($_POST['new_status']);

        $result = $wpdb->update($table, ['is_active' => $new_status], ['id' => $id]);

        if ($result !== false) {
            wp_send_json_success(['message' => 'Route status updated successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to update route status']);
        }
    }
}

KIT_Routes::init();
