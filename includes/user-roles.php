<?php
/**
 * Custom User Roles for 08600 Waybill Plugin
 * 
 * Administrator: Super user with full access to everything
 * Data Capturer: Can input data but cannot see prices
 * Manager: Can approve and invoice but cannot see prices
 */

if (!defined('ABSPATH')) {
    exit;
}

class KIT_User_Roles {
    
    /**
     * Initialize user roles
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'create_custom_roles'));
        add_action('admin_init', array(__CLASS__, 'check_user_permissions'));
        add_filter('user_contactmethods', array(__CLASS__, 'add_custom_user_fields'));
        add_action('admin_init', array(__CLASS__, 'remove_unwanted_roles'));
    }
    
    /**
     * Remove unwanted roles from the system
     */
    public static function remove_unwanted_roles() {
        // Remove roles we don't want
        $unwanted_roles = array(
            'editor',
            'author', 
            'contributor',
            'subscriber',
            'customer',
            'delivery_driver',
            'shop_manager'
        );
        
        foreach ($unwanted_roles as $role) {
            remove_role($role);
        }
    }
    
    /**
     * Create custom user roles
     */
    public static function create_custom_roles() {
        // Data Capturer Role - Can input data but cannot see prices
        add_role('data_capturer', 'Data Capturer', array(
            'read' => true,
            'edit_posts' => false,
            'delete_posts' => false,
            'publish_posts' => false,
            'upload_files' => true,
            'edit_pages' => true,
            'read_private_pages' => true,
            'edit_private_pages' => false,
            'edit_published_pages' => false,
            'publish_pages' => false,
            'delete_pages' => false,
            'delete_private_pages' => false,
            'delete_published_pages' => false,
            'manage_options' => false,
            'kit_view_waybills' => true,
            'kit_view_past_trips' => true,
            'kit_access_description' => true,
            'kit_access_weight' => true,
            'kit_access_dimensions' => true,
            'kit_access_sad500' => true,
            'kit_access_sadc_certificate' => true,
            'kit_access_vat' => true,
            'kit_update_data' => true,
            'kit_can_approve' => false,
            'kit_can_invoice' => false,
            'kit_can_see_prices' => false,
            'kit_edit_waybills' => true, // Allow editing waybills
            'kit_view_waybill_details' => true, // Allow viewing waybill details
        ));
        
        // Frontend customer portal only (distinct slug — do not use `customer`; see remove_unwanted_roles)
        if (!get_role('08600_portal_customer')) {
            add_role('08600_portal_customer', '08600 Customer', array(
                'read' => true,
                'kit_access_customer_portal' => true,
                'kit_submit_booking_request' => true,
            ));
        }

        // Manager Role - Can approve and invoice but cannot see prices
        add_role('manager', 'Manager', array(
            'read' => true,
            'edit_posts' => true,
            'delete_posts' => false,
            'publish_posts' => true,
            'upload_files' => true,
            'edit_pages' => true,
            'read_private_pages' => true,
            'edit_private_pages' => true,
            'edit_published_pages' => true,
            'publish_pages' => true,
            'delete_pages' => false,
            'delete_private_pages' => false,
            'delete_published_pages' => false,
            'manage_options' => false,
            'kit_view_waybills' => true,
            'kit_view_past_trips' => true,
            'kit_update_data' => true,
            'kit_access_description' => true,
            'kit_access_weight' => true,
            'kit_access_dimensions' => true,
            'kit_access_sad500' => true,
            'kit_access_sadc_certificate' => true,
            'kit_access_vat' => true,
            'kit_can_approve' => true,
            'kit_can_invoice' => true,
            'kit_can_see_prices' => false,
            'kit_edit_waybills' => true, // Allow editing waybills
            'kit_view_waybill_details' => true, // Allow viewing waybill details
        ));
    }
    
