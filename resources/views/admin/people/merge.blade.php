@extends('layouts.admin')

@section('title', 'ابزار ادغام و یکپارچه‌سازی اعلام')
@section('page_title', 'ابزار هوشمند یکپارچه‌سازی اعلام و پدیدآوران (Authorities Merger)')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.people.index') }}" class="hover:text-[#B38A50]">اشخاص</a>
    <span>/</span>
    <span class="text-stone-400">ادغام اعلام</span>
@endsection

@section('content')
<div x-data="authorityMerger({
        preloadedSource: {{ Js::from($preloadedSource) }},
        preloadedTarget: {{ Js::from($preloadedTarget) }},
        searchUrl: '{{ route('admin.people.search') }}'
    })" 
    class="max-w-5xl mx-auto space-y-6">

    <!-- Guide & Rollback Safety Banner -->
    <div class="p-5 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-xs text-amber-900 dark:text-amber-200 flex items-start gap-3 leading-relaxed shadow-xs">
        <div class="p-2 rounded-xl bg-amber-500/20 text-amber-700 dark:text-amber-300 shrink-0 mt-0.5">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="space-y-1">
            <h4 class="font-bold text-sm text-amber-800 dark:text-amber-300">راهنمای هوشمند ادغام و یکپارچه‌سازی اعلام:</h4>
            <p class="text-stone-700 dark:text-stone-300">
                در این میز کار، با جستجوی نام یا وارد کردن شناسه عددی، دو شخص مورد نظر را انتخاب نمایید. کلیه آثار تألیفی و کتابت‌های <strong>شخص مبدأ (حذف‌شونده)</strong> به <strong>شخص مقصد (اصلی)</strong> منتقل شده و شمارنده‌های آماری بازشماری می‌شوند.
                <span class="text-emerald-700 dark:text-emerald-300 font-bold">خیالتان راحت باشد: تمام اطلاعات در لاگ ممیزی اسنپ‌شات شده و با دکمه بازگردانی (Undo) در هر زمان قابل احیاست.</span>
            </p>
        </div>
    </div>

    <!-- Main Merging Desk Card -->
    <div class="p-6 sm:p-8 rounded-3xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-sm space-y-8">
        
        <form action="{{ route('admin.people.merge') }}" method="POST" class="space-y-8">
            @csrf
            
            <!-- Hidden inputs submitted to backend -->
            <input type="hidden" name="source_id" :value="source ? source.id : ''" required>
            <input type="hidden" name="target_id" :value="target ? target.id : ''" required>

            <!-- Dual Authority Selection Area -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start relative">

                <!-- 1. SOURCE AUTHORITY (To be merged and removed) -->
                <div class="rounded-2xl border-2 transition-all p-5 space-y-4"
                     :class="source ? 'border-rose-400/50 bg-rose-50/20 dark:bg-rose-950/10' : 'border-dashed border-stone-200 dark:border-stone-800 bg-stone-50/50 dark:bg-stone-900/30'">
                    
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold bg-rose-500/15 text-rose-700 dark:text-rose-300">
                            <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span>
                            ۱. شخص مبدأ (تکراری / حذف‌شونده)
                        </span>
                        <template x-if="source">
                            <button type="button" @click="clearSource()" class="text-xs text-rose-600 hover:text-rose-800 dark:text-rose-400 font-bold hover:underline cursor-pointer flex items-center gap-1">
                                <span>تغییر / حذف انتخاب ✕</span>
                            </button>
                        </template>
                    </div>

                    <!-- Search Input (When no source is selected) -->
                    <div x-show="!source" class="relative">
                        <label class="block text-xs font-bold text-stone-600 dark:text-stone-300 mb-1.5">
                            جستجو بر اساس نام، شهرت، کنیه یا کد عددی:
                        </label>
                        <div class="relative">
                            <input type="text" 
                                   x-model="sourceQuery"
                                   @input.debounce.250ms="searchSource()"
                                   @focus="if(sourceQuery.trim().length >= 2) showSourceDropdown = true"
                                   placeholder="مثلاً: فیض کاشانی یا 14 ..."
                                   class="w-full pr-10 pl-10 py-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 text-xs font-semibold focus:border-rose-500 focus:ring-2 focus:ring-rose-500/20 outline-none transition">
                            
                            <!-- Search Icon -->
                            <div class="absolute right-3.5 top-3.5 text-stone-400">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>

                            <!-- Loading Spinner -->
                            <div x-show="loadingSource" class="absolute left-3.5 top-3.5 text-rose-500">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            </div>
                        </div>

                        <!-- Autocomplete Results Dropdown -->
                        <div x-show="showSourceDropdown && sourceResults.length > 0" 
                             @click.outside="showSourceDropdown = false"
                             class="absolute z-30 w-full mt-2 bg-white dark:bg-stone-900 rounded-2xl shadow-xl border border-stone-200 dark:border-stone-700 max-h-72 overflow-y-auto divide-y divide-stone-100 dark:divide-stone-800">
                            <template x-for="p in sourceResults" :key="p.id">
                                <button type="button" @click="selectSource(p)"
                                        class="w-full p-3 text-right hover:bg-rose-50 dark:hover:bg-rose-950/30 transition flex items-center justify-between gap-3 cursor-pointer">
                                    <div class="space-y-1">
                                        <div class="font-bold text-xs text-stone-900 dark:text-stone-100" x-text="p.name"></div>
                                        <div class="text-[11px] text-stone-500 dark:text-stone-400 flex items-center gap-2">
                                            <span class="font-mono text-stone-400" x-text="'#' + p.id"></span>
                                            <span x-show="p.death_year_hijri" x-text="'وفات ' + p.death_year_hijri + ' ق'"></span>
                                            <span x-show="!p.death_year_hijri && p.century_hijri" x-text="'قرن ' + p.century_hijri + ' ق'"></span>
                                        </div>
                                    </div>
                                    <div class="text-left shrink-0">
                                        <span class="px-2 py-0.5 rounded-lg bg-stone-100 dark:bg-stone-800 text-[10px] font-mono font-bold text-[#B38A50]" 
                                              x-text="p.works_count + ' اثر'"></span>
                                        <span class="px-2 py-0.5 rounded-lg bg-amber-500/10 text-[10px] font-mono font-bold text-amber-700 dark:text-amber-300 mr-1" 
                                              x-text="p.manuscripts_count + ' نسخه'"></span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Selected Source Identity Card -->
                    <template x-if="source">
                        <div class="space-y-3 p-4 rounded-xl bg-white dark:bg-stone-900/80 border border-rose-300 dark:border-rose-900/50 shadow-xs">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="font-bold text-sm text-stone-900 dark:text-stone-100" x-text="source.name"></h3>
                                    <span class="text-xs font-mono font-bold text-rose-600 dark:text-rose-400" x-text="'شناسه پایگاه: #' + source.id"></span>
                                </div>
                                <a :href="source.url" target="_blank" class="text-[11px] text-[#B38A50] hover:underline font-bold flex items-center gap-1">
                                    <span>مشاهده در سایت</span>
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                            </div>

                            <div class="grid grid-cols-2 gap-2 text-xs pt-2 border-t border-stone-100 dark:border-stone-800">
                                <div class="p-2 rounded-lg bg-stone-50 dark:bg-stone-800/50">
                                    <span class="text-stone-400 text-[10px] block">تألیفات ثبت‌شده:</span>
                                    <span class="font-mono font-bold text-[#B38A50] text-sm" x-text="source.works_count"></span>
                                    <span class="text-stone-500 text-[10px]"> اثر</span>
                                </div>
                                <div class="p-2 rounded-lg bg-stone-50 dark:bg-stone-800/50">
                                    <span class="text-stone-400 text-[10px] block">کتابت‌ها (نسخ خطی):</span>
                                    <span class="font-mono font-bold text-amber-600 text-sm" x-text="source.manuscripts_count"></span>
                                    <span class="text-stone-500 text-[10px]"> نسخه</span>
                                </div>
                            </div>

                            <div class="text-[11px] text-stone-500 dark:text-stone-400 flex items-center gap-2">
                                <span x-show="source.death_year_hijri" x-text="'سال وفات: ' + source.death_year_hijri + ' هـ.ق'"></span>
                                <span x-show="!source.death_year_hijri && source.century_hijri" x-text="'قرن: ' + source.century_hijri + ' هـ.ق'"></span>
                                <span x-show="source.is_author" class="px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-700 dark:text-blue-300 font-bold text-[10px]">مؤلف</span>
                                <span x-show="source.is_scribe" class="px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-700 dark:text-amber-300 font-bold text-[10px]">کاتب</span>
                            </div>
                        </div>
                    </template>

                </div>

                <!-- 2. TARGET AUTHORITY (To be retained and enriched) -->
                <div class="rounded-2xl border-2 transition-all p-5 space-y-4"
                     :class="target ? 'border-emerald-400/50 bg-emerald-50/20 dark:bg-emerald-950/10' : 'border-dashed border-stone-200 dark:border-stone-800 bg-stone-50/50 dark:bg-stone-900/30'">
                    
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            ۲. شخص مقصد (اصلی / حفظ‌شونده)
                        </span>
                        <template x-if="target">
                            <button type="button" @click="clearTarget()" class="text-xs text-emerald-600 hover:text-emerald-800 dark:text-emerald-400 font-bold hover:underline cursor-pointer flex items-center gap-1">
                                <span>تغییر / حذف انتخاب ✕</span>
                            </button>
                        </template>
                    </div>

                    <!-- Search Input (When no target is selected) -->
                    <div x-show="!target" class="relative">
                        <label class="block text-xs font-bold text-stone-600 dark:text-stone-300 mb-1.5">
                            جستجو بر اساس نام، شهرت، کنیه یا کد عددی:
                        </label>
                        <div class="relative">
                            <input type="text" 
                                   x-model="targetQuery"
                                   @input.debounce.250ms="searchTarget()"
                                   @focus="if(targetQuery.trim().length >= 2) showTargetDropdown = true"
                                   placeholder="مثلاً: فیض کاشانی یا 14 ..."
                                   class="w-full pr-10 pl-10 py-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 text-xs font-semibold focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 outline-none transition">
                            
                            <!-- Search Icon -->
                            <div class="absolute right-3.5 top-3.5 text-stone-400">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>

                            <!-- Loading Spinner -->
                            <div x-show="loadingTarget" class="absolute left-3.5 top-3.5 text-emerald-500">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            </div>
                        </div>

                        <!-- Autocomplete Results Dropdown -->
                        <div x-show="showTargetDropdown && targetResults.length > 0" 
                             @click.outside="showTargetDropdown = false"
                             class="absolute z-30 w-full mt-2 bg-white dark:bg-stone-900 rounded-2xl shadow-xl border border-stone-200 dark:border-stone-700 max-h-72 overflow-y-auto divide-y divide-stone-100 dark:divide-stone-800">
                            <template x-for="p in targetResults" :key="p.id">
                                <button type="button" @click="selectTarget(p)"
                                        class="w-full p-3 text-right hover:bg-emerald-50 dark:hover:bg-emerald-950/30 transition flex items-center justify-between gap-3 cursor-pointer">
                                    <div class="space-y-1">
                                        <div class="font-bold text-xs text-stone-900 dark:text-stone-100" x-text="p.name"></div>
                                        <div class="text-[11px] text-stone-500 dark:text-stone-400 flex items-center gap-2">
                                            <span class="font-mono text-stone-400" x-text="'#' + p.id"></span>
                                            <span x-show="p.death_year_hijri" x-text="'وفات ' + p.death_year_hijri + ' ق'"></span>
                                            <span x-show="!p.death_year_hijri && p.century_hijri" x-text="'قرن ' + p.century_hijri + ' ق'"></span>
                                        </div>
                                    </div>
                                    <div class="text-left shrink-0">
                                        <span class="px-2 py-0.5 rounded-lg bg-stone-100 dark:bg-stone-800 text-[10px] font-mono font-bold text-[#B38A50]" 
                                              x-text="p.works_count + ' اثر'"></span>
                                        <span class="px-2 py-0.5 rounded-lg bg-amber-500/10 text-[10px] font-mono font-bold text-amber-700 dark:text-amber-300 mr-1" 
                                              x-text="p.manuscripts_count + ' نسخه'"></span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Selected Target Identity Card -->
                    <template x-if="target">
                        <div class="space-y-3 p-4 rounded-xl bg-white dark:bg-stone-900/80 border border-emerald-300 dark:border-emerald-900/50 shadow-xs">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="font-bold text-sm text-stone-900 dark:text-stone-100" x-text="target.name"></h3>
                                    <span class="text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400" x-text="'شناسه پایگاه: #' + target.id"></span>
                                </div>
                                <a :href="target.url" target="_blank" class="text-[11px] text-[#B38A50] hover:underline font-bold flex items-center gap-1">
                                    <span>مشاهده در سایت</span>
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                            </div>

                            <div class="grid grid-cols-2 gap-2 text-xs pt-2 border-t border-stone-100 dark:border-stone-800">
                                <div class="p-2 rounded-lg bg-stone-50 dark:bg-stone-800/50">
                                    <span class="text-stone-400 text-[10px] block">تألیفات ثبت‌شده:</span>
                                    <span class="font-mono font-bold text-[#B38A50] text-sm" x-text="target.works_count"></span>
                                    <span class="text-stone-500 text-[10px]"> اثر</span>
                                </div>
                                <div class="p-2 rounded-lg bg-stone-50 dark:bg-stone-800/50">
                                    <span class="text-stone-400 text-[10px] block">کتابت‌ها (نسخ خطی):</span>
                                    <span class="font-mono font-bold text-amber-600 text-sm" x-text="target.manuscripts_count"></span>
                                    <span class="text-stone-500 text-[10px]"> نسخه</span>
                                </div>
                            </div>

                            <div class="text-[11px] text-stone-500 dark:text-stone-400 flex items-center gap-2">
                                <span x-show="target.death_year_hijri" x-text="'سال وفات: ' + target.death_year_hijri + ' هـ.ق'"></span>
                                <span x-show="!target.death_year_hijri && target.century_hijri" x-text="'قرن: ' + target.century_hijri + ' هـ.ق'"></span>
                                <span x-show="target.is_author" class="px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-700 dark:text-blue-300 font-bold text-[10px]">مؤلف</span>
                                <span x-show="target.is_scribe" class="px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-700 dark:text-amber-300 font-bold text-[10px]">کاتب</span>
                            </div>
                        </div>
                    </template>

                </div>

            </div>

            <!-- Central Swap Action Button -->
            <div class="flex justify-center -my-3">
                <button type="button" @click="swap()" 
                        :disabled="!source && !target"
                        title="جابجایی مبدأ و مقصد با یک کلیک"
                        class="px-4 py-2 rounded-2xl border border-stone-200 dark:border-stone-700 bg-white dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white dark:hover:bg-[#B38A50] text-stone-700 dark:text-stone-200 font-bold text-xs shadow-md transition flex items-center gap-2 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">
                    <svg class="w-4 h-4 transition-transform group-hover:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span>تعویض جایگاه مبدأ و مقصد (Swap ⇄)</span>
                </button>
            </div>

            <!-- Error alert when source and target are identical -->
            <div x-show="source && target && source.id === target.id" x-cloak
                 class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-xs font-bold text-red-600 dark:text-red-400 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>خطا: شخص مبدأ و مقصد نمی‌توانند یکسان باشند. لطفاً دو رکورد متمایز را انتخاب کنید.</span>
            </div>

            <!-- Live Impact Preview Monitor -->
            <div x-show="source && target && source.id !== target.id" x-cloak 
                 class="p-5 rounded-2xl bg-gradient-to-r from-stone-50 to-stone-100/60 dark:from-stone-900/40 dark:to-stone-800/20 border border-stone-200 dark:border-stone-800 space-y-3">
                <div class="flex items-center gap-2 text-xs font-bold text-stone-700 dark:text-stone-300">
                    <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>پیش‌نمایش تراز ادغام و پیامدها:</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                    <div class="p-3 rounded-xl bg-white dark:bg-stone-800 border border-stone-100 dark:border-stone-700/50">
                        <span class="text-stone-400 text-[10px] block">آثار در حال انتقال:</span>
                        <span class="font-bold font-mono text-base text-[#B38A50]" x-text="source.works_count"></span>
                        <span class="text-stone-500 text-[11px]"> اثر تألیفی</span>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-stone-800 border border-stone-100 dark:border-stone-700/50">
                        <span class="text-stone-400 text-[10px] block">نسخه‌های در حال انتقال:</span>
                        <span class="font-bold font-mono text-base text-amber-600" x-text="source.manuscripts_count"></span>
                        <span class="text-stone-500 text-[11px]"> نسخه خطی</span>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-stone-800 border border-stone-100 dark:border-stone-700/50">
                        <span class="text-stone-400 text-[10px] block">مجموع آثار مقصد پس از ادغام:</span>
                        <span class="font-bold font-mono text-base text-emerald-600" x-text="Number(target.works_count) + Number(source.works_count)"></span>
                        <span class="text-stone-500 text-[11px]"> اثر تألیفی</span>
                    </div>
                </div>
                <p class="text-[11px] text-stone-500 dark:text-stone-400 leading-relaxed">
                    پس از تایید، رکورد <strong class="text-rose-600 dark:text-rose-400" x-text="source.name"></strong> به شناسه #<span x-text="source.id"></span> برای همیشه حذف شده و به <strong class="text-emerald-600 dark:text-emerald-400" x-text="target.name"></strong> الحاق می‌گردد.
                </p>
            </div>

            <!-- Submit & Action Row -->
            <div class="pt-4 flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-stone-100 dark:border-stone-800">
                <a href="{{ route('admin.people.index') }}" class="px-5 py-2.5 text-stone-500 hover:text-stone-800 dark:hover:text-stone-200 font-bold text-xs transition">
                    انصراف و بازگشت
                </a>

                <button type="submit" 
                        :disabled="!canSubmit"
                        @click="return confirm('هشدار قطعی ادغام:\nآیا از ادغام [' + source.name + '] در [' + target.name + '] اطمینان کامل دارید؟\nتمام آثار و نسخه‌ها منتقل و شخص مبدأ حذف خواهد شد.')"
                        class="w-full sm:w-auto px-8 py-3.5 rounded-2xl font-bold text-xs shadow-md transition flex items-center justify-center gap-2 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                        :class="canSubmit ? 'bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-700 hover:to-amber-800 text-white shadow-amber-600/20' : 'bg-stone-200 dark:bg-stone-800 text-stone-400'">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span x-text="canSubmit ? ('اجرای قطعی ادغام [' + source.name + '] در [' + target.name + ']') : 'لطفاً هر دو شخص مبدأ و مقصد را انتخاب کنید'"></span>
                </button>
            </div>

        </form>

    </div>

