@php
    use App\Support\Fa;
@endphp
{{-- The authors with the most works and with the most copies (backlog item 162, step 14.314) --}}
@php $ta = $data['top_authors']; @endphp
@if(! $scope->get('author') && ! $scope->get('work') && count($ta['by_works']) > 1)
<x-ui.section title="مؤلفان پرکار و پرنسخه" id="top-authors">
    <div class="grid lg:grid-cols-2 gap-6">
        @foreach([['by_works', 'بیشترین اثر', 'works'], ['by_copies', 'بیشترین نسخه', 'copies']] as [$list, $heading, $order])
            <x-ui.card class="!p-0 overflow-x-auto" data-top-authors="{{ $order }}">
                <table class="w-full text-sm">
                    <caption class="px-3 pt-3 pb-1 text-right text-xs font-semibold text-ink">{{ $heading }}</caption>
                    <thead class="text-xs text-muted border-b border-line">
                        <tr>
                            <th scope="col" class="px-3 py-2.5 text-right font-semibold">مؤلف</th>
                            <th scope="col" class="px-3 py-2.5 text-left font-semibold">اثر</th>
                            <th scope="col" class="px-3 py-2.5 text-left font-semibold">نسخه</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ta[$list] as $row)
                            <tr class="border-b border-line last:border-0 hover:bg-paper">
                                <td class="px-3 py-2 align-top">
                                    <a href="{{ route('people.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                                    @if($row['death'])<span class="text-xs text-muted tabular-nums whitespace-nowrap"> · د. {{ Fa::digits($row['death']) }}</span>@endif
                                </td>
                                <td class="px-3 py-2 align-top text-left"><a href="{{ $search('works', ['author_id' => $row['id']]) }}" class="text-xs tabular-nums {{ $order === 'works' ? 'text-ink font-semibold' : 'text-muted' }} hover:text-cinnabar">{{ Fa::num($row['works']) }}</a></td>
                                <td class="px-3 py-2 align-top text-left"><a href="{{ $search('manuscripts', ['author_id' => $row['id']]) }}" class="text-xs tabular-nums {{ $order === 'copies' ? 'text-ink font-semibold' : 'text-muted' }} hover:text-cinnabar">{{ Fa::num($row['copies']) }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-muted leading-relaxed">
        {{ Fa::num($ta['authors']) }} مؤلف شناخته (پیوندشده به یک شخص) در این دامنه اثر دارند، و {{ Fa::num($ta['single']) }} تن از ایشان تنها یک اثر.
        اثری که دو مؤلف دارد برای هر دو شمرده می‌شود. سال، سال درگذشت مؤلف به هجری قمری است.
    </p>
    @include('insights.explain', ['note' => $notes['topAuthors'], 'tables' => $tables['topAuthors']])
</x-ui.section>
@endif
