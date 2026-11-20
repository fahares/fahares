@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 1. Timeline of copying --}}
<x-ui.section title="خط زمانی کتابت" id="timeline">
    <x-ui.card>
        @if($t['shown'] < $min)
            @include('insights.thin', ['n' => $t['shown'], 'what' => 'نسخۀ تاریخ‌دار'])
        @else
        @include('insights.small', ['n' => $t['shown'], 'what' => 'نسخۀ تاریخ‌دار'])
        <div x-data="{ sel: {{ $peak['century'] }}, cs: @js(collect($t['centuries'])->keyBy('century')),
            get decades() { return this.cs[this.sel].decades.map((n, i) => n + this.cs[this.sel].decades_doubtful[i]) },
            {{-- Who ruled the century's busiest city of copying (Insights::dynasties): its runs, with the gaps between them --}}
            rule: @js((object) $data['dynasties']['centuries']),
            get band() {
                const rule = this.rule[this.sel], start = (this.sel - 1) * 100 + 1, parts = [];
                if (! rule) { return parts }
                let at = start;
                for (const [from, to, name] of rule.runs) {
                    if (from > at) { parts.push({ years: from - at, name: null }) }
                    parts.push({ years: to - from + 1, name, from, to });
                    at = to + 1;
                }
                if (at <= start + 99) { parts.push({ years: start + 100 - at, name: null }) }
                return parts;
            } }">
        <p class="text-xs text-muted mb-4">شمار نسخه‌های کتابت‌شده در هر سدۀ هجری. نسخه‌ای که تنها سده یا بخشی از سده دارد («قرن ۱۳»، «اواخر قرن ۱۲») لایۀ خود را دارد و اگر بازه‌اش دو سده را بگیرد، میان آن دو بخش شده است. روی هر سده بزنید تا نسخه‌های آن در جست‌وجو باز شود.</p>

        @include('insights.century-bars', ['rows' => $t['centuries'], 'unit' => 'نسخه', 'label' => 'شمار نسخه‌ها در هر سده',
            'href' => fn ($c) => $search('manuscripts', ['century' => $c])])

        {{-- Decades of one century --}}
        <div class="mt-6 pt-4 border-t border-line">
            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <span class="text-muted me-1">دهه‌های سدۀ</span>
                @foreach($t['centuries'] as $row)
                    <button type="button" @click="sel = {{ $row['century'] }}"
                            :class="sel === {{ $row['century'] }} ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                            :aria-pressed="(sel === {{ $row['century'] }}).toString()"
                            class="min-w-8 px-2 py-0.5 rounded-full border tabular-nums transition cursor-pointer">{{ Fa::digits($row['century']) }}</button>
                @endforeach
            </div>
            <div x-cloak class="mt-4">
                <div class="flex items-end gap-1 h-28 border-b border-line">
                    <template x-for="(n, i) in decades" :key="i">
                        <div class="flex-1 h-full flex flex-col justify-end items-center gap-0.5 min-w-0"
                             :data-tip="`${((sel - 1) * 100 + i * 10 + 1).toLocaleString('fa-IR', {useGrouping: false})} تا ${((sel - 1) * 100 + i * 10 + 10).toLocaleString('fa-IR', {useGrouping: false})}: ${n.toLocaleString('fa-IR')} نسخه` + (cs[sel].decades_doubtful[i] ? `، ${cs[sel].decades_doubtful[i].toLocaleString('fa-IR')} مردد` : '')">
                            <span class="text-[10px] text-muted tabular-nums" x-text="n.toLocaleString('fa-IR')"></span>
                            <span class="w-full max-w-10 flex flex-col-reverse rounded-t-[4px] overflow-hidden"
                                  :style="`height: max(${n ? 2 : 0}px, ${n * 100 / Math.max(1, ...decades)}%)`">
                                <span class="block w-full shrink-0 bg-cinnabar/75" :style="`height: ${cs[sel].decades[i] * 100 / Math.max(1, n)}%`"></span>
                                <span class="block w-full shrink-0 bg-cinnabar/30" :style="`height: ${cs[sel].decades_doubtful[i] * 100 / Math.max(1, n)}%`"></span>
                            </span>
                        </div>
                    </template>
                </div>
                <div class="flex gap-1 mt-1">
                    <template x-for="i in 10" :key="i">
                        <span class="flex-1 text-center text-[10px] text-muted tabular-nums" x-text="((sel - 1) * 100 + (i - 1) * 10 + 1).toLocaleString('fa-IR', {useGrouping: false})"></span>
                    </template>
                </div>
                <div x-show="band.length" class="mt-2" data-insights-band>
                    <div class="flex gap-px h-6 text-[10px] leading-6 text-ink" role="list" aria-label="حکومت‌ها در این سده">
                        <template x-for="(part, i) in band" :key="sel + '-' + i">
                            <span role="listitem" class="min-w-0 truncate text-center px-1" :style="`flex: ${part.years} 1 0`"
                                  :class="part.name ? (i % 2 ? 'bg-gold/35' : 'bg-gold/55') : 'bg-line/60'"
                                  :data-tip="part.name ? `${part.name}: ${part.from.toLocaleString('fa-IR', {useGrouping: false})} تا ${part.to.toLocaleString('fa-IR', {useGrouping: false})}` : 'حکومتی شناخته نیست'"
                                  x-text="part.name || ''"></span>
                        </template>
                    </div>
                    <p class="mt-1 text-[11px] text-muted">
                        حکومت بر <span class="text-ink" x-text="rule[sel]?.city"></span>، شهری که بیشترین نسخۀ سال‌دار این سده در آن کتابت شده است
                        (<span x-text="rule[sel]?.count.toLocaleString('fa-IR')"></span> نسخه). مرز حکومت‌ها تقریبی و به دقت دهه است.
                    </p>
                </div>
                <p class="mt-2 text-[11px] text-muted">تنها نسخه‌های سال‌دار، چون نسخه‌ای که تنها سده‌اش معلوم است، دهه ندارد.</p>
                <p class="mt-3 text-xs">
                    <a :href="`{{ $search('manuscripts') }}&century=${sel}`" class="text-cinnabar font-semibold hover:underline">
                        دیدن نسخه‌های سال‌دار سدۀ <span x-text="sel.toLocaleString('fa-IR')"></span> در جست‌وجو ←
                    </a>
                </p>
            </div>
        </div>

        <p class="mt-5 text-xs text-muted leading-relaxed">
            بر پایۀ {{ Fa::num($t['shown']) }} نسخه، یعنی {{ $pct($t['coverage']) }} نسخه‌ها، که تاریخ کتابتشان ثبت شده است:
            {{ Fa::num($t['exact']) }} با سال معین و {{ Fa::num($t['range']) }} تنها با سده یا بخشی از آن.
            @include('insights.doubtful-note', ['layers' => $t, 'unit' => 'نسخه', 'what' => 'تاریخ کتابتشان'])
            @if($t['outside'] > 0){{ Fa::num($t['outside']) }} نسخۀ بیرون از سده‌های {{ Fa::digits(config('insights.centuries')[0]) }} تا {{ Fa::digits(config('insights.centuries')[1]) }} در نمودار نیامده است.@endif
            @if($t['open'] > 0){{ Fa::num($t['open']) }} نسخه با بازۀ باز («پیش از ۱۱۰۰»، «پس از ۱۲۰۰») جدا شمرده شده و در نمودار نیامده است.@endif
        </p>
        @include('insights.explain', ['note' => $notes['timeline'], 'tables' => $tables['timeline']])
        </div>
        @endif
    </x-ui.card>
</x-ui.section>
