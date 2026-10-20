/* =====================================
   SMART SME - APP SCRIPT
   app.js - shared behaviour for every page.

   1. Toasts            window.App.toast(message, type)
   2. Sidebar           mobile open/close + collapsible groups
   3. Profile menu      topbar dropdown
   4. Password reveal   <button data-toggle-password="inputId">
   5. AJAX forms        <form data-ajax> (falls back to a normal post)
   6. Upload picker     [data-file-input] + [data-file-label]
   7. Chat demo         [data-chat] composer (no backend yet)
   8. Confirm dialog    <form data-confirm="Message" data-confirm-title data-confirm-ok>
   9. Filter forms      <form data-auto-submit> submits when a select changes
  10. Image picker      [data-image-picker] preview before upload
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

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-nav-toggle]')) {
            app && app.classList.toggle('nav-open');
        } else if (e.target.closest('[data-nav-close]')) {
            app && app.classList.remove('nav-open');
        }

        var groupBtn = e.target.closest('[data-nav-group]');
        if (groupBtn) {
            var group = groupBtn.closest('.nav-group');
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

    /* ---------- 7. Chat demo ---------- */

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

        function send(text) {
            text = text.trim();
            if (!text) { return; }
            bubble(text, 'me');
            input.value = '';
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
})();
