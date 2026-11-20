@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 3c. Much-copied works with no note of a printing (backlog item 146) --}}
@php $up = $data['unprinted']; @endphp
@if(count($up['items']) > 1)
<x-ui.section title="آثار پرنسخۀ بی نشانی چاپ" :count="count($up['items'])" id="unprinted">
    <x-ui.card class="!p-0 overflow-x-auto">
        @php $maxUnprinted = max(1, $up['items'][0]['count']); @endphp
        <table class="w-full text-sm">
            <thead class="text-xs text-muted border-b border-line">
                <tr>
                    <th scope="col" class="px-3 sm:px-4 py-2.5 text-right font-semibold w-10">#</th>
                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">اثر</th>
                    <th scope="col" class="px-3 sm:px-4 py-2.5 text-left font-semibold w-28 sm:w-56">نسخه</th>
                </tr>
            </thead>
            <tbody>
                @foreach($up['items'] as $i => $work)
                    <tr class="border-b border-line last:border-0 hover:bg-paper">
                        <td class="px-3 sm:px-4 py-2.5 text-muted tabular-nums align-top">{{ Fa::digits($i + 1) }}</td>
                        <td class="px-3 py-2.5 align-top">
                            <a href="{{ route('works.show', $work['key']) }}" class="font-semibold text-ink hover:text-cinnabar transition">{{ $work['title'] }}</a>
                            @if($work['author'])
                                <span class="block text-xs text-muted mt-0.5">
                                    @if($work['author_key'])<a href="{{ route('people.show', $work['author_key']) }}" class="hover:text-cinnabar">{{ $work['author'] }}</a>@else{{ $work['author'] }}@endif
                                </span>
                            @endif
                        </td>
                        <td class="px-3 sm:px-4 py-2.5 align-top">
                            <div class="flex items-center gap-2 justify-end">
                                <span class="hidden sm:block flex-1 h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                    <span class="block h-full bg-gold/80 rounded-full" style="width: {{ round($work['count'] * 100 / $maxUnprinted, 1) }}%"></span>
                                </span>
                                <a href="{{ route('works.show', $work['key']) }}#manuscripts" class="text-xs tabular-nums text-ink hover:text-cinnabar w-12 text-left">{{ Fa::num($work['count']) }}</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>
    <p class="mt-3 text-xs text-muted leading-relaxed">
        آثارِ دارای پدیدآور که فهارس برایشان چاپی یاد نکرده است: بخش «چاپ» رکوردشان خالی است و واژۀ «چاپ» در متنشان نیامده.
        این به معنای چاپ‌نشدن اثر نیست؛ فهارس چاپ همۀ آثار را ثبت نکرده است و تنها {{ Fa::num($up['printed']) }} اثر ({{ $pct($up['coverage']) }}){{ $scope->isEmpty() ? '' : ' در این دامنه' }} آگاهی چاپ دارند. پیش از تصحیح، چاپ اثر را در منابع دیگر هم بجویید.
    </p>
    @include('insights.explain', ['note' => $notes['unprinted'], 'tables' => $tables['unprinted']])
</x-ui.section>
@endif
