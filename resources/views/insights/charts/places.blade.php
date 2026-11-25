@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{--
    2. Centres of copying and of composition: the map (backlog item 137, step 14.275). The page is sent with a still
    map of the copies; resources/js/insights-map.js puts the map that is zoomed and dragged in its place when it comes
    into view, with the layers, the slider of years, the territories and the panel of the chosen city. The counts of
    the cities by decade are not in the page: the script asks for them then (insights.map, step 14.377).
--}}
<x-ui.section title="کانون‌های تاریخی استنساخ و تألیف" id="places">
    <x-ui.card>
        @if($p['mapped'] < $min && $cp['mapped'] < $min)
            @include('insights.thin', ['n' => max($p['mapped'], $cp['mapped']), 'what' => 'نسخه یا اثر با محل شناخته'])
        @else
        @php
            $mapData = [
                'layer' => $p['mapped'] >= $min ? 'copy' : 'composition',
                // The decades of the slider, by the year they end in
                'years' => \App\Support\Insights::mapYears(),
                // The cities and their counts by decade, in the scope of the page
                'data' => route('insights.map', $scope->query()),
                'frame' => \Illuminate\Support\Arr::only($map, ['lon0', 'lon1', 'lat0', 'lat1']),
                'basemap' => asset('data/insights/basemap.json'),
                'territories' => asset('data/insights/territories/__YEAR__.json'),
                'regions' => \App\Support\Dynasties::regions(),
                'capitals' => array_map(fn ($c) => ['n' => $c['name'], 'd' => $c['dynasty'], 'lat' => $c['lat'], 'lon' => $c['lon'], 'f' => $c['from'], 't' => $c['to']],
                    \App\Support\Dynasties::capitals()),
                // The searches that take a city of copying; the scribes' search cannot carry a scope
                'search' => ['copy' => $search('manuscripts'), 'scribes' => $scope->isEmpty() ? route('search', ['type' => 'scribes']) : null],
            ];
            $layerNames = ['copy' => 'نسخه‌ها (محل کتابت)', 'composition' => 'آثار (محل تألیف)', 'scribes' => 'کاتبان', 'authors' => 'مؤلفان',
                'origin' => 'خاستگاه (از نسبت)', 'birth' => 'زادگاه', 'death' => 'محل وفات', 'residence' => 'اقامت'];
            // The cities of the first layer as they stand, until the script has the data of the map and without script
            $stillField = $mapData['layer'] === 'copy' ? 'count' : 'works';
            $stillCities = collect($p['cities'])->filter(fn ($c) => $c[$stillField] > 0)->sortByDesc($stillField)->take(15)->values()->all();
            $chip = fn (string $on) => "{$on} ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'";
        @endphp
        <div x-data="insightsMap(@js($mapData))" data-insights-map>
        <div class="flex flex-wrap items-center gap-1.5 text-xs mb-3">
            <span class="text-muted me-1">لایه:</span>
            @foreach($layerNames as $key => $name)
                <button type="button" @click="layer = '{{ $key }}'" :aria-pressed="(layer === '{{ $key }}').toString()"
                        :class="{{ $chip("layer === '{$key}'") }}"
                        class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs mb-4">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-muted me-1">زمان:</span>
                @foreach(['all' => 'همۀ سال‌ها', 'until' => 'تا این سال', 'century' => 'صد سال پیش از آن'] as $key => $name)
                    <button type="button" @click="mode = '{{ $key }}'" :aria-pressed="(mode === '{{ $key }}').toString()"
                            :class="{{ $chip("mode === '{$key}'") }}"
                            class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
                @endforeach
            </div>
            <div class="flex items-center gap-2 grow min-w-56" :class="mode === 'all' && 'opacity-60'">
                <button type="button" @click="play()" :aria-pressed="playing.toString()" :aria-label="playing ? 'ایست' : 'پخش سال‌ها'"
                        class="size-7 shrink-0 rounded-full border border-line-strong bg-surface text-ink hover:border-cinnabar transition cursor-pointer" x-text="playing ? '❚❚' : '▶'">▶</button>
                <input type="range" min="{{ $mapData['years'][0] }}" max="{{ $mapData['years'][1] }}" step="10" x-model.number="year" @input="if (mode === 'all') { mode = 'until' }"
                       aria-label="سال هجری قمری" class="grow accent-cinnabar cursor-pointer">
                <output class="w-16 shrink-0 text-ink font-semibold tabular-nums text-center" x-text="mode === 'all' ? '—' : fa(year, false) + ' ق'">—</output>
            </div>
        </div>

        <div class="grid lg:grid-cols-[1fr_16rem] gap-6">
            <div class="relative min-w-0">
                <div x-ref="map" class="w-full h-72 sm:h-[32rem] bg-paper border border-line" x-show="ready" x-cloak role="img"
                     aria-label="نقشۀ کانون‌های استنساخ و تألیف؛ اندازۀ هر دایره شمار نسخه‌ها، آثار، کاتبان یا مؤلفان آن شهر است. همین ارقام در جدول زیر نمودار آمده است."></div>
                {{-- The still map of the copies, until the other one is ready and where there is no script --}}
                <svg x-show="! ready" viewBox="0 0 {{ $map['width'] }} {{ $map['height'] }}" class="w-full h-auto bg-paper border border-line" role="img"
                     aria-label="نقشۀ کانون‌های استنساخ؛ اندازۀ هر دایره شمار نسخه‌های کتابت‌شده در آن شهر است">
                    @include('insights.map-base')
                    @foreach($p['cities'] as $city)
                        @if($city['count'] > 0)
                            <circle cx="{{ $city['x'] }}" cy="{{ $city['y'] }}" r="{{ max(3, round(34 * sqrt($city['count'] / $maxPlace), 1)) }}" class="fill-cinnabar/55 stroke-surface" stroke-width="2"/>
                        @endif
                    @endforeach
                    <g class="hidden sm:inline" font-size="19" font-weight="600">
                        @foreach($layers['copy'] as $city)
                            {{-- RTL text: «start» is the right edge, so a name sits left of its dot, or right of it near the left edge --}}
                            <text x="{{ $city['x'] < 90 ? $city['x'] + $city['gap'] : $city['x'] - $city['gap'] }}" y="{{ $city['y'] + 6 }}" text-anchor="{{ $city['x'] < 90 ? 'end' : 'start' }}"
                                  class="fill-ink" paint-order="stroke" stroke="var(--paper)" stroke-width="5" stroke-linejoin="round">{{ $city['name'] }}</text>
                        @endforeach
                    </g>
                </svg>
                <div x-show="ready" x-cloak class="absolute top-2 left-2 grid gap-1 text-sm">
                    @foreach([['zoomBy(1.6)', '+', 'بزرگ‌نمایی'], ['zoomBy(0.625)', '−', 'کوچک‌نمایی'], ['reset()', '⤢', 'بازگشت به نمای کل']] as [$action, $sign, $name])
                        <button type="button" @click="{{ $action }}" aria-label="{{ $name }}" title="{{ $name }}"
                                class="size-8 bg-surface border border-line-strong text-ink hover:border-cinnabar hover:text-cinnabar transition cursor-pointer shadow-sm">{{ $sign }}</button>
                    @endforeach
                </div>
                <p x-show="ready && focus" x-cloak class="mt-2 text-xs text-ink" data-insights-map-focus>
                    نقشه روی دهۀ میانۀ حکومت <strong x-text="focus"></strong> باز شده است؛ قلمرو آن در همین دهه کشیده شده است.
                    <button type="button" @click="focus = null; mode = 'all'; reset()" class="text-cinnabar hover:underline cursor-pointer">همۀ سال‌ها</button>
                </p>
                <p x-show="failed" x-cloak class="mt-2 text-xs text-muted">نقشۀ تعاملی بار نشد؛ نقشۀ ثابتِ محل کتابت نسخه‌ها نشان داده شده است.</p>
                <ul x-show="ready && mode !== 'all'" x-cloak class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-muted" aria-label="رنگ قلمروها به تفکیک منطقه">
                    <template x-for="region in regions" :key="region">
                        <li class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm opacity-60" :style="`background: ${regionColor(region)}`" aria-hidden="true"></span><span x-text="region"></span></li>
                    </template>
                    <li class="flex items-center gap-1.5"><span class="inline-block size-2 border-[1.5px] border-ink bg-surface" aria-hidden="true"></span>پایتخت</li>
                </ul>
                <p x-show="ready" x-cloak class="mt-2 text-[11px] text-muted">
                    با چرخ موس یا دو انگشت بزرگ و کوچک کنید و نقشه را بکشید. روی هر شهر بزنید تا ارقامش کنار نقشه بیاید.
                </p>
            </div>

            <div class="min-w-0">
                {{-- The chosen city: its figures in every layer, who ruled it then, and the searches that have it --}}
                <div x-show="selected" x-cloak class="mb-4 border border-line-strong bg-paper p-3" data-insights-city>
                    <div class="flex items-baseline justify-between gap-2">
                        <strong class="font-naskh text-lg text-ink" x-text="selected?.n"></strong>
                        <button type="button" @click="select(null)" class="text-muted hover:text-cinnabar cursor-pointer px-1" aria-label="بستن">×</button>
                    </div>
                    <p class="text-[11px] text-muted" x-text="span"></p>
                    <p x-show="ruler" class="text-xs text-ink mt-1">در قلمرو <span class="font-semibold" x-text="ruler?.name"></span> <span class="text-muted" x-text="ruler ? '(' + ruler.span + ')' : ''"></span></p>
                    <ul class="mt-2 text-sm">
                        <template x-for="row in figures" :key="row.key">
                            <li class="flex items-baseline gap-2 py-0.5">
                                <span class="text-ink" x-text="row.unit"></span>
                                <span class="leader" aria-hidden="true"></span>
                                <a x-show="link(row.key) && row.n" :href="link(row.key)" class="text-xs text-cinnabar tabular-nums hover:underline" x-text="fa(row.n) + ' ←'"></a>
                                <span x-show="! (link(row.key) && row.n)" class="text-xs text-muted tabular-nums" x-text="fa(row.n)"></span>
                            </li>
                        </template>
                    </ul>
                </div>

                <p class="text-xs text-muted mb-2">
                    <span x-text="! loaded ? $el.textContent : { copy: 'پرکارترین شهرهای کتابت', composition: 'شهرهای تألیف', scribes: 'شهرهای پرکاتب', authors: 'شهرهای پرمؤلف', origin: 'شهرهای نسبت', birth: 'زادگاه‌ها', death: 'شهرهای وفات', residence: 'شهرهای اقامت' }[layer] + (mode === 'all' ? '' : '، ' + span)">{{ $mapData['layer'] === 'copy' ? 'پرکارترین شهرهای کتابت' : 'شهرهای تألیف' }}</span>
                </p>
                <ol class="text-sm">
                    <template x-for="row in ranked" :key="row.city.n">
                        <li class="border-b border-line">
                            <button type="button" @click="select(row.city)" class="w-full flex items-baseline gap-2 py-1.5 group cursor-pointer text-right">
                                <span class="text-ink group-hover:text-cinnabar transition" :class="selected?.n === row.city.n && 'text-cinnabar font-semibold'" x-text="row.city.n"></span>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-xs text-muted tabular-nums" x-text="fa(row.n)"></span>
                            </button>
                        </li>
                    </template>
                </ol>
                {{-- Until the data of the map has come, and without script: the cities of the first layer as they stand --}}
                <ol class="text-sm" x-show="! loaded" data-insights-still-cities>
                    @foreach($stillCities as $city)
                        <li class="border-b border-line flex items-baseline gap-2 py-1.5">
                            @if($stillField === 'count')
                                <a href="{{ $search('manuscripts', ['copy_city' => $city['name']]) }}" class="text-ink hover:text-cinnabar">{{ $city['name'] }}</a>
                            @else
                                <span class="text-ink">{{ $city['name'] }}</span>
                            @endif
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums">{{ Fa::num($city[$stillField]) }}</span>
                        </li>
                    @endforeach
                </ol>
                <p x-show="loaded && ranked.length === 0" x-cloak class="text-xs text-muted py-4">برای این لایه و این سال‌ها محلی ثبت نشده است.</p>
                <p x-show="ranked.length" x-cloak class="mt-2 text-[11px] text-muted">روی هم <span class="tabular-nums" x-text="fa(total)"></span> <span x-text="unit"></span> در شهرهای نقشه<span x-show="layer === 'scribes' || layer === 'authors'">؛ کسی که در دو شهر کار کرده در هر دو شمرده شده است</span><span x-show="layer === 'origin'" x-cloak>؛ کسی که دو نسبت دارد در هر دو شهر شمرده شده است</span>.</p>
            </div>
        </div>

        <p x-show="layer === 'copy' || layer === 'scribes'" class="mt-5 text-xs text-muted leading-relaxed">
            محل کتابت: بر پایۀ {{ Fa::num($p['mapped']) }} نسخه، یعنی تنها {{ $pct($p['coverage']) }} نسخه‌ها.
            محل کتابت برای {{ Fa::num($p['with_place']) }} نسخه ({{ $pct($p['place_coverage']) }}) ثبت شده و {{ $pct($p['mapped_share']) }} آن‌ها به یکی از {{ Fa::num(count(config('insights.copy_places'))) }} شهر شناخته‌شده رسید؛
            نام‌های کهن و گونه‌گون یک شهر (طهران، سپاهان، توس، قسطنطنیه …) یکی شمرده شده‌اند و نام‌هایی چون «ایران» یا «هندوستان» که شهر نیستند، روی نقشه نیامده‌اند.
        </p>
        <p x-show="layer === 'composition' || layer === 'authors'" x-cloak class="mt-5 text-xs text-muted leading-relaxed">
            محل تألیف: تنها {{ Fa::num($cp['with_place']) }} اثر، یعنی {{ $pct($cp['place_coverage']) }} آثار، محل تألیف دارند و {{ Fa::num($cp['mapped']) }} اثر از آن‌ها ({{ $pct($cp['mapped_share']) }}) به یکی از همین شهرها رسید.
            پس این لایه بر بخش بسیار کوچکی از آثار تکیه دارد. گونه‌های نام یک شهر («مشهد رضوی»، «مشهد مقدس») یکی شمرده شده‌اند.
            جست‌وجو فیلتر محل تألیف ندارد، پس ارقام این لایه پیوند ندارند.
        </p>
        <p x-show="layer === 'scribes'" x-cloak class="mt-3 text-xs text-muted leading-relaxed">
            کاتبان: {{ Fa::num($p['scribes_mapped']) }} کاتبِ شناخته که دست‌کم یک نسخه‌شان محل کتابت روی نقشه دارد. محل هر کاتب، شهر کتابت نسخه‌های اوست، نه زادگاه یا محل زندگی‌اش؛ این آگاهی‌ها هنوز در فهارس ثبت نشده است.
        </p>
        <p x-show="layer === 'authors'" x-cloak class="mt-3 text-xs text-muted leading-relaxed">
            مؤلفان: {{ Fa::num($p['authors_mapped']) }} مؤلف که دست‌کم یک اثرشان محل تألیف روی نقشه دارد. محل هر مؤلف، شهر تألیف آثار اوست، نه زادگاه یا محل زندگی‌اش.
        </p>
        <p x-show="layer === 'origin'" x-cloak class="mt-5 text-xs text-muted leading-relaxed">
            خاستگاه: {{ Fa::num($p['origins_mapped']) }} مؤلف و کاتب که در نامشان نسبتِ یکی از شهرهای نقشه هست («اصفهانی»، «حلّی»، «هروی»).
            نسبت می‌گوید شخص یا خاندانش از کجا بوده‌اند، نه اینکه کجا زاده شده یا زیسته است؛ نسبتِ پدر یا استاد هم گاه در نام می‌آید. این لایه را قاعده‌ای خودکار ساخته و کسی بازبینی‌اش نکرده است.
            «مشهدی» و «کربلایی» که بیشتر لقب زائرند شمرده نشده‌اند.
        </p>
        <p x-show="layer === 'birth' || layer === 'death'" x-cloak class="mt-5 text-xs text-muted leading-relaxed">
            زادگاه و محل وفات: از متن زندگی‌نامۀ {{ Fa::num($p['lives_read']) }} شخص در کتاب‌های تراجم (طبقات اعلام الشیعه، اعیان الشیعه، الاعلام و مانند آن) خوانده شده است.
            زادگاه {{ Fa::num($p['births_mapped']) }} نفر و محل وفات {{ Fa::num($p['deaths_mapped']) }} نفر یکی از شهرهای نقشه بود.
            تنها عبارتی گرفته شده که شهر را درست پس از «ولد»، «توفی»، «متولد» یا «درگذشت» می‌آورد؛ پس این لایه بخش کوچکی از اشخاص را دارد.
            قاعده‌ای خودکار آن را خوانده و پیوند زندگی‌نامه‌ها به اشخاص هم هنوز بازبینی نشده است؛ آنچه بازبینان در صفحۀ شخص رد کرده‌اند شمرده نمی‌شود و آنچه خود ثبت کرده‌اند شمرده می‌شود.
        </p>
        <p x-show="layer === 'residence'" x-cloak class="mt-5 text-xs text-muted leading-relaxed">
            اقامت: {{ Fa::num($p['residences_mapped']) }} مؤلف و کاتب که زندگی‌نامه‌شان اقامت در یکی از شهرهای نقشه را می‌گوید («سکن»، «نزل»، «أقام» …)، از «دوره‌های زندگی» صفحۀ اشخاص.
            بیشتر این دوره‌ها هنوز پیشنهاد خودکارند و بازبینی نشده‌اند؛ آنچه بازبینان رد کرده‌اند شمرده نمی‌شود. هر شخص در دهۀ وفاتش شمرده می‌شود، نه در سال‌های اقامت.
        </p>
        <p class="mt-3 text-xs text-muted leading-relaxed">
            لغزندۀ سال: «تا این سال» نسخه یا اثری را می‌شمارد که کل بازۀ تاریخش تا آن سال گذشته است؛ پس نسخۀ «قرن ۱۱» از سال ۱۱۰۰ شمرده می‌شود و نسخۀ بی تاریخ تنها در «همۀ سال‌ها» هست.
            کاتب و مؤلف در نخستین دهه‌ای شمرده می‌شوند که نسخه یا اثر تاریخ‌داری از آنان در آن شهر هست.
            در لایه‌های خاستگاه، زادگاه و محل وفات، هر شخص در دهۀ وفاتش شمرده می‌شود (کاتبِ بی سال وفات، در دهۀ واپسین نسخه‌اش).
            قلمرو حکومت‌ها از نقشه‌های تاریخی آمار دواوین است (مرزها: Cliopatria، بانک دادۀ Seshat)، به دقت دهه؛ مرز حکومت‌های پیشامدرن تقریبی است و چند سلسلۀ محلی هنوز مرز ندارند.
        </p>
        @include('insights.explain', ['note' => $notes['places'], 'tables' => $tables['places']])
        </div>
        @endif
    </x-ui.card>
</x-ui.section>
