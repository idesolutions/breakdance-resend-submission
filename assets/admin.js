(function () {
    'use strict';

    var cfg = window.bdrsResend;
    var box = document.querySelector('.bdrs');
    if (!cfg || !box) {
        return;
    }

    var button = box.querySelector('.bdrs-send');
    var result = box.querySelector('.bdrs-result');
    var override = box.querySelector('#bdrs-override');

    function currentSource() {
        var checked = box.querySelector('input[name="bdrs_source"]:checked');
        return checked ? checked.value : '';
    }

    function showList() {
        var source = currentSource();
        box.querySelectorAll('.bdrs-emails').forEach(function (list) {
            list.hidden = list.getAttribute('data-source') !== source;
        });
        button.disabled = !source;
    }

    function setResult(type, html) {
        result.className = 'bdrs-result notice inline notice-' + type;
        result.innerHTML = html;
    }

    function esc(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    box.querySelectorAll('input[name="bdrs_source"]').forEach(function (radio) {
        radio.addEventListener('change', showList);
    });
    showList();

    // The meta box lives inside the post form: keep Enter in the override field from submitting it.
    override.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            button.click();
        }
    });

    button.addEventListener('click', function () {
        var source = currentSource();
        var list = box.querySelector('.bdrs-emails[data-source="' + source + '"]');
        var indexes = list
            ? Array.prototype.map.call(list.querySelectorAll('input[type="checkbox"]:checked'), function (c) { return c.value; })
            : [];

        if (!indexes.length) {
            setResult('warning', '<p>' + esc(cfg.i18n.noneChecked) + '</p>');
            return;
        }
        if (!window.confirm(cfg.i18n.confirm)) {
            return;
        }

        var body = new FormData();
        body.append('action', cfg.action);
        body.append('nonce', box.getAttribute('data-nonce'));
        body.append('submission', box.getAttribute('data-submission'));
        body.append('source', source);
        body.append('override', override.value);
        indexes.forEach(function (i) { body.append('emails[]', i); });

        button.disabled = true;
        setResult('info', '<p>' + esc(cfg.i18n.sending) + '</p>');

        fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    var msg = json && json.data && json.data.message ? json.data.message : cfg.i18n.failed;
                    setResult('error', '<p>' + esc(msg) + '</p>');
                    return;
                }
                var items = json.data.results.map(function (r) {
                    return '<li class="' + (r.ok ? 'bdrs-ok' : 'bdrs-fail') + '">' + (r.ok ? '✓ ' : '✗ ') +
                        esc(r.subject) + ' → ' + esc(r.to) + (r.ok ? '' : ': ' + esc(r.error)) + '</li>';
                }).join('');
                setResult(json.data.failed ? 'warning' : 'success', '<ul>' + items + '</ul>');
            })
            .catch(function () {
                setResult('error', '<p>' + esc(cfg.i18n.failed) + '</p>');
            })
            .then(function () {
                button.disabled = false;
            });
    });
})();
