/**
 * Algenib Wishlist JS - Global API Version
 */
window.AlgWishlist = {
    init: function () {
        this.bindEvents();
        this.updateUI(); // Check initial state
    },

    bindEvents: function () {
        // Event Delegation for standard DOM buttons (WooCommerce loops, product pages)
        document.body.addEventListener('click', (e) => {
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

        this.toggleItem(productId, btn);
    },

    toggleItem: function (productId, btn) {
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
                        this.markAsActive(productId);
                    } else {
                        this.markAsInactive(productId);
                    }
                }
            })
            .catch(error => {
                console.error('Wishlist Request Failed:', error);
                btn.classList.remove('loading');
                btn.style.opacity = '1';
                // Revert optimistic update on failure
                if (isCurrentlyActive) {
                    this.markAsActive(productId);
                } else {
                    this.markAsInactive(productId);
                }
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

                if (typeof AlgWishlistSettings !== 'undefined' && AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.removed) {
                    btn.setAttribute('title', AlgWishlistSettings.i18n.removed);
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

                if (typeof AlgWishlistSettings !== 'undefined' && AlgWishlistSettings.i18n && AlgWishlistSettings.i18n.added) {
                    btn.setAttribute('title', AlgWishlistSettings.i18n.added);
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
        const badges = document.querySelectorAll('.ffla-wishlist-count');
        badges.forEach(el => el.textContent = count);
    }
};

document.addEventListener('DOMContentLoaded', function () {
    window.AlgWishlist.init();
});
