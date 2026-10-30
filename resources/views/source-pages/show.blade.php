@extends('layouts.app')

@php
    use App\Support\Fa;
    $source = $printed->source;
    $sourceName = $source->short_name ?: $source->title;
    $volumeNumber = $printed->volume->volume_number;
    $pageUrl = fn (?array $p) => $p ? route('source-pages.show', [$source->code, $p['volume'], $p['page']]) : null;
    $pageLabel = fn (array $p) => Fa::digits(($p['volume'] !== $volumeNumber ? "ج {$p['volume']}، " : '') . $printed->unit() . ' ' . $p['page']);
    $viewer = $printed->viewer();
    // a book's odd page lies left of the spine in a right-to-left book: its gutter is on the right, its number on the left
    $odd = $printed->page % 2 === 1;
    $snippet = mb_substr(preg_replace('/\s+/u', ' ', collect($printed->blocks)->pluck('text')->implode(' ')), 0, 150);
@endphp

@section('title', $source->title . '، ' . $printed->placeLabel() . ' | فهارس')
@section('meta_description', $sourceName . '، ' . $printed->placeLabel() . ($snippet !== '' ? ': ' . $snippet : ''))
{{-- every entry's text has its own page; this one is for reading on, not for the index --}}
@section('robots', 'noindex, follow')

@section('content')
{{-- One printed page of a source (plan docs/plans/source-page-text.md), set as a book's page: the running head, the
     text with each entry's heading linking to its entry page, the page number at the foot. The entries and works of
     the page, its image and «رفتن به صفحه» are beside it; the arrow keys turn the page. --}}
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6"
     x-data="{ go(url) { if (url) window.location = url } }"
     @keydown.window.arrow-right="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName)) go(@js($pageUrl($printed->previous)))"
     @keydown.window.arrow-left="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName)) go(@js($pageUrl($printed->next)))">
    <nav class="flex flex-wrap items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <span>{{ $sourceName }}</span>
        <span aria-hidden="true">/</span>
        <span>{{ Fa::digits('ج ' . $volumeNumber) }}</span>
        @if($printed->frontMatter)
            <span aria-hidden="true">/</span>
            <span>پیش‌گفتار</span>
        @endif
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold">{{ Fa::digits($printed->unit() . ' ' . $printed->page) }}</span>
    </nav>

    <h1 class="sr-only">{{ $source->title }}، {{ $printed->placeLabel() }}</h1>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_260px] items-start">
        <div class="min-w-0 space-y-5" x-data="{ copied: false }">
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs max-w-[46rem] mx-auto">
                <span class="text-muted">متن صفحه‌ی چاپی</span>
                <div class="flex flex-wrap items-center gap-2">
                    @if($viewer)
                        <x-ui.btn size="sm" x-on:click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($viewer) }})">دیدن برگه</x-ui.btn>
                    @endif
                    <x-ui.btn size="sm" x-on:click="navigator.clipboard.writeText($refs.sheet.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)">
                        <span x-show="!copied">رونوشت متن</span>
                        <span x-show="copied" x-cloak class="text-emerald-600">رونوشت شد</span>
                    </x-ui.btn>
                </div>
            </div>

            <article @class(['book-sheet max-w-[46rem] mx-auto', 'book-sheet--odd' => $odd]) data-printed-page>
                <header class="book-sheet__head">
                    <span @class(['book-sheet__folio', 'order-last' => $odd])>{{ Fa::digits($printed->page) }}</span>
                    <span class="book-sheet__title">{{ $source->title }}</span>
                    <span @class(['book-sheet__volume', 'order-first' => $odd])>{{ Fa::digits('ج ' . $volumeNumber) }}</span>
                </header>

                @if($printed->frontMatter)
                    <p class="book-sheet__label">پیش‌گفتار جلد {{ Fa::digits($volumeNumber) }}</p>
                @endif

                <div class="book-sheet__body" x-ref="sheet">
                    @forelse($printed->blocks as $block)
                        @php
                            $item = $block['item'];
                            $lines = explode("\n", $block['text'], 2);
                        @endphp
                        <section class="book-sheet__entry" @if($item) data-entry="{{ $item->item_key }}" @endif>
                            @if($block['continued'])
                                @if($item)
                                    <a href="{{ $item->url() }}" class="book-sheet__cont" data-entry-continued>ادامه‌ی «{{ $item->title ?: 'پیش‌گفتار' }}» از {{ Fa::digits($printed->unit() . ' ' . $item->page_start) }}</a>
                                @endif
                                <div class="whitespace-pre-line">{{ Fa::digits($block['text']) }}</div>
                            @else
                                <a href="{{ $item->url() }}" class="book-sheet__heading" data-entry-heading>{{ Fa::digits($lines[0]) }}</a>
                                @if(isset($lines[1]) && trim($lines[1]) !== '')
                                    <div class="whitespace-pre-line">{{ Fa::digits(trim($lines[1], "\n")) }}</div>
                                @endif
                            @endif
                        </section>
                    @empty
                        <p class="text-center text-sm text-muted font-sans py-16">این صفحه در چاپ متنی ندارد.</p>
                    @endforelse
                </div>

                <footer class="book-sheet__foot" aria-hidden="true">{{ Fa::digits($printed->page) }}</footer>
            </article>

            <nav class="flex justify-between gap-4 text-sm max-w-[46rem] mx-auto" aria-label="صفحه‌های پیش و پس">
                @if($printed->previous)
                    <a href="{{ $pageUrl($printed->previous) }}" class="book-turn" rel="prev"><span aria-hidden="true">→</span> {{ $pageLabel($printed->previous) }}</a>
                @else<span></span>@endif
                @if($printed->next)
                    <a href="{{ $pageUrl($printed->next) }}" class="book-turn" rel="next">{{ $pageLabel($printed->next) }} <span aria-hidden="true">←</span></a>
                @endif
            </nav>
        </div>

        <aside class="space-y-4 lg:sticky lg:top-24">
            <x-ui.card class="space-y-3">
                <div class="text-xs text-muted">مدخل‌های این صفحه</div>
                @forelse($printed->items as $item)
                    <a href="{{ $item->url() }}" class="flex items-baseline gap-1.5 text-sm text-ink hover:text-cinnabar" data-page-entry>
                        <span class="w-1.5 h-1.5 shrink-0 rotate-45 bg-gold translate-y-[-2px]" aria-hidden="true"></span>
                        <span class="min-w-0">{{ $item->title ?: 'پیش از نخستین مدخل' }}@if($item->entry_number)<span class="text-xs text-muted"> {{ Fa::digits('(ش ' . $item->entry_number . ')') }}</span>@endif
                            @if($item->page_start < $printed->page)<span class="block text-[11px] text-muted">{{ Fa::digits('از ' . $printed->unit() . ' ' . $item->page_start) }}</span>@endif
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-muted">این صفحه مدخلی ندارد.</p>
                @endforelse
            </x-ui.card>

            @if($links->isNotEmpty())
                <x-ui.card class="space-y-3">
                    <div class="text-xs text-muted">آثار فهارس</div>
                    @foreach($links as $link)
                        <div class="flex items-start gap-1 text-sm" data-machine-item="{{ $link->reviewKey() }}" data-item-link>
                            <div class="min-w-0">
                                <a href="{{ route('works.show', $link->citable) }}" class="font-bold text-ink hover:text-cinnabar">{{ $link->citable->primary_title }}</a>
                                @if($link->citable->author_name)<div class="text-xs text-muted">{{ $link->citable->author_name }}</div>@endif
                            </div>
                            @if($link->isAwaitingReview())
                                <x-ui.machine-badge class="shrink-0 mt-0.5" :text="$link->machineNote()" :review="$link->badgeReview()" />
                            @endif
                        </div>
                    @endforeach
                </x-ui.card>
            @endif

            @if($viewer)
                <x-ui.card data-viewer-card>
                    <div class="text-xs text-muted mb-3">برگه‌ی چاپی</div>
                    <button type="button" @click="$dispatch('open-catalog-viewer', @js($viewer))" class="block w-full group cursor-pointer" title="دیدن برگه در {{ $sourceName }}">
                        <div class="aspect-[3/4] overflow-hidden border border-line bg-white">
                            <img src="{{ $printed->thumbUrl() }}" alt="{{ 'برگه‌ی ' . $printed->placeLabel() }}" loading="lazy" class="w-full h-full object-cover object-top group-hover:opacity-90 transition" onerror="this.closest('[data-viewer-card]').remove()">
                        </div>
                        <span class="block mt-1.5 text-xs font-semibold text-cinnabar">دیدن برگه</span>
                    </button>
                </x-ui.card>
            @endif

            <x-ui.card>
                <form class="space-y-2" x-data="{ n: '' }"
                      @submit.prevent="const p = String(n).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).trim(); if (/^\d+$/.test(p)) go(@js(route('source-pages.show', [$source->code, $volumeNumber, 0])).replace(/0$/, p))">
                    <label for="go-to-page" class="block text-xs text-muted">رفتن به {{ $printed->unit() === 'ستون' ? 'ستون' : 'صفحه' }} در {{ Fa::digits('ج ' . $volumeNumber) }}</label>
                    <div class="flex gap-2">
                        <input id="go-to-page" x-model="n" inputmode="numeric" autocomplete="off" class="min-w-0 flex-1 px-2.5 py-1.5 text-sm bg-paper border border-line text-ink focus:border-cinnabar focus:outline-none" placeholder="{{ Fa::digits($printed->page) }}">
                        <x-ui.btn size="sm" type="submit">برو</x-ui.btn>
                    </div>
                </form>
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection
