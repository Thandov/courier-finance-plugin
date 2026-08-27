<?php
// includes/waybill/waybill-functions.php

if (! defined('ABSPATH')) {
    exit;
}

class KIT_Waybills
{
    public static function init()
    {
        // Waybill operations are staff-only. The employee and customer portals run as
        // logged-in users, so the authenticated wp_ajax_/admin_post_ hooks cover them;
        // no *_nopriv_ variant is registered for anything that reads or writes waybills.
        add_action('wp_ajax_load_waybill_page', [self::class, 'myplugin_ajax_load_waybill_page']);
        // Outcomes of the state changes below are queued for the next request and
        // printed here, instead of being signalled with query-string flags that
        // stuck in the URL and re-fired on every reload.
        add_action('admin_notices', ['KIT_Commons', 'printQueuedNotices']);
        add_action('admin_post_update_WaybillApproval', [self::class, 'update_waybillApproval']);
        add_action('admin_post_waybill_approve_and_invoice', [self::class, 'approve_and_invoice']);
        add_action('admin_post_assign_waybill_to_delivery', [self::class, 'assign_waybill_to_delivery']);
        // For JS fallback (POST without AJAX)
        add_action('admin_post_waybillQuoteStatus_update', [self::class, 'waybillQuoteStatus_update']);

        // For AJAX (with JS enabled)
        add_action('wp_ajax_waybillQuoteStatus_update', [self::class, 'waybillQuoteStatus_update']);
        add_shortcode('kit_waybill_form', [__CLASS__, 'display_waybill_form']);
        add_action('admin_post_add_waybill_action', [self::class, 'process_form']);
        add_action('wp_ajax_process_waybill_form', [self::class, 'process_form']);
        add_action('wp_ajax_get_direction_id', [self::class, 'get_direction_id_ajax']);
        add_action('wp_ajax_kit_get_customer_last_waybill_template', [self::class, 'ajax_get_customer_last_waybill_template']);
        add_action('admin_post_update_waybill_action', [self::class, 'update_waybill_action']);
        add_action('admin_post_delete_waybill', [__CLASS__, 'handle_delete_waybill']);
        add_action('wp_ajax_delete_waybill', [__CLASS__, 'handle_delete_waybill']);
        add_action('wp_ajax_kit_email_waybill_pdf', [__CLASS__, 'ajax_email_waybill_pdf']);
        add_action('wp_ajax_get_delivery_data', [__CLASS__, 'get_delivery_data']);
        add_action('admin_post_generate_quote', [self::class, 'generate_Waybill_quote']);
        add_action('admin_post_get_waybill_count', [self::class, 'get_waybill_count']);
        add_action('admin_post_get_recent_waybill_count', [self::class, 'get_recent_waybill_count']);
        add_action('admin_post_get_pending_waybill_count', [self::class, 'get_pending_waybill_count']);
        add_action('admin_post_get_latest_waybill_date', [self::class, 'get_latest_waybill_date']);
        add_action('admin_post_approve_waybill', [self::class, 'approve_waybill']);
        add_action('admin_post_update_destinationCountry', [self::class, 'update_destinationCountry']);
        add_action('admin_post_getDestinationCountry', [self::class, 'getDestinationCountry']);

        // PDF generation ajax endpoint removed – legacy pdf-generator.php handles output
    }
    // generate_pdf handler removed – use legacy pdf-generator.php file

