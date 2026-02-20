/**
 * WooBooster Module JS
 * Vanilla JS — AJAX autocomplete, dynamic form logic, rule tester, toggle.
 */
(function () {
  'use strict';

  var cfg = window.wooboosterAdmin || {};

  /* ── Rule Toggle (inline) ─────────────────────────────────────────── */

  function initRuleToggles() {
    document.querySelectorAll('.wb-toggle-rule').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ruleId = btn.dataset.ruleId;
        var fd = new FormData();
        fd.append('action', 'woobooster_toggle_rule');
        fd.append('nonce', cfg.nonce);
        fd.append('rule_id', ruleId);

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res.success) { location.reload(); }
          });
      });
    });
  }

  /* ── Delete Confirmation ──────────────────────────────────────────── */

  function initDeleteConfirm() {
    document.querySelectorAll('.wb-delete-rule').forEach(function (link) {
      link.addEventListener('click', function (e) {
        if (!confirm(cfg.i18n.confirmDelete)) {
          e.preventDefault();
        }
      });
    });
  }

  /* ── Rule Tester ──────────────────────────────────────────────────── */

  function initRuleTester() {
    var input = document.getElementById('wb-test-product');
    var btn = document.getElementById('wb-test-btn');
    var results = document.getElementById('wb-test-results');
    if (!input || !btn || !results) return;

    btn.addEventListener('click', function () {
      var val = input.value.trim();
      if (!val) return;
      results.style.display = 'block';
      results.innerHTML = '<p class="wb-text--muted">' + (cfg.i18n.testing || 'Testing…') + '</p>';

      var fd = new FormData();
      fd.append('action', 'woobooster_test_rule');
      fd.append('nonce', cfg.nonce);
      fd.append('product', val);

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.success) {
            results.innerHTML = '<div class="wb-message wb-message--danger"><span>' + (res.data.message || 'Error') + '</span></div>';
            return;
          }
          renderDiagnostics(res.data);
        });
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
    });

    function renderDiagnostics(d) {
      var html = '<div class="wb-test-grid">';

      // Product info.
      html += '<div class="wb-test-section"><h4>Product</h4>';
      html += '<p><strong>#' + d.product_id + '</strong> — ' + esc(d.product_name) + '</p></div>';

      // Matched rule.
      html += '<div class="wb-test-section"><h4>Matched Rule</h4>';
      if (d.matched_rule) {
        var r = d.matched_rule;
        html += '<p><strong>' + esc(r.name) + '</strong> (priority ' + r.priority + ')</p>';
        html += '<p>Condition: <code>' + esc(r.condition_attribute) + ' ' + esc(r.condition_operator) + ' ' + esc(r.condition_value) + '</code></p>';
        html += '<p>Action: ' + esc(r.action_source) + ' → <code>' + esc(r.action_value || '—') + '</code> (order: ' + esc(r.action_orderby) + ', limit: ' + r.action_limit + ')</p>';
      } else {
        html += '<p class="wb-text--muted">No rule matched.</p>';
      }
      html += '</div>';

      // Resulting products.
      html += '<div class="wb-test-section"><h4>Recommended Products (' + d.product_ids.length + ')</h4>';
      if (d.products && d.products.length) {
        html += '<table class="wb-mini-table"><thead><tr><th>ID</th><th>Name</th><th>Price</th><th>Stock</th></tr></thead><tbody>';
        d.products.forEach(function (p) {
          html += '<tr><td>' + p.id + '</td><td>' + esc(p.name) + '</td><td>' + p.price + '</td><td>' + esc(p.stock) + '</td></tr>';
        });
        html += '</tbody></table>';
      } else {
        html += '<p class="wb-text--muted">No products returned.</p>';
      }
      html += '</div>';

      // Timing.
      html += '<div class="wb-test-section"><h4>Performance</h4>';
      html += '<p>Execution time: <strong>' + d.time_ms + 'ms</strong></p></div>';

      // Condition keys.
      html += '<div class="wb-test-section wb-test-section--collapsible"><h4>Condition Keys (' + d.keys.length + ')</h4>';
      html += '<div class="wb-code-block"><code>' + d.keys.join('<br>') + '</code></div></div>';

      html += '</div>'; // .wb-test-grid
      results.innerHTML = html;
    }

    function esc(s) {
      if (!s) return '';
      var d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    }
  }

  /* ── Init ──────────────────────────────────────────────────────────── */

  document.addEventListener('DOMContentLoaded', function () {
    initConditionRepeater();
    initActionRepeater();
    initRuleToggles();
    initDeleteConfirm();
    initRuleTester();
    initImportExport();
    initSmartRecommendations();
  });

  /* ── Action Repeater ─────────────────────────────────────────────── */

  function initActionRepeater() {
    var container = document.getElementById('wb-action-repeater');
    var addBtn = document.getElementById('wb-add-action');
    if (!container) return;

    // Init existing rows.
    container.querySelectorAll('.wb-action-row').forEach(function (row) {
      bindActionRow(row);
    });

    // Add Action.
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        var newIdx = container.querySelectorAll('.wb-action-row').length;
        var fragment = createActionRow(newIdx);
        // Grab the action row element before appending (fragment empties on append).
        var actionRow = fragment.querySelector('.wb-action-row');
        container.appendChild(fragment);
        bindActionRow(actionRow);
        renumberActionFields();
      });
    }

    // Remove Action (also removes sibling panels).
    container.addEventListener('click', function (e) {
      if (e.target.classList.contains('wb-remove-action')) {
        var row = e.target.closest('.wb-action-row');
        if (container.querySelectorAll('.wb-action-row').length > 1) {
          // Remove sibling panels that follow this action row.
          var sibling = row.nextElementSibling;
          while (sibling && !sibling.classList.contains('wb-action-row')) {
            var next = sibling.nextElementSibling;
            sibling.remove();
            sibling = next;
          }
          row.remove();
          renumberActionFields();
        } else {
          alert('At least one action is required.');
        }
      }
    });

    function bindActionRow(row) {
      initActionRowToggle(row);
      initActionRowAutocomplete(row);
      initProductSearch(row);
      initCouponSearch(row);
      initExclusionPanel(row);
    }

    function createActionRow(idx) {
      var row = document.createElement('div');
      row.className = 'wb-action-row';
      row.dataset.index = idx;
      var prefix = 'actions[' + idx + ']';

      // Build attribute taxonomy options from existing select.
      var existingAttrSelect = document.querySelector('.wb-action-attr-taxonomy');
      var attrOptions = '<option value="">Attribute\u2026</option>';
      if (existingAttrSelect) {
        Array.prototype.slice.call(existingAttrSelect.options).forEach(function (opt) {
          if (opt.value) attrOptions += '<option value="' + opt.value + '">' + opt.textContent + '</option>';
        });
      }

      row.innerHTML =
        // Source Type
        '<select name="' + prefix + '[action_source]" class="wb-select wb-action-source" style="width: auto; flex-shrink: 0;">' +
        '<option value="category">Category</option>' +
        '<option value="tag">Tag</option>' +
        '<option value="attribute">Same Attribute</option>' +
        '<option value="attribute_value">Attribute</option>' +
        '<option value="copurchase">Bought Together</option>' +
        '<option value="trending">Trending</option>' +
        '<option value="recently_viewed">Recently Viewed</option>' +
        '<option value="similar">Similar Products</option>' +
        '<option value="specific_products">Specific Products</option>' +
        '<option value="apply_coupon">Apply Coupon</option>' +
        '</select>' +

        // Attribute Taxonomy (for attribute_value source)
        '<select class="wb-select wb-action-attr-taxonomy" style="width: auto; flex-shrink: 0; display:none;">' + attrOptions + '</select>' +

        // Value Autocomplete
        '<div class="wb-autocomplete wb-action-value-wrap" style="flex: 1; min-width: 200px;">' +
        '<input type="text" class="wb-input wb-autocomplete__input wb-action-value-display" placeholder="Value\u2026" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[action_value]" class="wb-action-value-hidden">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '</div>' +

        // Include Children
        '<label class="wb-checkbox wb-action-children-label" style="display:none; margin-left: 10px; align-self: center;">' +
        '<input type="checkbox" name="' + prefix + '[include_children]" value="1"> + Children' +
        '</label>' +

        // Order By
        '<select name="' + prefix + '[action_orderby]" class="wb-select" style="width: auto; flex-shrink: 0;" title="Order By">' +
        '<option value="rand">Random</option>' +
        '<option value="date">Newest</option>' +
        '<option value="price">Price (Low to High)</option>' +
        '<option value="price_desc">Price (High to Low)</option>' +
        '<option value="bestselling">Bestselling</option>' +
        '<option value="rating">Rating</option>' +
        '</select>' +

        // Limit
        '<input type="number" name="' + prefix + '[action_limit]" value="4" min="1" class="wb-input wb-input--sm" style="width: 70px;" title="Limit">' +

        // Remove
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-action" title="Remove">&times;</button>';

      // Specific Products panel
      var productsPanel = document.createElement('div');
      productsPanel.className = 'wb-action-products-panel';
      productsPanel.style.display = 'none';
      productsPanel.style.padding = '8px 0 0 20px';
      productsPanel.innerHTML =
        '<label class="wb-field__label">Select Products</label>' +
        '<div class="wb-autocomplete wb-product-search" style="max-width: 500px;">' +
        '<input type="text" class="wb-input wb-product-search__input" placeholder="Search products by name\u2026" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[action_products]" class="wb-product-search__ids" value="">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '<div class="wb-product-chips" style="margin-top: 6px;"></div>' +
        '</div>';

      // Coupon panel
      var couponPanel = document.createElement('div');
      couponPanel.className = 'wb-action-coupon-panel';
      couponPanel.style.display = 'none';
      couponPanel.style.padding = '8px 0 0 20px';
      couponPanel.innerHTML =
        '<label class="wb-field__label">Select Coupon</label>' +
        '<div class="wb-autocomplete wb-coupon-search" style="max-width: 400px;">' +
        '<input type="text" class="wb-input wb-coupon-search__input" placeholder="Search coupons\u2026" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[action_coupon_id]" class="wb-coupon-search__id" value="">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '</div>';

      // Exclusion panel
      var exclusionPanel = document.createElement('div');
      exclusionPanel.className = 'wb-exclusion-panel';
      exclusionPanel.style.padding = '8px 0 0 20px';
      exclusionPanel.style.marginBottom = '12px';
      exclusionPanel.innerHTML =
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-exclusions" style="margin-bottom: 8px;">\u25b6 Exclusions</button>' +
        '<div class="wb-exclusion-body" style="display:none;">' +
          '<div class="wb-field" style="margin-bottom: 10px;">' +
            '<label class="wb-field__label">Exclude Categories</label>' +
            '<div class="wb-autocomplete wb-exclude-cats-search" style="max-width: 500px;">' +
              '<input type="text" class="wb-input wb-exclude-cats__input" placeholder="Search categories\u2026" autocomplete="off">' +
              '<input type="hidden" name="' + prefix + '[exclude_categories]" class="wb-exclude-cats__ids" value="">' +
              '<div class="wb-autocomplete__dropdown"></div>' +
              '<div class="wb-exclude-cats-chips" style="margin-top: 6px;"></div>' +
            '</div>' +
          '</div>' +
          '<div class="wb-field" style="margin-bottom: 10px;">' +
            '<label class="wb-field__label">Exclude Products</label>' +
            '<div class="wb-autocomplete wb-exclude-prods-search" style="max-width: 500px;">' +
              '<input type="text" class="wb-input wb-exclude-prods__input" placeholder="Search products\u2026" autocomplete="off">' +
              '<input type="hidden" name="' + prefix + '[exclude_products]" class="wb-exclude-prods__ids" value="">' +
              '<div class="wb-autocomplete__dropdown"></div>' +
              '<div class="wb-exclude-prods-chips" style="margin-top: 6px;"></div>' +
            '</div>' +
          '</div>' +
          '<div class="wb-field" style="margin-bottom: 10px;">' +
            '<label class="wb-field__label">Price Range Filter</label>' +
            '<div style="display: flex; gap: 10px; align-items: center;">' +
              '<input type="number" name="' + prefix + '[exclude_price_min]" class="wb-input wb-input--sm" style="width: 100px;" placeholder="Min $" step="0.01" min="0">' +
              '<span>\u2014</span>' +
              '<input type="number" name="' + prefix + '[exclude_price_max]" class="wb-input wb-input--sm" style="width: 100px;" placeholder="Max $" step="0.01" min="0">' +
              '<span class="wb-field__desc">Only include products in this price range</span>' +
            '</div>' +
          '</div>' +
        '</div>';

      // Append panels after the action row (as siblings in the container)
      var fragment = document.createDocumentFragment();
      fragment.appendChild(row);
      fragment.appendChild(productsPanel);
      fragment.appendChild(couponPanel);
      fragment.appendChild(exclusionPanel);

      return fragment;
    }

    function renumberActionFields() {
      container.querySelectorAll('.wb-action-row').forEach(function (row, i) {
        row.dataset.index = i;
        var num = row.querySelector('.wb-action-number');
        if (num) num.textContent = i + 1;

        var prefix = 'actions[' + i + ']';
        // Renumber the action row itself.
        row.querySelectorAll('[name]').forEach(function (el) {
          var name = el.getAttribute('name');
          if (name) {
            el.setAttribute('name', name.replace(/actions\[\d+\]/, prefix));
          }
        });
        // Renumber sibling panels (products, coupon, exclusion) that follow this row.
        var sibling = row.nextElementSibling;
        while (sibling && !sibling.classList.contains('wb-action-row')) {
          sibling.querySelectorAll('[name]').forEach(function (el) {
            var name = el.getAttribute('name');
            if (name) {
              el.setAttribute('name', name.replace(/actions\[\d+\]/, prefix));
            }
          });
          sibling = sibling.nextElementSibling;
        }
      });
    }

    function initActionRowToggle(row) {
      var source = row.querySelector('.wb-action-source');
      var valWrap = row.querySelector('.wb-action-value-wrap');
      var childLabel = row.querySelector('.wb-action-children-label');
      var attrTaxSelect = row.querySelector('.wb-action-attr-taxonomy');
      var productsPanel = row.parentElement && row.nextElementSibling && row.nextElementSibling.classList.contains('wb-action-products-panel') ? row.nextElementSibling : null;
      var couponPanel = null;
      var noValueSources = ['attribute', 'copurchase', 'trending', 'recently_viewed', 'similar', 'specific_products', 'apply_coupon'];

      // Find sibling panels by traversing siblings of the action row.
      var sibling = row.nextElementSibling;
      while (sibling) {
        if (sibling.classList.contains('wb-action-products-panel')) productsPanel = sibling;
        if (sibling.classList.contains('wb-action-coupon-panel')) couponPanel = sibling;
        if (sibling.classList.contains('wb-exclusion-panel')) break;
        sibling = sibling.nextElementSibling;
      }

      function toggle() {
        if (valWrap) {
          valWrap.style.display = noValueSources.indexOf(source.value) !== -1 ? 'none' : '';
        }
        if (childLabel) {
          childLabel.style.display = source.value === 'category' ? '' : 'none';
        }
        if (attrTaxSelect) {
          attrTaxSelect.style.display = source.value === 'attribute_value' ? '' : 'none';
        }
        if (productsPanel) {
          productsPanel.style.display = source.value === 'specific_products' ? '' : 'none';
        }
        if (couponPanel) {
          couponPanel.style.display = source.value === 'apply_coupon' ? '' : 'none';
        }
      }

      if (source) {
        source.addEventListener('change', toggle);
        toggle();
      }
    }

    function initActionRowAutocomplete(row) {
      var display = row.querySelector('.wb-action-value-display');
      var hidden = row.querySelector('.wb-action-value-hidden');
      var dropdown = row.querySelector('.wb-autocomplete__dropdown');
      var sourceSelect = row.querySelector('.wb-action-source');
      var attrTaxSelect = row.querySelector('.wb-action-attr-taxonomy');

      if (!display || !hidden || !dropdown || !sourceSelect) return;

      var debounce = null;

      function getTaxonomy() {
        if (sourceSelect.value === 'category') return 'product_cat';
        if (sourceSelect.value === 'tag') return 'product_tag';
        if (sourceSelect.value === 'attribute_value' && attrTaxSelect) return attrTaxSelect.value;
        return '';
      }

      function searchTerms(search) {
        var taxonomy = getTaxonomy();
        if (!taxonomy) { dropdown.style.display = 'none'; return; }

        var fd = new FormData();
        fd.append('action', 'woobooster_search_terms');
        fd.append('nonce', cfg.nonce);
        fd.append('taxonomy', taxonomy);
        fd.append('search', search);
        fd.append('page', 1);

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res.success) return;
            dropdown.innerHTML = '';
            res.data.terms.forEach(function (t) {
              var item = document.createElement('div');
              item.className = 'wb-autocomplete__item';
              item.textContent = t.name + ' (' + t.count + ')';
              item.addEventListener('click', function () {
                display.value = t.name;
                // For attribute_value, store taxonomy:term_slug.
                if (sourceSelect.value === 'attribute_value' && attrTaxSelect && attrTaxSelect.value) {
                  hidden.value = attrTaxSelect.value + ':' + t.slug;
                } else {
                  hidden.value = t.slug;
                }
                dropdown.style.display = 'none';
              });
              dropdown.appendChild(item);
            });
            dropdown.style.display = dropdown.children.length ? 'block' : 'none';
          });
      }

      display.addEventListener('input', function () {
        clearTimeout(debounce);
        hidden.value = '';
        debounce = setTimeout(function () { searchTerms(display.value); }, 300);
      });

      display.addEventListener('focus', function () {
        if (!dropdown.children.length && display.value.length === 0) {
          searchTerms('');
        } else if (dropdown.children.length) {
          dropdown.style.display = 'block';
        }
      });

      document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && e.target !== display) {
          dropdown.style.display = 'none';
        }
      });

      // When attribute taxonomy changes, reset value and search.
      if (attrTaxSelect) {
        attrTaxSelect.addEventListener('change', function () {
          display.value = '';
          hidden.value = '';
          dropdown.innerHTML = '';
          if (sourceSelect.value === 'attribute_value' && attrTaxSelect.value) {
            searchTerms('');
          }
        });
      }
    }

    /* ── Product Search (for specific_products action) ──────────────── */
    function initProductSearch(row) {
      var sibling = row.nextElementSibling;
      while (sibling && !sibling.classList.contains('wb-action-products-panel')) {
        sibling = sibling.nextElementSibling;
      }
      if (!sibling) return;

      var input = sibling.querySelector('.wb-product-search__input');
      var hiddenIds = sibling.querySelector('.wb-product-search__ids');
      var dropdown = sibling.querySelector('.wb-autocomplete__dropdown');
      var chipsEl = sibling.querySelector('.wb-product-chips');
      if (!input || !hiddenIds || !dropdown) return;

      renderChips(hiddenIds, chipsEl, 'product');
      var debounce = null;

      input.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { searchProducts(input.value, dropdown, hiddenIds, chipsEl); }, 300);
      });
      input.addEventListener('focus', function () {
        if (!dropdown.children.length) searchProducts('', dropdown, hiddenIds, chipsEl);
        else dropdown.style.display = 'block';
      });
      document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && e.target !== input) dropdown.style.display = 'none';
      });
    }

    /* ── Coupon Search (for apply_coupon action) ────────────────────── */
    function initCouponSearch(row) {
      var sibling = row.nextElementSibling;
      while (sibling && !sibling.classList.contains('wb-action-coupon-panel')) {
        sibling = sibling.nextElementSibling;
      }
      if (!sibling) return;

      var input = sibling.querySelector('.wb-coupon-search__input');
      var hiddenId = sibling.querySelector('.wb-coupon-search__id');
      var dropdown = sibling.querySelector('.wb-autocomplete__dropdown');
      if (!input || !hiddenId || !dropdown) return;

      var debounce = null;
      input.addEventListener('input', function () {
        clearTimeout(debounce);
        hiddenId.value = '';
        debounce = setTimeout(function () {
          var fd = new FormData();
          fd.append('action', 'woobooster_search_coupons');
          fd.append('nonce', cfg.nonce);
          fd.append('search', input.value);

          fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              if (!res.success) return;
              dropdown.innerHTML = '';
              res.data.coupons.forEach(function (c) {
                var item = document.createElement('div');
                item.className = 'wb-autocomplete__item';
                item.textContent = c.code + ' (' + c.type + ': ' + c.amount + ')';
                item.addEventListener('click', function () {
                  input.value = c.code;
                  hiddenId.value = c.id;
                  dropdown.style.display = 'none';
                });
                dropdown.appendChild(item);
              });
              dropdown.style.display = dropdown.children.length ? 'block' : 'none';
            });
        }, 300);
      });
      input.addEventListener('focus', function () {
        if (!dropdown.children.length && input.value.length === 0) {
          input.dispatchEvent(new Event('input'));
        } else if (dropdown.children.length) {
          dropdown.style.display = 'block';
        }
      });
      document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && e.target !== input) dropdown.style.display = 'none';
      });
    }

    /* ── Exclusion Panel ────────────────────────────────────────────── */
    function initExclusionPanel(row) {
      // Find the exclusion panel that belongs to this action row.
      var sibling = row.nextElementSibling;
      while (sibling && !sibling.classList.contains('wb-exclusion-panel')) {
        sibling = sibling.nextElementSibling;
      }
      if (!sibling) return;

      // Toggle button.
      var toggleBtn = sibling.querySelector('.wb-toggle-exclusions');
      var body = sibling.querySelector('.wb-exclusion-body');
      if (toggleBtn && body) {
        toggleBtn.addEventListener('click', function () {
          var isOpen = body.style.display !== 'none';
          body.style.display = isOpen ? 'none' : '';
          toggleBtn.textContent = (isOpen ? '\u25b6' : '\u25bc') + ' Exclusions';
        });
      }

      // Exclude categories search.
      var catInput = sibling.querySelector('.wb-exclude-cats__input');
      var catIds = sibling.querySelector('.wb-exclude-cats__ids');
      var catDropdown = sibling.querySelector('.wb-exclude-cats-search .wb-autocomplete__dropdown');
      var catChips = sibling.querySelector('.wb-exclude-cats-chips');
      if (catInput && catIds && catDropdown) {
        renderChips(catIds, catChips, 'cat');
        var catDebounce = null;
        catInput.addEventListener('input', function () {
          clearTimeout(catDebounce);
          catDebounce = setTimeout(function () {
            var fd = new FormData();
            fd.append('action', 'woobooster_search_terms');
            fd.append('nonce', cfg.nonce);
            fd.append('taxonomy', 'product_cat');
            fd.append('search', catInput.value);
            fd.append('page', 1);

            fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
              .then(function (r) { return r.json(); })
              .then(function (res) {
                if (!res.success) return;
                catDropdown.innerHTML = '';
                var currentIds = (catIds.value || '').split(',').filter(Boolean);
                res.data.terms.forEach(function (t) {
                  if (currentIds.indexOf(t.slug) !== -1) return;
                  var item = document.createElement('div');
                  item.className = 'wb-autocomplete__item';
                  item.textContent = t.name + ' (' + t.count + ')';
                  item.addEventListener('click', function () {
                    currentIds.push(t.slug);
                    catIds.value = currentIds.join(',');
                    renderChips(catIds, catChips, 'cat');
                    catDropdown.style.display = 'none';
                    catInput.value = '';
                  });
                  catDropdown.appendChild(item);
                });
                catDropdown.style.display = catDropdown.children.length ? 'block' : 'none';
              });
          }, 300);
        });
        catInput.addEventListener('focus', function () {
          if (!catDropdown.children.length) catInput.dispatchEvent(new Event('input'));
          else catDropdown.style.display = 'block';
        });
        document.addEventListener('click', function (e) {
          if (!catDropdown.contains(e.target) && e.target !== catInput) catDropdown.style.display = 'none';
        });
      }

      // Exclude products search.
      var prodInput = sibling.querySelector('.wb-exclude-prods__input');
      var prodIds = sibling.querySelector('.wb-exclude-prods__ids');
      var prodDropdown = sibling.querySelector('.wb-exclude-prods-search .wb-autocomplete__dropdown');
      var prodChips = sibling.querySelector('.wb-exclude-prods-chips');
      if (prodInput && prodIds && prodDropdown) {
        renderChips(prodIds, prodChips, 'product');
        var prodDebounce = null;
        prodInput.addEventListener('input', function () {
          clearTimeout(prodDebounce);
          prodDebounce = setTimeout(function () {
            searchProducts(prodInput.value, prodDropdown, prodIds, prodChips);
          }, 300);
        });
        prodInput.addEventListener('focus', function () {
          if (!prodDropdown.children.length) prodInput.dispatchEvent(new Event('input'));
          else prodDropdown.style.display = 'block';
        });
        document.addEventListener('click', function (e) {
          if (!prodDropdown.contains(e.target) && e.target !== prodInput) prodDropdown.style.display = 'none';
        });
      }
    }

    /* ── Condition Exclusion Panel ──────────────────────────────────── */
    function initCondExclusionPanel(row, panel) {
      if (!panel) {
        // Find the panel as next sibling of the condition row.
        var el = row.nextElementSibling;
        if (el && el.classList.contains('wb-cond-exclusion-panel')) {
          panel = el;
        }
      }
      if (!panel) return;

      // Toggle button.
      var toggleBtn = panel.querySelector('.wb-toggle-cond-exclusions');
      var body = panel.querySelector('.wb-cond-exclusion-body');
      if (toggleBtn && body) {
        toggleBtn.addEventListener('click', function () {
          var isOpen = body.style.display !== 'none';
          body.style.display = isOpen ? 'none' : '';
          toggleBtn.innerHTML = (isOpen ? '&#9654;' : '&#9660;') + ' Exclusions';
        });
      }

      // Exclude categories search.
      var catInput = panel.querySelector('.wb-cond-exclude-cats__input');
      var catIds = panel.querySelector('.wb-cond-exclude-cats__ids');
      var catDropdown = panel.querySelector('.wb-cond-exclude-cats-search .wb-autocomplete__dropdown');
      var catChips = panel.querySelector('.wb-cond-exclude-cats-chips');
      if (catInput && catIds && catDropdown) {
        renderChips(catIds, catChips, 'cat');
        var catDebounce = null;
        catInput.addEventListener('input', function () {
          clearTimeout(catDebounce);
          catDebounce = setTimeout(function () {
            var fd = new FormData();
            fd.append('action', 'woobooster_search_terms');
            fd.append('nonce', cfg.nonce);
            fd.append('taxonomy', 'product_cat');
            fd.append('search', catInput.value);
            fd.append('page', 1);

            fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
              .then(function (r) { return r.json(); })
              .then(function (res) {
                if (!res.success) return;
                catDropdown.innerHTML = '';
                var currentIds = (catIds.value || '').split(',').filter(Boolean);
                res.data.terms.forEach(function (t) {
                  if (currentIds.indexOf(t.slug) !== -1) return;
                  var item = document.createElement('div');
                  item.className = 'wb-autocomplete__item';
                  item.textContent = t.name + ' (' + t.count + ')';
                  item.addEventListener('click', function () {
                    currentIds.push(t.slug);
                    catIds.value = currentIds.join(',');
                    renderChips(catIds, catChips, 'cat');
                    catDropdown.style.display = 'none';
                    catInput.value = '';
                  });
                  catDropdown.appendChild(item);
                });
                catDropdown.style.display = catDropdown.children.length ? 'block' : 'none';
              });
          }, 300);
        });
        catInput.addEventListener('focus', function () {
          if (!catDropdown.children.length) catInput.dispatchEvent(new Event('input'));
          else catDropdown.style.display = 'block';
        });
        document.addEventListener('click', function (e) {
          if (!catDropdown.contains(e.target) && e.target !== catInput) catDropdown.style.display = 'none';
        });
      }

      // Exclude products search.
      var prodInput = panel.querySelector('.wb-cond-exclude-prods__input');
      var prodIds = panel.querySelector('.wb-cond-exclude-prods__ids');
      var prodDropdown = panel.querySelector('.wb-cond-exclude-prods-search .wb-autocomplete__dropdown');
      var prodChips = panel.querySelector('.wb-cond-exclude-prods-chips');
      if (prodInput && prodIds && prodDropdown) {
        renderChips(prodIds, prodChips, 'product');
        var prodDebounce = null;
        prodInput.addEventListener('input', function () {
          clearTimeout(prodDebounce);
          prodDebounce = setTimeout(function () {
            searchProducts(prodInput.value, prodDropdown, prodIds, prodChips);
          }, 300);
        });
        prodInput.addEventListener('focus', function () {
          if (!prodDropdown.children.length) prodInput.dispatchEvent(new Event('input'));
          else prodDropdown.style.display = 'block';
        });
        document.addEventListener('click', function (e) {
          if (!prodDropdown.contains(e.target) && e.target !== prodInput) prodDropdown.style.display = 'none';
        });
      }
    }

  }

  /* ── Shared Helpers (used by both Action + Condition repeaters) ──── */

  function searchProducts(search, dropdown, hiddenIds, chipsEl) {
    var fd = new FormData();
    fd.append('action', 'woobooster_search_products');
    fd.append('nonce', cfg.nonce);
    fd.append('search', search);
    fd.append('page', 1);

    fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) return;
        dropdown.innerHTML = '';
        var currentIds = (hiddenIds.value || '').split(',').filter(Boolean);
        res.data.products.forEach(function (p) {
          if (currentIds.indexOf(String(p.id)) !== -1) return;
          var item = document.createElement('div');
          item.className = 'wb-autocomplete__item';
          item.textContent = p.name + (p.sku ? ' (' + p.sku + ')' : '') + ' #' + p.id;
          item.addEventListener('click', function () {
            currentIds.push(String(p.id));
            hiddenIds.value = currentIds.join(',');
            renderChips(hiddenIds, chipsEl, 'product');
            dropdown.style.display = 'none';
          });
          dropdown.appendChild(item);
        });
        dropdown.style.display = dropdown.children.length ? 'block' : 'none';
      });
  }

  function renderChips(hiddenInput, chipsEl, type) {
    if (!chipsEl || !hiddenInput) return;
    chipsEl.innerHTML = '';
    var ids = (hiddenInput.value || '').split(',').filter(Boolean);
    ids.forEach(function (id) {
      var chip = document.createElement('span');
      chip.className = 'wb-chip';
      chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:2px 8px;background:#f0f0f0;border-radius:12px;font-size:12px;margin:2px;';
      chip.textContent = (type === 'product' ? '#' : '') + id + ' ';
      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.textContent = '\u00d7';
      removeBtn.style.cssText = 'border:none;background:none;cursor:pointer;font-size:14px;color:#666;padding:0;line-height:1;';
      removeBtn.addEventListener('click', function () {
        var newIds = (hiddenInput.value || '').split(',').filter(function (v) { return v !== id; });
        hiddenInput.value = newIds.join(',');
        renderChips(hiddenInput, chipsEl, type);
      });
      chip.appendChild(removeBtn);
      chipsEl.appendChild(chip);
    });
  }

  /* ── Condition Repeater ──────────────────────────────────────────── */

  function initConditionRepeater() {
    var container = document.getElementById('wb-condition-groups');
    var addGroupBtn = document.getElementById('wb-add-group');
    if (!container) return;

    // Init existing rows.
    container.querySelectorAll('.wb-condition-row').forEach(function (row) {
      initConditionTypeToggle(row);
      initRowAutocomplete(row);
      initCondExclusionPanel(row, null);
    });

    // Wire up existing remove buttons.
    container.addEventListener('click', function (e) {
      if (e.target.classList.contains('wb-remove-condition')) {
        var condRow = e.target.closest('.wb-condition-row');
        // Also remove the sibling exclusion panel.
        var nextEl = condRow.nextElementSibling;
        if (nextEl && nextEl.classList.contains('wb-cond-exclusion-panel')) {
          nextEl.remove();
        }
        condRow.remove();
        renumberFields();
      }
      if (e.target.classList.contains('wb-remove-group')) {
        var group = e.target.closest('.wb-condition-group');
        var divider = group.previousElementSibling;
        if (divider && divider.classList.contains('wb-or-divider')) divider.remove();
        group.remove();
        renumberFields();
      }
      if (e.target.classList.contains('wb-add-condition')) {
        addConditionToGroup(e.target.closest('.wb-condition-group'));
      }
    });

    // Add OR Group.
    if (addGroupBtn) {
      addGroupBtn.addEventListener('click', function () {
        var groups = container.querySelectorAll('.wb-condition-group');
        var newIdx = groups.length;

        var divider = document.createElement('div');
        divider.className = 'wb-or-divider';
        divider.textContent = '— OR —';
        container.appendChild(divider);

        var group = createGroupEl(newIdx);
        container.appendChild(group);
      });
    }

    function createGroupEl(groupIdx) {
      var group = document.createElement('div');
      group.className = 'wb-condition-group';
      group.dataset.group = groupIdx;

      group.innerHTML = '<div class="wb-condition-group__header">' +
        '<span class="wb-condition-group__label">Condition Group ' + (groupIdx + 1) + '</span>' +
        '<button type="button" class="wb-btn wb-btn--danger wb-btn--xs wb-remove-group">&times;</button>' +
        '</div>';

      var row = createConditionRow(groupIdx, 0);
      group.appendChild(row);

      var addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'wb-btn wb-btn--subtle wb-btn--sm wb-add-condition';
      addBtn.textContent = '+ AND Condition';
      group.appendChild(addBtn);

      return group;
    }

    function addConditionToGroup(group) {
      var rows = group.querySelectorAll('.wb-condition-row');
      var gIdx = parseInt(group.dataset.group, 10);
      var cIdx = rows.length;

      var row = createConditionRow(gIdx, cIdx);
      var addBtn = group.querySelector('.wb-add-condition');
      group.insertBefore(row, addBtn);
    }

    function createConditionRow(gIdx, cIdx) {
      var prefix = 'conditions[' + gIdx + '][' + cIdx + ']';
      var row = document.createElement('div');
      row.className = 'wb-condition-row';
      row.dataset.condition = cIdx;

      // Build attribute taxonomy options from existing select.
      var existingAttrTax = container.querySelector('.wb-condition-attr-taxonomy');
      var attrTaxOptions = '<option value="">Attribute\u2026</option>';
      if (existingAttrTax) {
        Array.prototype.slice.call(existingAttrTax.options).forEach(function (opt) {
          if (opt.value) attrTaxOptions += '<option value="' + opt.value + '">' + opt.textContent + '</option>';
        });
      }

      row.innerHTML =
        // Condition Type
        '<select class="wb-select wb-condition-type" style="width: auto; flex-shrink: 0;" required>' +
        '<option value="">Type\u2026</option>' +
        '<option value="category">Category</option>' +
        '<option value="tag">Tag</option>' +
        '<option value="attribute">Attribute</option>' +
        '<option value="specific_product">Specific Product</option>' +
        '</select>' +
        // Attribute Taxonomy (hidden unless type=attribute)
        '<select class="wb-select wb-condition-attr-taxonomy" style="width: auto; flex-shrink: 0; display:none;">' + attrTaxOptions + '</select>' +
        // Hidden attribute value
        '<input type="hidden" name="' + prefix + '[attribute]" class="wb-condition-attr" value="">' +
        '<input type="hidden" name="' + prefix + '[operator]" value="equals">' +
        '<div class="wb-autocomplete wb-condition-value-wrap">' +
        '<input type="text" class="wb-input wb-autocomplete__input wb-condition-value-display" placeholder="Value\u2026" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[value]" class="wb-condition-value-hidden">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '</div>' +
        '<label class="wb-checkbox wb-condition-children-label" style="display:none;">' +
        '<input type="checkbox" name="' + prefix + '[include_children]" value="1"> + Children' +
        '</label>' +
        '<input type="number" name="' + prefix + '[min_quantity]" value="1" min="1" class="wb-input wb-input--sm" style="width: 60px;" title="Min Qty" placeholder="Qty">' +
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-condition">&times;</button>';

      // Condition exclusion panel.
      var condExPanel = document.createElement('div');
      condExPanel.className = 'wb-cond-exclusion-panel';
      condExPanel.style.padding = '4px 0 8px 20px';
      condExPanel.innerHTML =
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-cond-exclusions" style="margin-bottom: 6px;">\u25b6 Exclusions</button>' +
        '<div class="wb-cond-exclusion-body" style="display:none;">' +
          '<div class="wb-field" style="margin-bottom: 8px;">' +
            '<label class="wb-field__label">Exclude Categories</label>' +
            '<div class="wb-autocomplete wb-cond-exclude-cats-search" style="max-width: 400px;">' +
              '<input type="text" class="wb-input wb-cond-exclude-cats__input" placeholder="Search categories\u2026" autocomplete="off">' +
              '<input type="hidden" name="' + prefix + '[exclude_categories]" class="wb-cond-exclude-cats__ids" value="">' +
              '<div class="wb-autocomplete__dropdown"></div>' +
              '<div class="wb-cond-exclude-cats-chips" style="margin-top: 4px;"></div>' +
            '</div>' +
          '</div>' +
          '<div class="wb-field" style="margin-bottom: 8px;">' +
            '<label class="wb-field__label">Exclude Products</label>' +
            '<div class="wb-autocomplete wb-cond-exclude-prods-search" style="max-width: 400px;">' +
              '<input type="text" class="wb-input wb-cond-exclude-prods__input" placeholder="Search products\u2026" autocomplete="off">' +
              '<input type="hidden" name="' + prefix + '[exclude_products]" class="wb-cond-exclude-prods__ids" value="">' +
              '<div class="wb-autocomplete__dropdown"></div>' +
              '<div class="wb-cond-exclude-prods-chips" style="margin-top: 4px;"></div>' +
            '</div>' +
          '</div>' +
          '<div class="wb-field" style="margin-bottom: 8px;">' +
            '<label class="wb-field__label">Price Range Filter</label>' +
            '<div style="display: flex; gap: 8px; align-items: center;">' +
              '<input type="number" name="' + prefix + '[exclude_price_min]" class="wb-input wb-input--sm" style="width: 90px;" placeholder="Min $" step="0.01" min="0">' +
              '<span>\u2014</span>' +
              '<input type="number" name="' + prefix + '[exclude_price_max]" class="wb-input wb-input--sm" style="width: 90px;" placeholder="Max $" step="0.01" min="0">' +
            '</div>' +
          '</div>' +
        '</div>';

      var fragment = document.createDocumentFragment();
      fragment.appendChild(row);
      fragment.appendChild(condExPanel);

      initConditionTypeToggle(row);
      initRowAutocomplete(row);
      initCondExclusionPanel(row, condExPanel);
      return fragment;
    }

    function initRowAutocomplete(row) {
      var display = row.querySelector('.wb-condition-value-display');
      var hidden = row.querySelector('.wb-condition-value-hidden');
      var dropdown = row.querySelector('.wb-autocomplete__dropdown');
      var attrSelect = row.querySelector('.wb-condition-attr');
      if (!display || !hidden || !dropdown || !attrSelect) return;

      var debounce = null;

      function doSearch(search) {
        if (attrSelect.value === 'specific_product') {
          searchConditionProducts(display, hidden, dropdown, search);
        } else {
          searchRowTerms(display, hidden, dropdown, attrSelect, search);
        }
      }

      display.addEventListener('input', function () {
        clearTimeout(debounce);
        hidden.value = '';
        debounce = setTimeout(function () { doSearch(display.value); }, 300);
      });

      display.addEventListener('focus', function () {
        if (!dropdown.children.length) {
          doSearch('');
        } else {
          dropdown.style.display = 'block';
        }
      });

      document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && e.target !== display) {
          dropdown.style.display = 'none';
        }
      });
    }

    function searchRowTerms(display, hidden, dropdown, attrSelect, search) {
      var taxonomy = attrSelect.value;
      if (!taxonomy) { dropdown.style.display = 'none'; return; }

      var fd = new FormData();
      fd.append('action', 'woobooster_search_terms');
      fd.append('nonce', cfg.nonce);
      fd.append('taxonomy', taxonomy);
      fd.append('search', search);
      fd.append('page', 1);

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.success) return;
          dropdown.innerHTML = '';

          res.data.terms.forEach(function (t) {
            var item = document.createElement('div');
            item.className = 'wb-autocomplete__item';
            item.textContent = t.name + ' (' + t.count + ')';
            item.addEventListener('click', function () {
              display.value = t.name;
              hidden.value = t.slug;
              dropdown.style.display = 'none';
            });
            dropdown.appendChild(item);
          });

          dropdown.style.display = dropdown.children.length ? 'block' : 'none';
        });
    }

    function searchConditionProducts(display, hidden, dropdown, search) {
      var fd = new FormData();
      fd.append('action', 'woobooster_search_products');
      fd.append('nonce', cfg.nonce);
      fd.append('search', search);

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.success) return;
          dropdown.innerHTML = '';

          res.data.products.forEach(function (p) {
            var item = document.createElement('div');
            item.className = 'wb-autocomplete__item';
            item.textContent = p.name + (p.sku ? ' (' + p.sku + ')' : '');
            item.addEventListener('click', function () {
              display.value = p.name;
              hidden.value = String(p.id);
              dropdown.style.display = 'none';
            });
            dropdown.appendChild(item);
          });

          dropdown.style.display = dropdown.children.length ? 'block' : 'none';
        });
    }

    function initConditionTypeToggle(row) {
      var typeSelect = row.querySelector('.wb-condition-type');
      var attrTaxSelect = row.querySelector('.wb-condition-attr-taxonomy');
      var hiddenAttr = row.querySelector('.wb-condition-attr');
      var childLabel = row.querySelector('.wb-condition-children-label');

      if (!typeSelect || !hiddenAttr) return;

      function syncUI() {
        var type = typeSelect.value;
        if (attrTaxSelect) {
          attrTaxSelect.style.display = type === 'attribute' ? '' : 'none';
        }
        if (childLabel) {
          childLabel.style.display = type === 'category' ? '' : 'none';
        }
        var display = row.querySelector('.wb-condition-value-display');
        if (display) {
          display.placeholder = type === 'specific_product' ? 'Search product\u2026' : 'Value\u2026';
        }
      }

      typeSelect.addEventListener('change', function () {
        var type = typeSelect.value;
        if (type === 'category') {
          hiddenAttr.value = 'product_cat';
        } else if (type === 'tag') {
          hiddenAttr.value = 'product_tag';
        } else if (type === 'specific_product') {
          hiddenAttr.value = 'specific_product';
        } else if (type === 'attribute' && attrTaxSelect) {
          hiddenAttr.value = attrTaxSelect.value;
        } else {
          hiddenAttr.value = '';
        }
        syncUI();
        // Clear value when type changes.
        var display = row.querySelector('.wb-condition-value-display');
        var hidden = row.querySelector('.wb-condition-value-hidden');
        var dropdown = row.querySelector('.wb-autocomplete__dropdown');
        if (display) display.value = '';
        if (hidden) hidden.value = '';
        if (dropdown) dropdown.innerHTML = '';
        if (hiddenAttr.value === 'specific_product') {
          searchConditionProducts(display, hidden, dropdown, '');
        } else if (hiddenAttr.value) {
          searchRowTerms(display, hidden, dropdown, hiddenAttr, '');
        }
      });

      if (attrTaxSelect) {
        attrTaxSelect.addEventListener('change', function () {
          if (typeSelect.value === 'attribute') {
            hiddenAttr.value = attrTaxSelect.value;
          }
          var display = row.querySelector('.wb-condition-value-display');
          var hidden = row.querySelector('.wb-condition-value-hidden');
          var dropdown = row.querySelector('.wb-autocomplete__dropdown');
          if (display) display.value = '';
          if (hidden) hidden.value = '';
          if (dropdown) dropdown.innerHTML = '';
          if (attrTaxSelect.value) {
            searchRowTerms(display, hidden, dropdown, hiddenAttr, '');
          }
        });
      }

      // Initial UI sync (don't overwrite hidden attr for existing rows).
      syncUI();
    }

    function renumberFields() {
      container.querySelectorAll('.wb-condition-group').forEach(function (group, gIdx) {
        group.dataset.group = gIdx;
        group.querySelectorAll('.wb-condition-row').forEach(function (row, cIdx) {
          row.dataset.condition = cIdx;
          var prefix = 'conditions[' + gIdx + '][' + cIdx + ']';
          row.querySelectorAll('[name]').forEach(function (el) {
            var name = el.getAttribute('name');
            el.setAttribute('name', name.replace(/conditions\[\d+\]\[\d+\]/, prefix));
          });
          // Renumber the sibling exclusion panel.
          var nextEl = row.nextElementSibling;
          if (nextEl && nextEl.classList.contains('wb-cond-exclusion-panel')) {
            nextEl.querySelectorAll('[name]').forEach(function (el) {
              var name = el.getAttribute('name');
              el.setAttribute('name', name.replace(/conditions\[\d+\]\[\d+\]/, prefix));
            });
          }
        });
      });
    }
  }

  /* ── Import / Export ────────────────────────────────────────────── */

  function initImportExport() {
    var exportBtn = document.getElementById('wb-export-rules');
    var importBtn = document.getElementById('wb-import-rules-btn');
    var fileInput = document.getElementById('wb-import-file');

    var deleteAllBtn = document.getElementById('wb-delete-all-rules');
    if (deleteAllBtn) {
      deleteAllBtn.addEventListener('click', function () {
        if (!confirm('Are you sure you want to DELETE ALL RULES? This action cannot be undone.')) return;

        deleteAllBtn.disabled = true;
        deleteAllBtn.textContent = 'Deleting…';

        var fd = new FormData();
        fd.append('action', 'woobooster_delete_all_rules');
        fd.append('nonce', cfg.nonce);

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res.success) {
              alert(res.data.message);
              window.location.reload();
            } else {
              deleteAllBtn.disabled = false;
              deleteAllBtn.textContent = 'Delete All';
              alert(res.data.message || 'Error deleting rules.');
            }
          })
          .catch(function () {
            deleteAllBtn.disabled = false;
            deleteAllBtn.textContent = 'Delete All';
            alert('Network error.');
          });
      });
    }

    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        window.location.href = cfg.ajaxUrl + '?action=woobooster_export_rules&nonce=' + cfg.nonce;
      });
    }

    if (importBtn && fileInput) {
      importBtn.addEventListener('click', function () {
        fileInput.click();
      });

      fileInput.addEventListener('change', function () {
        if (!fileInput.files.length) return;
        var file = fileInput.files[0];

        if (file.type !== 'application/json' && !file.name.endsWith('.json')) {
          alert('Please select a valid JSON file.');
          return;
        }

        var reader = new FileReader();
        reader.onload = function (e) {
          var jsonContent = e.target.result;
          uploadImport(jsonContent);
        };
        reader.readAsText(file);
      });
    }

    function uploadImport(jsonContent) {
      if (!confirm('Are you sure you want to import rules? This will add to existing rules.')) return;

      var fd = new FormData();
      fd.append('action', 'woobooster_import_rules');
      fd.append('nonce', cfg.nonce);
      fd.append('json', jsonContent);

      importBtn.disabled = true;
      importBtn.textContent = 'Importing…';

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          importBtn.disabled = false;
          importBtn.textContent = 'Import';
          fileInput.value = '';

          if (res.success) {
            alert(res.data.message);
            window.location.reload();
          } else {
            alert(res.data.message || 'Error importing rules.');
          }
        })
        .catch(function () {
          importBtn.disabled = false;
          importBtn.textContent = 'Import';
          alert('Network error.');
        });
    }
  }

  /* ── Smart Recommendations ──────────────────────────────────────── */

  function initSmartRecommendations() {
    var rebuildBtn = document.getElementById('wb-rebuild-index');
    var purgeBtn = document.getElementById('wb-purge-index');
    var statusEl = document.getElementById('wb-smart-status');

    if (rebuildBtn) {
      rebuildBtn.addEventListener('click', function () {
        rebuildBtn.disabled = true;
        rebuildBtn.textContent = 'Building…';
        if (statusEl) statusEl.textContent = '';

        var fd = new FormData();
        fd.append('action', 'woobooster_rebuild_index');
        fd.append('nonce', cfg.nonce);

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            rebuildBtn.disabled = false;
            rebuildBtn.textContent = 'Rebuild Now';
            if (statusEl) {
              statusEl.style.color = res.success ? '#00a32a' : '#d63638';
              statusEl.textContent = res.data.message;
            }
          })
          .catch(function () {
            rebuildBtn.disabled = false;
            rebuildBtn.textContent = 'Rebuild Now';
            if (statusEl) {
              statusEl.style.color = '#d63638';
              statusEl.textContent = 'Network error.';
            }
          });
      });
    }

    if (purgeBtn) {
      purgeBtn.addEventListener('click', function () {
        if (!confirm('Are you sure you want to clear all Smart Recommendations data?')) return;

        purgeBtn.disabled = true;
        purgeBtn.textContent = 'Clearing…';

        var fd = new FormData();
        fd.append('action', 'woobooster_purge_index');
        fd.append('nonce', cfg.nonce);

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            purgeBtn.disabled = false;
            purgeBtn.textContent = 'Clear All Data';
            if (statusEl) {
              statusEl.style.color = res.success ? '#00a32a' : '#d63638';
              statusEl.textContent = res.data.message;
            }
          })
          .catch(function () {
            purgeBtn.disabled = false;
            purgeBtn.textContent = 'Clear All Data';
            if (statusEl) {
              statusEl.style.color = '#d63638';
              statusEl.textContent = 'Network error.';
            }
          });
      });
    }
  }
})();
