<?php
/**
 * Admin: activate customer portal accounts (WP user + kit_customer_id link).
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('kit_view_waybills')) {
    wp_die(esc_html__('You do not have permission to access this page.', '08600-services-quotations'));
}

require_once plugin_dir_path(__FILE__) . '../class-unified-table.php';
require_once plugin_dir_path(__FILE__) . '../components/quickStats.php';
require_once plugin_dir_path(__FILE__) . '../components/iconButton.php';

/**
 * Create a portal WP user for a kit customer and link kit_customer_id.
 *
 * @param int  $cust_id     Customer ID.
 * @param bool $send_email  Whether to send WP new-user notification.
 * @return array{ok:bool,user_id?:int,error?:string,login?:string,email?:string,already?:bool}
 */
function kit_customer_portal_create_user_for_customer($cust_id, $send_email = true)
{
    global $wpdb;
    $cust_id = (int) $cust_id;
    if ($cust_id <= 0) {
        return array('ok' => false, 'error' => __('Invalid customer.', '08600-services-quotations'));
    }

    $linked = get_users(
        array(
            'meta_key'   => 'kit_customer_id',
            'meta_value' => (string) $cust_id,
            'number'     => 1,
            'fields'     => 'ID',
        )
    );
    if (!empty($linked)) {
        return array(
            'ok'      => true,
            'already' => true,
            'user_id' => (int) $linked[0],
            'login'   => 'cust_' . $cust_id,
        );
    }

    $table = $wpdb->prefix . 'kit_customers';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT cust_id, name, surname, email_address FROM {$table} WHERE cust_id = %d LIMIT 1",
            $cust_id
        ),
        ARRAY_A
    );
    if (!$row) {
        return array('ok' => false, 'error' => __('Customer not found.', '08600-services-quotations'));
    }

    $email = isset($row['email_address']) ? trim((string) $row['email_address']) : '';
    if ($email === '' || !is_email($email)) {
        return array('ok' => false, 'error' => __('Missing or invalid email on customer profile.', '08600-services-quotations'));
    }

    $login = 'cust_' . $cust_id;
    if (username_exists($login)) {
        $u = get_user_by('login', $login);
        if ($u && (int) get_user_meta($u->ID, 'kit_customer_id', true) === $cust_id) {
            return array(
                'ok'      => true,
                'already' => true,
                'user_id' => (int) $u->ID,
                'login'   => $login,
                'email'   => $email,
            );
        }
        return array(
            'ok'    => false,
            'error' => sprintf(
                /* translators: %s: WordPress username */
                __('Username %s is already taken by another account.', '08600-services-quotations'),
                $login
            ),
        );
    }

    $existing_uid = email_exists($email);
    if ($existing_uid) {
        return array(
            'ok'    => false,
            'error' => sprintf(
                /* translators: 1: email, 2: WordPress user ID */
                __('Email %1$s is already registered (user #%2$d). Change the customer email or link that user manually.', '08600-services-quotations'),
                $email,
                (int) $existing_uid
            ),
        );
    }

    $pass = wp_generate_password(24, true, true);
    $uid = wp_insert_user(
        array(
            'user_login' => $login,
            'user_email' => $email,
            'user_pass'  => $pass,
            'first_name' => sanitize_text_field($row['name'] ?? ''),
            'last_name'  => sanitize_text_field($row['surname'] ?? ''),
            'role'       => '08600_portal_customer',
        )
    );

    if (is_wp_error($uid)) {
        return array('ok' => false, 'error' => $uid->get_error_message());
    }

    update_user_meta((int) $uid, 'kit_customer_id', $cust_id);

    if ($send_email && function_exists('wp_send_new_user_notifications')) {
        wp_send_new_user_notifications((int) $uid, 'user');
    }

    return array(
        'ok'      => true,
        'user_id' => (int) $uid,
        'login'   => $login,
        'email'   => $email,
        'already' => false,
    );
}

/**
 * Human status meta for portal account rows (Tailwind badge classes).
 *
 * @param string $status Internal status key.
 * @return array{label:string,class:string}
 */
