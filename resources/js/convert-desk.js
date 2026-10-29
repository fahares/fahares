// The reviewer's "convert to another suggestion" form: kind, field, note-type and relation choices follow the target
// record, all from SuggestionRegistry; a named record brings its own address fields from the server (backlog item 68)
export default (setup) => ({
    ...setup,
    targetResults: [],
    target: null,
    targetTimer: null,
    record: null,
    recordFields: null,
    fieldsTimer: null,
    personResults: [],
    personTimer: null,

    init() {
        if (this.otherRecord && this.targetRef) this.loadFields();
    },

    get fields() {
        return this.recordFields ?? this.types[this.targetType]?.fields ?? [];
    },

    get options() {
        if (this.kind === 'feedback') return Object.entries(this.feedbackCategories).map(([key, label]) => ({ key, label }));
        const type = this.types[this.targetType];
        if (!type) return [];
        return this.kind === 'field' ? this.fields : this.kind === 'note' ? type.notes : this.kind === 'relation' ? type.relations : [];
    },

    get field() {
        if (this.kind !== 'field') return null;
        return this.fields.find((f) => f.name === this.fieldName) ?? null;
    },

    get note() {
        if (this.kind !== 'note') return null;
        return this.types[this.targetType]?.notes.find((n) => n.key === this.fieldName) ?? null;
    },

    get relation() {
        if (this.kind !== 'relation') return null;
        return this.types[this.targetType]?.relations.find((r) => r.key === this.fieldName) ?? null;
    },

    get valueLabel() {
        if (this.kind === 'relation') return this.relation && this.relation.needs_text ? 'متن لازم این رابطه (نام دیگر، نام تازه، بخش‌های جداشدنی):' : 'توضیح (اختیاری):';
        if (this.kind === 'feedback') return 'متن بازخورد:';
        if (this.kind === 'general') return 'متن پیشنهاد:';
        return 'مقدار پیشنهادی (به شکل استاندارد):';
    },

    // The fields of the named record, its address in each source included; the type's fixed list until then
    loadFields() {
        clearTimeout(this.fieldsTimer);
        if (!this.otherRecord) {
            this.record = null;
            this.recordFields = null;
            return;
        }
        this.fieldsTimer = setTimeout(async () => {
            const ref = (this.targetRef || '').trim();
            if (!ref) {
                this.record = null;
                this.recordFields = null;
                return;
            }
            try {
                const res = await fetch(this.fieldsUrl + '?type=' + this.targetType + '&ref=' + encodeURIComponent(ref), { headers: { Accept: 'application/json' } });
                const data = res.ok ? await res.json() : null;
                this.record = data?.record ?? null;
                this.recordFields = data?.record ? data.fields : null;
            } catch (e) {
                this.record = null;
                this.recordFields = null;
            }
        }, 400);
    },

    // Same lookup as the public suggestion form: a pasted link or id, or a title/name search
    searchTargets() {
        this.target = null;
        clearTimeout(this.targetTimer);
        const q = this.relationTarget.trim();
        if (!q || !this.relation) {
            this.targetResults = [];
            return;
        }
        this.targetTimer = setTimeout(async () => {
            try {
                const res = await fetch(this.lookupUrl + '?type=' + this.relation.target + '&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                this.targetResults = res.ok ? await res.json() : [];
            } catch (e) {
                this.targetResults = [];
            }
        }, 300);
    },

    pickTarget(record) {
        this.target = record;
        this.relationTarget = String(record.id);
        this.targetResults = [];
    },

    // A scribe or an author: the name, and a person of fahares to link when one is picked
    searchPeople() {
        this.personId = '';
        clearTimeout(this.personTimer);
        const q = (this.value || '').trim();
        if (q.length < 2) {
            this.personResults = [];
            return;
        }
        this.personTimer = setTimeout(async () => {
            try {
                const res = await fetch(this.peopleUrl + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                this.personResults = res.ok ? await res.json() : [];
            } catch (e) {
                this.personResults = [];
            }
        }, 300);
    },

    pickPerson(person) {
        this.value = person.name;
        this.personId = String(person.id);
        this.personResults = [];
    },
});
