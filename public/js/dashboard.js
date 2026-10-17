/**
 * dashboard.js — renders the activity chart from the JSON block embedded in
 * the page (<script type="application/json" id="activity-data">). No fetch:
 * the series ships with the HTML and the range buttons just re-slice it.
 * Colours are read from the CSS tokens, so light/dark just works.
 */
(function () {
    'use strict';

    var dataEl = document.getElementById('activity-data');
    var canvas = document.getElementById('activity-chart');
    if (!dataEl || !canvas) { return; }

    var series = JSON.parse(dataEl.textContent);
    var wrap = canvas.closest('.chart-wrap');
    var fallback = document.querySelector('.chart-fallback');

    // The CDN can be unreachable; the rest of the dashboard still works.
    if (typeof window.Chart === 'undefined') {
        if (wrap) { wrap.hidden = true; }
        if (fallback) { fallback.hidden = false; }
        return;
    }

    function token(name, fallbackColor) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallbackColor;
    }
    function colors() {
        return { text: token('--text-3', '#6e6e73'), grid: token('--line', 'rgba(0,0,0,.08)'), a: token('--viz-1', '#0b7a43'), b: token('--viz-2', '#0a64c8') };
    }
    function slice(days) {
        return { labels: series.labels.slice(-days), items: series.items.slice(-days), ai: series.ai.slice(-days) };
    }
    function dataset(label, values, color) {
        return { label: label, data: values, borderColor: color, backgroundColor: color, borderWidth: 2.5, tension: .38, pointRadius: 0, pointHoverRadius: 5, pointHoverBorderWidth: 3, pointHoverBackgroundColor: token('--surface', '#fff') };
    }

    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Draw-in: lines are clipped by a rectangle that grows left to right. Cheaper and more
    // predictable than per-point animation, and it replays cleanly when the range changes.
    var reveal = {
        id: 'reveal',
        beforeDatasetsDraw: function (chart) {
            var p = chart.$reveal;
            if (p == null || p >= 1) { chart.$clipped = false; return; }
            var a = chart.chartArea, ctx = chart.ctx;
            ctx.save(); ctx.beginPath();
            ctx.rect(a.left - 14, a.top - 14, (a.width + 28) * p, a.height + 28);
            ctx.clip(); chart.$clipped = true;
        },
        afterDatasetsDraw: function (chart) { if (chart.$clipped) { chart.ctx.restore(); chart.$clipped = false; } }
    };
    var frameId = 0;
    function play(ms) {
        cancelAnimationFrame(frameId);
        if (reduce) { chart.$reveal = 1; chart.draw(); return; }
        var t0 = 0;
        (function step(now) {
            if (!t0) { t0 = now; }
            var p = Math.min((now - t0) / ms, 1);
            chart.$reveal = 1 - Math.pow(1 - p, 3);
            chart.draw();
            if (p < 1) { frameId = requestAnimationFrame(step); }
        })(performance.now());
    }

    var current = 7, c = colors(), start = slice(current);
    Chart.defaults.font.family = token('--font', 'system-ui');

    if (wrap) { wrap.classList.add('is-loading'); }
    var chart = new Chart(canvas, {
        plugins: [reveal],
        type: 'line',
        data: { labels: start.labels, datasets: [dataset('Items created', start.items, c.a), dataset('AI calls', start.ai, c.b)] },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            animation: false,   // the reveal plugin owns the entrance
            plugins: { legend: { align: 'end', labels: { color: c.text, usePointStyle: true, boxWidth: 8, boxHeight: 8 } } },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { color: c.text, maxRotation: 0, autoSkipPadding: 14 } },
                y: { beginAtZero: true, border: { display: false }, ticks: { color: c.text, precision: 0 }, grid: { color: c.grid } }
            }
        }
    });

    chart.$reveal = 0;
    requestAnimationFrame(function () {
        if (wrap) { wrap.classList.remove('is-loading'); }   // first frame is ready: shimmer off
        play(900);
    });

    function recolour() {
        c = colors();
        chart.options.plugins.legend.labels.color = c.text;
        chart.options.scales.x.ticks.color = c.text;
        chart.options.scales.y.ticks.color = c.text;
        chart.options.scales.y.grid.color = c.grid;
        chart.data.datasets[0].borderColor = chart.data.datasets[0].backgroundColor = c.a;
        chart.data.datasets[1].borderColor = chart.data.datasets[1].backgroundColor = c.b;
        chart.update('none');
    }
    // main.js fires 'themechange' after the theme is applied (inside the view transition).
    document.addEventListener('themechange', recolour);

    var buttons = document.querySelectorAll('.seg__btn[data-range]');
    buttons.forEach(function (button) {
        button.setAttribute('aria-pressed', String(button.classList.contains('is-active')));
        button.addEventListener('click', function () {
            var days = parseInt(button.dataset.range, 10);
            if (!days || days === current) { return; }
            current = days;
            buttons.forEach(function (other) {
                var on = other === button;
                other.classList.toggle('is-active', on);
                other.setAttribute('aria-pressed', String(on));
            });
            var next = slice(days);
            chart.data.labels = next.labels;
            chart.data.datasets[0].data = next.items;
            chart.data.datasets[1].data = next.ai;
            chart.update('none');
            play(650);
        });
    });
})();
