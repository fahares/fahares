{{--
    A grid of rows (subjects, features, languages) across Hijri centuries (Insights::centuryGrid), in two readings:
    the number of copies, shaded against the row's largest, which shows where a row's copies are; and the share of
    each century's copies, which shows a row's rise and fall. A share of a century with few copies says little and
    looked like a peak (12 of the 56 copies of the 4th century illuminated, darker than 7 821 in the 13th), so in that
    reading a century below grid_min_copies keeps its number but not its shade. $grid; $label: the table's caption;
    $href: (row key, century) → search link.
--}}
@php
    use App\Support\Fa;

    $min = config('insights.min_sample');
    $firm = config('insights.grid_min_copies');
    $pct = fn ($v) => Fa::digits(str_replace('.', '٫', (string) $v)) . '٪';
    $firmCols = array_column(array_filter($grid['centuries'], fn ($c) => $c['count'] >= $firm), 'century');
@endphp
<div x-data="{ mode: 'count' }">
    <div class="flex flex-wrap items-center gap-1.5 text-xs mb-3">
        <span class="text-muted me-1">رنگ و عدد هر خانه:</span>
        @foreach(['count' => 'شمار نسخه‌ها', 'share' => 'سهم از نسخه‌های آن سده'] as $key => $name)
            <button type="button" @click="mode = '{{ $key }}'" :aria-pressed="(mode === '{{ $key }}').toString()"
                    :class="mode === '{{ $key }}' ? 'bg-ink text-paper border-ink' : 'bg-surface text-ink border-line hover:border-ink'"
                    class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
        @endforeach
    </div>
    <div class="overflow-x-auto -mx-1 px-1">
        <table class="w-full min-w-[40rem] text-xs border-separate border-spacing-0.5">
            <caption class="sr-only">{{ $label }}</caption>
            <thead>
                <tr class="text-muted">
                    <th scope="col" class="text-right font-semibold pb-0 pe-2">سده</th>
                    @foreach($grid['centuries'] as $col)
                        <th scope="col" class="font-semibold tabular-nums pb-0 text-center {{ $col['count'] < $min ? 'opacity-50' : 'text-ink' }}">{{ Fa::digits($col['century']) }}</th>
                    @endforeach
                </tr>
                <tr class="text-muted">
                    <th scope="col" class="text-right font-normal text-[10px] pb-1 pe-2">نسخه‌ها</th>
                    @foreach($grid['centuries'] as $col)
                        <th scope="col" class="font-normal text-[10px] tabular-nums pb-1 text-center" title="{{ Fa::num($col['count']) }} نسخه در این سده">{{ Fa::approx($col['count']) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($grid['rows'] as $row)
                    @php
                        $topCount = max([1, ...array_map(fn ($c) => $c['count'], $row['cells'])]);
                        $topShare = max([0.1, ...array_map(fn ($c) => $row['cells'][$c]['share'], $firmCols)]);
                    @endphp
                    <tr>
                        <th scope="row" class="text-right font-normal text-ink pe-2 py-0.5 whitespace-nowrap max-w-40 truncate" title="{{ $row['name'] }}: {{ Fa::num($row['total']) }}">{{ $row['name'] }}</th>
                        @foreach($grid['centuries'] as $col)
                            @php
                                $cell = $row['cells'][$col['century']];
                                $tip = $row['name'] . '، سده‌ی ' . Fa::digits($col['century']) . ': ' . Fa::num($cell['count']) . ' از ' . Fa::num($col['count']) . ' نسخه (' . $pct($cell['share']) . ')'
                                    . ($col['count'] < $firm ? '؛ سده‌ی کم‌نسخه' : '');
                                $toneCount = $cell['count'] ? round(8 + 72 * $cell['count'] / $topCount) : 0;
                                $toneShare = $cell['count'] && $col['count'] >= $firm ? round(8 + 72 * $cell['share'] / $topShare) : 0;
                            @endphp
                            <td class="p-0 text-center">
                                @if($col['count'] < $min)
                                    <span class="block py-1.5 rounded-sm bg-line/40 text-muted" title="{{ $tip }}">·</span>
                                @else
                                    <a href="{{ $href($row['key'], $col['century']) }}" title="{{ $tip }}" aria-label="{{ $tip }}"
                                       class="block py-1.5 rounded-sm tabular-nums hover:ring-1 hover:ring-cinnabar focus-visible:ring-2 focus-visible:ring-gold focus:outline-none"
                                       style="background: color-mix(in srgb, var(--cinnabar) {{ $toneCount }}%, transparent)"
                                       :style="`background: color-mix(in srgb, var(--cinnabar) ${mode === 'count' ? {{ $toneCount }} : {{ $toneShare }}}%, transparent)`"
                                       :class="(mode === 'count' ? {{ $toneCount }} : {{ $toneShare }}) > 50 ? 'text-on-cinnabar' : '{{ $col['count'] < $firm ? 'text-muted' : 'text-ink' }}'">
                                        <span x-show="mode === 'count'">{{ $cell['count'] ? Fa::num($cell['count']) : '' }}</span>
                                        <span x-show="mode === 'share'" x-cloak>{{ $cell['count'] ? Fa::digits(round($cell['share'])) : '' }}</span>
                                    </a>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-[11px] text-muted leading-relaxed">
        <span x-show="mode === 'count'">هر خانه شمار نسخه‌های آن ردیف در آن سده است؛ پررنگ‌ترین خانه‌ی هر ردیف سده‌ای است که بیشترین نسخه‌های آن ردیف را دارد.</span>
        <span x-show="mode === 'share'" x-cloak>هر خانه سهم ردیف از نسخه‌های آن سده است، به درصد؛ پررنگ‌ترین خانه‌ی هر ردیف بیشترین سهم آن است. سده‌هایی که کمتر از {{ Fa::num($firm) }} نسخه دارند رنگ نگرفته‌اند، چون سهمِ چند نسخه گواه چیزی نیست.</span>
        سده‌هایی که کمتر از {{ Fa::digits($min) }} نسخه دارند خالی مانده‌اند.
    </p>
</div>
