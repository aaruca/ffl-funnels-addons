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
    initFormValidation();
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

    // Sync existing AND/OR divider selects with hidden input.
    initActionLogicSync();

    // Add Action.
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        var newIdx = container.querySelectorAll('.wb-action-row').length;
        var fragment = createActionRow(newIdx);
        // Grab the action row element before appending (fragment empties on append).
        var actionRow = fragment.querySelector('.wb-action-row');

        // Insert AND/OR divider before the new row (if not the first).
        if (newIdx > 0) {
          var logicHidden = document.getElementById('wb-action-logic');
          var currentLogic = logicHidden ? logicHidden.value : 'or';
          var divider = document.createElement('div');
          divider.className = 'wb-action-logic-divider';
          divider.innerHTML = '<select class="wb-action-logic-select">' +
            '<option value="or"' + (currentLogic === 'or' ? ' selected' : '') + '>OR</option>' +
            '<option value="and"' + (currentLogic === 'and' ? ' selected' : '') + '>AND</option>' +
            '</select>';
          container.appendChild(divider);
          bindLogicSelect(divider.querySelector('select'));
        }

        container.appendChild(fragment);
        bindActionRow(actionRow);
        renumberActionFields();
      });
    }

    // Remove Action (also removes sibling panels and divider).
    container.addEventListener('click', function (e) {
      if (e.target.classList.contains('wb-remove-action')) {
        var row = e.target.closest('.wb-action-row');
        if (container.querySelectorAll('.wb-action-row').length > 1) {
          // Remove the AND/OR divider before this row (if any).
          var prev = row.previousElementSibling;
          if (prev && prev.classList.contains('wb-action-logic-divider')) {
            prev.remove();
          }
          // Remove sibling panels that follow this action row.
          var sibling = row.nextElementSibling;
          while (sibling && !sibling.classList.contains('wb-action-row') && !sibling.classList.contains('wb-action-logic-divider')) {
            var next = sibling.nextElementSibling;
            sibling.remove();
            sibling = next;
          }
          row.remove();
          // If first row now has a divider before it, remove that too.
          var firstRow = container.querySelector('.wb-action-row');
          if (firstRow) {
            var firstPrev = firstRow.previousElementSibling;
            if (firstPrev && firstPrev.classList.contains('wb-action-logic-divider')) {
              firstPrev.remove();
            }
          }
          renumberActionFields();
        } else {
          alert('At least one action is required.');
        }
      }
    });

    function initActionLogicSync() {
      container.querySelectorAll('.wb-action-logic-select').forEach(function (sel) {
        bindLogicSelect(sel);
      });
    }

    function bindLogicSelect(sel) {
      sel.addEventListener('change', function () {
        var logicHidden = document.getElementById('wb-action-logic');
        if (logicHidden) logicHidden.value = sel.value;
        // Sync all other divider selects to match.
        container.querySelectorAll('.wb-action-logic-select').forEach(function (s) {
          if (s !== sel) s.value = sel.value;
        });
      });
    }

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
        '<select name="' + prefix + '[action_source]" class="wb-select wb-select--inline wb-action-source">' +
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
        '<select class="wb-select wb-select--inline wb-action-attr-taxonomy" style="display:none;">' + attrOptions + '</select>' +

        // Value Autocomplete
        '<div class="wb-autocomplete wb-action-value-wrap">' +
        '<input type="text" class="wb-input wb-autocomplete__input wb-action-value-display" placeholder="Value\u2026" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[action_value]" class="wb-action-value-hidden">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '</div>' +

        // Include Children
        '<label class="wb-checkbox wb-action-children-label" style="display:none;">' +
        '<input type="checkbox" name="' + prefix + '[include_children]" value="1"> + Children' +
        '</label>' +

        // Order By
        '<select name="' + prefix + '[action_orderby]" class="wb-select wb-select--inline" title="Order By">' +
        '<option value="rand">Random</option>' +
        '<option value="date">Newest</option>' +
        '<option value="price">Price (Low to High)</option>' +
        '<option value="price_desc">Price (High to Low)</option>' +
        '<option value="bestselling">Bestselling</option>' +
        '<option value="rating">Rating</option>' +
        '</select>' +

        // Limit
        '<input type="number" name="' + prefix + '[action_limit]" value="4" min="1" class="wb-input wb-input--sm wb-input--w70" title="Limit">' +

        // Remove
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-action" title="Remove">&times;</button>';

      // Specific Products panel
      var productsPanel = document.createElement('div');
      productsPanel.className = 'wb-action-products-panel wb-sub-panel';
      productsPanel.style.display = 'none';
      productsPanel.innerHTML =
        '<label class="wb-field__label">Select Products</label>' +
        '<div class="wb-autocomplete wb-autocomplete--md wb-product-search">' +
        '<input type="text" class="wb-input wb-product-search__input" placeholder="Search products by name\u2026" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[action_products]" class="wb-product-search__ids" value="">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '<div class="wb-product-chips wb-chips"></div>' +
        '</div>';

      // Coupon panel
      var couponPanel = document.createElement('div');
      couponPanel.className = 'wb-action-coupon-panel wb-sub-panel';
      couponPanel.style.display = 'none';
      couponPanel.innerHTML =
        '<p class="wb-field__desc wb-coupon-desc">Works with your existing WooCommerce coupons. Create coupons in Marketing &gt; Coupons first.</p>' +
        '<label class="wb-field__label">Select Coupon</label>' +
        '<div class="wb-autocomplete wb-autocomplete--sm wb-coupon-search">' +
        '<input type="text" class="wb-input wb-coupon-search__input" placeholder="Search coupons\u2026" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[action_coupon_id]" class="wb-coupon-search__id" value="">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '</div>' +
        '<div class="wb-field">' +
        '<label class="wb-field__label">Custom Cart Message</label>' +
        '<input type="text" name="' + prefix + '[action_coupon_message]" class="wb-input wb-input--max-md" placeholder="e.g. You got 15% off on Ammo products!" value="">' +
        '<p class="wb-field__desc">Leave empty for the default auto-apply message.</p>' +
        '</div>';

      // Exclusion panel
      var exclusionPanel = document.createElement('div');
      exclusionPanel.className = 'wb-exclusion-panel wb-sub-panel';
      exclusionPanel.innerHTML =
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-exclusions">\u25b6 Action Exclusions</button>' +
        '<div class="wb-exclusion-body" style="display:none;">' +
          '<div class="wb-field">' +
            '<label class="wb-field__label">Exclude Categories</label>' +
            '<div class="wb-autocomplete wb-autocomplete--md wb-exclude-cats-search">' +
              '<input type="text" class="wb-input wb-exclude-cats__input" placeholder="Search categories\u2026" autocomplete="off">' +
              '<input type="hidden" name="' + prefix + '[exclude_categories]" class="wb-exclude-cats__ids" value="">' +
              '<div class="wb-autocomplete__dropdown"></div>' +
              '<div class="wb-exclude-cats-chips wb-chips"></div>' +
            '</div>' +
          '</div>' +
          '<div class="wb-field">' +
            '<label class="wb-field__label">Exclude Products</label>' +
            '<div class="wb-autocomplete wb-autocomplete--md wb-exclude-prods-search">' +
              '<input type="text" class="wb-input wb-exclude-prods__input" placeholder="Search products\u2026" autocomplete="off">' +
              '<input type="hidden" name="' + prefix + '[exclude_products]" class="wb-exclude-prods__ids" value="">' +
              '<div class="wb-autocomplete__dropdown"></div>' +
              '<div class="wb-exclude-prods-chips wb-chips"></div>' +
            '</div>' +
          '</div>' +
          '<div class="wb-field">' +
            '<label class="wb-field__label">Price Range Filter</label>' +
            '<div class="wb-price-range">' +
              '<input type="number" name="' + prefix + '[exclude_price_min]" class="wb-input wb-input--sm wb-input--w100" placeholder="Min $" step="0.01" min="0">' +
              '<span>\u2014</span>' +
              '<input type="number" name="' + prefix + '[exclude_price_max]" class="wb-input wb-input--sm wb-input--w100" placeholder="Max $" step="0.01" min="0">' +
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
      var savedActionVal = hidden.value || '';
      var savedActionLabel = display.value || '';

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
                savedActionVal = hidden.value;
                savedActionLabel = display.value;
                dropdown.style.display = 'none';
              });
              dropdown.appendChild(item);
            });
            dropdown.style.display = dropdown.children.length ? 'block' : 'none';
          });
      }

      display.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { searchTerms(display.value); }, 300);
      });

      display.addEventListener('focus', function () {
        if (!dropdown.children.length && display.value.length === 0) {
          searchTerms('');
        } else if (dropdown.children.length) {
          dropdown.style.display = 'block';
        }
      });

      // Restore saved value if user blurs without selecting a new one.
      display.addEventListener('blur', function () {
        setTimeout(function () {
          if (!hidden.value && savedActionVal) {
            hidden.value = savedActionVal;
            display.value = savedActionLabel;
          }
        }, 250);
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
      var savedCouponId = hiddenId.value || '';
      var savedCouponLabel = input.value || '';
      input.addEventListener('input', function () {
        clearTimeout(debounce);
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
                  savedCouponId = c.id;
                  savedCouponLabel = c.code;
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
      // Restore saved coupon if user blurs without selecting a new one.
      input.addEventListener('blur', function () {
        setTimeout(function () {
          if (!hiddenId.value && savedCouponId) {
            hiddenId.value = savedCouponId;
            input.value = savedCouponLabel;
          }
        }, 250);
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
          toggleBtn.textContent = (isOpen ? '\u25b6' : '\u25bc') + ' Action Exclusions';
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
            productNameCache[String(p.id)] = p.name;
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

  // Cache for resolved product names (shared across all chip containers).
  var productNameCache = {};

  function renderChips(hiddenInput, chipsEl, type) {
    if (!chipsEl || !hiddenInput) return;
    chipsEl.innerHTML = '';
    var ids = (hiddenInput.value || '').split(',').filter(Boolean);
    if (!ids.length) return;

    // For product chips, resolve names via AJAX if not cached.
    if (type === 'product') {
      var uncached = ids.filter(function (id) { return !productNameCache[id]; });
      if (uncached.length) {
        var fd = new FormData();
        fd.append('action', 'woobooster_resolve_product_names');
        fd.append('nonce', cfg.nonce);
        fd.append('ids', uncached.join(','));
        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res.success && res.data.names) {
              Object.keys(res.data.names).forEach(function (k) {
                productNameCache[k] = res.data.names[k];
              });
            }
            buildChipEls(hiddenInput, chipsEl, ids, type);
          })
          .catch(function () {
            buildChipEls(hiddenInput, chipsEl, ids, type);
          });
        // Show temporary loading chips.
        ids.forEach(function (id) {
          var chip = document.createElement('span');
          chip.className = 'wb-chip';
          chip.className = 'wb-chip wb-chip--loading';
          chip.textContent = '#' + id + '\u2026';
          chipsEl.appendChild(chip);
        });
        return;
      }
    }

    buildChipEls(hiddenInput, chipsEl, ids, type);
  }

  function buildChipEls(hiddenInput, chipsEl, ids, type) {
    chipsEl.innerHTML = '';
    ids.forEach(function (id) {
      var label = id;
      if (type === 'product') {
        label = productNameCache[id] ? productNameCache[id] : '#' + id;
      }

      var chip = document.createElement('span');
      chip.className = 'wb-chip';
      chip.textContent = label + ' ';
      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'wb-chip__remove';
      removeBtn.textContent = '\u00d7';
      removeBtn.addEventListener('click', function () {
        var newIds = (hiddenInput.value || '').split(',').filter(function (v) { return v !== id; });
        hiddenInput.value = newIds.join(',');
        renderChips(hiddenInput, chipsEl, type);
      });
      chip.appendChild(removeBtn);
      chipsEl.appendChild(chip);
    });
  }

  /* ── Condition Exclusion Panel (module-scope — shared by both repeaters) */

  function initCondExclusionPanel(row, panel) {
    if (!panel) {
      var el = row.nextElementSibling;
      if (el && el.classList.contains('wb-cond-exclusion-panel')) {
        panel = el;
      }
    }
    if (!panel) return;

    var toggleBtn = panel.querySelector('.wb-toggle-cond-exclusions');
    var body = panel.querySelector('.wb-cond-exclusion-body');
    if (toggleBtn && body) {
      toggleBtn.addEventListener('click', function () {
        var isOpen = body.style.display !== 'none';
        body.style.display = isOpen ? 'none' : '';
        toggleBtn.textContent = (isOpen ? '\u25b6' : '\u25bc') + ' Condition Exclusions';
      });
    }

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
        '<select class="wb-select wb-select--inline wb-condition-type" required>' +
        '<option value="">Type\u2026</option>' +
        '<option value="category">Category</option>' +
        '<option value="tag">Tag</option>' +
        '<option value="attribute">Attribute</option>' +
        '<option value="specific_product">Specific Product</option>' +
        '</select>' +
        // Attribute Taxonomy (hidden unless type=attribute)
        '<select class="wb-select wb-select--inline wb-condition-attr-taxonomy" style="display:none;">' + attrTaxOptions + '</select>' +
        // Hidden attribute value
        '<input type="hidden" name="' + prefix + '[attribute]" class="wb-condition-attr" value="">' +
        '<select name="' + prefix + '[operator]" class="wb-select wb-select--operator wb-condition-operator">' +
        '<option value="equals">is</option>' +
        '<option value="not_equals">is not</option>' +
        '</select>' +
        '<div class="wb-autocomplete wb-condition-value-wrap">' +
        '<input type="text" class="wb-input wb-autocomplete__input wb-condition-value-display" placeholder="Value\u2026" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[value]" class="wb-condition-value-hidden">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '<div class="wb-condition-product-chips wb-chips" style="display:none;"></div>' +
        '</div>' +
        '<label class="wb-checkbox wb-condition-children-label" style="display:none;">' +
        '<input type="checkbox" name="' + prefix + '[include_children]" value="1"> + Children' +
        '</label>' +
        '<input type="number" name="' + prefix + '[min_quantity]" value="1" min="1" class="wb-input wb-input--sm wb-input--w60" title="Min cart qty (coupon rules only)" placeholder="Qty">' +
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-condition">&times;</button>';

      // Condition exclusion panel.
      var condExPanel = document.createElement('div');
      condExPanel.className = 'wb-cond-exclusion-panel wb-sub-panel';
      condExPanel.innerHTML =
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-cond-exclusions">\u25b6 Condition Exclusions</button>' +
        '<div class="wb-cond-exclusion-body" style="display:none;">' +
          '<div class="wb-field">' +
            '<label class="wb-field__label">Exclude Categories</label>' +
            '<div class="wb-autocomplete wb-autocomplete--sm wb-cond-exclude-cats-search">' +
              '<input type="text" class="wb-input wb-cond-exclude-cats__input" placeholder="Search categories\u2026" autocomplete="off">' +
              '<input type="hidden" name="' + prefix + '[exclude_categories]" class="wb-cond-exclude-cats__ids" value="">' +
              '<div class="wb-autocomplete__dropdown"></div>' +
              '<div class="wb-cond-exclude-cats-chips wb-chips"></div>' +
            '</div>' +
          '</div>' +
          '<div class="wb-field">' +
            '<label class="wb-field__label">Exclude Products</label>' +
            '<div class="wb-autocomplete wb-autocomplete--sm wb-cond-exclude-prods-search">' +
              '<input type="text" class="wb-input wb-cond-exclude-prods__input" placeholder="Search products\u2026" autocomplete="off">' +
              '<input type="hidden" name="' + prefix + '[exclude_products]" class="wb-cond-exclude-prods__ids" value="">' +
              '<div class="wb-autocomplete__dropdown"></div>' +
              '<div class="wb-cond-exclude-prods-chips wb-chips"></div>' +
            '</div>' +
          '</div>' +
          '<div class="wb-field">' +
            '<label class="wb-field__label">Price Range Filter</label>' +
            '<div class="wb-price-range">' +
              '<input type="number" name="' + prefix + '[exclude_price_min]" class="wb-input wb-input--sm wb-input--w90" placeholder="Min $" step="0.01" min="0">' +
              '<span>\u2014</span>' +
              '<input type="number" name="' + prefix + '[exclude_price_max]" class="wb-input wb-input--sm wb-input--w90" placeholder="Max $" step="0.01" min="0">' +
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
      var chipsEl = display.closest('.wb-condition-value-wrap').querySelector('.wb-condition-product-chips');
      var fd = new FormData();
      fd.append('action', 'woobooster_search_products');
      fd.append('nonce', cfg.nonce);
      fd.append('search', search);

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.success) return;
          dropdown.innerHTML = '';
          var currentIds = (hidden.value || '').split(',').filter(Boolean);

          res.data.products.forEach(function (p) {
            if (currentIds.indexOf(String(p.id)) !== -1) return;
            var item = document.createElement('div');
            item.className = 'wb-autocomplete__item';
            item.textContent = p.name + (p.sku ? ' (' + p.sku + ')' : '') + ' #' + p.id;
            item.addEventListener('click', function () {
              productNameCache[String(p.id)] = p.name;
              currentIds.push(String(p.id));
              hidden.value = currentIds.join(',');
              display.value = '';
              dropdown.style.display = 'none';
              renderChips(hidden, chipsEl, 'product');
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
        var hidden = row.querySelector('.wb-condition-value-hidden');
        var chipsEl = row.querySelector('.wb-condition-product-chips');
        if (display) {
          display.placeholder = type === 'specific_product' ? 'Search products\u2026' : 'Value\u2026';
        }
        if (chipsEl) {
          chipsEl.style.display = type === 'specific_product' ? '' : 'none';
          if (type === 'specific_product' && hidden && hidden.value) {
            renderChips(hidden, chipsEl, 'product');
          }
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

  /* ── Form Validation ──────────────────────────────────────────────── */

  function initFormValidation() {
    var form = document.querySelector('.wb-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      var errors = [];
      var noValueSources = ['attribute', 'copurchase', 'trending', 'recently_viewed', 'similar', 'specific_products', 'apply_coupon'];

      // Validate conditions have values.
      form.querySelectorAll('.wb-condition-row').forEach(function (row) {
        var hidden = row.querySelector('.wb-condition-value-hidden');
        if (hidden && !hidden.value) {
          errors.push('A condition is missing a value.');
        }
      });

      // Validate actions.
      form.querySelectorAll('.wb-action-row').forEach(function (row) {
        var source = row.querySelector('.wb-action-source');
        if (!source) return;
        var src = source.value;

        // Value required for taxonomy-based actions.
        if (noValueSources.indexOf(src) === -1) {
          var hidden = row.querySelector('.wb-action-value-hidden');
          if (hidden && !hidden.value) {
            errors.push('An action (' + src + ') is missing a value.');
          }
        }

        // Coupon required for apply_coupon.
        if (src === 'apply_coupon') {
          var panel = row.nextElementSibling;
          while (panel && !panel.classList.contains('wb-action-coupon-panel')) {
            panel = panel.nextElementSibling;
          }
          if (panel) {
            var couponId = panel.querySelector('.wb-coupon-search__id');
            if (couponId && !couponId.value) {
              errors.push('A coupon action has no coupon selected.');
            }
          }
        }

        // Products required for specific_products.
        if (src === 'specific_products') {
          var prodPanel = row.nextElementSibling;
          while (prodPanel && !prodPanel.classList.contains('wb-action-products-panel')) {
            prodPanel = prodPanel.nextElementSibling;
          }
          if (prodPanel) {
            var prodIds = prodPanel.querySelector('.wb-action-products');
            if (prodIds && !prodIds.value) {
              errors.push('A "Specific Products" action has no products selected.');
            }
          }
        }
      });

      if (errors.length) {
        e.preventDefault();
        // Deduplicate.
        var unique = [];
        errors.forEach(function (msg) { if (unique.indexOf(msg) === -1) unique.push(msg); });
        alert('Please fix the following:\n\n• ' + unique.join('\n• '));
      }
    });
  }
})();
