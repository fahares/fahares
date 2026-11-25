@props([
    // The source's code; with volume and page, or volume and entry (the entry's key as its address writes it)
    'source',
    'volume' => null,
    'page' => null,
    'entry' => null,
    'label' => 'ارجاع',
])
{{-- The way to a citation of the source, its page or its entry (step 14.389, backlog item 132): the same button on the
     source text page, the entry page and the source's own page; the page viewer has its own. --}}
<x-ui.btn size="sm" :href="route('cite.show', array_filter(['source' => $source, 'volume' => $volume, 'page' => $page, 'entry' => $entry], fn ($v) => $v !== null && $v !== ''))"
    rel="nofollow" title="ارجاع آماده برای پژوهش، با APA و شیکاگو و BibTeX" data-cite-link {{ $attributes }}>
    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h6m-9 8l3-3h11a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v11"/></svg>
    <span>{{ $label }}</span>
</x-ui.btn>
