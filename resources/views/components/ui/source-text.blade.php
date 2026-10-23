@props([
    // The catalogue the text comes from, e.g. «فهرست کتابخانه مسجد اعظم، چاپ ۱۳۸۸»
    'title',
    // Where in it: «ج ۳، ص ۲۶»
    'address' => null,
    // The Alpine expression that opens the page in the catalogue viewer; null hides «دیدن برگه»
    'viewer' => null,
    // A long text starts clipped with «نمایش کامل متن»
    'collapsible' => false,
])
{{-- One source text under «متن مأخذ» on the record page (backlog item 60): the printed library catalogue's and Fankha's
     look alike, with the address, «دیدن برگه» and «رونوشت متن»; an optional `badge` slot follows the title. When the text
     has parts marked data-copy, «رونوشت متن» copies those alone, leaving out buttons inside it. --}}
<div x-data="{ copied: false, open: {{ $collapsible ? 'false' : 'true' }} }" {{ $attributes }}>
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 mb-2">
        <h3 class="flex items-center gap-1 text-sm font-bold text-ink">
            <span>{{ $title }}</span>
            {{ $badge ?? '' }}
        </h3>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @if($address)
                <span class="text-muted">{{ $address }}</span>
            @endif
            @if($viewer)
                <x-ui.btn size="sm" x-on:click="{{ $viewer }}">دیدن برگه</x-ui.btn>
            @endif
            <x-ui.btn size="sm" x-on:click="navigator.clipboard.writeText([...$refs.text.querySelectorAll('[data-copy]')].map(e => e.innerText.trim()).join('\n\n') || $refs.text.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)">
                <span x-show="!copied">رونوشت متن</span>
                <span x-show="copied" x-cloak class="text-emerald-600">رونوشت شد</span>
            </x-ui.btn>
        </div>
    </div>
    <div class="relative">
        <div x-ref="text" :class="open ? '' : 'max-h-64 overflow-hidden'"
             class="p-4 sm:p-5 bg-surface border border-line text-[13px] sm:text-sm text-ink leading-loose text-right">{{ $slot }}</div>
        @if($collapsible)
            <div x-show="! open" class="absolute inset-x-px bottom-px h-20 bg-gradient-to-t from-surface to-transparent flex items-end justify-center pb-2">
                <button type="button" @click="open = true" class="text-xs font-semibold text-cinnabar hover:underline cursor-pointer">نمایش کامل متن</button>
            </div>
        @endif
    </div>
</div>
