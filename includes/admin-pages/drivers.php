<?php
if (!defined('ABSPATH')) {
    exit;
}

// Include unified table class
require_once plugin_dir_path(__FILE__) . '../class-unified-table.php';
// Include toast component
require_once plugin_dir_path(__FILE__) . '../components/toast.php';
// Include right sidebar quick actions component
require_once plugin_dir_path(__FILE__) . '../components/sidebarQuickActions.php';

/**
 * Render driver multiform for modal or inline use
 * 
 * @param array $atts Array of attributes:
 *   - form_action: Form action URL
 *   - driver_id: Driver ID for editing (empty for new)
 *   - edit_mode: Whether in edit mode (boolean)
 *   - driver: Driver object/array (null for new)
 *   - is_modal: Whether rendered in modal (boolean)
 * @return string HTML form content
 */
function kit_render_driver_multiform($atts = [])
{
    $atts = shortcode_atts([
        'form_action' => '',
        'driver_id' => '',
        'edit_mode' => false,
        'driver' => null,
        'is_modal' => false,
    ], $atts);

    $form_action = esc_url($atts['form_action'] ?: admin_url('admin.php?page=manage-drivers'));
    $driver_id = esc_attr($atts['driver_id']);
    $edit_mode = $atts['edit_mode'];
    $driver = $atts['driver'];
    $is_modal = $atts['is_modal'];

    // Convert driver to object if it's an array
    if (is_array($driver) && !empty($driver)) {
        $driver = (object) $driver;
    }

    ob_start();
    ?>
    <form method="POST" action="<?php echo $form_action; ?>" class="space-y-6">
        <input type="hidden" name="action" value="save_driver">
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('save_driver_nonce'); ?>">
        <?php if ($edit_mode && $driver && isset($driver->id)): ?>
            <input type="hidden" name="driver_id" value="<?php echo esc_attr($driver->id); ?>">
        <?php endif; ?>
        <?php if ($is_modal): ?>
            <input type="hidden" name="is_modal" value="1">
        <?php endif; ?>
        
        <div class="bg-gray-50/50 rounded-lg border border-gray-200 p-5">
            <div class="mb-4">
                <h3 class="text-base font-semibold text-gray-900">Driver Details</h3>
                <p class="text-sm text-gray-500 mt-1">Basic contact and license information.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <?php
                echo KIT_Commons::Linput([
                    'label' => 'Driver Name',
                    'name'  => 'name',
                    'id'    => 'name',
                    'type'  => 'text',
                    'value' => $driver && isset($driver->name) ? esc_attr($driver->name) : '',
                    'preset' => 'form_md',
                    'required' => true
                ]);
                
                echo KIT_Commons::Linput([
                    'label' => 'Phone',
                    'name'  => 'phone',
                    'id'    => 'phone',
                    'type'  => 'tel',
                    'value' => $driver && isset($driver->phone) ? esc_attr($driver->phone) : '',
                    'preset' => 'form_md',
                ]);
                
                echo KIT_Commons::Linput([
                    'label' => 'Email',
                    'name'  => 'email',
                    'id'    => 'email',
                    'type'  => 'email',
                    'value' => $driver && isset($driver->email) ? esc_attr($driver->email) : '',
                    'preset' => 'form_md',
                ]);
                
                echo KIT_Commons::Linput([
                    'label' => 'License Number',
                    'name'  => 'license_number',
                    'id'    => 'license_number',
                    'type'  => 'text',
                    'value' => $driver && isset($driver->license_number) ? esc_attr($driver->license_number) : '',
                    'preset' => 'form_md',
                ]);
                ?>
            </div>

            <div class="mt-5 pt-4 border-t border-gray-200">
                <label class="block <?= KIT_Commons::labelClass() ?> mb-2">Status</label>
                <label for="is_active" class="kit-driver-status-toggle">
                    <?php
                    $driver_active_checked = true;
                    if ($edit_mode && $driver && isset($driver->is_active)) {
                        $driver_active_checked = (bool) $driver->is_active;
                    }
                    echo KIT_Commons::Lcheckbox([
                        'no_label' => true,
                        'label' => '',
                        'id' => 'is_active',
                        'name' => 'is_active',
                        'value' => '1',
                        'checked' => $driver_active_checked,
                        'class' => 'kit-driver-status-input',
                    ]);
                    ?>
                    <span class="kit-driver-status-switch" aria-hidden="true">
                        <span class="kit-driver-status-knob"></span>
                    </span>
                    <span class="text-sm text-gray-700">Active</span>
                </label>
            </div>
        </div>

        <div class="pt-4 border-t flex items-center justify-between">
            <p class="text-xs text-gray-500">Tip: save changes before navigating away.</p>
            <div class="flex space-x-3">
                <?php
                echo KIT_Commons::renderButton(
                    $edit_mode ? 'Update Driver' : 'Add Driver',
                    'primary',
                    'lg',
                    ['type' => 'submit']
                );
                ?>
                <?php if (!$is_modal): ?>
                    <a href="<?php echo admin_url('admin.php?page=manage-drivers'); ?>" 
                       class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        Cancel
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

// Handle form submission
if (isset($_POST['action']) && $_POST['action'] === 'save_driver') {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'save_driver_nonce')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('kit_view_waybills')) {
        wp_die('Unauthorized');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'kit_drivers';

    // Helper: build redirect URL for admin vs frontend portal
    $build_redirect_url = function(array $args) {
        // In the employee portal (frontend), use section URLs instead of wp-admin
        if (function_exists('kit_using_employee_portal') && function_exists('kit_employee_portal_url') && kit_using_employee_portal()) {
            // Section is always manage-drivers; remove any page param
            unset($args['page']);
            return kit_employee_portal_url('manage-drivers', $args);
        }

        // Default: wp-admin URL
        return add_query_arg($args, admin_url('admin.php'));
    };

    // Validate required fields
    $name = sanitize_text_field($_POST['name'] ?? '');
    if (empty(trim($name))) {
        $redirect_args = [
            'page'    => 'manage-drivers',
            'add'     => isset($_POST['driver_id']) ? '' : '1',
            'edit'    => isset($_POST['driver_id']) ? intval($_POST['driver_id']) : '',
            'message' => urlencode('Driver name is required.'),
            'success' => '0',
        ];
        wp_redirect($build_redirect_url($redirect_args));
        exit;
    }

    // Convert empty strings to NULL for optional fields
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $license_number = sanitize_text_field($_POST['license_number'] ?? '');
    
    $data = [
        'name' => $name,
        'phone' => $phone === '' ? null : $phone,
        'email' => $email === '' ? null : $email,
        'license_number' => $license_number === '' ? null : $license_number,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    // Format specifiers for $wpdb->insert/update (null values use null in format array)
    $format = [
        '%s', // name
        $data['phone'] === null ? null : '%s', // phone
        $data['email'] === null ? null : '%s', // email
        $data['license_number'] === null ? null : '%s', // license_number
        '%d'  // is_active
    ];
    
    if (isset($_POST['driver_id']) && !empty($_POST['driver_id'])) {
        // Update
        $driver_id = intval($_POST['driver_id']);
        $result = $wpdb->update(
            $table,
            $data,
            ['id' => $driver_id],
            $format,
            ['%d']
        );
        
        if ($result === false) {
            $error = $wpdb->last_error;
            $message = !empty($error) ? 'Failed to update driver: ' . esc_html($error) : 'Failed to update driver.';
        } else if ($result === 0) {
            $message = 'No changes were made to the driver.';
        } else {
            $message = 'Driver updated successfully!';
        }
    } else {
        // Create
        $result = $wpdb->insert($table, $data, $format);
        
        if ($result === false) {
            $error = $wpdb->last_error;
            $message = !empty($error) ? 'Failed to create driver: ' . esc_html($error) : 'Failed to create driver.';
        } else {
            $message = 'Driver created successfully!';
            if (class_exists('Courier_Google_Sheets_Sync') && Courier_Google_Sheets_Sync::sync_driver_add($data)) {
                $message .= ' Synced to Google Sheet.';
            } elseif (class_exists('Courier_Google_Sheets') && Courier_Google_Sheets::is_configured()) {
                $message .= ' (Sheet sync failed. Share the sheet with Editor access and ensure kit_drivers tab exists.)';
            }
        }
    }

    // Redirect to avoid resubmission (toast will be shown on redirected page)
    $redirect_args = [
        'page'    => 'manage-drivers',
        'message' => urlencode($message),
        'success' => ($result !== false && $result !== 0 ? '1' : '0'),
    ];
    wp_redirect($build_redirect_url($redirect_args));
    exit;
}

// Handle delete
if (isset($_GET['delete']) && isset($_GET['_wpnonce'])) {
    if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_driver_' . $_GET['delete'])) {
        wp_die('Security check failed');
    }

    if (!current_user_can('kit_view_waybills')) {
        wp_die('Unauthorized');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'kit_drivers';
    $driver_id = intval($_GET['delete']);
    $driver = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table WHERE id = %d", $driver_id));
    $driver_name = $driver ? $driver->name : '';
    $result = $wpdb->delete($table, ['id' => $driver_id]);

    $message = $result !== false ? 'Driver deleted successfully!' : 'Failed to delete driver.';
    if ($result !== false && $driver_name !== '' && class_exists('Courier_Google_Sheets_Sync') && Courier_Google_Sheets_Sync::sync_driver_delete($driver_name)) {
        $message .= ' Removed from Google Sheet.';
    }

    $redirect_args = [
        'page'    => 'manage-drivers',
        'message' => urlencode($message),
        'success' => ($result !== false ? '1' : '0'),
    ];
    // Reuse the same helper used in the save handler if available
    if (isset($build_redirect_url) && is_callable($build_redirect_url)) {
        wp_redirect($build_redirect_url($redirect_args));
    } else {
        wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
    }
    exit;
}

// Handle bulk actions
if (isset($_POST['bulk_action']) && isset($_POST['bulk_ids']) && wp_verify_nonce($_POST['bulk_nonce'] ?? '', 'bulk_action_nonce')) {
    if (!current_user_can('kit_view_waybills')) {
        wp_die('Unauthorized');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'kit_drivers';
    $ids = array_map('intval', explode(',', sanitize_text_field($_POST['bulk_ids'])));
    $ids = array_filter($ids);
    
    if (empty($ids)) {
        $redirect_args = [
            'page'    => 'manage-drivers',
            'message' => urlencode('No drivers selected.'),
            'success' => '0',
        ];
        if (isset($build_redirect_url) && is_callable($build_redirect_url)) {
            wp_redirect($build_redirect_url($redirect_args));
        } else {
            wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        }
        exit;
    }

    $action = sanitize_text_field($_POST['bulk_action']);
    $message = '';
    $success = false;

    switch ($action) {
        case 'delete':
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $drivers_to_delete = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name FROM $table WHERE id IN ($placeholders)",
                $ids
            ), ARRAY_A);
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE id IN ($placeholders)",
                $ids
            ));
            $success = $result !== false;
            $message = $success ? sprintf('Successfully deleted %d driver(s).', count($ids)) : 'Failed to delete drivers.';
            if ($success && !empty($drivers_to_delete) && class_exists('Courier_Google_Sheets_Sync')) {
                $synced = 0;
                foreach ($drivers_to_delete as $d) {
                    if (Courier_Google_Sheets_Sync::sync_driver_delete($d['name'] ?? '')) {
                        $synced++;
                    }
                }
                if ($synced > 0) {
                    $message .= sprintf(' Removed %d from Google Sheet.', $synced);
                }
            }
            break;

        case 'update_status':
            $status = isset($_POST['status_value']) ? intval($_POST['status_value']) : 0;
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET is_active = %d WHERE id IN ($placeholders)",
                array_merge([$status], $ids)
            ));
            $success = $result !== false;
            $status_text = $status ? 'active' : 'inactive';
            $message = $success ? sprintf('Successfully set %d driver(s) to %s.', count($ids), $status_text) : 'Failed to update driver status.';
            break;

        case 'export':
            // Export will be handled via GET parameter
            $redirect_args = [
                'page'            => 'manage-drivers',
                'export_selected' => implode(',', $ids),
            ];
            if (isset($build_redirect_url) && is_callable($build_redirect_url)) {
                wp_redirect($build_redirect_url($redirect_args));
            } else {
                wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            }
            exit;

        case 'packing_list':
            $redirect_args = [
                'page'    => 'manage-drivers',
                'message' => urlencode('Packing list applies to waybills only.'),
                'success' => '0',
            ];
            if (isset($build_redirect_url) && is_callable($build_redirect_url)) {
                wp_redirect($build_redirect_url($redirect_args));
            } else {
                wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            }
            exit;
    }

    if ($action !== 'export') {
        $redirect_args = [
            'page'    => 'manage-drivers',
            'message' => urlencode($message),
            'success' => ($success ? '1' : '0'),
        ];
        if (isset($build_redirect_url) && is_callable($build_redirect_url)) {
            wp_redirect($build_redirect_url($redirect_args));
        } else {
            wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        }
        exit;
    }
}

