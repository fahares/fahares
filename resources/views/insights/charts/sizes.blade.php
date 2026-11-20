@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2f. Size of the copies --}}
@php $sz = $data['sizes']; @endphp
<x-ui.section title="اندازۀ نسخه‌ها" id="sizes">
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
                    <p class="text-xs text-muted mb-3">شمار نسخه‌ها در هر اندازه، به برگ. میانۀ شمار برگ {{ Fa::num($sz['median']) }} برگ است.</p>
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
                                 data-tip="سدۀ {{ Fa::digits($col['century']) }}: {{ $shown ? 'میانگین ' . Fa::num($col['folios']) . ' برگ از ' . Fa::num($col['with_folios']) . ' نسخه' : 'نسخۀ بسنده نیست' }}">
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
                            <span class="flex-1 text-center text-[10px] text-muted tabular-nums" data-tip="میانگین سطر در سدۀ {{ Fa::digits($col['century']) }}">{{ $col['with_lines'] >= config('insights.min_sample') ? Fa::digits(str_replace('.', '٫', (string) $col['lines'])) : '·' }}</span>
                        @endforeach
                    </div>
                    <p class="text-center text-[11px] text-muted mt-0.5">سدۀ هجری · سطر در صفحه</p>
                </div>
            </div>
            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایۀ {{ Fa::num($sz['with_folios']) }} نسخه که شمار برگشان ثبت شده و {{ Fa::num($sz['with_lines']) }} نسخه که شمار سطرشان ثبت شده است.
                در مجموعه‌ها، شمار برگ گاه برگ‌های همان رساله است و گاه برگ‌های کل مجموعه، بسته به فهرست. سده‌هایی که کمتر از {{ Fa::digits(config('insights.min_sample')) }} نسخه دارند نیامده‌اند.
            </p>
            @include('insights.explain', ['note' => $notes['sizes'], 'tables' => $tables['sizes']])
        @endif
    </x-ui.card>
</x-ui.section>
