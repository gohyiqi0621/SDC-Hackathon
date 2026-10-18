/**
 * location.js - request and report the browser's location permission.
 *
 * Deliberately independent of push: `geolocation` and `notifications` are two
 * separate browser grants, and nothing here is chained off the push switch, so a
 * user can have either one, both, or neither. Granting notifications never asks
 * for a location and vice versa.
 *
 * Two rules this file exists to enforce:
 *   1. Never prompt on load. `permissions.query()` reports the state without
 *      opening a dialog; only a real click may call getCurrentPosition().
 *   2. A denial is terminal. Once denied, the browser will not prompt again for
 *      this origin - the only route back is the site settings, so say that
 *      instead of offering a button that silently does nothing.
 *
 * No coordinates are stored: the server has no consumer for them yet, and
 * persisting a location without one would be a privacy cost with no benefit.
 * `window.__sdcLocation()` resolves a fix on demand for a future feature.
 */
(function () {
    'use strict';

    var btn = document.querySelector('[data-geo-btn]');
    var hint = document.querySelector('[data-geo-hint]');
    if (!btn || !hint) return;

    function setHint(text) { hint.textContent = text; }
    function setBtn(label, disabled) { btn.textContent = label; btn.disabled = !!disabled; }

    var supported = !!(navigator.geolocation && window.isSecureContext);

    // On-demand fix for anything that needs one later.
    window.__sdcLocation = function (options) {
        return new Promise(function (resolve, reject) {
            if (!supported) { reject(new Error('geolocation unavailable')); return; }
            navigator.geolocation.getCurrentPosition(resolve, reject, options || { timeout: 10000 });
        });
    };

    if (!supported) {
        setBtn('Unavailable', true);
        setHint('This browser cannot share a location here. A secure (HTTPS) page is required.');
        return;
    }

    function stateHint(state) {
        if (state === 'granted') return 'Location is allowed for this site.';
        if (state === 'denied') return 'Location is blocked. Allow it in your browser site settings, then reload.';
        return 'Location has not been requested. Nothing is read until you press Allow.';
    }

    function reflect(state) {
        setHint(stateHint(state));
        setBtn(state === 'denied' ? 'Blocked' : (state === 'granted' ? 'Refresh' : 'Allow'),
               state === 'denied');
    }

    // Read-only: this never triggers a prompt.
    function readState() {
        if (!navigator.permissions || !navigator.permissions.query) {
            return Promise.resolve('prompt');
        }
        return navigator.permissions.query({ name: 'geolocation' })
            .then(function (status) { return status.state; })
            .catch(function () { return 'prompt'; });
    }

    readState().then(reflect);

    btn.addEventListener('click', function () {
        btn.disabled = true;
        setHint('Requesting location\u2026');

        navigator.geolocation.getCurrentPosition(function () {
            reflect('granted');
        }, function (err) {
            var code = err && err.code;
            if (code === 1) {                    // PERMISSION_DENIED - terminal
                reflect('denied');
            } else if (code === 3) {             // TIMEOUT
                setBtn('Try again', false);
                setHint('The request timed out. Try again somewhere with a better signal.');
            } else {                             // POSITION_UNAVAILABLE
                setBtn('Try again', false);
                setHint('Your location is not available right now.');
            }
        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
    });
})();