function kit_customer_portal_status_meta($status)
{
    $map = array(
        'ready'          => array(
            'label' => __('Ready', '08600-services-quotations'),
            'class' => 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800',
        ),
        'active'         => array(
            'label' => __('Active', '08600-services-quotations'),
            'class' => 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800',
        ),
        'needs_email'    => array(
            'label' => __('Needs email', '08600-services-quotations'),
            'class' => 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800',
        ),
        'email_conflict' => array(
            'label' => __('Email conflict', '08600-services-quotations'),
            'class' => 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800',
        ),
        'email_in_use'   => array(
            'label' => __('Email in use', '08600-services-quotations'),
            'class' => 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800',
        ),
        'blocked'        => array(
            'label' => __('Blocked', '08600-services-quotations'),
            'class' => 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800',
        ),
    );

    return $map[$status] ?? array(
        'label' => $status,
        'class' => 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700',
    );
}

/**
 * Icon button link used in portal account action cells.
 */
function kit_customer_portal_action_link($variant, $href, $title, $icon, $onclick = '')
{
    $attrs = 'href="' . esc_url($href) . '" class="' . esc_attr(KIT_Icon::buttonClasses($variant, 'sm')) . '" title="' . esc_attr($title) . '" aria-label="' . esc_attr($title) . '"';
    if ($onclick !== '') {
        $attrs .= ' onclick="' . esc_attr($onclick) . '"';
    }
    return '<a ' . $attrs . '>' . KIT_Icon::svg($icon, 14) . '<span class="ml-1">' . esc_html($title) . '</span></a>';
}

global $wpdb;
$table = $wpdb->prefix . 'kit_customers';
$page_url = admin_url('admin.php?page=08600-customer-portal-accounts');

$single_result = null;
$batch_result = null;

// Single activate.
if (isset($_POST['kit_customer_portal_single_nonce'])
    && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kit_customer_portal_single_nonce'])), 'kit_customer_portal_single')
    && isset($_POST['kit_portal_activate_one'])
) {
    $activate_id = isset($_POST['cust_id']) ? (int) $_POST['cust_id'] : 0;
    $send_email = !isset($_POST['send_email']) || (string) wp_unslash($_POST['send_email']) === '1';
    $result = kit_customer_portal_create_user_for_customer($activate_id, $send_email);
    $single_result = array_merge($result, array('cust_id' => $activate_id, 'send_email' => $send_email));
}

// Batch activate.
if (isset($_POST['kit_customer_portal_batch_nonce'])
    && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kit_customer_portal_batch_nonce'])), 'kit_customer_portal_batch')
    && isset($_POST['kit_portal_batch_create'])
) {
    $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 20;
    $batch_size = max(1, min(50, $batch_size));
    $send_email = !isset($_POST['batch_send_email']) || (string) wp_unslash($_POST['batch_send_email']) === '1';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.cust_id, c.name, c.surname, c.email_address
             FROM {$table} c
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$wpdb->usermeta} um
                 WHERE um.meta_key = %s
                   AND um.meta_value <> ''
                   AND um.meta_value = CAST(c.cust_id AS CHAR)
             )
             AND c.email_address IS NOT NULL
             AND TRIM(c.email_address) <> ''
             AND LOCATE('@', c.email_address) > 0
             ORDER BY c.cust_id ASC",
            'kit_customer_id'
        ),
        ARRAY_A
    );

    $created = 0;
    $skipped_in_batch = 0;
    $errors = array();

    foreach ($rows as $row) {
        if ($created >= $batch_size) {
            break;
        }
        $cust_id = (int) $row['cust_id'];
        $result = kit_customer_portal_create_user_for_customer($cust_id, $send_email);
        if (!empty($result['ok']) && empty($result['already'])) {
            $created++;
        } elseif (!empty($result['already'])) {
            $skipped_in_batch++;
        } else {
            $skipped_in_batch++;
            if (!empty($result['error'])) {
                $errors[] = sprintf('cust_id %d: %s', $cust_id, $result['error']);
            }
        }
    }

    $batch_result = array(
        'created'    => $created,
        'skipped'    => $skipped_in_batch,
        'errors'     => $errors,
        'batch_size' => $batch_size,
    );
}

