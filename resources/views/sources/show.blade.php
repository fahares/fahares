@extends('layouts.app')

@php
    use App\Support\Fa;
    use App\Models\Source;
    use App\Support\SearchFilters;
    $name = $source->short_name ?: $source->title;
    $kind = Source::KIND_LABELS[$source->kind] ?? 'منبع';
    $about = array_filter([$source->compiler, $source->publisher, $source->edition_label]);
@endphp

@section('title', $source->title . ' | منابع و مراجع فهارس')
@section('meta_description', $kind . ': ' . $source->title . ($about ? '، ' . implode('، ', $about) : '') . '. جلدها، متن صفحه‌ها و برگه‌ی چاپی آن در فهارس.')

@section('content')
{{-- One source (step 13.79): what it is, what the site has of it, and each volume with where to start reading its text
     and turning its page images. --}}
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <nav class="flex flex-wrap items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('sources.index') }}" class="hover:text-cinnabar">منابع و مراجع</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold">{{ $name }}</span>
    </nav>

    <header class="text-center grid justify-items-center gap-2 pb-4">
        <p class="text-xs font-bold text-cinnabar">{{ $kind }}</p>
        <h1 class="font-naskh text-[26px] sm:text-[36px] font-bold leading-[1.6] text-ink text-balance">{{ $source->title }}</h1>
        @if($about)<p class="text-sm text-muted">{{ Fa::digits(implode('، ', $about)) }}</p>@endif
        @if($source->library)
            <p class="text-sm"><a href="{{ route('libraries.show', $source->library) }}" class="text-cinnabar hover:underline">{{ $source->library->display_name }}</a></p>
        @endif
        @if($source->editionOf)
            <p class="text-xs text-muted">چاپی از <a href="{{ route('sources.show', $source->editionOf->code) }}" class="text-cinnabar hover:underline">{{ $source->editionOf->title }}</a></p>
        @endif
        <div class="flex flex-wrap justify-center gap-x-5 gap-y-1 mt-2 text-sm text-muted" data-source-counts>
            @if($counts['manuscripts'])<span><span class="font-bold text-ink tabular-nums">{{ Fa::num($counts['manuscripts']) }}</span> نسخه</span>@endif
            @if($counts['works'])<span><span class="font-bold text-ink tabular-nums">{{ Fa::num($counts['works']) }}</span> اثر</span>@endif
            @if($counts['items'])<span><span class="font-bold text-ink tabular-nums">{{ Fa::num($counts['items']) }}</span> مدخل چاپی</span>@endif
            @if($counts['pages'])<span><span class="font-bold text-ink tabular-nums">{{ Fa::num($counts['pages']) }}</span> صفحه‌ی متن</span>@endif
        </div>
        <div class="flex flex-wrap justify-center gap-2 mt-2">
            @if($counts['items'])
                <x-ui.btn size="sm" :href="route('search', ['type' => 'sources', 'source_id' => $source->id])">جستجو در مدخل‌های این منبع</x-ui.btn>
            @endif
            @if($counts['works'] && in_array($source->kind, SearchFilters::SOURCE_KINDS, true))
                <x-ui.btn size="sm" :href="route('search', ['type' => 'works', 'source_id' => $source->id])">آثار یادشده در آن</x-ui.btn>
            @endif
        </div>
    </header>

    @if($source->description)
        <p class="max-w-3xl mx-auto text-sm text-ink leading-loose text-justify">{{ Fa::digits($source->description) }}</p>
    @endif

    <div class="jadval" aria-hidden="true"></div>

    @if($source->editions->isNotEmpty())
        <x-ui.section title="چاپ‌های دیگر">
            <ul class="space-y-1 text-sm">
                @foreach($source->editions as $edition)
                    <li><a href="{{ route('sources.show', $edition->code) }}" class="text-cinnabar hover:underline">{{ $edition->title }}</a>@if($edition->edition_label)<span class="text-muted"> ({{ Fa::digits($edition->edition_label) }})</span>@endif</li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif

    <x-ui.section title="جلدها" :count="$volumes->count()">
        <ul class="divide-y divide-line border-y border-line" data-source-volumes>
            @foreach($volumes as $row)
                @php $v = $row['volume']; @endphp
                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3" data-volume="{{ $v->volume_number }}">
                    <span class="w-12 shrink-0 font-naskh text-lg font-bold text-ink">{{ Fa::digits('ج ' . $v->volume_number) }}</span>
                    <span class="flex-1 min-w-40 text-xs text-muted">
                        @if($v->title && $v->title !== $source->title){{ Fa::digits($v->title) }} · @endif
                        @if($row['pages']){{ Fa::num($row['pages']) }} صفحه‌ی متن @elseif($row['items']){{ Fa::num($row['items']) }} مدخل @endif
                        @if($v->publication_year)· {{ Fa::digits($v->publication_year) }}@endif
                    </span>
                    <span class="flex flex-wrap gap-2">
                        @if($row['read'])<x-ui.btn size="sm" :href="$row['read']" data-read-volume>خواندن متن</x-ui.btn>@endif
                        @if($row['viewer'])<x-ui.btn size="sm" x-data x-on:click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($row['viewer']) }})">دیدن برگه‌ها</x-ui.btn>@endif
                    </span>
                </li>
            @endforeach
        </ul>
    </x-ui.section>
</div>
@endsection
