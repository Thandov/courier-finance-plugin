/**
 * Component-specific JavaScript utilities
 * This file provides common functionality for plugin components
 */

// Component utilities
const ComponentUtils = {
    // Check if required dependencies are available
    checkDependencies: function() {
        const dependencies = {
            jQuery: typeof jQuery !== 'undefined',
            SpinnerManager: typeof SpinnerManager !== 'undefined',
            myPluginAjax: typeof myPluginAjax !== 'undefined'
        };
        
        const missing = Object.keys(dependencies).filter(key => !dependencies[key]);
        if (missing.length > 0) {
            console.warn('Missing dependencies:', missing);
            return false;
        }
        return true;
    },

    // Safe spinner management
    showSpinner: function(element) {
        if (typeof SpinnerManager !== 'undefined' && SpinnerManager.show) {
            SpinnerManager.show(element);
        } else {
            // Fallback: add a simple loading class
            element.classList.add('loading');
        }
    },

    hideSpinner: function(element) {
        if (typeof SpinnerManager !== 'undefined' && SpinnerManager.hide) {
            SpinnerManager.hide(element);
        } else {
            // Fallback: remove loading class
            element.classList.remove('loading');
        }
    },

    // Input overlay loader (shows an overlay on top of inputs)
    ensureInputLoaderStyles: function() {
        if (document.getElementById('kit-input-loader-styles')) return;
        const style = document.createElement('style');
        style.id = 'kit-input-loader-styles';
        style.textContent = `
            .kit-input-loader-wrap{position:relative;display:block}
            .kit-input-loader-overlay{
                position:absolute;inset:0;border-radius:6px;
                background:rgba(255,255,255,.72);
                display:flex;align-items:center;justify-content:center;gap:8px;
                z-index:5;pointer-events:none;
            }
            .kit-input-loader-spinner{
                width:16px;height:16px;border:2px solid rgba(0,0,0,.12);
                border-top-color:#3498db;border-radius:50%;
                animation:kitInputSpin 1s linear infinite;
            }
            .kit-input-loader-text{font-size:12px;color:#374151}
            @keyframes kitInputSpin{to{transform:rotate(360deg)}}
            .kit-input-loading{caret-color:transparent}
        `;
        document.head.appendChild(style);
    },

    showInputLoader: function(inputEl, text = 'Calculating…') {
        if (!inputEl) return;
        ComponentUtils.ensureInputLoaderStyles();

        // Wrap input (once) so we can absolutely-position overlay
        let wrap = inputEl.closest('.kit-input-loader-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'kit-input-loader-wrap';
            inputEl.parentNode.insertBefore(wrap, inputEl);
            wrap.appendChild(inputEl);
        }

        let overlay = wrap.querySelector('.kit-input-loader-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'kit-input-loader-overlay';
            overlay.innerHTML = `<span class="kit-input-loader-spinner" aria-hidden="true"></span><span class="kit-input-loader-text"></span>`;
            wrap.appendChild(overlay);
        }
        const label = overlay.querySelector('.kit-input-loader-text');
        if (label) label.textContent = text;

        inputEl.classList.add('kit-input-loading');
        inputEl.setAttribute('aria-busy', 'true');
        // Hide the value visually while loading (but keep it in the DOM)
        if (!inputEl.dataset.kitOriginalTextColor) {
            inputEl.dataset.kitOriginalTextColor = inputEl.style.color || '';
        }
        inputEl.style.color = 'transparent';
    },

    hideInputLoader: function(inputEl) {
        if (!inputEl) return;
        const wrap = inputEl.closest('.kit-input-loader-wrap');
        const overlay = wrap ? wrap.querySelector('.kit-input-loader-overlay') : null;
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);

        inputEl.classList.remove('kit-input-loading');
        inputEl.removeAttribute('aria-busy');
        if (inputEl.dataset.kitOriginalTextColor !== undefined) {
            inputEl.style.color = inputEl.dataset.kitOriginalTextColor;
            delete inputEl.dataset.kitOriginalTextColor;
        } else {
            inputEl.style.color = '';
        }
    },

    // Tooltip (simple, global, dependency-free)
    ensureTooltipStyles: function() {
        if (document.getElementById('kit-tooltip-styles')) return;
        const style = document.createElement('style');
        style.id = 'kit-tooltip-styles';
        style.textContent = `
            .kit-tooltip{position:relative;display:inline-flex;align-items:center}
            .kit-tooltip[data-tooltip]::after{
                content:attr(data-tooltip);
                position:absolute;
                left:50%;
                bottom:calc(100% + 8px);
                transform:translateX(-50%);
                background:rgba(17,24,39,.95);
                color:#fff;
                font-size:12px;
                line-height:1;
                padding:8px 10px;
                border-radius:6px;
                white-space:nowrap;
                opacity:0;
                visibility:hidden;
                transition:opacity .12s ease, visibility .12s ease;
                pointer-events:none;
                z-index:50;
                box-shadow:0 10px 20px rgba(0,0,0,.15);
            }
            .kit-tooltip[data-tooltip]::before{
                content:'';
                position:absolute;
                left:50%;
                bottom:calc(100% + 2px);
                transform:translateX(-50%);
                width:0;height:0;
                border-left:6px solid transparent;
                border-right:6px solid transparent;
                border-top:6px solid rgba(17,24,39,.95);
                opacity:0;
                visibility:hidden;
                transition:opacity .12s ease, visibility .12s ease;
                pointer-events:none;
                z-index:50;
            }
            .kit-tooltip:focus-within[data-tooltip]::after,
            .kit-tooltip:hover[data-tooltip]::after,
            .kit-tooltip:focus-within[data-tooltip]::before,
            .kit-tooltip:hover[data-tooltip]::before{
                opacity:1;
                visibility:visible;
            }
        `;
        document.head.appendChild(style);
    },

    initTooltips: function() {
        ComponentUtils.ensureTooltipStyles();
    },

    // Safe AJAX calls
    ajaxCall: function(action, data, callback, options) {
        options = options || {};
        if (typeof myPluginAjax === 'undefined') {
            console.error('myPluginAjax not available');
            if (callback) callback({success: false, message: 'AJAX not available'});
            return;
        }

        const formData = new FormData();
        formData.append('action', action);
        // Resolve nonce defensively. Some screens may not have `myPluginAjax.nonces`
        // (or it may not be initialized yet), but the handler expects a nonce key named `nonce`.
        const resolvedNonce =
            (myPluginAjax && myPluginAjax.nonces && myPluginAjax.nonces.get_waybills_nonce) ||
            (myPluginAjax && myPluginAjax.nonce) ||
            (document.querySelector('input[name="_wpnonce"]') && document.querySelector('input[name="_wpnonce"]').value) ||
            '';
        if (!resolvedNonce) {
            console.warn('Nonce not available for action:', action);
        }
        formData.append('nonce', resolvedNonce);

        // Add additional data
        for (const [key, value] of Object.entries(data || {})) {
            formData.append(key, value);
        }

        const fetchOpts = {
            method: 'POST',
            body: formData
        };
        if (options.signal) {
            fetchOpts.signal = options.signal;
        }

        fetch(myPluginAjax.ajax_url, fetchOpts)
        .then(response => response.json())
        .then(function(payload) {
            if (callback) callback(payload);
        })
        .catch(error => {
            if (error && error.name === 'AbortError') {
                if (callback) callback({success: false, aborted: true, message: 'aborted'});
                return;
            }
            console.error('AJAX error:', error);
            if (callback) callback({success: false, message: error && error.message ? error.message : 'Request failed'});
        });
    }
};

