/* ==========================================================================
   product-import.js - "Import products" on Product & Service.

   1. Upload   pick / drop a .csv or .xlsx -> server detects columns + checks rows
   2. Ask      if the file uses categories the user doesn't have: add them, or cancel
   3. Review   detected columns, preview, skipped rows -> Import
   ========================================================================== */
(function () {
    'use strict';

    var dialog = document.getElementById('importDialog');
    if (!dialog) { return; }

    var fileInput   = dialog.querySelector('[data-import-file]');
    var drop        = dialog.querySelector('[data-import-drop]');
    var fileLabel   = dialog.querySelector('[data-import-file-label]');
    var errorEl     = dialog.querySelector('[data-import-error]');
    var commitBtn   = dialog.querySelector('[data-import-commit]');
    var acceptBtn   = dialog.querySelector('[data-import-accept]');
    var declineBtn  = dialog.querySelector('[data-import-decline]');
    var defaultText = fileLabel.textContent;

    var state = null;              // last preview response
    var createCategories = false;  // user said yes to adding new categories

    function toast(message, type) {
        if (window.App && window.App.toast) { window.App.toast(message, type); }
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken()
            }
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('Unexpected server response (HTTP ' + response.status + ').');
            });
        });
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    function showStep(name) {
        dialog.querySelectorAll('[data-step]').forEach(function (step) {
            step.hidden = step.getAttribute('data-step') !== name;
        });
        dialog.classList.toggle('import-dialog--narrow', name === 'categories');
    }

    function setBusy(button, busy) {
        if (!button) { return; }
        button.disabled = busy;
        button.classList.toggle('is-loading', busy);
    }

    function reset() {
        state = null;
        createCategories = false;
        fileInput.value = '';
        fileLabel.textContent = defaultText;
        errorEl.textContent = '';
        drop.classList.remove('is-loading', 'is-dragging');
        showStep('upload');
    }

    function open() {
        reset();
        if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
    }

    function close() {
        if (dialog.open) { dialog.close(); }
    }

    /* Tell the server to drop the checked file (best effort). */
    function cancelOnServer() {
        var body = new FormData();
        return post(dialog.getAttribute('data-cancel-url'), body).catch(function () {});
    }

    /* ---------- 1. Upload ---------- */

    function upload(file) {
        if (!file) { return; }

        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (ext !== 'csv' && ext !== 'xlsx') {
            errorEl.textContent = 'Upload a .csv or .xlsx file.';
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            errorEl.textContent = 'The file must be 5 MB or smaller.';
            return;
        }

        errorEl.textContent = '';
        fileLabel.textContent = 'Reading ' + file.name + '…';
        drop.classList.add('is-loading');

        var body = new FormData();
        body.append('file', file);

        post(dialog.getAttribute('data-preview-url'), body)
            .then(function (result) {
                drop.classList.remove('is-loading');
                fileLabel.textContent = defaultText;
                fileInput.value = '';

                if (!result.success) {
                    var errors = (result.data && result.data.errors) || {};
                    errorEl.textContent = errors.file || result.message || 'The file could not be read.';
                    return;
                }

                state = result.data;
                createCategories = false;

                if (state.missing.length === 0 && state.valid > 0 && state.new_categories.length > 0) {
                    showAsk();
                } else {
                    showReview();
                }
            })
            .catch(function (error) {
                drop.classList.remove('is-loading');
                fileLabel.textContent = defaultText;
                errorEl.textContent = error.message || 'Network error. Please try again.';
            });
    }

    fileInput.addEventListener('change', function () {
        upload(fileInput.files && fileInput.files[0]);
    });

    ['dragenter', 'dragover'].forEach(function (type) {
        drop.addEventListener(type, function (e) {
            e.preventDefault();
            drop.classList.add('is-dragging');
        });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        drop.addEventListener(type, function (e) {
            e.preventDefault();
            drop.classList.remove('is-dragging');
        });
    });
    drop.addEventListener('drop', function (e) {
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files.length) { upload(files[0]); }
    });

    /* ---------- 2. Ask about new categories ---------- */

    function chips(container, names) {
        container.innerHTML = '';
        names.forEach(function (name) {
            var chip = el('span', 'badge badge--info');
            chip.appendChild(el('i', 'fa-solid fa-folder-plus'));
            chip.appendChild(document.createTextNode(' ' + name));
            container.appendChild(chip);
        });
    }

    function showAsk() {
        dialog.querySelectorAll('[data-import-filename]').forEach(function (n) { n.textContent = state.file; });
        chips(dialog.querySelector('[data-import-new-categories]'), state.new_categories);
        showStep('categories');
    }

    acceptBtn.addEventListener('click', function () {
        createCategories = true;
        showReview();
    });

    declineBtn.addEventListener('click', function () {
        cancelOnServer();
        close();
        toast('Import cancelled. No products were added.', 'info');
    });

    /* ---------- 3. Review ---------- */

    function showReview() {
        dialog.querySelectorAll('[data-import-filename]').forEach(function (n) { n.textContent = state.file; });

        // Summary
        var summary = dialog.querySelector('[data-import-summary]');
        summary.innerHTML = '';
        var stats = [
            ['Rows in file', state.total, ''],
            ['Ready to import', state.valid, 'is-ok'],
            ['Will be skipped', state.skipped_count, state.skipped_count ? 'is-warn' : '']
        ];
        stats.forEach(function (s) {
            var box = el('div', 'import-stat ' + s[2]);
            box.appendChild(el('strong', '', String(s[1])));
            box.appendChild(el('span', '', s[0]));
            summary.appendChild(box);
        });

        if (state.missing.length) {
            var miss = el('div', 'flash flash--error import-missing');
            miss.appendChild(el('i', 'fa-solid fa-circle-exclamation'));
            var mText = el('div');
            mText.appendChild(el('strong', '', 'Required columns not found'));
            mText.appendChild(el('span', '', 'Add a column for: ' + state.missing.join(', ') + '. Download the template to see the expected headers.'));
            miss.appendChild(mText);
            summary.appendChild(miss);
        }

        // New categories note
        var note = dialog.querySelector('[data-import-categories-note]');
        note.hidden = !(createCategories && state.new_categories.length);
        dialog.querySelector('[data-import-categories-list]').textContent = state.new_categories.join(', ');

        // Sample
        var sampleWrap = dialog.querySelector('[data-import-sample-wrap]');
        var tbody = dialog.querySelector('[data-import-sample]');
        tbody.innerHTML = '';
        sampleWrap.hidden = state.sample.length === 0;
        state.sample.forEach(function (row) {
            var tr = el('tr');
            tr.appendChild(el('td', '', row.name));
            tr.appendChild(el('td', row.sku ? '' : 'muted', row.sku || '—'));
            var cat = el('td');
            var isNew = state.new_categories.some(function (c) { return c.toLowerCase() === String(row.category).trim().replace(/\s+/g, ' ').toLowerCase(); });
            cat.appendChild(el('span', 'badge' + (isNew ? ' badge--info' : ''), row.category + (isNew ? ' (new)' : '')));
            tr.appendChild(cat);
            var price = row.currency + ' ' + row.price;
            tr.appendChild(el('td', 'num', price));
            tr.appendChild(el('td', '', row.status === 'active' ? 'Active' : 'Inactive'));
            tbody.appendChild(tr);
        });

        // Skipped rows
        var skippedWrap = dialog.querySelector('[data-import-skipped-wrap]');
        var skippedList = dialog.querySelector('[data-import-skipped]');
        skippedList.innerHTML = '';
        skippedWrap.hidden = state.skipped_count === 0;
        dialog.querySelector('[data-import-skipped-title]').textContent =
            state.skipped_count + ' row' + (state.skipped_count === 1 ? '' : 's') + ' will be skipped - show why';
        state.skipped.forEach(function (s) {
            var li = el('li');
            li.appendChild(el('strong', '', 'Line ' + s.line + ' · ' + s.name));
            li.appendChild(el('span', '', s.reason));
            skippedList.appendChild(li);
        });
        if (state.skipped_count > state.skipped.length) {
            skippedList.appendChild(el('li', 'muted', '…and ' + (state.skipped_count - state.skipped.length) + ' more.'));
        }

        // Import button
        commitBtn.disabled = state.valid === 0 || state.missing.length > 0;
        commitBtn.lastChild.textContent = ' Import ' + state.valid + ' product' + (state.valid === 1 ? '' : 's');

        showStep('review');
    }

    commitBtn.addEventListener('click', function () {
        if (!state) { return; }

        var body = new FormData();
        body.append('token', state.token);
        body.append('create_categories', createCategories ? '1' : '0');

        setBusy(commitBtn, true);

        post(dialog.getAttribute('data-commit-url'), body)
            .then(function (result) {
                if (result.success) {
                    var next = result.data && result.data.redirect;
                    window.location.href = next || window.location.href;
                    return;
                }
                setBusy(commitBtn, false);
                toast(result.message || 'The import failed.', 'error');
                reset();
            })
            .catch(function (error) {
                setBusy(commitBtn, false);
                toast(error.message || 'Network error. Please try again.', 'error');
            });
    });

    /* ---------- Open / close ---------- */

    document.querySelectorAll('[data-import-open]').forEach(function (button) {
        button.addEventListener('click', open);
    });

    dialog.querySelectorAll('[data-import-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (state) { cancelOnServer(); }
            close();
        });
    });

    dialog.querySelector('[data-import-restart]').addEventListener('click', function () {
        cancelOnServer();
        reset();
    });

    // Esc on the "new categories" question counts as "No".
    dialog.addEventListener('cancel', function () {
        if (state && !dialog.querySelector('[data-step="categories"]').hidden) {
            cancelOnServer();
            toast('Import cancelled. No products were added.', 'info');
        } else if (state) {
            cancelOnServer();
        }
    });
})();
