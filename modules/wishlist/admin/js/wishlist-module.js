(function () {
    'use strict';

    /**
     * Media Library picker for the wishlist Custom Icon field.
     * Requires wp.media, which the module enqueues via wp_enqueue_media().
     */
    function mediaReady() {
        return typeof window.wp !== 'undefined' && !!window.wp.media;
    }

    function initMediaFields() {
        var fields = document.querySelectorAll('[data-ffla-media]');
        if (!fields.length) {
            return;
        }

        // This script only declares a dependency on the shared admin bundle, so
        // it can run before wp.media has loaded. Wait for it rather than
        // silently leaving a dead button.
        if (!mediaReady()) {
            var tries = 0;
            var timer = window.setInterval(function () {
                tries++;
                if (mediaReady()) {
                    window.clearInterval(timer);
                    initMediaFields();
                } else if (tries > 40) { // ~10s
                    window.clearInterval(timer);
                }
            }, 250);
            return;
        }

        fields.forEach(function (field) {
            if (field.getAttribute('data-ffla-media-init') === '1') {
                return;
            }
            field.setAttribute('data-ffla-media-init', '1');

            var targetId = field.getAttribute('data-target');
            var input = targetId ? document.getElementById(targetId) : null;
            var preview = field.querySelector('[data-ffla-media-preview]');
            var selectBtn = field.querySelector('[data-ffla-media-select]');
            var removeBtn = field.querySelector('[data-ffla-media-remove]');
            if (!input || !selectBtn) {
                return;
            }

            var frame = null;

            function setPreview(url) {
                if (!preview) {
                    return;
                }
                preview.innerHTML = '';
                if (url) {
                    var img = document.createElement('img');
                    img.src = url;
                    img.alt = '';
                    preview.appendChild(img);
                    preview.hidden = false;
                } else {
                    preview.hidden = true;
                }
                if (removeBtn) {
                    removeBtn.hidden = !url;
                }
            }

            selectBtn.addEventListener('click', function (e) {
                e.preventDefault();

                if (frame) {
                    frame.open();
                    return;
                }

                frame = window.wp.media({
                    title: selectBtn.textContent.trim(),
                    button: { text: selectBtn.textContent.trim() },
                    library: { type: 'image' },
                    multiple: false
                });

                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first();
                    if (!attachment) {
                        return;
                    }
                    var data = attachment.toJSON();
                    input.value = data.id;

                    // SVGs have no generated sizes, so fall back to the file URL.
                    var url = data.url;
                    if (data.sizes && data.sizes.thumbnail && data.sizes.thumbnail.url) {
                        url = data.sizes.thumbnail.url;
                    }
                    setPreview(url);
                });

                frame.open();
            });

            if (removeBtn) {
                removeBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    input.value = '';
                    setPreview('');
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMediaFields);
    } else {
        initMediaFields();
    }
})();
