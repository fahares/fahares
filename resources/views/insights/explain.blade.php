{{--
    Under a chart: its figures as folded tables (InsightsTables, backlog item 152), then the note (InsightsNotes,
    step 14.234) on how to read it, with examples from its own figures. $note; $tables
--}}
@include('insights.data-table', ['tables' => $tables ?? []])
<div class="mt-5 border-t border-line pt-4 grid gap-2.5 text-[13px] leading-loose text-ink" data-insights-note>
    <p class="text-xs font-bold text-cinnabar">این نمودار را چگونه بخوانیم</p>
    @foreach($note['reading'] as $paragraph)
        <p>{{ $paragraph }}</p>
    @endforeach
    @if($note['examples'])
        <p class="mt-1 text-xs font-bold text-cinnabar">نمونه از همین ارقام</p>
        <ul class="list-disc ps-5 grid gap-1.5 marker:text-gold">
            @foreach($note['examples'] as $example)
                <li>{{ $example }}</li>
            @endforeach
        </ul>
    @endif
</div>