$rows = $wpdb->get_results(
    "SELECT c.cust_id, c.name, c.surname, co.company_name, c.email_address
     FROM {$table} c
     LEFT JOIN {$wpdb->prefix}kit_company_customers co ON c.company_id = co.company_id
     ORDER BY c.cust_id ASC",
    ARRAY_A
);

$email_seen = array();
$dry_rows = array();

foreach ($rows as $row) {
    $cust_id = (int) $row['cust_id'];
    $email = isset($row['email_address']) ? trim((string) $row['email_address']) : '';
    $status = 'ready';
    $note = '';
    $user_id = 0;

    $linked = get_users(
        array(
            'meta_key'   => 'kit_customer_id',
            'meta_value' => (string) $cust_id,
            'number'     => 1,
            'fields'     => 'ID',
        )
    );
    if (!empty($linked)) {
        $status = 'active';
        $user_id = (int) $linked[0];
        $note = sprintf(__('WordPress user #%d', '08600-services-quotations'), $user_id);
        if (is_email($email)) {
            $email_seen[strtolower($email)] = true;
        }
    } elseif ($email === '' || !is_email($email)) {
        $status = 'needs_email';
        $note = __('Add a valid email on the customer profile before activating.', '08600-services-quotations');
    } elseif (isset($email_seen[strtolower($email)])) {
        $status = 'email_conflict';
        $note = __('This email is already used by another customer in the list.', '08600-services-quotations');
    } else {
        $email_seen[strtolower($email)] = true;
    }

    if ($status === 'ready' && email_exists($email)) {
        $status = 'email_in_use';
        $note = sprintf(
            /* translators: %d: WordPress user ID */
            __('Email already used in WordPress (user #%d).', '08600-services-quotations'),
            (int) email_exists($email)
        );
    } elseif ($status === 'ready') {
        $login = 'cust_' . $cust_id;
        if (username_exists($login)) {
            $u = get_user_by('login', $login);
            if ($u && (int) get_user_meta($u->ID, 'kit_customer_id', true) !== $cust_id) {
                $status = 'blocked';
                $note = sprintf(
                    /* translators: %s: WordPress username */
                    __('Username %s exists for another account.', '08600-services-quotations'),
                    $login
                );
            }
        }
    }

    $dry_rows[] = array(
        'cust_id' => $cust_id,
        'name'    => trim(($row['name'] ?? '') . ' ' . ($row['surname'] ?? '')),
        'company' => $row['company_name'] ?? '',
        'email'   => $email,
        'status'  => $status,
        'note'    => $note,
        'user_id' => $user_id,
        'login'   => 'cust_' . $cust_id,
    );
}

$count_ready = 0;
$count_active = 0;
$count_attention = 0;
foreach ($dry_rows as $dr) {
    if ($dr['status'] === 'ready') {
        $count_ready++;
    } elseif ($dr['status'] === 'active') {
        $count_active++;
    } else {
        $count_attention++;
    }
}

$view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : 'queue';
if (!in_array($view, array('queue', 'ready', 'attention', 'active', 'all'), true)) {
    $view = 'queue';
}

$search = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash($_GET['q']))) : '';
$search_l = strtolower($search);

$preview_rows = array_values(
    array_filter(
        $dry_rows,
        static function ($r) use ($view, $search_l) {
            $status = $r['status'] ?? '';
            if ($view === 'queue' && $status === 'active') {
                return false;
            }
            if ($view === 'ready' && $status !== 'ready') {
                return false;
            }
            if ($view === 'attention' && !in_array($status, array('needs_email', 'email_conflict', 'email_in_use', 'blocked'), true)) {
                return false;
            }
            if ($view === 'active' && $status !== 'active') {
                return false;
            }

            if ($search_l === '') {
                return true;
            }

            $hay = strtolower(
                (string) ($r['cust_id'] ?? '') . ' ' .
                (string) ($r['name'] ?? '') . ' ' .
                (string) ($r['company'] ?? '') . ' ' .
                (string) ($r['email'] ?? '') . ' ' .
                (string) ($r['login'] ?? '')
            );
            return strpos($hay, $search_l) !== false;
        }
    )
);

$selected = null;
if ($search !== '' && count($preview_rows) === 1) {
    $selected = $preview_rows[0];
} elseif (isset($_GET['cust_id'])) {
    $want = (int) $_GET['cust_id'];
    foreach ($dry_rows as $dr) {
        if ((int) $dr['cust_id'] === $want) {
            $selected = $dr;
            break;
        }
    }
}

