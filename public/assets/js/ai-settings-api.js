/**
 * ai-settings-api.js - the only place the AI Settings page talks to the server.
 *
 * window.AiSettingsApi exposes one object per section:
 *
 *   company    get(), save(formData)
 *   assistant  get(), save(values), generateSops()
 *   telegram   get(), connect(values), disconnect(), forget(), staffLink(), staffUnlink()
 *   calendar   get(), oauthStart(), calendars(), save(values), disconnect()
 *
 * Every call resolves to the `data` part of the app's JSON envelope
 * ({success, data, message}, with `message` copied in as data.message) or
 * rejects with an ApiError. The URLs come from
 * ChatbotController::aiSettingsEndpoints() (data-endpoints on the page).
 *
 * IDENTITY: no call sends a user id; the server takes the user from the
 * session. SECRETS: the bot token / API key are sent once in a POST body and
 * never logged, stored in the browser or expected back.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-ai-settings]');
    if (!root) { return; }

    var endpoints = {};
    try { endpoints = JSON.parse(root.getAttribute('data-endpoints') || '{}'); } catch (e) { endpoints = {}; }


    /* ---------------------------------------------------------- errors --- */

    /**
     * kind: 'unavailable' (endpoint missing / server unreachable), 'validation',
     * 'session' (signed out or CSRF expired), 'failed' (server said no),
     * 'bad_response'.
     */
    function ApiError(kind, message, extra) {
        this.name = 'ApiError';
        this.kind = kind;
        this.message = message;
        this.errors = (extra && extra.errors) || {};
        this.data = (extra && extra.data) || {};
        this.status = (extra && extra.status) || 0;
    }
    ApiError.prototype = Object.create(Error.prototype);
    ApiError.prototype.constructor = ApiError;

    /* --------------------------------------------------------- request --- */

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function toFormData(values) {
        if (values instanceof FormData) { return values; }
        var body = new FormData();
        Object.keys(values || {}).forEach(function (key) {
            var value = values[key];
            if (value === null || value === undefined) { value = ''; }
            if (typeof value === 'boolean') { value = value ? '1' : '0'; }
            body.append(key, value);
        });
        return body;
    }

    function request(method, key, values) {
        var url = endpoints[key];
        if (!url) {
            return Promise.reject(new ApiError('unavailable', 'This feature has no server endpoint configured.'));
        }

        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken()
            }
        };
        if (method === 'POST') { options.body = toFormData(values); }

        return fetch(url, options).then(function (response) {
            return response.text().then(function (text) {
                var json = null;
                try { json = JSON.parse(text); } catch (e) { json = null; }

                if (response.status === 404 || response.status === 405 || response.status === 501) {
                    throw new ApiError('unavailable', 'This endpoint is not implemented on the server yet.', { status: response.status });
                }
                if (response.status === 401 || response.status === 419) {
                    throw new ApiError('session', 'Your session has expired. Reload the page and sign in again.', { status: response.status });
                }
                if (json === null || typeof json !== 'object') {
                    // A login page instead of JSON usually means the session ended.
                    throw new ApiError(response.redirected ? 'session' : 'bad_response',
                        response.redirected
                            ? 'Your session has expired. Reload the page and sign in again.'
                            : 'The server sent an unexpected reply (HTTP ' + response.status + ').',
                        { status: response.status });
                }
                if (!json.success) {
                    var data = json.data || {};
                    var errors = data.errors || {};
                    throw new ApiError(
                        Object.keys(errors).length ? 'validation' : 'failed',
                        json.message || 'The server could not complete the request.',
                        { errors: errors, data: data, status: response.status }
                    );
                }
                var out = json.data && typeof json.data === 'object' && !Array.isArray(json.data) ? json.data : {};
                if (json.message && out.message === undefined) { out.message = json.message; }
                return out;
            });
        }, function () {
            throw new ApiError('unavailable', 'Could not reach the server.');
        });
    }

    function get(key) { return request('GET', key); }
    function post(key, values) { return request('POST', key, values || {}); }

    // GET  companyGet  -> { company_profile }      POST companySave (multipart, logo optional)
    var company = {
        get: function () { return get('companyGet'); },
        save: function (formData) { return post('companySave', formData); }
    };

    // GET  assistantGet -> { ai_settings }         POST assistantSave (api_key only when typed)
    // POST assistantGenerateSops -> { model, sops: [{slot, heading, title, why, steps[5]}] }
    var assistant = {
        get: function () { return get('assistantGet'); },
        save: function (values) { return post('assistantSave', values); },
        generateSops: function () { return post('assistantGenerateSops'); }
    };

    // GET  telegramGet -> { telegram, webhook, suggestions }
    // POST telegramConnect {use_saved} | {bot_token, bot_name} -> { telegram, webhook }
    // POST telegramDisconnect / telegramForget / telegramStaffUnlink -> { telegram }
    // POST telegramStaffLink -> { link }  (t.me/<bot>?start=staff_<code>)
    var telegram = {
        get: function () { return get('telegramGet'); },
        connect: function (values) { return post('telegramConnect', values); },
        disconnect: function () { return post('telegramDisconnect'); },
        forget: function () { return post('telegramForget'); },
        staffLink: function () { return post('telegramStaffLink'); },
        staffUnlink: function () { return post('telegramStaffUnlink'); }
    };

    // GET  calendarGet -> { google_calendar }      GET calendarOauthStart -> { auth_url }
    // GET  calendarCalendars -> { calendars }      POST calendarSave / calendarDisconnect
    var calendar = {
        get: function () { return get('calendarGet'); },
        oauthStart: function () { return get('calendarOauthStart'); },
        calendars: function () { return get('calendarCalendars'); },
        save: function (values) { return post('calendarSave', values); },
        disconnect: function () { return post('calendarDisconnect'); }
    };

    window.AiSettingsApi = {
        ApiError: ApiError,
        mode: function () { return 'live'; },
        company: company,
        assistant: assistant,
        telegram: telegram,
        calendar: calendar
    };
})();
