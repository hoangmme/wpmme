/* MMe Core – Cache Warmer page */
jQuery(function ($) {
    var $form = $('#wpmme-cache-form');
    if (!$form.length) {
        return;
    }

    var $urls = $('#wpmme-cache-urls');
    var stopped = false;
    var running = false;

    function lines() {
        return $.map($urls.val().split(/\r?\n/), function (line) {
            line = $.trim(line);
            return line && line.charAt(0) !== '#' ? line : null;
        });
    }

    function updateCount() {
        var n = lines().length;
        $('#wpmme-cache-count').text(n ? '(' + n + ')' : '');
    }

    function message(text, type) {
        $('#wpmme-cache-msg').attr('class', 'wpmme-footer__msg' + (type ? ' is-' + type : '')).text(text);
    }

    function post(action, data) {
        return $.post(wpmme_ajax.url, $.extend({ action: action, nonce: wpmme_ajax.nonce }, data));
    }

    function esc(text) {
        return $('<div>').text(text == null ? '' : String(text)).html();
    }

    function badge(status) {
        var cls = 'neutral';
        if (status === 'HIT') cls = 'success';
        else if (/^(MISS|EXPIRED|STALE|UPDATING|DYNAMIC)$/.test(status)) cls = 'info';
        else if (status === 'ERROR') cls = 'error';
        else if (status === 'BYPASS') cls = 'warning';
        return '<span class="wpmme-badge wpmme-badge--' + cls + '">' + esc(status) + '</span>';
    }

    $urls.on('input', updateCount);
    updateCount();

    // Save settings
    $('#wpmme-cache-save').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        message('Saving…');
        post('wpmme_cache_save', {
            enabled: $form.find('[name=enabled]').is(':checked') ? 1 : '',
            localhost: $form.find('[name=localhost]').is(':checked') ? 1 : '',
            time: $form.find('[name=time]').val(),
            urls: $urls.val()
        }).done(function (res) {
            if (!res.success) {
                message(res.data || 'Could not save.', 'error');
                return;
            }
            $urls.val(res.data.urls.join('\n'));
            updateCount();
            var text = 'Saved ' + res.data.urls.length + ' URLs.';
            if (res.data.rejected.length) {
                text += ' Skipped ' + res.data.rejected.length + ' line(s) not on this site.';
            }
            message(text, res.data.rejected.length ? 'warning' : 'success');
            $('#wpmme-cache-next').text(res.data.next ? 'Next run: ' + res.data.next : 'No run scheduled');
        }).fail(function () {
            message('Server error while saving.', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Add URLs from sitemap (merged with the current list)
    $('#wpmme-cache-sitemap').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        message('Reading sitemap…');
        post('wpmme_cache_sitemap', {}).done(function (res) {
            if (!res.success) {
                message(res.data || 'No sitemap found.', 'error');
                return;
            }
            var current = lines();
            var added = 0;
            $.each(res.data.urls, function (i, url) {
                if ($.inArray(url, current) === -1) {
                    current.push(url);
                    added++;
                }
            });
            $urls.val(current.join('\n'));
            updateCount();
            message('Added ' + added + ' URL(s) from the sitemap. Remove the ones you don\'t need, then Save.', 'success');
        }).fail(function () {
            message('Server error while reading the sitemap.', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Run now: one AJAX request per URL, so long lists never time out
    $('#wpmme-cache-run').on('click', function () {
        if (running) {
            return;
        }
        var list = lines();
        if (!list.length) {
            message('Add at least one URL first.', 'error');
            return;
        }
        running = true;
        stopped = false;
        var $btn = $(this).prop('disabled', true);
        var $tbody = $('#wpmme-cache-results').empty();
        var localhost = $form.find('[name=localhost]').is(':checked') ? 1 : '';
        var results = [];
        var started = Date.now();
        $('#wpmme-cache-progress-card').show();
        $('#wpmme-cache-stop').prop('disabled', false);

        function progress(done) {
            var pct = Math.round(done / list.length * 100);
            $('#wpmme-cache-progress').css('width', pct + '%');
            $('#wpmme-cache-progress-text').text((stopped ? 'Stopped at ' : 'Warming ') + done + ' / ' + list.length + '…');
        }

        function finish() {
            var seconds = (Date.now() - started) / 1000;
            post('wpmme_cache_finish', { results: JSON.stringify(results), duration: seconds.toFixed(1) }).done(function (res) {
                if (res.success) {
                    var s = res.data;
                    $('#wpmme-cache-progress-text').text((stopped ? 'Stopped. ' : 'Done. ') + s.total + ' URLs: ' + s.hit + ' already cached, ' + s.warmed + ' warmed, ' + s.bypass + ' not cacheable, ' + s.failed + ' failed (' + s.duration + 's).');
                }
            }).always(function () {
                running = false;
                $btn.prop('disabled', false);
                $('#wpmme-cache-stop').prop('disabled', true);
            });
        }

        function next(i) {
            progress(i);
            if (stopped || i >= list.length) {
                finish();
                return;
            }
            post('wpmme_cache_warm_url', { url: list[i], localhost: localhost }).done(function (res) {
                var r = res.success ? res.data : { url: list[i], status: 'ERROR', code: 0, time: '-', error: res.data };
                results.push(r);
                $tbody.append('<tr><td><a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.url) + '</a>' + (r.error ? '<br><small class="wpmme-text-error">' + esc(r.error) + '</small>' : '') + '</td><td>' + badge(r.status) + '</td><td>' + esc(r.code || '-') + '</td><td>' + esc(r.time) + 's</td></tr>');
            }).fail(function () {
                results.push({ url: list[i], status: 'ERROR', code: 0, error: 'AJAX request failed' });
                $tbody.append('<tr><td>' + esc(list[i]) + '</td><td>' + badge('ERROR') + '</td><td>-</td><td>-</td></tr>');
            }).always(function () {
                next(i + 1);
            });
        }

        next(0);
    });

    $('#wpmme-cache-stop').on('click', function () {
        stopped = true;
        $(this).prop('disabled', true);
    });

    // Show which page cache answers for the home page
    post('wpmme_cache_warm_url', { url: wpmme_ajax.home, localhost: $form.find('[name=localhost]').is(':checked') ? 1 : '' }).done(function (res) {
        var text;
        if (!res.success) {
            text = 'Could not reach the home page.';
        } else if (res.data.status === '-') {
            text = 'No page cache header found on the home page.';
        } else {
            text = 'Page cache answers on the home page: ' + res.data.status + ' (' + res.data.time + 's)';
        }
        $('#wpmme-cache-detect').text(text);
    });
});
