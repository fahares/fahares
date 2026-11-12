@extends('layouts.app')

@php $scopeLabels = array_column($scope->labels(), 1); @endphp
@section('title', $scope->isEmpty() ? 'آمار: نسخه‌های خطی در گذر سده‌ها | فهارس' : 'آمار نسخه‌های ' . implode('، ', $scopeLabels) . ' | فهارس')
@section('meta_description', 'خط زمانی کتابت نسخه‌های خطی از سده‌ی چهارم تا چهاردهم هجری، کانون‌های تاریخی استنساخ و تألیف، جابه‌جایی نسخه‌ها، موضوع‌ها و ویژگی‌های نسخه در گذر سده‌ها، آثار پرنسخه و سهم خطوط و زبان‌ها در فهارس')
{{-- A scope is one of countless filter combinations: linked to, not indexed --}}
@unless($scope->isEmpty())
    @section('robots', 'noindex, follow')
@endunless

@section('content')
@php
    use App\Support\Fa;
    use App\Support\Jalali;

    $pct = fn ($v) => Fa::digits(str_replace('.', '٫', (string) $v)) . '٪';
    $t = $data['timeline'];
    $p = $data['places'];
    $s = $data['scripts'];
    $l = $data['languages'];
    $a = $data['authors'];
    $w = $data['compositions'];
    $g = $data['copy_gap'];
    $cp = $p['composition'];
    $mv = $data['movement'];
    // The thin-data rule is for a scope; the whole data is drawn whatever it holds
    $min = $scope->isEmpty() ? 0 : config('insights.min_sample');
    // A link to the search, with the scope's filters the search has; the chart's own parameters come first
    $search = fn (string $type, array $params = []) => route('search', ['type' => $type] + $params + $scope->searchParams($type));
    $lifetime = collect($g['bands'])->firstWhere('upto', 0);
    $maxCentury = max(array_column($t['centuries'], 'count')) ?: 1;
    $peak = collect($t['centuries'])->sortByDesc('count')->first();

    // Names on the map: the largest centres of a layer, skipping one that sits too close to a larger one already named
    $mapLabels = function (string $count) use ($p) {
        $cities = collect($p['cities'])->filter(fn ($c) => $c[$count] > 0)->sortByDesc($count)->values();
        $top = max(1, $cities[0][$count] ?? 1);
        $labels = [];
        foreach ($cities as $city) {
            if (count($labels) >= 14) {
                break;
            }
            $near = collect($labels)->contains(fn ($o) => hypot($o['x'] - $city['x'], $o['y'] - $city['y']) < 34);
            if (! $near) {
                $labels[] = $city + ['gap' => max(3, 34 * sqrt($city[$count] / $top)) + 5];
            }
        }

        return $labels;
    };
    $layers = ['copy' => $mapLabels('count'), 'composition' => $mapLabels('works')];
    $maxPlace = max([1, ...array_column($p['cities'], 'count')]);
    $mapCities = array_map(fn ($c) => ['n' => $c['name'], 'x' => $c['x'], 'y' => $c['y'], 't' => $c['count'], 'c' => (object) $c['centuries'],
        'w' => $c['works'], 'wc' => (object) $c['work_centuries']], $p['cities']);
    $mapCenturies = range(7, config('insights.centuries')[1]);
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-10">

    <header class="text-center grid justify-items-center gap-2">
        <p class="text-xs font-bold text-cinnabar">آمار</p>
        @if($scope->isEmpty())
            <h1 class="font-naskh text-[28px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">نسخه‌ها در گذر سده‌ها</h1>
            <p class="text-sm text-muted max-w-2xl leading-relaxed">
                آنچه {{ Fa::approx($data['totals']['manuscripts']) }} نسخه‌ی خطی و {{ Fa::approx($data['totals']['works']) }} اثرِ ثبت‌شده در فهارس درباره‌ی زمان و جای کتابت، آثار پرنسخه و خطوط و زبان‌ها می‌گوید.
                هر نمودار تنها بر بخشی از نسخه‌ها تکیه دارد که آن آگاهی را دارند؛ سهم هر یک زیر نمودار آمده است.
            </p>
        @else
            <h1 class="font-naskh text-[24px] sm:text-[32px] font-bold leading-[1.6] text-ink text-balance">آمار نسخه‌های {{ implode('، ', $scopeLabels) }}</h1>
            <p class="text-sm text-muted">
                {{ Fa::num($data['totals']['manuscripts']) }} نسخه از {{ Fa::num($data['totals']['works']) }} اثر
                · <a href="{{ route('insights') }}" class="text-cinnabar font-semibold hover:underline">بازگشت به آمار کل</a>
            </p>
            <ul class="flex flex-wrap justify-center gap-2 text-xs" aria-label="فیلترهای این آمار">
                @foreach($scope->labels() as [$param, $label, $link])
                    <li class="inline-flex items-center gap-1 border border-line-strong bg-surface ps-2.5 rounded-full">
                        @if($link)<a href="{{ $link }}" class="text-ink hover:text-cinnabar">{{ $label }}</a>@else<span class="text-ink">{{ $label }}</span>@endif
                        <a href="{{ route('insights', $scope->without($param)) }}" class="px-2 py-0.5 text-muted hover:text-cinnabar" aria-label="برداشتن فیلتر {{ $label }}" title="برداشتن این فیلتر">×</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </header>

    @include('insights.filter-form')

    @if($data['totals']['manuscripts'] === 0 && $data['totals']['works'] === 0)
        <x-ui.card><p class="text-sm text-muted text-center py-6">در این دامنه نسخه یا اثری نیست. فیلترها را کمتر کنید یا <a href="{{ route('insights') }}" class="text-cinnabar hover:underline">به آمار کل</a> برگردید.</p></x-ui.card>
    @else

    <dl class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-line border border-line">
        @foreach([
            ['نسخه‌ی خطی', Fa::num($data['totals']['manuscripts']), 'متعلق به ' . Fa::num($data['totals']['works']) . ' اثر'],
            ['نسخه‌ی تاریخ‌دار', Fa::num($t['shown']), $pct($t['coverage']) . ' نسخه‌ها، با سده‌ای‌ها'],
            ['اوج کتابت', $peak['count'] ? 'سده‌ی ' . Fa::digits($peak['century']) : '—', Fa::num($peak['count']) . ' نسخه'],
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
            @if($t['shown'] < $min)
                @include('insights.thin', ['n' => $t['shown'], 'what' => 'نسخه‌ی تاریخ‌دار'])
            @else
            @include('insights.small', ['n' => $t['shown'], 'what' => 'نسخه‌ی تاریخ‌دار'])
            <div x-data="{ sel: {{ $peak['century'] }}, cs: @js(collect($t['centuries'])->keyBy('century')),
                get decades() { return this.cs[this.sel].decades.map((n, i) => n + this.cs[this.sel].decades_doubtful[i]) } }">
            <p class="text-xs text-muted mb-4">شمار نسخه‌های کتابت‌شده در هر سده‌ی هجری. نسخه‌ای که تنها سده یا بخشی از سده دارد («قرن ۱۳»، «اواخر قرن ۱۲») لایه‌ی خود را دارد و اگر بازه‌اش دو سده را بگیرد، میان آن دو بخش شده است. روی هر سده بزنید تا نسخه‌های آن در جست‌وجو باز شود.</p>

            @include('insights.century-bars', ['rows' => $t['centuries'], 'unit' => 'نسخه', 'label' => 'شمار نسخه‌ها در هر سده',
                'href' => fn ($c) => $search('manuscripts', ['century' => $c])])

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
                        <template x-for="(n, i) in decades" :key="i">
                            <div class="flex-1 h-full flex flex-col justify-end items-center gap-0.5 min-w-0"
                                 :title="`${((sel - 1) * 100 + i * 10 + 1).toLocaleString('fa-IR', {useGrouping: false})} تا ${((sel - 1) * 100 + i * 10 + 10).toLocaleString('fa-IR', {useGrouping: false})}: ${n.toLocaleString('fa-IR')} نسخه` + (cs[sel].decades_doubtful[i] ? `، ${cs[sel].decades_doubtful[i].toLocaleString('fa-IR')} مردد` : '')">
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
                    <p class="mt-2 text-[11px] text-muted">تنها نسخه‌های سال‌دار، چون نسخه‌ای که تنها سده‌اش معلوم است، دهه ندارد.</p>
                    <p class="mt-3 text-xs">
                        <a :href="`{{ $search('manuscripts') }}&century=${sel}`" class="text-cinnabar font-semibold hover:underline">
                            دیدن نسخه‌های سال‌دار سده‌ی <span x-text="sel.toLocaleString('fa-IR')"></span> در جست‌وجو ←
                        </a>
                    </p>
                </div>
            </div>

            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایه‌ی {{ Fa::num($t['shown']) }} نسخه، یعنی {{ $pct($t['coverage']) }} نسخه‌ها، که تاریخ کتابتشان ثبت شده است:
                {{ Fa::num($t['exact']) }} با سال معین و {{ Fa::num($t['range']) }} تنها با سده یا بخشی از آن.
                @include('insights.doubtful-note', ['layers' => $t, 'unit' => 'نسخه', 'what' => 'تاریخ کتابتشان'])
                @if($t['outside'] > 0){{ Fa::num($t['outside']) }} نسخه‌ی بیرون از سده‌های {{ Fa::digits(config('insights.centuries')[0]) }} تا {{ Fa::digits(config('insights.centuries')[1]) }} در نمودار نیامده است.@endif
                @if($t['open'] > 0){{ Fa::num($t['open']) }} نسخه با بازه‌ی باز («پیش از ۱۱۰۰»، «پس از ۱۲۰۰») جدا شمرده شده و در نمودار نیامده است.@endif
            </p>
            </div>
            @endif
        </x-ui.card>
    </x-ui.section>

    {{-- 1b. Authors by death and works by composition --}}
    <x-ui.section title="پدیدآورندگان و آثار در گذر سده‌ها" id="authors">
        <x-ui.card>
            @if($a['counted'] < $min && $w['counted'] < $min)
                @include('insights.thin', ['n' => max($a['counted'], $w['counted']), 'what' => 'مؤلف یا اثرِ تاریخ‌دار'])
            @else
            <div x-data="{ by: '{{ $a['counted'] >= $min ? 'authors' : 'works' }}' }">
            <div class="flex flex-wrap items-center gap-1.5 text-xs mb-4">
                <span class="text-muted me-1">بر پایه‌ی</span>
                @foreach(['authors' => 'سال وفات مؤلفان', 'works' => 'سال تألیف آثار'] as $key => $name)
                    <button type="button" @click="by = '{{ $key }}'" :aria-pressed="(by === '{{ $key }}').toString()"
                            :class="by === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                            class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
                @endforeach
            </div>

            <div x-show="by === 'authors'" @if($a['counted'] < $min) x-cloak @endif>
                @include('insights.small', ['n' => $a['counted'], 'what' => 'مؤلفِ تاریخ‌دار'])
                <p class="text-xs text-muted mb-4">شمار مؤلفان (کسانی که اثری از آنان در فهارس هست) در هر سده‌ی هجری بر پایه‌ی سال وفات. وفاتی که تنها سده‌اش ثبت شده («ق۱۳»)، لایه‌ی خود را دارد.</p>
                @include('insights.century-bars', ['rows' => $a['centuries'], 'unit' => 'مؤلف', 'label' => 'شمار مؤلفان در هر سده',
                    'href' => fn ($c) => $search('authors', ['role' => 'author', 'century' => $c])])
                <p class="mt-5 text-xs text-muted leading-relaxed">
                    بر پایه‌ی {{ Fa::num($a['counted']) }} مؤلف، یعنی {{ $pct($a['coverage']) }} از {{ Fa::num($a['total']) }} مؤلف، که تاریخ وفاتشان ثبت شده است:
                    {{ Fa::num($a['exact']) }} با سال معین و {{ Fa::num($a['range']) }} تنها با سده.
                    @include('insights.doubtful-note', ['layers' => $a, 'unit' => 'مؤلف', 'what' => 'سال وفاتشان'])
                </p>
            </div>

            <div x-show="by === 'works'" @if($a['counted'] >= $min) x-cloak @endif>
                @include('insights.small', ['n' => $w['counted'], 'what' => 'اثرِ تاریخ‌دار'])
                <p class="text-xs text-muted mb-4">شمار آثار در هر سده‌ی هجری بر پایه‌ی تاریخ تألیف، آن‌جا که فهرست آن را آورده است.</p>
                @include('insights.century-bars', ['rows' => $w['centuries'], 'unit' => 'اثر', 'label' => 'شمار آثار در هر سده',
                    'href' => fn ($c) => $search('works', ['century' => $c])])
                <p class="mt-5 text-xs text-muted leading-relaxed">
                    بر پایه‌ی {{ Fa::num($w['counted']) }} اثر، یعنی تنها {{ $pct($w['coverage']) }} آثار، که تاریخ تألیفشان ثبت شده است.
                    @include('insights.doubtful-note', ['layers' => $w, 'unit' => 'اثر', 'what' => 'تاریخ تألیفشان'])
                    @if($w['open'] > 0){{ Fa::num($w['open']) }} اثر با بازه‌ی باز («پیش از …») جدا شمرده شده است.@endif
                    جست‌وجوی هر سده، آثاری را هم که تاریخ تألیف ندارند به سده‌ی وفات مؤلفشان می‌آورد.
                </p>
            </div>
            </div>
            @endif
        </x-ui.card>
    </x-ui.section>

    {{-- 1c. Years from the author's death to the copy --}}
    <x-ui.section title="فاصله‌ی کتابت تا وفات مؤلف" id="copy-gap">
        <x-ui.card>
            @if($g['placed'] < $min)
                @include('insights.thin', ['n' => $g['placed'], 'what' => 'نسخه با تاریخ کتابت و وفات مؤلف'])
            @else
            @include('insights.small', ['n' => $g['placed'], 'what' => 'نسخه با تاریخ کتابت و وفات مؤلف'])
            @php $maxBand = max(1, ...array_column($g['bands'], 'count')); @endphp
            <p class="text-xs text-muted mb-4">
                چند سال پس از وفات مؤلف از اثرش نسخه برداشته‌اند.
                @if($lifetime && $g['placed'])
                    {{ $pct(round($lifetime['count'] * 100 / $g['placed'], 1)) }} نسخه‌ها در زمان حیات مؤلف کتابت شده‌اند.
                @endif
            </p>
            <ul class="space-y-2.5 text-sm">
                @foreach($g['bands'] as $band)
                    @php
                        $name = match (true) {
                            $band['above'] === null => 'بیش از ' . Fa::digits(-$band['upto']) . ' سال پیش از وفات',
                            $band['upto'] === 0 => 'در زمان حیات مؤلف',
                            $band['upto'] === null => 'بیش از ' . Fa::digits($band['above']) . ' سال پس از وفات',
                            default => Fa::digits($band['above'] + 1) . ' تا ' . Fa::digits($band['upto']) . ' سال پس از وفات',
                        };
                        $odd = $band['above'] === null;
                    @endphp
                    <li @class(['text-muted' => $odd])>
                        <span class="flex items-baseline gap-2">
                            <span @class(['text-ink' => ! $odd])>{{ $name }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums">{{ Fa::num($band['count']) }} · {{ $pct(round($band['count'] * 100 / max(1, $g['placed']), 1)) }}</span>
                        </span>
                        <span class="mt-1 flex h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full {{ $odd ? 'bg-muted/60' : 'bg-cinnabar/75' }}" style="width: {{ round($band['certain'] * 100 / $maxBand, 2) }}%"></span>
                            <span class="block h-full {{ $odd ? 'bg-muted/25' : 'bg-cinnabar/30' }}" style="width: {{ round($band['doubtful'] * 100 / $maxBand, 2) }}%"></span>
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایه‌ی {{ Fa::num($g['placed']) }} نسخه که هم تاریخ کتابتشان و هم وفات مؤلفشان ثبت شده است.
                سال معین به همان سال و سده به همه‌ی سال‌هایش گرفته شده است. {{ Fa::num($g['unplaced']) }} نسخه‌ی دیگر که فاصله‌شان با این حساب در یک ردیف نمی‌گنجد (مانند نسخه‌ی «قرن ۱۳» از مؤلفِ درگذشته‌ی ۱۲۵۰)، جدا شمرده شده‌اند.
                {{ Fa::num($g['doubtful']) }} نسخه ({{ $pct($g['doubtful_share']) }}) تاریخ کتابت یا وفات مردد دارند و کم‌رنگ آمده‌اند.
                ردیف نخست، کتابتِ بیش از {{ Fa::digits(-config('insights.copy_gap')[0]) }} سال پیش از وفات، نشان خطایی در تاریخ یا در نسبت اثر به مؤلف است.
            </p>
            @endif
        </x-ui.card>
    </x-ui.section>

    {{-- 2. Centres of copying and of composition --}}
    <x-ui.section title="کانون‌های تاریخی استنساخ و تألیف" id="places">
        <x-ui.card>
            @if($p['mapped'] < $min && $cp['mapped'] < $min)
                @include('insights.thin', ['n' => max($p['mapped'], $cp['mapped']), 'what' => 'نسخه یا اثر با جای شناخته'])
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
                @foreach(['copy' => 'جای کتابت', 'composition' => 'جای تألیف', 'both' => 'هر دو'] as $key => $name)
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
                         aria-label="نقشه‌ی کانون‌های استنساخ و تألیف؛ اندازه‌ی هر دایره شمار نسخه‌های کتابت‌شده یا آثار تألیف‌شده در آن شهر است">
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
                        <li class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-full bg-cinnabar/55" aria-hidden="true"></span>جای کتابت (نسخه)</li>
                        <li class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-full border-2 border-gold" aria-hidden="true"></span>جای تألیف (اثر)</li>
                        <li>اندازه‌ی دایره‌های هر لایه جدا سنجیده شده است.</li>
                    </ul>
                </div>

                <div>
                    <p class="text-xs text-muted mb-2">
                        <span x-text="layer === 'composition' ? 'شهرهای تألیف' : 'پرکارترین شهرها'"></span>
                        <span x-show="c !== 'all'" x-cloak>در سده‌ی <span x-text="Number(c).toLocaleString('fa-IR')"></span></span>
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
                    <p x-show="ranked.length === 0" x-cloak class="text-xs text-muted py-4">برای این سده جایی ثبت نشده است.</p>
                </div>
            </div>

            <p x-show="layer !== 'composition'" class="mt-5 text-xs text-muted leading-relaxed">
                جای کتابت: بر پایه‌ی {{ Fa::num($p['mapped']) }} نسخه، یعنی تنها {{ $pct($p['coverage']) }} نسخه‌ها.
                جای کتابت برای {{ Fa::num($p['with_place']) }} نسخه ({{ $pct($p['place_coverage']) }}) ثبت شده و {{ $pct($p['mapped_share']) }} آن‌ها به یکی از {{ Fa::num(count(config('insights.copy_places'))) }} شهر شناخته‌شده رسید؛
                نام‌های کهن و گونه‌گون یک شهر (طهران، سپاهان، توس، قسطنطنیه …) یکی شمرده شده‌اند و جاهایی چون «ایران» یا «هندوستان» که شهر نیستند، روی نقشه نیامده‌اند.
                سده‌ی هر نسخه، سده‌ای است که کل بازه‌ی تاریخش در آن است. از نسخه‌های روی نقشه، سده‌ی {{ Fa::num($p['by_century']) }} نسخه روشن است و {{ Fa::num($p['century_only']) }} نسخه از آن‌ها تنها سده یا بخشی از سده دارند («قرن ۱۱»، «اواخر قرن ۱۲»).
                نسخه‌ای که بازه‌اش از دو سده می‌گذرد یا تاریخ ندارد، تنها در «همه» شمرده می‌شود.
                <span x-show="c !== 'all' && layer === 'copy'" x-cloak>در این سده <span x-text="total.toLocaleString('fa-IR')"></span> نسخه‌ی جای‌دار و تاریخ‌دار هست.</span>
            </p>
            <p x-show="layer !== 'copy'" x-cloak class="mt-3 text-xs text-muted leading-relaxed">
                جای تألیف: تنها {{ Fa::num($cp['with_place']) }} اثر، یعنی {{ $pct($cp['place_coverage']) }} آثار، جای تألیف دارند و {{ Fa::num($cp['mapped']) }} اثر از آن‌ها ({{ $pct($cp['mapped_share']) }}) به یکی از همین شهرها رسید.
                پس این لایه بر بخش بسیار کوچکی از آثار تکیه دارد. گونه‌های نام یک شهر («مشهد رضوی»، «مشهد مقدس») یکی شمرده شده‌اند. سده‌ی هر اثر، سده‌ای است که کل بازه‌ی تاریخ تألیفش در آن است؛ از آثار روی نقشه، سده‌ی {{ Fa::num($cp['by_century']) }} اثر روشن است.
                جست‌وجو فیلتر جای تألیف ندارد، پس شهرهای این لایه پیوند ندارند.
            </p>
            </div>
            @endif
        </x-ui.card>
    </x-ui.section>

    {{-- 2b. From the place of copying to the present library --}}
    <x-ui.section title="از جای کتابت تا کتابخانه‌ی امروز" id="movement">
        <x-ui.card>
            @if($mv['mapped'] < $min)
                @include('insights.thin', ['n' => $mv['mapped'], 'what' => 'نسخه با جای کتابت شناخته'])
            @else
            @include('insights.small', ['n' => $mv['mapped'], 'what' => 'نسخه با جای کتابت شناخته'])
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
                @foreach(['from' => 'از جای کتابت', 'to' => 'به شهر امروز'] as $key => $name)
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
                    <span x-text="dir === 'from' ? `${city.total.toLocaleString('fa-IR')} نسخه‌ی کتابت‌شده در ${city.name}، امروز در این شهرها:` : `${city.total.toLocaleString('fa-IR')} نسخه‌ی جای‌دارِ کتابخانه‌های ${city.name}، کتابت‌شده در این شهرها:`"></span>
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
                بر پایه‌ی {{ Fa::num($mv['mapped']) }} نسخه که جای کتابتشان به یکی از شهرهای نقشه رسید و کتابخانه‌شان شهر دارد.
                {{ $pct($mv['stayed_share']) }} آن‌ها امروز در همان شهری‌اند که کتابت شده‌اند.
                شهر امروز، شهر کتابخانه‌ی دارنده‌ی نسخه است.
            </p>
            </div>
            @endif
        </x-ui.card>
    </x-ui.section>

    {{-- 2c. Subjects across the centuries --}}
    <x-ui.section title="موضوع‌ها در گذر سده‌ها" id="subjects">
        <x-ui.card>
            @php $sg = $data['subjects']; @endphp
            @if($sg['counted'] < $min || count($sg['rows']) < 2)
                @include('insights.thin', ['n' => $sg['counted'], 'what' => count($sg['rows']) < 2 && $sg['counted'] >= $min ? 'موضوع جدا' : 'نسخه‌ی موضوع‌دار و تاریخ‌دار'])
            @else
                @include('insights.small', ['n' => $sg['counted'], 'what' => 'نسخه‌ی موضوع‌دار و تاریخ‌دار'])
                <p class="text-xs text-muted mb-4">
                    نسخه‌های هر {{ $sg['children'] ? 'زیرموضوع' : 'موضوع اصلی' }} در هر سده: شمارشان، یا سهمشان از نسخه‌های آن سده (برای نمونه، از نسخه‌های سده‌ی ۱۱ چند درصد فقه است). روی هر خانه بزنید تا نسخه‌های آن در جست‌وجو باز شود.
                </p>
                @include('insights.century-grid', ['grid' => $sg, 'label' => 'سهم موضوع‌ها در نسخه‌های هر سده',
                    'href' => fn ($id, $c) => $search('manuscripts', ['subject_id' => $id, 'century' => $c])])
                <p class="mt-4 text-xs text-muted leading-relaxed">
                    بر پایه‌ی {{ Fa::num($sg['counted']) }} نسخه که اثرشان موضوع دارد و تاریخ کتابتشان در یک سده است؛ نسخه‌ای که بازه‌ی تاریخش دو سده را می‌گیرد در این جدول نیامده است.
                    {{ Fa::num(count($sg['rows'])) }} {{ $sg['children'] ? 'زیرموضوع' : 'موضوع' }} پرنسخه از {{ Fa::num($sg['kinds']) }} آمده است.
                    اثری که چند موضوع دارد در هر موضوعش شمرده شده است، پس جمع یک ستون می‌تواند از ۱۰۰ بیشتر شود.
                </p>
            @endif
        </x-ui.card>
    </x-ui.section>

    {{-- 2d. Features of the copies across the centuries --}}
    <x-ui.section title="ویژگی‌های نسخه‌ها در گذر سده‌ها" id="features">
        <x-ui.card>
            @php $fg = $data['features']; @endphp
            @if($fg['counted'] < $min)
                @include('insights.thin', ['n' => $fg['counted'], 'what' => 'نسخه‌ی تاریخ‌دار'])
            @else
                @include('insights.small', ['n' => $fg['counted'], 'what' => 'نسخه‌ی تاریخ‌دار'])
                <p class="text-xs text-muted mb-4">نسخه‌های مذهّب، تصحیح‌شده، مقابله‌شده، حاشیه‌دار و به خط مؤلف در هر سده: شمارشان، یا سهمشان از نسخه‌های آن سده.</p>
                @include('insights.century-grid', ['grid' => $fg, 'label' => 'سهم ویژگی‌های نسخه در هر سده',
                    'href' => fn ($flag, $c) => $search('manuscripts', ['flags' => [$flag], 'century' => $c])])
                <p class="mt-4 text-xs text-muted leading-relaxed">
                    بر پایه‌ی {{ Fa::num($fg['counted']) }} نسخه که تاریخ کتابتشان در یک سده است.
                    این ویژگی‌ها همان‌اند که فهرست‌نگار یاد کرده است؛ یاد نشدن یک ویژگی همیشه به معنای نبودن آن نیست، و فهرست‌ها در یاد کردن آن یکسان نیستند.
                </p>
            @endif
        </x-ui.card>
    </x-ui.section>

    {{-- 2e. Scribes and libraries --}}
    @php
        $sb = $data['scribes'];
        $lb = $data['libraries'];
        // Known years, or else the centuries of the copies dated by their century alone
        $years = fn (array $row) => $row['from'] !== null
            ? ($row['from'] === $row['to'] ? Fa::digits($row['from']) : Fa::digits($row['from']) . ' تا ' . Fa::digits($row['to']))
            : ($row['centuries'] ? ($row['centuries'][0] === $row['centuries'][1] ? 'سده‌ی ' . Fa::digits($row['centuries'][0]) : 'سده‌های ' . Fa::digits($row['centuries'][0]) . ' تا ' . Fa::digits($row['centuries'][1])) : '');
    @endphp
    <div class="grid lg:grid-cols-2 gap-8">
        @if(! $scope->get('scribe') && count($sb['items']) > 1)
        <x-ui.section title="کاتبان پرکار" id="scribes">
            <x-ui.card class="!p-0 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-muted border-b border-line">
                        <tr>
                            <th scope="col" class="px-3 py-2.5 text-right font-semibold">کاتب</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-semibold">سال‌ها و شهر</th>
                            <th scope="col" class="px-3 py-2.5 text-left font-semibold">نسخه</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sb['items'] as $row)
                            <tr class="border-b border-line last:border-0 hover:bg-paper">
                                <td class="px-3 py-2 align-top"><a href="{{ route('people.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a></td>
                                <td class="px-3 py-2 align-top text-xs text-muted tabular-nums">{{ $years($row) }}@if($years($row) !== '' && $row['city']) · @endif{{ $row['city'] }}</td>
                                <td class="px-3 py-2 align-top text-left"><a href="{{ $search('manuscripts', ['scribe_id' => $row['id']]) }}" class="text-xs tabular-nums text-ink hover:text-cinnabar">{{ Fa::num($row['count']) }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
            <p class="mt-3 text-xs text-muted leading-relaxed">
                کاتب شناخته (پیوندشده به یک شخص) برای {{ Fa::num($sb['with_scribe']) }} نسخه از {{ Fa::num($sb['scribes']) }} کاتب ثبت است.
                سال‌ها، نخستین و واپسین سال نسخه‌های سال‌دار هر کاتب است (تاریخی که تا {{ Fa::num(config('insights.exact_span')) }} سال دامنه دارد، با میانه‌اش). کاتبی که نسخه‌ی سال‌دار ندارد، سده‌ی نسخه‌هایی را دارد که تنها سده‌شان آمده است.
                شهر، شهری است که بیشترین نسخه‌ی جای‌دارش در آن کتابت شده است.
            </p>
        </x-ui.section>
        @endif

        @if(! $scope->get('library') && count($lb['largest']) > 1)
        <x-ui.section title="کتابخانه‌ها" id="libraries">
            <x-ui.card>
                <div x-data="{ by: 'largest' }">
                <div class="flex flex-wrap items-center gap-1.5 text-xs mb-3">
                    @foreach(['largest' => 'بزرگ‌ترین گنجینه‌ها', 'oldest' => 'کهن‌ترین گنجینه‌ها'] as $key => $name)
                        @if($key === 'largest' || $lb['oldest'])
                            <button type="button" @click="by = '{{ $key }}'" :aria-pressed="(by === '{{ $key }}').toString()"
                                    :class="by === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                                    class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
                        @endif
                    @endforeach
                </div>
                @foreach(['largest', 'oldest'] as $list)
                    @php $maxLib = max([1, ...array_column($lb['largest'], 'count')]); @endphp
                    <ol class="text-sm" x-show="by === '{{ $list }}'" @if($list === 'oldest') x-cloak @endif>
                        @foreach($lb[$list] as $row)
                            <li class="py-1.5 border-b border-line last:border-0">
                                <span class="flex items-baseline gap-2">
                                    <a href="{{ route('libraries.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                                    <span class="text-xs text-muted">{{ $row['city'] }}</span>
                                    <span class="leader" aria-hidden="true"></span>
                                    @if($list === 'oldest')
                                        <span class="text-xs text-muted tabular-nums" title="میانگین سال کتابت {{ Fa::num($row['dated']) }} نسخه‌ی تاریخ‌دار{{ $row['century_only'] ? '، از جمله ' . Fa::num($row['century_only']) . ' نسخه که تنها سده یا بخشی از سده دارند' : '' }}">میانگین {{ Fa::digits($row['mean']) }}ق</span>
                                    @else
                                        <a href="{{ $search('manuscripts', ['library_id' => $row['id']]) }}" class="text-xs text-muted tabular-nums hover:text-cinnabar">{{ Fa::num($row['count']) }}</a>
                                    @endif
                                    <a href="{{ route('insights', ['library' => $row['id']] + $scope->query()) }}" class="text-[11px] text-cinnabar hover:underline" title="آمار نسخه‌های این کتابخانه{{ $scope->isEmpty() ? '' : ' در همین دامنه' }}">آمار</a>
                                </span>
                                @if($list === 'largest')
                                    <span class="mt-1 block h-1 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                        <span class="block h-full bg-cinnabar/60 rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / $maxLib, 2) }}%)"></span>
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endforeach
                <p class="mt-4 text-xs text-muted leading-relaxed">
                    {{ Fa::num($lb['count']) }} کتابخانه در این آمار نسخه دارند.
                    «کهن‌ترین» بر پایه‌ی میانگین سال کتابت نسخه‌های تاریخ‌دار هر کتابخانه است و تنها کتابخانه‌هایی که دست‌کم {{ Fa::num($lb['min_dated']) }} نسخه‌ی تاریخ‌دار دارند در آن آمده‌اند.
                    نسخه‌ای که تاریخش تا یک سده دامنه دارد («قرن ۱۱») با میانه‌ی بازه‌اش (۱۰۵۰) شمرده می‌شود. بازه‌های بازتر و تاریخ‌های باز («پیش از ۱۱۰۰») کنار می‌مانند.
                </p>
                </div>
            </x-ui.card>
        </x-ui.section>
        @endif
    </div>

    {{-- 2e1. Dedications and works written at someone's request (step 14.119) --}}
    @php
        $dd = $data['dedications'] ?? null;
        $ddCols = $dd ? array_values(array_filter($dd['centuries'], fn ($c) => $c['works'] >= config('insights.min_sample') * 10)) : [];
        $ddMax = max([0.1, ...array_column($ddCols, 'share')]);
        $ddCenturies = fn (?array $c) => $c === null ? '' : ($c[0] === $c[1] ? 'سده‌ی ' . Fa::digits($c[0]) : 'سده‌های ' . Fa::digits($c[0]) . ' تا ' . Fa::digits($c[1]));
    @endphp
    @if($dd && $dd['works'] >= max(1, $min))
    <x-ui.section title="تقدیم و سفارش تألیف" id="dedications">
        <x-ui.card>
            <p class="text-sm text-ink mb-3">کسانی که اثری به نامشان یا به درخواستشان نوشته شده است.</p>
            @if(count($dd['items']))
                <ol class="text-sm lg:columns-2 lg:gap-8">
                    @foreach($dd['items'] as $row)
                        <li class="py-1.5 border-b border-line flex items-baseline gap-2 break-inside-avoid">
                            @if($row['person'])
                                <a href="{{ route('people.show', $row['person']) }}?tab=role-{{ $row['asked'] * 2 > $row['count'] ? 'commissioner' : 'dedicatee' }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                            @else
                                <span class="text-ink">{{ $row['name'] }}</span>
                            @endif
                            @if($row['centuries'])<span class="text-[11px] text-muted whitespace-nowrap">{{ $ddCenturies($row['centuries']) }}</span>@endif
                            <span class="leader" aria-hidden="true"></span>
                            @if($row['asked'])<span class="text-[11px] text-muted tabular-nums" title="آثاری که به درخواست او نوشته شده">{{ Fa::num($row['asked']) }} به درخواست</span>@endif
                            @if($row['person'])
                                <span class="text-xs text-muted tabular-nums">{{ Fa::num($row['count']) }}</span>
                            @else
                                <a href="{{ $search('works', ['f' => ['people' => $row['name']]]) }}" class="text-xs text-muted tabular-nums hover:text-cinnabar"
                                   title="جست‌وجوی این نام در کسان مرتبط با آثار">{{ Fa::num($row['count']) }}</a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
            @if(count($ddCols) > 2)
                <p class="mt-5 mb-2 text-xs font-semibold text-ink">سهم آثار تقدیم‌شده یا سفارشی در هر سده‌ی تألیف</p>
                <div class="flex items-end gap-1 sm:gap-2 h-28 border-b border-line-strong" role="list" aria-label="سهم آثار تقدیم‌شده یا سفارشی در هر سده‌ی تألیف">
                    @foreach($ddCols as $col)
                        @php $tip = 'سده‌ی ' . Fa::digits($col['century']) . ': ' . Fa::num($col['count']) . ' از ' . Fa::num($col['works']) . ' اثر (' . $pct($col['share']) . ')'; @endphp
                        <span role="listitem" class="flex-1 h-full flex flex-col justify-end items-center gap-1 min-w-0" title="{{ $tip }}" aria-label="{{ $tip }}">
                            <span class="text-[10px] text-muted tabular-nums whitespace-nowrap">{{ $pct($col['share']) }}</span>
                            <span class="w-full max-w-10 bg-gold/70 rounded-t-[4px]" style="height: max({{ $col['count'] ? 2 : 0 }}px, {{ round($col['share'] * 100 / $ddMax, 2) }}%)"></span>
                        </span>
                    @endforeach
                </div>
                <div class="flex gap-1 sm:gap-2 mt-1.5" aria-hidden="true">
                    @foreach($ddCols as $col)
                        <span class="flex-1 text-center text-[10px] text-ink tabular-nums">{{ Fa::digits($col['century']) }}</span>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
        <p class="mt-3 text-xs text-muted leading-relaxed">
            {{ Fa::num($dd['works']) }} اثر ({{ $pct($dd['coverage']) }} آثار این آمار) تقدیم‌نامه یا سفارش‌دهنده دارند، با {{ Fa::num($dd['names']) }} نام. {{ Fa::num($dd['linked']) }} نامِ پیوندخورده به رکورد شخص، {{ Fa::num($dd['linked_works']) }} اثر از این آثار را دربر دارند.
            این داده از ستون «اهدا / به درخواست» فنخاست و با سفارش‌دهنده‌ی کتابت یک نسخه (بخش «سفارش‌دهندگان») فرق دارد.
            نام‌های پیوندخورده با همه‌ی صورت‌هایشان یک‌جا شمرده می‌شوند و به صفحه‌ی شخص می‌روند؛ نام‌های بی‌پیوند با صورت نوشتاری‌شان.
            سده‌ها، سده‌ی آثاری است که کل بازه‌ی تاریخ تألیفشان در یک سده است؛ سده‌هایی که کمتر از {{ Fa::num(config('insights.min_sample') * 10) }} اثر دارند، در نمودار نیامده‌اند.
        </p>
    </x-ui.section>
    @endif

    {{-- 2e2. Patrons and facsimile copies (step 14.117) --}}
    @php
        $pt = $data['patrons'];
        $fx = $data['facsimiles'];
        $centuriesOf = fn (?array $c) => $c === null ? '' : ($c[0] === $c[1] ? 'سده‌ی ' . Fa::digits($c[0]) : 'سده‌های ' . Fa::digits($c[0]) . ' تا ' . Fa::digits($c[1]));
        $ptCols = array_values(array_filter($pt['centuries'], fn ($c) => $c['copies'] >= config('insights.min_sample') * 10));
        $ptMax = max([0.1, ...array_column($ptCols, 'share')]);
        $showFacsimiles = $scope->get('facsimile') === null && $fx['count'] >= max(1, $min);
    @endphp
    <div class="grid lg:grid-cols-2 gap-8">
        @if($pt['with_patron'] >= max(1, $min))
        <x-ui.section title="سفارش‌دهندگان" id="patrons">
            <x-ui.card>
                @if(count($pt['items']))
                    <ol class="text-sm">
                        @foreach($pt['items'] as $row)
                            <li class="py-1.5 border-b border-line last:border-0 flex items-baseline gap-2">
                                @if($row['person'] ?? null)
                                    <a href="{{ route('people.show', $row['person']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                                @else
                                    <span class="text-ink">{{ $row['name'] }}</span>
                                @endif
                                @if($row['centuries'])<span class="text-[11px] text-muted whitespace-nowrap">{{ $centuriesOf($row['centuries']) }}</span>@endif
                                <span class="leader" aria-hidden="true"></span>
                                @if($row['person'] ?? null)
                                    <span class="text-xs text-muted tabular-nums">{{ Fa::num($row['count']) }}</span>
                                @else
                                    <a href="{{ $search('manuscripts', ['f' => ['commissioned' => $row['name']]]) }}" class="text-xs text-muted tabular-nums hover:text-cinnabar"
                                       title="جست‌وجوی این نام در سفارش‌دهنده‌ی نسخه‌ها">{{ Fa::num($row['count']) }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
                @if(count($ptCols) > 2)
                    <p class="mt-5 mb-2 text-xs font-semibold text-ink">سهم نسخه‌های دارای سفارش‌دهنده در هر سده</p>
                    <div class="flex items-end gap-1 sm:gap-2 h-28 border-b border-line-strong" role="list" aria-label="سهم نسخه‌های دارای سفارش‌دهنده در هر سده">
                        @foreach($ptCols as $col)
                            @php $tip = 'سده‌ی ' . Fa::digits($col['century']) . ': ' . Fa::num($col['count']) . ' از ' . Fa::num($col['copies']) . ' نسخه (' . $pct($col['share']) . ')'; @endphp
                            <span role="listitem" class="flex-1 h-full flex flex-col justify-end items-center gap-1 min-w-0" title="{{ $tip }}" aria-label="{{ $tip }}">
                                <span class="text-[10px] text-muted tabular-nums whitespace-nowrap">{{ $pct($col['share']) }}</span>
                                <span class="w-full max-w-10 bg-gold/70 rounded-t-[4px]" style="height: max({{ $col['count'] ? 2 : 0 }}px, {{ round($col['share'] * 100 / $ptMax, 2) }}%)"></span>
                            </span>
                        @endforeach
                    </div>
                    <div class="flex gap-1 sm:gap-2 mt-1.5" aria-hidden="true">
                        @foreach($ptCols as $col)
                            <span class="flex-1 text-center text-[10px] text-ink tabular-nums">{{ Fa::digits($col['century']) }}</span>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
            <p class="mt-3 text-xs text-muted leading-relaxed">
                سفارش‌دهنده برای {{ Fa::num($pt['with_patron']) }} نسخه ({{ $pct($pt['coverage']) }}) ثبت است، با {{ Fa::num($pt['names']) }} نام گوناگون.
                سفارش‌دهنده‌ی {{ Fa::num($pt['linked'] ?? 0) }} نسخه به رکورد شخص پیوند خورده است: پادشاهان و صاحبان لقب‌هایی که سال نسخه نشان می‌دهد کیست («ظل السلطان»، «آیة الله بروجردی»). این نام‌ها با همه‌ی صورت‌هایشان یک‌جا شمرده می‌شوند و به صفحه‌ی شخص می‌روند. نام‌های دیگر با صورت نوشتاری‌شان شمرده می‌شوند و جست‌وجوی هر یک نسخه‌های هم‌نام را هم می‌آورد.
                @if($pt['kin']) {{ Fa::num($pt['kin']) }} نسخه که سفارش‌دهنده‌شان «مؤلف»، «خودش» یا یکی از خویشان کاتب («پدرش …») است، در فهرست نام‌ها نیامده‌اند. @endif
                سده‌ها، سده‌ی نسخه‌هایی است که کل بازه‌ی تاریخشان در یک سده است؛ سده‌هایی که کمتر از {{ Fa::num(config('insights.min_sample') * 10) }} نسخه دارند، در نمودار نیامده‌اند.
            </p>
        </x-ui.section>
        @endif

        @if($showFacsimiles)
        <x-ui.section title="نسخه‌های عکسی" id="facsimiles">
            <x-ui.card>
                <p class="text-sm text-ink">
                    <a href="{{ $search('manuscripts', ['facsimile' => '1']) }}" class="font-semibold tabular-nums hover:text-cinnabar">{{ Fa::num($fx['count']) }}</a>
                    نسخه، یعنی {{ $pct($fx['share']) }} نسخه‌های این آمار، عکسی‌اند.
                </p>
                @if(count($fx['libraries']) > 1)
                    <ol class="mt-3 text-sm">
                        @foreach($fx['libraries'] as $row)
                            <li class="py-1.5 border-b border-line last:border-0 flex items-baseline gap-2">
                                <a href="{{ route('libraries.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                                <span class="text-xs text-muted">{{ $row['city'] }}</span>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-[11px] text-muted tabular-nums" title="سهم عکسی‌ها در نسخه‌های این کتابخانه">{{ $pct($row['share']) }}</span>
                                <a href="{{ $search('manuscripts', ['facsimile' => '1', 'library_id' => $row['id']]) }}" class="text-xs text-muted tabular-nums hover:text-cinnabar">{{ Fa::num($row['count']) }}</a>
                            </li>
                        @endforeach
                    </ol>
                @endif
                <p class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <a href="{{ route('insights', $scope->query() + ['facsimile' => '0']) }}" class="text-cinnabar hover:underline">آمار بی نسخه‌های عکسی</a>
                    <a href="{{ route('insights', $scope->query() + ['facsimile' => '1']) }}" class="text-cinnabar hover:underline">آمار تنها نسخه‌های عکسی</a>
                </p>
            </x-ui.card>
            <p class="mt-3 text-xs text-muted leading-relaxed">
                نسخه‌ی عکسی، عکس نسخه‌ای است که در کتابخانه‌ی دیگری نگه داشته می‌شود، و تاریخ و جای کتابتش همان نسخه‌ی اصل است.
                پس نمودارهای این صفحه آن نسخه را، اگر اصلش هم در فهارس باشد، دو بار می‌شمارند. «آمار بی نسخه‌های عکسی» این دوباره‌شماری را کنار می‌گذارد.
            </p>
        </x-ui.section>
        @endif
    </div>

    {{-- 2f. Size of the copies --}}
    @php $sz = $data['sizes']; @endphp
    <x-ui.section title="اندازه‌ی نسخه‌ها" id="sizes">
        <x-ui.card>
            @if($sz['with_folios'] < $min)
                @include('insights.thin', ['n' => $sz['with_folios'], 'what' => 'نسخه با شمار برگ'])
            @else
                @include('insights.small', ['n' => $sz['with_folios'], 'what' => 'نسخه با شمار برگ'])
                @php
                    $maxBand = max([1, ...array_column($sz['bands'], 'count')]);
                    $folioCols = array_filter($sz['centuries'], fn ($c) => $c['with_folios'] >= config('insights.min_sample'));
                    $maxFolios = max([1, ...array_column($folioCols, 'folios')]);
                @endphp
                <div class="grid lg:grid-cols-2 gap-8">
                    <div>
                        <p class="text-xs text-muted mb-3">شمار نسخه‌ها در هر اندازه، به برگ. میانه‌ی شمار برگ {{ Fa::num($sz['median']) }} برگ است.</p>
                        <ul class="space-y-2.5 text-sm">
                            @foreach($sz['bands'] as $band)
                                <li>
                                    <span class="flex items-baseline gap-2">
                                        <span class="text-ink">{{ $band['upto'] === null ? 'بیش از ' . Fa::digits($band['above']) : Fa::digits($band['above'] + 1) . ' تا ' . Fa::digits($band['upto']) }} برگ</span>
                                        <span class="leader" aria-hidden="true"></span>
                                        <span class="text-xs text-muted tabular-nums">{{ Fa::num($band['count']) }} · {{ $pct(round($band['count'] * 100 / max(1, $sz['with_folios']), 1)) }}</span>
                                    </span>
                                    <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                        <span class="block h-full bg-cinnabar/70 rounded-full" style="width: max(2px, {{ round($band['count'] * 100 / $maxBand, 2) }}%)"></span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <p class="text-xs text-muted mb-3">میانگین شمار برگ نسخه‌های هر سده، و زیر آن میانگین سطرهای هر صفحه.</p>
                        <div class="flex items-end gap-1 h-36 border-b border-line-strong" role="list" aria-label="میانگین شمار برگ در هر سده">
                            @foreach($sz['centuries'] as $col)
                                @php $shown = $col['with_folios'] >= config('insights.min_sample'); @endphp
                                <div role="listitem" class="flex-1 h-full flex flex-col justify-end items-center gap-0.5 min-w-0"
                                     title="سده‌ی {{ Fa::digits($col['century']) }}: {{ $shown ? 'میانگین ' . Fa::num($col['folios']) . ' برگ از ' . Fa::num($col['with_folios']) . ' نسخه' : 'نسخه‌ی بسنده نیست' }}">
                                    @if($shown)
                                        <span class="text-[10px] text-muted tabular-nums">{{ Fa::num($col['folios']) }}</span>
                                        <span class="w-full max-w-8 rounded-t-[3px] bg-gold/70" style="height: {{ round($col['folios'] * 100 / $maxFolios, 2) }}%"></span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="flex gap-1 mt-1" aria-hidden="true">
                            @foreach($sz['centuries'] as $col)
                                <span class="flex-1 text-center text-[10px] text-ink tabular-nums">{{ Fa::digits($col['century']) }}</span>
                            @endforeach
                        </div>
                        <div class="flex gap-1 mt-0.5" aria-label="میانگین سطرهای هر سده">
                            @foreach($sz['centuries'] as $col)
                                <span class="flex-1 text-center text-[10px] text-muted tabular-nums" title="میانگین سطر در سده‌ی {{ Fa::digits($col['century']) }}">{{ $col['with_lines'] >= config('insights.min_sample') ? Fa::digits(str_replace('.', '٫', (string) $col['lines'])) : '·' }}</span>
                            @endforeach
                        </div>
                        <p class="text-center text-[11px] text-muted mt-0.5">سده‌ی هجری · سطر در صفحه</p>
                    </div>
                </div>
                <p class="mt-5 text-xs text-muted leading-relaxed">
                    بر پایه‌ی {{ Fa::num($sz['with_folios']) }} نسخه که شمار برگشان ثبت شده و {{ Fa::num($sz['with_lines']) }} نسخه که شمار سطرشان ثبت شده است.
                    در مجموعه‌ها، شمار برگ گاه برگ‌های همان رساله است و گاه برگ‌های کل مجموعه، بسته به فهرست. سده‌هایی که کمتر از {{ Fa::digits(config('insights.min_sample')) }} نسخه دارند نیامده‌اند.
                </p>
            @endif
        </x-ui.card>
    </x-ui.section>

    {{-- 2g. Languages and scripts across the centuries --}}
    @php $ls = $data['language_scripts']; @endphp
    <x-ui.section title="زبان‌ها و خطوط در گذر سده‌ها" id="language-scripts">
        <x-ui.card>
            @if($ls['languages']['counted'] < $min && $ls['scripts']['counted'] < $min)
                @include('insights.thin', ['n' => max($ls['languages']['counted'], $ls['scripts']['counted']), 'what' => 'نسخه‌ی تاریخ‌دار با زبان یا خط'])
            @else
            <div x-data="{ by: '{{ $ls['languages']['counted'] >= $min && count($ls['languages']['rows']) > 1 ? 'languages' : 'scripts' }}' }">
                <div class="flex flex-wrap items-center gap-1.5 text-xs mb-4">
                    @foreach(['languages' => 'زبان', 'scripts' => 'خط اصلی'] as $key => $name)
                        <button type="button" @click="by = '{{ $key }}'" :aria-pressed="(by === '{{ $key }}').toString()"
                                :class="by === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                                class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
                    @endforeach
                </div>
                <div x-show="by === 'languages'" @if(! ($ls['languages']['counted'] >= $min && count($ls['languages']['rows']) > 1)) x-cloak @endif>
                    <p class="text-xs text-muted mb-3">نسخه‌های هر زبان در هر سده؛ زبان نسخه، زبان اثر آن است و اثر چندزبانه در هر زبانش شمرده شده است.</p>
                    @include('insights.century-grid', ['grid' => $ls['languages'], 'label' => 'سهم زبان‌ها در نسخه‌های هر سده',
                        'href' => fn ($id, $c) => $search('manuscripts', ['language_id' => $id, 'century' => $c])])
                </div>
                <div x-show="by === 'scripts'" @if($ls['languages']['counted'] >= $min && count($ls['languages']['rows']) > 1) x-cloak @endif>
                    <p class="text-xs text-muted mb-3">نسخه‌های هر خط در هر سده، بر پایه‌ی خط اصلی هر نسخه.</p>
                    @include('insights.century-grid', ['grid' => $ls['scripts'], 'label' => 'سهم خطوط در نسخه‌های هر سده',
                        'href' => fn ($id, $c) => $search('manuscripts', ['script_id' => $id, 'century' => $c])])
                </div>
                <p class="mt-4 text-xs text-muted leading-relaxed">
                    بر پایه‌ی نسخه‌هایی که تاریخ کتابتشان در یک سده است: {{ Fa::num($ls['languages']['counted']) }} نسخه با زبان و {{ Fa::num($ls['scripts']['counted']) }} نسخه با خط.
                </p>
            </div>
            @endif
        </x-ui.card>
    </x-ui.section>

    {{-- 3. Most-copied works --}}
    @if(count($data['top_works']['items']) > 1)
    <x-ui.section :title="$scope->isEmpty() ? 'آثار پرنسخه' : 'آثار پرنسخه در این دامنه'" :count="count($data['top_works']['items'])" id="top-works">
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
    @endif

    {{-- 4. Scripts and languages --}}
    <div class="grid lg:grid-cols-2 gap-8">
        <x-ui.section title="سهم خطوط" id="scripts">
            <x-ui.card>
                @if($s['counted'] < $min)
                    @include('insights.thin', ['n' => $s['counted'], 'what' => 'نسخه‌ی خط‌دار'])
                @else
                @include('insights.small', ['n' => $s['counted'], 'what' => 'نسخه‌ی خط‌دار'])
                <p class="text-xs text-muted mb-3">خط اصلی هر نسخه. روی هر خط بزنید تا نسخه‌های آن خط را ببینید.</p>
                <ul class="space-y-2.5 text-sm">
                    @foreach($s['items'] as $row)
                        <li>
                            <a href="{{ $search('manuscripts', ['script_id' => $row['id']]) }}" class="group block">
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
                @endif
            </x-ui.card>
        </x-ui.section>

        <x-ui.section title="سهم زبان‌ها" id="languages">
            <x-ui.card>
            @if($l['with_language'] < $min)
                @include('insights.thin', ['n' => $l['with_language'], 'what' => 'اثرِ زبان‌دار'])
            @else
            @include('insights.small', ['n' => $l['with_language'], 'what' => 'اثرِ زبان‌دار'])
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
                            <a href="{{ $search('works', ['language_id' => $row['id']]) }}" class="group block">
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
            @endif
            </x-ui.card>
        </x-ui.section>
    </div>

    {{-- 5. The catalogues the figures rest on --}}
    @php $src = $data['sources']; @endphp
    @if(! $scope->get('catalog') && $src['items'])
    <x-ui.section title="فهرست‌هایی که این آمار بر آن‌ها بنا شده" id="sources">
        <x-ui.card>
            @php $maxSrc = max([1, ...array_column($src['items'], 'count')]); @endphp
            <p class="text-xs text-muted mb-3">هر نسخه از کدام فهرست کتابخانه به فهارس رسیده است. روی «آمار» بزنید تا آمار نسخه‌های همان فهرست را ببینید.</p>
            <ul class="space-y-2.5 text-sm">
                @foreach($src['items'] as $row)
                    <li>
                        <span class="flex items-baseline gap-2">
                            {{-- The volume, unless the title already names it («جلد شانزدهم») --}}
                            <span class="text-ink truncate min-w-0" title="{{ $row['title'] }}">{{ $row['title'] }}@if($row['volume'] && ! str_contains($row['title'], 'جلد')) <span class="text-muted">ج {{ Fa::digits($row['volume']) }}</span>@endif</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($row['count']) }} · {{ $pct(round($row['count'] * 100 / max(1, $data['totals']['manuscripts']), 1)) }}</span>
                            <a href="{{ route('insights', ['catalog' => $row['id']] + $scope->query()) }}" class="text-[11px] text-cinnabar hover:underline">آمار</a>
                        </span>
                        <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full bg-gold/70 rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / $maxSrc, 2) }}%)"></span>
                        </span>
                    </li>
                @endforeach
                @if($src['other'] > 0)
                    <li class="text-muted flex items-baseline gap-2">
                        <span>سایر ({{ Fa::num($src['other_kinds']) }} جلد فهرست)</span>
                        <span class="leader" aria-hidden="true"></span>
                        <span class="text-xs tabular-nums">{{ Fa::num($src['other']) }} · {{ $pct(round($src['other'] * 100 / max(1, $data['totals']['manuscripts']), 1)) }}</span>
                    </li>
                @endif
            </ul>
            <p class="mt-4 text-xs text-muted leading-relaxed">
                همه‌ی این نسخه‌ها از راه فنخا آمده‌اند؛ فنخا هر نسخه را از فهرست چاپی کتابخانه‌اش نقل کرده است.
                {{ Fa::num($src['counted']) }} نسخه ({{ $pct($src['coverage']) }}) به یکی از {{ Fa::num($src['volumes']) }} جلد فهرست پیوند دارند@if($src['unknown'] > 0) و جلد فهرست {{ Fa::num($src['unknown']) }} نسخه‌ی دیگر هنوز شناخته نیست@endif.
            </p>
        </x-ui.card>
    </x-ui.section>
    @endif

    @endif

    @include('insights.request-card')

    <p class="text-xs text-muted text-center">
        @if($scope->isEmpty())
            این آمار هر شب از روی داده‌های فهارس از نو ساخته می‌شود؛ آخرین بار {{ Jalali::format(\Illuminate\Support\Carbon::parse($data['built_at']), 'long') }}.
        @else
            آمار این دامنه هنگام درخواست ساخته شد ({{ Jalali::format(\Illuminate\Support\Carbon::parse($data['built_at']), 'long') }}) و تا یک روز همان می‌ماند.
        @endif
    </p>
</div>
@endsection
