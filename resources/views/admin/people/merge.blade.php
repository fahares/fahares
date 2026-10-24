@extends('layouts.admin')

@section('title', 'ادغام اشخاص')
@section('page_title', 'ادغام اشخاص تکراری')
@section('breadcrumb')
    <a href="{{ route('admin.people.index') }}" class="hover:text-cinnabar">اشخاص</a>
    <span>/</span>
    <span>ادغام</span>
@endsection

@section('content')
@php
    // The two sides of the merge: the duplicate that goes, the record that stays
    $sides = [
        'source' => ['step' => '۱', 'title' => 'شخص تکراری', 'hint' => 'حذف می‌شود و آثار و نسخه‌هایش به شخص اصلی می‌رسند', 'tone' => '!border-cinnabar/50'],
        'target' => ['step' => '۲', 'title' => 'شخص اصلی', 'hint' => 'می‌ماند و آثار و نسخه‌های شخص تکراری را می‌گیرد', 'tone' => '!border-emerald-600/50'],
    ];
@endphp
<div x-data="personMerger({
        source: {{ Js::from($preloadedSource) }},
        target: {{ Js::from($preloadedTarget) }},
        searchUrl: @js(route('admin.people.search'))
    })"
    class="max-w-5xl space-y-5 text-[13px]">

    <x-admin.notice>
        دو شخص را با جستجوی نام یا شناسه برگزینید. همه‌ی آثار، نسخه‌ها و نقش‌های <b>شخص تکراری</b> به <b>شخص اصلی</b> منتقل و شمارها دوباره حساب می‌شوند.
        نام‌های شخص تکراری به نام‌های دیگر شخص اصلی افزوده می‌شود و نشانی‌اش به صفحه‌ی شخص اصلی می‌رود.
        ادغام در لاگ ممیزی ثبت می‌شود و از همان‌جا بازگرداندنی است.
    </x-admin.notice>

    <form action="{{ route('admin.people.merge') }}" method="POST" class="space-y-5">
        @csrf
        <input type="hidden" name="source_id" :value="sides.source.person ? sides.source.person.id : ''" required>
        <input type="hidden" name="target_id" :value="sides.target.person ? sides.target.person.id : ''" required>
        @if($suggestionId ?? null)
            <input type="hidden" name="suggestion_id" value="{{ $suggestionId }}">
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
            @foreach($sides as $key => $side)
                <x-ui.card class="space-y-3 transition" ::class="sides.{{ $key }}.person ? '{{ $side['tone'] }}' : 'border-dashed'">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 class="rubric text-base">{{ $side['step'] }}. {{ $side['title'] }}</h2>
                        <template x-if="sides.{{ $key }}.person">
                            <x-ui.btn size="sm" variant="ghost" @click="clear('{{ $key }}')">تغییر</x-ui.btn>
                        </template>
                    </div>
                    <p class="text-xs text-muted">{{ $side['hint'] }}</p>

                    {{-- Search, until a person is chosen --}}
                    <div x-show="!sides.{{ $key }}.person" class="relative" @click.outside="sides.{{ $key }}.open = false">
                        <label for="merge-{{ $key }}" class="sr-only">جستجوی {{ $side['title'] }}</label>
                        <input id="merge-{{ $key }}" type="search" autocomplete="off"
                               x-model="sides.{{ $key }}.query"
                               @input.debounce.250ms="search('{{ $key }}')"
                               @focus="sides.{{ $key }}.open = sides.{{ $key }}.results.length > 0"
                               placeholder="نام، شهرت، کنیه یا شناسه"
                               class="admin-field w-full">
                        <span x-show="sides.{{ $key }}.loading" x-cloak class="absolute left-3 top-2 text-xs text-muted">در جستجو…</span>

                        <ul x-show="sides.{{ $key }}.open" x-cloak
                            class="absolute z-30 inset-x-0 mt-1 max-h-72 overflow-y-auto bg-surface border border-line-strong rounded-sm shadow-lg divide-y divide-line">
                            <template x-for="p in sides.{{ $key }}.results" :key="p.id">
                                <li>
                                    <button type="button" @click="pick('{{ $key }}', p)" class="w-full px-3 py-2 text-right hover:bg-paper flex items-baseline justify-between gap-3 cursor-pointer">
                                        <span class="min-w-0">
                                            <span class="block font-bold text-ink" x-text="p.name"></span>
                                            <span class="block text-xs text-muted" x-text="'#' + digits(p.id) + (life(p) ? ' · ' + life(p) : '')"></span>
                                        </span>
                                        <span class="shrink-0 text-xs text-muted" x-text="fa(p.works_count) + ' اثر، ' + fa(p.manuscripts_count) + ' نسخه'"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                        <p x-show="sides.{{ $key }}.query.trim() && !sides.{{ $key }}.loading && sides.{{ $key }}.searched && !sides.{{ $key }}.results.length" x-cloak class="mt-1 text-xs text-muted">شخصی پیدا نشد.</p>
                    </div>

                    {{-- The chosen person --}}
                    <template x-if="sides.{{ $key }}.person">
                        <div class="space-y-2">
                            <div>
                                <a :href="sides.{{ $key }}.person.url" target="_blank" class="font-naskh text-lg font-bold text-ink hover:text-cinnabar" x-text="sides.{{ $key }}.person.name"></a>
                                <span class="text-xs text-muted tabular-nums" x-text="'#' + digits(sides.{{ $key }}.person.id)"></span>
                            </div>
                            <ul class="space-y-1">
                                <li class="flex items-baseline gap-2"><span>آثار</span><span class="leader"></span><b class="tabular-nums" x-text="fa(sides.{{ $key }}.person.works_count)"></b></li>
                                <li class="flex items-baseline gap-2"><span>نسخه‌های کتابت</span><span class="leader"></span><b class="tabular-nums" x-text="fa(sides.{{ $key }}.person.manuscripts_count)"></b></li>
                            </ul>
                            <div class="flex flex-wrap items-center gap-1.5 text-xs text-muted">
                                <span x-show="life(sides.{{ $key }}.person)" x-text="life(sides.{{ $key }}.person)"></span>
                                <template x-if="sides.{{ $key }}.person.is_author"><span class="inline-flex px-2 rounded-full border border-line bg-surface text-ink">مؤلف</span></template>
                                <template x-if="sides.{{ $key }}.person.is_scribe"><span class="inline-flex px-2 rounded-full border border-line bg-surface text-ink">کاتب</span></template>
                            </div>
                        </div>
                    </template>
                </x-ui.card>
            @endforeach
        </div>

        <div class="flex justify-center">
            <x-ui.btn @click="swap()" ::disabled="!sides.source.person && !sides.target.person" class="disabled:opacity-40 disabled:cursor-not-allowed" title="جای شخص تکراری و شخص اصلی را عوض کن">
                جابه‌جایی دو طرف ⇄
            </x-ui.btn>
        </div>

        <x-admin.notice tone="error" x-show="same" x-cloak>شخص تکراری و شخص اصلی یکی‌اند. دو رکورد جدا برگزینید.</x-admin.notice>

        {{-- What the merge will do --}}
        <x-ui.card framed x-show="ready" x-cloak class="space-y-3">
            <h2 class="rubric text-base">پیامد ادغام</h2>
            <ul class="space-y-1.5">
                <li class="flex items-baseline gap-2"><span>آثاری که منتقل می‌شوند</span><span class="leader"></span><b class="tabular-nums" x-text="fa(sides.source.person?.works_count)"></b></li>
                <li class="flex items-baseline gap-2"><span>نسخه‌هایی که منتقل می‌شوند</span><span class="leader"></span><b class="tabular-nums" x-text="fa(sides.source.person?.manuscripts_count)"></b></li>
                <li class="flex items-baseline gap-2"><span>آثار شخص اصلی پس از ادغام</span><span class="leader"></span><b class="tabular-nums text-cinnabar" x-text="fa(Number(sides.target.person?.works_count) + Number(sides.source.person?.works_count))"></b></li>
                <li class="flex items-baseline gap-2"><span>نسخه‌های شخص اصلی پس از ادغام</span><span class="leader"></span><b class="tabular-nums text-cinnabar" x-text="fa(Number(sides.target.person?.manuscripts_count) + Number(sides.source.person?.manuscripts_count))"></b></li>
            </ul>
            <p class="text-xs text-muted leading-6">
                رکورد «<b class="text-ink" x-text="sides.source.person?.name"></b>» حذف و در «<b class="text-ink" x-text="sides.target.person?.name"></b>» ادغام می‌شود.
            </p>
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-line">
            <x-ui.btn :href="route('admin.people.index')" variant="ghost">انصراف</x-ui.btn>
            <button type="submit" :disabled="!ready"
                    @click="if (!confirm('«' + sides.source.person.name + '» در «' + sides.target.person.name + '» ادغام شود؟\nآثار و نسخه‌های شخص تکراری منتقل و رکوردش حذف می‌شود.')) $event.preventDefault()"
                    class="w-full sm:w-auto px-6 py-2.5 rounded-sm bg-cinnabar text-on-cinnabar font-bold transition cursor-pointer hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed">
                <span x-text="ready ? 'ادغام «' + sides.source.person.name + '» در «' + sides.target.person.name + '»' : 'هر دو شخص را برگزینید'">هر دو شخص را برگزینید</span>
            </button>
        </div>
    </form>
