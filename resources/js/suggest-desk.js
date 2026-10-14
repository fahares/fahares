// The suggestion modal shared by record pages. Per-field buttons dispatch
// `suggest-field` with {field}; the general button dispatches it with {general: true}.
// Templates read field.* before any field is chosen, so "no field" is a blank object, never null
const BLANK_FIELD = { name: null, label: '', input: '', current: null, prefill: '', pending: 0 };

export default (config) => ({
    config,
    open: false,
    mode: 'field',
    field: BLANK_FIELD,
    value: '',
    rationale: '',
    personId: null,
    personResults: [],
    personTimer: null,
    relationKey: '',
    targetQuery: '',
    targetResults: [],
    target: null,
    targetTimer: null,
    guestName: '',
    guestEmail: '',
    // Opt-in: the name shown beside the correction on the record page once approved
    creditOn: false,
    creditName: '',
    // Guests only: email the review outcome
    notifyOn: false,
    website: '',
    submitting: false,
    errors: {},
    done: null,

    start(detail) {
        this.errors = {};
        this.done = null;
        this.personId = null;
        this.personResults = [];
        this.rationale = '';
        this.relationKey = '';
        this.targetQuery = '';
        this.targetResults = [];
        this.target = null;
        if (detail.general) {
            this.mode = 'general';
            this.field = BLANK_FIELD;
            this.value = '';
        } else {
            this.mode = 'field';
            this.field = { name: detail.field, ...this.config.fields[detail.field] };
            this.value = this.field.prefill;
        }
        this.open = true;
    },

    toggleCredit() {
        // Start from the submitter's own name the first time the box is ticked
        if (this.creditOn && !this.creditName.trim()) this.creditName = this.guestName.trim() || this.config.userName || '';
    },

    close() {
        this.open = false;
    },

    get title() {
        return this.mode === 'general' ? 'پیشنهاد کلی درباره‌ی این ' + this.config.typeLabel : 'پیشنهاد اصلاح «' + this.field.label + '»';
    },

    get relation() {
        return this.config.relations.find((r) => r.key === this.relationKey) ?? null;
    },

    chooseRelation() {
        this.target = null;
        this.targetQuery = '';
        this.targetResults = [];
    },

    searchTargets() {
        this.target = null;
        clearTimeout(this.targetTimer);
        const q = this.targetQuery.trim();
        if (!q || !this.relation) {
            this.targetResults = [];
            return;
        }
        this.targetTimer = setTimeout(async () => {
            try {
                const url = this.config.lookupUrl + '?type=' + this.relation.target + '&q=' + encodeURIComponent(q);
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                this.targetResults = (res.ok ? await res.json() : []).filter((r) => !(this.relation.target === this.config.type && r.id === this.config.id));
            } catch (e) {
                this.targetResults = [];
            }
        }, 300);
    },

    pickTarget(record) {
        this.target = record;
        this.targetResults = [];
    },

    searchPeople() {
        this.personId = null;
        clearTimeout(this.personTimer);
        const q = this.value.trim();
        if (q.length < 2) {
            this.personResults = [];
            return;
        }
        this.personTimer = setTimeout(async () => {
            try {
                const res = await fetch(this.config.peopleUrl + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                this.personResults = res.ok ? await res.json() : [];
            } catch (e) {
                this.personResults = [];
            }
        }, 300);
    },

    pickPerson(person) {
        this.value = person.name;
        this.personId = person.id;
        this.personResults = [];
    },

    // Reviewers only: applies the value at once and reloads the page to show it
    async submitDirect() {
        this.submitting = true;
        this.errors = {};
        const body = new FormData();
        body.append('type', this.config.type);
        body.append('id', this.config.id);
        body.append('field_name', this.field.name);
        body.append('suggested_value', this.value);
        if (this.personId) body.append('person_id', this.personId);
        try {
            const res = await fetch(this.config.directUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf },
                body,
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                window.location.reload();
                return;
            }
            this.errors = res.status === 422
                ? Object.fromEntries(Object.entries(data.errors ?? {}).map(([k, v]) => [k, v[0]]))
                : { form: 'ویرایش با خطا روبه‌رو شد.' };
        } catch (e) {
            this.errors = { form: 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.' };
        }
        this.submitting = false;
    },

    async submit() {
        this.submitting = true;
        this.errors = {};
        const body = new FormData();
        body.append('type', this.config.type);
        body.append('id', this.config.id);
        const kind = this.mode === 'general' && this.relationKey ? 'relation' : this.mode;
        body.append('kind', kind);
        if (this.mode === 'field') body.append('field_name', this.field.name);
        if (kind === 'relation') {
            body.append('field_name', this.relationKey);
            body.append('target_id', this.target?.id ?? '');
        }
        body.append('suggested_value', this.value);
        body.append('rationale_citation', this.rationale);
        if (this.personId) body.append('person_id', this.personId);
        body.append('guest_name', this.guestName);
        body.append('guest_email', this.guestEmail);
        if (this.creditOn) body.append('credit_name', this.creditName);
        if (this.notifyOn) body.append('notify_submitter', '1');
        body.append('website', this.website);

        try {
            const res = await fetch(this.config.storeUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf },
                body,
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                this.done = data.message;
                if (this.mode === 'field') this.config.fields[this.field.name].pending++;
            } else if (res.status === 422) {
                this.errors = Object.fromEntries(Object.entries(data.errors ?? {}).map(([k, v]) => [k, v[0]]));
            } else if (res.status === 429) {
                this.errors = { form: 'تعداد پیشنهادهای ارسالی از این نشانی زیاد است. لطفاً کمی بعد دوباره تلاش کنید.' };
            } else {
                this.errors = { form: 'ارسال با خطا روبه‌رو شد. لطفاً دوباره تلاش کنید یا به info@fahares.net بنویسید.' };
            }
        } catch (e) {
            this.errors = { form: 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.' };
        }
        this.submitting = false;
    },
});
