// One box of the filter form of the insights page (backlog item 107): it looks up its choices as the reader types
// (InsightsController::lookup) and keeps the one picked in a hidden field; typed text alone is no choice.
export default ({ field, value = '', label = '', url }) => ({
    field,
    value: value === null ? '' : String(value),
    label,
    text: label,
    items: [],
    open: false,
    loading: false,
    active: -1,
    timer: null,

    typed() {
        this.value = '';
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 200);
    },

    async load() {
        this.loading = true;
        try {
            const res = await fetch(`${url}?field=${this.field}&q=${encodeURIComponent(this.text.trim())}`, { headers: { Accept: 'application/json' } });
            this.items = res.ok ? await res.json() : [];
        } catch (e) {
            this.items = [];
        }
        this.loading = false;
        this.open = true;
        this.active = this.items.length ? 0 : -1;
    },

    focus() {
        this.items.length ? (this.open = true) : this.load();
    },

    pick(item) {
        this.value = String(item.v);
        this.label = this.text = item.label;
        this.open = false;
    },

    clear() {
        this.value = '';
        this.label = this.text = '';
        this.items = [];
    },

    move(step) {
        if (!this.open || !this.items.length) {
            return this.focus();
        }
        this.active = (this.active + step + this.items.length) % this.items.length;
    },

    enter(event) {
        if (this.open && this.items[this.active]) {
            event.preventDefault();
            this.pick(this.items[this.active]);
        }
    },

    // Leaving the box: back to the last choice, or empty
    leave() {
        setTimeout(() => {
            this.open = false;
            this.text = this.value ? this.label : '';
        }, 150);
    },
});
