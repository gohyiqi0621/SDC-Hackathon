/**
 * setup-checklist.js - refresh the Setup checklist without reloading the page.
 *
 * After a save on a Setup page (Payment Method, AI Assistant Settings), call
 * window.refreshSetupChecklist(). It fetches this page again, swaps in the new
 * checklist (partials/setup_checklist.php) and updates the orange "setup not
 * finished" dots in the sidebar, so ticks appear right away.
 */
(function () {
    'use strict';

    var pending = null;

    function syncDots(fresh) {
        var now = document.querySelectorAll('.sidebar-nav .nav-link');
        var next = fresh.querySelectorAll('.sidebar-nav .nav-link');
        if (now.length !== next.length) { return; }
        for (var i = 0; i < now.length; i++) {
            var had = now[i].querySelector('.nav-dot');
            var want = next[i].querySelector('.nav-dot');
            if (had && !want) { had.parentNode.removeChild(had); }
            if (!had && want) {
                var dot = want.cloneNode(true);
                var caret = now[i].querySelector('.nav-caret');
                if (caret) { now[i].insertBefore(dot, caret); } else { now[i].appendChild(dot); }
            }
        }
    }

    window.refreshSetupChecklist = function () {
        var current = document.querySelector('.setup-check');
        if (!current || pending) { return pending; }
        pending = fetch(location.pathname + location.search, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');
                var box = fresh.querySelector('.setup-check');
                var live = document.querySelector('.setup-check');
                if (box && live) { live.replaceWith(document.importNode(box, true)); }
                syncDots(fresh);
            })
            .catch(function () { /* keep the old checklist; a reload shows the latest */ })
            .then(function () { pending = null; });
        return pending;
    };
})();
