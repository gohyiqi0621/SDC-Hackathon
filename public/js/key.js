/**
 * key.js - integration panel on the admin Key page.
 *
 * Default state: only the circular integration icons are visible. Clicking an
 * icon slides its configuration card down beneath the icons; clicking the same
 * icon again collapses it; clicking another icon switches (one open at a time).
 *
 * Progressive enhancement: the markup ships every panel with the `hidden`
 * attribute, so with JS disabled nothing is open and no content is lost - the
 * forms are still in the DOM and reachable with the keyboard/tab order.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-int-root]') || document;

    var toggles = Array.prototype.slice.call(root.querySelectorAll('[data-int-toggle]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-int-panel]'));
    var closers = Array.prototype.slice.call(root.querySelectorAll('[data-int-close]'));

    if (!toggles.length || !panels.length) {
        return;
    }

    /* Only one panel is open at a time; `null` means everything is closed. */
    var openId = null;
    /* The currently running slide animation, so a fast double-click cannot
       leave two panels visible at once. */
    var animating = null;

    function panelFor(id) {
        for (var i = 0; i < panels.length; i++) {
            if (panels[i].getAttribute('data-int-panel') === id) {
                return panels[i];
            }
        }
        return null;
    }

    function toggleFor(id) {
        for (var i = 0; i < toggles.length; i++) {
            if (toggles[i].getAttribute('data-int-toggle') === id) {
                return toggles[i];
            }
        }
        return null;
    }

    function syncState() {
        toggles.forEach(function (btn) {
            var id = btn.getAttribute('data-int-toggle');
            var isOpen = id === openId;
            btn.setAttribute('aria-selected', isOpen ? 'true' : 'false');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            btn.classList.toggle('is-active', isOpen);
        });
    }

    /* Force the browser to commit the collapsed state before animating, so the
       transition actually runs instead of being coalesced into the open state. */
    function nextFrame(fn) {
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(fn);
        });
    }

    function expand(panel) {
        panel.hidden = false;
        panel.classList.remove('is-open');
        void panel.offsetHeight; /* flush layout */
        nextFrame(function () {
            panel.classList.add('is-open');
        });
    }

    function collapse(panel, done) {
        panel.classList.remove('is-open');
        var after = function (event) {
            /* Ignore bubbling transitionend from nested form controls. */
            if (event && (event.target !== panel || event.propertyName !== 'height')) {
                return;
            }
            panel.removeEventListener('transitionend', after);
            panel.hidden = true;
            if (typeof done === 'function') {
                done();
            }
        };

        panel.addEventListener('transitionend', after);
        /* Safety net: if the transition never fires (reduced motion, tab hidden,
           interrupted), still finish the collapse. */
        window.setTimeout(after, 420);
    }

    function open(id) {
        var panel = panelFor(id);
        if (!panel) {
            return;
        }

        if (openId === id) {
            return;
        }

        var previous = openId === null ? null : panelFor(openId);

        if (animating) {
            /* Finish any in-flight collapse immediately. */
            animating.hidden = true;
            animating.classList.remove('is-open');
            animating = null;
        }

        openId = id;

        if (previous) {
            animating = previous;
            collapse(previous, function () {
                if (animating === previous) {
                    animating = null;
                }
            });
        }

        expand(panel);
        syncState();
    }

    function close() {
        if (openId === null) {
            return;
        }
        var panel = panelFor(openId);
        openId = null;
        syncState();
        if (panel) {
            animating = panel;
            collapse(panel, function () {
                if (animating === panel) {
                    animating = null;
                }
            });
        }
    }

    function toggle(id) {
        if (openId === id) {
            close();
        } else {
            open(id);
        }
    }

    /* ---------------------------------------------------------- listeners --- */

    toggles.forEach(function (btn) {
        btn.addEventListener('click', function () {
            toggle(btn.getAttribute('data-int-toggle'));
        });
    });

    closers.forEach(function (btn) {
        btn.addEventListener('click', function () {
            close();
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && openId !== null) {
            var opener = toggleFor(openId);
            close();
            if (opener) {
                opener.focus();
            }
        }
    });

    /* Deep link support: ?key=google / #int-google opens that panel on load. */
    var wanted = new URLSearchParams(window.location.search).get('int')
        || (window.location.hash || '').replace(/^#int-/, '');
    if (wanted && toggleFor(wanted)) {
        open(wanted);
    }

    syncState();
})();
