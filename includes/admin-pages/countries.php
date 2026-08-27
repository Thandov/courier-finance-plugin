<?php
if (!defined('ABSPATH')) {
    exit;
}

// Include user roles for permission checking
require_once plugin_dir_path(__FILE__) . '../user-roles.php';

// Include unified table class
require_once plugin_dir_path(__FILE__) . '../class-unified-table.php';
if (!class_exists('KIT_QuickStats')) {
    require_once plugin_dir_path(__FILE__) . '../components/quickStats.php';
}

// Handle country management actions
if (isset($_POST['add_country']) && check_admin_referer('add_country_action', 'add_country_nonce')) {
    global $wpdb;
    $table = $wpdb->prefix . 'kit_operating_countries';

    $name = sanitize_text_field($_POST['country_name']);
    $code = sanitize_text_field($_POST['country_code']);
    $charge_group = intval($_POST['charge_group']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name && $code) {
        $result = $wpdb->insert($table, [
            'country_name' => $name,
            'country_code' => $code,
            'charge_group' => $charge_group,
            'is_active' => $is_active,
            'created_at' => current_time('mysql'),
        ]);

        if ($result) {
            echo '<div class="notice notice-success"><p>Country added successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error adding country: ' . $wpdb->last_error . '</p></div>';
        }
    } else {
        echo '<div class="notice notice-error"><p>Name and code are required.</p></div>';
    }
}

// Handle country update
if (isset($_POST['edit_country']) && check_admin_referer('edit_country_action', 'edit_country_nonce')) {
    global $wpdb;
    $table = $wpdb->prefix . 'kit_operating_countries';

    $id = intval($_POST['country_id']);
    $name = sanitize_text_field($_POST['country_name']);
    $code = sanitize_text_field($_POST['country_code']);
    $charge_group = intval($_POST['charge_group']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($id && $name && $code) {
        $result = $wpdb->update($table, [
            'country_name' => $name,
            'country_code' => $code,
            'charge_group' => $charge_group,
            'is_active' => $is_active,
        ], ['id' => $id]);

        if ($result !== false) {
            echo '<div class="notice notice-success"><p>Country updated successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating country: ' . $wpdb->last_error . '</p></div>';
        }
    } else {
        echo '<div class="notice notice-error"><p>All fields are required.</p></div>';
    }
}

// Handle country deletion
if (isset($_GET['delete_country']) && check_admin_referer('delete_country_' . intval($_GET['delete_country']))) {
    global $wpdb;
    $table = $wpdb->prefix . 'kit_operating_countries';
    $id = intval($_GET['delete_country']);

    $result = $wpdb->delete($table, ['id' => $id]);

    if ($result) {
        echo '<div class="notice notice-success"><p>Country deleted successfully.</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Error deleting country: ' . $wpdb->last_error . '</p></div>';
    }
}

// Handle bulk actions
if (isset($_POST['bulk_action']) && isset($_POST['country_ids']) && is_array($_POST['country_ids'])) {
    global $wpdb;
    $table = $wpdb->prefix . 'kit_operating_countries';
    $action = sanitize_text_field($_POST['bulk_action']);
    $country_ids = array_map('intval', $_POST['country_ids']);
    $updated_count = 0;

    if (!empty($country_ids)) {
        switch ($action) {
            case 'activate':
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET is_active = 1 WHERE id IN (" . implode(',', array_fill(0, count($country_ids), '%d')) . ")",
                    ...$country_ids
                ));
                $updated_count = $result;
                break;

            case 'deactivate':
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET is_active = 0 WHERE id IN (" . implode(',', array_fill(0, count($country_ids), '%d')) . ")",
                    ...$country_ids
                ));
                $updated_count = $result;
                break;

            case 'delete':
                $result = $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table WHERE id IN (" . implode(',', array_fill(0, count($country_ids), '%d')) . ")",
                    ...$country_ids
                ));
                $updated_count = $result;
                break;
        }

        if ($updated_count > 0) {
            $action_text = ucfirst($action) . 'd';
            echo '<div class="notice notice-success"><p>' . $action_text . ' ' . $updated_count . ' countr' . ($updated_count === 1 ? 'y' : 'ies') . ' successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>No countries were updated.</p></div>';
        }
    }
}

