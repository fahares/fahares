// The reviewer's "convert to another suggestion" form: field and note-type choices follow the target record type
export default (setup) => ({
    ...setup,

    get options() {
        const type = this.types[this.targetType];
        if (!type) return [];
        return this.kind === 'field' ? type.fields : this.kind === 'note' ? type.notes : [];
    },

    get note() {
        if (this.kind !== 'note') return null;
        return this.types[this.targetType]?.notes.find((n) => n.key === this.fieldName) ?? null;
    },
});
