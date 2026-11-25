{{--
    «چند دامنه برای دیدن» (backlog item 155): the ready-made scopes of config insights.presets that hold enough copies
    (InsightsPresets). Only on a page of the whole data. One line of small chips under the form of the filters (step
    14.393): as cards with their sentences they took the first screen of every page from the figures. The sentence on
    what is worth seeing there, and the count, are the chip's title.
--}}
@php
    use App\Support\Fa;

    $presets = \App\Support\InsightsPresets::all();
@endphp
@if($presets)
    {{-- One row to swipe through on a phone; as many rows as it takes on a wider screen --}}
    <nav class="-mt-3 flex items-baseline gap-x-2 gap-y-1.5 text-xs overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:flex-wrap sm:overflow-visible" aria-labelledby="insights-presets" data-insights-presets>
        <h2 id="insights-presets" class="shrink-0 text-muted font-normal">چند دامنه برای دیدن:</h2>
        @foreach($presets as $preset)
            <a href="{{ $pageUrl($preset['topic'] ?? $topic, $preset['query']) }}" rel="nofollow" title="{{ Fa::num($preset['count']) }} نسخه. {{ $preset['note'] }}"
               class="shrink-0 px-2.5 py-0.5 rounded-full border border-line text-muted hover:text-cinnabar hover:border-cinnabar transition">{{ $preset['label'] }}</a>
        @endforeach
    </nav>
@endif
