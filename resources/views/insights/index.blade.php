@extends('layouts.app')

@section('title', 'آمار: نسخه‌های خطی در گذر سده‌ها | فهارس')
@section('meta_description', 'خط زمانی کتابت نسخه‌های خطی از سده‌ی چهارم تا چهاردهم هجری، کانون‌های تاریخی استنساخ، آثار پرنسخه و سهم خطوط و زبان‌ها در فهارس')

@section('content')
@php
    use App\Support\Fa;
    use App\Support\Jalali;

    $pct = fn ($v) => Fa::digits(str_replace('.', '٫', (string) $v)) . '٪';
    $t = $data['timeline'];
    $p = $data['places'];
    $s = $data['scripts'];
    $l = $data['languages'];
    $maxCentury = max(array_column($t['centuries'], 'count')) ?: 1;
    $peak = collect($t['centuries'])->sortByDesc('count')->first();

    // Names on the map: the largest centres, skipping one that sits too close to a larger one already named
    $labels = [];
    foreach ($p['cities'] as $city) {
        if (count($labels) >= 14) {
            break;
        }
        $near = collect($labels)->contains(fn ($o) => hypot($o['x'] - $city['x'], $o['y'] - $city['y']) < 34);
        if (! $near) {
            $labels[] = $city + ['gap' => max(3, 34 * sqrt($city['count'] / max(1, $p['cities'][0]['count']))) + 5];
        }
    }
    $maxPlace = max(1, $p['cities'][0]['count'] ?? 1);
    $mapCities = array_map(fn ($c) => ['n' => $c['name'], 'x' => $c['x'], 'y' => $c['y'], 't' => $c['count'], 'c' => (object) $c['centuries']], $p['cities']);
    $mapCenturies = range(7, config('insights.centuries')[1]);
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-10">

    <header class="text-center grid justify-items-center gap-2">
        <p class="text-xs font-bold text-cinnabar">آمار</p>
        <h1 class="font-naskh text-[28px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">نسخه‌ها در گذر سده‌ها</h1>
        <p class="text-sm text-muted max-w-2xl leading-relaxed">
            آنچه {{ Fa::approx($data['totals']['manuscripts']) }} نسخه‌ی خطی و {{ Fa::approx($data['totals']['works']) }} اثرِ ثبت‌شده در فهارس درباره‌ی زمان و جای کتابت، آثار پرنسخه و خطوط و زبان‌ها می‌گوید.
            هر نمودار تنها بر بخشی از نسخه‌ها تکیه دارد که آن آگاهی را دارند؛ سهم هر یک زیر نمودار آمده است.
        </p>
    </header>

    <dl class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-line border border-line">
        @foreach([
            ['نسخه‌ی خطی', Fa::num($data['totals']['manuscripts']), 'متعلق به ' . Fa::num($data['totals']['works']) . ' اثر'],
            ['نسخه‌ی تاریخ‌دار', Fa::num($t['shown']), $pct($t['coverage']) . ' نسخه‌ها'],
            ['اوج کتابت', 'سده‌ی ' . Fa::digits($peak['century']), Fa::num($peak['count']) . ' نسخه'],
            ['کانون استنساخ', Fa::num(count($p['cities'])) . ' شهر', 'از ' . Fa::num($p['mapped']) . ' نسخه‌ی جای‌دار'],
        ] as [$label, $value, $note])
            <div class="bg-surface p-4 grid gap-0.5">
                <dt class="text-xs text-muted">{{ $label }}</dt>
                <dd class="font-naskh text-2xl font-bold text-ink tabular-nums">{{ $value }}</dd>
                <dd class="text-xs text-muted">{{ $note }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- 1. Timeline of copying --}}
    <x-ui.section title="خط زمانی کتابت" id="timeline">
        <x-ui.card>
            <div x-data="{ sel: {{ $peak['century'] }}, cs: @js(collect($t['centuries'])->keyBy('century')) }">
            <p class="text-xs text-muted mb-4">شمار نسخه‌های کتابت‌شده در هر سده‌ی هجری. روی هر سده بزنید تا نسخه‌های آن در جستجو باز شود.</p>

            <div class="flex items-end gap-1 sm:gap-2 h-56 border-b border-line-strong" role="list" aria-label="شمار نسخه‌ها در هر سده">
                @foreach($t['centuries'] as $row)
                    <a role="listitem" href="{{ route('search', ['type' => 'manuscripts', 'century' => $row['century']]) }}"
                       class="group flex-1 h-full flex flex-col justify-end items-center gap-1 min-w-0 focus:outline-none"
                       title="سده‌ی {{ Fa::digits($row['century']) }}: {{ Fa::num($row['count']) }} نسخه"
                       aria-label="سده‌ی {{ Fa::digits($row['century']) }}: {{ Fa::num($row['count']) }} نسخه">
                        <span class="text-[10px] sm:text-xs text-muted tabular-nums whitespace-nowrap group-hover:text-cinnabar">{{ Fa::approx($row['count']) }}</span>
                        <span class="w-full max-w-12 rounded-t-[4px] bg-cinnabar/70 group-hover:bg-cinnabar group-focus-visible:bg-cinnabar group-focus-visible:ring-2 group-focus-visible:ring-gold transition"
                              style="height: max(2px, {{ round($row['count'] * 100 / $maxCentury, 2) }}%)"></span>
                    </a>
                @endforeach
            </div>
            <div class="flex gap-1 sm:gap-2 mt-1.5" aria-hidden="true">
                @foreach($t['centuries'] as $row)
                    <span class="flex-1 text-center text-xs text-ink tabular-nums">{{ Fa::digits($row['century']) }}</span>
                @endforeach
            </div>
            <p class="text-center text-[11px] text-muted mt-0.5">سده‌ی هجری</p>

            {{-- Decades of one century --}}
            <div class="mt-6 pt-4 border-t border-line">
                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                    <span class="text-muted me-1">دهه‌های سده‌ی</span>
                    @foreach($t['centuries'] as $row)
                        <button type="button" @click="sel = {{ $row['century'] }}"
                                :class="sel === {{ $row['century'] }} ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                                :aria-pressed="(sel === {{ $row['century'] }}).toString()"
                                class="min-w-8 px-2 py-0.5 rounded-full border tabular-nums transition cursor-pointer">{{ Fa::digits($row['century']) }}</button>
                    @endforeach
                </div>
                <div x-cloak class="mt-4">
                    <div class="flex items-end gap-1 h-28 border-b border-line">
                        <template x-for="(n, i) in cs[sel].decades" :key="i">
                            <div class="flex-1 h-full flex flex-col justify-end items-center gap-0.5 min-w-0"
                                 :title="`${((sel - 1) * 100 + i * 10 + 1).toLocaleString('fa-IR', {useGrouping: false})} تا ${((sel - 1) * 100 + i * 10 + 10).toLocaleString('fa-IR', {useGrouping: false})}: ${n.toLocaleString('fa-IR')} نسخه`">
                                <span class="text-[10px] text-muted tabular-nums" x-text="n.toLocaleString('fa-IR')"></span>
                                <span class="w-full max-w-10 rounded-t-[4px] bg-gold/70"
                                      :style="`height: max(${n ? 2 : 0}px, ${n * 100 / Math.max(1, ...cs[sel].decades)}%)`"></span>
                            </div>
                        </template>
                    </div>
                    <div class="flex gap-1 mt-1">
                        <template x-for="i in 10" :key="i">
                            <span class="flex-1 text-center text-[10px] text-muted tabular-nums" x-text="((sel - 1) * 100 + (i - 1) * 10 + 1).toLocaleString('fa-IR', {useGrouping: false})"></span>
                        </template>
                    </div>
                    <p class="mt-3 text-xs">
                        <a :href="`{{ route('search', ['type' => 'manuscripts']) }}&century=${sel}`" class="text-cinnabar font-semibold hover:underline">
                            دیدن <span x-text="cs[sel].count.toLocaleString('fa-IR')"></span> نسخه‌ی سده‌ی <span x-text="sel.toLocaleString('fa-IR')"></span> در جستجو ←
                        </a>
                    </p>
                </div>
            </div>

            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایه‌ی {{ Fa::num($t['shown']) }} نسخه، یعنی {{ $pct($t['coverage']) }} نسخه‌ها، که سال کتابتشان ثبت شده است.
                @if($t['outside'] > 0){{ Fa::num($t['outside']) }} نسخه‌ی کتابت‌شده پس از سال ۱۴۰۰ در نمودار نیامده است.@endif
            </p>
            </div>
        </x-ui.card>
    </x-ui.section>

    {{-- 2. Centres of copying --}}
    <x-ui.section title="کانون‌های تاریخی استنساخ" id="places">
        <x-ui.card>
            <div x-data="{
                c: 'all',
                hover: null,
                cities: @js($mapCities),
                n(city) { return this.c === 'all' ? city.t : (city.c[this.c] || 0) },
                get max() { return Math.max(1, ...this.cities.map(city => this.n(city))) },
                r(city) { const v = this.n(city); return v ? Math.max(3, 34 * Math.sqrt(v / this.max)) : 0 },
                href(city) { return `{{ route('search', ['type' => 'manuscripts']) }}&amp;copy_city=${encodeURIComponent(city.n)}` + (this.c === 'all' ? '' : `&century=${this.c}`) },
                get ranked() { return this.cities.filter(city => this.n(city) > 0).sort((a, b) => this.n(b) - this.n(a)).slice(0, 15) },
                get total() { return this.cities.reduce((s, city) => s + this.n(city), 0) },
            }">
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
                         aria-label="نقشه‌ی کانون‌های استنساخ؛ اندازه‌ی هر دایره شمار نسخه‌های کتابت‌شده در آن شهر است">
                        @include('insights.map-base')
                        <g>
                            @foreach($p['cities'] as $i => $city)
                                <a href="{{ route('search', ['type' => 'manuscripts', 'copy_city' => $city['name']]) }}"
                                   :href="href(cities[{{ $i }}])"
                                   @mouseenter="hover = cities[{{ $i }}]" @mouseleave="hover = null" @focus="hover = cities[{{ $i }}]" @blur="hover = null"
                                   aria-label="{{ $city['name'] }}">
                                    <circle cx="{{ $city['x'] }}" cy="{{ $city['y'] }}"
                                            r="{{ max(3, round(34 * sqrt($city['count'] / $maxPlace), 1)) }}"
                                            :r="r(cities[{{ $i }}])"
                                            class="fill-cinnabar/55 stroke-surface hover:fill-cinnabar transition-colors cursor-pointer" stroke-width="2"/>
                                </a>
                            @endforeach
                        </g>
                        <g class="hidden sm:inline pointer-events-none" font-size="19" font-weight="600">
                            @foreach($labels as $city)
                                {{-- RTL text: «start» is the right edge, so a name sits left of its dot, or right of it near the left edge --}}
                                <text x="{{ $city['x'] < 90 ? $city['x'] + $city['gap'] : $city['x'] - $city['gap'] }}" y="{{ $city['y'] + 6 }}" text-anchor="{{ $city['x'] < 90 ? 'end' : 'start' }}"
                                      class="fill-ink" paint-order="stroke" stroke="var(--paper)" stroke-width="5" stroke-linejoin="round">{{ $city['name'] }}</text>
                            @endforeach
                        </g>
                    </svg>
                    <div x-cloak x-show="hover" class="absolute top-2 right-2 bg-surface border border-line-strong shadow-sm px-3 py-1.5 text-xs pointer-events-none">
                        <strong class="text-ink" x-text="hover?.n"></strong>
                        <span class="text-muted tabular-nums" x-text="hover ? n(hover).toLocaleString('fa-IR') + ' نسخه' : ''"></span>
                    </div>
                </div>

                <div>
                    <p class="text-xs text-muted mb-2">
                        پرکارترین شهرها
                        <span x-show="c !== 'all'" x-cloak>در سده‌ی <span x-text="Number(c).toLocaleString('fa-IR')"></span></span>
                    </p>
                    <ol class="text-sm">
                        <template x-for="city in ranked" :key="city.n">
                            <li class="border-b border-line">
                                <a :href="href(city)" class="flex items-baseline gap-2 py-1.5 group">
                                    <span class="text-ink group-hover:text-cinnabar transition" x-text="city.n"></span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums" x-text="n(city).toLocaleString('fa-IR')"></span>
                                </a>
                            </li>
                        </template>
                    </ol>
                    <p x-show="ranked.length === 0" x-cloak class="text-xs text-muted py-4">برای این سده جای کتابتی ثبت نشده است.</p>
                </div>
            </div>

            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایه‌ی {{ Fa::num($p['mapped']) }} نسخه، یعنی تنها {{ $pct($p['coverage']) }} نسخه‌ها.
                جای کتابت برای {{ Fa::num($p['with_place']) }} نسخه ({{ $pct($p['place_coverage']) }}) ثبت شده و {{ $pct($p['mapped_share']) }} آن‌ها به یکی از {{ Fa::num(count(config('insights.copy_places'))) }} شهر شناخته‌شده رسید؛
                نام‌های کهن و گونه‌گون یک شهر (طهران، سپاهان، توس، قسطنطنیه …) یکی شمرده شده‌اند و جاهایی چون «ایران» یا «هندوستان» که شهر نیستند، روی نقشه نیامده‌اند.
                <span x-show="c !== 'all'" x-cloak>در این سده <span x-text="total.toLocaleString('fa-IR')"></span> نسخه‌ی جای‌دار و تاریخ‌دار هست.</span>
            </p>
            </div>
        </x-ui.card>
    </x-ui.section>

    {{-- 3. Most-copied works --}}
    <x-ui.section title="آثار پرنسخه" :count="count($data['top_works']['items'])" id="top-works">
        <x-ui.card class="!p-0 overflow-x-auto">
            @php $maxWork = max(1, $data['top_works']['items'][0]['count'] ?? 1); @endphp
            <table class="w-full text-sm">
                <thead class="text-xs text-muted border-b border-line">
                    <tr>
                        <th scope="col" class="px-3 sm:px-4 py-2.5 text-right font-semibold w-10">#</th>
                        <th scope="col" class="px-3 py-2.5 text-right font-semibold">اثر</th>
                        <th scope="col" class="px-3 sm:px-4 py-2.5 text-left font-semibold w-28 sm:w-56">نسخه</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['top_works']['items'] as $i => $work)
                        <tr class="border-b border-line last:border-0 hover:bg-paper">
                            <td class="px-3 sm:px-4 py-2.5 text-muted tabular-nums align-top">{{ Fa::digits($i + 1) }}</td>
                            <td class="px-3 py-2.5 align-top">
                                <a href="{{ route('works.show', $work['key']) }}" class="font-semibold text-ink hover:text-cinnabar transition">{{ $work['title'] }}</a>
                                @if($work['author'])
                                    <span class="block text-xs text-muted mt-0.5">
                                        @if($work['author_key'])
                                            <a href="{{ route('people.show', $work['author_key']) }}" class="hover:text-cinnabar">{{ $work['author'] }}</a>
                                        @else
                                            {{ $work['author'] }}
                                        @endif
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 sm:px-4 py-2.5 align-top">
                                <div class="flex items-center gap-2 justify-end">
                                    <span class="hidden sm:block flex-1 h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                        <span class="block h-full bg-cinnabar/70 rounded-full" style="width: {{ round($work['count'] * 100 / $maxWork, 1) }}%"></span>
                                    </span>
                                    <a href="{{ route('works.show', $work['key']) }}#manuscripts" class="text-xs tabular-nums text-ink hover:text-cinnabar w-12 text-left">{{ Fa::num($work['count']) }}</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
        @if(! empty($data['top_works']['skipped']))
            <p class="mt-3 text-xs text-muted leading-relaxed">
                رکوردهای بی‌پدیدآوری که عنوانشان نام یک گونه‌ی متن است، نه یک اثر معین، در جدول نیامده‌اند:
                @foreach($data['top_works']['skipped'] as $work)
                    <a href="{{ route('works.show', $work['key']) }}" class="text-ink hover:text-cinnabar underline decoration-line-strong">{{ $work['title'] }}</a> ({{ Fa::num($work['count']) }}){{ $loop->last ? '.' : '،' }}
                @endforeach
            </p>
        @endif
    </x-ui.section>

    {{-- 4. Scripts and languages --}}
    <div class="grid lg:grid-cols-2 gap-8">
        <x-ui.section title="سهم خطوط" id="scripts">
            <x-ui.card>
                <p class="text-xs text-muted mb-3">خط اصلی هر نسخه. روی هر خط بزنید تا نسخه‌های آن خط را ببینید.</p>
                <ul class="space-y-2.5 text-sm">
                    @foreach($s['items'] as $row)
                        <li>
                            <a href="{{ route('search', ['type' => 'manuscripts', 'script_id' => $row['id']]) }}" class="group block">
                                <span class="flex items-baseline gap-2">
                                    <span class="text-ink group-hover:text-cinnabar transition">{{ $row['name'] }}</span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums">{{ Fa::num($row['count']) }} · {{ $pct(round($row['count'] * 100 / max(1, $s['counted']), 1)) }}</span>
                                </span>
                                <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                    <span class="block h-full bg-cinnabar/70 group-hover:bg-cinnabar rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / max(1, $s['counted']), 2) }}%)"></span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                    @if($s['other'] > 0)
                        <li class="text-muted">
                            <span class="flex items-baseline gap-2">
                                <span>سایر ({{ Fa::num($s['other_kinds']) }} گونه)</span>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-xs tabular-nums">{{ Fa::num($s['other']) }} · {{ $pct(round($s['other'] * 100 / max(1, $s['counted']), 1)) }}</span>
                            </span>
                            <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                <span class="block h-full bg-muted/60 rounded-full" style="width: max(2px, {{ round($s['other'] * 100 / max(1, $s['counted']), 2) }}%)"></span>
                            </span>
                        </li>
                    @endif
                </ul>
                <p class="mt-4 text-xs text-muted">بر پایه‌ی {{ Fa::num($s['counted']) }} نسخه، یعنی {{ $pct($s['coverage']) }} نسخه‌ها، که خطشان ثبت شده است.</p>
            </x-ui.card>
        </x-ui.section>

        <x-ui.section title="سهم زبان‌ها" id="languages">
            <x-ui.card>
            <div x-data="{ by: 'works', rows: @js($l['items']), other: @js($l['other']),
                get sum() { return this.rows.reduce((s, r) => s + r[this.by], 0) + this.other[this.by] },
                share(v) { return (Math.round(v * 1000 / Math.max(1, this.sum)) / 10).toLocaleString('fa-IR') + '٪' } }">
                <div class="flex flex-wrap items-center gap-1.5 text-xs mb-3">
                    <span class="text-muted me-1">بر پایه‌ی</span>
                    @foreach(['works' => 'آثار', 'manuscripts' => 'نسخه‌ها'] as $key => $label)
                        <button type="button" @click="by = '{{ $key }}'" :aria-pressed="(by === '{{ $key }}').toString()"
                                :class="by === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                                class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $label }}</button>
                    @endforeach
                </div>
                @php $langSum = array_sum(array_column($l['items'], 'works')) + $l['other']['works']; @endphp
                <ul class="space-y-2.5 text-sm">
                    @foreach($l['items'] as $i => $row)
                        <li>
                            <a href="{{ route('search', ['type' => 'works', 'language_id' => $row['id']]) }}" class="group block">
                                <span class="flex items-baseline gap-2">
                                    <span class="text-ink group-hover:text-cinnabar transition">{{ $row['name'] }}</span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums" x-text="`${rows[{{ $i }}][by].toLocaleString('fa-IR')} · ${share(rows[{{ $i }}][by])}`">{{ Fa::num($row['works']) }} · {{ $pct(round($row['works'] * 100 / max(1, $langSum), 1)) }}</span>
                                </span>
                                <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                    <span class="block h-full bg-gold/80 group-hover:bg-gold rounded-full transition-[width]"
                                          style="width: max(2px, {{ round($row['works'] * 100 / max(1, $langSum), 2) }}%)"
                                          :style="`width: max(2px, ${rows[{{ $i }}][by] * 100 / Math.max(1, sum)}%)`"></span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                    @if($l['other']['works'] > 0)
                        <li class="text-muted">
                            <span class="flex items-baseline gap-2">
                                <span>سایر ({{ Fa::num($l['other']['kinds']) }} زبان)</span>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-xs tabular-nums" x-text="`${other[by].toLocaleString('fa-IR')} · ${share(other[by])}`">{{ Fa::num($l['other']['works']) }}</span>
                            </span>
                        </li>
                    @endif
                </ul>
                <p class="mt-4 text-xs text-muted leading-relaxed">
                    بر پایه‌ی {{ Fa::num($l['with_language']) }} اثر، یعنی {{ $pct($l['coverage']) }} آثار، که زبانشان ثبت شده است.
                    {{ Fa::num($l['multilingual']) }} اثرِ چندزبانه در هر زبانِ خود شمرده شده‌اند، پس جمع سهم‌ها کمی بیش از شمار آثار است.
                    در شمارش «نسخه‌ها» نسخه‌های هر اثر به زبان آن اثر نسبت داده شده است.
                </p>
            </div>
            </x-ui.card>
        </x-ui.section>
    </div>

    <p class="text-xs text-muted text-center">
        این آمار هر شب از روی داده‌های فهارس از نو ساخته می‌شود؛ آخرین بار {{ Jalali::format(\Illuminate\Support\Carbon::parse($data['built_at']), 'long') }}.
    </p>
</div>
@endsection
