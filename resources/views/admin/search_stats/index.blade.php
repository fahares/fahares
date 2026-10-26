@extends('layouts.admin')

@section('title', 'آمار جستجو')
@section('page_title', 'آمار جستجو')

@section('content')
@php
    use App\Http\Controllers\Admin\SearchStatsController as Stats;
    use App\Support\Fa;

    $periods = [];
    foreach (Stats::PERIODS as $d => $label) {
        $periods[] = ['label' => $label, 'url' => route('admin.search-stats.index', ['days' => $d]), 'active' => $days === $d];
    }
    $searches = (int) $totals->searches;
    $answered = (int) $totals->answered;
    $pct = fn ($part, $whole) => $whole ? Fa::digits(round(100 * $part / $whole)) . '٪' : '—';
    $tabLabels = ['works' => 'آثار', 'manuscripts' => 'نسخه‌ها', 'people' => 'اشخاص'];
    $scopeLabels = ['titles' => 'فقط عنوان', 'titles_names' => 'عنوان و نام', 'all' => 'همه‌ی اطلاعات'];
    $maxDay = max(1, ...array_column($daily, 'n'));
    $searchUrl = fn ($row) => route('search', ['q' => $row->query, 'type' => $row->type]);
    $tiles = [
        ['جستجو', Fa::num($searches), Fa::num((int) $totals->queries) . ' عبارت متفاوت'],
        ['بی‌نتیجه', $pct((int) $totals->empty, $searches), Fa::num((int) $totals->empty) . ' جستجو'],
        ['نتیجه‌ای باز شد', $pct((int) $totals->clicked, $answered), 'از جستجوهای نتیجه‌دار'],
        ['نتیجه‌ی اول باز شد', $pct((int) $totals->first_clicked, (int) $totals->clicked), $totals->avg_position ? 'میانگین رتبه ' . Fa::digits(round($totals->avg_position, 1)) : 'از نتیجه‌های بازشده'],
        ['کاربران واردشده', $pct((int) $totals->members, $searches), Fa::num((int) $totals->users) . ' کاربر یکتا با سابقه‌ی روشن'],
    ];
@endphp

<x-admin.tabs :options="$periods" />

