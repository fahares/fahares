@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2e2. Patrons and facsimile copies (step 14.117) --}}
@php
    $pt = $data['patrons'];
    $fx = $data['facsimiles'];
    $centuriesOf = fn (?array $c) => $c === null ? '' : ($c[0] === $c[1] ? 'سدۀ ' . Fa::digits($c[0]) : 'سده‌های ' . Fa::digits($c[0]) . ' تا ' . Fa::digits($c[1]));
    $ptCols = array_values(array_filter($pt['centuries'], fn ($c) => $c['copies'] >= config('insights.min_sample') * 10));
    $ptMax = max([0.1, ...array_column($ptCols, 'share')]);
    $showFacsimiles = $scope->get('facsimile') === null && $fx['count'] >= max(1, $min);
@endphp
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
                               title="جست‌وجوی این نام در سفارش‌دهندۀ نسخه‌ها">{{ Fa::num($row['count']) }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
        @if(count($ptCols) > 2)
            <p class="mt-5 mb-2 text-xs font-semibold text-ink">سهم نسخه‌های دارای سفارش‌دهنده در هر سده</p>
            <div class="flex items-end gap-1 sm:gap-2 h-28 border-b border-line-strong" role="list" aria-label="سهم نسخه‌های دارای سفارش‌دهنده در هر سده">
                @foreach($ptCols as $col)
                    @php $tip = 'سدۀ ' . Fa::digits($col['century']) . ': ' . Fa::num($col['count']) . ' از ' . Fa::num($col['copies']) . ' نسخه (' . $pct($col['share']) . ')'; @endphp
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
        @if(count($pt['courts']) > 1)
            @php $maxCourt = max([1, ...array_column($pt['courts'], 'count')]); @endphp
            <p class="mt-5 mb-2 text-xs font-semibold text-ink">نسخه‌های سفارشی به تفکیک دربار</p>
            <ul class="space-y-2 text-sm" data-insights-courts>
                @foreach($pt['courts'] as $row)
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
            <p class="mt-2 text-[11px] text-muted">تنها سفارش‌دهندگانی که به رکورد شخص پیوند خورده‌اند و دربارشان شناخته است.</p>
        @endif
    </x-ui.card>
    <p class="mt-3 text-xs text-muted leading-relaxed">
        سفارش‌دهنده برای {{ Fa::num($pt['with_patron']) }} نسخه ({{ $pct($pt['coverage']) }}) ثبت است، با {{ Fa::num($pt['names']) }} نام گوناگون.
        سفارش‌دهندۀ {{ Fa::num($pt['linked'] ?? 0) }} نسخه به رکورد شخص پیوند خورده است: پادشاهان و صاحبان لقب‌هایی که سال نسخه نشان می‌دهد کیست («ظل السلطان»، «آیة الله بروجردی»). این نام‌ها با همۀ صورت‌هایشان یک‌جا شمرده می‌شوند و به صفحۀ شخص می‌روند. نام‌های دیگر با صورت نوشتاری‌شان شمرده می‌شوند و جست‌وجوی هر یک نسخه‌های هم‌نام را هم می‌آورد.
        @if($pt['kin']) {{ Fa::num($pt['kin']) }} نسخه که سفارش‌دهنده‌شان «مؤلف»، «خودش» یا یکی از خویشان کاتب («پدرش …») است، در فهرست نام‌ها نیامده‌اند. @endif
        سده‌ها، سدۀ نسخه‌هایی است که کل بازۀ تاریخشان در یک سده است؛ سده‌هایی که کمتر از {{ Fa::num(config('insights.min_sample') * 10) }} نسخه دارند، در نمودار نیامده‌اند.
    </p>
    @include('insights.explain', ['note' => $notes['patrons'], 'tables' => $tables['patrons']])
</x-ui.section>
@endif
