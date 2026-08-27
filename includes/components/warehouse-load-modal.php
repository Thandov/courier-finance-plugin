<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delivery-view modal: pick warehouse waybills (multi-select) and load them onto this truck.
 *
 * @param object $delivery Delivery row from KIT_Deliveries::get_delivery().
 * @return string Button + modal markup.
 */
function kit_render_warehouse_load_modal($delivery)
{
    if (! class_exists('KIT_Warehouse')) {
        require_once dirname(__DIR__) . '/warehouse/warehouse-functions.php';
    }
    if (! class_exists('KIT_Modal')) {
        require_once __DIR__ . '/modal.php';
    }

    $delivery_id = (int) ($delivery->id ?? $delivery->delivery_id ?? 0);
    if ($delivery_id <= 0) {
        return '';
    }

    $items = KIT_Warehouse::getWarehouseItems();
    $dest_country_id = (int) ($delivery->destination_country_id ?? 0);
    $dest_country = trim((string) ($delivery->destination_country ?? ''));
    $truck_ref = trim((string) ($delivery->delivery_reference ?? ''));
    $dispatch = ! empty($delivery->dispatch_date) ? date_i18n('j M Y', strtotime($delivery->dispatch_date)) : '';

    $cards = [];
    foreach ((array) $items as $row) {
        $person = class_exists('KIT_Customers')
            ? KIT_Customers::format_person_display_name(
                (string) ($row->customer_name ?? ''),
                (string) ($row->customer_surname ?? '')
            )
            : trim((string) ($row->customer_name ?? '') . ' ' . (string) ($row->customer_surname ?? ''));
        $company = trim((string) ($row->company_name ?? ''));
        if ($company !== '' && strcasecmp($company, $person) === 0) {
            $company = '';
        }
        $customer_label = $person !== '' ? $person : ($company !== '' ? $company : __('No customer', '08600-services-quotations'));
        $city = trim((string) ($row->destination_city ?? ''));
        $country = trim((string) ($row->destination_country ?? ''));
        $destination = trim(implode(', ', array_filter([$city, $country], static function ($part) {
            return $part !== '';
        })));
        $created = ! empty($row->created_at) ? date_i18n('j M Y', strtotime($row->created_at)) : '';
        $created_by = trim((string) ($row->created_by_name ?? ''));
        if ($created_by === '') {
            $created_by = trim((string) ($row->created_by_login ?? ''));
        }
        $search = strtolower(implode(' ', array_filter([
            (string) ($row->waybill_no ?? ''),
            $person,
            $company,
            $city,
            $country,
            $created_by,
        ])));
        $cards[] = [
            'id'             => (int) ($row->id ?? 0),
            'waybill_no'     => (string) ($row->waybill_no ?? ''),
            'customer'       => $customer_label,
            'company'        => ($company !== '' && $company !== $customer_label) ? $company : '',
            'destination'    => $destination,
            'country_id'     => (int) ($row->destination_country_id ?? 0),
            'date'           => $created,
            'created_by'     => $created_by,
            'search'         => $search,
            'route_match'    => $dest_country_id > 0 && (int) ($row->destination_country_id ?? 0) === $dest_country_id,
        ];
    }

    usort($cards, static function ($a, $b) {
        if ($a['route_match'] !== $b['route_match']) {
            return $a['route_match'] ? -1 : 1;
        }
        return strcmp((string) $b['date'], (string) $a['date']);
    });

    $nonce = wp_create_nonce('kit_warehouse_load_truck');
    $ajax_url = admin_url('admin-ajax.php');

    ob_start();
    ?>
    <div class="kit-wh-load"
        data-delivery-id="<?php echo esc_attr((string) $delivery_id); ?>"
        data-nonce="<?php echo esc_attr($nonce); ?>"
        data-ajax="<?php echo esc_url($ajax_url); ?>"
        data-dest-country-id="<?php echo esc_attr((string) $dest_country_id); ?>">
        <div class="kit-wh-load__toolbar">
            <label class="kit-wh-load__search-wrap">
                <span class="screen-reader-text"><?php esc_html_e('Search warehouse waybills', '08600-services-quotations'); ?></span>
                <input type="search" class="kit-wh-load__search" placeholder="<?php esc_attr_e('Search waybill, customer, company, destination…', '08600-services-quotations'); ?>" autocomplete="off">
            </label>
            <?php if ($dest_country_id > 0) : ?>
                <label class="kit-wh-load__filter">
                    <input type="checkbox" class="kit-wh-load__route-only" checked>
                    <span><?php echo esc_html(sprintf(__('This destination (%s)', '08600-services-quotations'), $dest_country !== '' ? $dest_country : (string) $dest_country_id)); ?></span>
                </label>
            <?php endif; ?>
            <div class="kit-wh-load__sel">
                <button type="button" class="kit-wh-load__text-btn kit-wh-load__select-all"><?php esc_html_e('Select all', '08600-services-quotations'); ?></button>
                <button type="button" class="kit-wh-load__text-btn kit-wh-load__clear"><?php esc_html_e('Clear', '08600-services-quotations'); ?></button>
                <span class="kit-wh-load__count" aria-live="polite">0 selected</span>
            </div>
        </div>

        <div class="kit-wh-load__body">
            <div class="kit-wh-load__grid" role="listbox" aria-multiselectable="true" aria-label="<?php esc_attr_e('Warehoused waybills', '08600-services-quotations'); ?>">
                <?php if (empty($cards)) : ?>
                    <div class="kit-wh-load__empty"><?php esc_html_e('No waybills are waiting in warehouse.', '08600-services-quotations'); ?></div>
                <?php else : ?>
                    <?php foreach ($cards as $card) : ?>
                        <article
                            class="kit-wh-load__card<?php echo $card['route_match'] ? ' is-route' : ''; ?>"
                            role="option"
                            aria-selected="false"
                            tabindex="0"
                            data-id="<?php echo esc_attr((string) $card['id']); ?>"
                            data-search="<?php echo esc_attr($card['search']); ?>"
                            data-country-id="<?php echo esc_attr((string) $card['country_id']); ?>"
                            data-waybill="<?php echo esc_attr($card['waybill_no'] !== '' ? $card['waybill_no'] : '#' . $card['id']); ?>"
                            data-who="<?php echo esc_attr($card['customer']); ?>"
                            data-city="<?php echo esc_attr($card['destination'] !== '' ? $card['destination'] : '—'); ?>">
                            <div class="kit-wh-load__card-check" aria-hidden="true"></div>
                            <p class="kit-wh-load__wb"><?php echo esc_html($card['waybill_no'] !== '' ? $card['waybill_no'] : '#' . $card['id']); ?></p>
                            <p class="kit-wh-load__customer"><?php echo esc_html($card['customer']); ?></p>
                            <?php if ($card['company'] !== '') : ?>
                                <p class="kit-wh-load__company"><?php echo esc_html($card['company']); ?></p>
                            <?php endif; ?>
                            <dl class="kit-wh-load__facts">
                                <div>
                                    <dt><?php esc_html_e('Created', '08600-services-quotations'); ?></dt>
                                    <dd><?php echo $card['date'] !== '' ? esc_html($card['date']) : '—'; ?></dd>
                                </div>
                                <div>
                                    <dt><?php esc_html_e('By', '08600-services-quotations'); ?></dt>
                                    <dd><?php echo $card['created_by'] !== '' ? esc_html($card['created_by']) : '—'; ?></dd>
                                </div>
                                <?php if ($card['destination'] !== '') : ?>
                                    <div>
                                        <dt><?php esc_html_e('To', '08600-services-quotations'); ?></dt>
                                        <dd><?php echo esc_html($card['destination']); ?></dd>
                                    </div>
                                <?php endif; ?>
                            </dl>
                        </article>
                    <?php endforeach; ?>
                    <div class="kit-wh-load__empty kit-wh-load__empty--filter hidden"><?php esc_html_e('No warehouse waybills match this filter.', '08600-services-quotations'); ?></div>
                <?php endif; ?>
            </div>

            <aside class="kit-wh-load__truck">
                <header class="kit-wh-load__truck-head">
                    <p class="kit-wh-load__truck-kicker"><?php esc_html_e('This truck', '08600-services-quotations'); ?></p>
                    <p class="kit-wh-load__truck-ref"><?php echo esc_html($truck_ref !== '' ? $truck_ref : 'Delivery #' . $delivery_id); ?></p>
                    <?php if ($dispatch !== '') : ?>
                        <p class="kit-wh-load__truck-sub"><?php echo esc_html($dispatch); ?><?php echo $dest_country !== '' ? ' · ' . esc_html($dest_country) : ''; ?></p>
                    <?php endif; ?>
                </header>
                <div class="kit-wh-load__truck-body">
                    <p class="kit-wh-load__truck-hint"><?php esc_html_e('Select waybills, then press Load onto truck.', '08600-services-quotations'); ?></p>
                    <ul class="kit-wh-load__manifest" hidden aria-label="<?php esc_attr_e('Selected waybills', '08600-services-quotations'); ?>"></ul>
                </div>
                <footer class="kit-wh-load__truck-foot">
                    <?php
                    echo KIT_Commons::renderButton(
                        __('Load onto truck', '08600-services-quotations'),
                        'primary',
                        'md',
                        [
                            'type'           => 'button',
                            'classes'        => 'kit-wh-load__assign w-full',
                            'fullWidth'      => true,
                            'disabled'       => true,
                            'loadingOnClick' => true,
                        ]
                    );
                    ?>
                    <p class="kit-wh-load__status" aria-live="polite"></p>
                </footer>
            </aside>
        </div>
    </div>
    <style>
        .kit-wh-load { display: flex; flex-direction: column; gap: 14px; min-height: 420px; color: #111827; }
        .kit-wh-load__toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; }
        .kit-wh-load__search-wrap { flex: 1 1 240px; min-width: 200px; }
        .kit-wh-load__search { width: 100%; max-width: none; height: 38px; padding: 0 12px; border: 1px solid #d1d5db; background: #fff; font-size: 13px; color: #111827; box-sizing: border-box; }
        .kit-wh-load__search:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .kit-wh-load__filter { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #374151; cursor: pointer; user-select: none; }
        .kit-wh-load__sel { display: flex; align-items: center; gap: 12px; margin-left: auto; font-size: 13px; }
        .kit-wh-load__text-btn { background: none; border: 0; padding: 0; color: #2563eb; font-size: 13px; font-weight: 600; cursor: pointer; }
        .kit-wh-load__text-btn:hover { color: #1d4ed8; text-decoration: underline; }
        .kit-wh-load__count { color: #6b7280; font-variant-numeric: tabular-nums; }
        .kit-wh-load__body { display: grid; grid-template-columns: minmax(0, 1fr) 260px; gap: 16px; min-height: 360px; }
        .kit-wh-load__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; align-content: start; max-height: 62vh; overflow: auto; padding: 2px; }
        .kit-wh-load__empty { grid-column: 1 / -1; padding: 36px 12px; text-align: center; color: #6b7280; font-size: 13px; border: 1px dashed #d1d5db; background: #fff; }
        .kit-wh-load__empty.hidden { display: none; }
        .kit-wh-load__card { position: relative; margin: 0; padding: 12px 14px 12px 36px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; user-select: none; min-width: 0; }
        .kit-wh-load__card:hover { border-color: #93c5fd; }
        .kit-wh-load__card:focus { outline: 2px solid #2563eb; outline-offset: 1px; }
        .kit-wh-load__card.is-selected { border-color: #2563eb; background: #eff6ff; }
        .kit-wh-load__card.is-hidden { display: none; }
        .kit-wh-load__card-check { position: absolute; left: 12px; top: 14px; width: 14px; height: 14px; border: 1px solid #9ca3af; background: #fff; }
        .kit-wh-load__card.is-selected .kit-wh-load__card-check { background: #2563eb; border-color: #2563eb; box-shadow: inset 0 0 0 2px #fff; }
        .kit-wh-load__wb { margin: 0 0 6px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; font-weight: 700; color: #1d4ed8; overflow-wrap: anywhere; }
        .kit-wh-load__customer { margin: 0; font-size: 14px; font-weight: 600; line-height: 1.35; color: #111827; overflow-wrap: anywhere; }
        .kit-wh-load__company { margin: 2px 0 0; font-size: 13px; line-height: 1.35; color: #4b5563; overflow-wrap: anywhere; }
        .kit-wh-load__facts { display: grid; gap: 4px; margin: 10px 0 0; }
        .kit-wh-load__facts > div { display: grid; grid-template-columns: 4.75rem minmax(0, 1fr); gap: 8px; align-items: start; }
        .kit-wh-load__facts dt { margin: 0; font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .kit-wh-load__facts dd { margin: 0; font-size: 13px; line-height: 1.35; color: #111827; overflow-wrap: anywhere; }
        .kit-wh-load__truck { display: flex; flex-direction: column; border: 1px solid #d1d5db; background: #fff; min-height: 360px; min-width: 0; }
        .kit-wh-load__truck-head { padding: 14px; border-bottom: 1px solid #e5e7eb; }
        .kit-wh-load__truck-kicker { margin: 0 0 2px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #6b7280; }
        .kit-wh-load__truck-ref { margin: 0; font-size: 15px; font-weight: 700; color: #111827; overflow-wrap: anywhere; }
        .kit-wh-load__truck-sub { margin: 4px 0 0; font-size: 12px; color: #4b5563; overflow-wrap: anywhere; }
        .kit-wh-load__truck-body { flex: 1; padding: 14px; min-height: 80px; overflow: auto; max-height: 46vh; }
        .kit-wh-load__truck-hint { margin: 0; font-size: 13px; color: #6b7280; line-height: 1.45; }
        .kit-wh-load__truck-hint.hidden { display: none; }
        .kit-wh-load__manifest { list-style: none; margin: 0; padding: 0; }
        .kit-wh-load__manifest[hidden] { display: none; }
        .kit-wh-load__manifest-item { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: start; padding: 10px 0; border-bottom: 1px solid #e5e7eb; }
        .kit-wh-load__manifest-item:last-child { border-bottom: 0; }
        .kit-wh-load__manifest-wb { margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; font-weight: 700; color: #1d4ed8; overflow-wrap: anywhere; }
        .kit-wh-load__manifest-who { margin: 2px 0 0; font-size: 12px; line-height: 1.35; color: #111827; overflow-wrap: anywhere; }
        .kit-wh-load__manifest-to { margin: 2px 0 0; font-size: 11px; line-height: 1.35; color: #6b7280; overflow-wrap: anywhere; }
        .kit-wh-load__manifest-remove { flex-shrink: 0; margin: 0; padding: 0; border: 0; background: none; color: #9ca3af; font-size: 16px; line-height: 1; cursor: pointer; }
        .kit-wh-load__manifest-remove:hover { color: #b91c1c; }
        .kit-wh-load__truck-foot { padding: 14px; border-top: 1px solid #e5e7eb; }
        .kit-wh-load__truck-foot .kit-wh-load__assign { width: 100%; }
        .kit-wh-load__status { margin: 8px 0 0; font-size: 12px; color: #4b5563; min-height: 1.2em; }
        .kit-wh-load__status.is-error { color: #b91c1c; }
        @media (max-width: 1100px) {
            .kit-wh-load__grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
        }
        @media (max-width: 782px) {
            .kit-wh-load__body { grid-template-columns: 1fr; }
            .kit-wh-load__truck { min-height: 220px; }
            .kit-wh-load__grid { max-height: 46vh; grid-template-columns: minmax(0, 1fr); }
        }
    </style>
    <script>
    (function () {
        var scriptEl = document.currentScript;
        var root = scriptEl && scriptEl.parentNode
            ? scriptEl.parentNode.querySelector('.kit-wh-load')
            : document.querySelector('#warehouse-load-modal .kit-wh-load');
        if (!root || root.dataset.bound === '1') return;
        root.dataset.bound = '1';

        var selected = new Set();
        var lastIndex = -1;
        var assigning = false;
        var cards = Array.prototype.slice.call(root.querySelectorAll('.kit-wh-load__card'));
        var grid = root.querySelector('.kit-wh-load__grid');
        var search = root.querySelector('.kit-wh-load__search');
        var routeOnly = root.querySelector('.kit-wh-load__route-only');
        var countEl = root.querySelector('.kit-wh-load__count');
        var assignBtn = root.querySelector('.kit-wh-load__assign');
        var statusEl = root.querySelector('.kit-wh-load__status');
        var filterEmpty = root.querySelector('.kit-wh-load__empty--filter');
        var truckHint = root.querySelector('.kit-wh-load__truck-hint');
        var manifest = root.querySelector('.kit-wh-load__manifest');
        var destCountryId = root.getAttribute('data-dest-country-id') || '';

        function visibleCards() {
            return cards.filter(function (c) { return !c.classList.contains('is-hidden'); });
        }

        function setStatus(msg, isError) {
            if (!statusEl) return;
            statusEl.textContent = msg || '';
            statusEl.classList.toggle('is-error', !!isError);
        }

        function setAssignLabel(text) {
            if (!assignBtn) return;
            var label = assignBtn.querySelector('.kit-btn__text');
            if (label) label.textContent = text;
            else assignBtn.textContent = text;
        }

        function setAssignLoading(on) {
            if (window.KIT && KIT.Button) {
                KIT.Button.setLoading(assignBtn, on);
            } else if (assignBtn) {
                assignBtn.disabled = !!on;
            }
        }

        function setAssignEnabled(on) {
            if (!assignBtn) return;
            assignBtn.disabled = !on;
            // renderButton() bakes these in when disabled=true; attribute alone won't clear them.
            assignBtn.classList.toggle('opacity-50', !on);
            assignBtn.classList.toggle('cursor-not-allowed', !on);
            assignBtn.classList.toggle('pointer-events-none', !on);
        }

        function renderManifest() {
            if (!manifest) return;
            var ids = Array.from(selected);
            if (ids.length === 0) {
                manifest.innerHTML = '';
                manifest.hidden = true;
                if (truckHint) truckHint.classList.remove('hidden');
                return;
            }
            if (truckHint) truckHint.classList.add('hidden');
            manifest.hidden = false;
            manifest.innerHTML = ids.map(function (id) {
                var card = root.querySelector('.kit-wh-load__card[data-id="' + id + '"]');
                var wb = card ? (card.getAttribute('data-waybill') || ('#' + id)) : ('#' + id);
                var who = card ? (card.getAttribute('data-who') || '') : '';
                var city = card ? (card.getAttribute('data-city') || '') : '';
                return '<li class="kit-wh-load__manifest-item" data-id="' + id + '">'
                    + '<div>'
                    + '<p class="kit-wh-load__manifest-wb">' + escapeHtml(wb) + '</p>'
                    + (who ? '<p class="kit-wh-load__manifest-who">' + escapeHtml(who) + '</p>' : '')
                    + (city && city !== '—' ? '<p class="kit-wh-load__manifest-to">' + escapeHtml(city) + '</p>' : '')
                    + '</div>'
                    + '<button type="button" class="kit-wh-load__manifest-remove" data-id="' + id + '" aria-label="Remove ' + escapeHtml(wb) + '">&times;</button>'
                    + '</li>';
            }).join('');
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function syncUi() {
            var n = selected.size;
            var canAssign = n > 0 && !assigning;
            if (countEl) countEl.textContent = n + ' selected';
            if (assignBtn) {
                setAssignLabel(n > 0 ? ('Load ' + n + ' onto truck') : 'Load onto truck');
                if (assigning) {
                    setAssignLoading(true);
                } else {
                    setAssignLoading(false);
                    setAssignEnabled(canAssign);
                }
            }
            cards.forEach(function (card) {
                var on = selected.has(card.getAttribute('data-id'));
                card.classList.toggle('is-selected', on);
                card.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            renderManifest();
        }

        function applyFilter() {
            var q = (search && search.value ? search.value : '').toLowerCase().trim();
            var onlyRoute = !!(routeOnly && routeOnly.checked && destCountryId);
            var shown = 0;
            cards.forEach(function (card) {
                var matchSearch = !q || (card.getAttribute('data-search') || '').indexOf(q) !== -1;
                var matchRoute = !onlyRoute || card.getAttribute('data-country-id') === destCountryId;
                var show = matchSearch && matchRoute;
                card.classList.toggle('is-hidden', !show);
                if (show) shown++;
                if (!show) selected.delete(card.getAttribute('data-id'));
            });
            if (filterEmpty) filterEmpty.classList.toggle('hidden', shown > 0 || cards.length === 0);
            syncUi();
        }

        function toggleCard(card, additive) {
            var id = card.getAttribute('data-id');
            if (!additive) {
                if (selected.size === 1 && selected.has(id)) {
                    selected.delete(id);
                } else {
                    selected.clear();
                    selected.add(id);
                }
            } else if (selected.has(id)) {
                selected.delete(id);
            } else {
                selected.add(id);
            }
            lastIndex = cards.indexOf(card);
            syncUi();
        }

        function rangeSelect(card) {
            var end = cards.indexOf(card);
            if (lastIndex < 0) {
                toggleCard(card, true);
                return;
            }
            var start = Math.min(lastIndex, end);
            var stop = Math.max(lastIndex, end);
            for (var i = start; i <= stop; i++) {
                if (!cards[i].classList.contains('is-hidden')) {
                    selected.add(cards[i].getAttribute('data-id'));
                }
            }
            lastIndex = end;
            syncUi();
        }

        cards.forEach(function (card) {
            card.addEventListener('click', function (e) {
                if (e.shiftKey) {
                    e.preventDefault();
                    rangeSelect(card);
                    return;
                }
                toggleCard(card, true);
            });
            card.addEventListener('keydown', function (e) {
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    toggleCard(card, true);
                }
            });
        });

        if (grid) {
            grid.addEventListener('click', function (e) {
                if (e.target === grid) {
                    selected.clear();
                    syncUi();
                }
            });
        }

        var selectAll = root.querySelector('.kit-wh-load__select-all');
        var clearBtn = root.querySelector('.kit-wh-load__clear');
        if (selectAll) {
            selectAll.addEventListener('click', function () {
                visibleCards().forEach(function (c) { selected.add(c.getAttribute('data-id')); });
                syncUi();
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                selected.clear();
                syncUi();
            });
        }
        if (manifest) {
            manifest.addEventListener('click', function (e) {
                var btn = e.target.closest('.kit-wh-load__manifest-remove');
                if (!btn) return;
                e.preventDefault();
                selected.delete(btn.getAttribute('data-id'));
                syncUi();
            });
        }
        if (search) search.addEventListener('input', applyFilter);
        if (routeOnly) routeOnly.addEventListener('change', applyFilter);

        if (assignBtn) assignBtn.addEventListener('click', assignSelected);

        function assignSelected() {
            if (assigning || selected.size === 0) return;
            if (window.KIT && KIT.Button && assignBtn && KIT.Button.isLoading(assignBtn)) return;
            assigning = true;
            setAssignLoading(true);
            syncUi();
            setStatus('Loading onto truck…', false);

            var body = new FormData();
            body.append('action', 'kit_warehouse_load_truck');
            body.append('nonce', root.getAttribute('data-nonce') || '');
            body.append('delivery_id', root.getAttribute('data-delivery-id') || '');
            selected.forEach(function (id) { body.append('waybill_ids[]', id); });

            var ajax = root.getAttribute('data-ajax') || (window.myPluginAjax && myPluginAjax.ajax_url) || window.ajaxurl;
            fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (!json || !json.success) {
                        var msg = (json && json.data && json.data.message) ? json.data.message : 'Could not assign waybills.';
                        assigning = false;
                        setAssignLoading(false);
                        syncUi();
                        setStatus(msg, true);
                        return;
                    }
                    var ids = (json.data && json.data.assigned_ids) || [];
                    ids.forEach(function (id) {
                        selected.delete(String(id));
                        var card = root.querySelector('.kit-wh-load__card[data-id="' + id + '"]');
                        if (card) card.remove();
                    });
                    cards = Array.prototype.slice.call(root.querySelectorAll('.kit-wh-load__card'));
                    assigning = false;
                    syncUi();
                    var url = new URL(window.location.href);
                    url.searchParams.set('warehouse_loaded', String((json.data && json.data.assigned) || ids.length));
                    window.location.href = url.toString();
                })
                .catch(function () {
                    assigning = false;
                    setAssignLoading(false);
                    syncUi();
                    setStatus('Network error. Try again.', true);
                });
        }

        applyFilter();
        syncUi();
    })();
    </script>
    <?php
    $body = ob_get_clean();

    return KIT_Modal::render(
        'warehouse-load-modal',
        __('Warehoused waybills', '08600-services-quotations'),
        $body,
        '6xl',
        true,
        __('Load from warehouse', '08600-services-quotations')
    );
}
