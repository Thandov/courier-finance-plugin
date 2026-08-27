<?php
/**
 * Frontend Employee Dashboard
 * Renders the 08600 dashboard on the frontend for employees (no prices for non-admin).
 *
 * @package CourierFinancePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sidebar menu items for employee dashboard (no price-only or admin-only pages).
 * All items require kit_view_waybills; Settings requires kit_access_settings.
 *
 * @return array[] List of menu items: [ 'label', 'url', 'icon' (optional), 'active' (optional) ]
 */
function kit_employee_dashboard_sidebar_menu() {
    $dashboard_url = apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
    $portal_url = function_exists('kit_employee_portal_url') ? 'kit_employee_portal_url' : null;
    $is_adman = class_exists('KIT_User_Roles') && KIT_User_Roles::is_adman();
    $menu = array(
        array('label' => __('Dashboard', '08600-services-quotations'), 'url' => $dashboard_url, 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'),
        array('label' => __('Waybills', '08600-services-quotations'), 'children' => array(
            array('label' => __('Create Waybill', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('08600-waybill-create') : admin_url('admin.php?page=08600-waybill-create')),
            array('label' => __('Manage Waybills', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('08600-waybill-manage') : admin_url('admin.php?page=08600-waybill-manage')),
        )),
        array('label' => __('Warehouse', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('warehouse-waybills') : admin_url('admin.php?page=warehouse-waybills'), 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'),
        array('label' => __('Trips', '08600-services-quotations'), 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4', 'children' => array(
            array('label' => __('Create Trip', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('08600-trip-create') : admin_url('admin.php?page=08600-trip-create')),
            array('label' => __('Trips', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('kit-deliveries') : admin_url('admin.php?page=kit-deliveries')),
        )),
        array('label' => __('Customers', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('08600-customers') : admin_url('admin.php?page=08600-customers'), 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z'),
    );
    // Only admin and manager (ADMAN) see Countries, Drivers, Routes & Destinations
    if ($is_adman) {
        $menu[] = array('label' => __('Routes & Destinations', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('route-management') : admin_url('admin.php?page=route-management'), 'icon' => 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-1.447-.894L15 4m0 13V4m-6 3l6-3');
        $menu[] = array('label' => __('Drivers', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('manage-drivers') : admin_url('admin.php?page=manage-drivers'), 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z');
        $menu[] = array('label' => __('Countries', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('08600-countries') : admin_url('admin.php?page=08600-countries'), 'icon' => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z');
    }
    $menu[] = array('label' => __('Help', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('08600-help') : admin_url('admin.php?page=08600-help'), 'icon' => 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.22 0 4 1.78 4 4 0 1.1-.45 2.1-1.17 2.83L12 17l-2.83-2.83A3.99 3.99 0 018 13c0-2.22 1.78-4 4-4z');
    if (current_user_can('kit_access_settings')) {
        $menu[] = array('label' => __('Settings', '08600-services-quotations'), 'url' => $portal_url ? kit_employee_portal_url('08600-settings') : admin_url('admin.php?page=08600-settings'), 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z');
    }
    return apply_filters('kit_employee_dashboard_sidebar_menu', $menu);
}

/**
 * Render the portal layout with sidebar (same as dashboard) and inject main content.
 * Used for dashboard and for section pages (Warehouse, Waybills, etc.) so they all have the sidebar.
 *
 * @param string $main_content   HTML to show in the main area.
 * @param string $current_section Current section slug (e.g. warehouse-waybills) or '' for dashboard.
 * @return string
 */
function kit_employee_portal_layout_with_sidebar($main_content, $current_section = '') {
    $dashboard_url = apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
    $sidebar_menu = kit_employee_dashboard_sidebar_menu();
    $is_dashboard_page = ($current_section === '' || $current_section === '08600-dashboard');

    ob_start();
    include plugin_dir_path(__FILE__) . 'employee-portal-sidebar-layout.php';
    return ob_get_clean();
}

/**
 * Render employee dashboard shortcode
 *
 * @return string
 */
function kit_employee_dashboard_shortcode() {
    // Must be logged in with plugin access
    if (!is_user_logged_in() || !KIT_User_Roles::can_view_waybills()) {
        $login_url = apply_filters('kit_employee_login_url', home_url('/employee-login/'));
        return '<p>' . esc_html__('Please log in to access the dashboard.', '08600-services-quotations') . ' <a href="' . esc_url($login_url) . '">' . esc_html__('Log in', '08600-services-quotations') . '</a></p>';
    }

    require_once plugin_dir_path(__FILE__) . '../user-roles.php';
    require_once plugin_dir_path(__FILE__) . '../dashboard/dashboard-functions.php';
    require_once plugin_dir_path(__FILE__) . '../components/quickStats.php';
    require_once plugin_dir_path(__FILE__) . '../warehouse/warehouse-functions.php';

    $upcoming         = KIT_Dashboard::get_upcoming_deliveries(7);
    $recent_waybills  = KIT_Dashboard::get_recent_waybills(10);

    $can_see_prices = class_exists('KIT_User_Roles') && KIT_User_Roles::can_see_prices();

    $use_portal = function_exists('kit_employee_portal_url');
    $deliveries_url = $use_portal ? kit_employee_portal_url('kit-deliveries') : admin_url('admin.php?page=kit-deliveries');
    $waybills_url   = $use_portal ? kit_employee_portal_url('08600-waybill-manage') : admin_url('admin.php?page=08600-waybill-manage');
    $create_url     = $use_portal ? kit_employee_portal_url('08600-waybill-create') : admin_url('admin.php?page=08600-waybill-create');

    $desc = $can_see_prices
        ? 'Overview of waybills, warehouse, deliveries and revenue'
        : 'Overview of waybills, warehouse and deliveries';

    $sidebar_menu = kit_employee_dashboard_sidebar_menu();
    $dashboard_url = apply_filters('kit_employee_dashboard_url', home_url('/employee-dashboard/'));
    $current_section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
    $is_dashboard_page = ($current_section === '' || $current_section === '08600-dashboard');

    ob_start();
    ?>
    <style type="text/css">
    /* No top navbar space on portal; theme can override with body { --kit-employee-header-height: 56px; } if needed */
    :root { --kit-employee-header-height: 0; }
    /* Force sidebar layout — theme must not override. Dashboard uses full viewport. */
    .kit-employee-dashboard-with-sidebar {
        display: flex !important;
        flex-direction: row !important;
        min-height: 100vh !important;
        width: 100vw !important;
        max-width: 100vw !important;
        margin-left: calc(-50vw + 50%) !important;
        margin-right: 0 !important;
        margin-top: 0 !important;
        padding: 0 !important;
        padding-top: var(--kit-employee-header-height, 0) !important;
        background: #f1f5f9 !important;
        box-sizing: border-box !important;
    }
    .kit-employee-dashboard-sidebar {
        width: 260px !important;
        min-width: 260px !important;
        max-width: 260px !important;
        display: flex !important;
        flex-shrink: 0 !important;
        flex-direction: column !important;
        position: sticky !important;
        top: 0 !important;
        height: 100vh !important;
        min-height: 100vh !important;
        background: #ffffff !important;
        border-right: 1px solid #e2e8f0 !important;
        box-sizing: border-box !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    .kit-employee-dashboard-main {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        display: block !important;
        padding-left: 0 !important;
    }
    /* Ensure theme content wrapper doesn't clip or constrain */
    .entry-content-page .kit-employee-dashboard-with-sidebar,
    #content .kit-employee-dashboard-with-sidebar,
    #container .kit-employee-dashboard-with-sidebar {
        display: flex !important;
        width: 100vw !important;
        margin-left: calc(-50vw + 50%) !important;
    }
    /* Mobile: sidebar is fixed and off-screen so it does NOT reserve space; main fills full width */
    @media (max-width: 1023px) {
        .kit-employee-dashboard-sidebar {
            position: fixed !important;
            left: 0 !important;
            top: 0 !important;
            transform: translateX(-100%) !important;
            width: 260px !important;
            min-width: 260px !important;
            height: 100vh !important;
            min-height: 100vh !important;
            z-index: 100 !important;
        }
        .kit-employee-dashboard-with-sidebar.kit-employee-sidebar-open .kit-employee-dashboard-sidebar {
            transform: translateX(0) !important;
        }
        .kit-employee-dashboard-main {
            width: 100% !important;
            max-width: 100% !important;
            flex: 1 1 100% !important;
            min-width: 0 !important;
        }
    }
    </style>
    <div class="kit-employee-dashboard-wrap kit-employee-dashboard-with-sidebar" id="kit-employee-dashboard-wrap">
        <button type="button" class="kit-employee-dashboard-menu-toggle" id="kit-employee-sidebar-toggle" aria-label="<?php esc_attr_e('Open menu', '08600-services-quotations'); ?>">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <aside class="kit-employee-dashboard-sidebar" id="kit-employee-dashboard-sidebar" aria-label="<?php esc_attr_e('Dashboard navigation', '08600-services-quotations'); ?>">
            <div class="kit-employee-dashboard-sidebar-inner">
                <div class="kit-employee-dashboard-sidebar-brand">
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="kit-employee-dashboard-sidebar-logo">08600</a>
                    <span class="kit-employee-dashboard-sidebar-user"><?php echo esc_html(wp_get_current_user()->display_name); ?></span>
                </div>
                <nav class="kit-employee-dashboard-sidebar-nav">
                    <?php
                    foreach ($sidebar_menu as $item) {
                        if (!empty($item['children'])) {
                            $active = false;
                            foreach ($item['children'] as $child) {
                                if (!empty($child['url']) && strpos($child['url'], 'section=') !== false && $current_section !== '' && strpos($child['url'], 'section=' . $current_section) !== false) {
                                    $active = true;
                                    break;
                                }
                            }
                            ?>
                            <div class="kit-employee-dashboard-sidebar-group">
                                <span class="kit-employee-dashboard-sidebar-group-title"><?php echo esc_html($item['label']); ?></span>
                                <?php foreach ($item['children'] as $child) :
                                    $url = !empty($child['url']) ? $child['url'] : '#';
                                    $is_active = $current_section !== '' && strpos($url, 'section=' . $current_section) !== false;
                                ?>
                                    <a href="<?php echo esc_url($url); ?>" class="kit-employee-dashboard-sidebar-link<?php echo $is_active ? ' is-active' : ''; ?>"><?php echo esc_html($child['label']); ?></a>
                                <?php endforeach; ?>
                            </div>
                            <?php
                            continue;
                        }
                        $url = !empty($item['url']) ? $item['url'] : '#';
                        $is_active = ($item['label'] === __('Dashboard', '08600-services-quotations') && $is_dashboard_page) || ($current_section !== '' && strpos($url, 'section=' . $current_section) !== false);
                        ?>
                        <a href="<?php echo esc_url($url); ?>" class="kit-employee-dashboard-sidebar-link<?php echo $is_active ? ' is-active' : ''; ?>">
                            <?php echo esc_html($item['label']); ?>
                        </a>
                    <?php } ?>
                </nav>
                <div class="kit-employee-dashboard-sidebar-footer">
                    <button type="button" class="kit-employee-dashboard-sidebar-link kit-employee-dashboard-close-sidebar" id="kit-employee-close-sidebar" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;font:inherit;"><?php esc_html_e('Close menu', '08600-services-quotations'); ?></button>
                    <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>" class="kit-employee-dashboard-sidebar-link kit-employee-dashboard-sidebar-logout"><?php esc_html_e('Log out', '08600-services-quotations'); ?></a>
                </div>
            </div>
        </aside>
        <div class="kit-employee-dashboard-main">
            <div class="wrap kit-dashboard-wrap kit-dashboard-modern" id="kit-dashboard">
                <?php
                echo KIT_Commons::showingHeader([
                    'title' => 'Dashboard',
                    'desc'  => $desc,
                    'icon'  => KIT_Commons::icon('truck'),
                    'content' => KIT_Commons::kitButton([
                        'href' => $create_url,
                        'color' => 'blue',
                        'icon' => 'plus',
                    ], 'Create Waybill'),
                ]);
                ?>
                <div class="kit-employee-dashboard-header">
                    <span class="kit-employee-greeting"><?php echo esc_html(sprintf(__('Hello, %s', '08600-services-quotations'), wp_get_current_user()->display_name)); ?></span>
                </div>

                <div class="kit-dashboard-content">
                <section class="kit-dashboard-hero">
                    <div class="kit-dashboard-kpis">
                        <?php
                        echo KIT_QuickStats::render_for_context(KIT_QuickStats::CONTEXT_EMPLOYEE_DASHBOARD, [
                            'use_portal_urls' => true,
                        ]);
                        ?>
                    </div>
                    <?php
                    echo KIT_Dashboard::render_month_band([
                        'include_revenue' => $can_see_prices,
                        'use_portal_urls' => true,
                    ]);
                    ?>
                </section>

                <?php echo KIT_Dashboard::render_delivery_pipeline_strip(['use_portal_urls' => true]); ?>

                <section class="kit-dashboard-grid kit-dashboard-grid--work">
                    <div class="kit-dashboard-col kit-dashboard-col--stack">
                        <article class="kit-dashboard-card kit-dashboard-card--list kit-dashboard-card--upcoming">
                            <header class="kit-dashboard-card-header">
                                <h2 class="kit-dashboard-card-title">Upcoming deliveries</h2>
                                <a href="<?php echo esc_url($deliveries_url); ?>" class="kit-dashboard-card-link">View all</a>
                            </header>
                            <div class="kit-dashboard-card-body<?php echo empty($upcoming) ? ' kit-dashboard-card-body--empty' : ''; ?>">
                                <?php if (empty($upcoming)) : ?>
                                    <?php echo KIT_Dashboard::render_upcoming_empty(['use_portal_urls' => true]); ?>
                                <?php else : ?>
                                    <ul class="kit-dashboard-list">
                                        <?php foreach ($upcoming as $d) : ?>
                                            <?php
                                            $route = esc_html(($d->origin_country_name ?? '') . ' → ' . ($d->destination_country_name ?? ''));
                                            $date  = !empty($d->dispatch_date) && $d->dispatch_date !== '0000-00-00'
                                                ? date('M j, Y', strtotime($d->dispatch_date))
                                                : '';
                                            $view_url = $use_portal ? kit_employee_portal_url('view-deliveries', array('delivery_id' => (int) $d->id)) : admin_url('admin.php?page=view-deliveries&delivery_id=' . (int) $d->id);
                                            $driver_display = trim(implode(' · ', array_filter([$d->truck_number ?? '', $d->driver_name ?? ''])));
                                            ?>
                                            <li>
                                                <a href="<?php echo esc_url($view_url); ?>" class="kit-dashboard-list-item">
                                                    <div class="kit-dashboard-list-item-main">
                                                        <span class="kit-dashboard-list-item-title"><?php echo esc_html($d->delivery_reference); ?></span>
                                                        <span class="kit-dashboard-list-item-meta"><?php echo esc_html($date); ?></span>
                                                    </div>
                                                    <div class="kit-dashboard-list-item-sub"><?php echo $route; ?></div>
                                                    <?php if ($driver_display !== '') : ?>
                                                        <div class="kit-dashboard-list-item-extra"><?php echo esc_html($driver_display); ?></div>
                                                    <?php endif; ?>
                                                    <span class="kit-dashboard-list-item-badge"><?php echo (int) $d->waybill_count; ?> waybills</span>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </article>

                        <article class="kit-dashboard-card kit-dashboard-card--map">
                            <header class="kit-dashboard-card-header">
                                <div>
                                    <h2 class="kit-dashboard-card-title">Route map</h2>
                                    <p class="kit-dashboard-card-desc">Planned delivery routes (next 7 days)</p>
                                </div>
                            </header>
                            <div id="kit-dashboard-map" class="kit-dashboard-map-inner"></div>
                        </article>
                    </div>

                    <article class="kit-dashboard-card kit-dashboard-card--list kit-dashboard-card--fill">
                        <header class="kit-dashboard-card-header">
                            <h2 class="kit-dashboard-card-title">Recent waybills</h2>
                            <a href="<?php echo esc_url($waybills_url); ?>" class="kit-dashboard-card-link">View all</a>
                        </header>
                        <div class="kit-dashboard-card-body">
                            <?php if (empty($recent_waybills)) : ?>
                                <p class="kit-dashboard-empty">No waybills yet.</p>
                            <?php else : ?>
                                <ul class="kit-dashboard-waybill-list">
                                    <?php foreach ($recent_waybills as $w) : ?>
                                        <?php
                                        $view_url = $use_portal ? kit_employee_portal_url('08600-Waybill-view', array('waybill_id' => (int) $w->waybill_id)) : admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . (int) $w->waybill_id);
                                        $name = KIT_Dashboard::waybill_party_display_name($w);
                                        $created = !empty($w->created_at) ? date_i18n('M j', strtotime($w->created_at)) : '';
                                        $status = (string) ($w->status ?? '');
                                        $status_class = KIT_Dashboard::status_pill_modifier($status);
                                        ?>
                                        <li class="kit-dashboard-waybill-item">
                                            <a href="<?php echo esc_url($view_url); ?>" class="kit-dashboard-waybill-item-link">
                                                <span class="kit-dashboard-waybill-id"><?php echo esc_html($w->waybill_no); ?></span>
                                                <span class="kit-dashboard-waybill-copy">
                                                    <span class="kit-dashboard-waybill-name"><?php echo esc_html($name ?: '—'); ?></span>
                                                    <span class="kit-dashboard-waybill-dest"><?php echo esc_html($w->destination ?: '—'); ?></span>
                                                </span>
                                                <span class="kit-dashboard-waybill-aside">
                                                    <span class="kit-dashboard-waybill-date"><?php echo esc_html($created); ?></span>
                                                    <?php if ($status !== '') : ?>
                                                        <span class="kit-dashboard-status-pill kit-dashboard-status-pill--inline <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status); ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </article>
                </section>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var wrap = document.getElementById('kit-employee-dashboard-wrap');
        var toggle = document.getElementById('kit-employee-sidebar-toggle');
        var closeBtn = document.getElementById('kit-employee-close-sidebar');
        var sidebar = document.getElementById('kit-employee-dashboard-sidebar');
        function openSidebar() { if (wrap) wrap.classList.add('kit-employee-sidebar-open'); }
        function closeSidebar() { if (wrap) wrap.classList.remove('kit-employee-sidebar-open'); }
        if (toggle) toggle.addEventListener('click', openSidebar);
        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
        if (sidebar) {
            sidebar.querySelectorAll('a').forEach(function(a) { a.addEventListener('click', function() { closeSidebar(); }); });
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}