$portal_login = function_exists('kit_customer_login_url') ? kit_customer_login_url() : home_url('/customer/login/');
$portal_dash = function_exists('kit_customer_dashboard_url') ? kit_customer_dashboard_url() : home_url('/customer/dashboard/');

$filter_base = remove_query_arg(array('view', 'cust_id'), $page_url);
if ($search !== '') {
    $filter_base = add_query_arg('q', $search, $filter_base);
}

$header_actions = KIT_Commons::renderButton(
    __('Customer login', '08600-services-quotations'),
    'secondary',
    'md',
    array(
        'href'   => $portal_login,
        'target' => '_blank',
        'rel'    => 'noopener',
        'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a3 3 0 11-6 0 3 3 0 016 0zM4 20a8 8 0 0116 0" />',
        'iconPosition' => 'left',
        'noLoading' => true,
    )
) . KIT_Commons::renderButton(
    __('Dashboard', '08600-services-quotations'),
    'secondary',
    'md',
    array(
        'href'   => $portal_dash,
        'target' => '_blank',
        'rel'    => 'noopener',
        'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1" />',
        'iconPosition' => 'left',
        'noLoading' => true,
        'classes' => 'ml-2',
    )
);

$ready_url = add_query_arg('view', 'ready', $filter_base);
$active_url = add_query_arg('view', 'active', $filter_base);
$attention_url = add_query_arg('view', 'attention', $filter_base);

$portal_stats = array(
    array(
        'title'     => __('Ready', '08600-services-quotations'),
        'value'     => number_format($count_ready),
        'subtitle'  => __('Can activate now', '08600-services-quotations'),
        'icon'      => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'color'     => 'green',
        'clickable' => true,
        'onclick'   => "window.location.href='" . $ready_url . "'",
        'class'     => $view === 'ready' ? 'text-green-700' : '',
    ),
    array(
        'title'     => __('Active', '08600-services-quotations'),
        'value'     => number_format($count_active),
        'subtitle'  => __('Already live', '08600-services-quotations'),
        'icon'      => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        'color'     => 'blue',
        'clickable' => true,
        'onclick'   => "window.location.href='" . $active_url . "'",
        'class'     => $view === 'active' ? 'text-blue-700' : '',
    ),
    array(
        'title'     => __('Needs attention', '08600-services-quotations'),
        'value'     => number_format($count_attention),
        'subtitle'  => __('Fix email or conflicts', '08600-services-quotations'),
        'icon'      => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
        'color'     => 'yellow',
        'clickable' => true,
        'onclick'   => "window.location.href='" . $attention_url . "'",
        'class'     => $view === 'attention' ? 'text-amber-700' : '',
    ),
);

// Table data for unified table.
$table_rows = array();
foreach ($preview_rows as $dr) {
    $table_rows[] = array(
        'id'      => (int) $dr['cust_id'],
        'cust_id' => (int) $dr['cust_id'],
        'name'    => $dr['name'] !== '' ? $dr['name'] : '—',
        'company' => $dr['company'] !== '' ? $dr['company'] : '—',
        'email'   => $dr['email'] !== '' ? $dr['email'] : '—',
        'login'   => $dr['login'],
        'status'  => $dr['status'],
        'note'    => $dr['note'],
        'user_id' => (int) $dr['user_id'],
        'city'    => $dr['status'],
    );
}

$nonce_field = wp_nonce_field('kit_customer_portal_single', 'kit_customer_portal_single_nonce', true, false);

