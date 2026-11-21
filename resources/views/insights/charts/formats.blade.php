@php
    use App\Support\Fa;
@endphp
{{-- The page of the copies: height bands, the usual sizes, and the mean page by century and subject (step 14.314) --}}
@php
    $fm = $data['formats'];
    $cm = fn ($v) => Fa::digits(str_replace('.', '٫', (string) $v));
    $bandLabel = fn (array $band) => match (true) {
        $band['upto'] === null => 'بلندتر از ' . Fa::digits($band['above']),
        $band['above'] === 0 => 'تا ' . Fa::digits($band['upto']),
        default => 'بیش از ' . Fa::digits($band['above']) . ' تا ' . Fa::digits($band['upto']),
    };
@endphp
<x-ui.section title="قطع نسخه‌ها" id="formats">
    <x-ui.card>
        @if($fm['with_size'] < $min)
            @include('insights.thin', ['n' => $fm['with_size'], 'what' => 'نسخه با ابعاد'])
        @else
            @include('insights.small', ['n' => $fm['with_size'], 'what' => 'نسخه با ابعاد'])
            @php
                $maxBand = max([1, ...array_column($fm['bands'], 'count')]);
                $cols = array_filter($fm['centuries'], fn ($c) => $c['count'] >= config('insights.min_sample'));
                $maxHeight = max([1, ...array_column($cols, 'height')]);
                $maxSubject = max([1, ...array_column($fm['subjects'], 'height')]);
            @endphp
            <div class="grid lg:grid-cols-2 gap-8">
                <div>
                    <p class="text-xs text-muted mb-3">شمار نسخه‌ها بر پایۀ بلندی صفحه، به سانتی‌متر. میانۀ بلندی {{ $cm($fm['median']) }} سانتی‌متر است.</p>
                    <ul class="space-y-2.5 text-sm">
                        @foreach($fm['bands'] as $band)
                            <li>
                                <span class="flex items-baseline gap-2">
                                    <span class="text-ink">{{ $bandLabel($band) }} سانتی‌متر</span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums">{{ Fa::num($band['count']) }} · {{ $pct(round($band['count'] * 100 / max(1, $fm['with_size']), 1)) }}</span>
                                </span>
                                <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                    <span class="block h-full bg-cinnabar/70 rounded-full" style="width: max(2px, {{ round($band['count'] * 100 / $maxBand, 2) }}%)"></span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if($fm['common'])
                        <p class="text-xs text-muted mt-6 mb-2">پرشمارترین اندازه‌ها (پهنا × بلندی، سانتی‌متر)</p>
                        <ul class="flex flex-wrap gap-1.5 text-xs" data-format-common>
                            @foreach($fm['common'] as $size)
                                <li class="border border-line bg-paper px-2 py-1 tabular-nums"><span class="text-ink" dir="ltr">{{ $cm($size['width']) }}×{{ $cm($size['height']) }}</span> <span class="text-muted">· {{ Fa::num($size['count']) }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div>
                    <p class="text-xs text-muted mb-3">میانگین بلندی صفحۀ نسخه‌های هر سده، و زیر آن میانگین پهنا.</p>
                    <div class="flex items-end gap-1 h-36 border-b border-line-strong" role="list" aria-label="میانگین بلندی صفحه در هر سده">
                        @foreach($fm['centuries'] as $col)
                            @php $shown = $col['count'] >= config('insights.min_sample'); @endphp
                            <div role="listitem" class="flex-1 h-full flex flex-col justify-end items-center gap-0.5 min-w-0"
                                 data-tip="سدۀ {{ Fa::digits($col['century']) }}: {{ $shown ? 'میانگین ' . $cm($col['width']) . ' در ' . $cm($col['height']) . ' سانتی‌متر از ' . Fa::num($col['count']) . ' نسخه' : 'نسخۀ بسنده نیست' }}">
                                @if($shown)
                                    <span class="text-[10px] text-muted tabular-nums">{{ $cm($col['height']) }}</span>
                                    <span class="w-full max-w-8 rounded-t-[3px] bg-gold/70" style="height: {{ round($col['height'] * 100 / $maxHeight, 2) }}%"></span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="flex gap-1 mt-1" aria-hidden="true">
                        @foreach($fm['centuries'] as $col)
                            <span class="flex-1 text-center text-[10px] text-ink tabular-nums">{{ Fa::digits($col['century']) }}</span>
                        @endforeach
                    </div>
                    <div class="flex gap-1 mt-0.5" aria-label="میانگین پهنای صفحه در هر سده">
                        @foreach($fm['centuries'] as $col)
                            <span class="flex-1 text-center text-[10px] text-muted tabular-nums" data-tip="میانگین پهنا در سدۀ {{ Fa::digits($col['century']) }}">{{ $col['count'] >= config('insights.min_sample') ? $cm($col['width']) : '·' }}</span>
                        @endforeach
                    </div>
                    <p class="text-center text-[11px] text-muted mt-0.5">سدۀ هجری · پهنا به سانتی‌متر</p>
                </div>
            </div>
            @if(count($fm['subjects']) > 1)
                <p class="text-xs text-muted mt-8 mb-3">میانگین بلندی صفحه در نسخه‌های هر موضوع، از بلندترین.</p>
                <ul class="grid sm:grid-cols-2 gap-x-8 gap-y-2.5 text-sm" data-format-subjects>
                    @foreach($fm['subjects'] as $row)
                        <li data-tip="{{ $row['name'] }}: میانگین {{ $cm($row['width']) }} در {{ $cm($row['height']) }} سانتی‌متر از {{ Fa::num($row['count']) }} نسخه">
                            <span class="flex items-baseline gap-2">
                                <a href="{{ $search('manuscripts', ['subject_id' => $row['id']]) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-xs text-muted tabular-nums">{{ $cm($row['height']) }} سانتی‌متر</span>
                            </span>
                            <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                <span class="block h-full bg-gold/70 rounded-full" style="width: {{ round($row['height'] * 100 / $maxSubject, 2) }}%"></span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایۀ {{ Fa::num($fm['with_size']) }} نسخه که ابعادشان به سانتی‌متر ثبت شده است. بلندی، ضلع بلندتر صفحه گرفته شده است، هر کدام که نخست نوشته شده باشد.
                @if($fm['left_out'] > 0){{ Fa::num($fm['left_out']) }} نسخه که ابعادشان کمتر از {{ Fa::digits(config('insights.page_cm')[0]) }} یا بیش از {{ Fa::digits(config('insights.page_cm')[1]) }} سانتی‌متر آمده کنار گذاشته شده‌اند.@endif
                سده‌ها و موضوع‌هایی که کمتر از {{ Fa::digits(config('insights.min_sample')) }} نسخه دارند نیامده‌اند.
            </p>
            @include('insights.explain', ['note' => $notes['formats'], 'tables' => $tables['formats']])
        @endif
    </x-ui.card>
</x-ui.section>
