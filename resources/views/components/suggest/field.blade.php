@props(['name'])
{{-- Per-field "suggest a correction" button, visible in suggestion mode; opens <x-suggest.desk> --}}
<button type="button"
    x-show="$store.suggest.on" x-cloak
    @click="$dispatch('suggest-field', { field: @js($name) })"
    {{ $attributes->merge(['class' => 'inline-flex items-center justify-center shrink-0 w-6 h-6 rounded-lg border border-[#B38A50]/50 bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] hover:bg-[#B38A50] hover:text-white transition cursor-pointer align-middle']) }}
    title="پیشنهاد اصلاح این فیلد" aria-label="پیشنهاد اصلاح این فیلد">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
</button>
