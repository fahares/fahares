// Review of machine-extracted data on a record page (backlog item 59): the ⓘ badge's «درست است» and «نادرست است»
// post to its review URLs without a reload. A verified datum loses its badges; a rejected one is dimmed, and hidden
// once the «بازگردانی» toast is gone, as a reload would no longer show it. Every badge and element of one datum
// shares a key (data-machine-badge, data-machine-item), so the sidebar card and the printed text go together.
const UNDO_MS = 8000;

const FAILURES = {
    401: 'از حساب خارج شده‌اید؛ دوباره وارد شوید و از نو داوری کنید.',
    403: 'این حساب اجازه‌ی داوری ندارد.',
    419: 'نشست شما منقضی شده است؛ صفحه را تازه کنید و دوباره بکوشید.',
};

export default {
    // key → status after a review on this page; a key not here is still unverified
    statuses: {},
    busy: false,
    // { message, error, key, undo: { url, status, csrf } }
    toast: null,
    timer: null,

    awaiting(key) {
        return (this.statuses[key] ?? 'unverified') === 'unverified';
    },

    async send(review, action) {
        if (this.busy) return;
        this.settle();
        const data = await this.post(review[action], review.csrf, {});
        if (!data) return;
        this.mark(review.key, data.status);
        this.show({ message: data.message, key: review.key, undo: data.undo && { url: data.undo, status: data.status, csrf: review.csrf } });
    },

    async undo() {
        const t = this.toast;
        if (!t?.undo || this.busy) return;
        clearTimeout(this.timer);
        const data = await this.post(t.undo.url, t.undo.csrf, { status: t.undo.status });
        if (!data) return;
        this.mark(t.key, data.status);
        this.show({ message: data.message, key: t.key });
    },

    // Ends the undo window: a rejected datum leaves the page.
    settle() {
        clearTimeout(this.timer);
        const key = this.toast?.key;
        if (key && this.statuses[key] === 'rejected') {
            this.items(key).forEach((el) => { el.hidden = true; });
        }
        this.toast = null;
    },

    show(toast) {
        clearTimeout(this.timer);
        this.toast = toast;
        this.timer = setTimeout(() => this.settle(), UNDO_MS);
    },

    mark(key, status) {
        this.statuses[key] = status;
        this.items(key).forEach((el) => {
            el.hidden = false;
            el.toggleAttribute('data-machine-rejected', status === 'rejected');
        });
    },

    items(key) {
        return document.querySelectorAll(`[data-machine-item="${CSS.escape(key)}"]`);
    },

    // The JSON of a successful request, or null after showing why it failed.
    async post(url, csrf, body) {
        this.busy = true;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) return data;
            this.fail(FAILURES[res.status] ?? data.message ?? `خطای سرور (کد ${res.status})؛ داوری ثبت نشد.`);
        } catch (e) {
            this.fail('ارتباط با سرور برقرار نشد و داوری ثبت نشد. اتصال اینترنت را بررسی کنید و دوباره بکوشید.');
        } finally {
            this.busy = false;
        }
        return null;
    },

    fail(message) {
        this.show({ message, error: true, key: this.toast?.key, undo: this.toast?.undo });
    },
};
