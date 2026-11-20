/**
 * The dynasties as rows by region across the Hijri years (backlog item 154, steps 14.278 and 14.281), drawn as the
 * chart of dawawin.ir's statistics is: a strip of all the years with every dynasty as a hairline and a frame for the
 * window, and under it the window alone, with only the regions and dynasties it holds, packed anew for it, so the
 * chart stays short on a phone. The window is dragged (on the strip or on the rows), zoomed with two fingers or with
 * Ctrl and the wheel, and set by the buttons of periods; a finger moving up or down scrolls the page as ever.
 *
 * A bar is the darker the more copies of the scope were made in every ten years of the rule (the density, not the
 * count: a long rule has many copies for its length alone). No library: the SVG is written here.
 *
 * A dynasty is {n: name, r: region, f: from, t: to, c: copies, d: copies per ten years, s: share, cities: [[name, n]]}.
 */
import { regionColor } from './insights-regions';

const NS = 'http://www.w3.org/2000/svg';

export default (config) => {
    const [MIN, MAX] = config.span;
    const most = Math.max(0.1, ...config.dynasties.map((dynasty) => dynasty.d));
    let view = { lo: config.window[0], hi: config.window[1] };
    let frame = { left: 6, right: 300 };

    const el = (parent, tag, attrs, text) => {
        const node = document.createElementNS(NS, tag);
        for (const key in attrs) {
            node.setAttribute(key, attrs[key]);
        }
        if (text != null) {
            node.textContent = text;
        }
        parent.appendChild(node);

        return node;
    };

    return {
        selected: null,

        init() {
            const box = this.$refs.chart;
            // A phone shows three centuries at a time
            if (box.clientWidth && box.clientWidth < 600) {
                view = { lo: Math.max(MIN, view.hi - 300), hi: view.hi };
            }
            this.render();
            new ResizeObserver(() => this.render()).observe(box);
            new MutationObserver(() => this.render()).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            this.gestures(box);
        },

        fa(n, grouping = true) {
            return Number(n).toLocaleString('fa-IR', { useGrouping: grouping });
        },

        years(row) {
            return `${this.fa(row.f, false)} – ${this.fa(row.t, false)} ق`;
        },

        /** What a dynasty's bar says, for the shared box of figures and for the line under the chart */
        figures(row) {
            return row.c
                ? `${this.fa(row.c)} نسخه · ${this.fa(row.d)} نسخه در هر ده سال · ${this.fa(row.s)}٪ نسخه‌های همان سال‌ها`
                : 'نسخه‌ای با سال و شهر در قلمرو آن نیست';
        },

        show(lo, hi) {
            const span = Math.max(40, Math.min(MAX - MIN, hi - lo));
            lo = Math.max(MIN, Math.min(MAX - span, lo));
            view = { lo, hi: lo + span };
            this.render();
        },

        render() {
            const box = this.$refs.chart;
            const width = box.clientWidth;
            if (! width) {
                return;
            }
            const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            const ink = css('--ink'), muted = css('--muted'), line = css('--line'), surface = css('--surface'), cinnabar = css('--cinnabar');
            const mobile = width < 600, left = 6, right = width - 6;
            frame = { left, right };
            // The early years on the right, as the page reads
            const fx = (year) => right - (year - MIN) / (MAX - MIN) * (right - left);
            const x = (year) => right - (year - view.lo) / (view.hi - view.lo) * (right - left);
            box.querySelectorAll('svg').forEach((old) => old.remove());
            const svg = el(box, 'svg', { width, role: 'presentation' });
            svg.style.display = 'block';

            // The strip of all the years: every dynasty a hairline in the row of its region, and the window's frame
            const stripTop = 4, stripHeight = 40;
            el(svg, 'rect', { x: left, y: stripTop, width: right - left, height: stripHeight, fill: surface, stroke: line, rx: 4 });
            const step = (stripHeight - 6) / Math.max(1, config.regions.length);
            config.regions.forEach((region, i) => config.dynasties.filter((dynasty) => dynasty.r === region).forEach((dynasty) => el(svg, 'rect', {
                x: fx(dynasty.t), y: stripTop + 3 + i * step, width: Math.max(1, fx(dynasty.f) - fx(dynasty.t)), height: Math.max(1.5, step - 0.6),
                fill: regionColor(config.regions, region), 'fill-opacity': 0.25 + 0.6 * Math.sqrt(dynasty.d / most),
            })));
            for (let year = 200; year <= 1400; year += 200) {
                el(svg, 'text', { x: fx(year), y: stripTop + stripHeight + 13, 'text-anchor': 'middle', 'font-size': 10.5, fill: muted }, this.fa(year, false));
            }
            el(svg, 'rect', {
                x: fx(view.hi), y: stripTop - 2, width: Math.max(4, fx(view.lo) - fx(view.hi)), height: stripHeight + 4,
                fill: cinnabar, 'fill-opacity': 0.08, stroke: cinnabar, 'stroke-width': 2, rx: 4, cursor: 'grab',
            });

            // The window: a band for each region with a dynasty in it, its dynasties in the first lane free
            const top = stripTop + stripHeight + 42;
            const barHeight = mobile ? 15 : 16, gap = 2, span = view.hi - view.lo;
            const size = mobile ? 10.5 : 11.5;
            let y = top;
            const bands = [];
            for (const region of config.regions) {
                const items = config.dynasties.filter((dynasty) => dynasty.r === region && Math.min(dynasty.t, view.hi) - Math.max(dynasty.f, view.lo) >= span * 0.015);
                if (! items.length) {
                    continue;
                }
                const ends = [];
                for (const item of [...items].sort((a, b) => a.f - b.f || b.t - a.t)) {
                    let lane = ends.findIndex((end) => end + span / 200 <= item.f);
                    if (lane < 0) {
                        lane = ends.length;
                        ends.push(0);
                    }
                    ends[lane] = item.t;
                    item.lane = lane;
                }
                bands.push({ region, items, y });
                y += 15 + ends.length * (barHeight + gap) + 5;
            }
            const height = y + 4;
            svg.setAttribute('height', height);

            const tick = [10, 20, 50, 100, 200].find((years) => years * (right - left) / span >= 44) || 200;
            for (let year = Math.ceil(view.lo / tick) * tick; year <= view.hi; year += tick) {
                el(svg, 'line', { x1: x(year), x2: x(year), y1: top, y2: height, stroke: line, 'stroke-opacity': year % 100 ? 0.45 : 1 });
                if (x(year) > left + 12 && x(year) < right - 12) {
                    el(svg, 'text', { x: x(year), y: top - 6, 'text-anchor': 'middle', 'font-size': 11, fill: muted }, this.fa(year, false));
                }
            }
            for (const band of bands) {
                const color = regionColor(config.regions, band.region);
                el(svg, 'text', { x: right, y: band.y + 10, 'font-size': 11.5, 'font-weight': 600, fill: color }, band.region);
                for (const row of band.items) {
                    const x0 = Math.max(left, x(row.t)), x1 = Math.min(right, x(row.f));
                    if (x1 - x0 < 1) {
                        continue;
                    }
                    const chosen = this.selected?.n === row.n;
                    const rowY = band.y + 15 + row.lane * (barHeight + gap);
                    const group = el(svg, 'g', { 'data-tip': `${row.n} · ${this.years(row)} · ${this.figures(row)}`, cursor: 'pointer' });
                    el(group, 'rect', {
                        x: x0, y: rowY, width: Math.max(2, x1 - x0), height: barHeight, rx: 4, fill: color,
                        'fill-opacity': 0.14 + 0.78 * Math.sqrt(row.d / most), stroke: chosen ? ink : color, 'stroke-width': chosen ? 1.8 : 0.6,
                    });
                    if (x1 - x0 > row.n.length * size * 0.52 + 10) {
                        el(group, 'text', { x: x1 - 6, y: rowY + barHeight / 2 + 4, 'font-size': size, fill: ink, 'pointer-events': 'none' }, row.n);
                    }
                    group.addEventListener('click', (event) => {
                        event.stopPropagation();
                        this.selected = row;
                        this.render();
                    });
                }
            }
        },

        gestures(box) {
            const yearAtStrip = (px) => MIN + (frame.right - px) / (frame.right - frame.left) * (MAX - MIN);
            const points = new Map();
            let pan = null, moved = false;
            const pinch = () => {
                const xs = [...points.values()];

                return xs.length >= 2 ? Math.abs(xs[0] - xs[1]) : 0;
            };
            box.addEventListener('wheel', (event) => {
                if (! event.ctrlKey) {
                    return;
                }
                event.preventDefault();
                const at = box.getBoundingClientRect();
                const year = view.lo + (frame.right - (event.clientX - at.left)) / (frame.right - frame.left) * (view.hi - view.lo);
                const k = event.deltaY > 0 ? 1.2 : 1 / 1.2;
                this.show(year - (year - view.lo) * k, year + (view.hi - year) * k);
            }, { passive: false });
            box.addEventListener('pointerdown', (event) => {
                const at = box.getBoundingClientRect();
                // On the strip: the window follows the finger, by the scale of all the years; elsewhere by its own
                const strip = event.clientY - at.top <= 50;
                if (strip) {
                    const year = yearAtStrip(event.clientX - at.left);
                    if (year < view.lo || year > view.hi) {
                        const half = (view.hi - view.lo) / 2;
                        this.show(year - half, year + half);
                    }
                }
                points.set(event.pointerId, event.clientX);
                moved = false;
                pan = { x: event.clientX, lo: view.lo, hi: view.hi, pinch: pinch(), strip };
            });
            box.addEventListener('pointermove', (event) => {
                if (! pan || ! points.has(event.pointerId)) {
                    return;
                }
                points.set(event.pointerId, event.clientX);
                if (points.size >= 2 && pan.pinch) {
                    const k = pan.pinch / Math.max(1, pinch()), mid = (pan.lo + pan.hi) / 2, half = (pan.hi - pan.lo) / 2 * k;
                    moved = true;
                    this.show(mid - half, mid + half);
                } else if (Math.abs(event.clientX - pan.x) > 3) {
                    const perPixel = (pan.strip ? MAX - MIN : pan.hi - pan.lo) / (frame.right - frame.left);
                    const years = (event.clientX - pan.x) * perPixel * (pan.strip ? -1 : 1);
                    moved = true;
                    this.show(pan.lo + years, pan.hi + years);
                }
            });
            const up = (event) => {
                points.delete(event.pointerId);
                if (! points.size) {
                    pan = null;
                }
            };
            box.addEventListener('pointerup', up);
            box.addEventListener('pointercancel', up);
            box.addEventListener('pointerleave', up);
            // A click that ended a drag chooses nothing; a click on no bar lets the chosen one go
            box.addEventListener('click', (event) => {
                if (moved) {
                    event.stopPropagation();
                    moved = false;
                } else if (this.selected) {
                    this.selected = null;
                    this.render();
                }
            }, true);
        },
    };
};
