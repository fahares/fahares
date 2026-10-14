// "Report a problem or suggest" from any page: sends the page address and title with the message
export default (config) => ({
    config,
    open: false,
    category: 'bug',
    message: '',
    guestName: '',
    guestEmail: '',
    notifyOn: false,
    files: [],
    website: '',
    submitting: false,
    errors: {},
    done: null,

    start(detail = {}) {
        this.errors = {};
        this.done = null;
        this.category = detail.category ?? 'bug';
        this.open = true;
    },

    async submit() {
        this.submitting = true;
        this.errors = {};
        const body = new FormData();
        body.append('category', this.category);
        body.append('message', this.message);
        body.append('page_url', window.location.pathname + window.location.search);
        body.append('page_title', document.title);
        body.append('guest_name', this.guestName);
        body.append('guest_email', this.guestEmail);
        if (this.notifyOn) body.append('notify_submitter', '1');
        this.files.forEach((file) => body.append('files[]', file));
        body.append('website', this.website);
        try {
            const res = await fetch(this.config.url, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf }, body });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                this.done = data.message;
                this.message = '';
                this.files = [];
            } else if (res.status === 422) {
                this.errors = Object.fromEntries(Object.entries(data.errors ?? {}).map(([k, v]) => [k, v[0]]));
            } else if (res.status === 429) {
                this.errors = { form: 'تعداد گزارش‌های ارسالی زیاد است. لطفاً کمی بعد دوباره تلاش کنید.' };
            } else {
                this.errors = { form: 'ارسال با خطا روبه‌رو شد. لطفاً به info@fahares.net بنویسید.' };
            }
        } catch (e) {
            this.errors = { form: 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.' };
        }
        this.submitting = false;
    },
});
