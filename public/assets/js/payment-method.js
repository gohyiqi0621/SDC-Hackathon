/**
 * payment-method.js - Customer Assistant > Setup > Payment Method.
 *
 * Saves bank / e-wallet, account no., holder name, notes and the optional QR
 * to POST ai_settings/payment_method_save (multipart) and shows the result.
 */
(function () {
    'use strict';

    var box = document.querySelector('[data-payment-method]');
    if (!box) { return; }
    var form = box.querySelector('[data-pay-form]');
    var save = box.querySelector('[data-pay-save]');
    var qrInput = box.querySelector('[data-qr-input]');
    var qrImg = box.querySelector('[data-qr-img]');
    var qrEmpty = box.querySelector('[data-qr-empty]');
    var qrLabel = box.querySelector('[data-qr-label]');
    var qrRemove = box.querySelector('[data-qr-remove]');
    var preview = box.querySelector('[data-pay-preview]');
    var status = box.querySelector('[data-pay-status]');
    var removeQr = false;

    function toast(message, type) {
        if (window.App && typeof window.App.toast === 'function') { window.App.toast(message, type); }
    }
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function showQr(url) {
        qrImg.hidden = !url;
        qrEmpty.hidden = !!url;
        if (url) { qrImg.src = url; }
        qrRemove.hidden = !url;
        qrLabel.textContent = url ? 'Replace QR' : 'Upload QR';
    }
    function errors(map) {
        form.querySelectorAll('[data-error-for]').forEach(function (p) {
            var key = p.getAttribute('data-error-for');
            p.textContent = (map && map[key]) || '';
            var field = form.querySelector('[name="' + key + '"]');
            if (field) { field.classList.toggle('is-invalid', !!(map && map[key])); }
        });
    }

    qrInput.addEventListener('change', function () {
        var file = qrInput.files && qrInput.files[0];
        if (!file) { return; }
        if (file.size > 2 * 1024 * 1024) {
            errors({ payment_qr: 'The QR image must be 2 MB or smaller.' });
            qrInput.value = '';
            return;
        }
        removeQr = false;
        showQr(URL.createObjectURL(file));
    });
    qrRemove.addEventListener('click', function () {
        qrInput.value = '';
        removeQr = true;
        showQr(null);
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var local = {};
        if (!form.payment_bank.value) { local.payment_bank = 'Choose the bank or e-wallet customers pay to.'; }
        if (!form.payment_account_no.value.trim()) { local.payment_account_no = 'Enter the account number customers transfer to.'; }
        if (!form.payment_account_name.value.trim()) { local.payment_account_name = 'Enter the account holder name as it appears at the bank.'; }
        errors(local);
        if (Object.keys(local).length) { toast('Please fix the highlighted fields.', 'error'); return; }

        var data = new FormData(form);
        if (removeQr) { data.append('remove_payment_qr', '1'); }
        if (!qrInput.files || !qrInput.files.length) { data.delete('payment_qr'); }
        save.disabled = true;
        fetch(box.getAttribute('data-save-url'), {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() }
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    errors(res && res.data && res.data.errors);
                    toast((res && res.message) || 'The payment method could not be saved.', 'error');
                    return;
                }
                var p = res.data.payment || {};
                errors(null);
                removeQr = false;
                qrInput.value = '';
                showQr(p.qr_url);
                preview.textContent = p.summary ? 'Customers will see: “' + p.summary + '”' : '';
                status.textContent = p.summary ? 'Set' : 'Not set';
                status.classList.toggle('is-set', !!p.summary);
                toast('Payment method saved.', 'success');
                if (window.refreshSetupChecklist) { window.refreshSetupChecklist(); }
            })
            .catch(function () { toast('Network error. Please try again.', 'error'); })
            .then(function () { save.disabled = false; });
    });
})();
