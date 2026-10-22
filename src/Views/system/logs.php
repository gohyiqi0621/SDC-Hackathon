<?php
/**
 * system/logs.php - the flow log, live (polls system/logs_feed every 3 s).
 *
 * Expects: $days (YYYY-MM-DD, newest first), $feedUrl.
 * Optional: $bare (opened with ?key= and not signed in: no app shell).
 */
$bare = $bare ?? false;
if ($bare) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>Flow log</title><link rel="stylesheet" href="' . e(asset_versioned('assets/css/app.css')) . '"></head><body style="padding:0 16px 24px">';
} else {
    view('layouts/header', ['title' => $title]);
}
?>
<style>
.flog { display: grid; gap: 14px; padding-top: 24px; }
.flog-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.flog-bar h1 { margin: 0 auto 0 0; font-size: 22px; font-weight: 800; }
.flog-bar select, .flog-bar input { height: 36px; padding: 0 10px; border: 1px solid var(--border); border-radius: 10px; background: #fff; font: inherit; font-size: 13px; }
.flog-live { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--success); }
.flog-live i { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.flog-live.is-paused { color: var(--muted); }
.flog-list { border: 1px solid var(--border); border-radius: var(--radius); background: #fff; overflow: hidden; }
.flog-req { border-top: 6px solid #f4f4fb; }
.flog-req:first-child { border-top: 0; }
.flog-row { display: grid; grid-template-columns: 74px 230px minmax(0, 1fr); gap: 12px; padding: 7px 14px; font-size: 12.5px; border-bottom: 1px solid var(--line); }
.flog-row time { color: var(--muted); font-variant-numeric: tabular-nums; }
.flog-step { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 700; overflow-wrap: anywhere; }
.flog-row--tool .flog-step { color: #2f6fdb; }
.flog-row--flowise .flog-step { color: var(--primary); }
.flog-row--warn { background: #fffaf0; }
.flog-row--warn .flog-step { color: #a86f05; }
.flog-row--bad { background: #fff5f7; }
.flog-row--bad .flog-step { color: var(--danger); }
.flog-details { display: flex; flex-wrap: wrap; gap: 4px 12px; min-width: 0; color: var(--text-soft); }
.flog-details span { overflow-wrap: anywhere; }
.flog-details b { color: var(--muted); font-weight: 600; }
.flog-empty { padding: 30px; text-align: center; color: var(--muted); }
@media (max-width: 760px) { .flog-row { grid-template-columns: 1fr; gap: 2px; } }
</style>
<div class="page-body flog" data-flog data-feed-url="<?= e($feedUrl) ?>">
    <div class="flog-bar">
        <h1>Flow log</h1>
        <span class="flog-live" data-live><i></i> Live</span>
        <select data-day aria-label="Day">
            <?php foreach ($days as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?>
        </select>
        <select data-filter aria-label="Show">
            <option value="">All steps</option>
            <option value="staff">Staff chat only</option>
            <option value="tool">Tools only</option>
            <option value="flowise">Flowise only</option>
            <option value="problem">Problems only</option>
        </select>
        <input type="search" placeholder="Search…" data-search aria-label="Search">
    </div>
    <p class="muted" style="margin:0">Newest at the top. Each block is one message (one request). Secrets are masked.
        A Flowise reply with <b>used_tools: NONE</b> means Flowise answered without calling any of your tools.</p>
    <div class="flog-list" data-list><div class="flog-empty">Loading…</div></div>
</div>
<script>
(function () {
    var root = document.querySelector('[data-flog]');
    var list = root.querySelector('[data-list]');
    var daySel = root.querySelector('[data-day]'), filter = root.querySelector('[data-filter]'), search = root.querySelector('[data-search]');
    var live = root.querySelector('[data-live]');
    var rows = [], after = 0, timer = null;

    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

    var staffReqs = {};
    function matches(r, f, q) {
        if (f === 'staff' && !staffReqs[r.req]) return false;
        if (f === 'tool' && r.level !== 'tool' && r.step.indexOf('tool.') !== 0) return false;
        if (f === 'flowise' && r.step.indexOf('flowise.') !== 0) return false;
        if (f === 'problem' && r.level !== 'bad' && r.level !== 'warn') return false;
        if (q && (r.step + ' ' + JSON.stringify(r.details)).toLowerCase().indexOf(q) === -1) return false;
        return true;
    }

    function draw() {
        var f = filter.value, q = search.value.trim().toLowerCase();
        var groups = {}, order = [];
        staffReqs = {};   // a whole message counts as staff when any of its steps is
        rows.forEach(function (r) {
            if (r.step.indexOf('staff') === 0 || JSON.stringify(r.details).indexOf('staff:') !== -1) staffReqs[r.req] = true;
        });
        rows.forEach(function (r) {
            if (!matches(r, f, q)) return;
            if (!groups[r.req]) { groups[r.req] = []; order.push(r.req); }
            groups[r.req].push(r);
        });
        list.textContent = '';
        if (!order.length) { list.appendChild(el('div', 'flog-empty', rows.length ? 'Nothing matches.' : 'No log lines for this day yet.')); return; }
        order.reverse().forEach(function (req) {
            var box = el('div', 'flog-req');
            groups[req].forEach(function (r) {
                var row = el('div', 'flog-row flog-row--' + r.level);
                row.appendChild(el('time', null, r.time));
                row.appendChild(el('div', 'flog-step', r.step));
                var d = el('div', 'flog-details');
                r.details.forEach(function (kv) { var s = el('span'); s.appendChild(el('b', null, kv[0] + ': ')); s.appendChild(document.createTextNode(kv[1])); d.appendChild(s); });
                row.appendChild(d);
                box.appendChild(row);
            });
            list.appendChild(box);
        });
    }

    function poll(reset) {
        clearTimeout(timer);
        if (reset) { rows = []; after = 0; }
        var u = new URL(root.getAttribute('data-feed-url'), location.href);
        u.searchParams.set('day', daySel.value);
        if (after) u.searchParams.set('after', after);
        fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var d = res.data || {};
                rows = rows.concat(d.rows || []);
                if (rows.length > 2000) rows = rows.slice(-2000);
                after = d.total || after;
                live.classList.remove('is-paused');
                if ((d.rows || []).length || reset) draw();
            })
            .catch(function () { live.classList.add('is-paused'); })
            .then(function () { timer = setTimeout(function () { poll(false); }, document.hidden ? 15000 : 3000); });
    }

    daySel.addEventListener('change', function () { poll(true); });
    filter.addEventListener('change', draw);
    search.addEventListener('input', draw);
    poll(true);
})();
</script>
<?php if ($bare): ?></body></html><?php else: view('layouts/footer'); endif; ?>
