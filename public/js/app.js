// ===========================================================================
// HUMPHREY BUILDING MATERIALS — storefront JavaScript
// Vanilla JS only. Every feature degrades gracefully when JS is unavailable
// (forms still POST normally and the page still navigates).
// ===========================================================================

document.addEventListener('DOMContentLoaded', function () {
    initMobileNav();
    initFlashToasts();
    initCopyEmail();
    initAddToCart();
    initCartQtyControls();
    initDeleteConfirmations();
    initCatalogueScroll();
    initBackToTop();
    initPasswordToggles();
});

/* --------------------------------------------------------------------------
   1. Mobile navigation toggle
   -------------------------------------------------------------------------- */
function initMobileNav() {
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.querySelector('.main-nav');

    if (!toggle || !nav) return;

    toggle.addEventListener('click', function () {
        const isOpen = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
}

/* --------------------------------------------------------------------------
   2. Toast notifications (success / error)
   Temporary by design: show immediately, auto-dismiss after ~4 seconds,
   with an optional manual close button. Never persists across refresh —
   flash messages are read once from the DOM and the carrier is removed.
   -------------------------------------------------------------------------- */
function showToast(message, type, duration) {
    // Re-use a single container so toasts stack neatly in the corner.
    let wrap = document.getElementById('toast-wrap');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'toast-wrap';
        wrap.setAttribute('role', 'status');
        wrap.setAttribute('aria-live', 'polite');
        document.body.appendChild(wrap);
    }

    const toast = document.createElement('div');
    toast.className = 'toast toast-' + (type === 'error' ? 'error' : 'success');

    const text = document.createElement('span');
    text.className = 'toast-text';
    text.textContent = message;
    toast.appendChild(text);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = '&times;';
    close.addEventListener('click', function () { dismissToast(toast); });
    toast.appendChild(close);

    wrap.appendChild(toast);

    // Animate in, then auto-dismiss.
    requestAnimationFrame(function () {
        toast.classList.add('toast-show');
    });

    const timer = setTimeout(function () { dismissToast(toast); }, duration || 4000);
    toast.dataset.timer = String(timer);
}

function dismissToast(toast) {
    if (!toast || toast.dataset.dismissed) return;
    toast.dataset.dismissed = '1';
    if (toast.dataset.timer) clearTimeout(Number(toast.dataset.timer));
    toast.classList.remove('toast-show');
    setTimeout(function () { toast.remove(); }, 300);
}

/** Turn one-time Laravel flash messages (status / error) into toasts. */
function initFlashToasts() {
    const carrier = document.getElementById('flash-data');
    if (!carrier) return;

    const status = carrier.getAttribute('data-status');
    const error = carrier.getAttribute('data-error');
    carrier.remove();

    if (status) showToast(status, 'success');
    if (error) showToast(error, 'error');
}

/* --------------------------------------------------------------------------
   2b. Copy Email button (Final Spec §25) — copies the address to the
   clipboard and confirms with the exact toast: "Email copied successfully."
   -------------------------------------------------------------------------- */
function copyTextToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text);
    }
    // Fallback for older browsers / non-secure contexts.
    return new Promise(function (resolve, reject) {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try {
            const ok = document.execCommand('copy');
            document.body.removeChild(area);
            ok ? resolve() : reject(new Error('copy failed'));
        } catch (err) {
            document.body.removeChild(area);
            reject(err);
        }
    });
}

function initCopyEmail() {
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-copy-email]');
        if (!button) return;

        event.preventDefault();
        const email = button.getAttribute('data-copy-email');
        if (!email) return;

        copyTextToClipboard(email)
            .then(function () { showToast('Email copied successfully.', 'success'); })
            .catch(function () { showToast('Could not copy the email — please copy it manually.', 'error'); });
    });
}

/* --------------------------------------------------------------------------
   3. Add to cart without leaving the page (toast + live cart counter)
   -------------------------------------------------------------------------- */
