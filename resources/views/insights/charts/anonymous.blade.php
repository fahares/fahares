@php
    use App\Support\Fa;
@endphp
{{-- The works with no author, by subject and by the century of their oldest copy (backlog item 162, step 14.339) --}}
@php $an = $data['anonymous']; @endphp
@if(! $scope->get('author') && ! $scope->get('work'))
<x-ui.section title="آثار بی مؤلف" id="anonymous">
    <x-ui.card>
        @if($an['works'] < $min)
            @include('insights.thin', ['n' => $an['works'], 'what' => 'اثر'])
        @else
            @include('insights.small', ['n' => $an['works'], 'what' => 'اثر'])
            @php
                $cols = array_filter($an['centuries'], fn ($c) => $c['works'] >= config('insights.min_sample'));
                $maxShare = max([1, ...array_column($cols, 'share')]);
            @endphp
            <p class="text-sm text-ink mb-5">
                از {{ Fa::num($an['works']) }} اثر، <span class="font-semibold">{{ Fa::num($an['nameless']['works']) }}</span> اثر
                ({{ $pct(round($an['nameless']['works'] * 100 / max(1, $an['works']), 1)) }}) مؤلفی ندارند.
            </p>
            <div class="grid lg:grid-cols-2 gap-8">
                <div>
                    <p class="text-xs text-muted mb-3">سهم آثار بی مؤلف در آثار هر موضوع، از بیشترین.</p>
                    @if(count($an['subjects']) > 0)
                        <ul class="space-y-2.5 text-sm" data-anonymous-subjects>
                            @foreach($an['subjects'] as $row)
                                <li data-tip="{{ $row['name'] }}: {{ Fa::num($row['anonymous']) }} اثر بی مؤلف از {{ Fa::num($row['works']) }} اثر">
                                    <span class="flex items-baseline gap-2">
                                        <a href="{{ $search('works', ['subject_id' => $row['id']]) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                                        <span class="leader" aria-hidden="true"></span>
                                        <span class="text-xs text-muted tabular-nums">{{ $pct($row['share']) }}</span>
                                    </span>
                                    <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                        <span class="block h-full bg-cinnabar/70 rounded-full" style="width: max(2px, {{ $row['share'] }}%)"></span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-xs text-muted">در این دامنه موضوعی با اثر بسنده نیست.</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs text-muted mb-3">سهم آثار بی مؤلف در آثاری که کهن‌ترین نسخۀ تاریخ‌دارشان از هر سده است.</p>
                    <div class="flex items-end gap-1 h-36 border-b border-line-strong" role="list" aria-label="سهم آثار بی مؤلف در هر سده">
                        @foreach($an['centuries'] as $col)
                            @php $shown = $col['works'] >= config('insights.min_sample'); @endphp
                            <div role="listitem" class="flex-1 h-full flex flex-col justify-end items-center gap-0.5 min-w-0"
                                 data-tip="سدۀ {{ Fa::digits($col['century']) }}: {{ $shown ? Fa::num($col['anonymous']) . ' اثر بی مؤلف از ' . Fa::num($col['works']) . ' اثر (' . $pct($col['share']) . ')' : 'اثر بسنده نیست' }}">
                                @if($shown)
                                    <span class="text-[10px] text-muted tabular-nums">{{ Fa::digits(round($col['share'])) }}</span>
                                    <span class="w-full max-w-8 rounded-t-[3px] bg-gold/70" style="height: {{ round($col['share'] * 100 / $maxShare, 2) }}%"></span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="flex gap-1 mt-1" aria-hidden="true">
                        @foreach($an['centuries'] as $col)
                            <span class="flex-1 text-center text-[10px] text-ink tabular-nums">{{ Fa::digits($col['century']) }}</span>
                        @endforeach
                    </div>
                    <p class="text-center text-[11px] text-muted mt-0.5">سدۀ هجری کهن‌ترین نسخه · سهم به درصد</p>
                </div>
            </div>
            <p class="mt-5 text-xs text-muted leading-relaxed">
                اثر بی مؤلف اثری است که فهرست نام مؤلفی برایش نیاورده است.
                @if($an['unlinked'] > 0){{ Fa::num($an['unlinked']) }} اثر نام مؤلف دارند ولی آن نام رکورد شخص ندارد؛ این‌ها مؤلف‌دار شمرده شده‌اند.@endif
                آثار بی مؤلف {{ Fa::num($an['nameless']['copies']) }} نسخه دارند. موضوع‌هایی که کمتر از {{ Fa::digits(config('insights.anonymous_min_works')) }} اثر و سده‌هایی که کمتر از {{ Fa::digits(config('insights.min_sample')) }} اثر دارند نیامده‌اند.
            </p>
            @include('insights.explain', ['note' => $notes['anonymous'], 'tables' => $tables['anonymous']])
        @endif
    </x-ui.card>
</x-ui.section>
@endif