$columns = array(
    'name' => array(
        'label'    => __('Client', '08600-services-quotations'),
        'callback' => static function ($value, $row) {
            $initial = strtoupper(substr((string) $value, 0, 1));
            if ($initial === '' || $initial === '—') {
                $initial = '#';
            }
            $login = esc_html($row['login'] ?? '');
            $cust_id = (int) ($row['cust_id'] ?? 0);
            return '<div class="flex items-center">'
                . '<div class="flex-shrink-0 h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">'
                . '<span class="text-blue-600 font-semibold">' . esc_html($initial) . '</span>'
                . '</div>'
                . '<div class="ml-4">'
                . '<div class="text-sm font-medium text-gray-900">' . esc_html((string) $value) . '</div>'
                . '<div class="text-xs text-gray-500">#' . $cust_id . ' · ' . $login . '</div>'
                . '</div></div>';
        },
    ),
    'company' => __('Company', '08600-services-quotations'),
    'email'   => __('Email', '08600-services-quotations'),
    'status'  => array(
        'label'    => __('Status', '08600-services-quotations'),
        'callback' => static function ($value) {
            $meta = kit_customer_portal_status_meta((string) $value);
            return '<span class="' . esc_attr($meta['class']) . '">' . esc_html($meta['label']) . '</span>';
        },
    ),
    'note' => array(
        'label'    => __('Note', '08600-services-quotations'),
        'callback' => static function ($value) {
            return '<span class="text-xs text-gray-500">' . esc_html((string) $value) . '</span>';
        },
    ),
    'actions_html' => array(
        'label'    => __('Actions', '08600-services-quotations'),
        'callback' => static function ($value, $row) use ($nonce_field, $page_url, $view, $search) {
            $cust_id = (int) ($row['cust_id'] ?? 0);
            $status = (string) ($row['status'] ?? '');
            $edit_url = admin_url('admin.php?page=edit-customer&edit_customer=' . $cust_id);
            $view_url = admin_url('admin.php?page=08600-customers&view_customer=' . $cust_id);
            $select_url = add_query_arg(
                array(
                    'view'    => $view,
                    'q'       => $search,
                    'cust_id' => $cust_id,
                ),
                $page_url
            );

            $html = '<div class="flex flex-wrap items-center gap-2">';
            if ($status === 'ready') {
                $html .= '<form method="post" class="inline">'
                    . $nonce_field
                    . '<input type="hidden" name="cust_id" value="' . $cust_id . '" />'
                    . '<input type="hidden" name="send_email" value="1" />'
                    . KIT_Commons::renderButton(
                        __('Activate', '08600-services-quotations'),
                        'primary',
                        'sm',
                        array(
                            'type'  => 'submit',
                            'name'  => 'kit_portal_activate_one',
                            'value' => '1',
                            'classes' => 'px-3 py-1.5 text-xs',
                        )
                    )
                    . '</form>';
            } elseif ($status === 'needs_email') {
                $html .= kit_customer_portal_action_link('blue', $edit_url, __('Edit email', '08600-services-quotations'), 'edit');
            } elseif ($status === 'active') {
                $html .= kit_customer_portal_action_link('green', $view_url, __('Profile', '08600-services-quotations'), 'eye');
                if (!empty($row['user_id'])) {
                    $html .= kit_customer_portal_action_link('gray', get_edit_user_link((int) $row['user_id']), __('User', '08600-services-quotations'), 'edit');
                }
            } else {
                $html .= kit_customer_portal_action_link('blue', $edit_url, __('Edit', '08600-services-quotations'), 'edit');
                $html .= kit_customer_portal_action_link('green', $view_url, __('Profile', '08600-services-quotations'), 'eye');
            }
            $html .= kit_customer_portal_action_link('gray', $select_url, __('Details', '08600-services-quotations'), 'eye');
            $html .= '</div>';
            return $html;
        },
    ),
);

$view_labels = array(
    'queue'     => __('Queue', '08600-services-quotations'),
    'ready'     => __('Ready', '08600-services-quotations'),
    'attention' => __('Needs attention', '08600-services-quotations'),
    'active'    => __('Active', '08600-services-quotations'),
    'all'       => __('Everyone', '08600-services-quotations'),
);

