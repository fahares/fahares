{{--
    The small summary of a long list on its record page (backlog item 106, step 14.453): the three first cities of
    copying, the copies of each century in a small bar, and the share of autographs and illuminated copies, each with
    the number of copies it rests on. The full charts stay on the insights page the button leads to. $stats is
    ListStats::for(). On a work's page a century or the autographs filter the page's own list of copies (the copy
    filters of item 79); elsewhere they lead to the insights page.
--}}
@php
    use App\Support\Fa;
    use App\Support\ListStats;

    $param = $stats['param'];
    $id = (int) $stats['id'];
    $what = match ($param) {
        'author' => 'نسخه از آثارش',
        'scribe' => 'نسخۀ کتابت‌شده',
        'subject' => 'نسخه از آثار این موضوع',
        default => 'نسخه',
    };
    $total = $stats['total'];
    $centuries = $stats['centuries'];
    if ($centuries) {
        $centuries = array_replace(array_fill_keys(range(min(array_keys($centuries)), max(array_keys($centuries))), 0), $centuries);
    }
    $peak = $centuries ? max($centuries) : 0;
    $url = fn (array $more = []) => ListStats::url($param, $id, $more);
    $workKey = $param === 'work' ? \App\Models\Work::find($id)?->getRouteKey() : null;
    $local = $workKey ? fn (array $f) => route('works.show', ['id' => $workKey] + $f) . '#manuscripts' : null;
    $filter = $local ?? $url;
@endphp
<div class="mb-4 border border-line bg-surface px-4 py-3 grid gap-4 sm:grid-cols-3 text-sm" data-list-stats="{{ $param }}">
    <div class="min-w-0">
        <div class="text-xs font-bold text-muted mb-1.5">شهرهای کتابت</div>
        @if($stats['cities'])
            <ol class="space-y-0.5">
                @foreach($stats['cities'] as $row)
                    <li class="flex items-baseline gap-2">
                        <a href="{{ $url(['copy_city' => $row['city']]) }}" rel="nofollow" class="text-ink hover:text-cinnabar transition">{{ $row['city'] }}</a>
                        <span class="leader" aria-hidden="true"></span>
                        <span class="text-xs text-muted tabular-nums">{{ Fa::num($row['count']) }}</span>
                    </li>
                @endforeach
            </ol>
            <p class="mt-1 text-[11px] text-muted" data-list-stats-placed>{{ Fa::num($stats['placed']) }} از {{ Fa::num($total) }} {{ $what }} محل کتابت دارند</p>
        @else
            <p class="text-xs text-muted">هیچ‌یک از {{ Fa::num($total) }} {{ $what }} محل کتابتِ شناخته‌شده ندارد.</p>
        @endif
    </div>

    <div class="min-w-0">
        <div class="text-xs font-bold text-muted mb-1.5">سده‌های کتابت</div>
        @if($centuries)
            <div class="flex items-end gap-0.5 h-12" role="list" aria-label="شمار نسخه‌ها در هر سدۀ کتابت">
                @foreach($centuries as $c => $n)
                    <a href="{{ $filter(['century' => $c]) }}" rel="nofollow" role="listitem" title="سدۀ {{ Fa::num($c) }}: {{ Fa::num($n) }} {{ $what }}" aria-label="سدۀ {{ Fa::num($c) }}: {{ Fa::num($n) }} {{ $what }}"
                       class="group flex-1 min-w-[6px] max-w-[28px] h-full flex items-end" data-century="{{ $c }}">
                        <span class="block w-full {{ $n ? 'bg-cinnabar/70 group-hover:bg-cinnabar' : 'bg-line' }} transition" style="height: {{ $n ? max(6, round(100 * $n / $peak)) : 2 }}%"></span>
                    </a>
                @endforeach
            </div>
            <div class="flex justify-between text-[11px] text-muted tabular-nums mt-0.5" style="max-width: {{ count($centuries) * 30 }}px">
                <span>سدۀ {{ Fa::num(array_key_first($centuries)) }}</span>
                @if(count($centuries) > 1)<span>{{ Fa::num(array_key_last($centuries)) }}</span>@endif
            </div>
            <p class="mt-1 text-[11px] text-muted" data-list-stats-dated>{{ Fa::num($stats['dated']) }} از {{ Fa::num($total) }} {{ $what }} سدۀ کتابتشان روشن است</p>
        @else
            <p class="text-xs text-muted">هیچ‌یک از {{ Fa::num($total) }} {{ $what }} تاریخی در یک سده ندارد.</p>
        @endif
    </div>

    <div class="min-w-0">
        <div class="text-xs font-bold text-muted mb-1.5">ویژگی نسخه‌ها</div>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-0.5">
            <dt>@if($local && $stats['autograph'])<a href="{{ $local(['autograph' => 1]) }}" rel="nofollow" class="text-ink hover:text-cinnabar transition">خط مؤلف</a>@else<span class="text-ink">خط مؤلف</span>@endif</dt>
            <dd class="text-xs text-muted tabular-nums" data-list-stats-autograph>{{ ListStats::percent($stats['autograph'], $total) }}٪ <span>({{ Fa::num($stats['autograph']) }})</span></dd>
            <dt class="text-ink">مذهّب</dt>
            <dd class="text-xs text-muted tabular-nums" data-list-stats-illuminated>{{ ListStats::percent($stats['illuminated'], $total) }}٪ <span>({{ Fa::num($stats['illuminated']) }})</span></dd>
        </dl>
        <a href="{{ $url() }}" rel="nofollow" class="inline-block mt-2 text-xs font-bold text-cinnabar hover:underline">نمودارهای کامل در صفحۀ آمار ←</a>
    </div>
</div>
