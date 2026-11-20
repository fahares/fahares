@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 1c. Years from the author's death to the copy --}}
<x-ui.section title="فاصلۀ کتابت تا وفات مؤلف" id="copy-gap">
    <x-ui.card>
        @if($g['placed'] < $min)
            @include('insights.thin', ['n' => $g['placed'], 'what' => 'نسخه با تاریخ کتابت و وفات مؤلف'])
        @else
        @include('insights.small', ['n' => $g['placed'], 'what' => 'نسخه با تاریخ کتابت و وفات مؤلف'])
        @php $maxBand = max(1, ...array_column($g['bands'], 'count')); @endphp
        <p class="text-xs text-muted mb-4">
            چند سال پس از وفات مؤلف از اثرش نسخه برداشته‌اند.
            @if($lifetime && $g['placed'])
                {{ $pct(round($lifetime['count'] * 100 / $g['placed'], 1)) }} نسخه‌ها در زمان حیات مؤلف کتابت شده‌اند.
            @endif
        </p>
        <ul class="space-y-2.5 text-sm">
            @foreach($g['bands'] as $band)
                @php
                    $name = match (true) {
                        $band['above'] === null => 'بیش از ' . Fa::digits(-$band['upto']) . ' سال پیش از وفات',
                        $band['upto'] === 0 => 'در زمان حیات مؤلف',
                        $band['upto'] === null => 'بیش از ' . Fa::digits($band['above']) . ' سال پس از وفات',
                        default => Fa::digits($band['above'] + 1) . ' تا ' . Fa::digits($band['upto']) . ' سال پس از وفات',
                    };
                    $odd = $band['above'] === null;
                @endphp
                {{-- The two shades of the bar in figures: the certain dates and the doubtful ones --}}
                <li @class(['text-muted' => $odd]) data-tip="{{ $name }}: {{ Fa::num($band['certain']) }} نسخه با تاریخ قطعی{{ $band['doubtful'] ? ' و ' . Fa::num($band['doubtful']) . ' نسخه با تاریخ مردد' : '' }}">
                    <span class="flex items-baseline gap-2">
                        <span @class(['text-ink' => ! $odd])>{{ $name }}</span>
                        <span class="leader" aria-hidden="true"></span>
                        <span class="text-xs text-muted tabular-nums">{{ Fa::num($band['count']) }} · {{ $pct(round($band['count'] * 100 / max(1, $g['placed']), 1)) }}</span>
                    </span>
                    <span class="mt-1 flex h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                        <span class="block h-full {{ $odd ? 'bg-muted/60' : 'bg-cinnabar/75' }}" style="width: {{ round($band['certain'] * 100 / $maxBand, 2) }}%"></span>
                        <span class="block h-full {{ $odd ? 'bg-muted/25' : 'bg-cinnabar/30' }}" style="width: {{ round($band['doubtful'] * 100 / $maxBand, 2) }}%"></span>
                    </span>
                </li>
            @endforeach
        </ul>
        <p class="mt-5 text-xs text-muted leading-relaxed">
            بر پایۀ {{ Fa::num($g['placed']) }} نسخه که هم تاریخ کتابتشان و هم وفات مؤلفشان ثبت شده است.
            سال معین به همان سال و سده به همۀ سال‌هایش گرفته شده است. {{ Fa::num($g['unplaced']) }} نسخۀ دیگر که فاصله‌شان با این حساب در یک ردیف نمی‌گنجد (مانند نسخۀ «قرن ۱۳» از مؤلفِ درگذشتۀ ۱۲۵۰)، جدا شمرده شده‌اند.
            {{ Fa::num($g['doubtful']) }} نسخه ({{ $pct($g['doubtful_share']) }}) تاریخ کتابت یا وفات مردد دارند و کم‌رنگ آمده‌اند.
            ردیف نخست، کتابتِ بیش از {{ Fa::digits(-config('insights.copy_gap')[0]) }} سال پیش از وفات، نشان خطایی در تاریخ یا در نسبت اثر به مؤلف است.
        </p>
        @include('insights.explain', ['note' => $notes['copyGap'], 'tables' => $tables['copyGap']])
        @endif
    </x-ui.card>
</x-ui.section>
