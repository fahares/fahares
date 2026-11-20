@php
    use App\Support\Fa;
@endphp
{{-- 1a. The copies made under each dynasty (backlog item 154, step 14.265) --}}
@php $dy = $data['dynasties']; @endphp
<x-ui.section title="نسخه‌ها در روزگار هر سلسله" id="dynasties">
    <x-ui.card>
        @if($dy['ruled'] < $min)
            @include('insights.thin', ['n' => $dy['ruled'], 'what' => 'نسخه با سال و محل کتابت'])
        @else
            @include('insights.small', ['n' => $dy['ruled'], 'what' => 'نسخه با سال و محل کتابت'])
            @php $maxDynasty = max([1, ...array_column($dy['items'], 'count')]); @endphp
            <p class="text-xs text-muted mb-4">
                هر نسخه به حکومتی داده شده که شهر کتابتش در سال کتابت در قلمرو آن بوده است. کنار هر سلسله، سال نخستین و واپسین نسخه و شهرهایی که بیشترین نسخه را داده‌اند آمده است.
            </p>
            <ul class="space-y-3 text-sm">
                @foreach($dy['items'] as $row)
                    <li>
                        <span class="flex flex-wrap items-baseline gap-x-2">
                            <span class="text-ink font-semibold">{{ $row['name'] }}</span>
                            <span class="text-[11px] text-muted tabular-nums">{{ $row['from'] === $row['to'] ? Fa::digits($row['from']) : Fa::digits($row['from']) . ' تا ' . Fa::digits($row['to']) }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums">{{ Fa::num($row['count']) }} · {{ $pct(round($row['count'] * 100 / max(1, $dy['ruled']), 1)) }}</span>
                        </span>
                        <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full bg-cinnabar/70 rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / $maxDynasty, 2) }}%)"></span>
                        </span>
                        <span class="mt-1 block text-xs text-muted">
                            @foreach($row['cities'] as $city)
                                <a href="{{ $search('manuscripts', ['copy_city' => $city['name'], 'year_from' => $row['from'], 'year_to' => $row['to']]) }}" class="hover:text-cinnabar">{{ $city['name'] }}</a>
                                <span class="tabular-nums">{{ Fa::num($city['count']) }}</span>{{ $loop->last ? '' : '،' }}
                            @endforeach
                            @if($row['city_kinds'] > count($row['cities'])) و {{ Fa::num($row['city_kinds'] - count($row['cities'])) }} شهر دیگر @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایۀ {{ Fa::num($dy['ruled']) }} نسخه، یعنی تنها {{ $pct($dy['coverage']) }} نسخه‌ها: نسخه‌هایی که هم سال کتابت معین دارند و هم شهر کتابتشان روی نقشه است.
                @if($dy['counted'] > $dy['ruled']){{ Fa::num($dy['counted'] - $dy['ruled']) }} نسخۀ دیگر با همین دو آگاهی در دهه‌ای کتابت شده‌اند که برای شهرشان حکومتی شناخته نیست.@endif
                @if($dy['kinds'] > count($dy['items'])){{ Fa::num(count($dy['items'])) }} سلسلۀ پرنسخه از {{ Fa::num($dy['kinds']) }} آمده است.@endif
                قلمرو هر حکومت از نقشه‌های تاریخی آمار دواوین است (مرزها: Cliopatria، بانک دادۀ Seshat)، به دقت دهه؛ مرز حکومت‌های پیشامدرن تقریبی است و چند سلسلۀ محلی هنوز مرز ندارند.
            </p>
            @include('insights.explain', ['note' => $notes['dynasties'], 'tables' => $tables['dynasties']])
        @endif
    </x-ui.card>
</x-ui.section>