?>
<div class="wrap">
    <div class="<?php echo esc_attr(KIT_Commons::containerClasses()); ?>">
        <?php
        echo KIT_Commons::showingHeader(array(
            'title'   => __('Activate portal access', '08600-services-quotations'),
            'desc'    => __('Create a login for a customer, link it to their profile, and email them how to sign in. Clients use the website only — not wp-admin.', '08600-services-quotations'),
            'icon'    => KIT_Commons::icon('user-group'),
            'content' => $header_actions,
        ));
        ?>
        <hr class="wp-header-end">

        <?php if (is_array($single_result)) : ?>
            <?php
            if (class_exists('KIT_Toast')) {
                KIT_Toast::ensure_toast_loads();
                if (!empty($single_result['ok'])) {
                    if (!empty($single_result['already'])) {
                        $msg = sprintf(
                            __('Customer #%1$d already has portal access (%2$s).', '08600-services-quotations'),
                            (int) $single_result['cust_id'],
                            $single_result['login'] ?? ('cust_' . (int) $single_result['cust_id'])
                        );
                        echo KIT_Toast::show($msg, 'info', __('Already active', '08600-services-quotations'));
                    } elseif (!empty($single_result['send_email'])) {
                        $msg = sprintf(
                            __('Portal account created for customer #%1$d. Login: %2$s. Invite sent to %3$s.', '08600-services-quotations'),
                            (int) $single_result['cust_id'],
                            $single_result['login'] ?? '',
                            $single_result['email'] ?? ''
                        );
                        echo KIT_Toast::show($msg, 'success', __('Activated', '08600-services-quotations'));
                    } else {
                        $msg = sprintf(
                            __('Portal account created for customer #%1$d. Login: %2$s. Invite was not sent.', '08600-services-quotations'),
                            (int) $single_result['cust_id'],
                            $single_result['login'] ?? ''
                        );
                        echo KIT_Toast::show($msg, 'success', __('Activated', '08600-services-quotations'));
                    }
                } else {
                    echo KIT_Toast::show(
                        $single_result['error'] ?? __('Could not activate portal access.', '08600-services-quotations'),
                        'error',
                        __('Activation failed', '08600-services-quotations')
                    );
                }
            } else {
                $notice_class = !empty($single_result['ok']) ? 'notice-success' : 'notice-error';
                echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>';
                if (!empty($single_result['ok'])) {
                    echo esc_html(
                        !empty($single_result['already'])
                            ? sprintf(__('Customer #%d already has portal access.', '08600-services-quotations'), (int) $single_result['cust_id'])
                            : sprintf(__('Portal account created for customer #%d.', '08600-services-quotations'), (int) $single_result['cust_id'])
                    );
                } else {
                    echo esc_html($single_result['error'] ?? __('Could not activate portal access.', '08600-services-quotations'));
                }
                echo '</p></div>';
            }
            ?>
        <?php endif; ?>

        <?php if (is_array($batch_result)) : ?>
            <?php
            $batch_msg = sprintf(
                __('Batch finished: %1$d created, %2$d skipped (max %3$d per run).', '08600-services-quotations'),
                (int) $batch_result['created'],
                (int) $batch_result['skipped'],
                (int) $batch_result['batch_size']
            );
            if (class_exists('KIT_Toast')) {
                KIT_Toast::ensure_toast_loads();
                echo KIT_Toast::show($batch_msg, 'info', __('Batch complete', '08600-services-quotations'));
            } else {
                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($batch_msg) . '</p>';
                if (!empty($batch_result['errors'])) {
                    echo '<ul>';
                    foreach ($batch_result['errors'] as $err) {
                        echo '<li>' . esc_html($err) . '</li>';
                    }
                    echo '</ul>';
                }
                echo '</div>';
            }
            if (!empty($batch_result['errors']) && class_exists('KIT_Toast')) {
                foreach (array_slice($batch_result['errors'], 0, 5) as $err) {
                    echo KIT_Toast::show($err, 'error', __('Batch issue', '08600-services-quotations'));
                }
            }
            ?>
        <?php endif; ?>

        <?php
        echo KIT_QuickStats::render($portal_stats, '', array(
            'grid_cols' => 'grid-cols-1 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-3',
            'gap'       => 'gap-4',
        ));
        ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6 items-start">
            <div class="lg:col-span-2 bg-white rounded-lg shadow-md border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-1"><?php esc_html_e('Find customer', '08600-services-quotations'); ?></h2>
                <p class="text-sm text-gray-500 mb-4"><?php esc_html_e('Search by name, company, email, or customer ID. Confirm the profile, then activate.', '08600-services-quotations'); ?></p>
                <form method="get" class="flex flex-wrap gap-2 items-center">
                    <input type="hidden" name="page" value="08600-customer-portal-accounts" />
                    <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>" />
                    <label class="sr-only" for="kit-portal-q"><?php esc_html_e('Search customers', '08600-services-quotations'); ?></label>
                    <input
                        type="search"
                        name="q"
                        id="kit-portal-q"
                        value="<?php echo esc_attr($search); ?>"
                        placeholder="<?php esc_attr_e('Name, company, email, cust_id…', '08600-services-quotations'); ?>"
                        class="flex-1 min-w-[220px] px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                    <?php
                    echo KIT_Commons::renderButton(__('Search', '08600-services-quotations'), 'primary', 'md', array(
                        'type' => 'submit',
                        'noLoading' => true,
                    ));
                    if ($search !== '') {
                        echo KIT_Commons::renderButton(__('Clear', '08600-services-quotations'), 'secondary', 'md', array(
                            'href' => add_query_arg('view', $view, $page_url),
                            'noLoading' => true,
                        ));
                    }
                    ?>
                </form>

                <?php if (is_array($selected)) : ?>
                    <?php
                    $sel_meta = kit_customer_portal_status_meta($selected['status']);
                    $edit_url = admin_url('admin.php?page=edit-customer&edit_customer=' . (int) $selected['cust_id']);
                    $view_url = admin_url('admin.php?page=08600-customers&view_customer=' . (int) $selected['cust_id']);
                    ?>
                    <div class="mt-5 pt-5 border-t border-gray-200">
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <h3 class="text-base font-semibold text-gray-900">
                                <?php echo esc_html($selected['name'] !== '' ? $selected['name'] : __('(No name)', '08600-services-quotations')); ?>
                            </h3>
                            <?php if ($selected['company'] !== '') : ?>
                                <span class="text-sm text-gray-500">· <?php echo esc_html($selected['company']); ?></span>
                            <?php endif; ?>
                            <span class="text-sm text-gray-400">· #<?php echo (int) $selected['cust_id']; ?></span>
                            <span class="<?php echo esc_attr($sel_meta['class']); ?>"><?php echo esc_html($sel_meta['label']); ?></span>
                        </div>
                        <p class="text-sm text-gray-600 mb-3">
                            <?php esc_html_e('Email:', '08600-services-quotations'); ?>
                            <?php echo esc_html($selected['email'] !== '' ? $selected['email'] : '—'); ?>
                            <span class="mx-2 text-gray-300">|</span>
                            <?php esc_html_e('Login will be:', '08600-services-quotations'); ?>
                            <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded"><?php echo esc_html($selected['login']); ?></code>
                        </p>
                        <?php if ($selected['note'] !== '') : ?>
                            <p class="text-xs text-gray-500 mb-3"><?php echo esc_html($selected['note']); ?></p>
                        <?php endif; ?>
                        <div class="flex flex-wrap gap-2 items-center">
                            <?php if ($selected['status'] === 'ready') : ?>
                                <form method="post" class="inline-flex">
                                    <?php wp_nonce_field('kit_customer_portal_single', 'kit_customer_portal_single_nonce'); ?>
                                    <input type="hidden" name="cust_id" value="<?php echo (int) $selected['cust_id']; ?>" />
                                    <input type="hidden" name="send_email" value="1" />
                                    <?php
                                    echo KIT_Commons::renderButton(
                                        __('Activate & send email', '08600-services-quotations'),
                                        'primary',
                                        'md',
                                        array(
                                            'type'  => 'submit',
                                            'name'  => 'kit_portal_activate_one',
                                            'value' => '1',
                                            'gradient' => true,
                                        )
                                    );
                                    ?>
                                </form>
                            <?php elseif ($selected['status'] === 'active') : ?>
                                <span class="text-sm text-gray-500"><?php esc_html_e('This customer already has portal access.', '08600-services-quotations'); ?></span>
                                <?php if (!empty($selected['user_id'])) : ?>
                                    <?php
                                    echo KIT_Commons::renderButton(
                                        __('View WordPress user', '08600-services-quotations'),
                                        'secondary',
                                        'md',
                                        array(
                                            'href' => get_edit_user_link((int) $selected['user_id']),
                                            'noLoading' => true,
                                        )
                                    );
                                    ?>
                                <?php endif; ?>
                            <?php elseif ($selected['status'] === 'needs_email') : ?>
                                <?php
                                echo KIT_Commons::renderButton(
                                    __('Edit customer email', '08600-services-quotations'),
                                    'primary',
                                    'md',
                                    array('href' => $edit_url, 'gradient' => true, 'noLoading' => true)
                                );
                                ?>
                            <?php else : ?>
                                <?php
                                echo KIT_Commons::renderButton(
                                    __('Edit customer', '08600-services-quotations'),
                                    'secondary',
                                    'md',
                                    array('href' => $edit_url, 'noLoading' => true)
                                );
                                ?>
                            <?php endif; ?>
                            <?php
                            echo KIT_Commons::renderButton(
                                __('Open customer profile', '08600-services-quotations'),
                                'secondary',
                                'md',
                                array('href' => $view_url, 'noLoading' => true)
                            );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-lg shadow-md border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-1"><?php esc_html_e('Batch activate', '08600-services-quotations'); ?></h2>
                <p class="text-sm text-gray-500 mb-4"><?php esc_html_e('Create logins for the next Ready customers and email them.', '08600-services-quotations'); ?></p>
                <form
                    method="post"
                    class="space-y-4"
                    onsubmit="return confirm('<?php echo esc_js(__('This will create portal accounts and may email multiple clients. Continue?', '08600-services-quotations')); ?>');"
                >
                    <?php wp_nonce_field('kit_customer_portal_batch', 'kit_customer_portal_batch_nonce'); ?>
                    <div>
                        <label for="batch_size" class="block text-sm font-medium text-gray-700 mb-1"><?php esc_html_e('Create up to (max 50)', '08600-services-quotations'); ?></label>
                        <input type="number" name="batch_size" id="batch_size" value="20" min="1" max="50" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="batch_send_email" value="1" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                        <?php esc_html_e('Send invite email', '08600-services-quotations'); ?>
                    </label>
                    <?php
                    echo KIT_Commons::renderButton(
                        __('Activate next batch', '08600-services-quotations'),
                        'secondary',
                        'md',
                        array(
                            'type'  => 'submit',
                            'name'  => 'kit_portal_batch_create',
                            'value' => '1',
                            'fullWidth' => true,
                        )
                    );
                    ?>
                </form>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 mb-4">
            <?php foreach ($view_labels as $key => $label) : ?>
                <?php
                $tab_url = add_query_arg('view', $key, $filter_base);
                $active_tab = $view === $key;
                $tab_class = $active_tab
                    ? 'inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200'
                    : 'inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-white text-gray-600 border border-gray-200 hover:bg-gray-50';
                ?>
                <a href="<?php echo esc_url($tab_url); ?>" class="<?php echo esc_attr($tab_class); ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
            <?php
            echo KIT_Unified_Table::infinite($table_rows, $columns, array(
                'title'            => __('Portal accounts', '08600-services-quotations'),
                'searchable'       => true,
                'sortable'         => true,
                'exportable'       => false,
                'bulk_management'  => false,
                'delivery_list_print' => false,
                'groupby'          => null,
                'table_type'       => false,
                'preserve_order'   => true,
                'pagination'       => true,
                'items_per_page'   => 25,
                'empty_message'    => __('No customers match this view.', '08600-services-quotations'),
                'search_placeholder' => __('Search name, company, email…', '08600-services-quotations'),
                'search_filters'   => array(
                    array('value' => 'name', 'label' => __('Client', '08600-services-quotations'), 'placeholder' => __('Search by name…', '08600-services-quotations')),
                    array('value' => 'company', 'label' => __('Company', '08600-services-quotations'), 'placeholder' => __('Search by company…', '08600-services-quotations')),
                    array('value' => 'email', 'label' => __('Email', '08600-services-quotations'), 'placeholder' => __('Search by email…', '08600-services-quotations')),
                    array('value' => 'login', 'label' => __('Login', '08600-services-quotations'), 'placeholder' => __('Search by login…', '08600-services-quotations')),
                ),
                'search_default_filter' => 'name',
                'table_class' => 'w-full table-auto border-collapse kit-waybill-dashboard-table',
                'actions' => array(),
            ));
            ?>
        </div>
    </div>
</div>
