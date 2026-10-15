/**
 * api.js - thin fetch wrapper for the JSON endpoints.
 *
 * Adds the CSRF header, unwraps the {success, data, message} envelope, and
 * enhances any <form data-ajax> so it submits without a full page reload.
 * A form without JS still posts normally and gets the server-rendered result.
 */
(function () {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function toFormData(data) {
        var body = new FormData();
        Object.keys(data || {}).forEach(function (key) {
            body.append(key, data[key]);
        });
        return body;
    }

    /**
     * @returns {Promise<Object>} the parsed envelope
     * @throws  {Error} on a network failure or a non-JSON reply
     */
    function request(url, options) {
        var opts = options || {};
        opts.credentials = 'same-origin';
        opts.headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken()
        }, opts.headers || {});

        return fetch(url, opts).then(function (response) {
            return response.json().catch(function () {
                throw new Error('The server did not return JSON (HTTP ' + response.status + ').');
            });
        });
    }

    function post(url, data, options) {
        return request(url, Object.assign({
            method: 'POST',
            body: data instanceof FormData ? data : toFormData(data)
        }, options || {}));
    }

    /* ------------------------------------------------- form enhancement --- */

    function clearErrors(form) {
        form.querySelectorAll('[data-error-for]').forEach(function (el) {
            el.textContent = '';
        });
        form.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        });
    }

    function showErrors(form, errors) {
        clearErrors(form);
        Object.keys(errors).forEach(function (field) {
            var message = form.querySelector('[data-error-for="' + field + '"]');
            if (message) {
                message.textContent = errors[field];
            }
            var input = form.querySelector('[name="' + field + '"]');
            if (input) {
                input.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
            }
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax')) {
            return;
        }

        event.preventDefault();

        var button = form.querySelector('button[type="submit"]');
        if (window.UI) {
            UI.setLoading(button, true);
        }

        post(form.action, new FormData(form)).then(function (result) {
            if (result.success) {
                if (window.UI) {
                    UI.toast(result.message || 'Saved.', 'success');
                }
                var next = result.data && result.data.redirect;
                if (next) {
                    window.location.href = next;
                    return;
                }
                if (window.UI) {
                    UI.setLoading(button, false);
                }
                return;
            }

            if (window.UI) {
                UI.setLoading(button, false);
                UI.toast(result.message || 'Could not save.', 'error');
            }
            showErrors(form, (result.data && result.data.errors) || {});
        }).catch(function (error) {
            if (window.UI) {
                UI.setLoading(button, false);
                UI.toast(error.message || 'Network error.', 'error');
            }
        });
    });

    window.API = {
        request: request,
        post: post,
        csrfToken: csrfToken
    };
})();
