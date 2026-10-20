/**
 * ai-settings-api.js - the only place the AI Settings page talks to the server.
 *
 * window.AiSettingsApi exposes one object per section:
 *
 *   company    get(), save(formData)
 *   assistant  get(), save(values)
 *   telegram   get(), test(values), connect(values), disconnect()
 *   calendar   get(), oauthStart(), calendars(), save(values), testAvailability(values), disconnect()
 *
 * Every call resolves to the `data` part of the app's JSON envelope
 * ({success, data, message}) or rejects with an ApiError.
 *
 * LIVE vs DEMO
 * ------------
 * The URLs come from ChatbotController::aiSettingsEndpoints() (data-endpoints
 * on the page). When a section's GET answers 404/405, or the server cannot be
 * reached at all, that section switches to DEMO mode:
 *   - company / assistant: values are kept in this browser (localStorage) only;
 *   - telegram / calendar: nothing is ever reported as connected.
 * Every demo result carries `demo: true` so the page can say so plainly.
 *
 * IDENTITY
 * --------
 * No call sends a user id. The server must take the user from the session
 * (require_login()) and scope every query to that user. data-user-id is only
 * used here to keep one demo user's local data apart from another's.
 *
 * SECRETS
 * -------
 * The bot token is sent once in a POST body and never logged, stored locally
 * or expected back. OAuth tokens never reach the browser at all.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-ai-settings]');
    if (!root) { return; }

    var endpoints = {};
    try { endpoints = JSON.parse(root.getAttribute('data-endpoints') || '{}'); } catch (e) { endpoints = {}; }

    var userId = root.getAttribute('data-user-id') || 'anonymous';

    /** section -> 'live' | 'demo' (set by each get()). */
    var mode = { company: null, assistant: null, telegram: null, calendar: null };

    /* ---------------------------------------------------------- errors --- */

    /**
     * kind: 'unavailable' (endpoint missing / server unreachable), 'validation',
     * 'session' (signed out or CSRF expired), 'failed' (server said no),
     * 'demo' (action that cannot happen in demo mode), 'bad_response'.
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
                return json.data || {};
            });
        }, function () {
            throw new ApiError('unavailable', 'Could not reach the server.');
        });
    }

    /** GET a section; on 'unavailable' fall back to demo data. */
    function loadSection(section, key, demoLoader) {
        return request('GET', key).then(function (data) {
            mode[section] = 'live';
            return data;
        }, function (error) {
            if (error.kind === 'unavailable') {
                mode[section] = 'demo';
                return demoLoader();
            }
            throw error;
        });
    }

    /** Run the live call, or the demo handler when the section is in demo mode. */
    function liveOrDemo(section, live, demo) {
        return mode[section] === 'demo' ? Promise.resolve().then(demo) : live();
    }

    function demoRefusal(message) {
        return Promise.reject(new ApiError('demo', message));
    }

    /* ------------------------------------------------------ demo store --- */
    // MOCK DATA: everything below is browser-only and goes away once the
    // endpoints exist. Storage can be unavailable (private mode), so every
    // access is wrapped and the page still works without it.

    function storeKey(section) { return 'smartsme.aiSettings.demo.u' + userId + '.' + section; }

    function readStore(section) {
        try {
            var raw = window.localStorage.getItem(storeKey(section));
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    /** @returns {boolean} whether it was stored */
    function writeStore(section, value) {
        try {
            window.localStorage.setItem(storeKey(section), JSON.stringify(value));
            return true;
        } catch (e) { return false; }
    }

    function nowIso() { return new Date().toISOString(); }

    var MOCK_ASSISTANT = {
        assistant_name: '',
        personality: 'friendly',
        tone_description: '',
        system_instructions: '',
        is_enabled: true,
        ai_model: 'claude-haiku-5-5',
        temperature: 0.7,
        has_api_key: false,
        updated_at: null
    };

    var MOCK_TELEGRAM = {
        bot_username: '',
        staff_telegram_chat_id: '',
        connection_status: 'disconnected',
        is_enabled: false,
        has_bot_token: false,
        updated_at: null
    };

    var MOCK_CALENDAR = {
        google_account_email: null,
        calendar_id: null,
        connection_status: 'disconnected',
        default_appointment_duration_minutes: 30,
        timezone: 'Asia/Kuala_Lumpur',
        is_enabled: false,
        updated_at: null
    };

    function readFileAsDataUrl(file) {
        return new Promise(function (resolve) {
            if (!file || !window.FileReader) { resolve(null); return; }
            var reader = new FileReader();
            reader.onload = function () { resolve(String(reader.result)); };
            reader.onerror = function () { resolve(null); };
            reader.readAsDataURL(file);
        });
    }

    /* ------------------------------------------------- company profile --- */
    // Live contract:
    //   GET  companyGet  -> { company_profile: {id, company_name, company_logo_url,
    //                         company_background, company_description,
    //                         business_category, updated_at} | null }
    //   POST companySave (multipart: the four text fields, company_logo file,
    //                     remove_logo=1) -> { company_profile: {...} }
    //   422  -> data.errors {field: message}

    var company = {
        get: function () {
            return loadSection('company', 'companyGet', function () {
                return { company_profile: readStore('company'), demo: true };
            });
        },
        save: function (formData) {
            return liveOrDemo('company', function () {
                return request('POST', 'companySave', formData);
            }, function () {
                var previous = readStore('company') || {};
                var file = formData.get('company_logo');
                var hasFile = file && typeof file === 'object' && file.size > 0;

                return (hasFile ? readFileAsDataUrl(file) : Promise.resolve(null)).then(function (dataUrl) {
                    var logo = previous.company_logo_url || null;
                    if (formData.get('remove_logo') === '1') { logo = null; }
                    if (dataUrl) { logo = dataUrl; }

                    var row = {
                        id: previous.id || 'demo',
                        company_name: String(formData.get('company_name') || ''),
                        company_logo_url: logo,
                        company_background: String(formData.get('company_background') || ''),
                        company_description: String(formData.get('company_description') || ''),
                        business_category: String(formData.get('business_category') || ''),
                        created_at: previous.created_at || nowIso(),
                        updated_at: nowIso()
                    };

                    var stored = writeStore('company', row);
                    if (!stored && logo) {
                        // Usually the logo is too big for browser storage: keep the text.
                        row.company_logo_url = null;
                        stored = writeStore('company', row);
                        return { company_profile: row, demo: true, stored: stored, logo_dropped: true };
                    }
                    return { company_profile: row, demo: true, stored: stored };
                });
            });
        }
    };

    /* ------------------------------------------------------ assistant --- */
    // Live contract:
    //   GET  assistantGet  -> { ai_settings: {assistant_name, personality,
    //                           tone_description, system_instructions,
    //                           is_enabled, ai_model, temperature,
    //                           has_api_key, updated_at} | null }
    //   POST assistantSave (same fields, is_enabled "1"/"0", api_key only when
    //                       typed) -> { ai_settings: {...} } (never the key)

    var assistant = {
        get: function () {
            return loadSection('assistant', 'assistantGet', function () {
                return { ai_settings: readStore('assistant') || Object.assign({}, MOCK_ASSISTANT), demo: true };
            });
        },
        save: function (values) {
            return liveOrDemo('assistant', function () {
                return request('POST', 'assistantSave', values);
            }, function () {
                // The secret key is never kept in browser storage, even in demo mode.
                var safe = Object.assign({}, values);
                delete safe.api_key;
                var row = Object.assign({}, MOCK_ASSISTANT, safe, { has_api_key: false, updated_at: nowIso() });
                var stored = writeStore('assistant', row);
                return { ai_settings: row, demo: true, stored: stored };
            });
        }
    };

    /* ------------------------------------------------------- telegram --- */
    // Live contract (never includes the token):
    //   GET  telegramGet        -> { telegram: {bot_username, staff_telegram_chat_id,
    //                                connection_status: connected|disconnected|error,
    //                                is_enabled, has_bot_token, last_error?, updated_at} }
    //   POST telegramTest       (bot_username, bot_token?, staff_telegram_chat_id)
    //                           -> { ok: bool, message, bot_username? }
    //   POST telegramConnect    (same) -> { telegram: {...} }
    //   POST telegramDisconnect -> { telegram: {...} }

    var telegram = {
        get: function () {
            return loadSection('telegram', 'telegramGet', function () {
                // Only the non-secret fields are remembered in demo mode.
                var saved = readStore('telegram') || {};
                return {
                    telegram: Object.assign({}, MOCK_TELEGRAM, {
                        bot_username: saved.bot_username || '',
                        staff_telegram_chat_id: saved.staff_telegram_chat_id || ''
                    }),
                    demo: true
                };
            });
        },
        test: function (values) {
            return liveOrDemo('telegram', function () {
                return request('POST', 'telegramTest', values);
            }, function () {
                return {
                    ok: false,
                    demo: true,
                    message: 'Demo mode: nothing was sent to Telegram, so the token has not been checked. '
                        + 'The format looks right.'
                };
            });
        },
        connect: function (values) {
            return liveOrDemo('telegram', function () {
                return request('POST', 'telegramConnect', values);
            }, function () {
                writeStore('telegram', {
                    bot_username: values.bot_username,
                    staff_telegram_chat_id: values.staff_telegram_chat_id
                });
                return demoRefusal('Demo mode: the bot cannot be connected until the server endpoint exists. '
                    + 'The username and chat ID were kept in this browser; the token was not stored anywhere.');
            });
        },
        disconnect: function () {
            return liveOrDemo('telegram', function () {
                return request('POST', 'telegramDisconnect', {});
            }, function () {
                return { telegram: Object.assign({}, MOCK_TELEGRAM), demo: true };
            });
        }
    };

    /* -------------------------------------------------- google calendar --- */
    // Live contract (OAuth tokens never included):
    //   GET  calendarGet        -> { google_calendar: {google_account_email, calendar_id,
    //                                connection_status, default_appointment_duration_minutes,
    //                                timezone, is_enabled, updated_at} }
    //   GET  calendarOauthStart -> { auth_url: "https://accounts.google.com/..." }
    //        (server creates and stores the OAuth `state`; its callback redirects
    //         back to this page with ?gcal=connected or ?gcal=error&gcal_message=)
    //   GET  calendarCalendars  -> { calendars: [{id, summary, primary}] }
    //   POST calendarSave       (calendar_id, default_appointment_duration_minutes, timezone)
    //                           -> { google_calendar: {...} }
    //   POST calendarTest       (same) -> { available: bool, message, slots: [{start, end}] }
    //   POST calendarDisconnect -> { google_calendar: {...} } (appointments are kept)

    var calendar = {
        get: function () {
            return loadSection('calendar', 'calendarGet', function () {
                var saved = readStore('calendar') || {};
                return {
                    google_calendar: Object.assign({}, MOCK_CALENDAR, {
                        default_appointment_duration_minutes: saved.default_appointment_duration_minutes || MOCK_CALENDAR.default_appointment_duration_minutes,
                        timezone: saved.timezone || MOCK_CALENDAR.timezone
                    }),
                    demo: true
                };
            });
        },
        oauthStart: function () {
            return liveOrDemo('calendar', function () {
                return request('GET', 'calendarOauthStart');
            }, function () {
                return demoRefusal('Demo mode: Google sign-in needs the server\'s OAuth endpoint, which is not available yet. No account was connected.');
            });
        },
        calendars: function () {
            return liveOrDemo('calendar', function () {
                return request('GET', 'calendarCalendars');
            }, function () {
                return { calendars: [], demo: true };
            });
        },
        save: function (values) {
            return liveOrDemo('calendar', function () {
                return request('POST', 'calendarSave', values);
            }, function () {
                var row = Object.assign({}, MOCK_CALENDAR, {
                    default_appointment_duration_minutes: Number(values.default_appointment_duration_minutes),
                    timezone: values.timezone,
                    updated_at: nowIso()
                });
                var stored = writeStore('calendar', {
                    default_appointment_duration_minutes: row.default_appointment_duration_minutes,
                    timezone: row.timezone
                });
                return { google_calendar: row, demo: true, stored: stored };
            });
        },
        testAvailability: function (values) {
            return liveOrDemo('calendar', function () {
                return request('POST', 'calendarTest', values);
            }, function () {
                return demoRefusal('Demo mode: availability cannot be checked without a connected Google account.');
            });
        },
        disconnect: function () {
            return liveOrDemo('calendar', function () {
                return request('POST', 'calendarDisconnect', {});
            }, function () {
                return { google_calendar: Object.assign({}, MOCK_CALENDAR), demo: true };
            });
        }
    };

    window.AiSettingsApi = {
        ApiError: ApiError,
        mode: function (section) { return mode[section]; },
        company: company,
        assistant: assistant,
        telegram: telegram,
        calendar: calendar
    };
})();
