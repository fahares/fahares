// The suggestion modal shared by record pages. Per-field buttons dispatch
// `suggest-field` with {field}; the general button dispatches it with {general: true} (and an optional starting {value}).
// Only signed-in users suggest (backlog item 76): a guest gets a sign-in dialog instead, and returns to the same
// page with `?suggest=<key>`, which reopens the same form. What was typed is kept in sessionStorage meanwhile.
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
    // The name shown beside the correction once approved: on by default, the submitter may untick it
    creditOn: true,
    creditName: '',
    // A person connected with the copy (relation person_role)
    roleKey: '',
    roleName: '',
    // The copy moved or was renumbered (relation relocate)
    newShelfmark: '',
    // Research notes
    noteType: '',
    note: {},
    noteFiles: [],
    rightsOk: false,
    submitting: false,
    errors: {},
    done: null,
    // The form being filled, as a key for the return address and the saved draft: field.<name>, general[.<relation>] or note
    formKey: null,
    // Sign-in dialog for guests
    gate: false,
    gateErrors: {},
    gateBusy: false,

    init() {
        const params = new URLSearchParams(window.location.search);
        const key = params.get('suggest');
        if (!key) return;
        // Back from signing in: drop the parameter and open the form the guest asked for
        params.delete('suggest');
        const query = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
        if (!this.config.signedIn) return;
        const detail = this.detailFor(key);
        if (!detail) return;
        // Bring the field itself into view too, for when the form closes
        if (detail.field) document.querySelector(`[data-suggest-field="${CSS.escape(detail.field)}"]`)?.scrollIntoView({ block: 'center' });
        this.$nextTick(() => this.start(detail));
    },

    keyFor(detail) {
        if (detail.mode) return 'mode';
        if (detail.note) return 'note';
        if (detail.general) return detail.relation ? 'general.' + detail.relation : 'general';
        return 'field.' + detail.field;
    },

    detailFor(key) {
        const [kind, ...rest] = key.split('.');
        const name = rest.join('.');
        if (kind === 'mode') return { mode: true };
        if (kind === 'note') return Object.keys(this.config.noteTypes).length ? { note: true } : null;
        if (kind === 'general') return name ? { general: true, relation: name } : { general: true };
        return kind === 'field' && this.config.fields[name] ? { field: name } : null;
    },

    // Where to come back after signing in: this page, with the form to reopen
    get intended() {
        const params = new URLSearchParams(window.location.search);
        params.set('suggest', this.formKey ?? 'mode');
        return window.location.pathname + '?' + params.toString();
    },

    authUrl(base) {
        return base + '?intended=' + encodeURIComponent(this.intended);
    },

    draftKey() {
        return 'suggest-draft:' + window.location.pathname + ':' + this.formKey;
    },

    saveDraft() {
        if (!this.formKey || this.formKey === 'mode') return;
        const draft = {
            value: this.value, rationale: this.rationale, personId: this.personId, relationKey: this.relationKey,
            roleKey: this.roleKey, roleName: this.roleName, newShelfmark: this.newShelfmark,
            noteType: this.noteType, note: this.note, creditOn: this.creditOn, creditName: this.creditName,
        };
        try { sessionStorage.setItem(this.draftKey(), JSON.stringify(draft)); } catch (e) { /* storage blocked */ }
    },

    restoreDraft() {
        let draft = null;
        try { draft = JSON.parse(sessionStorage.getItem(this.draftKey()) ?? 'null'); } catch (e) { /* storage blocked */ }
        if (draft) Object.assign(this, draft);
    },

    clearDraft() {
        try { sessionStorage.removeItem(this.draftKey()); } catch (e) { /* storage blocked */ }
    },

    start(detail) {
        this.formKey = this.keyFor(detail);
        if (detail.mode) {
            // The «پیشنهاد اصلاح» button of a guest: suggestion mode starts once signed in
            if (this.config.signedIn) {
                this.$store.suggest.on = true;
            } else {
                this.gate = true;
            }
            return;
        }
        this.errors = {};
        this.done = null;
        this.personId = null;
        this.personResults = [];
        this.rationale = '';
        this.relationKey = '';
        this.targetQuery = '';
        this.targetResults = [];
        this.target = null;
        this.roleKey = '';
        this.roleName = '';
        this.newShelfmark = '';
        if (detail.note) {
            this.mode = 'note';
            this.field = BLANK_FIELD;
            this.noteType = Object.keys(this.config.noteTypes)[0] ?? '';
            this.note = {};
            this.noteFiles = [];
            this.rightsOk = false;
        } else if (detail.general) {
            this.mode = 'general';
            this.field = BLANK_FIELD;
            // e.g. «گزارش نشانی نادرست» starts the text with the reference it is about
            this.value = detail.value ?? '';
            // Opened for one relation type, e.g. from «اشخاص مرتبط با نسخه»
            if (detail.relation && this.config.relations.some((r) => r.key === detail.relation)) this.relationKey = detail.relation;
        } else {
            this.mode = 'field';
            this.field = { name: detail.field, ...this.config.fields[detail.field] };
            this.value = this.field.prefill;
        }
        this.toggleCredit();
        this.restoreDraft();
        if (!this.config.signedIn) {
            // Keeps e.g. the reference a «گزارش نشانی نادرست» starts with, for the form opened after signing in
            this.saveDraft();
            this.gateErrors = {};
            this.gate = true;
            return;
        }
        this.open = true;
    },

    toggleCredit() {
        // Start from the submitter's own name the first time the box is ticked
        if (this.creditOn && !this.creditName.trim()) this.creditName = this.config.creditName || this.config.userName || '';
    },

    close() {
        this.open = false;
        this.gate = false;
    },

    // «انصراف» drops what was typed; closing the window any other way keeps it for next time
    cancel() {
        this.clearDraft();
        this.close();
    },

    // The short sign-up form of the dialog; on success the page reloads at the intended address
    async register(form) {
        this.gateBusy = true;
        this.gateErrors = {};
        const body = new FormData(form);
        body.append('intended', this.intended);
        try {
            const res = await fetch(this.config.registerUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf },
                body,
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.redirect) {
                window.location.assign(data.redirect);
                return;
            }
            this.gateErrors = res.status === 422
                ? Object.fromEntries(Object.entries(data.errors ?? {}).map(([k, v]) => [k, v[0]]))
                : { form: res.status === 429 ? 'تلاش‌ها بیش از حد بود. کمی بعد دوباره تلاش کنید.' : 'ثبت‌نام انجام نشد. صفحه را تازه کنید و دوباره تلاش کنید.' };
        } catch (e) {
            this.gateErrors = { form: 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.' };
        }
        this.gateBusy = false;
    },

    get noteDef() {
        return this.config.noteTypes[this.noteType] ?? { fields: [], required: [], hint: '' };
    },

    // Shrinks large photos in the browser so each upload stays under the server's 2 MB limit
    async pickFiles(event) {
        const picked = [...event.target.files].slice(0, this.config.maxFiles);
        this.noteFiles = await Promise.all(picked.map((f) => this.shrink(f)));
    },

    async shrink(file, maxSide = 2400, quality = 0.85) {
        if (file.size <= 1.8 * 1024 * 1024) return file;
        const img = await new Promise((resolve, reject) => {
            const i = new Image();
            i.onload = () => resolve(i);
            i.onerror = reject;
            i.src = URL.createObjectURL(file);
        });
        const scale = Math.min(1, maxSide / Math.max(img.width, img.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        URL.revokeObjectURL(img.src);
        const out = new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
        return out.size > 1.9 * 1024 * 1024 && maxSide > 1200 ? this.shrink(file, maxSide - 600, 0.75) : out;
    },

    get title() {
        if (this.mode === 'note') return 'افزودن یادداشت یا منبع پژوهشی';
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
        if (kind === 'note') {
            body.append('field_name', this.noteType);
            for (const f of this.noteDef.fields) body.append(`note[${f}]`, this.note[f] ?? '');
            if (this.noteDef.files) this.noteFiles.forEach((file) => body.append('files[]', file));
            if (this.rightsOk) body.append('rights_confirmed', '1');
        }
        if (this.mode === 'field') body.append('field_name', this.field.name);
        if (kind === 'relation') {
            body.append('field_name', this.relationKey);
            body.append('target_id', this.target?.id ?? '');
            if (this.relation?.options) body.append('role', this.roleKey);
            if (this.relation?.apply === 'person_role') body.append('role_name', this.roleName);
            if (this.relation?.apply === 'relocate') body.append('shelfmark', this.newShelfmark);
        }
        body.append('suggested_value', this.value);
        body.append('rationale_citation', this.rationale);
        if (this.personId) body.append('person_id', this.personId);
        if (this.creditOn) body.append('credit_name', this.creditName);

        try {
            const res = await fetch(this.config.storeUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf },
                body,
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                this.done = data.message;
                this.clearDraft();
                if (this.mode === 'field') this.config.fields[this.field.name].pending++;
            } else if (res.status === 401) {
                // Signed out meanwhile: keep the text and offer to sign in again
                this.saveDraft();
                this.config.signedIn = false;
                this.open = false;
                this.gate = true;
            } else if (res.status === 419) {
                this.saveDraft();
                this.errors = { form: 'نشست شما منقضی شده است. صفحه را تازه کنید؛ متنی که نوشته‌اید نگه داشته شده است.' };
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
