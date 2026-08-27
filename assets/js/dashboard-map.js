(function() {
    'use strict';

    var CACHE_KEY = 'kit_dashboard_geocode';
    var NOMINATIM_DELAY_MS = 1100;

    function getCache() {
        try {
            var raw = sessionStorage.getItem(CACHE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }
    function setCache(key, latLng) {
        try {
            var c = getCache();
            c[key] = latLng;
            sessionStorage.setItem(CACHE_KEY, JSON.stringify(c));
        } catch (e) {}
    }

    function geocodeQuery(query) {
        if (!query || !String(query).trim()) return Promise.resolve(null);
        var key = String(query).toLowerCase().trim();
        var cache = getCache();
        if (cache[key]) return Promise.resolve(cache[key]);

        var url = 'https://nominatim.openstreetmap.org/search?q=' +
            encodeURIComponent(query) + '&format=json&limit=1';
        return fetch(url, {
            headers: { 'Accept': 'application/json' }
        })
            .then(function(r) { return r.json(); })
            .then(function(arr) {
                if (arr && arr[0]) {
                    var lat = parseFloat(arr[0].lat);
                    var lon = parseFloat(arr[0].lon);
                    if (!isNaN(lat) && !isNaN(lon)) {
                        setCache(key, [lat, lon]);
                        return [lat, lon];
                    }
                }
                return null;
            })
            .catch(function() { return null; });
    }

    function delay(ms) {
        return new Promise(function(resolve) { setTimeout(resolve, ms); });
    }

    function geocodeWithDelay(query) {
        return geocodeQuery(query).then(function(result) {
            return delay(NOMINATIM_DELAY_MS).then(function() { return result; });
        });
    }

    var DEFAULT_CENTER = [-20, 25];
    var DEFAULT_ZOOM = 3;
    var CENTER_ZOOM = 10;

    function initMap() {
        var mapEl = document.getElementById('kit-dashboard-map');
        if (!mapEl || typeof L === 'undefined') return;

        var mapCenterAddress = (window.kitDashboardMap && window.kitDashboardMap.map_center_address) || '';
        var initialCenter = DEFAULT_CENTER;
        var initialZoom = DEFAULT_ZOOM;

        function createMap(center, zoom) {
            var map = L.map('kit-dashboard-map').setView(center, zoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(map);
            setTimeout(function() {
                map.invalidateSize();
            }, 200);
            window.addEventListener('resize', function() {
                map.invalidateSize();
            });
            return map;
        }

        function add08600Marker(map, center) {
            var logoUrl = (window.kitDashboardMap && window.kitDashboardMap.map_logo_url) || '';
            if (!center || (center[0] == null || center[1] == null)) return;
            /* Logo is 517x93 (5.56:1 aspect ratio) - use proportional size */
            var iconW = 120;
            var iconH = 22;
            var iconOpts = {
                iconSize: [iconW, iconH],
                iconAnchor: [iconW / 2, iconH],
                popupAnchor: [0, -iconH]
            };
            if (logoUrl) {
                iconOpts.iconUrl = logoUrl;
            }
            var icon = logoUrl
                ? L.icon(iconOpts)
                : L.divIcon({ className: 'kit-map-08600-marker', html: '<span>08600</span>', iconSize: [iconW, iconH], iconAnchor: [iconW / 2, iconH] });
            L.marker(center, { icon: icon })
                .addTo(map)
                .bindPopup('<strong>08600</strong><br>Unit 1, Kya North Park, 28 Bernie St, Kya Sands, Randburg, 2188');
        }

        function runMapWithCenter(center, zoom, usedSettingsCenter) {
            var map = createMap(center, zoom);
            if (usedSettingsCenter) {
                add08600Marker(map, center);
            }
            loadDeliveries(map, mapEl, !!usedSettingsCenter);
        }

        var fallbackLat = window.kitDashboardMap && window.kitDashboardMap.map_fallback_lat;
        var fallbackLng = window.kitDashboardMap && window.kitDashboardMap.map_fallback_lng;
        var fallbackCenter = (fallbackLat != null && fallbackLng != null) ? [parseFloat(fallbackLat), parseFloat(fallbackLng)] : null;
        var geocodeQueryAlt = (window.kitDashboardMap && window.kitDashboardMap.map_geocode_query) || '';

        function tryGeocodeThenRun(query, useFallbackCenter) {
            geocodeQuery(String(query).trim()).then(function(center) {
                if (center && center[0] != null && center[1] != null) {
                    runMapWithCenter(center, CENTER_ZOOM, true);
                } else if (useFallbackCenter && fallbackCenter && !isNaN(fallbackCenter[0]) && !isNaN(fallbackCenter[1])) {
                    runMapWithCenter(fallbackCenter, CENTER_ZOOM, true);
                } else {
                    runMapWithCenter(initialCenter, initialZoom, false);
                }
            }).catch(function() {
                if (useFallbackCenter && fallbackCenter && !isNaN(fallbackCenter[0]) && !isNaN(fallbackCenter[1])) {
                    runMapWithCenter(fallbackCenter, CENTER_ZOOM, true);
                } else {
                    runMapWithCenter(initialCenter, initialZoom, false);
                }
            });
        }

        if (mapCenterAddress && String(mapCenterAddress).trim()) {
            geocodeQuery(String(mapCenterAddress).trim()).then(function(center) {
                if (center && center[0] != null && center[1] != null) {
                    runMapWithCenter(center, CENTER_ZOOM, true);
                } else if (geocodeQueryAlt) {
                    tryGeocodeThenRun(geocodeQueryAlt, true);
                } else if (fallbackCenter && !isNaN(fallbackCenter[0]) && !isNaN(fallbackCenter[1])) {
                    runMapWithCenter(fallbackCenter, CENTER_ZOOM, true);
                } else {
                    runMapWithCenter(initialCenter, initialZoom, false);
                }
            }).catch(function() {
                if (geocodeQueryAlt) {
                    tryGeocodeThenRun(geocodeQueryAlt, true);
                } else if (fallbackCenter && !isNaN(fallbackCenter[0]) && !isNaN(fallbackCenter[1])) {
                    runMapWithCenter(fallbackCenter, CENTER_ZOOM, true);
                } else {
                    runMapWithCenter(initialCenter, initialZoom, false);
                }
            });
        } else {
            runMapWithCenter(initialCenter, initialZoom, false);
        }
    }

    function loadDeliveries(map, mapEl, keepCenterFromSettings) {
        var ajaxurl = (window.kitDashboardMap && window.kitDashboardMap.ajaxurl) || (window.ajaxurl || '/wp-admin/admin-ajax.php');
        var nonce = (window.kitDashboardMap && window.kitDashboardMap.nonce) || '';

        var formData = new FormData();
        formData.append('action', 'kit_dashboard_map_deliveries');
        formData.append('nonce', nonce);

        fetch(ajaxurl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success || !Array.isArray(data.data)) return;
                var deliveries = data.data;
                if (deliveries.length === 0) return;

                var bounds = [];
                var colorIndex = 0;
                var colors = ['#2563eb', '#059669', '#d97706', '#dc2626', '#7c3aed'];

                function nextColor() {
                    var c = colors[colorIndex % colors.length];
                    colorIndex++;
                    return c;
                }

                function addRoute(originQuery, destQuery, delivery) {
                    var originPromise = geocodeWithDelay(originQuery);
                    var destPromise = geocodeWithDelay(destQuery);
                    return Promise.all([originPromise, destPromise]).then(function(results) {
                        var origin = results[0];
                        var dest = results[1];
                        if (!origin || !dest) return;
                        bounds.push(origin, dest);
                        var route = [origin, dest];
                        var color = nextColor();
                        L.polyline(route, { color: color, weight: 3 }).addTo(map)
                            .bindPopup(
                                '<div class="kit-map-popup">' +
                                '<strong>' + (delivery.delivery_reference || '') + '</strong><br/>' +
                                (delivery.origin_country || '') + ' → ' + (delivery.destination_country || '') + (delivery.destination_city ? ', ' + delivery.destination_city : '') + '<br/>' +
                                'Dispatch: ' + (delivery.dispatch_date || '') + '<br/>' +
                                (delivery.truck_number ? 'Truck: ' + delivery.truck_number + '<br/>' : '') +
                                (delivery.driver_name ? 'Driver: ' + delivery.driver_name : '') +
                                '</div>'
                            );
                    });
                }

                var chain = Promise.resolve();
                deliveries.forEach(function(d) {
                    var originQuery = (d.origin_country || '').trim();
                    var destQuery = [d.destination_city, d.destination_country].filter(Boolean).join(', ') || (d.destination_country || '').trim();
                    if (!originQuery) originQuery = d.destination_country;
                    if (!destQuery) destQuery = d.origin_country;
                    chain = chain.then(function() {
                        return addRoute(originQuery, destQuery, d);
                    });
                });

                chain.then(function() {
                    if (bounds.length > 0 && !keepCenterFromSettings) {
                        try {
                            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
                        } catch (e) {}
                    }
                });
            })
            .catch(function(err) {
                if (mapEl) mapEl.innerHTML = '<div class="p-4 text-gray-500 text-sm">Unable to load routes.</div>';
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMap);
    } else {
        initMap();
    }
})();