function initAddToCart() {
    document.querySelectorAll('form.js-add-to-cart').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            // No fetch support → fall back to the normal form POST.
            if (typeof fetch !== 'function') return;

            event.preventDefault();

            const button = form.querySelector('button[type="submit"]');
            const originalLabel = button ? button.textContent : '';

            if (button) {
                button.disabled = true;
                button.dataset.busy = '1';
                button.textContent = 'Adding…';
            }

            const body = new URLSearchParams(new FormData(form));

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (form.querySelector('input[name="_token"]') || {}).value || ''
                },
                body: body,
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    const data = result.data || {};

                    if (result.ok && data.success) {
                        showToast(data.message || 'Added to your cart.', 'success');
                        updateCartBadge(data.cart_count);
                        if (data.cart_url) {
                            const link = document.querySelector('a[href$="/cart"].cart-link');
                            if (link) link.setAttribute('href', data.cart_url);
                        }
                    } else {
                        showToast(data.message || 'Could not add that product.', 'error');
                        // Out-of-stock style errors → send the shopper to the product page.
                        if (data.product_url && /stock|available/i.test(data.message || '')) {
                            setTimeout(function () { window.location.href = data.product_url; }, 900);
                        }
                    }
                })
                .catch(function () {
                    showToast('Network problem — please try again.', 'error');
                })
                .finally(function () {
                    if (button) {
                        button.disabled = false;
                        button.textContent = originalLabel;
                        delete button.dataset.busy;
                    }
                });
        });
    });
}

/** Update (or create) the little number on the header cart icon. */
function updateCartBadge(count) {
    if (typeof count !== 'number') return;

    const badge = document.querySelector('.cart-link .cart-badge') ||
                  document.querySelector('a[href$="/cart"] .cart-badge');

    if (count <= 0) {
        if (badge) badge.remove();
        return;
    }

    if (badge) {
        badge.textContent = String(count);
        return;
    }

    const link = document.querySelector('a[href$="/cart"]');
    if (!link) return;

    const fresh = document.createElement('span');
    fresh.className = 'cart-badge';
    fresh.textContent = String(count);
    link.appendChild(fresh);
}

/* --------------------------------------------------------------------------
   4. Cart quantity stepper (cart page) — +/- buttons and manual entry
   update the line total, summary subtotal, item count and header badge
   IMMEDIATELY via fetch (PATCH), without reloading the page.

   The server stays the authority: every click sends the new quantity and
   the response carries the cart's real state. On any error we show a toast
   and revert the input to that server state. With JavaScript disabled the
   form still PATCHes normally (full page reload) — nothing is lost.

   Boundaries respected client-side to avoid pointless round trips:
   minus stops at min (minimum order, never below 1), plus stops at stock,
   both move by the product's quantity step.
   -------------------------------------------------------------------------- */
