/**
 * onesignal.js - OneSignal Web SDK (v16) wiring.
 *
 * Loaded on every signed-in page. It:
 *   1. inits the SDK with the App ID (public) from a meta tag,
 *   2. links the browser to our own user id via OneSignal.login(externalId),
 *      so subscriptions are not anonymous - the admin recipient picker is
 *      built from that link (see onesignal_subscribed_users() in PHP),
 *   3. drives the bell: unread badge + dropdown fed from our own database.
 *
 * OneSignal.logout() on sign-out is handled by the redirect flow (the SDK is
 * page-scoped); we also clear the external id explicitly before logout posts.
 */
(function () {
    'use strict';

    /**
     * Log the push-subscription state so a failing setup is visible in the
     * browser console instead of only in the server log.
     *
     * The important field is the subscription id: when the origin is not a
     * secure context (plain http:// that is not truly localhost-secure) the SDK
     * initialises but never obtains an endpoint, and OneSignal then reports
     * "All included players are not subscribed" for every send.
     *
     * Also exposed as window.__pushDiag() to re-check at any time.
     */
    function reportSubscription(OneSignal) {
        function snapshot() {
            var sub = (OneSignal.User && OneSignal.User.PushSubscription) || null;
            return {
                secureContext: window.isSecureContext,
                origin: window.location.origin,
                notificationPermission: (typeof Notification !== 'undefined') ? Notification.permission : 'unsupported',
                optedIn: sub ? sub.optedIn : null,
                subscriptionId: sub ? (sub.id || null) : null,
                externalId: (OneSignal.User && OneSignal.User.externalId) || null,
                onesignalId: (OneSignal.User && OneSignal.User.onesignalId) || null,
                workerScope: (OneSignal.config && OneSignal.config.serviceWorkerParam && OneSignal.config.serviceWorkerParam.scope) || null,
                workerPath: (OneSignal.config && OneSignal.config.serviceWorker && OneSignal.config.serviceWorker.path) || null,
            };
        }

        function print() {
            var s = snapshot();
            var ok = !!s.subscriptionId && s.optedIn === true;

            // The worker must sit where OneSignal's config says it does. When the
            // dashboard is left at its default ("/" root) but the app is served
            // from a subdirectory, the worker 404s and no subscription can ever
            // be created - the most common cause of "All included players are
            // not subscribed".
            var appPath = new URL('.', window.location.href).pathname.replace(/\/$/, '');
            var workerMismatch = !!s.workerPath && s.workerPath === '/' && appPath !== '';

            /* eslint-disable no-console */
            console.groupCollapsed('%c[push] ' + (ok ? 'ready' : 'NOT ready') + ' \u2014 ' + s.origin,
                'font-weight:600;color:' + (ok ? '#1a7f37' : '#b3261e'));
            console.log('secure context     :', s.secureContext, s.secureContext ? '' : '  <-- push needs HTTPS (or a truly-secure localhost)');
            console.log('browser permission :', s.notificationPermission);
            console.log('opted in           :', s.optedIn);
            console.log('subscription id    :', s.subscriptionId, s.subscriptionId ? '' : '  <-- no push endpoint, sends cannot be delivered');
            console.log('external id        :', s.externalId, s.externalId ? '' : '  <-- NOT LINKED TO A USER: this device will not appear in the recipient picker');
            console.log('onesignal id       :', s.onesignalId);
            console.log('worker path (server):', s.workerPath, 'scope:', s.workerScope);
            console.groupEnd();
            /* eslint-enable no-console */

            if (workerMismatch) {
                console.warn(
                    '[push] Service worker mismatch: OneSignal is configured to look for\n'
                    + '       ' + window.location.origin + '/OneSignalSDKWorker.js  (scope "/")\n'
                    + '       but this app is served from ' + appPath + '.\n'
                    + '       Fix: OneSignal dashboard > Settings > Web > Advanced Settings >\n'
                    + '       Service Worker > Customize, path ' + appPath + ', scope ' + appPath + '/'
                );
            }

            // A device can be subscribed and still unaddressable: without the
            // alias there is no way to tell which of our users it belongs to,
            // so it never appears in the admin recipient picker.
            if (ok && !s.externalId) {
                console.warn(
                    '[push] Subscribed, but not linked to a user account - this device\n'
                    + '       cannot be chosen as a recipient, and pushes aimed at this\n'
                    + '       account will find nobody.\n'
                    + '       Reload this page once; the app links the alias on load.'
                );
            }

            if (!ok && !workerMismatch) {
                var reason = !s.secureContext
                    ? 'This page is not a secure context. Serve it over HTTPS.'
                    : s.notificationPermission === 'denied'
                        ? 'The browser has blocked notifications for this origin.'
                        : s.optedIn !== true
                            ? 'Notifications are not turned on. Open Profile and switch them on.'
                            : 'No push endpoint was issued by the browser.';
                console.warn('[push] ' + reason);
            }
            return s;
        }

        window.__pushDiag = print;
        print();

        // Re-print when the subscription changes (e.g. right after the user
        // accepts the permission prompt).
        try {
            OneSignal.User.PushSubscription.addEventListener('change', print);
        } catch (err) { /* older SDK: the manual __pushDiag() call still works */ }

        return print;
    }

    var meta = document.querySelector('meta[name="onesignal-app-id"]');
    var appId = meta && meta.getAttribute('content');
    if (!appId) return;

    var externalId = meta.getAttribute('data-user-id') || '';
    var role = meta.getAttribute('data-user-role') || 'user';

    /*
     * Where the service worker must live.
     *
     * OneSignal's dashboard config decides the worker URL for "typical" sites
     * (the SDK's `customizationEnabled` is false by default, which makes it
     * DISCARD the path/serviceWorkerPath init options and use the server config
     * instead). The dashboard default is:
     *     path = "/",  workerName = "OneSignalSDKWorker.js",  scope = "/"
     * i.e. the worker is expected at the ORIGIN ROOT.
     *
     * This app is served from a subdirectory (/sdc-project/public), so that
     * request 404s, no worker registers, no push subscription is created, and
     * every send returns "All included players are not subscribed".
     *
     * Fix it in the dashboard (Settings > Web > Advanced Settings > Service
     * Worker: Customize, path /sdc-project/public, scope /sdc-project/public/).
     * We still pass our values so a customized config is honoured either way.
     */
    var workerScope = meta.getAttribute('data-worker-scope') || '';

    // The SDK's loader must be in the document BEFORE we queue anything: the
    // es6 bundle drains `window.OneSignalDeferred` once, at its own init. We
    // therefore inject the script first and queue afterwards, which is the
    // order the vendor snippet relies on.
    var script = document.createElement('script');
    script.src = 'https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js';
    script.defer = true;
    document.head.appendChild(script);

    window.OneSignalDeferred = window.OneSignalDeferred || [];
    OneSignalDeferred.push(async function (OneSignal) {
        // Passed so that a dashboard-customised worker config is honoured. A
        // default (uncustomised) OneSignal app ignores these and uses its own
        // origin-root config - see the note above.
        var workerPath = meta.getAttribute('data-worker-path') || '';
        var workerFile = meta.getAttribute('data-worker-file') || 'OneSignalSDKWorker.js';

        var initOptions = {
            appId: appId,
            allowLocalhostAsSecureOrigin: true,
            // The app owns all notification UI (our top-bar bell and the
            // on/off switch on Profile and Notification). Make sure OneSignal
            // never injects its own bell button, whatever the dashboard says.
            notifyButton: { enable: false },
            autoPrompt: false,
        };
        if (workerPath) {
            initOptions.path = workerPath;
            initOptions.serviceWorkerPath = workerFile;
        }
        if (workerScope) {
            initOptions.serviceWorkerParam = { scope: workerScope };
        }

        // Report first: if the service worker registration fails, init() rejects
        // and nothing after this line runs - so a diagnostic placed afterwards
        // would stay silent in exactly the case that needs explaining.
        window.__pushDiag = function () { return reportSubscription(OneSignal); };

        try {
            await OneSignal.init(initOptions);
        } catch (err) {
            console.error('[push] OneSignal.init failed:', err && err.message ? err.message : err);
            window.__pushDiag();
            return;
        }

        if (externalId) {
            // Without this, subscriptions stay anonymous.
            await OneSignal.login(externalId);
            await OneSignal.User.addTag('role', role);
        }

        /*
         * Re-link the alias once a push endpoint actually exists.
         *
         * On a fresh browser the FIRST visit runs ~two seconds before the user
         * accepts the permission prompt, so OneSignal.login() fires while the
         * player has no `identifier` yet. OneSignal creates the subscription but
         * leaves external_user_id EMPTY.
         *
         * That alias is what ties a subscription to one of OUR users: the admin
         * recipient picker is built from it (onesignal_subscribed_users() reads
         * external_user_id), and it is what a targeted send resolves to
         * subscription ids through. So an unlinked device is a device that
         * cannot be chosen - it never appears as a recipient at all.
         *
         * Calling login() again after the subscription exists re-associates the
         * alias (OneSignal re-syncs it on every call), which is what makes the
         * user show up in the picker. The listener makes it work even when the
         * user grants permission later in the same session, without a reload.
         *
         * Do NOT remove this: delivery does not depend on it, but addressing a
         * specific user does.
         */
        async function relinkAlias() {
            if (!externalId) return;
            var sub = OneSignal.User && OneSignal.User.PushSubscription;
            if (!sub || !sub.id) return;          // no endpoint yet - nothing to link
            try {
                await OneSignal.login(externalId);
                await OneSignal.User.addTag('role', role);
                console.info('[push] alias linked after subscription (' + externalId + ')');
            } catch (err) {
                console.warn('[push] alias re-link failed:', err);
            }
        }

        await relinkAlias();

        try {
            OneSignal.User.PushSubscription.addEventListener('change', relinkAlias);
        } catch (err) { /* older SDK: the reload path still links it */ }

        window.__pushDiag();
    });

    /* ------------------------------------------------------------- bell --- */
    var bell = document.querySelector('[data-notif-bell]');
    if (!bell) return;

    var feedUrl = bell.getAttribute('data-feed-url');
    var readUrl = bell.getAttribute('data-read-url');
    var badge = bell.querySelector('[data-notif-count]');
    var panel = bell.querySelector('[data-notif-panel]');
    var list  = bell.querySelector('[data-notif-list]');
    var loaded = false;

    function setCount(n) {
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.hidden = n <= 0;
    }

    function render(items) {
        if (!items.length) {
            list.innerHTML = '<p class="notif-panel__empty">Nothing yet.</p>';
            return;
        }
        list.innerHTML = items.map(function (n) {
            var when = new Date(n.created_at.replace(' ', 'T')).toLocaleString();
            return '<a class="notif-panel__item' + (n.is_read ? '' : ' is-unread') + '"'
                + (n.url ? ' href="' + UI.escapeHtml(n.url) + '"' : ' href="#"')
                + '><strong>' + UI.escapeHtml(n.title) + '</strong>'
                + '<span>' + UI.escapeHtml(n.body) + '</span>'
                + '<small>' + UI.escapeHtml(when) + '</small></a>';
        }).join('');
    }

    /* Shape-matched placeholder rows while the feed is in flight. */
    function sketch() {
        var bones = '<div class="skel-row skeleton"><i></i><i></i></div>';
        list.innerHTML = bones + bones + bones;
    }

    function load() {
        sketch();
        API.request(feedUrl).then(function (res) {
            if (!res.success) return;
            render(res.data.items || []);
            setCount(res.data.unread || 0);
            loaded = true;
        }).catch(function () { /* offline: leave the badge as-is */ });
    }

    function markRead() {
        API.post(readUrl, {}).then(function () {
            setCount(0);
        }).catch(function () { /* ignore */ });
    }

    window.addEventListener('DOMContentLoaded', load);
    bell.querySelector('[data-notif-toggle]').addEventListener('click', function (e) {
        e.stopPropagation();
        var open = panel.hidden;
        panel.hidden = !open;
        bell.querySelector('[data-notif-toggle]').setAttribute('aria-expanded', String(open));
        if (open) {
            if (!loaded) load();
            markRead();
        }
    });
    document.addEventListener('click', function (e) {
        if (!bell.contains(e.target)) { panel.hidden = true; }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { panel.hidden = true; }
    });
})();

