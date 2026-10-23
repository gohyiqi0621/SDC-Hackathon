/**
 * conversations.js - live Customer Chat Interface (view only).
 *
 * Renders the feed embedded in the page, then polls the feed endpoint:
 *   ?chat=<channel:chat_id>&after=<last message id>
 * every 3 s (15 s while the tab is hidden). New messages are appended, the
 * chat list is re-sorted, and today's orders are refreshed. Everything the
 * customer wrote is inserted as text (never as HTML).
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-msgr]');
    var dataEl = document.getElementById('msgr-data');
    if (!root || !dataEl) { return; }

    var FAST = 3000, SLOW = 15000;
    var $ = function (sel) { return root.querySelector(sel); };
    var el = {
        list: $('[data-list]'), listEmpty: $('[data-list-empty]'), search: $('[data-search]'), count: $('[data-count]'),
        live: $('[data-live]'), main: $('[data-main]'), head: $('[data-head]'), headAvatar: $('[data-head-avatar]'),
        headName: $('[data-head-name]'), headSub: $('[data-head-sub]'), body: $('[data-body]'), jump: $('[data-jump]'),
        orders: $('[data-orders]'), ordersCount: $('[data-orders-count]'), ordersList: $('[data-orders-list]'), ordersAlert: $('[data-orders-alert]'),
        foot: $('[data-foot]'), placeholder: $('[data-placeholder]'), back: $('[data-back]')
    };

    var state = { conversations: [], current: null, lastId: 0, lastDay: '', timer: null, busy: false, failures: 0, seen: loadSeen() };

    /* ------------------------------------------------------------ helpers */

    function node(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text != null) { n.textContent = text; }
        return n;
    }

    function initials(name) {
        var parts = String(name || '?').trim().split(/\s+/u).filter(Boolean);
        var s = parts.length > 1 ? parts[0].charAt(0) + parts[parts.length - 1].charAt(0) : (parts[0] || '?').slice(0, 2);
        return s.toUpperCase();
    }

    function hue(key) {
        var h = 0;
        for (var i = 0; i < key.length; i++) { h = (h * 31 + key.charCodeAt(i)) % 360; }
        return h;
    }

    function avatar(target, c) {
        target.textContent = c.channel === 'web' ? '' : initials(c.label);
        target.style.setProperty('--h', hue(c.key));
        target.classList.toggle('msgr-avatar--web', c.channel === 'web');
        if (c.channel === 'web') { target.appendChild(node('i', 'fa-solid fa-globe')); }
    }

    function sameDay(a, b) { return a.toDateString() === b.toDateString(); }

    function shortTime(iso) {
        var d = new Date(iso), now = new Date();
        if (sameDay(d, now)) { return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
        var y = new Date(now); y.setDate(now.getDate() - 1);
        if (sameDay(d, y)) { return 'Yesterday'; }
        return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
    }

    function clock(iso) { return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }

    function dayLabel(iso) {
        var d = new Date(iso), now = new Date(), y = new Date(now);
        y.setDate(now.getDate() - 1);
        if (sameDay(d, now)) { return 'Today'; }
        if (sameDay(d, y)) { return 'Yesterday'; }
        return d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    }

    function loadSeen() {
        try { return JSON.parse(localStorage.getItem('msgr-seen') || '{}') || {}; } catch (e) { return {}; }
    }

    function markSeen(c) {
        if (!c) { return; }
        state.seen[c.key] = c.last_at;
        try { localStorage.setItem('msgr-seen', JSON.stringify(state.seen)); } catch (e) { /* private mode */ }
    }

    function nearBottom() { return el.body.scrollHeight - el.body.scrollTop - el.body.clientHeight < 80; }
    function toBottom() { el.body.scrollTop = el.body.scrollHeight; el.jump.hidden = true; }

    /* --------------------------------------------------------- chat list */

    function renderList() {
        var q = el.search.value.trim().toLowerCase();
        var shown = 0;
        el.list.textContent = '';
        state.conversations.forEach(function (c) {
            if (q && (c.label + ' ' + (c.username || '') + ' ' + c.last_message).toLowerCase().indexOf(q) === -1) { return; }
            shown++;
            var li = node('li');
            var a = node('a', 'msgr-item' + (state.current && state.current.key === c.key ? ' is-active' : ''));
            a.href = '?page=customer&action=chat&chat=' + encodeURIComponent(c.key);
            a.dataset.key = c.key;

            var av = node('span', 'msgr-avatar');
            avatar(av, c);
            if (c.channel === 'telegram') { av.appendChild(node('i', 'fa-brands fa-telegram msgr-avatar__badge')); }

            var text = node('span', 'msgr-item__text');
            var row1 = node('span', 'msgr-item__row');
            row1.appendChild(node('strong', null, c.label));
            row1.appendChild(node('time', null, shortTime(c.last_at)));
            var row2 = node('span', 'msgr-item__row');
            var preview = node('small', null, (c.last_role === 'assistant' ? 'AI: ' : '') + c.last_message);
            row2.appendChild(preview);
            var unread = (!state.current || state.current.key !== c.key) && (!state.seen[c.key] || state.seen[c.key] < c.last_at);
            if (unread) { row2.appendChild(node('span', 'msgr-dot', '')); a.classList.add('is-unread'); }

            text.appendChild(row1);
            text.appendChild(row2);
            a.appendChild(av);
            a.appendChild(text);
            li.appendChild(a);
            el.list.appendChild(li);
        });
        el.listEmpty.hidden = shown > 0 || state.conversations.length === 0;
        el.count.textContent = '· ' + state.conversations.length + ' chat' + (state.conversations.length === 1 ? '' : 's');
    }

    /* ---------------------------------------------------------- messages */

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

    function appendMessages(messages) {
        var stick = nearBottom() || state.lastId === 0;
        messages.forEach(function (m) {
            if (m.id <= state.lastId) { return; }
            state.lastId = m.id;

            var day = dayLabel(m.at);
            if (day !== state.lastDay) {
                state.lastDay = day;
                var sep = node('div', 'msgr-day');
                sep.appendChild(node('span', null, day));
                el.body.appendChild(sep);
            }

            if (m.event) {
                var ev = node('div', 'msgr-event');
                ev.appendChild(node('span', null, m.content));
                ev.appendChild(node('time', null, clock(m.at)));
                el.body.appendChild(ev);
                return;
            }

            var mine = m.role !== 'customer';
            var wrap = node('div', 'msgr-msg ' + (mine ? 'msgr-msg--ai' : 'msgr-msg--customer') + (m.status !== 'ok' ? ' msgr-msg--muted' : ''));
            var bubble = node('div', 'msgr-bubble');
            bubble.appendChild(node('div', 'msgr-bubble__text', m.content));
            var meta = node('div', 'msgr-bubble__meta');
            if (mine) { meta.appendChild(node('span', 'msgr-bubble__who', 'AI')); }
            meta.appendChild(node('time', null, clock(m.at)));
            if (m.status === 'error') { meta.appendChild(node('span', 'msgr-bubble__flag', 'fallback reply')); }
            if (m.status === 'skipped') { meta.appendChild(node('span', 'msgr-bubble__flag', 'not sent')); }
            bubble.appendChild(meta);
            wrap.appendChild(bubble);
            el.body.appendChild(wrap);
            if (m.problem) { el.body.appendChild(problemCard(m.problem, 'right')); }
        });
        if (messages.length) {
            if (stick) { toBottom(); } else { el.jump.hidden = false; }
        }
    }

    function renderOrders(orders) {
        el.orders.hidden = !state.current;
        el.ordersCount.textContent = orders.length;
        el.ordersList.textContent = '';

        var review = orders.filter(function (o) { return o.status === 'payment_submitted'; }).length;
        if (el.ordersAlert) {
            el.ordersAlert.hidden = review === 0;
            el.ordersAlert.textContent = review + ' to review';
        }

        if (!orders.length) {
            el.ordersList.appendChild(node('li', 'msgr-orders__empty', 'No orders from this customer today.'));
            return;
        }
        orders.forEach(function (o) {
            var li = node('li', 'msgr-order msgr-order--' + o.status);

            var id = node('div', 'msgr-order__id');
            var no = node('a', 'msgr-order__no', o.order_no);
            no.href = root.dataset.ordersUrl + '&q=' + encodeURIComponent(o.order_no);
            no.title = 'Open in Orders';
            id.appendChild(no);
            id.appendChild(node('time', null, clock(o.at)));

            var items = node('div', 'msgr-order__items', o.items || '—');
            items.title = o.items || '';

            var status = node('span', 'msgr-status msgr-status--' + o.status, o.label);

            var total = node('strong', 'msgr-order__total', o.total);

            var receipt = node('div', 'msgr-order__receipt');
            if (o.receipt) {
                var r = node('a', null);
                r.href = o.receipt; r.target = '_blank'; r.rel = 'noopener';
                r.title = 'View payment receipt';
                r.setAttribute('aria-label', 'View payment receipt for ' + o.order_no);
                r.appendChild(node('i', 'fa-regular fa-file-image'));
                receipt.appendChild(r);
            }

            [id, items, status, total, receipt].forEach(function (n) { li.appendChild(n); });
            el.ordersList.appendChild(li);
        });
    }

    function renderHead(c) {
        var has = !!c;
        el.head.hidden = !has;
        el.foot.hidden = !has;
        el.placeholder.hidden = has;
        root.classList.toggle('has-chat', has);
        if (!has) { el.orders.hidden = true; return; }
        avatar(el.headAvatar, c);
        el.headName.textContent = c.label;
        el.headSub.textContent = [c.username ? '@' + c.username : null,
            c.channel === 'telegram' ? 'Telegram · ' + c.chat_id : 'Website',
            c.messages + ' messages'].filter(Boolean).join(' · ');
    }

    /* -------------------------------------------------------------- feed */

    function apply(feed) {
        state.conversations = feed.conversations || [];
        var added = false;
        if (!state.ready) {
            // First load: chats never opened here start as read; new ones later show a dot.
            state.conversations.forEach(function (c) {
                if (!state.seen[c.key]) { state.seen[c.key] = c.last_at; added = true; }
            });
            state.ready = true;
        }
        if (added) { try { localStorage.setItem('msgr-seen', JSON.stringify(state.seen)); } catch (e) { /* private mode */ } }
        if (feed.reset) {
            el.body.textContent = '';
            state.lastId = 0;
            state.lastDay = '';
            state.current = feed.current || null;
        } else if (feed.current && state.current && feed.current.key === state.current.key) {
            state.current = feed.current;
        }
        renderHead(state.current);
        appendMessages(feed.messages || []);
        if (state.current) { renderOrders(feed.orders_today || []); markSeen(state.current); }
        renderList();
    }

    function feedUrl(after) {
        var u = new URL(root.dataset.feedUrl, window.location.href);
        if (state.current) { u.searchParams.set('chat', state.current.key); }
        u.searchParams.set('after', String(after));
        return u.toString();
    }

    function poll() {
        if (state.busy) { return schedule(); }
        state.busy = true;
        fetch(feedUrl(state.lastId), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (json) {
                if (!json || !json.success) { throw new Error('bad response'); }
                state.failures = 0;
                el.live.classList.remove('is-off');
                apply(json.data);
            })
            .catch(function () {
                state.failures++;
                if (state.failures >= 2) { el.live.classList.add('is-off'); }
            })
            .then(function () { state.busy = false; schedule(); });
    }

    function schedule() {
        clearTimeout(state.timer);
        state.timer = setTimeout(poll, document.hidden ? SLOW : FAST);
    }

    function open(key) {
        var c = state.conversations.filter(function (x) { return x.key === key; })[0];
        if (!c) { return; }
        state.current = c;
        state.lastId = 0;
        state.lastDay = '';
        el.body.textContent = '';
        renderHead(c);
        renderList();
        el.ordersList.textContent = '';
        history.replaceState(null, '', '?page=customer&action=chat&chat=' + encodeURIComponent(key));
        root.classList.add('show-chat');
        clearTimeout(state.timer);
        state.busy = false;
        poll();
    }

    /* ------------------------------------------------------------ events */

    el.list.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-key]');
        if (!a || e.ctrlKey || e.metaKey) { return; }
        e.preventDefault();
        open(a.dataset.key);
    });
    el.search.addEventListener('input', renderList);
    el.jump.addEventListener('click', toBottom);
    el.body.addEventListener('scroll', function () { if (nearBottom()) { el.jump.hidden = true; } });
    el.back.addEventListener('click', function () { root.classList.remove('show-chat'); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { clearTimeout(state.timer); poll(); } });

    var first = {};
    try { first = JSON.parse(dataEl.textContent) || {}; } catch (e) { first = {}; }
    first.reset = true;
    apply(first);
    if (state.current && new URLSearchParams(window.location.search).get('chat')) { root.classList.add('show-chat'); }
    toBottom();
    schedule();
})();
