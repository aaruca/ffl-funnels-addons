/* Tax Rates Admin JS */
(function ($) {
    'use strict';

    $(function () {
        var importing = false;

        // ── Select All / Deselect All ──────────────────────────────────
        $('#ffla-tax-select-all').on('click', function () {
            $('.ffla-tax-state-checkbox').prop('checked', true);
        });

        $('#ffla-tax-deselect-all').on('click', function () {
            $('.ffla-tax-state-checkbox').prop('checked', false);
        });

        // ── Import Button ──────────────────────────────────────────────
        $('#ffla-tax-import-btn').on('click', function () {
            if (importing) return;

            var states = [];
            $('.ffla-tax-state-checkbox:checked').each(function () {
                states.push({ code: $(this).val(), name: $(this).data('name') });
            });

            if (states.length === 0) {
                alert(FflataxRates.i18n.noStates);
                return;
            }

            startImport(states);
        });

        // ── Core Import Loop ───────────────────────────────────────────
        function startImport(states) {
            importing = true;

            var $btn      = $('#ffla-tax-import-btn');
            var $progress = $('#ffla-tax-progress');
            var $bar      = $('#ffla-tax-bar');
            var $label    = $('#ffla-tax-current-label');
            var $log      = $('#ffla-tax-log');
            var $count    = $('#ffla-tax-count');
            var $title    = $('#ffla-tax-progress-title');
            var depth     = $btn.data('depth');
            var total     = states.length;
            var done      = 0;

            $btn.prop('disabled', true).text(FflataxRates.i18n.researching + '...');
            $progress.show();
            $log.empty();
            $bar.css('width', '0%');
            $title.text('Importing Tax Rates');
            $count.text('0 ' + FflataxRates.i18n.of + ' ' + total + ' ' + FflataxRates.i18n.states);

            function importNext() {
                if (done >= total) {
                    finishImport();
                    return;
                }

                var state = states[done];
                $label.html(FflataxRates.i18n.researching + ' <strong>' + state.name + '</strong>...');

                $.post(FflataxRates.ajaxUrl, {
                    action:   'ffla_import_tax_state',
                    security: FflataxRates.nonce,
                    state:    state.code,
                    depth:    depth,
                })
                .done(function (res) {
                    done++;
                    var pct = Math.round((done / total) * 100);
                    $bar.css('width', pct + '%');
                    $count.text(done + ' ' + FflataxRates.i18n.of + ' ' + total + ' ' + FflataxRates.i18n.states);

                    if (res.success) {
                        appendLog(state.code, state.name, 'ok', res.data.count + ' ' + FflataxRates.i18n.imported);
                    } else {
                        appendLog(state.code, state.name, 'error', res.data || FflataxRates.i18n.failed);
                    }
                })
                .fail(function () {
                    done++;
                    appendLog(state.code, state.name, 'error', FflataxRates.i18n.failed);
                })
                .always(function () {
                    importNext();
                });
            }

            importNext();
        }

        function finishImport() {
            importing = false;
            var $btn   = $('#ffla-tax-import-btn');
            var $label = $('#ffla-tax-current-label');
            var $title = $('#ffla-tax-progress-title');
            var $dots  = $('#ffla-tax-progress .wb-ai-dots');

            $title.text('\u2713 ' + FflataxRates.i18n.done);
            $label.text('\u2713 ' + FflataxRates.i18n.done);
            $dots.hide();
            $('#ffla-tax-bar').css('width', '100%');

            $btn.prop('disabled', false).text('Research & Import Selected States');

            // Reload after 2 s to refresh the state grid & log table.
            setTimeout(function () {
                window.location.reload();
            }, 2000);
        }

        function appendLog(code, name, status, message) {
            var $log  = $('#ffla-tax-log');
            var color = status === 'ok'
                ? 'var(--wb-color-neutral-foreground-2)'
                : 'var(--wb-color-danger-foreground, #c53030)';
            var icon  = status === 'ok' ? '\u2713' : '\u2715';
            var line  = $('<div style="padding: 2px 0; color:' + color + ';">')
                .text(icon + ' ' + code + ' \u2014 ' + name + ': ' + message);
            $log.append(line);
            $log.scrollTop($log[0].scrollHeight);
        }
    }); // end $(document).ready

}(jQuery));
