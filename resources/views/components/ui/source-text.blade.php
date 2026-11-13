@props([
    // The catalogue the text comes from, e.g. «فهرست کتابخانه مسجد اعظم، چاپ ۱۳۸۸»
    'title',
    // Where in it: «ج ۳، ص ۲۶»
    'address' => null,
    // The Alpine expression that opens the page in the catalogue viewer; null hides «دیدن برگه»
    'viewer' => null,
    // A long text starts clipped with «نمایش کامل متن»
    'collapsible' => false,
    // The entry's own page on the site, when the source's entries have one (al-Dhari'a)
    'link' => null,
    // The text of the whole printed page on the site (/sources/fankha/2/page/926)
    'page' => null,
    // The address of the printed leaf's image: «تصویر برگه» then opens it here, over the text, at the column's width
    'image' => null,
])
{{-- One source text under «متن مأخذ» on the record page (backlog item 60): the printed library catalogue's and Fankha's
     look alike, with the address, «دیدن برگه» and «رونوشت متن»; an optional `badge` slot follows the title. When the text
     has parts marked data-copy, «رونوشت متن» copies those alone, leaving out buttons inside it. --}}
<div x-data="{ copied: false, open: {{ $collapsible ? 'false' : 'true' }}, leaf: false }" {{ $attributes }}>
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 mb-2">
        <h3 class="flex items-center gap-1 text-sm font-bold text-ink">
            <span>{{ $title }}</span>
            {{ $badge ?? '' }}
        </h3>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @if($address)
                <span class="text-muted">{{ $address }}</span>
            @endif
            {{-- Two ways on, by what they show (step 14.157): the printed leaf's image, and the text in the source on the
                 site, which is the entry's own page where the source has entries and the whole page's text otherwise --}}
            @if($viewer)
                @if($image)
                    <x-ui.btn size="sm" x-on:click="leaf = ! leaf" x-bind:aria-expanded="leaf" x-bind:class="leaf && 'border-cinnabar text-cinnabar'" data-leaf-toggle>تصویر برگه</x-ui.btn>
                @else
                    <x-ui.btn size="sm" x-on:click="{{ $viewer }}">تصویر برگه</x-ui.btn>
                @endif
            @endif
            @if($link)
                <x-ui.btn size="sm" href="{{ $link }}" title="صفحه‌ی همین مدخل در منبع">متن در منبع</x-ui.btn>
            @elseif($page)
                <x-ui.btn size="sm" href="{{ $page }}" data-page-text title="متن کامل همین صفحه‌ی منبع">متن در منبع</x-ui.btn>
            @endif
            <x-ui.btn size="sm" title="رونوشت متن" aria-label="رونوشت متن" x-on:click="navigator.clipboard.writeText([...$refs.text.querySelectorAll('[data-copy]')].map(e => e.innerText.trim()).join('\n\n') || $refs.text.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)">
                <svg x-show="!copied" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                <span x-show="copied" x-cloak class="text-emerald-600">رونوشت شد</span>
            </x-ui.btn>
        </div>
    </div>
    @if($viewer && $image)
        {{-- The printed leaf over its text (step 14.157): loaded when first opened; the viewer turns the pages --}}
        <template x-if="leaf">
            <figure class="m-0 mb-3 border border-line bg-white" data-leaf>
                <img src="{{ $image }}" alt="تصویر برگه‌ی {{ $title }}@if($address)، {{ $address }}@endif" class="block w-full h-auto cursor-zoom-in" x-on:click="{{ $viewer }}">
                <figcaption class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-line bg-surface text-xs text-muted">
                    <span>{{ $title }}@if($address)، {{ $address }}@endif</span>
                    <span class="flex gap-2">
                        <x-ui.btn size="sm" x-on:click="{{ $viewer }}">بزرگ‌نمایی و ورق‌زدن</x-ui.btn>
                        <x-ui.btn size="sm" variant="ghost" x-on:click="leaf = false">بستن تصویر</x-ui.btn>
                    </span>
                </figcaption>
            </figure>
        </template>
    @endif
    <div class="relative">
        <div x-ref="text" :class="open ? '' : 'max-h-64 overflow-hidden'"
             class="p-4 sm:p-5 bg-surface border border-line text-[13px] sm:text-sm text-ink leading-loose text-right">{{ $slot }}</div>
        @if($collapsible)
            <div x-show="! open" class="absolute inset-x-px bottom-px h-20 bg-gradient-to-t from-surface to-transparent flex items-end justify-center pb-2">
                <button type="button" @click="open = true" class="text-xs font-semibold text-cinnabar hover:underline cursor-pointer">نمایش کامل متن</button>
            </div>
        @endif
    </div>
    {{-- under the text and outside it: not clipped with a long text, not taken by «رونوشت متن» --}}
    @isset($footer)<div class="mt-1.5">{{ $footer }}</div>@endisset
</div>
