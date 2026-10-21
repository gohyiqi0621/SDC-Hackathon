/**
 * ai-settings.js - behaviour for the AI Settings & Integration page.
 *
 *   1. Helpers           busy buttons, confirm dialog, result boxes, dates
 *   2. StatusBadge       IntegrationStatusBadge renderer
 *   3. Validation        tiny rule helpers shared by every form
 *   4. FormController    load / dirty tracking / reset / errors (one for each form)
 *   5. Tabs              page tabs + SOP tabs (ARIA tablist, arrow keys)
 *   6. Section names
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
        if (!when) { return 'Not saved yet'; }
        return 'Last saved ' + when;
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

    /* ============================================== 6. Section names === */

    var SECTION_NAMES = {
        company: 'Company Information',
        assistant: 'AI Assistant Settings',
        telegram: 'Telegram',
        calendar: 'Google Calendar'
    };

    /* ====================================== 7. Company information === */

    var Company = (function () {
        var section = $('[data-section="company"]', root);
        var form = $('[data-form="company"]', section);
        var emptyNote = $('[data-empty-note]', form);
        var category = $('#business_category', form);

        var ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
        var images = [];

        var ctrl = new FormController(form, {
            tab: 'company',
            extraDirty: function () { return images.some(function (img) { return img.pending() !== null; }); },
            onReset: function () { images.forEach(function (img) { img.reset(); }); },
            onChange: function () {}
        });

        /**
         * One image upload with preview, "Replace" and "Remove" (company logo,
         * payment QR). prefix: the data-<prefix>-* attributes; field: the file
         * field name sent to the server; removeName: the remove checkbox name.
         */
        function imageField(prefix, field, removeName) {
            var input = $('[data-' + prefix + '-input]', form);
            var preview = $('[data-' + prefix + '-preview]', form);
            var placeholder = $('[data-' + prefix + '-placeholder]', form);
            var chooseLabel = $('[data-' + prefix + '-choose-label]', form);
            var removeWrap = $('[data-' + prefix + '-remove-wrap]', form);
            var removeBox = $('[data-' + prefix + '-remove]', form);
            var fileName = $('[data-' + prefix + '-file-name]', form);
            var maxBytes = parseInt(input.getAttribute('data-max-bytes'), 10) || 2 * 1024 * 1024;
            var saved = null;
            var pendingFile = null;
            var objectUrl = null;

            function show(src) {
                preview.hidden = !src;
                placeholder.hidden = !!src;
                if (src) { preview.src = src; } else { preview.removeAttribute('src'); }
                chooseLabel.textContent = src ? 'Replace image' : 'Choose image';
            }
            function dropObjectUrl() {
                if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            }
            function reset() {
                pendingFile = null;
                input.value = '';
                removeBox.checked = false;
                fileName.textContent = '';
                dropObjectUrl();
                show(saved);
                removeWrap.hidden = !saved;
                ctrl.clearError(field);
                ctrl.refresh();
            }
            function fail(message) {
                pendingFile = null;
                input.value = '';
                dropObjectUrl();
                show(removeBox.checked ? null : saved);
                fileName.textContent = '';
                var errors = {};
                errors[field] = message;
                ctrl.showErrors(errors);
                ctrl.refresh();
            }

            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                ctrl.clearError(field);
                if (!file) { reset(); return; }
                if (ALLOWED_TYPES.indexOf(file.type) === -1) { fail('Please choose a JPG, PNG or WebP image.'); return; }
                if (file.size > maxBytes) {
                    fail('That image is ' + (file.size / 1024 / 1024).toFixed(1) + ' MB. The limit is ' + Math.round(maxBytes / 1024 / 1024) + ' MB.');
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
                    show(url);
                    fileName.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB · not saved yet';
                    ctrl.refresh();
                };
                probe.onerror = function () {
                    URL.revokeObjectURL(url);
                    fail('That file could not be read as an image.');
                };
                probe.src = url;
            });

            removeBox.addEventListener('change', function () {
                if (removeBox.checked) {
                    pendingFile = null;
                    input.value = '';
                    fileName.textContent = '';
                    dropObjectUrl();
                    show(null);
                } else {
                    show(saved);
                }
                ctrl.refresh();
            });

            var api = {
                setSaved: function (url) { saved = url || null; reset(); },
                reset: reset,
                pending: function () { return pendingFile; },
                appendTo: function (body) {
                    if (pendingFile) { body.append(field, pendingFile, pendingFile.name); }
                    if (removeBox.checked && !pendingFile) { body.append(removeName, '1'); }
                }
            };
            images.push(api);
            return api;
        }

        var logo = imageField('logo', 'company_logo', 'remove_logo');
        var qr = imageField('qr', 'payment_qr', 'remove_payment_qr');

        function schema() {
            return {
                company_name: [Rules.required('Company name'), Rules.minLength(2, 'Company name'), Rules.maxLength(150, 'Company name')],
                business_category: [Rules.required('Business category'), Rules.oneOf(selectOptions(category), 'Choose a category from the list.')],
                company_background: [Rules.maxLength(3000, 'Company background')],
                payment_instructions: [Rules.maxLength(1000, 'Payment instructions')]
            };
        }

        function fill(profile, demo) {
            var p = profile || {};
            ctrl.load({
                company_name: p.company_name || '',
                business_category: p.business_category || '',
                company_background: p.company_background || '',
                payment_instructions: p.payment_instructions || '',
                remove_logo: false,
                remove_payment_qr: false
            });
            logo.setSaved(p.company_logo_url);
            qr.setSaved(p.payment_qr_url);
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
            ['company_name', 'business_category', 'company_background', 'payment_instructions'].forEach(function (key) {
                body.append(key, trimmed(values[key]));
            });
            images.forEach(function (img) { img.appendTo(body); });

            ctrl.setBusy(true);
            Api.company.save(body).then(function (data) {
                ctrl.busy = false;
                fill(data.company_profile, !!data.demo);
                toast('Company information saved.', 'success');
            }).catch(function (error) {
                ctrl.saveFailed(error);
            }).then(function () {
                ctrl.setBusy(false);
            });
        });

        $('[data-retry]', section).addEventListener('click', function () { load(); });

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
        var KEY_PREFIX = { Anthropic: 'sk-ant-', OpenAI: 'sk-', DeepSeek: 'sk-' };   // Agnes keys have no fixed prefix

        /** What the server last said: whether a key is saved, and for which provider. */
        var keyState = { saved: false, provider: null };
        var PERSONALITIES = ['friendly', 'professional', 'warm', 'persuasive', 'custom'];

        /** Order and headings used inside the single system_instructions field. */
        var SOP = [
            { key: 'service', label: 'Customer Service SOP' },
            { key: 'booking', label: 'Booking SOP' },
            { key: 'sales',   label: 'Salesman SOP' }
        ];

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

        /* ---- Generate SOPs with AI (Agnes) ------------------------------- */

        var generateBtn = $('[data-sop-generate]', form);
        var aiResult = $('[data-sop-ai-result]', form);

        function showAiResult(data) {
            aiResult.innerHTML = '';
            var head = document.createElement('p');
            head.className = 'sop-ai-result-head';
            head.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> ';
            head.appendChild(document.createTextNode('Recommended for your business' + (data.model ? ' (' + data.model + ')' : '') + ':'));
            aiResult.appendChild(head);
            var list = document.createElement('ul');
            data.sops.forEach(function (sop) {
                var item = document.createElement('li');
                var title = document.createElement('strong');
                title.textContent = sop.title;
                item.appendChild(title);
                if (sop.why) { item.appendChild(document.createTextNode(' - ' + sop.why)); }
                list.appendChild(item);
            });
            aiResult.appendChild(list);
            aiResult.hidden = false;
        }

        function applySops(sops) {
            sops.forEach(function (sop) {
                var editor = editors[sop.slot];
                if (!editor) { return; }
                editor.value = sop.title + '\n' + sop.steps.map(function (step, i) { return (i + 1) + '. ' + step; }).join('\n');
                editor.dispatchEvent(new Event('input', { bubbles: true }));
            });
            var firstTab = $('[data-sop-tab="' + sops[0].slot + '"]', form);
            if (firstTab) { firstTab.click(); }
        }

        function generateSops() {
            setBusy(generateBtn, true, 'Generating…');
            Api.assistant.generateSops().then(function (data) {
                if (!data || !data.sops || !data.sops.length) { throw new Error('Agnes AI returned no SOPs. Please try again.'); }
                applySops(data.sops);
                showAiResult(data);
                toast('3 SOPs generated. Review them, then click Save.', 'success');
            }).catch(function (error) {
                var message = error && error.kind === 'unavailable'
                    ? 'AI generation is not available right now (the server endpoint could not be reached).'
                    : (error && error.message) || 'Could not generate the SOPs.';
                toast(message, 'error');
            }).then(function () {
                setBusy(generateBtn, false);
            });
        }

        if (generateBtn) {
            generateBtn.addEventListener('click', function () {
                var hasText = SOP.some(function (s) { return editors[s.key].value.trim() !== ''; });
                if (!hasText) { generateSops(); return; }
                confirmAction({
                    title: 'Replace your SOPs?',
                    message: 'Agnes AI will write 3 new SOPs and replace the text in all three sections. You can still reset before saving.',
                    confirmLabel: 'Generate'
                }).then(function (ok) { if (ok) { generateSops(); } });
            });
        }

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
                reply_language: ['auto', 'en', 'ms', 'zh', 'ta'].indexOf(s.reply_language) === -1 ? 'auto' : s.reply_language,
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
                temperature: values.temperature,
                reply_language: values.reply_language || 'auto'
            };
            // Only sent when typed; otherwise the server keeps the saved key.
            if (trimmed(values.api_key) !== '') { payload.api_key = trimmed(values.api_key); }

            Api.assistant.save(payload).then(function (data) {
                ctrl.busy = false;
                fill(data.ai_settings, !!data.demo);
                toast('AI assistant settings saved.', 'success');
            }).catch(function (error) {
                ctrl.saveFailed(error);
            }).then(function () {
                ctrl.setBusy(false);
            });
        });

        $('[data-retry]', section).addEventListener('click', function () { load(); });

        return { load: load };
    })();

    /* ================================================ 9. Telegram === */

    /*
     * Connect flow:
     *   saved chatbot, not connected  -> "choose": use it, or create a new one
     *   no saved chatbot              -> "create": name it, make it in @BotFather, paste token
     *   connected                     -> "connected": name, username, staff link (pill = Error if Telegram can't reach the app)
     */
    var Telegram = (function () {
        var section = $('[data-section="telegram"]', root);
        var form = $('[data-form="telegram"]', section);
        var badge = $('[data-status-badge="telegram"]', form);
        var result = $('[data-result]', form);
        var token = $('#bot_token', form);
        var nameInput = $('#bot_name', form);
        var views = {};
        $all('[data-tg-view]', form).forEach(function (el) { views[el.getAttribute('data-tg-view')] = el; });
        function el(name) { return $('[data-tg="' + name + '"]', form); }
        function action(name) { return $all('[data-action="' + name + '"]', form); }

        var errorsUi = new FormController(form, {});
        errorsUi.isDirty = function () { return false; };

        var TOKEN = /^\d{5,12}:[A-Za-z0-9_-]{30,64}$/;
        var state = { telegram: {}, suggestions: {}, current: null };
        var pollTimer = null;

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

        function show(view) {
            state.current = view;
            Object.keys(views).forEach(function (key) { views[key].hidden = key !== view; });
            errorsUi.clearErrors();
        }

        function hasSaved() {
            return !!(state.telegram.has_bot_token && state.telegram.bot_username);
        }

        function botLabel(t) {
            return t.bot_name || ('@' + t.bot_username);
        }

        /** Connected + Telegram reaches this app = Connected pill; otherwise Error with the reason. */
        function renderWebhook(webhook, status) {
            var problem = status === 'connected' && !!webhook && webhook.ok === false;
            StatusBadge.set(badge, problem ? 'error' : status);
            el('webhook-error').hidden = !problem;
            el('webhook-error-text').textContent = problem ? webhook.message : '';
        }

        /** "@username" from the saved Telegram name "First Last (@username)", else the name. */
        function staffHandle(t) {
            var name = String(t.staff_linked_name || '');
            var at = /@([A-Za-z0-9_]{3,})/.exec(name);
            return at ? '@' + at[1] : name.trim();
        }

        function renderStaff(t) {
            var linked = !!t.staff_telegram_chat_id;
            var handle = staffHandle(t);
            el('field-staff').value = linked
                ? 'Linked to chat ' + (handle ? handle + ' ' : '') + '(' + t.staff_telegram_chat_id + ')'
                : 'Not linked';
            el('staff-text').textContent = linked
                ? 'Staff alerts and hand-overs go there.'
                : 'Link your own Telegram to receive staff alerts and hand-overs.';
            el('staff-link-label').textContent = linked ? 'Link again' : 'Link my Telegram';
            action('staff-unlink')[0].hidden = !linked;
            el('staff-field').classList.toggle('is-ok', linked);
        }

        /** Pick the view from what the server says. */
        function render(data, keepView) {
            var t = (data && data.telegram) || {};
            state.telegram = t;
            if (data && data.suggestions) { state.suggestions = data.suggestions; }

            var status = StatusBadge.normalize(t.connection_status);
            if (data && 'webhook' in data) { state.webhook = data.webhook; }
            if (status !== 'connected') { state.webhook = null; }

            // Saved chatbot card
            el('saved-name').textContent = t.bot_name || 'Saved chatbot';
            el('saved-username').textContent = t.bot_username ? '@' + t.bot_username : '';

            // Connected card
            el('bot-name').textContent = t.bot_name || ('@' + (t.bot_username || ''));
            var link = el('bot-link');
            link.textContent = t.bot_link ? t.bot_link.replace(/^https:\/\//, '') : '';
            link.href = t.bot_link || '#';
            el('field-name').value = t.bot_name || '(not set)';
            el('field-username').value = t.bot_username ? '@' + t.bot_username : '';
            renderStaff(t);
            renderWebhook(state.webhook, status);

            // Create form suggestions
            var suggestedName = nameInput.value.trim() || t.bot_name || state.suggestions.bot_name || '';
            nameInput.value = suggestedName;
            el('copy-name').textContent = suggestedName || '—';
            el('copy-username').textContent = state.suggestions.bot_username || 'my_shop_bot';
            el('none-note').hidden = hasSaved();
            action('back-choose')[0].hidden = !hasSaved() || status === 'connected';

            if (keepView && state.current) { return; }
            if (status === 'connected') { show('connected'); }
            else if (hasSaved()) { show('choose'); }
            else { show('create'); }
        }

        function failed(error, title) {
            if (error.kind === 'validation') { errorsUi.showErrors(error.errors); }
            showResult(result, 'error', title, error.message);
        }

        function connect(values, button, busyLabel) {
            setBusy(button, true, busyLabel || 'Connecting…');
            showResult(result, 'loading', 'Connecting…', 'Checking the chatbot with Telegram, setting its name and linking customer messages to this app.');
            return Api.telegram.connect(values).then(function (data) {
                clearToken();
                render(data);
                var ok = data.webhook && data.webhook.ok;
                showResult(result, ok ? 'success' : 'warning', ok ? 'Chatbot connected' : 'Connected, but check the warning',
                    data.message || (ok ? 'Customers can now message your chatbot.' : ''));
                toast('Telegram chatbot connected.', 'success');
            }).catch(function (error) {
                failed(error, 'Could not connect');
            }).then(function () {
                setBusy(button, false);
            });
        }

        // Create a new chatbot (form submit = "Connect chatbot")
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var values = { bot_name: trimmed(nameInput.value), bot_token: trimmed(token.value) };
            var errors = {};
            if (!values.bot_token) { errors.bot_token = 'Paste the bot token that @BotFather sent you.'; }
            else if (!TOKEN.test(values.bot_token)) { errors.bot_token = 'That doesn\'t look like a bot token. It looks like 123456789:AA… (from @BotFather).'; }
            if (values.bot_name.length > 64) { errors.bot_name = 'The chatbot name must be 64 characters or fewer.'; }
            if (Object.keys(errors).length) { errorsUi.showErrors(errors); return; }
            connect(values, action('connect')[0]);
        });

        // Use the saved chatbot / reconnect the current one
        action('use-saved').forEach(function (button) {
            button.addEventListener('click', function () { connect({ use_saved: true }, button); });
        });
        action('reconnect').forEach(function (button) {
            button.addEventListener('click', function () { connect({ use_saved: true }, button, 'Reconnecting…'); });
        });

        action('show-create').forEach(function (button) {
            button.addEventListener('click', function () {
                clearResult(result);
                show('create');
                action('back-choose')[0].hidden = false;
                nameInput.focus();
            });
        });
        action('back-choose').forEach(function (button) {
            button.addEventListener('click', function () {
                clearResult(result);
                show(state.telegram.connection_status === 'connected' ? 'connected' : 'choose');
            });
        });

        // Copy helpers for the @BotFather steps
        nameInput.addEventListener('input', function () { el('copy-name').textContent = nameInput.value.trim() || '—'; });
        $all('[data-copy]', form).forEach(function (button) {
            button.addEventListener('click', function () {
                var text = button.getAttribute('data-copy') === 'name' ? nameInput.value.trim() : el('copy-username').textContent;
                if (!text || !navigator.clipboard) { return; }
                navigator.clipboard.writeText(text).then(function () { toast('Copied: ' + text, 'success'); }, function () {});
            });
        });

        action('disconnect').forEach(function (button) {
            button.addEventListener('click', function () {
                confirmAction({
                    title: 'Disconnect Telegram?',
                    message: 'The chatbot will stop replying to customers on Telegram. It stays saved on this account, so you can connect it again later.',
                    confirmLabel: 'Disconnect'
                }).then(function (ok) {
                    if (!ok) { return; }
                    setBusy(button, true, 'Disconnecting…');
                    Api.telegram.disconnect().then(function (data) {
                        render(data);
                        showResult(result, 'success', 'Telegram disconnected', data.message || '');
                    }).catch(function (error) {
                        failed(error, 'Could not disconnect');
                    }).then(function () { setBusy(button, false); });
                });
            });
        });

        action('forget').forEach(function (button) {
            button.addEventListener('click', function () {
                confirmAction({
                    title: 'Remove the saved chatbot?',
                    message: 'The bot token is deleted from this account. The bot itself still exists in Telegram (delete it in @BotFather if you no longer need it).',
                    confirmLabel: 'Remove'
                }).then(function (ok) {
                    if (!ok) { return; }
                    setBusy(button, true, 'Removing…');
                    Api.telegram.forget().then(function (data) {
                        render(data);
                        showResult(result, 'success', 'Chatbot removed', data.message || '');
                    }).catch(function (error) {
                        failed(error, 'Could not remove it');
                    }).then(function () { setBusy(button, false); });
                });
            });
        });

        /* ---- staff chat: "Link my Telegram" ---------------------------- */

        function stopPolling() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        }

        /** After the link is opened, check every 3 s (for 3 minutes) until the webhook saved the chat. */
        function pollForStaff(before) {
            stopPolling();
            var tries = 0;
            pollTimer = setInterval(function () {
                tries += 1;
                if (tries > 60) {
                    stopPolling();
                    showResult(result, 'warning', 'Not linked yet', 'Open the link again and press Start in Telegram. If nothing happens, check the "Customer messages" status above.');
                    return;
                }
                Api.telegram.get().then(function (data) {
                    var t = data.telegram || {};
                    if (t.staff_telegram_chat_id && t.staff_telegram_chat_id !== before) {
                        stopPolling();
                        render(data, true);
                        showResult(result, 'success', 'Staff chat linked', 'Linked to chat ' + (staffHandle(t) ? staffHandle(t) + ' ' : '') + '(' + t.staff_telegram_chat_id + '). Staff alerts and hand-overs go there.');
                        toast('Your Telegram is linked for staff alerts.', 'success');
                    }
                }).catch(function () {});
            }, 3000);
        }

        action('staff-link').forEach(function (button) {
            button.addEventListener('click', function () {
                // Open the window first (inside the click), so pop-up blockers allow it.
                var win = window.open('about:blank', '_blank');
                setBusy(button, true, 'Opening Telegram…');
                Api.telegram.staffLink().then(function (data) {
                    if (win) { win.location.href = data.link; } else { window.location.href = data.link; }
                    showResult(result, 'info', 'Press Start in Telegram', 'Telegram opened your chatbot. Press Start (or send the message) and this page will update by itself.');
                    pollForStaff(state.telegram.staff_telegram_chat_id || '');
                }).catch(function (error) {
                    if (win) { win.close(); }
                    failed(error, 'Could not create the link');
                }).then(function () { setBusy(button, false); });
            });
        });

        action('staff-unlink').forEach(function (button) {
            button.addEventListener('click', function () {
                setBusy(button, true, 'Unlinking…');
                Api.telegram.staffUnlink().then(function (data) {
                    render(data, true);
                    showResult(result, 'success', 'Staff chat unlinked', '');
                }).catch(function (error) {
                    failed(error, 'Could not unlink');
                }).then(function () { setBusy(button, false); });
            });
        });

        function load() {
            setView(section, 'loading');
            StatusBadge.set(badge, 'checking');
            return Api.telegram.get().then(function (data) {
                clearResult(result);
                state.current = null;
                render(data);
                setView(section, 'ready');
            }).catch(function (error) {
                showLoadError(section, error);
            });
        }

        $('[data-retry]', section).addEventListener('click', function () { load(); });

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
        var keepNote = $('[data-gcal-keep-note]', form);
        var capacityFields = $('[data-capacity-fields]', form);
        var capacityHint = $('[data-capacity-hint]', form);
        var summary = $('[data-booking-summary]', form);
        var DAY_NAMES = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        var buttons = {
            oauth: $('[data-action="oauth"]', form),
            refresh: $('[data-action="refresh-calendars"]', form),
            disconnect: $('[data-action="disconnect"]', form)
        };

        var state = { status: 'disconnected', saved: null };

        var ctrl = new FormController(form, { tab: 'calendar', onChange: updateRules });

        function openDays(v) {
            var days = [];
            for (var d = 1; d <= 7; d++) { if (v['open_day_' + d]) { days.push(d); } }
            return days;
        }

        function durationLabel(min) {
            min = Number(min) || 60;
            if (min < 60) { return min + ' minutes'; }
            var h = Math.floor(min / 60), m = min % 60;
            return h + (h === 1 ? ' hour' : ' hours') + (m ? ' ' + m + ' min' : '');
        }

        /** Show the capacity fields only for "many customers", and a plain-language summary. */
        function updateRules() {
            var v = ctrl.values();
            var multi = v.booking_mode === 'multi';
            capacityFields.hidden = !multi;
            capacityHint.textContent = v.capacity_unit === 'pax'
                ? 'Total people in one slot, e.g. 40 seats.'
                : 'Bookings in one slot, e.g. 10 tables.';

            var days = openDays(v).map(function (d) { return DAY_NAMES[d]; });
            var cap = Number(v.slot_capacity) || 0;
            var who = multi
                ? 'up to ' + (cap || '?') + (v.capacity_unit === 'pax' ? ' people' : ' bookings') + ' in the same slot'
                : 'one customer per slot';
            var mins = function (t) { return Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5)); };
            var last = /^\d{2}:\d{2}$/.test(v.close_time) ? mins(v.close_time) - (Number(v.default_appointment_duration_minutes) || 60) : -1;
            var lastText = last >= 0 ? ('0' + Math.floor(last / 60)).slice(-2) + ':' + ('0' + last % 60).slice(-2) : '';
            summary.textContent = days.length && v.open_time && v.close_time
                ? 'Summary: bookings of ' + durationLabel(v.default_appointment_duration_minutes) + ', '
                    + (days.length === 7 ? 'every day' : days.join(', ')) + ', ' + v.open_time + ' to ' + v.close_time
                    + (lastText ? ' (the last booking starts at ' + lastText + ')' : '') + ', ' + who + '.'
                : '';
        }

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
                setCalendarOptions([], 'Connect your Google account first');
                calendarSelect.disabled = true;
                buttons.refresh.disabled = true;
                calendarHint.textContent = 'Connect your Google account in step 1, then pick the calendar for new appointments.';
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
                    ? 'New appointments are added to this calendar.'
                    : 'This Google account has no calendar you can add events to. Create one in Google Calendar, then click Refresh list.';
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

        function render(g) {
            var data = g || {};
            state.status = StatusBadge.normalize(data.connection_status);
            state.saved = data;

            StatusBadge.set(badge, state.status);
            var connected = state.status === 'connected';
            email.textContent = data.google_account_email && state.status !== 'disconnected' ? data.google_account_email : 'Not connected';
            accountHint.textContent = connected
                ? 'Connected. Your Google access is stored securely on the server, never in this page.'
                : (data.oauth_configured === false
                    ? 'Google sign-in is not set up yet: add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env.'
                    : 'Sign in on Google\'s own page. This app never sees your Google password.');
            oauthLabel.textContent = connected ? 'Switch account' : (state.status === 'error' ? 'Connect again' : 'Connect Google account');
            buttons.oauth.classList.toggle('primary-btn', !connected);
            buttons.oauth.classList.toggle('secondary-btn', connected);
            buttons.oauth.disabled = data.oauth_configured === false;
            buttons.disconnect.hidden = state.status === 'disconnected';
            keepNote.hidden = state.status === 'disconnected';
            $('[data-gcal-account]', form).classList.toggle('is-connected', connected);

            if (state.status === 'error') {
                showResult(result, 'error', 'Google Calendar needs attention',
                    data.last_error || 'Google stopped accepting the saved access. Connect the account again.');
            }
        }

        function values(data) {
            var zone = data.timezone || 'Asia/Kuala_Lumpur';
            ensureTimezone(zone);
            var out = {
                calendar_id: data.calendar_id || '',
                timezone: zone,
                booking_mode: data.booking_mode === 'multi' ? 'multi' : 'single',
                slot_capacity: data.booking_mode === 'multi' ? String(data.slot_capacity || 10) : '10',
                capacity_unit: data.capacity_unit === 'bookings' ? 'bookings' : 'pax',
                default_appointment_duration_minutes: String(data.default_appointment_duration_minutes || 60),
                open_time: data.open_time || '09:00',
                close_time: data.close_time || '18:00'
            };
            var days = Array.isArray(data.open_days) && data.open_days.length ? data.open_days : [1, 2, 3, 4, 5, 6, 7];
            for (var d = 1; d <= 7; d++) { out['open_day_' + d] = days.indexOf(d) !== -1; }
            return out;
        }

        function fill(g) {
            render(g);
            return loadCalendars().then(function () {
                ctrl.load(values(g || {}));
                ctrl.setSavedMeta(savedMetaText((g || {}).updated_at));
                updateRules();
            });
        }

        function load() {
            setView(section, 'loading');
            StatusBadge.set(badge, 'checking');
            return Api.calendar.get().then(function (data) {
                clearResult(result);
                setView(section, 'ready');
                return fill(data.google_calendar);
            }).catch(function (error) {
                showLoadError(section, error);
            });
        }

        function schema() {
            return {
                calendar_id: [function (value) {
                    return state.status === 'connected' && trimmed(value) === '' ? 'Choose the calendar for new appointments.' : null;
                }],
                timezone: [Rules.required('Timezone'), Rules.oneOf(selectOptions(timezoneSelect), 'Choose a timezone from the list.')],
                slot_capacity: [function (value, all) {
                    if (all.booking_mode !== 'multi') { return null; }
                    var n = Number(value);
                    return /^\d+$/.test(trimmed(value)) && n >= 2 && n <= 1000 ? null : 'Enter how many fit in one slot (2 to 1000).';
                }],
                open_time: [function (value) { return /^\d{2}:\d{2}$/.test(value) ? null : 'Enter the opening time.'; }],
                close_time: [function (value, all) {
                    if (!/^\d{2}:\d{2}$/.test(value)) { return 'Enter the closing time.'; }
                    var mins = function (t) { return Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5)); };
                    return /^\d{2}:\d{2}$/.test(all.open_time) && mins(value) - mins(all.open_time) < Number(all.default_appointment_duration_minutes)
                        ? 'Closing time must be at least one booking length after opening time.' : null;
                }]
            };
        }

        buttons.oauth.addEventListener('click', function () {
            setBusy(buttons.oauth, true, 'Opening Google…');
            Api.calendar.oauthStart().then(function (data) {
                var url = String(data.auth_url || '');
                if (!/^https:\/\/accounts\.google\.com\//.test(url)) {
                    throw new Api.ApiError('bad_response', 'The server did not return a Google sign-in link.');
                }
                window.location.assign(url);
            }).catch(function (error) {
                setBusy(buttons.oauth, false);
                showResult(result, 'error', 'Could not open Google sign-in', error.message);
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
            if (!openDays(v).length) { errors.open_days = 'Choose at least one day.'; }
            if (Object.keys(errors).length) {
                ctrl.showErrors(errors);
                toast('Please fix the highlighted fields.', 'error');
                return;
            }
            ctrl.setBusy(true);
            Api.calendar.save({
                calendar_id: v.calendar_id,
                timezone: v.timezone,
                booking_mode: v.booking_mode,
                slot_capacity: v.booking_mode === 'multi' ? v.slot_capacity : '1',
                capacity_unit: v.capacity_unit,
                default_appointment_duration_minutes: v.default_appointment_duration_minutes,
                open_time: v.open_time,
                close_time: v.close_time,
                open_days: openDays(v).join(',')
            }).then(function (data) {
                ctrl.busy = false;
                return fill(data.google_calendar).then(function () {
                    toast('Booking settings saved.', 'success');
                });
            }).catch(function (error) {
                ctrl.saveFailed(error);
            }).then(function () {
                ctrl.setBusy(false);
            });
        });

        buttons.disconnect.addEventListener('click', function () {
            confirmAction({
                title: 'Disconnect Google Calendar?',
                message: 'New bookings will stop going to Google Calendar, and the saved Google access is deleted. Appointments already saved in this app are kept.',
                confirmLabel: 'Disconnect'
            }).then(function (ok) {
                if (!ok) { return; }
                setBusy(buttons.disconnect, true, 'Disconnecting…');
                Api.calendar.disconnect().then(function (data) {
                    setBusy(buttons.disconnect, false);
                    return fill(data.google_calendar).then(function () {
                        showResult(result, 'success', 'Google Calendar disconnected', 'Appointments already saved in this app were kept.');
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
                    showResult(result, 'success', 'Google account connected', 'Now choose the calendar for bookings in step 2, then click Save booking settings.');
                    toast('Google Calendar connected.', 'success');
                } else {
                    showResult(result, 'warning', 'Not connected yet', 'Google sign-in finished, but the connection was not saved. Please try again.');
                }
            } else {
                showResult(result, 'error', 'Google sign-in did not finish', message || 'The sign-in was cancelled or Google refused it. Please try again.');
            }
        }

        $('[data-retry]', section).addEventListener('click', function () { load(); });

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
        if (oauthOutcome === 'connected' || oauthOutcome === 'error') {
            Calendar.handleOAuthReturn(oauthOutcome, oauthMessage);
        }
    });
})();
