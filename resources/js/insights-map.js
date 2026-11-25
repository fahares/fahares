/**
 * The map of the statistics (backlog item 137, step 14.275), after the map of dawawin.ir's statistics: the cities of
 * copying and of composition on a real base map that is zoomed and dragged, in four layers (copies, works, scribes,
 * authors), with a slider of years, the territories of the states of the chosen decade under the cities, and a panel
 * for the city that is clicked. ECharts, the base map and the counts of the cities (config.data, step 14.377) are
 * fetched when the map comes into view; until then, and without script, the page shows the still map and the list of
 * cities it was sent with.
 *
 * A link may open the map at a decade and on a dynasty (`?map_year=1035&map_at=صفویان`, from the chart of the
 * dynasties): the slider goes to that decade and the map to the dynasty's seat of then.
 *
 * A city is {n: name, lat, lon, t, d, w, wd, s, sd, a, ad, o, od, b, bd, x, xd, r, rd}: for each layer its whole count and its counts by decade,
 * [[the year the decade ends in, count], …]. A copy is «by a year» once the whole range of its date has passed.
 */
const LAYERS = {
    copy: { total: 't', decades: 'd', unit: 'نسخه', color: '--cinnabar' },
    composition: { total: 'w', decades: 'wd', unit: 'اثر', color: '--gold' },
    scribes: { total: 's', decades: 'sd', unit: 'کاتب', color: '--ink' },
    authors: { total: 'a', decades: 'ad', unit: 'مؤلف', color: '--muted' },
    // Where the authors and scribes were from (the nisba of the name), were born and died (their lives)
    origin: { total: 'o', decades: 'od', unit: 'شخص', row: 'با نسبت این شهر', color: '--ink' },
    birth: { total: 'b', decades: 'bd', unit: 'شخص', row: 'زادۀ این شهر', color: '--gold' },
    death: { total: 'x', decades: 'xd', unit: 'شخص', row: 'درگذشته در این شهر', color: '--muted' },
    // The periods of residence the lives name (person_periods, step 14.291)
    residence: { total: 'r', decades: 'rd', unit: 'شخص', row: 'ساکن این شهر', color: '--cinnabar' },
};
import { regionColor } from './insights-regions';

const CENTER = [59, 27.5];

