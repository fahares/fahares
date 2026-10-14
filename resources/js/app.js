import Alpine from 'alpinejs';
import QRCode from 'qrcode';
import suggestDesk from './suggest-desk';
import convertDesk from './convert-desk';
import feedbackForm from './feedback-form';

window.Alpine = Alpine;
window.QRCode = QRCode;

// "Suggest a correction" mode: reveals the per-field buttons on record pages
Alpine.store('suggest', {
    on: false,
    toggle() {
        this.on = !this.on;
    },
});
Alpine.data('suggestDesk', suggestDesk);
Alpine.data('convertDesk', convertDesk);
Alpine.data('feedbackForm', feedbackForm);

Alpine.start();
