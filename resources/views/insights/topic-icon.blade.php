{{--
    The sign of a topic of the statistics (step 14.398): $slug is a key of config insights.topics. Line drawings on
    the site's own grid of 24, in the colour of the text around them; drawn for these pages, not taken from a set.
    A topic without a sign draws nothing.
--}}
@php
    $signs = [
        // Time: an hourglass, half run
        'time' => '<path d="M6.5 3.5h11M6.5 20.5h11"/><path d="M8 3.5v3c0 2.2 1.6 3.8 4 5.5 2.4-1.7 4-3.3 4-5.5v-3"/><path d="M8 20.5v-3c0-2.2 1.6-3.8 4-5.5 2.4 1.7 4 3.3 4 5.5v3"/><path d="M10.2 18h3.6M12 12v3.2"/>',
        // Places: a pin on the map
        'places' => '<path d="M12 21s6.5-5.6 6.5-11a6.5 6.5 0 1 0-13 0c0 5.4 6.5 11 6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        // Works: an open book
        'works' => '<path d="M12 6.5c-1.8-1.3-4-2-6.5-2H4V18h1.5c2.5 0 4.7 .7 6.5 2 1.8-1.3 4-2 6.5-2H20V4.5h-1.5c-2.5 0-4.7 .7-6.5 2z"/><path d="M12 6.5V20"/>',
        // Copies: leaves of a manuscript, the lines of the upper one written from the right
        'copies' => '<path d="M7.5 4.5V2.5h12v16h-2"/><rect x="4.5" y="4.5" width="13" height="16" rx=".8"/><path d="M7.5 8.5h7M7.5 11.5h7M7.5 14.5h7M10.5 17.5h4"/>',
        // People: a scribe and his reed pen
        'people' => '<circle cx="9.5" cy="7.5" r="3"/><path d="M3.5 20c0-3.5 2.7-6.3 6-6.3 1.4 0 2.7 .5 3.7 1.3"/><path d="M19.6 10.8l1.9 1.4-5.2 7.1-2.4 1.2 .5-2.6z"/>',
        // Libraries and catalogues: books on a shelf, one leaning
        'libraries' => '<path d="M3.5 20.5h17"/><rect x="5" y="6.5" width="3" height="14"/><rect x="8" y="4.5" width="3.5" height="16"/><rect x="11.5" y="8.5" width="3" height="12"/><path d="M15.4 8.3l2.9-.8 3.2 12.2-2.9 .8z"/>',
    ];
@endphp
@isset($signs[$slug])
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-insights-icon="{{ $slug }}"
         class="{{ $class ?? 'size-7' }}">{!! $signs[$slug] !!}</svg>
@endisset
