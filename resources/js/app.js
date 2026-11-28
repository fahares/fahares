import Alpine from 'alpinejs';
import QRCode from 'qrcode';
import suggestDesk from './suggest-desk';
import convertDesk from './convert-desk';
import structureDesk from './structure-desk';
import feedbackForm from './feedback-form';
import machineReview from './machine-review';
import referenceReview from './reference-queue';
import webPush from './web-push';
import catalogViewer from './catalog-viewer';
import citeQuote from './cite-quote';
import sendOnce from './send-once';
import insightsCombo from './insights-filter';
import announcement from './announcement';
import chartTip from './chart-tip';
import insightsMap from './insights-map';
import insightsDynasties from './insights-dynasties';

window.Alpine = Alpine;
window.QRCode = QRCode;

// "Suggest a correction" mode: reveals the per-field buttons on record pages
Alpine.store('suggest', {
    on: false,
    toggle() {
        this.on = !this.on;
        // ending the suggestion mode ends the edit mode it carries
        if (!this.on && Alpine.store('edit').on) Alpine.store('edit').toggle();
    },
});
// The edit mode of reviewers (backlog item 67): every ✎ opens an editor in place that applies the value at once
// (admin.suggestions.direct-edit). Kept on across pages in localStorage; only for users the page marks as allowed.
const EDIT_MODE_KEY = 'edit-mode';
const editAllowed = document.querySelector('meta[name="edit-mode"]') !== null;
Alpine.store('edit', {
    allowed: editAllowed,
    on: false,
    init() {
        let saved = false;
        try { saved = localStorage.getItem(EDIT_MODE_KEY) === '1'; } catch (e) { /* storage blocked */ }
        if (this.allowed && saved) this.set(true);
    },
    set(on) {
        this.on = on && this.allowed;
        document.documentElement.toggleAttribute('data-edit-mode', this.on);
        // the strong ✎ and the empty fields of the suggestion mode come with it
        Alpine.store('suggest').on = this.on;
        try { this.on ? localStorage.setItem(EDIT_MODE_KEY, '1') : localStorage.removeItem(EDIT_MODE_KEY); } catch (e) { /* storage blocked */ }
    },
    toggle() {
        this.set(!this.on);
    },
});
// The «پیشنهاد اصلاح» button of a record page (backlog item 92): a one-time hint, and a floating copy once it scrolls away
const SUGGEST_HINT_KEY = 'suggest-hint-seen';
Alpine.data('suggestToggle', () => ({
    away: false,
    hint: false,
    // The three ways of contributing, under the inline button or over the floating one
    menu: null,
    init() {
        try { this.hint = !localStorage.getItem(SUGGEST_HINT_KEY); } catch (e) { /* storage blocked: no hint */ }
        // The hint lies over the first fields: it goes by itself after a while, or once the reader moves on
        if (this.hint) {
            setTimeout(() => this.dismissHint(), 9000);
            window.addEventListener('scroll', () => this.dismissHint(), { once: true, passive: true });
        }
        this.$nextTick(() => new IntersectionObserver(([entry]) => {
            // Out of view above the viewport only; not while the page has not reached it yet
            this.away = !entry.isIntersecting && entry.boundingClientRect.top < 0;
        }).observe(this.$refs.inline));
    },
    choose(detail) {
        this.menu = null;
        this.dismissHint();
        if (detail.mode) this.$store.suggest.on = true;
        else this.$dispatch('suggest-field', detail);
    },
    dismissHint() {
        if (!this.hint) return;
        this.hint = false;
        try { localStorage.setItem(SUGGEST_HINT_KEY, '1'); } catch (e) { /* storage blocked */ }
    },
}));
// Review of machine-extracted data from its ⓘ badge (backlog item 59)
Alpine.store('machineReview', machineReview);
Alpine.data('suggestDesk', suggestDesk);
Alpine.data('convertDesk', convertDesk);
Alpine.data('structureDesk', structureDesk);
Alpine.data('feedbackForm', feedbackForm);
Alpine.data('referenceReview', referenceReview);
Alpine.data('webPush', webPush);
Alpine.data('catalogViewer', catalogViewer);
Alpine.data('citeQuote', citeQuote);
Alpine.data('insightsCombo', insightsCombo);
Alpine.data('insightsMap', insightsMap);
Alpine.data('insightsDynasties', insightsDynasties);
Alpine.data('announcement', announcement);

sendOnce();
chartTip();
Alpine.start();
