@extends('layouts.app')

@php
    use App\Support\Fa;
    $source = $reference->source;
    $sourceName = $source->short_name ?: $source->title;
    $what = match (true) {
        $reference->item !== null => 'این مدخل',
        $reference->page !== null => 'این صفحه',
        $reference->volume !== null => 'این جلد',
        default => 'این کتاب',
    };
    $missing = $reference->missing();
    $query = array_filter(['source' => $source->code, 'volume' => $reference->volume?->volume_number,
        'page' => $reference->item ? null : $reference->page, 'entry' => $reference->item?->urlKey()]);
    $forms = [
        'fahares' => ['ارجاع به فهارس', 'به صفحه‌ای از فهارس که متن یا تصویر منبع را در آن دیده‌اید.', $reference->fahares()],
        'in-text' => ['ارجاع درون‌متنی', null, $reference->inText()],
        'apa' => ['APA', null, $reference->apa()],
        'chicago' => ['شیکاگو', null, $reference->chicago()],
    ];
@endphp

@section('title', 'ارجاع به ' . $reference->label() . ' | فهارس')
@section('meta_description', 'ارجاع آماده به ' . $reference->label() . '، با قالب‌های APA و شیکاگو و دریافت BibTeX و RIS.')
@section('robots', 'noindex, follow')

@section('content')
{{-- The citation of a printed source, one of its pages or one entry (step 14.389, backlog item 132): the printed book
     first, as research cites the book the text is in; then the page on Fahares, the in-text, APA and Chicago forms, the
     permanent address and the downloads. Each form copies with one button. --}}
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6" x-data="{ copied: null, copy(key, text) { navigator.clipboard.writeText(text); this.copied = key; setTimeout(() => { if (this.copied === key) this.copied = null }, 2500) } }">
    <nav class="flex flex-wrap items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('sources.index') }}" class="hover:text-cinnabar">منابع</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('sources.show', $source->code) }}" class="hover:text-cinnabar">{{ $sourceName }}</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold">ارجاع</span>
    </nav>

    <header class="space-y-2">
        <h1 class="text-2xl font-bold text-ink">ارجاع به {{ $what }}</h1>
        <p class="text-sm text-muted leading-7" data-cite-label>
            <a href="{{ $reference->url() }}" class="text-cinnabar hover:underline">{{ $reference->label() }}</a>
            @if($reference->item && filled($reference->item->title))
                <span>، مدخل «{{ $reference->item->title }}»</span>
            @endif
        </p>
    </header>

    {{-- the printed book: what to cite in research --}}
    <section class="border border-line-strong bg-surface p-4 sm:p-5 space-y-3" data-cite-form="printed">
        <h2 class="text-sm font-bold text-ink">ارجاع به چاپ منبع</h2>
        <p class="text-xs text-muted leading-6">در پژوهش، به کتاب چاپی که متن از آن است ارجاع دهید.</p>
        <p class="font-lotus text-[1.1rem] leading-9 text-ink select-all" data-cite-text>{{ $reference->printed() }}</p>
        @if($missing)
            <p class="text-xs text-muted leading-6" data-cite-missing>
                {{ implode('، ', array_slice($missing, 0, -1)) . (count($missing) > 1 ? ' و ' : '') . end($missing) }} این منبع در فهارس ثبت نشده است. اگر کتاب در دسترستان است، آن را در ارجاع خود بیفزایید، یا در <a href="{{ route('sources.show', $source->code) }}" class="text-cinnabar hover:underline">صفحۀ منبع</a> با «پیشنهاد اصلاح» به ما بگویید.
            </p>
        @endif
        <x-ui.btn size="sm" x-on:click="copy('printed', {{ \Illuminate\Support\Js::from($reference->printed()) }})">
            <span x-show="copied !== 'printed'">رونوشت</span>
            <span x-show="copied === 'printed'" x-cloak class="text-emerald-600">رونوشت شد</span>
        </x-ui.btn>
    </section>

    @foreach($forms as $key => [$title, $hint, $text])
        <section class="border border-line bg-paper p-4 sm:p-5 space-y-3" data-cite-form="{{ $key }}">
            <h2 class="text-sm font-bold text-ink">{{ $title }}</h2>
            @if($hint)<p class="text-xs text-muted leading-6">{{ $hint }}</p>@endif
            {{-- the address in a Persian sentence keeps its own direction --}}
            <p class="font-lotus text-[1.05rem] leading-8 text-ink select-all [overflow-wrap:anywhere]" data-cite-text>{!! str_replace(e($reference->url()), '<bdi dir="ltr" class="font-sans text-[0.8em]">' . e($reference->url()) . '</bdi>', e($text)) !!}</p>
            <x-ui.btn size="sm" x-on:click="copy('{{ $key }}', {{ \Illuminate\Support\Js::from($text) }})">
                <span x-show="copied !== '{{ $key }}'">رونوشت</span>
                <span x-show="copied === '{{ $key }}'" x-cloak class="text-emerald-600">رونوشت شد</span>
            </x-ui.btn>
        </section>
    @endforeach

    <section class="border border-line bg-paper p-4 sm:p-5 space-y-3" data-cite-form="permalink">
        <h2 class="text-sm font-bold text-ink">نشانی پایدار</h2>
        <p class="text-xs text-muted leading-6">این نشانی همیشه به همین {{ $reference->item ? 'مدخل' : ($reference->page && $reference->url() !== route('sources.show', $source->code) ? 'صفحه' : 'منبع') }} در فهارس می‌رسد.</p>
        <p class="text-sm text-ink [overflow-wrap:anywhere]" dir="ltr" data-cite-text>{{ $reference->url() }}</p>
        <x-ui.btn size="sm" x-on:click="copy('permalink', {{ \Illuminate\Support\Js::from($reference->url()) }})">
            <span x-show="copied !== 'permalink'">رونوشت</span>
            <span x-show="copied === 'permalink'" x-cloak class="text-emerald-600">رونوشت شد</span>
        </x-ui.btn>
    </section>

    <p class="flex flex-wrap items-center gap-2 text-sm text-muted" data-cite-downloads>
        <span>دریافت برای نرم‌افزارهای مدیریت منابع (Zotero، EndNote، Mendeley):</span>
        <x-ui.btn size="sm" :href="route('cite.show', $query + ['format' => 'bib'])" rel="nofollow">BibTeX</x-ui.btn>
        <x-ui.btn size="sm" :href="route('cite.show', $query + ['format' => 'ris'])" rel="nofollow">RIS</x-ui.btn>
    </p>

    @if($reference->volume)
        <p class="text-xs text-muted">
            ارجاع به <a href="{{ route('cite.show', ['source' => $source->code]) }}" class="text-cinnabar hover:underline">کل کتاب</a>
            @if($reference->item && $reference->item->page_start)
                · <a href="{{ route('cite.show', ['source' => $source->code, 'volume' => $reference->volume->volume_number, 'page' => $reference->item->page_start]) }}" class="text-cinnabar hover:underline">صفحۀ این مدخل</a>
            @endif
        </p>
    @endif
</div>
@endsection
