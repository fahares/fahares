@props(['open' => false, 'suggestOnly' => false])

{{-- The record's places and texts in a union catalogue compiled from the printed catalogues (Fankha), folded under the
     first-hand sources (backlog item 147); the reader opens them. When the reader sees the record in another source
     (RecordSources::seesFirstHand, step 14.376) they are shown in suggestion mode only. --}}
<details {{ $attributes->merge(['class' => 'group']) }} data-secondary-sources @if($open) open @endif
    @if($suggestOnly) data-suggest-only style="display: none" x-show="$store.suggest?.on" @endif
    x-data x-effect="if ($store.suggest?.on) $el.open = true">
    <summary class="flex flex-wrap items-center gap-x-1 gap-y-0.5 cursor-pointer select-none text-xs text-muted hover:text-cinnabar transition list-none [&::-webkit-details-marker]:hidden">
        <svg class="w-3 h-3 transition-transform group-open:rotate-90 rtl:-scale-x-100" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <span class="font-semibold">مأخذ دست دوم</span>
        <span class="text-[11px]">(گردآوری از فهرست‌های چاپی)</span>
    </summary>
    <div class="mt-3 space-y-4">
        {{ $slot }}
    </div>
</details>
