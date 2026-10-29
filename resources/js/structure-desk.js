// The admin's direct structural tools (backlog item 45): the tool's choices, and the other record found by the lookup
export default (setup) => ({
    ...setup,
    targetResults: [],
    target: null,
    targetTimer: null,

    get relation() {
        return this.relations.find((r) => r.key === this.relationKey) ?? null;
    },

    searchTargets() {
        this.target = null;
        clearTimeout(this.targetTimer);
        const q = this.targetQuery.trim();
        if (!q || !this.relation?.target) {
            this.targetResults = [];
            return;
        }
        this.targetTimer = setTimeout(async () => {
            try {
                const res = await fetch(this.lookupUrl + '?type=' + this.relation.target + '&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                this.targetResults = (res.ok ? await res.json() : []).filter((r) => !(this.relation.target === this.type && r.id === this.id));
            } catch (e) {
                this.targetResults = [];
            }
        }, 300);
    },

    pickTarget(record) {
        this.target = record;
        this.targetQuery = String(record.id);
        this.targetResults = [];
    },
});
