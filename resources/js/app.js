import Alpine from 'alpinejs';
import QRCode from 'qrcode';
import suggestDesk from './suggest-desk';
import convertDesk from './convert-desk';
import feedbackForm from './feedback-form';
import machineReview from './machine-review';
import referenceReview from './reference-queue';
import webPush from './web-push';

window.Alpine = Alpine;
window.QRCode = QRCode;

// "Suggest a correction" mode: reveals the per-field buttons on record pages
Alpine.store('suggest', {
    on: false,
    toggle() {
        this.on = !this.on;
    },
});
// Review of machine-extracted data from its ⓘ badge (backlog item 59)
Alpine.store('machineReview', machineReview);
Alpine.data('suggestDesk', suggestDesk);
Alpine.data('convertDesk', convertDesk);
Alpine.data('feedbackForm', feedbackForm);
Alpine.data('referenceReview', referenceReview);
Alpine.data('webPush', webPush);

Alpine.start();
