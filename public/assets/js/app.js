/* =====================================
   SMART SME - APP SCRIPT
   app.js - shared behaviour for every page.

   1. Toasts            window.App.toast(message, type)
   2. Sidebar           mobile open/close, desktop collapse, collapsible groups
   3. Profile menu      topbar dropdown
   4. Password reveal   <button data-toggle-password="inputId">
   5. AJAX forms        <form data-ajax> (falls back to a normal post)
   6. Upload picker     [data-file-input] + [data-file-label]
   7. Chat              [data-chat] composer; real AI when data-chat-endpoint is set, demo otherwise
   8. Confirm dialog    <form data-confirm="Message" data-confirm-title data-confirm-ok>
   9. Filter forms      <form data-auto-submit> submits when a select changes
  10. Image picker      [data-image-picker] preview before upload
  12. Field errors      a field's error clears as soon as it is edited
  13. Money inputs      <input data-money> shows 10,000.00 while typing
  15. Popups            <dialog class="modal"> opened by [data-modal-open="#id"]
  16. File preview      [data-file-preview] shows the chosen PDF / image
  17. Submit confirm    <form data-submit-confirm="Message"> asks once before sending
  18. Invoice picker    [data-picker] tick products + qty, live totals; customer suggestions
  19. AI auto-fill      [data-ai-fill] reads a claim receipt and fills the claim form
===================================== */

