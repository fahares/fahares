// One row of the machine-matched catalogue references queue (backlog item 65): «تأیید» and «رد» post their form
// without a reload. The row stays, dimmed, with its new status and a «بازگردانی» that puts it back in the queue; the
// status tabs' counts follow. The forms still work on their own when scripts do not run.
import { FAILURES } from './machine-review';

const LABELS = { verified: 'تأیید شد', rejected: 'رد شد' };

export default (config) => ({
    status: 'unverified',
    undoUrl: null,
    busy: false,
    error: null,

    get label() {
        return LABELS[this.status] ?? '';
    },

    async send(form) {
        const data = await this.post(form.action, {});
        if (!data) return;
        this.count(this.status, -1);
        this.status = data.status;
        this.undoUrl = data.undo;
        this.count(this.status, 1);
    },

    async undo() {
        if (!this.undoUrl) return;
        const data = await this.post(this.undoUrl, { status: this.status });
        if (!data) return;
        this.count(this.status, -1);
        this.status = data.status;
        this.undoUrl = null;
        this.count(this.status, 1);
    },

    // The count beside a status tab, kept in step with the reviews made on this page
    count(status, delta) {
        const el = document.querySelector(`[data-tab-count="${status}"]`);
        if (!el) return;
        const n = Math.max(0, Number(el.dataset.n) + delta);
        el.dataset.n = n;
        el.textContent = n.toLocaleString('fa-IR');
    },

    // The JSON of a successful request, or null after showing why it failed
    async post(url, body) {
        if (this.busy) return null;
        this.busy = true;
        this.error = null;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) return data;
            this.error = FAILURES[res.status] ?? data.message ?? `خطای سرور (کد ${res.status})؛ داوری ثبت نشد.`;
        } catch (e) {
            this.error = 'ارتباط با سرور برقرار نشد و داوری ثبت نشد. اتصال اینترنت را بررسی کنید و دوباره بکوشید.';
        } finally {
            this.busy = false;
        }
        return null;
    },
});
