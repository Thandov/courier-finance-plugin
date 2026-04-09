<?php
if (! defined('ABSPATH')) {
    exit;
}

// Include the DeliveryCard component
require_once plugin_dir_path(__FILE__) . '../components/deliveryCard.php';

// Include the Modal component
require_once plugin_dir_path(__FILE__) . '../components/modal.php';

// Include the QuickStats component
require_once plugin_dir_path(__FILE__) . '../components/quickStats.php';

class KIT_Deliveries
{
    public static function init()
    {
        add_action('admin_menu', [self::class, 'add_admin_menu']);
        add_action('admin_post_kit_deliveries_crud', [self::class, 'updateShippingDirection']);
        add_action('wp_ajax_kit_deliveries_crud', [self::class, 'updateShippingDirection']);
        add_action('wp_ajax_nopriv_kit_deliveries_crud', [self::class, 'updateShippingDirection']);
        add_action('wp_ajax_refresh_deliveries_table', [self::class, 'ajax_refresh_deliveries_table']);
        add_action('wp_ajax_delivery_changeTo_Intransit', [self::class, 'delivery_changeTo_Intransit']);
        add_action('wp_ajax_delivery_changeTo_Delivered', [self::class, 'delivery_changeTo_Delivered']);
        add_action('wp_ajax_delivery_changeTo_Scheduled', [self::class, 'delivery_changeTo_Scheduled']);
        add_action('wp_ajax_get_scheduled_deliveries', [self::class, 'getScheduledDeliveries']);
        add_action('wp_ajax_get_customers', [self::class, 'get_customers']);
        add_action('wp_ajax_get_deliveries_by_country', [self::class, 'handle_get_deliveries_by_country']);
        add_action('wp_ajax_nopriv_get_deliveries_by_country', [self::class, 'handle_get_deliveries_by_country']);
        add_action('wp_ajax_get_deliveries_by_country_id', [self::class, 'handle_get_deliveries_by_country_id']);
        add_action('wp_ajax_nopriv_get_deliveries_by_country_id', [self::class, 'handle_get_deliveries_by_country_id']);
        // Removed: destination city from misc->others - use waybills.city_id instead
        add_shortcode('country_select', [self::class, 'CountrySelect']);
        add_action('wp_ajax_handle_get_cities_for_country', [self::class, 'handle_get_cities_for_country_callback']);
        add_action('wp_ajax_nopriv_handle_get_cities_for_country', [self::class, 'handle_get_cities_for_country_callback']);
        add_action('wp_ajax_handle_get_countryDeliveries', [self::class, 'handle_get_countryDeliveries_callback']);
        add_action('wp_ajax_handle_get_price_per_kg', [self::class, 'handle_get_price_per_kg']);
        add_action('wp_ajax_nopriv_handle_get_price_per_kg', [self::class, 'handle_get_price_per_kg']);
        add_action('wp_ajax_handle_get_price_per_m3', [self::class, 'handle_get_price_per_m3']);
        add_action('wp_ajax_nopriv_handle_get_price_per_m3', [self::class, 'handle_get_price_per_m3']);
        add_action('wp_ajax_list_delivery_backups', [self::class, 'handle_list_delivery_backups']);
        add_action('wp_ajax_restore_delivery_backup', [self::class, 'handle_restore_delivery_backup']);
        add_action('wp_ajax_filter_deliveries', [self::class, 'ajax_filter_deliveries']);
        add_action('wp_ajax_nopriv_filter_deliveries', [self::class, 'ajax_filter_deliveries']);

        // Schedule daily task to update past deliveries
        add_action('init', [self::class, 'schedule_daily_delivery_status_update']);
        add_action('kit_daily_update_past_deliveries', [self::class, 'update_past_deliveries_to_unconfirmed']);
    }
    public static function shippingDirections()
    {
        //Use table wp_kit_shipping_directions
        global $wpdb;
        $table = $wpdb->prefix . 'kit_shipping_directions';
        $query = "SELECT id, origin_country_id, destination_country_id, description, is_active, created_at FROM $table";
        return $wpdb->get_results($query);
    }

    public static function getDirectionId($delivery_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_deliveries';
        $query = $wpdb->prepare("SELECT direction_id FROM $table WHERE id = %d", $delivery_id);
        return $wpdb->get_var($query);
    }

