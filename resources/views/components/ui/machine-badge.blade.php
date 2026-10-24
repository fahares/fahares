@props([
    // What the machine did, shown on hover, focus or tap
    'text' => 'این داده را هوشواره به‌طور خودکار استخراج کرده و هنوز به دست انسان بازبینی نشده است.',
    // The detail of the `suggest-field` event that opens the suggestion desk of a record page; null hides the link
    'report' => null,
    'reportLabel' => 'گزارش نادرستی',
    // Review on the page (backlog item 59): ['key' => one name for every badge and element of the same datum,
    // 'verify' => url, 'reject' => url]. Only users with catalog_refs.review see «درست است» and «نادرست است»;
    // elements marked data-machine-item="{key}" are dimmed after a rejection, then hidden. See resources/js/machine-review.js.
    'review' => null,
])
@php $review = $review && auth()->user()?->can('catalog_refs.review') ? $review + ['csrf' => csrf_token()] : null; @endphp
{{-- ⓘ beside data produced automatically and not yet reviewed (backlog item 51): references matched by shelfmark,
     and later the bibliography links and related works. Hover, focus or a tap shows the note (a tap also fires mouseenter, so it never toggles); a tap or click outside, or Esc, hides it. --}}
<span x-data="{ open: false, timer: null, show() { clearTimeout(this.timer); this.open = true }, hide() { this.timer = setTimeout(() => this.open = false, 150) } }"
    @if($review) x-show="$store.machineReview.awaiting(@js($review['key']))" data-machine-badge="{{ $review['key'] }}" @endif
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
        @if($review)
            <span class="flex gap-2 mt-2" data-machine-review>
                <button type="button" @click="open = false; $store.machineReview.send(@js($review), 'verify')" :disabled="$store.machineReview.busy"
                    class="flex-1 px-2 py-1 rounded-md border border-emerald-600/40 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 font-bold hover:bg-emerald-100 dark:hover:bg-emerald-900/50 disabled:opacity-50 cursor-pointer">درست است</button>
                <button type="button" @click="open = false; $store.machineReview.send(@js($review), 'reject')" :disabled="$store.machineReview.busy"
                    class="flex-1 px-2 py-1 rounded-md border border-red-600/40 bg-red-50 dark:bg-red-950/40 text-red-800 dark:text-red-300 font-bold hover:bg-red-100 dark:hover:bg-red-900/50 disabled:opacity-50 cursor-pointer">نادرست است</button>
            </span>
        @endif
        @if($report)
            <button type="button" @click="open = false; $dispatch('suggest-field', @js($report))"
                class="block mt-1.5 font-bold text-cinnabar hover:underline cursor-pointer">{{ $reportLabel }}</button>
        @endif
    </span>
</span>
