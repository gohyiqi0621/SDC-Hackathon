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
  11. Live list filter  <input data-filter-list="#list"> hides [data-filter-item] rows
  12. Field errors      a field's error clears as soon as it is edited
  13. Money inputs      <input data-money> shows 10,000.00 while typing
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
        var label = document.querySelector('[data-file-label]');
        var drop = input.closest('[data-upload-drop]');

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (label) { label.textContent = file ? file.name : 'Choose a PDF, PNG or JPG'; }
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

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) { return; }
        if (form.dataset.confirmed === '1') { return; }

        var message = form.getAttribute('data-confirm');

        // No <dialog> support: fall back to the browser's own prompt.
        if (!dialog || typeof dialog.showModal !== 'function') {
            if (!window.confirm(message)) { e.preventDefault(); }
            return;
        }

        e.preventDefault();
        dialog.querySelector('[data-confirm-title]').textContent = form.getAttribute('data-confirm-title') || 'Are you sure?';
        dialog.querySelector('[data-confirm-message]').textContent = message;
        dialog.querySelector('[data-confirm-button]').textContent = form.getAttribute('data-confirm-ok') || 'Confirm';
        dialog.returnValue = '';
        dialog.showModal();

        dialog.addEventListener('close', function onClose() {
            dialog.removeEventListener('close', onClose);
            if (dialog.returnValue !== 'confirm') { return; }

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

    /* ---------- 11. Live list filter ---------- */

    document.querySelectorAll('[data-filter-list]').forEach(function (input) {
        var list = document.querySelector(input.getAttribute('data-filter-list'));
        if (!list) { return; }
        var empty = list.parentNode.querySelector('[data-filter-empty]');

        input.addEventListener('input', function () {
            var term = input.value.trim().toLowerCase();
            var shown = 0;
            list.querySelectorAll('[data-filter-item]').forEach(function (item) {
                var match = term === '' || (item.getAttribute('data-filter-item') || '').indexOf(term) !== -1;
                item.hidden = !match;
                if (match) { shown++; }
            });
            if (empty) { empty.hidden = shown > 0; }
        });
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

    document.querySelectorAll('input[data-money]').forEach(function (input) {
        if (input.value !== '') { input.value = formatMoney(input.value); }

        input.addEventListener('input', function () {
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

        input.addEventListener('blur', function () {
            if (input.value === '') { return; }
            var parts = formatMoney(input.value).split('.');
            input.value = (parts[0] || '0') + '.' + ((parts[1] || '') + '00').slice(0, 2);
        });
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
})();