    /**
     * Check user permissions and modify interface accordingly
     */
    public static function check_user_permissions() {
        $current_user = wp_get_current_user();
        $user_roles = $current_user->roles;
        
        // Add custom capabilities to administrator role only
        if (in_array('administrator', $user_roles)) {
            $admin_role = get_role('administrator');
            if ($admin_role) {
                $admin_role->add_cap('kit_view_waybills');
                $admin_role->add_cap('kit_view_past_trips');
                $admin_role->add_cap('kit_update_data');
                $admin_role->add_cap('kit_access_description');
                $admin_role->add_cap('kit_access_weight');
                $admin_role->add_cap('kit_access_dimensions');
                $admin_role->add_cap('kit_access_sad500');
                $admin_role->add_cap('kit_access_sadc_certificate');
                $admin_role->add_cap('kit_access_vat');
                $admin_role->add_cap('kit_can_approve');
                $admin_role->add_cap('kit_can_invoice');
                $admin_role->add_cap('kit_can_see_prices');
                $admin_role->add_cap('kit_access_settings');
                $admin_role->add_cap('kit_edit_waybills'); // Allow editing waybills
                $admin_role->add_cap('kit_view_waybill_details'); // Allow viewing waybill details
                $admin_role->add_cap('kit_access_customer_portal'); // Impersonate / test portal; PDF checks
            }
        }

        // Ensure Data Capturer role has required caps (handles existing sites)
        $dc_role = get_role('data_capturer');
        if ($dc_role) {
            $dc_role->add_cap('kit_view_waybills');
            $dc_role->add_cap('kit_view_past_trips');
            $dc_role->add_cap('kit_update_data');
            $dc_role->add_cap('kit_access_description');
            $dc_role->add_cap('kit_access_weight');
            $dc_role->add_cap('kit_access_dimensions');
            $dc_role->add_cap('kit_access_sad500');
            $dc_role->add_cap('kit_access_sadc_certificate');
            $dc_role->add_cap('kit_access_vat');
            $dc_role->add_cap('kit_edit_waybills'); // Allow editing waybills
            $dc_role->add_cap('kit_view_waybill_details'); // Allow viewing waybill details
            // Explicitly ensure no price access
            $dc_role->remove_cap('kit_can_see_prices');
        }

        // Ensure portal customer role exists and has cap (handles existing sites / upgrades)
        $portal_role = get_role('08600_portal_customer');
        if ($portal_role) {
            $portal_role->add_cap('kit_access_customer_portal');
            $portal_role->add_cap('kit_submit_booking_request');
        }

        // Ensure Manager role has required caps (handles existing sites)
        $mgr_role = get_role('manager');
        if ($mgr_role) {
            $mgr_role->add_cap('kit_view_waybills');
            $mgr_role->add_cap('kit_view_past_trips');
            $mgr_role->add_cap('kit_update_data');
            $mgr_role->add_cap('kit_access_description');
            $mgr_role->add_cap('kit_access_weight');
            $mgr_role->add_cap('kit_access_dimensions');
            $mgr_role->add_cap('kit_access_sad500');
            $mgr_role->add_cap('kit_access_sadc_certificate');
            $mgr_role->add_cap('kit_access_vat');
            $mgr_role->add_cap('kit_can_approve');
            $mgr_role->add_cap('kit_can_invoice');
            $mgr_role->add_cap('kit_edit_waybills'); // Allow editing waybills
            $mgr_role->add_cap('kit_view_waybill_details'); // Allow viewing waybill details
            // Explicitly ensure no price access
            $mgr_role->remove_cap('kit_can_see_prices');
        }
    }
    
    /**
     * Add custom user fields for company email
     */
    public static function add_custom_user_fields($contact_methods) {
        $contact_methods['company_email'] = 'Company Email';
        $contact_methods['company_phone'] = 'Company Phone';
        $contact_methods['company_address'] = 'Company Address';
        return $contact_methods;
    }
    
    /**
     * Check if user can access specific field
     */
    public static function can_access_field($field_name) {
        $current_user = wp_get_current_user();
        
        // Administrators have full access
        if (in_array('administrator', $current_user->roles)) {
            return true;
        }
        
        // Check specific field permissions
        switch ($field_name) {
            case 'description':
                return current_user_can('kit_access_description');
            case 'weight':
                return current_user_can('kit_access_weight');
            case 'dimensions':
                return current_user_can('kit_access_dimensions');
            case 'sad500':
                return current_user_can('kit_access_sad500');
            case 'sadc_certificate':
                return current_user_can('kit_access_sadc_certificate');
            case 'vat':
                return current_user_can('kit_access_vat');
            default:
                return false;
        }
    }
    
    /**
     * Check if user can update data
     */
    public static function can_update_data() {
        return current_user_can('kit_update_data') || current_user_can('manage_options');
    }
    
    /**
     * Check if user can view waybills
     */
    public static function can_view_waybills() {
        return current_user_can('kit_view_waybills') || current_user_can('edit_pages');
    }
    
    /**
     * Check if user can view past trips
     */
    public static function can_view_past_trips() {
        return current_user_can('kit_view_past_trips') || current_user_can('edit_pages');
    }
    
    /**
     * Check if user can approve waybills
     */
    public static function can_approve() {
        return current_user_can('kit_can_approve') || current_user_can('manage_options');
    }
    
    /**
     * Check if user can create invoices
     */
    public static function can_invoice() {
        return current_user_can('kit_can_invoice') || current_user_can('manage_options');
    }
    
    /**
     * Check if user can see prices.
     * Policy: Only administrators may see prices.
     */
    public static function can_see_prices() {
        $current_user = wp_get_current_user();
        $user_roles = is_array($current_user->roles) ? $current_user->roles : [];
        $username = isset($current_user->user_login) ? strtolower($current_user->user_login) : '';
        $allowed_users = ['thando', 'mel', 'patricia'];
        $is_admin = in_array('administrator', $user_roles) || current_user_can('manage_options');
        return $is_admin && in_array($username, $allowed_users, true);
    }
    
