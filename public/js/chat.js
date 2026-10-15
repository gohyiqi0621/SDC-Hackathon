/**
 * chat.js — the AI chat composer (IDs unchanged from v1 so views keep working).
 * Sends a message, appends the user bubble and the reply, handles attach + camera.
 * Photo capture is prototype-only: the image is previewed, only its name is sent.
 */
(function () {
    'use strict';

    var form = document.getElementById('chat-form');
    if (!form) { return; }

    var body        = document.getElementById('chat-body');
    var empty       = document.getElementById('chat-empty');
    var textarea    = document.getElementById('chat-text');
    var fileInput   = document.getElementById('chat-file');
    var imgName     = document.getElementById('chat-image-name');
    var preview     = document.getElementById('chat-preview');
    var previewImg  = document.getElementById('chat-preview-img');
    var previewName = document.getElementById('chat-preview-name');
    var cameraBox   = document.getElementById('chat-camera');
    var video       = document.getElementById('chat-video');
    var statusEl    = document.querySelector('[data-ai-status]');
    var sendBtn     = document.getElementById('chat-send');

    var stream = null;
    var hasImage = false;

    function scrollDown() { body.scrollTop = body.scrollHeight; }
    function hideEmpty() { if (empty) { empty.hidden = true; } }

    function bubble(role, text) {
        hideEmpty();
        var el = document.createElement('div');
        el.className = 'msg msg--' + role;
        el.textContent = text;
        body.appendChild(el);
        scrollDown();
        return el;
    }

    function typingBubble() {
        hideEmpty();
        var el = document.createElement('div');
        el.className = 'msg msg--bot';
        el.innerHTML = '<span class="typing" aria-label="Assistant is typing"><i></i><i></i><i></i></span>';
        body.appendChild(el);
        scrollDown();
        return el;
    }

    /* -------------------------------------------------------- attach ---- */
    function showPreview(name, dataUrl) {
        hasImage = true;
        imgName.value = name;
        previewName.textContent = name;
        previewImg.src = dataUrl || '';
        previewImg.hidden = !dataUrl;
        preview.hidden = false;
    }
    function clearPreview() {
        hasImage = false;
        imgName.value = '';
        if (fileInput) { fileInput.value = ''; }
        preview.hidden = true;
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var f = fileInput.files && fileInput.files[0];
            if (f) { showPreview(f.name, null); }
        });
    }
    var removeBtn = document.getElementById('chat-preview-remove');
    if (removeBtn) { removeBtn.addEventListener('click', clearPreview); }

    /* -------------------------------------------------------- camera ---- */
    var cameraBtn  = document.getElementById('chat-camera-btn');
    var captureBtn = document.getElementById('chat-capture');
    var cancelBtn  = document.getElementById('chat-camera-cancel');

    function stopCamera() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        cameraBox.hidden = true;
    }

    if (cameraBtn) {
        cameraBtn.addEventListener('click', function () {
            if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
                if (window.UI) { UI.toast('Camera is not supported in this browser.', 'error'); }
                return;
            }
            navigator.mediaDevices.getUserMedia({ video: true }).then(function (s) {
                stream = s; video.srcObject = s; video.play(); cameraBox.hidden = false;
            }).catch(function () {
                if (window.UI) { UI.toast('Camera is not available on this device.', 'error'); }
            });
        });
    }
    if (captureBtn) {
        captureBtn.addEventListener('click', function () {
            if (!video.videoWidth) { return; }
            var canvas = document.createElement('canvas');
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            showPreview('photo-' + Date.now() + '.png', canvas.toDataURL('image/png'));
            stopCamera();
        });
    }
    if (cancelBtn) { cancelBtn.addEventListener('click', stopCamera); }

    /* ----------------------------------------------------------- send --- */
    function autoGrow() {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 140) + 'px';
    }
    textarea.addEventListener('input', autoGrow);
    textarea.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (form.requestSubmit) { form.requestSubmit(); } else { form.dispatchEvent(new Event('submit', { cancelable: true })); }
        }
    });

    // Suggestion chips: <button data-suggest="Text">
    document.addEventListener('click', function (e) {
        var chip = e.target.closest && e.target.closest('[data-suggest]');
        if (!chip) { return; }
        textarea.value = chip.getAttribute('data-suggest');
        autoGrow();
        textarea.focus();
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var text = (textarea.value || '').trim();
        if (text === '' && !hasImage) {
            if (window.UI) { UI.toast('Type a message, or attach a photo.', 'error'); }
            return;
        }

        bubble('user', text !== '' ? text : '📷 Photo');

        var payload = new FormData();
        payload.append('csrf_token', (window.API && API.csrfToken()) || '');
        payload.append('session_id', form.querySelector('[name="session_id"]').value);
        payload.append('text', text);
        payload.append('image_name', imgName.value);
        if (fileInput && fileInput.files && fileInput.files[0] && !hasImage) {
            payload.append('attachment', fileInput.files[0]);
        }

        textarea.value = '';
        autoGrow();
        clearPreview();

        var pending = typingBubble();
        if (window.UI) { UI.setLoading(sendBtn, true); }

        fetch(form.action, {
            method: 'POST', body: payload, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': (window.API && API.csrfToken()) || '' }
        }).then(function (r) { return r.json(); }).then(function (env) {
            if (window.UI) { UI.setLoading(sendBtn, false); }
            pending.remove();

            var reply = (env.data && env.data.reply) || env.message || 'No reply.';
            var el = bubble('bot', reply);
            if (!env.success) {
                el.classList.add('msg--error');
                if (window.UI) { UI.toast(env.message || 'The AI call failed.', 'error'); }
            } else if (env.data && env.data.ok === false) {
                // The message was stored but the AI service was unreachable, so
                // a placeholder reply was saved. Say so out loud instead of
                // letting the user think the bot actually answered.
                el.classList.add('msg--error');
                if (window.UI) {
                    UI.toast('The AI service is offline — your message was saved, but no reply was generated.', 'error');
                }
            }
            if (statusEl) {
                var ok = !!(env.data && env.data.ok);
                statusEl.className = 'badge badge--dot ' + (ok ? 'badge--ok badge--live' : 'badge--danger');
                statusEl.textContent = ok ? 'Service online' : 'Service offline';
            }
        }).catch(function () {
            if (window.UI) { UI.setLoading(sendBtn, false); }
            pending.remove();
            bubble('bot', 'Network error. Please try again.').classList.add('msg--error');
        });
    });

    scrollDown();
})();
