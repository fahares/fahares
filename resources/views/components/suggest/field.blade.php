@props(['name'])
@php
    // Shared by the record page (see AppServiceProvider); empty on pages without readings
    $badge = \App\Suggestions\SuggestionReadings::badge($suggestionReadings ?? collect(), $name);
@endphp
@if($badge)
    {{-- This field has approved readings; the full list is in <x-suggest.readings> --}}
    <a href="#suggestion-readings" title="{{ $badge['title'] }}" aria-label="{{ $badge['title'] }}"
        class="inline-flex items-center justify-center shrink-0 gap-0.5 h-5 {{ $badge['count'] > 1 ? 'px-1.5 rounded-md bg-sky-50 dark:bg-sky-950/40 border border-sky-200 dark:border-sky-800 text-sky-700 dark:text-sky-300 text-[10px] font-bold' : 'w-5 rounded-full text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40' }} transition align-middle">
        @if($badge['count'] > 1)
            {{ $badge['count'] }} قول
        @else
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.040A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        @endif
    </a>
@endif
{{-- Per-field "suggest a correction" button, visible in suggestion mode; opens <x-suggest.desk> --}}
<button type="button"
    x-show="$store.suggest.on" x-cloak
    @click="$dispatch('suggest-field', { field: @js($name) })"
    {{ $attributes->merge(['class' => 'inline-flex items-center justify-center shrink-0 w-6 h-6 rounded-lg border border-gold/50 bg-amber-50 dark:bg-amber-950/40 text-cinnabar hover:bg-cinnabar hover:text-white transition cursor-pointer align-middle']) }}
    title="پیشنهاد اصلاح این فیلد" aria-label="پیشنهاد اصلاح این فیلد">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
</button>
