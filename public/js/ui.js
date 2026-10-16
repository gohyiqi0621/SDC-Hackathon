/**
 * ui.js — reusable UI pieces. Everything hangs off window.UI:
 * UI.toast, UI.openModal / closeModal, UI.confirm, UI.setLoading, UI.escapeHtml.
 */
(function () {
    'use strict';

    var ICONS = {
        success: '<polyline points="20 6 9 17 4 12"/>',
        error:   '<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="16.5" x2="12" y2="16.6"/>',
        warning: '<path d="M12 3 2.5 20h19z"/><line x1="12" y1="10" x2="12" y2="14"/><line x1="12" y1="17" x2="12" y2="17.1"/>',
        info:    '<circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16.5"/><line x1="12" y1="7.5" x2="12" y2="7.6"/>',
        close:   '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'
    };

    function svg(name, size) {
        var s = size ? ' style="width:' + size + 'px;height:' + size + 'px"' : '';
        return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"' + s + '>' + (ICONS[name] || ICONS.info) + '</svg>';
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    /* ----------------------------------------------------------- toast --- */
    var TYPES = ['success', 'error', 'warning', 'info'];

    function toast(message, type, timeout) {
        var region = document.getElementById('toast-stack');
        if (!region) { return null; }

        var kind = TYPES.indexOf(type) === -1 ? 'info' : type;
        var text = String(message);

        // Repeated submits used to stack identical prompts; replace in place.
        for (var i = 0; i < region.children.length; i++) {
            var seen = region.children[i];
            if (!seen.classList.contains('is-leaving')
                && seen.querySelector('span') && seen.querySelector('span').textContent === text
                && seen.classList.contains('toast--' + kind)) {
                return seen;
            }
        }

        var el = document.createElement('div');
        el.className = 'toast liquid-glass-strong toast--' + kind;
        el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        el.innerHTML = svg(kind) + '<span>' + escapeHtml(message) + '</span>'
            + '<button type="button" class="toast__close" aria-label="Dismiss">' + svg('close', 14) + '</button>';

        function dismiss() {
            if (el.classList.contains('is-leaving')) { return; }
            el.classList.add('is-leaving');
            el.addEventListener('animationend', function () { el.remove(); });
        }
        el.querySelector('.toast__close').addEventListener('click', dismiss);
        region.appendChild(el);

        var delay = typeof timeout === 'number' ? timeout : 4000;
        if (delay > 0) { setTimeout(dismiss, delay); }
        return el;
    }

    /* ----------------------------------------------------------- modal --- */
    function resolveEl(target) { return typeof target === 'string' ? document.querySelector(target) : target; }

    function openModal(target) {
        var el = resolveEl(target);
        if (!el) { return null; }
        if (typeof el.showModal === 'function' && !el.open) { el.showModal(); } else { el.setAttribute('open', ''); }
        return el;
    }

    /** Close with the exit animation, then really close (or on a safety timeout). */
    function closeModal(target) {
        var el = resolveEl(target);
        if (!el || !el.open || el.classList.contains('is-closing')) { return; }

        function finish() {
            el.classList.remove('is-closing');
            el.removeEventListener('animationend', once);
            if (el.open) { el.close(); }
        }
        var once = function (e) { if (e.target === el) { finish(); } };
        el.classList.add('is-closing');
        el.addEventListener('animationend', once);
        setTimeout(finish, 300); // animationend may never fire (no animation, hidden tab)
    }
    // Route every close path (Esc, backdrop click) through the exit animation.
    document.addEventListener('cancel', function (e) {
        if (e.target instanceof HTMLDialogElement && e.target.classList.contains('modal')) {
            e.preventDefault();
            closeModal(e.target);
        }
    }, true);

    // Light-dismiss: clicking the backdrop closes any .modal
    document.addEventListener('click', function (e) {
        var d = e.target;
        if (d instanceof HTMLDialogElement && d.classList.contains('modal') && d.open) { closeModal(d); }
    });
    // [data-open-modal="#id"] / [data-close-modal] triggers
    document.addEventListener('click', function (e) {
        var opener = e.target.closest && e.target.closest('[data-open-modal]');
        if (opener) { openModal(opener.getAttribute('data-open-modal')); }
        var closer = e.target.closest && e.target.closest('[data-close-modal]');
        if (closer) { closeModal(closer.closest('dialog')); }
    });

    /** Native <dialog> confirmation: resolves true only on the confirm button. */
    function confirmDialog(options) {
        var opts = options || {};
        var dialog = document.getElementById('confirm-dialog');
        if (!dialog) { return Promise.resolve(window.confirm(opts.message || 'Are you sure?')); }

        var ok = dialog.querySelector('[data-confirm-ok]');
        dialog.querySelector('[data-confirm-title]').textContent = opts.title || 'Are you sure?';
        dialog.querySelector('[data-confirm-message]').textContent = opts.message || '';
        ok.textContent = opts.confirmLabel || 'Confirm';
        ok.className = 'btn ' + (opts.danger === false ? 'btn--primary' : 'btn--danger-solid');

        dialog.returnValue = '';
        openModal(dialog);
        return new Promise(function (resolve) {
            dialog.addEventListener('close', function () { resolve(dialog.returnValue === 'confirm'); }, { once: true });
        });
    }

    /* --------------------------------------------------------- loading --- */
    function setLoading(button, loading) {
        if (!button) { return; }
        if (loading) {
            button.dataset.originalHtml = button.innerHTML;
            button.style.minWidth = button.offsetWidth + 'px'; // no layout jump
            button.classList.add('is-loading');
            button.disabled = true;
            button.innerHTML = '<span class="spinner" aria-hidden="true"></span><span class="sr-only">Loading</span>';
        } else {
            button.classList.remove('is-loading');
            button.disabled = false;
            button.style.minWidth = '';
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
        }
    }

    /* ----------------------------------------------------- confirm forms -- */
    // <form data-confirm-submit data-confirm-text="…"> asks first.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm-submit')) { return; }
        if (form.dataset.confirmed === '1') { return; }

        event.preventDefault();
        var btn = form.querySelector('button[type="submit"]');
        confirmDialog({
            title: form.getAttribute('data-confirm-title') || 'Please confirm',
            message: form.getAttribute('data-confirm-text') || 'Are you sure?',
            confirmLabel: btn ? btn.textContent.trim() : 'Confirm',
            danger: form.getAttribute('data-confirm-danger') !== 'false'
        }).then(function (ok) {
            if (ok) { form.dataset.confirmed = '1'; form.submit(); }
        });
    });

    /* --------------------------------------------- field error feel ------- */
    /* Source of truth is the .field__error text (server-rendered or written by
       api.js): non-empty slides in below the input, empty collapses. The input
       shakes and turns red whenever the field flips into error, and the error
       eases away as soon as the user types a valid value. */
    (function fieldErrors() {
        function inputOf(field) { return field.querySelector('input, select, textarea'); }

        function sync() {
            Array.prototype.forEach.call(document.querySelectorAll('.field'), function (field) {
                var error = field.querySelector('.field__error');
                if (!error) { return; }
                var open = error.textContent.trim() !== '';
                if (open && !field.classList.contains('is-error')) {
                    field.classList.add('is-error'); // triggers the CSS shake
                    if (navigator.vibrate) { navigator.vibrate(10); }
                } else if (!open && field.classList.contains('is-error')) {
                    field.classList.remove('is-error');
                    var input = inputOf(field);
                    if (input) { input.classList.remove('is-invalid'); input.removeAttribute('aria-invalid'); }
                }
            });
        }

        function wire() {
            Array.prototype.forEach.call(document.querySelectorAll('.field'), function (field) {
                if (field.dataset.fxError) { return; }
                var error = field.querySelector('.field__error');
                var input = field.querySelector('input, select, textarea');
                if (!error || !input) { return; }
                field.dataset.fxError = '1';
                input.addEventListener('input', function () {
                    // Fixing it: collapse the message as soon as the value validates.
                    if (error.textContent.trim() !== '' && input.checkValidity()) {
                        error.textContent = '';
                    }
                });
            });
        }

        var mo = new MutationObserver(function () { sync(); wire(); });
        mo.observe(document.body, { childList: true, characterData: true, subtree: true });
        sync();
        wire();
    })();

    /* --------------------------------------- password reveal (eye) -------- */
    /* Server markup ships one eye icon per button; the crossed-out eye is
       injected here so "show" state is visible on every password field. */
    (function passwordEyes() {
        var OFF = '<svg class="icon icon--sm icon--eye-off" aria-hidden="true" focusable="false"><use href="#i-eye-off"/></svg>';

        Array.prototype.forEach.call(document.querySelectorAll('[data-toggle-password]'), function (btn) {
            var input = document.getElementById(btn.getAttribute('data-toggle-password'));
            if (!input || btn.dataset.eyeWired || input.type !== 'password') { return; }
            btn.dataset.eyeWired = '1';
            btn.insertAdjacentHTML('beforeend', OFF);
            btn.setAttribute('aria-pressed', 'false');

            btn.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.classList.toggle('is-on', show);
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                btn.setAttribute('aria-pressed', String(show));
                if (show) { input.focus(); } // keep typing where they left off
            });
        });
    })();

    /* Wrong-password shake: a form-level warning inside an auth card nudges
       the whole card once, matching the field-level shake. */
    Array.prototype.forEach.call(document.querySelectorAll('.notice--warn[role="alert"]'), function (notice) {
        var card = notice.closest('.auth__card') || notice.closest('.card');
        if (!card || card.classList.contains('is-shaking')) { return; }
        card.classList.add('is-shaking');
        card.addEventListener('animationend', function () { card.classList.remove('is-shaking'); });
    });

    /* ------------------------------------------------------- count-up ----- */
    /* Numbers marked [data-countup] roll up from zero on load (non-numeric
       values like "Admin" or dates are skipped). */
    (function countUp() {
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        Array.prototype.forEach.call(document.querySelectorAll('[data-countup]'), function (el) {
            if (el.dataset.counted) { return; }
            el.dataset.counted = '1';
            var raw = el.textContent.trim().replace(/[,\s]/g, '');
            if (reduce || !/^\d+(\.\d+)?$/.test(raw)) { return; }
            var target = parseFloat(raw);
            var decimals = raw.indexOf('.') > -1 ? raw.split('.')[1].length : 0;
            var start = performance.now(), DURATION = 700;
            (function frame(now) {
                var p = Math.min(((now || performance.now()) - start) / DURATION, 1);
                var eased = 1 - Math.pow(1 - p, 3);
                el.textContent = decimals ? (target * eased).toFixed(decimals) : String(Math.round(target * eased));
                if (p < 1) { requestAnimationFrame(frame); }
            })();
        });
    })();

    window.UI = { toast: toast, openModal: openModal, closeModal: closeModal, confirm: confirmDialog, setLoading: setLoading, escapeHtml: escapeHtml };
})();
