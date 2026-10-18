/**
 * ui.js — reusable UI pieces. Everything hangs off window.UI:
 * UI.toast, UI.openModal / closeModal, UI.confirm, UI.setLoading, UI.escapeHtml, UI.countUp.
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
        var el = document.createElement('div');
        el.className = 'toast liquid-glass-strong toast--' + kind;
        el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        el.innerHTML = svg(kind) + '<span>' + escapeHtml(message) + '</span>'
            + '<button type="button" class="toast__close" aria-label="Dismiss">' + svg('close', 14) + '</button>';

        var timer = 0, remaining = typeof timeout === 'number' ? timeout : 4000, startedAt = 0;

        function dismiss() {
            clearTimeout(timer);
            if (el.classList.contains('is-leaving')) { return; }
            el.classList.remove('is-dragging', 'is-returning');
            el.classList.add('is-leaving');
            el.addEventListener('animationend', function () { el.remove(); });
        }
        function arm() {
            if (remaining <= 0) { return; }
            startedAt = Date.now();
            timer = setTimeout(dismiss, remaining);
        }
        function hold() {
            if (!timer) { return; }
            clearTimeout(timer); timer = 0;
            remaining = Math.max(1200, remaining - (Date.now() - startedAt));
        }
        el.querySelector('.toast__close').addEventListener('click', dismiss);
        region.appendChild(el);
        arm();

        // Reading it? Don't take it away: pause on hover/focus, resume (min 1.2s) after.
        el.addEventListener('pointerenter', hold);
        el.addEventListener('pointerleave', function () { if (!timer && !el.classList.contains('is-leaving')) { arm(); } });
        el.addEventListener('focusin', hold);

        // Swipe up to dismiss (iOS banner). Springs back when released short of the threshold.
        var y0 = 0, dy = 0, dragging = false;
        el.addEventListener('pointerdown', function (e) {
            if (e.target.closest('.toast__close') || (e.pointerType === 'mouse' && e.button !== 0)) { return; }
            dragging = true; y0 = e.clientY; dy = 0;
            el.setPointerCapture(e.pointerId);
            el.classList.remove('is-returning'); el.classList.add('is-dragging');
            hold();
        });
        el.addEventListener('pointermove', function (e) {
            if (!dragging) { return; }
            var d = e.clientY - y0;
            dy = d < 0 ? d : d * 0.15;                       // resist downward pulls
            el.style.transform = 'translateY(' + dy + 'px)';
        });
        function release() {
            if (!dragging) { return; }
            dragging = false;
            el.classList.remove('is-dragging');
            if (dy < -28) {
                el.style.setProperty('--dy', dy + 'px'); el.style.transform = '';
                dismiss();
            } else {
                el.classList.add('is-returning'); el.style.transform = '';
                arm();
            }
        }
        el.addEventListener('pointerup', release);
        el.addEventListener('pointercancel', release);

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
    function closeModal(target) {
        var el = resolveEl(target);
        if (el && el.open) { el.close(); }
    }

    // Light-dismiss: clicking the backdrop closes any .modal
    document.addEventListener('click', function (e) {
        var d = e.target;
        if (d instanceof HTMLDialogElement && d.classList.contains('modal') && d.open) { d.close('cancel'); }
    });
    // [data-open-modal="#id"] / [data-close-modal] triggers
    document.addEventListener('click', function (e) {
        var opener = e.target.closest && e.target.closest('[data-open-modal]');
        if (opener) { openModal(opener.getAttribute('data-open-modal')); }
        var closer = e.target.closest && e.target.closest('[data-close-modal]');
        if (closer) { closeModal(closer.closest('dialog')); }
    });

    // Phone bottom sheets: drag down from the top strip (grabber area) to dismiss.
    (function () {
        var sheetMq = window.matchMedia ? matchMedia('(max-width: 600px)') : null;
        var dlg = null, y0 = 0, dy = 0, t0 = 0;
        document.addEventListener('pointerdown', function (e) {
            var d = e.target.closest && e.target.closest('dialog.modal[open]');
            if (!d || !sheetMq || !sheetMq.matches) { return; }
            if (e.clientY - d.getBoundingClientRect().top > 56) { return; }     // grabber strip only
            dlg = d; y0 = e.clientY; dy = 0; t0 = Date.now();
            d.classList.add('is-dragging');
            d.setPointerCapture && d.setPointerCapture(e.pointerId);
        });
        document.addEventListener('pointermove', function (e) {
            if (!dlg) { return; }
            dy = Math.max(0, e.clientY - y0);
            dlg.style.transform = 'translateY(' + dy + 'px)';
        });
        function end() {
            if (!dlg) { return; }
            var d = dlg, fast = dy / Math.max(1, Date.now() - t0) > 0.6;
            dlg = null;
            d.classList.remove('is-dragging');
            d.style.transform = '';
            if (dy > 110 || (fast && dy > 30)) { d.close('cancel'); }
        }
        document.addEventListener('pointerup', end);
        document.addEventListener('pointercancel', end);
    })();

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
            if (!ok) { return; }
            form.dataset.confirmed = '1';
            // A confirmed submit is the only safe moment to run work that must
            // finish before navigation (e.g. unlinking the OneSignal alias).
            // A listener may cancel this event and navigate itself; otherwise we
            // submit as before. form.submit() fires no event, so no loop.
            var ev = new CustomEvent('app:confirmed-submit', { bubbles: true, cancelable: true });
            if (form.dispatchEvent(ev)) { form.submit(); }
        });
    });

    /* --------------------------------------------------------- count-up --- */
    // Whole-number stats ease up from 0 once they scroll into view. Anything that is not a
    // plain number ("User", "17 Oct 2026") is left alone. Reduced motion: no-op.
    function countUp(el, duration) {
        var text = el.textContent.trim();
        if (!/^\d{1,3}(,\d{3})*$|^\d+$/.test(text)) { return; }
        var target = parseInt(text.replace(/,/g, ''), 10);
        if (!target || target > 1e9) { return; }
        var comma = text.indexOf(',') !== -1, ms = duration || 700, t0 = 0;
        el.setAttribute('data-count', text);
        function fmt(n) { return comma ? n.toLocaleString('en-US') : String(n); }
        function frame(now) {
            if (!t0) { t0 = now; }
            var p = Math.min((now - t0) / ms, 1), eased = 1 - Math.pow(1 - p, 4);
            el.textContent = fmt(Math.round(target * eased));
            if (p < 1) { requestAnimationFrame(frame); } else { el.textContent = text; }
        }
        el.textContent = fmt(0);
        requestAnimationFrame(frame);
    }
    function initCountUp() {
        if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
        var els = document.querySelectorAll('.stat__value');
        if (!('IntersectionObserver' in window)) { return; }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) { if (en.isIntersecting) { io.unobserve(en.target); countUp(en.target); } });
        }, { threshold: 0.4 });
        Array.prototype.forEach.call(els, function (el) { io.observe(el); });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initCountUp); } else { initCountUp(); }

    window.UI = { countUp: countUp, toast: toast, openModal: openModal, closeModal: closeModal, confirm: confirmDialog, setLoading: setLoading, escapeHtml: escapeHtml };
})();
