<?php
class KIT_Commons
{
    public static function init()
    {
        add_shortcode('showheader', [self::class, 'showingHeader']);
        add_shortcode('kitbutton', [self::class, 'kitButton']);

        // Register AJAX handlers for status updates
        add_action('wp_ajax_update_delivery_status', [self::class, 'ajax_update_delivery_status']);
        add_action('wp_ajax_update_waybill_status', [self::class, 'ajax_update_waybill_status']);


        // Enqueue DataTables assets on relevant admin screens
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_datatables_assets']);
    }

    public static function containerClasses()
    {
        return 'max-w-7xl mx-auto';
    }
    
    public static function enqueue_datatables_assets()
    {
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || empty($screen->id)) {
            return;
        }
        // Only load on our plugin pages to avoid conflicts
        if (strpos($screen->id, '08600') === false) {
            return;
        }

        // Ensure jQuery is available
        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('jquery');
        }

        // Load DataTables only once
        $style_loaded = function_exists('wp_style_is') ? wp_style_is('datatables-css', 'enqueued') : false;
        if (function_exists('wp_enqueue_style') && ! $style_loaded) {
            wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css', [], '1.13.6');
        }
        $script_loaded = function_exists('wp_script_is') ? wp_script_is('datatables-js', 'enqueued') : false;
        if (function_exists('wp_enqueue_script') && ! $script_loaded) {
            wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', ['jquery'], '1.13.6', true);
        }
    }

    // NOTE: can_view_financials() method removed as it's unused and was failing
    // Dashboard uses direct hardcoded validation instead for reliability

    /**
     * Safely perform string operations on potentially null values
     * 
     * @param string|null $value The string value to operate on
     * @param string $operation The operation to perform ('str_replace', 'strpos', etc.)
     * @param array $params Additional parameters for the operation
     * @return string|int|false The result of the operation or empty string if input is null
     */
    public static function safeStringOperation($value, $operation, $params = [])
    {
        // Ensure value is not null
        if ($value === null) {
            return '';
        }

        // Convert to string if it's not already
        $value = (string) $value;

        switch ($operation) {
            case 'str_replace':
                $search = $params[0] ?? '';
                $replace = $params[1] ?? '';
                return str_replace($search, $replace, $value);

            case 'strpos':
                $needle = $params[0] ?? '';
                $offset = $params[1] ?? 0;
                return strpos($value, $needle, $offset);

            case 'ucfirst':
                return ucfirst($value);

            case 'strtolower':
                return strtolower($value);

            case 'strtoupper':
                return strtoupper($value);

            default:
                return $value;
        }
    }

    public static function getPrimeColor(): string
    {
        $schemaPath = plugin_dir_path(__FILE__) . '../colorSchema.json';

        if (file_exists($schemaPath)) {
            $json = file_get_contents($schemaPath);
            $data = json_decode($json, true);

            if (is_array($data) && !empty($data['primary'])) {
                $hex = trim($data['primary']);
                // simple hex guard (#RGB, #RRGGBB)
                if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex)) {
                    return $hex;
                }
            }
        }

        // fallback
        return '#2563eb';
    }

    // Removed duplicate getSecondaryColor() definition; use canonical below

    // Removed duplicate getAccentColor() definition; use canonical below

    public static function statusBadge($status, $addClass = null, $dataAttributes = [])
    {
        $status = strtolower($status);
        $badge_classes = [
            'rejected' => 'border border-red-300 bg-red-50 text-red-800',
            'pending' => 'border border-yellow-300 bg-yellow-50 text-yellow-800',
            'approved' => 'border border-green-300 bg-green-50 text-green-800',
            'shipped' => 'border border-green-300 bg-green-50 text-green-800',
            'delivered' => 'border border-blue-300 bg-blue-50 text-blue-800',
            'cancelled' => 'border border-red-300 bg-red-50 text-red-800',
            'completed' => 'border border-blue-300 bg-blue-50 text-blue-800'
        ];

        $class = $badge_classes[$status] ?? 'bg-gray-100 text-gray-800';
        $display_text = ucfirst($status);

        // Build data attributes
        $dataAttrs = '';
        foreach ($dataAttributes as $key => $value) {
            $dataAttrs .= ' data-' . $key . '="' . esc_attr($value) . '"';
        }

        // Add status as data attribute for filtering
        $dataAttrs .= ' data-status="' . esc_attr($status) . '"';

        return '<span class="inline-flex items-center px-5 py-2 text-xs font-medium ' . $class . '"' . $dataAttrs . '>'
            . $display_text . '</span>';
    }

    public static function waybillGetStatus($waybillno, $waybillid)
    {
        global $wpdb;
        $waybill = $wpdb->get_var($wpdb->prepare(
            "SELECT `status` FROM {$wpdb->prefix}kit_waybills WHERE waybill_no = %s",
            $waybillno
        ));
        $waybill = ($waybill) ?? NULL;
        return $waybill;
    }

    public static function get_countries()
    {
        global $wpdb;
        $countries = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kit_operating_countries WHERE is_active = 1");
        return $countries;
    }

    public static function get_cities()
    {
        global $wpdb;
        $cities = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kit_operating_cities");
        return $cities;
    }

    /**
     * Get brand primary color from colorSchema.json; fallback if missing
     */
    public static function getPrimaryColor(): string
    {
        $schema_path = plugin_dir_path(__FILE__) . '../colorSchema.json';
        if (file_exists($schema_path)) {
            $data = json_decode(file_get_contents($schema_path), true);
            if (is_array($data) && !empty($data['primary'])) {
                return $data['primary'];
            }
        }
        return '#2563eb';
    }

    /**
     * Canonical secondary color reader used by earlier delegate to avoid duplicate definitions
     */
    public static function getSecondaryColorCanonical(): string
    {
        $schema_path = plugin_dir_path(__FILE__) . '../colorSchema.json';
        if (file_exists($schema_path)) {
            $data = json_decode(file_get_contents($schema_path), true);
            if (is_array($data) && !empty($data['secondary'])) {
                return $data['secondary'];
            }
        }
        return '#111827';
    }

    /**
     * Canonical accent color reader used by earlier delegate to avoid duplicate definitions
     */
    public static function getAccentColorCanonical(): string
    {
        $schema_path = plugin_dir_path(__FILE__) . '../colorSchema.json';
        if (file_exists($schema_path)) {
            $data = json_decode(file_get_contents($schema_path), true);
            if (is_array($data) && !empty($data['accent'])) {
                return $data['accent'];
            }
        }
        return '#10b981';
    }


    // Create a function to display a select box with the statuses for the waybill.status column with options "pending", "quoted", "rejected", "completed" and then update the waybill.status column with the selected status
    public static function waybillQuoteStatus($waybillno, $waybillid, $fontSize = 'text-xs')
    {
        // Check if current user can invoice (ONLY administrator can change invoice status)
        $can_invoice = KIT_User_Roles::can_see_prices();

        $statusesStatus = [
            'pending'   => 'Pending',
            'invoiced'    => 'Invoiced',
            'rejected'  => 'Rejected',
            'completed' => 'Completed',
        ];
        $fontSize = $fontSize ?? 'text-xs';

        $current_status2 = self::waybillGetStatus($waybillno, $waybillid);

        $current_label2 = $statusesStatus[$current_status2] ?? 'Not Invoiced';

        // Get color classes for current status
        $getStatusColors = function ($current_status2) {
            switch ($current_status2) {
                case 'pending':
                    return 'bg-yellow-100 text-yellow-800 border-yellow-300';
                case 'invoiced':
                    return 'bg-blue-100 text-blue-800 border-blue-300';
                case 'rejected':
                    return 'bg-red-100 text-red-800 border-red-300';
                case 'completed':
                    return 'bg-green-100 text-green-800 border-green-300';
                default:
                    return 'bg-gray-100 text-gray-800 border-gray-300';
            }
        };

        $current_colors = $getStatusColors($current_status2);

        // If user cannot invoice (only admins can), return status badge only
        if (!$can_invoice) {
            return self::statusBadge($current_status2, 'px-6 py-2 ' . $fontSize);
        }
        ob_start(); ?>
        <form method="POST" action="<?= esc_url(admin_url('admin-post.php')) ?>" class="quotation-status-form">
            <input type="hidden" name="action" value="waybillQuoteStatus_update">
            <input type="hidden" name="waybillno" value="<?= esc_attr($waybillno) ?>">
            <input type="hidden" name="waybillid" value="<?= esc_attr($waybillid) ?>">
            <?php wp_nonce_field('update_waybill_approval_nonce'); ?>
            <div class="relative inline-block text-left">
                <div>
                    <?= KIT_Commons::renderButton($current_label2, 'ghost', 'sm', [
                        'type' => 'button',
                        'id' => 'quote-button-' . esc_attr($waybillid),
                        'onclick' => "toggleDropdownQuote('" . esc_js($waybillid) . "')",
                        'classes' => 'inline-flex items-center justify-center gap-2 px-4 py-2 border shadow-sm bg-white font-medium ' . $current_colors . ' hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500',
                        'icon' => '<path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>',
                        'iconPosition' => 'right',
                    ]) ?>
                </div>

                <div class="hidden origin-top-left absolute left-0 mt-2 w-56  shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50"
                    id="quote-dropdown-<?= esc_attr($waybillid) ?>">
                    <div class="py-1" role="menu" aria-orientation="vertical">
                        <?php foreach ($statusesStatus as $key => $label): ?>
                            <?= KIT_Commons::renderButton($label, 'ghost', 'sm', [
                                'type' => 'submit',
                                'name' => 'status',
                                'value' => $key,
                                'classes' => 'block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 ' . ($key === $current_status2 ? 'bg-gray-100 text-gray-900' : ''),
                                'role' => 'menuitem',
                            ]) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </form><?php
                return ob_get_clean();
            }


            public static function getWaybillApprovalStatus($waybillno, $waybillid)
            {
                global $wpdb;
                $waybill = $wpdb->get_var($wpdb->prepare(
                    "SELECT approval FROM {$wpdb->prefix}kit_waybills WHERE waybill_no = %s AND id = %d",
                    $waybillno,
                    $waybillid
                ));

                return $waybill;
            }
            public static function updateWaybillApprovalStatus($waybillno, $waybillid, $status)
            {
                global $wpdb;

                // Sanitize inputs
                $waybillno = sanitize_text_field($waybillno);
                $waybillid = intval($waybillid);
                $status    = sanitize_text_field($status);

                $table = $wpdb->prefix . 'kit_waybills';

                $updated = $wpdb->update(
                    $table,
                    [
                        'approval' => $status,
                        'approval_userid' => get_current_user_id(),
                    ],
                    [
                        'id' => $waybillid,
                        'waybill_no' => $waybillno,
                    ],
                    ['%s', '%d'],
                    ['%d', '%s']
                );

                if ($updated !== false) {
                    return ['success' => true, 'message' => 'Waybill approval updated.'];
                } else {
                    return ['success' => false, 'message' => 'Failed to update approval.'];
                }
            }

            public static function verifyint($value)
            {
                return empty($value) ? 0 : (int) $value;
            }

            public static function verifystring($value)
            {
                return empty($value) ? '' : trim((string) $value);
            }


            public static function waybillApprovalStatus($waybillno, $waybillid, $prevApproval, $fontSize = 'text-sm')
            {

                // Check if current user can approve (administrator OR manager can approve)
                $can_approve = KIT_User_Roles::can_approve();

                // if current user cannot approve, return the status badge with the previous approval status
                // Note: Managers CAN approve, only Data Capturers cannot
                if (!$can_approve) {
                    return self::statusBadge($prevApproval, 'px-6 py-2 ' . $fontSize);
                }

                // Output a form with a dropdown to change status
                $statuses = [
                    'pending'   => 'Not Approved',
                    'approved'  => 'Approved',
                    'rejected'  => 'Rejected',
                    'completed' => 'Completed'
                ];

                $current_status = self::getWaybillApprovalStatus($waybillno, $waybillid);

                $current_label = $statuses[$current_status] ?? 'Unkno23wn';

                // Check if user is a manager (not admin/superadmin)
                // Load WordPress user functions if needed
                if (!function_exists('wp_get_current_user')) {
                    require_once ABSPATH . 'wp-includes/pluggable.php';
                }

                $current_user = function_exists('wp_get_current_user')
                    ? call_user_func('wp_get_current_user')
                    : null;

                $is_manager = false;
                if ($current_user && isset($current_user->roles)) {
                    $is_manager = in_array('manager', $current_user->roles) && !in_array('administrator', $current_user->roles);
                }

                // If manager and waybill is already approved, rejected, or completed - lock it
                $locked_statuses = ['approved', 'rejected', 'completed'];
                $is_locked = $is_manager && in_array($current_status, $locked_statuses);

                // If locked, just show the badge without dropdown
                if ($is_locked) {
                    return self::statusBadge($current_status, 'px-6 py-2 ' . $fontSize);
                }


                // Get color classes for current status
                $getStatusColors = function ($status) {
                    switch ($status) {
                        case 'pending':
                            return 'bg-yellow-100 text-yellow-800 border-yellow-300';
                        case 'approved':
                            return 'bg-green-100 text-green-800 border-green-300';
                        case 'rejected':
                            return 'bg-red-100 text-red-800 border-red-300';
                        case 'completed':
                            return 'bg-green-100 text-green-800 border-green-300';
                        default:
                            return 'bg-gray-100 text-gray-800 border-gray-300';
                    }
                };

                $current_colors = $getStatusColors($current_status);

                ob_start(); ?>
        <form method="POST" action="<?= esc_url(admin_url('admin-post.php')) ?>" id="waybill-approval-form">
            <input type="hidden" name="action" value="update_WaybillApproval">
            <input type="hidden" name="waybillno" value="<?= esc_attr($waybillno) ?>">
            <input type="hidden" name="waybillid" value="<?= esc_attr($waybillid) ?>">
            <?php wp_nonce_field('update_waybill_approval_nonce'); ?>

            <div class="relative inline-block text-left">
                <div>
                    <?= KIT_Commons::renderButton($current_label, 'ghost', 'sm', [
                        'type' => 'button',
                        'id' => 'approval-button-' . esc_attr($waybillid),
                        'onclick' => "toggleDropdownApproval('" . esc_js($waybillid) . "')",
                        'classes' => 'inline-flex items-center justify-center gap-2 px-4 py-2 border shadow-sm bg-white font-medium ' . $current_colors . ' hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500',
                        'icon' => '<path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>',
                        'iconPosition' => 'right',
                    ]) ?>
                </div>

                <div class="hidden origin-top-right absolute right-0 mt-2 w-56  shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50"
                    id="approval-dropdown-<?= esc_attr($waybillid) ?>">
                    <div class="py-1" role="menu" aria-orientation="vertical">
                        <?php foreach ($statuses as $key => $label): ?>
                            <?= KIT_Commons::renderButton($label, 'ghost', 'sm', [
                                'type' => 'submit',
                                'name' => 'status',
                                'value' => $key,
                                'classes' => 'block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 ' . ($key === $current_status ? 'bg-gray-100 text-gray-900' : ''),
                                'role' => 'menuitem',
                            ]) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </form>

        <script>
            if (typeof window.waybillDropdownsInitialized === 'undefined') {
                window.waybillDropdownsInitialized = true;

                function toggleDropdownQuote(waybillId) {
                    console.log('toggleDropdownQuote called with ID:', waybillId);

                    const dropdownQuote = document.getElementById('quote-dropdown-' + waybillId);
                    const buttonQuote = document.getElementById('quote-button-' + waybillId);

                    console.log('Quote dropdown element:', dropdownQuote);
                    console.log('Quote button element:', buttonQuote);

                    if (!dropdownQuote || !buttonQuote) {
                        console.error('Quote dropdown or button not found!');
                        return;
                    }

                    // Close all other quote dropdowns
                    document.querySelectorAll('[id^="quote-dropdown-"]').forEach(d => {
                        if (d.id !== 'quote-dropdown-' + waybillId) {
                            d.classList.add('hidden');
                        }
                    });

                    // Toggle current dropdown
                    dropdownQuote.classList.toggle('hidden');
                    console.log('Quote dropdown toggled, hidden class:', dropdownQuote.classList.contains('hidden'));
                }

                function toggleDropdownApproval(waybillId) {
                    console.log('toggleDropdownApproval called with ID:', waybillId);

                    const dropdownApproval = document.getElementById('approval-dropdown-' + waybillId);
                    const buttonApproval = document.getElementById('approval-button-' + waybillId);

                    console.log('Approval dropdown element:', dropdownApproval);
                    console.log('Approval button element:', buttonApproval);

                    if (!dropdownApproval || !buttonApproval) {
                        console.error('Approval dropdown or button not found!');
                        return;
                    }

                    // Close all other approval dropdowns
                    document.querySelectorAll('[id^="approval-dropdown-"]').forEach(d => {
                        if (d.id !== 'approval-dropdown-' + waybillId) {
                            d.classList.add('hidden');
                        }
                    });

                    // Toggle current dropdown
                    dropdownApproval.classList.toggle('hidden');
                    console.log('Approval dropdown toggled, hidden class:', dropdownApproval.classList.contains('hidden'));
                }

                // Make functions globally available
                window.toggleDropdownQuote = toggleDropdownQuote;
                window.toggleDropdownApproval = toggleDropdownApproval;

                // Close dropdowns when clicking outside
                document.addEventListener('click', function(e) {
                    if (!e.target.closest('[id^="approval-dropdown-"]') && !e.target.closest('[id^="approval-button-"]') &&
                        !e.target.closest('[id^="quote-dropdown-"]') && !e.target.closest('[id^="quote-button-"]')) {
                        document.querySelectorAll('[id^="approval-dropdown-"], [id^="quote-dropdown-"]').forEach(d => {
                            d.classList.add('hidden');
                        });
                    }
                });
            }
        </script>
        <?php
                return ob_get_clean();
            }

            public static function warehouseDeliveryAssignment($waybill_id, $waybill_no, $destination_country, $destination_city, $current_status)
            {
                // Check if user can assign deliveries (Admin or Manager only)
                $can_assign = KIT_User_Roles::can_approve(); // Using same permission as approval

                if (!$can_assign) {
                    return self::statusBadge($current_status, 'px-6 py-2 text-sm');
                }

                // Include warehouse functions
                require_once plugin_dir_path(__FILE__) . 'warehouse/warehouse-functions.php';

                // Check if waybill has warehouse items
                $warehouse_items = KIT_Warehouse::getWarehouseItems($waybill_id);

                if (empty($warehouse_items)) {
                    return self::statusBadge($current_status, 'px-6 py-2 text-sm');
                }

                // Check if any warehouse items are already assigned
                $assigned_items = array_filter($warehouse_items, function ($item) {
                    return $item->status === 'assigned' || $item->status === 'shipped' || $item->status === 'delivered';
                });

                if (!empty($assigned_items)) {
                    $first_assigned = reset($assigned_items);
                    ob_start(); ?>
            <div class="inline-flex items-center px-3 py-1  text-sm bg-blue-100 text-blue-800 border border-blue-300">
                <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M8 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM15 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
                    <path d="M3 4a1 1 0 00-1 1v10a1 1 0 001 1h1.05a2.5 2.5 0 014.9 0H10a1 1 0 001-1V5a1 1 0 00-1-1H3zM14 7a1 1 0 00-1 1v6.05A2.5 2.5 0 0115.95 16H17a1 1 0 001-1V8a1 1 0 00-1-1h-3z" />
                </svg>
                Assigned to: <?= esc_html($first_assigned->delivery_reference) ?>
            </div>
        <?php
                    return ob_get_clean();
                }

                // Get available deliveries going to the same destination
                $available_deliveries = KIT_Warehouse::getAvailableDeliveries($destination_country, $destination_city);

                ob_start(); ?>
        <form method="POST" action="<?= esc_url(admin_url('admin-post.php')) ?>" id="delivery-assignment-form">
            <input type="hidden" name="action" value="assign_waybill_to_delivery">
            <input type="hidden" name="waybill_id" value="<?= esc_attr($waybill_id) ?>">
            <input type="hidden" name="waybill_no" value="<?= esc_attr($waybill_no) ?>">
            <?php wp_nonce_field('assign_waybill_delivery_nonce'); ?>

            <div class="relative inline-block text-left">
                <div>
                    <?= KIT_Commons::renderButton('Assign to Delivery', 'warning', 'sm', [
                        'type' => 'button',
                        'id' => 'delivery-assignment-button-' . esc_attr($waybill_id),
                        'onclick' => "toggleDeliveryAssignment('" . esc_js($waybill_id) . "')",
                        'classes' => 'inline-flex items-center justify-center gap-2 px-4 py-2 border shadow-sm font-medium bg-yellow-100 text-yellow-800 border-yellow-300 hover:bg-yellow-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500',
                        'icon' => '<path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>',
                        'iconPosition' => 'right',
                    ]) ?>
                </div>

                <div class="hidden origin-top-right absolute right-0 mt-2 w-80  shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50"
                    id="delivery-assignment-dropdown-<?= esc_attr($waybill_id) ?>">
                    <div class="py-1" role="menu" aria-orientation="vertical">
                        <?php if (empty($available_deliveries)): ?>
                            <div class="px-4 py-2 text-sm text-gray-500">
                                No deliveries available to <?= esc_html($destination_city) ?>, <?= esc_html($destination_country) ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($available_deliveries as $delivery): ?>
                                <?= KIT_Commons::renderButton(
                                    $delivery->delivery_name . ' — ' . $delivery->destination_city . ', ' . $delivery->destination_country . ' (Dispatch: ' . date('M j, Y', strtotime($delivery->dispatch_date)) . ')',
                                    'ghost',
                                    'sm',
                                    [
                                        'type' => 'submit',
                                        'name' => 'delivery_id',
                                        'value' => $delivery->delivery_id,
                                        'classes' => 'block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900',
                                        'role' => 'menuitem',
                                    ]
                                ) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>

        <script>
            if (typeof window.deliveryAssignmentInitialized === 'undefined') {
                window.deliveryAssignmentInitialized = true;

                function toggleDeliveryAssignment(waybillId) {
                    console.log('toggleDeliveryAssignment called with ID:', waybillId);

                    const dropdown = document.getElementById('delivery-assignment-dropdown-' + waybillId);
                    const button = document.getElementById('delivery-assignment-button-' + waybillId);

                    console.log('Delivery dropdown element:', dropdown);
                    console.log('Delivery button element:', button);

                    if (!dropdown || !button) {
                        console.error('Delivery dropdown or button not found!');
                        return;
                    }

                    // Close all other delivery assignment dropdowns
                    document.querySelectorAll('[id^="delivery-assignment-dropdown-"]').forEach(d => {
                        if (d.id !== 'delivery-assignment-dropdown-' + waybillId) {
                            d.classList.add('hidden');
                        }
                    });

                    // Toggle current dropdown
                    dropdown.classList.toggle('hidden');
                    console.log('Delivery dropdown toggled, hidden class:', dropdown.classList.contains('hidden'));
                }

                // Make function globally available
                window.toggleDeliveryAssignment = toggleDeliveryAssignment;

                // Close dropdowns when clicking outside
                document.addEventListener('click', function(e) {
                    if (!e.target.closest('[id^="delivery-assignment-dropdown-"]') &&
                        !e.target.closest('[id^="delivery-assignment-button-"]')) {
                        document.querySelectorAll('[id^="delivery-assignment-dropdown-"]').forEach(d => {
                            d.classList.add('hidden');
                        });
                    }
                });
            }
        </script>
    <?php
                return ob_get_clean();
            }

            public static function tick()
            {
    ?>
        <span class="w-[13px] h-[13px]  bg-green-600 text-white flex items-center justify-center text-[10px]">
            ✓
        </span>

    <?php
            }
            public static function update_waybillApproval()
            {
                global $wpdb;

                $waybillid = intval($_POST['waybillid']);
                $waybillno = sanitize_text_field($_POST['waybillno']);
                $status = sanitize_text_field($_POST['status']);
                $userId = get_current_user_id();

                $table = $wpdb->prefix . 'kit_waybills';

                $updated = $wpdb->update(
                    $table,
                    [
                        'approval' => $status,
                        'approval_userid' => $userId,
                    ],
                    [
                        'id' => $waybillid,
                        'waybill_no' => $waybillno,
                    ],
                    ['%s', '%d'],
                    ['%d', '%s']
                );

                if ($updated !== false) {
                    wp_send_json_success(['message' => 'Waybill approval updated.']);
                } else {
                    wp_send_json_error(['message' => 'Failed to update approval.']);
                }
            }

            /**
             * Renders a square button box styled as a radio or checkbox input with info inside.
             *
             * Example usage:
             * KIT_Commons::ButtonBox([
             *     'name' => 'include_sadc',
             *     'value' => '1',
             *     'min_desc' => 'R50',
             *     'data_target' => 'include_sadc',
             *     'checked' => false,
             *     'type' => 'checkbox',
             *     'class' => 'fee-option',
             * ], 'Waybill Fee');
             *
             * Output HTML:
             * <label class="fee-option ...">
             *   <input type="checkbox" ...>
             *   <div class="flex flex-col items-center justify-center h-full w-full pointer-events-none">
             *     <span class="font-semibold text-sm mb-1">Waybill Fee</span>
             *     <span class="text-xs text-gray-500 text-center">R50</span>
             *   </div>
             * </label>
             */
            // DEPRECATED: Use renderButton() instead
            public static function buttonBox($label, $highlight = '')
            {
                $type = ($highlight === 'highlight') ? 'warning' : 'secondary';
                $classes = ($highlight === 'highlight') ? 'bg-yellow-400 text-black hover:bg-yellow-500' : '';

                return self::renderButton($label, $type, 'sm', [
                    'classes' => $classes
                ]);
            }

            public static function DestinationButtonBox($atts = [])
            {
                $atts = shortcode_atts([
                    'name'               => '',
                    'delivery_reference' => '',
                    'direction_id'       => 0,
                    'dispatch_date'      => '',
                    'status'             => false,
                    'description'        => '',
                    'checked'            => false, // Allow custom default selection
                    'class'              => '',
                    'onclick'            => '',
                ], $atts);

                $input_id = $atts['direction_id'] ? 'btnbox_' . $atts['direction_id'] : uniqid('btnbox_');

                ob_start();
    ?>
        <input
            type="radio"
            name="<?php echo esc_attr($atts['name']); ?>"
            id="<?php echo esc_attr($input_id); ?>"
            value="<?php echo esc_attr($atts['direction_id']); ?>"
            class="sr-only peer"
            <?php echo $atts['checked'] ? 'checked' : ''; ?>>
        <label <?php echo $atts['onclick'] ? 'onclick="' . $atts['onclick'] . '"' : ''; ?> for="<?php echo esc_attr($input_id); ?>" class="<?= $atts['class'] ?>  bg-white w-[100px] h-[100px]  border-2 border-gray-300 cursor-pointer relative flex items-center justify-center text-center text-[11px] font-medium leading-tight hover:shadow-md transition-all duration-200 peer-checked:border-blue-500 peer-checked:bg-blue-100 peer-checked:shadow-lg">
            <div>
                <h4><?= esc_html($atts['description']); ?></h4>
                <div class="font-bold text-[12px]"><?= esc_html(date('d M Y', strtotime($atts['dispatch_date']))); ?></div>
                <div class="text-gray-500"><?= esc_html(ucfirst($atts['status'])); ?></div>
            </div>
        </label>
    <?php
                return ob_get_clean();
            }


            /**
             * Outputs a textarea field with custom height functionality.
             * If 'height' is numeric, uses px unit. You can also pass any CSS unit like '10em', '40vh', etc.
             * Note: Setting height in % works only if the parent/container of the textarea has an explicit height.
             */
            public static function TextAreaField($atts = [])
            {
                $atts = shortcode_atts([
                    'label' => '',
                    'name'  => '',
                    'id'    => '',
                    'type'  => 'text',
                    'value' => '',
                    'class' => '',
                    'special' => '',
                    'height' => '100', // Accepts number (px) or CSS string.
                    'is_dynamic' => false,
                    'dynamic_group' => '',
                    'dynamic_type' => 'text',
                    'label_class' => '',
                ], $atts);

                // Fallbacks for esc_attr/esc_html if not running in WP (for linting/tests)
                if (!function_exists('esc_attr')) {
                    function esc_attr($s)
                    {
                        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }
                }
                if (!function_exists('esc_html')) {
                    function esc_html($s)
                    {
                        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }
                }

                $labelClass = self::labelClass();
                $inputClass = self::inputClass();

                // Height parsing: allow numeric (px), explicit CSS unit, fallback/default, and warn for %
                $height = trim($atts['height']);
                if (is_numeric($height)) {
                    $height_css = $height . 'px';
                    $height_is_percent = false;
                } elseif (preg_match('/^\d+(\.\d+)?(px|em|rem|vh|vw)$/', $height)) {
                    $height_css = $height;
                    $height_is_percent = false;
                } elseif (preg_match('/^\d+(\.\d+)?%$/', $height)) {
                    // % only works if container has explicit height
                    $height_css = $height;
                    $height_is_percent = true;
                } else {
                    $height_css = '100px';
                    $height_is_percent = false;
                }

                ob_start(); ?>

        <div class="<?php echo esc_attr($atts['class']); ?>">
            <?php if (!empty($atts['label'])): ?>
                <label for="<?php echo esc_attr($atts['id']); ?>" class="<?php echo esc_attr(trim($labelClass . ' ' . $atts['label_class'])); ?>">
                    <?php echo esc_html($atts['label']); ?>
                </label>
            <?php endif; ?>
            <?php if ($height_is_percent): ?>
                <!-- Note: Using percentage heights on textarea only works if the parent has an explicit height set! -->
            <?php endif; ?>
            <textarea
                name="<?php echo esc_attr($atts['name']); ?>"
                id="<?php echo esc_attr($atts['id']); ?>"
                style="height: <?php echo esc_attr($height_css); ?>;"
                class="<?php echo esc_attr(trim($inputClass . ' ' . $atts['class'])); ?>"
                <?php echo $atts['special']; ?>><?php echo esc_attr($atts['value']); ?></textarea>
        </div>
    <?php
                return ob_get_clean();
            }

            public static function compareCharges($mass_charge, $volume_charge)
            {
                $mass = floatval($mass_charge);
                $volume = floatval($volume_charge);

                if (abs($mass - $volume) < 0.0001) {
                    return ['mass' => false, 'volume' => false, 'equal' => true];
                }

                return [
                    'mass' => $mass > $volume,
                    'volume' => $volume > $mass,
                    'equal' => false,
                ];
            }

            public static function LText($atts)
            {
                $atts = shortcode_atts([
                    'label' => '',
                    'value' => '',
                    'classlabel' => '',
                    'classP' => '',
                    'onclick' => '',
                    'is_dynamic' => false, // New parameter for dynamic fields
                    'allow_html' => false, // New parameter to allow HTML in value
                ], $atts);

                $labelClass = self::labelClass();

                // Standard input
                $value = $atts['allow_html'] ? ($atts['value'] ?? '') : htmlspecialchars($atts['value'] ?? '');
                return '<label class="' . esc_attr($labelClass) . ' ' . $atts['classlabel'] . ' ">' .
                    esc_html($atts['label']) . '</label>' .
                    '<p class="m-0 p-0 ' . esc_attr($atts['classP']) . '">' . $value . '</p>';
            }

            public static function Linput($atts)
            {
                $atts = shortcode_atts([
                    'label' => '',
                    'name'  => '',
                    'id'    => '',
                    'type'  => 'text',
                    'value' => '',
                    'class' => '',
                    'special' => '',
                    'onclick' => '',
                    'tabindex' => '',
                    'is_dynamic' => false, // New parameter for dynamic fields
                    'dynamic_group' => '', // Group name for dynamic fields
                    'dynamic_type' => 'text', // Type for dynamic fields (when is_dynamic=true)
                    'label_class' => '', // Class for the label
                    'icon' => '', // Icon SVG HTML to display inside input field
                ], $atts);

                $labelClass = self::labelClass();
                $inputClass = self::inputClass();

                // Standard input
                if (!$atts['is_dynamic']) {
                    $iconHtml = '';
                    $iconWrapper = '';
                    $uniqueId = !empty($atts['id']) ? esc_attr($atts['id']) : 'input-' . uniqid();

                    // If icon is provided, wrap input in relative container and add icon
                    if (!empty($atts['icon'])) {
                        $iconWrapper = '<div class="relative input-with-icon-container">';
                        // Icon positioned at left-3 (12px from left)
                        $iconHtml = '<div class="input-icon absolute left-3 flex items-center pointer-events-none" data-input-id="' . $uniqueId . '">' . $atts['icon'] . '</div>';
                        // Replace px-3 in inputClass with pl-12 pr-4 for proper icon spacing
                        // Icon is at 12px (left-3), icon width is 20px (w-5), so text should start at 48px (pl-12)
                        $inputClass = str_replace('px-3', '', $inputClass);
                        // Remove any conflicting padding classes from custom class
                        $atts['class'] = preg_replace('/\b(pl-|px-|pr-)\d+\b/', '', $atts['class']);
                        $atts['class'] = trim($atts['class']);
                        // Add inline style for padding to ensure it works even if Tailwind classes aren't compiled
                        // 3rem = 48px (pl-12), 1rem = 16px (pr-4) - creates proper space between icon and text
                        $paddingStyle = 'style="padding-left: 3rem !important; padding-right: 1rem !important;"';
                        if (empty($atts['special'])) {
                            $atts['special'] = $paddingStyle;
                        } else {
                            $atts['special'] = $paddingStyle . ' ' . $atts['special'];
                        }
                    }

                    // Use the unique ID for the input
                    $inputId = !empty($atts['id']) ? esc_attr($atts['id']) : $uniqueId;

                    $inputHtml = '<input step="0.01" type="' . esc_attr($atts['type']) . '" name="' . esc_attr($atts['name']) .
                        '" id="' . $inputId . '" value="' . esc_attr($atts['value']) .
                        '" class="' . esc_attr($inputClass) . ' ' . esc_attr($atts['class']) . '" ' . ($atts['onclick'] ? 'onclick="' . $atts['onclick'] . '" ' : '') .
                        esc_attr($atts['special']) . ' tabindex="' . esc_attr($atts['tabindex']) . '"/>';

                    $closeWrapper = $iconWrapper ? '</div>' : '';

                    $labelHtml = '<label for="' . $inputId . '" class="' . esc_attr($labelClass) . " " . esc_attr($atts['label_class']) . '">' .
                        esc_html($atts['label']) . '</label>';

                    return $labelHtml . $iconWrapper . $iconHtml . $inputHtml . $closeWrapper;
                }

                // Dynamic input field (part of a group)
                return '<input type="' . esc_attr($atts['dynamic_type']) . '" name="' .
                    esc_attr($atts['dynamic_group']) . '[' . esc_attr($atts['name']) . '][]" ' .
                    'value="' . esc_attr($atts['value']) . '" class="' . esc_attr($inputClass) . ' ' .
                    esc_attr($atts['class']) .  '" ' . esc_attr($atts['special']) . ' ' . ($atts['onclick'] ? 'onclick="' . $atts['onclick'] . '" ' : '') . ' tabindex="' . esc_attr($atts['tabindex']) . '"/>';
            }








            //H2 Bold text
            public static function h2tag($atts)
            {
                $atts = shortcode_atts([
                    'title'   => '',
                    'class'    => '',
                    'content' => '', // HTML content like modals or buttons
                ], $atts);

                return '<h2 class="text-lg font-semibold text-gray-800 mb-2 ' . $atts['class'] . '">' . $atts['title'] . '</h2>';
            }
            //H4 Bold text
            public static function h4tag($atts)
            {
                $atts = shortcode_atts([
                    'title'   => '',
                    'class'    => '',
                    'content' => '', // HTML content like modals or buttons
                ], $atts);

                return '<h4 class="text-lg font-semibold text-gray-800 mb-2 ' . $atts['class'] . '">' . $atts['title'] . '</h4>';
            }

            public static function sumShowcase($atts)
            {

                // Debug removed in production

    ?>
        <div class="flex flex-col">
            <div class="relative"><span class="text-gray-600 font-bold"><?= $atts['label'] ?></span>
                <div class="floatingPrice">
                    <?php if ($atts['bigMass']): ?>
                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-green-500 text-green-100">
                            Highest
                        </span>
                    <?php elseif ($atts['bigVolume']): ?>
                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-red-500 text-red-100">
                            Lowest
                        </span>
                    <?php elseif ($atts['is_equal']): ?>
                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-blue-100 text-blue-800">
                            Equal
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="relative">
                <span class="font-medium flex items-center">
                    <?php if ($atts['charge'] === "mass"): ?>
                        <?= KIT_Commons::currency() . ($atts['theRate'] ?? 0) ?> x
                        <?= number_format($atts['howMuch'] ?? 0, 2) ?> kg (<?= KIT_Commons::currency() ?><?= number_format($atts['kolot'], 2) ?>)
                    <?php else: ?>
                        <?= number_format($atts['howMuch'] ?? 0, 2) ?> m³ (<?= KIT_Commons::currency() ?><?= number_format($atts['kolot'], 2) ?>)
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php

            }

            public static function simpleSelect($label, $name, $selectId, $arrayList, $isActive)
            {
                //echo label
                echo '<label for="' . esc_attr($selectId) . '" class="' . self::labelClass() . '">' . esc_html($label) . '</label>';
                echo '<select name="' . $name . '" id="' . $selectId . '" class="' . self::selectClass() . '">';
                foreach ($arrayList as $key => $option) {
                    $selected = ($isActive !== null && (string)$isActive === (string)$key) ? ' selected' : '';
                    echo '<option value="' . esc_attr($key) . '"' . $selected . '>' . esc_html($option) . '</option>';
                }
                echo '</select>';
            }

            //KIT_Commons::SelectInput('','', $shippingDirections);
            public static function SelectInput($label, $name, $selectId, $shippingDirections)
            {
                //echo label
                echo '<label for="' . esc_attr($selectId) . '" class="' . self::labelClass() . '">' . esc_html($label) . '</label>';
                echo '<select name="' . $name . '" id="' . $selectId . '" class="' . self::selectClass() . '">';
                foreach ($shippingDirections as $key => $shipDirection) {
                    echo '<option value="' . esc_attr($shipDirection->id) . '">' . esc_html($shipDirection->description) . '</option>';
                }
                echo '</select>';
            }

            /**
             * Generates a paginated table with the exact styling from the reference template
             * 
             * @param array $data Array of objects or arrays containing the table data
             * @param array $options Configuration options:
             *     - 'fields' (array): Fields to display (required)
             *     - 'items_per_page' (int): Items per page (default: 10)
             *     - 'current_page' (int): Current page number (default: 1)
             *     - 'table_class' (string): CSS class for the table (default: 'min-w-full divide-y divide-gray-200')
             *     - 'actions' (bool): Show actions column (default: false)
             *     - 'show_create_quotation' (bool): Show create quotation button (default: false)
             *     - 'id' (string): ID for the table container (default: 'ajaxtable')
             * @return string HTML output of the table with pagination controls
             */
            public static function paginatedTable($data, $options = [])
            {

                // Default options
                $defaults = [
                    'fields' => [],
                    'items_per_page' => 20,
                    'current_page' => isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1,
                    'table_class' => 'min-w-full divide-y divide-gray-200',
                    'actions' => false,
                    'show_create_quotation' => false,
                    'id' => 'ajaxtable'
                ];
                $options = array_merge($defaults, $options);

                // Convert data to array if it's an object
                if (is_object($data)) {
                    $data = json_decode(json_encode($data), true);
                }

                // Ensure data is an array
                if (!is_array($data)) {
                    return '<div class="error">Invalid data format provided</div>';
                }

                // Calculate pagination values
                $total_items = count($data);
                $total_pages = ceil($total_items / $options['items_per_page']);
                $options['current_page'] = min($options['current_page'], $total_pages);

                // Get current page data
                $offset = ($options['current_page'] - 1) * $options['items_per_page'];
                $paginated_data = array_slice($data, $offset, $options['items_per_page']);

                // Start building HTML
                $html = '';

                // Top pagination controls
                $html .= '<div class="tablenav top flex flex-wrap items-center justify-between gap-4 mb-4">';

                // Items per page dropdown - LEFT SIDE
                $html .= '<div class="flex items-center space-x-2">';
                $html .= '<label for="items-per-page" class="text-xs text-gray-600 whitespace-nowrap">Items per page:</label>';
                $html .= '<form method="get" id="items-per-page-form" style="display:inline;">';

                // Preserve other query params
                foreach ($_GET as $key => $val) {
                    if ($key !== 'items_per_page' && $key !== 'paged') {
                        $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '">';
                    }
                }

                $html .= '<select id="items-per-page" name="items_per_page" class="text-xs rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 shadow-sm py-1 pl-2 pr-8" onchange="this.form.submit()">';
                $per_page_options = [5, 10, 20, 50, 100];
                foreach ($per_page_options as $option) {
                    $selected = $options['items_per_page'] == $option ? ' selected' : '';
                    $html .= '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
                }
                $html .= '</select>';
                $html .= '</form>';

                // Item count
                $html .= '<span class="text-xs text-gray-600 whitespace-nowrap">';
                $html .= number_format($total_items) . ' item' . ($total_items !== 1 ? 's' : '');
                $html .= '</span>';
                $html .= '</div>'; // End left side

                // Pagination links - RIGHT SIDE
                $html .= '<div class="flex items-center space-x-1">';

                if ($total_pages > 1) {
                    $pagination_args = [
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $options['current_page'],
                        'total' => $total_pages,
                        'prev_next' => true,
                        'prev_text' => '<span class="px-3 py-1 rounded border-gray-300 bg-white text-gray-700 hover:bg-gray-50">&laquo; Previous</span>',
                        'next_text' => '<span class="px-3 py-1 rounded border-gray-300 bg-white text-gray-700 hover:bg-gray-50">Next &raquo;</span>',
                        'add_args' => ['items_per_page' => $options['items_per_page']],
                        'type' => 'array'
                    ];

                    $pagination_links = paginate_links($pagination_args);

                    if ($pagination_links) {
                        foreach ($pagination_links as $link) {
                            // Add styling to current page
                            // Ensure link is not null before using string functions
                            $link = (string)($link ?? '');
                            if (strpos($link, 'current') !== false) {
                                $link = str_replace('page-numbers current', 'page-numbers current px-3 py-1 rounded border bg-blue-50 border-blue-500 text-blue-600', $link);
                            } else {
                                $link = str_replace('page-numbers', 'page-numbers px-3 py-1 rounded border border-gray-300 bg-white text-gray-700 hover:bg-gray-50', $link);
                            }
                            $html .= $link;
                        }
                    }
                }

                $html .= '</div>'; // End right side
                $html .= '</div>'; // End tablenav

                // Table
                $html .= '<table id="' . esc_attr($options['id']) . '" class="' . esc_attr($options['table_class']) . '">';
                $html .= '<thead class="bg-gray-50"><tr>';

                // Headers
                foreach ($options['headers'] as $field) {
                    if ($field !== 'customer_surname') {
                        $html .= '<th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">';
                        $html .= esc_html(ucfirst(str_replace('_', ' ', $field)));
                        $html .= '</th>';
                    }
                }


                if ($options['actions']) {
                    $html .= '<th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>';
                }

                $html .= '</tr></thead>';
                $html .= '<tbody>';

                // Table rows
                foreach ($paginated_data as $item) {
                    // Convert to object if it's an array
                    if (is_array($item)) {
                        $item = (object) $item;
                    }

                    $html .= '<tr class="bg-white" data-waybill-id="' . esc_attr($item->waybill_id ?? '') . '">';

                    foreach ($options['headers'] as $field) {

                        /* If the $field is Waybill data and not customer data, then show the waybill data */
                        if ($field !== 'customer_surname') {
                            $html .= '<td class="px-4 py-3 whitespace-nowrap"><div class="text-xs">';
                            if ($field === 'approval') {
                                if (current_user_can('administrator')) {
                                    $html .= self::waybillApprovalStatus($item->waybill_no, $item->waybill_id ?? 0, 'quoted', $item->approval ?? '', 'select');
                                } else {
                                    $html .= self::statusBadge($item->approval ?? '');
                                }
                            } elseif ($field === 'waybill no') {
                                // Access waybill_no property directly
                                $html .= '<a href="' . admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . ($item->waybill_id ?? '') . '&waybill_atts=view_waybill') . '" target="_blank" style="color:inherit; text-decoration:none;">';
                                $html .= '<span class="font-medium text-blue-600" style="color:inherit;">' . esc_html($item->waybill_no ?? '') . '</span>';
                                if (!empty($item->created_at)) {
                                    $html .= '<div class="text-xs text-gray-500 mt-1">' . date('M d', strtotime($item->created_at)) . '</div>';
                                }
                                $html .= '</a>';
                            } elseif ($field === 'customer_name') {
                                $html .= '<a href="?page=all-customer-waybills&amp;cust_id=' . esc_attr($item->customer_id ?? '') . '" target="_blank" style="color:inherit; text-decoration:none;">';
                                $html .= '<span class="font-medium text-blue-600">' . esc_html($item->customer_name ?? '') . '</span>';
                                if (!empty($item->customer_surname)) {
                                    $html .= '<div class="text-xs text-gray-500 truncate max-w-xs" title="' . esc_attr($item->customer_surname) . '">';
                                    $html .= esc_html($item->customer_surname);
                                    $html .= '</div>';
                                }
                                $html .= '</a>';
                            } elseif ($field === 'total') {
                                if (class_exists('KIT_User_Roles') && !KIT_User_Roles::can_see_prices()) {
                                    $html .= '<span class="text-xs text-gray-500">***</span>';
                                } else {
                                    $html .= '<span class="text-bold text-blue-600">' . self::currency() . '</span>';
                                    $html .= '<span class="text-xs text-gray-500">' . (!empty($item->waybill_no) ? KIT_Waybills::get_total_cost_of_waybill($item->waybill_no) : '') . '</span>';
                                }
                            } else {
                                $html .= esc_html($item->$field ?? '');
                            }

                            $html .= '</div></td>';
                        }

                        /* If the $field is Customer data and not waybill data, then show the customer data */
                        if ($field === 'customer_name') {
                            $html .= '<td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">' . esc_html($item->customer_name ?? '') . '</td>';
                        }
                    }

                    if ($options['actions']) {
                        $html .= '<td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500"><div class="flex space-x-2">';

                        // View action
                        $html .= '<a href="' . admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . ($item->waybill_id ?? '') . '&waybill_atts=view_waybill') . '" class="text-blue-600 hover:text-blue-900" title="View">';
                        $html .= '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">';
                        $html .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />';
                        $html .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />';
                        $html .= '</svg></a>';

                        // Print action
                        $html .= '<a href="' . plugin_dir_url(__FILE__) . '../pdf-generator.php?waybill_no=' . ($item->waybill_id ?? '') . '&pdf_nonce=' . wp_create_nonce('pdf_nonce') . '" target="_blank" class="text-indigo-600 hover:text-indigo-900" title="Print">';
                        $html .= '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">';
                        $html .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />';
                        $html .= '</svg></a>';

                        // Create quotation button (for admins/managers)
                        if (($options['show_create_quotation']) && (current_user_can('administrator') || current_user_can('manager'))) {
                            $html .= self::renderButton('Quote', 'success', 'sm', [
                                'title' => 'Create Quotation',
                                'data-waybill-id' => $item->waybill_id ?? '',
                                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />',
                                'iconPosition' => 'left',
                                'classes' => 'create-quotation',
                                'gradient' => true
                            ]);
                        }

                        // Delete button
                        $current_user = wp_get_current_user();
                        $html .= self::deleteWaybillGlobal($item->waybill_id ?? 0, $item->waybill_no ?? '', $item->delivery_id ?? 0, $current_user->ID);

                        $html .= '</div></td>';
                    }

                    $html .= '</tr>';
                }

                $html .= '</tbody></table>';

                return $html;
            }

            public static function Ratebox()
            {
                //return a input that that uses $atts['name'], $atts['type'], $atts['label']
    ?>

    <?php
                return '<div class="asdasd"></div>';
            }

            public static function selectClass()
            {
                return 'text-xs w-full px-3 py-2 border border-gray-300  shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 border border-gray-300 rounded px-3 py-2 bg-white';
            }
            public static function inputClass()
            {
                return 'text-xs w-full px-3 py-2 border border-gray-300  shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 border border-gray-300 rounded px-3 py-2 bg-white';
            }
            /**
             * 08600 Button Theme System - Tailwind CSS Based
             * Following 60-30-10 color rule with Blue (#2563eb) as primary
             */

            // Base button classes
            public static function buttonClass()
            {
                return 'inline-flex items-center gap-2 border rounded';
            }

            // Size-based padding and min-width for consistent button dimensions
            public static function buttonSizePadding($size = 'md')
            {
                $sizeClasses = [
                    'sm' => 'px-2 py-1 text-xs',
                    'md' => 'px-3 py-1.5 text-sm min-w-[7.5rem]',
                    'lg' => 'px-4 py-2 text-base'
                ];
                return $sizeClasses[$size] ?? $sizeClasses['md'];
            }

            // Primary button (60% - Main Blue)
            public static function buttonPrimary($size = 'md', $fullWidth = false, $gradient = false, $plain = false)
            {
                $sizePadding = self::buttonSizePadding($size);
                $widthClass = $fullWidth ? 'w-full' : '';

                // Gradient takes precedence over plain
                if ($gradient) {
                    return $sizePadding . ' bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white border-blue-600 ' . $widthClass;
                }

                // Plain style (default)
                if ($plain) {
                    return $sizePadding . ' bg-white hover:bg-gray-50 text-black border-gray-300 ' . $widthClass;
                }

                // Regular colored button (when plain is explicitly false)
                return $sizePadding . ' bg-blue-600 hover:bg-blue-700 text-white border-blue-600 ' . $widthClass;
            }

            // Secondary button (30% - Gray)
            public static function buttonSecondary($size = 'md', $fullWidth = false, $gradient = false, $plain = false)
            {
                $sizePadding = self::buttonSizePadding($size);
                $widthClass = $fullWidth ? 'w-full' : '';

                // Gradient takes precedence over plain
                if ($gradient) {
                    return $sizePadding . ' bg-gradient-to-r from-gray-100 to-gray-200 hover:from-gray-200 hover:to-gray-300 text-gray-700 border-gray-300 ' . $widthClass;
                }

                // Plain style (default)
                if ($plain) {
                    return $sizePadding . ' bg-white hover:bg-gray-50 text-black border-gray-300 ' . $widthClass;
                }

                // Regular colored button (when plain is explicitly false)
                return $sizePadding . ' bg-gray-100 hover:bg-gray-200 text-gray-700 border-gray-300 ' . $widthClass;
            }

            // Success button (10% - Green)
            public static function buttonSuccess($size = 'md', $fullWidth = false, $gradient = false, $plain = false)
            {
                $sizePadding = self::buttonSizePadding($size);
                $widthClass = $fullWidth ? 'w-full' : '';

                // Gradient takes precedence over plain
                if ($gradient) {
                    return $sizePadding . ' bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white border-green-600 ' . $widthClass;
                }

                // Plain style (default)
                if ($plain) {
                    return $sizePadding . ' bg-white hover:bg-gray-50 text-black border-gray-300 ' . $widthClass;
                }

                // Regular colored button (when plain is explicitly false)
                return $sizePadding . ' bg-green-600 hover:bg-green-700 text-white border-green-600 ' . $widthClass;
            }

            // Danger button (10% - Red)
            public static function buttonDanger($size = 'md', $fullWidth = false, $gradient = false, $plain = false)
            {
                $sizePadding = self::buttonSizePadding($size);
                $widthClass = $fullWidth ? 'w-full' : '';

                // Gradient takes precedence over plain
                if ($gradient) {
                    return $sizePadding . ' bg-gradient-to-r from-red-600 to-rose-600 hover:from-red-700 hover:to-rose-700 text-white border-red-600 ' . $widthClass;
                }

                // Plain style (default)
                if ($plain) {
                    return $sizePadding . ' bg-white hover:bg-gray-50 text-black border-gray-300 ' . $widthClass;
                }

                // Regular colored button (when plain is explicitly false)
                return $sizePadding . ' bg-red-600 hover:bg-red-700 text-white border-red-600 ' . $widthClass;
            }

            // Warning button (10% - Orange)
            public static function buttonWarning($size = 'md', $fullWidth = false, $gradient = false, $plain = false)
            {
                $sizePadding = self::buttonSizePadding($size);
                $widthClass = $fullWidth ? 'w-full' : '';

                // Gradient takes precedence over plain
                if ($gradient) {
                    return $sizePadding . ' bg-gradient-to-r from-orange-600 to-amber-600 hover:from-orange-700 hover:to-amber-700 text-white border-orange-600 ' . $widthClass;
                }

                // Plain style (default)
                if ($plain) {
                    return $sizePadding . ' bg-white hover:bg-gray-50 text-black border-gray-300 ' . $widthClass;
                }

                // Regular colored button (when plain is explicitly false)
                return $sizePadding . ' bg-orange-600 hover:bg-orange-700 text-white border-orange-600 ' . $widthClass;
            }

            // Outline button variants
            public static function buttonOutlinePrimary($size = 'md', $fullWidth = false)
            {
                $sizePadding = self::buttonSizePadding($size);
                $widthClass = $fullWidth ? 'w-full' : '';
                return $sizePadding . ' bg-transparent hover:bg-blue-50 text-blue-600 border-blue-600 ' . $widthClass;
            }

            public static function buttonOutlineSecondary($size = 'md', $fullWidth = false)
            {
                $sizePadding = self::buttonSizePadding($size);
                $widthClass = $fullWidth ? 'w-full' : '';
                return $sizePadding . ' bg-transparent hover:bg-gray-50 text-gray-600 border-gray-300 ' . $widthClass;
            }

            // Ghost button variants
            public static function buttonGhost($size = 'md', $fullWidth = false)
            {
                $sizePadding = self::buttonSizePadding($size);
                $widthClass = $fullWidth ? 'w-full' : '';
                return $sizePadding . ' bg-transparent hover:bg-gray-100 text-gray-600 hover:text-gray-800 border-transparent ' . $widthClass;
            }

            public static function buttonGhostPrimary($size = 'md', $fullWidth = false)
            {
                $sizePadding = self::buttonSizePadding($size);
                $widthClass = $fullWidth ? 'w-full' : '';
                return $sizePadding . ' bg-transparent hover:bg-blue-50 text-blue-600 hover:text-blue-700 border-transparent ' . $widthClass;
            }

            // Tab button styles
            public static function buttonTab($active = false)
            {
                if ($active) {
                    return 'bg-white text-gray-900 shadow-sm border border-gray-200 px-6 py-3 font-medium text-sm transition-all duration-200';
                }
                return 'bg-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 px-6 py-3 font-medium text-sm transition-all duration-200';
            }

            // Toggle button styles
            public static function buttonToggle($active = false)
            {
                if ($active) {
                    return 'bg-white text-gray-900 shadow-sm border border-gray-200 px-4 py-2  font-medium text-sm transition-all duration-200';
                }
                return 'bg-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 px-4 py-2  font-medium text-sm transition-all duration-200';
            }

            // Link button styles
            public static function buttonLink($size = 'md')
            {
                $sizePadding = self::buttonSizePadding($size);
                return $sizePadding . ' bg-transparent text-blue-600 hover:text-blue-700 hover:underline border-transparent';
            }

            // Icon button styles
            public static function buttonIcon($size = 'md')
            {
                $sizeClasses = [
                    'sm' => 'p-2 w-8 h-8',
                    'md' => 'p-3 w-10 h-10',
                    'lg' => 'p-4 w-12 h-12'
                ];

                return 'inline-flex items-center justify-center transition-all duration-200 ' . $sizeClasses[$size];
            }

            // Loading state
            public static function buttonLoading()
            {
                return 'relative text-transparent';
            }

            // Disabled state
            public static function buttonDisabled()
            {
                return 'opacity-50 cursor-not-allowed pointer-events-none';
            }

            /**
             * Generate complete button HTML with consistent styling
             */
            public static function renderButton($text, $type = 'primary', $size = 'md', $options = [])
            {
                $defaults = [
                    'href' => null,
                    'onclick' => null,
                    'disabled' => false,
                    'loading' => false,
                    'fullWidth' => false,
                    'icon' => null,
                    'iconPosition' => 'left', // 'left' or 'right'
                    'classes' => '',
                    'id' => null,
                    'name' => null,
                    'value' => null,
                    'type' => 'button',
                    'form' => null,
                    'gradient' => false, // Enable gradient for primary buttons
                    'plain' => true, // Default to plain button style (white bg, gray border, black text). Set to false for colored buttons.
                    'modal' => null, // Modal trigger (custom)
                    'data-bs-toggle' => null, // Bootstrap 5 modal toggle
                    'data-bs-target' => null, // Bootstrap 5 modal target
                    'data-bs-dismiss' => null, // Bootstrap 5 dismiss (e.g. "modal")
                    'data-target' => null, // Data target attribute
                    'target' => null,
                    'rel' => null,
                    'ariaLabel' => null,
                    'color' => null, // Tailwind color name (e.g., 'blue', 'pink', 'red')
                ];

                $options = array_merge($defaults, $options);
                // Primary action buttons (Edit, PDF, etc.) use gradient by default so they match size and style
                if ($type === 'primary' && (empty($options['gradient']) || $options['gradient'] === false)) {
                    $options['gradient'] = true;
                }
                // If color is specified, use custom color styling with sharp design
                if (!empty($options['color'])) {
                    $color = sanitize_text_field($options['color']);
                    // Generate sharp styling with custom color (no base classes needed)
                    $typeClasses = 'inline-flex items-center px-4 py-2 bg-' . $color . '-600 text-white rounded-md shadow-sm text-sm font-medium hover:bg-' . $color . '-700';
                    $baseClasses = ''; // No base classes when using custom color
                } else {
                    // Get base classes
                    $baseClasses = self::buttonClass();

                    // Get type-specific classes
                    $typeClasses = '';
                    switch ($type) {
                        case 'primary':
                            $typeClasses = self::buttonPrimary($size, $options['fullWidth'], $options['gradient'], $options['plain']);
                            break;
                        case 'secondary':
                            $typeClasses = self::buttonSecondary($size, $options['fullWidth'], $options['gradient'], $options['plain']);
                            break;
                        case 'success':
                            $typeClasses = self::buttonSuccess($size, $options['fullWidth'], $options['gradient'], $options['plain']);
                            break;
                        case 'danger':
                            $typeClasses = self::buttonDanger($size, $options['fullWidth'], $options['gradient'], $options['plain']);
                            break;
                        case 'warning':
                            $typeClasses = self::buttonWarning($size, $options['fullWidth'], $options['gradient'], $options['plain']);
                            break;
                        case 'outline-primary':
                            $typeClasses = self::buttonOutlinePrimary($size, $options['fullWidth']);
                            break;
                        case 'outline-secondary':
                            $typeClasses = self::buttonOutlineSecondary($size, $options['fullWidth']);
                            break;
                        case 'ghost':
                            $typeClasses = self::buttonGhost($size, $options['fullWidth']);
                            break;
                        case 'ghost-primary':
                            $typeClasses = self::buttonGhostPrimary($size, $options['fullWidth']);
                            break;
                        case 'link':
                            $typeClasses = self::buttonLink($size);
                            break;
                        default:
                            $typeClasses = self::buttonPrimary($size, $options['fullWidth'], false, $options['plain']);
                    }

                    // Combine base classes with type classes
                    $typeClasses = trim($baseClasses . ' ' . $typeClasses);
                }

                // Add loading state
                if ($options['loading']) {
                    $typeClasses .= ' ' . self::buttonLoading();
                }

                // Add disabled state
                if ($options['disabled']) {
                    $typeClasses .= ' ' . self::buttonDisabled();
                }
                // Combine all classes
                $allClasses = trim($typeClasses . ' ' . $options['classes']);

                // Safety: if not disabled, strip any disabling utility classes that may have been passed in
                if (empty($options['disabled']) || $options['disabled'] === false || $options['disabled'] === 'false') {
                    $allClasses = preg_replace('/\b(opacity-50|cursor-not-allowed|pointer-events-none)\b/', '', $allClasses);
                    $allClasses = preg_replace('/\s+/', ' ', trim($allClasses));
                }

                // Build attributes
                $attributes = [];
                // Build attributes only if the option is set and not null/empty (except for boolean flags)
                if (!empty($options['id'])) {
                    $attributes[] = 'id="' . esc_attr($options['id']) . '"';
                }
                if (!empty($options['name'])) {
                    $attributes[] = 'name="' . esc_attr($options['name']) . '"';
                }
                if (isset($options['value']) && $options['value'] !== '') {
                    $attributes[] = 'value="' . esc_attr($options['value']) . '"';
                }
                if (!empty($options['onclick'])) {
                    $attributes[] = 'onclick="' . htmlspecialchars($options['onclick'], ENT_QUOTES) . '"';
                }
                if (!empty($options['disabled']) && $options['disabled'] !== "false" && $options['disabled'] !== false) {
                    $attributes[] = 'disabled';
                }
                if (!empty($options['type'])) {
                    $attributes[] = 'type="' . esc_attr($options['type']) . '"';
                }
                if (!empty($options['form'])) {
                    $attributes[] = 'form="' . esc_attr($options['form']) . '"';
                }
                if (!empty($options['modal'])) {
                    $attributes[] = 'data-modal="' . esc_attr($options['modal']) . '"';
                }
                if (!empty($options['data-bs-toggle'])) {
                    $attributes[] = 'data-bs-toggle="' . esc_attr($options['data-bs-toggle']) . '"';
                }
                if (!empty($options['data-bs-target'])) {
                    $attributes[] = 'data-bs-target="' . esc_attr($options['data-bs-target']) . '"';
                }
                if (isset($options['data-bs-dismiss']) && $options['data-bs-dismiss'] !== '' && $options['data-bs-dismiss'] !== null) {
                    $attributes[] = 'data-bs-dismiss="' . esc_attr($options['data-bs-dismiss']) . '"';
                }
                if (!empty($options['data-target'])) {
                    $attributes[] = 'data-target="' . esc_attr($options['data-target']) . '"';
                }
                if (!empty($options['target'])) {
                    $attributes[] = 'target="' . esc_attr($options['target']) . '"';
                }
                if (!empty($options['rel'])) {
                    $attributes[] = 'rel="' . esc_attr($options['rel']) . '"';
                }
                if (!empty($options['ariaLabel'])) {
                    $attributes[] = 'aria-label="' . esc_attr($options['ariaLabel']) . '"';
                }
                if (isset($options['role']) && $options['role'] !== '') {
                    $attributes[] = 'role="' . esc_attr($options['role']) . '"';
                }
                if (isset($options['ariaExpanded']) && $options['ariaExpanded'] !== '') {
                    $attributes[] = 'aria-expanded="' . esc_attr($options['ariaExpanded']) . '"';
                }
                if (!empty($options['title'])) {
                    $attributes[] = 'title="' . esc_attr($options['title']) . '"';
                }
                if (!empty($options['style'])) {
                    $attributes[] = 'style="' . esc_attr($options['style']) . '"';
                }

                // Handle data attributes (any option starting with 'data-')
                foreach ($options as $key => $value) {
                    if (strpos($key, 'data-') === 0 && !empty($value)) {
                        $attributes[] = esc_attr($key) . '="' . esc_attr($value) . '"';
                    }
                }

                $attributesStr = implode(' ', $attributes);

                // Build content
                $content = '';

                $svgClass = 'w-4 h-4' . (!empty($options['iconClasses']) ? ' ' . esc_attr($options['iconClasses']) : '');
                $svgAttrs = 'class="' . $svgClass . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"';
                if (!empty($options['iconId'])) {
                    $svgAttrs = 'id="' . esc_attr($options['iconId']) . '" ' . $svgAttrs;
                }
                // Add icon if specified
                if ($options['icon'] && $options['iconPosition'] === 'left') {
                    $content .= '<svg ' . $svgAttrs . '>' . $options['icon'] . '</svg>';
                }

                if (empty($options['iconOnly'])) {
                    $spanOpen = '<span';
                    if (!empty($options['contentId'])) {
                        $spanOpen .= ' id="' . esc_attr($options['contentId']) . '"';
                    }
                    $spanOpen .= '>';
                    $content .= $spanOpen . (!empty($options['rawHtml']) ? $text : esc_html($text)) . '</span>';
                }

                // Add icon if specified (right position)
                if ($options['icon'] && $options['iconPosition'] === 'right') {
                    $content .= '<svg ' . $svgAttrs . '>' . $options['icon'] . '</svg>';
                }

                // Add loading spinner
                if ($options['loading']) {
                    $content .= '<svg class="absolute w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>';
                }

                // Return button HTML
                if ($options['href']) {
                    return '<a href="' . esc_url($options['href']) . '" class="' . $allClasses . '" ' . $attributesStr . '>' . $content . '</a>';
                } else {
                    return '<button class="' . $allClasses . '" ' . $attributesStr . '>' . $content . '</button>';
                }
            }

            /**
             * Generate a pretty heading with icon and customizable text
             * 
             * @param array $args {
             *     @type string $icon     SVG icon code (free version)
             *     @type string $words    Heading text content
             *     @type string $size     Heading size: 'sm', 'md', 'lg', 'xl', '2xl' (default: '2xl')
             *     @type string $color    Text color: 'blue', 'gray', 'green', 'red', 'purple' (default: 'blue')
             *     @type string $classes  Additional CSS classes
             *     @type string $tag      HTML tag: 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' (default: 'h2')
             * }
             * @return string HTML heading element
             */
            public static function prettyHeading($args = [])
            {
                $defaults = [
                    'icon' => '',
                    'words' => '',
                    'size' => '2xl',
                    'color' => 'black',
                    'classes' => '',
                    'tag' => 'h2',
                    'subheading' => ''
                ];

                $args = array_merge($defaults, $args);

                // Validate tag
                $validTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
                $tag = in_array($args['tag'], $validTags) ? $args['tag'] : 'h2';

                // Size classes
                $sizeClasses = [
                    'sm' => 'text-lg',
                    'md' => 'text-xl',
                    'lg' => 'text-2xl',
                    'xl' => 'text-3xl',
                    '2xl' => 'text-2xl'
                ];
                $sizeClass = isset($sizeClasses[$args['size']]) ? $sizeClasses[$args['size']] : $sizeClasses['2xl'];

                // Color classes
                $colorClasses = [
                    'black' => 'text-black',
                    'blue' => 'text-blue-700',
                    'gray' => 'text-gray-700',
                    'green' => 'text-green-700',
                    'red' => 'text-red-700',
                    'purple' => 'text-purple-700'
                ];
                $colorClass = isset($colorClasses[$args['color']]) ? $colorClasses[$args['color']] : $colorClasses['blue'];

                // Icon color (slightly lighter than text)
                $iconColorClasses = [
                    'blue' => 'text-blue-500',
                    'gray' => 'text-gray-500',
                    'green' => 'text-green-500',
                    'red' => 'text-red-500',
                    'purple' => 'text-purple-500'
                ];
                $iconColorClass = isset($iconColorClasses[$args['color']]) ? $iconColorClasses[$args['color']] : $iconColorClasses['blue'];

                // Build classes
                $allClasses = trim("font-bold {$sizeClass} {$colorClass} flex items-center gap-2 leading-none {$args['classes']}");

                // Build icon HTML if provided
                $iconHtml = '';
                if (!empty($args['icon'])) {
                    $iconHtml = '<svg class="w-6 h-6 self-center ' . esc_attr($iconColorClass) . '" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">' .
                        $args['icon'] .
                        '</svg>';
                }

                // Build subheading if provided
                $subheadingHtml = '';
                if (!empty($args['subheading'])) {
                    $subheadingHtml = '<p class="text-xs text-gray-500 mt-1">' . esc_html($args['subheading']) . '</p>';
                }

                // Build the heading
                $heading = '<' . $tag . ' class="' . esc_attr($allClasses) . '">' .
                    $iconHtml .
                    esc_html($args['words']) .
                    '</' . $tag . '>'.
                    $subheadingHtml ;

                return $heading;
            }
            
            public static function bossText($args = [])
            {
                $defaults = [
                    'icon' => '',
                    'words' => '',
                    'size' => '2xl',
                    'color' => 'black',
                    'classes' => '',
                    'tag' => 'h2'
                ];
                // Allow legacy string call: bossText('Some title') -> ['words' => 'Some title']
                if (!is_array($args)) {
                    $args = ['words' => (string) $args];
                }
                $args = array_merge($defaults, $args);

                // Validate tag
                $validTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
                $tag = in_array($args['tag'], $validTags) ? $args['tag'] : 'h2';

                // Size classes
                $sizeClasses = [
                    'sm' => 'text-lg',
                    'md' => 'text-xl',
                    'lg' => 'text-2xl',
                    'xl' => 'text-3xl',
                    '2xl' => 'text-2xl'
                ];
                $sizeClass = isset($sizeClasses[$args['size']]) ? $sizeClasses[$args['size']] : $sizeClasses['2xl'];

                // Color classes
                $colorClasses = [
                    'black' => 'text-black',
                    'blue' => 'text-blue-700',
                    'gray' => 'text-gray-700',
                    'green' => 'text-green-700',
                    'red' => 'text-red-700',
                    'purple' => 'text-purple-700'
                ];
                $colorClass = isset($colorClasses[$args['color']]) ? $colorClasses[$args['color']] : $colorClasses['blue'];

                // Icon color (slightly lighter than text)
                $iconColorClasses = [
                    'blue' => 'text-blue-500',
                    'gray' => 'text-gray-500',
                    'green' => 'text-green-500',
                    'red' => 'text-red-500',
                    'purple' => 'text-purple-500'
                ];
                $iconColorClass = isset($iconColorClasses[$args['color']]) ? $iconColorClasses[$args['color']] : $iconColorClasses['blue'];

                // Build classes
                $allClasses = trim("font-bold {$sizeClass} {$colorClass} flex items-center gap-2 {$args['classes']}");

                // Build icon HTML if provided
                $iconHtml = '';
                if (!empty($args['icon'])) {
                    $iconHtml = '<svg class="w-6 h-6 ' . esc_attr($iconColorClass) . '" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">' .
                        $args['icon'] .
                        '</svg>';
                }

                // Build the heading
                $heading = '<' . $tag . ' class="' . esc_attr($allClasses) . '">' .
                    $iconHtml .
                    esc_html($args['words']) .
                    '</' . $tag . '>';

                return $heading;
            }

            /**
             * Lightweight icon registry (stroke-based, currentColor)
             * Returns raw SVG children (paths/rects/circles) to embed inside our helpers
             */
            public static function icon(string $name): string
            {
                static $icons = null;
                if ($icons === null) {
                    $icons = [
                        'truck' => '<rect x="2" y="6" width="11" height="8" rx="1.5"/><path d="M13 10h5l2 3v4H9M2 17h2"/><rect x="16" y="11" width="3" height="2.5" rx=".4"/><circle cx="7" cy="17" r="2"/><circle cx="18" cy="17" r="2"/>',
                        'package' => '<path d="M3 7l9 5 9-5M3 7v10l9 5V12M21 7v10l-9 5"/>',
                        'receipt' => '<path d="M7 3h10a2 2 0 0 1 2 2v14l-3-2-3 2-3-2-3 2V5a2 2 0 0 1 2-2"/><path d="M8 7h8M8 11h8M8 15h5"/>',
                        'scale' => '<path d="M12 3v18M6 22h12"/><path d="M5 9l-3 5h6l-3-5zM19 9l-3 5h6l-3-5z"/>',
                        'barcode' => '<path d="M4 6v12M7 6v12M9 6v12M12 6v12M14 6v12M17 6v12M20 6v12"/>',
                        'map-pin' => '<path d="M12 22s7-7 7-12a7 7 0 1 0-14 0c0 5 7 12 7 12z"/><circle cx="12" cy="10" r="3"/>',
                        'warehouse' => '<path d="M3 9l9-5 9 5v11H3V9z"/><path d="M7 20v-6h10v6"/>',
                        'user-group' => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 1 1 0 7.75"/>',
                        'credit-card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/>',
                        'invoice' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 9h4M8 13h8M8 17h8"/>'
                    ];
                }
                return $icons[$name] ?? '';
            }

            public static function labelClass()
            {
                return 'block font-bold text-gray-700 mb-1';
            }

            public static function tbodyClasses()
            {
                return 'bg-white divide-y divide-gray-200'; // Default tbody classes
            }

            public static function tableClasses()
            {
                // Unified Tailwind table styling
                return 'min-w-full table-auto border-separate border-spacing-0 divide-y divide-gray-200';
            }

            public static function trowClasses()
            {
                return 'border-b border-gray-200 hover:bg-gray-50 transition-colors duration-200 align-middle';
            }
            public static function thClasses()
            {
                return 'px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider bg-gray-50 sticky top-0 z-10';
            }
            public static function tcolClasses()
            {
                //return 'px-6 py-4 text-sm text-gray-900';
                return 'px-4 py-2.5 text-sm text-gray-700 whitespace-nowrap';
            }

            public static function yspacingClass()
            {
                return 'space-y-3'; // Default spacing class
            }
            public static function currency()
            {
                return 'R'; // Rands
            }

            /**
             * Check if current user is an admin
             * @return bool
             */
            public static function isAdmin()
            {
                return KIT_User_Roles::is_admin();
            }

            /**
             * Check if current user can see prices
             * @return bool
             */
            public static function can_see_prices()
            {
                $current_user = wp_get_current_user();
                $user_roles = is_array($current_user->roles) ? $current_user->roles : [];
                $username = isset($current_user->user_login) ? strtolower($current_user->user_login) : '';
                $allowed_users = ['thando', 'mel', 'patricia'];
                $is_admin = in_array('administrator', $user_roles) || current_user_can('manage_options');
                return $is_admin && in_array($username, $allowed_users, true);
            }

            /**
             * Display waybill total with price visibility control
             * @param float $amount
             * @param string $fallback_text
             * @return string
             */
            public static function displayWaybillTotal($amount, $fallback_text = '***')
            {
                if (self::can_see_prices()) {
                    return self::currency() . ' ' . number_format($amount, 2);
                } else {
                    return $fallback_text;
                }
            }
            public static function container()
            {
                //return 'max-w-7xl mx-auto';
            }


            /**
             * Deletes data.
             * 
             * Example usage:
             * KIT_Commons::deleteWaybillGlobal($waybill->id, $waybill->no);
             */

            public static function deleteWaybillGlobal($waybillid, $waybillNo, $waybill_delivery_id, $customer_id)
            {
                ob_start();
    ?>
        <form method="POST" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="delete_waybill">
            <?php wp_nonce_field('delete_waybill_nonce') ?>
            <input type="hidden" name="waybill_id" value="<?= esc_attr($waybillid) ?>">
            <input type="hidden" name="waybill_no" value="<?= esc_attr($waybillNo) ?>">
            <input type="hidden" name="delivery_id" value="<?= esc_attr($waybill_delivery_id) ?>">
            <input type="hidden" name="user_id" value="<?= esc_attr($customer_id) ?>">
            <?php echo self::renderButton('Delete', 'danger', 'sm', [
                    'type' => 'submit',
                    'classes' => 'delete-waybill',
                    'gradient' => true
                ]); ?>
        </form>
    <?php
                return ob_get_clean();
            }


            /**
             * Renders a table for waybill tracking and data.
             * 
             * Example usage:
             * KIT_Commons::waybillTrackAndData($waybill);
             * 
             * @param array $waybill Array of waybill data.
             * @return string HTML table for waybill tracking.
             */

            public static function waybillTrackAndData($waybill)
            {
                if (empty($waybill)) {
                    return "No Waybill items";
                }
    ?>
        <table class="<?= self::tableClasses(); ?> w-full" style="border-spacing: 0;">
            <thead>
                <tr class="bg-gray-100" style="font-size: 5px;">
                    <th class="<?= self::thClasses() ?> text-left">Item</th>
                    <?php if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()): ?>
                        <th class="<?= self::thClasses() ?> text-center">Quantity</th>
                        <th class="<?= self::thClasses() ?> text-right">Unit Price</th>
                        <th class="<?= self::thClasses() ?> text-right">Sub Total</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="<?= self::tbodyClasses() ?>">
                <?php
                $grand_total = 0;
                foreach ($waybill as $key => $value) {
                    $qty = isset($value['quantity']) ? (float)$value['quantity'] : 0;
                    $unit = isset($value['unit_price']) ? (float)$value['unit_price'] : 0;
                    $sub = $qty * $unit;
                    $grand_total += $sub;
                ?>
                    <tr class="border-t border-gray-100">
                        <td class="<?= self::tcolClasses() ?>"><?= $value['item_name'] ?></td>
                        <?php if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()): ?>
                            <td class="<?= self::tcolClasses() ?> text-center"><?= number_format($qty, 0) ?></td>
                            <td class="<?= self::tcolClasses() ?> text-right"><?= number_format($unit, 2) ?></td>
                            <td class="<?= self::tcolClasses() ?> text-right"><?= number_format($sub, 2) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php
                }
                ?>
            </tbody>
            <?php if (class_exists('KIT_User_Roles') && !KIT_User_Roles::can_see_prices()): ?>

            <?php else: ?>
                <tfoot>
                    <tr class="border-t">
                        <td colspan="3" class="<?= self::tcolClasses() ?> text-right font-semibold">Total</td>
                        <td class="<?= self::tcolClasses() ?> text-right font-bold"><?= number_format($grand_total, 2) ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>

        </table>
    <?php
                return ob_get_clean();
            }

            // DEPRECATED: Use renderButton() instead
            public static function simpleBtn($atts = [])
            {
                $atts = shortcode_atts([
                    'type'    => 'button',
                    'class'   => '',
                    'onclick' => '',
                    'id'      => '',
                    'name'    => '',
                    'text'    => 'Button',
                    'data-target' => '',
                    'disabled' => false,
                ], $atts);

                // Convert to renderButton format
                $type = 'primary';
                $options = [
                    'type' => $atts['type'],
                    'onclick' => $atts['onclick'],
                    'id' => $atts['id'],
                    'name' => $atts['name'],
                    'disabled' => $atts['disabled'],
                    'data-target' => $atts['data-target'],
                    'classes' => $atts['class']
                ];

                return self::renderButton($atts['text'], $type, 'md', $options);
            }

            /**
             * Build breadcrumbs for the current admin/portal page.
             *
             * @param string $title Current page title.
             * @param array|null $manual_breadcrumbs Optional explicit breadcrumbs.
             * @return array[]
             */
            public static function resolveBreadcrumbs($title = '', $manual_breadcrumbs = null)
            {
                if (is_array($manual_breadcrumbs)) {
                    return $manual_breadcrumbs;
                }

                if (is_string($manual_breadcrumbs) && $manual_breadcrumbs !== '') {
                    $decoded = json_decode($manual_breadcrumbs, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }

                $is_portal = function_exists('kit_using_employee_portal') && kit_using_employee_portal();
                $slug = '';

                if ($is_portal) {
                    $slug = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '08600-dashboard';
                } else {
                    $slug = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
                }

                if ($slug === '') {
                    return [];
                }

                $labels = [
                    '08600-dashboard' => 'Dashboard',
                    '08600-waybill-create' => 'Create Waybill',
                    '08600-waybill-manage' => 'Manage Waybills',
                    'warehouse-waybills' => 'Warehouse',
                    '08600-customers' => 'Customers',
                    '08600-add-customer' => 'Add Customer',
                    'edit-customer' => 'Edit Customer',
                    'route-management' => 'Routes & Destinations',
                    'route-create' => 'Create Route',
                    '08600-countries' => 'Countries',
                    'kit-deliveries' => 'Deliveries',
                    'manage-drivers' => 'Drivers',
                    '08600-Waybill-view' => 'Waybill View',
                    'view-deliveries' => 'Delivery Details',
                    '08600-settings' => 'Settings',
                    '08600-help' => 'Help',
                ];

                $parents = [
                    '08600-dashboard' => null,
                    '08600-waybill-create' => '08600-waybill-manage',
                    '08600-waybill-manage' => '08600-dashboard',
                    'warehouse-waybills' => '08600-dashboard',
                    '08600-customers' => '08600-dashboard',
                    '08600-add-customer' => '08600-customers',
                    'edit-customer' => '08600-customers',
                    'route-management' => '08600-dashboard',
                    'route-create' => 'route-management',
                    '08600-countries' => '08600-dashboard',
                    'kit-deliveries' => '08600-dashboard',
                    'manage-drivers' => '08600-dashboard',
                    '08600-Waybill-view' => '08600-waybill-manage',
                    'view-deliveries' => 'kit-deliveries',
                    '08600-settings' => '08600-dashboard',
                    '08600-help' => '08600-dashboard',
                ];

                $labels = apply_filters('kit_breadcrumb_labels', $labels, $slug, $is_portal);
                $parents = apply_filters('kit_breadcrumb_parents', $parents, $slug, $is_portal);

                $chain = [$slug];
                $visited = [$slug => true];
                $cursor = $slug;

                while (isset($parents[$cursor]) && !empty($parents[$cursor])) {
                    $parent = $parents[$cursor];
                    if (isset($visited[$parent])) {
                        break;
                    }
                    $chain[] = $parent;
                    $visited[$parent] = true;
                    $cursor = $parent;
                }

                $chain = array_reverse($chain);
                $breadcrumbs = [];
                $last_index = count($chain) - 1;

                foreach ($chain as $idx => $node_slug) {
                    $name = $labels[$node_slug] ?? ucwords(str_replace(['-', '_'], ' ', $node_slug));
                    $is_last = $idx === $last_index;

                    if ($is_last && is_string($title) && trim($title) !== '') {
                        $normalized_name = strtolower(trim($name));
                        $normalized_title = strtolower(trim($title));
                        if ($normalized_name !== $normalized_title) {
                            $name = $title;
                        }
                    }

                    $item = ['name' => $name];
                    if (!$is_last) {
                        $item['slug'] = $is_portal
                            ? self::portalBreadcrumbUrl($node_slug)
                            : admin_url('admin.php?page=' . rawurlencode($node_slug));
                    }
                    $breadcrumbs[] = $item;
                }

                $action_label = self::detectBreadcrumbActionLabel($slug);
                if ($action_label !== '') {
                    $breadcrumbs[] = ['name' => $action_label];
                }

                $context = [
                    'slug' => $slug,
                    'is_portal' => $is_portal,
                    'title' => $title,
                ];

                return apply_filters('kit_header_breadcrumbs', $breadcrumbs, $context);
            }

            /**
             * Resolve the proper portal breadcrumb URL for a slug.
             *
             * @param string $slug
             * @return string
             */
            private static function portalBreadcrumbUrl($slug)
            {
                if ($slug === '08600-dashboard') {
                    return apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
                }

                if (function_exists('kit_employee_portal_url')) {
                    return kit_employee_portal_url($slug);
                }

                return admin_url('admin.php?page=' . rawurlencode($slug));
            }

            /**
             * Add a contextual action crumb for detail/edit screens.
             *
             * @param string $slug
             * @return string
             */
            private static function detectBreadcrumbActionLabel($slug)
            {
                if (isset($_GET['add'])) {
                    return $slug === 'manage-drivers' ? 'Add Driver' : 'Add New';
                }

                if (isset($_GET['edit'])) {
                    return $slug === 'manage-drivers' ? 'Edit Driver' : 'Edit';
                }

                if (isset($_GET['view']) || isset($_GET['waybill_id'])) {
                    return 'View Details';
                }

                return '';
            }


            public static function showingHeader($atts)
            {
                $atts = shortcode_atts([
                    'title'   => '',
                    'desc'    => '',
                    'content' => '', // HTML content like modals or buttons
                    'breadcrumbs' => null, // Optional array/json override
                    'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />'
                ], $atts);

                $desc = is_string($atts['desc']) ? $atts['desc'] : '';
                $breadcrumbs = self::resolveBreadcrumbs($atts['title'], $atts['breadcrumbs']);
                $gridCols = "";

                //if $atts['content'] not empty, set grid-cols-1 md:grid-cols-2
                if (is_array($atts["content"])) {
                    $gridCols = 'grid-cols-1 md:grid-cols-2';
                } elseif (is_string($atts["content"]) && !empty($atts["content"])) {
                    $gridCols = 'grid-cols-1 md:grid-cols-2';
                }

                ob_start(); ?>
        <header class="bg-white shadow mb-6 ">
            <div class="<?php echo self::container(); ?> mx-auto py-3 px-6 flex justify-between items-start gap-4">
                <div class="min-w-0">
                    <?= KIT_Commons::bossText([
                        'icon' => $atts["icon"],
                        'words' => $atts["title"],
                        'size' => '2xl',
                        'color' => 'black',
                        'classes' => '',
                        'tag' => 'h2'
                    ]) ?>

                    <?php if (!empty($breadcrumbs) && is_array($breadcrumbs)): ?>
                        <nav class="mt-2" aria-label="breadcrumb">
                            <ol class="flex flex-wrap items-center gap-1 text-xs text-gray-500">
                                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                                    <?php
                                    $is_last = $index === (count($breadcrumbs) - 1);
                                    $crumb_name = isset($crumb['name']) ? (string) $crumb['name'] : '';
                                    $crumb_link = isset($crumb['slug']) ? (string) $crumb['slug'] : '';
                                    ?>
                                    <li class="flex items-center gap-1">
                                        <?php if ($crumb_name !== ''): ?>
                                            <?php if (!$is_last && $crumb_link !== ''): ?>
                                                <a class="hover:text-gray-700 hover:underline" href="<?php echo esc_url($crumb_link); ?>">
                                                    <?php echo esc_html($crumb_name); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="<?php echo $is_last ? 'text-gray-800 font-medium' : ''; ?>">
                                                    <?php echo esc_html($crumb_name); ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if (!$is_last): ?>
                                            <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                            </svg>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </nav>
                    <?php endif; ?>
                </div>

                <div class="text-gray-600 relative overflow-hidden"><?php echo wp_kses_post($desc); ?></div>

                <?php
                if (!empty($atts['content'])) {
                    echo $atts['content']; // Accepts modal button etc.
                }
                ?>

            </div>
        </header>
    <?php
                return ob_get_clean();
            }

            // DEPRECATED: Use renderButton() instead
            public static function kitButton($atts, $content = '')
            {
                // Convert kitButton parameters to renderButton format
                $type = 'primary';
                if (isset($atts['color'])) {
                    switch ($atts['color']) {
                        case 'red':
                            $type = 'danger';
                            break;
                        case 'green':
                            $type = 'success';
                            break;
                        case 'gray':
                            $type = 'secondary';
                            break;
                        case 'yellow':
                            $type = 'warning';
                            break;
                        default:
                            $type = 'primary';
                            break;
                    }
                }

                $options = [
                    'href' => $atts['href'] ?? null,
                    'onclick' => $atts['onclick'] ?? null,
                    'disabled' => $atts['disabled'] ?? false,
                    'id' => $atts['id'] ?? null,
                    'name' => $atts['name'] ?? null,
                    'type' => $atts['type'] ?? 'button',
                    'modal' => $atts['modal'] ?? null,
                    'data-bs-toggle' => $atts['data-bs-toggle'] ?? null,
                    'data-bs-target' => $atts['data-bs-target'] ?? null,
                    'data-bs-dismiss' => $atts['data-bs-dismiss'] ?? null,
                    'data-target' => $atts['data-target'] ?? null,
                    'classes' => $atts['class'] ?? '',
                    'icon' => null,
                    'iconPosition' => 'left'
                ];

                // Convert icon names to SVG paths
                if (isset($atts['icon'])) {
                    switch ($atts['icon']) {
                        case 'plus':
                            $options['icon'] = '<path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />';
                            break;
                        case 'arrow-right':
                            $options['icon'] = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />';
                            break;
                        case 'arrow-left':
                            $options['icon'] = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" />';
                            break;
                        case 'trash':
                            $options['icon'] = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />';
                            break;
                        case 'remove':
                            $options['icon'] = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />';
                            break;
                    }
                }

                return self::renderButton($atts['text'] ?? $content, $type, 'md', $options);
            }

            /**
             * Enqueue component scripts and styles
             * Call this function at the top of any component that needs JavaScript
             */
            public static function enqueueComponentScripts($scripts = ['kitscript', 'waybill-pagination'])
            {
                // Always enqueue components.js first for utilities
                if (!wp_script_is('components', 'enqueued') && !wp_script_is('components', 'done')) {
                    wp_enqueue_script('components', COURIER_FINANCE_PLUGIN_URL . 'js/components.js', ['jquery'], '1.0', true);
                }

                // Only enqueue if not already enqueued
                foreach ($scripts as $script) {
                    if (!wp_script_is($script, 'enqueued') && !wp_script_is($script, 'done')) {
                        switch ($script) {
                            case 'kitscript':
                                wp_enqueue_script('kitscript', COURIER_FINANCE_PLUGIN_URL . 'js/kitscript.js', ['jquery', 'components'], '1.0', true);
                                break;
                            case 'waybill-pagination':
                                wp_enqueue_script('waybill-pagination', COURIER_FINANCE_PLUGIN_URL . 'js/waybill-pagination.js', ['jquery', 'components'], '1.0', true);
                                break;
                        }
                    }
                }

                // Localize scripts if not already done
                if (!wp_script_is('kitscript', 'done') && !wp_script_is('kitscript', 'enqueued')) {
                    // Preload country->cities map for instant dropdown updates
                    if (!class_exists('KIT_Deliveries')) {
                        require_once COURIER_FINANCE_PLUGIN_PATH . 'includes/deliveries/deliveries-functions.php';
                    }
                    $country_cities_map = method_exists('KIT_Deliveries', 'getCountryCitiesMap') ? KIT_Deliveries::getCountryCitiesMap() : [];

                    $localize_data = [
                        'ajax_url' => admin_url('admin-ajax.php'),
                        'admin_url' => admin_url(),
                        'countryCities' => $country_cities_map,
                        'nonces' => [
                            'add'    => wp_create_nonce('add_waybill_nonce'),
                            'delete' => wp_create_nonce('delete_waybill_nonce'),
                            'update' => wp_create_nonce('update_waybill_nonce'),
                            'get_waybills_nonce' => wp_create_nonce('get_waybills_nonce'),
                            'get_cities_nonce'   => wp_create_nonce('get_cities_nonce'),
                            'kit_waybill_nonce'  => wp_create_nonce('kit_waybill_nonce'),
                            'pdf_nonce'          => wp_create_nonce('pdf_nonce'),
                            'wp_debug'           => defined('WP_DEBUG') && WP_DEBUG,
                        ],
                    ];
                    wp_localize_script('kitscript', 'myPluginAjax', $localize_data);
                }
            }

            // Future: modal() method
            /**
             * @deprecated Use dynamicItemsControl instead
             */
            public static function waybillItemsControl($options = [])
            {
                // Convert waybill options to dynamic options
                $dynamicOptions = wp_parse_args($options, [
                    'item_type' => 'waybill',
                    'title' => 'Parcels',
                    'field_mapping' => [
                        'description' => 'item_name',
                        'quantity' => 'quantity',
                        'unit_price' => 'unit_price',
                        'subtotal' => 'subtotal'
                    ]
                ]);

                return self::dynamicItemsControl($dynamicOptions);
            }
            public static function dynamicItemsControl($options = [])
            {
                $defaults = [
                    'container_id' => 'dynamic-items-container',
                    'button_id' => 'add-item-btn',
                    'group_name' => 'items',
                    'existing_items' => [],
                    'input_class' => self::inputClass(),
                    'item_type' => 'waybill', // 'waybill' or 'misc'
                    'title' => 'Items',
                    'show_subtotal' => true,
                    'subtotal_id' => 'items-subtotal',
                    'currency_symbol' => 'R',
                    'export_href' => '',
                    'show_invoices' => false, // New option to show invoices column
                    'waybill_no' => '', // Required for invoice uploads when show_invoices is true
                    'field_mapping' => [
                        'description' => 'item_name', // or 'misc_item'
                        'quantity' => 'quantity', // or 'misc_quantity'
                        'unit_price' => 'unit_price', // or 'misc_price'
                        'subtotal' => 'subtotal',
                        'invoice_file' => 'invoice_file' // New field for invoice uploads
                    ]
                ];

                $options = wp_parse_args($options, $defaults);

                // Normalize existing_items to ensure foreach/count safety
                if (!isset($options['existing_items']) || !is_array($options['existing_items'])) {
                    $options['existing_items'] = [];
                }

                // Set field mapping based on item type
                if ($options['item_type'] === 'misc') {
                    $options['field_mapping'] = [
                        'description' => 'misc_item',
                        'quantity' => 'misc_quantity',
                        'unit_price' => 'misc_price',
                        'subtotal' => 'misc_subtotal',
                        'invoice_file' => 'invoice_file'
                    ];
                } elseif ($options['item_type'] === 'waybill') {
                    $options['field_mapping'] = [
                        'description' => 'item_name',
                        'quantity' => 'quantity',
                        'unit_price' => 'unit_price',
                        'subtotal' => 'subtotal',
                        'invoice_file' => 'invoice_file'
                    ];
                }

                ob_start(); ?>
        <!-- UNIFIED TABLE VERSION -->
        <div class="mb-6" id="step-<?php echo esc_attr($options['item_type']); ?>-items">
            <!-- Header -->
            <div class="flex items-center justify-between mb-4">
                <?= KIT_Commons::prettyHeading([
                    'icon' => '<path d="M16 7a4 4 0 1 0-8 0v2a4 4 0 0 0 8 0V7z" /><path d="M12 19v-2m0 0a7 7 0 0 1-7-7V7a7 7 0 0 1 14 0v3a7 7 0 0 1-7 7z" />',
                    'words' => $options['title'],
                    'classes' => 'm-0'
                ]) ?>
                <?php echo self::renderButton('+', 'primary', 'sm', [
                    'id' => $options['button_id'],
                    'type' => 'button',
                    'icon' => '',
                    'iconPosition' => 'left',
                    'gradient' => true
                ]); ?>
                <?php if (!empty($options['export_href'])) {
                    echo self::renderButton('Export', 'secondary', 'sm', [
                        'href' => $options['export_href'],
                        'gradient' => false,
                        'classes' => 'ml-2 px-4 py-2  bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-100'
                    ]);
                } ?>
            </div>

            <style>
                .dynamicItemsTable {
                    border-collapse: separate;
                    border-spacing: 0;
                    border: 1px solid #e2e8f0;
                    border-radius: 0.75rem;
                    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
                    width: 100%;
                    max-width: 100%;
                    table-layout: auto;
                    background-color: white;
                    box-sizing: border-box;
                }

                .dynamicItemsTable th,
                .dynamicItemsTable td {
                    padding: 0.5rem 0.375rem;
                    vertical-align: middle;
                    box-sizing: border-box;
                }

                .th-1,
                .th-2,
                .th-3,
                .th-4,
                .th-5 {
                    text-align: left;
                    font-size: 0.8125rem;
                    font-weight: 600;
                    color: #374151;
                    text-transform: uppercase;
                    letter-spacing: 0.025em;
                    border-bottom: 2px solid #e5e7eb;
                    background-color: #f9fafb;
                    padding: 0.5rem 0.375rem;
                }

                .th-1,
                .td-1 {
                    width: 40%;
                }

                .th-2,
                .td-2 {
                    width: 12%;
                }

                .th-3,
                .td-3 {
                    width: 20%;
                }

                .th-4,
                .td-4 {
                    width: 18%;
                }

                .th-5,
                .td-5 {
                    width: 10%;
                }

                .td-1 {
                    white-space: normal;
                    word-wrap: break-word;
                }

                .td-1,
                .td-2,
                .td-3,
                .td-4,
                .td-5 {
                    padding: 0.5rem 0.375rem;
                    text-align: left;
                    font-size: 0.8125rem;
                    font-weight: 400;
                    color: #1f2937;
                    border-bottom: 1px solid #f3f4f6;
                }

                .dynamic-item-invoice {
                    background-color: #fafafa;
                }

                .dynamic-item-invoice:hover {
                    background-color: #f3f4f6;
                }

                .dynamic-item-invoice td {
                    padding: 0.375rem 0.375rem;
                }

                .th-2,
                .td-2,
                .th-3,
                .td-3,
                .th-4,
                .td-4 {
                    text-align: right;
                }

                .td-2 input,
                .td-3 input {
                    text-align: right;
                }

                .td-1 input,
                .td-2 input,
                .td-3 input {
                    font-size: 0.8125rem;
                    padding: 0.375rem 0.375rem;
                    min-height: 2rem;
                    width: 100%;
                    box-sizing: border-box;
                }

                .invoice-upload {
                    font-size: 0.8125rem;
                    padding: 0.375rem 0.375rem;
                    width: 100%;
                    box-sizing: border-box;
                }

                tbody tr.dynamic-item {
                    transition: background-color 0.15s ease;
                }

                tbody tr.dynamic-item:hover {
                    background-color: #f9fafb;
                }

                tbody tr.dynamic-item:nth-child(even) {
                    background-color: #fafafa;
                }

                tbody tr.dynamic-item:nth-child(even):hover {
                    background-color: #f3f4f6;
                }

                .empty-state {
                    display: table-row;
                }

                .empty-state td[colspan] {
                    display: table-cell;
                    width: 100% !important;
                    padding: 2rem 1rem !important;
                    box-sizing: border-box;
                    position: relative;
                    text-align: center;
                }

                /* Force the colspan cell to span the full table width */
                .dynamicItemsTable tbody .empty-state td[colspan] {
                    width: 100% !important;
                }
            </style>

            <!-- Slim Table -->
            <div class="mt-6 bg-white border border-gray-200 overflow-x-auto" style="max-width: 100%; box-sizing: border-box;">
                <table class="dynamicItemsTable w-full border-collapse">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="th-1">Description</th>
                            <th class="th-2">QTY</th>
                            <th class="th-3" style="text-align: right;">Unit Price</th>
                            <th class="th-4" style="text-align: right;">Total</th>
                            <th class="th-5">Action</th>
                        </tr>
                    </thead>
                    <tbody id="<?php echo esc_attr($options['container_id']); ?>" class="bg-white">

                        <?php foreach ($options['existing_items'] as $index => $item): ?>
                            <tr class="dynamic-item hover:bg-gray-50 border-b border-gray-100">
                                <td class="td-1">
                                    <input type="text"
                                        name="<?php echo esc_attr($options['group_name']); ?>[<?php echo esc_attr($index); ?>][<?php echo esc_attr($options['field_mapping']['description']); ?>]"
                                        value="<?php echo esc_attr($item[$options['field_mapping']['description']] ?? ''); ?>"
                                        placeholder="Item description"
                                        class="w-full border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500" />
                                </td>
                                <td class="td-2">
                                    <input type="number"
                                        name="<?php echo esc_attr($options['group_name']); ?>[<?php echo esc_attr($index); ?>][<?php echo esc_attr($options['field_mapping']['quantity']); ?>]"
                                        value="<?php echo esc_attr($item[$options['field_mapping']['quantity']] ?? 1); ?>"
                                        min="1"
                                        class="w-full border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-center" />
                                </td>
                                <td class="td-3">
                                    <input type="number"
                                        name="<?php echo esc_attr($options['group_name']); ?>[<?php echo esc_attr($index); ?>][<?php echo esc_attr($options['field_mapping']['unit_price']); ?>]"
                                        value="<?php echo esc_attr($item[$options['field_mapping']['unit_price']] ?? 0); ?>"
                                        step="0.01"
                                        min="0"
                                        class="w-full border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500" />
                                </td>
                                <td class="td-4 text-sm text-gray-900 text-right">
                                    <?php echo esc_html($options['currency_symbol']); ?> <?php echo number_format(($item[$options['field_mapping']['quantity']] ?? 1) * ($item[$options['field_mapping']['unit_price']] ?? 0), 2); ?>
                                </td>
                                <td class="td-5 text-center">
                                    <?php echo self::renderButton('', 'danger', 'sm', [
                                        'type' => 'button',
                                        'classes' => 'remove-item inline-flex items-center justify-center w-8 h-8  border border-red-300 text-red-600 hover:bg-red-50',
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>',
                                        'gradient' => false
                                    ]); ?>
                                </td>
                            </tr>
                            <?php if ($options['show_invoices']): ?>
                                <tr class="dynamic-item-invoice border-b border-gray-100">
                                    <td colspan="5" class="td-1">
                                        <input type="file"
                                            name="invoice[]"
                                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                            class="invoice-upload w-full border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <!-- Empty State for Slim Version -->
                        <?php if (empty($options['existing_items'])): ?>
                            <?php
                            // Count the actual number of columns in the header
                            $columnCount = 5; // Description, QTY, Unit Price, Total, Action
                            ?>
                            <tr id="empty-state-slim" class="empty-state">
                                <td colspan="<?php echo $columnCount; ?>" class="text-center py-8 text-gray-500">
                                    <p class="text-sm">No items added yet. Click "Add Item" to start.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($options['show_subtotal']): ?>
                        <tfoot>
                            <tr class="bg-gray-50">
                                <td class="px-2 py-2" colspan="3" style="border-top: 1px solid #e5e7eb; text-align:right; font-weight:600; color:#374151;">Subtotal</td>
                                <td class="px-2 py-2 text-right" style="border-top: 1px solid #e5e7eb; font-weight:600; color:#111827;" id="<?php echo esc_attr($options['subtotal_id']); ?>"><?php echo esc_html($options['currency_symbol']); ?> 0.00</td>
                                <td style="border-top: 1px solid #e5e7eb;"></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                let itemIndex = <?php echo is_array($options['existing_items']) ? count($options['existing_items']) : 0; ?>;
                const container = document.getElementById('<?php echo esc_js($options['container_id']); ?>');
                const addBtn = document.getElementById('<?php echo esc_js($options['button_id']); ?>');
                const subtotalId = '<?php echo esc_js($options['subtotal_id']); ?>';
                const currencySymbol = '<?php echo esc_js($options['currency_symbol']); ?>';
                const groupName = '<?php echo esc_js($options['group_name']); ?>';
                const fieldMapping = <?php echo json_encode($options['field_mapping']); ?>;

                function toNumber(value) {
                    const num = parseFloat(String(value).replace(/,/g, '.'));
                    return isNaN(num) ? 0 : num;
                }

                function calculateSubtotal() {
                    let total = 0;
                    container.querySelectorAll('.dynamic-item').forEach((row) => {
                        const priceInput = row.querySelector(`input[name*="[${fieldMapping.unit_price}]"]`);
                        const qtyInput = row.querySelector(`input[name*="[${fieldMapping.quantity}]"]`);
                        const price = toNumber(priceInput ? priceInput.value : 0);
                        const qty = parseInt(qtyInput ? qtyInput.value : 0, 10) || 0;
                        const rowTotal = price * qty;
                        total += rowTotal;

                        // Update individual row total display
                        const totalCell = row.querySelector('td:nth-child(4)');
                        if (totalCell) {
                            totalCell.textContent = currencySymbol + ' ' + rowTotal.toFixed(2);
                        }
                    });

                    // Update subtotal display
                    const subtotalElement = document.getElementById(subtotalId);
                    if (subtotalElement) {
                        const currentText = subtotalElement.textContent;
                        const currencySymbolMatch = currentText.match(/^[^\d]*/);
                        const existingCurrency = currencySymbolMatch ? currencySymbolMatch[0] : currencySymbol + ' ';
                        subtotalElement.textContent = existingCurrency + total.toFixed(2);
                    }
                }

                function updateEmptyState() {
                    const emptyStateSlim = document.getElementById('empty-state-slim');
                    const hasItems = container.querySelectorAll('.dynamic-item').length > 0;

                    if (emptyStateSlim) {
                        emptyStateSlim.style.display = hasItems ? 'none' : 'table-row';
                    }
                }

                // Define invoice column settings (needed globally)
                const invoiceColumn = <?php echo $options['show_invoices'] ? 'true' : 'false'; ?>;


                function rowTemplate(idx) {
                    let invoiceRow = '';

                    if (invoiceColumn) {
                        invoiceRow = `
                                    <tr class="dynamic-item-invoice border-b border-gray-100">
                                        <td colspan="5" class="td-1">
                                            <input type="file" 
                                                name="invoice[]"
                                                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                                class="invoice-upload w-full border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                        </td>
                                    </tr>`;
                    }

                    return `
                                    <tr class="dynamic-item hover:bg-gray-50 border-b border-gray-100">
                                        <td class="td-1">
                                            <input type="text" 
                                                name="${groupName}[${idx}][${fieldMapping.description}]" 
                                                placeholder="Item description" 
                                                class="w-full border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500" />
                                        </td>
                                        <td class="td-2">
                                            <input type="number" 
                                                name="${groupName}[${idx}][${fieldMapping.quantity}]" 
                                                value="1" 
                                                min="1"
                                                class="w-full border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-center" />
                                        </td>
                                        <td class="td-3">
                                            <input type="number" 
                                                name="${groupName}[${idx}][${fieldMapping.unit_price}]" 
                                                value="0" 
                                                step="0.01"
                                                min="0"
                                                class="w-full border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500" />
                                        </td>
                                        <td class="td-4 text-sm text-gray-900 text-right">
                                            ${currencySymbol} 0.00
                                        </td>
                                        <td class="td-5 text-center">
                                            <button type="button" class="remove-item inline-flex items-center justify-center w-8 h-8  border border-red-300 text-red-600 hover:bg-red-50">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                    ${invoiceRow}`;
                }

                addBtn.addEventListener('click', function() {
                    // Hide empty state immediately
                    const emptyStateSlim = document.getElementById('empty-state-slim');
                    if (emptyStateSlim && emptyStateSlim.parentNode) {
                        emptyStateSlim.parentNode.removeChild(emptyStateSlim);
                    }

                    const newRow = rowTemplate(itemIndex++);
                    container.insertAdjacentHTML('beforeend', newRow);
                    updateEmptyState();
                    calculateSubtotal();
                });

                // Event delegation for remove buttons
                document.addEventListener('click', (e) => {
                    const removeButton = e.target.closest('.remove-item');
                    if (removeButton) {
                        e.preventDefault();
                        e.stopPropagation();

                        const itemRow = removeButton.closest('tr.dynamic-item');
                        if (!itemRow) {
                            return;
                        }

                        // Also remove the invoice row if it exists (next sibling)
                        const invoiceRow = itemRow.nextElementSibling;
                        if (invoiceRow && invoiceRow.classList.contains('dynamic-item-invoice')) {
                            invoiceRow.remove();
                        }
                        itemRow.remove();
                        updateEmptyState();
                        calculateSubtotal();
                    }
                });

                // Recalculate when user edits price or quantity
                container.addEventListener('input', (e) => {
                    if (
                        e.target.matches(`input[name*="[${fieldMapping.unit_price}]"]`) ||
                        e.target.matches(`input[name*="[${fieldMapping.quantity}]"]`)
                    ) {
                        calculateSubtotal();
                    }
                });

                // Initial calculation on load
                calculateSubtotal();
                updateEmptyState();

                // Recalculate empty state width on window resize (for responsive grid)
                let resizeTimeout;
                window.addEventListener('resize', function() {
                    clearTimeout(resizeTimeout);
                    resizeTimeout = setTimeout(function() {
                        updateEmptyState();
                    }, 100);
                });

            });
        </script>