function initCartQtyControls() {
    if (typeof fetch !== 'function') return;

    document.querySelectorAll('form.qty-form').forEach(function (form) {
        const input = form.querySelector('input[name="qty"]');
        if (!input) return;

        const min = parseInt(form.getAttribute('data-min'), 10) || 1;
        const max = parseInt(form.getAttribute('data-max'), 10) || 0; // 0 = no stock cap known
        const step = parseInt(form.getAttribute('data-step'), 10) || 1;
        let busy = false;

        // Remember the last quantity the server confirmed (for reverts).
        input.dataset.serverQty = input.value;

        const submitLine = function (nextQty, opts) {
            opts = opts || {};
            if (busy) return;
            if (isNaN(nextQty) || nextQty < 0) return;
            if (String(nextQty) === input.dataset.serverQty && !opts.force) return;

            busy = true;
            form.classList.add('is-updating');

            const body = new URLSearchParams();
            body.append('qty', String(nextQty));

            fetch(form.action, {
                method: 'PATCH',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (form.querySelector('input[name="_token"]') || {}).value || ''
                },
                body: body,
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    const data = result.data || {};

                    if (!result.ok || data.success !== true) {
                        // Rejected (stock/min/step changed, validation…):
                        // show why, then snap back to the real cart state.
                        showToast(data.message || 'Could not update the quantity.', 'error');
                        applyCartLine(form, input, data.qty !== undefined ? data.qty : input.dataset.serverQty, data);
                        return;
                    }

                    if (data.removed) {
                        // Qty 0 removed the line — the page layout changes too,
                        // so a reload shows the truthful cart (incl. empty state).
                        window.location.reload();
                        return;
                    }

                    applyCartLine(form, input, data.qty, data);

                    if (opts.toast) showToast(data.message || 'Cart updated.', 'success');
                })
                .catch(function () {
                    showToast('Network problem — the quantity was not updated.', 'error');
                    applyCartLine(form, input, input.dataset.serverQty, {});
                })
                .finally(function () {
                    busy = false;
                    form.classList.remove('is-updating');
                });
        };

        form.querySelectorAll('.qty-step').forEach(function (button) {
            button.addEventListener('click', function () {
                const dir = parseInt(button.getAttribute('data-dir'), 10);
                const current = parseInt(input.value, 10);
                if (isNaN(current)) return;

                const next = current + dir * step;

                if (next < min) {
                    return; // already at the minimum valid quantity — stay put
                }

                if (max > 0 && next > max) {
                    if (dir > 0) {
                        // Largest quantity the rules allow (min / step / stock).
                        const best = Math.max(min, Math.floor(max / step) * step);
                        if (best > current) {
                            submitLine(best);
                        } else {
                            showToast('Only ' + max + ' in stock.', 'error');
                        }
                    }
                    return;
                }

                submitLine(next);
            });
        });

        // Manual typing / native spinner arrows: apply on change (blur/Enter).
        input.addEventListener('change', function () {
            const next = parseInt(input.value, 10);
            if (isNaN(next)) {
                input.value = input.dataset.serverQty; // nonsense entry — just restore
                return;
            }
            submitLine(next, { toast: true });
        });

        // The Update button (and Enter) — same immediate behaviour, with a toast.
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const next = parseInt(input.value, 10);
            if (isNaN(next)) {
                input.value = input.dataset.serverQty;
                return;
            }
            submitLine(next, { toast: true, force: true });
        });
    });
}

/**
 * Paint a server response onto the cart page: the input, this line's
 * total, both summary subtotals, the item count and the header badge.
 */
function applyCartLine(form, input, qty, data) {
    if (qty !== undefined && qty !== null && qty !== '') {
        input.value = String(qty);
        input.dataset.serverQty = String(qty);
    }

    const line = form.closest('.cart-line');
    if (line && data.line_total) {
        const total = line.querySelector('.cart-line-total');
        if (total) total.textContent = data.line_total;
    }

    if (data.subtotal) {
        document.querySelectorAll('.js-cart-subtotal').forEach(function (el) {
            el.textContent = data.subtotal;
        });
    }

    if (typeof data.item_count === 'number') {
        document.querySelectorAll('.js-cart-count').forEach(function (el) {
            el.textContent = String(data.item_count);
        });
    }

    if (typeof data.cart_count === 'number') {
        updateCartBadge(data.cart_count);
    }
}

/* --------------------------------------------------------------------------
   5. Confirmation dialog before destructive actions
   -------------------------------------------------------------------------- */
function initDeleteConfirmations() {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });
}

/* --------------------------------------------------------------------------
   6. Catalogue scroll-position restoration (browser-side only)

   While the shopper scrolls the catalogue we remember {url, scrollY} in
   sessionStorage. When they come back to the SAME catalogue URL (product
   page "Back to Products", cart "Continue Shopping", browser Back, or a
   fresh visit to the identical search/filter/page) we restore the position.
   A different query string is a different catalogue state, so a stale
   position is never restored. Nothing is stored server-side.
   -------------------------------------------------------------------------- */
const CATALOGUE_SCROLL_KEY = 'hbm-catalogue-scroll';

function initCatalogueScroll() {
    const page = document.body.getAttribute('data-page');

    if (page === 'catalogue') {
        restoreCatalogueScroll();
        rememberCatalogueScroll();
    } else {
        pointCatalogueBackLinks();
    }
}

