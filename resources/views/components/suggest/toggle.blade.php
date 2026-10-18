{{-- Turns suggestion mode on and off: reveals a button beside every field (see <x-suggest.field>) --}}
<button
    @click="$store.suggest.toggle()"
    type="button"
    :class="$store.suggest.on ? 'bg-cinnabar text-on-cinnabar border-gold' : 'border-line-strong text-ink/80 hover:border-gold hover:text-cinnabar hover:bg-white dark:hover:bg-surface'"
    {{ $attributes->merge(['class' => 'px-3.5 py-2 rounded-xl border font-semibold text-xs transition duration-200 inline-flex items-center gap-1.5 shadow-2xs cursor-pointer']) }}>
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
    </svg>
    <span x-text="$store.suggest.on ? 'پایان پیشنهاد اصلاح' : 'پیشنهاد اصلاح'">پیشنهاد اصلاح</span>
</button>
