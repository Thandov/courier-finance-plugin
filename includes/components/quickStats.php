<?php
/**
 * QuickStats Component
 * Displays statistics in a single row with clean, modern styling
 */
class KIT_QuickStats {

    /** @var string Pass to render_for_context() / get_stats_for_context() — admin dashboard today KPIs (3) */
    public const CONTEXT_ADMIN_DASHBOARD = 'admin_dashboard';

    /** @var string Delivery pipeline: Scheduled / In transit / Delivered (3) */
    public const CONTEXT_ADMIN_DASHBOARD_DELIVERY_STATUS = 'admin_dashboard_delivery_status';

    /** @var string Employee portal dashboard today KPIs (3) */
    public const CONTEXT_EMPLOYEE_DASHBOARD = 'employee_dashboard';

    /** @var string Employee portal delivery status strip (3) */
    public const CONTEXT_EMPLOYEE_DASHBOARD_DELIVERY_STATUS = 'employee_dashboard_delivery_status';

    /** @var string Waybill manage page (4) */
    public const CONTEXT_WAYBILL_MANAGE = 'waybill_manage';

    /** @var string Warehouse assignment page (4) */
    public const CONTEXT_WAREHOUSE = 'warehouse';

    /** @var string Assign waybills page (2) */
    public const CONTEXT_ASSIGN_WAYBILLS = 'assign_waybills';

    /** @var string Drivers admin (4) */
    public const CONTEXT_DRIVERS = 'drivers';

    /** @var string Countries admin (4) */
    public const CONTEXT_COUNTRIES = 'countries';

    /** @var string Deliveries admin (4) */
    public const CONTEXT_DELIVERIES = 'deliveries';

    /** @var string Customers admin (4) */
    public const CONTEXT_CUSTOMERS = 'customers';

    /**
     * Build stat rows for a screen. See quick-stats-context-data.php for options per context.
     *
     * @param array $options {
     *   @type string $title              Section heading (optional; defaults per context)
     *   @type array  $render_options     Passed to render() (grid_cols, gap, section_class, show_icons)
     *   @type bool   $include_revenue    Employee dashboard: add revenue cards when true
     *   @type bool   $use_portal_urls     Employee dashboard: portal URLs for links
     *   @type array  $deliveries          Pre-fetched deliveries (deliveries context)
     *   @type array  $drivers             Pre-fetched drivers (drivers context)
     *   @type array  $countries           Pre-fetched countries (countries context)
     *   @type array  $customers           Pre-fetched customers (customers context)
     * }
     * @return array<int, array<string, mixed>>
     */
    public static function get_stats_for_context(string $context, array $options = [])
    {
        if (!function_exists('kit_quick_stats_get_stats_for_context')) {
            require_once __DIR__ . '/quick-stats-context-data.php';
        }

        return kit_quick_stats_get_stats_for_context($context, $options);
    }

    /**
     * Render stats for a known screen context (single entry point for all Quick Stat cards).
     *
     * @param array $options Same as get_stats_for_context(), plus optional title and render_options.
     */
    public static function render_for_context(string $context, array $options = [])
    {
        $stats = self::get_stats_for_context($context, $options);
        $title = array_key_exists('title', $options) ? (string) $options['title'] : self::default_title_for_context($context);
        $render = array_merge(self::default_render_options_for_context($context), $options['render_options'] ?? []);

        return self::render($stats, $title, $render);
    }

