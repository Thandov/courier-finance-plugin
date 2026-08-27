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
        // Delivery and pricing data is staff-only; every consumer runs as a logged-in
        // user, so no *_nopriv_ variants are registered.
        add_action('admin_menu', [self::class, 'add_admin_menu']);
        add_action('admin_post_kit_deliveries_crud', [self::class, 'updateShippingDirection']);
        add_action('wp_ajax_kit_deliveries_crud', [self::class, 'updateShippingDirection']);
        add_action('wp_ajax_refresh_deliveries_table', [self::class, 'ajax_refresh_deliveries_table']);
        add_action('wp_ajax_delivery_changeTo_Intransit', [self::class, 'delivery_changeTo_Intransit']);
        add_action('wp_ajax_delivery_changeTo_Delivered', [self::class, 'delivery_changeTo_Delivered']);
        add_action('wp_ajax_delivery_changeTo_Scheduled', [self::class, 'delivery_changeTo_Scheduled']);
        add_action('wp_ajax_kit_set_delivery_status', [self::class, 'ajax_set_delivery_status']);
        add_action('wp_ajax_get_scheduled_deliveries', [self::class, 'getScheduledDeliveries']);
        add_action('wp_ajax_get_customers', [self::class, 'get_customers']);
        add_action('wp_ajax_get_deliveries_by_country', [self::class, 'handle_get_deliveries_by_country']);
        add_action('wp_ajax_get_deliveries_by_country_id', [self::class, 'handle_get_deliveries_by_country_id']);
        // Removed: destination city from misc->others - use waybills.city_id instead
        add_shortcode('country_select', [self::class, 'CountrySelect']);
        add_action('wp_ajax_handle_get_cities_for_country', [self::class, 'handle_get_cities_for_country_callback']);
        add_action('wp_ajax_handle_get_countryDeliveries', [self::class, 'handle_get_countryDeliveries_callback']);
        add_action('wp_ajax_handle_get_price_per_kg', [self::class, 'handle_get_price_per_kg']);
        add_action('wp_ajax_handle_get_price_per_m3', [self::class, 'handle_get_price_per_m3']);
        add_action('wp_ajax_list_delivery_backups', [self::class, 'handle_list_delivery_backups']);
        add_action('wp_ajax_restore_delivery_backup', [self::class, 'handle_restore_delivery_backup']);
        add_action('wp_ajax_filter_deliveries', [self::class, 'ajax_filter_deliveries']);

        // Schedule daily task to update past deliveries
        add_action('init', [self::class, 'schedule_daily_delivery_status_update']);
        add_action('kit_daily_update_past_deliveries', [self::class, 'update_past_deliveries_to_delivered']);
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

    /**
     * Resolve volumetric rate (ZAR per m³) from kit_shipping_rates_volume.
     * Matches the logic used by {@see self::handle_get_price_per_m3()} so server-side saves
     * stay consistent with the edit-form AJAX calculator.
     *
     * Requires a real shipping {@see $direction_id}. There is no charge-group fallback: tiers are
     * always resolved for the selected route/direction only.
     *
     * @param int   $direction_id      Shipping direction id (must be greater than 0).
     * @param int   $origin_country_id Reserved for callers; not used for tier lookup.
     * @param float $total_volume_m3   Volume in m³.
     * @return float|null Rate per m³, or null if no active tier matches.
     */
    public static function lookup_volume_rate_per_m3($direction_id, $origin_country_id, $total_volume_m3)
    {
        global $wpdb;

        $direction_id = intval($direction_id);
        $total_volume_m3 = floatval($total_volume_m3);

        if ($direction_id <= 0 || $total_volume_m3 <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'kit_shipping_rates_volume';

        $rate_per_m3 = $wpdb->get_var($wpdb->prepare("
            SELECT rate_per_m3
            FROM $table
            WHERE direction_id = %d
              AND min_volume <= %f
              AND (
                    max_volume >= %f
                 OR max_volume = 0
                 OR max_volume IS NULL
              )
              AND is_active = 1
            ORDER BY effective_date DESC, min_volume DESC
            LIMIT 1", $direction_id, $total_volume_m3, $total_volume_m3));

        if ($rate_per_m3 !== null) {
            return floatval($rate_per_m3);
        }

        return null;
    }

    public static function handle_get_price_per_m3()
    {
        if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'get_waybills_nonce')) {
            wp_send_json_error(['message' => 'Invalid security token.']);
        }

        $direction_id    = isset($_POST['direction_id']) ? intval($_POST['direction_id']) : 0;
        $total_volume_m3 = isset($_POST['total_volume_m3']) ? floatval($_POST['total_volume_m3']) : 0;
        $origin_country  = isset($_POST['origin_country_id']) ? intval($_POST['origin_country_id']) : 0;

        if (! $total_volume_m3) {
            wp_send_json_error(['message' => 'Missing volume.']);
        }

        if ($direction_id <= 0) {
            wp_send_json_error(['message' => 'Select a delivery or destination so direction can be determined before fetching volume rates.']);
        }

        $rate_per_m3 = self::lookup_volume_rate_per_m3($direction_id, $origin_country, $total_volume_m3);

        if ($rate_per_m3 !== null) {
            wp_send_json_success(['rate_per_m3' => $rate_per_m3]);
        }

        wp_send_json_error(['message' => 'No matching volumetric rate found.']);
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
        return $wpdb->get_results($query);
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
     * Set past-dispatch deliveries to "delivered" (scheduled only; leaves unconfirmed/in_transit/manual statuses unchanged).
     * Runs via WordPress cron daily (hook kit_daily_update_past_deliveries).
     *
     * IMPORTANT: WordPress cron is "pseudo-cron" - it only runs when someone visits your site.
     * For midnight execution regardless of traffic, use a real server cron hitting wp-cron.php.
     */
    public static function update_past_deliveries_to_delivered()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_deliveries';
        $today      = current_time('Y-m-d');

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table_name
             WHERE dispatch_date IS NOT NULL
             AND dispatch_date < %s
             AND status = 'scheduled'",
            $today
        ));

        $result = 0;
        foreach ((array) $ids as $id) {
            $set = self::set_delivery_status((int) $id, 'delivered');
            if (! is_wp_error($set)) {
                $result++;
            }
        }

        if ($result > 0) {
            error_log("KIT Deliveries: Marked {$result} past-dispatch delivery(ies) as delivered at " . current_time('mysql'));
        } elseif ($wpdb->last_error) {
            error_log('KIT Deliveries: Error updating past deliveries — ' . $wpdb->last_error);
        }

        return $result;
    }

    /**
     * @deprecated Use update_past_deliveries_to_delivered()
     */
    public static function update_past_deliveries_to_unconfirmed()
    {
        return self::update_past_deliveries_to_delivered();
    }

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

    /**
     * Past dispatch_date (before today) + status scheduled or unconfirmed → delivered.
     * Called when staff open the deliveries list or a delivery view so the UI matches without waiting for cron.
     *
     * @return int|false Rows updated, or false on error
     */
    public static function auto_update_past_deliveries()
    {
        return self::update_past_deliveries_to_delivered();
    }

    /**
     * True when the current request (or its referer) is the employee portal.
     */
    public static function is_portal_request()
    {
        if (function_exists('kit_using_employee_portal') && kit_using_employee_portal()) {
            return true;
        }
        $referer = wp_get_referer();
        return is_string($referer) && strpos($referer, 'employee-dashboard') !== false;
    }

    /**
     * Admin or portal URL for a deliveries-related page slug.
     *
     * @param string $page Page / section slug (e.g. view-deliveries, kit-deliveries).
     * @param array  $args Query args.
     * @return string
     */
    public static function delivery_page_url($page, array $args = [])
    {
        if (self::is_portal_request() && function_exists('kit_employee_portal_url')) {
            return kit_employee_portal_url($page, $args);
        }
        return add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php'));
    }

    /**
     * View (or edit-mode) URL for a single delivery truck.
     *
     * @param int   $delivery_id
     * @param array $args Extra query args (e.g. edit_delivery => 1).
     * @return string
     */
    public static function delivery_view_url($delivery_id, array $args = [])
    {
        $args['delivery_id'] = (int) $delivery_id;
        return self::delivery_page_url('view-deliveries', $args);
    }

    /**
     * Redirect after create/update. Update returns to the truck view, not the wizard.
     *
     * @param string     $task        create_delivery|update_delivery
     * @param int|string $delivery_id
     * @param bool       $success
     */
    public static function delivery_after_save_redirect($task, $delivery_id, $success)
    {
        $delivery_id = (int) $delivery_id;
        if ($task === 'create_delivery') {
            if ($success && $delivery_id > 0) {
                wp_safe_redirect(self::delivery_view_url($delivery_id, [
                    'delivery_success' => '1',
                    'message'          => __('Delivery created successfully.', '08600-services-quotations'),
                ]));
            } else {
                wp_safe_redirect(add_query_arg('error', '1', self::delivery_page_url('kit-deliveries')));
            }
            exit;
        }

        if ($success && $delivery_id > 0) {
            wp_safe_redirect(self::delivery_view_url($delivery_id, ['updated' => '1']));
        } else {
            wp_safe_redirect(self::delivery_view_url($delivery_id, ['edit_delivery' => '1', 'error' => '1']));
        }
        exit;
    }

    // Removed legacy endpoint that read destination city from misc->others
    public static function view_deliveries_page()
    {
        self::auto_update_past_deliveries();

        if (
            isset($_POST['bulk_action'], $_POST['bulk_ids'], $_POST['bulk_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bulk_nonce'])), 'bulk_action_nonce')
        ) {
            if (!current_user_can('kit_update_data') && !current_user_can('manage_options')) {
                wp_die('Unauthorized');
            }

            $bulk_action = sanitize_text_field(wp_unslash($_POST['bulk_action']));
            $tokens = array_values(array_unique(array_filter(array_map('trim', explode(
                ',',
                sanitize_text_field(wp_unslash($_POST['bulk_ids']))
            )))));
            $redirect = wp_get_referer() ?: admin_url('admin.php?page=view-deliveries');

            if ($bulk_action === 'move_to_warehouse') {
                if (!class_exists('KIT_Warehouse')) {
                    require_once plugin_dir_path(__FILE__) . '../warehouse/warehouse-functions.php';
                }
                $moved = KIT_Warehouse::moveWaybillsToWarehouse($tokens);
                if (class_exists('KIT_Bulk_Action_Log')) {
                    KIT_Bulk_Action_Log::record('move_to_warehouse', $tokens, 'waybill', KIT_Bulk_Action_Log::current_context());
                }
                wp_safe_redirect(add_query_arg(
                    'warehouse_moved',
                    $moved,
                    remove_query_arg(['warehouse_loaded', 'bulk_deleted', 'warehouse_moved'], $redirect)
                ));
                exit;
            }

            if ($bulk_action === 'delete' && class_exists('KIT_Waybills')) {
                global $wpdb;
                $waybills_table = $wpdb->prefix . 'kit_waybills';
                $deleted_count = 0;
                foreach ($tokens as $token) {
                    $waybill_no = $token;
                    if ($waybill_no === '') {
                        continue;
                    }
                    KIT_Waybills::deleteWaybillItems($waybill_no);
                    $deleted = $wpdb->delete($waybills_table, ['waybill_no' => $waybill_no], ['%s']);
                    if (($deleted === false || $deleted === 0) && ctype_digit($token)) {
                        $row = $wpdb->get_row($wpdb->prepare(
                            "SELECT waybill_no FROM $waybills_table WHERE id = %d",
                            (int) $token
                        ));
                        if ($row && !empty($row->waybill_no)) {
                            KIT_Waybills::deleteWaybillItems($row->waybill_no);
                            $deleted = $wpdb->delete($waybills_table, ['id' => (int) $token], ['%d']);
                        }
                    }
                    if ($deleted !== false && $deleted > 0) {
                        $deleted_count += (int) $deleted;
                    }
                }
                if (class_exists('KIT_Bulk_Action_Log')) {
                    KIT_Bulk_Action_Log::record('delete', $tokens, 'waybill', KIT_Bulk_Action_Log::current_context());
                }
                wp_safe_redirect(add_query_arg(
                    'bulk_deleted',
                    $deleted_count,
                    remove_query_arg(['warehouse_moved', 'warehouse_loaded'], $redirect)
                ));
                exit;
            }
        }

        // Enqueue necessary CSS for styling - using plugin's standard approach
        wp_enqueue_style('autsincss', plugin_dir_url(__FILE__) . '../../assets/css/austin.css', [], '1.0');
        wp_enqueue_style('kit-tailwindcss', plugin_dir_url(__FILE__) . '../../assets/css/frontend.css', [], '1.0');

        // Add CSS class wrapper to admin body for scoping
        add_filter('admin_body_class', function ($classes) {
            return $classes . ' courier-finance-plugin';
        });

        // Waybill modal: kitscript + handleCountryChange (waybill-pagination) + myPluginAjax (countryCities + nonces).
        // Single entry point — see KIT_Commons::enqueueComponentScripts (root bug: localization never ran when kitscript was enqueued).
        wp_enqueue_script('jquery');
        KIT_Commons::enqueueComponentScripts(['kitscript', 'waybill-pagination']);

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

        if (isset($_GET['warehouse_moved'])) {
            $moved = max(0, (int) $_GET['warehouse_moved']);
            if ($moved > 0) {
                echo KIT_Toast::success(
                    sprintf(
                        _n('%d waybill moved to warehouse.', '%d waybills moved to warehouse.', $moved, '08600-services-quotations'),
                        $moved
                    ),
                    'Success'
                );
            }
        }

        if (isset($_GET['bulk_deleted'])) {
            $deleted = max(0, (int) $_GET['bulk_deleted']);
            if ($deleted > 0) {
                echo KIT_Toast::success(
                    sprintf(
                        _n('%d waybill deleted.', '%d waybills deleted.', $deleted, '08600-services-quotations'),
                        $deleted
                    ),
                    'Success'
                );
            }
        }

        if (isset($_GET['warehouse_loaded'])) {
            $loaded = max(0, (int) $_GET['warehouse_loaded']);
            if ($loaded > 0) {
                echo KIT_Toast::success(
                    sprintf(
                        _n('%d waybill loaded onto this truck.', '%d waybills loaded onto this truck.', $loaded, '08600-services-quotations'),
                        $loaded
                    ),
                    'Success'
                );
            }
        }

        if (isset($_GET['status_changed'])) {
            $changed = sanitize_key((string) wp_unslash($_GET['status_changed']));
            $labels = self::allowed_delivery_statuses();
            if (isset($labels[$changed])) {
                echo KIT_Toast::success(
                    sprintf(
                        /* translators: %s: delivery status label */
                        __('Truck status set to %s. Waybills on this truck now follow that status.', '08600-services-quotations'),
                        $labels[$changed]
                    ),
                    'Success'
                );
            }
        }

        if (isset($_GET['updated']) && (string) $_GET['updated'] === '1') {
            echo KIT_Toast::success(
                __('Delivery updated successfully.', '08600-services-quotations'),
                'Success'
            );
        }

        if (isset($_GET['error']) && (string) $_GET['error'] === '1') {
            echo KIT_Toast::error(
                __('Failed to update delivery. Please try again.', '08600-services-quotations'),
                'Error'
            );
        }

        if (! isset($_GET['delivery_id']) || ! is_numeric($_GET['delivery_id'])) {
            echo KIT_Toast::error('Invalid delivery ID.', 'Error');
            return;
        }

        $delivery_id = intval($_GET['delivery_id']);
        $show_edit_delivery = isset($_GET['edit_delivery']) && (string) $_GET['edit_delivery'] === '1';
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

                $header_actions = [];
                if (! $show_edit_delivery) {
                    require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/components/warehouse-load-modal.php';
                    $header_actions[] = kit_render_warehouse_load_modal($delivery);
                    $header_actions[] = KIT_Modal::render(
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
                    );
                }

                $delivery_view_header = [
                    'title'   => __('Delivery Details', '08600-services-quotations'),
                    'icon'    => KIT_Commons::icon('receipt'),
                    'content' => $header_actions,
                ];
                if ($show_edit_delivery) {
                    $delivery_view_header['desc'] = __('Update route, schedule, and driver.', '08600-services-quotations');
                }
                echo KIT_Commons::showingHeader($delivery_view_header);
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

                <?php
                $view_delivery_url = self::delivery_view_url($delivery_id);
                $edit_delivery_url = self::delivery_view_url($delivery_id, ['edit_delivery' => '1']);
                $on_truck_count    = is_array($waybillsandItems) ? count($waybillsandItems) : 0;
                $delivery_pdf_url  = add_query_arg(
                    [
                        'delivery_id'    => $delivery_id,
                        'delivery_nonce' => wp_create_nonce('delivery_truck_pdf'),
                    ],
                    plugin_dir_url(__FILE__) . '../../delivery-truck-pdf.php'
                );
                ?>

                <div class="<?php echo KIT_Commons::container() ?>">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 md:gap-4">
                        <div class="kit-truck-details-col md:col-span-4 min-w-0">
                            <div class="bg-white rounded-lg shadow border border-gray-200 p-3 sm:p-4 md:p-6 space-y-3 md:space-y-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h2 class="text-base sm:text-lg md:text-md font-semibold text-gray-700">Truck Details</h2>
                                </div>
                                <hr>
                                <?php if ($show_edit_delivery) : ?>
                                <form id="kit-truck-details-edit"
                                    method="POST"
                                    action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                    class="space-y-3"
                                    data-waybill-count="<?php echo esc_attr((string) $on_truck_count); ?>"
                                    data-origin-country="<?php echo esc_attr((string) ($delivery->origin_country_id ?? '')); ?>"
                                    data-dest-country="<?php echo esc_attr((string) ($delivery->destination_country_id ?? '')); ?>"
                                    data-dest-city="<?php echo esc_attr((string) ($delivery->destination_city_id ?? '')); ?>">
                                    <input type="hidden" name="action" value="kit_deliveries_crud">
                                    <input type="hidden" name="task" value="update_delivery">
                                    <input type="hidden" name="delivery_id" value="<?php echo esc_attr((string) $delivery_id); ?>">
                                    <input type="hidden" name="direction_id" value="<?php echo esc_attr((string) ($delivery->direction_id ?? 0)); ?>">
                                    <input type="hidden" name="delivery_reference" value="<?php echo esc_attr((string) $delivery->delivery_reference); ?>">
                                    <input type="hidden" name="status" value="<?php echo esc_attr((string) ($delivery->status ?? 'scheduled')); ?>">
                                    <?php wp_nonce_field('get_waybills_nonce', 'nonce'); ?>
                                <?php endif; ?>
                                <table class="min-w-full divide-y divide-gray-200 text-xs md:text-sm">
                                    <tbody class="bg-white divide-y divide-gray-100">
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3">
                                                Reference</th>
                                            <td class="py-1.5 md:py-2 text-gray-900 break-words">
                                                <span class="font-mono text-xs font-semibold"><?php echo esc_html($delivery->delivery_reference); ?></span></td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3 align-top">
                                                Origin</th>
                                            <td class="py-1.5 md:py-2 text-gray-900 break-words">
                                                <?php if ($show_edit_delivery) : ?>
                                                    <div class="kit-truck-edit-fields">
                                                        <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsOrigin.php'; ?>
                                                    </div>
                                                <?php else : ?>
                                                    <?php echo esc_html($delivery->origin_country); ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3 align-top">
                                                Destination</th>
                                            <td class="py-1.5 md:py-2 text-gray-900 break-words">
                                                <?php if ($show_edit_delivery) : ?>
                                                    <div class="kit-truck-edit-fields">
                                                        <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsDestination.php'; ?>
                                                    </div>
                                                    <?php if ($on_truck_count > 0) : ?>
                                                        <p class="text-xs text-amber-800 mt-2">
                                                            <?php
                                                            echo esc_html(sprintf(
                                                                /* translators: %d: waybill count */
                                                                _n(
                                                                    'This truck has %d waybill. Changing the route may not match that load.',
                                                                    'This truck has %d waybills. Changing the route may not match those loads.',
                                                                    $on_truck_count,
                                                                    '08600-services-quotations'
                                                                ),
                                                                $on_truck_count
                                                            ));
                                                            ?>
                                                        </p>
                                                    <?php endif; ?>
                                                <?php else : ?>
                                                    <?php echo esc_html($delivery->destination_country); ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3 align-top">
                                                Departure</th>
                                            <td class="py-1.5 md:py-2 text-gray-900">
                                                <?php if ($show_edit_delivery) : ?>
                                                    <?php
                                                    echo KIT_Commons::Ldate([
                                                        'no_label' => true,
                                                        'label'    => '',
                                                        'name'     => 'dispatch_date',
                                                        'id'       => 'kit_truck_dispatch_date',
                                                        'value'    => $delivery->dispatch_date ? (string) $delivery->dispatch_date : '',
                                                        'preset'   => '',
                                                        'class'    => KIT_Commons::selectClass(),
                                                        'special'  => 'required',
                                                    ]);
                                                    ?>
                                                <?php else : ?>
                                                    <?php echo esc_html(date('Y-m-d', strtotime($delivery->dispatch_date))); ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3 align-top">
                                                Driver</th>
                                            <td class="py-1.5 md:py-2 text-gray-900">
                                                <?php if ($show_edit_delivery) : ?>
                                                    <?php
                                                    $drivers = self::get_all_drivers();
                                                    $selected_driver_id = $delivery->driver_id ?? '';
                                                    ?>
                                                    <select name="driver_id" id="kit_truck_driver_id" required
                                                        class="<?php echo esc_attr(KIT_Commons::selectClass()); ?>">
                                                        <option value=""><?php esc_html_e('Select Driver', '08600-services-quotations'); ?></option>
                                                        <?php foreach ($drivers as $driver) : ?>
                                                            <option value="<?php echo esc_attr($driver->id); ?>"
                                                                <?php selected((string) $selected_driver_id, (string) $driver->id); ?>>
                                                                <?php echo esc_html($driver->name); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if (empty($drivers)) : ?>
                                                        <p class="mt-1.5 text-xs text-yellow-700">
                                                            <a href="<?php echo esc_url(self::delivery_page_url('manage-drivers', ['add' => '1'])); ?>" class="underline font-medium"><?php esc_html_e('Add driver', '08600-services-quotations'); ?></a>
                                                        </p>
                                                    <?php endif; ?>
                                                <?php elseif (!empty($delivery->driver_name)) : ?>
                                                    <?php echo esc_html($delivery->driver_name); ?>
                                                    <?php if (!empty($delivery->driver_phone)) : ?>
                                                        <span class="text-gray-500">(<?php echo esc_html($delivery->driver_phone); ?>)</span>
                                                    <?php endif; ?>
                                                <?php else : ?>
                                                    <span class="text-gray-400"><?php esc_html_e('No driver assigned', '08600-services-quotations'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th
                                                class="text-left py-1.5 md:py-2 pr-2 md:pr-4 text-black font-medium whitespace-nowrap w-1/3">
                                                Status</th>
                                            <td class="py-1.5 md:py-2">
                                                <?php
                                                $status_options = self::allowed_delivery_statuses();
                                                $current_status = (string) ($delivery->status ?? 'scheduled');
                                                ?>
                                                <div class="kit-del-status"
                                                    data-delivery-id="<?php echo esc_attr((string) $delivery_id); ?>"
                                                    data-current="<?php echo esc_attr($current_status); ?>"
                                                    data-waybills="<?php echo esc_attr((string) $on_truck_count); ?>"
                                                    data-nonce="<?php echo esc_attr(wp_create_nonce('kit_set_delivery_status')); ?>"
                                                    data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                                                    <?php
                                                    KIT_Commons::simpleSelect(
                                                        '',
                                                        'delivery_status',
                                                        'kit-del-status-select',
                                                        $status_options,
                                                        $current_status
                                                    );
                                                    ?>
                                                    <p id="kit-del-status-hint" class="text-xs text-gray-500 mt-1"><?php esc_html_e('Applies to all waybills on this truck', '08600-services-quotations'); ?></p>
                                                </div>
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
                                <div class="flex justify-between gap-2">
                                    <div class="flex flex-wrap gap-2">
                                        <?php
                                        if ($show_edit_delivery) {
                                            echo KIT_Commons::renderButton(
                                                __('Cancel', '08600-services-quotations'),
                                                'secondary',
                                                'lg',
                                                [
                                                    'href'         => esc_url($view_delivery_url),
                                                    'classes'      => 'gap-2',
                                                    'icon'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />',
                                                    'iconPosition' => 'left',
                                                ]
                                            );
                                            echo KIT_Commons::renderButton(
                                                __('Save truck', '08600-services-quotations'),
                                                'primary',
                                                'lg',
                                                [
                                                    'type'         => 'submit',
                                                    'classes'      => 'gap-2',
                                                    'icon'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />',
                                                    'iconPosition' => 'left',
                                                ]
                                            );
                                        } else {
                                            echo KIT_Commons::renderButton(
                                                'EDIT',
                                                'primary',
                                                'lg',
                                                [
                                                    'href'         => esc_url($edit_delivery_url),
                                                    'classes'      => 'gap-2',
                                                    'icon'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />',
                                                    'iconPosition' => 'left',
                                                    'gradient'     => true,
                                                ]
                                            );
                                        }
                                        ?>
                                    </div>
                                    <div class="">
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
                                <?php if ($show_edit_delivery) : ?>
                                </form>
                                <?php endif; ?>

                            </div>
                        </div>
                        <div class="md:col-span-8 min-w-0 bg-white rounded-lg shadow border border-gray-200 p-3 sm:p-4 md:p-6">
                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2 mb-3 md:mb-4">
                                <h2 class="text-base sm:text-lg md:text-xl font-semibold text-gray-700"><?php esc_html_e('Waybills on Truck', '08600-services-quotations'); ?></h2>
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

                                echo KIT_Unified_Table::infinite($waybillsandItems, $columns, KIT_Unified_Table::optionsWithManageDefaults([
                                    'title' => 'Waybills on Truck',
                                    'subtitle' => 'Showing: ' . $waybill_count . ' waybills',
                                    'empty_message' => 'No waybills assigned',
                                ]));
                                ?>
                            </div>
                            <?php
                            ?>
                        </div>
                    </div>
                </div>
                <div id="kit-del-status-confirm" class="kit-del-status-confirm hidden">
                    <div class="kit-del-status-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="kit-del-status-confirm-title">
                        <h3 id="kit-del-status-confirm-title"><?php esc_html_e('Change truck status?', '08600-services-quotations'); ?></h3>
                        <p id="kit-del-status-confirm-copy" class="kit-del-status-confirm__copy"></p>
                        <div class="kit-del-status-confirm__actions">
                            <?php
                            echo KIT_Commons::renderButton(
                                __('Cancel', '08600-services-quotations'),
                                'secondary',
                                'md',
                                [
                                    'type'      => 'button',
                                    'id'        => 'kit-del-status-cancel',
                                    'noLoading' => true,
                                    'onclick'   => 'window.kitDeliveryStatusCancel && window.kitDeliveryStatusCancel();',
                                ]
                            );
                            echo KIT_Commons::renderButton(
                                __('Change status', '08600-services-quotations'),
                                'primary',
                                'md',
                                [
                                    'type'           => 'button',
                                    'id'             => 'kit-del-status-ok',
                                    'loadingOnClick' => true,
                                    'onclick'        => 'return window.kitDeliveryStatusSave && window.kitDeliveryStatusSave(event, this);',
                                ]
                            );
                            ?>
                        </div>
                    </div>
                </div>
                <style>
                    @media (min-width: 768px) {
                        .kit-truck-details-col {
                            position: sticky;
                            top: 40px;
                            align-self: start;
                            z-index: 20;
                        }
                    }
                    .kit-truck-edit-fields > div { margin-bottom: 8px; }
                    .kit-truck-edit-fields .grid { grid-template-columns: 1fr !important; gap: 8px; }
                    .kit-del-status > label { display: none; }
                    .kit-del-status-confirm { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,.45); padding: 16px; }
                    .kit-del-status-confirm.hidden { display: none !important; }
                    .kit-del-status-confirm__panel { width: min(420px, 100%); background: #fff; border: 1px solid #e5e7eb; padding: 20px; }
                    .kit-del-status-confirm__panel h3 { margin: 0 0 8px; font-size: 16px; color: #111; }
                    .kit-del-status-confirm__copy { margin: 0 0 16px; font-size: 13px; line-height: 1.5; color: #333; }
                    .kit-del-status-confirm__actions { display: flex; justify-content: flex-end; gap: 8px; }
                </style>
                <script>
                (function () {
                    var wrap = document.querySelector('.kit-del-status');
                    var select = document.getElementById('kit-del-status-select');
                    var overlay = document.getElementById('kit-del-status-confirm');
                    var copyEl = document.getElementById('kit-del-status-confirm-copy');
                    if (!wrap || !select || !overlay || !copyEl) return;

                    var pending = null;
                    var labels = {};
                    Array.prototype.forEach.call(select.options, function (opt) {
                        labels[opt.value] = opt.textContent.trim();
                    });

                    window.kitDeliveryStatusCancel = function () {
                        overlay.classList.add('hidden');
                        pending = null;
                        select.disabled = false;
                        select.value = wrap.getAttribute('data-current') || select.value;
                    };

                    window.kitDeliveryStatusSave = function () {
                        if (!pending) {
                            return Promise.resolve();
                        }
                        select.disabled = true;
                        var body = new FormData();
                        body.append('action', 'kit_set_delivery_status');
                        body.append('nonce', wrap.getAttribute('data-nonce') || '');
                        body.append('delivery_id', wrap.getAttribute('data-delivery-id') || '');
                        body.append('status', pending);
                        var ajax = wrap.getAttribute('data-ajax')
                            || (window.myPluginAjax && myPluginAjax.ajax_url)
                            || window.ajaxurl
                            || '/wp-admin/admin-ajax.php';
                        return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
                            .then(function (r) { return r.json(); })
                            .then(function (json) {
                                if (!json || !json.success) {
                                    select.disabled = false;
                                    copyEl.textContent = (json && json.data && json.data.message)
                                        ? json.data.message
                                        : 'Could not change status.';
                                    throw new Error(copyEl.textContent);
                                }
                                var url = new URL(window.location.href);
                                url.searchParams.set('status_changed', pending);
                                window.location.href = url.toString();
                                return json;
                            })
                            .catch(function (err) {
                                select.disabled = false;
                                if (!copyEl.textContent) {
                                    copyEl.textContent = 'Network error. Try again.';
                                }
                                throw err;
                            });
                    };

                    select.addEventListener('change', function () {
                        var next = select.value;
                        var current = wrap.getAttribute('data-current') || '';
                        if (!next || next === current) return;
                        pending = next;
                        var count = parseInt(wrap.getAttribute('data-waybills') || '0', 10);
                        copyEl.textContent = 'Change this truck from ' + (labels[current] || current) +
                            ' to ' + (labels[next] || next) + '? This updates all ' + count +
                            ' waybill(s) currently on this truck. Waybills added later will also use the new status.';
                        overlay.classList.remove('hidden');
                    });

                    overlay.addEventListener('click', function (e) {
                        if (e.target === overlay) window.kitDeliveryStatusCancel();
                    });
                    document.addEventListener('keydown', function (e) {
                        if (e.key === 'Escape' && !overlay.classList.contains('hidden')) {
                            window.kitDeliveryStatusCancel();
                        }
                    });
                })();

                (function () {
                    var form = document.getElementById('kit-truck-details-edit');
                    if (!form) return;
                    var count = parseInt(form.getAttribute('data-waybill-count') || '0', 10);
                    var origOrigin = form.getAttribute('data-origin-country') || '';
                    var origDest = form.getAttribute('data-dest-country') || '';
                    var origCity = form.getAttribute('data-dest-city') || '';
                    form.addEventListener('submit', function (e) {
                        var origin = form.querySelector('[name="origin_country"]');
                        var dest = form.querySelector('[name="destination_country"]');
                        var city = form.querySelector('[name="destination_city"]');
                        var date = form.querySelector('[name="dispatch_date"]');
                        var driver = form.querySelector('[name="driver_id"]');
                        if (origin && !origin.value) {
                            e.preventDefault();
                            origin.focus();
                            return;
                        }
                        if (dest && !dest.value) {
                            e.preventDefault();
                            dest.focus();
                            return;
                        }
                        if (city && !city.value) {
                            e.preventDefault();
                            city.focus();
                            return;
                        }
                        if (date && !date.value) {
                            e.preventDefault();
                            date.focus();
                            return;
                        }
                        if (driver && !driver.value) {
                            e.preventDefault();
                            driver.focus();
                            return;
                        }
                        if (count < 1) return;
                        var changed = (origin && String(origin.value) !== String(origOrigin))
                            || (dest && String(dest.value) !== String(origDest))
                            || (city && String(city.value) !== String(origCity));
                        if (!changed) return;
                        var msg = 'This truck has ' + count + ' waybill(s). Changing the route may not match those loads. Save anyway?';
                        if (!window.confirm(msg)) {
                            e.preventDefault();
                        }
                    });
                })();
                </script>
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
    /**
     * @param string     $name                 Input name (e.g. destination_country)
     * @param string     $id                   Select id (e.g. stepDestinationSelect)
     * @param int|null   $selected_country_id    Selected country row id
     * @param bool       $required             HTML required attribute
     * @param bool       $include_scripts      Inline scripts (waybill legacy); set false for Edit Delivery modal
     */
    public static function CountrySelect($name = '', $id = '', $selected_country_id = null, $required = true, $include_scripts = true)
    {
        $required_attr = $required ? 'required' : '';
        //get all the countires with the is_active = 1
        $countries = self::getCountriesObject();
        if (empty($countries)) {
            return '<p class="text-red-500">No active countries found.</p>';
        }
        if ($include_scripts && $selected_country_id): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const select = document.getElementById('<?php echo esc_js($id); ?>');
                    if (select) {
                        handleCountryChange(select.value, '<?php echo esc_js((string) $name); ?>');
                    }
                });
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
                    <?php echo ((int) $selected_country_id === (int) $country->id) ? 'selected' : ''; ?>>
                    <?php echo esc_html($country->country_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php
        return ob_get_clean();
    }

    /**
     * Flat edit form (no stepper). Used if an existing delivery is passed to deliveryForm().
     *
     * @param object $delivery
     * @param array  $options is_modal, cancel_url, waybill_count
     * @return string
     */
    public static function render_delivery_edit_form($delivery, array $options = [])
    {
        if (! $delivery) {
            return '';
        }

        $is_modal = ! empty($options['is_modal']);
        $cancel_url = isset($options['cancel_url']) ? (string) $options['cancel_url'] : '';
        $waybill_count = isset($options['waybill_count']) ? (int) $options['waybill_count'] : 0;
        $delivery_id = (int) ($delivery->delivery_id ?? $delivery->id ?? 0);

        if ($waybill_count < 1 && $delivery_id > 0 && class_exists('KIT_Waybills') && method_exists('KIT_Waybills', 'truckWaybills')) {
            $rows = KIT_Waybills::truckWaybills($delivery_id);
            $waybill_count = is_array($rows) ? count($rows) : 0;
        }

        if ($cancel_url === '' && $delivery_id > 0) {
            $cancel_url = self::delivery_view_url($delivery_id);
        }

        $form_id = 'edit-delivery-form' . ($is_modal ? '-m-' . $delivery_id : '');
        $drivers = self::get_all_drivers();
        $selected_driver_id = $delivery->driver_id ?? '';

        ob_start();
        ?>
        <form id="<?php echo esc_attr($form_id); ?>"
            method="POST"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            class="space-y-4"
            data-waybill-count="<?php echo esc_attr((string) $waybill_count); ?>"
            data-origin-country="<?php echo esc_attr((string) ($delivery->origin_country_id ?? '')); ?>"
            data-dest-country="<?php echo esc_attr((string) ($delivery->destination_country_id ?? '')); ?>"
            data-dest-city="<?php echo esc_attr((string) ($delivery->destination_city_id ?? '')); ?>">
            <input type="hidden" name="action" value="kit_deliveries_crud">
            <input type="hidden" name="task" value="update_delivery">
            <input type="hidden" name="delivery_id" value="<?php echo esc_attr((string) $delivery_id); ?>">
            <input type="hidden" name="direction_id" value="<?php echo esc_attr((string) ($delivery->direction_id ?? 0)); ?>">
            <input type="hidden" name="delivery_reference" value="<?php echo esc_attr((string) $delivery->delivery_reference); ?>">
            <input type="hidden" name="status" value="<?php echo esc_attr((string) ($delivery->status ?? 'scheduled')); ?>">
            <?php wp_nonce_field('get_waybills_nonce', 'nonce'); ?>

            <p class="font-mono text-xs font-semibold text-gray-800"><?php echo esc_html($delivery->delivery_reference); ?></p>

            <div class="kit-truck-edit-fields space-y-3">
                <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsOrigin.php'; ?>
                <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsDestination.php'; ?>
            </div>

            <?php if ($waybill_count > 0) : ?>
                <p class="text-xs text-amber-800">
                    <?php
                    echo esc_html(sprintf(
                        _n(
                            'This truck has %d waybill. Changing the route may not match that load.',
                            'This truck has %d waybills. Changing the route may not match those loads.',
                            $waybill_count,
                            '08600-services-quotations'
                        ),
                        $waybill_count
                    ));
                    ?>
                </p>
            <?php endif; ?>

            <div>
                <label for="dispatch_date<?php echo $is_modal ? '-m-' . (int) $delivery_id : ''; ?>" class="<?php echo esc_attr(KIT_Commons::labelClass()); ?>">
                    <?php esc_html_e('Departure Date', '08600-services-quotations'); ?>
                </label>
                <?php
                echo KIT_Commons::Ldate([
                    'no_label' => true,
                    'label'    => '',
                    'name'     => 'dispatch_date',
                    'id'       => 'dispatch_date' . ($is_modal ? '-m-' . (int) $delivery_id : ''),
                    'value'    => $delivery->dispatch_date ? (string) $delivery->dispatch_date : '',
                    'preset'   => '',
                    'class'    => KIT_Commons::selectClass(),
                    'special'  => 'required',
                ]);
                ?>
            </div>

            <div>
                <label for="driver_id<?php echo $is_modal ? '-m-' . (int) $delivery_id : ''; ?>" class="<?php echo esc_attr(KIT_Commons::labelClass()); ?>">
                    <?php esc_html_e('Driver', '08600-services-quotations'); ?>
                </label>
                <select name="driver_id" id="driver_id<?php echo $is_modal ? '-m-' . (int) $delivery_id : ''; ?>" required
                    class="<?php echo esc_attr(KIT_Commons::selectClass()); ?>">
                    <option value=""><?php esc_html_e('Select Driver', '08600-services-quotations'); ?></option>
                    <?php foreach ($drivers as $driver) : ?>
                        <option value="<?php echo esc_attr($driver->id); ?>"
                            <?php selected((string) $selected_driver_id, (string) $driver->id); ?>>
                            <?php echo esc_html($driver->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 pt-2">
                <?php
                echo KIT_Commons::renderButton(
                    __('Save truck', '08600-services-quotations'),
                    'primary',
                    'lg',
                    [
                        'type'         => 'submit',
                        'classes'      => 'flex-1',
                        'icon'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />',
                        'iconPosition' => 'left',
                    ]
                );
                if ($is_modal) {
                    echo KIT_Commons::renderButton(
                        __('Cancel', '08600-services-quotations'),
                        'secondary',
                        'lg',
                        [
                            'type'    => 'button',
                            'classes' => 'flex-1 modal-close',
                        ]
                    );
                } elseif ($cancel_url !== '') {
                    echo KIT_Commons::renderButton(
                        __('Cancel', '08600-services-quotations'),
                        'secondary',
                        'lg',
                        [
                            'href'    => esc_url($cancel_url),
                            'classes' => 'flex-1',
                        ]
                    );
                }
                ?>
            </div>
        </form>
        <script>
        (function () {
            var form = document.getElementById(<?php echo wp_json_encode($form_id); ?>);
            if (!form || form.getAttribute('data-kit-route-guard') === '1') return;
            form.setAttribute('data-kit-route-guard', '1');
            var count = parseInt(form.getAttribute('data-waybill-count') || '0', 10);
            var origOrigin = form.getAttribute('data-origin-country') || '';
            var origDest = form.getAttribute('data-dest-country') || '';
            var origCity = form.getAttribute('data-dest-city') || '';
            form.addEventListener('submit', function (e) {
                if (count < 1) return;
                var origin = form.querySelector('[name="origin_country"]');
                var dest = form.querySelector('[name="destination_country"]');
                var city = form.querySelector('[name="destination_city"]');
                var changed = (origin && String(origin.value) !== String(origOrigin))
                    || (dest && String(dest.value) !== String(origDest))
                    || (city && String(city.value) !== String(origCity));
                if (!changed) return;
                if (!window.confirm('This truck has ' + count + ' waybill(s). Changing the route may not match those loads. Save anyway?')) {
                    e.preventDefault();
                }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Flat create form (no stepper). Same fields as edit, laid out in one view.
     *
     * @param array $options is_modal, cancel_url
     * @return string
     */
    public static function render_delivery_create_form(array $options = [])
    {
        $is_modal = ! empty($options['is_modal']);
        $cancel_url = isset($options['cancel_url']) ? (string) $options['cancel_url'] : '';

        if ($cancel_url === '') {
            $cancel_url = function_exists('kit_using_employee_portal') && kit_using_employee_portal()
                ? kit_employee_portal_url('kit-deliveries')
                : admin_url('admin.php?page=kit-deliveries');
        }

        $modal_dom_suffix = $is_modal ? '-m-new' : '';
        $form_id = 'create-delivery-form' . $modal_dom_suffix;
        $drivers = self::get_all_drivers();
        $delivery_reference = self::generateDeliveryRef();
        $delivery = null;

        $add_driver_url = function_exists('kit_using_employee_portal') && kit_using_employee_portal()
            ? kit_employee_portal_url('manage-drivers', ['add' => '1'])
            : admin_url('admin.php?page=manage-drivers&add=1');

        $statuses = [
            'scheduled'   => __('Scheduled', '08600-services-quotations'),
            'unconfirmed' => __('Unconfirmed', '08600-services-quotations'),
            'in_transit'  => __('In Transit', '08600-services-quotations'),
            'delivered'   => __('Delivered', '08600-services-quotations'),
        ];

        ob_start();
        ?>
        <?php if (! $is_modal) : ?>
            <div class="kit-trip-create-shell max-w-4xl mx-auto">
        <?php endif; ?>
        <form id="<?php echo esc_attr($form_id); ?>"
            method="POST"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            class="kit-trip-create-form <?php echo $is_modal ? 'kit-trip-create-form--modal space-y-4' : 'bg-white border border-gray-200 p-4 sm:p-6 space-y-5'; ?>"
            autocomplete="off">
            <input type="hidden" name="action" value="kit_deliveries_crud">
            <input type="hidden" name="task" value="create_delivery">
            <input type="hidden" name="delivery_id" value="0">
            <input type="hidden" name="direction_id" value="0">
            <input type="hidden" name="delivery_reference" value="<?php echo esc_attr($delivery_reference); ?>">
            <?php wp_nonce_field('get_waybills_nonce', 'nonce'); ?>

            <div>
                <span class="<?php echo esc_attr(KIT_Commons::labelClass()); ?>">
                    <?php esc_html_e('Reference', '08600-services-quotations'); ?>
                </span>
                <p class="font-mono text-sm font-semibold text-gray-900 m-0"><?php echo esc_html($delivery_reference); ?></p>
            </div>

            <div class="kit-trip-create-route">
                <div class="kit-trip-create-route__leg min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 m-0 mb-2">
                        <?php esc_html_e('Origin', '08600-services-quotations'); ?>
                    </p>
                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsOrigin.php'; ?>
                </div>
                <div class="kit-trip-create-route__leg min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 m-0 mb-2">
                        <?php esc_html_e('Destination', '08600-services-quotations'); ?>
                    </p>
                    <?php require COURIER_FINANCE_PLUGIN_PATH . 'includes/components/selectsDestination.php'; ?>
                </div>
            </div>

            <div class="kit-trip-meta">
                <div class="min-w-0">
                    <label for="dispatch_date<?php echo esc_attr($modal_dom_suffix); ?>" class="<?php echo esc_attr(KIT_Commons::labelClass()); ?>">
                        <?php esc_html_e('Departure Date', '08600-services-quotations'); ?>
                    </label>
                    <?php
                    echo KIT_Commons::Ldate([
                        'no_label' => true,
                        'label'    => '',
                        'name'     => 'dispatch_date',
                        'id'       => 'dispatch_date' . $modal_dom_suffix,
                        'value'    => '',
                        'preset'   => '',
                        'class'    => KIT_Commons::selectClass(),
                        'special'  => 'required',
                    ]);
                    ?>
                </div>
                <div class="min-w-0">
                    <label for="driver_id<?php echo esc_attr($modal_dom_suffix); ?>" class="<?php echo esc_attr(KIT_Commons::labelClass()); ?>">
                        <?php esc_html_e('Driver', '08600-services-quotations'); ?>
                    </label>
                    <select name="driver_id" id="driver_id<?php echo esc_attr($modal_dom_suffix); ?>" required
                        class="<?php echo esc_attr(KIT_Commons::selectClass()); ?>">
                        <option value=""><?php esc_html_e('Select Driver', '08600-services-quotations'); ?></option>
                        <?php foreach ($drivers as $driver) : ?>
                            <option value="<?php echo esc_attr($driver->id); ?>">
                                <?php echo esc_html($driver->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($drivers)) : ?>
                        <p class="mt-1.5 text-xs text-amber-800 m-0">
                            <a href="<?php echo esc_url($add_driver_url); ?>" class="underline font-medium">
                                <?php esc_html_e('Add a driver first', '08600-services-quotations'); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="min-w-0">
                    <label for="status<?php echo esc_attr($modal_dom_suffix); ?>" class="<?php echo esc_attr(KIT_Commons::labelClass()); ?>">
                        <?php esc_html_e('Status', '08600-services-quotations'); ?>
                    </label>
                    <select name="status" id="status<?php echo esc_attr($modal_dom_suffix); ?>"
                        class="<?php echo esc_attr(KIT_Commons::selectClass()); ?>">
                        <?php foreach ($statuses as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($value, 'scheduled'); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex flex-col-reverse sm:flex-row gap-3 pt-2">
                <?php
                if ($is_modal) {
                    echo KIT_Commons::renderButton(
                        __('Cancel', '08600-services-quotations'),
                        'secondary',
                        'lg',
                        [
                            'type'    => 'button',
                            'classes' => 'w-full sm:flex-1 modal-close',
                        ]
                    );
                } elseif ($cancel_url !== '') {
                    echo KIT_Commons::renderButton(
                        __('Cancel', '08600-services-quotations'),
                        'secondary',
                        'lg',
                        [
                            'href'    => esc_url($cancel_url),
                            'classes' => 'w-full sm:flex-1',
                        ]
                    );
                }
                echo KIT_Commons::renderButton(
                    __('Create Trip', '08600-services-quotations'),
                    'primary',
                    'lg',
                    [
                        'type'         => 'submit',
                        'classes'      => 'w-full sm:flex-1',
                        'icon'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />',
                        'iconPosition' => 'left',
                    ]
                );
                ?>
            </div>
        </form>
        <style>
            .kit-create-trip-page .kit-trip-create-shell {
                margin-left: auto;
                margin-right: auto;
            }
            .kit-trip-create-form input,
            .kit-trip-create-form select,
            .wp-core-ui .kit-trip-create-form input,
            .wp-core-ui .kit-trip-create-form select {
                width: 100%;
                max-width: none !important;
                min-width: 0;
                box-sizing: border-box;
            }
            .kit-trip-create-route {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 1.25rem;
            }
            .kit-trip-create-route__leg {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 0.75rem;
                min-width: 0;
            }
            .kit-trip-create-route__leg .grid {
                display: grid;
                grid-template-columns: minmax(0, 1fr) !important;
                gap: 0.75rem;
            }
            .kit-trip-meta {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 1rem;
            }
            @media (min-width: 640px) {
                .kit-trip-create-route {
                    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                    gap: 1.5rem;
                    align-items: start;
                }
                .kit-trip-meta {
                    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                }
                .kit-trip-meta > :last-child {
                    grid-column: 1 / -1;
                }
            }
            @media (min-width: 900px) {
                .kit-trip-meta {
                    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr);
                }
                .kit-trip-meta > :last-child {
                    grid-column: auto;
                }
            }
            #add-delivery-truck-modal .kit-tailwind-modal-panel,
            #create-delivery-modal .kit-tailwind-modal-panel,
            #add-delivery-modal .kit-tailwind-modal-panel {
                width: min(96vw, 56rem);
                max-width: 56rem;
                border-radius: 0;
                box-shadow: none;
                border: 1px solid #d1d5db;
            }
            #add-delivery-truck-modal.kit-tailwind-modal-shell,
            #create-delivery-modal.kit-tailwind-modal-shell,
            #add-delivery-modal.kit-tailwind-modal-shell {
                backdrop-filter: none;
                background: rgba(0, 0, 0, 0.45);
            }
        </style>
        <?php if (! $is_modal) : ?>
            </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    public static function deliveryForm($delivery_id = null, $is_modal = false)
    {
        $delivery = null;
        if ($delivery_id) {
            $delivery = self::get_delivery($delivery_id);
        }

        if ($delivery) {
            return self::render_delivery_edit_form($delivery, [
                'is_modal' => $is_modal,
            ]);
        }

        if (class_exists('KIT_User_Roles') && ! KIT_User_Roles::can_create_delivery_truck()) {
            $msg = __('Request management to create a delivery truck.', '08600-services-quotations');
            return '<div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg">' . esc_html($msg) . '</div>';
        }

        return self::render_delivery_create_form([
            'is_modal' => $is_modal,
        ]);
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

    /**
     * Standalone Create Trip page — flat create form, full page.
     */
    public static function render_create_trip_page()
    {
        if (isset($_GET['delivery_error']) || isset($_GET['delivery_success'])) {
            if (file_exists(plugin_dir_path(__FILE__) . '../components/toast.php')) {
                require_once plugin_dir_path(__FILE__) . '../components/toast.php';
            }
            if (class_exists('KIT_Toast')) {
                KIT_Toast::ensure_toast_loads();
                if (isset($_GET['delivery_success']) && $_GET['delivery_success'] === '1') {
                    $message = isset($_GET['message']) ? urldecode((string) $_GET['message']) : __('Trip created successfully.', '08600-services-quotations');
                    echo KIT_Toast::success($message, 'Success');
                }
                if (isset($_GET['delivery_error'])) {
                    echo KIT_Toast::error(urldecode((string) $_GET['delivery_error']), 'Error');
                }
            }
        }

        wp_enqueue_script('jquery');
        if (class_exists('KIT_Commons') && method_exists('KIT_Commons', 'enqueueComponentScripts')) {
            KIT_Commons::enqueueComponentScripts(['kitscript', 'waybill-pagination']);
        }

        $list_url = function_exists('kit_using_employee_portal') && kit_using_employee_portal()
            ? kit_employee_portal_url('kit-deliveries')
            : admin_url('admin.php?page=kit-deliveries');

        echo '<div class="wrap deliveries-page kit-create-trip-page">';
        echo '<div class="' . esc_attr(KIT_Commons::containerClasses()) . '">';
        echo KIT_Commons::showingHeader([
            'title'   => __('Create Trip', '08600-services-quotations'),
            'desc'    => __('Schedule a truck trip: route, dispatch date, and driver.', '08600-services-quotations'),
            'icon'    => KIT_Commons::icon('truck'),
            'content' => KIT_Commons::renderButton(
                __('Back to Trips', '08600-services-quotations'),
                'secondary',
                'md',
                [
                    'href'         => $list_url,
                    'icon'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />',
                    'iconPosition' => 'left',
                    'noLoading'    => true,
                ]
            ),
        ]);
        echo self::deliveryForm(null, false);
        echo '</div></div>';
    }

    public static function render_admin_page()
    {
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

        // Unified table bulk delete (same POST pattern as Waybill Manage)
        if (
            isset($_POST['bulk_action'], $_POST['bulk_ids'], $_POST['bulk_nonce'])
            && sanitize_text_field(wp_unslash($_POST['bulk_action'])) === 'delete'
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bulk_nonce'])), 'bulk_action_nonce')
        ) {
            if (!current_user_can('kit_update_data') && !current_user_can('manage_options')) {
                wp_die('Unauthorized');
            }
            $raw = sanitize_text_field(wp_unslash($_POST['bulk_ids']));
            $ids = array_filter(array_map('intval', explode(',', $raw)));
            foreach ($ids as $did) {
                self::delete_delivery($did);
            }
            $redirect = wp_get_referer() ?: admin_url('admin.php?page=kit-deliveries');
            wp_safe_redirect(remove_query_arg(['bulk_deleted', '_wp_http_referer'], add_query_arg('bulk_deleted', count($ids), $redirect)));
            exit;
        }

        // Check if we're viewing a specific delivery
        if (isset($_GET['view_delivery']) && is_numeric($_GET['view_delivery'])) {
            // Set the delivery_id parameter for the view_deliveries_page function
            $_GET['delivery_id'] = $_GET['view_delivery'];
            self::view_deliveries_page();
            return;
        }

        self::auto_update_past_deliveries();

        // No JavaScript needed - pure PHP form submission

        // Initialize variables for modal (in case it's shown in the main deliveries list)
        $delivery_id = 0; // Default for main list view
        $customers = tholaMaCustomer();
        $customers_encoded = base64_encode(json_encode($customers));
        $form_action = admin_url('admin-post.php?action=add_waybill_action');

        $deliveries = self::get_all_deliveries();
        ?>

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
                        'lg',
                        true,
                        'Add Delivery Truck'
                    );
                    $header_content = $add_delivery_modal;
                } else {
                    $header_content = '<p class="text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2 text-sm">' . esc_html__('Request management to create a delivery truck.', '08600-services-quotations') . '</p>';
                }
                echo KIT_Commons::showingHeader([
                    'title'   => __('Trips', '08600-services-quotations'),
                    'desc'    => __('Manage and track truck trips', '08600-services-quotations'),
                    'icon'    => KIT_Commons::icon('truck'),
                    'content' => $header_content,
                ]);
                ?>

                <!-- Statistics Cards -->
                <?php
                echo KIT_QuickStats::render_for_context(KIT_QuickStats::CONTEXT_DELIVERIES, [
                    'deliveries' => $deliveries,
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
                            // Rows and row markup both come from the shared trip row
                            // template in KIT_Commons so every deliveries list matches.
                            $deliveries_data = array_map([KIT_Commons::class, 'tripRowData'], $deliveries);

                            echo KIT_Commons::prettyHeading([
                                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>',
                                'words' => 'All Deliveries',
                                'size' => 'lg',
                                'color' => 'blue',
                                'classes' => 'mb-4'
                            ]);

                            echo KIT_Unified_Table::infinite(
                                $deliveries_data,
                                KIT_Commons::tripRowColumns(),
                                KIT_Unified_Table::optionsWithManageDefaults(KIT_Commons::tripRowTableOptions([
                                    'title' => 'All Deliveries',
                                    'sync_entity' => 'deliveries',
                                    'bulk_actions_list' => ['delete', 'export', 'packing_list'],
                                    'actions' => KIT_Commons::tripRowActions(),
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
                                ]))
                            );
                            ?>
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
                class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-[100050] flex items-center justify-center p-4"
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

            // Leftover onclick handlers navigate to the truck details editor (no modal wizard).
            window.editDelivery = function(deliveryId) {
                if (!deliveryId || deliveryId === '' || deliveryId === '0') {
                    return false;
                }
                var url = <?php echo wp_json_encode(self::delivery_page_url('view-deliveries', ['delivery_id' => '__ID__', 'edit_delivery' => '1'])); ?>;
                window.location.href = url.replace('__ID__', String(deliveryId));
                return false;
            };
            window.deleteDelivery = function(deliveryId, event) {
                return false;
            };

            function closeEditDeliveryModal() {
                if (editDeliveryModalBootstrap && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var el = document.getElementById('edit-delivery-modal');
                    if (el) try {
                        bootstrap.Modal.getOrCreateInstance(el).hide();
                    } catch (err) {}
                } else {
                    // Must use jQuery, not $ — wp-admin runs jQuery in noConflict (global $ is undefined).
                    jQuery('#edit-delivery-modal').addClass('hidden').css('display', 'none');
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
                    $('#edit-delivery-modal').addClass('hidden').css('display', 'none');
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

                // Handle edit delivery — open truck details in edit mode (no wizard modal).
                window.editDelivery = function(deliveryId) {
                    if (!deliveryId || deliveryId === '' || deliveryId === '0') {
                        return false;
                    }
                    var url = <?php echo wp_json_encode(self::delivery_page_url('view-deliveries', ['delivery_id' => '__ID__', 'edit_delivery' => '1'])); ?>;
                    window.location.href = url.replace('__ID__', String(deliveryId));
                    return false;
                };

                // Inject server-rendered KIT_Deliveries::deliveryForm (see get_delivery → form_html)
                function loadDeliveryFormInModal(delivery, deliveryId) {
                    if (!delivery || !delivery.form_html) {
                        alert('Could not load delivery form.');
                        closeEditDeliveryModal();
                        return;
                    }
                    $('#modal-content').html(delivery.form_html);

                    var modalRoot = document.getElementById('edit-delivery-modal');
                    var formEl = modalRoot ? modalRoot.querySelector('form[id^="edit-delivery-form"]') : null;

                    if (formEl && typeof handleCountryChange === 'function') {
                        var oc = formEl.querySelector('select[name="origin_country"]');
                        var dc = formEl.querySelector('select[name="destination_country"]');
                        if (oc && oc.value) {
                            handleCountryChange(oc.value, 'origin_country');
                        }
                        if (dc && dc.value) {
                            handleCountryChange(dc.value, 'destination_country');
                        }
                    }

                    var dt = modalRoot ? modalRoot.querySelector('input[name="dispatch_date"]') : null;
                    if (dt) {
                        dt.removeAttribute('min');
                        dt.removeAttribute('data-min');
                    }
                }

                // Handle modal close (#close-modal has data-bs-dismiss on Bootstrap, but still bind for custom)
                $('#close-modal').on('click', function() {
                    closeEditDeliveryModal();
                });

                // Cancel inside server-rendered deliveryForm uses .modal-close (not #add-delivery-truck-modal shell)
                $(document).on('click', '#edit-delivery-modal .modal-close', function(e) {
                    e.preventDefault();
                    closeEditDeliveryModal();
                });

                // Close modal when clicking outside (custom modal only; Bootstrap handles backdrop)
                $('#edit-delivery-modal').on('click', function(e) {
                    if (e.target === this) closeEditDeliveryModal();
                });

                // Escape key (custom admin modal has no Bootstrap keyboard handler)
                $(document).on('keydown.kitEditDeliveryModal', function(e) {
                    if (editDeliveryModalBootstrap || e.key !== 'Escape') {
                        return;
                    }
                    var $m = $('#edit-delivery-modal');
                    if ($m.length && $m.css('display') !== 'none' && !$m.hasClass('hidden')) {
                        closeEditDeliveryModal();
                    }
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

                // Handle delete delivery (replaces early stub on window)
                window.deleteDelivery = function(deliveryId, event) {
                    if (confirm(
                            'Are you sure you want to delete this delivery? This will also delete all associated waybills and items. A backup will be created automatically.'
                        )) {
                        // Icon actions are anchors, so event.target can be the inner <svg>.
                        const deleteBtn = event ? (event.currentTarget || event.target) : $('[onclick*="deleteDelivery"]')[0];
                        const $deleteBtn = $(deleteBtn).closest('a, button');
                        const $row = $deleteBtn.closest('tr');

                        $row.addClass('is-deleting');
                        $row.find('button').prop('disabled', true);
                        $deleteBtn.attr('aria-busy', 'true');

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
                                    $row.removeClass('is-deleting');
                                    $row.find('button').prop('disabled', false);
                                    $deleteBtn.removeAttr('aria-busy');
                                    alert('Error deleting delivery: ' + (response.data?.message || response.data || 'Unknown error'));
                                }
                            },
                            error: function(xhr, status, error) {
                                $row.removeClass('is-deleting');
                                $row.find('button').prop('disabled', false);
                                $deleteBtn.removeAttr('aria-busy');
                                alert('Error deleting delivery: ' + error);
                            }
                        });
                    }
                };

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
                    $delivery_id = $task === 'update_delivery' ? ($data['delivery_id'] ?? 0) : $result;
                    self::delivery_after_save_redirect($task, $delivery_id, (bool) $result);
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
                $delivery_row = self::get_delivery($data['id']);
                if (! $delivery_row) {
                    wp_send_json_error('Delivery not found');
                    return;
                }
                $result = (array) $delivery_row;
                $result['route_html'] = self::render_edit_delivery_modal_route_fields($delivery_row);
                // Same markup as view-deliveries&edit_delivery=1 (deliveryForm with $is_modal false).
                $result['form_html'] = self::deliveryForm((int) $data['id'], false);
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
                    $delivery_id = $task === 'update_delivery' ? ($data['delivery_id'] ?? 0) : $result;
                    self::delivery_after_save_redirect($task, $delivery_id, (bool) $result);
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
                $delivery_row = self::get_delivery($data['id']);
                if (! $delivery_row) {
                    wp_send_json_error('Delivery not found');
                    return;
                }
                $result = (array) $delivery_row;
                $result['route_html'] = self::render_edit_delivery_modal_route_fields($delivery_row);
                // Same markup as view-deliveries&edit_delivery=1 (deliveryForm with $is_modal false).
                $result['form_html'] = self::deliveryForm((int) $data['id'], false);
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

    /**
     * Allowed kit_deliveries.status values and labels.
     *
     * @return array<string, string>
     */
    public static function allowed_delivery_statuses()
    {
        return [
            'scheduled'   => __('Scheduled', '08600-services-quotations'),
            'unconfirmed' => __('Unconfirmed', '08600-services-quotations'),
            'in_transit'  => __('In Transit', '08600-services-quotations'),
            'delivered'   => __('Delivered', '08600-services-quotations'),
        ];
    }

    /**
     * Map a delivery status onto the waybill status used after warehouse assign.
     */
    public static function map_delivery_status_to_waybill_status($delivery_status)
    {
        $status = strtolower(trim((string) $delivery_status));
        $map = [
            'scheduled'   => 'assigned',
            'unconfirmed' => 'assigned',
            'in_transit'  => 'in_transit',
            'delivered'   => 'delivered',
        ];

        return $map[$status] ?? 'assigned';
    }

    /**
     * Set delivery status and apply the mapped status to every waybill on that truck.
     *
     * @param int    $delivery_id
     * @param string $new_status
     * @return array{ok:bool,status:string,waybills:int}|WP_Error
     */
    public static function set_delivery_status($delivery_id, $new_status)
    {
        global $wpdb;

        $delivery_id = (int) $delivery_id;
        $new_status = strtolower(trim((string) $new_status));
        $allowed = self::allowed_delivery_statuses();

        if ($delivery_id <= 0) {
            return new WP_Error('invalid_delivery', 'Delivery not found.');
        }
        if (! isset($allowed[$new_status])) {
            return new WP_Error('invalid_status', 'Invalid delivery status.');
        }

        $table = $wpdb->prefix . 'kit_deliveries';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $delivery_id));
        if (! $exists) {
            return new WP_Error('invalid_delivery', 'Delivery not found.');
        }

        $updated = $wpdb->update(
            $table,
            ['status' => $new_status],
            ['id' => $delivery_id],
            ['%s'],
            ['%d']
        );
        if ($updated === false) {
            return new WP_Error('db_error', 'Could not update delivery status.');
        }

        $waybills = self::sync_waybill_statuses_for_delivery($delivery_id, $new_status);

        if (class_exists('Courier_Google_Sheets_Sync')) {
            $d = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $delivery_id));
            if ($d) {
                Courier_Google_Sheets_Sync::sync_delivery_update($d);
            }
        }

        return [
            'ok'       => true,
            'status'   => $new_status,
            'waybills' => $waybills,
        ];
    }

    /**
     * Push the delivery's mapped status onto all non-warehouse waybills on this truck.
     *
     * @return int Rows updated (false treated as 0).
     */
    public static function sync_waybill_statuses_for_delivery($delivery_id, $delivery_status)
    {
        global $wpdb;

        $delivery_id = (int) $delivery_id;
        if ($delivery_id <= 0) {
            return 0;
        }

        $waybill_status = self::map_delivery_status_to_waybill_status($delivery_status);
        $result = $wpdb->update(
            $wpdb->prefix . 'kit_waybills',
            [
                'status'          => $waybill_status,
                'last_updated_at' => current_time('mysql'),
                'last_updated_by' => get_current_user_id(),
            ],
            [
                'delivery_id' => $delivery_id,
                'warehouse'   => 0,
            ],
            ['%s', '%s', '%d'],
            ['%d', '%d']
        );

        return $result === false ? 0 : (int) $result;
    }

    public static function ajax_set_delivery_status()
    {
        if (! current_user_can('kit_view_waybills') && ! current_user_can('edit_pages')) {
            wp_send_json_error(['message' => 'You do not have permission to change delivery status.'], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (! wp_verify_nonce($nonce, 'kit_set_delivery_status')) {
            wp_send_json_error(['message' => 'Security check failed. Refresh and try again.'], 403);
        }

        $delivery_id = isset($_POST['delivery_id']) ? (int) $_POST['delivery_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
        $result = self::set_delivery_status($delivery_id, $status);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $labels = self::allowed_delivery_statuses();
        wp_send_json_success([
            'status'   => $result['status'],
            'label'    => $labels[$result['status']] ?? $result['status'],
            'waybills' => $result['waybills'],
            'message'  => sprintf(
                __('Status set to %s. %d waybill(s) on this truck updated.', '08600-services-quotations'),
                $labels[$result['status']] ?? $result['status'],
                (int) $result['waybills']
            ),
        ]);
    }

    //Change delivery status from schediled to intransit
    public static function delivery_changeTo_Intransit()
    {
        $id = intval($_POST['id'] ?? 0);
        $result = self::set_delivery_status($id, 'in_transit');
        return ! is_wp_error($result);
    }
    //Change delivery status from intransit to delivered
    public static function delivery_changeTo_Delivered()
    {
        $id = intval($_POST['id'] ?? 0);
        $result = self::set_delivery_status($id, 'delivered');
        return ! is_wp_error($result);
    }
    //Change delivery status scheduled
    public static function delivery_changeTo_Scheduled()
    {
        $id = intval($_POST['id'] ?? 0);
        $result = self::set_delivery_status($id, 'scheduled');
        return ! is_wp_error($result);
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
            'status'              => in_array((string) ($data['status'] ?? ''), ['scheduled', 'unconfirmed', 'in_transit', 'delivered'], true)
                ? (string) $data['status']
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

            self::sync_waybill_statuses_for_delivery((int) $data['delivery_id'], $delivery_data['status']);

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

    /**
     * Route fields for Edit Delivery modal: simple destination country + city (same ids as waybill step 2).
     * Origin is shown above.
     *
     * @param object $delivery Row from get_delivery()
     */
    public static function render_edit_delivery_modal_route_fields($delivery)
    {
        if (! is_object($delivery)) {
            return '';
        }

        $origin_country_id       = isset($delivery->origin_country_id) ? (int) $delivery->origin_country_id : 0;
        $destination_country_id = isset($delivery->destination_country_id) ? (int) $delivery->destination_country_id : 0;
        $destination_city_id    = isset($delivery->destination_city_id) ? (int) $delivery->destination_city_id : 0;
        $selected_dest_country   = $destination_country_id > 0 ? $destination_country_id : null;

        ob_start();
        ?>
        <div class="mb-6">
            <label class="<?= KIT_Commons::labelClass() ?>" for="origin_country_select"><?php esc_html_e('Origin Country', '08600-services-quotations'); ?></label>
            <?php echo self::selectAllCountries('origin_country', 'origin_country_select', $origin_country_id, '', 'origin', []); ?>
        </div>

        <div class="bg-white p-6 rounded-lg border border-gray-200 space-y-5">
            <div>
                <h3 class="text-lg font-semibold text-gray-900"><?php esc_html_e('Delivery & Destination', '08600-services-quotations'); ?></h3>
                <p class="text-xs text-gray-600 mt-1"><?php esc_html_e('Destination country and city for this truck.', '08600-services-quotations'); ?></p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-3xl">
                <div id="waybill-form-country">
                    <label for="stepDestinationSelect" class="<?= KIT_Commons::labelClass() ?>"><?php esc_html_e('Destination Country', '08600-services-quotations'); ?></label>
                    <?php echo self::CountrySelect('destination_country', 'stepDestinationSelect', $selected_dest_country, true, false); ?>
                </div>
                <div>
                    <label for="destination_city" class="<?= KIT_Commons::labelClass() ?>"><?php esc_html_e('Destination City', '08600-services-quotations'); ?></label>
                    <div id="destinationWrap" data-select-class="<?= esc_attr(KIT_Commons::selectClass()); ?>">
                        <?php
                        echo self::selectAllCitiesByCountry(
                            'destination_city',
                            'destination_city',
                            $destination_country_id,
                            $destination_city_id > 0 ? $destination_city_id : 0,
                            'required'
                        );
                        ?>
                    </div>
                </div>
            </div>

            <input type="hidden" name="destination_country_backup" id="destination_country_backup" value="" />
        </div>
        <?php
        return (string) ob_get_clean();
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
                        'scheduled'   => 'bg-blue-100 text-blue-800',
                        'unconfirmed' => 'bg-gray-100 text-gray-800',
                        'in_transit'  => 'bg-yellow-100 text-yellow-800',
                        'delivered'   => 'bg-green-100 text-green-800',
                        'cancelled'   => 'bg-red-100 text-red-800',
                        default       => 'bg-gray-100 text-gray-800'
                    };
                    ?>
                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>">
                        <?php echo esc_html(ucfirst(str_replace('_', ' ', (string) ($delivery->status ?? 'unknown')))); ?>
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
                                'href'    => self::delivery_view_url($delivery->id),
                                'classes' => 'view-delivery-btn bg-transparent shadow-none border-0 px-0 py-0 text-blue-600 hover:text-blue-900',
                            ]
                        );

                        echo KIT_Commons::renderButton(
                            'Edit',
                            'ghost',
                            'lg',
                            [
                                'href'    => self::delivery_view_url($delivery->id, ['edit_delivery' => '1']),
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
    }

    echo '</tbody></table>';
}
