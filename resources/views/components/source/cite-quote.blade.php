@props([
    // The in-text citation the selected text is copied with: «(فهرستواره منزوی، ج ۵، ص ۳۳۶۶)»
    'cite',
    // Only a selection inside this element counts (a CSS selector), when the wrapper holds buttons too
    'within' => null,
])
{{-- A source's text whose selected pieces copy with their citation (step 14.389, backlog item 132); see cite-quote.js --}}
<div x-data="citeQuote({{ \Illuminate\Support\Js::from($cite) }}, {{ \Illuminate\Support\Js::from($within) }})" x-on:selectionchange.document="check()" x-on:scroll.window="show = false" {{ $attributes }}>
    {{ $slot }}
    <template x-teleport="body">
        <button type="button" x-show="show" x-cloak x-on:mousedown.prevent x-on:click="copy()" data-cite-quote
            :style="`left: ${x}px; top: ${y}px`"
            class="fixed z-40 -translate-x-1/2 -translate-y-full -mt-2 inline-flex items-center gap-1 px-2.5 py-1 rounded-sm border border-cinnabar bg-cinnabar text-on-cinnabar text-xs font-semibold shadow-md cursor-pointer whitespace-nowrap">
            <span x-show="! done">رونوشت با ارجاع</span>
            <span x-show="done" x-cloak>رونوشت شد</span>
        </button>
    </template>
</div>