    /**
     * @return array{grid_cols?:string,gap?:string,section_class?:string}
     */
    private static function default_render_options_for_context(string $context): array
    {
        switch ($context) {
            case self::CONTEXT_ADMIN_DASHBOARD:
            case self::CONTEXT_EMPLOYEE_DASHBOARD:
                return ['grid_cols' => 'grid-cols-1 sm:grid-cols-3', 'gap' => 'gap-4'];
            case self::CONTEXT_ADMIN_DASHBOARD_DELIVERY_STATUS:
            case self::CONTEXT_EMPLOYEE_DASHBOARD_DELIVERY_STATUS:
                return ['grid_cols' => 'grid-cols-1 sm:grid-cols-3', 'gap' => 'gap-4'];
            case self::CONTEXT_WAYBILL_MANAGE:
                return ['grid_cols' => 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-4', 'gap' => 'gap-4', 'section_class' => 'kit-waybill-kpis'];
            case self::CONTEXT_WAREHOUSE:
                return ['grid_cols' => 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-4', 'gap' => 'gap-4', 'section_class' => 'kit-warehouse-kpis'];
            case self::CONTEXT_ASSIGN_WAYBILLS:
                return ['grid_cols' => 'grid-cols-1 md:grid-cols-2', 'gap' => 'gap-6'];
            case self::CONTEXT_DRIVERS:
            case self::CONTEXT_COUNTRIES:
            case self::CONTEXT_CUSTOMERS:
                return ['grid_cols' => 'grid-cols-1 sm:grid-cols-4 md:grid-cols-4 lg:grid-cols-4', 'gap' => 'gap-4'];
            case self::CONTEXT_DELIVERIES:
                return ['grid_cols' => 'grid-cols-2 md:grid-cols-2 lg:grid-cols-4', 'gap' => 'gap-6'];
            default:
                return ['grid_cols' => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4', 'gap' => 'gap-6'];
        }
    }

    private static function default_title_for_context(string $context): string
    {
        switch ($context) {
            case self::CONTEXT_WAYBILL_MANAGE:
                return 'Waybill Overview';
            case self::CONTEXT_WAREHOUSE:
                return 'Warehouse queue';
            default:
                return '';
        }
    }
    
    /**
     * Render quick stats component
     * 
     * @param array $stats Array of stats with 'title', 'value', 'icon', 'color' keys
     * @param string $title Optional section title
     * @return string HTML output
     */
    public static function render($stats = [], $title = '', $options = []) {
        $stats = is_array($stats) ? $stats : [];
        $grid_cols = $options['grid_cols'] ?? 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4';
        $show_icons = $options['show_icons'] ?? true;
        $gap = $options['gap'] ?? 'gap-6';
        $section_class = isset($options['section_class']) ? trim((string) $options['section_class']) : '';
        
        $html = '<div class="mb-8 kit-quick-stats' . ($section_class !== '' ? ' ' . esc_attr($section_class) : '') . '">';
        
        if ($title) {
            $html .= '<div class="flex items-center justify-between mb-4">';
            $html .= '<h3 class="text-lg font-semibold text-gray-900">' . esc_html($title) . '</h3>';
            $html .= '</div>';
        }
        
        $html .= '<div class="grid min-w-0 ' . esc_attr($grid_cols) . ' ' . esc_attr($gap) . '">';
        foreach ($stats as $stat) {
            $has_icon = $show_icons && isset($stat['icon']);
            $color = $stat['color'] ?? 'blue';
            $colorClasses = self::getColorClasses($color);
            
            // Check if stat is clickable
            $href = isset($stat['href']) ? trim((string) $stat['href']) : '';
            $clickable = isset($stat['clickable']) && $stat['clickable'];
            $onclick = isset($stat['onclick']) ? ' onclick="' . esc_js($stat['onclick']) . '"' : '';
            $dataFilter = isset($stat['filter']) ? ' data-filter="' . esc_attr($stat['filter']) . '"' : '';
            $cardFilter = isset($stat['card_filter']) ? trim((string) $stat['card_filter']) : '';
            $is_link = $href !== '' && $cardFilter === '';
            $cardClasses = 'bg-white rounded-xl shadow-sm border border-gray-200 p-6 transition-all duration-200 min-w-0';
            if ($clickable || $cardFilter !== '' || $is_link) {
                $cardClasses .= ' hover:shadow-md hover:border-blue-300 cursor-pointer';
            } else {
                $cardClasses .= ' hover:shadow-md';
            }
            $extraAttrs = '';
            if ($cardFilter !== '') {
                $extraAttrs .= ' data-kit-card-filter="' . esc_attr($cardFilter) . '" tabindex="0" role="button"';
            }
            $tag = $is_link ? 'a' : 'div';
            $href_attr = $is_link ? ' href="' . esc_url($href) . '"' : '';
            $html .= '<' . $tag . ' class="' . $cardClasses . '"' . $href_attr . ($clickable && !$is_link ? $onclick . $dataFilter : '') . $extraAttrs . '>';
            
            if ($has_icon) {
                // Icon + title row; value on next row so tabular-nums align with icon column (same left edge as icon box)
                $html .= '<div class="flex items-start min-w-0 gap-3">';
                $html .= '<div class="flex-shrink-0">';
                $html .= '<div class="w-8 h-8 ' . $colorClasses['icon'] . ' rounded-lg flex items-center justify-center">';
                $html .= '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
                $html .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . esc_attr($stat['icon']) . '"></path>';
                $html .= '</svg>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '<div class="min-w-0 flex-1">';
                $html .= '<p class="text-sm font-medium text-gray-600">' . esc_html($stat['title']) . '</p>';
                $html .= '</div>';
                $html .= '</div>';
                $value_class = isset($stat['class']) ? esc_attr($stat['class']) : '';
                $html .= '<p class="text-xl sm:text-2xl font-bold tabular-nums leading-snug text-gray-900 ' . $value_class . ' mt-2">' . esc_html($stat['value']) . '</p>';
            } else {
                // No icon layout: title on top, value below
                $html .= '<h3 class="text-sm font-medium text-gray-600 mb-2">' . esc_html($stat['title']) . '</h3>';
                $value_class = isset($stat['class']) ? esc_attr($stat['class']) : '';
                $html .= '<p class="text-xl sm:text-2xl font-bold tabular-nums leading-snug text-gray-900 ' . $value_class . '">' . esc_html($stat['value']) . '</p>';
            }
            
            if (isset($stat['subtitle'])) {
                $html .= '<p class="text-xs text-gray-500 mt-2">' . esc_html($stat['subtitle']) . '</p>';
            }
            if (!empty($stat['subtitle_secondary']) && is_string($stat['subtitle_secondary'])) {
                $html .= '<p class="text-xs text-gray-400 mt-0.5">' . esc_html($stat['subtitle_secondary']) . '</p>';
            }
            if (!empty($stat['link_label']) && is_string($stat['link_label'])) {
                $html .= '<p class="kit-stat-view">' . esc_html($stat['link_label']) . '</p>';
            }
            if (!empty($stat['toggle']) && is_array($stat['toggle'])) {
                $tid = isset($stat['toggle']['id']) ? sanitize_key((string) $stat['toggle']['id']) : 'kit-stat-toggle';
                $tlabel = isset($stat['toggle']['label']) ? (string) $stat['toggle']['label'] : '';
                $checked = !empty($stat['toggle']['checked']);
                $html .= '<div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between gap-3">';
                $html .= '<span class="text-xs font-medium text-gray-600 leading-tight">' . esc_html($tlabel) . '</span>';
                $html .= '<label class="inline-flex items-center cursor-pointer shrink-0 gap-2" for="' . esc_attr($tid) . '">';
                $html .= KIT_Commons::Lcheckbox([
                    'no_label' => true,
                    'label' => '',
                    'id' => $tid,
                    'name' => '',
                    'value' => '1',
                    'checked' => $checked,
                    'class' => 'kit-quick-stats-toggle h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500',
                ]);
                $html .= '</label>';
                $html .= '</div>';
            }
            $html .= '</' . $tag . '>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get color classes for different stat types
     * 
     * @param string $color Color name
     * @return array Color classes
     */
    private static function getColorClasses($color) {
        $colors = [
            'blue' => [
                'icon' => 'bg-blue-100 text-blue-600'
            ],
            'green' => [
                'icon' => 'bg-green-100 text-green-600'
            ],
            'yellow' => [
                'icon' => 'bg-yellow-100 text-yellow-600'
            ],
            'purple' => [
                'icon' => 'bg-purple-100 text-purple-600'
            ],
            'red' => [
                'icon' => 'bg-red-100 text-red-600'
            ],
            'gray' => [
                'icon' => 'bg-gray-100 text-gray-600'
            ],
            'orange' => [
                'icon' => 'bg-orange-100 text-orange-600'
            ],
            'indigo' => [
                'icon' => 'bg-indigo-100 text-indigo-600'
            ]
        ];
        
        return $colors[$color] ?? $colors['blue'];
    }
}
