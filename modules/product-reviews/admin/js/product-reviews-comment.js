/**
 * Photos and videos box on a review's edit screen: pick files from the Media
 * Library and take files off the review. The IDs live in a hidden field the
 * review's Update button saves.
 */
(function () {
    'use strict';

    var strings = window.fflaReviewMedia || {};

    function boot() {
        var field = document.querySelector('[data-ffla-media-ids]');
        var list = document.querySelector('[data-ffla-media-list]');
        var add = document.querySelector('[data-ffla-media-add]');
        if (!field || !list || !add || typeof window.wp === 'undefined' || !window.wp.media) {
            return;
        }

        function ids() {
            return field.value.split(',').filter(function (v) {
                return v !== '';
            });
        }

        function setIds(next) {
            field.value = next.join(',');
        }

        function item(id, url, isImage) {
            var li = document.createElement('li');
            li.setAttribute('data-id', id);
            li.style.textAlign = 'center';
            var preview;
            if (isImage) {
                preview = document.createElement('img');
                preview.src = url;
                preview.alt = '';
                preview.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:4px;display:block;';
            } else {
                preview = document.createElement('span');
                preview.textContent = strings.video || '';
                preview.style.cssText = 'display:flex;align-items:center;justify-content:center;width:80px;height:80px;background:#f0f0f1;border-radius:4px;';
            }
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button-link';
            remove.setAttribute('data-ffla-media-remove', id);
            remove.textContent = strings.remove || 'Remove';
            li.appendChild(preview);
            li.appendChild(remove);
            return li;
        }

        list.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('[data-ffla-media-remove]') : null;
            if (!button) {
                return;
            }
            var id = button.getAttribute('data-ffla-media-remove');
            setIds(ids().filter(function (v) {
                return v !== id;
            }));
            var li = button.closest('li');
            if (li) {
                li.parentNode.removeChild(li);
            }
        });

        var frame = null;
        add.addEventListener('click', function (event) {
            event.preventDefault();
            if (!frame) {
                frame = window.wp.media({
                    title: strings.title || '',
                    button: { text: strings.button || '' },
                    library: { type: ['image', 'video'] },
                    multiple: true
                });
                frame.on('select', function () {
                    var current = ids();
                    frame.state().get('selection').each(function (attachment) {
                        var data = attachment.toJSON();
                        var id = String(data.id);
                        if (current.indexOf(id) !== -1) {
                            return;
                        }
                        current.push(id);
                        var isImage = data.type === 'image';
                        var url = isImage && data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url;
                        list.appendChild(item(id, url, isImage));
                    });
                    setIds(current);
                });
            }
            frame.open();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
