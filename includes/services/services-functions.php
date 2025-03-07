<?php

// Exit if accessed directly
if (! defined('ABSPATH')) {
    exit;
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

function kit_services_shortcode() {
    ob_start(); // Start output buffering

    global $wpdb;
    $services_table_name = $wpdb->prefix . 'kit_services';

    // Get all services
    $services = $wpdb->get_results( "SELECT * FROM $services_table_name" );

    if ( empty( $services ) ) {
        return '<p>No services available.</p>';
    }

    // Output services in a list
    $output = '<div class="service-slider owl-carousel">';
    foreach ( $services as $service ) {
        $output .= '<div class="single-serv-item">';
        $output .= '<div class="serv-icon">';
        $output .= '<i class="' . esc_html( $service->image ) . '"></i>';
        $output .= '</div>';
        $output .= '<div class="serv-content">';
        $output .= '<h5>' . esc_html( $service->name ) . '</h5>';
        $output .= '<p>' . esc_html( $service->description ) . '</p>';
        $output .= '</div>';
        $output .= '<a href="' . esc_html( $service->name ) . '" class="read-more">Read More</a>';
        $output .= '</div>';
    }
    $output .= '</div>';

    return ob_get_clean() . $output; // Return buffered content
}
add_shortcode( 'kit_services', 'kit_services_shortcode' );

function kit_services_blocks() {
    ob_start(); // Start output buffering

    global $wpdb;
    $services_table_name = $wpdb->prefix . 'kit_services';

    // Get all services
    $services = $wpdb->get_results( "SELECT * FROM $services_table_name" );

    if ( empty( $services ) ) {
        return '<p>No services available.</p>';
    }
    $output = ''; // Initialize output before the loop
    foreach ( $services as $service ) {
        $output .= '<div class="col-lg-4 col-md-6 col-12">';
        $output .= '<div class="single-serv-item">';
        $output .= '<div class="serv-icon">';
        $output .= '<i class="' . esc_html( $service->image ) . '"></i>';
        $output .= '</div>';
        $output .= '<div class="serv-content">';
        $output .= '<h5>' . esc_html( $service->name ) . '</h5>';
        $output .= '<p>' . esc_html( $service->description ) . '</p>';
        $output .= '</div>';
        $output .= '<a href="' . esc_html( $service->name ) . '" class="read-more">Read More</a>';
        $output .= '</div>';
        $output .= '</div>';
    }
    return ob_get_clean() . $output; // Return buffered content
}
add_shortcode( 'kit_services_blocks', 'kit_services_blocks' );