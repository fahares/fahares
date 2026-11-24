@php
    use App\Support\Fa;
    use App\Support\Insights;
@endphp
{{-- The texts other works are made on: commentaries, glosses, translations (backlog item 162, step 14.350) --}}
@php
    $dv = $data['derived'];
    $typeNames = Insights::DERIVED;
@endphp
@if(! $scope->get('work') && count($dv['items']) > 1)
<x-ui.section title="متن‌های پرشرح، پرحاشیه و پرترجمه" id="derived">
    <x-ui.card class="!p-0 overflow-x-auto">
        @php $maxDerived = max(1, $dv['items'][0]['count']); @endphp
        <table class="w-full text-sm" data-derived>
            <thead class="text-xs text-muted border-b border-line">
                <tr>
                    <th scope="col" class="px-3 sm:px-4 py-2.5 text-right font-semibold w-10">#</th>
                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">متن</th>
                    <th scope="col" class="px-3 sm:px-4 py-2.5 text-left font-semibold w-28 sm:w-56">اثر بر پایۀ آن</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dv['items'] as $i => $work)
                    @php
                        $parts = [];
                        foreach ($work['types'] as $type => $n) {
                            $parts[] = Fa::num($n) . ' ' . $typeNames[$type];
                        }
                    @endphp
                    <tr class="border-b border-line last:border-0 hover:bg-paper">
                        <td class="px-3 sm:px-4 py-2.5 text-muted tabular-nums align-top">{{ Fa::digits($i + 1) }}</td>
                        <td class="px-3 py-2.5 align-top">
                            <a href="{{ route('works.show', $work['key']) }}" class="font-semibold text-ink hover:text-cinnabar transition">{{ $work['title'] }}</a>
                            @if($work['author'])
                                <span class="block text-xs text-muted mt-0.5">
                                    @if($work['author_key'])
                                        <a href="{{ route('people.show', $work['author_key']) }}" class="hover:text-cinnabar">{{ $work['author'] }}</a>
                                    @else
                                        {{ $work['author'] }}
                                    @endif
                                </span>
                            @endif
                            <span class="block text-xs text-muted mt-0.5 tabular-nums">{{ implode(' · ', $parts) }}</span>
                        </td>
                        <td class="px-3 sm:px-4 py-2.5 align-top" data-tip="{{ $work['title'] }}: {{ implode('، ', $parts) }}">
                            <div class="flex items-center gap-2 justify-end">
                                <span class="hidden sm:block flex-1 h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                    <span class="block h-full bg-cinnabar/70 rounded-full" style="width: {{ round($work['count'] * 100 / $maxDerived, 1) }}%"></span>
                                </span>
                                <span class="text-xs tabular-nums text-ink w-12 text-left">{{ Fa::num($work['count']) }}</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>
    @php $lists = array_filter($dv['lists'], fn ($rows) => count($rows) > 1); @endphp
    @if($lists)
        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            @foreach($lists as $type => $rows)
                <x-ui.card>
                    <p class="text-xs text-muted mb-3">بیشترین {{ $typeNames[$type] }}</p>
                    <ol class="space-y-2 text-sm" data-derived-list="{{ $type }}">
                        @foreach($rows as $work)
                            <li class="flex items-baseline gap-2" data-tip="{{ $work['title'] }}{{ $work['author'] ? '، ' . $work['author'] : '' }}: {{ Fa::num($work['count']) }} {{ $typeNames[$type] }}">
                                <a href="{{ route('works.show', $work['key']) }}" class="text-ink hover:text-cinnabar truncate">{{ $work['title'] }}</a>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-xs text-muted tabular-nums">{{ Fa::num($work['count']) }}</span>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            @endforeach
        </div>
    @endif
    <p class="mt-3 text-xs text-muted leading-relaxed">
        {{ Fa::num($dv['made']) }} اثر بر پایۀ {{ Fa::num($dv['texts']) }} متن:
        @foreach($dv['types'] as $t){{ Fa::num($t['made']) }} {{ $t['name'] }}{{ $loop->last ? '.' : '، ' }}@endforeach
        @if(count($dv['languages']) > 1)
            زبان ترجمه‌ها:
            @foreach(array_slice($dv['languages'], 0, 4) as $l){{ $l['name'] }} ({{ Fa::num($l['count']) }}){{ $loop->last ? '.' : '، ' }}@endforeach
        @endif
        تنها اثری شمرده شده که به متن پایه‌اش پیوند دارد، پس شمار واقعی بیشتر است.
    </p>
    @include('insights.explain', ['note' => $notes['derived'], 'tables' => $tables['derived']])
</x-ui.section>
@endif
