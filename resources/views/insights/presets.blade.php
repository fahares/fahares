{{--
    «چند دامنه برای دیدن» (backlog item 155): the ready-made scopes of config insights.presets that hold enough copies
    (InsightsPresets). Small chips (step 14.393): as cards with their sentences they took the first screen of every
    page from the figures. The sentence on what is worth seeing there, and the count, are the chip's title. They sit
    inside the box of the filters, under its form, and only on the hub of the whole data (step 14.395).
--}}
@php
    use App\Support\Fa;

    $presets = \App\Support\InsightsPresets::all();
@endphp
@if($presets)
    <nav class="flex flex-wrap items-baseline gap-x-2 gap-y-1.5 text-xs px-4 py-3 border-t border-line" aria-labelledby="insights-presets" data-insights-presets>
        <h2 id="insights-presets" class="text-muted font-normal">چند دامنه برای دیدن:</h2>
        @foreach($presets as $preset)
            <a href="{{ $pageUrl($preset['topic'] ?? $topic, $preset['query']) }}" rel="nofollow" title="{{ Fa::num($preset['count']) }} نسخه. {{ $preset['note'] }}"
               class="px-2.5 py-0.5 rounded-full border border-line text-muted hover:text-cinnabar hover:border-cinnabar transition">{{ $preset['label'] }}</a>
        @endforeach
    </nav>
@endif