function restoreCatalogueScroll() {
    let stored;
    try {
        stored = JSON.parse(sessionStorage.getItem(CATALOGUE_SCROLL_KEY) || 'null');
    } catch (e) {
        stored = null;
    }
    if (!stored || stored.url !== window.location.pathname + window.location.search) return;

    const y = Math.max(0, parseInt(stored.y, 10) || 0);
    if (y <= 0) return;

    const apply = function () { window.scrollTo(0, y); };
    apply();
    // Late-loading content can shift the layout; re-apply once settled.
    window.addEventListener('load', apply, { once: true });
}

function rememberCatalogueScroll() {
    const save = function () {
        try {
            sessionStorage.setItem(CATALOGUE_SCROLL_KEY, JSON.stringify({
                url: window.location.pathname + window.location.search,
                y: Math.round(window.scrollY)
            }));
        } catch (e) { /* storage full/blocked — restoration is optional */ }
    };

    let timer = null;
    window.addEventListener('scroll', function () {
        clearTimeout(timer);
        timer = setTimeout(save, 200);
    }, { passive: true });

    // Capture the position the moment we navigate away (e.g. a product click).
    window.addEventListener('pagehide', save);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') save();
    });
}

/** On non-catalogue pages, point "back to products" links at the exact
    catalogue URL we last visited so filters/page survive the round trip. */
function pointCatalogueBackLinks() {
    let stored;
    try {
        stored = JSON.parse(sessionStorage.getItem(CATALOGUE_SCROLL_KEY) || 'null');
    } catch (e) {
        stored = null;
    }
    if (!stored || typeof stored.url !== 'string' || stored.url.indexOf('/products') !== 0) return;

    // Blade's route() emits absolute hrefs (https://site/products), so match
    // on the path rather than the raw attribute value. Only plain links get
    // repointed — any link with its own query (category pills, category cards,
    // search results) must keep its exact target.
    // Blade's route() emits absolute hrefs (https://site/products), so match
    // on the path rather than the raw attribute value. Only plain links get
    // repointed — any link with its own query (category pills, category cards,
    // search results) must keep its exact target.
    document.querySelectorAll('a[href]').forEach(function (link) {
        let url;
        try {
            url = new URL(link.getAttribute('href'), window.location.origin);
        } catch (e) {
            return; // javascript: etc — nothing to repoint
        }
        if (url.pathname === '/products' && url.search === '') {
            link.setAttribute('href', stored.url);
        }
    });
}

/* --------------------------------------------------------------------------
   7. Back-to-top button
   -------------------------------------------------------------------------- */
function initBackToTop() {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'back-to-top';
    button.setAttribute('aria-label', 'Back to top');
    button.innerHTML = '&uarr;';
    button.style.display = 'none';
    document.body.appendChild(button);

    const toggle = function () {
        button.style.display = window.scrollY > 400 ? 'flex' : 'none';
    };

    window.addEventListener('scroll', toggle, { passive: true });
    toggle();

    button.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

/* --------------------------------------------------------------------------
   8. Password visibility toggles (login, register, reset, change password,
   admin user forms). One eye button per password field, added in JS so the
   forms still work untouched without JavaScript.
   -------------------------------------------------------------------------- */
function initPasswordToggles() {
    document.querySelectorAll('input[type="password"]').forEach(function (input) {
        if (input.dataset.pwToggleReady) return;
        input.dataset.pwToggleReady = '1';

        const wrap = document.createElement('span');
        wrap.className = 'pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        const button = document.createElement('button');
        button.type = 'button'; // never submits the form
        button.className = 'pw-toggle';
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('title', 'Show password');
        button.innerHTML =
            '<svg class="pw-eye" viewBox="0 0 24 24" aria-hidden="true">' +
            '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>' +
            '<circle cx="12" cy="12" r="3"/></svg>' +
            '<svg class="pw-eye-off" viewBox="0 0 24 24" aria-hidden="true">' +
            '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>' +
            '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>' +
            '<path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>' +
            '<line x1="1" y1="1" x2="23" y2="23"/></svg>';
        wrap.appendChild(button);

        button.addEventListener('click', function () {
            const wasHidden = input.type === 'password';
            input.type = wasHidden ? 'text' : 'password';
            button.classList.toggle('is-visible', wasHidden);
            const label = wasHidden ? 'Hide password' : 'Show password';
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
            input.focus();
        });
    });
}
