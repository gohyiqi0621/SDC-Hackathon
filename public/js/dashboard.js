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
    // Show a short shimmer first so the moment reads as "loading", then the
    // text fallback - not a dead grey box.
    if (typeof window.Chart === 'undefined') {
        if (wrap) {
            wrap.classList.add('is-loading');
            setTimeout(function () {
                wrap.classList.remove('is-loading');
                wrap.hidden = true;
                if (fallback) { fallback.hidden = false; }
            }, 900);
        } else if (fallback) {
            fallback.hidden = false;
        }
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

    var current = 7, c = colors(), start = slice(current);
    Chart.defaults.font.family = token('--font', 'system-ui');

    var chart = new Chart(canvas, {
        type: 'line',
        data: { labels: start.labels, datasets: [dataset('Items created', start.items, c.a), dataset('AI calls', start.ai, c.b)] },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            animation: { duration: 900, easing: 'easeOutQuart' },
            plugins: { legend: { align: 'end', labels: { color: c.text, usePointStyle: true, boxWidth: 8, boxHeight: 8 } } },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { color: c.text, maxRotation: 0, autoSkipPadding: 14 } },
                y: { beginAtZero: true, border: { display: false }, ticks: { color: c.text, precision: 0 }, grid: { color: c.grid } }
            }
        }
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
    // main.js applies the theme first (it loads before this file), so tokens are fresh here.
    var toggle = document.querySelector('[data-theme-toggle]');
    if (toggle) { toggle.addEventListener('click', recolour); }
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
            chart.update();
        });
    });
})();
