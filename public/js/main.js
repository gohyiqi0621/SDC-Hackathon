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

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');
        });
    }
    if (themeSwitch) {
        themeSwitch.addEventListener('change', function () {
            applyTheme(themeSwitch.checked ? 'dark' : 'light');
            // dashboard.js listens to this to recolour the chart
            document.dispatchEvent(new CustomEvent('themechange'));
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
    // so we remember the previous tab and animate FROM it to the current one.
    var bar = document.querySelector('.tabbar');
    if (!bar) { return; }

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
})();
