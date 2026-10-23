/**
 * staff-connections.js - Staff Assistant > Connections: link / unlink your own
 * Telegram for staff alerts (POST ai_settings/telegram_staff_link | _unlink).
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-staff-connections]');
    if (!root) { return; }
    var msg = root.querySelector('[data-staff-msg]');

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function post(url) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', body: new FormData(),
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() }
        }).then(function (r) { return r.json(); });
    }
    function say(text, ok) {
        if (!msg) { return; }
        msg.textContent = text || '';
        msg.className = 'conn-msg ' + (ok ? 'is-ok' : 'is-bad');
    }

    var link = root.querySelector('[data-staff-link]');
    var check = root.querySelector('[data-staff-check]');
    if (link) {
        link.addEventListener('click', function () {
            link.disabled = true;
            post(root.getAttribute('data-link-url')).then(function (res) {
                link.disabled = false;
                if (!res || !res.success || !res.data || !res.data.link) { say((res && res.message) || 'Could not create the link.', false); return; }
                window.open(res.data.link, '_blank', 'noopener');
                say('Telegram opened. Press Start there, then click "Check again". The link works for 15 minutes.', true);
                if (check) { check.hidden = false; }
            }).catch(function () { link.disabled = false; say('Network error. Please try again.', false); });
        });
    }
    if (check) {
        check.addEventListener('click', function () { window.location.hash = 'alerts'; window.location.reload(); });
    }

    var unlink = root.querySelector('[data-staff-unlink]');
    if (unlink) {
        unlink.addEventListener('click', function () {
            if (!window.confirm('Unlink your Telegram? You will stop getting staff alerts.')) { return; }
            unlink.disabled = true;
            post(root.getAttribute('data-unlink-url')).then(function (res) {
                if (res && res.success) { window.location.reload(); return; }
                unlink.disabled = false;
                say((res && res.message) || 'Could not unlink.', false);
            }).catch(function () { unlink.disabled = false; say('Network error. Please try again.', false); });
        });
    }
})();
