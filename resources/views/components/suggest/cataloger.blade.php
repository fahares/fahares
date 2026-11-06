@props(['volume' => null])
{{-- «The cataloger of this volume is someone else» beside a source volume's catalogers (step 14.58): opens the general
     suggestion of <x-suggest.desk> on the relation cataloger_is with the volume chosen. Shown like <x-suggest.field>. --}}
<button type="button"
    :class="$store.suggest.on && 'is-on'"
    data-suggest-cataloger="{{ $volume }}"
    @click="$dispatch('suggest-field', { general: true, relation: 'cataloger_is', volume: @js($volume) })"
    {{ $attributes->merge(['class' => 'suggest-btn inline-flex items-center justify-center shrink-0 w-6 h-6 rounded-lg border border-gold/50 bg-amber-50 dark:bg-amber-950/40 text-cinnabar hover:bg-cinnabar hover:text-white transition cursor-pointer align-middle']) }}
    title="پیشنهاد اصلاح فهرست‌نگار" aria-label="پیشنهاد اصلاح فهرست‌نگار">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
</button>
