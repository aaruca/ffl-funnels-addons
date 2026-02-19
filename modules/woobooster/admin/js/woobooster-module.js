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
        var row = createActionRow(newIdx);
        container.appendChild(row);
        bindActionRow(row);
        renumberActionFields();
      });
    }

    // Remove Action.
    container.addEventListener('click', function (e) {
      if (e.target.classList.contains('wb-remove-action')) {
        var row = e.target.closest('.wb-action-row');
        if (container.querySelectorAll('.wb-action-row').length > 1) {
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

      return row;
    }

    function renumberActionFields() {
      container.querySelectorAll('.wb-action-row').forEach(function (row, i) {
        row.dataset.index = i;
        var num = row.querySelector('.wb-action-number');
        if (num) num.textContent = i + 1;

        var prefix = 'actions[' + i + ']';
        row.querySelectorAll('[name]').forEach(function (el) {
          var name = el.getAttribute('name');
          if (name) {
            el.setAttribute('name', name.replace(/actions\[\d+\]/, prefix));
          }
        });
      });
    }

    function initActionRowToggle(row) {
      var source = row.querySelector('.wb-action-source');
      var valWrap = row.querySelector('.wb-action-value-wrap');
      var childLabel = row.querySelector('.wb-action-children-label');
      var attrTaxSelect = row.querySelector('.wb-action-attr-taxonomy');
      var noValueSources = ['attribute', 'copurchase', 'trending', 'recently_viewed', 'similar'];

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
    });

    // Wire up existing remove buttons.
    container.addEventListener('click', function (e) {
      if (e.target.classList.contains('wb-remove-condition')) {
        e.target.closest('.wb-condition-row').remove();
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
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-condition">&times;</button>';

      initConditionTypeToggle(row);
      initRowAutocomplete(row);
      return row;
    }

    function initRowAutocomplete(row) {
      var display = row.querySelector('.wb-condition-value-display');
      var hidden = row.querySelector('.wb-condition-value-hidden');
      var dropdown = row.querySelector('.wb-autocomplete__dropdown');
      var attrSelect = row.querySelector('.wb-condition-attr');
      if (!display || !hidden || !dropdown || !attrSelect) return;

      var debounce = null;

      display.addEventListener('input', function () {
        clearTimeout(debounce);
        hidden.value = '';
        debounce = setTimeout(function () { searchRowTerms(display, hidden, dropdown, attrSelect, display.value); }, 300);
      });

      display.addEventListener('focus', function () {
        if (!dropdown.children.length) {
          searchRowTerms(display, hidden, dropdown, attrSelect, '');
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
      }

      typeSelect.addEventListener('change', function () {
        var type = typeSelect.value;
        if (type === 'category') {
          hiddenAttr.value = 'product_cat';
        } else if (type === 'tag') {
          hiddenAttr.value = 'product_tag';
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
        if (hiddenAttr.value) {
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
