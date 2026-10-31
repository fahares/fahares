{{--
    Stacked bars per Hijri century (Insights::centuryLayers): a known year, then only a century or a part of one,
    each certain and then doubtful in a paler shade. $rows: the centuries; $unit: «نسخه», «مؤلف» …; $href: century →
    search link; $label: the chart's aria-label.
--}}
@php
    use App\Support\Fa;

    $max = max(array_column($rows, 'count')) ?: 1;
    $layers = [
        'exact' => ['سال معین', 'bg-cinnabar/75'],
        'exact_doubtful' => ['سال معین، مردد', 'bg-cinnabar/30'],
        'range' => ['تنها سده یا بخشی از آن', 'bg-gold/80'],
        'range_doubtful' => ['تنها سده، مردد', 'bg-gold/35'],
    ];
@endphp
<div class="flex items-end gap-1 sm:gap-2 h-56 border-b border-line-strong" role="list" aria-label="{{ $label }}">
    @foreach($rows as $row)
        @php
            $tip = 'سده‌ی ' . Fa::digits($row['century']) . ': ' . Fa::num($row['count']) . ' ' . $unit;
            foreach ($layers as $key => [$name]) {
                if ($row[$key] > 0) {
                    $tip .= ' · ' . $name . ' ' . Fa::num($row[$key]);
                }
            }
        @endphp
        <a role="listitem" href="{{ $href($row['century']) }}"
           class="group flex-1 h-full flex flex-col justify-end items-center gap-1 min-w-0 focus:outline-none"
           title="{{ $tip }}" aria-label="{{ $tip }}">
            <span class="text-[10px] sm:text-xs text-muted tabular-nums whitespace-nowrap group-hover:text-cinnabar">{{ Fa::approx($row['count']) }}</span>
            <span class="w-full max-w-12 flex flex-col-reverse rounded-t-[4px] overflow-hidden group-hover:brightness-90 group-focus-visible:ring-2 group-focus-visible:ring-gold transition"
                  style="height: max({{ $row['count'] ? 2 : 0 }}px, {{ round($row['count'] * 100 / $max, 2) }}%)">
                @foreach($layers as $key => [, $class])
                    @if($row[$key] > 0)
                        <span class="block w-full shrink-0 {{ $class }}" style="height: {{ round($row[$key] * 100 / max(1, $row['count']), 3) }}%"></span>
                    @endif
                @endforeach
            </span>
        </a>
    @endforeach
</div>
<div class="flex gap-1 sm:gap-2 mt-1.5" aria-hidden="true">
    @foreach($rows as $row)
        <span class="flex-1 text-center text-xs text-ink tabular-nums">{{ Fa::digits($row['century']) }}</span>
    @endforeach
</div>
<p class="text-center text-[11px] text-muted mt-0.5">سده‌ی هجری</p>
<ul class="mt-3 flex flex-wrap justify-center gap-x-4 gap-y-1 text-[11px] text-muted" aria-label="راهنمای نمودار">
    @foreach($layers as $key => [$name, $class])
        @if(array_sum(array_column($rows, $key)) > 0)
            <li class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm {{ $class }}" aria-hidden="true"></span>{{ $name }}</li>
        @endif
    @endforeach
</ul>
