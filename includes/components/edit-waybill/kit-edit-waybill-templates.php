<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Edit-waybill screen layouts (same save action, different chrome).
 * Template 1 = current card grid. Template 2 = flat document + totals rail.
 */

if (!function_exists('kit_edit_waybill_default_template_id')) {
    function kit_edit_waybill_default_template_id(): string
    {
        return 'edit_waybill_template1';
    }
}

if (!function_exists('kit_edit_waybill_allowed_template_ids')) {
    /** @return list<string> */
    function kit_edit_waybill_allowed_template_ids(): array
    {
        return ['edit_waybill_template1', 'edit_waybill_template2'];
    }
}

if (!function_exists('kit_edit_waybill_normalize_template_id')) {
    function kit_edit_waybill_normalize_template_id($raw): string
    {
        $v = strtolower(trim((string) $raw));
        if ($v === '2' || $v === 'flat' || $v === 'template2' || $v === 'edit_waybill_template2') {
            return 'edit_waybill_template2';
        }
        if ($v === '1' || $v === 'cards' || $v === 'template1' || $v === 'edit_waybill_template1') {
            return 'edit_waybill_template1';
        }
        return kit_edit_waybill_default_template_id();
    }
}

if (!function_exists('kit_edit_waybill_get_active_template_id')) {
    function kit_edit_waybill_get_active_template_id(): string
    {
        if (isset($_GET['kit_edit_tpl'])) {
            $from_query = kit_edit_waybill_normalize_template_id(wp_unslash((string) $_GET['kit_edit_tpl']));
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                update_user_meta($user_id, 'kit_waybill_layout', $from_query);
            }
            return $from_query;
        }

        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $from_user = get_user_meta($user_id, 'kit_waybill_layout', true);
            if (is_string($from_user) && $from_user !== '') {
                $normalized = kit_edit_waybill_normalize_template_id($from_user);
                if (in_array($normalized, kit_edit_waybill_allowed_template_ids(), true)) {
                    return $normalized;
                }
            }
        }

        $from_option = kit_edit_waybill_default_template_id();
        if (function_exists('get_option')) {
            $stored_option = get_option('kit_waybill_layout', '');
            if (is_string($stored_option) && $stored_option !== '') {
                $from_option = $stored_option;
            }
        }

        $normalized = kit_edit_waybill_normalize_template_id($from_option);
        if (!in_array($normalized, kit_edit_waybill_allowed_template_ids(), true)) {
            return kit_edit_waybill_default_template_id();
        }

        return $normalized;
    }
}

if (!function_exists('kit_edit_waybill_template_path')) {
    function kit_edit_waybill_template_path(string $template_id): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . $template_id . '.php';
    }
}

if (!function_exists('kit_edit_waybill_switch_url')) {
    function kit_edit_waybill_switch_url(string $template_id): string
    {
        $short = $template_id === 'edit_waybill_template2' ? '2' : '1';
        return add_query_arg('kit_edit_tpl', $short);
    }
}

if (!function_exists('kit_edit_waybill_render_switcher')) {
    function kit_edit_waybill_render_switcher(string $active_id): void
    {
        $items = [
            'edit_waybill_template1' => 'Cards',
            'edit_waybill_template2' => 'Simple page',
        ];
        echo '<nav class="kit-edit-wb-switcher" aria-label="Waybill layout">';
        foreach ($items as $id => $label) {
            $is_active = $id === $active_id;
            echo '<a class="kit-edit-wb-switcher__item' . ($is_active ? ' is-active' : '') . '" href="' . esc_url(kit_edit_waybill_switch_url($id)) . '"'
                . ($is_active ? ' aria-current="page"' : '') . '>'
                . esc_html($label)
                . '</a>';
        }
        echo '</nav>';
        echo '<style>
            .kit-edit-wb-switcher{display:inline-flex;border:1px solid #a1a1aa;margin:0 0 14px;}
            .kit-edit-wb-switcher__item{font-size:15px;padding:10px 16px;min-height:44px;display:inline-flex;align-items:center;color:#3f3f46;text-decoration:none;background:#fff;}
            .kit-edit-wb-switcher__item:hover{color:#111;background:#f4f4f5;}
            .kit-edit-wb-switcher__item.is-active{background:#111;color:#fff;font-weight:700;}
        </style>';
    }
}