    /**
     * Check if user is one of the specific authorized administrators for settings
     * STRICT ACCESS: Only Thando, Mel, and Patricia can access settings
     */
    public static function can_access_settings() {
        $allowed_users = ['thando', 'mel', 'patricia'];
        $current_user = wp_get_current_user();
        $current_username = strtolower($current_user->user_login);
        
        return in_array($current_username, $allowed_users);
    }
    
    /**
     * Check if current user is admin
     */
    public static function is_admin() {
        return current_user_can('manage_options') || in_array('administrator', wp_get_current_user()->roles);
    }
    
    /**
     * Check if current user is data capturer
     */
    public static function is_data_capturer() {
        return in_array('data_capturer', wp_get_current_user()->roles);
    }
    
    /**
     * Check if user can edit an approved waybill
     * Managers cannot edit approved waybills, only administrators (thando, mel, patricia) can
     */
    public static function can_edit_approved_waybill($waybill_approval_status) {
        // If waybill is not approved, use normal edit permissions
        if ($waybill_approval_status !== 'approved' && $waybill_approval_status !== 1) {
            return !self::is_data_capturer(); // Managers and admins can edit pending waybills
        }
        
        // If waybill is approved, only administrators (thando, mel, patricia) can edit
        return self::can_see_prices();
    }
    
    /**
     * Check if current user is manager
     */
    public static function is_manager() {
        return in_array('manager', wp_get_current_user()->roles);
    }

    /**
     * Check if current user is admin or manager (ADMAN).
     * Only ADMAN can use: Countries, Drivers, Routes & Destinations pages, and create delivery trucks.
     * Data capturers are never ADMAN.
     */
    public static function is_adman() {
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }
        $roles = is_array($user->roles) ? $user->roles : array();
        if (in_array('data_capturer', $roles)) {
            return false;
        }
        return in_array('administrator', $roles) || in_array('manager', $roles) || current_user_can('manage_options');
    }

    /**
     * Whether the user is the frontend-only customer portal role.
     *
     * @param WP_User|null $user Optional user; default current user.
     * @return bool
     */
    public static function is_portal_customer($user = null) {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }
        return in_array('08600_portal_customer', (array) $user->roles, true);
    }

    /**
     * Customer portal capability (linked kit_customer_id required for data access).
     *
     * @param WP_User|int|null $user User object, user ID, or null for current user.
     * @return bool
     */
    public static function can_access_customer_portal($user = null) {
        if ($user === null) {
            return current_user_can('kit_access_customer_portal');
        }
        if (is_numeric($user)) {
            return user_can((int) $user, 'kit_access_customer_portal');
        }
        if ($user instanceof WP_User) {
            return $user->exists() && user_can($user, 'kit_access_customer_portal');
        }
        return false;
    }

    /**
     * Linked kit_customers.cust_id for portal users (usermeta).
     *
     * @param int|null $user_id WordPress user ID; default current user.
     * @return int Positive cust_id or 0 if unset/invalid.
     */
    public static function get_portal_customer_id($user_id = null) {
        $uid = $user_id !== null ? (int) $user_id : get_current_user_id();
        if ($uid <= 0) {
            return 0;
        }
        $id = (int) get_user_meta($uid, 'kit_customer_id', true);
        return $id > 0 ? $id : 0;
    }

    /**
     * Check if current user can create a delivery truck (managers and admins only).
     */
    public static function can_create_delivery_truck() {
        return self::is_adman();
    }
    
    /**
     * Get user role display name
     */
    public static function get_user_role_display_name() {
        $current_user = wp_get_current_user();
        $roles = $current_user->roles;
        
        if (in_array('administrator', $roles)) {
            
        } elseif (in_array('manager', $roles)) {
            return 'Manager';
        } elseif (in_array('data_capturer', $roles)) {
            return 'Data Capturer';
        } else {
            return 'User';
        }
    }
    
    /**
     * Get accessible fields for current user
     */
    public static function get_accessible_fields() {
        $fields = array();
        
        if (self::can_access_field('description')) {
            $fields[] = 'description';
        }
        if (self::can_access_field('weight')) {
            $fields[] = 'weight';
        }
        if (self::can_access_field('dimensions')) {
            $fields[] = 'dimensions';
        }
        if (self::can_access_field('sad500')) {
            $fields[] = 'sad500';
        }
        if (self::can_access_field('sadc_certificate')) {
            $fields[] = 'sadc_certificate';
        }
        if (self::can_access_field('vat')) {
            $fields[] = 'vat';
        }
        
        return $fields;
    }
}

// Initialize the user roles system
KIT_User_Roles::init();
