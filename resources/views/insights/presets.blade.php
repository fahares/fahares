{{--
    «چند دامنه برای دیدن» (backlog item 155): the ready-made scopes of config insights.presets that hold enough copies
    (InsightsPresets), each with a sentence on what is worth seeing there. Only on a page of the whole data.
--}}
@php
    use App\Support\Fa;

    $presets = \App\Support\InsightsPresets::all();
@endphp
@if($presets)
    <section class="grid gap-3" aria-labelledby="insights-presets" data-insights-presets>
        <h2 id="insights-presets" class="text-xs font-bold text-cinnabar">چند دامنه برای دیدن</h2>
        {{-- One row to swipe through on a phone, so the figures below are not pushed nine cards down --}}
        <ul class="flex overflow-x-auto snap-x gap-3 pb-1 sm:grid sm:grid-cols-2 lg:grid-cols-3 sm:overflow-visible sm:pb-0">
            @foreach($presets as $preset)
                <li class="min-w-0 w-[82%] shrink-0 snap-start sm:w-auto">
                    <a href="{{ $pageUrl($preset['topic'] ?? $topic, $preset['query']) }}" rel="nofollow"
                       class="group h-full bg-surface border border-line hover:border-cinnabar transition px-4 py-3 grid gap-1 content-start">
                        <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <span class="font-semibold text-sm text-ink group-hover:text-cinnabar transition">{{ $preset['label'] }}</span>
                            <span class="text-[11px] text-muted tabular-nums">{{ Fa::num($preset['count']) }} نسخه</span>
                        </span>
                        <span class="text-xs text-muted leading-relaxed">{{ $preset['note'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
