@extends('layouts.app')

@section('title', $cataloger->display_name . ' | کارنامه فهرست‌نگاری نسخ خطی')
@section('meta_description', 'شناسنامه، بیوگرافی و کارنامه فهرست‌نگاری ' . $cataloger->display_name . ' مشتمل بر مجلدات فهارس و ' . number_format($cataloger->manuscripts_count) . ' نسخه خطی توصیف‌شده در پایگاه فهارس')

@section('content')
<div x-data="{ activeTab: 'manuscripts' }" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-stone-500">
        <a href="{{ route('home') }}" class="hover:text-[#B38A50] transition">فهارس</a>
        <span>/</span>
        <a href="{{ route('catalogers.index') }}" class="hover:text-[#B38A50] transition">فهرست‌نگاران</a>
        <span>/</span>
        <span class="text-stone-800 dark:text-stone-200 font-semibold">{{ $cataloger->display_name }}</span>
    </nav>

    <!-- CATALOGER HEADER CARD -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-6 relative overflow-hidden">
        
        <div class="flex flex-col lg:flex-row items-start justify-between gap-6">
            
            <!-- Avatar & Details -->
            <div class="flex flex-col sm:flex-row items-start gap-6">
                <!-- Avatar -->
                <div class="relative shrink-0">
                    @if($cataloger->avatar_url)
                        <img src="{{ $cataloger->avatar_url }}" 
                             alt="{{ $cataloger->name }}" 
                             class="w-28 h-28 sm:w-32 sm:h-32 rounded-3xl object-cover border-2 border-amber-300 dark:border-amber-800/80 shadow-md">
                    @else
                        <div class="w-28 h-28 sm:w-32 sm:h-32 rounded-3xl bg-amber-50 dark:bg-amber-950/40 border-2 border-amber-300 dark:border-amber-800/80 flex items-center justify-center text-4xl font-black text-[#B38A50] shadow-md">
                            {{ mb_substr($cataloger->name, 0, 1) }}
                        </div>
                    @endif
                </div>

                <!-- Titles & Info -->
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800/60 text-[#B38A50] text-xs font-bold">
                            فهرست‌نگار و نسخه‌شناس
                        </span>

                        @if(!empty($cataloger->metadata['specialty']))
                            <span class="px-3 py-1 rounded-full bg-stone-100 dark:bg-stone-800 border border-stone-200 dark:border-stone-700 text-stone-600 dark:text-stone-300 text-xs font-medium">
                                {{ $cataloger->metadata['specialty'] }}
                            </span>
                        @endif
                    </div>

                    <div>
                        <h1 class="text-2xl sm:text-3xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                            {{ $cataloger->name }}
                        </h1>
                    </div>

                    <!-- Connect to Person -->
                    @if($cataloger->person)
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <a href="{{ route('people.show', $cataloger->person) }}" 
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-stone-200 dark:hover:bg-stone-700 text-stone-700 dark:text-stone-200 text-xs font-semibold transition border border-stone-200 dark:border-stone-700">
                                <svg class="w-3.5 h-3.5 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <span>مدخل در اعلام و پدیدآوران فهارس</span>
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Stats Box -->
            <div class="p-4 rounded-2xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] text-xs space-y-2.5 min-w-[260px] shrink-0 self-stretch sm:self-auto">
                <div class="flex justify-between items-center">
                    <span class="text-stone-500 dark:text-stone-400">نسخه‌های توصیف‌شده:</span>
                    <span class="font-black text-[#B38A50] text-sm">{{ number_format($cataloger->manuscripts_count) }} نسخه</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-stone-500 dark:text-stone-400">مجلدات فهارس:</span>
                    <span class="font-bold text-stone-800 dark:text-stone-200">{{ $cataloger->volumes_count }} جلد</span>
                </div>
                @if($cataloger->life_years_text)
                    <div class="flex justify-between items-center pt-2 border-t border-stone-200/60 dark:border-stone-700/60">
                        <span class="text-stone-500 dark:text-stone-400">سال‌های حیات:</span>
                        <span class="font-bold text-stone-700 dark:text-stone-300">{{ $cataloger->life_years_text }}</span>
                    </div>
                @endif
                @if(!empty($cataloger->metadata['birth_place']))
                    <div class="flex justify-between items-center">
                        <span class="text-stone-500 dark:text-stone-400">زادگاه:</span>
                        <span class="font-semibold text-stone-700 dark:text-stone-300">{{ $cataloger->metadata['birth_place'] }}</span>
                    </div>
                @endif
                @if(!empty($cataloger->metadata['resting_place']) || !empty($cataloger->metadata['death_place']))
                    <div class="flex justify-between items-center">
                        <span class="text-stone-500 dark:text-stone-400">آرامگاه / وفات:</span>
                        <span class="font-semibold text-stone-700 dark:text-stone-300">{{ $cataloger->metadata['resting_place'] ?? $cataloger->metadata['death_place'] }}</span>
                    </div>
                @endif
            </div>

        </div>

        @if($cataloger->bio)
            <div class="pt-5 border-t border-stone-100 dark:border-stone-800/80 space-y-2">
                <h3 class="text-xs font-bold text-stone-400 uppercase tracking-wider">زندگینامه و کارنامه علمی:</h3>
                <p class="text-xs sm:text-sm text-stone-700 dark:text-stone-200 leading-relaxed max-w-5xl whitespace-pre-line text-justify">
                    {{ $cataloger->bio }}
                </p>
            </div>
        @endif

        @if(!empty($cataloger->metadata['institutions']) || !empty($cataloger->metadata['major_catalogs']))
            <div class="pt-5 border-t border-stone-100 dark:border-stone-800/80 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                @if(!empty($cataloger->metadata['institutions']))
                    <div class="p-4 rounded-2xl bg-stone-50 dark:bg-stone-900/60 border border-stone-200/80 dark:border-stone-800 space-y-2">
                        <div class="font-bold text-stone-500 dark:text-stone-400 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            <span>نهادها و کتابخانه‌های محل خدمت:</span>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($cataloger->metadata['institutions'] as $inst)
                                <span class="px-2.5 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-700 font-semibold text-stone-700 dark:text-stone-200">
                                    {{ $inst }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if(!empty($cataloger->metadata['major_catalogs']))
                    <div class="p-4 rounded-2xl bg-amber-50/50 dark:bg-amber-950/20 border border-amber-200/60 dark:border-amber-900/40 space-y-2">
                        <div class="font-bold text-[#8C6226] dark:text-amber-400 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                            <span>مجموعه‌ها و فهارس شاخص:</span>
                        </div>
                        <ul class="space-y-1 list-disc list-inside text-stone-600 dark:text-stone-300 font-medium">
                            @foreach($cataloger->metadata['major_catalogs'] as $catName)
                                <li>{{ $catName }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <!-- TABS SWITCH -->
        <div class="flex items-center gap-3 pt-4 border-t border-stone-100 dark:border-stone-800 text-sm font-semibold">
            <button 
                @click="activeTab = 'manuscripts'" 
                :class="activeTab === 'manuscripts' ? 'bg-[#292C56] text-amber-100 shadow' : 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300'"
                class="px-4 py-2 rounded-xl transition flex items-center gap-2 cursor-pointer">
                <span>نسخه‌های خطی توصیف‌شده</span>
                <span class="px-2 py-0.5 rounded-full text-xs bg-amber-500/20 font-bold">{{ number_format($manuscripts->total()) }}</span>
            </button>
            <button 
                @click="activeTab = 'volumes'" 
                :class="activeTab === 'volumes' ? 'bg-[#292C56] text-amber-100 shadow' : 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300'"
                class="px-4 py-2 rounded-xl transition flex items-center gap-2 cursor-pointer">
                <span>مجلدات فهارس چاپی</span>
                <span class="px-2 py-0.5 rounded-full text-xs bg-amber-500/20 font-bold">{{ $volumes->count() }}</span>
            </button>
        </div>

    </div>

    <!-- TAB 1: MANUSCRIPTS -->
    <div x-show="activeTab === 'manuscripts'" class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse($manuscripts as $m)
                <div class="bg-white dark:bg-[#15192C] p-5 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-2xs hover:shadow-md transition flex flex-col justify-between group space-y-4">
                    
                    <div class="space-y-2">
                        <!-- Work Title -->
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('manuscripts.show', $m) }}" class="text-sm font-bold text-[#292C56] dark:text-stone-100 group-hover:text-[#B38A50] transition line-clamp-1">
                                {{ $m->work?->clean_title ?: ($m->work?->primary_title ?: 'نسخه خطی') }}
                            </a>
                            @if($m->volume_number && $m->page_start)
                                <button 
                                    type="button"
                                    @click="$dispatch('open-catalog-viewer', { catalog: '{{ $m->catalog?->code ?? 'fankha' }}', catalogName: '{{ $m->catalog?->short_name ?? 'فنخا' }}', volume: {{ $m->volume_number }}, page: {{ $m->page_start }} })"
                                    title="مشاهده تصویر برگه در مأخذ چاپی"
                                    class="shrink-0 p-1.5 rounded-lg bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] hover:bg-amber-100 dark:hover:bg-amber-900/40 transition cursor-pointer">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            @endif
                        </div>

                        <!-- Author -->
                        <div class="text-xs text-stone-500 dark:text-stone-400">
                            مؤلف: <span class="font-medium text-stone-700 dark:text-stone-300">{{ $m->work?->author?->name ?? 'ناشناخته' }}</span>
                        </div>

                        <!-- Library & Shelfmark -->
                        <div class="text-xs text-stone-600 dark:text-stone-400 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#B38A50]"></span>
                            <span>{{ $m->libraryRecord?->name ?? $m->library }}</span>
                            @if($m->shelfmark)
                                <span class="text-stone-400">• ش: {{ $m->shelfmark }}</span>
                            @endif
                        </div>
                    </div>

                    <!-- Footer: Citation -->
                    <div class="pt-3 border-t border-stone-100 dark:border-stone-800/80 flex items-center justify-between text-[11px] text-stone-400">
                        <span>
                            @if($m->volume_number && $m->page_start)
                                فنخا: ج {{ $m->volume_number }}، ص {{ $m->page_start }}
                            @endif
                        </span>
                        <a href="{{ route('manuscripts.show', $m) }}" class="font-semibold text-[#B38A50] hover:underline">
                            مشاهده شناسنامه ←
                        </a>
                    </div>

                </div>
            @empty
                <div class="col-span-full p-12 bg-white dark:bg-[#15192C] rounded-3xl text-center text-stone-400">
                    نسخه‌ای ثبت نگردیده است.
                </div>
            @endforelse
        </div>

        <!-- Pagination -->
        @if($manuscripts->hasPages())
            <div class="pt-4 flex justify-center">
                {{ $manuscripts->appends(request()->query())->links() }}
            </div>
        @endif
    </div>

    <!-- TAB 2: VOLUMES -->
    <div x-show="activeTab === 'volumes'" class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse($volumes as $vol)
                <div class="bg-white dark:bg-[#15192C] p-5 rounded-2xl border border-stone-200 dark:border-stone-800 shadow-2xs space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="space-y-1">
                            <div class="text-sm font-bold text-[#292C56] dark:text-stone-100">
                                {{ $vol->title }}
                            </div>
                            @if($vol->library)
                                <div class="text-xs text-stone-500 dark:text-stone-400">
                                    کتابخانه: <span class="text-stone-700 dark:text-stone-300 font-medium">{{ $vol->library->name }}</span>
                                </div>
                            @endif
                        </div>

                        @if($vol->volume_number)
                            <span class="px-2.5 py-1 rounded-xl bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold shrink-0 border border-amber-200 dark:border-amber-800/60">
                                جلد {{ $vol->volume_number }}
                            </span>
                        @endif
                    </div>

                    <div class="text-xs text-stone-400 space-y-1 pt-2 border-t border-stone-100 dark:border-stone-800/80">
                        @if($vol->publisher)
                            <div>ناشر: {{ $vol->publisher }}</div>
                        @endif
                        @if($vol->publication_year)
                            <div>سال انتشار: {{ $vol->publication_year }}</div>
                        @endif
                        @if($vol->pages_count)
                            <div>تعداد صفحات: {{ number_format($vol->pages_count) }} صفحه</div>
                        @endif
                        @if($vol->manuscripts_range)
                            <div>شماره نسخه‌ها: {{ $vol->manuscripts_range }}</div>
                        @endif
                    </div>

                    <div class="pt-2 flex items-center justify-between text-xs">
                        <span class="text-stone-500">نسخه‌های مستندشده:</span>
                        <span class="font-bold text-[#B38A50]">
                            {{ number_format($vol->manuscripts_count) }} نسخه
                        </span>
                    </div>
                </div>
            @empty
                <div class="col-span-full p-12 bg-white dark:bg-[#15192C] rounded-3xl text-center text-stone-400">
                    مجلدی برای این فهرست‌نگار ثبت نگردیده است.
                </div>
            @endforelse
        </div>
    </div>

</div>
@endsection