    /**
     * Email the waybill PDF to the customer address on file (wp_mail + WordPress mail settings).
     */
    public static function ajax_email_waybill_pdf()
    {
        check_ajax_referer('email_waybill_pdf', 'nonce');

        if (! is_user_logged_in()) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        if (! class_exists('KIT_User_Roles') || ! KIT_User_Roles::can_see_prices()) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $waybill_no = isset($_POST['waybill_no']) ? (int) $_POST['waybill_no'] : 0;
        if ($waybill_no <= 0) {
            wp_send_json_error(['message' => 'Invalid waybill number.']);
        }

        $pdf_row = self::pdfVerifier($waybill_no);
        if (! is_array($pdf_row) || empty($pdf_row['soWhat'])) {
            wp_send_json_error(['message' => 'This waybill cannot be emailed until it is approved and invoiced/quoted as required.'], 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'kit_waybills';
        $email_raw = $wpdb->get_var($wpdb->prepare(
            "SELECT email_address FROM {$table} WHERE waybill_no = %d LIMIT 1",
            $waybill_no
        ));

        $to = is_string($email_raw) ? sanitize_email(trim($email_raw)) : '';
        if ($to === '' || ! is_email($to)) {
            wp_send_json_error(['message' => 'No customer email on file for this waybill.']);
        }

        if (! defined('COURIER_FINANCE_PLUGIN_PATH')) {
            wp_send_json_error(['message' => 'Plugin path is not configured.'], 500);
        }

        $pdf_helper = COURIER_FINANCE_PLUGIN_PATH . 'includes/pdf/kit-waybill-pdf-binary.php';
        if (! is_readable($pdf_helper)) {
            wp_send_json_error(['message' => 'PDF module is missing.'], 500);
        }
        require_once $pdf_helper;

        try {
            $pdf_binary = kit_waybill_pdf_generate_binary($waybill_no);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        $tmp = wp_tempnam('waybill-' . $waybill_no . '-email.pdf');
        if (! $tmp || file_put_contents($tmp, $pdf_binary) === false) {
            wp_send_json_error(['message' => 'Could not prepare the PDF attachment.'], 500);
        }

        $subject = sprintf(
            /* translators: %d: waybill number */
            __('Waybill #%d – Quotation', '08600-services-quotations'),
            $waybill_no
        );
        $body = '<p>' . esc_html(
            sprintf(
                /* translators: %d: waybill number */
                __('Please find attached the waybill / quotation for waybill number %d.', '08600-services-quotations'),
                $waybill_no
            )
        ) . '</p><p>' . esc_html(__('Thank you for your business.', '08600-services-quotations')) . '</p>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $attachments = [$tmp];
        $mail_args = apply_filters(
            'kit_waybill_pdf_email_args',
            [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
                'headers' => $headers,
                'attachments' => $attachments,
            ],
            $waybill_no
        );

        $send_to = isset($mail_args['to']) ? $mail_args['to'] : $to;
        $send_to = sanitize_email((string) $send_to);
        if ($send_to === '' || ! is_email($send_to)) {
            @unlink($tmp);
            wp_send_json_error(['message' => 'Invalid recipient after filter.']);
        }

        $send_subject = isset($mail_args['subject']) ? (string) $mail_args['subject'] : $subject;
        $send_body = isset($mail_args['body']) ? (string) $mail_args['body'] : $body;
        $send_headers = isset($mail_args['headers']) && is_array($mail_args['headers']) ? $mail_args['headers'] : $headers;
        $send_attachments = isset($mail_args['attachments']) && is_array($mail_args['attachments']) ? $mail_args['attachments'] : $attachments;

        $sent = wp_mail($send_to, $send_subject, $send_body, $send_headers, $send_attachments);
        @unlink($tmp);

        if (! $sent) {
            wp_send_json_error(['message' => __('Email could not be sent. Check WordPress mail configuration.', '08600-services-quotations')]);
        }

        wp_send_json_success(['message' => __('Email sent.', '08600-services-quotations')]);
    }

    public static function chargeGroup($country_id)
    {
        // Get country using original country origin_country_id
        $origin_country_id = isset($country_id) ? intval($country_id) : 0;
        $origin_country = null;
        if ($origin_country_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'kit_operating_countries';
            $origin_country = $wpdb->get_var(
                $wpdb->prepare("SELECT `charge_group` FROM $table WHERE id = %d", $origin_country_id)
            );
        }

        return $origin_country;
    }


    public static function get_direction_id_ajax()
    {
        // Verify nonce for security
        if (!check_ajax_referer('get_waybills_nonce', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }

        $origin_country_id = isset($_POST['origin_country_id']) ? intval($_POST['origin_country_id']) : 0;
        $destination_country_id = isset($_POST['destination_country_id']) ? intval($_POST['destination_country_id']) : 0;

        if ($origin_country_id <= 0 || $destination_country_id <= 0) {
            wp_send_json_error(['message' => 'Invalid country IDs provided.']);
        }

        // Try to get existing direction
        $direction_id = KIT_Deliveries::get_direction_id($origin_country_id, $destination_country_id);

        // If direction doesn't exist, create it
        if (!$direction_id) {
            $direction_id = KIT_Deliveries::create_direction($origin_country_id, $destination_country_id);
        }

        if ($direction_id) {
            wp_send_json_success(['direction_id' => $direction_id]);
        } else {
            wp_send_json_error(['message' => 'Could not create or find shipping direction.']);
        }
    }

    /**
     * AJAX: last waybill for a customer, shaped for Create Waybill prefill.
     * Excludes mass and parcel line items by design.
     */
    public static function ajax_get_customer_last_waybill_template()
    {
        if (
            !check_ajax_referer('get_waybills_nonce', 'nonce', false)
            && !check_ajax_referer('get_waybills_nonce', '_ajax_nonce', false)
        ) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }

        if (
            !current_user_can('kit_edit_waybills')
            && !current_user_can('kit_view_waybills')
            && !current_user_can('kit_update_data')
            && !current_user_can('manage_options')
            && !current_user_can('edit_pages')
        ) {
            wp_send_json_error(['message' => 'You do not have permission to prefill waybills.']);
        }

        $customer_id = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
        if ($customer_id <= 0) {
            wp_send_json_error(['message' => 'Invalid customer.']);
        }

        $template = self::get_customer_last_waybill_template($customer_id);
        if (empty($template)) {
            wp_send_json_error(['message' => 'No previous waybill found for this customer.']);
        }

        wp_send_json_success($template);
    }

    /**
     * Build a create-form template from a customer's most recent waybill.
     *
     * Copies route, flags, charge basis, volume dims/charges, and misc items.
     * Never includes mass fields or parcel line items.
     *
     * @param int $customer_id
     * @return array|null
     */
    public static function get_customer_last_waybill_template($customer_id)
    {
        global $wpdb;

        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return null;
        }

        $waybills_t   = $wpdb->prefix . 'kit_waybills';
        $directions_t = $wpdb->prefix . 'kit_shipping_directions';
        $deliveries_t = $wpdb->prefix . 'kit_deliveries';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    w.id,
                    w.waybill_no,
                    w.customer_id,
                    w.company_id,
                    w.direction_id,
                    w.city_id,
                    w.delivery_id,
                    w.warehouse,
                    w.charge_basis,
                    w.description,
                    w.item_length,
                    w.item_width,
                    w.item_height,
                    w.total_volume,
                    w.volume_charge,
                    w.include_sad500,
                    w.include_sadc,
                    w.vat_include,
                    w.miscellaneous,
                    sd.origin_country_id,
                    sd.destination_country_id,
                    d.delivery_reference,
                    d.status AS delivery_status,
                    d.dispatch_date
                 FROM {$waybills_t} w
                 LEFT JOIN {$directions_t} sd ON sd.id = w.direction_id
                 LEFT JOIN {$deliveries_t} d ON d.id = w.delivery_id
                 WHERE w.customer_id = %d
                 ORDER BY w.created_at DESC, w.id DESC
                 LIMIT 1",
                $customer_id
            ),
            ARRAY_A
        );

        if (empty($row)) {
            return null;
        }

        $misc = [];
        if (!empty($row['miscellaneous'])) {
            $decoded = maybe_unserialize($row['miscellaneous']);
            if (is_array($decoded)) {
                $misc = $decoded;
            } else {
                $json = json_decode((string) $row['miscellaneous'], true);
                if (is_array($json)) {
                    $misc = $json;
                }
            }
        }
        $others = (isset($misc['others']) && is_array($misc['others'])) ? $misc['others'] : [];

        $origin_country_id = (int) ($others['origin_country_id'] ?? ($row['origin_country_id'] ?? 0));
        $origin_city_id    = (int) ($others['origin_city_id'] ?? 0);
        $dest_city_id      = (int) ($row['city_id'] ?? ($others['destination_city_id'] ?? 0));
        $dest_country_id   = (int) ($others['destination_country_id'] ?? ($row['destination_country_id'] ?? 0));

        // Prefer the city's actual country so the destination city select can resolve.
        // (Some legacy rows have direction.destination_country_id out of sync with city_id.)
        if ($dest_city_id > 0) {
            $cities_t = $wpdb->prefix . 'kit_operating_cities';
            $city_country_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT country_id FROM {$cities_t} WHERE id = %d LIMIT 1",
                    $dest_city_id
                )
            );
            if ($city_country_id > 0) {
                $dest_country_id = $city_country_id;
            }
        }

        $warehouse = ((int) ($row['warehouse'] ?? 0) === 1);
        $delivery_id = (int) ($row['delivery_id'] ?? 0);
        $delivery_ref = strtolower(trim((string) ($row['delivery_reference'] ?? '')));
        $delivery_status = strtolower(trim((string) ($row['delivery_status'] ?? '')));

        // Warehouse / pending sentinel deliveries are not selectable trucks.
        $usable_delivery = false;
        if (
            !$warehouse
            && $delivery_id > 0
            && $delivery_ref !== ''
            && $delivery_ref !== 'pending'
            && in_array($delivery_status, ['scheduled', 'in_transit', 'loading'], true)
        ) {
            $usable_delivery = true;
        } else {
            $delivery_id = 0;
            if ($delivery_ref === 'pending' || $warehouse) {
                $warehouse = true;
            }
        }

        $misc_items = [];
        if (!empty($misc['misc_items']) && is_array($misc['misc_items'])) {
            foreach ($misc['misc_items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = trim((string) ($item['misc_item'] ?? $item['item_name'] ?? $item['description'] ?? ''));
                $qty   = (int) ($item['misc_quantity'] ?? $item['quantity'] ?? 0);
                $price = (float) str_replace(',', '.', (string) ($item['misc_price'] ?? $item['unit_price'] ?? 0));
                if ($label === '' || $qty <= 0) {
                    continue;
                }
                $misc_items[] = [
                    'misc_item'     => sanitize_text_field($label),
                    'misc_quantity' => $qty,
                    'misc_price'    => round($price, 2),
                ];
            }
        }

        $charge_basis = strtolower(trim((string) ($row['charge_basis'] ?? '')));
        if (!in_array($charge_basis, ['auto', 'mass', 'volume', 'weight'], true)) {
            $charge_basis = 'auto';
        }
        if ($charge_basis === 'weight') {
            $charge_basis = 'mass';
        }

        $description = trim((string) ($row['description'] ?? ''));
        if ($description === '' && !empty($others['waybill_description'])) {
            $description = trim((string) $others['waybill_description']);
        }

        $total_volume  = (float) ($row['total_volume'] ?? 0);
        $volume_charge = (float) ($row['volume_charge'] ?? 0);
        $item_length   = (float) ($row['item_length'] ?? 0);
        $item_width    = (float) ($row['item_width'] ?? 0);
        $item_height   = (float) ($row['item_height'] ?? 0);

        $use_custom_volume_rate = !empty($others['use_custom_volume_rate']);
        $custom_volume_rate = isset($others['custom_volume_rate_per_m3'])
            ? (float) $others['custom_volume_rate_per_m3']
            : 0.0;
        $volume_rate_used = isset($others['volume_rate_used'])
            ? (float) $others['volume_rate_used']
            : 0.0;
        if ($volume_rate_used <= 0 && $total_volume > 0 && $volume_charge > 0) {
            $volume_rate_used = $volume_charge / $total_volume;
        }

        return [
            'source_waybill_id' => (int) $row['id'],
            'source_waybill_no' => (string) $row['waybill_no'],
            'customer_id'        => (int) $row['customer_id'],
            'company_id'         => (int) ($row['company_id'] ?? 0),
            'description'        => sanitize_textarea_field($description),
            'warehouse'          => $warehouse,
            'delivery_id'        => $usable_delivery ? $delivery_id : 0,
            'direction_id'       => (int) ($row['direction_id'] ?? 0),
            'origin_country_id'  => $origin_country_id,
            'origin_city_id'     => $origin_city_id,
            'destination_country_id' => $dest_country_id,
            'destination_city_id'    => $dest_city_id,
            'charge_basis'       => $charge_basis,
            'include_sad500'     => ((int) ($row['include_sad500'] ?? 0) === 1),
            'include_sadc'       => ((int) ($row['include_sadc'] ?? 0) === 1),
            'vat_include'        => ((int) ($row['vat_include'] ?? 0) === 1),
            'item_length'        => $item_length,
            'item_width'         => $item_width,
            'item_height'        => $item_height,
            'total_volume'       => $total_volume,
            'volume_charge'      => $volume_charge,
            'volume_rate_used'   => round($volume_rate_used, 4),
            'use_custom_volume_rate' => (bool) $use_custom_volume_rate,
            'custom_volume_rate_per_m3' => $custom_volume_rate,
            'misc_items'         => $misc_items,
            // Explicit exclusions for consumers / debugging.
            'exclude'            => ['mass', 'parcels'],
        ];
    }

    public static function waybillQuoteStatus_update()
    {
        global $wpdb;

        // Check if user can invoice waybills
        if (!KIT_User_Roles::can_invoice()) {
            wp_die('You do not have permission to update waybill status.');
        }

        // Verify nonce for security
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'update_waybill_approval_nonce')) {
            wp_die('Security check failed.');
        }

        $waybillid = intval($_POST['waybillid'] ?? 0);
        $waybillno = sanitize_text_field($_POST['waybillno'] ?? '');
        $status    = sanitize_text_field($_POST['status'] ?? '');
        $userId    = get_current_user_id();

        $table = $wpdb->prefix . 'kit_waybills';

        $updated = $wpdb->update(
            $table,
            ['status' => $status, 'status_userid' => $userId],
            ['id' => $waybillid, 'waybill_no' => $waybillno],
            ['%s', '%d'],
            ['%d', '%s']
        );

        $status_label = KIT_Commons::invoiceStatusLabels()[$status] ?? $status;

        if ($updated !== false && $updated !== 0 && class_exists('KIT_Bulk_Action_Log')) {
            KIT_Bulk_Action_Log::record(
                'waybill_invoice_status',
                [$waybillno],
                'waybill',
                sprintf('status -> %s', $status)
            );
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            if ($updated !== false) {
                wp_send_json_success(['message' => sprintf('Invoice status set to %s.', $status_label)]);
            } else {
                wp_send_json_error(['message' => 'Failed to update the invoice status.']);
            }
        }

        if ($updated === false) {
            KIT_Commons::queueNotice(
                'error',
                'Invoice status not changed.',
                'The database rejected the update, so nothing was saved. Please try again.'
            );
        } elseif ($updated === 0) {
            KIT_Commons::queueNotice(
                'info',
                'No change made.',
                sprintf('Waybill %s was already set to %s.', $waybillno, $status_label)
            );
        } else {
            KIT_Commons::queueNotice(
                'success',
                'Invoice status updated.',
                sprintf('Waybill %s is now %s.', $waybillno, $status_label)
            );
        }

        KIT_Commons::redirectAfterAction(
            admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . $waybillid)
        );
    }


    public static function update_waybillApproval()
    {
        global $wpdb;

        // Check if user can approve waybills
        if (!KIT_User_Roles::can_approve()) {
            wp_die('You do not have permission to approve waybills.');
        }

        // Verify nonce for security
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'update_waybill_approval_nonce')) {
            wp_die('Security check failed.');
        }

        $waybillid = intval($_POST['waybillid'] ?? 0);
        $waybillno = sanitize_text_field($_POST['waybillno'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? '');
        $userId = get_current_user_id();

        $table = $wpdb->prefix . 'kit_waybills';

        // Get current approval status to check if we need to update invoice status
        $current_waybill = $wpdb->get_row($wpdb->prepare(
            "SELECT approval, status FROM $table WHERE id = %d AND waybill_no = %s",
            $waybillid,
            $waybillno
        ));

        $update_data = [
            'approval' => $status,
            'approval_userid' => $userId,
        ];

        // If changing from "Approved" or "Completed" to something else, set invoice status to "pending"
        if (
            $current_waybill &&
            in_array($current_waybill->approval, ['approved', 'completed']) &&
            !in_array($status, ['approved', 'completed'])
        ) {
            $update_data['status'] = 'pending';
            error_log("Approval status changed from '{$current_waybill->approval}' to '{$status}' - setting invoice status to 'pending' for waybill {$waybillno}");
        }

        $updated = $wpdb->update(
            $table,
            $update_data,
            [
                'id' => $waybillid,
                'waybill_no' => $waybillno,
            ],
            array_fill(0, count($update_data), '%s'),
            ['%d', '%s']
        );

        // The handler knows exactly what it did, so it describes the outcome itself.
        // Previously it set a generic URL flag and the view guessed at the wording,
        // which is how the page came to announce an invoice reset that never happened.
        $labels = KIT_Commons::approvalStatusLabels();
        $from = (string) ($current_waybill->approval ?? '');
        $to_label = $labels[$status] ?? $status;

        if ($updated === false) {
            KIT_Commons::queueNotice(
                'error',
                'Approval not changed.',
                'The database rejected the update, so nothing was saved. Please try again.'
            );
            KIT_Commons::redirectAfterAction();
        }

        if ($updated === 0) {
            KIT_Commons::queueNotice(
                'info',
                'No change made.',
                sprintf('Waybill %s was already set to %s.', $waybillno, $to_label)
            );
            KIT_Commons::redirectAfterAction();
        }

        KIT_Commons::queueNotice(
            'success',
            'Approval updated.',
            sprintf('Waybill %s is now %s.', $waybillno, $to_label)
        );

        if (isset($update_data['status'])) {
            KIT_Commons::queueNotice(
                'info',
                'Invoice status reset to Pending.',
                sprintf('It was reset because approval moved away from %s.', $labels[$from] ?? $from)
            );
        }

        if (class_exists('KIT_Bulk_Action_Log')) {
            KIT_Bulk_Action_Log::record(
                'waybill_approval',
                [$waybillno],
                'waybill',
                sprintf('approval %s -> %s', $from !== '' ? $from : 'unset', $status)
            );
        }

        KIT_Commons::redirectAfterAction();
    }

    /**
     * Single action: set waybill approval to "approved" and invoice status to "invoiced".
     * Requires both can_approve() and can_invoice().
     */
    public static function approve_and_invoice()
    {
        global $wpdb;

        if (!KIT_User_Roles::can_approve()) {
            wp_die('You do not have permission to approve waybills.');
        }
        if (!KIT_User_Roles::can_invoice()) {
            wp_die('You do not have permission to invoice waybills.');
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'update_waybill_approval_nonce')) {
            wp_die('Security check failed.');
        }

        $waybillid = intval($_POST['waybillid'] ?? 0);
        $waybillno = sanitize_text_field($_POST['waybillno'] ?? '');
        $userId = get_current_user_id();
        $table = $wpdb->prefix . 'kit_waybills';

        $updated = $wpdb->update(
            $table,
            [
                'approval' => 'approved',
                'approval_userid' => $userId,
                'status' => 'invoiced',
                'status_userid' => $userId,
            ],
            ['id' => $waybillid, 'waybill_no' => $waybillno],
            ['%s', '%d', '%s', '%d'],
            ['%d', '%s']
        );

        if ($updated === false) {
            KIT_Commons::queueNotice(
                'error',
                'Nothing was changed.',
                'The database rejected the update, so this waybill was neither approved nor invoiced.'
            );
            KIT_Commons::redirectAfterAction();
        }

        KIT_Commons::queueNotice(
            'success',
            'Approved and invoiced.',
            sprintf('Waybill %s is now Approved, with its invoice status set to Invoiced.', $waybillno)
        );

        if (class_exists('KIT_Bulk_Action_Log')) {
            KIT_Bulk_Action_Log::record(
                'waybill_approve_invoice',
                [$waybillno],
                'waybill',
                'approval -> approved, status -> invoiced'
            );
        }

        KIT_Commons::redirectAfterAction();
    }

    public static function assign_waybill_to_delivery()
    {
        global $wpdb;

        // Check if user can assign deliveries (Admin or Manager only)
        if (!KIT_User_Roles::can_approve()) {
            wp_die('You do not have permission to assign waybills to deliveries.');
        }

        // Verify nonce for security
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'assign_waybill_delivery_nonce')) {
            wp_die('Security check failed.');
        }

        $waybill_id = intval($_POST['waybill_id'] ?? 0);
        $waybill_no = sanitize_text_field($_POST['waybill_no'] ?? '');
        $delivery_id = intval($_POST['delivery_id'] ?? 0);
        $assigned_by = get_current_user_id();

        if (!$waybill_id || !$delivery_id) {
            KIT_Commons::queueNotice('error', 'Assignment failed.', 'The waybill or delivery reference was missing from the request.');
            KIT_Commons::redirectAfterAction();
        }

        // Check if waybill exists in warehouse tracking
        $warehouse_waybill = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}kit_waybills WHERE waybill_no = %s",
            $waybill_no
        ));

        if (!$warehouse_waybill) {
            KIT_Commons::queueNotice('error', 'Assignment failed.', sprintf('Waybill %s could not be found.', $waybill_no));
            KIT_Commons::redirectAfterAction();
        }

        // Check if delivery exists
        $delivery = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}kit_deliveries WHERE id = %d",
            $delivery_id
        ));

        if (!$delivery) {
            KIT_Commons::queueNotice('error', 'Assignment failed.', 'That delivery no longer exists.');
            KIT_Commons::redirectAfterAction();
        }

        // Check if waybill is already assigned
        if ($warehouse_waybill->status === 'assigned' || $warehouse_waybill->status === 'shipped' || $warehouse_waybill->status === 'delivered') {
            KIT_Commons::queueNotice(
                'error',
                'Already assigned.',
                sprintf('Waybill %s is already %s, so it cannot be assigned again.', $waybill_no, $warehouse_waybill->status)
            );
            KIT_Commons::redirectAfterAction();
        }

        // Update warehouse tracking status
        $result = $wpdb->update(
            $wpdb->prefix . 'kit_waybills',
            [
                'status' => 'assigned',
                'delivery_id' => $delivery_id,
                'assigned_by' => $assigned_by,
                'assigned_at' => current_time('mysql')
            ],
            ['waybill_no' => $waybill_no]
        );

        if (!$result) {
            error_log("Failed to assign waybill {$waybill_no} to delivery {$delivery_id}: " . $wpdb->last_error);
            KIT_Commons::queueNotice('error', 'Assignment failed.', 'The database rejected the update, so nothing was saved.');
        } else {
            error_log("Waybill {$waybill_no} assigned to delivery {$delivery_id} by user {$assigned_by}");
            KIT_Commons::queueNotice(
                'success',
                'Assigned to delivery.',
                sprintf('Waybill %s is now on delivery #%d.', $waybill_no, $delivery_id)
            );
            if (class_exists('KIT_Bulk_Action_Log')) {
                KIT_Bulk_Action_Log::record(
                    'waybill_delivery_assign',
                    [$waybill_no],
                    'waybill',
                    sprintf('assigned to delivery %d', $delivery_id)
                );
            }
        }

        KIT_Commons::redirectAfterAction();
    }

    public static function myplugin_ajax_load_waybill_page()
    {
        check_ajax_referer('get_waybills_nonce', 'nonce');

        $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
        $items_per_page = isset($_POST['items_per_page']) ? intval($_POST['items_per_page']) : 20;

        $all_waybills = self::kit_get_all_waybills();

        // Return only the table (for AJAX) - use standardized columns
        // Note: waybill_no already includes status badge below it (universal), so no separate status column needed
        $columns = KIT_Commons::getColumns(['waybill_no', 'customer_name_plain']);

        $actions = [
            [
                'label' => 'View',
                'href' => '?page=08600-Waybill-view&waybill_id={waybill_id}',
                'class' => 'text-blue-600 hover:text-blue-800'
            ]
        ];

        // Capture the HTML output instead of echoing it directly
        ob_start();
        echo KIT_Unified_Table::infinite($all_waybills, $columns, KIT_Unified_Table::optionsWithManageDefaults([
            'title' => 'Waybills',
            'actions' => $actions,
            'pagination' => true,
            'items_per_page' => $items_per_page,
            'current_page' => $paged,
        ]));
        $html_output = ob_get_clean();

        // Return JSON response with the HTML content
        wp_send_json_success([
            'html' => $html_output,
            'total_waybills' => count($all_waybills),
            'current_page' => $paged,
            'items_per_page' => $items_per_page
        ]);
    }

    public static function truckWaybills($deliveryid)
    {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $deliveries_table = $prefix . 'kit_deliveries';
        $waybill_table = $prefix . 'kit_waybills';
        $customers_table = $prefix . 'kit_customers';
        $cities_table = $prefix . 'kit_operating_cities';

        // Get all waybills for this delivery (parcels are handled via parcel_id, not consolidated_waybill_id)
        $all_waybills = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                wb.id as waybill_id, 
                wb.waybill_no as waybill_no, 
                wb.direction_id, 
                wb.delivery_id, 
                wb.customer_id, 
                wb.city_id, 
                wb.approval, 
                wb.approval_userid, 
                wb.product_invoice_number, 
                wb.product_invoice_amount, 
                wb.mass_charge, 
                wb.volume_charge, 
                wb.charge_basis, 
                wb.miscellaneous, 
                wb.tracking_number, 
                wb.created_by, 
                wb.last_updated_by, 
                wb.status, 
                wb.status_userid, 
                wb.created_at, 
                wb.last_updated_at, 
                wb.item_length, 
                wb.item_width, 
                wb.item_height, 
                wb.total_mass_kg, 
                wb.total_volume,
                wb.description,
                wb.parcel_id,
                c.name as customer_name, 
                c.surname as customer_surname, 
                c.cell as customer_cell, 
                c.email_address as customer_email, 
                c.address as customer_address
            FROM $deliveries_table d, $waybill_table as wb, $customers_table as c 
            WHERE d.id = wb.delivery_id 
            AND wb.customer_id = c.cust_id 
            AND d.id = %d",
            $deliveryid
        ), ARRAY_A);

        // Sort by created_at DESC
        usort($all_waybills, function ($a, $b) {
            $a_date = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
            $b_date = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
            return $b_date - $a_date;
        });

        // Add total amount calculation and city name to each waybill
        foreach ($all_waybills as &$waybill) {
            $product_amount = floatval($waybill['product_invoice_amount'] ?? 0);
            $miscellaneous = floatval($waybill['miscellaneous'] ?? 0);
            $waybill['total'] = $product_amount + $miscellaneous;

            // Add city name if city_id exists
            $city_name = '';
            if (!empty($waybill['city_id'])) {
                $city_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT city_name FROM $cities_table WHERE id = %d LIMIT 1",
                    intval($waybill['city_id'])
                ), ARRAY_A);
                if ($city_row && isset($city_row['city_name'])) {
                    $city_name = trim($city_row['city_name']);
                    $waybill['customer_city'] = $city_name;
                }
            }

            // Add 'city' field for grouping (used by unified table)
            $waybill['city'] = $city_name !== '' ? $city_name : 'Unassigned City';

            // Add waybill_type for sorting (parcels have parcel_id set)
            $waybill['waybill_type'] = (!empty($waybill['parcel_id'])) ? 'parcel' : 'regular';
        }

        return $all_waybills;
    }
    //Get all waybills along with their items from the waybills_items table
    /**
     * Displays the waybill form.
     * function waybillLists
     * @return string HTML content of the waybill form.
     */
    public static function waybillLists($deliveryid)
    {
        global $wpdb;

        $prefix = $wpdb->prefix;

        // TABLE NAMES
        $waybills_table   = $prefix . 'kit_waybills';
        $customers_table  = $prefix . 'kit_customers';
        $deliveries_table = $prefix . 'kit_deliveries';
        $directions_table = $prefix . 'kit_shipping_directions';
        $countries_table  = $prefix . 'kit_operating_countries';
        $items_table      = $prefix . 'kit_waybill_items';

        // STEP 1: Get Delivery Info
        $delivery_sql = $wpdb->prepare("
            SELECT 
                delivery.id,
                delivery.delivery_reference,
                delivery.direction_id,
                delivery.dispatch_date,
                delivery.truck_number,
                delivery.status,
                dir.description AS route_description,
                origin.country_name AS origin_country,
                dest.country_name AS destination_country
            FROM $deliveries_table delivery
            LEFT JOIN $directions_table dir ON delivery.direction_id = dir.id
            LEFT JOIN $countries_table origin ON dir.origin_country_id = origin.id
            LEFT JOIN $countries_table dest ON dir.destination_country_id = dest.id
            WHERE delivery.id = %d
            LIMIT 1
        ", $deliveryid);

        $delivery = $wpdb->get_row($delivery_sql, ARRAY_A);

        if (!$delivery) {
            return null; // No delivery found
        }

        // STEP 2: Get all waybills linked to this delivery
        $waybills_sql = $wpdb->prepare("
            SELECT 
                w.id AS waybill_id,
                w.*,
                c.name AS customer_name,
                c.surname AS customer_surname,
                c.cell AS customer_cell
            FROM $waybills_table w
            LEFT JOIN $customers_table c ON w.customer_id = c.cust_id
            WHERE w.delivery_id = %d
        ", $deliveryid);

        $waybills_data = $wpdb->get_results($waybills_sql, ARRAY_A);

        $waybills = [];

        // STEP 3: Loop through waybills and get items for each
        foreach ($waybills_data as $waybill) {
            $items_sql = $wpdb->prepare("SELECT * FROM $items_table WHERE waybillno = %d", $waybill['waybill_no']);
            $items = $wpdb->get_results($items_sql, ARRAY_A);

            $waybills[] = [
                'waybill' => $waybill,
                'items'   => $items,
            ];
        }

        // FINAL RETURN
        return [
            'delivery' => $delivery,
            'waybills' => $waybills,
        ];
    }

    public static function get_waybill_items($waybillNo)
    {
        $waybill_items = [];
        global $wpdb;
        $table = $wpdb->prefix . 'kit_waybill_items';

        $waybill_items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE waybillno = %s",
                $waybillNo
            )
        );

        if (empty($waybill_items)) {
            return [];
        }

        return is_array($waybill_items) ? $waybill_items : [];
    }

    public static function render_waybills_with_items($waybills)
    {
        // First group waybills by their ID
        $grouped_waybills = [];
        $current_user = wp_get_current_user();
        foreach ($waybills as $waybill) {
            $waybill_id = $waybill['id'];

            if (!isset($grouped_waybills[$waybill_id])) {
                // Get items for this waybill
                $items = KIT_Waybills::get_waybill_items($waybill['waybill_no']);

                // Prepare waybill data
                $main_waybill = clone $waybill;
                unset($main_waybill->item_name);
                unset($main_waybill->quantity);
                unset($main_waybill->unit_price);

                $grouped_waybills[$waybill_id] = [
                    'waybill' => $main_waybill,
                    'items' => $items
                ];
            }
        }

        // Output the table
        ob_start(); ?>
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white border border-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Waybill #</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Items</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($grouped_waybills as $waybill_id => $data):

                        $waybill = $data['waybill'];
                        $items = $data['items'];
                        $item_count = count($items);
                    ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-medium text-blue-600"><?= esc_html($waybill['waybill_no']) ?></div>
                                <div class="text-xs text-gray-500"><?= date('M d, Y', strtotime($waybill['created_at'])) ?></div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div><?= esc_html($waybill['customer_id']) ?></div>
                                <div class="text-xs text-gray-500 truncate max-w-xs"><?= esc_html($waybill['customer_name'] ?? '') ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <?php if (KIT_User_Roles::can_see_prices()): ?>
                                    R<?= ($waybill['product_invoice_amount']) ?>
                                <?php else: ?>
                                    ***
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span
                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= ($waybill['status'] === 'pending') ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800' ?>">
                                    <?= ucfirst($waybill['status']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <?php echo KIT_Commons::renderButton($item_count . ' item' . (($item_count !== 1) ? 's' : ''), 'ghost-primary', 'lg', [
                                    'classes' => 'toggle-items flex items-center',
                                    'data-waybill-id' => $waybill_id,
                                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>',
                                    'iconPosition' => 'right'
                                ]); ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex space-x-2">
                                    <a href="<?php echo admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . $waybill['id'] . '&waybill_atts=view_waybill'); ?>"
                                        class="text-blue-600 hover:text-blue-900" title="View">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </a>
                                    <?= KIT_Commons::deleteWaybillGlobal($waybill['id'], $waybill['waybill_no'], $waybill['delivery_id'], $current_user->ID); ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="item-accordion" id="items-<?= $waybill_id ?>">
                            <td colspan="6" class="bg-gray-50 px-4 py-3">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs border border-gray-200 rounded">
                                        <thead>
                                            <tr class="bg-gray-100">
                                                <th class="<?= KIT_Commons::thClasses() ?> text-left">Item</th>
                                                <th class="<?= KIT_Commons::thClasses() ?> text-right">Qty</th>
                                                <th class="<?= KIT_Commons::thClasses() ?> text-right">Price</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Use tRows function to render items table body only
                                            $item_columns = [
                                                'item' => 'Item',
                                                'quantity' => 'Qty',
                                                'unit_price' => 'Price'
                                            ];

                                            // Prepare items data for tRows
                                            $items_data = [];
                                            foreach ($items as $item) {
                                                $items_data[] = (object) [
                                                    'item' => $item->item ?? $item->item_name,
                                                    'quantity' => $item->quantity ?? '',
                                                    'unit_price' => $item->unit_price ?? 0
                                                ];
                                            }

                                            echo self::tRows($items_data, $item_columns, [
                                                'accordion' => true,
                                                'show_headers' => false,
                                                'table_class' => '',
                                                'row_class' => 'border-t border-gray-100',
                                                'cell_class' => KIT_Commons::tcolClasses(),
                                                'custom_cell_render' => function ($key, $item, $index) {
                                                    if ($key === 'unit_price') {
                                                        if (KIT_User_Roles::can_see_prices()) {
                                                            return '<span class="text-right">R' . number_format($item->unit_price, 2) . '</span>';
                                                        } else {
                                                            return '<span class="text-right">***</span>';
                                                        }
                                                    }
                                                    if ($key === 'quantity') {
                                                        return '<span class="text-right">' . esc_html($item->quantity) . '</span>';
                                                    }
                                                    return esc_html($item->item);
                                                }
                                            ]);
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.toggle-items').forEach(button => {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        const waybillId = this.getAttribute('data-waybill-id');
                        const accordionRow = document.getElementById('items-' + waybillId);
                        const icon = this.querySelector('svg');

                        accordionRow.classList.toggle('hidden');
                        icon.classList.toggle('rotate-180');
                    });
                });
            });
        </script>
    <?php

    }


    public static function display_waybill_form()
    {
        wp_enqueue_style('kit-tailwind');
        // Quotations CSS removed
        // Ensure our city loader script is available on waybill page
        wp_enqueue_script('kitscript', plugin_dir_url(__FILE__) . '../../js/kitscript.js', ['jquery'], null, true);
        wp_enqueue_script('waybill-pagination', plugin_dir_url(__FILE__) . '../../js/waybill-pagination.js', ['jquery'], null, true);

        ob_start();
        include plugin_dir_path(__FILE__) . 'waybill-form.php';
        return ob_get_clean();
    }

    public static function process_form()
    {
        // Ensure WP database handle is available throughout this function
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            $wpdb = $GLOBALS['wpdb'] ?? null;
        }
        if (!$wpdb) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Courier Finance Plugin Error: $wpdb is not initialized in process_form');
            }
            if (defined('DOING_AJAX') && DOING_AJAX) {
                wp_send_json_error('Database not available', 500);
                wp_die();
            } else {
                wp_die('Database not available');
            }
        }
        // First determine if this is an AJAX request
        $is_ajax = defined('DOING_AJAX') && DOING_AJAX;

        // Verify the appropriate nonce based on request type
        if ($is_ajax) {
            // For AJAX requests - use strict nonce verification
            if (!check_ajax_referer('add_waybill_nonce', '_ajax_nonce', true)) {
                wp_send_json_error('Invalid nonce - security violation', 403);
                wp_die();
            }
        } else {
            // For regular form submissions - enhanced verification
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'add_waybill_nonce')) {
                wp_die('Nonce verification failed - security violation');
            }
        }

        // Check permissions (allow plugin-specific capability)
        if (!current_user_can('kit_update_data') && !current_user_can('manage_options') && !current_user_can('edit_pages')) {
            if ($is_ajax) {
                wp_send_json_error('Unauthorized', 403);
            } else {
                wp_die('Unauthorized');
            }
        }

        // Process the form data
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Debug: Log the POST data (only in development)
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Waybill form submission: ' . print_r($_POST, true));
            }

            // 🔒 SECURITY: Comprehensive server-side validation (mirrors client-side)
            $errors = [];

            // Check if this is a parcel submission
            // Only use parcel table if MULTIPLE waybills (2+), single waybill uses regular waybill table only
            $is_parcel = isset($_POST['waybills']) && is_array($_POST['waybills']) && count($_POST['waybills']) > 1;

            // Enhanced customer validation
            $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
            $customer_surname = isset($_POST['customer_surname']) ? trim($_POST['customer_surname']) : '';
            $cell = isset($_POST['cell']) ? trim($_POST['cell']) : '';
            $address = isset($_POST['address']) ? trim($_POST['address']) : '';
            $email_address = isset($_POST['email_address']) ? trim($_POST['email_address']) : '';
            $cust_id = isset($_POST['cust_id']) ? intval($_POST['cust_id']) : 0;
            $customer_select = isset($_POST['customer_select']) ? sanitize_text_field($_POST['customer_select']) : '';

            // Only validate customer fields if creating a new customer
            $is_new_customer = ($customer_select === 'new' || ($cust_id <= 0 && empty($customer_select)));

            if ($is_new_customer) {
                if (empty($customer_name)) {
                    $errors[] = [
                        'field' => 'customer_name',
                        'message' => 'Customer name is required'
                    ];
                }

                if (empty($customer_surname)) {
                    $errors[] = [
                        'field' => 'customer_surname',
                        'message' => 'Customer surname is required'
                    ];
                }

                if (empty($cell)) {
                    $errors[] = [
                        'field' => 'cell',
                        'message' => 'Cell phone number is required'
                    ];
                }

                if (empty($address)) {
                    $errors[] = [
                        'field' => 'address',
                        'message' => 'Address is required'
                    ];
                }

                if (empty($email_address)) {
                    $errors[] = [
                        'field' => 'email_address',
                        'message' => 'Email address is required'
                    ];
                } elseif (!filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = [
                        'field' => 'email_address',
                        'message' => 'Please enter a valid email address'
                    ];
                }
            } elseif ($cust_id <= 0 && empty($customer_select)) {
                // No customer selected and not creating new
                $errors[] = [
                    'field' => 'cust_id',
                    'message' => 'Please select a customer or create a new one'
                ];
            }

            // Enhanced origin validation
            // Only require origin fields if not warehouse AND not creating from delivery
            $is_warehouse = isset($_POST['pending']) && $_POST['pending'] == 1;
            $is_from_delivery = !empty($_POST['delivery_id']);

            if (!$is_warehouse && !$is_from_delivery) {
                if (empty($_POST['country_id'])) {
                    $errors[] = [
                        'field' => 'origin_country_select',
                        'message' => 'Origin country is required'
                    ];
                }

                if (empty($_POST['city_id'])) {
                    $errors[] = [
                        'field' => 'origin_city_select',
                        'message' => 'Origin city is required'
                    ];
                }
            }

            // Validate country status - ensure countries are active
            if (!empty($_POST['country_id'])) {
                $origin_country_active = $wpdb->get_var($wpdb->prepare(
                    "SELECT is_active FROM {$wpdb->prefix}kit_operating_countries WHERE id = %d",
                    intval($_POST['country_id'])
                ));
                if ($origin_country_active != 1) {
                    $errors[] = [
                        'field' => 'origin_country_select',
                        'message' => 'Selected origin country is not active. Please select an active country.'
                    ];
                }
            }

            // Check if this is a parcel submission
            // Only use parcel table if MULTIPLE waybills (2+), single waybill uses regular waybill table only
            $is_parcel = isset($_POST['waybills']) && is_array($_POST['waybills']) && count($_POST['waybills']) > 1;

            // Enhanced destination validation
            // For parcels, destination validation is done per waybill in step 4/5
            if (!$is_parcel) {
                $is_warehouse = isset($_POST['pending']) && $_POST['pending'] == 1;

                // Use backup field if main field is empty (for delivery card selections)
                $destination_country = isset($_POST['destination_country']) ? trim($_POST['destination_country']) : '';
                if (empty($destination_country) && isset($_POST['destination_country_backup'])) {
                    $destination_country = trim($_POST['destination_country_backup']);
                    $_POST['destination_country'] = $destination_country; // Sync to main field
                }

                if (!$is_warehouse) {
                    if (empty($destination_country)) {
                        $errors[] = [
                            'field' => 'stepDestinationSelect',
                            'message' => 'Destination country is required for non-warehouse items'
                        ];
                    } else {
                        // Validate destination country status
                        $destination_country_active = $wpdb->get_var($wpdb->prepare(
                            "SELECT is_active FROM {$wpdb->prefix}kit_operating_countries WHERE id = %d",
                            intval($destination_country)
                        ));
                        if ($destination_country_active != 1) {
                            $errors[] = [
                                'field' => 'stepDestinationSelect',
                                'message' => 'Selected destination country is not active. Please select an active country.'
                            ];
                        }
                    }
                    if (empty($_POST['destination_city'])) {
                        $errors[] = [
                            'field' => 'destination_city',
                            'message' => 'Destination city is required for non-warehouse items'
                        ];
                    }
                } else {
                    // Even for warehouse items, require destination country
                    if (empty($destination_country)) {
                        $errors[] = [
                            'field' => 'stepDestinationSelect',
                            'message' => 'Destination country is required even for warehouse items'
                        ];
                    } else {
                        // Validate destination country status for warehouse items too
                        $destination_country_active = $wpdb->get_var($wpdb->prepare(
                            "SELECT is_active FROM {$wpdb->prefix}kit_operating_countries WHERE id = %d",
                            intval($destination_country)
                        ));
                        if ($destination_country_active != 1) {
                            $errors[] = [
                                'field' => 'stepDestinationSelect',
                                'message' => 'Selected destination country is not active. Please select an active country.'
                            ];
                        }
                    }
                }
            }

            // Enhanced mass/volume validation
            // Skip for parcels (validation done per waybill)
            if (!$is_parcel) {
                if (empty($_POST['total_mass_kg']) || floatval($_POST['total_mass_kg']) <= 0) {
                    $errors[] = [
                        'field' => 'total_mass_kg',
                        'message' => 'Total mass is required and must be greater than 0'
                    ];
                }
            }

            // Enhanced delivery validation
            // Skip for parcels (validation done per waybill in step 4)
            if (!$is_parcel) {
                $is_warehouse = isset($_POST['pending']) && $_POST['pending'] == 1;
                $hasDeliverySelected = !empty($_POST['direction_id']) || !empty($_POST['delivery_id']);
                if (!$is_warehouse && !$hasDeliverySelected) {
                    $errors[] = [
                        'field' => 'direction_id',
                        'message' => 'Please select a delivery or check the warehouse option'
                    ];
                }
            }

            // 🔒 SECURITY: Additional data integrity checks
            // Validate numeric fields to prevent injection
            $numeric_fields = ['total_mass_kg', 'total_volume', 'mass_charge', 'volume_charge', 'item_length', 'item_width', 'item_height'];
            foreach ($numeric_fields as $field) {
                if (isset($_POST[$field]) && !empty($_POST[$field])) {
                    $value = $_POST[$field];
                    // Remove any non-numeric characters except decimal point and comma
                    $cleaned = preg_replace('/[^0-9.,\-]/', '', $value);
                    if (!is_numeric(str_replace(',', '.', $cleaned))) {
                        $errors[] = [
                            'field' => $field,
                            'message' => 'Invalid numeric value for ' . str_replace('_', ' ', $field)
                        ];
                    }
                }
            }

            // Validate waybill items if provided
            if (!empty($_POST['custom_items']) && is_array($_POST['custom_items'])) {
                foreach ($_POST['custom_items'] as $index => $item) {
                    if (!empty($item['item_name'])) {
                        // Validate item name length
                        if (strlen($item['item_name']) > 255) {
                            $errors[] = [
                                'field' => 'custom_items_' . $index,
                                'message' => 'Item name too long (max 255 characters)'
                            ];
                        }

                        // Validate quantity
                        if (isset($item['quantity']) && (!is_numeric($item['quantity']) || intval($item['quantity']) <= 0)) {
                            $errors[] = [
                                'field' => 'custom_items_' . $index,
                                'message' => 'Invalid quantity for item'
                            ];
                        }

                        // Validate unit price
                        if (isset($item['unit_price']) && (!is_numeric($item['unit_price']) || floatval($item['unit_price']) < 0)) {
                            $errors[] = [
                                'field' => 'custom_items_' . $index,
                                'message' => 'Invalid unit price for item'
                            ];
                        }
                    }
                }
            }

            // If there are validation errors, return them
            if (!empty($errors)) {
                if ($is_ajax) {
                    wp_send_json_error([
                        'message' => 'Please fix the validation errors below.',
                        'errors' => $errors
                    ]);
                } else {
                    // Redirect back to the form with encoded errors for inline display
                    $referer = wp_get_referer();
                    if (!$referer) {
                        $referer = admin_url('admin.php?page=08600-Waybill');
                    }
                    $encoded_errors = base64_encode(wp_json_encode($errors));
                    $redirect_url = add_query_arg('form_errors', rawurlencode($encoded_errors), $referer);
                    wp_redirect($redirect_url);
                }
                return;
            }

            // Check if this is a parcel submission (MULTIPLE waybills = 2+)
            // Only use parcel table if MULTIPLE waybills, single waybill uses regular waybill table only
            if (isset($_POST['waybills']) && is_array($_POST['waybills']) && count($_POST['waybills']) > 1) {
                // Handle parcels (2+ waybills)
                $result = self::save_parcel($_POST);
            } else {
                // Handle single waybill (1 waybill) - save directly to waybill table, no parcel table
                $result = self::save_waybill($_POST);
            }

            if (is_wp_error($result)) {
                // Handle the error gracefully
                $error_message = $result->get_error_message();
                $error_code = $result->get_error_code();
                error_log('Waybill save error: ' . $error_message);

                if ($is_ajax) {
                    // Return field-specific error if available
                    if ($error_code === 'validation_error') {
                        wp_send_json_error([
                            'message' => 'Please fix the validation errors below.',
                            'errors' => [
                                [
                                    'field' => 'general',
                                    'message' => $error_message
                                ]
                            ]
                        ]);
                    } else {
                        wp_send_json_error(['message' => 'Error saving waybill: ' . $error_message]);
                    }
                } else {
                    wp_redirect(add_query_arg('error', urlencode($error_message), wp_get_referer()));
                }
                return;
            }


            // Success case
            if ($is_ajax) {
                // 🔒 SECURITY: Role-based success message
                if (class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices()) {
                    $success_message = 'Waybill saved successfully.';
                } else {
                    $success_message = 'Waybill #' . ($result['waybill_no'] ?? '') . ' created successfully! You can create another waybill.';
                }

                // Check if this is a modal submission
                $is_modal = isset($_POST['is_modal']) && $_POST['is_modal'] === '1';

                // Ensure waybill_id is always a numeric value, not an object
                $waybill_id = 0;
                if (isset($result['id']) && is_numeric($result['id'])) {
                    $waybill_id = intval($result['id']);
                } elseif (is_array($result) && isset($result['waybill_id']) && is_numeric($result['waybill_id'])) {
                    $waybill_id = intval($result['waybill_id']);
                } elseif (is_numeric($result)) {
                    $waybill_id = intval($result);
                }

                wp_send_json_success([
                    'message' => $success_message,
                    'waybill_id' => $waybill_id,
                    'waybill_no' => $result['waybill_no'] ?? '',
                    'can_see_prices' => class_exists('KIT_User_Roles') ? KIT_User_Roles::can_see_prices() : false,
                    'is_admin' => class_exists('KIT_User_Roles') ? KIT_User_Roles::is_admin() : false,
                    'is_modal' => $is_modal
                ]);
            } else {
                // Role-aware redirect after creation
                $waybill_id = isset($result['id']) ? intval($result['id']) : 0;
                $waybill_no = isset($result['waybill_no']) ? $result['waybill_no'] : '';

                // Check if we're coming from a delivery page
                $referer = wp_get_referer();
                if ($referer && strpos($referer, 'page=view-deliveries') !== false) {
                    // Coming from delivery page - redirect back with success message
                    $redirect_url = add_query_arg([
                        'waybill_created' => '1',
                        'waybill_no' => $waybill_no,
                        'message' => urlencode('Waybill #' . $waybill_no . ' created successfully!')
                    ], $referer);
                } else {
                    // 🔒 SECURITY: Role-based redirect - admins go to created waybill; others stay on create page
                    if (class_exists('KIT_User_Roles') && KIT_User_Roles::is_admin()) {
                        // Administrator: go to viewWaybill.php
                        $redirect_url = admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . $waybill_id . '&waybill_atts=view_waybill');
                        $redirect_url = add_query_arg('message', urlencode('Waybill created successfully'), $redirect_url);
                    } else {
                        // Data Capturers/Managers: back to create page with success toast
                        $redirect_url = add_query_arg([
                            'page' => '08600-waybill-create',
                            'success' => '1',
                            'waybill_no' => $waybill_no,
                            'toast_message' => urlencode('Waybill #' . $waybill_no . ' created successfully! You can create another waybill.')
                        ], admin_url('admin.php'));
                    }
                }

                wp_redirect($redirect_url);
            }

            exit;
        }
    }

    /**
     * Get delivery data for pre-populating waybill form
     */
    /**
     * AJAX handler to get deliveries filtered by destination country for parcel dropdown
     */

    public static function get_delivery_data()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'get_delivery_data')) {
            error_log('get_delivery_data: Security check failed - nonce: ' . ($_POST['_ajax_nonce'] ?? 'missing'));
            wp_send_json_error('Security check failed');
        }

        // Check permissions
        if (!current_user_can('kit_view_waybills') && !current_user_can('edit_pages')) {
            wp_send_json_error('Unauthorized');
        }

        $delivery_id = intval($_POST['delivery_id']);
        if (!$delivery_id) {
            wp_send_json_error('Delivery ID required');
        }

        global $wpdb;
        $prefix = $wpdb->prefix;
        $deliveries_table = $prefix . 'kit_deliveries';
        $directions_table = $prefix . 'kit_shipping_directions';
        $cities_table = $prefix . 'kit_operating_cities';
        $countries_table = $prefix . 'kit_operating_countries';

        // Get delivery data with joined country and city information
        $delivery = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                d.*,
                sd.origin_country_id,
                sd.destination_country_id,
                oc.country_name as origin_country_name,
                dc.country_name as destination_country_name,
                city.city_name as destination_city_name
            FROM $deliveries_table d
            LEFT JOIN $directions_table sd ON d.direction_id = sd.id
            LEFT JOIN $countries_table oc ON sd.origin_country_id = oc.id
            LEFT JOIN $countries_table dc ON sd.destination_country_id = dc.id
            LEFT JOIN $cities_table city ON d.destination_city_id = city.id
            WHERE d.id = %d",
            $delivery_id
        ));

        if (!$delivery) {
            wp_send_json_error('Delivery not found');
        }

        // Debug logging
        error_log('get_delivery_data: Found delivery data - ID: ' . $delivery->id .
            ', Origin: ' . $delivery->origin_country_name .
            ', Destination: ' . $delivery->destination_country_name .
            ', City: ' . $delivery->destination_city_name);

        // Return delivery data
        wp_send_json_success([
            'delivery_id' => $delivery->id,
            'origin_country_id' => $delivery->origin_country_id,
            'destination_country_id' => $delivery->destination_country_id,
            'destination_city_id' => $delivery->destination_city_id,
            'origin_country_name' => $delivery->origin_country_name,
            'destination_country_name' => $delivery->destination_country_name,
            'destination_city_name' => $delivery->destination_city_name,
            'dispatch_date' => $delivery->dispatch_date,
            'status' => $delivery->status
        ]);
    }

    //Get the destination country by waybill.delivery_id which referneces the delivery.id
    /**
     * Get the destination country by delivery_id.
     * If $delivery_id is provided, returns the country name as string.
     * If called as an AJAX action, echoes JSON and exits.
     *
     * @param int|null $delivery_id
     * @return string|void
     */
    public static function getDestinationCountry($delivery_id = null)
    {
        global $wpdb;

        // If called as an AJAX action (admin_post), get delivery_id from POST or GET
        if ($delivery_id === null) {
            $delivery_id = isset($_REQUEST['delivery_id']) ? intval($_REQUEST['delivery_id']) : 0;
            $is_ajax = defined('DOING_AJAX') && DOING_AJAX;

            if (! $delivery_id) {
                if ($is_ajax) {
                    wp_send_json_error('No delivery_id provided.');
                } else {
                    wp_die('No delivery_id provided.');
                }
            }
        }

        $table = $wpdb->prefix . 'kit_deliveries';
        $country = $wpdb->get_var($wpdb->prepare(
            "SELECT destination_country FROM $table WHERE id = %d",
            $delivery_id
        ));

        if ($country) {
            // If called as AJAX action, return JSON
            $action = (string)($_REQUEST['action'] ?? '');
            if (! is_null($delivery_id) && $action !== '' && strpos($action, 'getDestinationCountry') !== false) {
                wp_send_json_success(['destination_country' => $country]);
            }
            return esc_html($country);
        } else {
            if (! is_null($delivery_id) && isset($_REQUEST['action']) && $_REQUEST['action'] !== '' && strpos($_REQUEST['action'], 'getDestinationCountry') !== false) {
                wp_send_json_error('Destination country not found.');
            }
            return '<span class="text-red-500">Not set</span>';
        }
    }

    // Calculate total:
    // $mass_charge, $volume_charge, $misc_total may not be empty
    // $waybillItemsTotal is conidtional, its not a must to include it, if its set, then we can include it, get the 10% value of the total $waybillItemsTotal
    // 
    public static function calculate_total($mass_charge, $volume_charge, $misc_total, $waybillItemsTotal, $charge_basis = null, $ttt = null)
    {
        $itemsTotal = 0;
        $include_sad500 = 0;
        $include_sadc = 0;

        // Ensure all inputs are floats
        $vat_selected = ((isset($_POST['vat_include']) && $_POST['vat_include'] == 1) || (is_array($ttt) && isset($ttt['vat_include']) && $ttt['vat_include'] == 1));
        $sad500_selected = ((isset($_POST['include_sad500']) && $_POST['include_sad500'] == 1) || (is_array($ttt) && isset($ttt['include_sad500']) && $ttt['include_sad500']));
        $sadc_selected = ((isset($_POST['include_sadc']) && $_POST['include_sadc'] == 1) || (is_array($ttt) && isset($ttt['include_sadc']) && $ttt['include_sadc']));
        // Enforce business rule server-side: VAT and SADC are mutually exclusive.
        if ($vat_selected) {
            $sadc_selected = false;
        }
        $include_sad500 = $sad500_selected ? self::sadc_certificate() : 0;
        $include_sadc = $sadc_selected ? self::sad() : 0;

        // Calculate VAT and international price
        $vatCharge = 0;
        $internationalPrice = 0;

        // Determine primary charge: manual override when charge_basis is set,
        // otherwise use the higher of mass vs volume.
        $better_charge = 0;
        if ($charge_basis == 'mass' || $charge_basis == 'weight') {
            $better_charge = $mass_charge;
        } elseif ($charge_basis == 'volume') {
            $better_charge = $volume_charge;
        } else {
            $better_charge = max($mass_charge, $volume_charge);
        }

        if ($vat_selected) {
            // Calculate VAT on the waybill amount (better charge) plus waybill items
            $vatBase = $better_charge + $waybillItemsTotal;
            $vatCharge = self::vatCharge($vatBase);
        } else {
            // If VAT is not selected, add international price (converted to Rands)
            // Prefer snapshot if provided through $ttt
            if (is_array($ttt) && isset($ttt['international_price_rands']) && is_numeric($ttt['international_price_rands'])) {
                $internationalPrice = floatval($ttt['international_price_rands']);
            } else {
                $internationalPrice = self::international_price_in_rands();
            }
        }

        $additionalCharges = $misc_total + $include_sad500 + $include_sadc + $vatCharge + $internationalPrice;
        // Calculate total
        $total = $better_charge + $additionalCharges;
        return (float) $total;
    }

    /**
     * Processes miscellaneous items from form data and calculates total
     *
     * @param array $miscData The misc data array from form input
     * @return array Contains combined misc items and total amount
     */
    public static function manageMiscItems(array $miscData): array
    {
        // Transform dynamicItemsControl format to getMiscCharges format
        $transformed_data = [
            'misc_item' => [],
            'misc_price' => [],
            'misc_quantity' => []
        ];

        // Check if data is in dynamicItemsControl format (array of items)
        // Reindex to handle deletions that leave non-zero-based numeric keys
        $maybe_items_list = is_array($miscData) ? array_values($miscData) : [];
        if (isset($maybe_items_list[0]) && is_array($maybe_items_list[0]) && isset($maybe_items_list[0]['misc_item'])) {
            // Transform from dynamicItemsControl format to getMiscCharges format
            foreach ($maybe_items_list as $item) {
                if (!empty($item['misc_item'])) {
                    $transformed_data['misc_item'][] = $item['misc_item'];
                    $transformed_data['misc_price'][] = floatval($item['misc_price'] ?? 0);
                    $transformed_data['misc_quantity'][] = intval($item['misc_quantity'] ?? 1);
                }
            }
        } else {
            // Data is already in getMiscCharges format
            $transformed_data = $miscData;
        }

        // Use getMiscCharges function with transformed data
        $misc_result = self::getMiscCharges($transformed_data, []);

        return [
            'misc_items' => $misc_result->misc_items,
            'misc_total' => floatval($misc_result->misc_total)
        ];
    }

    public static function approve_waybill()
    {

        if (! current_user_can('administrator') && ! current_user_can('manager')) {
            wp_die('Unauthorized access');
        }

        // Validate and sanitize input
        $waybill_id  = isset($_POST['waybill_id']) ? intval($_POST['waybill_id']) : 0;
        $user_id     = isset($_POST['user_id']) ? intval($_POST['user_id']) : get_current_user_id();
        $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;

        if (! $waybill_id || ! $user_id) {
            wp_die('Invalid request');
        }

        // Check if delivery is selected
        if (! $delivery_id) {
            wp_redirect(add_query_arg([
                'waybill_approved' => '0',
                'error'            => 'no_delivery_selected',
            ], wp_get_referer()));
            exit;
        }

        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';

        $updated = $wpdb->update(
            $waybills_table,
            [
                'approval_userid' => $user_id,
                'approval'        => 1,
                'delivery_id'     => $delivery_id, // Add delivery_id to the update
            ],
            ['id' => $waybill_id],
            ['%d', '%d', '%d'],
            ['%d']
        );

        if ($updated !== false) {
            wp_redirect(add_query_arg('waybill_approved', '1', wp_get_referer()));
        } else {
            wp_redirect(add_query_arg([
                'waybill_approved' => '0',
                'error'            => 'db_error',
            ], wp_get_referer()));
        }
        exit;
    }

    public static function getMiscCharges($miscData, $serial)
    {
        $misc_combined = [];
        $misc_total = 0;

        if (isset($miscData['misc_item'], $miscData['misc_price'], $miscData['misc_quantity'])) {
            $misc_item = $miscData['misc_item'];
            $amounts = $miscData['misc_price'];
            $quantities = $miscData['misc_quantity'];

            $count = count($misc_item);

            for ($i = 0; $i < $count; $i++) {
                if (!empty($misc_item[$i])) {
                    // Enhanced validation for miscellaneous charges
                    $item_name = sanitize_text_field(trim($misc_item[$i]));
                    $amount = floatval($amounts[$i]);
                    $quantity = intval($quantities[$i]);

                    // Validate item name length (prevent XSS and excessive data)
                    if (strlen($item_name) > 255) {
                        $item_name = substr($item_name, 0, 255);
                    }

                    // Validate quantity (must be positive)
                    if ($quantity <= 0) {
                        $quantity = 1; // Default to 1 for invalid quantities
                    }

                    // Validate amount (must be non-negative, prevent excessive amounts)
                    if ($amount < 0) {
                        $amount = 0; // Default to 0 for negative amounts
                    }

                    // Prevent excessive misc charges (max R100,000 per item)
                    if ($amount > 100000) {
                        $amount = 100000;
                    }

                    $item_total = $amount * $quantity;

                    // Prevent total misc overflow (max R1,000,000 total)
                    if (($misc_total + $item_total) > 1000000) {
                        break; // Stop processing if total would exceed limit
                    }

                    $is_realized = is_array($serial) && in_array($i, $serial);
                    $misc_combined[] = [
                        'misc_item' => $item_name,
                        'misc_price' => $amount,
                        'misc_quantity' => $quantity,
                        'realized' => $is_realized,
                    ];
                    $misc_total += $item_total;
                }
            }
        }

        return (object)[
            'misc_items' => $misc_combined,
            'misc_total' => number_format($misc_total, 2, '.', '')
        ];
    }

    public static function waybillItemsTotal($items)
    {
        $total = 0;

        foreach ($items as $item) {
            // Ensure the values are numeric and safe to use
            $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 0;
            $unit_price = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;

            $total += $quantity * $unit_price;
        }

        return $total;
    }

    //others
    public static function vatRate()
    {
        // Consolidated VAT configuration: fixed at 10% across the system
        // Returns a percentage value for display (10 == 10%)
        return 10.0;
    }
    public static function vatCharge($itemsTotal)
    {
        // vatRate() returns a percentage value (e.g., 15 for 15%) for the settings UI
        // Convert to decimal for calculation, but guard if it's already in decimal form
        $rate = self::vatRate();
        $rate = is_numeric($rate) ? floatval($rate) : 0.0;
        if ($rate > 1) {
            $rate = $rate / 100.0; // convert 15 -> 0.15
        }
        return $itemsTotal * $rate;
    }

    /**
     * Normalize a monetary value that may contain currency symbols or formatting.
     *
     * @param mixed $value
     * @return float
     */
    public static function normalize_amount($value)
    {
        if (is_string($value)) {
            $value = preg_replace('/[^0-9\.\-]/', '', $value);
        }

        if (is_numeric($value)) {
            return floatval($value);
        }

        return 0.0;
    }

    /**
     * Normalize waybill boolean flags (TINYINT, bool, or VARCHAR e.g. vat_include).
     * Same semantics as kit_google_sheet_parse_bool_int (settings.php) so we do not need that file loaded.
     */
    public static function normalize_flag_int($raw): int
    {
        if ($raw === null || $raw === false) {
            return 0;
        }
        if ($raw === true) {
            return 1;
        }
        $s = strtoupper(trim((string) $raw));
        if ($s === '' || $s === 'NULL' || $s === 'N/A' || $s === 'NA' || $s === '-' || $s === '--') {
            return 0;
        }
        if (in_array($s, ['1', 'TRUE', 'YES', 'Y', 'ON'], true)) {
            return 1;
        }
        if (in_array($s, ['0', 'FALSE', 'NO', 'N', 'OFF'], true)) {
            return 0;
        }
        if (is_numeric($s)) {
            return ((float) $s != 0.0) ? 1 : 0;
        }

        return 0;
    }

    /**
     * Overwrite columns from kit_waybills by primary key so SELECT w.* … JOIN cannot pick up duplicate-key overwrites.
     *
     * @param array $row Waybill row (ARRAY_A), must contain waybill_id or id.
     */
    public static function merge_canonical_waybill_columns(array &$row): void
    {
        global $wpdb;
        $pk = isset($row['waybill_id']) ? (int) $row['waybill_id'] : (int) ($row['id'] ?? 0);
        if ($pk <= 0 || empty($wpdb->prefix)) {
            return;
        }
        $canonical = $wpdb->get_row($wpdb->prepare(
            "SELECT include_sad500, include_sadc, vat_include, sad500_amount, sadc_amount, product_invoice_amount, waybill_items_total, miscellaneous
             FROM {$wpdb->prefix}kit_waybills WHERE id = %d LIMIT 1",
            $pk
        ), ARRAY_A);
        if (!is_array($canonical)) {
            return;
        }
        foreach ($canonical as $col => $val) {
            if ($val !== null) {
                $row[$col] = $val;
            }
        }
    }

    /**
     * Default SADC certificate charge (in Rands) when no company setting exists.
     *
     * @return float
     */
    public static function defaultSadcCharge()
    {
        $default = 1000.00;
        if (function_exists('apply_filters')) {
            $default = apply_filters('kit_waybills_default_sadc_charge', $default);
        }
        return floatval($default);
    }

    /**
     * Default SAD500 charge (in Rands) when no company setting exists.
     *
     * @return float
     */
    public static function defaultSad500Charge()
    {
        $default = 350.00;
        if (function_exists('apply_filters')) {
            $default = apply_filters('kit_waybills_default_sad500_charge', $default);
        }
        return floatval($default);
    }

    /**
     * Check if VAT warning should be shown (VAT checked but no items)
     */
    public static function shouldShowVatWarning($vat_checked, $waybillItemsTotal)
    {
        return $vat_checked && $waybillItemsTotal == 0;
    }
    public static function sad()
    {
        global $wpdb;
        $sadc_charge = null;

        if (isset($wpdb) && isset($wpdb->prefix)) {
            $company_table = $wpdb->prefix . 'kit_company_details';
            $sadc_charge = $wpdb->get_var("SELECT sadc_charge FROM $company_table LIMIT 1");
            if ($sadc_charge !== null) {
                $normalized = self::normalize_amount($sadc_charge);
                if ($normalized > 0) {
                    return $normalized;
                }
            }
        }

        return self::defaultSadcCharge();
    }
    public static function sadc_certificate()
    {
        global $wpdb;
        $sad500_charge = null;

        if (isset($wpdb) && isset($wpdb->prefix)) {
            $company_table = $wpdb->prefix . 'kit_company_details';
            $sad500_charge = $wpdb->get_var("SELECT sad500_charge FROM $company_table LIMIT 1");
            if ($sad500_charge !== null) {
                $normalized = self::normalize_amount($sad500_charge);
                if ($normalized > 0) {
                    return $normalized;
                }
            }
        }

        return self::defaultSad500Charge();
    }

    public static function international_price()
    {
        global $wpdb;
        $wpdprefix = $wpdb->prefix;
        $company_table = $wpdprefix . 'kit_company_details';
        $international_price = $wpdb->get_var("SELECT international_price FROM $company_table LIMIT 1");
        if ($international_price !== null && is_numeric($international_price)) {
            return floatval($international_price);
        }
        // Default value if not set
        return 100.00;
    }

    public static function international_price_in_rands()
    {
        // Live USD->ZAR with transient caching (fallback to 18.50)
        $usd_price = self::international_price();
        $cache_key = 'kit_usd_zar_rate';
        $rate = get_transient($cache_key);
        if ($rate === false) {
            $rate = 18.50; // fallback
            $response = wp_remote_get('https://open.er-api.com/v6/latest/USD', ['timeout' => 6]);
            if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($data['rates']['ZAR']) && is_numeric($data['rates']['ZAR'])) {
                    $rate = floatval($data['rates']['ZAR']);
                }
            }
            set_transient($cache_key, $rate, 24 * HOUR_IN_SECONDS);
        }
        return $usd_price * floatval($rate);
    }
    public static function prepareMiscCharges($data)
    {
        $misc_combined = [];
        $misc_total = 0.0;

        $destination_city = $data['destination_city'];
        $destination_country = $data['destination_country'];
        $origin_city = $data['origin_city'];
        $origin_country = $data['origin_country'];

        // Assign variables first
        $has_vat = !empty($data['vat_include']);
        $has_sad500 = !empty($data['include_sad500']);
        $has_waybill_fee = !empty($data['include_sadc']);
        // Enforce business rule server-side: VAT and SADC are mutually exclusive.
        if ($has_vat) {
            $has_waybill_fee = false;
        }

        $manny = (isset($data['enable_price_manipulator'])) ? true : false;

        // 🔒 SECURITY: Only authorized owners (Mel, Patricia, Thando) can use price manipulators
        // Get manipulator values using correct field names
        $manny_mass_rate = (isset($data['enable_price_manipulator']) && isset($data['mass_charge_manipulator'])) ? floatval($data['mass_charge_manipulator']) : null;
        $manny_volume_rate = (isset($data['enable_price_manipulator']) && isset($data['manny_volume_rate'])) ? floatval($data['manny_volume_rate']) : null;

        // Only check permissions if user is actually trying to manipulate prices (has values)
        $is_actually_manipulating = ($manny && (($manny_mass_rate !== null && $manny_mass_rate != 0) || ($manny_volume_rate !== null && $manny_volume_rate != 0)));

        // Allow all users to manipulate rates - this is basic functionality


        $others = [
            'waybill_description' => ($data['waybill_description'] ?? null),
            'mass_rate' => floatval($data['mass_rate'] ?? 0),
            'total_volume' => floatval($data['total_volume'] ?? 0),
            'manny' => $manny,
            'manny_mass_rate' => $manny_mass_rate,
            'manny_volume_rate' => $manny_volume_rate,
            'destination_city_id' => intval($destination_city),
            'destination_country_id' => intval($destination_country),
            'origin_city_id' => intval($origin_city),
            'origin_country_id' => intval($origin_country),
        ];

        // Remove/add keys based on rules
        // Fallback: Check waybills[0][misc] if misc is empty (for single waybill compatibility)
        if (isset($data['misc']) && is_array($data['misc']) && !empty($data['misc'])) {
            // Use manageMiscItems to handle dynamicItemsControl format transformation
            $misc_result = self::manageMiscItems($data['misc']);
            $misc_combined = $misc_result['misc_items'];
            $misc_total = floatval($misc_result['misc_total']);
        } elseif (isset($data['waybills'][0]['misc']) && is_array($data['waybills'][0]['misc']) && !empty($data['waybills'][0]['misc'])) {
            // Fallback for single waybill: extract misc from waybills[0][misc]
            $misc_result = self::manageMiscItems($data['waybills'][0]['misc']);
            $misc_combined = $misc_result['misc_items'];
            $misc_total = floatval($misc_result['misc_total']);
        }

        if ($has_vat) {
            // 🔒 FIX: Calculate items total without inserting to database (waybill not created yet)
            $waybillItemsTotal = 0;
            if (!empty($_POST['custom_items']) && is_array($_POST['custom_items'])) {
                foreach ($_POST['custom_items'] as $item) {
                    if (!empty($item['item_name']) && isset($item['quantity']) && isset($item['unit_price'])) {
                        $quantity = intval($item['quantity']);
                        $unit_price = floatval($item['unit_price']);
                        $waybillItemsTotal += $quantity * $unit_price;
                    }
                }
            }

            // Only VAT, remove SAD500 and waybill fee if present
            unset($data['include_sad500'], $data['include_sadc']);
            $others = array_filter($others, function ($k) {
                return !in_array($k, ['include_sad500', 'include_sadc']);
            }, ARRAY_FILTER_USE_KEY);
            $others['vat_total'] = self::vatCharge($waybillItemsTotal);
        } else {
            // When VAT is NOT included, ALWAYS store international_price
            // This ensures it's available for calculator and display
            $rate_used = self::international_price_in_rands() / max(1.0, self::international_price());
            $others['usd_to_zar_rate_used'] = $rate_used;
            $others['international_price_rands'] = self::international_price_in_rands();
            $others['international_price_snapshot_at'] = current_time('mysql');

            // Also store SAD500/SADC if checked (they can coexist with international_price)
            if ($has_sad500) {
                $others['include_sad500'] = self::sadc_certificate();
            }
            if ($has_waybill_fee) {
                $others['include_sadc'] = self::sad();
            }
        }

        return [
            'misc_items' => $misc_combined,
            'misc_total' => $misc_total,
            'others'     => $others,
        ];
    }

    /**
     * Calculate waybill charges (mass, volume, charge basis)
     * 
     * @param array $data Form data
     * @return array ['mass_charge', 'volume_charge', 'charge_basis', 'posted_total_mass', 'posted_total_volume', 'snapshot_mass_rate', 'use_custom_volume_rate', 'custom_volume_rate']
     */
    private static function calculate_waybill_charges($data)
    {
        $posted_total_mass = isset($data['total_mass_kg']) ? floatval(str_replace(',', '.', $data['total_mass_kg'])) : 0.0;
        $posted_total_volume = isset($data['total_volume']) ? floatval(str_replace(',', '.', $data['total_volume'])) : 0.0;

        // Calculate volume from dimensions if needed
        if ($posted_total_volume <= 0) {
            $item_length = isset($data['item_length']) ? floatval(str_replace(',', '.', $data['item_length'])) : 0.0;
            $item_width = isset($data['item_width']) ? floatval(str_replace(',', '.', $data['item_width'])) : 0.0;
            $item_height = isset($data['item_height']) ? floatval(str_replace(',', '.', $data['item_height'])) : 0.0;
            if ($item_length > 0 && $item_width > 0 && $item_height > 0) {
                $posted_total_volume = ($item_length * $item_width * $item_height) / 1000000;
            }
        }

        // Mass rate and charge
        $snapshot_mass_rate = 0.0;
        if (isset($data['mass_rate']) && floatval($data['mass_rate']) > 0) {
            $snapshot_mass_rate = floatval(str_replace(',', '.', $data['mass_rate']));
        } elseif (isset($data['current_rate']) && floatval($data['current_rate']) > 0) {
            $snapshot_mass_rate = floatval(str_replace(',', '.', $data['current_rate']));
        } elseif (isset($data['base_rate']) && floatval($data['base_rate']) > 0) {
            $snapshot_mass_rate = floatval(str_replace(',', '.', $data['base_rate']));
        } else {
            $snapshot_mass_rate = 30.0; // Default
        }

        $mass_charge = 0.0;
        if ($posted_total_mass > 0 && $snapshot_mass_rate > 0) {
            $mass_charge = $posted_total_mass * $snapshot_mass_rate;
        }

        // Mass manipulator (if authorized)
        $is_manipulating_mass = isset($data['enable_price_manipulator']) && $data['enable_price_manipulator'] &&
            isset($data['mass_charge_manipulator']) && floatval($data['mass_charge_manipulator']) != 0;
        if ($is_manipulating_mass) {
            $mass_charge += floatval($data['mass_charge_manipulator']);
        }

        // Volume charge
        $volume_charge = 0.0;
        $custom_volume_rate = isset($data['custom_volume_rate_per_m3']) ? floatval(str_replace(',', '.', $data['custom_volume_rate_per_m3'])) : 0.0;
        $use_custom_volume_rate = isset($data['use_custom_volume_rate']) && intval($data['use_custom_volume_rate']) === 1;
        if ($posted_total_volume > 0) {
            if ($use_custom_volume_rate && $custom_volume_rate > 0) {
                $volume_charge = $posted_total_volume * $custom_volume_rate;
            } else {
                $posted_volume_charge = isset($data['volume_charge']) ? floatval(str_replace(',', '.', $data['volume_charge'])) : 0.0;
                if ($posted_volume_charge > 0) {
                    $derived_rate = $posted_volume_charge / max(0.0001, $posted_total_volume);
                    $volume_charge = $posted_total_volume * $derived_rate;
                } elseif (class_exists('KIT_Deliveries')) {
                    $dir_for_vol = isset($data['direction_id']) ? intval($data['direction_id']) : 0;
                    $origin_for_vol = isset($data['origin_country_id']) ? intval($data['origin_country_id']) : 0;
                    $tier_rate = KIT_Deliveries::lookup_volume_rate_per_m3($dir_for_vol, $origin_for_vol, $posted_total_volume);
                    if ($tier_rate !== null && $tier_rate > 0) {
                        $volume_charge = $posted_total_volume * $tier_rate;
                    } elseif ($snapshot_mass_rate > 0) {
                        // Legacy fallback when no volumetric tier exists (avoid silently zeroing).
                        $volume_charge = $posted_total_volume * $snapshot_mass_rate;
                    }
                } elseif ($snapshot_mass_rate > 0) {
                    $volume_charge = $posted_total_volume * $snapshot_mass_rate;
                }
            }
        }

        // Charge basis
        $charge_basis = $data['charge_basis'] ?? '';
        if (empty($charge_basis)) {
            $epsilon = 0.005;
            if (($mass_charge - $volume_charge) > $epsilon) {
                $charge_basis = 'mass';
            } elseif (($volume_charge - $mass_charge) > $epsilon) {
                $charge_basis = 'volume';
            } else {
                $charge_basis = 'mass';
            }
        }

        return [
            'mass_charge' => $mass_charge,
            'volume_charge' => $volume_charge,
            'charge_basis' => $charge_basis,
            'posted_total_mass' => $posted_total_mass,
            'posted_total_volume' => $posted_total_volume,
            'snapshot_mass_rate' => $snapshot_mass_rate,
            'use_custom_volume_rate' => $use_custom_volume_rate,
            'custom_volume_rate' => $custom_volume_rate,
        ];
    }

    /**
     * Merge non-empty step-1 customer fields from waybill POST into kit_customers.
     * Does not clear existing DB values when POST omits a field (patch-only).
     *
     * @param int   $customer_id
     * @param array $data        Full waybill payload (origin_country, telephone, etc.)
     * @param array $fields      Pre-sanitized strings: customer_name, customer_surname, company_name, cell, address; email_address may be null
     */
    public static function sync_customer_profile_from_waybill_post($customer_id, array $data, array $fields)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0 || !class_exists('KIT_Customers')) {
            return;
        }

        $patch = [];

        $cn = isset($fields['customer_name']) ? trim((string) $fields['customer_name']) : '';
        if ($cn !== '') {
            $patch['name'] = sanitize_text_field($cn);
        }
        $sn = isset($fields['customer_surname']) ? trim((string) $fields['customer_surname']) : '';
        if ($sn !== '') {
            $patch['surname'] = sanitize_text_field($sn);
        }
        $comp = isset($fields['company_name']) ? trim((string) $fields['company_name']) : '';
        if ($comp !== '' && class_exists('KIT_Customers')) {
            $linked = KIT_Customers::resolve_company_id_from_payload(['company_name' => $comp]);
            if ($linked > 0) {
                $patch['company_id'] = $linked;
            }
        }
        $cell = isset($fields['cell']) ? trim((string) $fields['cell']) : '';
        if ($cell !== '') {
            $patch['cell'] = sanitize_text_field($cell);
        }
        $addr = isset($fields['address']) ? trim((string) $fields['address']) : '';
        if ($addr !== '') {
            $patch['address'] = sanitize_textarea_field($addr);
        }
        if (!empty($fields['email_address']) && trim((string) $fields['email_address']) !== '') {
            $patch['email_address'] = sanitize_email(trim((string) $fields['email_address']));
        }

        $origin_country = 0;
        if (isset($data['origin_country']) && (string) $data['origin_country'] !== '') {
            $origin_country = (int) $data['origin_country'];
        } elseif (isset($data['country_id']) && (string) $data['country_id'] !== '') {
            $origin_country = (int) $data['country_id'];
        }
        if ($origin_country > 0) {
            $patch['country_id'] = $origin_country;
        }

        $origin_city = 0;
        if (isset($data['origin_city']) && (string) $data['origin_city'] !== '') {
            $origin_city = (int) $data['origin_city'];
        } elseif (isset($data['city_id']) && (string) $data['city_id'] !== '') {
            $origin_city = (int) $data['city_id'];
        }
        if ($origin_city > 0) {
            $patch['city_id'] = $origin_city;
        }

        $tel = isset($data['telephone']) ? trim((string) $data['telephone']) : '';
        if ($tel !== '') {
            $patch['telephone'] = sanitize_text_field($tel);
        }

        if (empty($patch)) {
            return;
        }

        KIT_Customers::update_customer($customer_id, $patch);
    }

    /**
     * Unified function to save or update a waybill
     * 
     * @param array $data Form data (typically $_POST)
     * @param int|null $waybill_id If provided, updates existing waybill; otherwise creates new
     * @param bool $is_update_mode If true, expects existing waybill to be loaded
     * @return array|WP_Error Success data or error
     */
    public static function save_or_update_waybill($data, $waybill_id = null, $is_update_mode = false)
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $is_create_mode = !$is_update_mode && !$waybill_id;

        // ============================================
        // UPDATE MODE: Load existing waybill
        // ============================================
        $existing = null;
        $waybill_no = null;
        if ($is_update_mode) {
            $waybill_no = isset($data['waybill_no']) && !empty($data['waybill_no']) ? (string)$data['waybill_no'] : null;
            $posted_waybill_id = intval($data['waybill_id'] ?? 0);

            $existing = $waybill_no ? self::getFullWaybillWithItems($waybill_no) : null;

            if (!$existing && $posted_waybill_id) {
                $resolved_no = $wpdb->get_var($wpdb->prepare("SELECT waybill_no FROM {$waybills_table} WHERE id = %d", $posted_waybill_id));
                if ($resolved_no) {
                    $existing = self::getFullWaybillWithItems($resolved_no);
                    if ($existing) {
                        $waybill_no = (string)$resolved_no;
                    }
                }
            }

            if (!$existing) {
                return new WP_Error('not_found', 'Waybill not found.');
            }

            $waybill_id = $existing->waybill['waybill_id'];

            // Check approval status
            $waybill_approval = $existing->waybill['approval'] ?? 'pending';
            if (!KIT_User_Roles::can_edit_approved_waybill($waybill_approval)) {
                return new WP_Error('permission_denied', 'Access denied. You cannot edit approved waybills.');
            }
        }

        // ============================================
        // CREATE MODE: Warehouse handling
        // ============================================
        $is_warehouse = isset($data['pending']) && $data['pending'] == 1;
        if ($is_create_mode && $is_warehouse) {
            $warehouse_delivery_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}kit_deliveries WHERE delivery_reference = 'pending' LIMIT 1");
            $warehouse_direction_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}kit_shipping_directions WHERE origin_country_id = 1 AND destination_country_id = 1 LIMIT 1");
            if ($warehouse_delivery_id) {
                $data['delivery_id'] = $warehouse_delivery_id;
            }
            if ($warehouse_direction_id) {
                $data['direction_id'] = $warehouse_direction_id;
            }
            $data['status'] = 'pending';
            $data['dispatch_date'] = null;
        }

        // ============================================
        // SHARED: Extract customer data
        // ============================================
        $cust_id = isset($data['cust_id']) ? intval($data['cust_id']) : 0;
        $customer_select = isset($data['customer_select']) ? sanitize_text_field($data['customer_select']) : '';
        $customer_name = isset($data['customer_name']) ? sanitize_text_field(trim($data['customer_name'])) : '';
        $customer_surname = isset($data['customer_surname']) ? sanitize_text_field(trim($data['customer_surname'])) : '';
        $company_name = isset($data['company_name']) ? sanitize_text_field(trim($data['company_name'])) : '';
        // Handle email - convert empty string to null
        $email_address = isset($data['email_address']) && trim($data['email_address']) !== ''
            ? sanitize_email(trim($data['email_address']))
            : null;
        $cell = isset($data['cell']) ? sanitize_text_field(trim($data['cell'])) : '';
        $address = isset($data['address']) ? sanitize_textarea_field(trim($data['address'])) : '';
        $destination_city = isset($data['destination_city']) ? intval($data['destination_city']) : 0;

        // ============================================
        // CREATE MODE: Validation
        // ============================================
        if ($is_create_mode) {
            if ($is_warehouse) {
                if (empty($cust_id) && empty($customer_name)) {
                    return new WP_Error('validation_error', 'Customer information is required even for warehouse items.');
                }
            } else {
                // Use backup field if main field is empty (for delivery card selections)
                $destination_country = isset($data['destination_country']) ? intval($data['destination_country']) : 0;
                if (empty($destination_country) && isset($data['destination_country_backup'])) {
                    $destination_country = intval($data['destination_country_backup']);
                    $data['destination_country'] = $destination_country; // Sync to main field
                }
                if (empty($destination_country)) {
                    return new WP_Error('validation_error', 'Destination country is required for non-warehouse items.');
                }
                if (empty($destination_city)) {
                    return new WP_Error('validation_error', 'Destination city is required for non-warehouse items.');
                }
                if (empty($cust_id) && empty($customer_name)) {
                    return new WP_Error('validation_error', 'Customer information is required.');
                }
            }
        }

        // ============================================
        // SHARED: Calculate charges
        // ============================================
        $charges = self::calculate_waybill_charges($data);
        $mass_charge = $charges['mass_charge'];
        $volume_charge = $charges['volume_charge'];
        $charge_basis = $charges['charge_basis'];
        $posted_total_mass = $charges['posted_total_mass'];
        $posted_total_volume = $charges['posted_total_volume'];
        $snapshot_mass_rate = $charges['snapshot_mass_rate'];
        $use_custom_volume_rate = $charges['use_custom_volume_rate'];
        $custom_volume_rate = $charges['custom_volume_rate'];

        // ============================================
        // SHARED: Customer handling
        // ============================================
        $customer_id = 0;
        $company_id = isset($data['company_id']) ? (int) $data['company_id'] : 0;
        if (isset($data['cust_id']) && intval($data['cust_id']) > 0) {
            $customer_id = intval($data['cust_id']);
        } elseif (isset($data['customer_select']) && intval($data['customer_select']) > 0) {
            $customer_id = intval($data['customer_select']);
        } elseif (isset($data['customer_id']) && intval($data['customer_id']) > 0) {
            $customer_id = intval($data['customer_id']);
        }
        $client_type = strtolower(trim((string) ($data['client_type'] ?? $data['kit_customer_client_type'] ?? '')));
        if ($company_id <= 0 && $client_type === 'business' && !empty($data['company_name']) && class_exists('KIT_Company_Customers')) {
            $company_id = (int) KIT_Company_Customers::ensure_company((string) $data['company_name'], [
                'cell' => $data['cell'] ?? '',
                'telephone' => $data['telephone'] ?? '',
                'email' => $data['email_address'] ?? $data['email'] ?? '',
                'address' => $data['address'] ?? '',
                'vat_number' => $data['vat_number'] ?? '',
                'country_id' => $data['country_id'] ?? $data['origin_country'] ?? null,
                'city_id' => $data['city_id'] ?? $data['origin_city'] ?? null,
            ]);
            // Company is the party; do not also create a person row from company_name.
            if ($company_id > 0 && $customer_id <= 0) {
                $customer_id = 0;
            }
        }

        // Create new customer if needed (CREATE mode only) — individuals only
        $new_customer_created_in_request = false;
        if ($company_id <= 0 && $is_create_mode && (($customer_id <= 0 && $customer_select === 'new') || (empty($cust_id) && $customer_select === 'new'))) {
            // Customer form uses origin_* field names; fall back to country_id/city_id for compatibility.
            $country_raw = isset($data['country_id']) ? $data['country_id'] : (isset($data['origin_country']) ? $data['origin_country'] : 0);
            $city_raw = isset($data['city_id']) ? $data['city_id'] : (isset($data['origin_city']) ? $data['origin_city'] : 0);
            $country_id = !empty($country_raw) ? intval($country_raw) : 0;
            $city_id = !empty($city_raw) ? intval($city_raw) : 0;
            $new_customer_id = KIT_Customers::save_customer([
                'customer_select' => $customer_select,
                'customer_name' => $customer_name,
                'customer_surname' => $customer_surname,
                'cell' => $cell,
                'address' => $address,
                'company_name' => $company_name,
                'email_address' => $email_address,
                'country_id' => $country_id,
                'city_id' => $city_id,
            ]);
            if (!$new_customer_id || is_wp_error($new_customer_id)) {
                $dup_msg = class_exists('KIT_Customers') ? KIT_Customers::get_last_customer_validation_error() : '';
                return new WP_Error('customer_error', $dup_msg ?: 'Failed to create new customer. If this person or company already exists, pick them from the customer list instead of creating a new record.');
            }
            $customer_id = $new_customer_id;
            $new_customer_created_in_request = true;
        }

        // UPDATE MODE: Handle customer change
        if ($is_update_mode && $customer_id > 0 && $customer_id != $existing->waybill['customer_id']) {
            $customers_table = $wpdb->prefix . 'kit_customers';
            $customer_update_data = [];
            if (!empty($customer_name)) $customer_update_data['name'] = $customer_name;
            if (!empty($customer_surname)) $customer_update_data['surname'] = $customer_surname;
            if (!empty($cell)) $customer_update_data['cell'] = $cell;
            if (!empty($address)) $customer_update_data['address'] = $address;
            if (!empty($email_address)) $customer_update_data['email_address'] = $email_address;
            if (!empty($customer_update_data)) {
                $wpdb->update($customers_table, $customer_update_data, ['cust_id' => $customer_id], array_fill(0, count($customer_update_data), '%s'), ['%d']);
            }
        }

        if ($is_create_mode && $customer_id <= 0) {
            return new WP_Error('validation_error', 'Please select a customer before creating a waybill.');
        }

        // ============================================
        // SHARED: Misc charges
        // ============================================
        $final_misc_data = self::prepareMiscCharges($data);

        // UPDATE MODE: Preserve original misc data if VAT unchanged
        if ($is_update_mode) {
            $original_vat = $existing->waybill['vat_include'] ?? 0;
            $new_vat = isset($data['vat_include']) ? 1 : 0;
            $vat_changed = ($original_vat != $new_vat);

            if (!$vat_changed) {
                $original_misc = maybe_unserialize($existing->waybill['miscellaneous'] ?? '');
                // Edit Waybill sends kit_waybill_misc_ui when the misc table is on the form.
                // If the user removes every misc row, "misc" is absent from POST; without this flag
                // we would wrongly restore misc_items from the DB and undelete their changes.
                $misc_was_posted = isset($data['misc'])
                    || (!empty($data['kit_waybill_misc_ui']) && $is_update_mode);
                if (!$misc_was_posted && is_array($original_misc)) {
                    $final_misc_data['misc_items'] = $original_misc['misc_items'] ?? [];
                    $final_misc_data['misc_total'] = isset($original_misc['misc_total']) ? floatval($original_misc['misc_total']) : 0;
                }
                if (empty($new_vat) && is_array($original_misc) && isset($original_misc['others']['international_price_rands'])) {
                    $final_misc_data['others']['international_price_rands'] = $original_misc['others']['international_price_rands'];
                    $final_misc_data['others']['usd_to_zar_rate_used'] = $original_misc['others']['usd_to_zar_rate_used'] ?? null;
                }
                if (is_array($original_misc) && isset($original_misc['others']['waybill_description'])) {
                    if (!isset($data['waybill_description']) || empty($data['waybill_description'])) {
                        $final_misc_data['others']['waybill_description'] = $original_misc['others']['waybill_description'];
                    }
                }
            }
        }

        // Store snapshot rates
        $snapshot_volume_rate = 0.0;
        if ($use_custom_volume_rate && $custom_volume_rate > 0) {
            $snapshot_volume_rate = $custom_volume_rate;
        } elseif ($posted_total_volume > 0 && isset($data['volume_charge'])) {
            $posted_volume_charge = floatval(str_replace(',', '.', $data['volume_charge']));
            if ($posted_volume_charge > 0) {
                $snapshot_volume_rate = $posted_volume_charge / max(0.0001, $posted_total_volume);
            }
        }
        if ($posted_total_mass > 0 && $mass_charge > 0) {
            $snapshot_mass_rate = $mass_charge / max(0.0001, $posted_total_mass);
        }

        if (!isset($final_misc_data['others']) || !is_array($final_misc_data['others'])) {
            $final_misc_data['others'] = [];
        }
        $final_misc_data['others']['used_charge_basis'] = $charge_basis;
        $final_misc_data['others']['use_custom_volume_rate'] = $use_custom_volume_rate ? 1 : 0;
        $final_misc_data['others']['custom_volume_rate_per_m3'] = $custom_volume_rate;
        $final_misc_data['others']['volume_rate_used'] = $snapshot_volume_rate;
        $final_misc_data['others']['mass_rate'] = $snapshot_mass_rate > 0 ? $snapshot_mass_rate : ($final_misc_data['others']['mass_rate'] ?? 0);

        $misc_total = $final_misc_data['misc_total'] ?? 0;

        // ============================================
        // SHARED: Waybill items total
        // ============================================
        // Calculate items total from form data (used for both create and update modes)
        // In update mode, items will be saved to DB AFTER waybill is successfully updated
        $waybillItemsTotal = 0;
        if (!empty($data['custom_items']) && is_array($data['custom_items'])) {
            foreach ($data['custom_items'] as $item) {
                if (!empty($item['item_name']) && isset($item['quantity']) && isset($item['unit_price'])) {
                    $quantity = intval($item['quantity']);
                    $unit_price = floatval($item['unit_price']);
                    if ($quantity > 0 && $unit_price >= 0) {
                        $item_total = $quantity * $unit_price;
                        if ($item_total <= 999999.99) {
                            $waybillItemsTotal += $item_total;
                        }
                    }
                }
            }
        }

        // ============================================
        // SHARED: Calculate total using bulletproof calculator
        // ============================================
        require_once plugin_dir_path(__FILE__) . 'bulletproof-calculator.php';

        $vat_include = isset($data['vat_include']) ? (intval($data['vat_include']) ? 1 : 0) : 0;
        // Must use intval (not isset→1): seed/API pass 0 with key present; isset alone treated 0 as "on".
        $include_sad500 = isset($data['include_sad500']) ? (intval($data['include_sad500']) ? 1 : 0) : 0;
        $include_sadc = isset($data['include_sadc']) ? (intval($data['include_sadc']) ? 1 : 0) : 0;
        // Enforce business rule server-side: VAT and SADC are mutually exclusive.
        if ($vat_include) {
            $include_sadc = 0;
        }

        $calculation_params = [
            'mass_charge' => $mass_charge,
            'volume_charge' => $volume_charge,
            'misc_total' => $misc_total,
            'waybill_items_total' => $waybillItemsTotal,
            'charge_basis' => $charge_basis,
            'include_sad500' => $include_sad500,
            'include_sadc' => $include_sadc,
            'include_vat' => $vat_include
        ];

        if (isset($final_misc_data['others']) && is_array($final_misc_data['others']) && empty($vat_include)) {
            if (isset($final_misc_data['others']['international_price_rands']) && is_numeric($final_misc_data['others']['international_price_rands'])) {
                $calculation_params['international_price_override'] = floatval($final_misc_data['others']['international_price_rands']);
            }
        }

        $calculation_breakdown = KIT_Bulletproof_Calculator::calculate_waybill_total($calculation_params);
        $calculated_total = $calculation_breakdown['totals']['final_total'];

        // Extract calculated values for new DB columns
        $calculated_misc_total = floatval($calculation_breakdown['additional_charges']['misc_total'] ?? $misc_total);
        $calculated_sad500_amount = floatval($calculation_breakdown['additional_charges']['sad500'] ?? 0);
        $calculated_sadc_amount = floatval($calculation_breakdown['additional_charges']['sadc'] ?? 0);
        $calculated_international_price = floatval($calculation_breakdown['additional_charges']['international_price'] ?? 0);

        // Calculate border clearing total (10% of waybill items total)
        $calculated_border_clearing_total = $waybillItemsTotal * 0.10;

        // UPDATE MODE: Check for total override and preserve original if unchanged
        $waybillTotal = $calculated_total;
        if ($is_update_mode) {
            $use_override_total = false;
            $override_total = null;
            if (isset($data['enable_total_override']) && $data['enable_total_override'] == 'on' && isset($data['override_total'])) {
                $override_total_value = floatval($data['override_total']);
                if ($override_total_value > 0) {
                    $use_override_total = true;
                    $override_total = $override_total_value;
                }
            }

            $original_total = floatval($existing->waybill['product_invoice_amount'] ?? 0);
            $original_items_total = floatval($existing->waybill['waybill_items_total'] ?? 0);

            $original_misc_total = 0.0;
            if (!empty($existing->waybill['miscellaneous'])) {
                $original_misc = maybe_unserialize($existing->waybill['miscellaneous']);
                if (is_array($original_misc) && isset($original_misc['misc_total'])) {
                    $original_misc_total = floatval($original_misc['misc_total']);
                }
            }

            $values_changed = false;
            if (
                abs(floatval($mass_charge) - floatval($existing->waybill['mass_charge'] ?? 0)) > 0.01 ||
                abs(floatval($volume_charge) - floatval($existing->waybill['volume_charge'] ?? 0)) > 0.01 ||
                abs($misc_total - $original_misc_total) > 0.01 ||
                abs($waybillItemsTotal - $original_items_total) > 0.01 ||
                ($vat_include != ($existing->waybill['vat_include'] ?? 0)) ||
                ($include_sad500 != ($existing->waybill['include_sad500'] ?? 0)) ||
                ($include_sadc != ($existing->waybill['include_sadc'] ?? 0)) ||
                ($charge_basis != ($existing->waybill['charge_basis'] ?? ''))
            ) {
                $values_changed = true;
            }

            if ($use_override_total) {
                $waybillTotal = $override_total;
            } elseif (!$values_changed && abs($calculated_total - $original_total) > 0.01) {
                $waybillTotal = $original_total;
            } else {
                $waybillTotal = $calculated_total;
            }
        }

        // Ensure charge basis aligns
        $epsilon = 0.005;
        if (empty($charge_basis) || $charge_basis === 'auto') {
            if (($mass_charge - $volume_charge) > $epsilon) {
                $charge_basis = 'mass';
            } elseif (($volume_charge - $mass_charge) > $epsilon) {
                $charge_basis = 'volume';
            } else {
                $charge_basis = 'mass';
            }
        }

        $misc_serialized = serialize($final_misc_data);

        // ============================================
        // CREATE MODE: Generate waybill number and resolve direction
        // ============================================
        if ($is_create_mode) {
            // Use provided waybill_no if available (for parcels), otherwise generate
            $waybill_no = isset($data['waybill_no']) && !empty($data['waybill_no'])
                ? (string)$data['waybill_no']
                : self::generate_waybill_number();

            $direction_id_input = isset($data['direction_id']) ? (int)$data['direction_id'] : 0;
            $delivery_id_input = isset($data['delivery_id']) ? (int)$data['delivery_id'] : 0;

            if ($direction_id_input <= 0) {
                $origin_country_id = isset($data['country_id']) ? (int)$data['country_id'] : 0;
                $destination_country_id = isset($data['destination_country']) ? (int)$data['destination_country'] : 0;
                if ($origin_country_id > 0 && $destination_country_id > 0) {
                    $direction_id_input = KIT_Deliveries::get_direction_id($origin_country_id, $destination_country_id);
                    if (!$direction_id_input) {
                        $direction_id_input = KIT_Deliveries::create_direction($origin_country_id, $destination_country_id);
                    }
                }
            }

            if ($direction_id_input <= 0 && $delivery_id_input > 0) {
                $resolved = (int) $wpdb->get_var($wpdb->prepare("SELECT direction_id FROM {$wpdb->prefix}kit_deliveries WHERE id = %d", $delivery_id_input));
                if ($resolved > 0) {
                    $direction_id_input = $resolved;
                }
            }

            if ($direction_id_input <= 0) {
                $fallback_dir = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}kit_shipping_directions WHERE origin_country_id = 1 AND destination_country_id = 1 LIMIT 1");
                if ($fallback_dir > 0) {
                    $direction_id_input = $fallback_dir;
                }
            }

            if ($direction_id_input <= 0) {
                return new WP_Error('validation_error', 'Could not determine shipping direction.');
            }

            // Determine city_id
            $city_id = 9; // Default
            if ($destination_city && $destination_city > 0) {
                $city_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}kit_operating_cities WHERE id = %d", $destination_city));
                if ($city_exists > 0) {
                    $city_id = (int)$destination_city;
                }
            }
            if ($city_id == 9 && $customer_id > 0) {
                $customer_city_id = $wpdb->get_var($wpdb->prepare("SELECT city_id FROM {$wpdb->prefix}kit_customers WHERE cust_id = %d", $customer_id));
                if ($customer_city_id && $customer_city_id > 0) {
                    $city_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}kit_operating_cities WHERE id = %d", $customer_city_id));
                    if ($city_exists > 0) {
                        $city_id = (int)$customer_city_id;
                    }
                }
            }
            if (empty($city_id) || $city_id <= 0) {
                $city_id = 9;
            }

            $posted_invoice = '';
            if (!empty($data['product_invoice_number'])) {
                $posted_invoice = sanitize_text_field(trim((string) $data['product_invoice_number']));
            }
            if ($posted_invoice === '' && !empty($data['custom_items']) && is_array($data['custom_items'])) {
                foreach ($data['custom_items'] as $item) {
                    if (!empty($item['client_invoice'])) {
                        $posted_invoice = sanitize_text_field(trim((string) $item['client_invoice']));
                        break;
                    }
                }
            }
            if ($posted_invoice !== '') {
                $product_invoice_number = $posted_invoice;
            } else {
                $product_invoice_number = self::generate_product_invoice_number();
            }
        } else {
            // UPDATE MODE: Use existing values as base
            $direction_id_input = $data['direction_id'] ?? $existing->waybill['direction_id'];
            $delivery_id_input = $data['delivery_id'] ?? $existing->waybill['delivery_id'];
            $waybill_no = $existing->waybill['waybill_no'];
            $product_invoice_number = $existing->waybill['product_invoice_number'];

            // Allow updating city_id from destination_city selection when editing
            $city_id = $existing->waybill['city_id'] ?? 9;
            $posted_destination_city = isset($data['destination_city']) ? intval($data['destination_city']) : 0;
            if ($posted_destination_city > 0) {
                $city_exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}kit_operating_cities WHERE id = %d",
                        $posted_destination_city
                    )
                );
                if ($city_exists > 0) {
                    $city_id = $posted_destination_city;
                }
            }
        }

        // ============================================
        // SHARED: Prepare waybill description
        // ============================================
        $waybill_description = '';
        if (isset($data['waybill_description']) && !empty(trim($data['waybill_description']))) {
            $waybill_description = sanitize_textarea_field(trim($data['waybill_description']));
        } elseif ($is_update_mode && isset($final_misc_data['others']['waybill_description'])) {
            $waybill_description = sanitize_textarea_field(trim($final_misc_data['others']['waybill_description']));
        } elseif ($is_update_mode && !empty($existing->waybill['description'])) {
            $waybill_description = $existing->waybill['description'];
        }

        // ============================================
        // SHARED: Resolve delivery_id (warehouse must never keep a truck id from POST)
        // ============================================
        if ($is_create_mode) {
            $delivery_id_for_db = (int) $delivery_id_input;
            if (!empty($is_warehouse)) {
                $wid = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}kit_deliveries WHERE delivery_reference = 'pending' LIMIT 1");
                if ($wid > 0) {
                    $delivery_id_for_db = $wid;
                }
            } elseif ($delivery_id_for_db <= 0) {
                // Legacy fallback when no delivery was posted (non-warehouse should normally validate earlier)
                $delivery_id_for_db = 1;
            }
        } else {
            $delivery_id_for_db = (int) ($delivery_id_input ?: ($existing->waybill['delivery_id'] ?? 0));
        }

        // ============================================
        // SHARED: Prepare waybill data array
        // ============================================
        $waybill_data = [
            'description' => $waybill_description,
            'direction_id' => (int)$direction_id_input,
            'delivery_id' => $delivery_id_for_db,
            'customer_id' => $customer_id,
            'company_id' => $company_id > 0 ? $company_id : null,
            'city_id' => (int)$city_id,
            'product_invoice_amount' => (float)$waybillTotal,
            'waybill_items_total' => (float)$waybillItemsTotal,
            // New calculated columns for better performance
            'misc_total' => $calculated_misc_total,
            'border_clearing_total' => $calculated_border_clearing_total,
            'sad500_amount' => $calculated_sad500_amount,
            'sadc_amount' => $calculated_sadc_amount,
            'international_price_rands' => $calculated_international_price,
            'item_length' => (float)($data['item_length'] ?? ($is_update_mode ? $existing->waybill['item_length'] : 0)),
            'item_width' => (float)($data['item_width'] ?? ($is_update_mode ? $existing->waybill['item_width'] : 0)),
            'item_height' => (float)($data['item_height'] ?? ($is_update_mode ? $existing->waybill['item_height'] : 0)),
            'total_mass_kg' => (float)($data['total_mass_kg'] ?? ($is_update_mode ? $existing->waybill['total_mass_kg'] : 0)),
            'total_volume' => (float)$posted_total_volume,
            'mass_charge' => (float)$mass_charge,
            'volume_charge' => (float)$volume_charge,
            'charge_basis' => $charge_basis,
            'miscellaneous' => !empty($misc_serialized) ? $misc_serialized : '',
            'include_sad500' => $include_sad500,
            'include_sadc' => $include_sadc,
            'vat_include' => $vat_include,
        ];

        if ($is_create_mode) {
            // Explicitly set waybill_no and parcel_id to prevent column mapping issues
            $waybill_data['waybill_no'] = (string) $waybill_no;
            $waybill_data['parcel_id'] = null; // Must be NULL on create; set later only for parcel waybills
            // warehouse is BOOLEAN (TINYINT(1)): 1 = in warehouse, 0/NULL = not in warehouse
            // UI uses `pending`; Google Sheet seed and API may set `warehouse` without `pending`.
            $waybill_data['warehouse'] = $is_warehouse
                ? 1
                : ((isset($data['warehouse']) && ($data['warehouse'] == 1 || $data['warehouse'] === '1')) ? 1 : 0);
            $waybill_data['product_invoice_number'] = $product_invoice_number;
            $waybill_data['tracking_number'] = 'TRK-' . strtoupper(wp_generate_password(8, false));
            if (!empty($data['_skip_google_sync']) && isset($data['created_by']) && (int) $data['created_by'] > 0) {
                $waybill_data['created_by'] = (int) $data['created_by'];
                $waybill_data['last_updated_by'] = (isset($data['last_updated_by']) && (int) $data['last_updated_by'] > 0)
                    ? (int) $data['last_updated_by']
                    : (int) $data['created_by'];
                if (isset($data['approval_userid']) && (int) $data['approval_userid'] > 0) {
                    $waybill_data['approval_userid'] = (int) $data['approval_userid'];
                }
            } else {
                $waybill_data['created_by'] = get_current_user_id();
                $waybill_data['last_updated_by'] = get_current_user_id();
            }
            if (! empty($is_warehouse)) {
                $waybill_data['status'] = 'pending';
            } else {
                $delivery_status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}kit_deliveries WHERE id = %d",
                    $delivery_id_for_db
                ));
                $waybill_data['status'] = class_exists('KIT_Deliveries')
                    ? KIT_Deliveries::map_delivery_status_to_waybill_status((string) $delivery_status)
                    : 'assigned';
            }
            $waybill_data['created_at'] = current_time('mysql');
            $waybill_data['last_updated_at'] = current_time('mysql');
        } else {
            $waybill_data['approval'] = $data['approval'] ?? $existing->waybill['approval'];
            $waybill_data['approval_userid'] = $data['approval_userid'] ?? $existing->waybill['approval_userid'];
            // warehouse is BOOLEAN (TINYINT(1)): 1 = in warehouse, 0/NULL = not in warehouse
            if (isset($data['warehouse'])) {
                $waybill_data['warehouse'] = ($data['warehouse'] == 1 || $data['warehouse'] === '1') ? 1 : 0;
            } elseif (isset($existing->waybill['warehouse'])) {
                // Preserve existing warehouse value
                $waybill_data['warehouse'] = $existing->waybill['warehouse'];
            } else {
                // Default to 0 for non-warehouse
                $waybill_data['warehouse'] = 0;
            }
            $waybill_data['last_updated_by'] = get_current_user_id();
            $waybill_data['last_updated_at'] = current_time('mysql');
        }

        // ============================================
        // DATABASE OPERATION
        // ============================================
        if ($is_create_mode) {
            $inserted = $wpdb->insert($waybills_table, $waybill_data);
            if (!$inserted) {
                return new WP_Error('db_error', 'Waybill insert failed: ' . $wpdb->last_error);
            }
            $waybill_id = $wpdb->insert_id;

            $booking_req_id = isset($_POST['kit_booking_request_id']) ? (int) $_POST['kit_booking_request_id'] : (isset($data['kit_booking_request_id']) ? (int) $data['kit_booking_request_id'] : 0);
            if ($booking_req_id > 0 && class_exists('KIT_Booking_Requests')) {
                KIT_Booking_Requests::mark_converted($booking_req_id, (int) $waybill_id);
            }

            // Save items
            if (!empty($data['custom_items'])) {
                self::save_waybill_items($data['custom_items'], $waybill_no, $waybill_id, $vat_include);
            }
            if (empty($data['_skip_google_sync']) && class_exists('Courier_Google_Sheets_Sync')) {
                $w = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$waybills_table} WHERE id = %d", $waybill_id), OBJECT);
                if ($w) {
                    Courier_Google_Sheets_Sync::sync_waybill($w, true);
                    Courier_Google_Sheets_Sync::sync_waybill_items((string) ($w->waybill_no ?? $waybill_no), $data['custom_items'] ?? []);
                }
            }
        } else {
            // Generate QR code
            $qr_code_data = self::generate_qr_code_data($waybill_no);
            if (!empty($qr_code_data)) {
                $waybill_data['qr_code_data'] = $qr_code_data;
            }

            $update_result = $wpdb->update($waybills_table, $waybill_data, ['id' => $waybill_id], null, ['%d']);
            if ($update_result === false) {
                return new WP_Error('db_error', 'Failed to update waybill: ' . $wpdb->last_error);
            }

            // Update delivery table
            if ($delivery_id_input) {
                $deliveries_table = $wpdb->prefix . 'kit_deliveries';
                $delivery_data = [];
                $delivery_formats = [];

                if (isset($data['destination_city']) && !empty($data['destination_city'])) {
                    $delivery_data['destination_city_id'] = intval($data['destination_city']);
                    $delivery_formats[] = '%d';
                }
                if (isset($data['truck_number'])) {
                    $delivery_data['truck_number'] = sanitize_text_field($data['truck_number']);
                    $delivery_formats[] = '%s';
                }
                if (isset($data['dispatch_date'])) {
                    $dispatch_date = sanitize_text_field($data['dispatch_date']);
                    if (!empty($dispatch_date)) {
                        $delivery_data['dispatch_date'] = $dispatch_date;
                        $delivery_formats[] = '%s';
                    }
                }
                if (isset($data['truck_driver'])) {
                    $driver_id = !empty($data['truck_driver']) ? intval($data['truck_driver']) : null;
                    if ($driver_id !== null && $driver_id > 0) {
                        $delivery_data['driver_id'] = $driver_id;
                        $delivery_formats[] = '%d';
                    } elseif ($driver_id === null || $driver_id == 0) {
                        $delivery_data['driver_id'] = null;
                        $delivery_formats[] = '%d';
                    }
                }
                if (!empty($delivery_data)) {
                    $wpdb->update($deliveries_table, $delivery_data, ['id' => $delivery_id_input], $delivery_formats, ['%d']);
                }
            }

            // Update direction table
            if ($direction_id_input && (isset($data['origin_country']) || isset($data['destination_country']))) {
                $directions_table = $wpdb->prefix . 'kit_shipping_directions';
                $direction_data = [];
                if (isset($data['origin_country']) && !empty($data['origin_country'])) {
                    $direction_data['origin_country_id'] = intval($data['origin_country']);
                }
                if (isset($data['destination_country']) && !empty($data['destination_country'])) {
                    $direction_data['destination_country_id'] = intval($data['destination_country']);
                }
                if (!empty($direction_data)) {
                    $wpdb->update($directions_table, $direction_data, ['id' => $direction_id_input], array_fill(0, count($direction_data), '%d'), ['%d']);
                }
            }

            // ✅ FIXED: Update waybill items AFTER waybill is successfully saved
            // This prevents data loss if waybill update fails
            if (!empty($data['custom_items']) && is_array($data['custom_items'])) {
                $vat_include = isset($data['vat_include']) ? (intval($data['vat_include']) ? 1 : 0) : 0;
                $updated_items_total = self::updateWaybillItems($data['custom_items'], $waybill_no);

                // Verify items total matches what we calculated (with tolerance for floating point)
                if (abs($updated_items_total - $waybillItemsTotal) > 0.01) {
                    // If there's a mismatch, update the waybill with the actual items total from DB
                    // This ensures consistency between calculated and stored values
                    $update_items_result = $wpdb->update(
                        $waybills_table,
                        ['waybill_items_total' => (float)$updated_items_total],
                        ['id' => $waybill_id],
                        ['%f'],
                        ['%d']
                    );

                    // Log mismatch for debugging (only if significant difference)
                    if (abs($updated_items_total - $waybillItemsTotal) > 1.00) {
                        error_log(sprintf(
                            'Waybill %s items total mismatch: calculated %.2f, actual %.2f',
                            $waybill_no,
                            $waybillItemsTotal,
                            $updated_items_total
                        ));
                    }
                }
            } elseif (empty($data['custom_items']) || !is_array($data['custom_items'])) {
                // If no items provided, delete existing items to keep data consistent
                self::deleteWaybillItems($waybill_no);
                if (class_exists('Courier_Google_Sheets_Sync')) {
                    Courier_Google_Sheets_Sync::sync_waybill_items_delete($waybill_no);
                }
            }
            if (class_exists('Courier_Google_Sheets_Sync')) {
                $w = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$waybills_table} WHERE id = %d", $waybill_id), OBJECT);
                if ($w) {
                    Courier_Google_Sheets_Sync::sync_waybill($w, false);
                    Courier_Google_Sheets_Sync::sync_waybill_items((string) ($w->waybill_no ?? $waybill_no), $data['custom_items'] ?? []);
                }
            }
        }

        // ============================================
        // POST-PROCESSING
        // ============================================
        if ($customer_id > 0 && !$new_customer_created_in_request) {
            self::sync_customer_profile_from_waybill_post($customer_id, $data, [
                'customer_name' => $customer_name,
                'customer_surname' => $customer_surname,
                'company_name' => $company_name,
                'cell' => $cell,
                'address' => $address,
                'email_address' => $email_address,
            ]);
        }

        if ($is_create_mode) {
            // Generate QR code
            $qr_code_data = self::generate_qr_code_data($waybill_no);
            if (!empty($qr_code_data)) {
                $wpdb->update($waybills_table, ['qr_code_data' => $qr_code_data], ['id' => $waybill_id], ['%s'], ['%d']);
            }

            return [
                'id' => $waybill_id,
                'waybill_no' => $waybill_no,
                'success' => true,
                'message' => 'Waybill created successfully'
            ];
        } else {
            return [
                'id' => $waybill_id,
                'waybill_no' => $waybill_no,
                'success' => true,
                'message' => 'Waybill updated successfully'
            ];
        }
    }

    /**
     * Save parcel (multiple waybills under one parcel)
     * 
     * @param array $data Form data containing 'waybills' array
     * @return array|WP_Error Success data or error
     */
    public static function save_parcel($data)
    {
        global $wpdb;

        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $parcels_table = $wpdb->prefix . 'kit_parcels';
        $waybills_array = $data['waybills'] ?? [];

        // Require at least 2 waybills for a parcel
        // Single waybill should use regular waybill table (save_waybill), not parcel
        if (empty($waybills_array) || !is_array($waybills_array) || count($waybills_array) < 2) {
            return new WP_Error('invalid_data', 'Parcels require at least 2 waybills. Single waybill should use regular waybill table.');
        }

        // Get customer_id from step 1 (shared across all waybills)
        // Handle both cust_id and customer_select (same logic as save_or_update_waybill)
        $cust_id = isset($data['cust_id']) ? intval($data['cust_id']) : 0;
        $customer_select = isset($data['customer_select']) ? sanitize_text_field($data['customer_select']) : '';

        // Get customer details for new customer creation
        $customer_name = isset($data['customer_name']) ? sanitize_text_field(trim($data['customer_name'])) : '';
        $customer_surname = isset($data['customer_surname']) ? sanitize_text_field(trim($data['customer_surname'])) : '';
        $cell = isset($data['cell']) ? sanitize_text_field(trim($data['cell'])) : '';
        $address = isset($data['address']) ? sanitize_text_field(trim($data['address'])) : '';
        // Handle email - convert empty string to null
        $email_address = isset($data['email_address']) && trim($data['email_address']) !== ''
            ? sanitize_email(trim($data['email_address']))
            : null;
        $company_name = isset($data['company_name']) ? sanitize_text_field(trim($data['company_name'])) : '';

        // Determine customer_id (check multiple possible sources)
        $customer_id = 0;

        if ($cust_id > 0) {
            $customer_id = $cust_id;
        } elseif (!empty($customer_select) && $customer_select !== 'new' && intval($customer_select) > 0) {
            $customer_id = intval($customer_select);
        } elseif (isset($data['customer_id']) && intval($data['customer_id']) > 0) {
            $customer_id = intval($data['customer_id']);
        }

        // Create new customer if needed
        if (($customer_id <= 0 && $customer_select === 'new')) {
            // Customer form uses origin_* field names; fall back to country_id/city_id for compatibility.
            $country_raw = isset($data['country_id']) ? $data['country_id'] : (isset($data['origin_country']) ? $data['origin_country'] : 0);
            $city_raw = isset($data['city_id']) ? $data['city_id'] : (isset($data['origin_city']) ? $data['origin_city'] : 0);
            $country_id = !empty($country_raw) ? intval($country_raw) : 0;
            $city_id = !empty($city_raw) ? intval($city_raw) : 0;

            $new_customer_id = KIT_Customers::save_customer([
                'customer_select' => $customer_select,
                'customer_name' => $customer_name,
                'customer_surname' => $customer_surname,
                'cell' => $cell,
                'address' => $address,
                'company_name' => $company_name,
                'email_address' => $email_address,
                'country_id' => $country_id,
                'city_id' => $city_id,
            ]);

            if (!$new_customer_id || is_wp_error($new_customer_id)) {
                $dup_msg = class_exists('KIT_Customers') ? KIT_Customers::get_last_customer_validation_error() : '';
                return new WP_Error('customer_error', $dup_msg ?: 'Failed to create new customer. If this person or company already exists, pick them from the customer list instead of creating a new record.');
            }
            $customer_id = $new_customer_id;
        }

        if (!$customer_id || $customer_id <= 0) {
            return new WP_Error('missing_customer', 'Customer ID is required. Please select a customer or create a new one.');
        }

        // Generate parcel number (use first waybill's number as base)
        // Extract numeric base if alphanumeric (e.g., "4000a" -> "4000")
        if (isset($data['waybill_no']) && !empty($data['waybill_no'])) {
            $base_waybill_no = preg_match('/^(\d+)/', (string)$data['waybill_no'], $matches)
                ? $matches[1]
                : (string)$data['waybill_no'];
        } else {
            $base_waybill_no = self::generate_waybill_number();
        }
        $parcel_no = (string)$base_waybill_no;

        // Get description from step 1
        $description = isset($data['waybill_description']) ? sanitize_textarea_field($data['waybill_description']) : '';

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // 1. Create parcel record
            $parcel_data = [
                'parcel_no' => $parcel_no,
                'description' => $description,
                'customer_id' => $customer_id,
                'total_waybills' => count($waybills_array),
                'total_amount' => 0.00, // Will be calculated
                'status' => 'pending',
                'created_by' => get_current_user_id(),
                'last_updated_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
                'last_updated_at' => current_time('mysql')
            ];

            $inserted = $wpdb->insert($parcels_table, $parcel_data);
            if (!$inserted) {
                throw new Exception('Failed to create parcel: ' . $wpdb->last_error);
            }

            $parcel_id = $wpdb->insert_id;
            $total_amount = 0.00;
            $saved_waybills = [];

            // 2. Save each waybill in the array
            foreach ($waybills_array as $index => $waybill_data) {
                // Merge shared data (from step 1) with waybill-specific data
                $full_waybill_data = array_merge($data, $waybill_data);

                // Handle custom_items - they should be in waybill_data with index
                if (isset($data['custom_items']) && is_array($data['custom_items']) && isset($data['custom_items'][$index])) {
                    $full_waybill_data['custom_items'] = $data['custom_items'][$index];
                } elseif (isset($waybill_data['custom_items'])) {
                    $full_waybill_data['custom_items'] = $waybill_data['custom_items'];
                }

                // Get destination info from step 4 (first waybill) or from waybill data
                if ($index === 0) {
                    // First waybill: use step 4 data
                    $full_waybill_data['destination_city'] = $data['destination_city'] ?? '';
                    $full_waybill_data['delivery_id'] = $data['delivery_id'] ?? '';
                    $full_waybill_data['direction_id'] = $data['direction_id'] ?? '';
                } else {
                    // Subsequent waybills: use data from waybill array or copy from previous
                    if (empty($waybill_data['destination_city']) && !empty($waybill_data['different_city'])) {
                        $full_waybill_data['destination_city'] = $waybill_data['destination_city'] ?? '';
                    } else {
                        $full_waybill_data['destination_city'] = $data['destination_city'] ?? '';
                    }

                    $full_waybill_data['direction_id'] = $waybill_data['direction_id'] ?? $data['direction_id'] ?? '';
                    $full_waybill_data['delivery_id'] = $waybill_data['delivery_id'] ?? $data['delivery_id'] ?? '';
                }

                // Generate unique waybill number for this waybill
                if (isset($waybill_data['waybill_no']) && !empty($waybill_data['waybill_no'])) {
                    $waybill_no = (string)$waybill_data['waybill_no'];
                } else {
                    // Generate waybill number with suffix: 4000a, 4000b, 4000c, etc.
                    $waybill_no = self::generate_child_waybill_number($parcel_no, $index);
                }

                // Set the waybill number in the data
                $full_waybill_data['waybill_no'] = $waybill_no;

                // Save the waybill using existing save function
                $result = self::save_or_update_waybill($full_waybill_data, null, false);

                if (is_wp_error($result)) {
                    throw new Exception('Failed to save waybill #' . ($index + 1) . ': ' . $result->get_error_message());
                }

                // Get the saved waybill ID
                $saved_waybill_no = $result['waybill_no'] ?? $waybill_no;
                $saved_waybill_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $waybills_table WHERE waybill_no = %s",
                    $saved_waybill_no
                ));

                if (!$saved_waybill_id) {
                    throw new Exception('Failed to retrieve saved waybill ID for waybill #' . $saved_waybill_no);
                }

                // Link waybill to parcel
                $wpdb->update(
                    $waybills_table,
                    ['parcel_id' => $parcel_id],
                    ['id' => $saved_waybill_id],
                    ['%d'],
                    ['%d']
                );

                // Get waybill total for parcel total
                $waybill_total = $wpdb->get_var($wpdb->prepare(
                    "SELECT product_invoice_amount FROM $waybills_table WHERE id = %d",
                    $saved_waybill_id
                ));
                $total_amount += floatval($waybill_total ?? 0);

                $saved_waybills[] = [
                    'waybill_no' => $saved_waybill_no,
                    'waybill_id' => $saved_waybill_id
                ];
            }

            // 3. Update parcel with total amount
            $wpdb->update(
                $parcels_table,
                ['total_amount' => $total_amount],
                ['id' => $parcel_id],
                ['%f'],
                ['%d']
            );

            // Commit transaction
            $wpdb->query('COMMIT');

            return [
                'parcel_no' => $parcel_no,
                'parcel_id' => $parcel_id,
                'waybills' => $saved_waybills,
                'total_amount' => $total_amount,
                'success' => true,
                'message' => sprintf('Successfully created parcel with %d waybills', count($saved_waybills))
            ];
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            error_log('Parcel save error: ' . $e->getMessage());
            return new WP_Error('parcel_error', $e->getMessage());
        }
    }

    /**
     * Get parcel data with all waybills
     * 
     * @param int $parcel_id The parcel ID
     * @return array|null Parcel data or null if not found
     */
    public static function get_parcel($parcel_id)
    {
        global $wpdb;

        $parcels_table = $wpdb->prefix . 'kit_parcels';
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $customers_table = $wpdb->prefix . 'kit_customers';

        // Get parcel data
        $parcel = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.*, 
                        CONCAT(cust.name, ' ', cust.surname) AS customer_name,
                        cust.cell AS customer_cell,
                        cust.email_address AS customer_email,
                        cust.address AS customer_address,
                        cust.company_name AS customer_company
                 FROM $parcels_table p
                 LEFT JOIN $customers_table cust ON p.customer_id = cust.cust_id
                 WHERE p.id = %d
                 LIMIT 1",
                $parcel_id
            ),
            ARRAY_A
        );

        if (!$parcel) {
            return null;
        }

        // Get all waybills in this parcel
        $waybills = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT w.*, 
                        w.warehouse,
                        w.delivery_id,
                        CONCAT(c.name, ' ', c.surname) AS customer_name
                 FROM $waybills_table w
                 LEFT JOIN $customers_table c ON w.customer_id = c.cust_id
                 WHERE w.parcel_id = %d
                 ORDER BY w.waybill_no ASC",
                $parcel_id
            ),
            ARRAY_A
        );

        $parcel['waybills'] = $waybills ? $waybills : [];
        $parcel['waybill_count'] = count($parcel['waybills']);

        // Recalculate total_amount from actual waybills to ensure accuracy
        $calculated_total = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(product_invoice_amount), 0) FROM $waybills_table WHERE parcel_id = %d",
            $parcel_id
        ));

        $calculated_total = floatval($calculated_total ?: 0);

        // Store the original stored total for comparison
        $stored_total = floatval($parcel['total_amount'] ?? 0);

        // Update the parcel array with the recalculated total
        $parcel['total_amount'] = $calculated_total;

        // Update the database if the stored total differs from calculated total
        if (abs($stored_total - $calculated_total) > 0.01) {
            $wpdb->update(
                $parcels_table,
                [
                    'total_amount' => $calculated_total,
                    'last_updated_at' => current_time('mysql'),
                ],
                ['id' => $parcel_id],
                ['%f', '%s'],
                ['%d']
            );
        }

        return $parcel;
    }

    public static function save_waybill($data)
    {
        // Use unified function for create mode
        return self::save_or_update_waybill($data, null, false);
    }

    public  static function getAllWaybills()
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $deliveries_table = $wpdb->prefix . 'kit_deliveries';
        $customers_table = $wpdb->prefix . 'kit_customers';
        $directions_table = $wpdb->prefix . 'kit_shipping_directions';
        $countries_table = $wpdb->prefix . 'kit_operating_countries';
        $cities_table = $wpdb->prefix . 'kit_operating_cities';
        $drivers_table = $wpdb->prefix . 'kit_drivers';

        // Get all waybills (parcels are handled via parcel_id, not consolidated_waybill_id)
        $query = "
            SELECT 
                w.id,
                w.waybill_no,
                w.description,
                w.customer_id,
                w.direction_id,
                w.delivery_id,
                w.city_id,
                w.product_invoice_number,
                w.product_invoice_amount,
                w.waybill_items_total,
                w.item_length,
                w.item_width,
                w.item_height,
                w.total_mass_kg,
                w.total_volume,
                w.mass_charge,
                w.volume_charge,
                w.charge_basis,
                w.miscellaneous,
                w.include_sad500,
                w.include_sadc,
                w.vat_include,
                w.tracking_number,
                w.status,
                w.approval,
                w.warehouse,
                w.parcel_id,
                w.created_by,
                w.last_updated_by,
                w.created_at,
                w.last_updated_at,
                d.status as delivery_status,
                d.delivery_reference,
                d.dispatch_date,
                d.truck_number,
                dr.name as driver_name,
                c.name as customer_name,
                c.surname as customer_surname,
                c.cell as customer_cell,
                c.email_address as customer_email,
                c.address as customer_address,
                COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) as company_name,
                c.country_id as customer_country_id,
                dir.description as route_description,
                origin_country.country_name as origin_country_name,
                dest_country.country_name as destination_country_name,
                dest_city.city_name as customer_city,
                CASE 
                    WHEN w.warehouse = 1 THEN 'warehouse'
                    ELSE 'waybills'
                END as source_table
            FROM $waybills_table w
            LEFT JOIN $deliveries_table d ON w.delivery_id = d.id
            LEFT JOIN $drivers_table dr ON d.driver_id = dr.id
            LEFT JOIN $customers_table c ON w.customer_id = c.cust_id
            LEFT JOIN {$wpdb->prefix}kit_company_customers co ON w.company_id = co.company_id
            LEFT JOIN {$wpdb->prefix}kit_company_customers cust_co ON c.company_id = cust_co.company_id
            LEFT JOIN $directions_table dir ON d.direction_id = dir.id
            LEFT JOIN $countries_table origin_country ON dir.origin_country_id = origin_country.id
            LEFT JOIN $countries_table dest_country ON dir.destination_country_id = dest_country.id
            LEFT JOIN $cities_table dest_city ON w.city_id = dest_city.id
            ORDER BY (w.waybill_no + 0) DESC, w.waybill_no DESC, w.id DESC";

        $all_waybills = $wpdb->get_results($query);

        // Match SQL: (waybill_no + 0) DESC, then string id, then internal id
        usort($all_waybills, static function ($a, $b) {
            $aNo = (string) ($a->waybill_no ?? '');
            $bNo = (string) ($b->waybill_no ?? '');
            $aNum = (float) ($aNo + 0);
            $bNum = (float) ($bNo + 0);
            if ($aNum !== $bNum) {
                return $bNum <=> $aNum;
            }
            $strCmp = strcmp($bNo, $aNo);
            if ($strCmp !== 0) {
                return $strCmp;
            }
            return (int) ($b->id ?? 0) <=> (int) ($a->id ?? 0);
        });

        return $all_waybills;
    }
    /**
     * Fetches waybills from the database with optional filters and sorting.
     *
     * @param array $args Optional. Arguments for filtering and sorting. Default empty array.
     * @return array Array of waybill objects.
     */
    public static function get_waybills($args = [])
    {
        global $wpdb;

        $waybills_table   = $wpdb->prefix . 'kit_waybills';
        $customers_table  = $wpdb->prefix . 'kit_customers';
        $deliveries_table = $wpdb->prefix . 'kit_deliveries';
        $directions_table = $wpdb->prefix . 'kit_shipping_directions';
        $countries_table  = $wpdb->prefix . 'kit_operating_countries';
        $users_table      = $wpdb->users;

        $defaults = [
            'number'        => 20,
            'offset'        => 0,
            'orderby'       => 'w.created_at',
            'order'         => 'DESC',
            'fields'        => 'all',
            'status'        => '',
            'approval'      => '',
            'custom_format' => false,
            'search'        => '',
            'pending'   => null,
        ];
        $args = wp_parse_args($args, $defaults);

        // Validate orderby and order
        $allowed_orderby = [
            'w.created_at',
            'w.id',
            'w.status',
            'w.waybill_no',
            'w.approval',
            'c.name',
            'c.surname',
            'u.display_name',
            'dest_city.city_name',
            'dest_country.country_name',
            'dir.description',
            'w.product_invoice_amount'
        ];
        $allowed_order   = ['ASC', 'DESC'];

        if (! in_array($args['orderby'], $allowed_orderby)) {
            $args['orderby'] = 'w.created_at';
        }

        if (! in_array(strtoupper($args['order']), $allowed_order)) {
            $args['order'] = 'DESC';
        }

        // Build select fields
        if ($args['custom_format']) {

            $select_fields = "
                w.id AS waybill_id,
                w.waybill_no,
                w.approval,
                w.approval_userid,
                w.customer_id,
                w.status,
                w.product_invoice_number,
                c.name AS customer_name,
                c.surname AS customer_surname,
                d.destination_city,
                d.origin_country,
                d.destination_country,
                d.delivery_reference,
                u.display_name AS created_by,
                a.user_login AS approved_by_username,
                dir.description AS route_description,
                dest_country.country_name AS destination_country_name,
                dest_city.city_name AS customer_city
                ";
        } else {
            $select_fields = "w.id AS waybill_id,
            w.*,
            w.parcel_id,
            d.delivery_reference,
            c.name AS customer_name, 
            c.surname AS customer_surname,
            u.display_name AS created_by,
            a.user_login AS approved_by_username,
            w.status AS approval_status,
            dir.description AS route_description,
            dest_country.country_name AS destination_country_name,
            dest_city.city_name AS customer_city";
        }



        // Base query
        $query = "SELECT $select_fields
        FROM $waybills_table AS w
        LEFT JOIN $customers_table AS c ON w.customer_id = c.cust_id
        LEFT JOIN $deliveries_table AS d ON w.delivery_id = d.id
        LEFT JOIN $directions_table AS dir ON d.direction_id = dir.id
        LEFT JOIN $countries_table AS dest_country ON dir.destination_country_id = dest_country.id
        LEFT JOIN {$wpdb->prefix}kit_operating_cities AS dest_city ON w.city_id = dest_city.id
        LEFT JOIN $users_table AS u ON w.created_by = u.ID
        LEFT JOIN $users_table AS a ON w.approval_userid = a.ID";

        // WHERE clause
        $where  = [];
        $params = [];

        // Simple status filter
        if (!empty($args['status'])) {
            $where[]  = "w.status = %s";
            $params[] = $args['status'];
        }

        if (! empty($args['approval'])) {
            $where[]  = "w.approval = %s";
            $params[] = $args['approval'];
        }

        // Add search functionality
        if (! empty($args['search'])) {
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';

            // First try simple search to see if we get any results
            $simple_test_query = "SELECT COUNT(*) FROM {$wpdb->prefix}kit_waybills WHERE status NOT IN ('pending') AND (waybill_no LIKE %s OR status LIKE %s)";
            $simple_test_count = $wpdb->get_var($wpdb->prepare($simple_test_query, $search_term, $search_term));

            if ($simple_test_count > 0) {
                // Use full search with all fields
                $where[] = "(w.waybill_no LIKE %s OR 
                            w.status LIKE %s OR
                            CONCAT(COALESCE(c.name, ''), ' ', COALESCE(c.surname, '')) LIKE %s OR 
                            COALESCE(c.name, '') LIKE %s OR 
                            COALESCE(c.surname, '') LIKE %s OR
                            COALESCE(dest_city.city_name, '') LIKE %s OR
                            COALESCE(dir.description, '') LIKE %s OR
                            COALESCE(dest_country.country_name, '') LIKE %s OR
                            COALESCE(u.display_name, '') LIKE %s)";
                $params = array_merge($params, array_fill(0, 9, $search_term));
            } else {
                // Use simple search only
                $where[] = "(w.waybill_no LIKE %s OR w.status LIKE %s)";
                $params = array_merge($params, array_fill(0, 2, $search_term));
            }
        }

        if ($where) {
            $query .= " WHERE " . implode(" AND ", $where);
        }

        // Append ORDER BY (safe because it's whitelisted above)
        $query .= " ORDER BY {$args['orderby']} {$args['order']}";

        // Add LIMIT/OFFSET placeholders
        $query .= " LIMIT %d OFFSET %d";
        $params[] = $args['number'];
        $params[] = $args['offset'];

        // Prepare full query once, with all params
        $prepared_query = $wpdb->prepare($query, ...$params);
        // Run query
        return $wpdb->get_results($prepared_query);
    }

    public static function get_waybill_count($search_term = '')
    {
        global $wpdb;

        // Simple count - count all waybills (parcels are handled via parcel_id)
        if (empty($search_term)) {
            $waybills_table = $wpdb->prefix . 'kit_waybills';

            // Count all waybills
            $total = $wpdb->get_var("SELECT COUNT(*) FROM $waybills_table");

            return (int)$total;
        }
        // For search, try a simpler approach first to avoid JOIN issues
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $search_like = '%' . $wpdb->esc_like($search_term) . '%';

        // First try simple search on waybill_no and status only
        $simple_query = "SELECT COUNT(*) FROM $waybills_table WHERE status NOT IN ('pending') AND (waybill_no LIKE %s OR status LIKE %s)";
        $simple_count = $wpdb->get_var($wpdb->prepare($simple_query, $search_like, $search_like));

        if ($simple_count > 0) {
            // If we found results with simple search, use complex search for more fields
            $customers_table  = $wpdb->prefix . 'kit_customers';
            $deliveries_table = $wpdb->prefix . 'kit_deliveries';
            $directions_table = $wpdb->prefix . 'kit_shipping_directions';
            $countries_table  = $wpdb->prefix . 'kit_operating_countries';
            $users_table      = $wpdb->users;

            $query = "SELECT COUNT(DISTINCT w.id)
                      FROM $waybills_table AS w
                      LEFT JOIN $customers_table AS c ON w.customer_id = c.cust_id
                      LEFT JOIN $deliveries_table AS d ON w.delivery_id = d.id
                      LEFT JOIN $directions_table AS dir ON d.direction_id = dir.id
                      LEFT JOIN $countries_table AS dest_country ON dir.destination_country_id = dest_country.id
                      LEFT JOIN {$wpdb->prefix}kit_operating_cities AS dest_city ON w.city_id = dest_city.id
                      LEFT JOIN $users_table AS u ON w.created_by = u.ID
                      WHERE w.status NOT IN ('pending')";

            $query .= " AND (w.waybill_no LIKE %s OR 
                            w.status LIKE %s OR
                            CONCAT(COALESCE(c.name, ''), ' ', COALESCE(c.surname, '')) LIKE %s OR 
                            COALESCE(c.name, '') LIKE %s OR 
                            COALESCE(c.surname, '') LIKE %s OR
                            COALESCE(dest_city.city_name, '') LIKE %s OR
                            COALESCE(dir.description, '') LIKE %s OR
                            COALESCE(dest_country.country_name, '') LIKE %s OR
                            COALESCE(u.display_name, '') LIKE %s)";

            $params = array_fill(0, 9, $search_like);
            $count = $wpdb->get_var($wpdb->prepare($query, ...$params));
        } else {
            $count = $simple_count;
        }

        return $count ? (int) $count : 0;
    }

    public static function get_recent_waybill_count()
    {
        global $wpdb;
        $date  = date('Y-m-d', strtotime('-7 days'));
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kit_waybills WHERE created_at >= %s",
            $date
        ));
        return $count ? (int) $count : 0;
    }

    public static function get_pending_waybill_count()
    {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kit_waybills WHERE status = %s",
            'pending'
        ));
        return $count ? (int) $count : 0;
    }

    /**
     * Count waybills flagged as warehouse storage (warehouse = 1).
     */
    public static function get_warehouse_waybill_count()
    {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kit_waybills WHERE warehouse = %d",
            1
        ));
        return $count ? (int) $count : 0;
    }

    /**
     * Warehouse-flagged waybills created in the last 7 days.
     */
    public static function get_recent_warehouse_waybill_count()
    {
        global $wpdb;
        $date = date('Y-m-d', strtotime('-7 days'));
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kit_waybills WHERE warehouse = %d AND created_at >= %s",
            1,
            $date
        ));
        return $count ? (int) $count : 0;
    }

    /**
     * Generate a unique waybill number starting from 4000 and incrementing by 1
     * Works with both INT and VARCHAR (extracts numeric base from alphanumeric values)
     */
    public static function generate_waybill_number()
    {
        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';
        $parcels_table = $wpdb->prefix . 'kit_parcels';

        // Get all waybill numbers and extract numeric bases
        // This handles both "4000" and "4000a" formats - extracts "4000" from both
        // For waybills in parcels (4000a, 4000b), we only consider the numeric base (4000)
        $all_waybills = $wpdb->get_col("SELECT waybill_no FROM $waybills_table");

        // Also get parcel numbers
        $all_parcels = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '$parcels_table'") === $parcels_table) {
            $all_parcels = $wpdb->get_col("SELECT parcel_no FROM $parcels_table");
        }

        // Combine both arrays and extract numeric bases
        $all_numbers = array_merge($all_waybills, $all_parcels);

        $max_numeric_base = 0;
        foreach ($all_numbers as $waybill_no) {
            // Extract numeric part from beginning of string (handles "4000", "4000a", "4001b", etc.)
            // For waybills in parcels like "4000a", this extracts "4000" as the base
            if (preg_match('/^(\d+)/', (string)$waybill_no, $matches)) {
                $numeric_base = intval($matches[1]);
                if ($numeric_base > $max_numeric_base) {
                    $max_numeric_base = $numeric_base;
                }
            }
        }

        // Always start from 4000 if no waybills exist, otherwise increment by 1 from the highest
        if ($max_numeric_base < 4000) {
            $next_waybill_no = 4000;
        } else {
            $next_waybill_no = $max_numeric_base + 1;
        }

        // Check if the generated number already exists in BOTH tables, if so, increment until unique
        do {
            $exists_in_waybills = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $waybills_table WHERE waybill_no = %s", (string)$next_waybill_no)
            );

            $exists_in_parcels = false;
            if ($wpdb->get_var("SHOW TABLES LIKE '$parcels_table'") === $parcels_table) {
                $exists_in_parcels = $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM $parcels_table WHERE parcel_no = %s", (string)$next_waybill_no)
                );
            }

            $exists = $exists_in_waybills || $exists_in_parcels;
            if ($exists) {
                $next_waybill_no++;
            }
        } while ($exists);

        return (string)$next_waybill_no; // Return as string for VARCHAR compatibility
    }

    /**
     * Generate child waybill number with suffix (e.g., 4000a, 4000b, 4000c)
     * @param string|int $parent_waybill_no The parent waybill number (e.g., 4000)
     * @param int $index Zero-based index for the child (0 = 'a', 1 = 'b', 2 = 'c', etc.)
     * @return string Child waybill number with suffix
     */
    public static function generate_child_waybill_number($parent_waybill_no, $index = 0)
    {
        // Extract numeric base from parent (handles both "4000" and "4000a" formats)
        $numeric_base = is_numeric($parent_waybill_no)
            ? intval($parent_waybill_no)
            : (preg_match('/^(\d+)/', (string)$parent_waybill_no, $matches) ? intval($matches[1]) : 4000);

        // Generate suffix: a, b, c, d, ... z, then aa, ab, etc.
        $suffix = '';
        if ($index < 26) {
            // Single letter: a-z
            $suffix = chr(97 + $index); // 97 = 'a' in ASCII
        } else {
            // Multiple letters: aa, ab, ac, ... (for more than 26 children)
            $first_letter = chr(96 + floor($index / 26)); // 96 = 'a' - 1
            $second_letter = chr(97 + ($index % 26));
            $suffix = $first_letter . $second_letter;
        }

        return (string)$numeric_base . $suffix;
    }

    /**
     * Generate a unique uniform product_invoice_number
     * Format: INV-YYYYMMDD-XXXXX (sequential number)
     */
    public static function generate_product_invoice_number()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';

        // Get the highest invoice number for today's date
        $date_prefix = date('Ymd');
        $today_invoice_pattern = "INV-{$date_prefix}-";

        // Get the highest sequential number for today
        $max_invoice = $wpdb->get_var($wpdb->prepare(
            "SELECT product_invoice_number FROM $table_name 
            WHERE product_invoice_number LIKE %s 
            ORDER BY CAST(SUBSTRING_INDEX(product_invoice_number, '-', -1) AS UNSIGNED) DESC 
            LIMIT 1",
            $today_invoice_pattern . '%'
        ));

        if ($max_invoice) {
            // Extract the sequential number from the last part
            $parts = explode('-', $max_invoice);
            $last_part = end($parts);
            $next_seq = intval($last_part) + 1;
        } else {
            // First invoice for today starts at 1
            $next_seq = 1;
        }

        // Format as INV-YYYYMMDD-XXXXX (padded to 5 digits for consistency)
        $product_invoice_number = sprintf('%s%05d', $today_invoice_pattern, $next_seq);

        // Check if this invoice number already exists (shouldn't happen, but safety check)
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE product_invoice_number = %s",
            $product_invoice_number
        ));

        // If it exists, increment until unique
        while ($exists) {
            $next_seq++;
            $product_invoice_number = sprintf('%s%05d', $today_invoice_pattern, $next_seq);
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE product_invoice_number = %s",
                $product_invoice_number
            ));
        }

        return $product_invoice_number;
    }

    public static function get_latest_waybill_date()
    {
        global $wpdb;
        $date = $wpdb->get_var(
            "SELECT created_at FROM {$wpdb->prefix}kit_waybills ORDER BY created_at DESC LIMIT 1"
        );

        if (empty($date)) {
            return '';
        }

        return date('M j, Y', strtotime($date));
    }


    public static function kit_get_all_waybills()
    {
        // Get specific fields
        return self::get_waybills(['fields' => 'w.waybill_no, w.product_invoice_number, w.created_at, c.name, c.surname',]);
    }


    public static function RenderTable($data, $args = [])
    {
        $defaults = [
            'fields' => ['waybill_no', 'delivery_id', 'customer_id', 'status'],
            'table_class' => 'min-w-full divide-y divide-gray-200',
            'responsive' => true,
            'actions' => true,
            'show_create_quotation' => false, // New option to show create quotation button
            'status_labels' => [
                'pending' => 'Pending',
                'shipped' => 'Shipped',
                'delivered' => 'Delivered',
                'cancelled' => 'Cancelled'
            ],
            'status_colors' => [
                'pending' => 'bg-yellow-100 text-yellow-800',
                'shipped' => 'bg-blue-100 text-blue-800',
                'delivered' => 'bg-green-100 text-green-800',
                'cancelled' => 'bg-red-100 text-red-800'
            ]
        ];

        $args = wp_parse_args($args, $defaults);

        ob_start();
    ?>
        <div class="overflow-x-auto shadow-sm rounded-lg border border-gray-200">
            <table class="<?php echo esc_attr($args['table_class']); ?>">
                <thead class="bg-gray-50">
                    <tr>
                        <?php foreach ($args['fields'] as $field): ?>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?php echo esc_html(ucfirst(str_replace('_', ' ', $field ?: ''))); ?>
                            </th>
                        <?php endforeach; ?>
                        <?php if ($args['actions']): ?>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($data as $item):

                        $quotation = (object)$item;

                    ?>
                        <tr class="hover:bg-gray-50" data-waybill-id="<?php echo esc_attr($quotation->id); ?>">
                            <?php foreach ($args['fields'] as $field): ?>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="text-xs">
                                        <?php if ($field === 'status'): ?>
                                            <span
                                                class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo esc_attr($args['status_colors'][strtolower($quotation->$field)] ?? 'bg-gray-100 text-gray-800'); ?>">
                                                <?php echo esc_html($args['status_labels'][strtolower($quotation->$field)] ?? ucfirst($quotation->$field)); ?>
                                            </span>
                                        <?php elseif ($field === 'waybill_no'): ?>
                                            <span class="font-medium text-blue-600"><?php echo esc_html($quotation->$field); ?></span>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?php echo date('M d', strtotime($quotation->created_at)); ?></div>
                                        <?php elseif ($field === 'customer_id'): ?>
                                            <span class="font-medium"><?php echo esc_html($quotation->customer_id); ?></span>
                                            <div class="text-xs text-gray-500 truncate max-w-xs"
                                                title="<?php echo esc_attr($quotation->customer_name); ?>">
                                                <?php echo esc_html($quotation->customer_name) . ' ' . esc_html($quotation->surname); ?>
                                            </div>
                                        <?php else: ?>
                                            <?php echo esc_html($quotation->$field); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                            <?php if ($args['actions']): ?>
                                <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                    <div class="flex space-x-2">
                                        <?php if (!$args['quoted_already']): ?>
                                            <a href="<?php echo admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . $quotation->id . '&waybill_atts=view_waybill'); ?>"
                                                class="text-blue-600 hover:text-blue-900" title="View">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                                    stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </a>

                                        <?php else: ?>
                                            <a href="<?php echo plugin_dir_url(__FILE__) . '../pdf-generator.php?waybill_no=' . $quotation->id . '&pdf_nonce=' . wp_create_nonce('pdf_nonce'); ?>"
                                                target="_blank"
                                                class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition flex items-center gap-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                                    stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                                </svg>
                                                Download PASDDF
                                            </a>
                                        <?php endif; ?>
                                        <!--  <a href="<?php echo admin_url('admin.php?page=08600-Waybill-print&waybill_id=' . $quotation->id); ?>"
                                            class="text-indigo-600 hover:text-indigo-900" title="Print" target="_blank">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                                stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                            </svg>
                                        </a> -->
                                        <?php if ($args['show_create_quotation']): ?>
                                            <?php echo KIT_Commons::renderButton('Quote', 'success', 'lg', [
                                                'title' => 'Create Quotation',
                                                'data-waybill-id' => $quotation->id,
                                                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />',
                                                'iconPosition' => 'left',
                                                'gradient' => true
                                            ]); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
            jQuery('.create-quotation').on('click', function(e) {
                e.preventDefault();
                const waybillId = jQuery(this).data('waybill-id');
                const $button = jQuery(this);
                const $row = $button.closest('tr');

                if (!confirm('Are you sure you want to create a quotation for this waybill?')) {
                    return;
                }

                $button.html('<span class="spinner is-active" style="margin: 0;"></span>');
                $button.prop('disabled', true);

                jQuery.ajax({
                    url: myPluginAjax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'create_quotation_from_waybill',
                        waybill_id: waybillId,
                        _ajax_nonce: myPluginAjax.nonces.kit_waybill_nonce // Now using the correct nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Redirect to the new quotation if URL provided
                            if (response.data.redirect_url) {
                                window.location.href = response.data.redirect_url;
                            } else {
                                window.location.reload();
                            }
                        } else {
                            alert('Error: ' + (response.data ? .message || 'Failed to create quotation'));
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Error: ' + error);
                    },
                    complete: function() {
                        // Only reset if not redirecting
                        if (!response || !response.success) {
                            $button.html(
                                '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>'
                            );
                            $button.prop('disabled', false);
                        }
                    }
                });
            });
        </script>
    <?php

        return ob_get_clean();
    }

    public static function bonaWaybill($waybill_id)
    {
        global $wpdb;

        $shipDirectionTable = $wpdb->prefix . 'kit_shipping_directions';
        $customers_table = $wpdb->prefix . 'kit_customers';
        $companies_table = $wpdb->prefix . 'kit_company_customers';
        // 1. Get the waybill details, delivery, customer/company, and quotation info
        $waybill_data = $wpdb->get_row(
            $wpdb->prepare("
                SELECT
                    w.id AS waybill_id,
                    w.*,
                    w.parcel_id,
                    w.created_at as gmode,
                    w.direction_id AS waybill_direction_id,
                    w.city_id as waybill_destination_city_id,
                    dt.delivery_reference,
                    dt.direction_id AS delivery_direction_id,
                    dt.dispatch_date,
                    dt.truck_number,
                    dt.driver_id as truck_driver,
                    dt.destination_city_id as delivery_destination_city_id,
                    dt.status as delivery_status,
                    sd.origin_country_id,
                    sd.destination_country_id,
                    c.name as customer_name,
                    c.surname as customer_surname,
                    COALESCE(NULLIF(co.cell, ''), c.cell) as cell,
                    COALESCE(NULLIF(co.telephone, ''), c.telephone) as telephone,
                    COALESCE(NULLIF(co.email_address, ''), c.email_address) as email_address,
                    COALESCE(NULLIF(co.address, ''), c.address) as address,
                    COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) as company_name,
                    COALESCE(NULLIF(co.vat_number, ''), c.vat_number) as vat_number,
                    COALESCE(NULLIF(co.country_id, 0), c.country_id) as country_id,
                    COALESCE(NULLIF(co.city_id, 0), c.city_id) as customer_city_id,
                    q.waybill_id,
                    q.subtotal,
                    q.vat_amount,
                    q.total as invoice_total,
                    q.quotation_notes,
                    q.status as quote_status,
                    q.created_by as created_by_user_id,
                    q.created_at as invoiced_at,
                    u.user_login AS approved_by_username,
                    oc1.country_name AS origin_country,
                    oc1.country_code AS origin_code,
                    oc2.country_name AS destination_country, 
                    oc2.country_code AS destination_code
                FROM {$wpdb->prefix}kit_waybills AS w
                LEFT JOIN {$wpdb->prefix}kit_deliveries AS dt ON w.delivery_id = dt.id
                LEFT JOIN {$customers_table} AS c ON w.customer_id = c.cust_id
                LEFT JOIN {$companies_table} AS co ON w.company_id = co.company_id
                LEFT JOIN {$companies_table} AS cust_co ON c.company_id = cust_co.company_id
                LEFT JOIN {$wpdb->prefix}kit_quotations AS q ON w.id = q.waybill_id
                LEFT JOIN {$wpdb->prefix}users AS u ON u.id = w.approval_userid
                LEFT JOIN $shipDirectionTable sd ON w.direction_id = sd.id
                LEFT JOIN {$wpdb->prefix}kit_operating_countries oc1 ON sd.origin_country_id = oc1.id 
                LEFT JOIN {$wpdb->prefix}kit_operating_countries oc2 ON sd.destination_country_id = oc2.id 
                WHERE
                    w.id = %s LIMIT 1", $waybill_id),
            ARRAY_A
        );

        if (!$waybill_data) {
            return null; // Waybill not found
        }

        // Ensure all string fields have default values to prevent null deprecation warnings
        // Convert object to array first, then handle null values
        $waybill_data = (array) $waybill_data;
        $waybill_data = array_map(function ($value) {
            return $value === null ? '' : $value;
        }, $waybill_data);

        // JOIN + w.* can let duplicate column names overwrite kit_waybills fields; re-read authoritative columns.
        self::merge_canonical_waybill_columns($waybill_data);

        // Keep shipment route on the waybill's direction (warehouse deliveries often use a different direction).
        if (!empty($waybill_data['waybill_direction_id'])) {
            $waybill_data['direction_id'] = (int) $waybill_data['waybill_direction_id'];
        }

        $waybill_no = $waybill_data['waybill_no'];
        // 2. Get the waybill items
        $waybill_items = $wpdb->get_results(
            $wpdb->prepare("
                            SELECT *
                            FROM {$wpdb->prefix}kit_waybill_items
                            WHERE waybillno = %s
                        ", $waybill_no),
            ARRAY_A
        );
        // Attach items to the result and ensure no null values
        if ($waybill_items) {
            $waybill_items = array_map(function ($item) {
                return array_map(function ($value) {
                    return $value === null ? '' : $value;
                }, $item);
            }, $waybill_items);
        }
        $waybill_data['items'] = $waybill_items;

        // Unserialize miscellaneous items before returning
        if (!empty($waybill_data['miscellaneous'])) {
            $misc_data = maybe_unserialize($waybill_data['miscellaneous']);
            // Ensure nested arrays have safe defaults
            if (is_array($misc_data)) {
                if (isset($misc_data['others']) && is_array($misc_data['others'])) {
                    // Preserve all existing 'others' values, including waybill_description
                    // Only convert null to empty string, preserve actual values (including empty strings)
                    // array_map preserves existing values, only converts null to ''
                    $misc_data['others'] = array_map(function ($value) {
                        return $value === null ? '' : $value;
                    }, $misc_data['others']);
                } else {
                    $misc_data['others'] = [];
                }
                if (isset($misc_data['misc_items']) && is_array($misc_data['misc_items'])) {
                    $misc_data['misc_items'] = array_map(function ($item) {
                        if (is_array($item)) {
                            return array_map(function ($value) {
                                return $value === null ? '' : $value;
                            }, $item);
                        }
                        return $item;
                    }, $misc_data['misc_items']);
                }
                // Ensure misc_total has a safe default
                if (!isset($misc_data['misc_total']) || $misc_data['misc_total'] === null) {
                    $misc_data['misc_total'] = 0.0;
                }
            } else {
                $misc_data = ['others' => [], 'misc_items' => [], 'misc_total' => 0.0];
            }
        } else {
            $misc_data = ['others' => [], 'misc_items' => [], 'misc_total' => 0.0];
        }

        // ✅ Ensure 'others' array exists and has structure for waybill_description
        if (!isset($misc_data['others']) || !is_array($misc_data['others'])) {
            $misc_data['others'] = [];
        }

        // Preserve waybill_description if it exists (don't overwrite with empty)
        // Only set default if it truly doesn't exist
        if (!isset($misc_data['others']['waybill_description'])) {
            // Don't set a default - if it's not in the data, it means it wasn't saved
            // This preserves the actual state of the waybill
        }

        // ✅ CRITICAL FIX: Populate destination and origin city/country IDs from waybill/delivery/direction data
        // These are needed by selectsDestination.php and selectsOrigin.php components
        if (!isset($misc_data['others']['destination_city_id']) || empty($misc_data['others']['destination_city_id'])) {
            // Try waybill's city_id first, then delivery's destination_city_id
            $destination_city_id = !empty($waybill_data['waybill_destination_city_id'])
                ? intval($waybill_data['waybill_destination_city_id'])
                : (!empty($waybill_data['delivery_destination_city_id'])
                    ? intval($waybill_data['delivery_destination_city_id'])
                    : 0);
            if ($destination_city_id > 0) {
                $misc_data['others']['destination_city_id'] = $destination_city_id;
            }
        }

        if (!isset($misc_data['others']['destination_country_id']) || empty($misc_data['others']['destination_country_id'])) {
            // Get from direction's destination_country_id
            $destination_country_id = !empty($waybill_data['destination_country_id'])
                ? intval($waybill_data['destination_country_id'])
                : 0;
            if ($destination_country_id > 0) {
                $misc_data['others']['destination_country_id'] = $destination_country_id;
            }
        }

        if (!isset($misc_data['others']['origin_country_id']) || empty($misc_data['others']['origin_country_id'])) {
            // Get from direction's origin_country_id
            $origin_country_id = !empty($waybill_data['origin_country_id'])
                ? intval($waybill_data['origin_country_id'])
                : 0;
            if ($origin_country_id > 0) {
                $misc_data['others']['origin_country_id'] = $origin_country_id;
            }
        }

        if (empty($misc_data['others']['origin_city_id']) && class_exists('KIT_Routes')) {
            $origin_country_for_city = (int) ($misc_data['others']['origin_country_id'] ?? ($waybill_data['origin_country_id'] ?? 0));
            if ($origin_country_for_city > 0) {
                $default_origin_city = KIT_Routes::get_default_city_id_for_country($origin_country_for_city);
                if ($default_origin_city > 0) {
                    $misc_data['others']['origin_city_id'] = $default_origin_city;
                }
            }
        }

        // Ensure 'others' array exists
        if (!isset($misc_data['others']) || !is_array($misc_data['others'])) {
            $misc_data['others'] = [];
        }

        // Remove stale fee snapshots when toggles are off (e.g. sheet says SAD500 FALSE but misc still holds a prior R amount).
        $flag_s500 = self::normalize_flag_int($waybill_data['include_sad500'] ?? 0) === 1;
        $flag_sadc = self::normalize_flag_int($waybill_data['include_sadc'] ?? 0) === 1;
        if (!$flag_s500 && isset($misc_data['others']['include_sad500'])) {
            unset($misc_data['others']['include_sad500']);
        }
        if (!$flag_sadc && isset($misc_data['others']['include_sadc'])) {
            unset($misc_data['others']['include_sadc']);
        }

        $waybill_data['miscellaneous'] = $misc_data;


        return $waybill_data;
    }


    public static function waybillView()
    {
        $waybill_id = isset($_GET['waybill_id']) ? intval($_GET['waybill_id']) : 0;

        if ($waybill_id <= 0) {
            echo '<div class="wrap"><div class="' . KIT_Commons::containerClasses() . '">';
            echo KIT_Commons::emptyRecordState([
                'title'   => 'No waybill selected',
                'message' => 'This page needs a waybill to open. Pick one from Manage Waybills.',
                'actions' => [
                    [
                        'label'   => 'Go to Manage Waybills',
                        'href'    => admin_url('admin.php?page=08600-waybill-manage'),
                        'primary' => true,
                    ],
                ],
            ]);
            echo '</div></div>';
            return;
        }

        $waybill = KIT_Waybills::bonaWaybill($waybill_id);

        if (class_exists('KIT_Commons')) {
            KIT_Commons::enqueueComponentScripts(['kitscript']);
        }

        // Build breadcrumb links
        $waybill_no = '';
        if ($waybill) {
            if (is_object($waybill)) {
                $waybill_no = isset($waybill->waybill_no) ? $waybill->waybill_no : '';
            } elseif (is_array($waybill)) {
                $waybill_no = isset($waybill['waybill_no']) ? $waybill['waybill_no'] : '';
            }
        }
        $breadlinks = [
            [
                "name" => "08600 Solution",
                "slug" => admin_url('admin.php?page=08600-waybill-manage')
            ],
            [
                "name" => "Manage Waybills",
                "slug" => admin_url('admin.php?page=08600-waybill-manage')
            ],
            [
                "name" => "Waybill #" . ($waybill_no ?: 'N/A'),
                "slug" => ""
            ]
        ];

        // Check if this waybill is part of a parcel
        // Store parcel_id for display but allow viewing the waybill
        $parcel_id = null;
        if ($waybill && is_array($waybill) && !empty($waybill['parcel_id'])) {
            $parcel_id = intval($waybill['parcel_id']);
        }

        // Initialize variables with safe defaults
        $waybillFromStats = [];
        $waybillToStats = [];
        $mass_rate = '';
        $checkManny = '';
        $prefferedCharge = '';

        // Only process waybill data if it exists
        if ($waybill && is_array($waybill)) {
            // Get transit stats only if direction_id exists
            if (isset($waybill['direction_id']) && !empty($waybill['direction_id'])) {
                $waybillFromStats = KIT_Deliveries::getWaybillTransitStats($waybill['direction_id']);
                $waybillToStats = KIT_Deliveries::getWaybillTransitStats($waybill['direction_id'], 'destination');
            }

            // Determine preferred charge
            if (isset($waybill['mass_charge']) && isset($waybill['volume_charge'])) {
                if ($waybill['mass_charge'] > $waybill['volume_charge']) {
                    $prefferedCharge = 'mass';
                } else {
                    $prefferedCharge = 'volume';
                }
            }

            // Process miscellaneous data safely
            if (isset($waybill['miscellaneous']) && is_array($waybill['miscellaneous']) && isset($waybill['miscellaneous']['others'])) {
                $mass_rate = ($waybill['miscellaneous']['others']['mass_rate'] ?? 1);
                $checkManny = (isset($waybill['miscellaneous']['others']['manny']) && $waybill['miscellaneous']['others']['manny']) ? 'checked' : '';
            }
        }

        $is_editing = isset($_GET['edit']) && $_GET['edit'] === 'true';
        // Normalize waybill to array for downstream templates
        if (isset($waybill) && is_object($waybill)) {
            $waybill = (array) $waybill;
        }

        // Pass parcel_id to view component if this waybill is in a parcel
        if ($parcel_id) {
            $waybill['_parcel_id'] = $parcel_id;
        }
    ?>
        <div class="wrap"> 
            <div class="<?php echo KIT_Commons::containerClasses(); ?>">

                <?php
                if (!empty($waybill) && is_array($waybill)) {
                    if (!$is_editing) { ?>
                        <?php require(COURIER_FINANCE_PLUGIN_PATH . 'includes/components/viewWaybill.php'); ?>
                    <?php
                    } else {
                    ?>
                        <?php require(COURIER_FINANCE_PLUGIN_PATH . 'includes/components/editWaybill.php'); ?>
                <?php
                    }
                } else {
                    echo KIT_Commons::emptyRecordState([
                        'title'   => 'Waybill not found',
                        'message' => sprintf(
                            'There is no waybill with ID %d. It may have been deleted, or the link may be from before the last data import.',
                            $waybill_id
                        ),
                        'actions' => [
                            [
                                'label'   => 'Go to Manage Waybills',
                                'href'    => admin_url('admin.php?page=08600-waybill-manage'),
                                'primary' => true,
                            ],
                        ],
                    ]);
                }
                ?>
            </div>
        </div>
        <?php
    }

    public static function render_detail_row($label, $value)
    {
        return '
                        <div class="flex gap-4 px-4 py-2">
                            <span class="text-black-600 font-bold">' . esc_html($label) . '</span>
                            <span class="text-gray-800">' . (! empty($value) ? esc_html($value) : '—') . '</span>
                        </div>';
    }

    public static function handle_delete_waybill()
    {
        global $wpdb;

        $is_ajax = defined('DOING_AJAX') && DOING_AJAX;

        // Accept either waybill_id or waybill_no
        $waybill_id = isset($_POST['waybill_id']) ? intval($_POST['waybill_id']) : 0;
        $waybill_no_raw = isset($_POST['waybill_no']) ? $_POST['waybill_no'] : '';

        // If waybill_no not provided but waybill_id is, get waybill_no from database
        if (empty($waybill_no_raw) && $waybill_id > 0) {
            $waybill_no_raw = $wpdb->get_var($wpdb->prepare(
                "SELECT waybill_no FROM {$wpdb->prefix}kit_waybills WHERE id = %d",
                $waybill_id
            ));
        }

        if (empty($waybill_no_raw)) {
            if ($is_ajax) {
                wp_send_json_error(['message' => 'Missing waybill_no or waybill_id']);
            } else {
                wp_die('Invalid request: waybill_no or waybill_id not provided.');
            }
        }

        // Handle both numeric waybill_no and string waybill_no (like "4000a")
        $waybill_no = is_numeric($waybill_no_raw) ? (int) $waybill_no_raw : sanitize_text_field($waybill_no_raw);

        if (!check_ajax_referer('delete_waybill_nonce', '_ajax_nonce', false)) {
            if ($is_ajax) {
                wp_send_json_error(['message' => 'Invalid nonce'], 403);
            } else {
                wp_die('Invalid nonce');
            }
        }

        // Check permissions
        if (!current_user_can('kit_update_data')) {
            if ($is_ajax) {
                wp_send_json_error(['message' => 'Insufficient permissions'], 403);
            } else {
                wp_die('You do not have sufficient permissions to access this page.');
            }
        }

        $table_name = $wpdb->prefix . 'kit_waybills';
        $waybill_items_table = $wpdb->prefix . 'kit_waybill_items';

        // Get waybill ID first to check if it's part of a parcel
        $waybill = $wpdb->get_row($wpdb->prepare(
            "SELECT id, parcel_id FROM $table_name WHERE waybill_no = %s",
            $waybill_no
        ));

        if (!$waybill) {
            if ($is_ajax) {
                wp_send_json_error(['message' => 'Waybill not found']);
            } else {
                wp_die('Waybill not found');
            }
        }

        // Delete waybill items first
        $wpdb->delete($waybill_items_table, ['waybillno' => $waybill_no], ['%s']);

        // Delete the waybill
        $result = $wpdb->delete($table_name, ['waybill_no' => $waybill_no], ['%s']);

        // If this waybill was part of a parcel, check if we need to update or delete the parcel
        $parcel_id = isset($waybill->parcel_id) ? intval($waybill->parcel_id) : 0;

        if ($parcel_id > 0) {
            $remaining_waybills = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE parcel_id = %d",
                $parcel_id
            ));

            // If no waybills left in parcel, delete the parcel
            $parcel_deleted = false;
            if ($remaining_waybills == 0) {
                $parcels_table = $wpdb->prefix . 'kit_parcels';
                $wpdb->delete($parcels_table, ['id' => $parcel_id], ['%d']);
                $parcel_deleted = true;
            } else {
                // Update parcel totals
                $parcels_table = $wpdb->prefix . 'kit_parcels';
                $total_amount = $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(product_invoice_amount), 0) FROM $table_name WHERE parcel_id = %d",
                    $parcel_id
                ));
                $waybill_count = $remaining_waybills;

                $wpdb->update(
                    $parcels_table,
                    [
                        'total_amount' => floatval($total_amount),
                        'total_waybills' => $waybill_count
                    ],
                    ['id' => $parcel_id],
                    ['%f', '%d'],
                    ['%d']
                );
            }
        }

        if ($is_ajax) {
            if ($result !== false) {
                // Get updated parcel totals if applicable
                $response_data = ['message' => 'Waybill deleted successfully'];

                // Check if parcel was deleted
                if (isset($parcel_deleted) && $parcel_deleted) {
                    $response_data['parcel_deleted'] = true;
                    $response_data['message'] = 'Waybill and parcel deleted successfully';
                } elseif ($parcel_id > 0) {
                    $parcels_table = $wpdb->prefix . 'kit_parcels';
                    $parcel_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT total_amount, total_waybills FROM $parcels_table WHERE id = %d",
                        $parcel_id
                    ), ARRAY_A);

                    if ($parcel_data) {
                        $response_data['parcel_totals'] = [
                            'total_amount' => floatval($parcel_data['total_amount']),
                            'waybill_count' => intval($parcel_data['total_waybills'])
                        ];
                    }
                }

                wp_send_json_success($response_data);
            } else {
                wp_send_json_error(['message' => 'Failed to delete waybill']);
            }
        } else {
            if ($result !== false) {
                wp_redirect(add_query_arg('deleted', '1', wp_get_referer()));
            } else {
                wp_redirect(add_query_arg('error', '1', wp_get_referer()));
            }
            exit;
        }
    }


    public static function getFullWaybillWithItems($waybill_no)
    {
        global $wpdb;

        $prefix = $wpdb->prefix;

        // TABLE NAMES
        $waybills_table   = $prefix . 'kit_waybills';
        $customers_table  = $prefix . 'kit_customers';
        $deliveries_table = $prefix . 'kit_deliveries';
        $directions_table = $prefix . 'kit_shipping_directions';
        $countries_table  = $prefix . 'kit_operating_countries';
        $cities_table  = $prefix . 'kit_operating_cities';
        $items_table      = $prefix . 'kit_waybill_items';

        // PHASE 1: Waybill + related joins
        $waybill_sql = $wpdb->prepare(" SELECT 
                b.id AS waybill_id,
                c.id AS customer_id,
                d.id AS delivery_id,
                dir.id AS direction_id,
                b.direction_id,
                b.customer_id,
                b.approval,
                b.approval_userid,
                b.waybill_no,
                b.product_invoice_number,
                b.product_invoice_amount,
                b.waybill_items_total,
                b.item_length,
                b.item_width,
                b.item_height,
                b.total_mass_kg,
                b.total_volume,
                b.mass_charge,
                b.volume_charge,
                b.charge_basis,
                b.vat_include,
                b.miscellaneous,
                b.include_sad500,
                b.include_sadc,
                b.created_at,
                b.tracking_number,
                b.parcel_id,
                b.warehouse,
                c.name AS customer_name,
                c.surname AS customer_surname,
                c.address,
                c.email_address,
                c.city_id,
                city.city_name AS customer_city,
                COALESCE(NULLIF(co.company_name, ''), NULLIF(cust_co.company_name, '')) AS company_name,
                c.cell AS customer_cell,
                d.delivery_reference,
                d.direction_id,
                d.dispatch_date,
                d.truck_number,
                d.status,
                d.status AS delivery_status,
                dir.description AS route_description,
                origin.country_name AS origin_country,
                dest.country_name AS destination_country
                FROM $waybills_table b
                LEFT JOIN $customers_table c ON b.customer_id = c.cust_id
                LEFT JOIN {$wpdb->prefix}kit_company_customers co ON b.company_id = co.company_id
                LEFT JOIN {$wpdb->prefix}kit_company_customers cust_co ON c.company_id = cust_co.company_id
                LEFT JOIN $deliveries_table d ON b.delivery_id = d.id
                LEFT JOIN $directions_table dir ON b.direction_id = dir.id
                LEFT JOIN $countries_table origin ON dir.origin_country_id = origin.id
                LEFT JOIN $countries_table dest ON dir.destination_country_id = dest.id
                LEFT JOIN $cities_table city ON c.city_id = city.id
                WHERE b.waybill_no = %s
                LIMIT 1", $waybill_no);

        $waybill = $wpdb->get_row($waybill_sql, ARRAY_A);

        if (!$waybill) {
            return null;
        }

        self::merge_canonical_waybill_columns($waybill);

        // PHASE 2: Waybill Items
        // Use %s (string) instead of %d (integer) to properly match waybill numbers with letters (e.g., "4008a", "4008b")
        $items_sql = $wpdb->prepare("SELECT * FROM $items_table WHERE waybillno = %s", $waybill_no);

        $items = $wpdb->get_results($items_sql, ARRAY_A);


        return (object)[
            'waybill' => $waybill,
            'items'   => $items,
        ];
    }

    /**
     * Calculate consistent pricing totals for a waybill.
     *
     * @param array|object $waybill Waybill data (array or object).
     * @param array        $items   Waybill items (array of arrays).
     *
     * @return array{
     *     charge_basis:string,
     *     transport_total:float,
     *     misc_total:float,
     *     misc_items:array,
     *     sad500_total:float,
     *     sadc_total:float,
     *     intl_amount:float,
     *     items_total:float,
     *     final_total:float,
     *     product_invoice_amount:float,
     *     stored_volume_rate:float,
     *     stored_mass_rate:float
     * }
     */
    public static function calculate_waybill_totals($waybill, $items = [])
    {
        $waybill_data = is_object($waybill) ? (array) $waybill : (array) ($waybill ?? []);
        $items = is_array($items) ? $items : [];

        $mass_charge = isset($waybill_data['mass_charge']) ? floatval($waybill_data['mass_charge']) : 0.0;
        $volume_charge = isset($waybill_data['volume_charge']) ? floatval($waybill_data['volume_charge']) : 0.0;

        $misc_total = 0.0;
        $misc_items = [];
        $stored_basis = '';
        $stored_volume_rate = 0.0;
        $stored_mass_rate = 0.0;
        $stored_intl_calc = 0.0;
        $stored_sad500 = 0.0;
        $stored_sadc = 0.0;

        if (!empty($waybill_data['miscellaneous'])) {
            $misc_data = maybe_unserialize($waybill_data['miscellaneous']);

            if (is_array($misc_data)) {
                if (isset($misc_data['misc_total'])) {
                    $misc_total = floatval($misc_data['misc_total']);
                } elseif (isset($misc_data['misc_items']) && is_array($misc_data['misc_items'])) {
                    foreach ($misc_data['misc_items'] as $mi) {
                        $price = isset($mi['misc_price']) ? floatval($mi['misc_price']) : 0.0;
                        $qty = isset($mi['misc_quantity']) ? intval($mi['misc_quantity']) : 0;
                        $misc_total += $price * $qty;
                    }
                }

                if (isset($misc_data['misc_items']) && is_array($misc_data['misc_items'])) {
                    $misc_items = $misc_data['misc_items'];
                }

                if (isset($misc_data['others']) && is_array($misc_data['others'])) {
                    $others = $misc_data['others'];
                    if (isset($others['used_charge_basis'])) {
                        $stored_basis = (string) $others['used_charge_basis'];
                    }
                    if (isset($others['volume_rate_used'])) {
                        $stored_volume_rate = floatval($others['volume_rate_used']);
                    }
                    if (isset($others['mass_rate'])) {
                        $stored_mass_rate = floatval($others['mass_rate']);
                    }
                    if (isset($others['international_price_rands'])) {
                        $stored_intl_calc = self::normalize_amount($others['international_price_rands']);
                    }
                    if (isset($others['include_sad500'])) {
                        $stored_sad500 = self::normalize_amount($others['include_sad500']);
                    }
                    if (isset($others['include_sadc'])) {
                        $stored_sadc = self::normalize_amount($others['include_sadc']);
                    }
                }
            }
        }

        $charge_basis = '';
        if (!empty($waybill_data['charge_basis'])) {
            $charge_basis = (string) $waybill_data['charge_basis'];
        } elseif (!empty($stored_basis)) {
            $charge_basis = $stored_basis;
        } else {
            $charge_basis = ($mass_charge > $volume_charge) ? 'mass' : 'volume';
        }

        $transport_total = ($charge_basis === 'mass' || $charge_basis === 'weight')
            ? $mass_charge
            : $volume_charge;

        $sad500_total = (self::normalize_flag_int($waybill_data['include_sad500'] ?? 0) === 1)
            ? ($stored_sad500 > 0.0 ? $stored_sad500 : floatval(self::sadc_certificate()))
            : 0.0;

        $sadc_total = (self::normalize_flag_int($waybill_data['include_sadc'] ?? 0) === 1)
            ? ($stored_sadc > 0.0 ? $stored_sadc : floatval(self::sad()))
            : 0.0;

        $intl_amount = 0.0;
        $vat_included = self::normalize_flag_int($waybill_data['vat_include'] ?? 0);
        if ($vat_included === 0) {
            $intl_amount = $stored_intl_calc > 0.0
                ? $stored_intl_calc
                : floatval(self::international_price_in_rands());
        }

        $items_total = 0.0;
        foreach ($items as $item) {
            if (is_object($item)) {
                $item = (array) $item;
            }
            $qty = isset($item['quantity']) ? intval($item['quantity']) : 0;
            $unit = isset($item['unit_price']) ? floatval($item['unit_price']) : 0.0;
            $items_total += $qty * $unit;
        }

        $computed_fallback_total = $transport_total + $misc_total + $sad500_total + $sadc_total + $intl_amount + $items_total;

        $product_invoice_amount = 0.0;
        if (isset($waybill_data['product_invoice_amount'])) {
            $raw_invoice_amount = $waybill_data['product_invoice_amount'];
            if (is_string($raw_invoice_amount)) {
                $raw_invoice_amount = str_replace(',', '', $raw_invoice_amount);
            }
            $product_invoice_amount = floatval($raw_invoice_amount);
        }

        $final_total = $product_invoice_amount > 0.0
            ? $product_invoice_amount
            : $computed_fallback_total;

        return [
            'charge_basis'            => $charge_basis,
            'transport_total'         => $transport_total,
            'misc_total'              => $misc_total,
            'misc_items'              => $misc_items,
            'sad500_total'            => $sad500_total,
            'sadc_total'              => $sadc_total,
            'intl_amount'             => $intl_amount,
            'items_total'             => $items_total,
            'final_total'             => $final_total,
            'product_invoice_amount'  => $product_invoice_amount,
            'stored_volume_rate'      => $stored_volume_rate,
            'stored_mass_rate'        => $stored_mass_rate,
        ];
    }

    /**
     * Generate QR code data JSON for waybill containing all details
     * @param int $waybill_no Waybill number
     * @return string JSON encoded QR code data
     */
    public static function generate_qr_code_data($waybill_no)
    {
        global $wpdb;

        $full = self::getFullWaybillWithItems($waybill_no);
        if (!$full || !isset($full->waybill)) {
            return '';
        }

        $waybill = $full->waybill;
        $items = $full->items ?? [];

        // Get delivery details with driver
        $deliveries_table = $wpdb->prefix . 'kit_deliveries';
        $drivers_table = $wpdb->prefix . 'kit_drivers';
        $delivery_id = $waybill['delivery_id'] ?? 0;

        $delivery_info = null;
        $driver_info = null;

        if ($delivery_id > 0) {
            $delivery_info = $wpdb->get_row($wpdb->prepare(
                "SELECT d.*, dr.name AS driver_name, dr.phone AS driver_phone 
                 FROM $deliveries_table d
                 LEFT JOIN $drivers_table dr ON d.driver_id = dr.id
                 WHERE d.id = %d",
                $delivery_id
            ), ARRAY_A);

            if ($delivery_info && !empty($delivery_info['driver_name'])) {
                $driver_info = [
                    'name' => $delivery_info['driver_name'],
                    'phone' => $delivery_info['driver_phone'] ?? null
                ];
            }
        }

        // Build comprehensive QR code data
        $qr_data = [
            'waybill_no' => $waybill['waybill_no'] ?? '',
            'tracking_number' => $waybill['tracking_number'] ?? '',
            'invoice_number' => $waybill['product_invoice_number'] ?? '',
            'invoice_amount' => floatval($waybill['product_invoice_amount'] ?? 0),
            'created_at' => $waybill['created_at'] ?? '',
            'status' => $waybill['status'] ?? '',

            // Customer Information
            'customer' => [
                'id' => intval($waybill['customer_id'] ?? 0),
                'name' => ($waybill['customer_name'] ?? '') . ' ' . ($waybill['customer_surname'] ?? ''),
                'company' => $waybill['company_name'] ?? '',
                'email' => $waybill['email_address'] ?? '',
                'cell' => $waybill['customer_cell'] ?? '',
                'address' => $waybill['address'] ?? '',
                'city' => $waybill['customer_city'] ?? ''
            ],

            // Delivery Information
            'delivery' => [
                'reference' => $delivery_info['delivery_reference'] ?? '',
                'truck_number' => $delivery_info['truck_number'] ?? $waybill['truck_number'] ?? '',
                'dispatch_date' => $delivery_info['dispatch_date'] ?? $waybill['dispatch_date'] ?? '',
                'status' => $delivery_info['status'] ?? $waybill['delivery_status'] ?? ''
            ],

            // Driver Information (if available)
            'driver' => $driver_info,

            // Route Information
            'route' => [
                'description' => $waybill['route_description'] ?? '',
                'origin_country' => $waybill['origin_country'] ?? '',
                'destination_country' => $waybill['destination_country'] ?? ''
            ],

            // Dimensions & Weight
            'dimensions' => [
                'length_cm' => floatval($waybill['item_length'] ?? 0),
                'width_cm' => floatval($waybill['item_width'] ?? 0),
                'height_cm' => floatval($waybill['item_height'] ?? 0),
                'total_mass_kg' => floatval($waybill['total_mass_kg'] ?? 0),
                'total_volume_m3' => floatval($waybill['total_volume'] ?? 0)
            ],

            // Charges
            'charges' => [
                'mass_charge' => floatval($waybill['mass_charge'] ?? 0),
                'volume_charge' => floatval($waybill['volume_charge'] ?? 0),
                'charge_basis' => $waybill['charge_basis'] ?? '',
                'items_total' => floatval($waybill['waybill_items_total'] ?? 0),
                'final_total' => floatval($waybill['product_invoice_amount'] ?? 0)
            ],

            // Items (if any)
            'items' => []
        ];

        // Add waybill items if available
        if (!empty($items) && is_array($items)) {
            foreach ($items as $item) {
                $qr_data['items'][] = [
                    'name' => $item['item_name'] ?? '',
                    'quantity' => intval($item['quantity'] ?? 0),
                    'unit_price' => floatval($item['unit_price'] ?? 0),
                    'total' => floatval($item['total'] ?? 0)
                ];
            }
        }

        // Use compact JSON to keep size small for storage and QR encoding
        return json_encode($qr_data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Generate QR code image as base64 string
     * @param string $data QR code data to encode
     * @param int $size Size of QR code (default 300)
     * @return string Base64 encoded PNG image data URI
     */
    public static function generate_qr_code_image($data, $size = 300)
    {
        if (empty($data)) {
            return '';
        }

        try {
            require_once __DIR__ . '/../../vendor/autoload.php';

            if (! class_exists('\\Endroid\\QrCode\\Writer\\PngWriter')) {
                throw new \RuntimeException('Endroid QR Code library not installed');
            }

            $writer = new \Endroid\QrCode\Writer\PngWriter();
            $qrCode = \Endroid\QrCode\QrCode::create($data)
                ->setEncoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
                ->setErrorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::High)
                ->setSize($size)
                ->setMargin(10);

            $result = $writer->write($qrCode);
            $imageData = $result->getString();

            return 'data:image/png;base64,' . base64_encode($imageData);
        } catch (\Throwable $e) {
            error_log('QR Code generation error: ' . $e->getMessage());
            return '';
        }
    }

    public static function get_waybill_items_total($data)
    {
        $finalTotal = 0;

        // Handle two possible POST shapes from dynamicItemsControl:
        // 1) Grouped parallel arrays:
        //    misc[misc_item][], misc[misc_price][], misc[misc_quantity][]
        // 2) Array of item objects:
        //    misc[misc_items][i][misc_item|misc_price|misc_quantity]

        // Case 2: array of item objects
        if (isset($data['misc_items']) && is_array($data['misc_items'])) {
            foreach ($data['misc_items'] as $item) {
                $price = isset($item['misc_price']) ? floatval($item['misc_price']) : 0;
                $qty   = isset($item['misc_quantity']) ? intval($item['misc_quantity']) : 0;
                $finalTotal += ($price * $qty);
            }
            return $finalTotal;
        }

        // Case 1: grouped parallel arrays
        if (isset($data['misc_item']) && is_array($data['misc_item']) && !empty($data['misc_item'])) {
            $count = count($data['misc_item']);
            for ($i = 0; $i < $count; $i++) {
                $price = isset($data['misc_price'][$i]) ? floatval($data['misc_price'][$i]) : 0;
                $qty   = isset($data['misc_quantity'][$i]) ? intval($data['misc_quantity'][$i]) : 0;
                $finalTotal += ($price * $qty);
            }
        }

        return $finalTotal;
    }


    public static function vatValidate($vat, $sad, $sad500)
    {
        $validate = [
            'vat' => $vat,
            'sad' => $sad,
            'sad500' => $sad500
        ];

        // If vat includes (assumed when $vat == 1), then SAD and SAD500 must be 0
        if ($vat == 1) {
            if ($sad != 0) {
                $validate['sad'] = false;
            }
            if ($sad500 != 0) {
                $validate['sad500'] = false;
            }
        }

        if ($sad == 1) {
            $validate['vat'] = false;
        }
        return $validate;
    }


    public static function deleteWaybillItems($waybill_no)
    {
        global $wpdb;
        $items_table = $wpdb->prefix . 'kit_waybill_items';
        if ($waybill_no != null) {
            // ✅ Delete previous waybill items
            $deleted = $wpdb->delete($items_table, ['waybillno' => $waybill_no]);

            if ($deleted) {
                return $deleted;
            }
        }
    }

    /**
     * Verify and optionally correct a waybill's total by recalculating it with the
     * bulletproof calculator and comparing with the DB value.
     *
     * Args:
     * - waybill_id?: int
     * - waybill_no?: int
     * - update_if_mismatch?: bool (default false)
     * - tolerance?: float (default 0.01)
     *
     * Returns array with:
     * - waybill_id, waybill_no
     * - db_total, calc_total, difference, matches, updated
     * - breakdown (calculator arrays)
     */
    public static function doubleCalcWaybillTotal($args = [])
    {
        global $wpdb;

        $update_if_mismatch = !empty($args['update_if_mismatch']);
        $tolerance = isset($args['tolerance']) ? floatval($args['tolerance']) : 0.01;

        $waybill_id = isset($args['waybill_id']) ? intval($args['waybill_id']) : 0;
        $waybill_no = isset($args['waybill_no']) && !empty($args['waybill_no']) ? (string)$args['waybill_no'] : null;

        // Resolve using whichever identifier we have
        if ($waybill_no <= 0 && $waybill_id > 0) {
            $waybill_no = (int) $wpdb->get_var($wpdb->prepare("SELECT waybill_no FROM {$wpdb->prefix}kit_waybills WHERE id = %d", $waybill_id));
        }

        if ($waybill_no <= 0) {
            return ['error' => 'Waybill identifier not provided'];
        }

        $full = self::getFullWaybillWithItems($waybill_no);
        if (!$full || empty($full->waybill)) {
            return ['error' => 'Waybill not found', 'waybill_no' => $waybill_no];
        }

        $wb = $full->waybill;
        $items = is_array($full->items) ? $full->items : [];

        // Compute items total defensively from items if present
        $computed_items_total = 0.0;
        foreach ($items as $it) {
            $qty = isset($it['quantity']) ? floatval($it['quantity']) : 0.0;
            $price = isset($it['unit_price']) ? floatval($it['unit_price']) : 0.0;
            $line_total = $price * $qty;
            // Prefer explicit total_price if present
            if (isset($it['total_price']) && is_numeric($it['total_price'])) {
                $line_total = floatval($it['total_price']);
            }
            $computed_items_total += $line_total;
        }

        // Parse miscellaneous safely
        $misc_total = 0.0;
        if (!empty($wb['miscellaneous'])) {
            $misc = maybe_unserialize($wb['miscellaneous']);
            if (is_array($misc) && isset($misc['misc_total'])) {
                $misc_total = floatval($misc['misc_total']);
            }
        }

        // Calculator params
        require_once plugin_dir_path(__FILE__) . 'bulletproof-calculator.php';
        $params = [
            'mass_charge' => floatval($wb['mass_charge'] ?? 0),
            'volume_charge' => floatval($wb['volume_charge'] ?? 0),
            'misc_total' => $misc_total,
            'waybill_items_total' => $computed_items_total > 0 ? $computed_items_total : floatval($wb['waybill_items_total'] ?? 0),
            'charge_basis' => $wb['charge_basis'] ?? 'auto',
            'include_sad500' => self::normalize_flag_int($wb['include_sad500'] ?? 0) === 1,
            'include_sadc' => self::normalize_flag_int($wb['include_sadc'] ?? 0) === 1,
            'include_vat' => self::normalize_flag_int($wb['vat_include'] ?? 0) === 1,
        ];

        $breakdown = KIT_Bulletproof_Calculator::calculate_waybill_total($params);
        $calc_total = floatval($breakdown['totals']['final_total'] ?? 0);
        $db_total = floatval($wb['product_invoice_amount'] ?? 0);
        $difference = round($db_total - $calc_total, 2);
        $matches = abs($difference) < $tolerance;

        $updated = false;
        if (!$matches && $update_if_mismatch) {
            // Update DB with authoritative calculated total; zero fee columns when flags are off (stale sad500_amount is a common cause of wrong totals).
            $wit = ($computed_items_total > 0 ? $computed_items_total : $wb['waybill_items_total']);
            $update_data = [
                'product_invoice_amount' => $calc_total,
                'waybill_items_total' => $wit,
            ];
            $format = ['%f', '%f'];
            if (self::normalize_flag_int($wb['include_sad500'] ?? 0) !== 1) {
                $update_data['sad500_amount'] = 0.0;
                $format[] = '%f';
            }
            if (self::normalize_flag_int($wb['include_sadc'] ?? 0) !== 1) {
                $update_data['sadc_amount'] = 0.0;
                $format[] = '%f';
            }
            $wpdb->update(
                $wpdb->prefix . 'kit_waybills',
                $update_data,
                ['waybill_no' => $waybill_no],
                $format,
                ['%s']
            );
            $updated = ($wpdb->rows_affected > 0);
        }

        return [
            'waybill_id' => intval($wb['waybill_id'] ?? 0),
            'waybill_no' => $waybill_no,
            'db_total' => $db_total,
            'calc_total' => $calc_total,
            'difference' => $difference,
            'matches' => $matches,
            'updated' => $updated,
            'breakdown' => $breakdown,
        ];
    }
    public static function updateWaybillItems($waybill_items, $waybill_no = null)
    {
        global $wpdb;
        $items_table = $wpdb->prefix . 'kit_waybill_items';
        $total = 0;

        // 🔒 CRITICAL FIX: Prevent NULL waybillno database errors
        if ($waybill_no === null || empty($waybill_no)) {
            error_log("updateWaybillItems: waybill_no is null or empty - skipping item insertion");
            return 0;
        }

        // Validate waybill_items is an array
        if (!is_array($waybill_items)) {
            error_log("updateWaybillItems: waybill_items is not an array - skipping item insertion");
            return 0;
        }

        // ✅ Delete previous waybill items (only if waybill_no is valid)
        $delete_result = $wpdb->delete($items_table, ['waybillno' => $waybill_no], ['%s']);
        if ($delete_result === false) {
            error_log(sprintf(
                "updateWaybillItems: Failed to delete existing items for waybill_no %s: %s",
                $waybill_no,
                $wpdb->last_error
            ));
            // Continue anyway - items might not exist yet
        }

        // Get client_invoice from waybill's product_invoice_number
        $client_invoice = null;
        if ($waybill_no) {
            $waybill = $wpdb->get_row($wpdb->prepare(
                "SELECT product_invoice_number FROM {$wpdb->prefix}kit_waybills WHERE waybill_no = %s LIMIT 1",
                $waybill_no
            ));
            if ($waybill && !empty($waybill->product_invoice_number)) {
                $client_invoice = sanitize_text_field($waybill->product_invoice_number);
            }
        }

        foreach ($waybill_items as $item) {
            // Skip if required fields are missing
            if (empty($item['item_name']) || !isset($item['quantity']) || !isset($item['unit_price'])) {
                continue;
            }

            $quantity = intval($item['quantity']);
            $unit_price = floatval($item['unit_price']);

            // Validate quantity and price (matching logic from save_or_update_waybill)
            if ($quantity <= 0 || $unit_price < 0) {
                error_log(sprintf(
                    "updateWaybillItems: Skipping invalid item for waybill_no %s: quantity=%d, unit_price=%.2f",
                    $waybill_no,
                    $quantity,
                    $unit_price
                ));
                continue;
            }

            $subtotal = $quantity * $unit_price;

            // ✅ ACCURACY FIX: Apply same validation as manual calculation
            // Items with subtotal over 999999.99 are excluded (matches line 2037 logic)
            if ($subtotal <= 999999.99) {
                $total += $subtotal;
            } else {
                error_log(sprintf(
                    "updateWaybillItems: Skipping item with subtotal exceeding limit for waybill_no %s: subtotal=%.2f",
                    $waybill_no,
                    $subtotal
                ));
                // Skip inserting this item to maintain consistency
                continue;
            }

            // Use client_invoice from item if provided, otherwise use waybill's product_invoice_number
            $item_client_invoice = !empty($item['client_invoice']) ? sanitize_text_field($item['client_invoice']) : $client_invoice;

            $insert_result = $wpdb->insert($items_table, [
                'waybillno'   => $waybill_no,
                'item_name'   => sanitize_text_field($item['item_name']),
                'quantity'    => $quantity,
                'unit_price'  => $unit_price,
                'unit_mass'   => floatval($item['unit_mass'] ?? 0),
                'unit_volume' => floatval($item['unit_volume'] ?? 0),
                'total_price' => $subtotal,
                'client_invoice' => $item_client_invoice,
                'created_at'  => current_time('mysql'),
            ], [
                '%s',
                '%s',
                '%d',
                '%f',
                '%f',
                '%f',
                '%f',
                '%s',
                '%s'
            ]);

            if ($insert_result === false) {
                error_log(sprintf(
                    "updateWaybillItems: Failed to insert item '%s' for waybill_no %s: %s",
                    sanitize_text_field($item['item_name']),
                    $waybill_no,
                    $wpdb->last_error
                ));
                // Continue with other items
            }
        }
        return $total;
    }

    public static function update_waybill_action()
    {
        // Security checks
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'update_waybill_nonce')) {
            wp_die('Security check failed.');
        }

        // Allow Data Capturers/Managers via plugin cap (avoid WP core caps)
        if (!current_user_can('kit_update_data')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        // Use unified function for update mode
        $result = self::save_or_update_waybill($_POST, null, true);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }

        $waybill_id = $result['id'];
        $waybill_no = $result['waybill_no'];
        $waybillItemsTotal = 0;
        if (isset($_POST['custom_items']) && is_array($_POST['custom_items'])) {
            foreach ($_POST['custom_items'] as $item) {
                if (!empty($item['item_name']) && isset($item['quantity']) && isset($item['unit_price'])) {
                    $waybillItemsTotal += intval($item['quantity']) * floatval($item['unit_price']);
                }
            }
        }
        $vat_include = isset($_POST['vat_include']) ? 1 : 0;

        // Check for VAT warning and add to redirect URL
        $redirect_url = admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . $waybill_id . '&updated=1');
        if (self::shouldShowVatWarning($vat_include, $waybillItemsTotal)) {
            $redirect_url = add_query_arg('vat_warning', '1', $redirect_url);
        }

        // Final consistency check: ensure stored totals match calculator output
        if (!empty($waybill_no)) {
            self::doubleCalcWaybillTotal([
                'waybill_no' => $waybill_no,
                'update_if_mismatch' => true,
            ]);
        }

        global $wpdb;
        $waybills_table = $wpdb->prefix . 'kit_waybills';

        // Optional: assign waybill from warehouse to a specific delivery when editing
        $posted_delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
        $move_to_warehouse  = !empty($_POST['move_to_warehouse']) && intval($_POST['move_to_warehouse']) === 1;

        if (!empty($waybill_id) && $posted_delivery_id > 0 && !$move_to_warehouse) {
            $delivery_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}kit_deliveries WHERE id = %d",
                $posted_delivery_id
            ));
            $mapped_status = class_exists('KIT_Deliveries')
                ? KIT_Deliveries::map_delivery_status_to_waybill_status((string) $delivery_status)
                : 'assigned';
            $wpdb->update(
                $waybills_table,
                [
                    'delivery_id'     => $posted_delivery_id,
                    'warehouse'       => 0,
                    'status'          => $mapped_status,
                    'last_updated_at' => current_time('mysql'),
                    'last_updated_by' => get_current_user_id(),
                ],
                ['id' => $waybill_id],
                ['%d', '%d', '%s', '%s', '%d'],
                ['%d']
            );
        } elseif (!empty($waybill_id) && $move_to_warehouse) {
            // Move waybill back to warehouse: clear delivery_id, set warehouse flag, status pending
            $wpdb->update(
                $waybills_table,
                [
                    'delivery_id'     => 0,
                    'warehouse'       => 1,
                    'status'          => 'pending',
                    'last_updated_at' => current_time('mysql'),
                    'last_updated_by' => get_current_user_id(),
                ],
                ['id' => $waybill_id],
                ['%d', '%d', '%s', '%s', '%d'],
                ['%d']
            );
        }

        // ✅ Redirect to success page
        wp_redirect($redirect_url);
        exit;
    }



    // Save waybill items using custom_items
    public static function save_waybill_items($items, $waybill_no, $waybill_id, $vatOption)
    {
        return (!empty($items) && is_array($items)) ? self::updateWaybillItems($items, $waybill_no) : 0;
    }

    public static function get_total_cost_of_waybill($waybill_no)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kit_waybills';

        // Get all waybills with their charges and misc
        $waybills = $wpdb->get_results($wpdb->prepare("SELECT mass_charge, volume_charge, miscellaneous FROM $table WHERE waybill_no = %s", $waybill_no));

        $grand_total = 0;
        foreach ($waybills as $waybill) {
            $mass = floatval($waybill['mass_charge']);
            $volume = floatval($waybill['volume_charge']);

            // Unserialize misc and get misc_total
            $misc_total = 0;
            if (!empty($waybill['miscellaneous'])) {
                $misc = maybe_unserialize($waybill['miscellaneous']);
                if (is_array($misc) && isset($misc['misc_total'])) {
                    $misc_total = floatval($misc['misc_total']);
                }
            }

            $grand_total += max($mass, $volume) + $misc_total;
        }

        return number_format($grand_total, 2, '.', '');
    }

    /**
     * Helper function to render cell content based on key
     * 
     * @param string $key Column key
     * @param object $item Row data object
     * @param string $label Column label
     * @return string HTML content
     */
    private static function renderCellContent($key, $item, $label)
    {
        $value = $item->$key ?? '';

        switch ($key) {
            case 'waybill_no':
                return '<div class="font-medium text-blue-600">' . esc_html($value) . '</div>' .
                    '<div class="text-xs text-gray-500">' . date('M d, Y', strtotime($item->created_at ?? '')) . '</div>';

            case 'customer_id':
                return '<div>' . esc_html($value) . '</div>' .
                    '<div class="text-xs text-gray-500 truncate max-w-xs">' . esc_html($item->customer_name ?? '') . '</div>';

            case 'status':
                $status_class = ($value === 'pending') ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800';
                return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ' . $status_class . '">' .
                    ucfirst($value) . '</span>';

            case 'approval':
                // Check if current user can approve (administrator or manager)
                $can_approve = KIT_User_Roles::can_approve();

                // If user can approve, show waybillApprovalStatus
                if ($can_approve) {
                    return  KIT_Commons::waybillApprovalStatus($item->waybill_no, $item->id, 'quoted', $item->approval, 'select');
                } else {
                    // For users who cannot approve, show approval status badge only
                    $approval = strtolower($item->approval ?? 'pending');
                    switch ($approval) {
                        case 'approved':
                            $badgeColor = 'bg-green-400 text-green-800';
                            break;
                        case 'pending':
                            $badgeColor = 'bg-yellow-400 text-yellow-800';
                            break;
                        case 'rejected':
                            $badgeColor = 'bg-red-400 text-red-800';
                            break;
                        default:
                            $badgeColor = 'bg-gray-400 text-gray-800';
                    }
                    return '<span class="esmall selection:inline-block mt-1 px-2 py-1 rounded-full font-semibold ' . $badgeColor . '">' .
                        ucfirst($approval) . '</span>';
                }

            case 'amount':
            case 'product_invoice_amount':
                return 'R' . ($value ? number_format($value, 2) : '0');

            case 'items':
                $item_count = is_array($value) ? count($value) : (int)$value;
                return KIT_Commons::renderButton($item_count . ' item' . ($item_count !== 1 ? 's' : ''), 'ghost-primary', 'lg', [
                    'classes' => 'toggle-items flex items-center',
                    'data-item-id' => $item->id ?? '',
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>',
                    'iconPosition' => 'right'
                ]);

            case 'actions':
                return self::renderDefaultActions($item);

            case 'total':
                return self::get_total_cost_of_waybill($item->waybill_no);

            default:
                return esc_html($value);
        }
    }

    /**
     * Render default actions for a row
     * 
     * @param object $item Row data object
     * @return string HTML content
     */
    private static function renderDefaultActions($item)
    {
        $current_user = wp_get_current_user();

        return '<div class="flex space-x-2">' .
            '<a href="' . admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . $item->id . '&waybill_atts=view_waybill') . '" ' .
            'class="text-blue-600 hover:text-blue-900" title="View">' .
            '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">' .
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />' .
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />' .
            '</svg></a>' .
            KIT_Commons::deleteWaybillGlobal($item->id, $item->waybill_no, $item->delivery_id, $current_user->ID) .
            '</div>';
    }

    /**
     * Render default accordion content
     * 
     * @param array $accordion_data Accordion data structure
     * @param object $item Row data object
     * @return string HTML content
     */
    private static function renderDefaultAccordionContent($accordion_data, $item)
    {
        if (!isset($accordion_data['items']) || !is_array($accordion_data['items'])) {
            return '<p class="text-gray-500">No items available</p>';
        }

        $items = $accordion_data['items'];

        return '<div class="overflow-x-auto">' .
            '<table class="w-full text-xs border border-gray-200 rounded">' .
            '<thead><tr class="bg-gray-100">' .
            '<th class="' . KIT_Commons::thClasses() . ' text-left">Item</th>' .
            '<th class="' . KIT_Commons::thClasses() . ' text-right">Qty</th>' .
            '<th class="' . KIT_Commons::thClasses() . ' text-right">Price</th>' .
            '</tr></thead>' .
            '<tbody>' .
            implode('', array_map(function ($item) {
                return '<tr class="border-t border-gray-100">' .
                    '<td class="' . KIT_Commons::tcolClasses() . '">' . esc_html($item->item ?? $item->item_name) . '</td>' .
                    '<td class="' . KIT_Commons::tcolClasses() . ' text-right">' . esc_html($item->quantity ?? '') . '</td>' .
                    '<td class="' . KIT_Commons::tcolClasses() . ' text-right">R' . number_format($item->unit_price ?? 0, 2) . '</td>' .
                    '</tr>';
            }, $items)) .
            '</tbody></table></div>';
    }

    public static function pdfVerifier($waybill_no, $waybill_id = null)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kit_waybills';
        $where = 'waybill_no = %s';
        $params = [$waybill_no];
        if ($waybill_id !== null) {
            $where .= ' AND id = %d';
            $params[] = $waybill_id;
        }



        $sql = $wpdb->prepare("SELECT approval, approval_userid, status, status_userid FROM $table_name WHERE $where LIMIT 1", ...$params);
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!$row) return false;
        // soWhat: true only if both approval and status conditions are met
        $approval_ok = ($row['approval'] === 'approved' && !empty($row['approval_userid']));
        $status_ok = (in_array($row['status'], ['invoiced', 'quoted', 'paid', 'completed']) && !empty($row['status_userid'])) ?? false;

        $row['soWhat'] = ($approval_ok && $status_ok) ? true : false;
        return $row;
    }

    public static function delete_waybill($waybill_no, $waybill_id = null)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'kit_waybills';

        // Sanitize inputs (treat waybill_no as integer key)
        $waybill_no = (int) $waybill_no;
        $waybill_id = $waybill_id !== null ? (int) $waybill_id : null;

        // Capability check (admin screens): allow admins, managers, and data capturers who can edit/update waybills
        if (is_admin() && ! (current_user_can('manage_options') || current_user_can('kit_edit_waybills') || current_user_can('kit_update_data'))) {
            wp_die(__('You do not have permission to delete waybills.', 'courier-finance-plugin'), 403);
        }

        if (class_exists('Courier_Google_Sheets_Sync')) {
            Courier_Google_Sheets_Sync::sync_waybill_items_delete($waybill_no);
            Courier_Google_Sheets_Sync::sync_waybill_delete($waybill_no);
        }
        // Best-effort: delete related items first to avoid orphan rows
        self::deleteWaybillItems($waybill_no);

        // Best-effort: remove warehouse tracking entries for this waybill
        $tracking_table = $wpdb->prefix . 'kit_waybills';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tracking_table)) === $tracking_table) {
            $wpdb->delete($tracking_table, ['waybill_no' => $waybill_no]);
        }

        // Build where clause for the main delete
        $where = ['waybill_no' => $waybill_no];
        if ($waybill_id !== null) {
            $where['id'] = $waybill_id;
        }

        // Delete from DB
        $deleted = $wpdb->delete($table, $where);

        // Debug log outcome for troubleshooting
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[KIT_Waybills::delete_waybill] waybill_no=' . $waybill_no . ' id=' . ($waybill_id ?? 'null') . ' deleted=' . var_export($deleted, true) . ' last_error=' . ($wpdb->last_error ?: ''));
        }

        // Redirect back with a status flag
        $redirect = wp_get_referer();
        if (! $redirect) {
            $redirect = admin_url('admin.php?page=08600-Waybill');
        }
        $redirect = add_query_arg(['deleted' => ($deleted !== false ? 1 : 0)], $redirect);
        wp_redirect($redirect);
        exit;
    }

    /**
     * Static function to dynamically render table rows with customizable data
     * 
     * @param array $data Array of objects/arrays containing row data
     * @param array $columns Array defining column structure ['key' => 'label']
     * @param array $options Additional options for customization
     * @return string HTML output of table rows
     */
    public static function tRows($data, $columns, $options = [])
    {
        $defaults = [
            'row_class' => 'hover:bg-gray-50',
            'cell_class' => 'px-4 py-3 whitespace-nowrap',
            'header_class' => 'px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase',
            'actions' => true,
            'actions_column' => 'actions',
            'custom_cell_render' => null,
            'custom_actions_render' => null,
            'row_attributes' => null,
            'accordion' => false,
            'accordion_content' => null,
            'table_class' => 'min-w-full divide-y divide-gray-200',
            'show_headers' => true
        ];

        $options = wp_parse_args($options, $defaults);

        ob_start();

        if (!$options['accordion']) {
            // Full table structure
        ?>
            <table class="<?= esc_attr($options['table_class']) ?>">
                <?php if ($options['show_headers']): ?>
                    <thead class="bg-gray-50">
                        <tr>
                            <?php foreach ($columns as $key => $label): ?>
                                <th class="<?= esc_attr($options['header_class']) ?>"><?= esc_html($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                <?php endif; ?>
                <tbody class="divide-y divide-gray-200">
                <?php } else {
                // Accordion mode - only table body content
                ?>
                <tbody class="divide-y divide-gray-200">
                <?php }

            foreach ($data as $index => $item):
                $row_attrs = '';
                if ($options['row_attributes'] && is_callable($options['row_attributes'])) {
                    $row_attrs = $options['row_attributes']($item, $index);
                }
                ?>
                    <tr class="<?= esc_attr($options['row_class']) ?>" <?= $row_attrs ?>>
                        <?php foreach ($columns as $key => $label):
                            $cell_attrs = '';
                            if ($options['custom_cell_render'] && is_callable($options['custom_cell_render'])) {
                                $custom_content = $options['custom_cell_render']($key, $item, $index);
                                if ($custom_content !== null) {
                                    echo '<td class="' . esc_attr($options['cell_class']) . '" ' . $cell_attrs . '>' . $custom_content . '</td>';
                                    continue;
                                }
                            }
                        ?>
                            <td class="<?= esc_attr($options['cell_class']) ?>" <?= $cell_attrs ?>>
                                <?php if ($options['custom_cell_render'] && is_callable($options['custom_cell_render'])): ?>
                                    <?= $options['custom_cell_render']($key, $item, $index) ?>
                                <?php else: ?>
                                    <?= self::renderCellContent($key, $item, $label) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>

                    <?php if ($options['accordion'] && $options['accordion_content'] && is_callable($options['accordion_content'])): ?>
                        <tr class="accordion-content">
                            <td colspan="<?= count($columns) ?>" class="bg-gray-50 px-4 py-3">
                                <?= $options['accordion_content']($item, $index) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>

                </tbody>
                <?php if (!$options['accordion']): ?>
            </table>
        <?php endif; ?>

<?php
        return ob_get_clean(true);
    }

    public static function DoubleCheckTotal($mass_charge, $volume_charge, $misc_total, $include_sad500, $include_sadc, $vatCharge, $internationalPrice, $charge_basis = null)
    {
        // Manual override if charge_basis set, otherwise pick the higher charge
        if ($charge_basis == 'mass' || $charge_basis == 'weight') {
            $better_charge = $mass_charge;
        } elseif ($charge_basis == 'volume') {
            $better_charge = $volume_charge;
        } else {
            $better_charge = max($mass_charge, $volume_charge);
        }

        // Calculate additional charges
        $additionalCharges = $misc_total + $include_sad500 + $include_sadc + $vatCharge + $internationalPrice;

        // Calculate total
        $total = $better_charge + $additionalCharges;

        // Return float total
        return (float) $total;
    }

    /**
     * Calculate total number of waybills for a specific delivery
     * 
     * @param int $delivery_id The delivery ID to count waybills for
     * @return int Total number of waybills for the delivery
     */
    public static function calculate_total_waybills($delivery_id)
    {
        global $wpdb;

        if (!$delivery_id || $delivery_id <= 0) {
            return 0;
        }

        $waybills_table = $wpdb->prefix . 'kit_waybills';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$waybills_table} WHERE delivery_id = %d",
            $delivery_id
        ));

        return intval($count);
    }

    /**
     * Calculate total mass (weight) for all waybills in a delivery
     * 
     * @param int $delivery_id The delivery ID to calculate mass for
     * @return float Total mass in kilograms
     */
    public static function calculate_total_mass($delivery_id)
    {
        global $wpdb;

        if (!$delivery_id || $delivery_id <= 0) {
            return 0.0;
        }

        $waybills_table = $wpdb->prefix . 'kit_waybills';

        $total_mass = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total_mass_kg) FROM {$waybills_table} WHERE delivery_id = %d",
            $delivery_id
        ));

        return floatval($total_mass ?: 0);
    }

    /**
     * Calculate total volume for all waybills in a delivery
     * 
     * @param int $delivery_id The delivery ID to calculate volume for
     * @return float Total volume in cubic meters
     */
    public static function calculate_total_volume($delivery_id)
    {
        global $wpdb;

        if (!$delivery_id || $delivery_id <= 0) {
            return 0.0;
        }

        $waybills_table = $wpdb->prefix . 'kit_waybills';

        $total_volume = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total_volume) FROM {$waybills_table} WHERE delivery_id = %d",
            $delivery_id
        ));

        return floatval($total_volume ?: 0);
    }

    /**
     * Calculate total amount for all waybills in a delivery
     * 
     * @param int $delivery_id The delivery ID to calculate total amount for
     * @return float Total amount for all waybills
     */
    public static function calculate_total_amount($delivery_id)
    {
        global $wpdb;

        if (!$delivery_id || $delivery_id <= 0) {
            return 0.0;
        }

        $waybills_table = $wpdb->prefix . 'kit_waybills';

        $total_amount = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(product_invoice_amount + miscellaneous) FROM {$waybills_table} WHERE delivery_id = %d",
            $delivery_id
        ));

        return floatval($total_amount ?: 0);
    }
}

