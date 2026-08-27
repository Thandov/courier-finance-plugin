<?php

// Exit if accessed directly
if (! defined('ABSPATH')) {
    exit;
}

class KIT_Company
{
    /** Default 08600 identity — used to seed empty DB rows and as fallbacks. */
    const COMPANY_ADDRESS = 'Unit 1, Kya North Park, 28 Bernie St, Kya Sands, Randburg, 2188';
    const COMPANY_PHONE = '+27813934500';
    const COMPANY_VAT_NUMBER = '4630317438';

    /**
     * Default company identity fields (seed / empty fallbacks).
     *
     * @return array<string, string>
     */
    public static function hardcoded_identity()
    {
        return [
            'company_address' => self::COMPANY_ADDRESS,
            'company_phone' => self::COMPANY_PHONE,
            'company_vat_number' => self::COMPANY_VAT_NUMBER,
        ];
    }

    /**
     * Prefer stored DB values; fill empty identity fields with defaults.
     *
     * @return array<string, mixed>
     */
    public static function get_details_array()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_company_details';
        $row = $wpdb->get_row("SELECT * FROM $table ORDER BY id ASC LIMIT 1", ARRAY_A);
        if (!is_array($row)) {
            $row = [];
        }

        $defaults = self::hardcoded_identity();
        foreach ($defaults as $key => $default) {
            if (!isset($row[$key]) || trim((string) $row[$key]) === '') {
                $row[$key] = $default;
            }
        }

        return $row;
    }

    public static function get_details()
    {
        return (object) self::get_details_array();
    }

    public static function update_details($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_company_details';
        $fields = [
            'company_name' => sanitize_text_field($data['company_name'] ?? ''),
            'company_address' => sanitize_textarea_field($data['company_address'] ?? self::COMPANY_ADDRESS),
            'company_email' => sanitize_email($data['company_email'] ?? ''),
            'company_phone' => sanitize_text_field($data['company_phone'] ?? self::COMPANY_PHONE),
            'company_website' => sanitize_text_field($data['company_website'] ?? ''),
            'company_registration' => sanitize_text_field($data['company_registration'] ?? ''),
            'company_vat_number' => sanitize_text_field($data['company_vat_number'] ?? self::COMPANY_VAT_NUMBER),
            'bank_name' => sanitize_text_field($data['bank_name'] ?? ''),
            'account_number' => sanitize_text_field($data['account_number'] ?? ''),
            'branch_code' => sanitize_text_field($data['branch_code'] ?? ''),
            'account_type' => sanitize_text_field($data['account_type'] ?? ''),
            'account_holder' => sanitize_text_field($data['account_holder'] ?? ''),
            'swift_code' => sanitize_text_field($data['swift_code'] ?? ''),
            'iban' => sanitize_text_field($data['iban'] ?? ''),
            'vat_percentage' => floatval($data['vat_percentage'] ?? 15),
            'sadc_charge' => floatval($data['sadc_charge'] ?? 0),
            'sad500_charge' => floatval($data['sad500_charge'] ?? 0),
        ];
        $id = $wpdb->get_var("SELECT id FROM $table ORDER BY id ASC LIMIT 1");
        if ($id) {
            return (false !== $wpdb->update($table, $fields, ['id' => intval($id)]));
        }
        return (bool) $wpdb->insert($table, $fields);
    }
}

/**
 * Check if the service name already exists in the database.
 *
 * @param string $service_name The service name to check.
 * @return bool True if exists, false otherwise.
 */
function service_name_exists($service_name)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_services';

    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE name = %s",
        $service_name
    ));

    return ($result > 0);
}

/**
 * Create a new service.
 *
 * @param string $name The service name.
 * @param string $description The service description.
 * @param string $image The service image URL.
 * @return bool|int The inserted service ID on success, false on failure.
 */
function create_service($name, $description, $image = null)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_services';

    if (service_name_exists($name)) {
        return new WP_Error('service_exists', 'A service with this name already exists.');
    }

    $result = $wpdb->insert(
        $table_name,
        array(
            'name' => $name,
            'description' => $description,
            'image' => $image,
        ),
        array(
            '%s', // name
            '%s', // description
            '%s'  // image (can be null)
        )
    );

    return ($result !== false) ? $wpdb->insert_id : false;
}

/**
 * Get all services.
 *
 * @return array Array of services.
 */
function get_all_services()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_services';

    return $wpdb->get_results("SELECT * FROM $table_name");
}

/**
 * Get a single service by ID.
 *
 * @param int $service_id The service ID.
 * @return object|null The service object, or null if not found.
 */
function get_service_by_id($service_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_services';

    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $service_id));
}

/**
 * Update an existing service.
 *
 * @param int $service_id The service ID.
 * @param string $name The new service name.
 * @param string $description The new service description.
 * @param string $image The new service image URL.
 * @return bool True on success, false on failure.
 */
function update_service($service_id, $name, $description, $image = null)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_services';

    if (service_name_exists($name)) {
        return new WP_Error('service_exists', 'A service with this name already exists.');
    }

    $result = $wpdb->update(
        $table_name,
        array(
            'name' => $name,
            'description' => $description,
            'image' => $image,
        ),
        array('id' => $service_id),
        array(
            '%s', // name
            '%s', // description
            '%s'  // image (can be null)
        ),
        array('%d') // id
    );

    return ($result !== false);
}

