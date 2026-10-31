@extends('layouts.app')

@section('title', $cataloger->display_name . ' | کارنامه فهرست‌نگاری نسخ خطی')
@section('meta_description', 'شناسنامه، بیوگرافی و کارنامه فهرست‌نگاری ' . $cataloger->display_name . ' مشتمل بر مجلدات فهارس و ' . \App\Support\Fa::num($cataloger->manuscripts_count) . ' نسخه خطی توصیف‌شده در پایگاه فهارس')

@section('content')
@php
    // Volumes first; manuscripts when there are no volumes or the reader is paging through manuscripts
    $defaultTab = ($volumes->isEmpty() || request()->filled('manuscripts_page')) ? 'manuscripts' : 'volumes';
@endphp
@php use App\Support\Fa; @endphp
<div x-data="{ activeTab: '{{ $defaultTab }}' }" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('catalogers.index') }}" class="hover:text-cinnabar">فهرست‌نگاران</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold">{{ $cataloger->display_name }}</span>
    </nav>

    <header class="text-center grid justify-items-center gap-2 pb-6">
        <div class="w-32 sm:w-36 aspect-[4/5] rounded-t-full rounded-b-sm border border-gold p-1 bg-surface mb-2">
            <div class="w-full h-full rounded-t-full rounded-b-[2px] overflow-hidden bg-paper grid place-items-center">
                @if($cataloger->avatar_url)
                    <img src="{{ $cataloger->avatar_url }}" alt="{{ $cataloger->name }}" class="w-full h-full object-cover sepia-[.85] contrast-[1.05]">
                @else
                    <span class="font-naskh text-5xl text-muted">{{ mb_substr($cataloger->name, 0, 1) }}</span>
                @endif
            </div>
        </div>
        <p class="text-xs font-bold text-cinnabar">فهرست‌نگار و نسخه‌شناس</p>
        <h1 class="font-naskh text-[28px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">{{ $cataloger->name }}</h1>
        @if($cataloger->life_years_text)<p class="text-base text-muted">{{ Fa::digits($cataloger->life_years_text) }}</p>@endif
        <div class="flex flex-wrap justify-center gap-1.5 mt-1">
            @if(! empty($cataloger->metadata['specialty']))<x-ui.chip>{{ $cataloger->metadata['specialty'] }}</x-ui.chip>@endif
            @if($cataloger->person)<x-ui.chip :href="route('people.show', $cataloger->person)" tone="accent">مدخل در اشخاص فهارس</x-ui.chip>@endif
        </div>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px] items-start">
        <aside class="space-y-4 lg:order-last lg:sticky lg:top-24">
            <x-ui.card :framed="true">
                <div class="grid grid-cols-2 gap-2 text-center">
                    <div>
                        <div class="font-naskh text-2xl font-bold text-cinnabar tabular-nums">{{ Fa::num($cataloger->manuscripts_count) }}</div>
                        <div class="text-xs text-muted">نسخه‌ی فهرست‌شده</div>
                    </div>
                    <div>
                        <div class="font-naskh text-2xl font-bold text-cinnabar tabular-nums">{{ Fa::num($cataloger->volumes_count) }}</div>
                        <div class="text-xs text-muted">مجلد فهرست</div>
                    </div>
                </div>
                @if(! empty($cataloger->metadata['birth_place']) || ! empty($cataloger->metadata['resting_place']) || ! empty($cataloger->metadata['death_place']))
                    <div class="mt-4 pt-3 border-t border-line">
                        <x-ui.meta-list class="!grid-cols-[max-content_minmax(0,1fr)] !gap-y-2">
                            @if(! empty($cataloger->metadata['birth_place']))<x-ui.meta label="زادگاه">{{ $cataloger->metadata['birth_place'] }}</x-ui.meta>@endif
                            @if(! empty($cataloger->metadata['death_place']))<x-ui.meta label="درگذشت در">{{ $cataloger->metadata['death_place'] }}</x-ui.meta>@endif
                            @if(! empty($cataloger->metadata['resting_place']))<x-ui.meta label="آرامگاه">{{ $cataloger->metadata['resting_place'] }}</x-ui.meta>@endif
                        </x-ui.meta-list>
                    </div>
                @endif
            </x-ui.card>
        </aside>

        <div class="min-w-0 space-y-10">
            @if($cataloger->bio)
                <x-ui.section title="زندگی‌نامه و کارنامه">
                    <p class="text-sm sm:text-[15px] text-ink leading-loose whitespace-pre-line text-justify max-w-[72ch]">{{ $cataloger->bio }}</p>
                    @if(! empty($cataloger->metadata['sources']))
                        <p class="mt-3 text-xs text-muted leading-loose">منابع:
                            @foreach($cataloger->metadata['sources'] as $source)
                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="underline decoration-line-strong underline-offset-4 hover:text-ink">{{ $source['title'] }}</a>@if(! $loop->last)، @endif
                            @endforeach
                        </p>
                    @endif
                </x-ui.section>
            @endif

            @if(! empty($cataloger->metadata['institutions']) || ! empty($cataloger->metadata['major_catalogs']))
                <div class="grid md:grid-cols-2 gap-8">
                    @if(! empty($cataloger->metadata['institutions']))
                        <x-ui.section title="نهادهای محل خدمت">
                            <ul class="space-y-1 text-sm">
                                @foreach($cataloger->metadata['institutions'] as $inst)
                                    <li class="flex gap-2.5"><span class="mt-2 w-1.5 h-1.5 shrink-0 rotate-45 bg-gold" aria-hidden="true"></span><span class="text-ink">{{ $inst }}</span></li>
                                @endforeach
                            </ul>
                        </x-ui.section>
                    @endif
                    @if(! empty($cataloger->metadata['major_catalogs']))
                        <x-ui.section title="فهرست‌های شاخص">
                            <ul class="space-y-1 text-sm">
                                @foreach($cataloger->metadata['major_catalogs'] as $catName)
                                    <li class="flex gap-2.5"><span class="mt-2 w-1.5 h-1.5 shrink-0 rotate-45 bg-gold" aria-hidden="true"></span><span class="text-ink">{{ $catName }}</span></li>
                                @endforeach
                            </ul>
                        </x-ui.section>
                    @endif
                </div>
            @endif

            <div class="inline-flex border border-line-strong rounded-sm overflow-hidden text-sm" role="tablist">
                <button type="button" role="tab" @click="activeTab = 'volumes'" :aria-selected="activeTab === 'volumes'"
                    :class="activeTab === 'volumes' ? 'bg-ink text-paper' : 'text-muted hover:text-ink'" class="px-4 py-1.5 transition cursor-pointer">
                    فهرست‌ها <span class="tabular-nums">{{ Fa::num($catalogues->count()) }}</span>
                </button>
                <button type="button" role="tab" @click="activeTab = 'manuscripts'" :aria-selected="activeTab === 'manuscripts'"
                    :class="activeTab === 'manuscripts' ? 'bg-ink text-paper' : 'text-muted hover:text-ink'" class="px-4 py-1.5 transition cursor-pointer border-s border-line-strong">
                    نسخه‌های فهرست‌شده <span class="tabular-nums">{{ Fa::num($manuscripts->total()) }}</span>
                </button>
            </div>

            <!-- TAB: VOLUMES -->
            <div x-show="activeTab === 'volumes'" @if($defaultTab !== 'volumes') style="display: none;" @endif>
                @if($volumes->isEmpty())
                    <p class="text-sm text-muted">مجلدی برای این فهرست‌نگار ثبت نشده است.</p>
                @else
                    {{-- each printed catalogue once, with its cover, its page and the cataloger's volumes of it (step 13.109) --}}
                    <ul class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-6 gap-y-10" data-cataloger-catalogues>
                        @foreach($catalogues as $cat)
                            @php
                                $source = $cat['source'];
                                $first = $cat['volumes']->first();
                                $numbers = $cat['labels'];
                                $title = preg_replace('/\s*-\s*(?:جلد\s.*|[0-9۰-۹]+)$/u', '', $source->title);
                                $years = $cat['volumes']->pluck('publication_year')->filter()->unique()->values();
                            @endphp
                            <li data-cataloger-catalogue>
                                @if($cat['linked'])<a href="{{ route('sources.show', $source->code) }}" class="group grid gap-2 content-start">@else<div class="grid gap-2 content-start">@endif
                                    <x-source.cover :source="$source" :title="$title" class="w-full {{ $cat['linked'] ? 'group-hover:-translate-y-1 transition duration-300 motion-reduce:transition-none' : '' }}" />
                                    <span class="text-sm font-bold text-ink leading-6 {{ $cat['linked'] ? 'group-hover:text-cinnabar transition' : '' }}">{{ $title }}</span>
                                    <span class="text-[11px] text-muted leading-5">
                                        @if($first->library){{ $first->library->display_name }}@endif
                                        {{-- a book in one volume says none --}}
                                @if($numbers->isNotEmpty() && ! ($numbers->count() === 1 && in_array($numbers[0], ['1', 'اول'], true) && ($source->volumes_count ?? 1) <= 1))· {{ $numbers->count() > 1 ? 'جلدهای ' : 'جلد ' }}{{ Fa::digits($numbers->implode('، ')) }}@endif
                                        @if($years->isNotEmpty())· {{ Fa::digits($years->implode('، ')) }}@endif
                                    </span>
                                @if($cat['linked'])</a>@else</div>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <!-- TAB: MANUSCRIPTS -->
            <div x-show="activeTab === 'manuscripts'" @if($defaultTab !== 'manuscripts') style="display: none;" @endif>
                @if($manuscripts->isEmpty())
                    <p class="text-sm text-muted">نسخه‌ای ثبت نشده است.</p>
                @else
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($manuscripts as $m)
                            <li class="py-3 flex items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('manuscripts.show', $m) }}" class="font-naskh text-base font-bold text-ink hover:text-cinnabar transition">{{ $m->work?->clean_title ?: ($m->work?->primary_title ?: 'نسخه‌ی خطی') }}</a>
                                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted mt-0.5">
                                        <span>از {{ $m->work?->author?->name ?? 'ناشناخته' }}</span>
                                        <span class="text-ink" title="{{ $m->libraryRecord?->display_name }}">{{ $m->libraryRecord?->short_name ?? $m->library }}</span>
                                        @if($m->shelfmark)<span>شماره‌ی <span dir="ltr">{{ Fa::digits($m->shelfmark) }}</span></span>@endif
                                        @if($m->volume_number && $m->page_start)<span>{{ $m->catalog?->short_name ?? 'فنخا' }}، ج {{ Fa::digits($m->volume_number) }}، ص {{ Fa::digits($m->page_start) }}</span>@endif
                                    </div>
                                </div>
                                @if($m->volume_number && $m->page_start)
                                    <x-ui.btn size="sm" variant="ghost" @click="$dispatch('open-catalog-viewer', { catalog: '{{ $m->catalog?->code ?? 'fankha' }}', catalogName: '{{ $m->catalog?->short_name ?? 'فنخا' }}', volume: {{ $m->volume_number }}, page: {{ $m->page_start }} })" title="دیدن برگه در مأخذ چاپی">برگه</x-ui.btn>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if($manuscripts->hasPages())
                        <div class="pt-4">{{ $manuscripts->appends(request()->query())->links() }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>

</div>
@endsection
