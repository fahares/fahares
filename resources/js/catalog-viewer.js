// The printed-page viewer (backlog item 94), opened by the `open-catalog-viewer` event with {catalog, catalogName, volume, page}.
// One Pointer Events engine serves mouse, touch and pen: one-finger or mouse drag pans, two fingers pinch around their
// midpoint, a double tap or double click steps through fit / fit-width / 2.5×, and at base zoom a horizontal swipe turns
// the page (right-to-left book: a swipe to the right brings the next page). The image is placed at the stage's top-left
// and moved with translate + scale (origin 0 0), so every zoom keeps the point under the pointer or fingers in place.
// A hand-written engine rather than @panzoom/panzoom: the swipe, tap and double-tap rules need the same pointer stream
// the pinch uses, and panzoom captures it for itself.
const MAX_ZOOM = 5;          // times the fit scale
const MIN_ZOOM = 0.5;
const STEP = 1.25;           // a zoom button or key press
const TAP_MOVE = 10;         // px: more than this is a drag
const DOUBLE_TAP_MS = 300;
const SWIPE_MIN = 60;        // px of horizontal travel that turns the page
const PAN_KEY = 75;
const FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';

const fa = (n) => String(n ?? '').replace(/\d/g, (d) => FA_DIGITS[d]);
// Persian or Arabic-Indic digits typed into the jump box
const latin = (s) => String(s).replace(/[۰-۹]/g, (d) => d.charCodeAt(0) - 0x06F0).replace(/[٠-٩]/g, (d) => d.charCodeAt(0) - 0x0660);
const phone = () => window.matchMedia('(max-width: 639px), (pointer: coarse)').matches;

