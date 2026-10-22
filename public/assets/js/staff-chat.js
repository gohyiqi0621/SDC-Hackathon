/**
 * staff-chat.js - Staff > Chat Interface.
 *
 * Shows the saved history, sends each message to POST staff/chat_send and
 * shows the assistant's reply. Replies are rendered with light formatting
 * (**bold**, bullet and numbered lists, line breaks); everything is inserted
 * as text, never as raw HTML.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-staff-chat]');
    if (!root) { return; }

    var body = root.querySelector('[data-body]');
    var form = root.querySelector('[data-composer]');
    var input = root.querySelector('[data-input]');
    var sendBtn = root.querySelector('[data-send]');
    var jump = root.querySelector('[data-jump]');
    var welcome = root.querySelector('[data-welcome]');
    var busy = false;
    var lastDay = '';

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function node(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text != null) { n.textContent = text; }
        return n;
    }

    function sameDay(a, b) { return a.toDateString() === b.toDateString(); }
    function clock(d) { return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
    function dayLabel(d) {
        var now = new Date(), y = new Date(now);
        y.setDate(now.getDate() - 1);
        if (sameDay(d, now)) { return 'Today'; }
        if (sameDay(d, y)) { return 'Yesterday'; }
        return d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    }

    /** **bold** inside one line, as text nodes. */
    function inline(target, text) {
        String(text).split(/(\*\*[^*]+\*\*)/g).forEach(function (part) {
            if (/^\*\*[^*]+\*\*$/.test(part)) { target.appendChild(node('strong', null, part.slice(2, -2))); }
            else if (part) { target.appendChild(document.createTextNode(part.replace(/`/g, ''))); }
        });
    }

    /** Light markdown: paragraphs, "- " / "• " bullets, "1. " lists, ### headings. */
    function render(text) {
        var wrap = node('div', 'msgr-bubble__text msgr-rich');
        var list = null, listType = '';
        String(text || '').replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
            var bullet = /^\s*[-•*]\s+(.*)$/.exec(line);
            var num = /^\s*(\d+)[.)]\s+(.*)$/.exec(line);
            var head = /^\s*#{1,4}\s+(.*)$/.exec(line);
            if (bullet || num) {
                var type = bullet ? 'ul' : 'ol';
                if (!list || listType !== type) { list = node(type); listType = type; wrap.appendChild(list); }
                var li = node('li');
                inline(li, bullet ? bullet[1] : num[2]);
                list.appendChild(li);
                return;
            }
            list = null;
            if (line.trim() === '') { return; }
            var p = node(head ? 'p' : 'p', head ? 'msgr-rich__head' : null);
            inline(p, head ? head[1] : line);
            wrap.appendChild(p);
        });
        return wrap;
    }

    function nearBottom() { return body.scrollHeight - body.scrollTop - body.clientHeight < 80; }
    function toBottom() { body.scrollTop = body.scrollHeight; jump.hidden = true; }

    function addDay(d) {
        var label = dayLabel(d);
        if (label === lastDay) { return; }
        lastDay = label;
        var sep = node('div', 'msgr-day');
        sep.appendChild(node('span', null, label));
        body.appendChild(sep);
    }

    /** who: 'me' (staff) or 'ai'. */
    function add(who, text, opts) {
        opts = opts || {};
        var at = opts.at ? new Date(opts.at) : new Date();
        var stick = nearBottom() || opts.force;
        addDay(at);
        var wrap = node('div', 'msgr-msg ' + (who === 'me' ? 'msgr-msg--self' : 'msgr-msg--peer') + (opts.muted ? ' msgr-msg--muted' : ''));
        var bubble = node('div', 'msgr-bubble');
        bubble.appendChild(who === 'me' ? node('div', 'msgr-bubble__text', text) : render(text));
        var meta = node('div', 'msgr-bubble__meta');
        meta.appendChild(node('time', null, clock(at)));
        if (opts.flag) { meta.appendChild(node('span', 'msgr-bubble__flag', opts.flag)); }
        bubble.appendChild(meta);
        wrap.appendChild(bubble);
        body.appendChild(wrap);
        if (stick) { toBottom(); } else { jump.hidden = false; }
        return wrap;
    }

    function note(text) {
        var n = node('div', 'msgr-event');
        n.appendChild(node('span', null, text));
        body.appendChild(n);
        toBottom();
    }

    function typing(on) {
        var el = body.querySelector('.staff-typing');
        if (on && !el) {
            el = node('div', 'msgr-msg msgr-msg--peer staff-typing');
            var b = node('div', 'msgr-bubble');
            b.appendChild(node('span')); b.appendChild(node('span')); b.appendChild(node('span'));
            el.appendChild(b);
            body.appendChild(el);
            toBottom();
        } else if (!on && el) {
            el.remove();
        }
    }

    function autosize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    }

    function send(text) {
        text = String(text || '').trim();
        if (!text || busy) { return; }
        busy = true;
        sendBtn.disabled = true;
        add('me', text, { force: true });
        input.value = '';
        autosize();
        typing(true);

        var data = new FormData();
        data.append('message', text);
        fetch(root.getAttribute('data-send-url'), {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() }
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                typing(false);
                if (!res || !res.success) { note((res && res.message) || 'The message could not be sent.'); return; }
                var d = res.data || {};
                if (d.reply) { add('ai', d.reply, { force: true, muted: d.status === 'error', flag: d.status === 'error' ? 'not answered' : null }); }
                if (d.status === 'skipped') { note('No reply: the staff assistant is switched off or not saved in Staff > Setting & Integration.'); }
                else if (d.status === 'error' && d.error) { note('Reason: ' + d.error); }
            })
            .catch(function () {
                typing(false);
                note('Network error. Please try again.');
            })
            .then(function () {
                busy = false;
                sendBtn.disabled = false;
                input.focus();
            });
    }

    /* ---- events ---- */
    form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(input.value); }
    });
    input.addEventListener('input', autosize);
    jump.addEventListener('click', toBottom);
    body.addEventListener('scroll', function () { if (nearBottom()) { jump.hidden = true; } });
    root.querySelectorAll('[data-suggest]').forEach(function (chip) {
        chip.addEventListener('click', function () { send(chip.textContent); });
    });

    /* ---- what Flowise receives ---- */
    var dialog = document.querySelector('[data-preview-dialog]');
    var openBtn = root.querySelector('[data-preview-open]');
    if (dialog && openBtn) {
        openBtn.addEventListener('click', function () {
            if (typeof dialog.showModal === 'function') { dialog.showModal(); }
            fetch(root.getAttribute('data-preview-url'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    var d = (res && res.data) || {};
                    dialog.querySelector('[data-preview-system]').textContent = d.system_message || '(not available)';
                    dialog.querySelector('[data-preview-payload]').textContent = JSON.stringify(d.payload || {}, null, 2);
                })
                .catch(function () { dialog.querySelector('[data-preview-system]').textContent = 'Could not load.'; });
        });
        dialog.querySelector('[data-preview-close]').addEventListener('click', function () { dialog.close(); });
    }

    /* ---- history ---- */
    var data = {};
    try { data = JSON.parse(document.getElementById('staff-chat-data').textContent) || {}; } catch (e) { data = {}; }
    var history = data.history || [];
    if (history.length && welcome) { welcome.remove(); }
    history.forEach(function (m) {
        if (m.status === 'skipped') { return; }
        add(m.role === 'me' ? 'me' : 'ai', m.content, { at: m.at, muted: m.status === 'error', force: true });
    });
    toBottom();
    input.focus();
})();
