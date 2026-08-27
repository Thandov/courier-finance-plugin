<?php
//File location: includes/countries/opc-functions.php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class KIT_OPCCountries
{
    public static function init()
    {
        add_action('wp_ajax_kit_get_countries', [__CLASS__, 'get_countries']);
        // insert_countries on init is disabled (use seed/migration instead).
        add_action('wp_ajax_kit_add_country', [__CLASS__, 'add_country']);
        add_action('wp_ajax_kit_get_country_options', [__CLASS__, 'kit_get_country_options']);
    }

    public static function add_country() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'kit_operating_countries';

        $name = sanitize_text_field($_POST['country_name']);
        $code = sanitize_text_field($_POST['country_code']);
        $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

        if (!$name || !$code) {
            wp_send_json_error('Country name and code are required');
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE country_code = %s", $code));
        if ($exists) {
            wp_send_json_error('Country already exists');
        }

        $inserted = $wpdb->insert($table, [
            'country_name' => $name,
            'country_code' => $code,
            'is_active' => $is_active,
            'created_at' => current_time('mysql')
        ]);

        error_log($wpdb->last_error); // optional
        
        if ($inserted) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Database error');
        }
    }
    // Function to get countries
    public static function get_countries()
    {
        $countries = self::get_all_countries();
        wp_send_json_success($countries);
    }
    // Function to insert countries into the database INSERT INTO `wp_kit_operating_countries`(`id`, `country_name`, `country_code`, `is_active`, `created_at`)
    public static function insert_countries()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kit_operating_countries';
        $countries = self::get_all_countries();

        foreach ($countries as $country) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE country_code = %s",
                $country['code']
            ));

            if (!$exists) {
                $wpdb->insert(
                    $table_name,
                    [
                        'country_name' => $country['name'],
                        'country_code' => $country['code'],
                        'is_active' => 1,
                        'created_at' => current_time('mysql')
                    ]
                );
            }
        }
    }
    // Function to get all countries
    public static function get_all_countries()
    {
        //Worpress get all countries from table wp_kit_operating_countries
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_operating_countries';
        $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
        if (empty($results)) {
            return [];
        }
        $countries = [];
        foreach ($results as $row) {
            $countries[] = [
                'name' => $row['country_name'],
                'code' => $row['country_code'],
                'is_active' => $row['is_active'],
                'created_at' => $row['created_at']
            ];
        }
        return $countries;
    }

    

public static function kit_get_country_options() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_operating_countries';
    $countries = $wpdb->get_results("SELECT * FROM $table_name WHERE is_active = 1");

    ob_start();
    ?>
    <option value="">Select a country</option>
    <?php foreach ($countries as $country): ?>
        <option value="<?= esc_attr($country->country_code) ?>"><?= esc_html($country->country_name) ?></option>
    <?php endforeach; ?>
    <option value="add_country_to_db">+ Add Country</option>
    <?php
    $options_html = ob_get_clean();

    wp_send_json_success(['html' => $options_html]);
}
}
// Initialize
KIT_OPCCountries::init();