</div>

<script>
function authorityMerger(config) {
    return {
        source: config.preloadedSource || null,
        target: config.preloadedTarget || null,
        searchUrl: config.searchUrl,

        sourceQuery: '',
        targetQuery: '',
        sourceResults: [],
        targetResults: [],
        loadingSource: false,
        loadingTarget: false,
        showSourceDropdown: false,
        showTargetDropdown: false,

        get canSubmit() {
            return this.source && this.target && this.source.id !== this.target.id;
        },

        async searchSource() {
            const q = this.sourceQuery.trim();
            if (q.length < 1) {
                this.sourceResults = [];
                this.showSourceDropdown = false;
                return;
            }
            this.loadingSource = true;
            try {
                const res = await fetch(`${this.searchUrl}?q=${encodeURIComponent(q)}`);
                const data = await res.json();
                this.sourceResults = data;
                this.showSourceDropdown = data.length > 0;
            } catch (e) {
                console.error(e);
            } finally {
                this.loadingSource = false;
            }
        },

        async searchTarget() {
            const q = this.targetQuery.trim();
            if (q.length < 1) {
                this.targetResults = [];
                this.showTargetDropdown = false;
                return;
            }
            this.loadingTarget = true;
            try {
                const res = await fetch(`${this.searchUrl}?q=${encodeURIComponent(q)}`);
                const data = await res.json();
                this.targetResults = data;
                this.showTargetDropdown = data.length > 0;
            } catch (e) {
                console.error(e);
            } finally {
                this.loadingTarget = false;
            }
        },

        selectSource(person) {
            this.source = person;
            this.sourceQuery = '';
            this.sourceResults = [];
            this.showSourceDropdown = false;
        },

        selectTarget(person) {
            this.target = person;
            this.targetQuery = '';
            this.targetResults = [];
            this.showTargetDropdown = false;
        },

        clearSource() {
            this.source = null;
            this.sourceQuery = '';
            this.sourceResults = [];
        },

        clearTarget() {
            this.target = null;
            this.targetQuery = '';
            this.targetResults = [];
        },

        swap() {
            const temp = this.source;
            this.source = this.target;
            this.target = temp;
        }
    };
}
</script>
@endsection