export default (config) => ({
    open: false,
    catalog: 'fankha',
    catalogName: 'فنخا',
    volume: 1,
    page: 1,
    // The volume's page range, from the page-images endpoint; null until known (next is then not capped)
    first: 1,
    last: null,
    // The image on screen and its size; it changes only once the next page has loaded, so the old page stays meanwhile
    shownUrl: '',
    natW: 0,
    natH: 0,
    loading: false,
    hasError: false,
    // View: scale and top-left offset in stage pixels; mode is the base the view returns to
    scale: 1,
    x: 0,
    y: 0,
    mode: 'fit',
    animate: false,
    gesturing: false,
    barsHidden: false,
    jumpOpen: false,
    jumpValue: '',
    fa,

    pointers: new Map(),
    gesture: null,
    lastTap: null,
    tapTimer: null,
    preloaded: new Map(),
    ranges: new Map(),
    pushedState: false,

    init() {
        window.addEventListener('open-catalog-viewer', (e) => this.openViewer(e.detail || {}));
        // The former event name
        window.addEventListener('open-fankha-viewer', (e) => this.openViewer({ catalog: 'fankha', catalogName: 'فنخا', ...(e.detail || {}) }));
        window.addEventListener('keydown', (e) => this.onKey(e));
        window.addEventListener('resize', () => this.open && this.relayout());
        // The Android back button (or the browser's) closes the viewer instead of leaving the page
        window.addEventListener('popstate', () => {
            if (this.open && this.pushedState) {
                this.pushedState = false;
                this.closeViewer();
            }
        });
    },

    get pageLabel() {
        // «،» rather than «•», which reads as a Persian zero beside the digits
        return `ج ${fa(this.volume)}، ص ${fa(this.page)}`;
    },

    get countLabel() {
        return this.last ? `صفحه‌ی ${fa(this.page)} از ${fa(this.last)}` : `صفحه‌ی ${fa(this.page)}`;
    },

    get zoomLabel() {
        return fa(Math.round((this.scale / (this.fitScale() || 1)) * 100)) + '٪';
    },

    get canPrev() {
        return this.page > this.first;
    },

    get canNext() {
        return this.last === null || this.page < this.last;
    },

    get atMax() {
        return this.natW > 0 && this.scale >= this.maxScale() - 1e-6;
    },

    get atMin() {
        return this.natW > 0 && this.scale <= this.minScale() + 1e-6;
    },

    get transform() {
        return `translate3d(${this.x}px, ${this.y}px, 0) scale(${this.scale})`;
    },

    urlFor(page) {
        const v = String(this.volume).padStart(2, '0');
        const p = String(page).padStart(4, '0');
        return `${config.baseUrl}/${this.catalog}/v${v}/p${p}.webp`;
    },

    get imageUrl() {
        return this.urlFor(this.page);
    },

    openViewer(detail) {
        const catalog = detail.catalog || 'fankha';
        const volume = parseInt(detail.volume) || 1;
        const sameVolume = this.catalog === catalog && this.volume === volume;
        this.catalog = catalog;
        this.catalogName = detail.catalogName || 'فنخا';
        this.volume = volume;
        if (!sameVolume) {
            this.first = 1;
            this.last = null;
        }
        this.mode = phone() ? 'width' : 'fit';
        this.barsHidden = false;
        this.jumpOpen = false;
        if (!this.open) {
            this.open = true;
            document.body.classList.add('overflow-hidden');
            history.pushState({ catalogViewer: true }, '');
            this.pushedState = true;
        }
        this.fetchRange();
        this.goTo(parseInt(detail.page) || 1, true);
        setTimeout(() => this.$refs.close?.focus({ preventScroll: true }));
    },

    closeViewer() {
        if (!this.open) return;
        this.open = false;
        this.jumpOpen = false;
        this.pointers.clear();
        this.gesture = null;
        document.body.classList.remove('overflow-hidden');
        // Closed by its own button or Esc: drop the history entry it added
        if (this.pushedState) {
            this.pushedState = false;
            history.back();
        }
    },

    async fetchRange() {
        const key = `${this.catalog}/${this.volume}`;
        if (!this.ranges.has(key)) {
            this.ranges.set(key, fetch(`${config.rangeUrl}/${this.catalog}/${this.volume}`, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : null)).catch(() => null));
        }
        const range = await this.ranges.get(key);
        if (range && key === `${this.catalog}/${this.volume}`) {
            this.first = range.first;
            this.last = range.last;
        }
    },

    // Loads a page into the cache; resolves with the Image, or rejects
    load(page, bust = false) {
        const url = this.urlFor(page) + (bust ? `?t=${Date.now()}` : '');
        if (!bust && this.preloaded.has(url)) return this.preloaded.get(url);
        const promise = new Promise((resolve, reject) => {
            const img = new Image();
            img.decoding = 'async';
            img.onload = () => resolve(img);
            img.onerror = () => reject(new Error('missing'));
            img.src = url;
        });
        promise.catch(() => this.preloaded.delete(url));
        this.preloaded.set(url, promise);
        if (this.preloaded.size > 12) this.preloaded.delete(this.preloaded.keys().next().value);
        return promise;
    },

    goTo(page, fresh = false, bust = false) {
        page = Math.max(this.first, this.last ? Math.min(this.last, page) : page);
        if (!fresh && page === this.page && !this.hasError) return;
        this.page = page;
        this.loading = true;
        this.hasError = false;
        const wanted = `${this.catalog}/${this.volume}/${page}`;
        this.load(page, bust).then((img) => {
            if (wanted !== `${this.catalog}/${this.volume}/${this.page}`) return;
            this.shownUrl = img.src;
            this.natW = img.naturalWidth;
            this.natH = img.naturalHeight;
            this.loading = false;
            this.resetView(false);
            // The adjacent pages, for an instant turn
            if (page > this.first) this.load(page - 1);
            if (this.last === null || page < this.last) this.load(page + 1);
        }).catch(() => {
            if (wanted !== `${this.catalog}/${this.volume}/${this.page}`) return;
            this.loading = false;
            this.hasError = true;
        });
    },

    retry() {
        this.goTo(this.page, true, true);
    },

    prevPage() {
        if (this.canPrev) this.goTo(this.page - 1);
    },

    nextPage() {
        if (this.canNext) this.goTo(this.page + 1);
    },

    toggleJump() {
        this.jumpOpen = !this.jumpOpen;
        if (this.jumpOpen) {
            this.jumpValue = '';
            // Once x-show has shown the box
            setTimeout(() => this.$refs.jumpInput?.focus());
        }
    },

    submitJump() {
        const page = parseInt(latin(this.jumpValue));
        this.jumpOpen = false;
        if (page > 0) this.goTo(page);
    },

    // ---- geometry ----

    // The stage area the image may use: the whole stage less the bars on screen
    box(withBars = !this.barsHidden) {
        const stage = this.$refs.stage;
        const W = stage?.clientWidth || window.innerWidth;
        const H = stage?.clientHeight || window.innerHeight;
        const top = withBars ? (this.$refs.header?.offsetHeight || 0) : 0;
        const bottom = withBars ? (this.$refs.footer?.offsetHeight || 0) : 0;
        return { W, H, top, bottom, h: H - top - bottom };
    },

    pad() {
        return phone() ? 4 : 16;
    },

    fitScale() {
        if (!this.natW) return 1;
        const b = this.box(true);
        const p = this.pad();
        return Math.min((b.W - 2 * p) / this.natW, (b.h - 2 * p) / this.natH);
    },

    widthScale() {
        if (!this.natW) return 1;
        return (this.box(true).W - 2 * (phone() ? 0 : this.pad())) / this.natW;
    },

    baseScale() {
        return this.mode === 'width' ? this.widthScale() : this.fitScale();
    },

    minScale() {
        return this.fitScale() * MIN_ZOOM;
    },

    maxScale() {
        return Math.max(this.fitScale() * MAX_ZOOM, this.widthScale() * 2.5);
    },

    // Whether the view is at its base zoom, where a swipe turns the page
    atBase() {
        return this.scale <= Math.max(this.fitScale(), this.widthScale()) * 1.02 && this.natW * this.scale <= this.box().W + 1;
    },

    // Keeps the image inside the stage: centred along an axis it fits in, edge to edge along one it overflows
    clamp(x, y, s = this.scale) {
        const b = this.box();
        const w = this.natW * s;
        const h = this.natH * s;
        x = w <= b.W ? (b.W - w) / 2 : Math.min(0, Math.max(b.W - w, x));
        y = h <= b.h ? b.top + (b.h - h) / 2 : Math.min(b.top, Math.max(b.top + b.h - h, y));
        return { x, y };
    },

    setView(s, x, y, animate = true) {
        const c = this.clamp(x, y, s);
        this.animate = animate;
        this.scale = s;
        this.x = c.x;
        this.y = c.y;
    },

    // Zooms to scale s keeping the stage point (px, py) still; without a point, around the centre of the free area
    zoomTo(s, px, py, animate = true) {
        s = Math.min(this.maxScale(), Math.max(this.minScale(), s));
        if (px === undefined) {
            const b = this.box();
            px = b.W / 2;
            py = b.top + b.h / 2;
        }
        const k = s / this.scale;
        this.setView(s, px - (px - this.x) * k, py - (py - this.y) * k, animate);
    },

    zoomIn(px, py) {
        this.zoomTo(this.scale * STEP, px, py);
    },

    zoomOut(px, py) {
        this.zoomTo(this.scale / STEP, px, py);
    },

    // Back to the base mode, with the top (and the right edge, where a line starts) of the page in view
    resetView(animate = true) {
        this.setView(this.baseScale(), -Infinity, Infinity, animate);
    },

    toggleMode() {
        this.mode = this.mode === 'fit' ? 'width' : 'fit';
        this.resetView();
    },

    relayout() {
        if (this.atBase()) this.resetView(false);
        else this.setView(this.scale, this.x, this.y, false);
    },

    toggleBars() {
        this.barsHidden = !this.barsHidden;
        this.jumpOpen = false;
        this.setView(this.scale, this.x, this.y);
    },

    // Double tap: the next larger of fit, fit-width and 2.5× fit, or back to the smallest
    cycleZoom(px, py) {
        const fit = this.fitScale();
        const width = this.widthScale();
        const levels = [fit];
        if (Math.abs(width - fit) / fit > 0.04) levels.push(width);
        levels.sort((a, b) => a - b);
        levels.push(Math.max(fit * 2.5, levels[levels.length - 1] * 1.6));
        const next = levels.find((l) => l > this.scale * 1.02) ?? levels[0];
        if (next === levels[0] || Math.abs(next - fit) < 1e-6) this.mode = 'fit';
        if (Math.abs(next - width) < 1e-6) this.mode = 'width';
        if (next === levels[0]) this.resetView();
        else this.zoomTo(next, px, py);
    },

    // ---- pointer gestures ----

    point(e) {
        const r = this.$refs.stage.getBoundingClientRect();
        return { x: e.clientX - r.left, y: e.clientY - r.top };
    },

    startPan(p) {
        this.gesture = { kind: 'pending', sx: p.x, sy: p.y, x0: this.x, y0: this.y, t: Date.now(), moved: false, base: this.atBase(), multi: this.gesture?.multi };
    },

    startPinch() {
        const [a, b] = [...this.pointers.values()];
        this.gesture = {
            kind: 'pinch', multi: true, moved: true,
            d0: Math.hypot(a.x - b.x, a.y - b.y) || 1,
            mx: (a.x + b.x) / 2, my: (a.y + b.y) / 2,
            s0: this.scale, x0: this.x, y0: this.y,
        };
    },

    onPointerDown(e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        if (this.jumpOpen) {
            this.jumpOpen = false;
            return;
        }
        try {
            this.$refs.stage.setPointerCapture(e.pointerId);
        } catch (err) { /* a pointer the browser no longer tracks */ }
        this.pointers.set(e.pointerId, this.point(e));
        this.gesturing = true;
        this.animate = false;
        if (this.pointers.size === 1) this.startPan(this.point(e));
        else if (this.pointers.size === 2) this.startPinch();
    },

    onPointerMove(e) {
        if (!this.pointers.has(e.pointerId) || !this.gesture || !this.natW) return;
        const p = this.point(e);
        this.pointers.set(e.pointerId, p);
        const g = this.gesture;
        if (g.kind === 'pinch') {
            if (this.pointers.size < 2) return;
            const [a, b] = [...this.pointers.values()];
            const s = Math.min(this.maxScale(), Math.max(this.minScale(), g.s0 * Math.hypot(a.x - b.x, a.y - b.y) / g.d0));
            const mx = (a.x + b.x) / 2;
            const my = (a.y + b.y) / 2;
            // The image point that was under the starting midpoint follows the current midpoint
            this.setView(s, mx - (g.mx - g.x0) * (s / g.s0), my - (g.my - g.y0) * (s / g.s0), false);
            return;
        }
        const dx = p.x - g.sx;
        const dy = p.y - g.sy;
        if (g.kind === 'pending') {
            if (Math.hypot(dx, dy) < TAP_MOVE) return;
            g.moved = true;
            // At base zoom a mainly horizontal touch drag is a page swipe; anything else pans
            g.kind = g.base && e.pointerType !== 'mouse' && Math.abs(dx) > Math.abs(dy) ? 'swipe' : 'pan';
        }
        if (g.kind === 'swipe') {
            g.dx = dx;
            // The page follows the finger a little, as feedback
            this.x = this.clamp(g.x0, g.y0).x + dx * 0.35;
        } else {
            this.setView(this.scale, g.x0 + dx, g.y0 + dy, false);
        }
    },

    onPointerUp(e) {
        if (!this.pointers.has(e.pointerId)) return;
        const p = this.point(e);
        this.pointers.delete(e.pointerId);
        const g = this.gesture;
        if (this.pointers.size === 1) {
            // A finger of the pinch lifted: the other one carries on panning
            this.startPan([...this.pointers.values()][0]);
            this.gesture.kind = 'pan';
            return;
        }
        if (this.pointers.size > 0) return;
        this.gesturing = false;
        this.gesture = null;
        if (!g) return;
        if (g.kind === 'swipe') {
            if (e.type !== 'pointercancel' && Math.abs(g.dx) >= SWIPE_MIN && (g.dx > 0 ? this.canNext : this.canPrev)) {
                if (g.dx > 0) this.nextPage();
                else this.prevPage();
            } else {
                this.setView(this.scale, g.x0, g.y0);
            }
            return;
        }
        if (g.kind === 'pinch' || g.multi) {
            // Let a pinch below the fit settle back
            if (this.scale < this.fitScale()) this.resetView();
            return;
        }
        if (g.moved || e.type === 'pointercancel' || Date.now() - g.t > 500) return;
        this.onTap(p, e.pointerType);
    },

    onTap(p, type) {
        const now = Date.now();
        const last = this.lastTap;
        if (last && now - last.t < DOUBLE_TAP_MS && Math.hypot(p.x - last.x, p.y - last.y) < 30) {
            clearTimeout(this.tapTimer);
            this.lastTap = null;
            this.cycleZoom(p.x, p.y);
            return;
        }
        this.lastTap = { ...p, t: now };
        // A single tap shows or hides the bars on a touch screen, once it is clear no second tap follows
        if (type !== 'mouse') {
            clearTimeout(this.tapTimer);
            this.tapTimer = setTimeout(() => this.toggleBars(), DOUBLE_TAP_MS);
        }
    },

    onWheel(e) {
        if (!this.natW) return;
        if (e.ctrlKey || e.metaKey) {
            // Ctrl+wheel, and a trackpad pinch, zoom around the pointer
            const p = this.point(e);
            const factor = Math.exp(-e.deltaY * (e.deltaMode === 1 ? 0.05 : 0.0025));
            this.zoomTo(this.scale * factor, p.x, p.y, false);
        } else {
            this.setView(this.scale, this.x - e.deltaX, this.y - e.deltaY * 0.9, false);
        }
    },

    onKey(e) {
        if (!this.open) return;
        if (e.target?.closest?.('input, textarea')) {
            if (e.key === 'Escape') {
                e.preventDefault();
                this.jumpOpen = false;
            }
            return;
        }
        const keys = {
            Escape: () => (this.jumpOpen ? (this.jumpOpen = false) : this.closeViewer()),
            ArrowUp: () => this.setView(this.scale, this.x, this.y + PAN_KEY),
            ArrowDown: () => this.setView(this.scale, this.x, this.y - PAN_KEY),
            ArrowLeft: () => this.setView(this.scale, this.x + PAN_KEY, this.y),
            ArrowRight: () => this.setView(this.scale, this.x - PAN_KEY, this.y),
            PageUp: () => this.prevPage(),
            PageDown: () => this.nextPage(),
            '+': () => this.zoomIn(),
            '=': () => this.zoomIn(),
            '-': () => this.zoomOut(),
            _: () => this.zoomOut(),
            0: () => this.resetView(),
        };
        if (keys[e.key] && !e.ctrlKey && !e.metaKey && !e.altKey) {
            e.preventDefault();
            keys[e.key]();
        }
    },
});
