@extends('layouts.app')

@php $scopeLabels = array_column($scope->labels(), 1); @endphp
@php
    $page = $topic ? $topics[$topic] : null;
    $pageName = $page ? 'آمار ' . $page['name'] . ': ' . $page['title'] : 'آمار: نسخه‌های خطی در گذر سده‌ها';
@endphp
@section('title', ($scope->isEmpty() ? $pageName : ($page ? 'آمار ' . $page['name'] . '، ' : 'آمار ') . 'نسخه‌های ' . implode('، ', $scopeLabels)) . ' | فهارس')
@section('meta_description', $page ? $page['lead'] : 'خط زمانی کتابت نسخه‌های خطی از سدۀ چهارم تا چهاردهم هجری، کانون‌های تاریخی استنساخ و تألیف، جابه‌جایی نسخه‌ها، موضوع‌ها و ویژگی‌های نسخه در گذر سده‌ها، آثار پرنسخه و سهم خطوط و زبان‌ها در فهارس')
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
    // This page, hub or topic, with the given filters: the form, the chips of the scope and the tabs stay on it
    $pageUrl = fn (?string $slug, array $query = []) => $slug ? route('insights.topic', ['topic' => $slug] + $query) : route('insights', $query);
    $here = fn (array $query) => $pageUrl($topic, $query);
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
    // The still map the page is sent with (charts/places): the cities of copying and the names of the largest
    $layers = ['copy' => $mapLabels('count')];
    $maxPlace = max([1, ...array_column($p['cities'], 'count')]);
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-10">

    <header class="text-center grid justify-items-center gap-2">
        <p class="text-xs font-bold text-cinnabar">آمار</p>
        @if($scope->isEmpty())
            <h1 class="font-naskh text-[28px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">{{ $page ? $page['title'] : 'نسخه‌ها در گذر سده‌ها' }}</h1>
            @if($page)
                <p class="text-sm text-muted max-w-2xl leading-relaxed">{{ $page['lead'] }}</p>
            @else
            <p class="text-sm text-muted max-w-2xl leading-relaxed">
                آنچه {{ Fa::approx($data['totals']['manuscripts']) }} نسخۀ خطی و {{ Fa::approx($data['totals']['works']) }} اثرِ ثبت‌شده در فهارس دربارۀ زمان و محل کتابت، آثار پرنسخه و خطوط و زبان‌ها می‌گوید.
                هر نمودار تنها بر بخشی از نسخه‌ها تکیه دارد که آن آگاهی را دارند؛ سهم هر یک زیر نمودار آمده است.
            </p>
            @endif
        @else
            <h1 class="font-naskh text-[24px] sm:text-[32px] font-bold leading-[1.6] text-ink text-balance">{{ $page ? $page['title'] . ' در ' : 'آمار ' }}نسخه‌های {{ implode('، ', $scopeLabels) }}</h1>
            <p class="text-sm text-muted">
                {{ Fa::num($data['totals']['manuscripts']) }} نسخه از {{ Fa::num($data['totals']['works']) }} اثر
                · <a href="{{ $here([]) }}" class="text-cinnabar font-semibold hover:underline">بازگشت به آمار کل</a>
            </p>
            <ul class="flex flex-wrap justify-center gap-2 text-xs" aria-label="فیلترهای این آمار">
                @foreach($scope->labels() as [$param, $label, $link])
                    <li class="inline-flex items-center gap-1 border border-line-strong bg-surface ps-2.5 rounded-full">
                        @if($link)<a href="{{ $link }}" class="text-ink hover:text-cinnabar">{{ $label }}</a>@else<span class="text-ink">{{ $label }}</span>@endif
                        <a href="{{ $here($scope->without($param)) }}" class="px-2 py-0.5 text-muted hover:text-cinnabar" aria-label="برداشتن فیلتر {{ $label }}" title="برداشتن این فیلتر">×</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </header>

    <nav class="flex flex-wrap justify-center gap-1.5 text-sm" aria-label="صفحه‌های آمار" data-insights-tabs>
        @foreach([null => ['name' => 'همه']] + $topics as $slug => $tab)
            @php $slug = $slug === '' ? null : $slug; @endphp
            <a href="{{ $pageUrl($slug, $scope->query()) }}" @if($slug === $topic) aria-current="page" @endif
               class="px-3 py-1 rounded-full border transition {{ $slug === $topic ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar' }}">{{ $tab['name'] }}</a>
        @endforeach
    </nav>

    @include('insights.filter-form')
    @includeWhen($scope->isEmpty(), 'insights.presets')

    @if($data['totals']['manuscripts'] === 0 && $data['totals']['works'] === 0)
        <x-ui.card><p class="text-sm text-muted text-center py-6">در این دامنه نسخه یا اثری نیست. فیلترها را کمتر کنید یا <a href="{{ $here([]) }}" class="text-cinnabar hover:underline">به آمار کل</a> برگردید.</p></x-ui.card>
    @else

    @php $notes = \App\Support\InsightsNotes::for($data); @endphp
    @php
        $tables = \App\Support\InsightsTables::for($data);
        // The scope in the name of a downloaded file: «-author=1110-century=5-8»
        $csvSuffix = $scope->isEmpty() ? '' : '-' . trim(preg_replace('/[^\p{L}\p{N}=._-]+/u', '-', urldecode(http_build_query($scope->query()))), '-');
    @endphp
    @if(! $topic)
    <dl class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-line border border-line">
        @foreach([
            ['نسخۀ خطی', Fa::num($data['totals']['manuscripts']), 'متعلق به ' . Fa::num($data['totals']['works']) . ' اثر'],
            ['نسخۀ تاریخ‌دار', Fa::num($t['shown']), $pct($t['coverage']) . ' نسخه‌ها، با سده‌ای‌ها'],
            ['اوج کتابت', $peak['count'] ? 'سدۀ ' . Fa::digits($peak['century']) : '—', Fa::num($peak['count']) . ' نسخه'],
            ['کانون استنساخ', Fa::num(count($p['cities'])) . ' شهر', 'از ' . Fa::num($p['mapped']) . ' نسخۀ دارای محل کتابت'],
        ] as [$label, $value, $note])
            <div class="bg-surface p-4 grid gap-0.5">
                <dt class="text-xs text-muted">{{ $label }}</dt>
                <dd class="font-naskh text-2xl font-bold text-ink tabular-nums">{{ $value }}</dd>
                <dd class="text-xs text-muted">{{ $note }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- The topic pages, each with two of its figures --}}
    @php
        $pw = $data['copies_per_work'];
        $figures = [
            'time' => [Fa::num($t['shown']) . ' نسخۀ تاریخ‌دار', $peak['count'] ? 'اوج کتابت در سدۀ ' . Fa::digits($peak['century']) : null],
            'places' => [Fa::num(count($p['cities'])) . ' شهر', Fa::num($p['mapped']) . ' نسخه با محل کتابت'],
            'works' => [Fa::num($pw['works']) . ' اثر', $pw['works'] ? $pct(round($pw['single'] * 100 / $pw['works'], 1)) . ' تک‌نسخه' : null],
            'copies' => [Fa::num($data['collections']['volumes']) . ' مجموعه', Fa::num($data['facsimiles']['count']) . ' نسخۀ عکسی'],
            'people' => [Fa::num($data['scribes']['scribes']) . ' کاتب', Fa::num($data['dedications']['names']) . ' نام در تقدیم‌ها'],
            'libraries' => [Fa::num($data['libraries']['count']) . ' کتابخانه', Fa::num($data['sources']['volumes']) . ' جلد فهرست'],
        ];
    @endphp
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4" data-insights-cards>
        @foreach($topics as $slug => $card)
            <a href="{{ $pageUrl($slug, $scope->query()) }}" class="group bg-surface border border-line hover:border-cinnabar transition p-5 grid gap-2 content-start">
                <h2 class="font-naskh text-xl font-bold text-ink group-hover:text-cinnabar transition">{{ $card['name'] }}</h2>
                <p class="text-sm text-muted leading-relaxed">{{ $card['lead'] }}</p>
                <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink tabular-nums">
                    @foreach(array_filter($figures[$slug]) as $figure)
                        <span>{{ $figure }}</span>
                    @endforeach
                </p>
            </a>
        @endforeach
    </div>
    {{-- An old link to a chart of the one long page (/insights#places) goes on to the page that has the chart now --}}
    @php
        $chartPages = [];
        foreach ($topics as $slug => $card) {
            foreach (\Illuminate\Support\Arr::flatten($card['charts']) as $chart) {
                $chartPages[$chart] = $pageUrl($slug, $scope->query());
            }
        }
    @endphp
    <script>
        (function () {
            const pages = @js($chartPages), id = location.hash.slice(1);
            if (pages[id]) { location.replace(pages[id] + '#' + id); }
        })();
    </script>
    @else
        @foreach($page['charts'] as $row)
            @if(is_array($row))
                <div class="grid lg:grid-cols-2 gap-8">
                    @foreach($row as $chart)
                        @include('insights.charts.' . $chart)
                    @endforeach
                </div>
            @else
                @include('insights.charts.' . $row)
            @endif
        @endforeach
        <nav class="flex flex-wrap justify-center gap-x-5 gap-y-1 text-sm" aria-label="صفحه‌های دیگر آمار">
            @foreach($topics as $slug => $tab)
                @continue($slug === $topic)
                <a href="{{ $pageUrl($slug, $scope->query()) }}" class="text-cinnabar hover:underline">{{ $tab['name'] }} ←</a>
            @endforeach
        </nav>
    @endif

    @endif

    @include('insights.request-card')

    {{-- The one box of exact figures the marks of every chart share (resources/js/chart-tip.js, backlog item 156) --}}
    <div id="chart-tip" data-chart-tip role="tooltip" hidden
         class="fixed z-50 top-0 left-0 max-w-[min(20rem,calc(100vw-1rem))] bg-ink text-paper text-xs leading-relaxed whitespace-pre-line tabular-nums px-2.5 py-1.5 rounded-[4px] shadow-md pointer-events-none"></div>

    <p class="text-xs text-muted text-center">
        @if($scope->isEmpty())
            این آمار هر شب از روی داده‌های فهارس از نو ساخته می‌شود؛ آخرین بار {{ Jalali::format(\Illuminate\Support\Carbon::parse($data['built_at']), 'long') }}.
        @else
            آمار این دامنه هنگام درخواست ساخته شد ({{ Jalali::format(\Illuminate\Support\Carbon::parse($data['built_at']), 'long') }}) و تا یک روز همان می‌ماند.
        @endif
    </p>
</div>
@endsection
