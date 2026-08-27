<?php
/**
 * Customer booking requests (quote intent — not live waybills).
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_Booking_Requests
{
    /**
     * @return string
     */
    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'kit_booking_requests';
    }

    public static function ensure_table()
    {
        if (class_exists('KIT_Database') && method_exists('KIT_Database', 'create_booking_requests_table')) {
            KIT_Database::create_booking_requests_table();
        }
    }

    /**
     * @param int $id
     * @return array<string,mixed>|null
     */
    public static function get($id)
    {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        self::ensure_table();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * @param int $cust_id
     * @return list<array<string,mixed>>
     */
    public static function list_for_customer($cust_id)
    {
        global $wpdb;
        $cust_id = (int) $cust_id;
        if ($cust_id <= 0) {
            return array();
        }
        self::ensure_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE customer_id = %d ORDER BY id DESC',
                $cust_id
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function list_all($status = '')
    {
        global $wpdb;
        self::ensure_table();
        $sql = 'SELECT * FROM ' . self::table_name();
        $params = array();
        if ($status !== '') {
            $sql .= ' WHERE status = %s';
            $params[] = $status;
        }
        $sql .= ' ORDER BY id DESC';
        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $data
     * @return int|false
     */
    public static function insert($data)
    {
        global $wpdb;
        self::ensure_table();
        $ok = $wpdb->insert(
            self::table_name(),
            array(
                'customer_id'            => (int) ($data['customer_id'] ?? 0),
                'origin_country_id'      => (int) ($data['origin_country_id'] ?? 0) ?: null,
                'origin_city_id'         => (int) ($data['origin_city_id'] ?? 0) ?: null,
                'destination_country_id' => (int) ($data['destination_country_id'] ?? 0) ?: null,
                'destination_city_id'    => (int) ($data['destination_city_id'] ?? 0) ?: null,
                'parcel_count'           => max(1, (int) ($data['parcel_count'] ?? 1)),
                'weight_kg'              => isset($data['weight_kg']) && $data['weight_kg'] !== '' ? (float) $data['weight_kg'] : null,
                'length_cm'              => isset($data['length_cm']) && $data['length_cm'] !== '' ? (float) $data['length_cm'] : null,
                'width_cm'               => isset($data['width_cm']) && $data['width_cm'] !== '' ? (float) $data['width_cm'] : null,
                'height_cm'              => isset($data['height_cm']) && $data['height_cm'] !== '' ? (float) $data['height_cm'] : null,
                'service_note'           => sanitize_text_field($data['service_note'] ?? ''),
                'notes'                  => sanitize_textarea_field($data['notes'] ?? ''),
                'status'                 => 'pending',
                'created_by'             => get_current_user_id() ?: null,
                'created_at'             => current_time('mysql'),
            )
        );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * @param int $id
     * @param int $waybill_id
     */
    public static function mark_converted($id, $waybill_id)
    {
        global $wpdb;
        $id = (int) $id;
        $waybill_id = (int) $waybill_id;
        if ($id <= 0 || $waybill_id <= 0) {
            return false;
        }
        self::ensure_table();
        return false !== $wpdb->update(
            self::table_name(),
            array(
                'status'     => 'converted',
                'waybill_id' => $waybill_id,
            ),
            array('id' => $id),
            array('%s', '%d'),
            array('%d')
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function city_label($city_id)
    {
        global $wpdb;
        $city_id = (int) $city_id;
        if ($city_id <= 0) {
            return '';
        }
        $name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT city_name FROM {$wpdb->prefix}kit_operating_cities WHERE id = %d LIMIT 1",
                $city_id
            )
        );
        return is_string($name) ? $name : '';
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function country_label($country_id)
    {
        global $wpdb;
        $country_id = (int) $country_id;
        if ($country_id <= 0) {
            return '';
        }
        $name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT country_name FROM {$wpdb->prefix}kit_operating_countries WHERE id = %d LIMIT 1",
                $country_id
            )
        );
        return is_string($name) ? $name : '';
    }

    /**
     * @param array<string,mixed> $row
     * @return string
     */
    public static function format_notes_for_waybill($row)
    {
        $from = trim(self::country_label($row['origin_country_id'] ?? 0) . ' / ' . self::city_label($row['origin_city_id'] ?? 0), ' /');
        $to = trim(self::country_label($row['destination_country_id'] ?? 0) . ' / ' . self::city_label($row['destination_city_id'] ?? 0), ' /');
        $parts = array(
            sprintf('Booking request #%d', (int) ($row['id'] ?? 0)),
            $from !== '' ? 'From: ' . $from : '',
            $to !== '' ? 'To: ' . $to : '',
            !empty($row['parcel_count']) ? 'Parcels: ' . (int) $row['parcel_count'] : '',
            $row['weight_kg'] !== null && $row['weight_kg'] !== '' ? 'Weight kg: ' . $row['weight_kg'] : '',
            $row['service_note'] !== '' ? 'Service: ' . $row['service_note'] : '',
            $row['notes'] !== '' ? 'Notes: ' . $row['notes'] : '',
        );
        return implode("\n", array_filter($parts));
    }

    /**
     * @param int $cust_id
     */
    public static function staff_create_waybill_url($cust_id, $request_id)
    {
        $args = array(
            'cust_id'             => (int) $cust_id,
            'booking_request_id'  => (int) $request_id,
        );
        if (function_exists('kit_using_employee_portal') && kit_using_employee_portal()) {
            return kit_employee_portal_url('08600-waybill-create', $args);
        }
        return add_query_arg(
            array_merge(array('page' => '08600-waybill-create'), $args),
            admin_url('admin.php')
        );
    }
}
