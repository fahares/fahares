{{--
    A grid of rows (subjects, features) across Hijri centuries (Insights::centuryGrid): each cell is the row's share of
    the century's copies, shaded against the row's own largest share, so each row shows its rise and fall. A century
    with fewer than min_sample copies is greyed. $grid: the grid; $label: the table's caption; $href: (row key, century)
    → search link.
--}}
@php
    use App\Support\Fa;

    $min = config('insights.min_sample');
    $pct = fn ($v) => Fa::digits(str_replace('.', '٫', (string) $v)) . '٪';
    $dense = array_column(array_filter($grid['centuries'], fn ($c) => $c['count'] >= $min), 'century');
@endphp
<div class="overflow-x-auto -mx-1 px-1">
    <table class="w-full min-w-[40rem] text-xs border-separate border-spacing-0.5">
        <caption class="sr-only">{{ $label }}</caption>
        <thead>
            <tr class="text-muted">
                <th scope="col" class="text-right font-semibold pb-1 pe-2">سده</th>
                @foreach($grid['centuries'] as $col)
                    <th scope="col" class="font-semibold tabular-nums pb-1 text-center {{ $col['count'] < $min ? 'opacity-50' : 'text-ink' }}"
                        title="{{ Fa::num($col['count']) }} نسخه">{{ Fa::digits($col['century']) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($grid['rows'] as $row)
                @php $top = max([0.1, ...array_map(fn ($c) => $row['cells'][$c]['share'], $dense)]); @endphp
                <tr>
                    <th scope="row" class="text-right font-normal text-ink pe-2 py-0.5 whitespace-nowrap max-w-40 truncate" title="{{ $row['name'] }}: {{ Fa::num($row['total']) }}">{{ $row['name'] }}</th>
                    @foreach($grid['centuries'] as $col)
                        @php
                            $cell = $row['cells'][$col['century']];
                            $thin = $col['count'] < $min;
                            $tip = $row['name'] . '، سده‌ی ' . Fa::digits($col['century']) . ': ' . Fa::num($cell['count']) . ' از ' . Fa::num($col['count']) . ' نسخه (' . $pct($cell['share']) . ')';
                            $tone = $thin || ! $cell['count'] ? 0 : round(8 + 72 * $cell['share'] / $top);
                        @endphp
                        <td class="p-0 text-center">
                            @if($thin)
                                <span class="block py-1.5 rounded-sm bg-line/40 text-muted" title="{{ $tip }}">·</span>
                            @else
                                <a href="{{ $href($row['key'], $col['century']) }}" title="{{ $tip }}" aria-label="{{ $tip }}"
                                   class="block py-1.5 rounded-sm tabular-nums hover:ring-1 hover:ring-cinnabar focus-visible:ring-2 focus-visible:ring-gold focus:outline-none {{ $tone > 50 ? 'text-on-cinnabar' : 'text-ink' }}"
                                   style="background: color-mix(in srgb, var(--cinnabar) {{ $tone }}%, transparent)">{{ $cell['count'] ? Fa::digits(round($cell['share'])) : '' }}</a>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<p class="mt-2 text-[11px] text-muted">هر خانه سهم ردیف از نسخه‌های آن سده است، به درصد؛ پررنگ‌ترین خانه‌ی هر ردیف بیشترین سهم آن ردیف است. سده‌هایی که کمتر از {{ Fa::digits($min) }} نسخه دارند، کم‌رنگ آمده‌اند.</p>
