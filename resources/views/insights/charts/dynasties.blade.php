@php
    use App\Support\Fa;
@endphp
{{-- 1a. The copies made under each dynasty (backlog item 154, step 14.265) --}}
@php $dy = $data['dynasties']; @endphp
<x-ui.section title="نسخه‌ها در روزگار هر سلسله" id="dynasties">
    <x-ui.card>
        @if($dy['ruled'] < $min)
            @include('insights.thin', ['n' => $dy['ruled'], 'what' => 'نسخه با سال و محل کتابت'])
        @else
            @include('insights.small', ['n' => $dy['ruled'], 'what' => 'نسخه با سال و محل کتابت'])
            @php $maxDynasty = max([1, ...array_column($dy['items'], 'count')]); @endphp
            <p class="text-xs text-muted mb-4">
                هر نسخه به حکومتی داده شده که شهر کتابتش در سال کتابت در قلمرو آن بوده است. کنار هر سلسله، سال نخستین و واپسین نسخه و شهرهایی که بیشترین نسخه را داده‌اند آمده است.
            </p>
            {{-- The dynasties as rows by region across the years (resources/js/insights-dynasties.js, step 14.278) --}}
            @php
                $counts = $dy['counts'];
                $top = array_column($dy['items'], 'cities', 'name');
                $rowsData = [
                    'regions' => \App\Support\Dynasties::regions(),
                    'dynasties' => array_map(fn ($d) => ['n' => $d['name'], 'r' => $d['region'], 'f' => $d['from'], 't' => $d['to'], 'c' => $counts[$d['name']] ?? 0,
                        'cities' => array_map(fn ($c) => [$c['name'], $c['count']], $top[$d['name']] ?? [])], \App\Support\Dynasties::all()),
                    'span' => [0, 1450],
                    // The years most of the copies are from
                    'window' => [700, 1350],
                ];
            @endphp
            <div x-data="insightsDynasties(@js($rowsData))" class="mb-6" data-insights-dynasty-rows>
                <div x-show="ready" x-cloak>
                    <div class="flex flex-wrap items-center gap-1.5 text-xs mb-2">
                        <span class="text-muted me-1">پنجرۀ سال‌ها:</span>
                        @foreach([[0, 1450, 'همه'], [1, 500, 'تا ۵۰۰'], [500, 900, '۵۰۰ تا ۹۰۰'], [900, 1350, '۹۰۰ تا ۱۳۵۰']] as [$from, $to, $name])
                            <button type="button" @click="show({{ $from }}, {{ $to }})" class="px-2.5 py-0.5 rounded-full border border-line bg-surface text-ink hover:border-cinnabar transition cursor-pointer">{{ $name }}</button>
                        @endforeach
                    </div>
                    <div x-ref="chart" class="w-full border border-line bg-paper" :style="`height: ${height}px`" role="img"
                         aria-label="سلسله‌ها به تفکیک منطقه در گذر سال‌های هجری؛ هر نوار یک سلسله است و پررنگی‌اش شمار نسخه‌های کتابت‌شده در قلمرو و روزگار آن. همین ارقام در فهرست و جدول زیر آمده است."></div>
                    <p class="mt-2 text-xs text-ink min-h-5">
                        <template x-if="selected">
                            <span>
                                <strong x-text="selected.n"></strong> <span class="text-muted" x-text="years(selected) + ' · ' + selected.r"></span>:
                                <span x-text="selected.c ? fa(selected.c) + ' نسخه در قلمرو و روزگار آن' : 'نسخه‌ای با سال و شهر در قلمرو آن نیست'"></span><span x-show="selected.cities.length" x-text="'؛ بیشتر در ' + selected.cities.map(([name, n]) => name + ' (' + fa(n) + ')').join('، ')"></span>.
                            </span>
                        </template>
                        <span x-show="! selected" class="text-muted text-[11px]">هر نوار یک سلسله است، از سال آغاز تا پایانش، در ردیف‌های منطقۀ خود؛ هر چه پررنگ‌تر، نسخۀ بیشتری در قلمرو و روزگار آن کتابت شده است. نوار پایین را بکشید تا سال‌ها جابه‌جا شود، و روی هر سلسله بزنید.</span>
                    </p>
                </div>
                <p x-show="failed" x-cloak class="text-xs text-muted">نمودار ردیفی سلسله‌ها بار نشد؛ فهرست زیر همان ارقام را دارد.</p>
            </div>
            <ul class="space-y-3 text-sm">
                @foreach($dy['items'] as $row)
                    <li>
                        <span class="flex flex-wrap items-baseline gap-x-2">
                            <span class="text-ink font-semibold">{{ $row['name'] }}</span>
                            <span class="text-[11px] text-muted tabular-nums">{{ $row['from'] === $row['to'] ? Fa::digits($row['from']) : Fa::digits($row['from']) . ' تا ' . Fa::digits($row['to']) }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums">{{ Fa::num($row['count']) }} · {{ $pct(round($row['count'] * 100 / max(1, $dy['ruled']), 1)) }}</span>
                        </span>
                        <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full bg-cinnabar/70 rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / $maxDynasty, 2) }}%)"></span>
                        </span>
                        <span class="mt-1 block text-xs text-muted">
                            @foreach($row['cities'] as $city)
                                <a href="{{ $search('manuscripts', ['copy_city' => $city['name'], 'year_from' => $row['from'], 'year_to' => $row['to']]) }}" class="hover:text-cinnabar">{{ $city['name'] }}</a>
                                <span class="tabular-nums">{{ Fa::num($city['count']) }}</span>{{ $loop->last ? '' : '،' }}
                            @endforeach
                            @if($row['city_kinds'] > count($row['cities'])) و {{ Fa::num($row['city_kinds'] - count($row['cities'])) }} شهر دیگر @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایۀ {{ Fa::num($dy['ruled']) }} نسخه، یعنی تنها {{ $pct($dy['coverage']) }} نسخه‌ها: نسخه‌هایی که هم سال کتابت معین دارند و هم شهر کتابتشان روی نقشه است.
                @if($dy['counted'] > $dy['ruled']){{ Fa::num($dy['counted'] - $dy['ruled']) }} نسخۀ دیگر با همین دو آگاهی در دهه‌ای کتابت شده‌اند که برای شهرشان حکومتی شناخته نیست.@endif
                @if($dy['kinds'] > count($dy['items'])){{ Fa::num(count($dy['items'])) }} سلسلۀ پرنسخه از {{ Fa::num($dy['kinds']) }} آمده است.@endif
                قلمرو هر حکومت از نقشه‌های تاریخی آمار دواوین است (مرزها: Cliopatria، بانک دادۀ Seshat)، به دقت دهه؛ مرز حکومت‌های پیشامدرن تقریبی است و چند سلسلۀ محلی هنوز مرز ندارند.
            </p>
            @include('insights.explain', ['note' => $notes['dynasties'], 'tables' => $tables['dynasties']])
        @endif
    </x-ui.card>
</x-ui.section>