/**
 * Delete a service.
 *
 * @param int $service_id The service ID.
 * @return bool True on success, false on failure.
 */
function delete_service($service_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_services';

    $result = $wpdb->delete(
        $table_name,
        array('id' => $service_id),
        array('%d')
    );

    return ($result !== false);
}

/**
 * Marketing services shown on the public site (homepage + Services page).
 *
 * @return array<int, array{name:string,description:string,image:string,slug:string}>
 */
function kit_default_frontend_services()
{
    return [
        [
            'name' => 'Freight Forwarding',
            'description' => 'We provide end-to-end freight forwarding services across air, sea, and road freight, ensuring timely delivery.',
            'image' => 'flaticon-air-freight',
            'slug' => 'freight-forwarding',
        ],
        [
            'name' => 'Customs Clearance',
            'description' => 'We offer efficient customs clearance services, ensuring smooth compliance with regulations across the SADC region.',
            'image' => 'flaticon-delivery-man',
            'slug' => 'customs-clearance',
        ],
        [
            'name' => 'Warehousing',
            'description' => 'Our secure and efficient warehousing services use the latest technology for optimal storage and product distribution.',
            'image' => 'flaticon-wholesale',
            'slug' => 'warehousing',
        ],
        [
            'name' => 'Transportation',
            'description' => 'We provide reliable transportation services with a well-maintained fleet, ensuring safety, punctuality, and efficiency.',
            'image' => 'flaticon-truck',
            'slug' => 'transportation',
        ],
        [
            'name' => 'Distribution',
            'description' => 'Our distribution solutions streamline your supply chain, ensuring your products reach the market efficiently and timely.',
            'image' => 'flaticon-pallet',
            'slug' => 'distribution',
        ],
    ];
}

/**
 * Insert default homepage services when kit_services is empty.
 */
function kit_seed_default_services()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_services';

    $table_exists = $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
        DB_NAME,
        $table_name
    ));
    if ((int) $table_exists === 0) {
        return 0;
    }

    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    if ($count > 0) {
        return 0;
    }

    $inserted = 0;
    foreach (kit_default_frontend_services() as $service) {
        $ok = $wpdb->insert(
            $table_name,
            [
                'name' => $service['name'],
                'description' => $service['description'],
                'image' => $service['image'],
            ],
            ['%s', '%s', '%s']
        );
        if ($ok !== false) {
            $inserted++;
        }
    }

    return $inserted;
}

/**
 * @return array<int, object>
 */
function kit_get_frontend_services()
{
    kit_seed_default_services();

    $services = get_all_services();
    if (!empty($services)) {
        return $services;
    }

    $fallback = [];
    foreach (kit_default_frontend_services() as $service) {
        $fallback[] = (object) $service;
    }

    return $fallback;
}

function kit_service_read_more_url($service)
{
    $slug = '';
    if (is_object($service) && !empty($service->slug)) {
        $slug = (string) $service->slug;
    } elseif (is_object($service) && !empty($service->name)) {
        $slug = sanitize_title((string) $service->name);
    }

    if ($slug === '') {
        return home_url('/services');
    }

    return home_url('/' . $slug);
}

function kit_services_shortcode() {
    $services = kit_get_frontend_services();

    $output = '<div class="service-slider owl-carousel">';
    foreach ($services as $service) {
        $output .= '<div class="single-serv-item">';
        $output .= '<div class="serv-icon">';
        $output .= '<i class="' . esc_attr($service->image) . '"></i>';
        $output .= '</div>';
        $output .= '<div class="serv-content">';
        $output .= '<h5>' . esc_html($service->name) . '</h5>';
        $output .= '<p>' . esc_html($service->description) . '</p>';
        $output .= '</div>';
        $output .= '<a href="' . esc_url(kit_service_read_more_url($service)) . '" class="read-more">Read More</a>';
        $output .= '</div>';
    }
    $output .= '</div>';

    return $output;
}
add_shortcode( 'kit_services', 'kit_services_shortcode' );
add_action('init', 'kit_seed_default_services', 30);

function kit_services_blocks() {
    $services = kit_get_frontend_services();

    $output = '';
    foreach ($services as $service) {
        $output .= '<div class="col-lg-4 col-md-6 col-12">';
        $output .= '<div class="single-serv-item">';
        $output .= '<div class="serv-icon">';
        $output .= '<i class="' . esc_attr($service->image) . '"></i>';
        $output .= '</div>';
        $output .= '<div class="serv-content">';
        $output .= '<h5>' . esc_html($service->name) . '</h5>';
        $output .= '<p>' . esc_html($service->description) . '</p>';
        $output .= '</div>';
        $output .= '<a href="' . esc_url(kit_service_read_more_url($service)) . '" class="read-more">Read More</a>';
        $output .= '</div>';
        $output .= '</div>';
    }

    return $output;
}
add_shortcode( 'kit_services_blocks', 'kit_services_blocks' );