@props(['type' => null])
{{-- The one way into contributing on a record page (step 14.156): «مشارکت در این صفحه» lists the three kinds, each with a
     line on what it is for: a field's value (suggestion mode: every ✎ strong, the empty fields shown), a general
     suggestion, and a research note. Guests use it like anyone; the sign-in comes when the form is sent. Once the button
     scrolls away, a round copy floats in the corner; the first visit also shows a hint that goes by itself
     (backlog item 92). $type: the record's type, for whether it takes notes --}}
@php
    $choices = array_filter([
        ['detail' => ['mode' => true], 'title' => 'اصلاح یک فیلد', 'hint' => 'مقداری اشتباه است یا جا افتاده؟ ✎ کنار همان فیلد را بزنید.'],
        ['detail' => ['general' => true], 'title' => 'پیشنهاد کلی', 'hint' => 'تکراری بودن رکورد، یکی بودن با رکوردی دیگر، و هر چه در فیلدها نمی‌گنجد.'],
        $type && \App\Suggestions\NoteTypes::forRecord($type)
            ? ['detail' => ['note' => true], 'title' => 'افزودن یادداشت یا منبع', 'hint' => 'چاپ، پژوهش، فهرست دیگر یا تصویری که می‌شناسید.']
            : null,
    ]);
    $menuClass = 'absolute z-30 w-72 p-1.5 rounded-sm bg-surface border border-gold shadow-xl text-right';
@endphp
<span x-data="suggestToggle" class="relative inline-flex" @keydown.escape.window="menu = null">
    <button x-ref="inline"
        @click="$store.suggest.on ? $store.suggest.toggle() : (menu = menu === 'inline' ? null : 'inline'); dismissHint()"
        type="button" :aria-expanded="menu === 'inline'"
        :class="$store.suggest.on ? 'bg-cinnabar text-on-cinnabar border-gold' : 'border-line-strong text-ink/80 hover:border-gold hover:text-cinnabar hover:bg-white dark:hover:bg-surface'"
        {{ $attributes->merge(['class' => 'px-3.5 py-2 rounded-xl border font-semibold text-xs transition duration-200 inline-flex items-center gap-1.5 shadow-2xs cursor-pointer']) }}>
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
        </svg>
        <span x-text="$store.suggest.on ? 'پایان اصلاح فیلدها' : 'مشارکت در این صفحه'">مشارکت در این صفحه</span>
    </button>

    <!-- The three kinds of contribution -->
    <span x-show="menu === 'inline'" x-cloak x-transition.opacity @click.outside="menu = null" role="menu" data-contribute-menu
        class="{{ $menuClass }} top-full start-0 mt-2">
        @foreach($choices as $choice)
            <button type="button" role="menuitem" @click="choose(@js($choice['detail']))"
                class="block w-full text-right px-3 py-2.5 rounded-sm hover:bg-paper cursor-pointer">
                <span class="block text-sm font-bold text-ink">{{ $choice['title'] }}</span>
                <span class="block mt-0.5 text-xs text-muted leading-relaxed">{{ $choice['hint'] }}</span>
            </button>
        @endforeach
    </span>

    <!-- One-time hint, remembered in localStorage once closed or gone by itself -->
    <span x-show="hint && !menu" x-cloak x-transition.opacity role="note"
        class="absolute top-full start-0 mt-2 z-30 w-64 p-3 pe-8 rounded-sm bg-[#221C17] text-[#F3EAD6] border border-gold/50 shadow-xl text-xs leading-relaxed">
        هر جا ✎ می‌بینید، می‌توانید همان فیلد را اصلاح کنید. راه‌های دیگر مشارکت زیر همین دکمه است.
        <button type="button" @click="dismissHint()" class="absolute top-1.5 end-1.5 w-6 h-6 inline-flex items-center justify-center rounded-sm hover:bg-white/10 cursor-pointer" title="بستن" aria-label="بستن راهنما">✕</button>
    </span>

    <!-- Floating copy while the button is out of view; the suggestion-mode banner of the desk replaces it -->
    <template x-teleport="body">
        <span x-show="away && !$store.suggest.on" x-cloak x-transition.opacity class="fixed bottom-4 end-4 z-30">
            <span x-show="menu === 'float'" x-cloak x-transition.opacity @click.outside="menu = null" role="menu"
                class="{{ $menuClass }} bottom-full end-0 mb-2">
                @foreach($choices as $choice)
                    <button type="button" role="menuitem" @click="choose(@js($choice['detail']))"
                        class="block w-full text-right px-3 py-2.5 rounded-sm hover:bg-paper cursor-pointer">
                        <span class="block text-sm font-bold text-ink">{{ $choice['title'] }}</span>
                        <span class="block mt-0.5 text-xs text-muted leading-relaxed">{{ $choice['hint'] }}</span>
                    </button>
                @endforeach
            </span>
            <button type="button" @click="menu = menu === 'float' ? null : 'float'; dismissHint()" :aria-expanded="menu === 'float'"
                class="w-12 h-12 rounded-full bg-cinnabar text-on-cinnabar border border-gold shadow-xl inline-flex items-center justify-center cursor-pointer hover:opacity-90 transition"
                title="مشارکت در این صفحه" aria-label="مشارکت در این صفحه">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
            </button>
        </span>
    </template>
</span>