// Initialize
KIT_Waybills::init();

// Include necessary files
function wpd_breadcrumbs($atts)
{
    // Set default attributes
    $atts = shortcode_atts(
        [
            'data' => '[]', // Default is an empty array
        ],
        $atts,
        'custom_breadcrumbs'
    );
    $output = "";

    // Decode the JSON data passed to the shortcode
    $breadlinks = json_decode(urldecode($atts['data']), true);

    // Check if the decoded data is valid
    if (! is_array($breadlinks)) {
        return "Invalid breadcrumb data.";
    }

    // Start the breadcrumb container
    $output .= '<div class="shadow rounded bg-slate-300 space-y-6 mb-6">';
    $output .= '<div class="max-w-7xl mx-auto px-4 py-3">
        <nav aria-label="breadcrumb">
            <ol class="flex items-center text-xs text-gray-500">';

    // Loop through each breadcrumb and generate the list items
    $total_links = count($breadlinks);
    foreach ($breadlinks as $index => $link) {
        if ($index !== 0) {
            $output .= '<li class="mx-1 text-black">/</li>'; // Adjusted spacing for separator
        }

        // If it's the last item, make it active
        if ($index === $total_links - 1) {
            $output .= '<li class="text-black font-medium">
                    <p class="m-0 text-black">' . $link['name'] . '</p>
                </li>';
        } else {
            $output .= '<li><a href="' . $link['slug'] . '"
                        class="shutup text-black font-medium hover:underline m-0">
                        <p class="m-0 text-black">' . $link['name'] . '</p>
                    </a></li>';
        }
    }

    // Close the breadcrumb container
    $output .= '</ol>
        </nav>
        </div>
    </div>';

    return $output;
}
add_shortcode('cust_breadcrumb', 'wpd_breadcrumbs');

