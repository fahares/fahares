@props(['name', 'label', 'items', 'selected' => null, 'all' => 'همه', 'placeholder' => 'جستجو…', 'unit' => null])

{{-- A filter of the search page as a dropdown with its own search box, for long lists (subjects, libraries):
     items are [id, name, count, hint]; count may be null when there is nothing to count (backlog item 83, phase 5) --}}
@once
<script>
function filterCombobox(config) {
    return {
        open: false,
        search: '',
        selectedId: config.selectedId ?? '',
        highlighted: -1,
        items: config.items || [],
        get selectedItem() { return this.items.find(i => String(i.id) === String(this.selectedId)) || null; },
        get filtered() {
            const q = this.normalize(this.search);
            return q ? this.items.filter(i => this.normalize(i.name + ' ' + (i.hint || '')).includes(q)) : this.items;
        },
        normalize(t) {
            return (t || '').toString().toLowerCase().replace(/[ً-ٰٟ]/g, '').replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
                .replace(/[آأإ]/g, 'ا').replace(/[‌\s]+/g, ' ').trim();
        },
        fa(n) { return Number(n).toLocaleString('fa-IR'); },
        show() {
            this.open = true; this.search = '';
            this.highlighted = this.items.findIndex(i => String(i.id) === String(this.selectedId));
            this.$nextTick(() => { this.$refs.input.focus(); this.scroll(); });
        },
        move(step) {
            if (!this.open) return this.show();
            this.highlighted = Math.max(-1, Math.min(this.filtered.length - 1, this.highlighted + step));
            this.scroll();
        },
        enter() {
            if (!this.open) return this.show();
            const item = this.filtered[this.highlighted] ?? (this.search.trim() ? this.filtered[0] : null);
            this.pick(item ? item.id : '');
        },
        scroll() { this.$nextTick(() => this.$refs.list?.querySelector('[data-hl="true"]')?.scrollIntoView({ block: 'nearest' })); },
        pick(id) { this.selectedId = id === '' ? '' : String(id); this.open = false; this.search = ''; this.$nextTick(() => this.$refs.trigger.focus()); },
    };
}
</script>
@endonce
<div x-data="filterCombobox({ selectedId: @js((string) ($selected ?? '')), items: @js($items) })" class="relative space-y-2" @click.outside="open = false" @keydown.escape="open = false">
    <div class="flex items-center justify-between">
        <span class="block text-xs font-bold text-ink/80">{{ $label }}</span>
        <span class="text-[10px] text-muted">{{ \App\Support\Fa::num(count($items)) }}{{ $unit ? ' ' . $unit : '' }}</span>
    </div>
    <input type="hidden" name="{{ $name }}" :value="selectedId">
    <button type="button" x-ref="trigger" @click="open ? open = false : show()" @keydown.arrow-down.prevent="show()"
            aria-haspopup="listbox" :aria-expanded="open"
            class="w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink flex items-center justify-between gap-2 hover:border-gold focus:outline-none focus:ring-1 focus:ring-cinnabar transition text-right">
        <span class="truncate" x-text="selectedItem ? selectedItem.name + (selectedItem.count != null ? ' (' + fa(selectedItem.count) + ')' : '') : @js($all)">{{ $all }}</span>
        <span class="flex items-center gap-1 shrink-0 text-muted">
            <span x-show="selectedId" x-cloak @click.stop="pick('')" class="hover:text-cinnabar px-0.5" title="پاک کردن">✕</span>
            <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </span>
    </button>
    <div x-show="open" x-cloak class="absolute z-30 mt-1 w-full bg-surface border border-line rounded-sm shadow-2xl p-2 space-y-2">
        <input x-ref="input" type="text" x-model="search" @input="highlighted = filtered.length ? 0 : -1; scroll()"
               @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="enter()"
               placeholder="{{ $placeholder }}" class="w-full text-xs p-2 rounded-sm border border-line-strong bg-paper text-ink focus:outline-none focus:border-gold">
        <div x-ref="list" role="listbox" class="max-h-60 overflow-y-auto text-xs space-y-0.5">
            <button type="button" tabindex="-1" x-show="!search.trim()" @click="pick('')"
                    class="w-full text-right px-2.5 py-1.5 rounded-sm" :class="!selectedId ? 'bg-cinnabar/15 text-cinnabar font-bold' : 'hover:bg-paper'">{{ $all }}</button>
            <template x-for="(item, i) in filtered" :key="item.id">
                <button type="button" tabindex="-1" role="option" :data-hl="highlighted === i" :aria-selected="String(item.id) === String(selectedId)"
                        @click="pick(item.id)" @mouseenter="highlighted = i"
                        class="w-full text-right px-2.5 py-1.5 rounded-sm flex items-center justify-between gap-2"
                        :class="String(item.id) === String(selectedId) ? 'bg-cinnabar/15 text-cinnabar font-bold' : (highlighted === i ? 'bg-paper text-ink' : '')">
                    <span class="min-w-0 truncate"><span x-text="item.name"></span><span x-show="item.hint" class="text-[10px] text-muted font-normal" x-text="' · ' + item.hint"></span></span>
                    <span class="text-[10px] text-muted tabular-nums shrink-0" x-show="item.count != null" x-text="fa(item.count)"></span>
                </button>
            </template>
            <p x-show="!filtered.length" class="text-center py-3 text-muted">موردی یافت نشد</p>
        </div>
    </div>
</div>
