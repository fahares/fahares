@props([
    // What the machine did, shown on hover, focus or tap
    'text' => 'این داده را هوشواره به‌طور خودکار استخراج کرده و هنوز به دست انسان بازبینی نشده است.',
    // The detail of the `suggest-field` event that opens the suggestion desk of a record page; null hides the link
    'report' => null,
    'reportLabel' => 'گزارش نادرستی',
])
{{-- ⓘ beside data produced automatically and not yet reviewed (backlog item 51): references matched by shelfmark,
     and later the bibliography links and related works. Hover, focus or a tap shows the note (a tap also fires mouseenter, so it never toggles); a tap or click outside, or Esc, hides it. --}}
<span x-data="{ open: false, timer: null, show() { clearTimeout(this.timer); this.open = true }, hide() { this.timer = setTimeout(() => this.open = false, 150) } }"
    @mouseenter="show()" @mouseleave="hide()" @keydown.escape.stop="open = false" @click.outside="open = false" @focusout="$el.contains($event.relatedTarget) || hide()"
    {{ $attributes->class('relative inline-flex align-middle') }}>
    <button type="button" @click="show()" @focus="show()" :aria-expanded="open.toString()"
        class="inline-flex items-center justify-center w-5 h-5 rounded-full text-sky-700 dark:text-sky-300 hover:bg-sky-50 dark:hover:bg-sky-950/40 transition cursor-pointer"
        aria-label="استخراج خودکار، بازبینی‌نشده">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M12 11v5M12 8h.01"/></svg>
    </button>
    <span x-show="open" x-cloak x-transition.opacity role="tooltip"
        class="absolute z-30 top-full mt-1 end-0 w-64 max-w-[80vw] p-3 rounded-lg border border-sky-200 dark:border-sky-800 bg-surface shadow-lg text-xs leading-6 text-ink font-normal text-right whitespace-normal">
        {{ $text }}
        @if($report)
            <button type="button" @click="open = false; $dispatch('suggest-field', @js($report))"
                class="block mt-1.5 font-bold text-cinnabar hover:underline cursor-pointer">{{ $reportLabel }}</button>
        @endif
    </span>
</span>
