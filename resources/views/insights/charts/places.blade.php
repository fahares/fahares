@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2. Centres of copying and of composition --}}
<x-ui.section title="کانون‌های تاریخی استنساخ و تألیف" id="places">
    <x-ui.card>
        @if($p['mapped'] < $min && $cp['mapped'] < $min)
            @include('insights.thin', ['n' => max($p['mapped'], $cp['mapped']), 'what' => 'نسخه یا اثر با محل شناخته'])
        @else
        <div x-data="{
            c: 'all',
            layer: '{{ $p['mapped'] >= $min ? 'copy' : 'composition' }}',
            hover: null,
            cities: @js($mapCities),
            n(city) { return this.c === 'all' ? city.t : (city.c[this.c] || 0) },
            w(city) { return this.c === 'all' ? city.w : (city.wc[this.c] || 0) },
            key(city) { return this.layer === 'composition' ? this.w(city) : this.n(city) },
            get maxN() { return Math.max(1, ...this.cities.map(city => this.n(city))) },
            get maxW() { return Math.max(1, ...this.cities.map(city => this.w(city))) },
            r(city) { const v = this.layer === 'composition' ? 0 : this.n(city); return v ? Math.max(3, 34 * Math.sqrt(v / this.maxN)) : 0 },
            rw(city) { const v = this.layer === 'copy' ? 0 : this.w(city); return v ? Math.max(3, 34 * Math.sqrt(v / this.maxW)) : 0 },
            href(city) { return this.layer === 'composition' ? null : `{{ $search('manuscripts') }}&amp;copy_city=${encodeURIComponent(city.n)}` + (this.c === 'all' ? '' : `&century=${this.c}`) },
            tip(city) { return [this.layer !== 'composition' ? this.n(city).toLocaleString('fa-IR') + ' نسخه' : null, this.layer !== 'copy' ? this.w(city).toLocaleString('fa-IR') + ' اثر' : null].filter(Boolean).join(' · ') },
            get ranked() { return this.cities.filter(city => this.key(city) > 0).sort((a, b) => this.key(b) - this.key(a)).slice(0, 15) },
            get total() { return this.cities.reduce((s, city) => s + this.key(city), 0) },
        }">
        <div class="flex flex-wrap items-center gap-1.5 text-xs mb-3">
            <span class="text-muted me-1">لایه:</span>
            @foreach(['copy' => 'محل کتابت', 'composition' => 'محل تألیف', 'both' => 'هر دو'] as $key => $name)
                <button type="button" @click="layer = '{{ $key }}'" :aria-pressed="(layer === '{{ $key }}').toString()"
                        :class="layer === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                        class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-1.5 text-xs mb-4">
            <span class="text-muted me-1">سده:</span>
            <button type="button" @click="c = 'all'" :aria-pressed="(c === 'all').toString()"
                    :class="c === 'all' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                    class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">همه</button>
            @foreach($mapCenturies as $c)
                <button type="button" @click="c = {{ $c }}" :aria-pressed="(c === {{ $c }}).toString()"
                        :class="c === {{ $c }} ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                        class="min-w-8 px-2 py-0.5 rounded-full border tabular-nums transition cursor-pointer">{{ Fa::digits($c) }}</button>
            @endforeach
        </div>

        <div class="grid lg:grid-cols-[1fr_16rem] gap-6">
            <div class="relative">
                <svg viewBox="0 0 {{ $map['width'] }} {{ $map['height'] }}" class="w-full h-auto bg-paper border border-line" role="img"
                     aria-label="نقشۀ کانون‌های استنساخ و تألیف؛ اندازۀ هر دایره شمار نسخه‌های کتابت‌شده یا آثار تألیف‌شده در آن شهر است">
                    @include('insights.map-base')
                    <g>
                        @foreach($p['cities'] as $i => $city)
                            <a href="{{ $search('manuscripts', ['copy_city' => $city['name']]) }}"
                               :href="href(cities[{{ $i }}])"
                               @mouseenter="hover = cities[{{ $i }}]" @mouseleave="hover = null" @focus="hover = cities[{{ $i }}]" @blur="hover = null"
                               aria-label="{{ $city['name'] }}">
                                <circle cx="{{ $city['x'] }}" cy="{{ $city['y'] }}"
                                        r="{{ $city['count'] ? max(3, round(34 * sqrt($city['count'] / $maxPlace), 1)) : 0 }}"
                                        :r="r(cities[{{ $i }}])"
                                        class="fill-cinnabar/55 stroke-surface hover:fill-cinnabar transition-colors cursor-pointer" stroke-width="2"/>
                            </a>
                        @endforeach
                    </g>
                    {{-- The places of composition: discs alone, rings over the copies when both are shown --}}
                    <g>
                        @foreach($p['cities'] as $i => $city)
                            @if($city['works'] > 0)
                                <circle cx="{{ $city['x'] }}" cy="{{ $city['y'] }}" r="0" :r="rw(cities[{{ $i }}])"
                                        :class="layer === 'both' ? 'fill-transparent stroke-gold' : 'fill-gold/60 stroke-surface hover:fill-gold'"
                                        :stroke-width="layer === 'both' ? 3 : 2" class="transition-colors"
                                        @mouseenter="hover = cities[{{ $i }}]" @mouseleave="hover = null"/>
                            @endif
                        @endforeach
                    </g>
                    @foreach($layers as $layer => $labels)
                        <g class="hidden sm:inline pointer-events-none" font-size="19" font-weight="600"
                           @if($layer === 'copy') x-show="layer !== 'composition'" @else x-show="layer === 'composition'" x-cloak @endif>
                            @foreach($labels as $city)
                                {{-- RTL text: «start» is the right edge, so a name sits left of its dot, or right of it near the left edge --}}
                                <text x="{{ $city['x'] < 90 ? $city['x'] + $city['gap'] : $city['x'] - $city['gap'] }}" y="{{ $city['y'] + 6 }}" text-anchor="{{ $city['x'] < 90 ? 'end' : 'start' }}"
                                      class="fill-ink" paint-order="stroke" stroke="var(--paper)" stroke-width="5" stroke-linejoin="round">{{ $city['name'] }}</text>
                            @endforeach
                        </g>
                    @endforeach
                </svg>
                <div x-cloak x-show="hover" class="absolute top-2 right-2 bg-surface border border-line-strong shadow-sm px-3 py-1.5 text-xs pointer-events-none">
                    <strong class="text-ink" x-text="hover?.n"></strong>
                    <span class="text-muted tabular-nums" x-text="hover ? tip(hover) : ''"></span>
                </div>
                <ul x-show="layer === 'both'" x-cloak class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-muted">
                    <li class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-full bg-cinnabar/55" aria-hidden="true"></span>محل کتابت (نسخه)</li>
                    <li class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-full border-2 border-gold" aria-hidden="true"></span>محل تألیف (اثر)</li>
                    <li>اندازۀ دایره‌های هر لایه جدا سنجیده شده است.</li>
                </ul>
            </div>

            <div>
                <p class="text-xs text-muted mb-2">
                    <span x-text="layer === 'composition' ? 'شهرهای تألیف' : 'پرکارترین شهرها'"></span>
                    <span x-show="c !== 'all'" x-cloak>در سدۀ <span x-text="Number(c).toLocaleString('fa-IR')"></span></span>
                </p>
                <ol class="text-sm">
                    <template x-for="city in ranked" :key="city.n">
                        <li class="border-b border-line">
                            <a :href="href(city)" class="flex items-baseline gap-2 py-1.5 group">
                                <span class="text-ink transition" :class="layer !== 'composition' && 'group-hover:text-cinnabar'" x-text="city.n"></span>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-xs text-muted tabular-nums" x-text="layer === 'both' ? tip(city) : key(city).toLocaleString('fa-IR')"></span>
                            </a>
                        </li>
                    </template>
                </ol>
                <p x-show="ranked.length === 0" x-cloak class="text-xs text-muted py-4">برای این سده محلی ثبت نشده است.</p>
            </div>
        </div>

        <p x-show="layer !== 'composition'" class="mt-5 text-xs text-muted leading-relaxed">
            محل کتابت: بر پایۀ {{ Fa::num($p['mapped']) }} نسخه، یعنی تنها {{ $pct($p['coverage']) }} نسخه‌ها.
            محل کتابت برای {{ Fa::num($p['with_place']) }} نسخه ({{ $pct($p['place_coverage']) }}) ثبت شده و {{ $pct($p['mapped_share']) }} آن‌ها به یکی از {{ Fa::num(count(config('insights.copy_places'))) }} شهر شناخته‌شده رسید؛
            نام‌های کهن و گونه‌گون یک شهر (طهران، سپاهان، توس، قسطنطنیه …) یکی شمرده شده‌اند و نام‌هایی چون «ایران» یا «هندوستان» که شهر نیستند، روی نقشه نیامده‌اند.
            سدۀ هر نسخه، سده‌ای است که کل بازۀ تاریخش در آن است. از نسخه‌های روی نقشه، سدۀ {{ Fa::num($p['by_century']) }} نسخه روشن است و {{ Fa::num($p['century_only']) }} نسخه از آن‌ها تنها سده یا بخشی از سده دارند («قرن ۱۱»، «اواخر قرن ۱۲»).
            نسخه‌ای که بازه‌اش از دو سده می‌گذرد یا تاریخ ندارد، تنها در «همه» شمرده می‌شود.
            <span x-show="c !== 'all' && layer === 'copy'" x-cloak>در این سده <span x-text="total.toLocaleString('fa-IR')"></span> نسخۀ تاریخ‌دار با محل کتابت هست.</span>
        </p>
        <p x-show="layer !== 'copy'" x-cloak class="mt-3 text-xs text-muted leading-relaxed">
            محل تألیف: تنها {{ Fa::num($cp['with_place']) }} اثر، یعنی {{ $pct($cp['place_coverage']) }} آثار، محل تألیف دارند و {{ Fa::num($cp['mapped']) }} اثر از آن‌ها ({{ $pct($cp['mapped_share']) }}) به یکی از همین شهرها رسید.
            پس این لایه بر بخش بسیار کوچکی از آثار تکیه دارد. گونه‌های نام یک شهر («مشهد رضوی»، «مشهد مقدس») یکی شمرده شده‌اند. سدۀ هر اثر، سده‌ای است که کل بازۀ تاریخ تألیفش در آن است؛ از آثار روی نقشه، سدۀ {{ Fa::num($cp['by_century']) }} اثر روشن است.
            جست‌وجو فیلتر محل تألیف ندارد، پس شهرهای این لایه پیوند ندارند.
        </p>
        @include('insights.explain', ['note' => $notes['places'], 'tables' => $tables['places']])
        </div>
        @endif
    </x-ui.card>
</x-ui.section>