<div class="space-y-6 text-[13px]">
    <x-admin.notice>
        جستجوهای نخستین صفحه‌ی نتایج ثبت می‌شوند. جستجوی مهمان بی‌نام است. جستجوی کاربر واردشده با حسابش نگه داشته می‌شود، مگر آنکه در «حساب من» خاموشش کرده باشد؛ جستجوهای هر کاربر در صفحه‌ی او در «کاربران» دیده می‌شود. ربات‌ها و جستجوی فوری سربرگ شمرده نمی‌شوند.
        «باز شد» یعنی کاربر از همان صفحه نتیجه‌ای را گشود.
    </x-admin.notice>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        @foreach($tiles as [$label, $value, $note])
            <x-ui.card class="!p-4">
                <div class="text-xs text-muted">{{ $label }}</div>
                <div class="text-2xl font-bold text-ink tabular-nums mt-1">{{ $value }}</div>
                <div class="text-[11px] text-muted mt-1">{{ $note }}</div>
            </x-ui.card>
        @endforeach
    </div>

    @if($searches === 0)
        <p class="py-10 text-center text-muted">در این بازه جستجویی ثبت نشده است.</p>
    @else
        <x-ui.card class="space-y-3">
            <h2 class="rubric text-base">جستجو در هر روز</h2>
            {{-- One series: the day's searches; the tooltip also gives the ones that found nothing --}}
            <div class="flex items-end gap-[2px] h-32 border-b border-line-strong" role="img" aria-label="شمار جستجو در هر روز">
                @foreach($daily as $d)
                    <div class="flex-1 min-w-[1px] h-full flex items-end group relative" title="@jdate($d['day'], 'date'): {{ Fa::num($d['n']) }} جستجو، {{ Fa::num($d['empty']) }} بی‌نتیجه">
                        <div class="w-full rounded-t-[4px] bg-cinnabar/80 group-hover:bg-cinnabar transition" style="height: {{ $d['n'] ? max(2, round(100 * $d['n'] / $maxDay)) : 0 }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between text-[11px] text-muted tabular-nums">
                <span>@jdate($daily[0]['day'], 'date')</span>
                <span>بیشترین: {{ Fa::num($maxDay) }} در روز</span>
                <span>@jdate(end($daily)['day'], 'date')</span>
            </div>
        </x-ui.card>

        @foreach([
            ['پرتکرارترین جستجوها', $top, 'جستجویی ثبت نشده است.'],
            ['جستجوهای بی‌نتیجه', $empty, 'همه‌ی جستجوها نتیجه داشته‌اند.'],
            ['نتیجه‌دار ولی بی‌کلیک', $unopened, 'جستجوی تکراری بی‌کلیکی نیست.'],
        ] as [$title, $rows, $none])
            <section class="space-y-2">
                <h2 class="rubric text-base">{{ $title }}</h2>
                @if($title === 'نتیجه‌دار ولی بی‌کلیک')
                    <p class="text-xs text-muted">دست‌کم دو بار جستجو شده و نتیجه داشته، ولی هیچ نتیجه‌ای باز نشده است: احتمالاً نتیجه‌ها آن چیزی نبوده که کاربر می‌خواست.</p>
                @endif
                <x-admin.table :is-empty="$rows->isEmpty()" :empty="$none">
                    <x-slot:head>
                        <x-admin.th>عبارت</x-admin.th>
                        <x-admin.th>دفعات</x-admin.th>
                        <x-admin.th>میانگین نتیجه</x-admin.th>
                        <x-admin.th>باز شد</x-admin.th>
                        <x-admin.th>تب</x-admin.th>
                        <x-admin.th>آخرین بار</x-admin.th>
                    </x-slot:head>
                    @foreach($rows as $row)
                        <x-admin.row>
                            <x-admin.cell main><a href="{{ $searchUrl($row) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $row->query }}</a></x-admin.cell>
                            <x-admin.cell label="دفعات" class="tabular-nums">{{ Fa::num($row->n) }}</x-admin.cell>
                            <x-admin.cell label="میانگین نتیجه" class="tabular-nums">{{ Fa::num((int) $row->results) }}</x-admin.cell>
                            <x-admin.cell label="باز شد" class="tabular-nums">{{ $pct((int) $row->clicks, (int) $row->n) }}</x-admin.cell>
                            <x-admin.cell label="تب">{{ $tabLabels[$row->type] ?? $row->type }}</x-admin.cell>
                            <x-admin.cell label="آخرین بار" class="text-muted whitespace-nowrap">@jdate(\Illuminate\Support\Carbon::parse($row->last_at), 'date')</x-admin.cell>
                        </x-admin.row>
                    @endforeach
                </x-admin.table>
            </section>
        @endforeach

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach([
                ['تب جستجو', $tabs->mapWithKeys(fn ($n, $k) => [$tabLabels[$k] ?? $k => $n])],
                ['دامنه‌ی جستجو', $scopes->mapWithKeys(fn ($n, $k) => [$scopeLabels[$k] ?? $k => $n])],
                ['فیلترهای به‌کاررفته', $filters->mapWithKeys(fn ($n, $k) => [Stats::FILTER_LABELS[$k] ?? $k => $n])],
                ['رتبه‌ی نتیجه‌ی بازشده', collect([1 => 'اول', 2 => 'دوم', 3 => 'سوم', 4 => '۴ تا ۱۰', 11 => 'پس از ۱۰'])->mapWithKeys(fn ($l, $b) => [$l => $positions[$b] ?? 0])],
            ] as [$title, $counts])
                <x-ui.card class="space-y-2">
                    <h2 class="rubric text-base">{{ $title }}</h2>
                    @php $sum = max(1, $counts->sum()); @endphp
                    @forelse($counts->filter() as $label => $n)
                        <div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-ink">{{ $label }}</span>
                                <span class="leader"></span>
                                <span class="tabular-nums text-muted">{{ Fa::num($n) }} <span class="text-[11px]">({{ $pct($n, $sum) }})</span></span>
                            </div>
                            <div class="h-1 mt-1 bg-line rounded-full overflow-hidden"><div class="h-full bg-cinnabar/80 rounded-full" style="width: {{ round(100 * $n / $sum) }}%"></div></div>
                        </div>
                    @empty
                        <p class="text-xs text-muted">موردی نیست.</p>
                    @endforelse
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
@endsection
