@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2b. From the place of copying to the present library --}}
<x-ui.section title="از محل کتابت تا کتابخانۀ امروز" id="movement">
    <x-ui.card>
        @if($mv['mapped'] < $min)
            @include('insights.thin', ['n' => $mv['mapped'], 'what' => 'نسخه با محل کتابت شناخته'])
        @else
        @include('insights.small', ['n' => $mv['mapped'], 'what' => 'نسخه با محل کتابت شناخته'])
        <div x-data="{
            {{-- A scope of one library or city has one city of today: where its copies came from is the question --}}
            dir: '{{ $scope->get('library') || $scope->get('city') ? 'to' : 'from' }}', sel: 0,
            lists: { from: @js($mv['origins']), to: @js($mv['destinations']) },
            get city() { return this.lists[this.dir][this.sel] },
            get max() { return Math.max(1, ...this.city.items.map(r => r.count)) },
            href(row) {
                const [from, to] = this.dir === 'from' ? [this.city.name, row.name] : [row.name, this.city.name];
                return `{{ $search('manuscripts') }}&amp;copy_city=${encodeURIComponent(from)}&amp;city=${encodeURIComponent(to)}`;
            },
            share(v) { return (Math.round(v * 1000 / Math.max(1, this.city.total)) / 10).toLocaleString('fa-IR') + '٪' },
        }">
        <p class="text-xs text-muted mb-3">نسخه‌هایی که در یک شهر کتابت شده‌اند امروز در کتابخانه‌های کدام شهرها هستند، یا نسخه‌های کتابخانه‌های یک شهر از کجا آمده‌اند.</p>
        <div class="flex flex-wrap items-center gap-1.5 text-xs mb-3">
            @foreach(['from' => 'از محل کتابت', 'to' => 'به شهر امروز'] as $key => $name)
                <button type="button" @click="dir = '{{ $key }}'; sel = 0" :aria-pressed="(dir === '{{ $key }}').toString()"
                        :class="dir === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                        class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-1.5 text-xs mb-4">
            <template x-for="(c, i) in lists[dir]" :key="dir + c.name">
                <button type="button" @click="sel = i" :aria-pressed="(sel === i).toString()"
                        :class="sel === i ? 'border-cinnabar text-cinnabar bg-paper' : 'border-line text-ink bg-surface hover:border-cinnabar'"
                        class="px-2 py-0.5 border rounded-sm transition cursor-pointer">
                    <span x-text="c.name"></span> <span class="text-muted tabular-nums" x-text="c.total.toLocaleString('fa-IR')"></span>
                </button>
            </template>
        </div>
        <div x-show="city" x-cloak>
            <p class="text-sm text-ink mb-2">
                <span x-text="dir === 'from' ? `${city.total.toLocaleString('fa-IR')} نسخۀ کتابت‌شده در ${city.name}، امروز در این شهرها:` : `${city.total.toLocaleString('fa-IR')} نسخۀ دارای محل کتابت در کتابخانه‌های ${city.name}، کتابت‌شده در این شهرها:`"></span>
            </p>
            <ul class="space-y-2 text-sm">
                <template x-for="row in city.items" :key="row.name">
                    <li>
                        <a :href="href(row)" class="group block">
                            <span class="flex items-baseline gap-2">
                                <span class="text-ink group-hover:text-cinnabar transition" x-text="row.name"></span>
                                <span x-show="row.name === city.name" class="text-[11px] text-gold" x-text="dir === 'from' ? '(در همان شهر مانده)' : '(کتابت‌شده در همین شهر)'"></span>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-xs text-muted tabular-nums" x-text="`${row.count.toLocaleString('fa-IR')} · ${share(row.count)}`"></span>
                            </span>
                            <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                <span class="block h-full rounded-full" :class="row.name === city.name ? 'bg-gold/80' : 'bg-cinnabar/70 group-hover:bg-cinnabar'" :style="`width: max(2px, ${row.count * 100 / max}%)`"></span>
                            </span>
                        </a>
                    </li>
                </template>
                <li x-show="city.other > 0" class="text-muted text-sm flex items-baseline gap-2">
                    <span x-text="`سایر (${city.other_kinds.toLocaleString('fa-IR')} شهر)`"></span>
                    <span class="leader" aria-hidden="true"></span>
                    <span class="text-xs tabular-nums" x-text="`${city.other.toLocaleString('fa-IR')} · ${share(city.other)}`"></span>
                </li>
            </ul>
        </div>
        <p class="mt-5 text-xs text-muted leading-relaxed">
            بر پایۀ {{ Fa::num($mv['mapped']) }} نسخه که محل کتابتشان به یکی از شهرهای نقشه رسید و کتابخانه‌شان شهر دارد.
            {{ $pct($mv['stayed_share']) }} آن‌ها امروز در همان شهری‌اند که کتابت شده‌اند.
            شهر امروز، شهر کتابخانۀ دارندۀ نسخه است.
        </p>
        @include('insights.explain', ['note' => $notes['movement'], 'tables' => $tables['movement']])
        </div>
        @endif
    </x-ui.card>
</x-ui.section>