// Unified table bulk (same POST fields as Waybill Manage)
if (
    isset($_POST['bulk_action'], $_POST['bulk_ids'], $_POST['bulk_nonce'])
    && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bulk_nonce'])), 'bulk_action_nonce')
) {
    global $wpdb;
    $table = $wpdb->prefix . 'kit_operating_countries';
    $action = sanitize_text_field(wp_unslash($_POST['bulk_action']));
    $country_ids = array_filter(array_map('intval', explode(',', sanitize_text_field(wp_unslash($_POST['bulk_ids'])))));
    $updated_count = 0;

    if (!empty($country_ids) && current_user_can('manage_options')) {
        switch ($action) {
            case 'update_status':
                $status = isset($_POST['status_value']) ? intval($_POST['status_value']) : 0;
                $placeholders = implode(',', array_fill(0, count($country_ids), '%d'));
                $updated_count = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET is_active = %d WHERE id IN ($placeholders)",
                    array_merge([$status], $country_ids)
                ));
                break;

            case 'activate':
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET is_active = 1 WHERE id IN (" . implode(',', array_fill(0, count($country_ids), '%d')) . ")",
                    ...$country_ids
                ));
                $updated_count = $result;
                break;

            case 'deactivate':
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET is_active = 0 WHERE id IN (" . implode(',', array_fill(0, count($country_ids), '%d')) . ")",
                    ...$country_ids
                ));
                $updated_count = $result;
                break;

            case 'delete':
                $result = $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table WHERE id IN (" . implode(',', array_fill(0, count($country_ids), '%d')) . ")",
                    ...$country_ids
                ));
                $updated_count = $result;
                break;
        }

        if ($updated_count > 0) {
            $action_text = $action === 'update_status' ? 'Updated' : ucfirst($action) . 'd';
            echo '<div class="notice notice-success"><p>' . esc_html($action_text) . ' ' . intval($updated_count) . ' countr' . ($updated_count === 1 ? 'y' : 'ies') . ' successfully.</p></div>';
        }
    }
}

// Handle quick toggle (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'toggle_country_status') {
    global $wpdb;
    $table = $wpdb->prefix . 'kit_operating_countries';
    $id = intval($_POST['country_id']);
    $new_status = intval($_POST['new_status']);

    $result = $wpdb->update($table, ['is_active' => $new_status], ['id' => $id]);

    if ($result !== false) {
        wp_send_json_success(['message' => 'Status updated successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to update status']);
    }
}

// Get countries data
global $wpdb;
$table = $wpdb->prefix . 'kit_operating_countries';
$countries = $wpdb->get_results("SELECT * FROM $table ORDER BY country_name ASC");

// Get edit country data if editing
$edit_country = false;
if (isset($_GET['edit_country'])) {
    $edit_id = intval($_GET['edit_country']);
    $edit_country = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $edit_id));
}

?>

