@php
    use App\Support\Fa;
@endphp
{{-- What the catalogues wrote of each copy (backlog item 162, step 14.348) --}}
@php
    $cp = $data['completeness'];
    $absent = ['date' => 'بی‌تا', 'scribe' => 'بی‌کا'];
    $grids = array_filter([
        ['title' => 'بزرگ‌ترین کتابخانه‌ها', 'head' => 'کتابخانه', 'rows' => $cp['libraries'], 'route' => 'libraries.show'],
        ['title' => 'پرکارترین فهرست‌نگاران', 'head' => 'فهرست‌نگار', 'rows' => $cp['catalogers'], 'route' => 'catalogers.show'],
    ], fn ($g) => count($g['rows']) > 1);
@endphp
<x-ui.section title="فهرست‌ها از هر نسخه چه نوشته‌اند" id="completeness">
    <x-ui.card>
        @if($cp['copies'] < $min)
            @include('insights.thin', ['n' => $cp['copies'], 'what' => 'نسخه'])
        @else
            @include('insights.small', ['n' => $cp['copies'], 'what' => 'نسخه'])
            <p class="text-xs text-muted mb-4">سهم نسخه‌هایی که فهرست برایشان هر یک از این داده‌ها را نوشته است.</p>
            <ul class="space-y-2.5 text-sm" data-completeness>
                @foreach($cp['fields'] as $f)
                    @php
                        $gap = $cp['absent'][$f['key']] ?? null;
                        $tip = $f['name'] . ': ' . Fa::num($f['count']) . ' از ' . Fa::num($cp['copies']) . ' نسخه (' . $pct($f['share']) . ')';
                    @endphp
                    <li data-tip="{{ $tip }}">
                        <div class="flex items-baseline gap-2">
                            <span class="text-ink whitespace-nowrap">{{ $f['name'] }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums">{{ Fa::num($f['count']) }} نسخه · {{ $pct($f['share']) }}</span>
                        </div>
                        <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full bg-cinnabar/60 rounded-full" style="width: {{ $f['share'] }}%"></span>
                        </span>
                        @if($gap)
                            <span class="mt-1 block text-[11px] text-muted">
                                «{{ $absent[$f['key']] }}» در فهرست: {{ Fa::num($gap['stated']) }} نسخه؛ بی هیچ سخنی: {{ Fa::num($gap['silent']) }} نسخه
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
    @if($cp['copies'] >= $min)
        @foreach($grids as $grid)
            <x-ui.card class="mt-6">
                <p class="text-xs text-muted mb-3">{{ $grid['title'] }}: درصد نسخه‌هایی که هر داده را دارند.</p>
                <div class="overflow-x-auto -mx-1 px-1">
                    <table class="w-full min-w-[44rem] text-xs border-separate border-spacing-0.5" data-completeness-grid>
                        <caption class="sr-only">{{ $grid['title'] }}: سهم نسخه‌هایی که هر داده را دارند</caption>
                        <thead>
                            <tr class="text-muted">
                                <th scope="col" class="text-right font-semibold pb-1 pe-2">{{ $grid['head'] }}</th>
                                <th scope="col" class="font-semibold pb-1 text-left pe-2">نسخه</th>
                                @foreach($cp['fields'] as $f)
                                    <th scope="col" class="font-semibold pb-1 text-center">{{ $f['name'] }}</th>
                                @endforeach
                                <th scope="col" class="font-semibold pb-1 text-center">میانگین</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($grid['rows'] as $row)
                                <tr>
                                    <th scope="row" class="text-right font-normal pe-2 py-0.5 whitespace-nowrap max-w-56 truncate">
                                        <a href="{{ route($grid['route'], $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}{{ ($row['city'] ?? null) ? '، ' . $row['city'] : '' }}</a>
                                    </th>
                                    <td class="text-left pe-2 text-muted tabular-nums">{{ Fa::num($row['count']) }}</td>
                                    @foreach($cp['fields'] as $f)
                                        @php
                                            $share = $row['shares'][$f['key']];
                                            $tone = round(4 + 72 * $share / 100);
                                        @endphp
                                        <td class="p-0 text-center">
                                            <span class="block py-1.5 rounded-sm tabular-nums {{ $tone > 50 ? 'text-on-cinnabar' : 'text-ink' }}"
                                                  style="background: color-mix(in srgb, var(--cinnabar) {{ $tone }}%, transparent)"
                                                  data-tip="{{ $row['name'] }}، {{ $f['name'] }}: {{ $pct($share) }} از {{ Fa::num($row['count']) }} نسخه">{{ Fa::digits(round($share)) }}</span>
                                        </td>
                                    @endforeach
                                    <td class="text-center text-muted tabular-nums">{{ Fa::digits(round($row['mean'])) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endforeach
        <p class="mt-3 text-xs text-muted leading-relaxed">
            بر پایۀ {{ Fa::num($cp['copies']) }} نسخه. «شمار برگ» نسخه‌ای را هم می‌گیرد که فهرست شمار صفحه‌اش را داده است.
            در جدول‌ها تنها کتابخانه و فهرست‌نگاری آمده که دست‌کم {{ Fa::num($cp['min_copies']) }} نسخه در این دامنه دارد؛ سهم‌های هر جلد فهرست در جدول «سهم هر داده در هر جلد فهرست» در زیر آمده است.
            نبودِ تاریخ یا نام کاتب بیشتر از خودِ نسخه است تا از فهرست.
        </p>
        @include('insights.explain', ['note' => $notes['completeness'], 'tables' => $tables['completeness']])
    @endif
</x-ui.section>
