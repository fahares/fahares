// The reviewer's "convert to another suggestion" form: field, note-type and relation choices follow the target record type
export default (setup) => ({
    ...setup,
    targetResults: [],
    target: null,
    targetTimer: null,

    get options() {
        const type = this.types[this.targetType];
        if (!type) return [];
        return this.kind === 'field' ? type.fields : this.kind === 'note' ? type.notes : this.kind === 'relation' ? type.relations : [];
    },

    get note() {
        if (this.kind !== 'note') return null;
        return this.types[this.targetType]?.notes.find((n) => n.key === this.fieldName) ?? null;
    },

    get relation() {
        if (this.kind !== 'relation') return null;
        return this.types[this.targetType]?.relations.find((r) => r.key === this.fieldName) ?? null;
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
});