/* ------------------------------------------------ unlink on sign-out --- */
(function () {
    'use strict';

    // The logout form posts to a confirm dialog; unlinking is best-effort and
    // must never block the navigation.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!/action=logout/.test(form.getAttribute('action') || '')) return;

        if (window.OneSignal) {
            try { window.OneSignal.logout(); } catch (err) { /* SDK not ready */ }
        }
    }, true);
})();

/* ------------------------------------------------ Profile push switch --- */
(function () {
    'use strict';

    var sw = document.querySelector('[data-push-switch]');
    if (!sw) return;
    var hint = document.querySelector('[data-push-hint]');

    window.OneSignalDeferred = window.OneSignalDeferred || [];
    OneSignalDeferred.push(async function (OneSignal) {
        // Reflect the current state; the SDK exposes both the browser permission
        // and whether this device actually has a subscription.
        var granted = OneSignal.Notifications.permission;
        sw.checked = granted;

        sw.addEventListener('change', async function () {
            if (sw.checked) {
                await OneSignal.Notifications.requestPermission();
                var ok = OneSignal.Notifications.permission;
                sw.checked = ok;
                if (hint) hint.textContent = ok
                    ? 'This device will receive notifications.'
                    : 'Your browser blocked notifications. Allow them in site settings.';
                if (ok) UI.toast('Notifications turned on.', 'success');
            } else {
                if (hint) hint.textContent = 'Turn this on to let your browser ask for permission.';
                UI.toast('Turn notifications off from your browser site settings.', 'info');
            }        });
    });
})();

