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

    /* ------------------------------------------------------- markdown ---- */
    /* Agnes replies are Markdown. Escaping happens FIRST, so anything the
       model echoes back is inert before any tag is added - the renderer can
       only ever emit the handful of tags listed below. */

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function inline(text) {
        return text
            .replace(/`([^`\n]+)`/g, '<code class="md-inline">$1</code>')
            .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>')
            .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g,
                '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    }

    function renderMarkdown(source) {
        var blocks = [];
        var text = escapeHtml(source).replace(/```[a-z]*\n?([\s\S]*?)```/g, function (_m, code) {
            blocks.push('<pre class="md-code"><code>' + code.replace(/\n+$/, '') + '</code></pre>');
            return '\u0000' + (blocks.length - 1) + '\u0000';
        });

        var out = [];
        var list = null;
        function closeList() { if (list) { out.push('</' + list + '>'); list = null; } }

        text.split('\n').forEach(function (line) {
            var bullet = line.match(/^\s*[-*]\s+(.*)$/);
            var numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
            var heading = line.match(/^#{1,6}\s+(.+)$/);

            if (bullet || numbered) {
                var tag = bullet ? 'ul' : 'ol';
                if (list !== tag) { closeList(); out.push('<' + tag + ' class="md-list">'); list = tag; }
                out.push('<li>' + inline((bullet || numbered)[1]) + '</li>');
            } else if (heading) {
                closeList();
                out.push('<p class="md-h">' + inline(heading[1]) + '</p>');
            } else if (line.trim() === '') {
                closeList();
            } else {
                closeList();
                out.push('<p>' + inline(line) + '</p>');
            }
        });
        closeList();

        return out.join('').replace(/\u0000(\d+)\u0000/g, function (_m, i) { return blocks[+i]; });
    }

    function scrollDown() { body.scrollTop = body.scrollHeight; }
    function hideEmpty() { if (empty) { empty.hidden = true; } }

    function bubble(role, text) {
        hideEmpty();
        var el = document.createElement('div');
        el.className = 'msg msg--' + role;

        var inner = document.createElement('div');
        inner.className = 'msg__text';
        if (role === 'bot') {
            inner.innerHTML = renderMarkdown(text);
        } else {
            // User text is never parsed - it stays literal.
            inner.textContent = text;
        }
        el.appendChild(inner);

        body.appendChild(el);
        scrollDown();
        return el;
    }

    // Server-rendered assistant messages arrive as escaped text; upgrade them.
    Array.prototype.forEach.call(document.querySelectorAll('[data-markdown]'), function (el) {
        el.innerHTML = renderMarkdown(el.textContent);
    });

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

            if (!env.success) {
                // Agnes failed - missing key, rejected key, rate limit, timeout or
                // an upstream error. Show the real reason; never invent a reply.
                hideEmpty();
                var errEl = document.createElement('div');
                errEl.className = 'msg msg--bot msg--error';
                errEl.textContent = env.message || 'The AI call failed.';
                body.appendChild(errEl);
                scrollDown();
                if (window.UI) { UI.toast(env.message || 'The AI call failed.', 'error'); }
                return;
            }

            bubble('bot', (env.data && env.data.reply) || env.message || 'No reply.');

            if (statusEl) {
                // The service answered, so it is reachable by definition.
                statusEl.className = 'badge badge--dot badge--ok badge--live';
                statusEl.textContent = 'Service online';
            }
        }).catch(function () {
            if (window.UI) { UI.setLoading(sendBtn, false); }
            pending.remove();
            bubble('bot', 'Network error. Please try again.').classList.add('msg--error');
        });
    });

    scrollDown();
})();
