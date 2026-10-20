/**
 * ai-settings.js - behaviour for the AI Settings & Integration page.
 *
 *   1. Helpers           busy buttons, confirm dialog, result boxes, dates
 *   2. StatusBadge       IntegrationStatusBadge renderer
 *   3. Validation        tiny rule helpers shared by every form
 *   4. FormController    load / dirty tracking / reset / errors (one for each form)
 *   5. Tabs              page tabs + SOP tabs (ARIA tablist, arrow keys)
 *   6. Demo banner
 *   7. Company information
 *   8. AI assistant      SOP editor
 *   9. Telegram
 *  10. Google Calendar
 *  11. Start-up
 *
 * All server calls go through window.AiSettingsApi (ai-settings-api.js).
 * Nothing here logs form values; the bot token is cleared after it is sent.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-ai-settings]');
    if (!root || !window.AiSettingsApi) { return; }

    var Api = window.AiSettingsApi;

    /* ================================================== 1. Helpers === */

    function $(selector, context) { return (context || document).querySelector(selector); }
    function $all(selector, context) { return Array.prototype.slice.call((context || document).querySelectorAll(selector)); }

    function toast(message, type) {
        if (window.App && typeof window.App.toast === 'function') { window.App.toast(message, type); }
    }

    /** Uses the app's shared <dialog id="confirmDialog">. Resolves true on confirm. */
    function confirmAction(options) {
        var dialog = document.getElementById('confirmDialog');
        if (!dialog || typeof dialog.showModal !== 'function') {
            return Promise.resolve(window.confirm(options.message));
        }
        dialog.querySelector('[data-confirm-title]').textContent = options.title || 'Are you sure?';
        dialog.querySelector('[data-confirm-message]').textContent = options.message || '';
        dialog.querySelector('[data-confirm-button]').textContent = options.confirmLabel || 'Confirm';
        dialog.returnValue = '';
        dialog.showModal();

        return new Promise(function (resolve) {
            dialog.addEventListener('close', function onClose() {
                dialog.removeEventListener('close', onClose);
                resolve(dialog.returnValue === 'confirm');
            });
        });
    }

    /** Spinner + label inside a button while a request runs. */
    function setBusy(button, busy, label) {
        if (!button) { return; }
        if (busy) {
            if (button.dataset.busy === '1') { return; }
            button.dataset.busy = '1';
            button.dataset.idleHtml = button.innerHTML;
            button.dataset.wasDisabled = button.disabled ? '1' : '0';
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.classList.add('is-busy');
            button.innerHTML = '';
            var spin = document.createElement('i');
            spin.className = 'fa-solid fa-spinner fa-spin';
            spin.setAttribute('aria-hidden', 'true');
            button.appendChild(spin);
            button.appendChild(document.createTextNode(' ' + (label || 'Working…')));
        } else {
            if (button.dataset.busy !== '1') { return; }
            button.innerHTML = button.dataset.idleHtml || button.innerHTML;
            button.disabled = button.dataset.wasDisabled === '1';
            button.removeAttribute('aria-busy');
            button.classList.remove('is-busy');
            delete button.dataset.busy;
            delete button.dataset.idleHtml;
            delete button.dataset.wasDisabled;
        }
    }

    var RESULT_ICONS = {
        success: 'fa-solid fa-circle-check',
        error: 'fa-solid fa-circle-exclamation',
        warning: 'fa-solid fa-triangle-exclamation',
        info: 'fa-solid fa-circle-info',
        loading: 'fa-solid fa-spinner fa-spin'
    };

    /** Inline outcome box under an integration card. All text goes in as text, never HTML. */
    function showResult(box, type, title, text, items) {
        if (!box) { return; }
        box.innerHTML = '';
        box.hidden = false;
        box.className = 'ai-result flash flash--' + (type === 'loading' ? 'info' : type);
        box.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var icon = document.createElement('i');
        icon.className = RESULT_ICONS[type] || RESULT_ICONS.info;
        icon.setAttribute('aria-hidden', 'true');

        var body = document.createElement('div');
        var strong = document.createElement('strong');
        strong.textContent = title;
        body.appendChild(strong);
        if (text) {
            var span = document.createElement('span');
            span.textContent = text;
            body.appendChild(span);
        }
        if (items && items.length) {
            var list = document.createElement('ul');
            items.forEach(function (item) {
                var li = document.createElement('li');
                li.textContent = item;
                list.appendChild(li);
            });
            body.appendChild(list);
        }
        box.appendChild(icon);
        box.appendChild(body);
    }

    function clearResult(box) {
        if (box) { box.hidden = true; box.innerHTML = ''; }
    }

    function formatDateTime(value, timeZone) {
        if (!value) { return ''; }
        var date = new Date(value);
        if (isNaN(date.getTime())) { return ''; }
        try {
            return date.toLocaleString('en-MY', {
                day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
                timeZone: timeZone || undefined
            });
        } catch (e) {
            return date.toLocaleString();
        }
    }

    function setView(section, view) {
        $all('[data-view]', section).forEach(function (el) { el.hidden = el.getAttribute('data-view') !== view; });
    }

    function showLoadError(section, error) {
        var text = $('[data-view="error"] [data-error-text]', section);
        if (text) { text.textContent = (error && error.message) || 'Something went wrong while contacting the server.'; }
        setView(section, 'error');
    }

    function trimmed(value) { return String(value == null ? '' : value).trim(); }

    /* ============================================== 2. StatusBadge === */

    var StatusBadge = {
        STATES: {
            checking:     ['Checking…',    'fa-solid fa-spinner fa-spin'],
            connected:    ['Connected',    'fa-solid fa-circle-check'],
            disconnected: ['Disconnected', 'fa-regular fa-circle'],
            error:        ['Error',        'fa-solid fa-triangle-exclamation']
        },
        /** Unknown values show as Disconnected: never claim a connection by accident. */
        normalize: function (status) {
            return Object.prototype.hasOwnProperty.call(this.STATES, status) ? status : 'disconnected';
        },
        set: function (badge, status, suffix) {
            if (!badge) { return; }
            var key = this.normalize(status);
            var state = this.STATES[key];
            badge.className = 'status-badge status-badge--' + key;
            $('[data-status-icon]', badge).className = state[1];
            $('[data-status-label]', badge).textContent = state[0] + (suffix ? ' · ' + suffix : '');
        }
    };

    /* =============================================== 3. Validation === */

    var Rules = {
        required: function (label) {
            return function (value) { return trimmed(value) === '' ? label + ' is required.' : null; };
        },
        minLength: function (min, label) {
            return function (value) {
                var v = trimmed(value);
                return v !== '' && v.length < min ? label + ' needs at least ' + min + ' characters.' : null;
            };
        },
        maxLength: function (max, label) {
            return function (value) {
                return trimmed(value).length > max ? label + ' must be ' + max + ' characters or fewer.' : null;
            };
        },
        oneOf: function (allowed, message) {
            return function (value) {
                return trimmed(value) !== '' && allowed.indexOf(String(value)) === -1 ? message : null;
            };
        },
        pattern: function (regex, message) {
            return function (value) {
                var v = trimmed(value);
                return v !== '' && !regex.test(v) ? message : null;
            };
        }
    };

    /** @returns {Object} field -> first failing message */
    function validate(values, schema) {
        var errors = {};
        Object.keys(schema).forEach(function (field) {
            var checks = schema[field];
            for (var i = 0; i < checks.length; i++) {
                var message = checks[i](values[field], values);
                if (message) { errors[field] = message; break; }
            }
        });
        return errors;
    }

    function selectOptions(select) {
        return $all('option', select).map(function (o) { return o.value; }).filter(Boolean);
    }

    /* =========================================== 4. FormController === */

    var controllers = [];

    /**
     * Shared logic for every settings form: reads and writes named controls,
     * remembers the last loaded/saved values, shows "Unsaved changes", and
     * runs Reset (with a confirmation).
     *
     * options: tab (tab key for the dirty dot), extraDirty() (state outside
     * named fields, e.g. a chosen logo), onReset(values), onChange().
     */
    function FormController(form, options) {
        var self = this;
        this.form = form;
        this.options = options || {};
        this.baseline = null;
        this.baselineKey = '';
        this.busy = false;
        this.saveButton = $('[data-save]', form);
        this.resetButton = $('[data-reset]', form);
        this.indicator = $('[data-dirty-indicator]', form);
        this.savedMeta = $('[data-saved-meta]', form);
        this.tabDot = this.options.tab ? $('[data-tab-dirty="' + this.options.tab + '"]', root) : null;

        function onEdit(event) {
            // File inputs manage their own errors (they are validated on change).
            if (event.target && event.target.name && event.target.type !== 'file') { self.clearError(event.target.name); }
            self.refresh();
            if (self.options.onChange) { self.options.onChange(); }
        }
        form.addEventListener('input', onEdit);
        form.addEventListener('change', onEdit);

        if (this.resetButton) {
            this.resetButton.addEventListener('click', function () {
                confirmAction({
                    title: 'Reset unsaved changes?',
                    message: 'Your edits in this section will be discarded and the last saved values restored.',
                    confirmLabel: 'Reset changes'
                }).then(function (ok) { if (ok) { self.reset(); } });
            });
        }

        controllers.push(this);
    }

    FormController.prototype.fields = function () {
        return $all('[name]', this.form).filter(function (el) {
            return el.type !== 'file' && el.name !== 'csrf_token' && !el.hasAttribute('data-untracked');
        });
    };

    FormController.prototype.values = function () {
        var values = {};
        this.fields().forEach(function (el) {
            if (el.type === 'checkbox') {
                values[el.name] = el.checked;
            } else if (el.type === 'radio') {
                if (!(el.name in values)) { values[el.name] = ''; }
                if (el.checked) { values[el.name] = el.value; }
            } else {
                values[el.name] = el.value;
            }
        });
        return values;
    };

    FormController.prototype.setValues = function (values) {
        this.fields().forEach(function (el) {
            if (!(el.name in values)) { return; }
            var value = values[el.name];
            if (el.type === 'checkbox') {
                el.checked = value === true || value === 1 || value === '1' || value === 't' || value === 'true';
            } else if (el.type === 'radio') {
                el.checked = String(value) === el.value;
            } else {
                el.value = value == null ? '' : String(value);
            }
        });
    };

    /** Put server values in the form and treat them as the saved state. */
    FormController.prototype.load = function (values) {
        this.setValues(values);
        this.markSaved();
        this.clearErrors();
    };

    FormController.prototype.markSaved = function () {
        this.baseline = this.values();
        this.baselineKey = JSON.stringify(this.baseline);
        this.refresh();
    };

    FormController.prototype.isDirty = function () {
        if (this.baseline === null) { return false; }
        if (JSON.stringify(this.values()) !== this.baselineKey) { return true; }
        return !!(this.options.extraDirty && this.options.extraDirty());
    };

    FormController.prototype.refresh = function () {
        var dirty = this.isDirty();
        if (this.indicator) { this.indicator.hidden = !dirty; }
        if (this.tabDot) { this.tabDot.hidden = !dirty; }
        if (this.saveButton && this.saveButton.dataset.busy !== '1') { this.saveButton.disabled = !dirty || this.busy; }
        if (this.resetButton) { this.resetButton.disabled = !dirty || this.busy; }
        updateCounters(this.form);
    };

    FormController.prototype.reset = function () {
        if (this.baseline === null) { return; }
        var values = JSON.parse(this.baselineKey);
        this.setValues(values);
        this.clearErrors();
        if (this.options.onReset) { this.options.onReset(values); }
        this.refresh();
        if (this.options.onChange) { this.options.onChange(); }
        toast('Unsaved changes were reset.', 'info');
    };

    FormController.prototype.setBusy = function (busy, label) {
        this.busy = busy;
        setBusy(this.saveButton, busy, label || 'Saving…');
        this.refresh();
    };

    FormController.prototype.clearError = function (field) {
        var message = $('[data-error-for="' + field + '"]', this.form);
        if (message) { message.textContent = ''; }
        $all('[name="' + field + '"]', this.form).forEach(function (el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        });
    };

    FormController.prototype.clearErrors = function () {
        var self = this;
        $all('[data-error-for]', this.form).forEach(function (el) { self.clearError(el.getAttribute('data-error-for')); });
    };

    /** Shows messages next to fields and focuses the first invalid one. */
    FormController.prototype.showErrors = function (errors) {
        var form = this.form;
        var first = null;
        this.clearErrors();
        Object.keys(errors || {}).forEach(function (field) {
            var message = $('[data-error-for="' + field + '"]', form);
            if (message) { message.textContent = errors[field]; }
            $all('[name="' + field + '"]', form).forEach(function (el) {
                if (el.type !== 'hidden') {
                    el.classList.add('is-invalid');
                    el.setAttribute('aria-invalid', 'true');
                    if (!first) { first = el; }
                }
            });
            if (!first && message) { first = message; }
        });
        if (first && typeof first.focus === 'function') {
            if (first.tagName === 'P') { first.setAttribute('tabindex', '-1'); }
            first.focus({ preventScroll: false });
        }
    };

    FormController.prototype.setSavedMeta = function (text, isError) {
        if (!this.savedMeta) { return; }
        this.savedMeta.textContent = text || '';
        this.savedMeta.classList.toggle('is-error', !!isError);
    };

    /** Common handling for a rejected save. */
    FormController.prototype.saveFailed = function (error) {
        if (error && error.kind === 'validation') {
            this.showErrors(error.errors);
        }
        var message = (error && error.message) || 'Could not save. Please try again.';
        this.setSavedMeta('Not saved: ' + message, true);
        toast(message, 'error');
    };

    function savedMetaText(updatedAt, demo) {
        var when = formatDateTime(updatedAt);
        if (!when) { return demo ? 'Not saved yet (demo mode)' : 'Not saved yet'; }
        return 'Last saved ' + when + (demo ? ' · this browser only' : '');
    }

    function updateCounters(scope) {
        $all('[data-counter-for]', scope).forEach(function (counter) {
            var field = $('#' + counter.getAttribute('data-counter-for'), scope);
            if (!field) { return; }
            var max = parseInt(field.getAttribute('maxlength'), 10) || 0;
            var length = field.value.length;
            counter.textContent = max ? length + ' / ' + max : String(length);
            counter.classList.toggle('is-near', max > 0 && length > max * 0.9);
        });
    }

    window.addEventListener('beforeunload', function (event) {
        var dirty = controllers.some(function (c) { return c.isDirty(); });
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    /* ===================================================== 5. Tabs === */

    /**
     * Turns a set of role="tab" buttons into an accessible tablist.
     * getPanel(tab) returns the panel element for that tab.
     */
    function makeTabs(tabs, getPanel, onSelect) {
        function select(tab, focus) {
            tabs.forEach(function (t) {
                var active = t === tab;
                t.classList.toggle('active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
                t.setAttribute('tabindex', active ? '0' : '-1');
                var panel = getPanel(t);
                if (panel) { panel.hidden = !active; }
            });
            if (focus) { tab.focus(); }
            if (onSelect) { onSelect(tab); }
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { select(tab, false); });
            tab.addEventListener('keydown', function (event) {
                var next = null;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { next = tabs[(index + 1) % tabs.length]; }
                if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { next = tabs[(index - 1 + tabs.length) % tabs.length]; }
                if (event.key === 'Home') { next = tabs[0]; }
                if (event.key === 'End') { next = tabs[tabs.length - 1]; }
                if (next) {
                    event.preventDefault();
                    select(next, true);
                }
            });
        });

        return { select: select };
    }

    var pageTabs = $all('[role="tab"][data-tab]', root);
    var pageTabList = makeTabs(pageTabs, function (tab) {
        return document.getElementById(tab.getAttribute('aria-controls'));
    }, function (tab) {
        var key = tab.getAttribute('data-tab');
        if (window.history && history.replaceState) {
            history.replaceState(null, '', window.location.pathname + window.location.search + '#' + key);
        }
    });

    function selectPageTab(key) {
        var tab = pageTabs.filter(function (t) { return t.getAttribute('data-tab') === key; })[0];
        if (tab) { pageTabList.select(tab, false); }
    }

    /* ============================================== 6. Demo banner === */

    var SECTION_NAMES = {
        company: 'Company Information',
        assistant: 'AI Assistant Settings',
        telegram: 'Telegram',
        calendar: 'Google Calendar'
    };

    function updateDemoState() {
        var demo = Object.keys(SECTION_NAMES).filter(function (key) { return Api.mode(key) === 'demo'; });
        var banner = $('[data-demo-banner]', root);

        Object.keys(SECTION_NAMES).forEach(function (key) {
            var chip = $('[data-section="' + key + '"] [data-demo-chip]', root);
            if (chip) { chip.hidden = Api.mode(key) !== 'demo'; }
        });

        if (!banner) { return; }
        banner.hidden = demo.length === 0;
        if (demo.length) {
            var all = demo.length === Object.keys(SECTION_NAMES).length;
            $('[data-demo-banner-text]', banner).textContent =
                (all ? 'The server endpoints for this page are not available yet.'
                     : 'The server endpoints for ' + demo.map(function (k) { return SECTION_NAMES[k]; }).join(', ') + ' are not available yet.')
                + ' Company and assistant changes are kept in this browser only, and no Telegram bot or Google account is actually connected.';
        }
    }

    /* ====================================== 7. Company information === */

    var Company = (function () {
        var section = $('[data-section="company"]', root);
        var form = $('[data-form="company"]', section);
        var input = $('[data-logo-input]', form);
        var preview = $('[data-logo-preview]', form);
        var placeholder = $('[data-logo-placeholder]', form);
        var chooseLabel = $('[data-logo-choose-label]', form);
        var removeWrap = $('[data-logo-remove-wrap]', form);
        var removeBox = $('[data-logo-remove]', form);
        var fileName = $('[data-logo-file-name]', form);
        var emptyNote = $('[data-empty-note]', form);
        var category = $('#business_category', form);

        var ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
        var maxBytes = parseInt(input.getAttribute('data-max-bytes'), 10) || 2 * 1024 * 1024;

        var savedLogo = null;
        var pendingFile = null;
        var objectUrl = null;

        var ctrl = new FormController(form, {
            tab: 'company',
            extraDirty: function () { return pendingFile !== null; },
            onReset: function () { resetLogo(); },
            onChange: function () {}
        });

        function showLogo(src) {
            preview.hidden = !src;
            placeholder.hidden = !!src;
            if (src) { preview.src = src; } else { preview.removeAttribute('src'); }
            chooseLabel.textContent = src ? 'Replace image' : 'Choose image';
        }

        function dropObjectUrl() {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        }

        function resetLogo() {
            pendingFile = null;
            input.value = '';
            removeBox.checked = false;
            fileName.textContent = '';
            dropObjectUrl();
            showLogo(savedLogo);
            removeWrap.hidden = !savedLogo;
            ctrl.clearError('company_logo');
            ctrl.refresh();
        }

        function logoError(message) {
            pendingFile = null;
            input.value = '';
            dropObjectUrl();
            showLogo(removeBox.checked ? null : savedLogo);
            fileName.textContent = '';
            ctrl.showErrors({ company_logo: message });
            ctrl.refresh();
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            ctrl.clearError('company_logo');
            if (!file) { resetLogo(); return; }

            if (ALLOWED_TYPES.indexOf(file.type) === -1) {
                logoError('Please choose a JPG, PNG or WebP image.');
                return;
            }
            if (file.size > maxBytes) {
                logoError('That image is ' + (file.size / 1024 / 1024).toFixed(1) + ' MB. The limit is ' + Math.round(maxBytes / 1024 / 1024) + ' MB.');
                return;
            }

            // Make sure it really decodes as an image before accepting it.
            dropObjectUrl();
            var url = URL.createObjectURL(file);
            var probe = new Image();
            probe.onload = function () {
                objectUrl = url;
                pendingFile = file;
                removeBox.checked = false;
                showLogo(url);
                fileName.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB · not saved yet';
                ctrl.refresh();
            };
            probe.onerror = function () {
                URL.revokeObjectURL(url);
                logoError('That file could not be read as an image.');
            };
            probe.src = url;
        });

        removeBox.addEventListener('change', function () {
            if (removeBox.checked) {
                pendingFile = null;
                input.value = '';
                fileName.textContent = '';
                dropObjectUrl();
                showLogo(null);
            } else {
                showLogo(savedLogo);
            }
            ctrl.refresh();
        });

        function schema() {
            return {
                company_name: [Rules.required('Company name'), Rules.minLength(2, 'Company name'), Rules.maxLength(150, 'Company name')],
                business_category: [Rules.required('Business category'), Rules.oneOf(selectOptions(category), 'Choose a category from the list.')],
                company_background: [Rules.maxLength(3000, 'Company background')]
            };
        }

        function fill(profile, demo) {
            var p = profile || {};
            ctrl.load({
                company_name: p.company_name || '',
                business_category: p.business_category || '',
                company_background: p.company_background || '',
                remove_logo: false
            });
            savedLogo = p.company_logo_url || null;
            resetLogo();
            emptyNote.hidden = !!profile;
            ctrl.setSavedMeta(savedMetaText(p.updated_at, demo));
        }

        function load() {
            setView(section, 'loading');
            return Api.company.get().then(function (data) {
                fill(data.company_profile || null, !!data.demo);
                setView(section, 'ready');
            }).catch(function (error) {
                showLoadError(section, error);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var values = ctrl.values();
            var errors = validate(values, schema());
            if (Object.keys(errors).length) {
                ctrl.showErrors(errors);
                toast('Please fix the highlighted fields.', 'error');
                return;
            }

            var body = new FormData();
            ['company_name', 'business_category', 'company_background'].forEach(function (key) {
                body.append(key, trimmed(values[key]));
            });
            if (pendingFile) { body.append('company_logo', pendingFile, pendingFile.name); }
            if (removeBox.checked && !pendingFile) { body.append('remove_logo', '1'); }

            ctrl.setBusy(true);
            Api.company.save(body).then(function (data) {
                ctrl.busy = false;
                fill(data.company_profile, !!data.demo);
                if (data.logo_dropped) {
                    toast('Saved in this browser, but the logo was too large to keep in demo mode.', 'info');
                } else if (data.demo) {
                    toast(data.stored === false ? 'Demo mode: browser storage is unavailable, so nothing was kept.' : 'Saved in this browser only (demo mode).', 'info');
                } else {
                    toast('Company information saved.', 'success');
                }
            }).catch(function (error) {
                ctrl.saveFailed(error);
            }).then(function () {
                ctrl.setBusy(false);
            });
        });

        $('[data-retry]', section).addEventListener('click', function () { load().then(updateDemoState); });

        return {
            load: load,
            companyName: function () { return trimmed($('#company_name', form).value); }
        };
    })();

    /* ============================================ 8. AI assistant === */

    var Assistant = (function () {
        var section = $('[data-section="assistant"]', root);
        var form = $('[data-form="assistant"]', section);
        var output = $('[data-sop-output]', form);
        var combined = $('[data-sop-combined]', form);
        var count = $('[data-sop-count]', form);
        var toneRequired = $('[data-tone-required]', form);
        var switchInput = $('#is_enabled', form);
        var switchLabel = $('[data-switch-label]', form);
        var modelSelect = $('#ai_model', form);
        var apiKey = $('#api_key', form);
        var apiKeyProvider = $('[data-api-key-provider]', form);
        var apiKeyHint = $('[data-api-key-hint]', form);
        var temperature = $('#temperature', form);
        var temperatureValue = $('[data-temperature-value]', form);

        var MAX_INSTRUCTIONS = 20000;
        var DEFAULT_MODEL = modelSelect.options.length ? modelSelect.options[0].value : '';
        var KEY_PREFIX = { Anthropic: 'sk-ant-', DeepSeek: 'sk-' };

        /** What the server last said: whether a key is saved, and for which provider. */
        var keyState = { saved: false, provider: null };
        var PERSONALITIES = ['friendly', 'professional', 'warm', 'persuasive', 'custom'];

        /** Order and headings used inside the single system_instructions field. */
        var SOP = [
            { key: 'service', label: 'Customer Service SOP' },
            { key: 'booking', label: 'Booking SOP' },
            { key: 'sales',   label: 'Salesman SOP' }
        ];

        var TEMPLATES = {
            service: [
                '1. Apologise once and thank the customer for their patience.',
                '2. Ask for the order number and the phone number used for the order.',
                '3. Check the status and explain the next step and expected timing.',
                '4. Refunds or replacements: collect a photo of the issue, then hand over to staff.',
                '5. Close by asking if there is anything else we can help with.'
            ].join('\n'),
            booking: [
                '1. Ask for the service, preferred date and time, name and phone number.',
                '2. Only offer slots inside opening hours and at least 2 hours from now.',
                '3. Check availability before confirming. Never double-book.',
                '4. Repeat the booking details back and ask the customer to confirm.',
                '5. Cancellations: allowed up to 24 hours before the appointment.'
            ].join('\n'),
            sales: [
                '1. Ask what the customer needs before recommending anything.',
                '2. Recommend at most 2 options and explain the main benefit of each.',
                '3. Mention current promotions only if they apply to what the customer wants.',
                '4. Quote prices exactly as listed. Never invent discounts.',
                '5. End with a clear next step: order link, booking, or talk to staff.'
            ].join('\n')
        };

        var editors = {};
        SOP.forEach(function (s) { editors[s.key] = $('[data-sop="' + s.key + '"]', form); });

        function compose() {
            return SOP.map(function (s) {
                var text = editors[s.key].value.replace(/\s+$/, '').replace(/^\s*\n/, '');
                return text.trim() === '' ? '' : '### ' + s.label + '\n' + text;
            }).filter(Boolean).join('\n\n');
        }

        /**
         * Splits stored text back into the SOP boxes. Text that is not under a
         * known SOP heading (e.g. the old "General AI behavior" section) is
         * not shown, so it is dropped the next time the settings are saved.
         */
        function parse(text) {
            var parts = { service: [], booking: [], sales: [] };
            var current = null;
            String(text || '').replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
                var heading = /^#{2,3}\s*(.+?)\s*$/.exec(line);
                if (heading) {
                    var match = SOP.filter(function (s) { return s.label.toLowerCase() === heading[1].toLowerCase(); })[0];
                    if (match) { current = match.key; return; }
                    if (heading[1].toLowerCase() === 'general ai behavior') { current = null; return; }
                }
                if (current) { parts[current].push(line); }
            });
            var result = {};
            Object.keys(parts).forEach(function (key) { result[key] = parts[key].join('\n').trim(); });
            return result;
        }

        function syncInstructions() {
            var text = compose();
            output.value = text;
            combined.textContent = text || '(nothing yet)';
            count.textContent = text.length.toLocaleString() + ' / ' + MAX_INSTRUCTIONS.toLocaleString() + ' characters';
            count.classList.toggle('is-near', text.length > MAX_INSTRUCTIONS * 0.9);
            SOP.forEach(function (s) {
                var dot = $('[data-sop-filled="' + s.key + '"]', form);
                if (dot) { dot.hidden = editors[s.key].value.trim() === ''; }
            });
        }

        function fillEditors(text) {
            var parts = parse(text);
            SOP.forEach(function (s) { editors[s.key].value = parts[s.key]; });
            syncInstructions();
        }

        // Typing in a box updates the hidden field before the form's own
        // input listener compares it with the saved value.
        SOP.forEach(function (s) {
            editors[s.key].addEventListener('input', syncInstructions);
        });

        $all('[data-sop-template]', form).forEach(function (button) {
            button.addEventListener('click', function () {
                var key = button.getAttribute('data-sop-template');
                var editor = editors[key];
                var apply = function () {
                    editor.value = TEMPLATES[key];
                    editor.dispatchEvent(new Event('input', { bubbles: true }));
                    editor.focus();
                };
                if (editor.value.trim() === '') { apply(); return; }
                confirmAction({
                    title: 'Replace this section?',
                    message: 'The starter template will replace what is in "' + editor.getAttribute('data-sop-label') + '". You can still reset before saving.',
                    confirmLabel: 'Replace text'
                }).then(function (ok) { if (ok) { apply(); } });
            });
        });

        makeTabs($all('[data-sop-tab]', form), function (tab) {
            return document.getElementById(tab.getAttribute('aria-controls'));
        });

        function updateToneRequirement() {
            var custom = (ctrl.values().personality === 'custom');
            toneRequired.hidden = !custom;
            $('#tone_description', form).required = custom;
        }

        function updateSwitchLabel() {
            switchLabel.textContent = switchInput.checked ? 'Enabled' : 'Disabled';
        }

        function providerOf(modelId) {
            var option = $all('option', modelSelect).filter(function (o) { return o.value === modelId; })[0];
            return option ? option.getAttribute('data-provider') : null;
        }

        function clearKey() {
            apiKey.value = '';
            apiKey.type = 'password';
            var toggle = $('[data-toggle-password="api_key"]', form);
            if (toggle) {
                toggle.setAttribute('aria-label', 'Show key');
                var icon = $('i', toggle);
                if (icon) { icon.className = 'fa-regular fa-eye'; }
            }
        }

        /** Label, placeholder and hint follow the chosen model's provider and whether a key is saved. */
        function updateModelFields() {
            var provider = providerOf(modelSelect.value) || 'Anthropic';
            var keyStillValid = keyState.saved && keyState.provider === provider;
            apiKeyProvider.textContent = '(' + provider + ')';
            apiKey.placeholder = keyStillValid
                ? 'Saved securely. Leave blank to keep the current key'
                : (KEY_PREFIX[provider] || '') + '...';
            apiKeyHint.textContent = keyStillValid
                ? 'A ' + provider + ' key is saved (encrypted). Enter a new one only to replace it.'
                : (keyState.saved
                    ? 'The saved key is for ' + keyState.provider + '. Enter your ' + provider + ' secret key for this model.'
                    : 'Your ' + provider + ' API key. Hidden by default, sent to the server once and stored encrypted. It is never shown again.');
            temperatureValue.textContent = Number(temperature.value).toFixed(1);
        }

        var ctrl = new FormController(form, {
            tab: 'assistant',
            onReset: function (values) {
                fillEditors(values.system_instructions);
            },
            onChange: function () {
                updateToneRequirement();
                updateSwitchLabel();
                updateModelFields();
            }
        });

        // The slider value is shown live while dragging.
        temperature.addEventListener('input', function () {
            temperatureValue.textContent = Number(temperature.value).toFixed(1);
        });


        /* ---- load / save ------------------------------------------------ */

        function schema() {
            return {
                assistant_name: [Rules.required('Assistant name'), Rules.maxLength(60, 'Assistant name')],
                personality: [Rules.required('Personality'), Rules.oneOf(PERSONALITIES, 'Choose one of the personalities.')],
                tone_description: [
                    function (value, all) {
                        return all.personality === 'custom' && trimmed(value).length < 10
                            ? 'Describe the custom tone in at least 10 characters.' : null;
                    },
                    Rules.maxLength(500, 'Tone description')
                ],
                system_instructions: [Rules.maxLength(MAX_INSTRUCTIONS, 'System instructions')],
                ai_model: [Rules.required('AI model'), Rules.oneOf(selectOptions(modelSelect), 'Choose an AI model from the list.')],
                temperature: [function (value) {
                    var n = Number(value);
                    return value === '' || isNaN(n) || n < 0 || n > 1 ? 'Temperature must be from 0 to 1.' : null;
                }],
                api_key: [function (value, all) {
                    var key = trimmed(value);
                    var provider = providerOf(all.ai_model);
                    if (key === '') {
                        return keyState.saved && provider && keyState.provider && provider !== keyState.provider
                            ? 'You switched to a ' + provider + ' model. Enter the ' + provider + ' secret key.' : null;
                    }
                    if (key.length < 20 || /\s/.test(key)) { return 'That does not look like a secret key. Copy it again from the provider.'; }
                    if (provider && KEY_PREFIX[provider] && key.indexOf(KEY_PREFIX[provider]) !== 0) {
                        return provider + ' keys start with "' + KEY_PREFIX[provider] + '". Check you copied the key for ' + provider + '.';
                    }
                    return null;
                }]
            };
        }

        function fill(settings, demo) {
            var s = settings || {};
            var model = providerOf(s.ai_model) ? s.ai_model : DEFAULT_MODEL;
            var temp = typeof s.temperature === 'number' && s.temperature >= 0 && s.temperature <= 1 ? s.temperature : 0.7;
            keyState = { saved: !!s.has_api_key, provider: s.has_api_key ? providerOf(model) : null };
            clearKey();
            fillEditors(s.system_instructions || '');
            ctrl.load({
                assistant_name: s.assistant_name || '',
                personality: PERSONALITIES.indexOf(s.personality) === -1 ? 'friendly' : s.personality,
                tone_description: s.tone_description || '',
                is_enabled: s.is_enabled === undefined || s.is_enabled === null ? true : s.is_enabled,
                system_instructions: output.value,
                ai_model: model,
                temperature: String(Math.round(temp * 10) / 10),
                api_key: ''
            });
            ctrl.setSavedMeta(savedMetaText(s.updated_at, demo));
            updateToneRequirement();
            updateSwitchLabel();
            updateModelFields();
        }

        function load() {
            setView(section, 'loading');
            return Api.assistant.get().then(function (data) {
                fill(data.ai_settings || null, !!data.demo);
                setView(section, 'ready');
            }).catch(function (error) {
                showLoadError(section, error);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            syncInstructions();
            var values = ctrl.values();
            var errors = validate(values, schema());
            if (Object.keys(errors).length) {
                ctrl.showErrors(errors);
                toast('Please fix the highlighted fields.', 'error');
                return;
            }

            ctrl.setBusy(true);
            var payload = {
                assistant_name: trimmed(values.assistant_name),
                personality: values.personality,
                tone_description: trimmed(values.tone_description),
                system_instructions: values.system_instructions,
                is_enabled: !!values.is_enabled,
                ai_model: values.ai_model,
                temperature: values.temperature
            };
            // Only sent when typed; otherwise the server keeps the saved key.
            if (trimmed(values.api_key) !== '') { payload.api_key = trimmed(values.api_key); }

            Api.assistant.save(payload).then(function (data) {
                ctrl.busy = false;
                fill(data.ai_settings, !!data.demo);
                toast(data.demo
                    ? (data.stored === false ? 'Demo mode: browser storage is unavailable, so nothing was kept.' : 'Saved in this browser only (demo mode).')
                    : 'AI assistant settings saved.', data.demo ? 'info' : 'success');
            }).catch(function (error) {
                ctrl.saveFailed(error);
            }).then(function () {
                ctrl.setBusy(false);
            });
        });

        $('[data-retry]', section).addEventListener('click', function () { load().then(updateDemoState); });

        return { load: load };
    })();

    /* ================================================ 9. Telegram === */

    var Telegram = (function () {
        var section = $('[data-section="telegram"]', root);
        var form = $('[data-form="telegram"]', section);
        var badge = $('[data-status-badge="telegram"]', form);
        var result = $('[data-result]', form);
        var token = $('#bot_token', form);
        var tokenRequired = $('[data-token-required]', form);
        var tokenHint = $('[data-token-hint]', form);
        var connectLabel = $('[data-connect-label]', form);
        var buttons = {
            test: $('[data-action="test"]', form),
            connect: $('[data-action="connect"]', form),
            disconnect: $('[data-action="disconnect"]', form)
        };
        var meta = { token: $('[data-meta="token"]', form), updated: $('[data-meta="updated"]', form) };

        // Errors are shown with the same helpers as the other forms; this
        // controller has no Save/Reset buttons, so it never reports dirty.
        var errorsUi = new FormController(form, {});
        errorsUi.isDirty = function () { return false; };

        var state = { status: 'disconnected', hasToken: false, demo: false };

        var USERNAME = /^[A-Za-z][A-Za-z0-9_]{1,29}bot$/i;
        var CHAT_ID = /^-?\d{5,20}$/;
        var TOKEN = /^\d{5,12}:[A-Za-z0-9_-]{30,64}$/;

        function clearToken() {
            token.value = '';
            token.type = 'password';
            var toggle = $('[data-toggle-password="bot_token"]', form);
            if (toggle) {
                toggle.setAttribute('aria-label', 'Show token');
                var icon = $('i', toggle);
                if (icon) { icon.className = 'fa-regular fa-eye'; }
            }
        }

        /** keepInputs: after a failed request, refresh the status but leave what the user typed. */
        function render(t, demo, keepInputs) {
            var data = t || {};
            state.status = StatusBadge.normalize(data.connection_status);
            state.hasToken = !!data.has_bot_token;
            state.demo = !!demo;

            if (!keepInputs) {
                $('#bot_username', form).value = data.bot_username ? '@' + String(data.bot_username).replace(/^@/, '') : '';
                $('#staff_telegram_chat_id', form).value = data.staff_telegram_chat_id || '';
                clearToken();
            }

            StatusBadge.set(badge, state.status);
            tokenRequired.hidden = state.hasToken;
            token.placeholder = state.hasToken
                ? 'Stored securely. Leave blank to keep the current token'
                : '123456789:AAH...from @BotFather';
            tokenHint.textContent = state.hasToken
                ? 'A token is already stored (encrypted). Enter a new one only to replace it.'
                : 'Hidden by default. It is sent to the server once, verified with Telegram, and stored encrypted. It is never shown again.';

            meta.token.textContent = state.hasToken ? 'Yes, encrypted on the server' : (state.demo ? 'None (demo mode never stores tokens)' : 'None');
            meta.updated.textContent = formatDateTime(data.updated_at) || '—';

            connectLabel.textContent = state.status === 'connected' ? 'Update connection' : 'Connect';
            buttons.disconnect.disabled = state.status === 'disconnected' && !state.hasToken;

            if (state.status === 'error') {
                showResult(result, 'error', 'Telegram connection has a problem',
                    data.last_error || 'The server could not reach your bot. Check the token and try connecting again.');
            }
        }

        function collect() {
            return {
                bot_username: trimmed($('#bot_username', form).value).replace(/^@/, ''),
                staff_telegram_chat_id: trimmed($('#staff_telegram_chat_id', form).value),
                bot_token: trimmed(token.value)
            };
        }

        function check(values) {
            var errors = validate(values, {
                bot_username: [Rules.required('Bot username'), Rules.pattern(USERNAME, 'Bot usernames are 5–32 letters, numbers or underscores and end in "bot".')],
                staff_telegram_chat_id: [Rules.pattern(CHAT_ID, 'Chat IDs are numbers only (groups start with a minus sign).')],
                bot_token: [
                    function (value) { return !state.hasToken && trimmed(value) === '' ? 'Bot token is required.' : null; },
                    Rules.pattern(TOKEN, 'That doesn\'t look like a bot token. It looks like 123456789:AA… (from @BotFather).')
                ]
            });
            if (Object.keys(errors).length) {
                errorsUi.showErrors(errors);
                return false;
            }
            errorsUi.clearErrors();
            return true;
        }

        /** Only include the token when one was typed; the server keeps the stored one otherwise. */
        function payload(values) {
            var body = { bot_username: values.bot_username, staff_telegram_chat_id: values.staff_telegram_chat_id };
            if (values.bot_token) { body.bot_token = values.bot_token; }
            return body;
        }

        function failed(error, title) {
            if (error.kind === 'validation') { errorsUi.showErrors(error.errors); }
            if (error.data && error.data.telegram) { render(error.data.telegram, state.demo, true); }
            showResult(result, error.kind === 'demo' ? 'warning' : 'error', error.kind === 'demo' ? 'Not connected (demo mode)' : title, error.message);
        }

        buttons.test.addEventListener('click', function () {
            var values = collect();
            if (!check(values)) { return; }
            setBusy(buttons.test, true, 'Testing…');
            showResult(result, 'loading', 'Testing connection…', 'Asking Telegram whether this bot token works.');

            Api.telegram.test(payload(values)).then(function (data) {
                if (data.telegram) { render(data.telegram, !!data.demo, true); }
                if (data.demo) {
                    showResult(result, 'warning', 'Not tested (demo mode)', data.message);
                } else if (data.ok) {
                    // The token stays in the field so "Connect" can send it next;
                    // it is cleared as soon as the connection is saved.
                    showResult(result, 'success', 'Connection works',
                        data.message || ('Telegram recognised ' + (data.bot_username ? '@' + data.bot_username : 'your bot') + '. Click Connect to save it.'));
                } else {
                    showResult(result, 'error', 'Connection failed', data.message || 'Telegram rejected the token or chat ID.');
                }
            }).catch(function (error) {
                failed(error, 'Connection test failed');
            }).then(function () {
                setBusy(buttons.test, false);
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var values = collect();
            if (!check(values)) { return; }
            setBusy(buttons.connect, true, 'Connecting…');
            showResult(result, 'loading', 'Connecting…', 'Verifying the bot with Telegram and saving it securely.');

            Api.telegram.connect(payload(values)).then(function (data) {
                render(data.telegram, !!data.demo);
                if (state.status === 'connected') {
                    showResult(result, 'success', 'Telegram connected', 'Customers can now message ' + $('#bot_username', form).value
                        + ($('#staff_telegram_chat_id', form).value.trim() ? ' and staff alerts go to your staff chat.' : '.'));
                    toast('Telegram connected.', 'success');
                } else if (state.status !== 'error') {
                    showResult(result, 'warning', 'Saved, but not connected', 'The server did not confirm the connection. Try “Test connection”.');
                }
            }).catch(function (error) {
                failed(error, 'Could not connect');
            }).then(function () {
                setBusy(buttons.connect, false);
            });
        });

        buttons.disconnect.addEventListener('click', function () {
            confirmAction({
                title: 'Disconnect Telegram?',
                message: 'The assistant will stop replying on Telegram and staff alerts will stop. The stored bot token will be deleted. You can connect again later.',
                confirmLabel: 'Disconnect'
            }).then(function (ok) {
                if (!ok) { return; }
                setBusy(buttons.disconnect, true, 'Disconnecting…');
                Api.telegram.disconnect().then(function (data) {
                    setBusy(buttons.disconnect, false);
                    render(data.telegram, !!data.demo);
                    showResult(result, 'success', 'Telegram disconnected', data.demo ? 'Demo mode: there was nothing connected.' : 'The bot token was removed from the server.');
                }).catch(function (error) {
                    setBusy(buttons.disconnect, false);
                    failed(error, 'Could not disconnect');
                });
            });
        });

        function load() {
            setView(section, 'loading');
            StatusBadge.set(badge, 'checking');
            return Api.telegram.get().then(function (data) {
                clearResult(result);
                render(data.telegram, !!data.demo);
                setView(section, 'ready');
            }).catch(function (error) {
                showLoadError(section, error);
            });
        }

        $('[data-retry]', section).addEventListener('click', function () { load().then(updateDemoState); });

        return { load: load };
    })();

    /* ========================================= 10. Google Calendar === */

    var Calendar = (function () {
        var section = $('[data-section="calendar"]', root);
        var form = $('[data-form="calendar"]', section);
        var badge = $('[data-status-badge="calendar"]', form);
        var result = $('[data-result]', form);
        var email = $('[data-gcal-email]', form);
        var accountHint = $('[data-gcal-account-hint]', form);
        var oauthLabel = $('[data-oauth-label]', form);
        var calendarSelect = $('#calendar_id', form);
        var calendarHint = $('[data-calendar-hint]', form);
        var timezoneSelect = $('#timezone', form);
        var durationSelect = $('#default_appointment_duration_minutes', form);
        var buttons = {
            oauth: $('[data-action="oauth"]', form),
            refresh: $('[data-action="refresh-calendars"]', form),
            test: $('[data-action="test"]', form),
            disconnect: $('[data-action="disconnect"]', form)
        };

        var DURATIONS = ['15', '30', '45', '60', '90'];
        var state = { status: 'disconnected', demo: false, saved: null };

        var ctrl = new FormController(form, { tab: 'calendar' });

        function option(value, label) {
            var o = document.createElement('option');
            o.value = value;
            o.textContent = label;
            return o;
        }

        function setCalendarOptions(list, placeholder) {
            calendarSelect.innerHTML = '';
            if (placeholder) { calendarSelect.appendChild(option('', placeholder)); }
            (list || []).forEach(function (c) {
                calendarSelect.appendChild(option(c.id, (c.summary || c.id) + (c.primary ? ' (primary)' : '')));
            });
        }

        function ensureTimezone(zone) {
            if (zone && !$all('option', timezoneSelect).some(function (o) { return o.value === zone; })) {
                timezoneSelect.appendChild(option(zone, zone));
            }
        }

        function loadCalendars() {
            ctrl.clearError('calendar_id');
            if (state.status !== 'connected') {
                setCalendarOptions([], 'Connect an account first');
                calendarSelect.disabled = true;
                buttons.refresh.disabled = true;
                calendarHint.textContent = 'Appointments are added to this calendar.';
                return Promise.resolve();
            }

            calendarSelect.disabled = true;
            buttons.refresh.disabled = true;
            setCalendarOptions([], 'Loading calendars…');

            return Api.calendar.calendars().then(function (data) {
                var list = data.calendars || [];
                var savedId = state.saved && state.saved.calendar_id;
                if (savedId && !list.some(function (c) { return c.id === savedId; })) {
                    list = list.concat([{ id: savedId, summary: 'Saved calendar (no longer listed)' }]);
                }
                setCalendarOptions(list, list.length ? 'Choose a calendar' : 'No calendars found');
                calendarSelect.disabled = list.length === 0;
                calendarHint.textContent = list.length
                    ? 'Appointments are added to this calendar.'
                    : 'This Google account has no calendars you can write to.';
            }).catch(function (error) {
                var savedId = state.saved && state.saved.calendar_id;
                setCalendarOptions(savedId ? [{ id: savedId, summary: 'Saved calendar' }] : [], savedId ? null : 'Calendars unavailable');
                calendarSelect.disabled = !savedId;
                errorText('calendar_id', 'Could not load your calendars: ' + error.message);
            }).then(function () {
                buttons.refresh.disabled = false;
            });
        }

        function errorText(field, message) {
            var p = $('[data-error-for="' + field + '"]', form);
            if (p) { p.textContent = message; }
        }

        function render(g, demo) {
            var data = g || {};
            state.status = StatusBadge.normalize(data.connection_status);
            state.demo = !!demo;
            state.saved = data;

            StatusBadge.set(badge, state.status);
            var connected = state.status === 'connected';
            email.textContent = connected && data.google_account_email ? data.google_account_email : 'No Google account connected';
            accountHint.textContent = connected
                ? 'Signed in with Google. The access is stored on the server, never in this page.'
                : (data.oauth_configured === false && !state.demo
                    ? 'Google sign-in is not set up on the server yet: add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env.'
                    : 'You\'ll sign in on Google\'s own page. This app never sees your Google password.');
            oauthLabel.textContent = connected ? 'Switch account' : 'Connect Google Account';
            buttons.oauth.classList.toggle('primary-btn', !connected);
            buttons.oauth.classList.toggle('secondary-btn', connected);
            buttons.test.disabled = !connected;
            buttons.disconnect.disabled = state.status === 'disconnected';

            if (state.status === 'error') {
                showResult(result, 'error', 'Google Calendar needs attention',
                    data.last_error || 'Google stopped accepting the saved access. Connect the account again.');
            }
        }

        function values(data) {
            var duration = String(data.default_appointment_duration_minutes || 30);
            var zone = data.timezone || 'Asia/Kuala_Lumpur';
            ensureTimezone(zone);
            return {
                calendar_id: data.calendar_id || '',
                default_appointment_duration_minutes: DURATIONS.indexOf(duration) === -1 ? '30' : duration,
                timezone: zone
            };
        }

        function fill(g, demo) {
            render(g, demo);
            return loadCalendars().then(function () {
                ctrl.load(values(g || {}));
                ctrl.setSavedMeta(savedMetaText((g || {}).updated_at, demo));
            });
        }

        function load() {
            setView(section, 'loading');
            StatusBadge.set(badge, 'checking');
            return Api.calendar.get().then(function (data) {
                clearResult(result);
                setView(section, 'ready');
                return fill(data.google_calendar, !!data.demo);
            }).catch(function (error) {
                showLoadError(section, error);
            });
        }

        function schema() {
            return {
                calendar_id: [function (value) {
                    return state.status === 'connected' && trimmed(value) === '' ? 'Choose the calendar for new appointments.' : null;
                }],
                default_appointment_duration_minutes: [Rules.required('Duration'), Rules.oneOf(DURATIONS, 'Choose one of the listed durations.')],
                timezone: [Rules.required('Timezone'), Rules.oneOf(selectOptions(timezoneSelect), 'Choose a timezone from the list.')]
            };
        }

        buttons.oauth.addEventListener('click', function () {
            if (state.demo) {
                showResult(result, 'warning', 'Not connected (demo mode)',
                    'Google sign-in needs the server\'s OAuth endpoint, which is not available yet. No account was connected.');
                return;
            }
            setBusy(buttons.oauth, true, 'Opening Google…');
            Api.calendar.oauthStart().then(function (data) {
                var url = String(data.auth_url || '');
                if (!/^https:\/\/accounts\.google\.com\//.test(url)) {
                    throw new Api.ApiError('bad_response', 'The server did not return a Google sign-in link.');
                }
                window.location.assign(url);
            }).catch(function (error) {
                setBusy(buttons.oauth, false);
                showResult(result, error.kind === 'demo' ? 'warning' : 'error', 'Could not start Google sign-in', error.message);
            });
        });

        buttons.refresh.addEventListener('click', function () {
            var keep = calendarSelect.value;
            loadCalendars().then(function () {
                calendarSelect.value = keep;
                ctrl.refresh();
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var v = ctrl.values();
            var errors = validate(v, schema());
            if (Object.keys(errors).length) {
                ctrl.showErrors(errors);
                toast('Please fix the highlighted fields.', 'error');
                return;
            }
            ctrl.setBusy(true);
            Api.calendar.save({
                calendar_id: v.calendar_id,
                default_appointment_duration_minutes: v.default_appointment_duration_minutes,
                timezone: v.timezone
            }).then(function (data) {
                ctrl.busy = false;
                return fill(data.google_calendar, !!data.demo).then(function () {
                    toast(data.demo ? 'Duration and timezone saved in this browser only (demo mode).' : 'Google Calendar settings saved.', data.demo ? 'info' : 'success');
                });
            }).catch(function (error) {
                ctrl.saveFailed(error);
            }).then(function () {
                ctrl.setBusy(false);
            });
        });

        buttons.test.addEventListener('click', function () {
            var v = ctrl.values();
            setBusy(buttons.test, true, 'Checking…');
            showResult(result, 'loading', 'Checking availability…', 'Looking for free slots in the selected calendar.');
            Api.calendar.testAvailability({
                calendar_id: v.calendar_id,
                default_appointment_duration_minutes: v.default_appointment_duration_minutes,
                timezone: v.timezone
            }).then(function (data) {
                var slots = (data.slots || []).slice(0, 5).map(function (slot) {
                    return formatDateTime(slot.start, v.timezone) + (slot.end ? ' – ' + formatDateTime(slot.end, v.timezone).replace(/^.*,\s*/, '') : '');
                });
                if (data.available) {
                    showResult(result, 'success', 'Calendar is reachable', data.message || 'These are the next free slots:', slots);
                } else {
                    showResult(result, 'warning', 'No free slots found', data.message || 'The calendar was reached, but no free slot matched.');
                }
            }).catch(function (error) {
                showResult(result, error.kind === 'demo' ? 'warning' : 'error', 'Availability test failed', error.message);
            }).then(function () {
                setBusy(buttons.test, false);
            });
        });

        buttons.disconnect.addEventListener('click', function () {
            confirmAction({
                title: 'Disconnect Google Calendar?',
                message: 'New bookings will no longer be added to Google Calendar and the stored Google access will be removed. Appointment records already saved in this app are kept.',
                confirmLabel: 'Disconnect'
            }).then(function (ok) {
                if (!ok) { return; }
                setBusy(buttons.disconnect, true, 'Disconnecting…');
                Api.calendar.disconnect().then(function (data) {
                    setBusy(buttons.disconnect, false);
                    return fill(data.google_calendar, !!data.demo).then(function () {
                        showResult(result, 'success', 'Google Calendar disconnected', 'Existing appointments were kept.');
                    });
                }).catch(function (error) {
                    setBusy(buttons.disconnect, false);
                    showResult(result, 'error', 'Could not disconnect', error.message);
                });
            });
        });

        /** After Google sends the user back: trust the server status, not the URL. */
        function handleOAuthReturn(outcome, message) {
            selectPageTab('calendar');
            if (outcome === 'connected') {
                if (state.status === 'connected') {
                    showResult(result, 'success', 'Google Calendar connected', 'Choose the calendar for appointments, then save.');
                    toast('Google Calendar connected.', 'success');
                } else {
                    showResult(result, 'warning', 'Not confirmed', 'Google sign-in finished, but the server has not confirmed the connection. Please try again.');
                }
            } else {
                showResult(result, 'error', 'Google sign-in did not complete', message || 'The connection was cancelled or refused.');
            }
        }

        $('[data-retry]', section).addEventListener('click', function () { load().then(updateDemoState); });

        return { load: load, handleOAuthReturn: handleOAuthReturn };
    })();

    /* ================================================ 11. Start-up === */

    var params = new URLSearchParams(window.location.search);
    var oauthOutcome = params.get('gcal');
    var oauthMessage = params.get('gcal_message');

    if (oauthOutcome && window.history && history.replaceState) {
        params.delete('gcal');
        params.delete('gcal_message');
        var query = params.toString();
        history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + '#calendar');
    }

    var initialTab = (window.location.hash || '').replace('#', '');
    selectPageTab(oauthOutcome ? 'calendar' : (SECTION_NAMES[initialTab] ? initialTab : 'company'));

    // A link to #telegram etc. on this same page switches the tab too.
    window.addEventListener('hashchange', function () {
        var key = (window.location.hash || '').replace('#', '');
        if (SECTION_NAMES[key]) { selectPageTab(key); }
    });

    Promise.all([Company.load(), Assistant.load(), Telegram.load(), Calendar.load()]).then(function () {
        updateDemoState();
        if (oauthOutcome === 'connected' || oauthOutcome === 'error') {
            Calendar.handleOAuthReturn(oauthOutcome, oauthMessage);
        }
    });
})();
