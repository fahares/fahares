@extends('layouts.app')

@section('title', 'منابع و مراجع | فهارس')
@section('meta_description', 'فهرستگان، فهرست‌های کتابخانه‌ها، کتاب‌شناسی‌ها و تراجمی که فهارس از آن‌ها ساخته شده یا رکوردهایش را به آن‌ها پیوند داده است، با متن و برگه‌ی چاپی آن‌ها')

@php
    use App\Support\Fa;
    use App\Models\Source;
    use App\Support\SourceShelf;
    $headings = [
        Source::KIND_UNION_CATALOGUE => 'فهرستگان',
        Source::KIND_LIBRARY_CATALOGUE => 'فهرست‌های کتابخانه‌ها',
        Source::KIND_BIBLIOGRAPHY => 'کتاب‌شناسی‌ها',
        Source::KIND_BIOGRAPHICAL => 'تراجم',
        Source::KIND_OTHER => 'دیگر منابع',
    ];
    // a source's counts with its editions'
    $sum = fn (Source $s, string $key) => collect([$s, ...$s->editions])->sum(fn ($e) => $counts[$e->id][$key] ?? 0);
@endphp

@section('content')
{{-- «منابع و مراجع» (step 13.80): the sources by kind, each with what the site has of it; a box narrows the list --}}
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8" x-data="{ q: '' }">
    <header class="text-center grid justify-items-center gap-2 pb-2">
        <p class="text-xs font-bold text-cinnabar">کتاب‌هایی که فهارس از آن‌ها ساخته شده است</p>
        <h1 class="font-naskh text-[28px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">منابع و مراجع</h1>
        <p class="text-sm text-muted max-w-2xl leading-relaxed">
            فهرستگان و فهرست‌های چاپی کتابخانه‌ها که نسخه‌ها از آن‌ها آمده‌اند، و کتاب‌شناسی‌ها و تراجمی که آثار و اشخاص به آن‌ها پیوند دارند.
            هر منبع با جلدهایش، متن صفحه‌ها و برگه‌ی چاپی‌اش، هرجا که سایت آن را دارد.
        </p>
        <div class="relative w-full max-w-md mt-3">
            <label for="sources-q" class="sr-only">نام منبع</label>
            <input id="sources-q" type="text" x-model="q" placeholder="نام منبع یا کتابخانه" autocomplete="off"
                class="w-full pr-10 pl-4 py-2.5 bg-surface rounded-sm border border-line-strong text-sm text-ink placeholder:text-muted focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 outline-none">
            <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
        </div>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    @foreach($groups as $kind => $sources)
        <x-ui.section :title="$headings[$kind] ?? $kind" :count="$sources->count()" data-source-kind="{{ $kind }}">
            <ul class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-x-6 gap-y-10">
                @foreach($sources as $source)
                    @php
                        $text = $sum($source, 'pages') + $sum($source, 'items') > 0;
                        $images = collect([$source, ...$source->editions])->contains(fn ($e) => SourceShelf::hasImages($e));
                        $haystack = mb_strtolower($source->title . ' ' . $source->short_name . ' ' . $source->library?->display_name . ' ' . $source->compiler);
                    @endphp
                    <li x-show="! q || @js($haystack).includes(q.toLowerCase())" data-source="{{ $source->code }}">
                        <a href="{{ route('sources.show', $source->code) }}" class="group grid gap-2 content-start">
                            <x-source.cover :source="$source" class="w-full group-hover:-translate-y-1 transition duration-300 motion-reduce:transition-none" />
                            <span class="text-sm font-bold text-ink leading-6 group-hover:text-cinnabar transition">{{ $source->title }}</span>
                            <span class="text-[11px] text-muted leading-5">
                                {{ $source->library?->display_name ?? $source->compiler }}
                                @if($source->editions->isNotEmpty())· {{ Fa::num($source->editions->count() + 1) }} چاپ@endif
                                @if(($source->volumes_count ?? $source->volumes->count()) > 1)· {{ Fa::num($source->volumes_count ?? $source->volumes->count()) }} جلد@endif
                                @if($n = $sum($source, 'manuscripts'))· {{ Fa::num($n) }} نسخه@endif
                                @if($n = $sum($source, 'works'))· {{ Fa::num($n) }} اثر@endif
                            </span>
                            @if($text || $images)
                                <span class="flex gap-1.5">
                                    @if($text)<span class="px-1.5 text-[10px] font-bold text-gold border border-gold/50 rounded-sm" title="متن صفحه‌ها در سایت هست">متن</span>@endif
                                    @if($images)<span class="px-1.5 text-[10px] font-bold text-muted border border-line-strong rounded-sm" title="برگه‌ی چاپی در سایت هست">برگه</span>@endif
                                </span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endforeach
</div>
@endsection
