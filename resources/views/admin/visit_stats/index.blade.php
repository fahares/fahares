@extends('layouts.admin')

@section('title', 'آمار بازدید')
@section('page_title', 'آمار بازدید')

@section('content')
@php
    use App\Http\Controllers\Admin\VisitStatsController as Stats;
    use App\Support\Fa;
    use App\Support\PageViews;

    $periods = [];
    foreach (Stats::PERIODS as $d => $label) {
        $periods[] = ['label' => $label, 'url' => route('admin.visit-stats.index', ['days' => $d]), 'active' => $days === $d];
    }
    $views = $totals['views'];
    $pct = fn ($part, $whole) => $whole ? Fa::digits(round(100 * $part / $whole)) . '٪' : '—';
    $maxDay = max(1, ...array_column($daily, 'views'));
    $entries = array_sum($sources);
    $tiles = [
        ['بازدید صفحه', Fa::num($views), 'میانگین ' . Fa::num((int) round($views / count($daily))) . ' در روز'],
        ['بازدیدکننده‌ی یکتای روزانه', Fa::digits(str_replace('.', '٫', (string) round($totals['visitors'] / count($daily), $totals['visitors'] < 10 * count($daily) ? 1 : 0))), 'میانگین روزانه؛ بیشترین ' . Fa::num($totals['busiest']) . ' در یک روز'],
        ['موبایل', $pct($totals['mobile'], $views), Fa::num($totals['mobile']) . ' بازدید'],
        ['ورود از Google', $pct($sources['google'], $entries), Fa::num($sources['google']) . ' ورود به سایت'],
        ['کاربران واردشده', $pct($totals['members'], $views), Fa::num($totals['members']) . ' بازدید؛ بقیه مهمان'],
    ];
    $sourceLabels = ['google' => 'جست‌وجوی Google', 'engines' => 'جست‌وجوگرهای دیگر', 'direct' => 'مستقیم (بی ارجاع‌دهنده)', 'sites' => 'سایت‌های دیگر'];
@endphp

<x-admin.tabs :options="$periods" />