export default (config) => {
    // Kept out of Alpine's reactive data: the chart, the cities and the territories are large and are not watched
    const territories = {};
    let cities = [], chart = null, lands = [], timer = null, ticket = 0;

    return {
    layer: config.layer,
    mode: 'all',
    year: config.years[1],
    playing: false,
    selected: null,
    ready: false,
    failed: false,
    // The counts of the cities have come: until then the page's own list of cities stands
    loaded: false,
    // The dynasty a link opened the map on
    focus: null,
    // Raised by every drawing, so what reads the territories (the ruler of the chosen city) is worked out again
    drawn: 0,
    years: config.years,
    regions: config.regions,

    init() {
        const asked = new URLSearchParams(location.search);
        const year = Number(asked.get('map_year'));
        if (year >= this.years[0] && year <= this.years[1]) {
            this.year = Math.ceil(year / 10) * 10;
            this.mode = 'until';
            this.focus = asked.get('map_at') || null;
        }
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
        this.$watch('layer', () => this.draw());
        this.$watch('mode', () => { if (this.mode === 'all') { this.stop(); } this.draw(); });
        // Another year is no longer the decade the link asked for
        this.$watch('year', () => { this.focus = null; this.draw(); });
    },

    async load() {
        const json = (response) => {
            if (! response.ok) {
                throw new Error(`${response.status}`);
            }

            return response.json();
        };
        const [{ default: echarts }, basemap, data] = await Promise.all([
            import('./echarts-map'),
            fetch(config.basemap).then(json),
            fetch(config.data, { headers: { Accept: 'application/json' } }).then(json),
        ]);
        echarts.registerMap('fahares', basemap);
        cities = data.cities;
        this.loaded = true;
        // The still map gives its place to the box of the chart, which must be on the page to be measured
        this.ready = true;
        await this.$nextTick();
        chart = echarts.init(this.$refs.map, null, { renderer: 'canvas' });
        chart.on('click', (point) => {
            this.selected = point.seriesId === 'cities' ? point.data.city : null;
            this.draw();
        });
        chart.getZr().on('click', (event) => {
            if (! event.target) {
                this.selected = null;
                this.draw();
            }
        });
        // The box may be measured before the page has laid it out (a tab opened in the background): the chart
        // takes its size again whenever the box gets one
        new ResizeObserver(() => { if (this.$refs.map.clientWidth) { chart.resize(); } }).observe(this.$refs.map);
        // The colours of the page are read anew when the reader turns the dark theme on or off
        new MutationObserver(() => { this.base(); this.draw(); }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        this.base();
        // A link of a dynasty: the map goes to its seat in the decade asked for (else the first one it had)
        const seats = this.focus ? config.capitals.filter((capital) => capital.d === this.focus) : [];
        const seat = seats.find((capital) => capital.f <= this.year - 5 && this.year - 5 <= capital.t) ?? seats[0];
        if (seat) {
            chart.setOption({ geo: { center: [seat.lon, seat.lat], zoom: 2.2 } });
        }
        await this.draw();
    },

    css(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    },

    fa(n, grouping = true) {
        return Number(n).toLocaleString('fa-IR', { useGrouping: grouping });
    },

    /** The count of a city in a layer, at the chosen time */
    value(city, layer = this.layer) {
        const { total, decades } = LAYERS[layer];
        if (this.mode === 'all') {
            return city[total];
        }
        const from = this.mode === 'century' ? this.year - 100 : 0;

        return city[decades].reduce((sum, [upto, n]) => sum + (upto <= this.year && upto > from ? n : 0), 0);
    },

    get unit() {
        return LAYERS[this.layer].unit;
    },

    get ranked() {
        if (! this.loaded) {
            return [];
        }

        return cities.map((city) => ({ city, n: this.value(city) })).filter((row) => row.n > 0).sort((a, b) => b.n - a.n).slice(0, 15);
    },

    get total() {
        if (! this.loaded) {
            return 0;
        }

        return cities.reduce((sum, city) => sum + this.value(city), 0);
    },

    get span() {
        return this.mode === 'all' ? 'همۀ سال‌ها' : this.mode === 'until' ? `تا سال ${this.fa(this.year, false)} ق` : `از ${this.fa(this.year - 99, false)} تا ${this.fa(this.year, false)} ق`;
    },

    /** The figures of the chosen city in every layer, for the panel */
    get figures() {
        return this.selected ? Object.entries(LAYERS).map(([key, layer]) => ({ key, unit: layer.row ?? layer.unit, n: this.value(this.selected, key) })) : [];
    },

    /** The state whose territory holds the chosen city in the decade of the slider */
    get ruler() {
        if (! this.selected || this.mode === 'all' || this.drawn < 0) {
            return null;
        }
        const point = [this.selected.lon, this.selected.lat];
        const inside = (ring) => {
            let hit = false;
            for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
                const [xi, yi] = ring[i], [xj, yj] = ring[j];
                if ((yi > point[1]) !== (yj > point[1]) && point[0] < (xj - xi) * (point[1] - yi) / (yj - yi) + xi) {
                    hit = ! hit;
                }
            }

            return hit;
        };

        return lands.find((land) => this.polygons(land.geometry).some((polygon) => inside(polygon[0])))?.properties ?? null;
    },

    /** The search of the copies, or of the scribes, of the chosen city (null where the search has no such filter) */
    link(key) {
        if (! this.selected || ! config.search[key]) {
            return null;
        }
        const years = key !== 'copy' || this.mode === 'all' ? '' : `&year_to=${this.year}` + (this.mode === 'century' ? `&year_from=${this.year - 99}` : '');

        return `${config.search[key]}&copy_city=${encodeURIComponent(this.selected.n)}${years}`;
    },

    polygons(geometry) {
        return geometry.type === 'MultiPolygon' ? geometry.coordinates : [geometry.coordinates];
    },

    regionColor(region) {
        return regionColor(config.regions, region);
    },

    /** The frame of the map and its series, in the colours of the page as it is now */
    base() {
        const ink = this.css('--ink'), paper = this.css('--paper'), surface = this.css('--surface'), line = this.css('--line-strong');
        const font = getComputedStyle(document.body).fontFamily;
        const esc = (text) => String(text).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const zoom = chart.getOption()?.geo?.[0];
        chart.setOption({
            backgroundColor: 'transparent',
            geo: {
                map: 'fahares', roam: true, silent: true,
                boundingCoords: [[config.frame.lon0, config.frame.lat1], [config.frame.lon1, config.frame.lat0]],
                center: zoom?.center ?? CENTER, zoom: zoom?.zoom ?? 1, scaleLimit: { min: 0.8, max: 16 },
                itemStyle: { areaColor: surface, borderColor: line, borderWidth: 0.6 },
                regions: [{ name: 'lake', itemStyle: { areaColor: paper, borderColor: line } }],
            },
            // The box of figures, as the one the marks of the other charts share (chart-tip.js and the page's
            // [data-chart-tip], step 14.377): the same colours, size, corners and shadow, «name: figure» first and
            // every other part on a line of its own, and no slide from one mark to the next
            tooltip: {
                trigger: 'item', confine: true, backgroundColor: ink, borderWidth: 0, padding: [6, 10], transitionDuration: 0,
                textStyle: { color: paper, fontFamily: font, fontSize: 12 },
                extraCssText: 'border-radius: 4px; line-height: 1.625; font-variant-numeric: tabular-nums; white-space: pre-line; '
                    + 'max-width: min(20rem, calc(100vw - 1rem)); box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);',
                formatter: (point) => {
                    if (point.seriesId === 'lands') {
                        const land = lands[point.value]?.properties;

                        return land ? `${esc(land.name)}: ${esc(land.span)}\n${esc(land.region)}` : '';
                    }
                    if (point.seriesId === 'capitals') {
                        return `${esc(point.name)}: پایتخت ${esc(point.data.capital.d)}\n${this.fa(point.data.capital.f, false)} – ${this.fa(point.data.capital.t, false)} ق`;
                    }
                    if (point.seriesId === 'cities') {
                        return `${esc(point.name)}: ${this.fa(point.value[2])} ${this.unit}\n${this.span}`;
                    }

                    return '';
                },
            },
            series: [
                {
                    id: 'lands', type: 'custom', coordinateSystem: 'geo', data: [], z: 2,
                    renderItem: (params, api) => {
                        const land = lands[api.value(0)];
                        if (! land) {
                            return null;
                        }
                        const color = this.regionColor(land.properties.region);

                        return {
                            type: 'group',
                            children: this.polygons(land.geometry).map((polygon) => ({
                                type: 'polygon', shape: { points: polygon[0].map((point) => api.coord(point)) },
                                style: { fill: color, opacity: 0.22, stroke: color, lineWidth: 1.1 },
                                emphasis: { style: { opacity: 0.4 } },
                            })),
                        };
                    },
                },
                {
                    // The seats of the states of the chosen decade (step 14.278): a small square, named when there is room
                    id: 'capitals', type: 'scatter', coordinateSystem: 'geo', data: [], z: 4, symbol: 'rect', symbolSize: 7,
                    itemStyle: { color: surface, borderColor: ink, borderWidth: 1.5 },
                    label: {
                        show: true, position: 'top', distance: 3, formatter: (point) => point.name, fontFamily: font, fontSize: 10,
                        color: ink, textBorderColor: paper, textBorderWidth: 2,
                    },
                    labelLayout: { hideOverlap: true },
                },
                {
                    id: 'cities', type: 'scatter', coordinateSystem: 'geo', data: [], z: 5, cursor: 'pointer',
                    itemStyle: { opacity: 0.7, borderColor: surface, borderWidth: 1.5 },
                    emphasis: { scale: false, itemStyle: { opacity: 1 } },
                    label: {
                        show: true, position: 'right', distance: 4, formatter: (point) => point.name, fontFamily: font, fontSize: 12, fontWeight: 600,
                        color: ink, textBorderColor: paper, textBorderWidth: 3,
                    },
                    // Names that would overlap are left out, the larger city first: zooming in makes room for more
                    labelLayout: { hideOverlap: true },
                },
            ],
        }, true);
    },

    async landsOf(year) {
        // A file holds the territories of the middle year of a decade and is named by it: 1035 for 1031–1040
        const file = year - 5;
        territories[file] ??= fetch(config.territories.replace('__YEAR__', file)).then((response) => response.ok ? response.json() : { features: [] })
            .catch(() => ({ features: [] }));

        return (await territories[file]).features;
    },

    async draw() {
        if (! chart) {
            return;
        }
        const mine = ++ticket;
        const found = this.mode === 'all' ? [] : await this.landsOf(this.year);
        if (mine !== ticket) {
            return;
        }
        lands = found;
        this.drawn++;
        const rows = cities.map((city) => ({ city, n: this.value(city) })).filter((row) => row.n > 0).sort((a, b) => b.n - a.n);
        const most = Math.max(1, rows[0]?.n ?? 1);
        const color = this.css(LAYERS[this.layer].color);
        // Smaller circles on a narrow map, so the cities of a phone's screen do not run into one another
        const scale = Math.max(0.55, Math.min(1, chart.getWidth() / 640));
        chart.setOption({
            series: [
                { id: 'lands', data: lands.map((land, i) => ({ value: i, name: land.properties.name })) },
                {
                    // A capital in the middle year of the decade the territories are of
                    id: 'capitals',
                    data: this.mode === 'all' ? [] : config.capitals.filter((capital) => capital.f <= this.year - 5 && this.year - 5 <= capital.t)
                        .map((capital) => ({ name: capital.n, value: [capital.lon, capital.lat], capital })),
                },
                {
                    id: 'cities', itemStyle: { color },
                    data: rows.map(({ city, n }) => ({
                        name: city.n, value: [city.lon, city.lat, n], city, symbolSize: (5 + 27 * Math.sqrt(n / most)) * scale,
                        ...(this.selected?.n === city.n ? { itemStyle: { opacity: 1, borderColor: this.css('--ink'), borderWidth: 2.5 } } : {}),
                    })),
                },
            ],
        });
    },

    select(city) {
        this.selected = city;
        this.draw();
    },

    zoomBy(factor) {
        const geo = chart.getOption().geo[0];
        chart.setOption({ geo: { zoom: Math.min(16, Math.max(0.8, geo.zoom * factor)), center: geo.center } });
    },

    reset() {
        chart.setOption({ geo: { zoom: 1, center: CENTER } });
    },

    stop() {
        clearInterval(timer);
        timer = null;
        this.playing = false;
    },

    play() {
        if (this.playing) {
            return this.stop();
        }
        if (this.mode === 'all') {
            this.mode = 'until';
        }
        if (this.year >= this.years[1]) {
            this.year = this.years[0];
        }
        this.playing = true;
        timer = setInterval(() => {
            if (this.year >= this.years[1]) {
                this.stop();
            } else {
                this.year += 10;
            }
        }, 700);
    },
    };
};
