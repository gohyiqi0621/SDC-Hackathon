/**
 * orders.js - Orders page popups: Details (one <dialog> per order) and the
 * Reject popup (reason sent to the customer). Everything else is plain forms.
 */
(function () {
    'use strict';

    var reject = document.getElementById('rejectDialog');

    function open(dialog) {
        if (!dialog) { return; }
        if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
    }

    function close(dialog) {
        if (!dialog) { return; }
        if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
    }

    document.addEventListener('click', function (e) {
        var details = e.target.closest('[data-details]');
        if (details) {
            open(document.getElementById('details-' + details.getAttribute('data-details')));
            return;
        }

        var rejectBtn = e.target.closest('[data-reject]');
        if (rejectBtn && reject) {
            var openDetails = rejectBtn.closest('dialog');
            if (openDetails) { close(openDetails); }
            reject.querySelector('[data-reject-id]').value = rejectBtn.getAttribute('data-reject');
            reject.querySelector('[data-reject-no]').textContent = rejectBtn.getAttribute('data-order-no') || '';
            reject.querySelector('textarea').value = '';
            open(reject);
            setTimeout(function () { reject.querySelector('textarea').focus(); }, 30);
            return;
        }

        if (e.target.closest('[data-close]')) {
            close(e.target.closest('dialog'));
            return;
        }

        // Click on the dark backdrop closes a popup.
        if (e.target.tagName === 'DIALOG' && e.target.classList.contains('odialog')) {
            var r = e.target.getBoundingClientRect();
            if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) {
                close(e.target);
            }
        }
    });

    /* ---- Tick orders, then delete them together ---- */
    var bulk  = document.getElementById('bulkDelete');
    var all   = document.querySelector('[data-check-all]');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-check]'));

    function syncBulk() {
        if (!bulk) { return; }
        var n = boxes.filter(function (b) { return b.checked; }).length;
        bulk.hidden = n === 0;
        bulk.querySelector('[data-bulk-count]').textContent = String(n);
        bulk.setAttribute('data-confirm', 'Delete ' + n + ' order' + (n === 1 ? '' : 's') +
            '? This cannot be undone. Paid invoices stay in Financial > Invoice.');
        if (all) {
            all.checked = n > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        }
        boxes.forEach(function (b) { b.closest('tr').classList.toggle('is-selected', b.checked); });
    }
    boxes.forEach(function (b) { b.addEventListener('change', syncBulk); });
    if (all) {
        all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            syncBulk();
        });
    }
    if (bulk) {
        bulk.querySelector('[data-bulk-clear]').addEventListener('click', function () {
            boxes.forEach(function (b) { b.checked = false; });
            syncBulk();
        });
    }
    syncBulk();

    // A second click on a submit button must not send twice.
    document.querySelectorAll('.orders-page form, .odialog form').forEach(function (form) {
        form.addEventListener('submit', function () {
            if (form.hasAttribute('data-confirm') && form.dataset.confirmed !== '1') { return; }
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { setTimeout(function () { btn.disabled = true; }, 0); }
        });
    });
})();
