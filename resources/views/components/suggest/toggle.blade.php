{{-- Turns suggestion mode on and off: reveals a button beside every field (see <x-suggest.field>). A guest gets the
     sign-in dialog of <x-suggest.desk> instead (backlog item 76). Once the button scrolls away, a round copy floats in
     the corner; the first visit also shows a one-time hint (backlog item 92) --}}
@php
    $toggle = auth()->check() ? '$store.suggest.toggle()' : "\$dispatch('suggest-field', { mode: true })";
@endphp
<span x-data="suggestToggle" class="relative inline-flex">
    <button x-ref="inline"
        @click="{{ $toggle }}; dismissHint()"
        type="button"
        :class="$store.suggest.on ? 'bg-cinnabar text-on-cinnabar border-gold' : 'border-line-strong text-ink/80 hover:border-gold hover:text-cinnabar hover:bg-white dark:hover:bg-surface'"
        {{ $attributes->merge(['class' => 'px-3.5 py-2 rounded-xl border font-semibold text-xs transition duration-200 inline-flex items-center gap-1.5 shadow-2xs cursor-pointer']) }}>
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
        </svg>
        <span x-text="$store.suggest.on ? 'پایان پیشنهاد اصلاح' : 'پیشنهاد اصلاح'">پیشنهاد اصلاح</span>
    </button>

    <!-- One-time hint, remembered in localStorage once closed -->
    <span x-show="hint" x-cloak x-transition.opacity role="note"
        class="absolute top-full start-0 mt-2 z-30 w-64 p-3 pe-8 rounded-sm bg-[#221C17] text-[#F3EAD6] border border-gold/50 shadow-xl text-xs leading-relaxed">
        <span class="hover-only">برای پیشنهاد اصلاح، نشانگر را روی هر فیلد ببرید تا دکمه‌ی ✎ کنارش پیدا شود، یا این دکمه را بزنید تا همه‌ی دکمه‌ها با هم دیده شوند.</span>
        <span class="touch-only">برای پیشنهاد اصلاح، این دکمه را بزنید تا دکمه‌ی ✎ کنار هر فیلد پیدا شود. پس از پایین رفتن در صفحه، همین دکمه در گوشه‌ی صفحه هست.</span>
        <button type="button" @click="dismissHint()" class="absolute top-1.5 end-1.5 w-6 h-6 inline-flex items-center justify-center rounded-sm hover:bg-white/10 cursor-pointer" title="بستن" aria-label="بستن راهنما">✕</button>
    </span>

    <!-- Floating copy while the button is out of view; the suggestion-mode banner of the desk replaces it -->
    <template x-teleport="body">
        <button type="button" x-show="away && !$store.suggest.on" x-cloak x-transition.opacity
            @click="{{ $toggle }}; dismissHint()"
            class="fixed bottom-4 end-4 z-30 w-12 h-12 rounded-full bg-cinnabar text-on-cinnabar border border-gold shadow-xl inline-flex items-center justify-center cursor-pointer hover:opacity-90 transition"
            title="پیشنهاد اصلاح" aria-label="پیشنهاد اصلاح">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
        </button>
    </template>
</span>
