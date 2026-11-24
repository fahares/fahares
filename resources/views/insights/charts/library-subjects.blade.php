@php
    use App\Support\Fa;
@endphp
{{-- The subjects each library is strong in (backlog item 162, step 14.343) --}}
@php
    $ls = $data['library_subjects'];
    $times = fn ($v) => Fa::digits(str_replace('.', '٫', (string) $v));
@endphp
@if(! $scope->get('library') && ! $scope->get('subject') && ! $scope->get('work') && count($ls['items']) > 1)
<x-ui.section title="موضوع‌های پررنگ هر کتابخانه" id="library-subjects">
    <x-ui.card class="!p-0 overflow-x-auto">
        <table class="w-full text-sm" data-library-subjects>
            <thead class="text-xs text-muted border-b border-line">
                <tr>
                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">کتابخانه</th>
                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">موضوع‌هایی که بیش از دیگران دارد</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ls['items'] as $row)
                    <tr class="border-b border-line last:border-0 hover:bg-paper">
                        <td class="px-3 py-2 align-top">
                            <a href="{{ route('libraries.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                            <span class="block text-xs text-muted tabular-nums">{{ $row['city'] }} · {{ Fa::num($row['copies']) }} نسخه</span>
                        </td>
                        <td class="px-3 py-2 align-top">
                            <ul class="flex flex-wrap gap-1.5 text-xs">
                                @foreach($row['subjects'] as $s)
                                    <li data-tip="{{ $s['name'] }}: {{ Fa::num($s['count']) }} نسخه، {{ $pct($s['share']) }} نسخه‌های این کتابخانه؛ سهم در همۀ کتابخانه‌ها {{ $pct($s['overall']) }}">
                                        <a href="{{ route('insights', ['library' => $row['id'], 'subject' => $s['id']] + $scope->query()) }}" class="inline-block border border-line bg-paper px-2 py-1 hover:border-cinnabar">
                                            <span class="text-ink">{{ $s['name'] }}</span>
                                            <span class="text-muted tabular-nums">{{ $pct($s['share']) }} · {{ $times($s['ratio']) }} برابر</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>
    @if(count($ls['subjects']) > 1)
        <x-ui.card class="mt-6">
            <p class="text-xs text-muted mb-3">کتابخانه‌ای که هر موضوع بیشترین سهم را در نسخه‌هایش دارد.</p>
            <ul class="grid sm:grid-cols-2 gap-x-8 gap-y-2 text-sm" data-subject-libraries>
                @foreach($ls['subjects'] as $s)
                    <li class="flex items-baseline gap-2" data-tip="{{ $s['name'] }} در {{ $s['library']['name'] }}: {{ Fa::num($s['count']) }} نسخه، {{ $pct($s['share']) }}؛ سهم در همۀ کتابخانه‌ها {{ $pct($s['overall']) }}">
                        <span class="text-ink whitespace-nowrap">{{ $s['name'] }}</span>
                        <span class="leader" aria-hidden="true"></span>
                        <a href="{{ route('insights', ['library' => $s['library']['id'], 'subject' => $s['id']] + $scope->query()) }}" class="text-xs text-muted hover:text-cinnabar text-left">{{ $s['library']['name'] }}{{ $s['library']['city'] ? '، ' . $s['library']['city'] : '' }} <span class="tabular-nums">· {{ $pct($s['share']) }}</span></a>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
    <p class="mt-3 text-xs text-muted leading-relaxed">
        بر پایۀ {{ Fa::num($ls['copies']) }} نسخه که اثرشان موضوع دارد، در {{ Fa::num($ls['libraries']) }} کتابخانه که دست‌کم {{ Fa::num(config('insights.specialty_min_copies')) }} نسخه از این دست دارند.
        «برابر» نسبت سهم موضوع در کتابخانه به سهم آن در همۀ کتابخانه‌هاست. موضوعی که کمتر از {{ Fa::digits(config('insights.specialty_min_cell')) }} نسخه در کتابخانه دارد نیامده است، و برای هر کتابخانه تا سه موضوع آمده است.
    </p>
    @include('insights.explain', ['note' => $notes['librarySubjects'], 'tables' => $tables['librarySubjects']])
</x-ui.section>
@endif
