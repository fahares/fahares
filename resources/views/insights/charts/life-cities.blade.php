@php
    use App\Support\Fa;
    use App\Models\PersonPeriod;
@endphp
{{-- The cities of the approved periods of people's lives, by the kind of the period (backlog item 139, step 14.369) --}}
@php
    $lc = $data['life_cities'];
    // the order a life is told in
    $kinds = array_intersect_key(array_merge(array_flip(['birth', 'origin', 'study', 'teaching', 'residence', 'office', 'travel', 'death']), PersonPeriod::KINDS), PersonPeriod::KINDS);
    $people = fn (array $params) => route('search', ['type' => 'authors'] + $params);
@endphp
@if(count($lc['cities']) > 1)
<x-ui.section title="شهرهای زندگی دانشوران" id="life-cities">
    <x-ui.card class="!p-0 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-muted border-b border-line">
                <tr>
                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">شهر</th>
                    <th scope="col" class="px-3 py-2.5 text-left font-semibold">اشخاص</th>
                    @foreach($kinds as $kind => $label)
                        <th scope="col" class="px-3 py-2.5 text-left font-semibold whitespace-nowrap">
                            @if($scope->isEmpty() && $lc['kinds'][$kind] > 0)<a href="{{ $people(['life_kind' => $kind]) }}" class="hover:text-cinnabar">{{ $label }}</a>@else{{ $label }}@endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($lc['cities'] as $city)
                    <tr class="border-b border-line last:border-0 hover:bg-paper" data-life-city="{{ $city['name'] }}">
                        <th scope="row" class="px-3 py-2 text-right font-normal text-ink whitespace-nowrap">{{ $city['name'] }}</th>
                        <td class="px-3 py-2 text-left">
                            @if($scope->isEmpty())<a href="{{ $people(['life_city' => $city['name']]) }}" class="tabular-nums font-semibold text-ink hover:text-cinnabar">{{ Fa::num($city['people']) }}</a>
                            @else<span class="tabular-nums font-semibold text-ink">{{ Fa::num($city['people']) }}</span>@endif
                        </td>
                        @foreach($kinds as $kind => $label)
                            <td class="px-3 py-2 text-left text-xs tabular-nums">
                                @if(! ($n = $city['kinds'][$kind] ?? 0))<span class="text-muted/50">·</span>
                                @elseif($scope->isEmpty())<a href="{{ $people(['life_city' => $city['name'], 'life_kind' => $kind]) }}" class="text-muted hover:text-cinnabar">{{ Fa::num($n) }}</a>
                                @else<span class="text-muted">{{ Fa::num($n) }}</span>@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>
    <p class="mt-3 text-xs text-muted leading-relaxed">
        {{ Fa::num($lc['periods']) }} دورۀ تأییدشده از زندگی {{ Fa::num($lc['people']) }} تن{{ $scope->isEmpty() ? '' : ' از مؤلفان و کاتبان این دامنه' }} در {{ Fa::num($lc['all_cities']) }} شهر؛
        {{ Fa::num(count($lc['cities'])) }} شهرِ پرشمارتر آمده است. هر کس برای هر شهر و هر نوع دوره یک بار شمرده می‌شود، پس جمع یک ردیف از ستون «اشخاص» بیشتر می‌شود.
        @if($scope->isEmpty())پیوندها به زبانۀ «مؤلفان» می‌روند؛ کسی که تنها کاتب است در زبانۀ «کاتبان» با همین فیلتر پیدا می‌شود.@endif
    </p>
    @include('insights.explain', ['note' => $notes['lifeCities'], 'tables' => $tables['lifeCities']])
</x-ui.section>
@endif
