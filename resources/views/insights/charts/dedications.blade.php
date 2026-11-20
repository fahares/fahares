@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2e1. Dedications and works written at someone's request (step 14.119) --}}
@php
    $dd = $data['dedications'] ?? null;
    $ddCols = $dd ? array_values(array_filter($dd['centuries'], fn ($c) => $c['works'] >= config('insights.min_sample') * 10)) : [];
    $ddMax = max([0.1, ...array_column($ddCols, 'share')]);
    $ddCenturies = fn (?array $c) => $c === null ? '' : ($c[0] === $c[1] ? 'سدۀ ' . Fa::digits($c[0]) : 'سده‌های ' . Fa::digits($c[0]) . ' تا ' . Fa::digits($c[1]));
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
            <p class="mt-5 mb-2 text-xs font-semibold text-ink">سهم آثار تقدیم‌شده یا سفارشی در هر سدۀ تألیف</p>
            <div class="flex items-end gap-1 sm:gap-2 h-28 border-b border-line-strong" role="list" aria-label="سهم آثار تقدیم‌شده یا سفارشی در هر سدۀ تألیف">
                @foreach($ddCols as $col)
                    @php $tip = 'سدۀ ' . Fa::digits($col['century']) . ': ' . Fa::num($col['count']) . ' از ' . Fa::num($col['works']) . ' اثر (' . $pct($col['share']) . ')'; @endphp
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
        @if(count($dd['courts']) > 1)
            @php $maxCourt = max([1, ...array_column($dd['courts'], 'count')]); @endphp
            <p class="mt-5 mb-2 text-xs font-semibold text-ink">آثار تقدیم‌شده یا سفارشی به تفکیک دربار</p>
            <ul class="space-y-2 text-sm" data-insights-courts>
                @foreach($dd['courts'] as $row)
                    <li>
                        <span class="flex items-baseline gap-2">
                            <span class="text-ink">{{ $row['name'] }}</span>
                            <span class="text-[11px] text-muted truncate min-w-0">{{ $row['people'] > 1 ? Fa::num($row['people']) . ' نام، بیش از همه ' : '' }}{{ $row['first'] }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums">{{ Fa::num($row['count']) }}</span>
                        </span>
                        <span class="mt-1 block h-1 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full bg-gold/70 rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / $maxCourt, 2) }}%)"></span>
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-[11px] text-muted">تنها پادشاهان، وزیران و عالمانِ دربار که نامشان به رکورد شخص پیوند خورده و دربارشان شناخته است؛ پس این شمار بخشی از تقدیم‌هاست.</p>
        @endif
    </x-ui.card>
    <p class="mt-3 text-xs text-muted leading-relaxed">
        {{ Fa::num($dd['works']) }} اثر ({{ $pct($dd['coverage']) }} آثار این آمار) تقدیم‌نامه یا سفارش‌دهنده دارند، با {{ Fa::num($dd['names']) }} نام. {{ Fa::num($dd['linked']) }} نامِ پیوندخورده به رکورد شخص، {{ Fa::num($dd['linked_works']) }} اثر از این آثار را دربر دارند.
        این داده از بند «اهدا / به درخواست» معرفی آثار است و با سفارش‌دهندۀ کتابت یک نسخه (بخش «سفارش‌دهندگان») فرق دارد.
        نام‌های پیوندخورده با همۀ صورت‌هایشان یک‌جا شمرده می‌شوند و به صفحۀ شخص می‌روند؛ نام‌های بی‌پیوند با صورت نوشتاری‌شان.
        سده‌ها، سدۀ آثاری است که کل بازۀ تاریخ تألیفشان در یک سده است؛ سده‌هایی که کمتر از {{ Fa::num(config('insights.min_sample') * 10) }} اثر دارند، در نمودار نیامده‌اند.
    </p>
    @include('insights.explain', ['note' => $notes['dedications'], 'tables' => $tables['dedications']])
</x-ui.section>
@endif
