@php
    use App\Support\Fa;
@endphp
{{-- The copies in their author's hand, and the authors who copied the works of others (backlog item 162, step 14.336) --}}
@php $ag = $data['autographs']; @endphp
@if(! $scope->get('author') && ! $scope->get('work') && ! $scope->get('scribe') && count($ag['items']) > 1)
<x-ui.section title="نسخه‌های خط مؤلف و مؤلفان کاتب" id="autographs">
    <div class="grid lg:grid-cols-2 gap-6">
        <x-ui.card class="!p-0 overflow-x-auto" data-autographs="own">
            <table class="w-full text-sm">
                <caption class="px-3 pt-3 pb-1 text-right text-xs font-semibold text-ink">بیشترین نسخه به خط خود مؤلف</caption>
                <thead class="text-xs text-muted border-b border-line">
                    <tr>
                        <th scope="col" class="px-3 py-2.5 text-right font-semibold">مؤلف</th>
                        <th scope="col" class="px-3 py-2.5 text-left font-semibold">نسخه</th>
                        <th scope="col" class="px-3 py-2.5 text-left font-semibold">اثر</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ag['items'] as $row)
                        <tr class="border-b border-line last:border-0 hover:bg-paper">
                            <td class="px-3 py-2 align-top">
                                <a href="{{ route('people.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                                @if($row['death'])<span class="text-xs text-muted tabular-nums whitespace-nowrap"> · د. {{ Fa::digits($row['death']) }}</span>@endif
                            </td>
                            <td class="px-3 py-2 align-top text-left"><a href="{{ $search('manuscripts', ['author_id' => $row['id'], 'scribe_id' => $row['id']]) }}" class="text-xs tabular-nums text-ink font-semibold hover:text-cinnabar">{{ Fa::num($row['copies']) }}</a></td>
                            <td class="px-3 py-2 align-top text-left text-xs text-muted tabular-nums whitespace-nowrap" data-tip="{{ Fa::num($row['works']) }} اثر از {{ Fa::num($row['of_works']) }} اثر این مؤلف نسخۀ خط او را دارد">{{ Fa::num($row['works']) }} از {{ Fa::num($row['of_works']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
        @if(count($ag['others']) > 1)
            <x-ui.card class="!p-0 overflow-x-auto" data-autographs="others">
                <table class="w-full text-sm">
                    <caption class="px-3 pt-3 pb-1 text-right text-xs font-semibold text-ink">مؤلفانی که آثار دیگران را کتابت کرده‌اند</caption>
                    <thead class="text-xs text-muted border-b border-line">
                        <tr>
                            <th scope="col" class="px-3 py-2.5 text-right font-semibold">شخص</th>
                            <th scope="col" class="px-3 py-2.5 text-left font-semibold">از دیگران</th>
                            <th scope="col" class="px-3 py-2.5 text-left font-semibold">از خود</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ag['others'] as $row)
                            <tr class="border-b border-line last:border-0 hover:bg-paper">
                                <td class="px-3 py-2 align-top">
                                    <a href="{{ route('people.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                                    @if($row['death'])<span class="text-xs text-muted tabular-nums whitespace-nowrap"> · د. {{ Fa::digits($row['death']) }}</span>@endif
                                </td>
                                <td class="px-3 py-2 align-top text-left"><a href="{{ $search('manuscripts', ['scribe_id' => $row['id']]) }}" class="text-xs tabular-nums text-ink font-semibold hover:text-cinnabar">{{ Fa::num($row['others']) }}</a></td>
                                <td class="px-3 py-2 align-top text-left text-xs text-muted tabular-nums">{{ Fa::num($row['own']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    </div>
    <p class="mt-3 text-xs text-muted leading-relaxed">
        {{ Fa::num($ag['copies']) }} نسخه از {{ Fa::num($ag['works']) }} اثر به خط مؤلف خود است. فهرست‌ها روی هم {{ Fa::num($ag['flagged']) }} نسخه را «خط مؤلف» خوانده‌اند؛
        نسخه‌ای که کاتبش از مؤلفان همان اثر است نیز شمرده شده، هرچند فهرست این را نگفته باشد.
        فهرست دوم تنها کسانی را می‌آورد که دست‌کم {{ Fa::digits(config('insights.author_scribe_min_works')) }} اثر از خود دارند.
        در ستون «اثر»، عدد نخست آثاری است که نسخۀ خط مؤلف دارند و عدد دوم همۀ آثار او در این دامنه.
    </p>
    @include('insights.explain', ['note' => $notes['autographs'], 'tables' => $tables['autographs']])
</x-ui.section>
@endif