<div class="wrap">
    <div class="<?php echo KIT_Commons::containerClasses(); ?>">
        <?php
        echo KIT_Commons::showingHeader([
            'title' => 'Operating Countries',
            'desc'  => 'Configure the countries available for routing, pricing and deliveries.',
            'icon'  => KIT_Commons::icon('warehouse'),
            'content' => KIT_Commons::renderButton('Add New Country', 'primary', 'md', [
                'href'    => '?page=08600-countries&add_new=1',
                'icon'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />',
                'iconPosition' => 'left',
                'gradient' => true,
            ]),
        ]);
        ?>
        <hr class="wp-header-end">

        <?php
        echo KIT_QuickStats::render_for_context(KIT_QuickStats::CONTEXT_COUNTRIES, [
            'countries' => $countries,
        ]);
        ?>

        <div class="flex gap-8 items-start mt-5">
            <!-- Countries List -->
            <div class="flex-[2]">
                <?php
                // Prepare data for unified table
                $countries_data = [];
                foreach ($countries as $country) {
                    $countries_data[] = [
                        'id' => $country->id,
                        'country_name' => $country->country_name,
                        'country_code' => $country->country_code,
                        'charge_group' => $country->charge_group,
                        'is_active' => $country->is_active,
                        'created_at' => $country->created_at,
                        'city' => $country->country_name,
                        'status' => !empty($country->is_active) ? 'active' : 'inactive',
                    ];
                }

                // Define columns
                $columns = [
                    'country_name' => [
                        'label' => 'Name',
                        'sortable' => true,
                        'searchable' => true,
                        'callback' => function ($value, $row) {
                            return '<strong>' . esc_html($value) . '</strong>';
                        }
                    ],
                    'country_code' => [
                        'label' => 'Code',
                        'sortable' => true,
                        'searchable' => true,
                        'callback' => function ($value, $row) {
                            return '<code>' . esc_html($value) . '</code>';
                        }
                    ],
                    'charge_group' => [
                        'label' => 'Charge Group',
                        'sortable' => true,
                        'searchable' => false,
                        'callback' => function ($value, $row) {
                            return '<span class="inline-block bg-gray-100 text-gray-700 px-2 py-0.5 rounded text-xs">Group ' . esc_html($value) . '</span>';
                        }
                    ],
                    'is_active' => [
                        'label' => 'Status',
                        'sortable' => true,
                        'searchable' => false,
                        'callback' => function ($value, $row) {
                            $status_text = $value ? 'Active' : 'Inactive';
                            $status_class = $value ? 'status-active' : 'status-inactive';
                            $status_color = $value ? '#00a32a' : '#d63638';
                            $toggle_text = $value ? 'Deactivate' : 'Activate';
                            $new_status = $value ? 0 : 1;

                            $color_class = $value ? 'text-green-600 border-green-600' : 'text-red-600 border-red-600';
                            return '
                            <div class="flex items-center gap-2">
                                <span class="' . $status_class . ' font-semibold ' . $color_class . '">● ' . $status_text . '</span>
                                ' . KIT_Commons::renderButton($toggle_text, 'ghost', 'lg', [
                                'type' => 'button',
                                'classes' => 'quick-toggle-btn text-xs px-2 py-1 rounded border hover:bg-gray-50 ' . $color_class,
                                'data-country-id' => $row['id'],
                                'data-new-status' => $new_status,
                            ]) . '
                            </div>
                        ';
                        }
                    ],
                    'created_at' => [
                        'label' => 'Created',
                        'sortable' => true,
                        'searchable' => false,
                        'callback' => function ($value, $row) {
                            return esc_html(date('M j, Y', strtotime($value)));
                        }
                    ]
                ];

                // Define actions
                $actions = [
                    [
                        'label' => 'Edit',
                        'href' => '?page=08600-countries&edit_country={id}',
                        'class' => 'button button-small'
                    ],
                    [
                        'label' => 'Delete',
                        'href' => wp_nonce_url('?page=08600-countries&delete_country={id}', 'delete_country_{id}'),
                        'class' => 'button button-small',
                        'onclick' => 'return confirm(\'Are you sure you want to delete this country? This action cannot be undone.\');',
                        'style' => 'color: #d63638;'
                    ]
                ];

                // Render unified table
                echo KIT_Unified_Table::infinite($countries_data, $columns, KIT_Unified_Table::optionsWithManageDefaults([
                    'title' => 'Operating Countries',
                    'actions' => $actions,
                    'pagination' => true,
                    'items_per_page' => 20,
                    'current_page' => 1,
                    'show_items_per_page' => true,
                    'empty_message' => 'No countries found. <a href="?page=08600-countries&add_new=1">Add your first country</a>.',
                    'search_placeholder' => 'Search countries...',
                    'search_filters' => [
                        ['value' => 'country_name', 'label' => 'Name', 'placeholder' => 'Search by name...'],
                        ['value' => 'country_code', 'label' => 'Code', 'placeholder' => 'Search by code...'],
                    ],
                    'search_default_filter' => 'country_name',
                    'bulk_actions_list' => ['delete', 'export', 'packing_list', 'status_active', 'status_inactive'],
                ]));
                ?>
            </div>

            <!-- Add/Edit Form -->
            <div class="flex-1 min-w-[320px]">
                <?php if ($edit_country): ?>
                    <div class="bg-white border border-gray-300 rounded p-5">
                        <h3>Edit Country</h3>
                        <form method="post">
                            <?php wp_nonce_field('edit_country_action', 'edit_country_nonce'); ?>
                            <input type="hidden" name="country_id" value="<?php echo esc_attr($edit_country->id); ?>">

                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="country_name">Country Name</label>
                                    </th>
                                    <td>
                                        <?php echo KIT_Commons::Linput([
                                            'no_label' => true,
                                            'name' => 'country_name',
                                            'id' => 'country_name',
                                            'type' => 'text',
                                            'value' => $edit_country->country_name,
                                            'preset' => '',
                                            'class' => 'regular-text',
                                            'special' => 'required',
                                        ]); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="country_code">Country Code</label>
                                    </th>
                                    <td>
                                        <?php echo KIT_Commons::Linput([
                                            'no_label' => true,
                                            'name' => 'country_code',
                                            'id' => 'country_code',
                                            'type' => 'text',
                                            'value' => $edit_country->country_code,
                                            'preset' => '',
                                            'class' => 'regular-text uppercase',
                                            'special' => 'required',
                                        ]); ?>
                                        <p class="description">Use ISO country codes (e.g., US, GB, ZA)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="charge_group">Charge Group</label>
                                    </th>
                                    <td>
                                        <?php
                                        echo KIT_Commons::Lnumber([
                                            'no_label' => true,
                                            'label' => '',
                                            'id' => 'charge_group',
                                            'name' => 'charge_group',
                                            'value' => (string) $edit_country->charge_group,
                                            'min' => '0',
                                            'max' => '9',
                                            'preset' => '',
                                            'class' => 'small-text',
                                        ]);
                                        ?>
                                        <p class="description">Pricing group (0-9)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Status</th>
                                    <td>
                                        <label>
                                            <?php
                                            echo KIT_Commons::Lcheckbox([
                                                'no_label' => true,
                                                'label' => '',
                                                'name' => 'is_active',
                                                'omit_id' => true,
                                                'value' => '1',
                                                'checked' => ((int) $edit_country->is_active === 1),
                                            ]);
                                            ?>
                                            Active (country will appear in selection lists)
                                        </label>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <?php echo KIT_Commons::renderButton('Update Country', 'primary', 'lg', ['type' => 'submit', 'name' => 'edit_country', 'classes' => 'button button-primary']); ?>
                                <a href="?page=08600-countries" class="button">Cancel</a>
                            </p>
                        </form>
                    </div>
                <?php elseif (isset($_GET['add_new'])): ?>
                    <div class="bg-white border border-gray-300 rounded p-5">
                        <h3>Add New Country</h3>
                        <form method="post">
                            <?php wp_nonce_field('add_country_action', 'add_country_nonce'); ?>

                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="country_name">Country Name</label>
                                    </th>
                                    <td>
                                        <?php echo KIT_Commons::Linput([
                                            'no_label' => true,
                                            'name' => 'country_name',
                                            'id' => 'country_name',
                                            'type' => 'text',
                                            'value' => '',
                                            'placeholder' => 'e.g., United States',
                                            'preset' => '',
                                            'class' => 'regular-text',
                                            'special' => 'required',
                                        ]); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="country_code">Country Code</label>
                                    </th>
                                    <td>
                                        <?php echo KIT_Commons::Linput([
                                            'no_label' => true,
                                            'name' => 'country_code',
                                            'id' => 'country_code',
                                            'type' => 'text',
                                            'value' => '',
                                            'placeholder' => 'e.g., US',
                                            'preset' => '',
                                            'class' => 'regular-text uppercase',
                                            'special' => 'required',
                                        ]); ?>
                                        <p class="description">Use ISO country codes (e.g., US, GB, ZA)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="charge_group">Charge Group</label>
                                    </th>
                                    <td>
                                        <?php
                                        echo KIT_Commons::Lnumber([
                                            'no_label' => true,
                                            'label' => '',
                                            'id' => 'charge_group',
                                            'name' => 'charge_group',
                                            'value' => '1',
                                            'min' => '0',
                                            'max' => '9',
                                            'preset' => '',
                                            'class' => 'small-text',
                                        ]);
                                        ?>
                                        <p class="description">Pricing group (0-9)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Status</th>
                                    <td>
                                        <label>
                                            <?php
                                            echo KIT_Commons::Lcheckbox([
                                                'no_label' => true,
                                                'label' => '',
                                                'name' => 'is_active',
                                                'omit_id' => true,
                                                'value' => '1',
                                                'checked' => true,
                                            ]);
                                            ?>
                                            Active (country will appear in selection lists)
                                        </label>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <?php echo KIT_Commons::renderButton('Add Country', 'primary', 'lg', ['type' => 'submit', 'name' => 'add_country', 'classes' => 'button button-primary']); ?>
                                <a href="?page=08600-countries" class="button">Cancel</a>
                            </p>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="bg-white border border-gray-300 rounded p-5">
                        <h3>Quick Actions</h3>
                        <p>
                            <a href="?page=08600-countries&add_new=1" class="button button-primary">Add New Country</a>
                        </p>
                        <p>
                            <a href="?page=route-management" class="button">Manage Routes</a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .row {
        display: flex;
        gap: 20px;
        margin: 20px 0;
    }

    .col-md-4 {
        flex: 1;
    }

    .card {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        padding: 20px;
    }

    .badge {
        background: #f0f0f1;
        color: #50575e;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
    }

    .status-active {
        color: #00a32a;
        font-weight: 600;
    }

    .status-inactive {
        color: #d63638;
        font-weight: 600;
    }

    .form-table th {
        width: 150px;
        padding: 20px 10px 20px 0;
    }

    .form-table td {
        padding: 15px 10px;
    }

    .description {
        font-style: italic;
        color: #666;
        margin: 5px 0 0 0;
    }
</style>

<script>
    jQuery(document).ready(function($) {
        // Auto-uppercase country codes
        $('#country_code').on('input', function() {
            this.value = this.value.toUpperCase();
        });

        // Quick toggle functionality
        $(document).on('click', '.quick-toggle-btn', function(e) {
            e.preventDefault();

            const $btn = $(this);
            const countryId = $btn.data('country-id');
            const newStatus = $btn.data('new-status');
            const $row = $btn.closest('tr');

            // Disable button during request
            $btn.prop('disabled', true).text('Updating...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'toggle_country_status',
                    country_id: countryId,
                    new_status: newStatus,
                    nonce: '<?php echo wp_create_nonce('toggle_country_status'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Reload the page to show updated status
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data.message || 'Failed to update status'));
                        $btn.prop('disabled', false).text($btn.data('original-text'));
                    }
                },
                error: function() {
                    alert('Error: Failed to update status');
                    $btn.prop('disabled', false).text($btn.data('original-text'));
                }
            });
        });

        // Store original button text
        $('.quick-toggle-btn').each(function() {
            $(this).data('original-text', $(this).text());
        });
    });
</script>