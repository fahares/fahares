@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 3. Most-copied works --}}
@if(count($data['top_works']['items']) > 1)
<x-ui.section :title="$scope->isEmpty() ? 'آثار پرنسخه' : 'آثار پرنسخه در این دامنه'" :count="count($data['top_works']['items'])" id="top-works">
    <x-ui.card class="!p-0 overflow-x-auto">
        @php $maxWork = max(1, $data['top_works']['items'][0]['count'] ?? 1); @endphp
        <table class="w-full text-sm">
            <thead class="text-xs text-muted border-b border-line">
                <tr>
                    <th scope="col" class="px-3 sm:px-4 py-2.5 text-right font-semibold w-10">#</th>
                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">اثر</th>
                    <th scope="col" class="px-3 sm:px-4 py-2.5 text-left font-semibold w-28 sm:w-56">نسخه</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['top_works']['items'] as $i => $work)
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
                        </td>
                        <td class="px-3 sm:px-4 py-2.5 align-top">
                            <div class="flex items-center gap-2 justify-end">
                                <span class="hidden sm:block flex-1 h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                    <span class="block h-full bg-cinnabar/70 rounded-full" style="width: {{ round($work['count'] * 100 / $maxWork, 1) }}%"></span>
                                </span>
                                <a href="{{ route('works.show', $work['key']) }}#manuscripts" class="text-xs tabular-nums text-ink hover:text-cinnabar w-12 text-left">{{ Fa::num($work['count']) }}</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>
    @if(! empty($data['top_works']['skipped']))
        <p class="mt-3 text-xs text-muted leading-relaxed">
            رکوردهای بی‌پدیدآوری که عنوانشان نام یک گونۀ متن است، نه یک اثر معین، در جدول نیامده‌اند:
            @foreach($data['top_works']['skipped'] as $work)
                <a href="{{ route('works.show', $work['key']) }}" class="text-ink hover:text-cinnabar underline decoration-line-strong">{{ $work['title'] }}</a> ({{ Fa::num($work['count']) }}){{ $loop->last ? '.' : '،' }}
            @endforeach
        </p>
    @endif
    @include('insights.explain', ['note' => $notes['topWorks'], 'tables' => $tables['topWorks']])
</x-ui.section>
@endif
