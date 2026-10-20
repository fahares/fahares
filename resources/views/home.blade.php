@extends('layouts.app')

@section('title', 'فهارس | پایگاه جامع کتاب‌شناسی و نسخه‌شناسی مکتوب')

@php
    use App\Models\SiteSetting;
    use App\Support\Fa;
    $interval = max(3, (int) SiteSetting::get('home.slide_interval')) * 1000;
@endphp

@section('content')
<div class="pb-6">

    {{-- Header banner (admin → صفحه‌ی خانه → تصویر سربرگ); the search sheet sits on its lower edge --}}
    @if($hero)
        <div class="relative h-[200px] sm:h-[330px] overflow-hidden bg-[#221C17]">
            <img src="{{ $hero->image_url }}" alt="{{ $hero->title }}" fetchpriority="high" class="w-full h-full object-cover object-[center_45%]">
            <div class="absolute inset-0 bg-gradient-to-b from-transparent from-40% to-[#241F1A]/55" aria-hidden="true"></div>
            @if($hero->credit)
                <div class="absolute left-3 top-2 sm:top-auto sm:bottom-2 text-[11px] text-[#F3EAD6]/85">
                    @if($hero->link_url)<a href="{{ $hero->link_url }}" @if(str_starts_with($hero->link_url, 'http')) target="_blank" rel="noopener" @endif class="hover:underline">{{ $hero->credit }}</a>@else{{ $hero->credit }}@endif
                </div>
            @endif
        </div>
    @endif

    {{-- Search sheet --}}
    <section class="max-w-5xl mx-auto px-4 sm:px-6 {{ $hero ? '-mt-14 sm:-mt-[110px]' : 'mt-10' }} relative z-20">
        <div class="relative bg-surface border border-gold shadow-[inset_0_0_0_4px_var(--surface),inset_0_0_0_5px_var(--line),0_20px_40px_-24px_rgb(0_0_0/0.45)] px-5 py-7 sm:px-10 sm:py-9 text-center space-y-4">
            <div class="flex flex-col items-center md:flex-row md:justify-center md:gap-8 md:pt-4">
                <img src="{{ \App\Support\VersionedAsset::url('images/brand/shamseh-512.png') }}" alt="" width="176" height="176" class="h-24 w-24 sm:h-32 sm:w-32 md:h-44 md:w-44 shrink-0">
                <div class="flex flex-col items-center mt-4 md:mt-0 md:items-start md:text-right">
                    <h1 class="font-naskh text-[40px] sm:text-[60px] font-bold text-ink leading-[1.3]">فهارس</h1>
                    <p class="text-base sm:text-xl font-bold text-cinnabar">{{ SiteSetting::get('home.eyebrow') }}</p>
                    <p class="text-sm text-muted max-w-2xl leading-relaxed mt-2 md:mt-1">{{ SiteSetting::get('home.lede') }}</p>
                </div>
            </div>
            <h2 class="font-naskh text-lg sm:text-[22px] font-bold text-ink leading-[1.6] text-balance md:pt-2">{{ SiteSetting::get('home.headline') }}</h2>

            <div x-data="{ activeTab: 'works' }" class="max-w-3xl mx-auto text-right pt-2">
                <div class="flex items-center justify-center gap-1.5 mb-3 text-xs sm:text-sm font-semibold" role="tablist" aria-label="دامنه‌ی جستجو">
                    @foreach(['works' => 'آثار', 'manuscripts' => 'نسخه‌ها', 'people' => 'پدیدآوران و کاتبان'] as $tab => $tabLabel)
                        <button type="button" role="tab" @click="activeTab = '{{ $tab }}'" :aria-selected="activeTab === '{{ $tab }}'"
                                :class="activeTab === '{{ $tab }}' ? 'bg-paper text-ink border-line-strong' : 'text-muted border-transparent hover:text-ink'"
                                class="px-3.5 py-1 rounded-sm border transition cursor-pointer">{{ $tabLabel }}</button>
                    @endforeach
                </div>

                <form action="{{ route('search') }}" method="GET" class="relative group">
                    <input type="hidden" name="type" :value="activeTab">
                    <input type="hidden" name="scope" value="all">
                    <label for="home-search" class="sr-only">جستجو</label>
                    <div class="relative flex items-center">
                        <input type="text" name="q" id="home-search" autocomplete="off"
                               placeholder="عنوان اثر، نام مؤلف، کاتب، آغاز و انجام، یا شماره‌ی نسخه"
                               class="w-full pr-12 pl-28 py-3.5 sm:py-4 bg-paper text-ink placeholder:text-muted text-base rounded-sm border border-line-strong focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 outline-none transition">
                        <svg class="absolute right-4 w-5 h-5 text-muted group-focus-within:text-cinnabar transition pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <button type="submit" class="absolute left-1.5 px-5 py-2.5 bg-cinnabar text-on-cinnabar font-semibold rounded-sm text-sm hover:opacity-90 transition flex items-center gap-1.5 cursor-pointer">
                            <span>جستجو</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 12H5m6-6-6 6 6 6"/></svg>
                        </button>
                    </div>
                </form>

                <div class="mt-3 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-xs text-muted">
                    <span>نمونه:</span>
                    @foreach($suggestedSearches as $pill)
                        <a href="{{ $pill['url'] }}" class="text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $pill['label'] }}</a>
                    @endforeach
                </div>
            </div>

            <ul class="flex flex-wrap justify-center gap-y-2 pt-3 text-xs sm:text-sm text-muted tabular-nums" aria-label="آمار پایگاه">
                @foreach([
                    ['works', 'اثر', route('search', ['type' => 'works'])],
                    ['manuscripts', 'نسخه', route('search', ['type' => 'manuscripts'])],
                    ['people', 'پدیدآور و کاتب', route('search', ['type' => 'people'])],
                    ['libraries', 'کتابخانه', route('libraries.index')],
                    ['catalogers', 'فهرست‌نگار', route('catalogers.index')],
                ] as [$key, $statLabel, $statUrl])
                    <li class="relative px-3 sm:px-4 {{ $loop->first ? '' : "before:content-[''] before:absolute before:right-[-3px] before:top-1/2 before:-mt-[3px] before:w-1.5 before:h-1.5 before:bg-cinnabar before:rotate-45" }}">
                        <a href="{{ $statUrl }}" class="hover:text-ink transition">
                            <b class="font-bold text-ink text-base sm:text-lg ml-1">{{ Fa::num($stats[$key]) }}</b>{{ $statLabel }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Slides below the search box (admin → صفحه‌ی خانه → اسلایدها) --}}
    @if($slides->isNotEmpty())
        <section class="max-w-5xl mx-auto px-4 sm:px-6 pt-14"
                 x-data="{
                    i: 0, n: {{ $slides->count() }}, timer: null, paused: false,
                    go(k) { this.i = (k + this.n) % this.n },
                    start() {
                        if (this.n < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                        this.timer = setInterval(() => { if (!this.paused && !document.hidden) this.go(this.i + 1) }, {{ $interval }});
                    }
                 }"
                 x-init="start()"
                 @mouseenter="paused = true" @mouseleave="paused = false"
                 @focusin="paused = true" @focusout="paused = false"
                 aria-roledescription="carousel" aria-label="تصویرهای برگزیده">
            <div class="relative overflow-hidden bg-[#221C17] border border-gold shadow-[inset_0_0_0_3px_var(--surface)] p-[4px]">
                <div class="relative aspect-[16/5] overflow-hidden">
                    @foreach($slides as $slide)
                        <figure class="absolute inset-0 m-0 transition-opacity duration-700 motion-reduce:transition-none"
                                :class="i === {{ $loop->index }} ? 'opacity-100 z-10' : 'opacity-0 z-0'"
                                @if(! $loop->first) x-cloak @endif
                                aria-roledescription="slide" aria-label="{{ Fa::digits($loop->iteration) }} از {{ Fa::digits($slides->count()) }}"
                                :aria-hidden="i !== {{ $loop->index }}">
                            @if($slide->link_url)<a href="{{ $slide->link_url }}" class="block w-full h-full" :tabindex="i === {{ $loop->index }} ? 0 : -1">@endif
                                <img src="{{ $slide->image_url }}" alt="{{ $slide->title }}" class="w-full h-full object-cover"
                                     @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                            @if($slide->link_url)</a>@endif
                            @if($slide->show_caption && ($slide->title || $slide->subtitle))
                                <figcaption class="absolute inset-y-0 right-0 w-full sm:w-2/5 flex flex-col justify-end sm:justify-center gap-1 p-4 sm:p-8 bg-gradient-to-t sm:bg-gradient-to-l from-black/75 to-transparent text-white pointer-events-none">
                                    @if($slide->title)<span class="font-naskh text-lg sm:text-2xl font-bold leading-snug">{{ $slide->title }}</span>@endif
                                    @if($slide->subtitle)<span class="text-xs sm:text-sm text-white/85 leading-relaxed line-clamp-3">{{ $slide->subtitle }}</span>@endif
                                </figcaption>
                            @endif
                        </figure>
                    @endforeach

                    @if($slides->count() > 1)
                        <button type="button" @click="go(i - 1)" aria-label="تصویر قبلی"
                                class="absolute right-2 top-1/2 -translate-y-1/2 z-20 w-9 h-9 grid place-items-center rounded-full bg-black/40 text-white hover:bg-black/60 transition cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"/></svg>
                        </button>
                        <button type="button" @click="go(i + 1)" aria-label="تصویر بعدی"
                                class="absolute left-2 top-1/2 -translate-y-1/2 z-20 w-9 h-9 grid place-items-center rounded-full bg-black/40 text-white hover:bg-black/60 transition cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                        </button>
                        <div class="absolute bottom-2 inset-x-0 z-20 flex justify-center gap-2">
                            @foreach($slides as $slide)
                                <button type="button" @click="go({{ $loop->index }})" aria-label="تصویر {{ Fa::digits($loop->iteration) }}"
                                        class="w-2.5 h-2.5 rotate-45 border border-white/80 transition cursor-pointer"
                                        :class="i === {{ $loop->index }} ? 'bg-white' : 'bg-white/20 hover:bg-white/50'"></button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    {{-- Catalogers chosen in admin --}}
    @if($homeCatalogers->isNotEmpty())
        <section class="max-w-6xl mx-auto px-4 sm:px-6 pt-14">
            <div class="flex items-baseline justify-between gap-3 mb-6">
                <h2 class="rubric text-xl">{{ SiteSetting::get('home.catalogers_title') }}</h2>
                <a href="{{ route('catalogers.index') }}" class="text-xs sm:text-sm font-semibold text-cinnabar hover:underline whitespace-nowrap">همه‌ی فهرست‌نگاران</a>
            </div>
            <div class="grid grid-cols-3 sm:grid-cols-6 gap-4 sm:gap-6">
                @foreach($homeCatalogers as $c)
                    <a href="{{ route('catalogers.show', $c) }}" class="group grid justify-items-center gap-1.5 text-center">
                        <div class="w-full aspect-[4/5] rounded-t-full rounded-b-sm border border-gold p-1 bg-surface">
                            <div class="w-full h-full rounded-t-full rounded-b-[2px] overflow-hidden bg-paper">
                                @if($c->avatar_url)
                                    <img src="{{ $c->avatar_url }}" alt="" loading="lazy" class="w-full h-full object-cover sepia-[.85] contrast-[1.05] group-hover:sepia-0 transition duration-500 motion-reduce:transition-none">
                                @else
                                    <span class="w-full h-full grid place-items-center font-naskh text-3xl text-muted">{{ mb_substr($c->name, 0, 1) }}</span>
                                @endif
                            </div>
                        </div>
                        <span class="text-xs sm:text-sm font-bold text-ink leading-snug group-hover:text-cinnabar transition">{{ $c->name }}</span>
                        @if($c->life_years_text)
                            <span class="text-[11px] text-muted leading-tight">{{ Fa::digits($c->life_years_text) }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Featured images --}}
    @if($treasures->isNotEmpty())
        <section class="max-w-5xl mx-auto px-4 sm:px-6 pt-14">
            <h2 class="rubric text-xl mb-6">{{ SiteSetting::get('home.treasures_title') }}</h2>
            <div class="grid grid-cols-3 gap-3 sm:gap-6 {{ $treasures->count() > 3 ? 'lg:grid-cols-6' : '' }}">
                @foreach($treasures as $t)
                    <figure class="m-0 space-y-2 group">
                        @if($t->link_url)<a href="{{ $t->link_url }}" @if(str_starts_with($t->link_url, 'http')) target="_blank" rel="noopener" @endif class="block">@endif
                            <div class="aspect-[3/4] overflow-hidden bg-[#221C17] border border-line">
                                <img src="{{ $t->image_url }}" alt="{{ $t->title }}{{ $t->subtitle ? '، ' . $t->subtitle : '' }}" loading="lazy" class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform duration-500 motion-reduce:transition-none">
                            </div>
                        @if($t->link_url)</a>@endif
                        <figcaption class="space-y-0.5">
                            @if($t->title)<span class="block font-naskh text-base sm:text-lg font-bold text-ink">{{ $t->title }}</span>@endif
                            @if($t->subtitle)<span class="hidden sm:block text-xs text-ink/80">{{ $t->subtitle }}</span>@endif
                            @if($t->credit)<span class="hidden sm:block text-[11px] text-muted">{{ $t->credit }}</span>@endif
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Index of subjects and libraries --}}
    <section class="max-w-6xl mx-auto px-4 sm:px-6 pt-14 grid gap-12 md:grid-cols-2">
        <div>
            <div class="flex items-baseline justify-between gap-3 mb-4">
                <h2 class="rubric text-xl">نمایه‌ی موضوعات</h2>
                <a href="{{ route('subjects.index') }}" class="text-xs sm:text-sm font-semibold text-cinnabar hover:underline whitespace-nowrap">همه‌ی موضوعات</a>
            </div>
            <ul class="divide-y divide-transparent">
                @foreach($topSubjects as $subject)
                    <li>
                        <a href="{{ route('subjects.show', $subject) }}" class="flex items-baseline gap-2 py-1.5 group">
                            <span class="font-semibold text-ink group-hover:text-cinnabar transition whitespace-nowrap">{{ $subject->title }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($subject->works_count) }} اثر</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
        <div>
            <div class="flex items-baseline justify-between gap-3 mb-4">
                <h2 class="rubric text-xl">بزرگ‌ترین گنجینه‌ها</h2>
                <a href="{{ route('libraries.index') }}" class="text-xs sm:text-sm font-semibold text-cinnabar hover:underline whitespace-nowrap">همه‌ی {{ Fa::num($stats['libraries']) }} کتابخانه</a>
            </div>
            <ul>
                @foreach($topLibraries as $lib)
                    <li>
                        <a href="{{ route('libraries.show', $lib) }}" class="flex items-baseline gap-2 py-1.5 group">
                            <span class="font-semibold text-ink group-hover:text-cinnabar transition min-w-0">{{ $lib->display_name }}</span>
                            @if($lib->city)<span class="text-xs text-muted whitespace-nowrap">{{ $lib->city }}</span>@endif
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($lib->manuscripts_count) }} نسخه</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

</div>
@endsection
