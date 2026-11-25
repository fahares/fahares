@php
    use App\Support\Fa;
@endphp
{{--
    1a. The copies made under each dynasty (backlog item 154; steps 14.265, 14.278, 14.281): the dynasties as rows by
    region across the years (resources/js/insights-dynasties.js), and under it the list in three readings: the copies
    of every ten years of the rule (the first one: a long rule has many copies for its length alone), the count, and
    the dynasty's share of the copies made anywhere in its years.
--}}
@php $dy = $data['dynasties']; @endphp
<x-ui.section title="نسخه‌ها در روزگار هر سلسله" id="dynasties">
    <x-ui.card>
        @if($dy['ruled'] < $min)
            @include('insights.thin', ['n' => $dy['ruled'], 'what' => 'نسخه با سال و محل کتابت'])
        @else
            @include('insights.small', ['n' => $dy['ruled'], 'what' => 'نسخه با سال و محل کتابت'])
            @php
                $dec = fn ($v) => Fa::digits(str_replace('.', '٫', (string) $v));
                $shown = (int) config('insights.dynasties_shown');
                // A density or a share of a handful of copies says little: those readings list the dynasties with enough
                $floor = (int) config('insights.min_sample');
                $by = fn (array $rows, string $key) => collect($rows)->sortByDesc(fn ($r) => [$r[$key], $r['count']])->take($shown)->values()->all();
                $readingNames = ['density' => 'در هر ده سال حکومت', 'count' => 'شمار', 'share' => 'سهم از همان سال‌ها'];
                $readingsOf = function (array $items) use ($by, $floor, $shown) {
                    $firm = array_values(array_filter($items, fn ($r) => $r['count'] >= $floor));

                    return ['density' => $by($firm, 'density'), 'count' => array_slice($items, 0, $shown), 'share' => $by($firm, 'share')];
                };
                // The copies made under each dynasty, and the works composed under it (step 14.288): [name, unit, lists]
                $kinds = ['copies' => ['نسخه‌ها (کتابت)', 'نسخه', $readingsOf($dy['items'])]];
                if ($dy['works']['ruled'] >= max(1, $min)) {
                    $kinds['works'] = ['آثار (تألیف)', 'اثر', $readingsOf($dy['works']['items'])];
                }
                $byName = array_column($dy['items'], null, 'name');
                // The map at the decade of the middle of a rule, on the dynasty's seat (step 14.377)
                $mapLink = fn (array $row) => route('insights.topic', ['topic' => 'places'] + $scope->query()
                    + ['map_year' => \App\Support\Insights::mapYearOf((int) $row['rule_from'], (int) $row['rule_to']), 'map_at' => $row['name']]) . '#places';
                $rowsData = [
                    'regions' => \App\Support\Dynasties::regions(),
                    'dynasties' => array_map(fn ($d) => ['n' => $d['name'], 'r' => $d['region'], 'f' => $d['from'], 't' => $d['to'],
                        'c' => $byName[$d['name']]['count'] ?? 0, 'd' => $byName[$d['name']]['density'] ?? 0, 's' => $byName[$d['name']]['share'] ?? 0,
                        'cities' => array_map(fn ($c) => [$c['name'], $c['count']], $byName[$d['name']]['cities'] ?? [])], \App\Support\Dynasties::all()),
                    'span' => [0, 1450],
                    // The page of the map, in the scope of this page, and the years of its slider (step 14.377)
                    'map' => route('insights.topic', ['topic' => 'places'] + $scope->query()),
                    'mapYears' => \App\Support\Insights::mapYears(),
                    // The years most of the copies are from; a phone shows the last three centuries of them
                    'window' => [1000, 1350],
                ];
            @endphp
            <p class="text-xs text-muted mb-4">
                هر نسخه به حکومتی داده شده که شهر کتابتش در سال کتابت در قلمرو آن بوده است. سلسلۀ دیرپا تنها به خاطر سال‌های بیشترش نسخۀ بیشتری دارد؛ پس سنجۀ نخست «نسخه در هر ده سال حکومت» است، نه شمار.
            </p>

            <div x-data="insightsDynasties(@js($rowsData))" class="mb-6" data-insights-dynasty-rows>
                <div class="flex flex-wrap items-center gap-1.5 text-xs mb-2">
                    <span class="text-muted me-1">دوره:</span>
                    @foreach([[250, 650, 'تا حملۀ مغول'], [640, 920, 'ایلخانی و تیموری'], [900, 1150, 'صفوی و گورکانی'], [1140, 1350, 'افشار تا قاجار'], [0, 1450, 'همه']] as [$from, $to, $name])
                        <button type="button" @click="show({{ $from }}, {{ $to }})" class="px-2.5 py-0.5 rounded-full border border-line bg-surface text-ink hover:border-cinnabar transition cursor-pointer">{{ $name }}</button>
                    @endforeach
                </div>
                <div x-ref="chart" class="relative w-full overflow-hidden select-none touch-pan-y" role="img"
                     aria-label="سلسله‌ها به تفکیک منطقه در گذر سال‌های هجری؛ هر نوار یک سلسله است و پررنگی‌اش شمار نسخه‌های هر ده سال حکومت آن. همین ارقام در فهرست و جدول زیر آمده است."></div>
                <p class="mt-2 text-xs text-ink min-h-5">
                    <template x-if="selected">
                        <span>
                            <strong x-text="selected.n"></strong> <span class="text-muted" x-text="years(selected) + ' · ' + selected.r"></span>:
                            <span x-text="figures(selected)"></span><span x-show="selected.cities.length" x-text="'؛ بیشتر در ' + selected.cities.map(([name, n]) => name + ' (' + fa(n) + ')').join('، ')"></span>.
                            <a :href="mapLink(selected)" class="text-cinnabar hover:underline whitespace-nowrap" data-insights-dynasty-map>روی نقشه ←</a>
                        </span>
                    </template>
                    <span x-show="! selected" class="text-muted text-[11px]">هر نوار یک سلسله است، از آغاز تا پایان حکومتش، در ردیف‌های منطقۀ خود؛ هر چه پررنگ‌تر، نسخۀ بیشتری در هر ده سالِ آن کتابت شده است. قاب نوار بالا یا خود ردیف‌ها را بکشید تا سال‌ها جابه‌جا شود (بزرگ‌نمایی: دو انگشت، یا Ctrl و چرخ موس) و روی هر سلسله بزنید.</span>
                </p>
                <noscript><p class="text-xs text-muted">نمودار ردیفی سلسله‌ها به JavaScript نیاز دارد؛ فهرست زیر همان ارقام را دارد.</p></noscript>
            </div>

            <div x-data="{ kind: 'copies', by: 'density' }" data-insights-dynasty-list>
                @if(count($kinds) > 1)
                    <div class="flex flex-wrap items-center gap-1.5 text-xs mb-2">
                        <span class="text-muted me-1">شمردن:</span>
                        @foreach($kinds as $kind => [$kindName])
                            <button type="button" @click="kind = '{{ $kind }}'" :aria-pressed="(kind === '{{ $kind }}').toString()"
                                    :class="kind === '{{ $kind }}' ? 'bg-ink text-paper border-ink' : 'bg-surface text-ink border-line hover:border-ink'"
                                    class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $kindName }}</button>
                        @endforeach
                    </div>
                @endif
                <div class="flex flex-wrap items-center gap-1.5 text-xs mb-3">
                    <span class="text-muted me-1">چیدن بر پایۀ:</span>
                    @foreach($readingNames as $key => $name)
                        <button type="button" @click="by = '{{ $key }}'" :aria-pressed="(by === '{{ $key }}').toString()"
                                :class="by === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                                class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
                    @endforeach
                </div>
                @foreach($kinds as $kind => [$kindName, $unit, $readings])
                @foreach($readings as $key => $rows)
                    @php $maxMeasure = max([0.1, ...array_column($rows, $key)]); @endphp
                    <ul class="space-y-3 text-sm" x-show="kind === '{{ $kind }}' && by === '{{ $key }}'" @if($kind !== 'copies' || $key !== 'density') x-cloak @endif
                        data-insights-dynasties="{{ $kind === 'copies' ? $key : $kind . '-' . $key }}">
                        @foreach($rows as $row)
                            @php
                                $figures = ['density' => $dec($row['density']) . ' در هر ده سال', 'count' => Fa::num($row['count']) . ' ' . $unit,
                                    'share' => $pct($row['share']) . ' ' . ($kind === 'copies' ? 'نسخه‌های' : 'آثار') . ' همان سال‌ها'];
                                $tip = $row['name'] . ': ' . Fa::num($row['count']) . ' ' . $unit . ' در ' . Fa::num($row['years']) . ' سال حکومت · ' . $dec($row['density']) . ' ' . $unit . ' در هر ده سال · '
                                    . $pct($row['share']) . ' ' . ($kind === 'copies' ? 'نسخه‌های سال‌دارِ' : 'آثار سال‌دارِ') . ' همان سال‌ها';
                            @endphp
                            <li>
                                <span class="flex flex-wrap items-baseline gap-x-2" data-tip="{{ $tip }}">
                                    <span class="text-ink font-semibold">{{ $row['name'] }}</span>
                                    <span class="text-[11px] text-muted tabular-nums">{{ Fa::digits($row['rule_from']) }} تا {{ Fa::digits($row['rule_to']) }}</span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums"><span class="text-ink">{{ $figures[$key] }}</span>@foreach(array_diff_key($figures, [$key => 1]) as $other) · {{ $other }}@endforeach</span>
                                </span>
                                <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                    <span class="block h-full {{ $kind === 'copies' ? 'bg-cinnabar/70' : 'bg-gold/80' }} rounded-full" style="width: max(2px, {{ round($row[$key] * 100 / $maxMeasure, 2) }}%)"></span>
                                </span>
                                <span class="mt-1 block text-xs text-muted">
                                    @foreach($row['cities'] as $city)
                                        @if($kind === 'copies')
                                            <a href="{{ $search('manuscripts', ['copy_city' => $city['name'], 'year_from' => $row['from'], 'year_to' => $row['to']]) }}" class="hover:text-cinnabar">{{ $city['name'] }}</a>
                                        @else
                                            {{ $city['name'] }}
                                        @endif
                                        <span class="tabular-nums">{{ Fa::num($city['count']) }}</span>{{ $loop->last ? '' : '،' }}
                                    @endforeach
                                    @if($row['city_kinds'] > count($row['cities'])) و {{ Fa::num($row['city_kinds'] - count($row['cities'])) }} شهر دیگر @endif
                                    @if($row['cities']) · @endif
                                    <a href="{{ $mapLink($row) }}" class="text-cinnabar hover:underline whitespace-nowrap">روی نقشه</a>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
                @endforeach
                @isset($kinds['works'])
                    <p x-show="kind === 'works'" x-cloak class="mt-4 inline-flex items-start gap-1.5 bg-gold/15 border border-gold/40 px-2.5 py-1 text-xs text-ink leading-relaxed">
                        <span aria-hidden="true">⚠</span>
                        <span>نمونۀ کوچک: تنها {{ Fa::num($dy['works']['ruled']) }} اثر ({{ $pct($dy['works']['coverage']) }} آثار) هم سال تألیف معین دارند و هم شهر تألیفشان روی نقشه است. فهرست‌نگاران این دو را بیشتر برای آثار متأخر ثبت کرده‌اند، پس این چینش را با احتیاط بخوانید. نمودار ردیفی بالا تنها نسخه‌ها را نشان می‌دهد.</span>
                    </p>
                @endisset
            </div>

            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایۀ {{ Fa::num($dy['ruled']) }} نسخه، یعنی تنها {{ $pct($dy['coverage']) }} نسخه‌ها: نسخه‌هایی که هم سال کتابت معین دارند و هم شهر کتابتشان روی نقشه است.
                @if($dy['counted'] > $dy['ruled']){{ Fa::num($dy['counted'] - $dy['ruled']) }} نسخۀ دیگر با همین دو آگاهی در دهه‌ای کتابت شده‌اند که برای شهرشان حکومتی شناخته نیست.@endif
                @if($dy['kinds'] > $shown)در هر چینش {{ Fa::num($shown) }} سلسله از {{ Fa::num($dy['kinds']) }} آمده است؛ جدول داده‌ها همه را دارد.@endif
            </p>
            <p class="mt-2 text-xs text-muted leading-relaxed">
                «نسخه در هر ده سال»: شمار نسخه‌ها بخش بر سال‌های حکومت سلسله، از آغاز تا پایانش. «سهم از نسخه‌های همان سال‌ها»: از همۀ نسخه‌های سال‌دار و شهردار که در سال‌های حکومت این سلسله در هر جا کتابت شده‌اند، چه بخشی در قلمرو آن بوده است؛ این سنجه افزون بر درازای حکومت، بیشتر ماندنِ نسخه‌های سده‌های نزدیک‌تر را هم کنار می‌گذارد.
                در این دو چینش، سلسله‌ای که کمتر از {{ Fa::num($floor) }} نسخه دارد نیامده است. هیچ‌یک از این سنجه‌ها وسعت قلمرو را به حساب نمی‌آورد: سلسله‌ای که شهرهای بیشتری داشته، نسخۀ بیشتری دارد.
                قلمرو هر حکومت از نقشه‌های تاریخی آمار دواوین است (مرزها: Cliopatria، بانک دادۀ Seshat)، به دقت دهه؛ مرز حکومت‌های پیشامدرن تقریبی است و چند سلسلۀ محلی هنوز مرز ندارند.
            </p>
            @include('insights.explain', ['note' => $notes['dynasties'], 'tables' => $tables['dynasties']])
        @endif
    </x-ui.card>
</x-ui.section>