<div class="space-y-6 text-[13px]">
    <x-admin.notice>
        صفحه‌هایی شمرده می‌شوند که مرورگر یک انسان باز کرده است. ربات‌ها، پیش‌بارگذاری مرورگر، پنل مدیریت و درخواست‌های داخلی شمرده نمی‌شوند.
        هیچ IP، شناسه‌ی کاربر یا کوکی‌ای ذخیره نمی‌شود. بازدیدکننده‌ی یکتا با هش IP و مرورگر و نمکی که هر روز عوض و پاک می‌شود شمرده می‌شود، پس فقط در همان روز معنا دارد و جمع چند روز آدم‌های یکتا نیست.
        بازدیدها با چند دقیقه تأخیر می‌رسند. روزها به وقت تهران‌اند.
        <a href="{{ route('admin.search-stats.index', ['days' => $days]) }}" class="font-bold text-cinnabar hover:underline">آمار جست‌وجو</a>
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

    @if($views === 0)
        <p class="py-10 text-center text-muted">در این بازه بازدیدی ثبت نشده است.</p>
    @else
        <x-ui.card class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="rubric text-base">بازدید در هر روز</h2>
                <div class="flex items-center gap-3 text-[11px] text-muted">
                    <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-cinnabar/30"></span> بازدید صفحه</span>
                    <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-cinnabar"></span> بازدیدکننده‌ی یکتا</span>
                </div>
            </div>
            {{-- The day's views, and inside them its unique visitors, who are never more --}}
            <div class="flex items-end gap-[2px] h-36 border-b border-line-strong" role="img" aria-label="بازدید و بازدیدکننده‌ی یکتا در هر روز">
                @foreach($daily as $d)
                    <div class="flex-1 min-w-[1px] h-full flex items-end relative group" title="@jdate($d['day'], 'date'): {{ Fa::num($d['views']) }} بازدید، {{ Fa::num($d['visitors']) }} بازدیدکننده">
                        <div class="w-full rounded-t-[4px] bg-cinnabar/30 group-hover:bg-cinnabar/40 transition relative" style="height: {{ $d['views'] ? max(2, round(100 * $d['views'] / $maxDay)) : 0 }}%">
                            <div class="absolute inset-x-0 bottom-0 rounded-t-[4px] bg-cinnabar" style="height: {{ $d['views'] ? round(100 * $d['visitors'] / $d['views']) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between text-[11px] text-muted tabular-nums">
                <span>@jdate($daily[0]['day'], 'date')</span>
                <span>بیشترین: {{ Fa::num($maxDay) }} بازدید در روز</span>
                <span>@jdate(end($daily)['day'], 'date')</span>
            </div>
        </x-ui.card>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach([
                ['ورود به سایت', collect($sourceLabels)->mapWithKeys(fn ($l, $k) => [$l => $sources[$k]]), 'بازدیدهایی که از بیرون سایت آمده‌اند؛ ' . Fa::num($totals['internal']) . ' بازدید دیگر با کلیک درون خود سایت بوده است.'],
                ['سایت‌های ارجاع‌دهنده', collect($sites), 'فقط دامنه ثبت می‌شود.'],
                ['دستگاه و کاربر', collect(['موبایل' => $totals['mobile'], 'دسکتاپ' => $views - $totals['mobile'], 'کاربر واردشده' => $totals['members'], 'مهمان' => $views - $totals['members']]), 'سهم از همه‌ی بازدیدها.'],
            ] as [$title, $counts, $note])
                <x-ui.card class="space-y-2">
                    <h2 class="rubric text-base">{{ $title }}</h2>
                    <p class="text-[11px] text-muted">{{ $note }}</p>
                    @php $whole = $title === 'دستگاه و کاربر' ? max(1, $views) : max(1, $counts->sum()); @endphp
                    @forelse($counts->filter() as $label => $n)
                        <div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-ink" @if($title === 'سایت‌های ارجاع‌دهنده') dir="ltr" @endif>{{ $label }}</span>
                                <span class="leader"></span>
                                <span class="tabular-nums text-muted">{{ Fa::num($n) }} <span class="text-[11px]">({{ $pct($n, $whole) }})</span></span>
                            </div>
                            <div class="h-1 mt-1 bg-line rounded-full overflow-hidden"><div class="h-full bg-cinnabar/80 rounded-full" style="width: {{ round(100 * $n / $whole) }}%"></div></div>
                        </div>
                    @empty
                        <p class="text-xs text-muted">موردی نیست.</p>
                    @endforelse
                </x-ui.card>
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            @foreach($top as $type => $items)
                <section class="space-y-2 @if($type === 'source_page') xl:col-span-2 @endif">
                    <h2 class="rubric text-base">{{ $type === 'source_page' ? 'صفحه‌های منبع پربازدید' : 'پربازدیدترین ' . PageViews::TYPE_LABELS[$type] }}</h2>
                    <x-admin.table :is-empty="$items->isEmpty()" empty="">
                        <x-slot:head>
                            <x-admin.th>{{ PageViews::TYPE_LABELS[$type] }}</x-admin.th>
                            <x-admin.th>بازدید</x-admin.th>
                            <x-admin.th>بازدیدکننده‌ی روزانه</x-admin.th>
                        </x-slot:head>
                        @foreach($items as $ref => $item)
                            <x-admin.row>
                                <x-admin.cell main>
                                    @if($item->url)
                                        <a href="{{ $item->url }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $item->label }}</a>
                                    @else
                                        <span class="text-muted">{{ $item->label }} (یافت نشد)</span>
                                    @endif
                                </x-admin.cell>
                                <x-admin.cell label="بازدید" class="tabular-nums">{{ Fa::num($item->views) }}</x-admin.cell>
                                <x-admin.cell label="بازدیدکننده‌ی روزانه" class="tabular-nums">{{ Fa::num($item->visitors) }}</x-admin.cell>
                            </x-admin.row>
                        @endforeach
                    </x-admin.table>
                </section>
            @endforeach
        </div>
    @endif

    <p class="text-[11px] text-muted">
        بازدیدهای تک‌تک ۳۰ روز نگه داشته می‌شوند و هر شب در شمار روزانه جمع می‌شوند.
        @if($lastAggregated) آخرین روز جمع‌شده: @jdate(\Illuminate\Support\Carbon::parse($lastAggregated), 'date'). @else هنوز روزی جمع نشده است. @endif
    </p>
</div>
@endsection