// Get driver for editing
$driver = null;
$edit_mode = false;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    global $wpdb;
    $table = $wpdb->prefix . 'kit_drivers';
    $driver = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($_GET['edit'])));
    $edit_mode = $driver !== null;
}

// Get all drivers
global $wpdb;
$table = $wpdb->prefix . 'kit_drivers';
$drivers = $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC");

// Show toast messages from URL parameters (backward compatibility)
if (isset($_GET['message'])) {
    if (class_exists('KIT_Toast')) {
        KIT_Toast::ensure_toast_loads();
        $success = isset($_GET['success']) && $_GET['success'] === '1';
        $toast_type = $success ? 'success' : 'error';
        $toast_title = $success ? 'Success' : 'Error';
        echo KIT_Toast::show(urldecode($_GET['message']), $toast_type, $toast_title);
    }
}
?>

<div class="wrap">
    <div class="<?php echo KIT_Commons::containerClasses(); ?>">
    <?php
    echo KIT_Commons::showingHeader([
        'title' => 'Manage Drivers',
        'desc' => '',
        'content' => KIT_Modal::render('new-driver-modal', 'Add New Driver', kit_render_driver_multiform([
            'form_action' => admin_url('admin.php?page=manage-drivers&add=1'),
            'driver_id' => '',
            'edit_mode' => false,
            'driver' => null,
            'is_modal' => true
        ])),
        'icon' => KIT_Commons::icon('user'),
    ]);
    ?>
            <?php
        echo KIT_QuickStats::render_for_context(KIT_QuickStats::CONTEXT_DRIVERS, [
            'drivers' => $drivers,
        ]);
        ?>
    
    <?php if (isset($_GET['add']) || $edit_mode): ?>
        <!-- Add/Edit Driver Form with right sidebar actions -->
        <?php
        $current_user = wp_get_current_user();
        $driver_page_context = [
            'page' => 'manage-drivers',
            'screen' => 'driver-form',
            'mode' => $edit_mode ? 'edit' : 'add',
            'driver_id' => $edit_mode && $driver && isset($driver->id) ? intval($driver->id) : 0,
            'roles' => is_array($current_user->roles ?? null) ? $current_user->roles : [],
        ];

        $default_driver_quick_actions = [
            [
                'title' => 'All Drivers',
                'description' => 'Return to drivers list',
                'href' => admin_url('admin.php?page=manage-drivers'),
                'icon' => 'M3 7h18M3 12h18M3 17h18',
                'color' => 'blue'
            ],
            [
                'title' => 'Add New Driver',
                'description' => 'Open a fresh driver form',
                'href' => admin_url('admin.php?page=manage-drivers&add=1'),
                'icon' => 'M12 4v16m8-8H4',
                'color' => 'green'
            ],
            [
                'title' => 'Manage Deliveries',
                'description' => 'Assign this driver to deliveries',
                'href' => admin_url('admin.php?page=kit-deliveries'),
                'icon' => 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-1.447-.894L15 4m0 13V4m-6 3l6-3',
                'color' => 'purple'
            ],
            [
                'title' => 'Route Management',
                'description' => 'Review route coverage',
                'href' => admin_url('admin.php?page=route-management'),
                'icon' => 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-1.447-.894L15 4m0 13V4m-6 3l6-3',
                'color' => 'orange'
            ],
        ];

        $driver_quick_actions = apply_filters('kit_manage_drivers_quick_actions', $default_driver_quick_actions, $driver_page_context);
        $driver_quick_actions_title = apply_filters('kit_manage_drivers_quick_actions_title', 'Quick Actions', $driver_page_context);
        $driver_quick_actions_subtitle = apply_filters('kit_manage_drivers_quick_actions_subtitle', 'Suggested quick actions', $driver_page_context);
        ?>
        <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-start">
            <div class="md:col-span-8">
                <div class="bg-white rounded-lg shadow-md border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6 pb-4 border-b">
                        <h2 class="text-2xl font-bold text-gray-800"><?php echo $edit_mode ? 'Edit Driver' : 'Add New Driver'; ?></h2>
                        <a href="<?php echo admin_url('admin.php?page=manage-drivers'); ?>" class="text-gray-600 hover:text-gray-900">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </a>
                    </div>
                    
                    <?php
                    echo kit_render_driver_multiform([
                        'form_action' => admin_url('admin.php?page=manage-drivers'),
                        'driver_id' => $edit_mode && $driver && isset($driver->id) ? $driver->id : '',
                        'edit_mode' => $edit_mode,
                        'driver' => $driver,
                        'is_modal' => false,
                    ]);
                    ?>
                </div>
            </div>
            <div class="md:col-span-4">
                <div class="sticky top-4">
                    <?php echo KIT_SidebarQuickActions::render($driver_quick_actions, $driver_quick_actions_title, $driver_quick_actions_subtitle, $driver_page_context); ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Drivers List -->
        <div class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
            <?php
            // Convert drivers to array format for unified table
            $drivers_data = [];
            foreach ($drivers as $driver_row) {
                // Combine phone and email into contact field
                $contact_parts = [];
                if (!empty($driver_row->phone)) {
                    $contact_parts[] = esc_html($driver_row->phone);
                }
                if (!empty($driver_row->email)) {
                    $contact_parts[] = esc_html($driver_row->email);
                }
                $contact = !empty($contact_parts) ? implode(' • ', $contact_parts) : 'N/A';
                
                $drivers_data[] = [
                    'id' => $driver_row->id,
                    'name' => $driver_row->name,
                    'contact' => $contact,
                    'phone' => $driver_row->phone ?: 'N/A', // Keep for search
                    'email' => $driver_row->email ?: 'N/A', // Keep for search
                    'license_number' => $driver_row->license_number ?: 'N/A',
                    'is_active' => $driver_row->is_active,
                    'city' => 'Drivers',
                    'status' => !empty($driver_row->is_active) ? 'active' : 'inactive',
                    'created_at' => $driver_row->created_at ?? '',
                ];
            }

            // Define columns for the unified table
            $columns = [
                'name' => [
                    'label' => 'Driver Name',
                    'callback' => function($value, $row) {
                        $initial = strtoupper(substr($value, 0, 1));
                        $is_active = !empty($row['is_active']);
                        $dot_color = $is_active ? 'bg-green-500' : 'bg-red-500';
                        return '<div class="flex items-center">
                            <div class="flex-shrink-0 relative">
                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                    <span class="text-blue-600 font-semibold">' . esc_html($initial) . '</span>
                                </div>
                                <span class="status-indicator absolute bottom-0 right-0 block h-3 w-3 rounded-full ' . $dot_color . ' border-2 border-white"></span>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900">' . esc_html($value) . '</div>
                            </div>
                        </div>';
                    }
                ],
                'contact' => 'Contact',
                'license_number' => 'License Number',
            ];

            // Define actions for the table (row-level actions)
            $actions = [
                [
                    'label' => 'Edit',
                    'href' => admin_url('admin.php?page=manage-drivers&edit={id}'),
                    'class' => 'inline-flex items-center px-2.5 py-1.5 text-sm font-medium text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md transition-colors'
                ],
                [
                    'label' => 'Delete',
                    'callback' => function($href, $row) {
                        $driver_id = is_array($row) ? ($row['id'] ?? '') : (is_object($row) ? ($row->id ?? '') : '');
                        if (empty($driver_id)) {
                            return '#';
                        }
                        return wp_nonce_url(
                            admin_url('admin.php?page=manage-drivers&delete=' . intval($driver_id)),
                            'delete_driver_' . intval($driver_id)
                        );
                    },
                    'class' => 'inline-flex items-center px-2.5 py-1.5 text-sm font-medium text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors',
                    'onclick' => 'return confirm(\'Are you sure you want to delete this driver? This action cannot be undone.\');'
                ]
            ];

            // Render unified table
            echo KIT_Unified_Table::infinite($drivers_data, $columns, KIT_Unified_Table::optionsWithManageDefaults([
                'title' => 'All Drivers',
                'sync_entity' => 'drivers',
                'actions' => $actions,
                'pagination' => true,
                'items_per_page' => 20,
                'bulk_actions_list' => ['delete', 'export', 'packing_list', 'status_active', 'status_inactive'],
                'empty_message' => 'No drivers found. <a href="' . admin_url('admin.php?page=manage-drivers&add=1') . '">Add your first driver</a>.',
                'search_placeholder' => 'Search drivers...',
                'search_filters' => [
                    ['value' => 'name', 'label' => 'Driver Name', 'placeholder' => 'Search by driver name...'],
                    ['value' => 'contact', 'label' => 'Contact', 'placeholder' => 'Search by phone or email...'],
                    ['value' => 'license_number', 'label' => 'License Number', 'placeholder' => 'Search by license number...']
                ],
                'search_default_filter' => 'name',
            ]));
            ?>
        </div>
    <?php endif; ?>
