/* main.js — shell behaviour: theme, user menu, liquid tab-bar indicator. */
(function () {
    'use strict';

    var root = document.documentElement;
    root.classList.add('js');

    /* ------------------------------------------------------------- theme -- */
    var themeToggle = document.querySelector('[data-theme-toggle]');
    var themeSwitch = document.querySelector('[data-theme-switch]'); // optional <input type=checkbox> on Profile

    function applyTheme(theme) {
        root.dataset.theme = theme;
        try { localStorage.setItem('theme', theme); } catch (e) { /* private mode */ }
        if (themeToggle) { themeToggle.setAttribute('aria-pressed', String(theme === 'dark')); }
        if (themeSwitch) { themeSwitch.checked = theme === 'dark'; }
    }
    applyTheme(root.dataset.theme || 'light');

    /**
     * Switch the theme with a circular reveal that grows out of the control
     * that triggered it; the way back retracts along the same circle. Consumes
     * a click/change point (px, viewport coords), falls back to its centre.
     */
    function switchTheme(next, clientX, clientY, el) {
        var rect = el ? el.getBoundingClientRect() : null;
        var fromX = typeof clientX === 'number' && (clientX || clientY) ? clientX : (rect ? rect.left + rect.width / 2 : window.innerWidth / 2);
        var fromY = typeof clientY === 'number' && (clientX || clientY) ? clientY : (rect ? rect.top + rect.height / 2 : window.innerHeight / 2);

        function commit() {
            applyTheme(next);
            if (navigator.vibrate) { navigator.vibrate(4); }
            // dashboard.js listens to this to recolour the chart
            document.dispatchEvent(new CustomEvent('themechange'));
        }

        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!document.startViewTransition || reduce) { commit(); return; }

        root.style.setProperty('--fx-x', fromX + 'px');
        root.style.setProperty('--fx-y', fromY + 'px');
        // dark grows a light circle over the page, light retracts the dark one
        root.dataset.themeFx = next === 'dark' ? 'expand' : 'collapse';
        var vt = document.startViewTransition(commit);
        vt.finished.then(function () { delete root.dataset.themeFx; });
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function (e) {
            switchTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', e.clientX, e.clientY, themeToggle);
        });
    }
    if (themeSwitch) {
        themeSwitch.addEventListener('change', function (e) {
            switchTheme(themeSwitch.checked ? 'dark' : 'light', e.clientX, e.clientY, themeSwitch);
        });
    }

    /* -------------------------------------------------------- user menu --- */
    var userMenu = document.querySelector('[data-user-menu]');
    function setMenu(open) {
        if (!userMenu) { return; }
        userMenu.querySelector('.user-dropdown').hidden = !open;
        userMenu.querySelector('[data-user-menu-toggle]').setAttribute('aria-expanded', String(open));
    }
    if (userMenu) {
        userMenu.querySelector('[data-user-menu-toggle]').addEventListener('click', function (e) {
            e.stopPropagation();
            setMenu(userMenu.querySelector('.user-dropdown').hidden);
        });
        document.addEventListener('click', function (e) { if (!userMenu.contains(e.target)) { setMenu(false); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setMenu(false); } });
    }

    /* --------------------------------------------- liquid tab-bar pill ---- */
    // One shared indicator slides between tabs. Pages are separate documents,
    /* --------------------------------------- segmented control thumb ----- */
    /* Each .seg gets a sliding pill that glides to the active button instead
       of the active style blinking on. Works for the page Links on admin and
       the live range buttons on the dashboard chart. */
    Array.prototype.forEach.call(document.querySelectorAll('.seg'), function (seg) {
        var activeSeg = seg.querySelector('.seg__btn.is-active');
        if (!activeSeg) { return; }
        var thumb = document.createElement('span');
        thumb.className = 'seg__thumb';
        thumb.setAttribute('aria-hidden', 'true');
        seg.appendChild(thumb);

        function place() {
            thumb.style.transform = 'translateX(' + activeSeg.offsetLeft + 'px)';
            thumb.style.width = activeSeg.offsetWidth + 'px';
        }
        place();
        window.addEventListener('resize', place);
        seg.addEventListener('click', function (e) {
            var btn = e.target.closest('.seg__btn');
            if (btn && !btn.classList.contains('is-active')) {
                activeSeg.classList.remove('is-active');
                btn.classList.add('is-active');
                activeSeg = btn;
                place();
            }
        });
        // font swap / late layout: settle once more after load
        window.addEventListener('load', place);
    });

    // so we remember the previous tab and animate FROM it to the current one.
    var bar = document.querySelector('.tabbar');
    if (!bar) { return; }

    // Tiny haptic tick on the press of a tab (Android; harmless elsewhere).
    Array.prototype.forEach.call(bar.querySelectorAll('.tabbar__item'), function (item) {
        item.addEventListener('pointerdown', function () {
            if (navigator.vibrate) { navigator.vibrate(6); }
        });
    });

    var pill = bar.querySelector('.tabbar__pill');
    var items = Array.prototype.slice.call(bar.querySelectorAll('.tabbar__item'));
    var active = bar.querySelector('.tabbar__item[aria-current="page"]');
    if (!pill || !active) { return; }

    function place(el) {
        bar.style.setProperty('--pill-x', el.offsetLeft + 'px');
        bar.style.setProperty('--pill-w', el.offsetWidth + 'px');
    }
    function snap(el) {
        pill.classList.add('is-snap');
        place(el);
        void pill.offsetWidth; // flush so the next change animates
        pill.classList.remove('is-snap');
    }

    var index = items.indexOf(active);
    var prev = null;
    try { prev = parseInt(sessionStorage.getItem('tabbar-prev'), 10); } catch (e) { /* ignore */ }
    var from = items[prev];

    if (from && from !== active) {
        root.style.setProperty('--push-dir', index > prev ? 1 : -1);
        snap(from);
        requestAnimationFrame(function () {
            place(active);
            pill.classList.add('is-settling');
            active.classList.add('is-pop');
            setTimeout(function () { pill.classList.remove('is-settling'); active.classList.remove('is-pop'); }, 460);
        });
    } else {
        snap(active);
    }
    try { sessionStorage.setItem('tabbar-prev', String(index)); } catch (e) { /* ignore */ }

    window.addEventListener('resize', function () { snap(active); });
    window.addEventListener('load', function () { snap(active); }); // after web fonts settle

    /* ------------------------------------------- pull-to-refresh --------- */
    // Signature mini-program gesture. Content pages are whole documents, so a
    // refresh is a plain reload. Own ring + rubber-band threshold; disabled on
    // iOS (which rubber-bands natively, so the two would fight) and under
    // prefers-reduced-motion. overscroll-behavior-y: contain in CSS stops the
    // browser's own site-refresh gesture from firing at the same time.
    var coarse = window.matchMedia('(pointer: coarse)').matches;
    var isIOS = /iP(hone|ad|od)/.test(navigator.userAgent);
    var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (coarse && !isIOS && !still) {
        var THRESHOLD = 64;
        var ptr = document.createElement('div');
        ptr.className = 'ptr';
        ptr.appendChild(document.createElement('i'));
        document.body.appendChild(ptr);
        var ring = ptr.firstElementChild;
        var startY = 0, pull = 0, armed = false;

        ptr.reset = function () {
            armed = false; pull = 0;
            ptr.classList.remove('is-on');
            ptr.style.opacity = '';
            ring.style.transform = '';
        };
        ptr.reset();

        window.addEventListener('touchstart', function (e) {
            if (window.scrollY !== 0) { return; }
            startY = e.touches[0].clientY;
            armed = true; pull = 0;
        }, { passive: true });

        window.addEventListener('touchmove', function (e) {
            if (!armed) { return; }
            pull = e.touches[0].clientY - startY;
            if (pull <= 0) {
                if (pull < -20) { ptr.reset(); } // scrolled back up, not a pull
                return;
            }
            var progress = Math.min(pull / THRESHOLD, 1);
            ptr.classList.add('is-on');
            ptr.style.opacity = String(progress);
            ring.style.transform = 'rotate(' + (progress * 220) + 'deg)';
        }, { passive: true });

        window.addEventListener('touchcancel', function () { ptr.reset(); }, { passive: true });
        window.addEventListener('touchend', function () {
            if (!armed) { return; }
            if (pull > THRESHOLD) { location.reload(); } else { ptr.reset(); }
        }, { passive: true });
    }
})();
