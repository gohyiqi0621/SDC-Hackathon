/**
 * notification.js - the admin composer.
 *
 * Owns the modal form: recipient choice, the schedule shortcuts, and the submit
 * itself. It deliberately does NOT use api.js's generic <form data-ajax> handler,
 * because the contract here is different - the composer closes on the outcome
 * and the history row is inserted in place, instead of reloading the page.
 *
 * Outcomes:
 *   success              -> close, toast, insert the row, refresh the counters
 *   failed send (422)    -> close, toast, insert the failed audit row
 *   validation (422)     -> stay open, mark the fields
 */
(function () {
    'use strict';

    var dialog = document.getElementById('notif-dialog');
    var form = document.getElementById('notif-form');
    if (!dialog || !form || !window.API || !window.UI) return;

    var audienceInputs = form.querySelectorAll('[data-notif-audience]');
    var picker         = form.querySelector('[data-notif-picker]');
    var boxes          = form.querySelectorAll('[data-notif-recipient]');
    // NB: not `data-notif-count` - that is the bell's unread badge in the top
    // bar (see app_header.php), and an unscoped querySelector would find it.
    var countEl        = form.querySelector('[data-notif-recipient-count]');
    var toggleAll      = form.querySelector('[data-notif-toggle-all]');
    var when           = form.querySelector('#notif-when');
    var whenHint       = form.querySelector('[data-notif-when-hint]');
    var submitBtn      = form.querySelector('[data-notif-submit]');
    var pad            = function (n) { return String(n).padStart(2, '0'); };

    /* -------------------------------------------------------- recipients --- */

    function audience() {
        for (var i = 0; i < audienceInputs.length; i++) {
            if (audienceInputs[i].checked) return audienceInputs[i].value;
        }
        return 'all';
    }

    function selected() {
        return Array.prototype.filter.call(boxes, function (b) { return b.checked; });
    }

    function syncRecipients() {
        var isUsers = audience() === 'users';
        picker.hidden = !isUsers;

        var picked  = selected();
        var devices = picked.reduce(function (sum, b) {
            return sum + (parseInt(b.getAttribute('data-devices'), 10) || 1);
        }, 0);

        if (countEl) {
            countEl.textContent = picked.length === 0
                ? (isUsers ? 'No one selected yet.' : '')
                : picked.length + (picked.length === 1 ? ' person' : ' people')
                    + ' · ' + devices + (devices === 1 ? ' device' : ' devices');
        }
        if (toggleAll) {
            toggleAll.textContent = (boxes.length > 0 && picked.length === boxes.length) ? 'Clear' : 'Select all';
        }
    }

    Array.prototype.forEach.call(audienceInputs, function (input) {
        input.addEventListener('change', syncRecipients);
    });
    Array.prototype.forEach.call(boxes, function (box) {
        box.addEventListener('change', syncRecipients);
    });

    if (toggleAll) {
        toggleAll.addEventListener('click', function () {
            var turnOn = selected().length !== boxes.length;
            Array.prototype.forEach.call(boxes, function (b) { b.checked = turnOn; });
            syncRecipients();
        });
    }

    /* ---------------------------------------------------------- schedule --- */

    /** `datetime-local` wants "YYYY-MM-DDTHH:MM" in the browser's own zone. */
    function toLocalInput(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
            + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function setWhen(date) {
        when.value = date ? toLocalInput(date) : '';
        when.min = toLocalInput(new Date(Date.now() + 60000));
        whenHint.textContent = date
            ? 'Sends on ' + date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) + ' (your local time).'
            : 'Leave blank to send now.';
    }

    Array.prototype.forEach.call(form.querySelectorAll('[data-notif-when]'), function (chip) {
        chip.addEventListener('click', function () {
            var value = chip.getAttribute('data-notif-when');
            if (value === 'clear') return setWhen(null);
            if (value === 'tomorrow') {
                var tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                tomorrow.setHours(9, 0, 0, 0);
                return setWhen(tomorrow);
            }
            setWhen(new Date(Date.now() + Number(value) * 60000));
        });
    });

    when.addEventListener('change', function () {
        // Keep the hint honest when the time is typed by hand.
        var picked = when.value ? new Date(when.value) : null;
        whenHint.textContent = picked && !isNaN(picked)
            ? 'Sends on ' + picked.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) + ' (your local time).'
            : 'Leave blank to send now.';
    });

    // The clock moves while the page sits open, so re-arm the minimum on open.
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-open-modal="#notif-dialog"]')) {
            when.min = toLocalInput(new Date(Date.now() + 60000));
        }
    });

    /* ------------------------------------------------------- history view -- */

    function insertRow(html, wasEmpty) {
        var tbody = document.getElementById('notif-history');
        if (!tbody || !html) return;
        if (wasEmpty) {
            var empty = document.getElementById('notif-empty');
            if (empty) empty.remove();
        }
        tbody.insertAdjacentHTML('afterbegin', html);
    }

    function updateStats(stats) {
        if (!stats) return;
        Object.keys(stats).forEach(function (key) {
            var el = document.querySelector('[data-stat="' + key + '"]');
            if (!el) return;
            el.textContent = stats[key];
            el.classList.remove('is-bumped');
            void el.offsetWidth; // restart the animation
            el.classList.add('is-bumped');
        });
    }

    /* ------------------------------------------------------------ errors --- */

    function clearErrors() {
        Array.prototype.forEach.call(form.querySelectorAll('[data-error-for]'), function (el) {
            el.textContent = '';
        });
        Array.prototype.forEach.call(form.querySelectorAll('.is-invalid'), function (el) {
            el.classList.remove('is-invalid');
        });
    }

    function showErrors(errors) {
        var first = null;
        Object.keys(errors).forEach(function (field) {
            var slot = form.querySelector('[data-error-for="' + field + '"]');
            if (slot) slot.textContent = errors[field];

            var input = form.querySelector('[name="' + field + '"], [name="' + field + '[]"]');
            if (input) input.classList.add('is-invalid');
            if (!first) first = field;
        });

        // A recipients error only makes sense once the picker is on screen.
        if (errors.recipients) {
            picker.hidden = false;
        }
        if (first) {
            var focus = form.querySelector('[data-error-for="' + first + '"]');
            if (focus && focus.scrollIntoView) {
                focus.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }
    }

    /* -------------------------------------------------------------- submit -- */

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        clearErrors();
        UI.setLoading(submitBtn, true);

        API.post(form.action, new FormData(form)).then(function (result) {
            UI.setLoading(submitBtn, false);
            var data = result.data || {};

            if (result.success) {
                UI.closeModal(dialog);
                UI.toast(result.message || 'Notification sent.', 'success');
                insertRow(data.row, data.empty);
                updateStats(data.stats);
                form.reset();
                syncRecipients();
                setWhen(null);
                return;
            }

            var errors = data.errors || {};
            if (Object.keys(errors).length > 0) {
                // Nothing was sent - keep the composer open so it can be fixed.
                showErrors(errors);
                return;
            }

            // The send itself failed: report it and close, and still show the
            // failed attempt, because the server recorded it as an audit row.
            UI.closeModal(dialog);
            UI.toast(result.message || 'Could not send the notification.', 'error');
            insertRow(data.row, false);
            updateStats(data.stats);
        }).catch(function (error) {
            UI.setLoading(submitBtn, false);
            UI.closeModal(dialog);
            UI.toast(error.message || 'Network error.', 'error');
        });
    });

    syncRecipients();
    setWhen(null);
})();
