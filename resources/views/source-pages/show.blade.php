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
    $width = $printed->columns() > 1 ? 'max-w-[62rem]' : 'max-w-[46rem]';
    $snippet = mb_substr(preg_replace('/\s+/u', ' ', \App\Support\PageMarks::strip(collect($printed->blocks)->pluck('text')->implode(' '))), 0, 150);
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
        <a href="{{ route('sources.index') }}" class="hover:text-cinnabar">منابع</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('sources.show', $source->code) }}" class="hover:text-cinnabar">{{ $sourceName }}</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('sources.show', $source->code) }}#v{{ $volumeNumber }}" class="hover:text-cinnabar">{{ Fa::digits('ج ' . $volumeNumber) }}</a>
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
            {{-- turning the page: back, the page to go to with the tools, and on; the same bar under the sheet --}}
            <nav class="book-nav {{ $width }} mx-auto" aria-label="صفحه‌های پیش و پس" data-page-nav>
                @if($printed->previous)
                    <a href="{{ $pageUrl($printed->previous) }}" class="book-turn" rel="prev"><span aria-hidden="true">→</span> {{ $pageLabel($printed->previous) }}</a>
                @else<span class="book-turn invisible" aria-hidden="true">→</span>@endif
                <div class="book-nav__middle">
                    <form class="flex items-center gap-1.5" x-data="{ n: '' }"
                          @submit.prevent="const p = String(n).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).trim(); if (/^\d+$/.test(p)) go(@js(route('source-pages.show', [$source->code, $volumeNumber, 0])).replace(/0$/, p))">
                        <label for="go-to-page" class="text-xs text-muted whitespace-nowrap">{{ $printed->unit() === 'ستون' ? 'ستون' : 'صفحه' }}</label>
                        <input id="go-to-page" x-model="n" inputmode="numeric" autocomplete="off" class="w-16 px-2 py-1 text-sm text-center bg-paper border border-line-strong text-ink focus:border-cinnabar focus:outline-none" placeholder="{{ Fa::digits($printed->page) }}" aria-label="رفتن به صفحه">
                        <x-ui.btn size="sm" type="submit">برو</x-ui.btn>
                    </form>
                    @if($viewer)
                        <x-ui.btn size="sm" x-on:click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($viewer) }})">دیدن برگه</x-ui.btn>
                    @endif
                    <x-ui.btn size="sm" x-on:click="navigator.clipboard.writeText($refs.sheet.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)">
                        <span x-show="!copied">رونوشت متن</span>
                        <span x-show="copied" x-cloak class="text-emerald-600">رونوشت شد</span>
                    </x-ui.btn>
                </div>
                @if($printed->next)
                    <a href="{{ $pageUrl($printed->next) }}" class="book-turn" rel="next">{{ $pageLabel($printed->next) }} <span aria-hidden="true">←</span></a>
                @else<span class="book-turn invisible" aria-hidden="true">←</span>@endif
            </nav>

            <article @class(['book-sheet mx-auto', $width, 'book-sheet--columns' => $printed->columns() > 1, 'book-sheet--odd' => $odd]) data-printed-page>
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
                            // al-Dhari'a's volume 26 prints the number alone, «[ 972 ]», and the title on the next line
                            // in parentheses: the title is linked too, the rest of its line goes on after it
                            $title = null;
                            // al-Dhari'a's heading, «( 5 : شارحة الصدور )» or an unnumbered «( ديوان محمد مذهب )», runs on with
                            // its paragraph: only the heading is the link
                            $rest = '';
                            if (preg_match('/^(\([^)\n]*\))(.+)$/us', $lines[0], $split)) {
                                [$lines[0], $rest] = [$split[1], $split[2]];
                            }
                            if (preg_match('/^\[\s*\d+\s*\]$/u', trim($lines[0])) && preg_match('/^\s*(\([^)\n]+\))(.*)$/us', $lines[1] ?? '', $m)) {
                                [$title, $lines[1]] = [$m[1], $m[2]];
                            }
                        @endphp
                        @php
                            // the heading leads to the entry's work (ⓘ while a machine's guess), not to its text again
                            $tied = $item ? ($works[$item->id] ?? collect()) : collect();
                            $work = $tied->first();
                            $workUrl = $work ? route('works.show', $work->citable) : null;
                        @endphp
                        <section class="book-sheet__entry" @if($item) data-entry="{{ $item->item_key }}" @endif>
                            @if($block['continued'])
                                @if($item)
                                    <a href="{{ $item->pageUrl() }}" class="book-sheet__cont" data-entry-continued>ادامه‌ی «{{ $item->title ?: 'پیش‌گفتار' }}» از {{ Fa::digits($printed->unit() . ' ' . $item->page_start) }}</a>
                                @endif
                                @if(! empty($block['marks']))
                                    {{-- a catalogue's page: its works' headings and its copies' lines link their records --}}
                                    <div class="whitespace-pre-line">{!! collect(\App\Support\PrintedPage::pieces($block))->map(fn ($p) => isset($p['url'])
                                        ? '<a href="' . e($p['url']) . '" class="book-sheet__mark book-sheet__mark--' . $p['kind'] . '" title="' . e($p['title']) . '" data-record-link="' . $p['kind'] . '">' . e(Fa::digits($p['text'])) . '</a>'
                                        : (string) \App\Support\PrintedPage::text($p['text']))->implode('') !!}</div>
                                @else
                                    <div class="whitespace-pre-line">{{ \App\Support\PrintedPage::text($block['text']) }}</div>
                                @endif
                            @else
                                <div class="book-sheet__headline">
                                    @if($workUrl && ! $title)
                                        <a href="{{ $workUrl }}" class="book-sheet__heading book-sheet__heading--work" title="{{ $work->citable->primary_title }}" data-entry-heading data-work-link>{{ \App\Support\PrintedPage::text($lines[0]) }}</a>
                                    @else
                                        <span class="book-sheet__heading" data-entry-heading>{{ \App\Support\PrintedPage::text($lines[0]) }}</span>
                                    @endif
                                    @if($work)
                                        @if($work->isAwaitingReview())<x-ui.machine-badge class="ms-1 align-middle" :text="$work->machineNote()" :review="$work->badgeReview()" />@endif
                                        @if($tied->count() > 1)<a href="#page-works" class="ms-1 font-sans text-xs text-muted hover:text-cinnabar" title="آثار دیگر این مدخل">{{ Fa::digits('+' . ($tied->count() - 1)) }}</a>@endif
                                    @endif
                                    @if($rest !== '')<span class="book-sheet__rest">{{ \App\Support\PrintedPage::text($rest) }}</span>@endif
                                </div>
                                @if($title)
                                    <div class="whitespace-pre-line">@if($workUrl)<a href="{{ $workUrl }}" class="book-sheet__heading book-sheet__heading--work" title="{{ $work->citable->primary_title }}" data-work-link>{{ \App\Support\PrintedPage::text($title) }}</a>@else<span class="book-sheet__heading">{{ \App\Support\PrintedPage::text($title) }}</span>@endif{{ \App\Support\PrintedPage::text(rtrim($lines[1])) }}</div>
                                @elseif(isset($lines[1]) && trim($lines[1]) !== '')
                                    <div class="whitespace-pre-line">{{ \App\Support\PrintedPage::text(trim($lines[1], "\n")) }}</div>
                                @endif
                            @endif
                        </section>
                    @empty
                        <p class="text-center text-sm text-muted font-sans py-16">این صفحه در چاپ متنی ندارد.</p>
                    @endforelse
                </div>

                <footer class="book-sheet__foot" aria-hidden="true">{{ Fa::digits($printed->page) }}</footer>
            </article>

            <nav class="flex justify-between gap-4 text-sm {{ $width }} mx-auto" aria-label="صفحه‌های پیش و پس، زیر برگه">
                @if($printed->previous)
                    <a href="{{ $pageUrl($printed->previous) }}" class="book-turn" rel="prev"><span aria-hidden="true">→</span> {{ $pageLabel($printed->previous) }}</a>
                @else<span></span>@endif
                @if($printed->next)
                    <a href="{{ $pageUrl($printed->next) }}" class="book-turn" rel="next">{{ $pageLabel($printed->next) }} <span aria-hidden="true">←</span></a>
                @endif
            </nav>
        </div>

        <aside class="space-y-4 lg:sticky lg:top-24">
            @if($printed->items->isEmpty() && $printed->records)
                <x-ui.card class="space-y-3">
                    <div class="text-xs text-muted">آثار این صفحه</div>
                    @foreach(collect($printed->records)->where('kind', 'work') as $record)
                        <a href="{{ $record['url'] }}" class="flex items-baseline gap-1.5 text-sm text-ink hover:text-cinnabar" data-page-record>
                            <span class="w-1.5 h-1.5 shrink-0 rotate-45 bg-gold translate-y-[-2px]" aria-hidden="true"></span>
                            <span class="min-w-0">{{ $record['title'] }}</span>
                        </a>
                    @endforeach
                    @php $copies = collect($printed->records)->where('kind', 'manuscript')->count(); @endphp
                    @if($copies)<p class="text-xs text-muted">{{ Fa::digits($copies . ' نسخه، با پیوند در متن') }}</p>@endif
                </x-ui.card>
            @else
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
            @endif

            @if($links->isNotEmpty())
                <x-ui.card class="space-y-3" id="page-works">
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

        </aside>
    </div>
</div>
<script>
    // a poem wider than its page goes in steps (.book-poem.is-stepped in app.css), measured again on a resize
    (() => {
        const step = () => document.querySelectorAll('[data-poem]').forEach((p) => {
            p.classList.remove('is-stepped');
            if (p.scrollWidth > p.parentElement.clientWidth + 1) p.classList.add('is-stepped');
        });
        step();
        window.addEventListener('resize', step);
    })();
</script>
@endsection
