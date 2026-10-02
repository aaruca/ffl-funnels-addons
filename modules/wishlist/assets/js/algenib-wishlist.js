/**
 * Algenib Wishlist JS - Global API Version
 */
window.AlgWishlist = {
    /**
     * Elements whose state follows the wishlist. Custom markup can opt in with
     * a data-alg-wishlist attribute (toggle() adds it to any element it is
     * called on).
     */
    BUTTON_SELECTOR: '.alg-add-to-wishlist, .aws-wishlist--trigger, [data-alg-wishlist]',

    init: function () {
        this.bindEvents();
        this.updateUI(); // Initial state from the page.

        if (typeof AlgWishlistSettings !== 'undefined' && Array.isArray(AlgWishlistSettings.initial_items)) {
            this.updateCount(AlgWishlistSettings.initial_items.length);
        }

        this.reconcileWithCookie();
    },

    settings: function () {
        return (typeof AlgWishlistSettings !== 'undefined') ? AlgWishlistSettings : {};
    },

    i18n: function (key, fallback) {
        const s = this.settings();
        return (s.i18n && s.i18n[key]) ? s.i18n[key] : fallback;
    },

    bindEvents: function () {
        document.body.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('.alg-remove-btn');
            if (removeBtn) {
                e.preventDefault();
                this.removeItem(removeBtn);
                return;
            }

            const btn = e.target.closest('.alg-add-to-wishlist, .aws-wishlist--trigger');
            if (btn) {
                e.preventDefault();
                this.toggle(btn);
            }
        });
    },

    /**
     * Public method to toggle wishlist state for a button.
     * Can be called directly via onclick="window.AlgWishlist.toggle(this)",
     * which is required for elements inside Shadow DOM.
     */
    toggle: function (btn) {
        if (!btn) return;

        if (btn instanceof Event) {
            btn.preventDefault();
            btn = btn.currentTarget || btn.target;
        }

        const productId = btn.getAttribute('data-product-id');
        if (!productId) return;

        // Custom markup that calls toggle() takes part in state updates.
        if (!btn.matches('.alg-add-to-wishlist, .aws-wishlist--trigger')) {
            btn.setAttribute('data-alg-wishlist', '');
        }

        const forcedAction = btn.getAttribute('data-todo');
        this.toggleItem(productId, btn, forcedAction);
    },

    /* ── State: items, cookie, server ─────────────────────────────────── */

    readCookie: function (name) {
        const parts = document.cookie ? document.cookie.split('; ') : [];
        for (let i = 0; i < parts.length; i++) {
            const eq = parts[i].indexOf('=');
            if (eq > 0 && parts[i].substring(0, eq) === name) {
                return decodeURIComponent(parts[i].substring(eq + 1));
            }
        }
        return null;
    },

    /**
     * The page may come from a full-page cache built for another visitor. The
     * state cookie holds a hash of this visitor's real list: when it does not
     * match the page, fix the page (from the cookie alone when the list is
     * empty, otherwise by asking the server).
     */
    reconcileWithCookie: function () {
        const s = this.settings();
        if (!s.state_cookie) return;
        const pageState = String(s.state || '0');
        const cookie = this.readCookie(s.state_cookie);

        if (cookie === pageState) return;
        if (cookie === '0') {
            this.applyItems([]);
            return;
        }
        if (cookie === null && pageState === '0') return; // No list, nothing shown.
        this.fetchState();
    },

    fetchState: function () {
        const s = this.settings();
        const data = new FormData();
        data.append('action', 'alg_wishlist_state');
        return fetch(s.ajax_url, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(response => response.json())
            .then(response => {
                if (response && response.success && response.data) {
                    if (response.data.nonce) {
                        s.nonce = response.data.nonce;
                    }
                    s.state = response.data.state;
                    this.applyItems(response.data.items || []);
                    return true;
                }
                return false;
            })
            .catch(() => false);
    },

    /**
     * Make every button, counter and wishlist card on the page match $ids.
     */
    applyItems: function (ids) {
        const s = this.settings();
        const list = (ids || []).map(String);
        s.initial_items = list;

        const hosts = this._collectShadowHosts();
        const roots = [document].concat(hosts.map(h => h.shadowRoot));
        roots.forEach(root => {
            root.querySelectorAll(this.BUTTON_SELECTOR).forEach(btn => {
                const id = btn.getAttribute('data-product-id');
                if (id) {
                    this._updateButtonsState([btn], list.indexOf(String(id)) !== -1);
                }
            });
        });

        // Wishlist page cards that are not in this visitor's list.
        document.querySelectorAll('.alg-wishlist-grid .alg-wishlist-card').forEach(card => {
            if (list.indexOf(String(card.getAttribute('data-product-id'))) === -1) {
                card.remove();
            }
        });
        this._maybeShowEmpty();

        this.updateCount(list.length);
    },

    _rememberItem: function (productId, inList) {
        const s = this.settings();
        const id = String(productId);
        let list = Array.isArray(s.initial_items) ? s.initial_items.map(String) : [];
        list = list.filter(x => x !== id);
        if (inList) {
            list.push(id);
        }
        s.initial_items = list;
    },

    /* ── Requests ─────────────────────────────────────────────────────── */

    /**
     * POST to the toggle endpoint; on an expired token (cached page) get a
     * fresh one from the state endpoint and retry once.
     */
    _send: function (fields, retried) {
        const s = this.settings();
        const data = new FormData();
        Object.keys(fields).forEach(k => data.append(k, fields[k]));
        data.append('nonce', s.nonce);

        return fetch(s.ajax_url, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(response => {
                if (response.status === 403 && !retried) {
                    return this.fetchState().then(() => this._send(fields, true));
                }
                return response.json();
            });
    },

    toggleItem: function (productId, btn, forcedAction) {
        const self = this;
        const isCurrentlyActive = btn.classList.contains('active');

        // Optimistic UI update.
        if (isCurrentlyActive) {
            this.markAsInactive(productId);
        } else {
            this.markAsActive(productId);
        }

        btn.classList.add('loading');
        btn.style.opacity = '0.7';

        const fields = { action: 'alg_add_to_wishlist', product_id: productId };
        if (forcedAction && forcedAction !== 'toggle') {
            fields.todo = forcedAction;
        }

        const revert = () => {
            if (isCurrentlyActive) {
                self.markAsActive(productId);
            } else {
                self.markAsInactive(productId);
            }
        };

        this._send(fields, false)
            .then(response => {
                btn.classList.remove('loading');
                btn.style.opacity = '1';

                if (response && response.success) {
                    const added = response.data.status === 'added';
                    if (added) {
                        self.markAsActive(productId);
                        self.showToast(self.i18n('added', 'Added to Wishlist'));
                    } else {
                        self.markAsInactive(productId);
                        self.showToast(self.i18n('removed', 'Removed from Wishlist'));
                    }
                    self._rememberItem(productId, added);
                    if (response.data.state) {
                        self.settings().state = response.data.state;
                    }
                    if (response.data.count !== undefined) {
                        self.updateCount(response.data.count);
                    }
                } else {
                    console.error('Wishlist Error:', response);
                    revert();
                    if (response && response.data && response.data.message) {
                        self.showToast(response.data.message);
                    }
                }
            })
            .catch(error => {
                console.error('Wishlist Request Failed:', error);
                btn.classList.remove('loading');
                btn.style.opacity = '1';
                revert();
            });
    },

    /**
     * Remove an item from the wishlist page (× on a card).
     */
    removeItem: function (btn) {
        const self = this;
        const productId = btn.getAttribute('data-product-id');
        if (!productId) return;

        const card = btn.closest('.alg-wishlist-card');

        btn.classList.add('loading');
        if (card) {
            card.style.opacity = '0.5';
            card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        }

        this._send({ action: 'alg_add_to_wishlist', product_id: productId, todo: 'remove' }, false)
            .then(response => {
                if (response && response.success) {
                    self.markAsInactive(productId);
                    self._rememberItem(productId, false);
                    if (response.data.state) {
                        self.settings().state = response.data.state;
                    }

                    if (card) {
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.9)';
                        setTimeout(() => {
                            card.remove();
                            self._maybeShowEmpty();
                        }, 300);
                    }

                    if (response.data.count !== undefined) {
                        self.updateCount(response.data.count);
                    }

                    self.showToast(self.i18n('removed', 'Removed from Wishlist'));
                } else {
                    console.error('Wishlist Remove Error:', response);
                    btn.classList.remove('loading');
                    if (card) card.style.opacity = '1';
                }
            })
            .catch(error => {
                console.error('Wishlist Remove Failed:', error);
                btn.classList.remove('loading');
                if (card) card.style.opacity = '1';
            });
    },

    /**
     * Empty-state block (message + Return to Shop) once the grid has no cards.
     * Built with DOM nodes, never innerHTML, so translations stay inert.
     */
    _maybeShowEmpty: function () {
        const grid = document.querySelector('.alg-wishlist-grid');
        if (!grid || grid.querySelector('.alg-wishlist-card') || grid.querySelector('.alg-wishlist-empty')) {
            return;
        }
        const wrap = document.createElement('div');
        wrap.className = 'alg-wishlist-empty';
        const p = document.createElement('p');
        p.textContent = this.i18n('empty_wishlist', 'Your wishlist is currently empty.');
        wrap.appendChild(p);

        const shopUrl = this.settings().shop_url;
        if (shopUrl) {
            const a = document.createElement('a');
            a.className = 'button alg-return-shop';
            a.href = shopUrl;
            a.textContent = this.i18n('return_to_shop', 'Return to Shop');
            wrap.appendChild(a);
        }

        grid.innerHTML = '';
        grid.appendChild(wrap);
    },

    /* ── Buttons ──────────────────────────────────────────────────────── */

    updateUI: function () {
        const s = this.settings();
        if (Array.isArray(s.initial_items)) {
            // Collect shadow-DOM hosts once for the whole batch.
            const shadowHosts = this._collectShadowHosts();
            s.initial_items.forEach(id => {
                this.markAsActive(id, shadowHosts);
            });
        }
    },

    /**
     * Every element in the document that hosts an open shadow root.
     */
    _collectShadowHosts: function () {
        const hosts = [];
        document.querySelectorAll('*').forEach(node => {
            if (node.shadowRoot) {
                hosts.push(node);
            }
        });
        return hosts;
    },

    _buttonsFor: function (productId, shadowHosts) {
        const id = String(productId).replace(/"/g, '');
        const selector = this.BUTTON_SELECTOR.split(',')
            .map(sel => sel.trim() + '[data-product-id="' + id + '"]')
            .join(', ');
        let nodes = Array.prototype.slice.call(document.querySelectorAll(selector));
        (shadowHosts || this._collectShadowHosts()).forEach(host => {
            nodes = nodes.concat(Array.prototype.slice.call(host.shadowRoot.querySelectorAll(selector)));
        });
        return nodes;
    },

    markAsActive: function (productId, shadowHosts) {
        this._updateButtonsState(this._buttonsFor(productId, shadowHosts), true);
    },

    markAsInactive: function (productId, shadowHosts) {
        this._updateButtonsState(this._buttonsFor(productId, shadowHosts), false);
    },

    /**
     * Label text: a button's own data-text-add / data-text-remove (Bricks
     * Wishlist Button), the standard texts for the link-style AWS button, and
     * no change for buttons with fixed text ([alg_wishlist_button text=""]).
     */
    _labelFor: function (btn, isActive) {
        const own = btn.getAttribute(isActive ? 'data-text-remove' : 'data-text-add');
        if (own !== null) {
            return own;
        }
        if (btn.classList.contains('aws-wishlist--trigger')) {
            return isActive
                ? this.i18n('text_remove', 'Remove from wishlist')
                : this.i18n('text_add', 'Add to wishlist');
        }
        return null;
    },

    _updateButtonsState: function (nodes, isActive) {
        nodes.forEach(btn => {
            const hasAwsClass = btn.classList.contains('aws-wishlist--trigger');

            btn.classList.toggle('active', isActive);

            if (hasAwsClass && btn.hasAttribute('data-type')) {
                btn.setAttribute('data-type', isActive ? 'REMOVE' : 'ADD');
            }

            const label = this._labelFor(btn, isActive);
            if (label !== null) {
                const span = btn.querySelector('.ffla-wishlist-label') || btn.querySelector('span');
                if (span) {
                    span.textContent = label;
                }
            }

            const title = isActive
                ? this.i18n('text_remove', 'Remove from wishlist')
                : this.i18n('text_add', 'Add to wishlist');
            btn.setAttribute('title', title);
            if (btn.classList.contains('snaf-wishlist-btn')) {
                btn.setAttribute('aria-label', title);
            }

            const path = btn.querySelector('path');
            if (path && !hasAwsClass) {
                path.setAttribute('fill', isActive ? 'currentColor' : 'none');
            }
        });
    },

    showToast: function (message) {
        let toast = document.getElementById('alg-wishlist-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'alg-wishlist-toast';
            toast.setAttribute('role', 'status');
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.classList.add('is-visible');

        // Trigger reflow for the animation.
        toast.offsetHeight;
        toast.classList.add('is-shown');

        if (this.toastTimeout) {
            clearTimeout(this.toastTimeout);
        }

        this.toastTimeout = setTimeout(() => {
            toast.classList.remove('is-shown');
            setTimeout(() => {
                toast.classList.remove('is-visible');
            }, 400);
        }, 3500);
    },

    /**
     * Update every counter badge. A badge with data-hide-zero="0" (Bricks
     * counter with "Hide badge when zero" off) stays visible at 0.
     */
    updateCount: function (count) {
        const badges = document.querySelectorAll('.alg-wishlist-count');
        badges.forEach(el => {
            el.textContent = count;
            const keepAtZero = el.getAttribute('data-hide-zero') === '0';
            if (count > 0 || keepAtZero) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        });
    }
};

document.addEventListener('DOMContentLoaded', function () {
    window.AlgWishlist.init();
});
