/**
 * Algenib Wishlist JS - Global API Version
 */
window.AlgWishlist = {
    init: function () {
        this.bindEvents();
        this.updateUI(); // Check initial state

        // BUG 1 fix: Set initial count from PHP-localized data
        if (typeof AlgWishlistSettings !== 'undefined' && Array.isArray(AlgWishlistSettings.initial_items)) {
            this.updateCount(AlgWishlistSettings.initial_items.length);
        }
    },

    bindEvents: function () {
        // Event Delegation for standard DOM buttons (WooCommerce loops, product pages)
        document.body.addEventListener('click', (e) => {
            // BUG 2 fix: Also handle .alg-remove-btn clicks
            const removeBtn = e.target.closest('.alg-remove-btn');
            if (removeBtn) {
                e.preventDefault();
                this.removeItem(removeBtn);
                return;
            }

            // Share button handler
            const shareBtn = e.target.closest('.alg-share-wishlist-btn');
            if (shareBtn) {
                e.preventDefault();
                this.copyShareLink(shareBtn);
                return;
            }

            const btn = e.target.closest('.alg-add-to-wishlist, .aws-wishlist--trigger');
            if (btn) {
                e.preventDefault();
                this.toggle(btn);
            }
        });

        // Sync Doofinder Shadow DOMs whenever they render asynchronous layers
        document.addEventListener('df:layer:render', (e) => {
            setTimeout(() => {
                this.updateShadowRoots();
            }, 100);
        });
    },

    /**
     * Public method to toggle wishlist state for a button.
     * Can be called directly via onclick="window.AlgWishlist.toggle(this)"
     * which is required for elements inside Shadow DOM (like Doofinder).
     * Can be called directly via onclick or triggered by custom event.
     */
    toggle: function (btn) {
        if (!btn) return;

        // Prevent default if it's an event or link
        if (btn instanceof Event) {
            btn.preventDefault();
            btn = btn.currentTarget || btn.target;
        }

        const productId = btn.getAttribute('data-product-id');
        if (!productId) return;

        // F6: Forward data-todo attribute if present
        const forcedAction = btn.getAttribute('data-todo');
        this.toggleItem(productId, btn, forcedAction);
    },

    toggleItem: function (productId, btn, forcedAction) {
        const self = this;
        // Optimistic UI Update
        const isCurrentlyActive = btn.classList.contains('active');

        if (isCurrentlyActive) {
            this.markAsInactive(productId);
        } else {
            this.markAsActive(productId);
        }

        // Visual feedback (loading)
        btn.classList.add('loading');
        btn.style.opacity = '0.7';

        const data = new FormData();
        data.append('action', 'alg_add_to_wishlist');
        data.append('product_id', productId);
        data.append('nonce', AlgWishlistSettings.nonce);

        // F6: Forward forced action (add/remove/toggle) to AJAX
        if (forcedAction && forcedAction !== 'toggle') {
            data.append('todo', forcedAction);
        }

        const ajaxUrl = AlgWishlistSettings.ajax_url;

        fetch(ajaxUrl, {
            method: 'POST',
            body: data
        })
            .then(response => response.json())
            .then(response => {
                btn.classList.remove('loading');
                btn.style.opacity = '1';

                if (response.success) {
                    if (response.data.status === 'added') {
                        self.markAsActive(productId);
                        if (typeof AlgWishlistSettings !== 'undefined' && AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.added) {
                            self.showToast(AlgWishlistSettings.i18n.added);
                        }
                    } else {
                        self.markAsInactive(productId);
                        if (typeof AlgWishlistSettings !== 'undefined' && AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.removed) {
                            self.showToast(AlgWishlistSettings.i18n.removed);
                        }
                    }

                    // Update badge count header if exists
                    if (response.data.count !== undefined) {
                        self.updateCount(response.data.count);
                    }
                } else {
                    console.error('Wishlist Error:', response);
                    // Revert optimistic update on failure
                    if (isCurrentlyActive) {
                        self.markAsActive(productId);
                    } else {
                        self.markAsInactive(productId);
                    }
                }
            })
            .catch(error => {
                console.error('Wishlist Request Failed:', error);
                btn.classList.remove('loading');
                btn.style.opacity = '1';
                // Revert optimistic update on failure
                if (isCurrentlyActive) {
                    self.markAsActive(productId);
                } else {
                    self.markAsInactive(productId);
                }
            });
    },

    /**
     * BUG 2 fix: Remove item from wishlist page (handles .alg-remove-btn clicks).
     * Sends AJAX with todo=remove, animates the card out, and updates count.
     */
    removeItem: function (btn) {
        const self = this;
        const productId = btn.getAttribute('data-product-id');
        if (!productId) return;

        const card = btn.closest('.alg-wishlist-card');

        // Visual feedback
        btn.classList.add('loading');
        if (card) {
            card.style.opacity = '0.5';
            card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        }

        const data = new FormData();
        data.append('action', 'alg_add_to_wishlist');
        data.append('product_id', productId);
        data.append('todo', 'remove');
        data.append('nonce', AlgWishlistSettings.nonce);

        fetch(AlgWishlistSettings.ajax_url, {
            method: 'POST',
            body: data
        })
            .then(response => response.json())
            .then(response => {
                if (response.success) {
                    self.markAsInactive(productId);

                    // Animate card out
                    if (card) {
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.9)';
                        setTimeout(() => {
                            card.remove();

                            // Check if grid is now empty
                                const grid = document.querySelector('.alg-wishlist-grid');
                            if (grid && grid.querySelectorAll('.alg-wishlist-card').length === 0) {
                                const emptyText = (AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.empty_wishlist)
                                    ? AlgWishlistSettings.i18n.empty_wishlist
                                    : 'Your wishlist is currently empty.';
                                // Avoid innerHTML with server-provided translations to stay XSS-safe.
                                const wrap = document.createElement('div');
                                wrap.className = 'alg-wishlist-empty';
                                const p = document.createElement('p');
                                p.textContent = emptyText;
                                wrap.appendChild(p);
                                grid.innerHTML = '';
                                grid.appendChild(wrap);
                            }
                        }, 300);
                    }

                    // Update badge count
                    if (response.data.count !== undefined) {
                        self.updateCount(response.data.count);
                    }

                    if (AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.removed) {
                        self.showToast(AlgWishlistSettings.i18n.removed);
                    }
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
     * Share feature: Copy wishlist share link to clipboard.
     */
    copyShareLink: function (btn) {
        const url = btn.getAttribute('data-url');
        if (!url) return;

        const self = this;
        navigator.clipboard.writeText(url).then(() => {
            self.showToast(
                AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.link_copied
                    ? AlgWishlistSettings.i18n.link_copied
                    : 'Link copied!'
            );
        }).catch(() => {
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = url;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            self.showToast(
                AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.link_copied
                    ? AlgWishlistSettings.i18n.link_copied
                    : 'Link copied!'
            );
        });
    },

    updateUI: function () {
        // Use localized items from PHP to set initial UI state without extra AJAX calls
        if (typeof AlgWishlistSettings !== 'undefined' && Array.isArray(AlgWishlistSettings.initial_items)) {
            AlgWishlistSettings.initial_items.forEach(id => {
                this.markAsActive(id);
            });
        }
    },

    updateShadowRoots: function () {
        if (typeof AlgWishlistSettings === 'undefined' || !Array.isArray(AlgWishlistSettings.initial_items)) {
            return;
        }

        // Loop over potential custom elements that might hold shadow DOMs
        const tags = document.querySelectorAll('*');
        tags.forEach(node => {
            if (node.shadowRoot) {
                AlgWishlistSettings.initial_items.forEach(id => {
                    const buttons = node.shadowRoot.querySelectorAll(`[data-product-id="${id}"]`);
                    this._updateButtonsState(buttons, true);
                });
            }
        });
    },

    markAsActive: function (productId) {
        // Update ALL buttons for this product (standard + Doofinder if outside shadow DOM)
        const buttons = document.querySelectorAll(`[data-product-id="${productId}"]`);
        this._updateButtonsState(buttons, true);

        // Also sweep through any open Shadow DOMs just in case
        const tags = document.querySelectorAll('*');
        tags.forEach(node => {
            if (node.shadowRoot) {
                const shadowBtns = node.shadowRoot.querySelectorAll(`[data-product-id="${productId}"]`);
                this._updateButtonsState(shadowBtns, true);
            }
        });
    },

    markAsInactive: function (productId) {
        const buttons = document.querySelectorAll(`[data-product-id="${productId}"]`);
        this._updateButtonsState(buttons, false);

        const tags = document.querySelectorAll('*');
        tags.forEach(node => {
            if (node.shadowRoot) {
                const shadowBtns = node.shadowRoot.querySelectorAll(`[data-product-id="${productId}"]`);
                this._updateButtonsState(shadowBtns, false);
            }
        });
    },

    _updateButtonsState: function (nodes, isActive) {
        nodes.forEach(btn => {
            const hasAwsClass = btn.classList.contains('aws-wishlist--trigger');

            if (isActive) {
                btn.classList.add('active');

                if (hasAwsClass && btn.hasAttribute('data-type')) {
                    btn.setAttribute('data-type', 'REMOVE');
                }

                const span = btn.querySelector('span');
                if (span) {
                    if (typeof AlgWishlistSettings !== 'undefined' && AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.text_remove) {
                        span.textContent = AlgWishlistSettings.i18n.text_remove;
                    } else {
                        span.textContent = 'Remove from wishlist';
                    }
                }

                if (typeof AlgWishlistSettings !== 'undefined' && AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.text_remove) {
                    btn.setAttribute('title', AlgWishlistSettings.i18n.text_remove);
                } else {
                    btn.setAttribute('title', 'Remove from Wishlist');
                }

                const path = btn.querySelector('path');
                if (path && !hasAwsClass) {
                    path.setAttribute('fill', 'currentColor');
                }

            } else {
                btn.classList.remove('active');

                if (hasAwsClass && btn.hasAttribute('data-type')) {
                    btn.setAttribute('data-type', 'ADD');
                }

                const span = btn.querySelector('span');
                if (span) {
                    if (typeof AlgWishlistSettings !== 'undefined' && AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.text_add) {
                        span.textContent = AlgWishlistSettings.i18n.text_add;
                    } else {
                        span.textContent = 'Add to wishlist';
                    }
                }

                if (typeof AlgWishlistSettings !== 'undefined' && AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.text_add) {
                    btn.setAttribute('title', AlgWishlistSettings.i18n.text_add);
                } else {
                    btn.setAttribute('title', 'Add to Wishlist');
                }

                const path = btn.querySelector('path');
                if (path && !hasAwsClass) {
                    path.setAttribute('fill', 'none');
                }
            }
        });
    },

    showToast: function (message) {
        let toast = document.getElementById('alg-wishlist-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'alg-wishlist-toast';
            toast.style.cssText = `
                position: fixed;
                top: 40px;
                left: 50%;
                transform: translateX(-50%);
                background: #ff4343;
                color: #ffffff;
                padding: 16px 32px;
                border-radius: 8px;
                z-index: 9999999;
                font-family: inherit;
                font-size: 16px;
                font-weight: 600;
                box-shadow: 0 10px 30px rgba(255, 67, 67, 0.4);
                transition: opacity 0.4s ease, top 0.4s ease;
                opacity: 0;
                pointer-events: none;
                text-align: center;
                display: flex;
                align-items: center;
                justify-content: center;
                min-width: 250px;
            `;
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.style.display = 'flex';

        // Trigger reflow for animation
        toast.offsetHeight;
        toast.style.opacity = '1';
        toast.style.top = '60px'; // slide down effect

        if (this.toastTimeout) {
            clearTimeout(this.toastTimeout);
        }

        this.toastTimeout = setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.top = '40px';
            setTimeout(() => {
                toast.style.display = 'none';
            }, 400); // Wait for transition duration
        }, 3500);
    },

    updateCount: function (count) {
        const badges = document.querySelectorAll('.alg-wishlist-count');
        badges.forEach(el => {
            el.textContent = count;
            if (count > 0) {
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