</div>

<script>
function personMerger(config) {
    const side = (person) => ({ person: person || null, query: '', results: [], loading: false, open: false, searched: false });

    return {
        sides: { source: side(config.source), target: side(config.target) },

        get same() {
            return this.sides.source.person && this.sides.target.person && this.sides.source.person.id === this.sides.target.person.id;
        },
        get ready() {
            return this.sides.source.person && this.sides.target.person && ! this.same;
        },

        // Persian digits with the Persian thousands separator
        fa(n) {
            return Number(n || 0).toLocaleString('fa-IR');
        },
        // Persian digits without grouping, for ids and years
        digits(n) {
            return String(n ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
        },
        life(p) {
            if (p.death_year_hijri) return 'درگذشت ' + this.digits(p.death_year_hijri) + ' ق';
            if (p.century_hijri) return 'قرن ' + this.digits(p.century_hijri);
            return '';
        },

        async search(key) {
            const s = this.sides[key];
            const q = s.query.trim();
            if (! q) {
                s.results = [];
                s.open = false;
                s.searched = false;
                return;
            }
            s.loading = true;
            try {
                const res = await fetch(`${config.searchUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                s.results = await res.json();
                s.open = s.results.length > 0;
            } catch (e) {
                console.error(e);
            } finally {
                s.loading = false;
                s.searched = true;
            }
        },
        pick(key, person) {
            this.sides[key] = { ...side(person) };
        },
        clear(key) {
            this.sides[key] = side(null);
        },
        swap() {
            [this.sides.source, this.sides.target] = [this.sides.target, this.sides.source];
        },
    };
}
</script>
@endsection