<?php
                return ob_get_clean();
            }
            /**
             * @deprecated Use dynamicItemsControl instead
             */
            public static function miscItemsControl($options = [])
            {
                // Convert misc options to dynamic options
                $dynamicOptions = wp_parse_args($options, [
                    'item_type' => 'misc',
                    'title' => 'MisceDllaneous Items',
                    'field_mapping' => [
                        'description' => 'misc_item',
                        'quantity' => 'misc_quantity',
                        'unit_price' => 'misc_price',
                        'subtotal' => 'misc_subtotal'
                    ]
                ]);

                return self::dynamicItemsControl($dynamicOptions);
            }


            /**
             * Create waybill directory structure for invoice uploads
             * 
             * @param string $waybill_no The waybill number
             * @return string|false The path to the waybill invoices directory, or false on failure
             */
            public static function createWaybillInvoiceDirectory($waybill_no)
            {
                // CARDINAL RULE: Only create folder when WAYBILL_NO has been generated
                // Reject any temp or invalid waybill numbers
                if (empty($waybill_no) || $waybill_no === 'undefined' || strpos($waybill_no, 'TEMP-') === 0) {
                    error_log('createWaybillInvoiceDirectory - REJECTED: Invalid waybill number (' . $waybill_no . ')');
                    return false; // Don't create directory for temp waybills
                }

                error_log('createWaybillInvoiceDirectory - Creating directory for waybill: ' . $waybill_no);

                // Sanitize waybill number for directory name
                $sanitized_waybill = sanitize_file_name($waybill_no);
                $waybill_dir_name = 'wb' . $sanitized_waybill;

                // Define base upload directory
                $upload_dir = wp_upload_dir();
                $base_dir = $upload_dir['basedir'] . '/waybills';


                // Create directories if they don't exist
                if (!file_exists($base_dir)) {
                    if (!wp_mkdir_p($base_dir)) {
                        error_log('Failed to create base directory: ' . $base_dir);
                        return false;
                    }
                    // Add .htaccess for security
                    $htaccess_file = $base_dir . '/.htaccess';
                    if (!file_put_contents($htaccess_file, "Options -Indexes\nDeny from all")) {
                        error_log('Failed to create .htaccess file: ' . $htaccess_file);
                    }
                }

                $waybill_dir = $base_dir . '/' . $waybill_dir_name;
                if (!file_exists($waybill_dir)) {
                    if (!wp_mkdir_p($waybill_dir)) {
                        error_log('Failed to create waybill directory: ' . $waybill_dir);
                        return false;
                    }
                }

                $invoice_dir = $waybill_dir . '/waybillInvoices';
                if (!file_exists($invoice_dir)) {
                    if (!wp_mkdir_p($invoice_dir)) {
                        error_log('Failed to create invoice directory: ' . $invoice_dir);
                        return false;
                    }
                }

                // Verify the directory is writable
                if (!is_writable($invoice_dir)) {
                    error_log('Invoice directory is not writable: ' . $invoice_dir);
                    return false;
                }

                return $invoice_dir;
            }

            /**
             * Manually create waybill invoice directory for existing waybills
             * This function can be called to create folders for waybills that don't have them yet
             * 
             * @param string $waybill_no The waybill number
             * @return array Result array with success status and message
             */
            public static function createMissingWaybillDirectory($waybill_no)
            {
                // Validate waybill number
                if (empty($waybill_no) || $waybill_no === 'undefined' || strpos($waybill_no, 'TEMP-') === 0) {
                    return [
                        'success' => false,
                        'message' => 'Invalid waybill number: ' . $waybill_no
                    ];
                }

                error_log('createMissingWaybillDirectory - Creating directory for existing waybill: ' . $waybill_no);

                // Try to create the directory
                $invoice_dir = self::createWaybillInvoiceDirectory($waybill_no);

                if ($invoice_dir) {
                    return [
                        'success' => true,
                        'message' => 'Directory created successfully: ' . $invoice_dir,
                        'directory' => $invoice_dir
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Failed to create directory for waybill: ' . $waybill_no
                    ];
                }
            }

            /**
             * Handle waybill item invoice upload
             * 
             * @param array $file The $_FILES array element for the uploaded file
             * @param string $waybill_no The waybill number
             * @param int $item_index The item index
             * @return array Result array with success status and file path or error message
             */
            public static function handleWaybillInvoiceUpload($file, $waybill_no, $item_index = 0, $is_temp_upload = false)
            {
                // Validate file
                if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
                    return ['success' => false, 'message' => 'No file uploaded'];
                }

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    return ['success' => false, 'message' => 'Upload error: ' . $file['error']];
                }

                // Validate file type
                $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($file_extension, $allowed_types)) {
                    return ['success' => false, 'message' => 'Invalid file type. Allowed: ' . implode(', ', $allowed_types)];
                }

                // Validate file size (max 10MB)
                $max_size = 10 * 1024 * 1024; // 10MB
                if ($file['size'] > $max_size) {
                    return ['success' => false, 'message' => 'File too large. Maximum size: 10MB'];
                }

                // Handle directory structure based on upload type
                if ($is_temp_upload) {
                    // For temporary uploads, use temp directory without waybill-specific subfolder
                    $upload_dir = wp_upload_dir();
                    $base_dir = $upload_dir['basedir'] . '/waybills';
                    $temp_dir = $base_dir . '/temp';

                    // Ensure temp directory exists
                    if (!file_exists($temp_dir)) {
                        if (!wp_mkdir_p($temp_dir)) {
                            return [
                                'success' => false,
                                'message' => 'Failed to create temp directory. Check uploads folder permissions. Base: ' . $base_dir
                            ];
                        }
                    }

                    $invoice_dir = $temp_dir;
                } else {
                    // For real waybills, create waybill-specific directory
                    $invoice_dir = self::createWaybillInvoiceDirectory($waybill_no);
                    if (!$invoice_dir) {
                        $upload_dir = wp_upload_dir();
                        $base_dir = $upload_dir['basedir'] . '/waybills';
                        return [
                            'success' => false,
                            'message' => 'Failed to create directory structure. Check uploads folder permissions. Base: ' . $base_dir
                        ];
                    }
                }

                // Generate unique filename
                $sanitized_name = sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME));
                if ($is_temp_upload) {
                    $filename = $sanitized_name . '_temp_item' . $item_index . '_' . time() . '.' . $file_extension;
                } else {
                    $filename = $sanitized_name . '_item' . $item_index . '_' . time() . '.' . $file_extension;
                }
                $file_path = $invoice_dir . '/' . $filename;

                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $file_path)) {
                    return [
                        'success' => true,
                        'file_path' => $file_path,
                        'filename' => $filename,
                        'message' => 'File uploaded successfully'
                    ];
                } else {
                    return ['success' => false, 'message' => 'Failed to move uploaded file'];
                }
            }

            /**
             * Get waybill invoice directory URL
             * 
             * @param string $waybill_no The waybill number
             * @return string The URL to the waybill invoices directory
             */
            public static function getWaybillInvoiceDirectoryUrl($waybill_no)
            {
                // Only proceed if we have a valid waybill number (not temp)
                if (empty($waybill_no) || strpos($waybill_no, 'TEMP-') === 0) {
                    return '';
                }

                $sanitized_waybill = sanitize_file_name($waybill_no);
                $waybill_dir_name = 'wb' . $sanitized_waybill;

                $upload_dir = wp_upload_dir();
                return $upload_dir['baseurl'] . '/waybills/' . $waybill_dir_name . '/waybillInvoices/';
            }

            public static function getNameOfUser($user_id)
            {
                if (!function_exists('get_userdata')) {
                    require_once ABSPATH . 'wp-includes/pluggable.php';
                }
                $user = get_userdata($user_id);
                return $user ? $user->display_name : 'Unknown User';
            }

            /**
             * ============================================================================
             * STANDARDIZED TABLE COLUMN DEFINITIONS
             * ============================================================================
             * 
             * Use these methods to get pre-configured column definitions for KIT_Unified_Table.
             * This provides:
             * - Consistent styling across all tables
             * - Universal waybill number with status display
             * - DRY principle - define once, use everywhere
             * - Better performance - callbacks defined once
             * 
             * Usage:
             *   $columns = KIT_Commons::getColumns(['waybill_no', 'customer_name', 'destination', 'total']);
             *   echo KIT_Unified_Table::infinite($data, $columns, $options);
             * 
             * Or get a single column:
             *   $columns = [
             *       'waybill_no' => KIT_Commons::getColumn('waybill_no'),
             *       'custom_col' => ['label' => 'Custom', 'callback' => function($v) { return $v; }],
             *   ];
             */

            /**
             * Get a single standardized column definition
             * 
             * @param string $column_key The column key (e.g., 'waybill_no', 'customer_name')
             * @param array $overrides Optional overrides for the column config
             * @return array Column configuration
             */
            public static function getColumn($column_key, $overrides = [])
            {
                $definitions = self::getColumnDefinitions();
                $column = $definitions[$column_key] ?? ['label' => ucfirst(str_replace('_', ' ', $column_key))];
                return array_merge($column, $overrides);
            }

            /**
             * Get multiple standardized column definitions
             * 
             * @param array $column_keys Array of column keys or ['key' => overrides] pairs
             * @return array Columns configuration for KIT_Unified_Table
             */
            public static function getColumns($column_keys)
            {
                $columns = [];
                foreach ($column_keys as $key => $value) {
                    if (is_numeric($key)) {
                        // Simple key: ['waybill_no', 'customer_name']
                        $columns[$value] = self::getColumn($value);
                    } else {
                        // Key with overrides: ['waybill_no' => ['label' => 'WB #']]
                        $columns[$key] = self::getColumn($key, is_array($value) ? $value : []);
                    }
                }
                return $columns;
            }

            /**
             * Master column definitions registry
             * All column types with their full configuration
             */
            private static function getColumnDefinitions()
            {
                return [
                    // ========================================
                    // WAYBILL NUMBER (with status badge below)
                    // ========================================
                    'waybill_no' => [
                        'label' => 'Waybill #',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-xs align-top overflow-hidden',
                        'callback' => function ($value, $row, $rowIndex) {
                            $waybill_no = $value ?? 'N/A';
                            $row = is_object($row) ? (array) $row : $row;
                            $waybill_id = $row['waybill_id'] ?? $row['id'] ?? 0;
                            $view_page = $row['view_page'] ?? '08600-Waybill-view';
                            $view_param = $row['view_param'] ?? 'waybill_id';

                            // Build view URL
                            $view_url = admin_url('admin.php?page=' . $view_page . '&' . $view_param . '=' . $waybill_id);

                            $waybill_link = $waybill_id > 0
                                ? '<a href="' . esc_url($view_url) . '" target="_blank" rel="noopener" class="text-blue-600 hover:text-blue-800 hover:underline font-medium">#' . esc_html($waybill_no) . '</a>'
                                : '#' . esc_html($waybill_no);

                            // UNIVERSAL STATUS: Check both 'status' (primary) and 'approval' (fallback) fields
                            // Prefer 'status' field as it's more comprehensive, fallback to 'approval'
                            $raw_status = $row['status'] ?? $row['approval'] ?? 'pending';
                            $status = strtolower(trim((string)$raw_status));

                            // Normalize numeric and boolean values
                            if ($status === '1' || $status === 'true') {
                                $status = 'approved';
                            } elseif ($status === '0' || $status === 'false' || $status === '') {
                                $status = 'pending';
                            }

                            // Comprehensive status badge configuration (supports all status values)
                            $config = [
                                // Approval statuses
                                'approved' => ['bg' => '#22c55e', 'icon' => '✓', 'text' => 'Approved'],
                                'pending' => ['bg' => '#eab308', 'icon' => '⏳', 'text' => 'Pending'],
                                'rejected' => ['bg' => '#ef4444', 'icon' => '✗', 'text' => 'Rejected'],
                                'cancelled' => ['bg' => '#6b7280', 'icon' => '✗', 'text' => 'Cancelled'],
                                'completed' => ['bg' => '#3b82f6', 'icon' => '✓', 'text' => 'Completed'],
                                // Waybill workflow statuses
                                'quoted' => ['bg' => '#8b5cf6', 'icon' => '📋', 'text' => 'Quoted'],
                                'paid' => ['bg' => '#10b981', 'icon' => '💰', 'text' => 'Paid'],
                                'assigned' => ['bg' => '#06b6d4', 'icon' => '🚚', 'text' => 'Assigned'],
                                'shipped' => ['bg' => '#3b82f6', 'icon' => '📦', 'text' => 'Shipped'],
                                'delivered' => ['bg' => '#22c55e', 'icon' => '✓', 'text' => 'Delivered'],
                                'invoiced' => ['bg' => '#6366f1', 'icon' => '🧾', 'text' => 'Invoiced'],
                            ];

                            $settings = $config[$status] ?? ['bg' => '#6b7280', 'icon' => '?', 'text' => ucfirst($status)];

                            // ALWAYS show status badge below waybill number (universal requirement); w-fit so badge doesn't expand column width
                            $badge = '<span class="inline-flex w-fit items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[10px] font-medium mt-1 shrink-0" style="background-color: ' . $settings['bg'] . '; color: #fff;">'
                                . '<span>' . $settings['icon'] . '</span>'
                                . '<span>' . $settings['text'] . '</span>'
                                . '</span>';

                            // Order: waybill link → customer/company → status badge
                            $extra = [];
                            if (!empty($row['created_by'])) {
                                $user_data = function_exists('get_userdata') ? get_userdata($row['created_by']) : null;
                                if ($user_data && $user_data->display_name) {
                                    $extra[] = '<span class="text-[10px] text-gray-400 mt-0.5">' . esc_html($user_data->display_name) . '</span>';
                                }
                            }
                            // Customer/company name between waybill_no and status
                            $is_placeholder = static function ($value): bool {
                                $v = strtolower(trim((string) $value));
                                return $v === '' || in_array($v, ['0', 'null', 'n/a', 'na', 'none', '-', '--'], true);
                            };
                            $customer_name = trim((string)($row['customer_name'] ?? ''));
                            $customer_surname = trim((string)($row['customer_surname'] ?? $row['surname'] ?? ''));
                            $company = trim((string)($row['company'] ?? $row['customer_company'] ?? ''));
                            if ($is_placeholder($customer_name)) {
                                $customer_name = '';
                            }
                            if ($is_placeholder($customer_surname)) {
                                $customer_surname = '';
                            }
                            if ($is_placeholder($company)) {
                                $company = '';
                            }
                            $person_name = trim($customer_name . ' ' . $customer_surname);
                            $company_is_displayable = ($company !== '' && !in_array(strtolower($company), ['individual', '1ndividual', 'private'], true));
                            if ($company_is_displayable && $person_name !== '' && strcasecmp($person_name, $company) !== 0) {
                                $customer_display = $person_name . ' · ' . $company;
                            } elseif ($company_is_displayable) {
                                $customer_display = $company;
                            } else {
                                $customer_display = $person_name;
                            }
                            if ($customer_display !== '') {
                                $customer_id = $row['customer_id'] ?? 0;
                                if ($customer_id > 0) {
                                    $customer_display = '<a href="?page=08600-customers&view_customer=' . (int) $customer_id . '" target="_blank" rel="noopener" class="block text-blue-600 hover:text-blue-800 hover:underline text-[11px] font-medium" title="' . esc_attr($customer_display) . '" style="display:block;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html($customer_display) . '</a>';
                                } else {
                                    $customer_display = '<span class="block text-[11px] text-gray-700" title="' . esc_attr($customer_display) . '" style="display:block;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html($customer_display) . '</span>';
                                }
                                $extra[] = '<span class="block mt-0.5">' . $customer_display . '</span>';
                            }
                            $extra[] = $badge;

                            return '<div class="flex flex-col items-start min-w-0 w-full overflow-hidden" style="max-width:150px;overflow:hidden;">' . $waybill_link . implode('', $extra) . '</div>';
                        }
                    ],

                    // ========================================
                    // WAYBILL NUMBER (simple, no status)
                    // ========================================
                    'waybill_no_simple' => [
                        'label' => 'Waybill #',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-sm text-blue-700 whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            $row = is_object($row) ? (array) $row : $row;
                            $waybill_id = $row['waybill_id'] ?? $row['id'] ?? 0;
                            $waybill_no = $value ?? '';

                            if (empty($waybill_no)) {
                                return '—';
                            }

                            $view_url = add_query_arg([
                                'page' => '08600-Waybill-view',
                                'waybill_id' => $waybill_id,
                            ], admin_url('admin.php'));

                            return '<a href="' . esc_url($view_url) . '" target="_blank" rel="noopener" class="text-blue-600 hover:text-blue-800 hover:underline font-medium">#' . esc_html($waybill_no) . '</a>';
                        }
                    ],

                    // ========================================
                    // CUSTOMER NAME (with link)
                    // ========================================
                    'customer_name' => [
                        'label' => 'Customer',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            $row = is_object($row) ? (array) $row : $row;
                            $customer_id = $row['customer_id'] ?? 0;
                            $name = $value ?? '';
                            $surname = $row['customer_surname'] ?? $row['surname'] ?? '';
                            $full_name = trim($name . ' ' . $surname);

                            if (empty($full_name)) {
                                return '—';
                            }

                            if ($customer_id > 0) {
                                return '<a href="?page=08600-customers&view_customer=' . $customer_id . '" target="_blank" rel="noopener" class="text-blue-600 hover:text-blue-800 hover:underline font-medium">' . esc_html($full_name) . '</a>';
                            }
                            return esc_html($full_name);
                        }
                    ],

                    // ========================================
                    // CUSTOMER NAME (plain text)
                    // ========================================
                    'customer_name_plain' => [
                        'label' => 'Customer',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-sm whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            $row = is_object($row) ? (array) $row : $row;
                            $name = $row['customer_name'] ?? $row['name'] ?? '';
                            $surname = $row['customer_surname'] ?? $row['surname'] ?? '';
                            return esc_html(trim($name . ' ' . $surname) ?: '—');
                        }
                    ],

                    // ========================================
                    // WAYBILL DESCRIPTION
                    // ========================================
                    'description' => [
                        'label' => 'Description',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left min-w-[420px]',
                        'cell_class' => 'text-left text-sm min-w-[420px] max-w-[640px] whitespace-normal break-words',
                        'callback' => function ($value, $row, $rowIndex) {
                            $row = is_object($row) ? (array) $row : $row;
                            $desc = $row['description'] ?? $value ?? '';
                            return esc_html($desc ?: '—');
                        }
                    ],

                    // ========================================
                    // DESTINATION / CITY
                    // ========================================
                    'destination' => [
                        'label' => 'Destination',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-xs align-middle',
                        'callback' => function ($value, $row, $rowIndex) {
                            $row = is_object($row) ? (array) $row : $row;
                            $city_name = $row['city'] ?? '';

                            if (empty($city_name) && !empty($value)) {
                                if (strpos($value, ',') !== false) {
                                    list($country, $city) = array_map('trim', explode(',', $value, 2));
                                    $city_name = $city;
                                } else {
                                    $city_name = $value;
                                }
                            }

                            $delivery_reference = $row['delivery_reference'] ?? '';
                            $delivery_id = $row['delivery_id'] ?? 0;

                            $html = '<div class="flex flex-col">';
                            $html .= '<span class="font-medium text-gray-900">' . esc_html($city_name ?: '—') . '</span>';

                            if (!empty($delivery_reference) && $delivery_reference !== 'pending') {
                                $html .= '<span class="text-[10px] text-gray-500">Ref: ' . esc_html($delivery_reference) . '</span>';
                            }

                            $html .= '</div>';
                            return $html;
                        }
                    ],

                    // ========================================
                    // CITY (simple)
                    // ========================================
                    'city' => [
                        'label' => 'City',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-sm whitespace-nowrap',
                    ],

                    // ========================================
                    // MASS AND DIMENSIONS
                    // ========================================
                    'mass_and_dimensions' => [
                        'label' => 'Mass & Dims',
                        'sortable' => true,
                        'searchable' => false,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-xs',
                        'callback' => function ($value, $row, $rowIndex) {
                            $row = is_object($row) ? (array) $row : $row;
                            $mass = $row['total_mass_kg'] ?? 0;
                            $length = $row['item_length'] ?? 0;
                            $width = $row['item_width'] ?? 0;
                            $height = $row['item_height'] ?? 0;
                            $volume = $row['total_volume'] ?? 0;

                            $mass_display = ($mass > 0) ? number_format($mass, 1) . ' kg' : '0 kg';
                            $dimensions_display = ($length > 0 && $width > 0 && $height > 0)
                                ? number_format($length, 0) . ' × ' . number_format($width, 0) . ' × ' . number_format($height, 0)
                                : '0 × 0 × 0';
                            $volume_display = ($volume > 0) ? number_format($volume, 3) . ' m³' : '0 m³';

                            return '<div class="text-xs text-gray-500">' .
                                esc_html($mass_display) . '<br>' .
                                esc_html($dimensions_display) . '<br>' .
                                esc_html($volume_display) . '</div>';
                        }
                    ],

                    // ========================================
                    // TOTAL / AMOUNT (currency)
                    // ========================================
                    'total' => [
                        'label' => 'Total',
                        'sortable' => true,
                        'searchable' => false,
                        'header_class' => 'text-right whitespace-nowrap',
                        'cell_class' => 'text-right text-sm font-medium whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            if (class_exists('KIT_User_Roles') && !KIT_User_Roles::can_see_prices()) {
                                return '***';
                            }
                            $amount = is_numeric($value) ? floatval($value) : 0;
                            return 'R ' . number_format($amount, 2);
                        }
                    ],

                    // ========================================
                    // STATUS (generic)
                    // ========================================
                    'status' => [
                        'label' => 'Status',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            $status = strtolower(trim($value ?? 'pending'));
                            $config = [
                                'approved' => ['bg' => '#22c55e', 'text' => 'Approved'],
                                'pending' => ['bg' => '#eab308', 'text' => 'Pending'],
                                'rejected' => ['bg' => '#ef4444', 'text' => 'Rejected'],
                                'completed' => ['bg' => '#3b82f6', 'text' => 'Completed'],
                                'delivered' => ['bg' => '#3b82f6', 'text' => 'Delivered'],
                                'in_transit' => ['bg' => '#8b5cf6', 'text' => 'In Transit'],
                                'cancelled' => ['bg' => '#6b7280', 'text' => 'Cancelled']
                            ];

                            $settings = $config[$status] ?? ['bg' => '#6b7280', 'text' => ucfirst($status)];

                            return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium" style="background-color: ' . $settings['bg'] . '; color: #fff;">'
                                . $settings['text'] . '</span>';
                        }
                    ],

                    // ========================================
                    // APPROVAL STATUS
                    // ========================================
                    'approval' => [
                        'label' => 'Approval',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            $raw = $value ?? 'pending';
                            $status = strtolower(trim((string)$raw));

                            if ($status === '1' || $status === 'true') {
                                $status = 'approved';
                            } elseif ($status === '0' || $status === 'false' || $status === '') {
                                $status = 'pending';
                            }

                            $config = [
                                'approved' => ['bg' => '#22c55e', 'icon' => '✓', 'text' => 'Approved'],
                                'pending' => ['bg' => '#eab308', 'icon' => '⏳', 'text' => 'Pending'],
                                'rejected' => ['bg' => '#ef4444', 'icon' => '✗', 'text' => 'Rejected']
                            ];

                            $settings = $config[$status] ?? ['bg' => '#6b7280', 'icon' => '?', 'text' => ucfirst($status)];

                            return '<span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[10px] font-medium" style="background-color: ' . $settings['bg'] . '; color: #fff;">'
                                . '<span>' . $settings['icon'] . '</span>'
                                . '<span>' . $settings['text'] . '</span>'
                                . '</span>';
                        }
                    ],

                    // ========================================
                    // DATE (formatted)
                    // ========================================
                    'created_at' => [
                        'label' => 'Created',
                        'sortable' => true,
                        'searchable' => false,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-xs text-gray-600 whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            if (empty($value)) return '—';
                            $timestamp = strtotime($value);
                            return $timestamp ? date('d M Y', $timestamp) : esc_html($value);
                        }
                    ],

                    // ========================================
                    // DATE TIME (formatted)
                    // ========================================
                    'updated_at' => [
                        'label' => 'Updated',
                        'sortable' => true,
                        'searchable' => false,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-xs text-gray-600 whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            if (empty($value)) return '—';
                            $timestamp = strtotime($value);
                            return $timestamp ? date('d M Y H:i', $timestamp) : esc_html($value);
                        }
                    ],

                    // ========================================
                    // EMAIL
                    // ========================================
                    'email' => [
                        'label' => 'Email',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-sm whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            if (empty($value)) return '—';
                            return '<a href="mailto:' . esc_attr($value) . '" class="text-blue-600 hover:text-blue-800 hover:underline">' . esc_html($value) . '</a>';
                        }
                    ],

                    // ========================================
                    // PHONE
                    // ========================================
                    'phone' => [
                        'label' => 'Phone',
                        'sortable' => false,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-sm whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            if (empty($value) || $value === 'N/A') return '—';
                            return '<a href="tel:' . esc_attr($value) . '" class="text-blue-600 hover:text-blue-800 hover:underline">' . esc_html($value) . '</a>';
                        }
                    ],

                    // ========================================
                    // COMPANY NAME
                    // ========================================
                    'company_name' => [
                        'label' => 'Company',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap w-52 max-w-52',
                        'cell_class' => 'text-left text-xs w-52 max-w-52 truncate',
                    ],

                    // ========================================
                    // COUNTRY
                    // ========================================
                    'country_name' => [
                        'label' => 'Country',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap w-32 max-w-32',
                        'cell_class' => 'text-left text-xs w-32 max-w-32',
                    ],

                    // ========================================
                    // TOTAL WAYBILLS (count)
                    // ========================================
                    'total_waybills' => [
                        'label' => 'Waybills',
                        'sortable' => true,
                        'searchable' => false,
                        'header_class' => 'text-center whitespace-nowrap w-24 max-w-24',
                        'cell_class' => 'text-center text-sm font-medium w-24 max-w-24',
                        'callback' => function ($value, $row, $rowIndex) {
                            $count = intval($value);
                            $class = $count > 0 ? 'text-blue-600' : 'text-gray-400';
                            return '<span class="' . $class . '">' . $count . '</span>';
                        }
                    ],

                    // ========================================
                    // TRUCK DETAILS
                    // ========================================
                    'truck_details' => [
                        'label' => 'Truck',
                        'sortable' => false,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-xs text-gray-600',
                        'callback' => function ($value, $row, $rowIndex) {
                            $row = is_object($row) ? (array) $row : $row;
                            $truck_number = $row['truck_number'] ?? '';
                            $delivery_reference = $row['delivery_reference'] ?? '';
                            $dispatch_date = $row['dispatch_date'] ?? '';

                            if (empty($truck_number) && empty($delivery_reference)) {
                                return '—';
                            }

                            $html = '<div class="flex flex-col">';
                            if (!empty($truck_number)) {
                                $html .= '<span class="font-medium">' . esc_html($truck_number) . '</span>';
                            }
                            if (!empty($delivery_reference) && $delivery_reference !== 'pending') {
                                $html .= '<span class="text-[10px] text-gray-500">' . esc_html($delivery_reference) . '</span>';
                            }
                            if (!empty($dispatch_date) && $dispatch_date !== '0000-00-00') {
                                $formatted_date = date('d M Y', strtotime($dispatch_date));
                                $html .= '<span class="text-[10px] text-gray-400">' . esc_html($formatted_date) . '</span>';
                            }
                            $html .= '</div>';
                            return $html;
                        }
                    ],

                    // ========================================
                    // DRIVER NAME
                    // ========================================
                    'driver_name' => [
                        'label' => 'Driver',
                        'sortable' => true,
                        'searchable' => true,
                        'header_class' => 'text-left whitespace-nowrap',
                        'cell_class' => 'text-left text-sm whitespace-nowrap',
                        'callback' => function ($value, $row, $rowIndex) {
                            $row = is_object($row) ? (array) $row : $row;
                            $name = $row['name'] ?? $row['driver_name'] ?? $value ?? '';
                            $surname = $row['surname'] ?? '';
                            return esc_html(trim($name . ' ' . $surname) ?: '—');
                        }
                    ],
                ];
            }
        }
