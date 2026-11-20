/**
 * The dynasties as rows by region across the Hijri years (backlog item 154, step 14.278), after the chart of
 * dawawin.ir's statistics: each dynasty a bar from its first year to its last, in the lanes of its region, the darker
 * the more copies of the scope were made in its lands and days. The window of years is dragged and zoomed (the
 * slider under the chart, the wheel, two fingers). ECharts is fetched when the chart comes into view.
 *
 * A dynasty is {n: name, r: region, f: from, t: to, c: copies, cities: [[name, copies], …] for the larger ones}.
 */
import { regionColor } from './insights-regions';

const LANE = 16;

export default (config) => {
    let chart = null;
    const rows = [];
    const bands = [];

    // The lanes: the regions in their order, and within a region each dynasty in the first lane free at its start
    let lane = 0;
    for (const region of config.regions) {
        const ends = [];
        const mine = config.dynasties.filter((dynasty) => dynasty.r === region).sort((a, b) => a.f - b.f || b.t - a.t);
        for (const dynasty of mine) {
            let i = ends.findIndex((end) => end + 5 <= dynasty.f);
            if (i < 0) {
                i = ends.length;
                ends.push(0);
            }
            ends[i] = dynasty.t;
            // The first lane of a region is left for its name
            rows.push({ ...dynasty, lane: lane + 1 + i });
        }
        if (mine.length) {
            bands.push({ region, from: lane, lanes: ends.length + 1 });
            lane += ends.length + 1;
        }
    }
    const lanes = lane;
    const most = Math.max(1, ...rows.map((row) => row.c));

    return {
        ready: false,
        failed: false,
        selected: null,
        height: lanes * LANE + 90,

        init() {
            const start = () => this.load().catch(() => { this.failed = true; });
            if ('IntersectionObserver' in window) {
                const seen = new IntersectionObserver((entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) {
                        seen.disconnect();
                        start();
                    }
                }, { rootMargin: '300px' });
                seen.observe(this.$el);
            } else {
                start();
            }
        },

        fa(n, grouping = true) {
            return Number(n).toLocaleString('fa-IR', { useGrouping: grouping });
        },

        years(row) {
            return `${this.fa(row.f, false)} – ${this.fa(row.t, false)} ق`;
        },

        async load() {
            const { default: echarts } = await import('./echarts-map');
            this.ready = true;
            await this.$nextTick();
            chart = echarts.init(this.$refs.chart, null, { renderer: 'canvas' });
            chart.on('click', (point) => {
                this.selected = point.seriesId === 'dynasties' ? rows[point.dataIndex] : null;
                // Drawn again, for the outline of the chosen bar
                this.draw();
            });
            new ResizeObserver(() => { if (this.$refs.chart.clientWidth) { chart.resize(); } }).observe(this.$refs.chart);
            new MutationObserver(() => this.draw()).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            this.draw();
        },

        draw() {
            const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            const ink = css('--ink'), paper = css('--paper'), muted = css('--muted'), line = css('--line');
            const font = getComputedStyle(document.body).fontFamily;
            const esc = (text) => String(text).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            const zoom = chart.getOption()?.dataZoom?.[0];
            chart.setOption({
                backgroundColor: 'transparent',
                // Read from right to left, as the page is: the early years on the right
                grid: { left: 8, right: 8, top: 26, bottom: 56 },
                xAxis: {
                    type: 'value', min: config.span[0], max: config.span[1], inverse: true, position: 'top',
                    axisLabel: { color: muted, fontFamily: font, formatter: (year) => this.fa(year, false) },
                    axisLine: { lineStyle: { color: line } }, splitLine: { lineStyle: { color: line, opacity: 0.6 } },
                },
                yAxis: { type: 'value', min: 0, max: lanes, inverse: true, show: false },
                dataZoom: [
                    {
                        type: 'slider', xAxisIndex: 0, bottom: 10, height: 22, filterMode: 'none',
                        startValue: zoom?.startValue ?? config.window[0], endValue: zoom?.endValue ?? config.window[1],
                        textStyle: { color: muted, fontFamily: font }, labelFormatter: (year) => this.fa(Math.round(year), false),
                        borderColor: line, fillerColor: 'rgba(162, 58, 38, 0.12)', handleStyle: { color: css('--cinnabar') },
                    },
                    { type: 'inside', xAxisIndex: 0, filterMode: 'none' },
                ],
                tooltip: {
                    trigger: 'item', confine: true, backgroundColor: ink, borderWidth: 0, padding: [6, 10],
                    textStyle: { color: paper, fontFamily: font, fontSize: 12 },
                    formatter: (point) => {
                        const row = rows[point.dataIndex];
                        if (point.seriesId !== 'dynasties' || ! row) {
                            return '';
                        }

                        return `<b>${esc(row.n)}</b><br>${this.years(row)}<br>${esc(row.r)}<br>` + (row.c ? `${this.fa(row.c)} نسخه در قلمرو و روزگار آن` : 'نسخه‌ای با سال و شهر در قلمرو آن نیست');
                    },
                },
                series: [
                    {
                        // The name of each region over its lanes, and a line between two regions
                        id: 'regions', type: 'custom', silent: true, z: 1, data: bands.map((band, i) => [i]), clip: false,
                        renderItem: (params, api) => {
                            const band = bands[api.value(0)];
                            const top = api.coord([config.span[0], band.from])[1];
                            const { x, width } = params.coordSys;

                            return {
                                type: 'group',
                                children: [
                                    { type: 'line', shape: { x1: x, y1: top, x2: x + width, y2: top }, style: { stroke: line, lineWidth: 1 } },
                                    {
                                        type: 'text', x: x + width - 4, y: top + 3,
                                        style: { text: band.region, fill: muted, font: `600 11px ${font}`, align: 'right', verticalAlign: 'top', opacity: 0.9 },
                                    },
                                ],
                            };
                        },
                    },
                    {
                        id: 'dynasties', type: 'custom', z: 2, cursor: 'pointer', clip: true,
                        data: rows.map((row) => [row.f, row.t, row.lane]),
                        encode: { x: [0, 1], y: 2 },
                        renderItem: (params, api) => {
                            const row = rows[params.dataIndex];
                            const [x0, y] = api.coord([api.value(0), api.value(2)]);
                            const [x1] = api.coord([api.value(1), api.value(2)]);
                            const left = Math.max(params.coordSys.x, Math.min(x0, x1));
                            const right = Math.min(params.coordSys.x + params.coordSys.width, Math.max(x0, x1));
                            if (right - left < 1) {
                                return null;
                            }
                            const color = regionColor(config.regions, row.r);
                            const chosen = this.selected?.n === row.n;
                            const wide = right - left > row.n.length * 6.2 + 8;

                            return {
                                type: 'group',
                                children: [
                                    {
                                        type: 'rect', shape: { x: left, y: y + 2, width: right - left, height: LANE - 4, r: 2 },
                                        style: { fill: color, opacity: 0.18 + 0.72 * Math.sqrt(row.c / most), stroke: chosen ? ink : color, lineWidth: chosen ? 1.6 : 0.6 },
                                    },
                                    ...(wide ? [{
                                        type: 'text', x: right - 4, y: y + LANE / 2,
                                        style: { text: row.n, fill: ink, font: `10.5px ${font}`, align: 'right', verticalAlign: 'middle' },
                                        silent: true,
                                    }] : []),
                                ],
                            };
                        },
                    },
                ],
            }, true);
        },

        show(from, to) {
            chart?.dispatchAction({ type: 'dataZoom', startValue: from, endValue: to });
        },
    };
};
