(function () {
    'use strict';

    var cfg = window.kitAdminMenuNesting;
    if (!cfg || !cfg.root || !cfg.groups) {
        return;
    }

    function pageFromHref(href) {
        if (!href) {
            return '';
        }
        try {
            return new URL(href, window.location.origin).searchParams.get('page') || '';
        } catch (e) {
            var match = String(href).match(/[?&]page=([^&]+)/);
            return match ? decodeURIComponent(match[1]) : '';
        }
    }

    function itemPage(li) {
        var link = li.querySelector('a');
        return link ? pageFromHref(link.getAttribute('href')) : '';
    }

    function isFlyoutMode(topLi) {
        var body = document.body;
        if (body.classList.contains('folded')) {
            return true;
        }
        if (body.classList.contains('auto-fold') && window.innerWidth <= 960) {
            return true;
        }
        return topLi.classList.contains('wp-not-current-submenu');
    }

    function syncMode(topLi) {
        topLi.classList.toggle('kit-menu-mode-flyout', isFlyoutMode(topLi));
    }

    function nest() {
        var topLi = document.querySelector(cfg.root);
        if (!topLi) {
            return;
        }

        var submenu = topLi.querySelector('.wp-submenu');
        if (!submenu || submenu.dataset.kitNested === '1') {
            if (topLi && submenu) {
                syncMode(topLi);
            }
            return;
        }

        var children = Array.prototype.slice.call(submenu.children);
        var heads = [];
        var byPage = {};
        var order = [];

        children.forEach(function (li) {
            if (li.classList.contains('wp-submenu-head')) {
                heads.push(li);
                return;
            }
            var page = itemPage(li);
            if (page) {
                byPage[page] = li;
            }
            order.push(li);
        });

        var claimed = {};
        var groupNodes = [];

        cfg.groups.forEach(function (group) {
            var visible = (group.pages || []).map(function (slug) {
                return byPage[slug];
            }).filter(Boolean);

            visible.forEach(function (li) {
                claimed[itemPage(li)] = true;
            });

            if (visible.length < 2) {
                visible.forEach(function (li) {
                    groupNodes.push(li);
                });
                return;
            }

            var hasCurrent = visible.some(function (li) {
                return li.classList.contains('current');
            });

            var wrap = document.createElement('li');
            wrap.className = 'kit-menu-group' + (hasCurrent ? ' kit-menu-group--current' : '');
            wrap.setAttribute('data-kit-group', group.id);

            var childrenId = 'kit-menu-group-' + group.id;
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'kit-menu-group__toggle';
            toggle.setAttribute('aria-expanded', hasCurrent ? 'true' : 'false');
            toggle.setAttribute('aria-controls', childrenId);
            toggle.innerHTML = '<span class="kit-menu-group__label"></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>';
            toggle.querySelector('.kit-menu-group__label').textContent = group.label;

            var list = document.createElement('ul');
            list.id = childrenId;
            list.className = 'kit-menu-group__children';
            visible.forEach(function (li) {
                list.appendChild(li);
            });

            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (isFlyoutMode(topLi)) {
                    return;
                }
                var open = toggle.getAttribute('aria-expanded') !== 'true';
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                wrap.classList.toggle('kit-menu-group--open', open);
            });

            if (hasCurrent) {
                wrap.classList.add('kit-menu-group--open');
            }

            wrap.appendChild(toggle);
            wrap.appendChild(list);
            groupNodes.push(wrap);
        });

        var leftovers = order.filter(function (li) {
            var page = itemPage(li);
            return !page || !claimed[page];
        });

        var dashboard = leftovers.filter(function (li) {
            return itemPage(li) === cfg.dashboardPage;
        });
        var otherLeaves = leftovers.filter(function (li) {
            return itemPage(li) !== cfg.dashboardPage;
        });

        heads.concat(dashboard, groupNodes, otherLeaves).forEach(function (node) {
            submenu.appendChild(node);
        });

        if (dashboard[0]) {
            dashboard[0].classList.add('wp-first-item');
        }

        submenu.dataset.kitNested = '1';
        syncMode(topLi);
    }

    function bindModeSync() {
        var topLi = document.querySelector(cfg.root);
        if (!topLi) {
            return;
        }
        var sync = function () {
            syncMode(topLi);
        };
        window.addEventListener('resize', sync);
        var collapse = document.getElementById('collapse-button');
        if (collapse) {
            collapse.addEventListener('click', function () {
                window.setTimeout(sync, 0);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            nest();
            bindModeSync();
        });
    } else {
        nest();
        bindModeSync();
    }
})();
