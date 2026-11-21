@props(['name', 'setup', 'placeholder' => ''])

{{-- A record of the site picked by name, link or id through the suggestions' lookup; the form field holds its id.
     setup: ['url' => lookup url, 'type' => work|person|library|source, 'picked' => SuggestionRegistry::describe() of the held record or null] --}}
<div x-data="{
        id: @js($setup['picked']['id'] ?? ''), label: @js($setup['picked']['label'] ?? ''), sub: @js($setup['picked']['sub'] ?? ''), link: @js($setup['picked']['url'] ?? ''),
        q: '', results: [], busy: false,
        async search() {
            if (this.q.trim().length < 1) { this.results = []; return; }
            this.busy = true;
            try {
                const r = await fetch(@js($setup['url']) + '?type=' + @js($setup['type']) + '&q=' + encodeURIComponent(this.q.trim()), { headers: { Accept: 'application/json' } });
                this.results = r.ok ? await r.json() : [];
            } catch (e) { this.results = []; }
            this.busy = false;
        },
        pick(r) { this.id = r.id; this.label = r.label; this.sub = r.sub || ''; this.link = r.url || ''; this.q = ''; this.results = []; },
        clear() { this.id = ''; this.label = ''; this.sub = ''; this.link = ''; },
     }" class="space-y-1" data-record-picker="{{ $name }}">
    <input type="hidden" name="{{ $name }}" :value="id" value="{{ $setup['picked']['id'] ?? '' }}">
    <div x-show="id" x-cloak class="flex items-baseline gap-2 px-3 py-2 border border-line bg-paper">
        <span class="flex-1 min-w-0">
            <a :href="link || null" target="_blank" class="font-bold text-ink hover:text-cinnabar" x-text="label">{{ $setup['picked']['label'] ?? '' }}</a>
            <span class="text-xs text-muted" x-show="sub" x-text="' · ' + sub"></span>
            <span class="text-[11px] text-muted" dir="ltr" x-text="'#' + id"></span>
        </span>
        <button type="button" @click="clear()" class="text-xs font-bold text-cinnabar hover:underline cursor-pointer">تغییر</button>
    </div>
    <div x-show="!id" class="relative">
        <input type="text" x-model="q" @input.debounce.300ms="search()" @keydown.enter.prevent="search()" placeholder="{{ $placeholder }}" autocomplete="off" class="admin-field w-full">
        <ul x-show="results.length" x-cloak class="absolute z-10 inset-x-0 mt-1 max-h-64 overflow-auto border border-line-strong bg-surface shadow-sm divide-y divide-line">
            <template x-for="r in results" :key="r.id">
                <li>
                    <button type="button" @click="pick(r)" class="block w-full text-start px-3 py-2 hover:bg-paper cursor-pointer">
                        <span class="font-bold text-ink" x-text="r.label"></span>
                        <span class="block text-xs text-muted" x-text="r.sub"></span>
                    </button>
                </li>
            </template>
        </ul>
        <p x-show="q.trim().length > 1 && !busy && !results.length" x-cloak class="mt-1 text-xs text-muted">چیزی پیدا نشد؛ نشانی صفحه یا شناسۀ رکورد را هم می‌توان نوشت.</p>
    </div>
</div>