function render_waybill_row($waybill, $columns, $current_user = null)
{
    if (!$current_user) {
        $current_user = wp_get_current_user();
    }
    $roles = (array) $current_user->roles;

    echo '<tr>';
    foreach ($columns as $col) {
        switch ($col) {
            case 'waybill_no':
                echo '<td>' . esc_html($waybill['waybill_no']) . '</td>';
                break;
            case 'customer':
                echo '<td>' . esc_html($waybill['customer_name']) . '</td>';
                break;
            case 'dispatch_date':
                echo '<td>' . (!empty($waybill['dispatch_date']) ? date('M j, Y', strtotime($waybill['dispatch_date'])) : '-') . '</td>';
                break;
            // ... add more columns as needed ...
            case 'actions':
                echo '<td>';
                // View action (everyone)
                echo KIT_Commons::renderButton('View', 'primary', 'lg', ['href' => admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . $waybill['id']), 'gradient' => true]) . ' ';
                // Generate Quotation (admin only)
                if (in_array('administrator', $roles)) {
                    echo KIT_Commons::renderButton('Generate Quotation', 'success', 'lg', ['href' => admin_url('admin.php?page=generate-quotation&waybill_id=' . $waybill['id']), 'gradient' => true]) . ' ';
                }
                // Delete (admin only)
                if (in_array('administrator', $roles)) {
                    echo KIT_Commons::renderButton('Delete', 'danger', 'lg', ['href' => admin_url('admin-post.php?action=delete_waybill&waybill_id=' . $waybill['id']), 'onclick' => 'return confirm(\'Are you sure?\')', 'gradient' => true]);
                }
                echo '</td>';
                break;
            default:
                // fallback for any other column
                echo '<td>' . (isset($waybill[$col]) ? esc_html($waybill[$col]) : '-') . '</td>';
        }
    }
    echo '</tr>';
}
