<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . '../user-roles.php';
require_once plugin_dir_path(__FILE__) . '../dashboard/dashboard-functions.php';
require_once plugin_dir_path(__FILE__) . '../components/quickStats.php';
require_once plugin_dir_path(__FILE__) . '../warehouse/warehouse-functions.php';

$upcoming         = KIT_Dashboard::get_upcoming_deliveries(7);
$recent_waybills  = KIT_Dashboard::get_recent_waybills(10);

$deliveries_url = admin_url('admin.php?page=kit-deliveries');
$waybills_url   = admin_url('admin.php?page=08600-waybill-manage');
$create_url     = admin_url('admin.php?page=08600-waybill-create');
?>
<div class="wrap kit-dashboard-wrap kit-dashboard-modern" id="kit-dashboard">
    <?php
    echo KIT_Commons::showingHeader([
        'title' => 'Dashboard',
        'desc'  => 'Overview of waybills, warehouse, deliveries and revenue',
        'icon'  => KIT_Commons::icon('truck'),
        'content' => KIT_Commons::kitButton([
            'href' => $create_url,
            'color' => 'blue',
            'icon' => 'plus',
        ], 'Create Waybill'),
    ]);
    ?>
    <hr class="wp-header-end">

    <div class="kit-dashboard-content">
        <section class="kit-dashboard-hero">
            <div class="kit-dashboard-kpis">
                <?php
                echo KIT_QuickStats::render_for_context(KIT_QuickStats::CONTEXT_ADMIN_DASHBOARD, []);
                ?>
            </div>
            <?php echo KIT_Dashboard::render_month_band([]); ?>
        </section>

        <?php echo KIT_Dashboard::render_delivery_pipeline_strip([]); ?>

        <section class="kit-dashboard-grid kit-dashboard-grid--work">
            <div class="kit-dashboard-col kit-dashboard-col--stack">
                <article class="kit-dashboard-card kit-dashboard-card--list kit-dashboard-card--upcoming">
                    <header class="kit-dashboard-card-header">
                        <h2 class="kit-dashboard-card-title">Upcoming deliveries</h2>
                        <a href="<?php echo esc_url($deliveries_url); ?>" class="kit-dashboard-card-link">View all</a>
                    </header>
                    <div class="kit-dashboard-card-body<?php echo empty($upcoming) ? ' kit-dashboard-card-body--empty' : ''; ?>">
                        <?php if (empty($upcoming)) : ?>
                            <?php echo KIT_Dashboard::render_upcoming_empty([]); ?>
                        <?php else : ?>
                            <ul class="kit-dashboard-list">
                                <?php foreach ($upcoming as $d) : ?>
                                    <?php
                                    $route = esc_html(($d->origin_country_name ?? '') . ' → ' . ($d->destination_country_name ?? ''));
                                    $date  = !empty($d->dispatch_date) && $d->dispatch_date !== '0000-00-00'
                                        ? date('M j, Y', strtotime($d->dispatch_date))
                                        : '';
                                    $view_url = admin_url('admin.php?page=view-deliveries&delivery_id=' . (int) $d->id);
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
                                $view_url = admin_url('admin.php?page=08600-Waybill-view&waybill_id=' . (int) $w->waybill_id);
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