/**
 * KIT.Button — loader + disable for renderButton() (.kit-btn) elements.
 */
const KitButton = {
    ensureStyles: function() {
        if (document.getElementById('kit-btn-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'kit-btn-styles';
        style.textContent = `
            .kit-btn {
                position: relative;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
            }
            .kit-btn .kit-btn__spinner {
                display: none;
                position: absolute;
                left: 50%;
                top: 50%;
                transform: translate(-50%, -50%);
                line-height: 0;
                pointer-events: none;
            }
            .kit-btn.is-loading {
                pointer-events: none;
                cursor: wait;
                opacity: 0.85;
            }
            .kit-btn.is-loading .kit-btn__text,
            .kit-btn.is-loading > svg:not(.kit-btn__spinner-icon) {
                visibility: hidden;
            }
            .kit-btn.is-loading .kit-btn__spinner {
                display: inline-flex;
            }
        `;
        document.head.appendChild(style);
    },

    resolve: function(button) {
        if (!button) {
            return null;
        }
        if (button.classList && button.classList.contains('kit-btn')) {
            return button;
        }
        if (button.closest) {
            return button.closest('.kit-btn');
        }
        return null;
    },

    isLoading: function(button) {
        const btn = KitButton.resolve(button);
        return !!(btn && btn.classList.contains('is-loading'));
    },

    setLoading: function(button, on) {
        const btn = KitButton.resolve(button);
        if (!btn) {
            return;
        }
        KitButton.ensureStyles();
        const loading = !!on;
        btn.classList.toggle('is-loading', loading);
        btn.setAttribute('aria-busy', loading ? 'true' : 'false');
        if (loading) {
            btn.setAttribute('disabled', 'disabled');
            btn.dataset.kitWasDisabled = btn.dataset.kitWasDisabled || (btn.disabled ? '1' : '0');
        } else {
            if (btn.dataset.kitWasDisabled !== '1') {
                btn.removeAttribute('disabled');
            }
            delete btn.dataset.kitWasDisabled;
        }
    },

  /**
   * Run fn with the button in a loading state. Clears loading when fn completes
   * (sync) or when its returned Promise settles.
   */
    withLoading: function(button, fn) {
        const btn = KitButton.resolve(button);
        if (!btn || typeof fn !== 'function') {
            return Promise.resolve();
        }
        if (KitButton.isLoading(btn)) {
            return Promise.resolve();
        }
        KitButton.setLoading(btn, true);
        let result;
        try {
            result = fn(btn);
        } catch (err) {
            KitButton.setLoading(btn, false);
            return Promise.reject(err);
        }
        if (result && typeof result.then === 'function') {
            return result.finally(function() {
                KitButton.setLoading(btn, false);
            });
        }
        KitButton.setLoading(btn, false);
        return Promise.resolve(result);
    },

    wrapOnclick: function(btn) {
        const code = btn.getAttribute('onclick');
        if (!code || btn.dataset.kitOnclickWrapped === '1') {
            return;
        }
        btn.dataset.kitOnclickWrapped = '1';
        btn.removeAttribute('onclick');
        btn.addEventListener('click', function(event) {
            if (KitButton.isLoading(btn)) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return false;
            }
            KitButton.withLoading(btn, function() {
                const fn = new Function('event', 'btn', code);
                return fn.call(btn, event, btn);
            });
        });
    },

    bindFormSubmits: function() {
        document.querySelectorAll('form').forEach(function(form) {
            if (form.dataset.kitBtnSubmitBound === '1') {
                return;
            }
            form.dataset.kitBtnSubmitBound = '1';
            form.addEventListener('submit', function() {
                if (form.getAttribute('data-kit-no-submit-loading') === '1') {
                    return;
                }
                const submitter = document.activeElement;
                const btn = KitButton.resolve(submitter);
                if (btn && btn.type === 'submit') {
                    setTimeout(function() {
                        KitButton.setLoading(btn, true);
                    }, 0);
                }
            });
        });
    },

    bindAll: function(root) {
        KitButton.ensureStyles();
        const scope = root || document;
        scope.querySelectorAll('.kit-btn[data-kit-loading-on-click="1"]').forEach(function(btn) {
            if (btn.getAttribute('onclick')) {
                KitButton.wrapOnclick(btn);
            }
        });
        KitButton.bindFormSubmits();
    }
};

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    ComponentUtils.checkDependencies();
    ComponentUtils.initTooltips();
    KitButton.bindAll();

    // Add loading styles if SpinnerManager is not available (legacy .loading class)
    if (typeof SpinnerManager === 'undefined') {
        const style = document.createElement('style');
        style.textContent = `
            .loading {
                position: relative;
                opacity: 0.6;
            }
            .loading::after {
                content: '';
                position: absolute;
                right: 8px;
                top: 50%;
                transform: translateY(-50%);
                width: 16px;
                height: 16px;
                border: 2px solid rgba(0,0,0,0.1);
                border-radius: 50%;
                border-top-color: #3498db;
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                to { transform: translateY(-50%) rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    }
});

window.ComponentUtils = ComponentUtils;
window.KIT = window.KIT || {};
window.KIT.Button = KitButton;

