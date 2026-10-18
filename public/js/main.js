/* main.js — shell behaviour: theme (circular reveal), user menu, large-title collapse,
   keyboard-aware tab bar, page direction, sliding segmented controls, pull-to-refresh,
   liquid tab-bar indicator. */
(function () {
    'use strict';

    var root = document.documentElement;
    root.classList.add('js');

    // The AI Chat screen owns the viewport height. Marking it lets CSS lock the document
    // while the keyboard is open without affecting pages that are meant to scroll.
    if (document.querySelector('.content--chat')) {
        root.classList.add('is-chat');
    }

    // Installed to the home screen? (iOS: navigator.standalone, others: display-mode)
    if (window.navigator.standalone || (window.matchMedia && matchMedia('(display-mode: standalone)').matches)) {
        root.classList.add('is-standalone');
    }

    /* ------------------------------------------------------------- theme -- */
    var themeToggle = document.querySelector('[data-theme-toggle]');
    var themeSwitch = document.querySelector('[data-theme-switch]'); // optional <input type=checkbox> on Profile
    var themeMetas = document.querySelectorAll('meta[name="theme-color"]');

    // Browser/status-bar tint follows the active theme, read from the --bg token (no hex here).
    function syncThemeColor() {
        var bg = getComputedStyle(root).getPropertyValue('--bg').trim();
        if (!bg) { return; }
        Array.prototype.forEach.call(themeMetas, function (m) { m.setAttribute('content', bg); });
    }

    function applyTheme(theme) {
        root.dataset.theme = theme;
        try { localStorage.setItem('theme', theme); } catch (e) { /* private mode */ }
        if (themeToggle) { themeToggle.setAttribute('aria-pressed', String(theme === 'dark')); }
        if (themeSwitch) { themeSwitch.checked = theme === 'dark'; }
        syncThemeColor();
    }
    applyTheme(root.dataset.theme || 'dark');

    var reduceMotion = window.matchMedia ? matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };

    // Switch theme with a circular reveal growing from the control that was used; switching
    // back retracts the old theme into it. Falls back to an instant swap.
    function switchTheme(next, x, y, el) {
        var r = el ? el.getBoundingClientRect() : null;
        var fromX = (x || y) ? x : (r ? r.left + r.width / 2 : window.innerWidth / 2);
        var fromY = (x || y) ? y : (r ? r.top + r.height / 2 : window.innerHeight / 2);

        function commit() {
            applyTheme(next);
            // dashboard.js listens to this to recolour the chart
            document.dispatchEvent(new CustomEvent('themechange'));
        }
        if (!document.startViewTransition || reduceMotion.matches) { commit(); return; }

        root.style.setProperty('--fx-x', fromX + 'px');
        root.style.setProperty('--fx-y', fromY + 'px');
        root.dataset.themeFx = next === 'dark' ? 'expand' : 'collapse';
        var vt = document.startViewTransition(commit);
        vt.finished.then(function () { delete root.dataset.themeFx; }, function () { delete root.dataset.themeFx; });
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function (e) {
            switchTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', e.clientX, e.clientY, themeToggle);
        });
    }
    if (themeSwitch) {
        themeSwitch.addEventListener('change', function (e) {
            switchTheme(themeSwitch.checked ? 'dark' : 'light', e.clientX || 0, e.clientY || 0, themeSwitch);
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

    /* ------------------------------------------- large-title collapse ---- */
    // iOS pattern: the page heading is big under a clear bar; once it scrolls away the bar
    // turns to glass and shows its own title. CSS does the visuals (.has-large-title,
    // .is-title-collapsed); this only reports where the heading is.
    var heading = document.querySelector('.page-head__title');
    var topbar = document.querySelector('.topbar');
    if (heading && topbar && 'IntersectionObserver' in window) {
        root.classList.add('has-large-title');
        var titleObserver = null;
        var observeTitle = function () {
            if (titleObserver) { titleObserver.disconnect(); }
            var offset = topbar.offsetHeight;   // includes the status-bar safe area
            titleObserver = new IntersectionObserver(function (entries) {
                var entry = entries[entries.length - 1];
                root.classList.toggle('is-title-collapsed', !entry.isIntersecting && entry.boundingClientRect.top < offset);
            }, { rootMargin: '-' + offset + 'px 0px 0px 0px', threshold: 0 });
            titleObserver.observe(heading);
        };
        observeTitle();
        var resizeFrame = 0;
        window.addEventListener('resize', function () {
            cancelAnimationFrame(resizeFrame);
            resizeFrame = requestAnimationFrame(observeTitle);
        });
    }

    /* --------------------------------- keyboard-aware tab bar ------------ */
    // On touch devices the tab bar slides away while a text field is focused so it never
    // floats above the on-screen keyboard.
    var touchNarrow = window.matchMedia ? matchMedia('(pointer: coarse) and (max-width: 1023px)') : null;
    function isTextField(el) {
        if (!el || !el.tagName) { return false; }
        if (el.isContentEditable || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') { return true; }
        return el.tagName === 'INPUT' && !/^(checkbox|radio|button|submit|reset|range|file|color|image)$/i.test(el.type);
    }
    document.addEventListener('focusin', function (e) {
        if (touchNarrow && touchNarrow.matches && isTextField(e.target)) { root.classList.add('kb-open'); }
    });
    document.addEventListener('focusout', function () {
        setTimeout(function () { if (!isTextField(document.activeElement)) { root.classList.remove('kb-open'); } }, 60);
    });

    /* ------------------------------------------------- page direction ---- */
    // --push-dir (1 forward, -1 back) steers the content entrance (see layout.css). Pages are
    // separate documents, so it is derived on arrival: browser back/forward = -1; between tabs
    // by tab order; otherwise "deeper" (e.g. Items -> New item) = 1 and "up" = -1.
    (function () {
        var dir = 1, nav = null;
        try { nav = performance.getEntriesByType('navigation')[0]; } catch (e) { /* ignore */ }
        var tabs = Array.prototype.slice.call(document.querySelectorAll('.tabbar__item'));
        var cur = tabs.indexOf(document.querySelector('.tabbar__item[aria-current="page"]'));
        var prevIdx = NaN, prevDepth = NaN;
        try {
            prevIdx = parseInt(sessionStorage.getItem('tabbar-prev'), 10);
            prevDepth = parseInt(sessionStorage.getItem('page-depth'), 10);
        } catch (e) { /* ignore */ }
        var depth = document.querySelector('.crumbs') ? 1 : 0;   // detail pages carry breadcrumbs

        if (nav && nav.type === 'back_forward') { dir = -1; }
        else if (depth !== prevDepth && !isNaN(prevDepth)) { dir = depth > prevDepth ? 1 : -1; }
        else if (cur > -1 && !isNaN(prevIdx) && prevIdx !== cur) { dir = cur > prevIdx ? 1 : -1; }
        root.style.setProperty('--push-dir', dir);
        try { sessionStorage.setItem('page-depth', String(depth)); } catch (e) { /* ignore */ }
    })();

    /* ------------------------------------- sliding segmented control ---- */
    // One thumb glides between options. Buttons that toggle .is-active in JS (chart range)
    // are followed with a MutationObserver; link segments (filters) just settle in place.
    Array.prototype.forEach.call(document.querySelectorAll('.seg'), function (seg) {
        var thumb = document.createElement('span');
        thumb.className = 'seg__thumb is-snap';
        thumb.setAttribute('aria-hidden', 'true');
        seg.insertBefore(thumb, seg.firstChild);

        function active() { return seg.querySelector('.seg__btn.is-active'); }
        function place(snap) {
            var el = active();
            if (!el) { thumb.style.opacity = '0'; return; }
            thumb.style.opacity = '';
            if (snap) { thumb.classList.add('is-snap'); }
            seg.style.setProperty('--thumb-x', el.offsetLeft + 'px');
            seg.style.setProperty('--thumb-w', el.offsetWidth + 'px');
            if (snap) { void thumb.offsetWidth; thumb.classList.remove('is-snap'); }
        }
        place(true);
        seg.classList.add('has-thumb');
        window.addEventListener('load', function () { place(true); });
        window.addEventListener('resize', function () { place(true); });
        if ('MutationObserver' in window) {
            new MutationObserver(function () { place(false); })
                .observe(seg, { attributes: true, attributeFilter: ['class'], subtree: true });
        }
    });

    /* ------------------------------------------------ pull-to-refresh ---- */
    // Pages are whole documents, so refresh = reload. Touch devices only; iOS Safari
    // rubber-bands natively, so there it runs only when installed (standalone has no native one).
    (function () {
        var coarse = window.matchMedia && matchMedia('(pointer: coarse)').matches;
        var ios = /iP(hone|ad|od)/.test(navigator.userAgent);
        if (!coarse || reduceMotion.matches || (ios && !root.classList.contains('is-standalone'))) { return; }

        var THRESHOLD = 120, MAX = 80;   // finger travel to trigger; ring travel cap
        var ring = document.createElement('div');
        ring.className = 'ptr liquid-glass-thin';
        ring.setAttribute('aria-hidden', 'true');
        var spinner = document.createElement('i');
        ring.appendChild(spinner);
        document.body.appendChild(ring);

        var startY = 0, pull = 0, armed = false, busy = false;
        function show(y, progress) {
            ring.classList.add('is-on');
            ring.style.setProperty('--ptr-y', y + 'px');
            ring.style.opacity = String(progress);
            ring.style.transform = 'translateY(' + (y - 12) + 'px) scale(' + (0.8 + 0.2 * progress) + ')';
            spinner.style.transform = 'rotate(' + (progress * 260) + 'deg)';
        }
        function reset() {
            armed = false; pull = 0;
            ring.classList.add('is-settling');
            ring.style.opacity = '0';
            ring.style.transform = '';
            setTimeout(function () { ring.classList.remove('is-on', 'is-settling'); }, 260);
        }
        function blocked(t) {
            return !!(t && t.closest && t.closest('.chat-body, dialog[open], .modal, input, textarea, select, .seg, .table-wrap, .int-panel'));
        }

        window.addEventListener('touchstart', function (e) {
            if (busy || window.scrollY > 0 || blocked(e.target)) { return; }
            startY = e.touches[0].clientY; armed = true; pull = 0;
            ring.classList.remove('is-settling');
            root.style.overscrollBehaviorY = 'contain';   // stop the browser's own refresh competing
        }, { passive: true });
        window.addEventListener('touchmove', function (e) {
            if (!armed) { return; }
            pull = e.touches[0].clientY - startY;
            if (pull <= 0) { if (pull < -16) { reset(); } return; }
            var eased = Math.min(MAX, pull * 0.55);        // rubber-band
            show(eased, Math.min(pull / THRESHOLD, 1));
        }, { passive: true });
        window.addEventListener('touchcancel', function () { if (armed) { reset(); } }, { passive: true });
        window.addEventListener('touchend', function () {
            if (!armed) { return; }
            if (pull >= THRESHOLD) {
                busy = true; armed = false;
                ring.classList.add('is-settling', 'is-loading');
                show(48, 1);
                setTimeout(function () { location.reload(); }, 220);
            } else { reset(); }
        }, { passive: true });
    })();

    /* ------------------------------------- visual viewport (keyboard) ---- */
    // Publishes the visible height as --vvh so the chat fills exactly the space left above
    // the on-screen keyboard, and keeps the newest message in view while it opens.
    //
    // The scroll reset matters as much as the height. iOS Safari does NOT shrink the layout
    // viewport for the keyboard, so the document stays a keyboard-height taller than what
    // you can actually see; Safari scrolls it up to reveal the caret and never scrolls back.
    // That is what pushed the header off-screen and left the composer floating with a gap.
    if (window.visualViewport) {
        var vv = window.visualViewport;
        var isChat = root.classList.contains('is-chat');

        var anchorTop = function () {
            // html has scroll-behavior:smooth, which would animate the correction.
            var prev = root.style.scrollBehavior;
            root.style.scrollBehavior = 'auto';
            window.scrollTo(0, 0);
            root.style.scrollBehavior = prev;
        };

        var syncViewport = function () {
            root.style.setProperty('--vvh', vv.height + 'px');

            var typing = root.classList.contains('kb-open');
            // Never fight a pinch-zoom: only re-anchor at 1x.
            if (isChat && typing && vv.scale === 1 && (window.scrollY > 0 || vv.offsetTop > 0)) {
                anchorTop();
            }

            if (typing) {
                var body = document.getElementById('chat-body');
                if (body) { body.scrollTop = body.scrollHeight; }
            }
        };

        vv.addEventListener('resize', syncViewport);
        vv.addEventListener('scroll', syncViewport);
        syncViewport();
    }

    /* -------------------------------------------------- password reveal -- */
    // <button data-toggle-password="inputId"> flips a field between masked and
    // plain text. Delegated from the document, so it also covers fields inside
    // <dialog> modals and any markup added later without re-binding.
    //
    // Deliberately ABOVE the tab-bar block: that block ends with an early
    // `return` when the page has no .tabbar (auth screens), which would kill
    // everything registered after it.
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-toggle-password]') : null;
        if (!btn) { return; }

        var input = document.getElementById(btn.getAttribute('data-toggle-password'));
        if (!input) { return; }

        e.preventDefault();

        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';

        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', String(reveal));
        btn.classList.toggle('is-revealed', reveal);

        // Swap the sprite reference rather than replacing the <svg>, so the
        // button keeps its event target and focus ring.
        var use = btn.querySelector('use');
        if (use) {
            use.setAttribute('href', reveal ? '#i-eye-off' : '#i-eye');
            use.setAttribute('xlink:href', reveal ? '#i-eye-off' : '#i-eye');
        }

        // Keep the caret in the field the user just acted on.
        input.focus({ preventScroll: true });
    });

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
