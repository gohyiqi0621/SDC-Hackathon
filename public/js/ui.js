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

    window.UI = { toast: toast, openModal: openModal, closeModal: closeModal, confirm: confirmDialog, setLoading: setLoading, escapeHtml: escapeHtml };
})();
