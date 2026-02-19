document.addEventListener('DOMContentLoaded', function () {

    // Initialize Wishlist State
    const Wishlist = {
        items: [], // Array of product IDs

        init: function () {
            // Load initial state from localized script
            if (typeof AlgWishlistSettings !== 'undefined') {
                this.items = AlgWishlistSettings.initial_items.map(String);
            }

            // Render initial state
            this.updateUI();

            // Single delegated click listener on body — handles both
            // .alg-add-to-wishlist and .wbw-doofinder-btn, present or future.
            document.body.addEventListener('click', this.handleClick.bind(this));

            // Watch for Doofinder dynamically injecting buttons into the DOM.
            // Using MutationObserver instead of df:layer:render because Doofinder
            // injects buttons asynchronously and the event name varies by version.
            this.observeDoofinder();
        },

        handleClick: function (e) {
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
                            formData.append('nonce', AlgWishlistSettings.nonce);

                            fetch(AlgWishlistSettings.ajax_url, {
                                method: 'POST',
                                body: formData
                            })
                                .then(response => {
                                    if (!response.ok) throw new Error('Network response was not ok');
                                    return response.json();
                                })
                                .then(data => {
                                    if (data.success) {
                                        // Sync server state
                                        this.items = data.data.items.map(String);
                                        this.updateUI();

                                        // Check if the item we tried to add is actually there
                                        const serverHasItem = this.items.includes(productId);
                                        if (!isAdded && !serverHasItem) {
                                            // We tried to add, but server didn't save it -> Revert
                                            console.warn('Server failed to save item');
                                            const failedBtn = document.querySelector(`.alg-add-to-wishlist[data-product-id="${productId}"]`);
                                            if (failedBtn) failedBtn.classList.remove('active');
                                        } else {
                                            // Success! Show Feedback
                                            const msg = !isAdded ? AlgWishlistSettings.i18n.added : AlgWishlistSettings.i18n.removed;
                                            this.showToast(msg);
                                        }
                                    } else {
                                        throw new Error(data.data.message || 'Unknown error');
                                    }
                                })
                                .catch(error => {
                                    console.error('Wishlist Action Failed:', error);
                                    // Revert UI state
                                    if (isAdded) {
                                        this.items.push(productId); // Put it back
                                    } else {
                                        this.items = this.items.filter(id => id !== productId); // Remove it
                                    }
                                    this.updateUI();

                                    alert('Could not update wishlist: ' + error.message);
                                });
                        },

                        updateUI: function () {
                            // Update all buttons on page
                            const buttons = document.querySelectorAll('.alg-add-to-wishlist, .wbw-doofinder-btn');
                            buttons.forEach(btn => {
                                const id = btn.getAttribute('data-product-id');
                                if (this.items.includes(id)) {
                                    btn.classList.add('active');
                                    btn.setAttribute('aria-label', AlgWishlistSettings.i18n.removed);
                                } else {
                                    btn.classList.remove('active');
                                    btn.setAttribute('aria-label', AlgWishlistSettings.i18n.added);
                                }
                            });

                            // Update Counters
                            const counters = document.querySelectorAll('.alg-wishlist-count');
                            counters.forEach(counter => {
                                counter.innerText = this.items.length;
                                counter.classList.toggle('hidden', this.items.length === 0);
                            });
                        },

                        showToast: function (message) {
                            let toast = document.querySelector('.alg-wishlist-toast');
                            if (!toast) {
                                toast = document.createElement('div');
                                toast.className = 'alg-wishlist-toast';
                                document.body.appendChild(toast);
                            }
                            toast.innerText = message;
                            toast.classList.add('show');

                            // Clear previous timeout
                            if (this.toastTimeout) clearTimeout(this.toastTimeout);

                            this.toastTimeout = setTimeout(() => {
                                toast.classList.remove('show');
                            }, 3000);
                        },
                    };

                    Wishlist.init();
                });