(function () {
    'use strict';

    /* ---------- 1. Toasts ---------- */

    var ICONS = {
        success: 'fa-circle-check',
        error: 'fa-circle-exclamation',
        info: 'fa-circle-info'
    };

    function toast(message, type) {
        var region = document.getElementById('toastRegion');
        if (!region || !message) { return; }

        type = ICONS[type] ? type : 'info';

        var el = document.createElement('div');
        el.className = 'toast toast--' + type;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var icon = document.createElement('i');
        icon.className = 'fa-solid ' + ICONS[type];

        var text = document.createElement('span');
        text.textContent = message;

        el.appendChild(icon);
        el.appendChild(text);
        region.appendChild(el);

        setTimeout(function () { el.remove(); }, 4500);
    }

    window.App = { toast: toast };

    /* ---------- 2. Sidebar ---------- */

    var app = document.querySelector('.app');
    var root = document.documentElement;

    // Desktop collapse (icons only). head.php restores the saved state before paint.
    function setCollapsed(collapsed) {
        root.classList.toggle('sidebar-collapsed', collapsed);
        try { localStorage.setItem('sidebar', collapsed ? 'collapsed' : 'open'); } catch (err) {}

        var btn = document.querySelector('[data-sidebar-collapse]');
        if (btn) {
            var label = collapsed ? 'Open sidebar' : 'Close sidebar';
            btn.setAttribute('aria-expanded', String(!collapsed));
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
        }

        var brand = document.querySelector('[data-sidebar-brand]');
        if (brand) {
            brand.setAttribute('title', collapsed ? 'Open sidebar' : brand.dataset.brandTitle || 'Home');
        }

        // With the labels hidden, a hover tooltip names each icon.
        document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
            var text = link.querySelector('span');
            if (collapsed && text) {
                link.setAttribute('title', text.textContent.trim());
            } else {
                link.removeAttribute('title');
            }
        });
    }

    if (root.classList.contains('sidebar-collapsed')) { setCollapsed(true); }

    document.addEventListener('click', function (e) {
        var brand = e.target.closest('[data-sidebar-brand]');
        if (brand && root.classList.contains('sidebar-collapsed') && window.matchMedia('(min-width: 901px)').matches) {
            e.preventDefault();
            setCollapsed(false);
            return;
        }

        if (e.target.closest('[data-sidebar-collapse]')) {
            setCollapsed(!root.classList.contains('sidebar-collapsed'));
            return;
        }

        if (e.target.closest('[data-nav-toggle]')) {
            app && app.classList.toggle('nav-open');
        } else if (e.target.closest('[data-nav-close]')) {
            app && app.classList.remove('nav-open');
        }

        var groupBtn = e.target.closest('[data-nav-group]');
        if (groupBtn) {
            var group = groupBtn.closest('.nav-group');

            // A collapsed group has nowhere to show its pages: expand the sidebar and open it.
            if (root.classList.contains('sidebar-collapsed') && window.matchMedia('(min-width: 901px)').matches) {
                setCollapsed(false);
                group.classList.add('open');
                groupBtn.setAttribute('aria-expanded', 'true');
                return;
            }

            var open = group.classList.toggle('open');
            groupBtn.setAttribute('aria-expanded', String(open));
        }
    });

    /* ---------- 3. Profile menu ---------- */

    var menu = document.querySelector('[data-profile-menu]');
    if (menu) {
        var toggle = menu.querySelector('[data-profile-toggle]');
        var dropdown = menu.querySelector('.profile-dropdown');

        var setMenu = function (open) {
            dropdown.hidden = !open;
            toggle.setAttribute('aria-expanded', String(open));
        };

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            setMenu(dropdown.hidden);
        });
        document.addEventListener('click', function (e) {
            if (!menu.contains(e.target)) { setMenu(false); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { setMenu(false); }
        });
    }

    /* ---------- 4. Password reveal ---------- */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-toggle-password]');
        if (!btn) { return; }

        var input = document.getElementById(btn.getAttribute('data-toggle-password'));
        if (!input) { return; }

        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');

        var icon = btn.querySelector('i');
        if (icon) {
            icon.className = 'fa-regular ' + (show ? 'fa-eye-slash' : 'fa-eye');
        }
    });

    /* ---------- 5. AJAX forms ---------- */

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function clearErrors(form) {
        form.querySelectorAll('[data-error-for]').forEach(function (el) { el.textContent = ''; });
        form.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        });
    }

    function showErrors(form, errors) {
        clearErrors(form);
        Object.keys(errors).forEach(function (field) {
            var message = form.querySelector('[data-error-for="' + field + '"]');
            if (message) { message.textContent = errors[field]; }

            var input = form.querySelector('[name="' + field + '"]');
            if (input) {
                input.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
            }
        });
    }

    function setLoading(button, loading) {
        if (!button) { return; }
        button.disabled = loading;
        button.classList.toggle('is-loading', loading);
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax')) { return; }
        if (!window.fetch || !window.FormData) { return; } // plain post still works

        e.preventDefault();

        var button = form.querySelector('button[type="submit"]');
        setLoading(button, true);

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken()
            }
        })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Unexpected server response (HTTP ' + response.status + ').');
                });
            })
            .then(function (result) {
                if (result.success) {
                    var next = result.data && result.data.redirect;
                    if (next) {
                        window.location.href = next;
                        return;
                    }
                    toast(result.message || 'Saved.', 'success');
                    clearErrors(form);
                    setLoading(button, false);
                    return;
                }

                setLoading(button, false);
                showErrors(form, (result.data && result.data.errors) || {});
                toast(result.message || 'Please check the form.', 'error');
            })
            .catch(function (error) {
                setLoading(button, false);
                toast(error.message || 'Network error. Please try again.', 'error');
            });
    });

    /* ---------- 6. Upload picker ---------- */

    document.querySelectorAll('[data-file-input]').forEach(function (input) {
        var drop = input.closest('[data-upload-drop]');
        var label = (drop || document).querySelector('[data-file-label]');
        var original = label ? label.textContent : '';

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (label) { label.textContent = file ? file.name : original; }
        });

        if (drop) {
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
                if (e.dataTransfer && e.dataTransfer.files.length) {
                    input.files = e.dataTransfer.files;
                    input.dispatchEvent(new Event('change'));
                }
            });
        }
    });

    /* ---------- 7. Chat ---------- */

    document.querySelectorAll('[data-chat]').forEach(function (chat) {
        var body = chat.querySelector('.chat-body');
        var form = chat.querySelector('.composer');
        var input = form.querySelector('textarea');

        function bubble(text, who) {
            var el = document.createElement('div');
            el.className = 'chat-bubble chat-bubble--' + who;
            el.textContent = text;
            body.appendChild(el);
            body.scrollTop = body.scrollHeight;
        }

        var endpoint = chat.getAttribute('data-chat-endpoint');
        var button = form.querySelector('button[type="submit"]');
        var busy = false;

        body.scrollTop = body.scrollHeight;

        function note(text) {
            var el = document.createElement('div');
            el.className = 'chat-note';
            el.textContent = text;
            body.appendChild(el);
            body.scrollTop = body.scrollHeight;
        }

        function typing(on) {
            var el = body.querySelector('.chat-typing');
            if (on && !el) {
                el = document.createElement('div');
                el.className = 'chat-bubble chat-bubble--bot chat-typing';
                el.innerHTML = '<span></span><span></span><span></span>';
                body.appendChild(el);
                body.scrollTop = body.scrollHeight;
            } else if (!on && el) {
                el.remove();
            }
        }

        // Real AI: same pipeline as Telegram (AI Settings -> Flowise).
        function ask(text) {
            busy = true;
            setLoading(button, true);
            typing(true);

            var data = new FormData();
            data.append('message', text);

            fetch(endpoint, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken() }
            })
                .then(function (r) { return r.json(); })
                .then(function (result) {
                    typing(false);
                    if (!result.success) {
                        note(result.message || 'The message could not be sent.');
                        return;
                    }
                    var d = result.data || {};
                    if (d.reply) { bubble(d.reply, 'bot'); }
                    if (d.status === 'skipped') {
                        note('No reply: the AI assistant is switched off in AI Settings.');
                    } else if (d.status === 'error') {
                        note('Customers would get the reply above. Reason: ' + (d.error || 'unknown error'));
                    }
                })
                .catch(function () {
                    typing(false);
                    note('Network error. Please try again.');
                })
                .then(function () {
                    busy = false;
                    setLoading(button, false);
                    input.focus();
                });
        }

        function send(text) {
            text = text.trim();
            if (!text || busy) { return; }
            bubble(text, 'me');
            input.value = '';

            if (endpoint) {
                ask(text);
                return;
            }

            setTimeout(function () {
                bubble('Thanks! The AI assistant is not connected yet, so this is a demo reply. '
                    + 'Once it is connected, it will answer from your records.', 'bot');
            }, 500);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            send(input.value);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send(input.value);
            }
        });

        chat.querySelectorAll('[data-suggest]').forEach(function (chip) {
            chip.addEventListener('click', function () { send(chip.textContent); });
        });
    });

    /* ---------- 8. Confirm dialog ---------- */

    var dialog = document.getElementById('confirmDialog');

    /**
     * Ask in the shared confirm dialog; onYes runs only on "confirm".
     * tone "danger" (default, red) is for destructive actions; anything
     * else ("primary") shows a calm, non-red dialog.
     */
    function askConfirm(opts, onYes) {
        if (!dialog || typeof dialog.showModal !== 'function') {
            if (window.confirm(opts.message)) { onYes(); }
            return;
        }

        var danger = (opts.tone || 'danger') === 'danger';
        var button = dialog.querySelector('[data-confirm-button]');

        dialog.classList.toggle('confirm-dialog--primary', !danger);
        dialog.querySelector('[data-confirm-title]').textContent = opts.title || 'Are you sure?';
        dialog.querySelector('[data-confirm-message]').textContent = opts.message || '';
        dialog.querySelector('[data-confirm-cancel]').textContent = opts.cancel || 'Cancel';
        button.textContent = opts.ok || 'Confirm';
        button.className = danger ? 'danger-btn' : 'primary-btn';
        dialog.returnValue = '';
        dialog.showModal();

        dialog.addEventListener('close', function onClose() {
            dialog.removeEventListener('close', onClose);
            if (dialog.returnValue === 'confirm') { onYes(); }
        });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) { return; }
        if (form.dataset.confirmed === '1') { return; }

        e.preventDefault();
        askConfirm({
            title: form.getAttribute('data-confirm-title'),
            message: form.getAttribute('data-confirm'),
            ok: form.getAttribute('data-confirm-ok'),
            tone: form.getAttribute('data-confirm-tone')
        }, function () {
            form.dataset.confirmed = '1';
            setLoading(form.querySelector('button[type="submit"]'), true);
            form.submit();
        });
    }, true);

    /* ---------- 9. Filter forms ---------- */

    document.querySelectorAll('form[data-auto-submit]').forEach(function (form) {
        form.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () { form.submit(); });
        });
    });

    /* ---------- 10. Image picker ---------- */

    document.querySelectorAll('[data-image-picker]').forEach(function (picker) {
        var input = picker.querySelector('[data-image-input]');
        var preview = picker.querySelector('[data-image-preview]');
        var placeholder = picker.querySelector('[data-image-placeholder]');
        var remove = picker.querySelector('[data-image-remove]');
        var original = preview.getAttribute('src');

        function show(src) {
            preview.hidden = !src;
            placeholder.hidden = !!src;
            if (src) { preview.src = src; }
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) { show(original); return; }
            if (remove) { remove.checked = false; }
            var reader = new FileReader();
            reader.onload = function () { show(reader.result); };
            reader.readAsDataURL(file);
        });

        if (remove) {
            remove.addEventListener('change', function () {
                if (remove.checked) {
                    input.value = '';
                    show('');
                } else {
                    show(original);
                }
            });
        }
    });

    /* ---------- 12. Field errors ---------- */

    // A stale "Enter the amount" under a field the user is now filling in is
    // confusing, so a field's error goes away once it is edited.
    function clearFieldError(e) {
        var field = e.target;
        if (!field.classList || !field.classList.contains('is-invalid')) { return; }
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        var form = field.form;
        var message = form && field.name ? form.querySelector('[data-error-for="' + field.name + '"]') : null;
        if (message) { message.textContent = ''; }
    }
    document.addEventListener('input', clearFieldError);
    document.addEventListener('change', clearFieldError);

    /* ---------- 13. Money inputs ---------- */

    // Adds thousand separators as the user types (1234567.5 -> 1,234,567.5) and
    // pads to two decimals on blur. The server strips the commas again.
    function formatMoney(raw) {
        var clean = String(raw).replace(/[^0-9.]/g, '');
        var dot = clean.indexOf('.');
        var whole = dot === -1 ? clean : clean.slice(0, dot);
        var cents = dot === -1 ? null : clean.slice(dot + 1).replace(/\./g, '').slice(0, 2);

        whole = whole.replace(/^0+(?=\d)/, '').slice(0, 10);
        if (whole === '' && cents !== null) { whole = '0'; }
        whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        return cents === null ? whole : whole + '.' + cents;
    }

    function padMoney(value) {
        var parts = formatMoney(value).split('.');
        return (parts[0] || '0') + '.' + ((parts[1] || '') + '00').slice(0, 2);
    }

    document.querySelectorAll('input[data-money]').forEach(function (input) {
        if (input.value !== '') { input.value = padMoney(input.value); }
    });

    // Delegated, so rows added later (invoice items) are formatted too.
    document.addEventListener('input', function (e) {
        var input = e.target;
        if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-money') || input.readOnly) { return; }

        // Keep the caret after the same digit once commas move around.
        var caret = input.selectionStart || 0;
        var before = input.value.slice(0, caret).replace(/,/g, '').length;
        var next = formatMoney(input.value);
        input.value = next;

        var pos = 0;
        for (var seen = 0; pos < next.length && seen < before; pos++) {
            if (next.charAt(pos) !== ',') { seen++; }
        }
        input.setSelectionRange(pos, pos);
    });

    document.addEventListener('focusout', function (e) {
        var input = e.target;
        if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-money') || input.value === '') { return; }
        input.value = padMoney(input.value);
    });

    /* ---------- 14. AI preview ---------- */

    document.querySelectorAll('[data-ai-preview]').forEach(function (panel) {
        var list = panel.querySelector('[data-ai-status]');
        var dialogEl = document.querySelector('[data-ai-preview-dialog]');
        var loaded = null;

        function row(ok, title, text) {
            var li = document.createElement('li');
            li.innerHTML = '<span class="item-icon"><i class="fa-solid ' + (ok ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i></span><span><strong></strong><small></small></span>';
            li.querySelector('.item-icon').classList.add(ok ? 'item-icon--ok' : 'item-icon--warn');
            li.querySelector('strong').textContent = title;
            li.querySelector('small').textContent = text;
            return li;
        }

        fetch(panel.getAttribute('data-ai-preview'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                var d = result.data || {};
                loaded = d;
                list.innerHTML = '';
                list.appendChild(row(d.flowise_configured, 'Flowise', d.flowise_configured ? 'Connected in .env' : 'Not set up yet (FLOWISE_API_URL / FLOWISE_CHATFLOW_ID)'));
                list.appendChild(row(d.enabled, 'Assistant', d.enabled ? 'Switched on' : 'Switched off or not saved'));
                list.appendChild(row(true, 'Model', (d.model || '?') + ' (' + (d.provider || '?') + '), temperature ' + d.temperature));
                (d.problems || []).forEach(function (p) { list.appendChild(row(false, 'To do', p)); });
            })
            .catch(function () {
                list.innerHTML = '';
                list.appendChild(row(false, 'Could not load', 'Refresh the page to try again.'));
            });

        var open = panel.querySelector('[data-ai-preview-open]');
        if (open && dialogEl) {
            open.addEventListener('click', function () {
                if (!loaded) { toast('Still loading, try again in a moment.'); return; }
                dialogEl.querySelector('[data-ai-preview-system]').textContent = loaded.system_message || '';
                dialogEl.querySelector('[data-ai-preview-payload]').textContent = JSON.stringify(loaded.payload || {}, null, 2);
                dialogEl.showModal();
            });
            dialogEl.querySelector('[data-ai-preview-close]').addEventListener('click', function () { dialogEl.close(); });
            dialogEl.addEventListener('click', function (e) { if (e.target === dialogEl) { dialogEl.close(); } });
        }
    });

    /* ---------- 15. Popups ---------- */

    // <a href="fallback" data-modal-open="#id"> opens <dialog id="id" class="modal">.
    // The link still works without JavaScript (the page renders the popup open).
    function openModal(modal) {
        if (!modal || typeof modal.showModal !== 'function') { return false; }
        if (!modal.open) { modal.showModal(); }
        return true;
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-modal-open]');
        if (opener) {
            if (openModal(document.querySelector(opener.getAttribute('data-modal-open')))) { e.preventDefault(); }
            return;
        }

        var closer = e.target.closest('[data-modal-close]');
        if (closer && closer.closest('dialog')) { closer.closest('dialog').close(); }
    });

    document.querySelectorAll('dialog.modal').forEach(function (modal) {
        if (modal.hasAttribute('data-modal-autoopen')) { openModal(modal); }

        modal.addEventListener('close', function () {
            // An edit popup belongs to its URL: closing it goes back to the plain list.
            var back = modal.getAttribute('data-modal-return');
            if (back) { window.location.replace(back); return; }

            // Drop ?new=1 so a refresh does not reopen the popup.
            if (/[?&]new=/.test(window.location.search) && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.delete('new');
                window.history.replaceState(null, '', url.toString());
            }
        });
    });

    /* ---------- 16. File preview ---------- */

    // Shows the chosen PDF or image next to the form, so details can be checked against it.
    document.querySelectorAll('[data-file-preview]').forEach(function (pane) {
        var input = pane.querySelector('[data-file-input]');
        var frame = pane.querySelector('[data-preview-frame]');
        var img = pane.querySelector('[data-preview-img]');
        var pdf = pane.querySelector('[data-preview-pdf]');
        var empty = pane.querySelector('[data-preview-empty]');
        var remove = pane.querySelector('input[name="remove_file"], input[name="remove_receipt"]');
        if (!input || !frame || !img || !pdf) { return; }

        var initial = { shown: !frame.hidden, pdf: !pdf.hidden, src: pdf.hidden ? img.getAttribute('src') : pdf.getAttribute('src') };
        var objectUrl = null;

        function show(kind, src) {
            img.hidden = kind !== 'img';
            pdf.hidden = kind !== 'pdf';
            if (kind === 'img') { img.src = src; }
            if (kind === 'pdf') { pdf.src = src; }
            frame.hidden = !kind;
            if (empty) { empty.hidden = !!kind; }
        }

        function restore() {
            if (initial.shown) { show(initial.pdf ? 'pdf' : 'img', initial.src); } else { show(null); }
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            if (!file) { restore(); return; }

            if (remove) { remove.checked = false; }
            objectUrl = URL.createObjectURL(file);
            if (file.type === 'application/pdf') {
                show('pdf', objectUrl);
            } else if (/^image\//.test(file.type)) {
                show('img', objectUrl);
            } else {
                show(null);
            }
        });

        if (remove) {
            remove.addEventListener('change', function () {
                if (remove.checked) {
                    input.value = '';
                    show(null);
                } else {
                    restore();
                }
            });
        }
    });

    /* ---------- 17. Submit confirm ---------- */

    // <form data-submit-confirm="Message"> asks once ("Yes, submit" / "Review again")
    // before sending - used where the sender cannot change the record afterwards.
    // With data-submit-confirm-intent="issue" it only asks when the form's
    // intent field has that value (so "Save as draft" goes straight through).
    // Runs before the AJAX handler (capture phase) and hands back to it on "yes".
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-submit-confirm')) { return; }

        if (form.dataset.submitConfirmed === '1') {
            delete form.dataset.submitConfirmed;
            return;
        }

        var onlyFor = form.getAttribute('data-submit-confirm-intent');
        var intent = form.querySelector('[data-intent-field]');
        if (onlyFor && (!intent || intent.value !== onlyFor)) { return; }

        // Required fields still empty: let the server's field errors show first.
        var missing = Array.prototype.some.call(form.querySelectorAll('[required]'), function (field) {
            return String(field.value || '').trim() === '';
        });
        if (missing) { return; }

        e.preventDefault();
        e.stopPropagation();

        askConfirm({
            title: form.getAttribute('data-submit-confirm-title') || 'Submit now?',
            message: form.getAttribute('data-submit-confirm'),
            ok: 'Yes, submit',
            cancel: 'Review again',
            tone: 'primary'
        }, function () {
            form.dataset.submitConfirmed = '1';
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
        });
    }, true);

    // <button type="submit" data-intent="draft"> puts its intent in the form's
    // [data-intent-field] first (FormData does not include the clicked button).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-intent]');
        if (!btn || !btn.form) { return; }
        var field = btn.form.querySelector('[data-intent-field]');
        if (field) { field.value = btn.getAttribute('data-intent'); }
    });

    /* ---------- 18. Invoice: product picker + customer suggestions ---------- */

    // Every active product is listed; ticking one enables its quantity box and
    // the totals update (subtotal + the company's SST = total). Products priced
    // in another currency than the invoice cannot be ticked. The server prices
    // the invoice again from the database - this is only what the user sees.
    document.querySelectorAll('[data-picker]').forEach(function (picker) {
        var form = picker.closest('form');
        var rate = parseFloat(picker.getAttribute('data-sst-rate')) || 0;
        var currencySelect = form.querySelector('[data-invoice-currency]');
        var search = picker.querySelector('[data-picker-search]');
        var noMatch = picker.querySelector('[data-picker-nomatch]');
        var rows = Array.prototype.slice.call(picker.querySelectorAll('[data-picker-row]'));

        function show(cents, cur) { return (cur ? cur + ' ' : '') + padMoney((cents / 100).toFixed(2)); }

        function recalc() {
            var cur = currencySelect ? currencySelect.value : 'MYR';
            var subtotal = 0;
            var count = 0;

            rows.forEach(function (row) {
                var check = row.querySelector('[data-picker-check]');
                var qty = row.querySelector('[data-picker-qty]');
                var otherCurrency = row.getAttribute('data-currency') !== cur;

                if (otherCurrency && check.checked) { check.checked = false; }
                check.disabled = otherCurrency;
                row.classList.toggle('is-disabled', otherCurrency);
                row.querySelector('[data-picker-currency-note]').hidden = !otherCurrency;

                qty.disabled = !check.checked;
                row.classList.toggle('is-picked', check.checked);

                var cents = 0;
                if (check.checked) {
                    var q = parseFloat(String(qty.value).replace(/,/g, '')) || 0;
                    cents = Math.round(q * Math.round(parseFloat(row.getAttribute('data-price')) * 100));
                    subtotal += cents;
                    count++;
                }
                row.querySelector('[data-picker-total]').textContent = check.checked ? padMoney((cents / 100).toFixed(2)) : '—';
            });

            var sst = Math.round(subtotal * rate / 100);
            picker.querySelector('[data-picker-subtotal]').textContent = show(subtotal, cur);
            if (rate > 0) { picker.querySelector('[data-picker-sst]').textContent = show(sst, cur); }
            picker.querySelector('[data-picker-grand]').textContent = show(subtotal + sst, cur);
            picker.querySelector('[data-picker-count]').textContent = count ? '(' + count + ' item' + (count === 1 ? '' : 's') + ')' : '';
        }

        picker.addEventListener('change', function (e) {
            if (e.target.matches('[data-picker-check]') && e.target.checked) {
                var qty = e.target.closest('[data-picker-row]').querySelector('[data-picker-qty]');
                if (!(parseFloat(qty.value) > 0)) { qty.value = '1'; }
                recalc();
                qty.focus();
                qty.select();
                return;
            }
            recalc();
        });
        picker.addEventListener('input', recalc);
        if (currencySelect) { currencySelect.addEventListener('change', recalc); }

        // Category dropdown + search box narrow the list together.
        var category = picker.querySelector('[data-picker-category]');

        function filter() {
            var term = search ? search.value.trim().toLowerCase() : '';
            var cat = category ? category.value : '';
            var shown = 0;
            rows.forEach(function (row) {
                // A ticked product always stays visible, so it can be unticked.
                var match = row.querySelector('[data-picker-check]').checked
                    || ((cat === '' || row.getAttribute('data-category') === cat)
                        && (term === '' || row.getAttribute('data-search').indexOf(term) !== -1));
                row.hidden = !match;
                if (match) { shown++; }
            });
            if (noMatch) { noMatch.hidden = shown > 0; }
        }

        if (search) {
            search.addEventListener('input', filter);
            // Enter in the search box must not submit the invoice.
            search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
        }
        if (category) { category.addEventListener('change', filter); }

        recalc();
    });

    // Customer: picking a name used on an earlier invoice fills its address and
    // phone (only into empty fields, so nothing typed is overwritten).
    document.querySelectorAll('[data-customer-input]').forEach(function (input) {
        var form = input.form;
        var dataEl = form.querySelector('[data-customer-data]');
        var hint = form.querySelector('[data-customer-hint]');
        var customers = [];
        try { customers = JSON.parse(dataEl ? dataEl.textContent : '[]') || []; } catch (err) { customers = []; }

        function matchFor(value) {
            var name = value.trim().toLowerCase();
            return customers.find(function (c) { return String(c.party_name).toLowerCase() === name; });
        }

        function apply() {
            var match = matchFor(input.value);
            var filled = false;
            if (match) {
                [['party_address', match.party_address], ['party_phone', match.party_phone]].forEach(function (pair) {
                    var field = form.elements[pair[0]];
                    if (field && !field.value.trim() && pair[1]) {
                        field.value = pair[1];
                        filled = true;
                    }
                });
            }
            if (hint) { hint.hidden = !filled; }
        }

        input.addEventListener('change', apply);
        // Picking from the browser's suggestion list fires "input" with the full name.
        input.addEventListener('input', function () { if (matchFor(input.value)) { apply(); } });
    });

    // Payment terms: a standard term sets the due date (invoice date + its days);
    // "Custom…" shows a text box and leaves the due date to the user.
    document.querySelectorAll('[data-payment-terms]').forEach(function (select) {
        var form = select.form;
        var custom = form.querySelector('[data-payment-terms-custom]');
        var issue = form.elements.issue_date;
        var due = form.elements.due_date;

        function addDays(ymd, days) {
            var p = String(ymd).split('-').map(Number);
            if (p.length !== 3 || !p[0]) { return ''; }
            var d = new Date(Date.UTC(p[0], p[1] - 1, p[2] + days));
            return d.toISOString().slice(0, 10);
        }

        function sync(fromIssue) {
            var opt = select.selectedOptions[0];
            var isCustom = select.value === '__custom';
            if (custom) {
                custom.hidden = !isCustom;
                if (isCustom && !fromIssue) { custom.focus(); }
            }
            if (opt && opt.hasAttribute('data-days') && issue && issue.value && due) {
                due.value = addDays(issue.value, parseInt(opt.getAttribute('data-days'), 10) || 0);
            }
        }

        select.addEventListener('change', function () { sync(false); });
        if (issue) { issue.addEventListener('change', function () { sync(true); }); }
    });

    /* ---------- 19. AI auto-fill ---------- */

    // When a receipt is chosen in the New claim popup, the browser turns it into page
    // images (pdf.js for PDFs, a resize for photos), POSTs them to the
    // extract endpoint, and fills the form. Fields the user already typed are
    // kept; "Read again" overwrites. Nothing is saved until the user submits.
    var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
    var pdfjsLoading = null;
    var MAX_PAGES = 4;
    var MAX_SIDE = 1600;

    function loadPdfJs() {
        if (window.pdfjsLib) { return Promise.resolve(window.pdfjsLib); }
        if (!pdfjsLoading) {
            pdfjsLoading = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = PDFJS + 'pdf.min.js';
                s.onload = function () {
                    window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
                    resolve(window.pdfjsLib);
                };
                s.onerror = function () { pdfjsLoading = null; reject(new Error('Could not load the PDF reader. Check the internet connection.')); };
                document.head.appendChild(s);
            });
        }
        return pdfjsLoading;
    }

    function canvasJpeg(canvas) { return canvas.toDataURL('image/jpeg', 0.85); }

    function imageToPages(blob) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(blob);
            var img = new Image();
            img.onload = function () {
                var scale = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
                var c = document.createElement('canvas');
                c.width = Math.round(img.naturalWidth * scale);
                c.height = Math.round(img.naturalHeight * scale);
                var g = c.getContext('2d');
                g.fillStyle = '#fff';
                g.fillRect(0, 0, c.width, c.height);
                g.drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                resolve([canvasJpeg(c)]);
            };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('This image could not be opened.')); };
            img.src = url;
        });
    }

    function pdfToPages(blob) {
        return Promise.all([loadPdfJs(), blob.arrayBuffer()]).then(function (r) {
            return r[0].getDocument({ data: r[1] }).promise;
        }).then(function (pdf) {
            var pages = [];
            var chain = Promise.resolve();
            for (var n = 1; n <= Math.min(pdf.numPages, MAX_PAGES); n++) {
                (function (num) {
                    chain = chain.then(function () { return pdf.getPage(num); }).then(function (page) {
                        var base = page.getViewport({ scale: 1 });
                        var vp = page.getViewport({ scale: Math.min(2.5, MAX_SIDE / Math.max(base.width, base.height)) });
                        var c = document.createElement('canvas');
                        c.width = Math.round(vp.width);
                        c.height = Math.round(vp.height);
                        var g = c.getContext('2d');
                        g.fillStyle = '#fff';
                        g.fillRect(0, 0, c.width, c.height);
                        return page.render({ canvasContext: g, viewport: vp }).promise.then(function () { pages.push(canvasJpeg(c)); });
                    });
                })(n);
            }
            return chain.then(function () { return pages; });
        });
    }

    function toPages(blob) {
        if (blob.type === 'application/pdf') { return pdfToPages(blob); }
        if (/^image\//.test(blob.type)) { return imageToPages(blob); }
        return Promise.reject(new Error('AI can read PDF, JPG, PNG or WebP files.'));
    }

    document.querySelectorAll('[data-ai-fill]').forEach(function (box) {
        var form = box.closest('form');
        var input = form.querySelector('[data-file-input]');
        var kind = box.getAttribute('data-ai-kind');
        var message = box.querySelector('[data-ai-message]');
        var icon = box.querySelector('[data-ai-icon]');
        var runBtn = box.querySelector('[data-ai-run]');
        var runLabel = box.querySelector('[data-ai-run-label]');
        var existing = box.getAttribute('data-ai-existing');
        var lastFile = null;
        var busy = false;

        function status(state, text) {
            box.setAttribute('data-state', state);
            message.textContent = text;
            icon.className = 'ai-fill-icon fa-solid ' + ({
                loading: 'fa-spinner fa-spin', ok: 'fa-circle-check', warn: 'fa-triangle-exclamation', error: 'fa-circle-exclamation'
            }[state] || 'fa-wand-magic-sparkles');
        }

        // Fill one field unless the user already changed it (force = "Read again").
        function put(name, value, force) {
            var el = form.elements[name];
            if (!el || value === '' || value === null || value === undefined) { return false; }
            if (el instanceof RadioNodeList) {
                var radio = Array.prototype.find.call(el, function (r) { return r.value === value; });
                if (!radio) { return false; }
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
                return true;
            }
            if (el.readOnly) { return false; }
            var untouched = el.value.trim() === '' || el.value === el.defaultValue
                || (el.tagName === 'SELECT' && el.selectedOptions[0] && el.selectedOptions[0].defaultSelected);
            if (!force && !untouched) { return false; }
            if (el.tagName === 'SELECT' && !Array.prototype.some.call(el.options, function (o) { return o.value === value; })) { return false; }

            el.value = el.hasAttribute('data-money') ? padMoney(String(value)) : String(value);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.classList.add('ai-filled');
            return true;
        }

        function fillClaim(f, force) {
            var n = 0;
            if (put('title', f.title || f.merchant, force)) { n++; }
            ['expense_date', 'amount', 'currency', 'category'].forEach(function (k) { if (put(k, f[k], force)) { n++; } });
            if (f.merchant && f.title && f.title.indexOf(f.merchant) === -1 && put('notes', 'Merchant: ' + f.merchant, false)) { n++; }
            return n;
        }

        function run(blob, force) {
            if (busy) { return; }
            busy = true;
            runBtn.disabled = true;
            status('loading', kind === 'claim' ? 'Reading the receipt...' : 'Reading the invoice...');

            toPages(blob).then(function (pages) {
                var data = new FormData();
                data.append('kind', kind);
                pages.forEach(function (p) { data.append('pages[]', p); });
                return fetch(box.getAttribute('data-ai-url'), {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken() }
                }).then(function (r) {
                    return r.json().catch(function () { throw new Error('Unexpected server response (HTTP ' + r.status + ').'); });
                });
            }).then(function (result) {
                if (!result.success) { throw new Error(result.message || 'AI could not read this file.'); }
                var f = result.data.fields || {};
                var filled = fillClaim(f, force);
                if (filled === 0) {
                    status('warn', 'AI found nothing new to fill. Please type the details.');
                } else {
                    status('ok', 'Filled ' + filled + ' field' + (filled === 1 ? '' : 's') + ' with ' + result.data.model + '. Please check every field.');
                }
            }).catch(function (err) {
                status('error', (err && err.message ? err.message : 'AI could not read this file.') + ' You can still type the details.');
            }).then(function () {
                busy = false;
                runBtn.disabled = false;
                runBtn.hidden = false;
                runLabel.textContent = 'Read again';
            });
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) { return; }
            lastFile = file;
            run(file, false);
        });

        runBtn.addEventListener('click', function () {
            if (lastFile) { run(lastFile, true); return; }
            if (!existing) { return; }
            fetch(existing, { credentials: 'same-origin' }).then(function (r) {
                if (!r.ok) { throw new Error('The saved file could not be opened.'); }
                return r.blob();
            }).then(function (blob) { lastFile = blob; run(blob, true); })
              .catch(function (err) { status('error', err.message); });
        });

        // A field the user edits is theirs again: drop the AI highlight.
        form.addEventListener('input', function (e) {
            if (e.isTrusted && e.target.classList) { e.target.classList.remove('ai-filled'); }
        });
    });
})();
