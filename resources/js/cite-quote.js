// «رونوشت با ارجاع» (step 14.389, backlog item 132): a piece of a source's text the reader selects is copied in
// quotation marks with its in-text citation, «… (فهرستواره منزوی، ج ۵، ص ۳۳۶۶)». The button floats over the
// selection; the browser's own copy is left as it is.
export default (cite, within = null) => ({
    show: false,
    done: false,
    x: 0,
    y: 0,
    text: '',

    check() {
        const sel = window.getSelection();
        const range = sel && !sel.isCollapsed && sel.rangeCount ? sel.getRangeAt(0) : null;
        const area = within ? this.$el.querySelector(within) : this.$el;
        const text = range && area && area.contains(range.commonAncestorContainer) ? sel.toString().replace(/\s+/g, ' ').trim() : '';
        if (!text) {
            if (!this.done) this.show = false;
            return;
        }
        const rect = range.getBoundingClientRect();
        this.text = text;
        this.x = rect.left + rect.width / 2;
        this.y = rect.top;
        this.done = false;
        this.show = true;
    },

    copy() {
        navigator.clipboard.writeText(`«${this.text}» ${cite}`);
        this.done = true;
        setTimeout(() => { this.show = false; this.done = false; }, 1800);
    },
});
