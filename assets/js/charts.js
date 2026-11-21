// Wykresy metryk (uPlot, dołączony lokalnie). Średnia jako linia, min–max jako pasmo.
import uPlot from '../lib/uplot/uPlot.esm.js';
import { formatAxis, formatMetric } from './format.js';

const css = getComputedStyle(document.documentElement);
const color = (name) => css.getPropertyValue(name).trim();
const charts = document.querySelector('.charts');
const tz = charts?.dataset.tz || 'Europe/Warsaw';

function buildChart(el, payload) {
    const ts = [];
    const min = [];
    const avg = [];
    const max = [];
    for (const [t, mn, av, mx] of payload.points) {
        ts.push(t);
        min.push(mn);
        avg.push(av);
        max.push(mx);
    }

    el.replaceChildren();
    if (ts.length === 0) {
        el.textContent = 'Brak danych w tym zakresie.';
        el.classList.add('chart-empty');
        return;
    }

    const axis = {
        stroke: color('--muted'),
        grid: { stroke: color('--border'), width: 1 },
        ticks: { stroke: color('--border'), width: 1 },
    };
    const accent = color('--accent');
    const showBand = payload.bucket > 60;

    const opts = {
        width: el.clientWidth || 600,
        height: 180,
        tzDate: (t) => uPlot.tzDate(new Date(t * 1000), tz),
        scales: { x: { time: true, range: () => [payload.from, payload.to] } },
        legend: { show: true, live: true },
        cursor: { points: { size: 6 } },
        series: [
            { label: 'Czas', value: (u, v) => (v == null ? '–' : uPlot.fmtDate('{YYYY}-{MM}-{DD} {HH}:{mm}')(uPlot.tzDate(new Date(v * 1000), tz))) },
            { label: 'min', show: showBand, stroke: 'transparent', value: (u, v) => formatMetric(v, payload.unit) },
            { label: 'średnia', stroke: accent, width: 1.6, value: (u, v) => formatMetric(v, payload.unit), spanGaps: false },
            { label: 'max', show: showBand, stroke: 'transparent', value: (u, v) => formatMetric(v, payload.unit) },
        ],
        bands: showBand ? [{ series: [3, 1], fill: `${accent}33` }] : [],
        axes: [
            { ...axis },
            { ...axis, size: 70, values: (u, splits) => splits.map((v) => formatAxis(v, payload.unit)) },
        ],
    };

    const plot = new uPlot(opts, [ts, min, avg, max], el);
    new ResizeObserver(() => plot.setSize({ width: el.clientWidth, height: 180 })).observe(el);
}

async function loadChart(el) {
    el.classList.add('chart-loading');
    try {
        const response = await fetch(el.dataset.chartUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        buildChart(el, await response.json());
    } catch (e) {
        el.textContent = 'Nie udało się wczytać wykresu.';
        el.classList.add('chart-empty');
    } finally {
        el.classList.remove('chart-loading');
    }
}

document.querySelectorAll('[data-chart-url]').forEach(loadChart);

// Dodatkowy wykres dowolnej metryki
const picker = document.querySelector('[data-chart-picker]');
if (picker && charts) {
    picker.addEventListener('submit', (event) => {
        event.preventDefault();
        const select = picker.querySelector('select');
        const key = select?.value;
        if (!key) return;
        const url = new URL(picker.dataset.chartBase, window.location.origin);
        url.searchParams.set('metric', key);
        url.searchParams.set('range', picker.dataset.range);

        const figure = document.createElement('figure');
        figure.className = 'card chart-card';
        const caption = document.createElement('figcaption');
        caption.className = 'mono small';
        caption.textContent = key;
        const el = document.createElement('div');
        el.className = 'chart';
        el.dataset.chartUrl = url.pathname + url.search;
        figure.append(caption, el);
        charts.append(figure);
        select.querySelector(`option[value="${CSS.escape(key)}"]`)?.remove();
        loadChart(el);
    });
}
