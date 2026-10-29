import Alpine from 'alpinejs';
import QRCode from 'qrcode';
import suggestDesk from './suggest-desk';
import convertDesk from './convert-desk';
import feedbackForm from './feedback-form';
import machineReview from './machine-review';
import referenceReview from './reference-queue';
import webPush from './web-push';
import catalogViewer from './catalog-viewer';

window.Alpine = Alpine;
window.QRCode = QRCode;

// "Suggest a correction" mode: reveals the per-field buttons on record pages
Alpine.store('suggest', {
    on: false,
    toggle() {
        this.on = !this.on;
    },
});
// The «پیشنهاد اصلاح» button of a record page (backlog item 92): a one-time hint, and a floating copy once it scrolls away
const SUGGEST_HINT_KEY = 'suggest-hint-seen';
Alpine.data('suggestToggle', () => ({
    away: false,
    hint: false,
    init() {
        try { this.hint = !localStorage.getItem(SUGGEST_HINT_KEY); } catch (e) { /* storage blocked: no hint */ }
        this.$nextTick(() => new IntersectionObserver(([entry]) => {
            // Out of view above the viewport only; not while the page has not reached it yet
            this.away = !entry.isIntersecting && entry.boundingClientRect.top < 0;
        }).observe(this.$refs.inline));
    },
    dismissHint() {
        this.hint = false;
        try { localStorage.setItem(SUGGEST_HINT_KEY, '1'); } catch (e) { /* storage blocked */ }
    },
}));
// Review of machine-extracted data from its ⓘ badge (backlog item 59)
Alpine.store('machineReview', machineReview);
Alpine.data('suggestDesk', suggestDesk);
Alpine.data('convertDesk', convertDesk);
Alpine.data('feedbackForm', feedbackForm);
Alpine.data('referenceReview', referenceReview);
Alpine.data('webPush', webPush);
Alpine.data('catalogViewer', catalogViewer);

Alpine.start();