    public static function handle_get_price_per_m3()
    {
        global $wpdb;

        if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'get_waybills_nonce')) {
            wp_send_json_error(['message' => 'Invalid security token.']);
        }

        $direction_id    = isset($_POST['direction_id']) ? intval($_POST['direction_id']) : 0;
        $total_volume_m3 = isset($_POST['total_volume_m3']) ? floatval($_POST['total_volume_m3']) : 0;
        $origin_country  = isset($_POST['origin_country_id']) ? intval($_POST['origin_country_id']) : 0;
        $chargeGroup     = KIT_Waybills::chargeGroup($origin_country);

        if (! $total_volume_m3) {
            wp_send_json_error(['message' => 'Missing volume.']);
        }

        $table = $wpdb->prefix . 'kit_shipping_rates_volume';

        $lookupIds = [];
        if ($direction_id > 0) {
            $lookupIds[] = $direction_id;
        }
        if ($chargeGroup) {
            $lookupIds[] = intval($chargeGroup);
        }
        $lookupIds = array_values(array_unique(array_filter($lookupIds)));

        if (empty($lookupIds)) {
            wp_send_json_error(['message' => 'Unable to determine direction or charge group for volume rates.']);
        }

        $rate_per_m3 = null;
        foreach ($lookupIds as $lookupId) {
            $rate_per_m3 = $wpdb->get_var($wpdb->prepare("
            SELECT rate_per_m3
            FROM $table
            WHERE direction_id = %d
              AND %f BETWEEN min_volume AND max_volume
              AND is_active = 1
            ORDER BY effective_date DESC, min_volume DESC
            LIMIT 1", $lookupId, $total_volume_m3));

            if ($rate_per_m3 !== null) {
                break;
            }
        }

        if ($rate_per_m3 !== null) {
            wp_send_json_success(['rate_per_m3' => $rate_per_m3]);
        } else {
            wp_send_json_error(['message' => 'No matching volumetric rate found.']);
        }
    }

    public static function handle_get_price_per_kg()
    {
        global $wpdb;

        // ✅ BULLETPROOF: Comprehensive input validation and sanitization
        try {
            // Validate nonce for security
            if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'get_waybills_nonce')) {
                wp_send_json_error(['message' => 'Invalid security token.']);
                return;
            }

            // Sanitize and validate inputs
            $direction_id      = isset($_POST['direction_id']) ? intval($_POST['direction_id']) : 0;
            $total_mass_kg     = isset($_POST['total_mass_kg']) ? floatval($_POST['total_mass_kg']) : 0;
            $origin_country_id = isset($_POST['origin_country_id']) ? intval($_POST['origin_country_id']) : 0;

            // ✅ BULLETPROOF: Comprehensive validation
            if ($direction_id <= 0) {
                wp_send_json_error(['message' => 'Invalid direction ID.']);
                return;
            }

            if ($total_mass_kg <= 0) {
                wp_send_json_error(['message' => 'Mass must be greater than 0.']);
                return;
            }

            if ($total_mass_kg > 10000) { // Reasonable upper limit
                wp_send_json_error(['message' => 'Mass exceeds maximum limit of 10,000 kg.']);
                return;
            }

            // Get charge group with fallback
            $chargeGroup = KIT_Waybills::chargeGroup($origin_country_id);
            if (! $chargeGroup) {
                wp_send_json_error(['message' => 'Unable to determine charge group.']);
                return;
            }

            $table = $wpdb->prefix . 'kit_shipping_rates_mass';

            // ✅ BULLETPROOF: Fixed boundary conditions with proper weight range logic
            $rate_per_kg = $wpdb->get_var($wpdb->prepare("
            SELECT rate_per_kg
            FROM $table
            WHERE direction_id = %d
              AND min_weight <= %f
              AND (max_weight > %f OR max_weight = %f)
            ORDER BY effective_date DESC, min_weight DESC
            LIMIT 1", $chargeGroup, $total_mass_kg, $total_mass_kg, $total_mass_kg));

            // ✅ BULLETPROOF: Comprehensive error handling and fallbacks
            if ($rate_per_kg !== null && $rate_per_kg > 0) {
                $total_charge = round($rate_per_kg * $total_mass_kg, 2);

                // Validate calculated charge
                if ($total_charge <= 0) {
                    wp_send_json_error(['message' => 'Invalid calculated charge.']);
                    return;
                }

                wp_send_json_success([
                    'rate_per_kg'  => floatval($rate_per_kg),
                    'total_charge' => $total_charge,
                    'direction_id' => $direction_id,
                    'mass_kg'      => $total_mass_kg,
                    'charge_group' => $chargeGroup,
                ]);
            } else {
                // ✅ BULLETPROOF: Try fallback rate lookup
                $fallback_rate = $wpdb->get_var($wpdb->prepare("
                SELECT rate_per_kg
                FROM $table
                WHERE direction_id = %d
                ORDER BY effective_date DESC, min_weight ASC
                LIMIT 1", $chargeGroup));

                if ($fallback_rate !== null && $fallback_rate > 0) {
                    $total_charge = round($fallback_rate * $total_mass_kg, 2);
                    wp_send_json_success([
                        'rate_per_kg'  => floatval($fallback_rate),
                        'total_charge' => $total_charge,
                        'direction_id' => $direction_id,
                        'mass_kg'      => $total_mass_kg,
                        'charge_group' => $chargeGroup,
                        'fallback'     => true,
                    ]);
                } else {
                    wp_send_json_error([
                        'message'    => 'No matching rate found for the specified criteria.',
                        'debug_info' => [
                            'direction_id' => $direction_id,
                            'mass_kg'      => $total_mass_kg,
                            'charge_group' => $chargeGroup,
                        ],
                    ]);
                }
            }
        } catch (Exception $e) {
            // ✅ BULLETPROOF: Catch any unexpected errors
            error_log('Rate fetch error: ' . $e->getMessage());
            wp_send_json_error([
                'message'    => 'An unexpected error occurred while fetching rates.',
                'error_code' => 'RATE_FETCH_ERROR',
            ]);
        }
    }

    public static function handle_get_countryDeliveries_callback()
    {

        check_ajax_referer('get_waybills_nonce', 'nonce');

        $country_id = isset($_POST['country_id']) ? ($_POST['country_id']) : 0;

        if (! $country_id) {
            wp_send_json_error(['message' => 'Missing country country_id']);
        }

        $deliveries = KIT_Deliveries::getScheduledCountryDeliveries($country_id);

        wp_send_json_success($deliveries);
    }
    public static function kit_get_Cities_forCountry()
    {
        if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'get_waybills_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        $country_id = intval($_POST['country_id'] ?? 0);

        if (! $country_id) {
            wp_send_json_error('Invalid country ID');
        }

        $cities = KIT_Deliveries::get_Cities_forCountry($country_id);

        if (! is_array($cities)) {
            wp_send_json_error('No cities found');
        }

        wp_send_json_success($cities);
    }
    public static function handle_get_cities_for_country()
    {
        // Verify nonce
        check_ajax_referer('get_waybills_nonce', 'nonce');

        if (! isset($_POST['country_id'])) {
            wp_send_json_error('Country ID is required');
        }

        $country_id = sanitize_text_field($_POST['country_id']);

        // Replace this with your actual database query
        global $wpdb;
        $cities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}kit_operating_cities WHERE country_id = %s",
            $country_id
        ));

        if ($wpdb->last_error) {
            wp_send_json_error($wpdb->last_error);
        }

        wp_send_json_success($cities);
    }
    public static function generateDeliveryRef()
    {
        //example of ref DEL-20250601-001
        //Check if the reference in the delivery table exists before
        global $wpdb;
        $table = $wpdb->prefix . 'kit_deliveries';

        $date    = date('Ymd');
        $counter = 1;

        do {
            $ref    = sprintf('DEL-%s-%03d', $date, $counter);
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE delivery_reference = %s",
                $ref
            ));
            $counter++;
        } while ($exists);

        return $ref;
    }
    public static function add_admin_menu()
    {
        // Menu registration moved to unified 08600 Waybills menu
        // Individual menu registration commented out to avoid conflicts

        /*
        add_menu_page(
            'Deliveries Management',
            'Deliveries',
            'edit_pages',
            'kit-deliveries',
            [__CLASS__, 'render_admin_page'],
            'dashicons-car',
            6
        );
        add_submenu_page(
            'Deliveries',       // Parent slug (e.g., under Pages)
            'View Delivery',                // Page title
            '',                // Menu title
            'edit_pages',                // Capability
            'view-deliveries',         // Menu slug
            [__CLASS__, 'view_deliveries_page']           // Callback function to display the page
        );
        */

        //Create a packing list for the deliver. So it must show waybills for the current delivery truck and then show the destinations
        //Group the packing list based on the destination, infuture this will allow us to create a route for delivery

    }
    public static function handle_get_deliveries_by_country()
    {
        if (! isset($_POST['nonce']) || ! (wp_verify_nonce($_POST['nonce'], 'deliveries_nonce') || wp_verify_nonce($_POST['nonce'], 'get_waybills_nonce'))) {
            wp_send_json_error('Invalid nonce', 403);
        }

        $country_code = sanitize_text_field($_POST['country']);

        if (empty($country_code)) {
            wp_send_json_error(['message' => 'Country code is required']);
        }

        $deliveries = KIT_Deliveries::getScheduledDeliveries($country_code);

        ob_start();
        foreach ($deliveries as $delivery): ?>
            <?php
            renderDeliveryCard(
                $delivery,
                'scheduled',
                true,
                'handleDeliveryClick',
                [
                    'type'       => 'radio',
                    'name'       => 'delivery_id',
                    'checked_id' => 1,
                ]
            );
            ?>
        <?php endforeach;
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }
    public static function get_deliveries_by_country_id()
    {
        if (! isset($_POST['nonce']) || ! (wp_verify_nonce($_POST['nonce'], 'deliveries_nonce') || wp_verify_nonce($_POST['nonce'], 'get_waybills_nonce'))) {
            wp_send_json_error('Invalid nonce', 403);
        }

        $country_id = intval($_POST['country']);

        if (empty($country_id)) {
            wp_send_json_error(['message' => 'Country ID is required']);
        }

        // Validate country ID exists
        global $wpdb;
        $country_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}kit_operating_countries WHERE id = %d",
            $country_id
        ));

        if (! $country_exists) {
            wp_send_json_error(['message' => 'Country not found']);
        }

        $deliveries = self::getScheduledCountryDeliveries($country_id);

        ob_start();
        foreach ($deliveries as $delivery) {
            echo self::render_scheduled_delivery_card($delivery, [
                'input_type' => 'radio',
                'input_name' => 'delivery_id',
                'checked_id' => 1,
            ]);
        }
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Render scheduled delivery card for AJAX responses
     * Uses our reusable deliveryCard component
     */
    public static function render_scheduled_delivery_card($delivery, $options = [])
    {

        // Convert delivery data to match our component format
        $component_delivery = (object) [
            'direction_id'        => $delivery->delivery_id,
            'id'                  => $delivery->delivery_id,
            'origin_country'      => $delivery->origin_country, // Use country names directly
            'destination_country' => $delivery->destination_country,
            'dispatch_date'       => $delivery->dispatch_date,
            'truck_number'        => $delivery->truck_number ?? '',
            'description'         => $delivery->description ?? '',
        ];

        // Reduce noisy logs: keep a single concise line (id + ref) during development
        // error_log('Delivery loaded: id=' . ($delivery->delivery_id ?? 'n/a') . ' ref=' . ($delivery->delivery_reference ?? '')); // Uncomment if needed for debugging

        // Use our reusable component with radio button options
        $radio_options = [
            'type'       => $options['input_type'] ?? 'radio',
            'name'       => $options['input_name'] ?? 'delivery_id',
            'checked_id' => $options['checked_id'] ?? null,
        ];

        ob_start();
        renderDeliveryCard($component_delivery, 'scheduled', true, 'handleDeliveryClick', $radio_options);

        return ob_get_clean();
    }

    // Wrapper to satisfy registered AJAX hook name
    public static function handle_get_deliveries_by_country_id()
    {
        // Reuse core implementation
        self::get_deliveries_by_country_id();
    }
    public static function getScheduledCountryDeliveries($country_id)
    {
        global $wpdb;

        $deliveryTable      = $wpdb->prefix . 'kit_deliveries';
        $shipDirectionTable = $wpdb->prefix . 'kit_shipping_directions';

        // Validate country ID and ensure it's active
        $destinationCountry_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}kit_operating_countries WHERE id = %d AND is_active = 1",
            $country_id
        ));

        if (! $destinationCountry_id) {
            return false;
        }

        $query = "
        SELECT 
            d.id as delivery_id, 
            d.delivery_reference, 
            d.direction_id, 
            d.dispatch_date, 
            d.truck_number, 
            d.status, 
            sd.description, 

            -- Origin
            oc1.country_name AS origin_country, 
            oc1.country_code AS origin_code,

            -- Destination
            oc2.country_name AS destination_country, 
            oc2.country_code AS destination_code

        FROM $deliveryTable d

        LEFT JOIN $shipDirectionTable sd ON d.direction_id = sd.id 

        LEFT JOIN {$wpdb->prefix}kit_operating_countries oc1 ON sd.origin_country_id = oc1.id 
        LEFT JOIN {$wpdb->prefix}kit_operating_countries oc2 ON sd.destination_country_id = oc2.id 

        WHERE sd.destination_country_id = %d AND d.status = 'scheduled'
    ";

        $sql = $wpdb->prepare($query, $destinationCountry_id);

        return $wpdb->get_results($sql);
    }

    public static function tailSelect($atts)
    {
        // Properly output the HTML using PHP, not short tags inside a string
        ob_start();

        $delivery_id = isset($atts['delivery_id']) ? esc_attr($atts['delivery_id']) : '';
        $status      = isset($atts['status']) ? $atts['status'] : '';
        ?>
        <div class="relative">
            <?php
            // Ensure status is not null before using string functions
            $status       = $status ?? '';
            $status_label = ucfirst(str_replace('_', ' ', (string) $status));

            echo KIT_Commons::renderButton(
                $status_label,
                'secondary',
                'lg',
                [
                    'type'        => 'button',
                    'id'          => 'delivery-status-button-' . $delivery_id,
                    'onclick'     => sprintf("toggleDropdownDeliveryStatus('%s')", esc_js($delivery_id)),
                    'fullWidth'   => true,
                    'classes'     => 'justify-between text-left',
                    'iconPosition' => 'right',
                    'icon'        => '<path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />',
                ]
            );
            ?>

            <div id="delivery-status-dropdown-<?php echo $delivery_id; ?>"
                class="hidden absolute right-0 mt-2 w-56 bg-white shadow-lg rounded-md z-10">
                <div class="py-1">
                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Scheduled</a>
                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">In Transit</a>
                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Delivered</a>
                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Cancelled</a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function deliveryStatus($atts)
    {
        if ($atts['insideForm'] == 'false') {
            echo "adffda";
            //just get the delivery status from the database
            global $wpdb;
            $table_name = $wpdb->prefix . 'kit_deliveries';
            $status     = $wpdb->get_var($wpdb->prepare("SELECT status FROM $table_name WHERE id = %d", $atts['delivery_id']));

            //just get the delivery status from the database
            $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $table_name WHERE id = %d", $atts['delivery_id']));

            ob_start();
        ?>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="delivery-status-form">
                <input type="hidden" name="action" value="update_delivery_status">
                <input type="hidden" name="delivery_id" value="<?php echo esc_attr($atts['delivery_id'] ?? '') ?>">
                <?php wp_nonce_field('update_delivery_status_nonce'); ?>
                <div class="relative inline-block text-left">
                    <?php echo self::tailSelect($atts) ?>
                </div>
            </form>

        <?php
        } else {
            return self::tailSelect($atts);
        }
    }

    public static function getScheduledDeliveries($country_code = '')
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_deliveries';

        $query = "
                SELECT 
                    $table_name.id,
                    $table_name.delivery_reference, 
                    $table_name.direction_id, 
                    $table_name.destination_city_id,
                    $table_name.dispatch_date, 
                    $table_name.truck_number, 
                    $table_name.driver_id,
                    $table_name.status, 
                    sd.description,
                    sd.destination_country_id,
                    sd.origin_country_id,
                    oc1.country_name AS origin_country, 
                    oc1.country_code AS origin_code,
                    oc2.country_name AS destination_country, 
                    oc2.country_code AS destination_code,
                    d.name AS driver_name,
                    d.phone AS driver_phone,
                    d.email AS driver_email,
                    d.license_number AS driver_license

                FROM $table_name 

                LEFT JOIN {$wpdb->prefix}kit_shipping_directions sd 
                    ON $table_name.direction_id = sd.id 

                LEFT JOIN {$wpdb->prefix}kit_operating_countries oc1 
                    ON sd.origin_country_id = oc1.id 

                LEFT JOIN {$wpdb->prefix}kit_operating_countries oc2 
                    ON sd.destination_country_id = oc2.id 

                LEFT JOIN {$wpdb->prefix}kit_drivers d
                    ON $table_name.driver_id = d.id
                WHERE $table_name.status = 'scheduled'
                AND  $table_name.delivery_reference != 'pending'
                AND oc2.is_active = 1";

        if (! empty($country_code)) {
            // Filter by the joined destination country name column
            $query .= $wpdb->prepare(" AND oc2.country_name = %s", $country_code);
        }

        $query .= " ORDER BY dispatch_date ASC";
        return $wpdb->get_results($query);
    }

    /**
     * Filter deliveries by type: all, scheduled (future), or past
     * 
     * @param string $filter_type Filter type: 'all', 'scheduled', or 'past'. Default: 'scheduled'
     * @param string $country_code Optional country code to filter by
     * @return array Array of delivery objects
     */
    public static function filterDeliveries($filter_type = 'scheduled', $country_code = '')
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_deliveries';
        $drivers_table = $wpdb->prefix . 'kit_drivers';

        $drivers_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$drivers_table'");
        $driver_id_exists = false;
        if ($drivers_table_exists) {
            $driver_id_exists = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'driver_id'",
                DB_NAME,
                $table_name
            ));
        }

        $driver_select = '';
        $driver_join = '';
        if ($drivers_table_exists && $driver_id_exists) {
            $driver_select = ",
                    $table_name.driver_id,
                    dr.name AS driver_name,
                    dr.phone AS driver_phone,
                    dr.email AS driver_email,
                    dr.license_number AS driver_license";
            $driver_join = "
                LEFT JOIN {$drivers_table} dr
                    ON $table_name.driver_id = dr.id";
        }

        $query = "
                SELECT 
                    $table_name.id,
                    $table_name.delivery_reference, 
                    $table_name.direction_id, 
                    $table_name.destination_city_id,
                    $table_name.dispatch_date, 
                    $table_name.truck_number, 
                    $table_name.status, 
                    sd.description,
                    sd.destination_country_id,
                    sd.origin_country_id,
                    oc1.country_name AS origin_country, 
                    oc1.country_code AS origin_code,
                    oc2.country_name AS destination_country, 
                    oc2.country_code AS destination_code
                    $driver_select

                FROM $table_name 

                LEFT JOIN {$wpdb->prefix}kit_shipping_directions sd 
                    ON $table_name.direction_id = sd.id 

                LEFT JOIN {$wpdb->prefix}kit_operating_countries oc1 
                    ON sd.origin_country_id = oc1.id 

                LEFT JOIN {$wpdb->prefix}kit_operating_countries oc2 
                    ON sd.destination_country_id = oc2.id 
                $driver_join
                WHERE $table_name.delivery_reference != 'pending'
                AND oc2.is_active = 1";

        // Apply filter based on type
        switch ($filter_type) {
            case 'scheduled':
                // Scheduled deliveries: dispatch_date > NOW() (future deliveries, regardless of status)
                $query .= " AND $table_name.dispatch_date > NOW()";
                break;
            case 'past':
                // Past deliveries: dispatch_date < NOW()
                $query .= " AND $table_name.dispatch_date < NOW()";
                break;
            case 'all':
            default:
                // All deliveries - no date filter
                break;
        }

        if (!empty($country_code)) {
            // Filter by the joined destination country name column
            $query .= $wpdb->prepare(" AND oc2.country_name = %s", $country_code);
        }

        $query .= " ORDER BY dispatch_date ASC";
        $deliveries = $wpdb->get_results($query);

        if ($deliveries === null) {
            error_log('filterDeliveries SQL error: ' . $wpdb->last_error);
            return [];
        }

        return $deliveries;
    }

    /**
     * AJAX handler for filtering deliveries
     */
    public static function ajax_filter_deliveries()
    {
        // Verify nonce if needed (optional for public endpoints)
        if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'filter_deliveries_nonce')) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        $filter_type = isset($_POST['filter_type']) ? sanitize_text_field($_POST['filter_type']) : 'scheduled';
        $country_code = isset($_POST['country_code']) ? sanitize_text_field($_POST['country_code']) : '';

        // Ensure delivery card component is loaded
        $delivery_card_path = plugin_dir_path(__FILE__) . '../components/deliveryCard.php';
        if (file_exists($delivery_card_path) && !function_exists('renderDeliveryCard')) {
            require_once $delivery_card_path;
        }

        // Get filtered deliveries
        $deliveries = self::filterDeliveries($filter_type, $country_code);
        if (!is_array($deliveries)) {
            $deliveries = [];
        }

        // Render delivery cards HTML
        ob_start();
        if (!empty($deliveries)) {
            foreach ($deliveries as $delivery) {
                if (function_exists('renderDeliveryCard')) {
                    renderDeliveryCard($delivery, 'scheduled', true, 'handleDeliveryClick');
                } else {
                    // Fallback if function doesn't exist
                    echo '<div class="delivery-card p-4 border border-gray-200 rounded-lg">';
                    echo '<div class="font-medium">' . esc_html($delivery->delivery_reference ?? 'N/A') . '</div>';
                    echo '<div class="text-sm text-gray-600">' . esc_html($delivery->origin_country ?? '') . ' → ' . esc_html($delivery->destination_country ?? '') . '</div>';
                    echo '</div>';
                }
            }
        }
        $html = ob_get_clean();

        // Send response
        wp_send_json_success([
            'html' => $html,
            'count' => count($deliveries),
            'filter_type' => $filter_type
        ]);
    }

    /**
     * Schedule daily task to update past deliveries to "Unconfirmed" status
     * Runs at midnight (00:00) every day
     * 
     * NOTE: WordPress cron is "pseudo-cron" - it only runs when someone visits your site.
     * For true midnight execution, set up a real server cron job (see function documentation below).
     */
    public static function schedule_daily_delivery_status_update()
    {
        // Check if the event is already scheduled
        if (!wp_next_scheduled('kit_daily_update_past_deliveries')) {
            // Schedule the event to run daily at midnight (00:00)
            // WordPress cron uses server time, so we schedule it for 00:00
            $timestamp = strtotime('tomorrow midnight');
            wp_schedule_event($timestamp, 'daily', 'kit_daily_update_past_deliveries');

            error_log('KIT Deliveries: Scheduled daily delivery status update cron job');
        }
    }

    /**
     * Update past deliveries to "Unconfirmed" status
     * This function runs automatically via WordPress cron at midnight daily
     * 
     * IMPORTANT: WordPress cron is "pseudo-cron" - it only runs when someone visits your site.
     * If you need it to run exactly at midnight regardless of site traffic, set up a real cron job:
     * 
     * Add this to your server's crontab (crontab -e):
     * 0 0 * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
     * 
     * OR use wget:
     * 0 0 * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
     * 
     * This will trigger WordPress cron exactly at midnight every day.
     */
    public static function update_past_deliveries_to_unconfirmed()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_deliveries';

        // Update all deliveries where dispatch_date < NOW() and status is 'scheduled'
        // to status 'unconfirmed'. Some older installs may not have a last_updated_at column,
        // so we ONLY touch status here to avoid SQL errors.
        $result = $wpdb->query(
            "UPDATE $table_name 
             SET status = 'unconfirmed'
             WHERE dispatch_date < NOW() 
             AND status = 'scheduled'"
        );

        // Log the update (optional, for debugging)
        if ($result !== false) {
            error_log("KIT Deliveries: Updated $result past deliveries to 'unconfirmed' status at " . date('Y-m-d H:i:s'));
        } else {
            error_log("KIT Deliveries: Error updating past deliveries - " . $wpdb->last_error);
        }

        return $result;
    }

    /**
     * Manually trigger the update (for testing or manual execution)
     * You can call this via: KIT_Deliveries::update_past_deliveries_to_unconfirmed()
     * 
     * Example usage in code:
     * KIT_Deliveries::update_past_deliveries_to_unconfirmed();
     */
    public static function getCityData($city_id)
    {
        global $wpdb;

        $cities_table = $wpdb->prefix . 'kit_operating_cities';
        $query        = $wpdb->prepare(
            "SELECT id, city_name FROM $cities_table WHERE id = %d",
            $city_id
        );
        return $wpdb->get_row($query, OBJECT);
    }

    //Create function that will chnage delivery but looking at todays date, if the delivery date is in the past, it will change the delivery status to unconfirmed, since no one updated it.

    /**
     * Auto-update deliveries to 'unconfirmed' if dispatch_date is in the past
     * Only updates deliveries that are currently 'scheduled'
     * 
     * @return int|false Number of deliveries updated, or false on error
     */
    public static function auto_update_past_deliveries()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'kit_deliveries';

        // Get today's date
        $today = date('Y-m-d');

        // Update all scheduled deliveries with past dispatch dates to unconfirmed
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $table 
            SET status = 'unconfirmed' 
            WHERE status = 'scheduled' 
            AND dispatch_date < %s 
            AND dispatch_date IS NOT NULL",
            $today
        ));

        if ($result === false) {
            error_log('Failed to auto-update past deliveries: ' . $wpdb->last_error);
            return false;
        }

        // Log how many were updated
        if ($result > 0) {
            error_log("Auto-updated {$result} past deliveries to unconfirmed status");
        }

        return $result;
    }

    // Removed legacy endpoint that read destination city from misc->others
    public static function view_deliveries_page()
    {
        // Auto-update past deliveries to unconfirmed before loading the page
        self::auto_update_past_deliveries();

        // Enqueue necessary CSS for styling - using plugin's standard approach
        wp_enqueue_style('autsincss', plugin_dir_url(__FILE__) . '../../assets/css/austin.css', [], '1.0');
        wp_enqueue_style('kit-tailwindcss', plugin_dir_url(__FILE__) . '../../assets/css/frontend.css', [], '1.0');

        // Add CSS class wrapper to admin body for scoping
        add_filter('admin_body_class', function ($classes) {
            return $classes . ' courier-finance-plugin';
        });

        // Enqueue necessary JavaScript
        wp_enqueue_script('jquery');
        wp_enqueue_script('kitscript', plugin_dir_url(__FILE__) . '../../js/kitscript.js', ['jquery'], '1.0.0', true);
        wp_enqueue_script('components', plugin_dir_url(__FILE__) . '../../js/components.js', ['jquery'], '1.0.0', true);

        // Handle success/error messages from form submissions
        if (!class_exists('KIT_Toast')) {
            require_once plugin_dir_path(__FILE__) . '../components/toast.php';
        }
        KIT_Toast::ensure_toast_loads();

        if (isset($_GET['delivery_success']) && $_GET['delivery_success'] === '1') {
            $message = isset($_GET['message']) ? urldecode($_GET['message']) : 'Operation completed successfully!';
            echo KIT_Toast::success($message, 'Success');
        }

        if (isset($_GET['delivery_error'])) {
            $error_message = urldecode($_GET['delivery_error']);
            echo KIT_Toast::error($error_message, 'Error');
        }

        if (! isset($_GET['delivery_id']) || ! is_numeric($_GET['delivery_id'])) {
            echo KIT_Toast::error('Invalid delivery ID.', 'Error');
            return;
        }

        $delivery_id = intval($_GET['delivery_id']);
        $delivery    = self::get_delivery($delivery_id);

        if (! $delivery) {
            echo KIT_Toast::error('Delivery not found.', 'Error');
            return;
        }

        // Waybills will be fetched using KIT_Waybills::truckWaybills() method below
        $customers         = tholaMaCustomer();
        $customers_encoded = base64_encode(json_encode($customers));
        $form_action       = admin_url('admin-post.php?action=add_waybill_action');

        // Include modal component
        require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/components/modal.php';

        // Include waybill functions to access KIT_Waybills class
        require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/waybill/waybill-functions.php';

        // Get waybills for this delivery early so we can use the data
        $waybillsandItems = KIT_Waybills::truckWaybills($delivery_id);

        if (is_array($waybillsandItems) && !empty($waybillsandItems)) {
            foreach ($waybillsandItems as &$waybillRow) {
                $cityId = isset($waybillRow['city_id']) ? (int) $waybillRow['city_id'] : 0;
                $cityName = '';

                if ($cityId > 0 && class_exists('KIT_Routes')) {
                    $cityName = (string) KIT_Routes::get_city_name_by_id($cityId);
                }

                if ($cityName === '') {
                    $cityName = 'Unassigned City';
                }

                $waybillRow['city'] = $cityName;

                $firstName = isset($waybillRow['customer_name']) ? trim((string) $waybillRow['customer_name']) : '';
                $surname = isset($waybillRow['customer_surname']) ? trim((string) $waybillRow['customer_surname']) : '';
                $fullName = trim($firstName . ' ' . $surname);

                if ($fullName !== '') {
                    $waybillRow['customer_name'] = $fullName;
                } elseif ($firstName !== '') {
                    $waybillRow['customer_name'] = $firstName;
                } elseif ($surname !== '') {
                    $waybillRow['customer_name'] = $surname;
                } else {
                    $waybillRow['customer_name'] = 'Unknown Customer';
                }
            }
            unset($waybillRow);

            usort($waybillsandItems, function ($a, $b) {
                $cityA = strtolower((string) ($a['city'] ?? ''));
                $cityB = strtolower((string) ($b['city'] ?? ''));

                $cityCompare = strcmp($cityA, $cityB);
                if ($cityCompare !== 0) {
                    return $cityCompare;
                }

                $nameA = strtolower((string) ($a['customer_name'] ?? ''));
                $nameB = strtolower((string) ($b['customer_name'] ?? ''));

                return strcmp($nameA, $nameB);
            });
        }

        ?>

        <div class="wrap deliveries-page">
            <div class="<?php echo KIT_Commons::containerClasses(); ?>">
                <?php
                // Initialize variables for modal if not already set
                if (!isset($form_action)) {
                    $form_action = admin_url('admin-post.php?action=add_waybill_action');
                }
                if (!isset($customers_encoded)) {
                    $customers = tholaMaCustomer();
                    $customers_encoded = base64_encode(json_encode($customers));
                }
                if (!isset($delivery_id)) {
                    $delivery_id = intval($_GET['delivery_id'] ?? 0);
                }

                echo KIT_Commons::showingHeader([
                    'title'   => 'Delivery Details',
                    'icon'    => KIT_Commons::icon('receipt'),
                    'content' => KIT_Modal::render(
                        'create-waybill-modal',
                        'Create New Waybill',
                        '<!-- DEBUG: Modal content start -->' . kit_render_waybill_multiform([
                            'form_action'          => $form_action,
                            'waybill_id'           => '',
                            'is_edit_mode'         => '0',
                            'waybill'              => '{}',
                            'customer_id'          => '0',
                            'delivery_id'          => $delivery_id,
                            'is_existing_customer' => '0',
                            'customer'             => $customers_encoded,
                        ]) . '<!-- DEBUG: Modal content end -->',
                        '6xl'
                    ),
                ]);
                ?>

                <script>
                    jQuery(document).ready(function($) {
                        const deliveryId = <?php echo intval($delivery_id); ?>;
                        const deliveryData = <?php echo json_encode([
                                                    'id' => $delivery->id ?? 0,
                                                    'direction_id' => $delivery->direction_id ?? 0,
                                                    'destination_country_id' => $delivery->destination_country_id ?? 0,
                                                    'destination_city_id' => $delivery->destination_city_id ?? 0,
                                                    'destination_country' => isset($delivery->destination_country) ? $delivery->destination_country : ''
                                                ]); ?>;

                        // Verification function to ensure all required fields are set
                        function verifyFieldsSet() {
                            const directionIdField = document.getElementById('direction_id');
                            const selectedDeliveryId = document.getElementById('selected_delivery_id');
                            const destinationCountrySelect = document.getElementById('stepDestinationSelect') ||
                                document.querySelector('select[name="destination_country"]');
                            const destinationCountryBackup = document.getElementById('destination_country_backup');
                            const destinationCity = document.getElementById('destination_city');

                            const directionId = directionIdField ? directionIdField.value.trim() : '';
                            const deliveryId = selectedDeliveryId ? selectedDeliveryId.value.trim() : '';
                            const countryId = destinationCountrySelect ? destinationCountrySelect.value :
                                (destinationCountryBackup ? destinationCountryBackup.value : '');
                            const cityId = destinationCity ? destinationCity.value.trim() : '';

                            const allFieldsSet = directionId && deliveryId && countryId && cityId;

                            console.log('🔍 Field verification:', {
                                directionId: directionId || 'MISSING',
                                deliveryId: deliveryId || 'MISSING',
                                countryId: countryId || 'MISSING',
                                cityId: cityId || 'MISSING',
                                allFieldsSet: allFieldsSet ? '✅' : '❌'
                            });

                            if (!allFieldsSet) {
                                // Try to fix missing fields
                                if (!countryId && deliveryData.destination_country_id) {
                                    if (destinationCountrySelect) {
                                        destinationCountrySelect.value = deliveryData.destination_country_id;
                                        const changeEvent = new Event('change', {
                                            bubbles: true
                                        });
                                        destinationCountrySelect.dispatchEvent(changeEvent);
                                        console.log('🔧 Fixed: Set destination country');
                                    }
                                    if (destinationCountryBackup) {
                                        destinationCountryBackup.value = deliveryData.destination_country_id;
                                        console.log('🔧 Fixed: Set destination_country_backup');
                                    }
                                }

                                if (!cityId && deliveryData.destination_city_id) {
                                    if (destinationCity) {
                                        // Check if cities are loaded
                                        if (destinationCity.options.length > 1) {
                                            destinationCity.value = deliveryData.destination_city_id;
                                            const cityChangeEvent = new Event('change', {
                                                bubbles: true
                                            });
                                            destinationCity.dispatchEvent(cityChangeEvent);
                                            console.log('🔧 Fixed: Set destination city');
                                        } else {
                                            // Cities not loaded yet, retry verification
                                            setTimeout(verifyFieldsSet, 500);
                                            return;
                                        }
                                    }
                                }

                                if (!directionId && deliveryData.direction_id) {
                                    if (directionIdField) {
                                        directionIdField.value = deliveryData.direction_id;
                                        console.log('🔧 Fixed: Set direction_id');
                                    }
                                }

                                if (!deliveryId && deliveryData.id) {
                                    if (selectedDeliveryId) {
                                        selectedDeliveryId.value = deliveryData.id;
                                        console.log('🔧 Fixed: Set selected_delivery_id to:', deliveryData.id);
                                    }
                                }

                                // Re-verify after fixes
                                setTimeout(verifyFieldsSet, 500);
                            } else {
                                console.log('✅ All required fields verified and set!');
                            }
                        }

                        // Listen for modal opening - prepare step 4 in background but keep step 1 visible
                        function prepareStep4AndDelivery() {
                            // CRITICAL: Ensure step 1 is active and visible
                            const step1 = document.getElementById('step-1');
                            const step4 = document.getElementById('step-4');

                            if (step1 && step4) {
                                // Force step 1 to be active and visible
                                step1.classList.remove('hidden');
                                step1.classList.add('active');
                                step4.classList.remove('active');
                                step4.classList.add('hidden');
                                console.log('✅ Ensured step-1 is active, step-4 is hidden');
                            }

                            // Wait for form to be fully rendered
                            setTimeout(function() {
                                if (!step4) {
                                    console.warn('⚠️ Could not find step-4 element, retrying...');
                                    setTimeout(prepareStep4AndDelivery, 200);
                                    return;
                                }

                                // Double-check step 1 is still active
                                if (step1) {
                                    step1.classList.remove('hidden');
                                    step1.classList.add('active');
                                }
                                if (step4) {
                                    step4.classList.remove('active');
                                    step4.classList.add('hidden');
                                }

                                console.log('✅ Preparing step-4 in background (user still sees step-1)');

                                // Function to find and select delivery card with retries
                                function findAndSelectDeliveryCard(retryCount = 0) {
                                    const maxRetries = 10;
                                    const retryDelay = 200;

                                    // Wait for scheduled deliveries to be initialized
                                    if (typeof initializeScheduledDeliveries === 'function' && !window.scheduledDeliveriesInitialized) {
                                        if (retryCount < maxRetries) {
                                            setTimeout(function() {
                                                findAndSelectDeliveryCard(retryCount + 1);
                                            }, retryDelay);
                                            return;
                                        }
                                    }

                                    // Try multiple selectors to find the delivery card
                                    let deliveryCard = document.querySelector('.delivery-card[data-delivery-id="' + deliveryId + '"]');

                                    if (!deliveryCard && deliveryData.direction_id) {
                                        deliveryCard = document.querySelector('.delivery-card[data-direction-id="' + deliveryData.direction_id + '"]');
                                    }

                                    if (!deliveryCard && deliveryData.id) {
                                        deliveryCard = document.querySelector('.delivery-card[data-index="' + deliveryData.id + '"]');
                                    }

                                    // Also try finding by matching the delivery reference or other attributes
                                    if (!deliveryCard) {
                                        const allCards = document.querySelectorAll('.delivery-card');
                                        allCards.forEach(function(card) {
                                            const cardDeliveryId = card.getAttribute('data-delivery-id');
                                            const cardDirectionId = card.getAttribute('data-direction-id');
                                            const cardIndex = card.getAttribute('data-index');

                                            if (cardDeliveryId == deliveryId ||
                                                cardDirectionId == deliveryData.direction_id ||
                                                cardIndex == deliveryId ||
                                                cardIndex == deliveryData.id) {
                                                deliveryCard = card;
                                            }
                                        });
                                    }

                                    if (deliveryCard) {
                                        console.log('✅ Found delivery card for delivery_id:', deliveryId);

                                        const directionId = deliveryCard.getAttribute('data-direction-id') ||
                                            deliveryCard.getAttribute('data-index') ||
                                            deliveryData.direction_id;

                                        // Get city ID from card
                                        const destinationCityId = deliveryCard.getAttribute('data-destination-city-id') ||
                                            deliveryData.destination_city_id;

                                        // CRITICAL: Set hidden fields FIRST before selecting card
                                        const selectedDeliveryId = document.getElementById('selected_delivery_id');
                                        const directionIdField = document.getElementById('direction_id');

                                        if (selectedDeliveryId) {
                                            selectedDeliveryId.value = deliveryId;
                                            console.log('✅ Set selected_delivery_id to:', deliveryId);
                                        }
                                        if (directionIdField && directionId) {
                                            directionIdField.value = directionId;
                                            console.log('✅ Set direction_id to:', directionId);
                                        }

                                        // Get and set destination country
                                        const destinationCountryId = deliveryCard.getAttribute('data-destination-country-id') ||
                                            deliveryData.destination_country_id;
                                        if (destinationCountryId) {
                                            const destinationCountrySelect = document.getElementById('stepDestinationSelect') ||
                                                document.querySelector('select[name="destination_country"]');
                                            const destinationCountryBackup = document.getElementById('destination_country_backup');

                                            if (destinationCountrySelect) {
                                                destinationCountrySelect.value = destinationCountryId;

                                                // Trigger change event to load cities
                                                const changeEvent = new Event('change', {
                                                    bubbles: true
                                                });
                                                destinationCountrySelect.dispatchEvent(changeEvent);

                                                // Also try loadDestinationCities if available
                                                if (typeof window.loadDestinationCities === 'function') {
                                                    window.loadDestinationCities(destinationCountryId);
                                                }

                                                console.log('✅ Set stepDestinationSelect to:', destinationCountryId, 'and triggered city loading');
                                            }
                                            if (destinationCountryBackup) {
                                                destinationCountryBackup.value = destinationCountryId;
                                                console.log('✅ Set destination_country_backup to:', destinationCountryId);
                                            }
                                        }

                                        // Visually select the card (add selected class)
                                        deliveryCard.classList.add('selected');
                                        console.log('✅ Added selected class to delivery card');

                                        // Use selectDeliveryCard if available (preferred method) - don't skip details to ensure city loads
                                        if (typeof selectDeliveryCard === 'function') {
                                            selectDeliveryCard(deliveryCard, directionId, false); // false = show details and load city
                                        } else if (typeof window.selectDeliveryCard === 'function') {
                                            window.selectDeliveryCard(deliveryCard, directionId, false);
                                        } else if (typeof handleDeliveryClick === 'function') {
                                            handleDeliveryClick(deliveryCard, directionId, false);
                                        } else if (typeof window.handleDeliveryClick === 'function') {
                                            window.handleDeliveryClick(deliveryCard, directionId, false);
                                        } else {
                                            // Fallback: manually click the card
                                            deliveryCard.click();
                                        }

                                        console.log('✅ Auto-selected delivery card');

                                        // Ensure city is selected after cities are loaded
                                        if (destinationCityId) {
                                            // First, ensure cities are loaded using the global loadDestinationCities function
                                            const countryId = deliveryCard.getAttribute('data-destination-country-id') || deliveryData.destination_country_id;

                                            // Use loadDestinationCities if available (from waybillmultiform.php)
                                            if (typeof window.loadDestinationCities === 'function') {
                                                console.log('🔄 Loading cities using loadDestinationCities function');
                                                window.loadDestinationCities(countryId, destinationCityId);
                                            } else {
                                                // Fallback: trigger country change to load cities
                                                if (destinationCountrySelect) {
                                                    const changeEvent = new Event('change', {
                                                        bubbles: true
                                                    });
                                                    destinationCountrySelect.dispatchEvent(changeEvent);
                                                }
                                            }

                                            function setCityValue(cityId, retryCount = 0) {
                                                const maxRetries = 20; // Increased retries
                                                const citySelect = document.getElementById('destination_city');

                                                if (!citySelect) {
                                                    if (retryCount < maxRetries) {
                                                        setTimeout(() => setCityValue(cityId, retryCount + 1), 200);
                                                    } else {
                                                        console.warn('⚠️ destination_city select not found after retries');
                                                    }
                                                    return;
                                                }

                                                // Check if cities are loaded (more than just "Select City" option)
                                                if (citySelect.options.length > 1) {
                                                    // Find the option with matching value
                                                    const optionExists = Array.from(citySelect.options).some(opt => String(opt.value) === String(cityId));

                                                    if (optionExists) {
                                                        citySelect.value = cityId;
                                                        const cityChangeEvent = new Event('change', {
                                                            bubbles: true
                                                        });
                                                        citySelect.dispatchEvent(cityChangeEvent);

                                                        // Verify the value was actually set
                                                        if (citySelect.value === String(cityId)) {
                                                            console.log('✅ Set destination city to:', cityId, '(verified)');
                                                            // Trigger final verification
                                                            setTimeout(function() {
                                                                verifyFieldsSet();
                                                            }, 300);
                                                            return;
                                                        } else {
                                                            console.warn('⚠️ City value not set correctly, retrying...');
                                                            if (retryCount < maxRetries) {
                                                                setTimeout(() => setCityValue(cityId, retryCount + 1), 200);
                                                            }
                                                        }
                                                    } else {
                                                        // Option doesn't exist yet, retry
                                                        if (retryCount < maxRetries) {
                                                            setTimeout(() => setCityValue(cityId, retryCount + 1), 200);
                                                        } else {
                                                            console.warn('⚠️ City option not found after retries, trying direct population');
                                                            // Last resort: try to populate from preloaded map
                                                            const citiesMap = (window.myPluginAjax && window.myPluginAjax.countryCities) || {};
                                                            const cities = citiesMap && citiesMap[String(countryId)] ? citiesMap[String(countryId)] : [];

                                                            if (Array.isArray(cities) && cities.length) {
                                                                citySelect.innerHTML = '<option value="">Select City</option>';
                                                                cities.forEach(function(city) {
                                                                    const option = document.createElement('option');
                                                                    option.value = city.id;
                                                                    option.textContent = city.city_name;
                                                                    if (String(city.id) === String(cityId)) {
                                                                        option.selected = true;
                                                                    }
                                                                    citySelect.appendChild(option);
                                                                });
                                                                citySelect.value = cityId;
                                                                const cityChangeEvent = new Event('change', {
                                                                    bubbles: true
                                                                });
                                                                citySelect.dispatchEvent(cityChangeEvent);

                                                                // Verify the value was actually set
                                                                if (citySelect.value === String(cityId)) {
                                                                    console.log('✅ Set destination city (direct population):', cityId, '(verified)');
                                                                    setTimeout(function() {
                                                                        verifyFieldsSet();
                                                                    }, 300);
                                                                } else {
                                                                    console.warn('⚠️ City value not set correctly after direct population');
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    // Cities not loaded yet, retry
                                                    if (retryCount < maxRetries) {
                                                        setTimeout(() => setCityValue(cityId, retryCount + 1), 200);
                                                    } else {
                                                        console.warn('⚠️ Cities still not loaded after retries');
                                                    }
                                                }
                                            }

                                            // Start trying to set city after a delay (increased delay to allow cities to load)
                                            setTimeout(() => setCityValue(destinationCityId, 0), 800);

                                            // Verify all fields are set after city is set (with longer timeout)
                                            setTimeout(function() {
                                                verifyFieldsSet();
                                            }, 3000);
                                        } else {
                                            // No city ID, but still verify other fields
                                            setTimeout(function() {
                                                verifyFieldsSet();
                                            }, 1000);
                                        }
                                    } else if (retryCount < maxRetries) {
                                        // Retry if card not found yet
                                        setTimeout(function() {
                                            findAndSelectDeliveryCard(retryCount + 1);
                                        }, retryDelay);
                                    } else {
                                        // Final fallback: populate fields directly from delivery data
                                        console.warn('⚠️ Could not find delivery card after retries, populating fields directly');

                                        if (deliveryData.destination_country_id) {
                                            const destinationCountrySelect = document.getElementById('stepDestinationSelect') ||
                                                document.querySelector('select[name="destination_country"]');
                                            const destinationCountryBackup = document.getElementById('destination_country_backup');

                                            if (destinationCountrySelect) {
                                                destinationCountrySelect.value = deliveryData.destination_country_id;

                                                // Trigger change event to load cities
                                                const changeEvent = new Event('change', {
                                                    bubbles: true
                                                });
                                                destinationCountrySelect.dispatchEvent(changeEvent);

                                                // Also try handleCountryChange if available
                                                if (typeof handleCountryChange === 'function') {
                                                    handleCountryChange(deliveryData.destination_country_id, 'destination');
                                                }

                                                console.log('✅ Set destination country to:', deliveryData.destination_country_id);
                                            }

                                            if (destinationCountryBackup) {
                                                destinationCountryBackup.value = deliveryData.destination_country_id;
                                                console.log('✅ Set destination_country_backup to:', deliveryData.destination_country_id);
                                            }

                                            // Set city after cities are loaded with retry logic
                                            if (deliveryData.destination_city_id) {
                                                // Use loadDestinationCities if available
                                                if (typeof window.loadDestinationCities === 'function') {
                                                    console.log('🔄 Loading cities using loadDestinationCities function (fallback)');
                                                    window.loadDestinationCities(deliveryData.destination_country_id, deliveryData.destination_city_id);
                                                }

                                                function setCityValueFallback(cityId, retryCount = 0) {
                                                    const maxRetries = 20; // Increased retries
                                                    const citySelect = document.getElementById('destination_city');

                                                    if (!citySelect) {
                                                        if (retryCount < maxRetries) {
                                                            setTimeout(() => setCityValueFallback(cityId, retryCount + 1), 200);
                                                        }
                                                        return;
                                                    }

                                                    if (citySelect.options.length > 1) {
                                                        // Check if option exists
                                                        const optionExists = Array.from(citySelect.options).some(opt => String(opt.value) === String(cityId));

                                                        if (optionExists) {
                                                            citySelect.value = cityId;
                                                            const cityChangeEvent = new Event('change', {
                                                                bubbles: true
                                                            });
                                                            citySelect.dispatchEvent(cityChangeEvent);

                                                            // Verify the value was actually set
                                                            if (citySelect.value === String(cityId)) {
                                                                console.log('✅ Set destination city (fallback):', cityId, '(verified)');
                                                                setTimeout(function() {
                                                                    verifyFieldsSet();
                                                                }, 300);
                                                                return;
                                                            } else if (retryCount < maxRetries) {
                                                                setTimeout(() => setCityValueFallback(cityId, retryCount + 1), 200);
                                                            }
                                                        } else if (retryCount < maxRetries) {
                                                            // Option doesn't exist yet, retry
                                                            setTimeout(() => setCityValueFallback(cityId, retryCount + 1), 200);
                                                        } else {
                                                            // Last resort: try direct population
                                                            const citiesMap = (window.myPluginAjax && window.myPluginAjax.countryCities) || {};
                                                            const cities = citiesMap && citiesMap[String(deliveryData.destination_country_id)] ? citiesMap[String(deliveryData.destination_country_id)] : [];

                                                            if (Array.isArray(cities) && cities.length) {
                                                                citySelect.innerHTML = '<option value="">Select City</option>';
                                                                cities.forEach(function(city) {
                                                                    const option = document.createElement('option');
                                                                    option.value = city.id;
                                                                    option.textContent = city.city_name;
                                                                    if (String(city.id) === String(cityId)) {
                                                                        option.selected = true;
                                                                    }
                                                                    citySelect.appendChild(option);
                                                                });
                                                                citySelect.value = cityId;
                                                                const cityChangeEvent = new Event('change', {
                                                                    bubbles: true
                                                                });
                                                                citySelect.dispatchEvent(cityChangeEvent);

                                                                if (citySelect.value === String(cityId)) {
                                                                    console.log('✅ Set destination city (fallback direct population):', cityId, '(verified)');
                                                                    setTimeout(function() {
                                                                        verifyFieldsSet();
                                                                    }, 300);
                                                                }
                                                            }
                                                        }
                                                    } else if (retryCount < maxRetries) {
                                                        setTimeout(() => setCityValueFallback(cityId, retryCount + 1), 200);
                                                    }
                                                }

                                                setTimeout(() => setCityValueFallback(deliveryData.destination_city_id, 0), 800);
                                            }
                                        }

                                        // Set hidden fields - CRITICAL for validation
                                        const selectedDeliveryId = document.getElementById('selected_delivery_id');
                                        const directionIdField = document.getElementById('direction_id');

                                        if (selectedDeliveryId) {
                                            selectedDeliveryId.value = deliveryId;
                                            console.log('✅ Set selected_delivery_id (fallback) to:', deliveryId);
                                        }
                                        if (directionIdField) {
                                            const dirId = deliveryData.direction_id || deliveryId;
                                            directionIdField.value = dirId;
                                            console.log('✅ Set direction_id (fallback) to:', dirId);
                                        }

                                        // Verify all fields are set after fallback population
                                        setTimeout(function() {
                                            verifyFieldsSet();
                                        }, 2000);
                                    }
                                }

                                // Start looking for the delivery card after a short delay
                                setTimeout(function() {
                                    findAndSelectDeliveryCard(0);
                                }, 500);
                            }, 500);
                        }

                        // Listen for modal opening event
                        $(document).on('modal:opened', function(e, openedModal) {
                            if (openedModal && openedModal.length && openedModal.attr('id') === 'create-waybill-modal') {
                                prepareStep4AndDelivery();
                            }
                        });

                        // Also listen directly on the modal element
                        $('#create-waybill-modal').on('modal:opened', function() {
                            prepareStep4AndDelivery();
                        });

                        // Fallback: check if modal is already open when script loads
                        setTimeout(function() {
                            const modal = $('#create-waybill-modal');
                            if (modal.length && !modal.hasClass('hidden')) {
                                prepareStep4AndDelivery();
                            }
                        }, 1000);
                    });
                </script>
                <?php
                // Display success message if waybill was created
                if (isset($_GET['waybill_created']) && $_GET['waybill_created'] === '1') {
                    if (!class_exists('KIT_Toast')) {
                        require_once plugin_dir_path(__FILE__) . '../components/toast.php';
                    }
                    KIT_Toast::ensure_toast_loads();
                    $waybill_no = isset($_GET['waybill_no']) ? sanitize_text_field($_GET['waybill_no']) : '';
                    $message    = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : 'Waybill created successfully!';
                    echo KIT_Toast::success($message, 'Success');
                }
                ?>

                <div class="<?php echo KIT_Commons::container() ?>">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
                        <div class="md:col-span-4 min-w-0">
                            <div class="bg-white rounded-lg shadow border border-gray-200 p-3 sm:p-4 md:p-6 space-y-3 md:space-y-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h2 class="text-base sm:text-lg md:text-md font-semibold text-gray-700">Truck Details</h2>
                                </div>
                                <hr>
                                <table class="min-w-full divide-y divide-gray-200 text-xs md:text-sm">
                                    <tbody class="bg-white divide-y divide-gray-100">
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3">
                                                Reference</th>
                                            <td class="py-1.5 md:py-2 text-gray-900 break-words">
                                                <?php echo esc_html($delivery->delivery_reference); ?></td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3">
                                                Origin</th>
                                            <td class="py-1.5 md:py-2 text-gray-900 break-words">
                                                <?php echo esc_html($delivery->origin_country); ?></td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3">
                                                Destination</th>
                                            <td class="py-1.5 md:py-2 text-gray-900 break-words">
                                                <?php echo esc_html($delivery->destination_country); ?></td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3">
                                                Departure</th>
                                            <td class="py-1.5 md:py-2 text-gray-900">
                                                <?php echo esc_html(date('Y-m-d', strtotime($delivery->dispatch_date))); ?></td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3">
                                                Driver</th>
                                            <td class="py-1.5 md:py-2 text-gray-900">
                                                <?php
                                                if (isset($delivery->driver_name) && !empty($delivery->driver_name)) {
                                                    echo esc_html($delivery->driver_name);
                                                    if (!empty($delivery->driver_phone)) {
                                                        echo ' <span class="text-gray-500">(' . esc_html($delivery->driver_phone) . ')</span>';
                                                    }
                                                } else {
                                                    echo '<span class="text-gray-400">No driver assigned</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3">
                                                Status</th>
                                            <td class="py-1.5 md:py-2">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                            <?php
                            echo $delivery->status === 'delivered'
                                ? 'bg-green-100 text-green-800'
                                : ($delivery->status === 'in_transit'
                                    ? 'bg-yellow-100 text-yellow-800'
                                    : 'bg-blue-100 text-blue-800');
                            ?>">
                                                    <?php echo esc_html(ucfirst(str_replace('_', ' ', (string) ($delivery->status ?? '')))); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3">
                                                Created By</th>
                                            <td class="py-1.5 md:py-2 text-gray-900">
                                                <?php echo esc_html(self::get_customer_name($delivery->created_by)); ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <hr>
                                <!-- Button to generate PDF of this delivery and its waybills, showing the waybills and the city names of the waybills -->
                                <div class="flex justify-between">
                                    <div class="">
                                        <?php
                                        // Render Edit Delivery Modal
                                        $edit_delivery_form = self::deliveryForm($delivery_id, true);
                                        echo KIT_Commons::renderButton(
                                            'EDIT',
                                            'primary',
                                            'lg',
                                            [
                                                'onclick'     => sprintf("editDeliveryTruck('%s')", esc_js($delivery_id)),
                                                'classes'     => 'gap-2',
                                                'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />',
                                                'iconPosition' => 'left',
                                            ]
                                        );

                                        ?>
                                    </div>
                                    <div class="">
                                        <?php
                                        $delivery_pdf_url = add_query_arg(
                                            [
                                                'delivery_id'   => $delivery_id,
                                                'delivery_nonce' => wp_create_nonce('delivery_truck_pdf'),
                                            ],
                                            plugin_dir_url(__FILE__) . '../../delivery-truck-pdf.php'
                                        );
                                        ?>
                                        <?php
                                        echo KIT_Commons::renderButton(
                                            'PDF',
                                            'primary',
                                            'lg',
                                            [
                                                'href'        => esc_url($delivery_pdf_url),
                                                'target'      => '_blank',
                                                'rel'         => 'noopener noreferrer',
                                                'classes'     => 'gap-2',
                                                'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />',
                                                'iconPosition' => 'left',
                                                'gradient'    => true,
                                            ]
                                        );
                                        ?>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <div class="md:col-span-8 min-w-0 bg-white rounded-lg shadow border border-gray-200 p-3 sm:p-4 md:p-6">
                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2 mb-3 md:mb-4">
                                <h2 class="text-base sm:text-lg md:text-xl font-semibold text-gray-700">Waybills on Truck</h2>
                                <div class="text-xs sm:text-sm text-gray-500">
                                    Showing: <?php echo is_array($waybillsandItems) ? count($waybillsandItems) : 0; ?> waybills
                                </div>
                            </div>

                            <!-- Totals Summary Row -->
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 sm:p-3 md:p-4 mb-3 md:mb-4">
                                <div class="flex flex-wrap justify-between items-center gap-2 md:gap-4 text-center">
                                    <div class="flex-1 min-w-[100px] px-1">
                                        <div class="text-xs md:text-sm font-medium text-blue-800 truncate">Total Waybills</div>
                                        <div class="text-lg md:text-2xl font-bold text-blue-900 truncate">
                                            <?php echo KIT_Waybills::calculate_total_waybills($delivery_id); ?></div>
                                    </div>
                                    <div class="flex-1 min-w-[100px] px-1">
                                        <div class="text-xs md:text-sm font-medium text-blue-800 truncate">Total Weight</div>
                                        <div class="text-sm md:text-2xl font-bold text-blue-900 truncate">
                                            <?php echo number_format(KIT_Waybills::calculate_total_mass($delivery_id), 1); ?> KG
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-[100px] px-1">
                                        <div class="text-xs md:text-sm font-medium text-blue-800 truncate">Total Volume</div>
                                        <div class="text-sm md:text-2xl font-bold text-blue-900 truncate">
                                            <?php echo number_format(KIT_Waybills::calculate_total_volume($delivery_id), 1); ?> m³
                                        </div>
                                    </div>
                                    <?php if (class_exists('KIT_User_Roles') && !KIT_User_Roles::can_see_prices()): ?>

                                    <?php else: ?>
                                        <div class="flex-1 min-w-[100px] px-1">
                                            <div class="text-xs md:text-sm font-medium text-blue-800 truncate">Total Amount</div>
                                            <div class="text-sm md:text-2xl font-bold text-blue-900 truncate">
                                                <?php echo KIT_Commons::currency() . ' ' . number_format(KIT_Waybills::calculate_total_amount($delivery_id), 2); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php
                            // Use standardized columns - waybill_no includes status badge below it (universal)
                            // Name & Surname column removed; Mass & Dims and Volume (m³) combined in one cell
                            $columns = KIT_Commons::getColumns([
                                'waybill_no',
                                'description',
                            ]);
                            // Description: biggest column for space
                            $columns['description']['header_class'] = ($columns['description']['header_class'] ?? '') . ' whitespace-nowrap text-left min-w-[180px]';
                            $columns['description']['cell_class']   = ($columns['description']['cell_class'] ?? '') . ' text-left text-sm min-w-[180px] max-w-none';

                            // Mass & Dims and Volume (m³) combined in one cell
                            $columns['total_mass_kg'] = array(
                                'label'        => 'Mass, Dims & Vol (m³)',
                                'header_class' => 'whitespace-nowrap',
                                'callback'     => function ($value, $row, $rowIndex) {
                                    $mass = $value ?? 0;
                                    $length = isset($row['item_length']) ? floatval($row['item_length']) : 0;
                                    $width = isset($row['item_width']) ? floatval($row['item_width']) : 0;
                                    $height = isset($row['item_height']) ? floatval($row['item_height']) : 0;
                                    $volume = isset($row['total_volume']) ? floatval($row['total_volume']) : 0;

                                    $mass_display = ($mass > 0) ? number_format($mass, 1) . ' kg' : '0 kg';
                                    $dimensions_display = ($length > 0 && $width > 0 && $height > 0)
                                        ? number_format($length, 0) . ' x ' . number_format($width, 0) . ' x ' . number_format($height, 0)
                                        : '0 x 0 x 0';
                                    $volume_display = ($volume > 0) ? number_format($volume, 3) . ' m³' : '0 m³';

                                    return '<div class="text-xs text-gray-500">' .
                                        esc_html($mass_display) . ' <br> ' .
                                        esc_html($dimensions_display) . ' <br> ' .
                                        esc_html($volume_display) . '</div>';
                                },
                            );
                            $columns['destination_city'] = array(
                                'label'    => 'Destination City',
                                'header_class' => 'whitespace-nowrap text-left',
                                'cell_class'   => 'whitespace-nowrap text-left',
                                'callback' => function ($value, $row, $rowIndex) {
                                    $city_name = isset($row['customer_city']) ? trim($row['customer_city']) : '';
                                    if (empty($city_name)) {
                                        $city_id = $row['city_id'] ?? 0;
                                        if ($city_id > 0) {
                                            $city_name = KIT_Routes::get_city_name_by_id($city_id);
                                        }
                                    }
                                    return !empty($city_name) ? esc_html($city_name) : '<span class="text-gray-400">N/A</span>';
                                },
                            );

                            // Conditionally add total column based on user permissions
                            if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()) {
                                $columns['total'] = array(
                                    'label'        => 'Total',
                                    'header_class' => 'whitespace-nowrap text-right',
                                    'cell_class'   => 'whitespace-nowrap text-right',
                                    'callback'     => function ($value, $row, $rowIndex) {
                                        $total = $value ?? 0;
                                        if (!is_numeric($total)) {
                                            return $total;
                                        }
                                        return KIT_Commons::currency() . ' ' . number_format(floatval($total), 2);
                                    },
                                );
                            }
                            // Debug: Check how many waybills we have
                            $waybill_count = is_array($waybillsandItems) ? count($waybillsandItems) : 0;
                            ?>
                            <div class="">
                                <?php
                                //customer dropdown trigger truck waybills
                                $dropdowns = true;

                                echo KIT_Unified_Table::infinite($waybillsandItems, $columns, [
                                    'title'                 => 'Waybills on Truck',
                                    'subtitle'              => 'Showing: ' . $waybill_count . ' waybills',
                                    'empty_message'         => 'No waybills assigned',
                                    'class'                 => 'min-w-full divide-y divide-gray-200',
                                    'table_class'           => 'w-full border-collapse',
                                    'groupby'               => 'city',
                                    'group_heading_prefix'  => '',
                                    'preserve_order'        => true,
                                    'group_collapsible'     => true,
                                    'group_collapsed'       => true,
                                ]);
                                ?>
                            </div>
                            <?php
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
        <?php
    }
    public static function getAllCountries()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_operating_countries';
        return $wpdb->get_results("SELECT * FROM $table_name");
    }
    public static function getCountriesObject()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_operating_countries';
        return $wpdb->get_results("SELECT * FROM $table_name WHERE is_active = 1");
    }

    /**
     * Get countries with configurable filtering rules
     * @param array $options Rules like ['only_active', 'show_all', 'order_by']
     * @return array Country objects
     */
    public static function getCountriesWithRules($options = [])
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_operating_countries';

        // Default to showing active countries for backwards compatibility
        $where_conditions = [];
        $params           = [];

        // Default behavior - show all countries for route creation
        if (empty($options) || isset($options['show_all_countries'])) {
            $query = "SELECT * FROM $table_name ORDER BY country_name ASC";
        } else {
            if (isset($options['only_active']) && $options['only_active']) {
                $where_conditions[] = "is_active = %d";
                $params[]           = 1;
            }

            if (isset($options['order_by'])) {
                $order_sql = sanitize_sql_orderby($options['order_by']);
                $query     = "SELECT * FROM $table_name" .
                    (count($where_conditions) > 0 ? " WHERE " . implode(" AND ", $where_conditions) : "") .
                    " ORDER BY " . $order_sql;
            } else {
                $query = "SELECT * FROM $table_name" .
                    (count($where_conditions) > 0 ? " WHERE " . implode(" AND ", $where_conditions) : "") .
                    " ORDER BY country_name ASC";
            }
        }

        if (count($params) > 0) {
            $results = $wpdb->get_results($wpdb->prepare($query, $params));
        } else {
            $results = $wpdb->get_results($query);
        }

        return $results ?: [];
    }
    public static function get_Cities_forCountry($country_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_operating_cities';
        $query      = $wpdb->prepare("SELECT * FROM $table_name WHERE country_id = %d ORDER BY city_name ASC", $country_id);
        $results    = $wpdb->get_results($query);

        // Optional debug (disabled in production)
        // error_log("Getting cities for country ID: $country_id, found: " . count($results));

        return $results;
    }
    /**
     * Returns a map of country_id => list of cities [{id, city_name}], limited to active countries
     * for efficient client-side population of city selects without AJAX.
     */
    public static function getCountryCitiesMap($force_refresh = false)
    {
        static $runtime_cache = null;
        $cache_ttl = 15 * MINUTE_IN_SECONDS;
        $transient_key = 'kit_country_cities_map_v1';

        if (! $force_refresh && is_array($runtime_cache)) {
            return $runtime_cache;
        }

        if (! $force_refresh) {
            $cached_transient = get_transient($transient_key);
            if (is_array($cached_transient)) {
                $runtime_cache = $cached_transient;
                return $cached_transient;
            }

            $cache_file = self::countryCitiesMapCacheFile();
            if ($cache_file && file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_ttl)) {
                $json = @file_get_contents($cache_file);
                $decoded = json_decode((string) $json, true);
                if (is_array($decoded)) {
                    set_transient($transient_key, $decoded, $cache_ttl);
                    $runtime_cache = $decoded;
                    return $decoded;
                }
            }
        }

        global $wpdb;
        $cities_table = $wpdb->prefix . 'kit_operating_cities';
        $rows = $wpdb->get_results("SELECT id, country_id, city_name FROM $cities_table ORDER BY country_id ASC, city_name ASC");

        $map = [];
        foreach ((array) $rows as $row) {
            $key = (string) intval($row->country_id);
            if (! isset($map[$key])) {
                $map[$key] = [];
            }
            $map[$key][] = [
                'id'        => intval($row->id),
                'city_name' => (string) $row->city_name,
            ];
        }

        set_transient($transient_key, $map, $cache_ttl);
        self::writeCountryCitiesMapCache($map);
        $runtime_cache = $map;

        return $map;
    }

    private static function countryCitiesMapCacheFile()
    {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return null;
        }

        $dir = trailingslashit($upload_dir['basedir']) . 'courier-finance-cache';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return trailingslashit($dir) . 'country-cities-map.json';
    }

    private static function writeCountryCitiesMapCache(array $map)
    {
        $cache_file = self::countryCitiesMapCacheFile();
        if (!$cache_file) {
            return;
        }

        $json = wp_json_encode($map);
        if (is_string($json)) {
            @file_put_contents($cache_file, $json);
        }
    }
    public static function CountrySelect($name = '', $id = '', $delivery_id = null, $required = true)
    {
        $required_attr = $required ? 'required' : '';
        //get all the countires with the is_active = 1
        $countries = self::getCountriesObject();
        if (empty($countries)) {
            return '<p class="text-red-500">No active countries found.</p>';
        }
        if ($delivery_id): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const select = document.getElementById('<?php echo esc_js($id); ?>');
                    if (select) {
                        handleCountryChange(select.value);
                    }
                });
            </script>
            <script>
                // Safe editDelivery handler to avoid null.classList errors
                function editDelivery(deliveryId) {
                    const panelId = 'delivery-edit-panel-' + deliveryId;
                    const modalId = 'delivery-edit-modal';
                    const target = document.getElementById(panelId) || document.getElementById(modalId);
                    if (!target) {
                        console.warn('Edit target not found for delivery', deliveryId, 'expected ids:', panelId, 'or', modalId);
                        return false;
                    }
                    if (target.classList) {
                        target.classList.remove('hidden');
                        target.focus && target.focus();
                    }
                    return false; // prevent default
                }
            </script>
        <?php endif;
        ob_start();
        ?>
        <select onchange="handleCountryChange(this.value, '<?php echo esc_attr($name); ?>')"
            name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>" <?php echo $required_attr; ?>
            class="<?php echo KIT_Commons::selectClass(); ?>">
            <option value="">Select Country</option>
            <?php foreach ($countries as $country): ?>
                <option value="<?php echo esc_attr($country->id); ?>"
                    <?php echo ($delivery_id == $country->id) ? 'selected' : ''; ?>>
                    <?php echo esc_html($country->country_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php
        return ob_get_clean();
    }

    public static function deliveryForm($delivery_id = null, $is_modal = false)
    {
        // Get delivery data for editing
        $delivery = null;
        if ($delivery_id) {
            $delivery = self::get_delivery($delivery_id);
        }

        // Only managers and admins can create a new delivery truck
        if (! $delivery && class_exists('KIT_User_Roles') && ! KIT_User_Roles::can_create_delivery_truck()) {
            $msg = __('Request management to create a delivery truck.', '08600-services-quotations');
            return '<div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg">' . esc_html($msg) . '</div>';
        }

        ob_start();
    ?>
        <?php if (!$is_modal): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
            <?php endif; ?>
            <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    <strong>Success!</strong> Delivery updated successfully.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] == '1'): ?>
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <strong>Error!</strong> Failed to update delivery. Please try again.
                </div>
            <?php endif; ?>

            <?php if (!$is_modal): ?>
                <div class="mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex-shrink-0">
                            <h2 class="text-lg sm:text-xl font-semibold text-gray-900 whitespace-nowrap">
                                <?php echo $delivery ? 'Edit Delivery Truck' : 'Create New Delivery Truck' ?>
                            </h2>
                            <p class="text-xs sm:text-sm text-gray-600 mt-1">
                                <?php echo $delivery ? 'Update delivery truck information and settings' : 'Create a new delivery truck entry' ?>
                            </p>
                        </div>
                        <?php if ($delivery): ?>
                            <div class="flex items-center space-x-2 flex-shrink-0">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                            <?php echo $delivery->status === 'delivered' ? 'bg-green-100 text-green-800' : ($delivery->status === 'in_transit' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800') ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', (string) ($delivery->status ?? ''))) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form id="edit-delivery-form" method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="space-y-6">
                <input type="hidden" name="action" value="kit_deliveries_crud">
                <input type="hidden" name="task" value="<?php echo $delivery ? 'update_delivery' : 'create_delivery' ?>">
                <input type="hidden" name="delivery_id" id="delivery_id"
                    value="<?php echo $delivery ? $delivery->delivery_id : 0 ?>">
                <input type="hidden" name="direction_id" id="direction_id"
                    value="<?php echo $delivery ? $delivery->direction_id : 0 ?>">

                <?php wp_nonce_field('get_waybills_nonce', 'nonce'); ?>

                <!-- Step Indicators -->
                <div class="mb-8">
                    <div class="flex items-center justify-between">
                        <!-- Step 1 -->
                        <div class="flex items-center flex-1">
                            <div class="step-indicator flex items-center justify-center w-10 h-10 rounded-full border-2 border-blue-500 bg-blue-500 text-white font-semibold transition-all duration-300" data-step="1">
                                <span class="step-number">1</span>
                                <svg class="step-check hidden w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div class="ml-3 hidden sm:block">
                                <p class="text-sm font-medium text-gray-900">Reference</p>
                                <p class="text-xs text-gray-500">Auto-generated</p>
                            </div>
                            <div class="flex-1 h-0.5 bg-gray-200 mx-4 hidden sm:block">
                                <div class="step-progress h-full bg-blue-500 transition-all duration-300" style="width: 0%"></div>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div class="flex items-center flex-1">
                            <div class="step-indicator flex items-center justify-center w-10 h-10 rounded-full border-2 border-gray-300 bg-white text-gray-500 font-semibold transition-all duration-300" data-step="2">
                                <span class="step-number">2</span>
                                <svg class="step-check hidden w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div class="ml-3 hidden sm:block">
                                <p class="text-sm font-medium text-gray-500">Route</p>
                                <p class="text-xs text-gray-400">Origin & Destination</p>
                            </div>
                            <div class="flex-1 h-0.5 bg-gray-200 mx-4 hidden sm:block">
                                <div class="step-progress h-full bg-blue-500 transition-all duration-300" style="width: 0%"></div>
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div class="flex items-center">
                            <div class="step-indicator flex items-center justify-center w-10 h-10 rounded-full border-2 border-gray-300 bg-white text-gray-500 font-semibold transition-all duration-300" data-step="3">
                                <span class="step-number">3</span>
                                <svg class="step-check hidden w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div class="ml-3 hidden sm:block">
                                <p class="text-sm font-medium text-gray-500">Details</p>
                                <p class="text-xs text-gray-400">Date & Driver</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 1: Reference Number -->
                <div class="delivery-step" data-step="1" style="display: block;">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 border border-blue-100">
                        <div class="flex items-center mb-3">
                            <div class="flex-shrink-0 w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-base font-semibold text-gray-900">Reference Number</h3>
                                <p class="text-xs text-gray-600">Auto-generated delivery reference</p>
                            </div>
                        </div>
                        <div class="relative">
                            <input type="text" name="delivery_reference" id="delivery_reference" readonly
                                value="<?php echo $delivery ? esc_attr($delivery->delivery_reference) : KIT_Deliveries::generateDeliveryRef() ?>"
                                class="w-full px-3 py-2.5 border border-blue-200 rounded-lg shadow-sm bg-white text-gray-900 font-mono text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                                <?php echo KIT_Commons::renderButton('', 'link', 'sm', [
                                    'type' => 'button',
                                    'onclick' => "copyToClipboard('delivery_reference')",
                                    'classes' => 'text-blue-500 hover:text-blue-700 transition-colors',
                                    'title' => 'Copy reference',
                                    'ariaLabel' => 'Copy reference',
                                    'iconOnly' => true,
                                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>',
                                ]); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Route Information -->
                <div class="delivery-step hidden" data-step="2" style="display: none;">
                    <div class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl p-4 border border-green-100">
                        <div class="flex items-center mb-4">
                            <div class="flex-shrink-0 w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-base font-semibold text-gray-900">Route Information</h3>
                                <p class="text-xs text-gray-600">Select origin and destination locations</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <!-- Origin -->
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <label for="origin_country_select" class="block text-xs font-medium text-gray-700 mb-2">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-1.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        Origin Location
                                    </span>
                                </label>
                                <div class="space-y-2">
                                    <?php
                                    if ($delivery) {
                                        error_log('Debug - Origin Country ID: ' . ($delivery->origin_country_id ?? 'not set'));
                                        error_log('Debug - Origin Country Name: ' . ($delivery->origin_country ?? 'not set'));
                                    }
                                    require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsOrigin.php';
                                    ?>
                                </div>
                            </div>

                            <!-- Destination -->
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <label for="destination_country_select" class="block text-xs font-medium text-gray-700 mb-2">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-1.5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        Destination Location
                                    </span>
                                </label>
                                <div class="space-y-2">
                                    <?php
                                    if ($delivery) {
                                        error_log('Debug - Destination Country ID: ' . ($delivery->destination_country_id ?? 'not set'));
                                        error_log('Debug - Destination Country Name: ' . ($delivery->destination_country ?? 'not set'));
                                        error_log('Debug - Destination City ID: ' . ($delivery->destination_city_id ?? 'not set'));
                                    }
                                    require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsDestination.php';
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Delivery Details -->
                <div class="delivery-step hidden" data-step="3" style="display: none;">
                    <div class="bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl p-4 border border-purple-100">
                        <div class="flex items-center mb-4">
                            <div class="flex-shrink-0 w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-base font-semibold text-gray-900">Delivery Details</h3>
                                <p class="text-xs text-gray-600">Set departure date and assign driver</p>
                            </div>
                        </div>

                        <!-- Compact Grid Layout -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <!-- Dispatch Date -->
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <label for="dispatch_date" class="block text-xs font-medium text-gray-700 mb-2">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-1.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        Departure Date
                                    </span>
                                </label>
                                <input type="date" name="dispatch_date" id="dispatch_date" required
                                    value="<?php echo $delivery ? esc_attr($delivery->dispatch_date) : '' ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors text-sm">
                            </div>

                            <!-- Driver -->
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <label for="driver_id" class="block text-xs font-medium text-gray-700 mb-2">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-1.5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                        Driver Name
                                    </span>
                                </label>
                                <?php
                                $drivers = KIT_Deliveries::get_all_drivers();
                                $selected_driver_id = $delivery ? ($delivery->driver_id ?? '') : '';
                                ?>
                                <select name="driver_id" id="driver_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors text-sm">
                                    <option value="">Select Driver</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?php echo esc_attr($driver->id); ?>"
                                            <?php echo ($selected_driver_id == $driver->id) ? 'selected' : ''; ?>>
                                            <?php echo esc_html($driver->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($drivers)): ?>
                                    <p class="mt-1.5 text-xs text-yellow-600">
                                        <a href="<?php echo admin_url('admin.php?page=manage-drivers&add=1'); ?>" class="underline font-medium">Add driver</a>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <!-- Status -->
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <label for="status" class="block text-xs font-medium text-gray-700 mb-2">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-1.5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Delivery Status
                                    </span>
                                </label>
                                <div class="relative">
                                    <?php
                                    $deliveries_status = [
                                        'scheduled'  => 'Scheduled',
                                        'in_transit' => 'In Transit',
                                        'delivered'  => 'Delivered',
                                    ];
                                    $current_status = $delivery ? $delivery->status : 'scheduled';
                                    echo KIT_Commons::simpleSelect(
                                        '',
                                        'status',
                                        'status',
                                        $deliveries_status,
                                        $current_status
                                    );
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t border-gray-200">
                    <?php echo KIT_Commons::renderButton('Previous', 'secondary', 'lg', ['type' => 'button', 'id' => 'prev-step-btn', 'classes' => 'hidden flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-colors flex items-center justify-center', 'style' => 'display: none;', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>', 'iconPosition' => 'left']); ?>
                    <?php echo KIT_Commons::renderButton('Next', 'primary', 'lg', ['type' => 'button', 'id' => 'next-step-btn', 'classes' => 'flex-1 px-6 py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition-colors flex items-center justify-center shadow-sm', 'style' => 'display: flex;', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>', 'iconPosition' => 'right']); ?>
                    <?php echo KIT_Commons::renderButton($delivery ? 'Update Delivery' : 'Create Delivery', 'primary', 'lg', ['type' => 'submit', 'id' => 'submit-delivery-btn', 'classes' => 'hidden flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md flex items-center justify-center', 'style' => 'display: none;', 'gradient' => true, 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>', 'iconPosition' => 'left']); ?>
                    <?php if ($is_modal): ?>
                        <?php echo KIT_Commons::renderButton('Cancel', 'secondary', 'lg', ['type' => 'button', 'classes' => 'flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-colors modal-close flex items-center justify-center']); ?>
                    <?php endif; ?>
                </div>
            </form>

            <script>
                (function() {
                    let currentStep = 1;
                    const totalSteps = 3;
                    const form = document.getElementById('edit-delivery-form');

                    if (!form) return;

                    function updateStepIndicators(step) {
                        // Update step indicators
                        document.querySelectorAll('.step-indicator').forEach((indicator, index) => {
                            const stepNum = index + 1;
                            const stepNumber = indicator.querySelector('.step-number');
                            const stepCheck = indicator.querySelector('.step-check');

                            if (stepNum < step) {
                                // Completed step
                                indicator.classList.remove('border-gray-300', 'bg-white', 'text-gray-500');
                                indicator.classList.add('border-blue-500', 'bg-blue-500', 'text-white');
                                if (stepNumber) stepNumber.classList.add('hidden');
                                if (stepCheck) stepCheck.classList.remove('hidden');
                            } else if (stepNum === step) {
                                // Current step
                                indicator.classList.remove('border-gray-300', 'bg-white', 'text-gray-500');
                                indicator.classList.add('border-blue-500', 'bg-blue-500', 'text-white');
                                if (stepNumber) stepNumber.classList.remove('hidden');
                                if (stepCheck) stepCheck.classList.add('hidden');
                            } else {
                                // Future step
                                indicator.classList.remove('border-blue-500', 'bg-blue-500', 'text-white');
                                indicator.classList.add('border-gray-300', 'bg-white', 'text-gray-500');
                                if (stepNumber) stepNumber.classList.remove('hidden');
                                if (stepCheck) stepCheck.classList.add('hidden');
                            }
                        });

                        // Update progress bars
                        document.querySelectorAll('.step-progress').forEach((progress, index) => {
                            if (index + 1 < step) {
                                progress.style.width = '100%';
                            } else {
                                progress.style.width = '0%';
                            }
                        });
                    }

                    function showStep(step) {
                        // Hide all steps with display: none
                        document.querySelectorAll('.delivery-step').forEach(stepEl => {
                            stepEl.style.display = 'none';
                            stepEl.classList.add('hidden');
                        });

                        // Show current step
                        const currentStepEl = document.querySelector(`.delivery-step[data-step="${step}"]`);
                        if (currentStepEl) {
                            currentStepEl.style.display = 'block';
                            currentStepEl.classList.remove('hidden');
                        }

                        // Update navigation buttons
                        const prevBtn = document.getElementById('prev-step-btn');
                        const nextBtn = document.getElementById('next-step-btn');
                        const submitBtn = document.getElementById('submit-delivery-btn');

                        if (prevBtn) {
                            if (step > 1) {
                                prevBtn.style.display = 'flex';
                                prevBtn.classList.remove('hidden');
                            } else {
                                prevBtn.style.display = 'none';
                                prevBtn.classList.add('hidden');
                            }
                        }

                        if (nextBtn && submitBtn) {
                            if (step < totalSteps) {
                                nextBtn.style.display = 'flex';
                                nextBtn.classList.remove('hidden');
                                submitBtn.style.display = 'none';
                                submitBtn.classList.add('hidden');
                            } else {
                                nextBtn.style.display = 'none';
                                nextBtn.classList.add('hidden');
                                submitBtn.style.display = 'flex';
                                submitBtn.classList.remove('hidden');
                            }
                        }

                        updateStepIndicators(step);
                    }

                    function validateStep(step) {
                        if (step === 1) {
                            // Step 1: Reference is auto-generated, always valid
                            return true;
                        } else if (step === 2) {
                            // Step 2: Validate route information
                            const originCountry = document.getElementById('origin_country_select');
                            const originCity = document.getElementById('origin_city_select');
                            const destCountry = document.getElementById('destination_country_select');
                            const destCity = document.getElementById('destination_city_select');

                            if (!originCountry || !originCountry.value) {
                                alert('Please select an origin country');
                                return false;
                            }
                            if (!originCity || !originCity.value) {
                                alert('Please select an origin city');
                                return false;
                            }
                            if (!destCountry || !destCountry.value) {
                                alert('Please select a destination country');
                                return false;
                            }
                            if (!destCity || !destCity.value) {
                                alert('Please select a destination city');
                                return false;
                            }
                            return true;
                        } else if (step === 3) {
                            // Step 3: Validate delivery details
                            const dispatchDate = document.getElementById('dispatch_date');
                            const driverId = document.getElementById('driver_id');

                            if (!dispatchDate || !dispatchDate.value) {
                                alert('Please select a departure date');
                                dispatchDate?.focus();
                                return false;
                            }
                            if (!driverId || !driverId.value) {
                                alert('Please select a driver');
                                driverId?.focus();
                                return false;
                            }
                            return true;
                        }
                        return true;
                    }

                    // Next button handler
                    const nextBtn = document.getElementById('next-step-btn');
                    if (nextBtn) {
                        nextBtn.addEventListener('click', function() {
                            if (validateStep(currentStep)) {
                                currentStep++;
                                if (currentStep > totalSteps) currentStep = totalSteps;
                                showStep(currentStep);
                            }
                        });
                    }

                    // Previous button handler
                    const prevBtn = document.getElementById('prev-step-btn');
                    if (prevBtn) {
                        prevBtn.addEventListener('click', function() {
                            currentStep--;
                            if (currentStep < 1) currentStep = 1;
                            showStep(currentStep);
                        });
                    }

                    // Step indicator click handler
                    document.querySelectorAll('.step-indicator').forEach((indicator, index) => {
                        indicator.addEventListener('click', function() {
                            const targetStep = index + 1;
                            if (targetStep <= currentStep || targetStep === 1) {
                                currentStep = targetStep;
                                showStep(currentStep);
                            }
                        });
                    });

                    // Copy to clipboard function
                    window.copyToClipboard = function(inputId) {
                        const input = document.getElementById(inputId);
                        if (input) {
                            input.select();
                            input.setSelectionRange(0, 99999); // For mobile devices
                            document.execCommand('copy');

                            // Show feedback
                            const btn = event.target.closest('button');
                            if (btn) {
                                const originalHTML = btn.innerHTML;
                                btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
                                btn.classList.add('text-green-500');
                                setTimeout(() => {
                                    btn.innerHTML = originalHTML;
                                    btn.classList.remove('text-green-500');
                                }, 2000);
                            }
                        }
                    };

                    // Initialize
                    showStep(1);
                })();
            </script>
            <?php if (!$is_modal): ?>
            </div>
        <?php endif; ?>

        <style>
            .form-field input:focus,
            .form-field select:focus {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
            }

            .form-field input.error,
            .form-field select.error {
                border-color: #ef4444;
                box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
            }

            .success-toast {
                animation: slideInRight 0.3s ease-out;
            }

            @keyframes slideInRight {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }

                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        </style>


    <?php
        return ob_get_clean();
    }

    /**
     * Get country_id, country_name, and country_code from a direction_id.
     *
     * @param int $direction_id
     * @param string $type 'origin' or 'destination'
     * @return array|null
     */
    public static function getWaybillTransitStats($direction_id, $type = 'origin')
    {
        global $wpdb;

        // Validate $type
        $type = strtolower($type);
        if (! in_array($type, ['origin', 'destination'])) {
            $type = 'origin';
        }

        // Set column names based on type
        $country_id_col      = $type === 'origin' ? 'origin_country_id' : 'destination_country_id';
        $country_table_alias = $type === 'origin' ? 'oc1' : 'oc2';

        // Get the country_id from the direction
        $direction = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT sd.{$country_id_col} as country_id
                 FROM {$wpdb->prefix}kit_shipping_directions sd
                 WHERE sd.id = %d
                 LIMIT 1",
                $direction_id
            ),
            ARRAY_A
        );

        if (! $direction || empty($direction['country_id'])) {
            return null;
        }

        $country_id = intval($direction['country_id']);

        // Get the country details
        $country = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id as country_id, country_name, country_code
                 FROM {$wpdb->prefix}kit_operating_countries
                 WHERE id = %d
                 LIMIT 1",
                $country_id
            ),
            ARRAY_A
        );

        return $country ?: null;
    }

    public static function render_admin_page()
    {
        // Auto-update past deliveries to unconfirmed before loading the page
        self::auto_update_past_deliveries();

        // Show delivery_error / delivery_success from redirects (e.g. create_delivery denied)
        if (isset($_GET['delivery_error']) || isset($_GET['delivery_success'])) {
            if (file_exists(plugin_dir_path(__FILE__) . '../components/toast.php')) {
                require_once plugin_dir_path(__FILE__) . '../components/toast.php';
            }
            if (class_exists('KIT_Toast')) {
                KIT_Toast::ensure_toast_loads();
                if (isset($_GET['delivery_success']) && $_GET['delivery_success'] === '1') {
                    $message = isset($_GET['message']) ? urldecode($_GET['message']) : __('Operation completed successfully!', '08600-services-quotations');
                    echo KIT_Toast::success($message, 'Success');
                }
                if (isset($_GET['delivery_error'])) {
                    echo KIT_Toast::error(urldecode($_GET['delivery_error']), 'Error');
                }
            }
        }

        // Check if we're viewing a specific delivery
        if (isset($_GET['view_delivery']) && is_numeric($_GET['view_delivery'])) {
            // Set the delivery_id parameter for the view_deliveries_page function
            $_GET['delivery_id'] = $_GET['view_delivery'];
            self::view_deliveries_page();
            return;
        }

        // No JavaScript needed - pure PHP form submission

        // Initialize variables for modal (in case it's shown in the main deliveries list)
        $delivery_id = 0; // Default for main list view
        $customers = tholaMaCustomer();
        $customers_encoded = base64_encode(json_encode($customers));
        $form_action = admin_url('admin-post.php?action=add_waybill_action');

        $deliveries = self::get_all_deliveries();

        // Get delivery statistics
        $total_deliveries     = count($deliveries);
        $scheduled_deliveries = array_filter($deliveries, function ($d) {
            return $d->status === 'scheduled';
        });
        $scheduled_count       = count($scheduled_deliveries);
        $in_transit_deliveries = array_filter($deliveries, function ($d) {
            return $d->status === 'in_transit';
        });
        $in_transit_count     = count($in_transit_deliveries);
        $delivered_deliveries = array_filter($deliveries, function ($d) {
            return $d->status === 'delivered';
        });
        $delivered_count     = count($delivered_deliveries);
        $delivered_countries = array_unique(array_column($deliveries, 'destination_country_name'));
        $countries_count     = count($delivered_countries); ?>

        <div class="wrap deliveries-page">
            <div class="<?php echo KIT_Commons::containerClasses(); ?>">
                <?php
                // Nonce for Edit Delivery modal AJAX (get_delivery) – required when #delivery-form / #edit-delivery-form not present (e.g. user cannot create)
                $deliveries_ajax_nonce = wp_create_nonce('get_waybills_nonce');
                ?>
                <input type="hidden" id="deliveries-ajax-nonce" value="<?php echo esc_attr($deliveries_ajax_nonce); ?>" data-nonce-action="get_waybills_nonce" />
                <?php
                $can_create = class_exists('KIT_User_Roles') && KIT_User_Roles::can_create_delivery_truck();
                if ($can_create) {
                    $delivery_form_content = self::deliveryForm(null, true);
                    $add_delivery_modal = KIT_Modal::render(
                        'add-delivery-truck-modal',
                        'Add Delivery Truck',
                        $delivery_form_content,
                        '4xl',
                        true,
                        'Add Delivery Truck'
                    );
                    $header_content = $add_delivery_modal;
                } else {
                    $header_content = '<p class="text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2 text-sm">' . esc_html__('Request management to create a delivery truck.', '08600-services-quotations') . '</p>';
                }
                echo KIT_Commons::showingHeader([
                    'title'   => 'Deliveries Management',
                    'desc'    => 'Manage and track all delivery operations',
                    'icon'    => KIT_Commons::icon('truck'),
                    'content' => $header_content,
                ]);
                ?>

                <!-- Statistics Cards -->
                <?php
                $delivery_stats = [
                    [
                        'title' => 'Total Deliveries',
                        'value' => number_format($total_deliveries),
                        'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                        'color' => 'blue',
                        'class' => 'deliveries-stats-total'
                    ],
                    [
                        'title' => 'Scheduled',
                        'value' => number_format($scheduled_count),
                        'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                        'color' => 'green',
                        'class' => 'deliveries-stats-scheduled'
                    ],
                    [
                        'title' => 'In Transit',
                        'value' => number_format($in_transit_count),
                        'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
                        'color' => 'yellow',
                        'class' => 'deliveries-stats-in-transit'
                    ],
                    [
                        'title' => 'Countries Served',
                        'value' => number_format($countries_count),
                        'icon' => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                        'color' => 'purple',
                        'class' => 'deliveries-stats-countries'
                    ]
                ];

                // Render stats
                echo KIT_QuickStats::render($delivery_stats, '', [
                    'grid_cols' => 'grid-cols-2 md:grid-cols-2 lg:grid-cols-4',
                    'gap' => 'gap-6'
                ]);
                ?>

                <!-- Tabbed Interface -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200">


                    <!-- Tab Content -->
                    <div class="p-6">
                        <!-- Tab 1: Show All Deliveries -->
                        <!-- Table View -->
                        <div id="table-view">
                            <?php
                            // Convert deliveries to array format for unified table
                            $deliveries_data = [];
                            foreach ($deliveries as $delivery) {
                                // Combine route and dispatch date
                                $origin = $delivery->origin_country_name ?? 'N/A';
                                $dest = $delivery->destination_country_name ?? 'N/A';
                                $route = "$origin → $dest";

                                $dispatch_date = 'N/A';
                                if (!empty($delivery->dispatch_date) && $delivery->dispatch_date !== '0000-00-00') {
                                    $dispatch_date = date('M j, Y', strtotime($delivery->dispatch_date));
                                }
                                $route_with_date = $route . '<br><span class="text-xs text-gray-500">' . esc_html($dispatch_date) . '</span>';

                                $deliveries_data[] = [
                                    'id' => $delivery->id,
                                    'delivery_reference' => $delivery->delivery_reference ?? 'N/A',
                                    'status' => $delivery->status ?? 'unknown',
                                    'route' => $route,
                                    'dispatch_date' => $dispatch_date,
                                    'route_with_date' => $route_with_date,
                                    'truck_number' => $delivery->truck_number ?? 'N/A',
                                    'driver_name' => $delivery->driver_name ?? 'N/A',
                                    'waybill_count' => $delivery->waybill_count ?? 0,
                                ];
                            }

                            // Define columns for the unified table with proper widths
                            $columns = [
                                'delivery_reference' => [
                                    'label' => 'Reference',
                                    'header_class' => 'min-w-[180px]',
                                    'cell_class' => 'min-w-[180px]',
                                    'callback' => function ($value, $row) {
                                        $status = $row['status'] ?? 'unknown';
                                        $status_class = match ($status) {
                                            'scheduled'  => 'bg-blue-100 text-blue-800',
                                            'in_transit' => 'bg-yellow-100 text-yellow-800',
                                            'delivered'  => 'bg-green-100 text-green-800',
                                            'cancelled'  => 'bg-red-100 text-red-800',
                                            default      => 'bg-gray-100 text-gray-800'
                                        };
                                        $status_text = ucfirst($status);

                                        return '<div>
                                        <div class="text-sm font-medium text-gray-900">' . esc_html($value) . '</div>
                                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full mt-1 ' . $status_class . '">
                                            ' . esc_html($status_text) . '
                                        </span>
                                    </div>';
                                    }
                                ],
                                'route_with_date' => [
                                    'label' => 'Route & Dispatch Date',
                                    'header_class' => 'min-w-[300px] whitespace-normal',
                                    'cell_class' => 'min-w-[300px] whitespace-normal',
                                    'callback' => function ($value, $row) {
                                        return '<div class="text-sm text-gray-900 whitespace-normal leading-relaxed">' . $value . '</div>';
                                    }
                                ],
                                'truck_number' => [
                                    'label' => 'Truck',
                                    'header_class' => 'min-w-[100px]',
                                    'cell_class' => 'min-w-[100px]'
                                ],
                                'driver_name' => [
                                    'label' => 'Driver',
                                    'header_class' => 'min-w-[120px]',
                                    'cell_class' => 'min-w-[120px]'
                                ],
                                'waybill_count' => [
                                    'label' => 'Waybills',
                                    'header_class' => 'min-w-[80px] text-center',
                                    'cell_class' => 'min-w-[80px] text-center'
                                ],
                            ];

                            // Define actions for the table
                            $actions = [
                                [
                                    'label' => 'View',
                                    'href' => admin_url('admin.php?page=view-deliveries&delivery_id={id}'),
                                    'class' => 'inline-flex items-center px-2.5 py-1.5 text-sm font-medium text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md transition-colors'
                                ],
                                [
                                    'label' => 'Edit',
                                    'callback' => function ($href, $row) {
                                        $delivery_id = is_array($row) ? ($row['id'] ?? '') : (is_object($row) ? ($row->id ?? '') : '');
                                        return '#';
                                    },
                                    'class' => 'inline-flex items-center px-2.5 py-1.5 text-sm font-medium text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md transition-colors cursor-pointer',
                                    'onclick' => 'return editDelivery({id});'
                                ],
                                [
                                    'label' => 'Delete',
                                    'callback' => function ($href, $row) {
                                        $delivery_id = is_array($row) ? ($row['id'] ?? '') : (is_object($row) ? ($row->id ?? '') : '');
                                        return 'javascript:void(0)';
                                    },
                                    'class' => 'inline-flex items-center px-2.5 py-1.5 text-sm font-medium text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors cursor-pointer',
                                    'onclick' => 'deleteDelivery({id}, event); return false;'
                                ]
                            ];

                            // Render table with pretty heading
                            echo KIT_Commons::prettyHeading([
                                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>',
                                'words' => 'All Deliveries',
                                'size' => 'lg',
                                'color' => 'blue',
                                'classes' => 'mb-4'
                            ]);

                            // Render unified table with dynamic column layout
                            echo KIT_Unified_Table::infinite($deliveries_data, $columns, [
                                'title' => 'All Deliveries',
                                'sync_entity' => 'deliveries',
                                'actions' => $actions,
                                'searchable' => true,
                                'sortable' => true,
                                'pagination' => true,
                                'items_per_page' => 20,
                                'empty_message' => 'No deliveries found.',
                                'search_placeholder' => 'Search deliveries...',
                                'search_filters' => [
                                    ['value' => 'delivery_reference', 'label' => 'Reference', 'placeholder' => 'Search by reference...'],
                                    ['value' => 'route', 'label' => 'Route', 'placeholder' => 'Search by route...'],
                                    ['value' => 'truck_number', 'label' => 'Truck', 'placeholder' => 'Search by truck...'],
                                    ['value' => 'driver_name', 'label' => 'Driver', 'placeholder' => 'Search by driver...']
                                ],
                                'search_default_filter' => 'delivery_reference',
                                'table_class' => 'w-full table-auto border-collapse', // Changed from table-fixed to table-auto for dynamic columns
                                'actions_cell_class' => 'px-3 py-2 text-sm font-medium text-gray-900 whitespace-nowrap align-top w-[180px] min-w-[180px] flex items-center gap-2', // Fixed width for actions column with flex layout
                                'row_attrs_callback' => function ($row, $rowIndex) {
                                    $id = is_array($row) ? ($row['id'] ?? '') : (is_object($row) ? ($row->id ?? '') : '');
                                    return ['data-delivery-id' => $id];
                                }
                            ]);
                            ?>
                            <script>
                                // Replace {id} placeholders in onclick handlers after table render
                                jQuery(document).ready(function($) {
                                    $('a[onclick*="{id}"]').each(function() {
                                        var $link = $(this);
                                        var onclick = $link.attr('onclick');
                                        // Get ID from row's data attribute
                                        var $row = $link.closest('tr');
                                        var id = $row.data('delivery-id') || '';
                                        // Fallback: try to get ID from href if it has delivery_id
                                        if (!id) {
                                            var href = $link.attr('href');
                                            if (href && href.indexOf('delivery_id=') !== -1) {
                                                var match = href.match(/delivery_id=(\d+)/);
                                                if (match) id = match[1];
                                            }
                                        }
                                        // Replace {id} in onclick
                                        if (onclick && id) {
                                            onclick = onclick.replace(/{id}/g, id);
                                            $link.attr('onclick', onclick);
                                        }
                                    });
                                });
                            </script>
                        </div>
                    </div>
                </div>


                <!-- Quick Actions Section - Bottom of Page -->
                <div class="mt-8">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <a href="?page=08600-waybill-create"
                                class="flex items-center p-4 bg-blue-50 hover:bg-blue-100 rounded-lg border border-blue-200 transition-colors group">
                                <div
                                    class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3 group-hover:bg-blue-200">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                                        </path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900">Create Waybill</h4>
                                    <p class="text-sm text-gray-600">Generate new waybill</p>
                                </div>
                            </a>

                            <a href="?page=08600-customers"
                                class="flex items-center p-4 bg-green-50 hover:bg-green-100 rounded-lg border border-green-200 transition-colors group">
                                <div
                                    class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3 group-hover:bg-green-200">
                                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                                        </path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900">Manage Customers</h4>
                                    <p class="text-sm text-gray-600">View and edit customers</p>
                                </div>
                            </a>

                            <a href="?page=route-management"
                                class="flex items-center p-4 bg-purple-50 hover:bg-purple-100 rounded-lg border border-purple-200 transition-colors group">
                                <div
                                    class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mr-3 group-hover:bg-purple-200">
                                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-1.447-.894L15 4m0 13V4m-6 3l6-3">
                                        </path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900">Manage Routes</h4>
                                    <p class="text-sm text-gray-600">Configure shipping routes</p>
                                </div>
                            </a>

                            <a href="?page=warehouse-waybills"
                                class="flex items-center p-4 bg-orange-50 hover:bg-orange-100 rounded-lg border border-orange-200 transition-colors group">
                                <div
                                    class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mr-3 group-hover:bg-orange-200">
                                    <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                                        </path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900">Warehouse</h4>
                                    <p class="text-sm text-gray-600">Manage warehouse waybills</p>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .tab-content {
                display: none;
            }

            .tab-content.active {
                display: block !important;
            }

            .tab-button {
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .tab-button.active {
                border-bottom-color: #3b82f6 !important;
                color: #2563eb !important;
            }
        </style>

        <?php
        $edit_delivery_modal_bootstrap = function_exists('kit_using_employee_portal') && kit_using_employee_portal();
        ?>
        <!-- Edit Delivery Modal (Bootstrap on frontend / employee dashboard, custom in admin) -->
        <?php if ($edit_delivery_modal_bootstrap) : ?>
            <div id="edit-delivery-modal" class="modal fade" tabindex="-1" aria-labelledby="edit-delivery-modal-label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="edit-delivery-modal-label">Edit Delivery</h5>
                            <?php
                            echo KIT_Commons::renderButton(
                                '',
                                'ghost',
                                'lg',
                                [
                                    'type'            => 'button',
                                    'id'              => 'close-modal',
                                    'classes'         => 'btn-close-placeholder',
                                    'ariaLabel'       => __('Close modal', 'courier-finance-plugin'),
                                    'iconOnly'        => true,
                                    'icon'            => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />',
                                    'data-bs-dismiss' => 'modal',
                                ]
                            );
                            ?>
                        </div>
                        <div class="modal-body">
                            <div id="modal-content" class="overflow-y-auto">
                                <div class="flex items-center justify-center py-12">
                                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <div id="edit-delivery-modal"
                class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4"
                style="display: none;">
                <div class="relative mx-auto w-11/12 md:w-3/4 lg:w-1/2 shadow-xl rounded-xl bg-white max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between p-6 border-b border-gray-200">
                        <h3 class="text-xl font-semibold text-gray-900" id="modal-title">Edit Delivery</h3>
                        <?php
                        echo KIT_Commons::renderButton(
                            '',
                            'ghost',
                            'lg',
                            [
                                'type'        => 'button',
                                'id'          => 'close-modal',
                                'classes'     => 'w-10 h-10 p-0 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 justify-center',
                                'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />',
                                'iconPosition' => 'left',
                                'ariaLabel'   => __('Close modal', 'courier-finance-plugin'),
                            ]
                        );
                        ?>
                    </div>
                    <div class="p-6">
                        <div id="modal-content" class="overflow-y-auto">
                            <div class="flex items-center justify-center py-12">
                                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <script>
            // Define ajaxurl for WordPress admin
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var editDeliveryModalBootstrap = <?php echo $edit_delivery_modal_bootstrap ? 'true' : 'false'; ?>;

            function closeEditDeliveryModal() {
                if (editDeliveryModalBootstrap && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var el = document.getElementById('edit-delivery-modal');
                    if (el) try {
                        bootstrap.Modal.getOrCreateInstance(el).hide();
                    } catch (err) {}
                } else {
                    $('#edit-delivery-modal').addClass('hidden').hide();
                }
            }

            // Tab functionality
            document.addEventListener('DOMContentLoaded', function() {
                // Ensure Edit Delivery modal stays closed on load (custom modal only; Bootstrap handles its own)
                if (!editDeliveryModalBootstrap) {
                    var editModal = document.getElementById('edit-delivery-modal');
                    if (editModal) {
                        editModal.style.display = 'none';
                        editModal.classList.add('hidden');
                    }
                }

                const tabButtons = document.querySelectorAll('.tab-button');
                const tabContents = document.querySelectorAll('.tab-content');

                function switchTab(tabId) {
                    // Hide all tab contents
                    tabContents.forEach(content => {
                        content.style.display = 'none';
                        content.classList.add('hidden');
                        content.classList.remove('active');
                    });

                    // Remove active class from all tab buttons
                    tabButtons.forEach(button => {
                        button.classList.remove('active', 'border-blue-500', 'text-blue-600');
                        button.classList.add('border-transparent', 'text-gray-500');
                    });

                    // Show selected tab content
                    const selectedContent = document.getElementById('tab-content-' + tabId);
                    if (selectedContent) {
                        selectedContent.style.display = 'block';
                        selectedContent.classList.remove('hidden');
                        selectedContent.classList.add('active');
                    }

                    // Activate selected tab button
                    const selectedButton = document.getElementById('tab-' + tabId);
                    if (selectedButton) {
                        selectedButton.classList.add('active', 'border-blue-500', 'text-blue-600');
                        selectedButton.classList.remove('border-transparent', 'text-gray-500');
                    }
                }

                // Add click event listeners to tab buttons
                tabButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const tabId = this.id.replace('tab-', '');
                        switchTab(tabId);
                    });
                });

                // Initialize with first tab active
                switchTab('all-deliveries');

                // Debug: Log tab elements to console
                console.log('Tab buttons found:', tabButtons.length);
                console.log('Tab contents found:', tabContents.length);
            });



            jQuery(document).ready(function($) {
                // Ensure Edit Delivery modal is closed on page load (custom modal only)
                if (!editDeliveryModalBootstrap) {
                    $('#edit-delivery-modal').addClass('hidden').hide().css('display', 'none');
                }

                // Allow past dates for catch-up delivery creation
                // No minimum date restriction - allow past dates

                // Handle form submission
                $('#delivery-form').on('submit', function(e) {
                    e.preventDefault();

                    // Basic form validation
                    const requiredFields = ['origin_country', 'destination_country', 'dispatch_date',
                        'truck_number'
                    ];
                    let isValid = true;

                    requiredFields.forEach(function(fieldName) {
                        const field = document.querySelector(`[name="${fieldName}"]`);
                        if (!field.value.trim()) {
                            field.classList.add('border-red-500');
                            isValid = false;
                        } else {
                            field.classList.remove('border-red-500');
                        }
                    });

                    if (!isValid) {
                        alert('Please fill in all required fields.');
                        return;
                    }

                    const formData = $(this).serializeArray();
                    formData.push({
                        name: 'task',
                        value: $('#delivery_id').val() === '0' ? 'create_delivery' : 'update_delivery'
                    });

                    // Show loading state
                    $('#save-delivery-btn').prop('disabled', true).text('Saving...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: formData,
                        dataType: 'json',
                        success: function(response) {
                            console.log('AJAX Response:', response);
                            if (response.success) {
                                // Show success message
                                const successMsg = $(
                                    '<div class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50">Delivery saved successfully!</div>'
                                );
                                $('body').append(successMsg);
                                setTimeout(function() {
                                    successMsg.fadeOut(function() {
                                        $(this).remove();
                                    });
                                    // Refresh the table via AJAX instead of reloading the page
                                    refreshDeliveriesTable();
                                    closeEditDeliveryModal();
                                }, 1000);
                            } else {
                                alert('Error: ' + (response.data || 'Unknown error occurred'));
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', error);
                            console.error('Response:', xhr.responseText);
                            alert('Network error occurred. Please try again.');
                        },
                        complete: function() {
                            $('#save-delivery-btn').prop('disabled', false).text('Save Delivery');
                        }
                    });
                });

                // Handle "Add Delivery" button
                $('#add-delivery-btn, #create-first-delivery').on('click', function() {
                    // Switch to add delivery tab
                    switchTab('add-delivery');
                    $('#delivery-form input:first').focus();
                });

                // Test country change functionality
                $('#test-country-change').on('click', function() {
                    console.log('Testing country change...');
                    const originCountrySelect = document.getElementById('origin_country_select');
                    if (originCountrySelect && originCountrySelect.value) {
                        handleCountryChange(originCountrySelect.value, 'origin');
                    } else {
                        alert('Please select a country first');
                    }
                });

                // Handle edit delivery
                window.editDelivery = function(deliveryId) {
                    // Prevent call without delivery ID
                    if (!deliveryId || deliveryId === '' || deliveryId === '0') {
                        console.error('Cannot edit delivery: delivery ID is required');
                        return false;
                    }

                    console.log('Opening modal for delivery ID:', deliveryId);

                    // Show modal (Bootstrap on frontend / employee dashboard, custom in admin)
                    if (editDeliveryModalBootstrap && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        var modalEl = document.getElementById('edit-delivery-modal');
                        if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    } else {
                        $('#edit-delivery-modal').removeClass('hidden').show();
                    }

                    // Get the AJAX URL - try multiple sources (frontend portal has window.ajaxurl set for kit-deliveries)
                    const ajaxUrl = window.ajaxurl || (window.myPluginAjax && window.myPluginAjax.ajax_url) ||
                        '/wp-admin/admin-ajax.php';
                    // Nonce: from add form if present, else from page-level hidden input (required on frontend when user cannot create)
                    const nonce = $('#delivery-form input[name="nonce"]').val() ||
                        $('#edit-delivery-form input[name="nonce"]').val() ||
                        $('#deliveries-ajax-nonce').val() ||
                        '';
                    if (!nonce) {
                        console.error('Edit Delivery: nonce not found. Ensure #deliveries-ajax-nonce or delivery form is on the page.');
                    }
                    console.log('Using AJAX URL:', ajaxUrl);

                    // Load delivery data via AJAX
                    $.ajax({
                        url: ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'kit_deliveries_crud',
                            task: 'get_delivery',
                            id: deliveryId,
                            nonce: nonce
                        },
                        success: function(response) {
                            console.log('AJAX response:', response);
                            if (response.success) {
                                // Load the delivery form in the modal
                                loadDeliveryFormInModal(response.data, deliveryId);
                            } else {
                                alert('Error loading delivery data: ' + (response.data ||
                                    'Unknown error'));
                                closeEditDeliveryModal();
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX error:', error);
                            console.error('Status:', status);
                            console.error('Response:', xhr.responseText);
                            console.error('XHR:', xhr);
                            alert(
                                'Network error occurred while loading delivery data. Please check the console for details.');
                            closeEditDeliveryModal();
                        }
                    });
                    return false; // Prevent link default (href="#" would add # to URL)
                };

                // Function to load delivery form in modal
                function loadDeliveryFormInModal(delivery, deliveryId) {
                    // Escape function to prevent template literal injection
                    function escapeHtml(str) {
                        if (!str) return '';
                        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                    }

                    // Escape values for template literal (prevent backtick and ${ injection)
                    function escapeTemplate(str) {
                        if (!str) return '';
                        return String(str).replace(/\\/g, '\\\\').replace(/`/g, '\\`').replace(/\${/g, '\\${');
                    }

                    // Create form HTML
                    const formHtml = `
                        <form id="modal-delivery-form" class="space-y-8" method="post" action="${ajaxurl.replace('admin-ajax.php', 'admin-post.php')}">
                            <input type="hidden" name="action" value="kit_deliveries_crud">
                            <input type="hidden" name="delivery_id" value="${deliveryId}">
                            <input type="hidden" name="task" value="update_delivery">
                            <input type="hidden" name="nonce" value="${escapeHtml($('#delivery-form input[name="nonce"]').val() || $('#edit-delivery-form input[name="nonce"]').val() || $('#deliveries-ajax-nonce').val() || '')}">

                            <!-- Reference Number -->
                            <div class="form-field">
                                <label class="block text-sm font-medium text-gray-700 mb-3">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        Reference Number
                                    </span>
                                </label>
                                <input type="text" name="delivery_reference" value="${escapeHtml(delivery.delivery_reference || '')}" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-gray-50 text-gray-700 font-mono text-sm" readonly>
                                <p class="mt-2 text-xs text-gray-500">Auto-generated reference number</p>
                            </div>

                            <!-- Route Information -->
                            <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
                                <h3 class="text-base font-semibold text-gray-800 mb-6 flex items-center">
                                    <svg class="w-5 h-5 mr-3 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    Route Information
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Origin -->
                                    <div class="form-field">
                                        <label class="block text-sm font-medium text-gray-700 mb-3">
                                            <span class="flex items-center">
                                                <svg class="w-4 h-4 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                Origin Country
                                            </span>
                                        </label>
                                        <select name="origin_country" class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                                            <option value="">Select Origin Country</option>
                                            <option value="1" ${delivery.origin_country_id == 1 ? 'selected' : ''}>South Africa</option>
                                            <option value="2" ${delivery.origin_country_id == 2 ? 'selected' : ''}>Zimbabwe</option>
                                            <option value="3" ${delivery.origin_country_id == 3 ? 'selected' : ''}>Zambia</option>
                                        </select>
                                    </div>

                                    <!-- Destination -->
                                    <div class="form-field">
                                        <label class="block text-sm font-medium text-gray-700 mb-3">
                                            <span class="flex items-center">
                                                <svg class="w-4 h-4 mr-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                </svg>
                                                Destination Country
                                            </span>
                                        </label>
                                        <select name="destination_country" onchange="handleCountryChange(this.value, 'destination')" class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                                            <option value="">Select Destination Country</option>
                                            <option value="1" ${delivery.destination_country_id == 1 ? 'selected' : ''}>South Africa</option>
                                            <option value="2" ${delivery.destination_country_id == 2 ? 'selected' : ''}>Zimbabwe</option>
                                            <option value="3" ${delivery.destination_country_id == 3 ? 'selected' : ''}>Zambia</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Destination City -->
                                <div class="form-field mt-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-3">
                                        <span class="flex items-center">
                                            <svg class="w-4 h-4 mr-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                            Destination City
                                        </span>
                                    </label>
                                    <select name="destination_city" id="destination_city_select" class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                                        <option value="">Select Destination City</option>
                                        <!-- Cities will be loaded dynamically based on selected country -->
                                    </select>
                                </div>
                            </div>

                            <!-- Delivery Details -->
                            <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
                                <h3 class="text-base font-semibold text-gray-800 mb-6 flex items-center">
                                    <svg class="w-5 h-5 mr-3 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    Delivery Details
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Dispatch Date -->
                                    <div class="form-field">
                                        <label class="block text-sm font-medium text-gray-700 mb-3">
                                            <span class="flex items-center">
                                                <svg class="w-4 h-4 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                </svg>
                                                Dispatch Date
                                            </span>
                                        </label>
                                        <input type="date" name="dispatch_date" value="${escapeHtml(delivery.dispatch_date || '')}" required
                                               class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors bg-white"
                                               data-allow-past-dates="true">
                                    </div>

                                    <!-- Truck Number -->
                                    <div class="form-field">
                                        <label class="block text-sm font-medium text-gray-700 mb-3">
                                            <span class="flex items-center">
                                                <svg class="w-4 h-4 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                </svg>
                                                Truck Number
                                            </span>
                                        </label>
                                        <input type="text" name="truck_number" value="${escapeHtml(delivery.truck_number || '')}" required
                                               class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors bg-white"
                                               placeholder="Enter truck number">
                                    </div>
                                </div>

                                <!-- Status -->
                                <div class="form-field mt-6">
                                    <label class="block text-sm font-medium text-gray-700 mb-3">
                                        <span class="flex items-center">
                                            <svg class="w-4 h-4 mr-2 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Delivery Status
                                        </span>
                                    </label>
                                    <select name="status" class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                                        <option value="scheduled" ${delivery.status === 'scheduled' ? 'selected' : ''}>Scheduled</option>
                                        <option value="in_transit" ${delivery.status === 'in_transit' ? 'selected' : ''}>In Transit</option>
                                        <option value="delivered" ${delivery.status === 'delivered' ? 'selected' : ''}>Delivered</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="flex gap-4 pt-8 border-t border-gray-200">
                                <button type="submit" id="modal-save-btn"
                                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-6 py-4 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center space-x-3 disabled:opacity-50 disabled:cursor-not-allowed">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <span>Update Delivery</span>
                                </button>
                                <button type="button" id="modal-cancel-btn"
                                    class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-4 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center space-x-3">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    <span>Cancel</span>
                                </button>
                            </div>
                        </form>
                    `;

                    $('#modal-content').html(formHtml);

                    // Load cities for destination country and set selected city
                    if (delivery.destination_country_id && delivery.destination_city_id) {
                        // Wait for DOM to be ready, then load cities
                        setTimeout(function() {
                            const citySelect = document.getElementById('destination_city_select');
                            if (!citySelect) return;

                            const destinationCityId = delivery.destination_city_id;
                            const destinationCountryId = delivery.destination_country_id;

                            // Function to set selected city once options are available
                            function setSelectedCity() {
                                if (citySelect.options.length > 1) { // More than just "Select City"
                                    citySelect.value = destinationCityId;
                                    // Verify it was set correctly
                                    if (citySelect.value == destinationCityId) {
                                        console.log('Destination city set to:', destinationCityId);
                                        return true;
                                    }
                                }
                                return false;
                            }

                            // Try using handleCountryChange first (uses preloaded cities)
                            if (typeof handleCountryChange === 'function') {
                                handleCountryChange(destinationCountryId, 'destination');

                                // Try to set immediately, then poll if needed
                                if (!setSelectedCity()) {
                                    let attempts = 0;
                                    const maxAttempts = 10;
                                    const checkInterval = setInterval(function() {
                                        attempts++;
                                        if (setSelectedCity() || attempts >= maxAttempts) {
                                            clearInterval(checkInterval);
                                        }
                                    }, 100);
                                }
                            }

                            // Also load via AJAX as fallback/backup
                            const ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
                            const nonce = $('#delivery-form input[name="nonce"]').val() || $('#edit-delivery-form input[name="nonce"]').val() || $('#deliveries-ajax-nonce').val() || $('#modal-delivery-form input[name="nonce"]').val() || '';

                            $.ajax({
                                url: ajaxUrl,
                                type: 'POST',
                                data: {
                                    action: 'handle_get_cities_for_country',
                                    country_id: destinationCountryId,
                                    nonce: nonce
                                },
                                success: function(response) {
                                    if (response.success && Array.isArray(response.data) && response.data.length > 0) {
                                        citySelect.innerHTML = '<option value="">Select City</option>';
                                        response.data.forEach(function(city) {
                                            const option = document.createElement('option');
                                            option.value = city.id;
                                            option.textContent = city.city_name;
                                            if (city.id == destinationCityId) {
                                                option.selected = true;
                                            }
                                            citySelect.appendChild(option);
                                        });
                                        // Ensure the correct city is selected
                                        citySelect.value = destinationCityId;
                                        console.log('Cities loaded via AJAX, city set to:', destinationCityId);
                                    }
                                },
                                error: function() {
                                    console.error('Error loading cities for destination country');
                                }
                            });
                        }, 100);
                    }

                    // Remove date restriction to allow editing old deliveries
                    // Use multiple attempts to catch the input after DOM insertion
                    function removeDateRestriction() {
                        const modalDateInput = document.querySelector(
                            '#modal-delivery-form input[name="dispatch_date"]');
                        if (modalDateInput) {
                            modalDateInput.removeAttribute('min');
                            modalDateInput.removeAttribute('data-min');
                            // Also prevent browser from setting min based on HTML5 validation
                            if (modalDateInput.hasAttribute('min')) {
                                modalDateInput.removeAttribute('min');
                            }
                            console.log('Date restriction removed from modal dispatch_date input');
                        }
                    }

                    // Try immediately, then after short delay, and also on input focus
                    removeDateRestriction();
                    setTimeout(removeDateRestriction, 50);
                    setTimeout(removeDateRestriction, 200);

                    // Also remove on focus/click to catch any late-set restrictions
                    $(document).on('focus click', '#modal-delivery-form input[name="dispatch_date"]', function() {
                        const $input = $(this);
                        $input.removeAttr('min').removeAttr('data-min');
                        // Force remove any min attribute that might have been set
                        this.removeAttribute('min');
                    });

                    // Monitor for any attempts to set min attribute using MutationObserver
                    setTimeout(function() {
                        const modalDateInput = document.querySelector(
                            '#modal-delivery-form input[name="dispatch_date"]');
                        if (modalDateInput) {
                            const observer = new MutationObserver(function(mutations) {
                                mutations.forEach(function(mutation) {
                                    if (mutation.type === 'attributes' && mutation
                                        .attributeName === 'min') {
                                        const target = mutation.target;
                                        if (target.hasAttribute('min')) {
                                            target.removeAttribute('min');
                                            console.log(
                                                'Prevented min date restriction from being set'
                                            );
                                        }
                                    }
                                });
                            });

                            observer.observe(modalDateInput, {
                                attributes: true,
                                attributeFilter: ['min']
                            });
                        }
                    }, 100);

                    // Handle modal form submission - now using regular form submission
                    $('#modal-delivery-form').on('submit', function(e) {
                        // Show loading state
                        const $btn = $('#modal-save-btn');
                        const originalText = $btn.find('span').text();
                        $btn.prop('disabled', true).find('span').text('Saving...');

                        // Let the form submit normally - no preventDefault()
                        // The form will submit to admin-post.php and redirect back
                    });

                    // Handle cancel button
                    $('#modal-cancel-btn').on('click', function() {
                        closeEditDeliveryModal();
                    });
                }

                // Handle modal close (#close-modal has data-bs-dismiss on Bootstrap, but still bind for custom)
                $('#close-modal').on('click', function() {
                    closeEditDeliveryModal();
                });

                // Close modal when clicking outside (custom modal only; Bootstrap handles backdrop)
                $('#edit-delivery-modal').on('click', function(e) {
                    if (e.target === this) closeEditDeliveryModal();
                });

                // Function to refresh deliveries table via AJAX
                window.refreshDeliveriesTable = function() {
                    const tableBody = $('#deliveries-table-body');
                    if (!tableBody.length) {
                        console.warn('Deliveries table body not found');
                        return;
                    }

                    // Show loading indicator
                    tableBody.html('<tr><td colspan="8" class="px-6 py-4 text-center text-gray-500">Loading...</td></tr>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'refresh_deliveries_table',
                            nonce: '<?php echo wp_create_nonce('get_waybills_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data && response.data.html) {
                                tableBody.html(response.data.html);
                                // Update statistics if provided
                                if (response.data.stats) {
                                    updateStatistics(response.data.stats);
                                }
                            } else {
                                tableBody.html('<tr><td colspan="8" class="px-6 py-4 text-center text-red-500">Error loading deliveries</td></tr>');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error refreshing table:', error);
                            tableBody.html('<tr><td colspan="8" class="px-6 py-4 text-center text-red-500">Error loading deliveries</td></tr>');
                        }
                    });
                };

                // Function to update statistics cards
                function updateStatistics(stats) {
                    if (stats.total !== undefined) {
                        $('.deliveries-stats-total').text(stats.total);
                    }
                    if (stats.scheduled !== undefined) {
                        $('.deliveries-stats-scheduled').text(stats.scheduled);
                    }
                    if (stats.in_transit !== undefined) {
                        $('.deliveries-stats-in-transit').text(stats.in_transit);
                    }
                    if (stats.countries !== undefined) {
                        $('.deliveries-stats-countries').text(stats.countries);
                    }
                }

                // Handle delete delivery - define early so it's available when onclick attributes are parsed
                window.deleteDelivery = window.deleteDelivery || function(deliveryId, event) {
                    if (confirm(
                            'Are you sure you want to delete this delivery? This will also delete all associated waybills and items. A backup will be created automatically.'
                        )) {
                        // Find the row containing this delete button
                        const deleteBtn = event ? event.target : $('button[onclick*="deleteDelivery(' + deliveryId + ')"]')[0];
                        const $deleteBtn = $(deleteBtn);
                        const $row = $deleteBtn.closest('tr');

                        // Show visual feedback on the row being deleted
                        $row.css({
                            'opacity': '0.5',
                            'background-color': '#fee2e2',
                            'transition': 'all 0.3s ease'
                        });

                        // Disable all buttons in this row
                        $row.find('button').prop('disabled', true);
                        const originalText = $deleteBtn.html();
                        $deleteBtn.html('Deleting...');

                        // Make AJAX request to delete delivery
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'kit_deliveries_crud',
                                task: 'delete_delivery',
                                id: deliveryId,
                                nonce: '<?php echo wp_create_nonce('get_waybills_nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Show success message with backup info
                                    const backupInfo = response.data.backup || response.data;
                                    const backupMsg = backupInfo ?
                                        '\\n\\nBackup created:\\n- File: ' + (backupInfo.file || 'N/A') +
                                        '\\n- Waybills: ' + (backupInfo.waybills_count || 0) +
                                        '\\n- Items: ' + (backupInfo.items_count || 0) : '';
                                    alert('Delivery deleted successfully!' + backupMsg);

                                    // Fade out and remove the specific row
                                    $row.fadeOut(500, function() {
                                        $(this).remove();
                                    });
                                } else {
                                    // Restore row appearance on error
                                    $row.css({
                                        'opacity': '1',
                                        'background-color': '',
                                    });
                                    $row.find('button').prop('disabled', false);
                                    $deleteBtn.html(originalText);
                                    alert('Error deleting delivery: ' + (response.data?.message || response.data || 'Unknown error'));
                                }
                            },
                            error: function(xhr, status, error) {
                                // Restore row appearance on error
                                $row.css({
                                    'opacity': '1',
                                    'background-color': '',
                                });
                                $row.find('button').prop('disabled', false);
                                $deleteBtn.html(originalText);
                                alert('Error deleting delivery: ' + error);
                            }
                        });
                    }
                };

                // Handle edit delivery - open modal (defined earlier in this script; do not overwrite)
                if (typeof window.editDelivery === 'undefined') {
                    window.editDelivery = function(deliveryId) {
                        window.location.href = '?page=view-deliveries&delivery_id=' + deliveryId + '&edit_delivery=1';
                    };
                }

                // Add visual feedback for form interactions
                $('input, select').on('focus', function() {
                    $(this).parent().addClass('ring-2 ring-blue-500 ring-opacity-50');
                }).on('blur', function() {
                    $(this).parent().removeClass('ring-2 ring-blue-500 ring-opacity-50');
                });

                // View toggle functionality
                $('#block-view-btn').on('click', function() {
                    $('#block-view').removeClass('hidden');
                    $('#table-view').addClass('hidden');
                    $('#block-view-btn').removeClass('bg-gray-200 text-gray-700').addClass(
                        'bg-blue-600 text-white');
                    $('#table-view-btn').removeClass('bg-blue-600 text-white').addClass(
                        'bg-gray-200 text-gray-700');
                });

                $('#table-view-btn').on('click', function() {
                    $('#table-view').removeClass('hidden');
                    $('#block-view').addClass('hidden');
                    $('#table-view-btn').removeClass('bg-gray-200 text-gray-700').addClass(
                        'bg-blue-600 text-white');
                    $('#block-view-btn').removeClass('bg-blue-600 text-white').addClass(
                        'bg-gray-200 text-gray-700');
                });


            });
        </script>
    <?php
    }
    public static function updateShippingDirection()
    {
        global $wpdb;

        // Check if this is a regular form submission (not AJAX)
        if (! wp_doing_ajax()) {
            // Handle regular form submission
            if (! wp_verify_nonce($_POST['nonce'], 'get_waybills_nonce')) {
                wp_die('Security check failed');
            }

            if (! current_user_can('kit_view_waybills') && ! current_user_can('edit_pages')) {
                wp_die('Unauthorized');
            }

            $task = $_POST['task'] ?? 'update_delivery';
            if ($task === 'create_delivery' && class_exists('KIT_User_Roles') && ! KIT_User_Roles::can_create_delivery_truck()) {
                $redirect = wp_get_referer() ?: admin_url('admin.php?page=kit-deliveries');
                wp_safe_redirect(add_query_arg('delivery_error', urlencode(__('Request management to create a delivery truck.', '08600-services-quotations')), $redirect));
                exit;
            }
            $data = $_POST;

            if (empty($task)) {
                wp_die('Task parameter missing');
            }

            // Process the form submission
            switch ($task) {
                case 'create_delivery':
                case 'update_delivery':
                    $result = self::save_delivery($data);
                    if ($result) {
                        // For create, redirect to view page (not edit mode). For update, redirect to edit mode.
                        $delivery_id = $task === 'update_delivery' ? $data['delivery_id'] : $result;
                        if ($task === 'create_delivery') {
                            wp_redirect(admin_url('admin.php?page=view-deliveries&delivery_id=' . $delivery_id . '&updated=1'));
                        } else {
                            wp_redirect(admin_url('admin.php?page=view-deliveries&delivery_id=' . $delivery_id . '&edit_delivery=1&updated=1'));
                        }
                        exit;
                    } else {
                        // Redirect back with error message
                        $delivery_id = $task === 'update_delivery' ? $data['delivery_id'] : '';
                        $error_url = $task === 'create_delivery'
                            ? admin_url('admin.php?page=kit-deliveries&error=1')
                            : admin_url('admin.php?page=view-deliveries&delivery_id=' . $delivery_id . '&edit_delivery=1&error=1');
                        wp_redirect($error_url);
                        exit;
                    }
                    break;
                default:
                    wp_die('Invalid task');
            }
        }

        // Handle AJAX requests (for backward compatibility)
        check_ajax_referer('get_waybills_nonce', 'nonce');

        if (! current_user_can('kit_view_waybills') && ! current_user_can('edit_pages')) {
            wp_send_json_error('Unauthorized');
        }

        $task = $_POST['task'] ?? 'create_delivery';
        $data = $_POST;

        if (empty($task)) {
            wp_send_json_error('Task parameter missing');
        }

        if ($task === 'create_delivery' && class_exists('KIT_User_Roles') && ! KIT_User_Roles::can_create_delivery_truck()) {
            wp_send_json_error(__('Request management to create a delivery truck.', '08600-services-quotations'));
        }

        switch ($task) {
            case 'create_delivery':
            case 'update_delivery':
                $result = self::save_delivery($data);
                break;

            case 'get_delivery':
                if (empty($data['id'])) {
                    wp_send_json_error('Delivery ID required');
                    return;
                }
                $result = self::get_delivery($data['id']);
                break;

            case 'delete_delivery':
                $result = self::delete_delivery($data['id']);
                break;
            case 'get_scheduled_deliveries':
                if (empty($_POST['country'])) {
                    wp_send_json_error('Country parameter missing');
                }
                $result = self::deliveries_by_CountStat(
                    sanitize_text_field($_POST['country'])
                );
                break;

            default:
                wp_send_json_error('Invalid action');
        }

        // Handle both AJAX and POST requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            // Special handling for delete_delivery which returns an array with success key
            if ($task === 'delete_delivery' && is_array($result)) {
                if (isset($result['success']) && $result['success'] === false) {
                    wp_send_json_error($result['message'] ?? 'Failed to delete delivery');
                } else {
                    wp_send_json_success($result);
                }
            } elseif ($result === false || $result === 0) {
                $error_message = 'Operation failed';
                if ($task === 'update_delivery') {
                    $error_message = 'Failed to update delivery. Please check the error log.';
                } elseif ($task === 'create_delivery') {
                    $error_message = 'Failed to create delivery. Please check the error log.';
                }
                wp_send_json_error($error_message);
            } else {
                wp_send_json_success($result);
            }
        } else {
            // For POST (admin-post.php) requests
            if ($result === false || $result === 0) {
                $error_message = 'Operation failed';
                if ($task === 'update_delivery') {
                    $error_message = 'Failed to update delivery. Please check the error log.';
                } elseif ($task === 'create_delivery') {
                    $error_message = 'Failed to create delivery. Please check the error log.';
                }
                wp_redirect(add_query_arg(['delivery_error' => urlencode($error_message)], wp_get_referer() ?: admin_url()));
                exit;
            } else {
                // Success - redirect with success message
                $success_message = 'Delivery updated successfully!';
                if ($task === 'create_delivery') {
                    $success_message = 'Delivery created successfully!';
                }
                wp_redirect(add_query_arg(['delivery_success' => '1', 'message' => urlencode($success_message)], wp_get_referer() ?: admin_url()));
                exit;
            }
        }
    }

    public static function handle_ajax()
    {

        // Check if this is a regular form submission (not AJAX)
        if (! wp_doing_ajax()) {
            // Handle regular form submission
            if (! wp_verify_nonce($_POST['nonce'], 'get_waybills_nonce')) {
                wp_die('Security check failed');
            }

            if (! current_user_can('kit_view_waybills') && ! current_user_can('edit_pages')) {
                wp_die('Unauthorized');
            }

            $task = $_POST['task'] ?? 'create_delivery';
            $data = $_POST;

            if (empty($task)) {
                wp_die('Task parameter missing');
            }

            // Process the form submission
            switch ($task) {
                case 'create_delivery':
                case 'update_delivery':
                    $result = self::save_delivery($data);
                    if ($result) {
                        // For create, redirect to view page (not edit mode). For update, redirect to edit mode.
                        $delivery_id = $task === 'update_delivery' ? $data['delivery_id'] : $result;
                        if ($task === 'create_delivery') {
                            wp_redirect(admin_url('admin.php?page=view-deliveries&delivery_id=' . $delivery_id . '&updated=1'));
                        } else {
                            wp_redirect(admin_url('admin.php?page=view-deliveries&delivery_id=' . $delivery_id . '&edit_delivery=1&updated=1'));
                        }
                        exit;
                    } else {
                        // Redirect back with error message
                        $delivery_id = $task === 'update_delivery' ? $data['delivery_id'] : '';
                        $error_url = $task === 'create_delivery'
                            ? admin_url('admin.php?page=kit-deliveries&error=1')
                            : admin_url('admin.php?page=view-deliveries&delivery_id=' . $delivery_id . '&edit_delivery=1&error=1');
                        wp_redirect($error_url);
                        exit;
                    }
                    break;
                default:
                    wp_die('Invalid task');
            }
        }

        // Handle AJAX requests (for backward compatibility)
        check_ajax_referer('get_waybills_nonce', 'nonce');

        if (! current_user_can('kit_view_waybills') && ! current_user_can('edit_pages')) {
            wp_send_json_error('Unauthorized');
        }

        $task = $_POST['task'] ?? 'create_delivery';
        $data = $_POST;

        if (empty($task)) {
            wp_send_json_error('Task parameter missing');
        }

        if ($task === 'create_delivery' && class_exists('KIT_User_Roles') && ! KIT_User_Roles::can_create_delivery_truck()) {
            wp_send_json_error(__('Request management to create a delivery truck.', '08600-services-quotations'));
        }

        switch ($task) {
            case 'create_delivery':
            case 'update_delivery':
                $result = self::save_delivery($data);
                break;

            case 'get_delivery':
                if (empty($data['id'])) {
                    wp_send_json_error('Delivery ID required');
                    return;
                }
                $result = self::get_delivery($data['id']);
                break;

            case 'delete_delivery':
                $result = self::delete_delivery($data['id']);
                break;
            case 'get_scheduled_deliveries':
                if (empty($_POST['country'])) {
                    wp_send_json_error('Country parameter missing');
                }
                $result = self::deliveries_by_CountStat(
                    sanitize_text_field($_POST['country'])
                );
                break;

            default:
                wp_send_json_error('Invalid action');
        }

        // Handle both AJAX and POST requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            // Special handling for delete_delivery which returns an array with success key
            if ($task === 'delete_delivery' && is_array($result)) {
                if (isset($result['success']) && $result['success'] === false) {
                    wp_send_json_error($result['message'] ?? 'Failed to delete delivery');
                } else {
                    wp_send_json_success($result);
                }
            } elseif ($result === false || $result === 0) {
                $error_message = 'Operation failed';
                if ($task === 'update_delivery') {
                    $error_message = 'Failed to update delivery. Please check the error log.';
                } elseif ($task === 'create_delivery') {
                    $error_message = 'Failed to create delivery. Please check the error log.';
                }
                wp_send_json_error($error_message);
            } else {
                wp_send_json_success($result);
            }
        } else {
            // For POST (admin-post.php) requests
            if ($result === false || $result === 0) {
                $error_message = 'Operation failed';
                if ($task === 'update_delivery') {
                    $error_message = 'Failed to update delivery. Please check the error log.';
                } elseif ($task === 'create_delivery') {
                    $error_message = 'Failed to create delivery. Please check the error log.';
                }
                wp_redirect(add_query_arg(['delivery_error' => urlencode($error_message)], wp_get_referer() ?: admin_url()));
                exit;
            } else {
                // Success - redirect with success message
                $success_message = 'Delivery updated successfully!';
                if ($task === 'create_delivery') {
                    $success_message = 'Delivery created successfully!';
                }
                wp_redirect(add_query_arg(['delivery_success' => '1', 'message' => urlencode($success_message)], wp_get_referer() ?: admin_url()));
                exit;
            }
        }
    }

    //Change delivery status from schediled to intransit
    public static function delivery_changeTo_Intransit()
    {
        global $wpdb;
        $table   = $wpdb->prefix . 'kit_deliveries';
        $id      = intval($_POST['id']);
        $updated = $wpdb->update(
            $table,
            ['status' => 'in_transit'],
            ['id' => intval($id)]
        );

        return $updated !== false ? true : false;
    }
    //Change delivery status from intransit to delivered
    public static function delivery_changeTo_Delivered()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_deliveries';
        $id    = intval($_POST['id']);

        $updated = $wpdb->update(
            $table,
            ['status' => 'delivered'],
            ['id' => intval($id)]
        );

        // Debug statement removed - was causing JSON parsing errors
        return $updated !== false ? true : false;
    }
    //Change delivery status scheduled
    public static function delivery_changeTo_Scheduled()
    {
        global $wpdb;
        $table   = $wpdb->prefix . 'kit_deliveries';
        $id      = intval($_POST['id']);
        $updated = $wpdb->update(
            $table,
            ['status' => 'scheduled'],
            ['id' => intval($id)]
        );

        return $updated !== false ? true : false;
    }
    public static function deliveries_by_CountStat($country)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_deliveries';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
        WHERE destination_country = %s 
        AND status = 'scheduled'
        ORDER BY dispatch_date ASC",
            $country
        ));
    }
    public static function get_customers()
    {
        return get_users(['role__in' => ['customer', 'subscriber']]);
    }
    public static function get_customer_name($customer_id)
    {
        $customer = get_user_by('ID', $customer_id);
        return $customer ? $customer->display_name : 'Unknown';
    }
    public static function get_all_deliveries()
    {
        //get all deliveries and fk link with operating countries where the delivery.origin_country
        global $wpdb;
        $table          = $wpdb->prefix . 'kit_deliveries';
        $waybills_table = $wpdb->prefix . 'kit_waybills';

        // Fetch all deliveries ordered by created_at in descending order
        // Join with kit_shipping_directions on direction_id
        // Also join kit_operating_countries twice to get origin and destination country names

        $countries_table  = $wpdb->prefix . 'kit_operating_countries';
        $directions_table = $wpdb->prefix . 'kit_shipping_directions';

        // Check if drivers table exists
        $drivers_table = $wpdb->prefix . 'kit_drivers';
        $drivers_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$drivers_table'");

        // Check if driver_id column exists
        $driver_id_exists = false;
        if ($drivers_table_exists) {
            $driver_id_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'driver_id'",
                DB_NAME,
                $table
            ));
        }

        // Build driver join and select if available
        $driver_join = "";
        $driver_select = "";
        if ($drivers_table_exists && $driver_id_exists) {
            $driver_join = "LEFT JOIN {$drivers_table} dr ON d.driver_id = dr.id";
            $driver_select = ",
                dr.name AS driver_name,
                dr.phone AS driver_phone";
        }

        // Join: deliveries -> directions -> countries (origin & destination) -> drivers
        $query = "
            SELECT 
                d.*, 
                sd.origin_country_id, 
                sd.destination_country_id, 
                sd.description AS direction_description,
                oc1.country_name AS origin_country_name,
                oc2.country_name AS destination_country_name,
                COALESCE(wb.waybill_count, 0) AS waybill_count
                $driver_select
            FROM {$table} d
            LEFT JOIN {$directions_table} sd ON d.direction_id = sd.id
            LEFT JOIN {$countries_table} oc1 ON sd.origin_country_id = oc1.id
            LEFT JOIN {$countries_table} oc2 ON sd.destination_country_id = oc2.id
            LEFT JOIN (
                SELECT delivery_id, COUNT(*) AS waybill_count
                FROM {$waybills_table}
                GROUP BY delivery_id
            ) wb ON wb.delivery_id = d.id
            $driver_join
            WHERE d.delivery_reference != 'pending'
            ORDER BY d.created_at DESC
        ";
        return $wpdb->get_results($query);
    }
    public static function get_delivery($id)
    {
        global $wpdb;

        $deliveryTable      = $wpdb->prefix . 'kit_deliveries';
        $shipDirectionTable = $wpdb->prefix . 'kit_shipping_directions';
        $countryTable       = $wpdb->prefix . 'kit_operating_countries';
        $driversTable       = $wpdb->prefix . 'kit_drivers';

        // Check if drivers table exists, if not create it
        $drivers_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$driversTable'");

        // Create drivers table if it doesn't exist
        if (!$drivers_table_exists) {
            require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/class-database.php';
            Database::create_drivers_table();
            $drivers_table_exists = true;
        }

        // Check if driver_id column exists in deliveries table
        $driver_id_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'driver_id'",
            DB_NAME,
            $deliveryTable
        ));

        // Add driver_id column if it doesn't exist
        if (!$driver_id_exists && $drivers_table_exists) {
            require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/class-database.php';
            Database::update_deliveries_table_for_drivers();
            $driver_id_exists = true;
        }

        // Build query with conditional driver join (only if both table and column exist)
        $driver_join = "";
        $driver_select = "";
        if ($drivers_table_exists && $driver_id_exists) {
            $driver_join = "LEFT JOIN $driversTable dr ON d.driver_id = dr.id";
            $driver_select = ",
            -- Driver
            dr.name AS driver_name,
            dr.phone AS driver_phone,
            dr.email AS driver_email";
        }

        $query = "
        SELECT
            d.*,
            d.id AS delivery_id,
            sd.description AS description,
            sd.description AS direction_description,
            sd.origin_country_id,
            sd.destination_country_id,
            oc1.country_name AS origin_country,
            oc1.country_name AS origin_country_name,
            oc1.country_code AS origin_code,
            oc2.country_name AS destination_country,
            oc2.country_name AS destination_country_name,
            oc2.country_code AS destination_code
            $driver_select

        FROM $deliveryTable d

        LEFT JOIN $shipDirectionTable sd ON d.direction_id = sd.id
        LEFT JOIN $countryTable oc1 ON sd.origin_country_id = oc1.id
        LEFT JOIN $countryTable oc2 ON sd.destination_country_id = oc2.id
        $driver_join

        WHERE d.id = %d
    ";

        return $wpdb->get_row($wpdb->prepare($query, $id));
    }

    public static function get_direction_id($origin_country_id, $destination_country_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_shipping_directions';
        return $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE origin_country_id = %d AND destination_country_id = %d", $origin_country_id, $destination_country_id));
    }

    /**
     * Get or create direction_id based on origin and destination country IDs
     * This is the main function to use when you have country IDs and need a direction_id
     * 
     * @param int $origin_country_id
     * @param int $destination_country_id
     * @return int|false The direction_id or false if failed
     */
    public static function get_or_create_direction_id($origin_country_id, $destination_country_id)
    {
        // First try to find existing direction
        $direction_id = self::get_direction_id($origin_country_id, $destination_country_id);

        // If found, return it
        if ($direction_id) {
            return intval($direction_id);
        }

        // If not found, create a new one
        $direction_id = self::create_direction($origin_country_id, $destination_country_id);

        return $direction_id ? intval($direction_id) : false;
    }

    /**
     * Verify delivery based on destination and origin countries
     * @param int $destination_country_id
     * @param int $origin_country_id
     * @return array|false Delivery verification result or false on error
     */
    public static function get_delivery_verify($destination_country_id, $origin_country_id)
    {
        global $wpdb;

        // Validate input parameters
        if (!$destination_country_id || !$origin_country_id) {
            return false;
        }

        // Get direction_id for the route
        $direction_id = self::get_direction_id($origin_country_id, $destination_country_id);

        if (!$direction_id) {
            return false;
        }

        // Get delivery information for this route
        $delivery_table = $wpdb->prefix . 'kit_deliveries';
        $directions_table = $wpdb->prefix . 'kit_shipping_directions';
        $countries_table = $wpdb->prefix . 'kit_operating_countries';

        $query = "
            SELECT 
                d.*,
                sd.description,
                oc1.country_name AS origin_country,
                oc2.country_name AS destination_country
            FROM $delivery_table d
            LEFT JOIN $directions_table sd ON d.direction_id = sd.id
            LEFT JOIN $countries_table oc1 ON sd.origin_country_id = oc1.id
            LEFT JOIN $countries_table oc2 ON sd.destination_country_id = oc2.id
            WHERE d.direction_id = %d
            AND d.status = 'scheduled'
            ORDER BY d.dispatch_date ASC
            LIMIT 1
        ";

        $delivery = $wpdb->get_row($wpdb->prepare($query, $direction_id));

        if ($delivery) {
            return [
                'delivery_id' => $delivery->id,
                'delivery_reference' => $delivery->delivery_reference,
                'direction_id' => $direction_id,
                'origin_country' => $delivery->origin_country,
                'destination_country' => $delivery->destination_country,
                'dispatch_date' => $delivery->dispatch_date,
                'truck_number' => $delivery->truck_number,
                'status' => $delivery->status,
                'description' => $delivery->description,
                'verified' => true
            ];
        }

        return [
            'delivery_id' => null,
            'direction_id' => $direction_id,
            'origin_country' => $origin_country_id,
            'destination_country' => $destination_country_id,
            'verified' => false,
            'message' => 'No scheduled delivery found for this route'
        ];
    }

    public static function create_direction($origin_country_id, $destination_country_id)
    {
        global $wpdb;

        $table           = $wpdb->prefix . 'kit_shipping_directions';
        $countries_table = $wpdb->prefix . 'kit_operating_countries';

        // If this direction already exists, return it immediately (idempotent)
        $existing_id = self::get_direction_id($origin_country_id, $destination_country_id);
        if (! empty($existing_id)) {
            return intval($existing_id);
        }

        // Fetch country names
        $origin_country_name      = KIT_Routes::get_country_name_by_id($origin_country_id);
        $destination_country_name = KIT_Routes::get_country_name_by_id($destination_country_id);

        // 🔁 Activate origin country if inactive
        $origin_active = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM $countries_table WHERE id = %d",
            $origin_country_id
        ));

        if ($origin_active !== null && intval($origin_active) === 0) {
            $wpdb->update(
                $countries_table,
                ['is_active' => 1],
                ['id' => $origin_country_id],
                ['%d'],
                ['%d']
            );
        }

        // 🔁 Activate destination country if inactive
        $destination_active = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM $countries_table WHERE id = %d",
            $destination_country_id
        ));

        if ($destination_active !== null && intval($destination_active) === 0) {
            $wpdb->update(
                $countries_table,
                ['is_active' => 1],
                ['id' => $destination_country_id],
                ['%d'],
                ['%d']
            );
        }

        // Insert route/direction with duplicate handling
        $inserted = $wpdb->insert($table, [
            'origin_country_id'      => $origin_country_id,
            'destination_country_id' => $destination_country_id,
            'description'            => $origin_country_name . ' to ' . $destination_country_name,
        ]);

        if ($inserted === false) {
            // If duplicate key (direction_pair) or any race condition, re-select and return
            $last_error = isset($wpdb->last_error) ? strtolower($wpdb->last_error) : '';
            if (strpos($last_error, 'duplicate') !== false) {
                $existing_id = self::get_direction_id($origin_country_id, $destination_country_id);
                if (! empty($existing_id)) {
                    return intval($existing_id);
                }
            }
            // As a safe fallback, try once more to read existing row
            $existing_id = self::get_direction_id($origin_country_id, $destination_country_id);
            if (! empty($existing_id)) {
                return intval($existing_id);
            }
            // Could not create or find; surface a failure (return 0 to let caller handle WP_Error)
            return 0;
        }

        return intval($wpdb->insert_id);
    }

    /**
     * Get all active drivers
     * Creates table if it doesn't exist (for backward compatibility)
     */
    public static function get_all_drivers()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_drivers';

        // Check if table exists, if not create it
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/class-database.php';
            Database::create_drivers_table();
        }

        return $wpdb->get_results("SELECT * FROM $table WHERE is_active = 1 ORDER BY name ASC");
    }

    /**
     * Get driver by ID
     */
    public static function get_driver($driver_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_drivers';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $driver_id));
    }

    public static function save_delivery($data)
    {
        global $wpdb;
        $table              = $wpdb->prefix . 'kit_deliveries';
        $directions_table   = $wpdb->prefix . 'kit_shipping_directions';
        $chargeGroups_table = $wpdb->prefix . 'kit_charge_groups';
        $countries_table    = $wpdb->prefix . 'kit_operating_countries';

        // Validate required fields
        if (empty($data['origin_country']) || empty($data['destination_country'])) {
            error_log('Save delivery failed: Missing origin or destination country. Origin: ' . ($data['origin_country'] ?? 'empty') . ', Destination: ' . ($data['destination_country'] ?? 'empty'));
            return false;
        }

        // Get origin and destination country IDs from form
        $origin_country_id      = intval($data['origin_country']);
        $destination_country_id = intval($data['destination_country']);

        // Get or create direction_id based on the country IDs
        $direction_id = self::get_or_create_direction_id($origin_country_id, $destination_country_id);

        // Validate direction was created/found
        if (! $direction_id) {
            error_log('Save delivery failed: Could not create/find direction for countries ' . $data['origin_country'] . ' to ' . $data['destination_country']);
            return false;
        }

        // Get destination city ID - ensure it's valid (not 0)
        $destination_city_id = 1; // Default to first city
        if (isset($data['destination_city']) && !empty($data['destination_city']) && intval($data['destination_city']) > 0) {
            $destination_city_id = intval($data['destination_city']);
        }

        $delivery_data = [
            'delivery_reference'  => sanitize_text_field($data['delivery_reference']),
            'direction_id'        => (int) $direction_id,
            'destination_city_id' => $destination_city_id,
            'dispatch_date'       => sanitize_text_field($data['dispatch_date']),
            'driver_id'           => isset($data['driver_id']) && !empty($data['driver_id']) ? intval($data['driver_id']) : null,
            'status'              => in_array($data['status'], ['scheduled', 'in_transit', 'delivered'])
                ? $data['status']
                : 'scheduled',
            'created_by'          => get_current_user_id(),
        ];

        if (isset($data['delivery_id']) && $data['delivery_id'] > 0) {
            // Update existing delivery

            $result = $wpdb->update(
                $table,
                $delivery_data,
                ['id' => intval($data['delivery_id'])]
            );

            if ($result === false) {
                error_log('Delivery update failed for ID ' . $data['delivery_id'] . ': ' . $wpdb->last_error);
                error_log('Delivery update debug - SQL: ' . $wpdb->last_query);
                return false;
            }

            if (class_exists('Courier_Google_Sheets_Sync')) {
                $d = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $data['delivery_id']));
                if ($d) {
                    Courier_Google_Sheets_Sync::sync_delivery_update($d);
                }
            }
            error_log('Delivery updated successfully: ID ' . $data['delivery_id'] . ', Rows affected: ' . $result);
            return $data['delivery_id'];
        } else {
            // Create new delivery
            $result = $wpdb->insert($table, $delivery_data);

            if ($result === false) {
                error_log('Delivery insert failed: ' . $wpdb->last_error);
                return false;
            }

            if (class_exists('Courier_Google_Sheets_Sync')) {
                $d = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $wpdb->insert_id));
                if ($d) {
                    Courier_Google_Sheets_Sync::sync_delivery_add($d);
                }
            }
            // The delivery has been created successfully, return the id of the new delivery
            error_log('Delivery created successfully: ID ' . $wpdb->insert_id);
            return $wpdb->insert_id;
        }
    }
    /**
     * Delete delivery with cascade delete and JSON backup
     * @param int $id Delivery ID to delete
     * @return array Result with success status and backup info
     */
    public static function delete_delivery($id)
    {
        global $wpdb;

        $delivery_id = intval($id);
        if ($delivery_id <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid delivery ID'
            ];
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // 1. Get delivery data for backup
            $delivery = self::get_delivery($delivery_id);
            if (!$delivery) {
                $wpdb->query('ROLLBACK');
                return [
                    'success' => false,
                    'message' => 'Delivery not found'
                ];
            }

            if (class_exists('Courier_Google_Sheets_Sync')) {
                Courier_Google_Sheets_Sync::sync_delivery_delete($delivery->delivery_reference ?? '');
            }

            // 2. Get all waybills associated with this delivery
            $waybills_table = $wpdb->prefix . 'kit_waybills';
            $waybill_items_table = $wpdb->prefix . 'kit_waybill_items';

            $waybills = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$waybills_table} WHERE delivery_id = %d",
                $delivery_id
            ));

            // 3. Get waybill items for each waybill
            $waybill_data = [];
            foreach ($waybills as $waybill) {
                $items = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$waybill_items_table} WHERE waybillno = %d",
                    $waybill->waybill_no
                ));

                $waybill_data[] = [
                    'waybill' => $waybill,
                    'items' => $items
                ];
            }

            // 4. Create JSON backup
            $backup_data = [
                'delivery' => $delivery,
                'waybills' => $waybill_data,
                'backup_date' => current_time('mysql'),
                'backup_timestamp' => time()
            ];

            $backup_json = json_encode($backup_data, JSON_PRETTY_PRINT);

            // 5. Save backup to file
            $backup_dir = WP_CONTENT_DIR . '/courier-finance-backups/';
            if (!file_exists($backup_dir)) {
                wp_mkdir_p($backup_dir);
            }

            $backup_filename = 'delivery_backup_' . $delivery_id . '_' . date('Y-m-d_H-i-s') . '.json';
            $backup_filepath = $backup_dir . $backup_filename;

            $backup_saved = file_put_contents($backup_filepath, $backup_json);

            // 6. Delete waybill items first (foreign key constraint)
            foreach ($waybills as $waybill) {
                $wpdb->delete($waybill_items_table, ['waybillno' => $waybill->waybill_no]);
            }

            // 7. Delete waybills
            $waybills_deleted = $wpdb->delete($waybills_table, ['delivery_id' => $delivery_id]);

            // 8. Delete delivery
            $delivery_table = $wpdb->prefix . 'kit_deliveries';
            $delivery_deleted = $wpdb->delete($delivery_table, ['id' => $delivery_id]);

            // 9. Commit transaction
            $wpdb->query('COMMIT');

            return [
                'success' => true,
                'message' => 'Delivery and associated waybills deleted successfully',
                'backup' => [
                    'file' => $backup_filename,
                    'path' => $backup_filepath,
                    'saved' => $backup_saved !== false,
                    'waybills_count' => count($waybills),
                    'items_count' => array_sum(array_map(function ($w) {
                        return count($w['items']);
                    }, $waybill_data))
                ]
            ];
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            error_log('Delivery delete error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to delete delivery: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Restore delivery from JSON backup
     * @param string $backup_filepath Path to the backup JSON file
     * @return array Result with success status
     */
    public static function restore_delivery_from_backup($backup_filepath)
    {
        global $wpdb;

        if (!file_exists($backup_filepath)) {
            return [
                'success' => false,
                'message' => 'Backup file not found'
            ];
        }

        $backup_content = file_get_contents($backup_filepath);
        $backup_data = json_decode($backup_content, true);

        if (!$backup_data) {
            return [
                'success' => false,
                'message' => 'Invalid backup file format'
            ];
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // 1. Restore delivery
            $delivery_table = $wpdb->prefix . 'kit_deliveries';
            $delivery_data = $backup_data['delivery'];

            // Remove id to create new delivery
            unset($delivery_data['id']);

            $delivery_inserted = $wpdb->insert($delivery_table, $delivery_data);
            if (!$delivery_inserted) {
                throw new Exception('Failed to restore delivery: ' . $wpdb->last_error);
            }

            $new_delivery_id = $wpdb->insert_id;

            // 2. Restore waybills
            $waybills_table = $wpdb->prefix . 'kit_waybills';
            $waybill_items_table = $wpdb->prefix . 'kit_waybill_items';
            $restored_waybills = 0;
            $restored_items = 0;

            foreach ($backup_data['waybills'] as $waybill_data) {
                $waybill = $waybill_data['waybill'];
                $items = $waybill_data['items'];

                // Update delivery_id to new delivery
                $waybill['delivery_id'] = $new_delivery_id;

                // Remove id to create new waybill
                unset($waybill['id']);

                $waybill_inserted = $wpdb->insert($waybills_table, $waybill);
                if (!$waybill_inserted) {
                    throw new Exception('Failed to restore waybill: ' . $wpdb->last_error);
                }

                $new_waybill_id = $wpdb->insert_id;
                $restored_waybills++;

                // 3. Restore waybill items
                foreach ($items as $item) {
                    $item['waybillno'] = $waybill['waybill_no']; // Use waybill_no instead of waybill_id
                    unset($item['id']); // Remove id to create new item

                    $item_inserted = $wpdb->insert($waybill_items_table, $item);
                    if ($item_inserted) {
                        $restored_items++;
                    }
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');

            return [
                'success' => true,
                'message' => 'Delivery restored successfully from backup',
                'restored' => [
                    'delivery_id' => $new_delivery_id,
                    'waybills_count' => $restored_waybills,
                    'items_count' => $restored_items,
                    'backup_date' => $backup_data['backup_date'] ?? 'Unknown'
                ]
            ];
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            error_log('Delivery restore error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to restore delivery: ' . $e->getMessage()
            ];
        }
    }

    /**
     * List all available backup files
     * @return array List of backup files with metadata
     */
    public static function list_backup_files()
    {
        $backup_dir = WP_CONTENT_DIR . '/courier-finance-backups/';

        if (!file_exists($backup_dir)) {
            return [];
        }

        $files = glob($backup_dir . 'delivery_backup_*.json');
        $backups = [];

        foreach ($files as $file) {
            $filename = basename($file);
            $file_content = file_get_contents($file);
            $backup_data = json_decode($file_content, true);

            $backups[] = [
                'filename' => $filename,
                'filepath' => $file,
                'size' => filesize($file),
                'modified' => filemtime($file),
                'delivery_id' => $backup_data['delivery']['id'] ?? 'Unknown',
                'waybills_count' => count($backup_data['waybills'] ?? []),
                'backup_date' => $backup_data['backup_date'] ?? 'Unknown',
                'delivery_reference' => $backup_data['delivery']['delivery_reference'] ?? 'Unknown'
            ];
        }

        // Sort by modification time (newest first)
        usort($backups, function ($a, $b) {
            return $b['modified'] - $a['modified'];
        });

        return $backups;
    }

    public static function selectAllCountries($name, $id, $country_id, $required = true, $type = 'origin', $options = [])
    {
        // Support new rules system with backwards compatibility
        if (! empty($options) && is_array($options)) {
            $countries = self::getCountriesWithRules($options);
        } else {
            $countries = self::getCountriesObject(); // Default: active countries only
        }

        ob_start();
    ?>
        <select
            class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>" <?php echo $required; ?>
            onchange="handleCountryChange(this.value, '<?php echo $type; ?>')">
            <option value="">Select Country</option>
            <?php foreach ($countries as $country): ?>
                <option value="<?php echo esc_attr($country->id); ?>"
                    <?php echo (intval($country_id) == intval($country->id)) ? 'selected' : ''; ?> <?php if (! empty($options['show_inactive_indicators']) && (! isset($country->is_active) || $country->is_active == 0)) {
                                                                                                        echo ' style="color: #9ca3af;"';
                                                                                                    }
                                                                                                    ?>>
                    <?php echo esc_html($country->country_name); ?>
                    <?php if (! empty($options['show_inactive_indicators']) && (! isset($country->is_active) || $country->is_active == 0)) {
                        echo ' (Inactive)';
                    }
                    ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php
        return ob_get_clean();
    }

    public static function selectAllCitiesByCountry($name, $id, $country_id, $city_id, $required = true)
    {
        ob_start();

        // If no country is selected, show empty dropdown
        if (! $country_id) {
            $cities = [];
        } else {
            $cities = KIT_Deliveries::get_Cities_forCountry($country_id);
        }

        // Remove debug output to avoid breaking markup/selection
    ?>
        <select
            class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>" <?php echo $required; ?>>
            <option value="">Select City</option>
            <?php if ($cities && is_array($cities)): ?>
                <?php foreach ($cities as $city): ?>
                    <?php $isSelected = (intval($city_id) == intval($city->id)) ? ' selected="selected"' : ''; ?>
                    <option value="<?php echo esc_attr($city->id); ?>" <?php echo $isSelected; ?>>
                        <?php echo esc_html($city->city_name); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <?php
        return ob_get_clean();
    }

    public static function handle_get_cities_for_country_callback()
    {
        // Verify nonce
        if (! isset($_POST['nonce'])) {
            wp_send_json_error(['message' => 'Nonce not found']);
            return;
        }

        if (! wp_verify_nonce($_POST['nonce'], 'get_waybills_nonce')) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        if (! isset($_POST['country_id'])) {
            wp_send_json_error(['message' => 'Country ID is required']);
            return;
        }

        $country_id = intval($_POST['country_id']);

        if (! $country_id) {
            wp_send_json_error(['message' => 'Invalid country ID']);
            return;
        }

        // Get cities for the country
        $cities = KIT_Deliveries::get_Cities_forCountry($country_id);

        if ($cities && is_array($cities) && count($cities) > 0) {
            wp_send_json_success($cities);
        } else {
            wp_send_json_error(['message' => 'No cities found for this country']);
        }
    }

    /**
     * AJAX handler for listing delivery backups
     */
    public static function handle_list_delivery_backups()
    {
        check_ajax_referer('get_waybills_nonce', 'nonce');

        if (!current_user_can('edit_pages')) {
            wp_send_json_error('Unauthorized');
        }

        $backups = self::list_backup_files();
        wp_send_json_success($backups);
    }

    /**
     * AJAX handler to refresh deliveries table
     */
    public static function ajax_refresh_deliveries_table()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'get_waybills_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        // Check permissions
        if (!current_user_can('kit_view_waybills') && !current_user_can('edit_pages')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        // Get all deliveries
        $deliveries = self::get_all_deliveries();

        // Calculate statistics
        $total_deliveries = count($deliveries);
        $scheduled_count = count(array_filter($deliveries, function ($d) {
            return $d->status === 'scheduled';
        }));
        $in_transit_count = count(array_filter($deliveries, function ($d) {
            return $d->status === 'in_transit';
        }));
        $delivered_countries = array_unique(array_column($deliveries, 'destination_country_name'));
        $countries_count = count($delivered_countries);

        // Generate table rows HTML
        ob_start();
        foreach ($deliveries as $delivery):
        ?>
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm font-medium text-gray-900">
                        <?php echo esc_html($delivery->delivery_reference); ?>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">
                        <?php
                        $origin = $delivery->origin_country_name ?? 'N/A';
                        $dest = $delivery->destination_country_name ?? 'N/A';
                        echo esc_html("$origin → $dest");
                        ?>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">
                        <?php
                        if (!empty($delivery->dispatch_date) && $delivery->dispatch_date !== '0000-00-00') {
                            echo esc_html(date('M j, Y', strtotime($delivery->dispatch_date)));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">
                        <?php echo esc_html($delivery->truck_number ?? 'N/A'); ?>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">
                        <?php echo esc_html($delivery->driver_name ?? 'N/A'); ?>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <?php
                    $status_class = match ($delivery->status) {
                        'scheduled'  => 'bg-blue-100 text-blue-800',
                        'in_transit' => 'bg-yellow-100 text-yellow-800',
                        'delivered'  => 'bg-green-100 text-green-800',
                        'cancelled'  => 'bg-red-100 text-red-800',
                        default      => 'bg-gray-100 text-gray-800'
                    };
                    ?>
                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>">
                        <?php echo esc_html(ucfirst($delivery->status ?? 'Unknown')); ?>
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">
                        <?php echo esc_html($delivery->waybill_count ?? 0); ?>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <div class="flex space-x-2">
                        <?php
                        echo KIT_Commons::renderButton(
                            'View',
                            'link',
                            'lg',
                            [
                                'href'    => admin_url('admin.php?page=view-deliveries&delivery_id=' . $delivery->id),
                                'classes' => 'view-delivery-btn bg-transparent shadow-none border-0 px-0 py-0 text-blue-600 hover:text-blue-900',
                            ]
                        );

                        echo KIT_Commons::renderButton(
                            'Edit',
                            'ghost',
                            'lg',
                            [
                                'type'    => 'button',
                                'onclick' => sprintf("return editDelivery(%d)", $delivery->id),
                                'classes' => 'bg-transparent shadow-none border-0 px-2 py-1 text-blue-600 hover:text-blue-900',
                            ]
                        );

                        echo KIT_Commons::renderButton(
                            'Delete',
                            'ghost',
                            'lg',
                            [
                                'type'    => 'button',
                                'onclick' => sprintf("deleteDelivery(%d, event)", $delivery->id),
                                'classes' => 'bg-transparent shadow-none border-0 px-2 py-1 text-red-600 hover:text-red-900',
                            ]
                        );
                        ?>
                    </div>
                </td>
            </tr>
<?php
        endforeach;
        $html = ob_get_clean();

        // Return response with HTML and statistics
        wp_send_json_success([
            'html'  => $html,
            'stats' => [
                'total'     => number_format($total_deliveries),
                'scheduled' => number_format($scheduled_count),
                'in_transit' => number_format($in_transit_count),
                'countries' => number_format($countries_count),
            ],
        ]);
    }

    /**
     * AJAX handler for restoring delivery from backup
     */
    public static function handle_restore_delivery_backup()
    {
        check_ajax_referer('get_waybills_nonce', 'nonce');

        if (!current_user_can('edit_pages')) {
            wp_send_json_error('Unauthorized');
        }

        $backup_filepath = sanitize_text_field($_POST['backup_filepath'] ?? '');

        if (empty($backup_filepath)) {
            wp_send_json_error('Backup file path is required');
        }

        $result = self::restore_delivery_from_backup($backup_filepath);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}

KIT_Deliveries::init();

function render_waybills_with_items($waybillsandItems)
{
    // Define the columns you want to show
    $columns = ['waybill_no', 'customer', 'dispatch_date', 'actions'];

    echo '<table class="table-class">';
    // Table header
    echo '<thead><tr>';
    foreach ($columns as $col) {
        echo '<th>' . ucfirst(str_replace('_', ' ', (string) ($col ?? ''))) . '</th>';
    }
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($waybillsandItems as $row) {
        $waybill = (object) $row['waybill'];
        render_waybill_row($waybill, $columns);
        // Optionally, render items as sub-rows or in a details row
        // foreach ($row['items'] as $item) { ... }
    }

    echo '</tbody></table>';
}
