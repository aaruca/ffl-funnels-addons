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
            const btn = e.target.closest('.alg-add-to-wishlist');
            if (btn) {
                e.preventDefault();
                this.toggle(btn);
            }
        });

        // Listen for custom event from isolated components (like Doofinder)
        document.addEventListener('alg_wishlist_toggle', (e) => {
            const btn = e.target;
            // Ensure button visually updates optimistically as well if it's the target
            this.toggle(btn);
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
        // Optimistic UI Update - we don't have this.items tracked easily in global without complex sync, 
        // so we just rely on visual class for optimistic, then correct on server response.
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
        data.append('action', 'alg_toggle_wishlist');
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
                    } else {
                        self.markAsInactive(productId);
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
        // Fetch current user wishlist array on load
        // If we want to strictly sync UI on load (good for cached pages):
        const data = new FormData();
        data.append('action', 'alg_get_wishlist');
        data.append('nonce', AlgWishlistSettings.nonce);
        const ajaxUrl = AlgWishlistSettings.ajax_url;

        fetch(ajaxUrl, {
            method: 'POST',
            body: data
        })
            .then(response => response.json())
            .then(response => {
                if (response.success && Array.isArray(response.data)) {
                    response.data.forEach(id => {
                        this.markAsActive(id);
                    });
                }
            })
            .catch(e => console.error(e));
    },

    markAsActive: function (productId) {
        // Update ALL buttons for this product (standard + Doofinder if outside shadow DOM)
        const buttons = document.querySelectorAll(`[data-product-id="${productId}"]`);
        this._updateButtonsState(buttons, true);

        // For elements inside Shadow DOM that triggered this, their state is updated
        // via the 'btn' object reference passed to toggleItem().
    },

    markAsInactive: function (productId) {
        const buttons = document.querySelectorAll(`[data-product-id="${productId}"]`);
        this._updateButtonsState(buttons, false);
    },

    _updateButtonsState: function (nodes, isActive) {
        nodes.forEach(btn => {
            if (isActive) {
                btn.classList.add('active');
                btn.setAttribute('title', 'Remove from Wishlist');
                const path = btn.querySelector('path');
                if (path) path.setAttribute('fill', 'currentColor');
            } else {
                btn.classList.remove('active');
                btn.setAttribute('title', 'Add to Wishlist');
                const path = btn.querySelector('path');
                if (path) path.setAttribute('fill', 'none');
            }
        });
    },

    updateCount: function (count) {
        const badges = document.querySelectorAll('.ffla-wishlist-count');
        badges.forEach(el => el.textContent = count);
    }
};

document.addEventListener('DOMContentLoaded', function () {
    window.AlgWishlist.init();
});
