@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 3b. Copies per work, and the works of each century (backlog item 146) --}}
@php $pw = $data['copies_per_work']; $cw = $data['century_works']; @endphp
<x-ui.section title="هر اثر چند نسخه دارد" id="copies-per-work">
    <x-ui.card>
        @if($pw['works'] < $min)
            @include('insights.thin', ['n' => $pw['works'], 'what' => 'اثر'])
        @else
            @include('insights.small', ['n' => $pw['works'], 'what' => 'اثر'])
            @php $maxBandWorks = max([1, ...array_column($pw['bands'], 'works')]); $maxBandCopies = max([1, ...array_column($pw['bands'], 'copies')]); @endphp
            <p class="text-xs text-muted mb-3">
                آثار بر پایۀ شمار نسخه‌هایشان{{ $scope->isEmpty() ? '' : ' در این دامنه' }}. نوار بالا شمار آثار هر دسته است و نوار پایین نسخه‌هایی که آن آثار دارند.
            </p>
            <ul class="space-y-3 text-sm">
                @foreach($pw['bands'] as $band)
                    @continue($band['works'] === 0)
                    <li>
                        <span class="flex items-baseline gap-2">
                            <span class="text-ink">
                                @if($band['upto'] === null) بیش از {{ Fa::digits($band['above']) }} نسخه
                                @elseif($band['upto'] === $band['above'] + 1) {{ $band['upto'] === 1 ? 'تک‌نسخه' : Fa::digits($band['upto']) . ' نسخه' }}
                                @else {{ Fa::digits($band['above'] + 1) }} تا {{ Fa::digits($band['upto']) }} نسخه
                                @endif
                            </span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($band['works']) }} اثر ({{ $pct(round($band['works'] * 100 / max(1, $pw['works']), 1)) }}) · {{ Fa::num($band['copies']) }} نسخه ({{ $pct(round($band['copies'] * 100 / max(1, $pw['copies']), 1)) }})</span>
                        </span>
                        <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full bg-cinnabar/70 rounded-full" style="width: max(2px, {{ round($band['works'] * 100 / $maxBandWorks, 2) }}%)"></span>
                        </span>
                        <span class="mt-0.5 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full bg-gold/80 rounded-full" style="width: max(2px, {{ round($band['copies'] * 100 / $maxBandCopies, 2) }}%)"></span>
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-xs text-muted leading-relaxed">
                <span class="inline-block w-2.5 h-2.5 bg-cinnabar/70 rounded-full align-middle" aria-hidden="true"></span> آثار
                <span class="inline-block w-2.5 h-2.5 bg-gold/80 rounded-full align-middle ms-2" aria-hidden="true"></span> نسخه‌ها ·
                از {{ Fa::num($pw['works']) }} اثرِ نسخه‌دار، {{ Fa::num($pw['single']) }} اثر ({{ $pct(round($pw['single'] * 100 / max(1, $pw['works']), 1)) }}) تنها یک نسخه دارند.
                @if($pw['works'] >= 100)
                    @foreach($pw['top'] as $top)
                        پرنسخه‌ترین {{ $top['percent'] === 1 ? 'یک' : 'ده' }} درصدِ آثار ({{ Fa::num($top['works']) }} اثر) {{ $pct($top['share']) }} نسخه‌ها را دارند{{ $loop->last ? '.' : '؛' }}
                    @endforeach
                @endif
            </p>
            @include('insights.explain', ['note' => $notes['copiesPerWork'], 'tables' => $tables['copiesPerWork']])
        @endif
    </x-ui.card>
</x-ui.section>