</div>
</div>
<style id="kit-driver-status-toggle-styles">
    .kit-driver-status-toggle {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        user-select: none;
    }

    .kit-driver-status-input {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .kit-driver-status-switch {
        position: relative;
        width: 44px;
        height: 24px;
        border-radius: 9999px;
        background: #d1d5db;
        transition: background-color 0.2s ease;
    }

    .kit-driver-status-knob {
        position: absolute;
        top: 2px;
        left: 2px;
        width: 20px;
        height: 20px;
        border-radius: 9999px;
        background: #ffffff;
        border: 1px solid #d1d5db;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
        transition: transform 0.2s ease;
    }

    .kit-driver-status-input:checked + .kit-driver-status-switch {
        background: #2563eb;
    }

    .kit-driver-status-input:checked + .kit-driver-status-switch .kit-driver-status-knob {
        transform: translateX(20px);
        border-color: #ffffff;
    }

    .kit-driver-status-input:focus-visible + .kit-driver-status-switch {
        outline: 2px solid #93c5fd;
        outline-offset: 2px;
    }
</style>
<script>
    jQuery(document).ready(function($) {
        const $newDriverModal = $('#new-driver-modal');
        if ($newDriverModal.length === 0) {
            return;
        }

        const resetNewDriverForm = function() {
            const form = $newDriverModal.find('form').get(0);
            if (!form) {
                return;
            }

            form.reset();
            const statusInput = form.querySelector('input[name="is_active"]');
            if (statusInput) {
                statusInput.checked = true;
            }
        };

        $newDriverModal.on('modal:opened', resetNewDriverForm);
    });
</script>
