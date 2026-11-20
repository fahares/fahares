@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 3d. Bound volumes (backlog item 146) --}}
@php $col = $data['collections']; @endphp
<x-ui.section title="مجموعه‌ها: رساله‌های هم‌مجلد" id="collections">
    <x-ui.card>
        @if($col['volumes'] < $min)
            @include('insights.thin', ['n' => $col['volumes'], 'what' => 'مجموعه'])
        @else
            @include('insights.small', ['n' => $col['volumes'], 'what' => 'مجموعه'])
            @php $maxVolumes = max([1, ...array_column($col['bands'], 'volumes')]); @endphp
            <div class="grid lg:grid-cols-2 gap-8">
                <div>
                    <p class="text-xs text-muted mb-3">شمار مجموعه‌ها بر پایۀ شمار رساله‌هایشان. میانگین {{ Fa::digits(str_replace('.', '٫', (string) $col['mean'])) }} رساله در هر مجموعه است و بزرگ‌ترین مجموعه {{ Fa::num($col['largest']) }} رساله دارد.</p>
                    <ul class="space-y-2.5 text-sm">
                        @foreach($col['bands'] as $band)
                            @continue($band['volumes'] === 0)
                            <li>
                                <span class="flex items-baseline gap-2">
                                    <span class="text-ink">
                                        @if($band['upto'] === null) بیش از {{ Fa::digits($band['above']) }} رساله
                                        @elseif($band['upto'] === $band['above'] + 1) {{ Fa::digits($band['upto']) }} رساله
                                        @else {{ Fa::digits($band['above'] + 1) }} تا {{ Fa::digits($band['upto']) }} رساله
                                        @endif
                                    </span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums">{{ Fa::num($band['volumes']) }} · {{ $pct(round($band['volumes'] * 100 / max(1, $col['volumes']), 1)) }}</span>
                                </span>
                                <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                    <span class="block h-full bg-cinnabar/70 rounded-full" style="width: max(2px, {{ round($band['volumes'] * 100 / $maxVolumes, 2) }}%)"></span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    @if($col['pairs'])
                        <p class="text-xs text-muted mb-3">آثاری که بیشتر با هم در یک مجلد آمده‌اند، با شمار مجموعه‌هایی که هر دو را دارند.</p>
                        <ol class="space-y-2 text-sm">
                            @foreach($col['pairs'] as $pair)
                                <li class="flex items-baseline gap-2">
                                    <span class="min-w-0">
                                        @foreach($pair['works'] as $work)
                                            <a href="{{ route('works.show', $work['key']) }}" class="text-ink hover:text-cinnabar transition" title="{{ $work['author'] }}">{{ $work['title'] }}</a>@if(! $loop->last) <span class="text-muted">و</span> @endif
                                        @endforeach
                                    </span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums">{{ Fa::num($pair['count']) }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="text-sm text-muted">در این دامنه دو اثری نیست که در بیش از یک مجموعه با هم آمده باشند.</p>
                    @endif
                </div>
            </div>
            <p class="mt-5 text-xs text-muted leading-relaxed">
                {{ Fa::num($col['copies']) }} نسخه ({{ $pct($col['coverage']) }} نسخه‌ها) رساله‌ای از {{ Fa::num($col['volumes']) }} مجموعه‌اند.
                مجموعه از شمارۀ نسخه شناخته می‌شود: رساله‌هایی از یک کتابخانه که شمارۀ مجلدشان یکی است («۳۸۵۲/۴» و «۳۸۵۲/۵»).
                @if($col['lone'] > 0){{ Fa::num($col['lone']) }} مجلد که{{ $scope->isEmpty() ? '' : ' در این دامنه' }} تنها یک رساله‌شان هست شمرده نشده‌اند.@endif
                @unless($scope->isEmpty())در یک دامنه، تنها رساله‌هایی از هر مجموعه شمرده می‌شوند که در همان دامنه‌اند.@endunless
            </p>
            @include('insights.explain', ['note' => $notes['collections'], 'tables' => $tables['collections']])
        @endif
    </x-ui.card>
</x-ui.section>
