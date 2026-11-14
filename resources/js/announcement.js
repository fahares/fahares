// The site's public notice (x-announcement): shown once to a visitor, as a card in the corner or a window.
// The page brings the queue of running notices, newest first; the first one not yet seen is shown, and the
// next not before config.gap later. The browser remembers what it has shown; for a member the server does too.
const SEEN_KEY = 'announcements-seen';
const LAST_KEY = 'announcement-last-at';
const FIRST_VISIT_KEY = 'first-visit-at';
const NEWCOMER_MS = 60 * 60 * 1000; // a first-time visitor hears no site news for an hour
const SHOW_AFTER_MS = 1500;
const SEEN_AFTER_MS = 6000; // on screen this long in a front tab counts as seen

export default (config) => ({
    item: null,
    open: false,
    reported: false,

    init() {
        const item = this.pick();
        if (!item) return;
        this.item = item;
        // A tab opened in the background waits until the visitor looks at it
        const start = () => {
            if (document.visibilityState !== 'visible') return;
            document.removeEventListener('visibilitychange', start);
            setTimeout(() => this.show(), SHOW_AFTER_MS);
        };
        document.addEventListener('visibilitychange', start);
        start();
    },

    pick() {
        const items = config.items || [];
        if (!items.length) return null;
        if (config.preview) return items[0];
        try {
            if (!config.member) {
                let first = localStorage.getItem(FIRST_VISIT_KEY);
                if (first === null) {
                    // Anything already stored (the theme, a closed bar) is from an earlier visit
                    first = localStorage.length > 0 ? '0' : String(Date.now());
                    localStorage.setItem(FIRST_VISIT_KEY, first);
                }
                if (Date.now() - Number(first) < NEWCOMER_MS) return null;
            }
            // One notice was shown a short while ago: the rest of the queue waits
            if (Date.now() - Number(localStorage.getItem(LAST_KEY) || 0) < config.gap) return null;
            const seen = this.seenIds();
            return items.find((item) => !seen.includes(item.id)) || null;
        } catch (e) {
            // Storage is blocked: the notice could not be remembered and would return on every page
            return null;
        }
    },

    seenIds() {
        const ids = JSON.parse(localStorage.getItem(SEEN_KEY) || '[]');
        return Array.isArray(ids) ? ids : [];
    },

    show() {
        this.open = true;
        window.dispatchEvent(new CustomEvent('announcement-shown'));
        if (this.item.style === 'modal') this.$nextTick(() => this.$refs.close?.focus());
        setTimeout(() => {
            if (document.visibilityState === 'visible') this.markSeen();
        }, SEEN_AFTER_MS);
    },

    close() {
        if (!this.open) return;
        this.open = false;
        this.markSeen();
    },

    follow() {
        this.markSeen();
        this.report('click');
        this.open = false;
    },

    markSeen() {
        if (this.reported || config.preview) return;
        this.reported = true;
        try {
            localStorage.setItem(SEEN_KEY, JSON.stringify([...this.seenIds(), this.item.id].slice(-50)));
            localStorage.setItem(LAST_KEY, String(Date.now()));
        } catch (e) { /* storage blocked */ }
        this.report('view');
    },

    report(event) {
        if (config.preview) return;
        // keepalive: the request outlives the page when the visitor follows the link
        fetch(this.item.event, {
            method: 'POST',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
            body: JSON.stringify({ event }),
        }).catch(() => {});
    },
});
