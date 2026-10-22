/**
 * staff-chat.js - Staff > Chat Interface.
 *
 * Shows the saved history of this chat session, sends each message to
 * POST staff/chat_send (with the session key) and shows the assistant's reply.
 * "New chat" is a plain link to a new session; the first message of a new
 * session puts it in the address bar and in the Chats list.
 *
 * Email drafts: lines starting with "> " in a reply are an email draft. The
 * latest draft gets "Send this reply" (asks the assistant to send it) and
 * "Edit" (puts the text in the composer to change and send yourself). Replies are rendered with light formatting
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
    var session = root.getAttribute('data-session') || 'main';
    var fresh = root.getAttribute('data-new') === '1';

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

    /**
     * Light markdown: paragraphs, "- " / "• " bullets, "1. " lists, ### headings,
     * "> " quotes, ``` code blocks. A ```draft block is an email draft (shown
     * with Send / Edit); so is a "> " quote right after a line mentioning "draft"
     * (older replies).
     */
    function render(text) {
        var wrap = node('div', 'msgr-bubble__text msgr-rich');
        var list = null, listType = '', quote = null, fence = null, lastText = '';
        String(text || '').replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
            var f = /^\s*```\s*([A-Za-z]*)\s*$/.exec(line);
            if (fence) {
                if (f && f[1] === '') { fence = null; return; }
                fence.draftLines.push(line);
                if (fence.tagName === 'PRE') { fence.textContent += (fence.textContent ? '\n' : '') + line; }
                else {
                    var fl = node('p');
                    if (line.trim() === '') { fl.className = 'msgr-draft__gap'; } else { fl.appendChild(document.createTextNode(line)); }
                    fence.appendChild(fl);
                }
                return;
            }
            if (f) {
                list = null; quote = null;
                fence = f[1].toLowerCase() === 'draft' ? node('blockquote', 'msgr-draft') : node('pre', 'msgr-code');
                fence.draftLines = [];
                wrap.appendChild(fence);
                return;
            }
            // Markdown table rows ("| a | b |"): shown as a small table; the "|---|" line is skipped.
            if (/^\s*\|.*\|\s*$/.test(line)) {
                list = null; quote = null;
                if (/^\s*\|[\s:|-]+\|\s*$/.test(line)) { return; }
                var cells = line.trim().replace(/^\||\|$/g, '').split('|');
                var last = wrap.lastChild;
                var table = last && last.tagName === 'TABLE' ? last : null;
                if (!table) { table = node('table', 'msgr-table'); wrap.appendChild(table); }
                var tr = node('tr');
                cells.forEach(function (cell) {
                    var td = node(table.rows.length === 0 ? 'th' : 'td');
                    inline(td, cell.trim());
                    tr.appendChild(td);
                });
                table.appendChild(tr);
                return;
            }
            var q = /^\s*>\s?(.*)$/.exec(line);
            if (q) {
                list = null;
                if (!quote) {
                    quote = node('blockquote', /draft/i.test(lastText) ? 'msgr-draft' : 'msgr-quote');
                    quote.draftLines = [];
                    wrap.appendChild(quote);
                }
                quote.draftLines.push(q[1]);
                var ql = node('p');
                if (q[1].trim() === '') { ql.className = 'msgr-draft__gap'; } else { inline(ql, q[1]); }
                quote.appendChild(ql);
                return;
            }
            if (quote && line.trim() === '') { return; }
            quote = null;
            var bullet = /^\s*[-•*]\s+(.*)$/.exec(line);
            var num = /^\s*(\d+)[.)]\s+(.*)$/.exec(line);
            var head = /^\s*#{1,4}\s+(.*)$/.exec(line);
            if (line.trim() !== '') { lastText = line; }
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
            var p = node('p', head ? 'msgr-rich__head' : null);
            inline(p, head ? head[1] : line);
            wrap.appendChild(p);
        });
        return wrap;
    }

    /* ---- email cards (from the tools the assistant used) ---- */
    function person(raw) {
        raw = String(raw || '').trim();
        var m = /^"?([^"<]*?)"?\s*<([^>]+)>/.exec(raw);
        var name = m ? m[1].trim() : '', addr = m ? m[2].trim() : raw;
        return { name: name || addr.split('@')[0] || raw, addr: addr, full: raw };
    }
    function shortDate(text) {
        var m = /^\w+ (\d+ \w+ \d{4}), (.+)$/.exec(String(text || ''));
        if (!m) { return text || ''; }
        var now = new Date();
        var today = now.getDate() + ' ' + now.toLocaleDateString('en-GB', { month: 'short' }) + ' ' + now.getFullYear();
        return m[1] === today ? m[2] : m[1].replace(/ \d{4}$/, '');
    }
    var FOLDERS = { inbox: 'Inbox', important: 'Important', unread: 'Unread', starred: 'Starred', sent: 'Sent', spam: 'Spam',
                    trash: 'Trash', primary: 'Primary', social: 'Social', promotions: 'Promotions', updates: 'Updates', all: 'All mail' };

    function emailsCard(c) {
        var card = node('div', 'mcard mcard--emails');
        var head = node('div', 'mcard__head');
        head.innerHTML = '<i class="fa-regular fa-envelope" aria-hidden="true"></i>';
        head.appendChild(node('strong', null, FOLDERS[c.folder] || c.folder || 'Emails'));
        var items = c.items || [];
        var unread = items.filter(function (e) { return e.unread; }).length;
        head.appendChild(node('span', 'mcard__count', items.length + (items.length === 1 ? ' email' : ' emails') + (unread ? ' · ' + unread + ' unread' : '')));
        if (c.search) { head.appendChild(node('span', 'mcard__chip', '“' + c.search + '”')); }
        if (c.period && c.period !== 'any date') { head.appendChild(node('span', 'mcard__chip', c.period)); }
        card.appendChild(head);
        if (!items.length) { card.appendChild(node('p', 'mcard__empty', 'No emails found.')); return card; }
        var ul = node('ul', 'mcard__list');
        items.forEach(function (e) {
            var who = person(c.folder === 'sent' ? e.to : e.from);
            var li = node('li');
            var row = node('button', 'mcard__row' + (e.unread ? ' is-unread' : ''));
            row.type = 'button';
            row.title = 'Open this email';
            row.appendChild(node('span', 'mcard__no', String(e.no)));
            row.appendChild(node('span', 'mcard__avatar', (who.name.charAt(0) || '?').toUpperCase()));
            var main = node('span', 'mcard__main');
            var top = node('span', 'mcard__top');
            top.appendChild(node('span', 'mcard__from', (c.folder === 'sent' ? 'To: ' : '') + who.name));
            if (e.important) { top.appendChild(node('span', 'mcard__tag', 'Important')); }
            top.appendChild(node('span', 'mcard__date', shortDate(e.date)));
            main.appendChild(top);
            main.appendChild(node('span', 'mcard__subject', e.subject || '(no subject)'));
            if (e.snippet) { main.appendChild(node('span', 'mcard__snippet', e.snippet)); }
            row.appendChild(main);
            row.addEventListener('click', function () { send('Open email #' + e.no + ' (' + (e.subject || 'no subject') + ')'); });
            li.appendChild(row);
            ul.appendChild(li);
        });
        card.appendChild(ul);
        return card;
    }

    function emailCard(c) {
        var card = node('div', 'mcard mcard--email');
        var from = person(c.from);
        var head = node('div', 'mcard__mail-head');
        head.appendChild(node('span', 'mcard__avatar mcard__avatar--lg', (from.name.charAt(0) || '?').toUpperCase()));
        var who = node('div', 'mcard__mail-who');
        who.appendChild(node('strong', null, c.subject || '(no subject)'));
        var line = node('small');
        line.appendChild(node('b', null, from.name));
        line.appendChild(document.createTextNode(' <' + from.addr + '> · ' + (c.date || '')));
        who.appendChild(line);
        if (c.to) { who.appendChild(node('small', null, 'To: ' + c.to)); }
        head.appendChild(who);
        card.appendChild(head);
        var text = node('div', 'mcard__body', c.body || '');
        card.appendChild(text);
        var bar = node('div', 'mcard__actions');
        if ((c.body || '').length > 420 || (c.body || '').split('\n').length > 8) {
            text.classList.add('is-clipped');
            var more = node('button', 'mcard__btn', 'Show full email');
            more.type = 'button';
            more.addEventListener('click', function () {
                var clipped = text.classList.toggle('is-clipped');
                more.textContent = clipped ? 'Show full email' : 'Show less';
            });
            bar.appendChild(more);
        }
        var reply = node('button', 'mcard__btn mcard__btn--primary', 'Draft a reply');
        reply.type = 'button';
        reply.addEventListener('click', function () { send('Draft a reply to this email from ' + from.name + '.'); });
        bar.appendChild(reply);
        card.appendChild(bar);
        return card;
    }

    function sentCard(c) {
        var card = node('div', 'mcard mcard--sent');
        card.innerHTML = '<i class="fa-solid fa-circle-check" aria-hidden="true"></i>';
        var t = node('span');
        t.appendChild(node('strong', null, 'Sent to ' + person(c.to).name));
        t.appendChild(node('small', null, c.subject || ''));
        card.appendChild(t);
        return card;
    }

    /** Receipt to review: the picture, the order, and Approve / Reject buttons (staff decide, not the AI). */
    function receiptCard(c) {
        var card = node('div', 'mcard mcard--receipt');
        var head = node('div', 'mcard__head');
        head.innerHTML = '<i class="fa-solid fa-receipt" aria-hidden="true"></i>';
        head.appendChild(node('strong', null, 'Receipt · ' + (c.order_no || '')));
        var badge = node('span', 'rcpt-pill');
        head.appendChild(badge);
        card.appendChild(head);

        var grid = node('div', 'rcpt');
        var pic = node('a', 'rcpt__pic');
        pic.href = c.receipt_url || '#';
        pic.target = '_blank';
        pic.rel = 'noopener';
        pic.title = 'Open the receipt in full size';
        if (/^image\//.test(c.receipt_type || '')) {
            var img = node('img');
            img.src = c.receipt_url;
            img.alt = 'Payment receipt of ' + (c.order_no || 'the order');
            img.loading = 'lazy';
            pic.appendChild(img);
            pic.appendChild(node('span', 'rcpt__zoom', 'Click to enlarge'));
        } else {
            pic.classList.add('rcpt__pic--file');
            pic.innerHTML = '<i class="fa-regular fa-file-pdf" aria-hidden="true"></i>';
            pic.appendChild(node('span', null, 'Open receipt (PDF)'));
        }
        grid.appendChild(pic);

        var info = node('dl', 'rcpt__info');
        [['Customer', c.customer + (c.phone ? ' · ' + c.phone : '')], ['Order total', c.total],
         ['Items', (c.items || []).join(', ')], ['Ordered', c.ordered_at], ['Receipt sent', c.receipt_sent_at]].forEach(function (row) {
            if (!row[1]) { return; }
            info.appendChild(node('dt', null, row[0]));
            info.appendChild(node('dd', row[0] === 'Order total' ? 'rcpt__total' : null, row[1]));
        });
        grid.appendChild(info);
        card.appendChild(grid);

        var bar = node('div', 'mcard__actions rcpt__actions');
        var msg = node('p', 'rcpt__msg');
        card.appendChild(bar);
        card.appendChild(msg);

        function state(status, label) {
            c.status = status;
            badge.className = 'rcpt-pill rcpt-pill--' + (status === 'payment_submitted' ? 'warn' : (status === 'payment_rejected' ? 'bad' : 'ok'));
            badge.textContent = label || status;
            bar.innerHTML = '';
            if (status !== 'payment_submitted') { return; }
            var ok = node('button', 'mcard__btn mcard__btn--ok', 'Approve payment');
            ok.type = 'button';
            ok.addEventListener('click', function () { decide('approve', ''); });
            var no = node('button', 'mcard__btn mcard__btn--bad', 'Reject');
            no.type = 'button';
            no.addEventListener('click', function () {
                bar.innerHTML = '';
                var why = node('input', 'rcpt__reason');
                why.type = 'text';
                why.maxLength = 300;
                why.placeholder = 'Reason for the customer, e.g. amount paid is less than the total';
                var go = node('button', 'mcard__btn mcard__btn--bad', 'Reject payment');
                go.type = 'button';
                go.addEventListener('click', function () { decide('reject', why.value); });
                var back = node('button', 'mcard__btn', 'Cancel');
                back.type = 'button';
                back.addEventListener('click', function () { state(c.status, badge.textContent); });
                bar.appendChild(why);
                bar.appendChild(go);
                bar.appendChild(back);
                why.focus();
            });
            bar.appendChild(ok);
            bar.appendChild(no);
        }

        function decide(decision, reason) {
            bar.querySelectorAll('button, input').forEach(function (el) { el.disabled = true; });
            msg.textContent = 'Saving…';
            var data = new FormData();
            data.append('id', String(c.order_id || ''));
            data.append('decision', decision);
            data.append('reason', reason || '');
            fetch(root.getAttribute('data-decide-url'), {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() }
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    var d = (res && res.data) || {};
                    msg.textContent = (res && res.message) || '';
                    msg.className = 'rcpt__msg ' + (res && res.success ? 'is-ok' : 'is-bad');
                    if (d.status) { state(d.status, d.status_label); }
                    else { bar.querySelectorAll('button, input').forEach(function (el) { el.disabled = false; }); }
                })
                .catch(function () {
                    msg.textContent = 'Network error. Please try again.';
                    msg.className = 'rcpt__msg is-bad';
                    bar.querySelectorAll('button, input').forEach(function (el) { el.disabled = false; });
                });
        }

        state(c.status || 'payment_submitted', c.status_label || 'Receipt to review');
        if (c.others_waiting) { card.appendChild(node('p', 'rcpt__more', c.others_waiting + ' more receipt' + (c.others_waiting > 1 ? 's' : '') + ' waiting for review.')); }
        return card;
    }

    function cards(list) {
        var box = node('div', 'mcards');
        (list || []).forEach(function (c) {
            if (c.type === 'emails') { box.appendChild(emailsCard(c)); }
            else if (c.type === 'email') { box.appendChild(emailCard(c)); }
            else if (c.type === 'sent') { box.appendChild(sentCard(c)); }
            else if (c.type === 'receipt') { box.appendChild(receiptCard(c)); }
        });
        return box.childNodes.length ? box : null;
    }

    /** Staff-only card under a reply the AI could not give: why, and how to fix it. */
    function problemCard(pr, side) {
        var card = node('div', 'msgr-problem msgr-problem--' + side);
        var head = node('div', 'msgr-problem__head');
        head.appendChild(node('i', 'fa-solid fa-triangle-exclamation'));
        head.appendChild(node('strong', null, pr.title || 'The AI could not answer'));
        head.appendChild(node('span', 'msgr-problem__tag', 'Only staff see this'));
        card.appendChild(head);
        if (pr.why) { card.appendChild(node('p', null, pr.why)); }
        if (pr.fix) {
            var fix = node('p', 'msgr-problem__fix');
            fix.appendChild(node('b', null, 'How to fix: '));
            fix.appendChild(document.createTextNode(pr.fix));
            card.appendChild(fix);
        }
        if (pr.detail) {
            var det = node('details');
            det.appendChild(node('summary', null, 'Technical detail'));
            det.appendChild(node('code', null, pr.detail));
            card.appendChild(det);
        }
        return card;
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

    /** "Send this reply" / "Edit" under the latest email draft only. */
    function draftActions(wrap) {
        body.querySelectorAll('.msgr-draft__actions').forEach(function (a) { a.remove(); });
        var drafts = wrap ? wrap.querySelectorAll('.msgr-draft') : [];
        if (!drafts.length) { return; }
        var draft = drafts[drafts.length - 1];
        if (!draft.draftLines) { return; }
        var text = draft.draftLines.join('\n').replace(/\*\*/g, '').trim();
        var bar = node('div', 'msgr-draft__actions');
        var sendIt = node('button', 'msgr-draft__send', null);
        sendIt.type = 'button';
        sendIt.innerHTML = '<i class="fa-solid fa-paper-plane" aria-hidden="true"></i> ';
        sendIt.appendChild(document.createTextNode('Send'));
        sendIt.addEventListener('click', function () { send('Yes, send it.'); });
        var edit = node('button', 'msgr-draft__edit', null);
        edit.type = 'button';
        edit.innerHTML = '<i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> ';
        edit.appendChild(document.createTextNode('Edit'));
        edit.addEventListener('click', function () {
            input.value = 'Send this version:\n' + text;
            autosize();
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        });
        bar.appendChild(sendIt);
        bar.appendChild(edit);
        draft.insertAdjacentElement('afterend', bar);
    }

    /** who: 'me' (staff) or 'ai'. */
    function add(who, text, opts) {
        opts = opts || {};
        var at = opts.at ? new Date(opts.at) : new Date();
        var stick = nearBottom() || opts.force;
        addDay(at);
        var wrap = node('div', 'msgr-msg ' + (who === 'me' ? 'msgr-msg--self' : 'msgr-msg--peer') + (opts.muted ? ' msgr-msg--muted' : ''));
        var box = who === 'ai' ? cards(opts.cards) : null;
        var bubble = node('div', 'msgr-bubble' + (box ? ' msgr-bubble--cards' : ''));
        if (box) { bubble.appendChild(box); }
        if (who === 'me' || String(text || '').trim() !== '') {
            bubble.appendChild(who === 'me' ? node('div', 'msgr-bubble__text', text) : render(text));
        }
        var meta = node('div', 'msgr-bubble__meta');
        meta.appendChild(node('time', null, clock(at)));
        if (opts.flag) { meta.appendChild(node('span', 'msgr-bubble__flag', opts.flag)); }
        bubble.appendChild(meta);
        wrap.appendChild(bubble);
        body.appendChild(wrap);
        if (opts.problem) { body.appendChild(problemCard(opts.problem, 'left')); }
        draftActions(who === 'ai' && !opts.muted ? wrap : null);
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

        if (welcome && welcome.parentNode) { welcome.remove(); }
        started(text);

        var data = new FormData();
        data.append('message', text);
        data.append('session', session);
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
                if (d.history_saved === false) { note('This chat could not be saved, so it will be gone after you leave the page. Run "php bin/migrate.php" (see Logs for the reason).'); }
                if (d.reply) { add('ai', d.reply, { force: true, cards: d.cards, muted: d.status === 'error', flag: d.status === 'error' ? 'not answered' : null, problem: d.problem }); }
                else if (d.problem) { body.appendChild(problemCard(d.problem, 'left')); toBottom(); }
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

    /** First message of a new chat: keep it in the address bar and name it in the Chats list. */
    function started(text) {
        if (!fresh) { return; }
        fresh = false;
        try { window.history.replaceState(null, '', root.getAttribute('data-session-url')); } catch (e) { /* ignore */ }
        var title = document.querySelector('[data-session-title]');
        var t = text.replace(/\s+/g, ' ').trim();
        if (title) { title.textContent = t.length > 60 ? t.slice(0, 59) + '…' : t; }
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

    /* ---- history ---- */
    var data = {};
    try { data = JSON.parse(document.getElementById('staff-chat-data').textContent) || {}; } catch (e) { data = {}; }
    var history = data.history || [];
    if (history.length && welcome) { welcome.remove(); }
    history.forEach(function (m) {
        if (m.status === 'skipped') { return; }
        add(m.role === 'me' ? 'me' : 'ai', m.content, { at: m.at, cards: m.cards, muted: m.status === 'error', force: true, problem: m.problem });
    });
    toBottom();
    input.focus();
})();
