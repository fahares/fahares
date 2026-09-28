@extends('layouts.app')

@section('title', 'نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' | ' . ($manuscript->libraryRecord?->name ?? $manuscript->library) . ' (' . ($manuscript->shelfmark ?? 'بی‌شماره') . ')')
@section('meta_description', 'شناسنامه کالبدشناسی نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' در ' . ($manuscript->libraryRecord?->name ?? $manuscript->library) . ' با شماره بازیابی ' . ($manuscript->shelfmark ?? ''))

@section('content')
<div x-data="{ suggestionModalOpen: false, selectedField: 'کاتب' }" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-stone-500">
        <a href="{{ route('home') }}" class="hover:text-[#B38A50]">فهارس</a>
        <span>/</span>
        <a href="{{ route('search', ['type' => 'manuscripts']) }}" class="hover:text-[#B38A50]">نسخه‌های خطی</a>
        <span>/</span>
        @if($manuscript->work)
            <a href="{{ route('works.show', $manuscript->work) }}" class="hover:text-[#B38A50] truncate max-w-xs">{{ $manuscript->work->primary_title }}</a>
            <span>/</span>
        @endif
        <span class="text-stone-800 dark:text-stone-200 font-semibold">{{ $manuscript->shelfmark ?? 'شناسنامه نسخه' }}</span>
    </nav>

    <!-- MANUSCRIPT HEADER IDENTIFICATION -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-md space-y-6 relative overflow-hidden">
        
        <div class="flex flex-col md:flex-row md:items-start justify-between gap-6">
            <div class="space-y-3 flex-1">
                
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold">
                        شناسنامه کالبدشناسی نسخه خطی
                    </span>

                    @if($manuscript->is_autograph)
                        <span class="px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-200 text-xs font-bold flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                            <span>اصل نسخه (دستخط مؤلف)</span>
                        </span>
                    @endif
                </div>

                @if($manuscript->work)
                    <div class="space-y-1">
                        <span class="text-xs text-stone-400 font-medium">عنوان اثر:</span>
                        <h1 class="text-2xl sm:text-3xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                            <a href="{{ route('works.show', $manuscript->work) }}" class="hover:text-[#B38A50] transition">
                                {{ $manuscript->work->primary_title }}
                            </a>
                        </h1>
                        @if($manuscript->work->author)
                            <div class="text-xs text-stone-600 dark:text-stone-300">
                                پدیدآور اثر: <a href="{{ route('people.show', $manuscript->work->author) }}" class="font-bold text-[#B38A50] hover:underline">{{ $manuscript->work->author->name }}</a>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Suggestion / Feedback Action Button -->
            <div>
                <button 
                    @click="suggestionModalOpen = true" 
                    type="button" 
                    class="px-4 py-2.5 rounded-xl border border-[#B38A50] text-[#B38A50] hover:bg-[#B38A50] hover:text-white dark:hover:text-stone-900 font-semibold text-xs transition duration-200 flex items-center gap-2 shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    <span>پیشنهاد تصحیح / تکمیل این نسخه</span>
                </button>
            </div>
        </div>

        <!-- Shelfmark & Location Bar -->
        <div class="p-5 rounded-2xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">کتابخانه و مخزن نگهداری:</span>
                @if($manuscript->libraryRecord || $manuscript->library_id)
                    <a href="{{ route('libraries.show', $manuscript->libraryRecord ?? $manuscript->library_id) }}" class="text-sm font-bold text-[#292C56] dark:text-amber-200 hover:text-[#B38A50] transition">
                        {{ $manuscript->libraryRecord?->name ?? $manuscript->library }}
                    </a>
                @else
                    <span class="text-sm font-bold text-stone-800 dark:text-stone-200">{{ $manuscript->library ?? 'نامشخص' }}</span>
                @endif
            </div>

            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">شهر و کشور:</span>
                <span class="text-sm font-bold text-stone-800 dark:text-stone-200">{{ $manuscript->city ?? 'ایران' }}</span>
            </div>

            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">شماره نسخه:</span>
                <span class="text-base font-black text-[#B38A50] tracking-wide">
                    {{ $manuscript->shelfmark ?? 'بی‌شماره' }}
                </span>
            </div>
        </div>

    </div>

    <!-- CODICOLOGICAL FLAGS (10 Indicators) -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
        <h3 class="text-xs font-bold text-stone-400 uppercase tracking-wider">نشان‌های نسخه‌شناسی و کالبدشناسی</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 text-xs">
            
            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_autograph ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>اصل نسخه (دستخط مؤلف)</span>
                <span>{{ $manuscript->is_autograph ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->has_author_marginalia ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>حواشی به خط مؤلف</span>
                <span>{{ $manuscript->has_author_marginalia ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_illuminated ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-800 text-[#B38A50] font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>تذهیب و سرلوح</span>
                <span>{{ $manuscript->is_illuminated ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_illustrated ? 'bg-purple-50 dark:bg-purple-950/40 border-purple-300 dark:border-purple-800 text-purple-800 dark:text-purple-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>نگاره و نقاشی</span>
                <span>{{ $manuscript->is_illustrated ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_corrected ? 'bg-blue-50 dark:bg-blue-950/40 border-blue-300 dark:border-blue-800 text-blue-800 dark:text-blue-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>تصحیح‌شده (مصحح)</span>
                <span>{{ $manuscript->is_corrected ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->has_marginal_notes ? 'bg-stone-100 dark:bg-stone-800 border-stone-300 dark:border-stone-700 text-stone-800 dark:text-stone-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>دارای حواشی (محشی)</span>
                <span>{{ $manuscript->has_marginal_notes ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_ruled ? 'bg-stone-100 dark:bg-stone-800 border-stone-300 dark:border-stone-700 text-stone-800 dark:text-stone-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>سطور مجدول (جدول‌بندی)</span>
                <span>{{ $manuscript->is_ruled ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->has_catchwords ? 'bg-stone-100 dark:bg-stone-800 border-stone-300 dark:border-stone-700 text-stone-800 dark:text-stone-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>دارای رکابه (پای‌صفحه)</span>
                <span>{{ $manuscript->has_catchwords ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_collated ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>مقابله و بلاغ</span>
                <span>{{ $manuscript->is_collated ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_facsimile ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-800 text-amber-800 dark:text-amber-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>چاپ عکسی / فاکسیمیله</span>
                <span>{{ $manuscript->is_facsimile ? '✓' : '—' }}</span>
            </div>

        </div>
    </div>

    <!-- PHYSICAL & HISTORICAL DETAILS -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- Scribe & Date Card -->
        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
            <h3 class="text-sm font-bold text-[#292C56] dark:text-amber-200 border-r-2 border-[#B38A50] pr-2">
                مشخصات کاتب و تاریخ کتابت
            </h3>
            
            <dl class="space-y-3 text-xs divide-y divide-stone-100 dark:divide-stone-800">
                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">نام کاتب:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        @if($manuscript->is_autograph)
                            @if($manuscript->work?->author)
                                <a href="{{ route('people.show', $manuscript->work->author) }}" class="text-emerald-700 dark:text-emerald-400 hover:underline font-bold inline-flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                    </svg>
                                    <span>دستخط مؤلف ({{ $manuscript->work->author->name }})</span>
                                </a>
                            @elseif($manuscript->scribe)
                                <a href="{{ route('people.show', $manuscript->scribe) }}" class="text-emerald-700 dark:text-emerald-400 hover:underline font-bold inline-flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                    </svg>
                                    <span>دستخط مؤلف ({{ $manuscript->scribe->name }})</span>
                                </a>
                            @else
                                <span class="text-emerald-700 dark:text-emerald-400 font-bold">مؤلف (نسخه اصل)</span>
                            @endif
                        @elseif($manuscript->scribe_id)
                            <a href="{{ route('people.show', $manuscript->scribe_id) }}" class="text-[#B38A50] hover:underline">{{ $manuscript->scribe_name ?: 'مشاهده کاتب' }}</a>
                        @elseif($manuscript->scribe_name)
                            {{ $manuscript->scribe_name }}
                        @elseif($manuscript->is_bika)
                            <span class="text-stone-400 italic">بی‌کاتب</span>
                        @else
                            <span class="text-stone-400">نامشخص</span>
                        @endif
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">تاریخ کتابت:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        @if($manuscript->copy_date_raw)
                            <span>{{ $manuscript->copy_date_raw }}</span>
                            @if($manuscript->copy_date_hijri_year)
                                <span class="text-stone-400 mr-1">({{ $manuscript->copy_date_hijri_year }} هـ.ق)</span>
                            @endif
                        @elseif($manuscript->is_bita)
                            <span class="text-stone-400 italic">بی‌تاریخ</span>
                        @else
                            <span class="text-stone-400">نامشخص</span>
                        @endif
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">محل کتابت:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->copy_place ?? 'نامشخص' }}
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">ردیف ثبت در مأخذ:</dt>
                    <dd class="font-bold text-stone-700 dark:text-stone-300">
                        ردیف {{ $manuscript->sequence_number }} (در {{ $manuscript->catalog?->short_name ?? 'فنخا' }})
                    </dd>
                </div>
            </dl>
        </div>

        <!-- Codicology & Material Card -->
        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
            <h3 class="text-sm font-bold text-[#292C56] dark:text-amber-200 border-r-2 border-[#B38A50] pr-2">
                ویژگی‌های مادی و ظاهری نسخه
            </h3>
            
            <dl class="space-y-3 text-xs divide-y divide-stone-100 dark:divide-stone-800">
                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">نوع و اقلام خط:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->script_names ?? 'نامشخص' }}
                        @if($manuscript->script_style)
                            <span class="text-stone-400 mr-1">({{ $manuscript->script_style }})</span>
                        @endif
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">تعداد برگ و سطر:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->folios ? $manuscript->folios . ' برگ' : '-' }} • {{ $manuscript->lines ? $manuscript->lines . ' سطر' : '-' }}
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">ابعاد و اندازه (سانتی‌متر):</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->dimensions ?? '-' }}
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">کاغذ و جلد:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->paper ?? 'کاغذ نامشخص' }} • {{ $manuscript->binding ?? 'جلد نامشخص' }}
                    </dd>
                </div>
            </dl>
        </div>

    </div>

    <!-- INCIPIT & EXPLICIT (آغاز و انجام نسخه) -->
    @if(count($manuscript->incipits_list) > 0 || count($manuscript->explicits_list) > 0)
        <div class="space-y-4">
            
            @if(count($manuscript->incipits_list) > 0)
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-stone-100 dark:border-stone-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-4 bg-[#B38A50] rounded-sm"></span>
                            <h3 class="text-xs font-bold text-[#B38A50] uppercase tracking-wider">
                                آغاز نسخه
                            </h3>
                        </div>
                        @if(count($manuscript->incipits_list) > 1)
                            <span class="text-[11px] text-stone-500 font-medium bg-amber-50 dark:bg-amber-950/40 px-2.5 py-0.5 rounded-full border border-amber-200 dark:border-amber-900/40">
                                {{ count($manuscript->incipits_list) }} آغاز ثبت‌شده
                            </span>
                        @endif
                    </div>

                    <div class="space-y-3">
                        @foreach($manuscript->incipits_list as $idx => $inc)
                            <div class="p-4 rounded-2xl bg-amber-50/40 dark:bg-amber-950/20 border-r-4 border-[#B38A50] space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if(!empty($inc['is_work_match']))
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#B38A50]/20 text-[#B38A50] dark:bg-amber-900/40 dark:text-amber-300 border border-amber-300/60 dark:border-amber-700/60">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                            </svg>
                                            <span>{{ $inc['label'] }}</span>
                                        </span>
                                    @elseif(!empty($inc['label']))
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#B38A50]/15 text-[#B38A50]">
                                            {{ $inc['label'] }}
                                        </span>
                                    @elseif(count($manuscript->incipits_list) > 1)
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#B38A50]/15 text-[#B38A50]">
                                            آغاز {{ $idx + 1 }}
                                        </span>
                                    @endif
                                </div>
                                @if(!empty($inc['is_placeholder']))
                                    <div class="text-stone-600 dark:text-stone-400 text-xs italic leading-relaxed">
                                        {{ $inc['text'] }}
                                    </div>
                                @else
                                    <div class="manuscript-quote text-stone-800 dark:text-stone-100 text-sm leading-relaxed font-medium">
                                        « {{ trim($inc['text'], "«» \t\n\r\0\x0B") }} »
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if(count($manuscript->explicits_list) > 0)
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-stone-100 dark:border-stone-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-4 bg-[#292C56] dark:bg-indigo-400 rounded-sm"></span>
                            <h3 class="text-xs font-bold text-[#292C56] dark:text-indigo-400 uppercase tracking-wider">
                                انجام نسخه
                            </h3>
                        </div>
                        @if(count($manuscript->explicits_list) > 1)
                            <span class="text-[11px] text-stone-500 font-medium bg-indigo-50 dark:bg-indigo-950/40 px-2.5 py-0.5 rounded-full border border-indigo-200 dark:border-indigo-900/40">
                                {{ count($manuscript->explicits_list) }} انجام ثبت‌شده
                            </span>
                        @endif
                    </div>

                    <div class="space-y-3">
                        @foreach($manuscript->explicits_list as $idx => $exp)
                            <div class="p-4 rounded-2xl bg-indigo-50/40 dark:bg-indigo-950/20 border-r-4 border-[#292C56] dark:border-indigo-400 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if(!empty($exp['is_work_match']))
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#292C56]/15 dark:bg-indigo-400/20 text-[#292C56] dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                            </svg>
                                            <span>{{ $exp['label'] }}</span>
                                        </span>
                                    @elseif(!empty($exp['label']))
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#292C56]/15 dark:bg-indigo-400/20 text-[#292C56] dark:text-indigo-300">
                                            {{ $exp['label'] }}
                                        </span>
                                    @elseif(count($manuscript->explicits_list) > 1)
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#292C56]/15 dark:bg-indigo-400/20 text-[#292C56] dark:text-indigo-300">
                                            انجام {{ $idx + 1 }}
                                        </span>
                                    @endif
                                </div>
                                @if(!empty($exp['is_placeholder']))
                                    <div class="text-stone-600 dark:text-stone-400 text-xs italic leading-relaxed">
                                        {{ $exp['text'] }}
                                    </div>
                                @else
                                    <div class="manuscript-quote text-stone-800 dark:text-stone-100 text-sm leading-relaxed font-medium">
                                        « {{ trim($exp['text'], "«» \t\n\r\0\x0B") }} »
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    @endif

    <!-- OWNERSHIP, SEALS, WAQF & MANUSCRIPT NOTES -->
    @if(count($manuscript->ownership_and_seals_list) > 0 || $manuscript->residual_notes_text || count($manuscript->editorial_notes_list) > 0)
        <div class="space-y-4">
            
            @if(count($manuscript->ownership_and_seals_list) > 0)
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
                    <div class="flex items-center gap-2 pb-2 border-b border-stone-100 dark:border-stone-800">
                        <span class="w-2 h-4 bg-amber-600 rounded-sm"></span>
                        <h3 class="text-xs font-bold text-amber-800 dark:text-amber-300 uppercase tracking-wider">
                            مهرها، یادداشت‌های تملک و وقفیات نسخه
                        </h3>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($manuscript->ownership_and_seals_list as $seal)
                            @php
                                $isSeal = str_contains($seal, 'مهر');
                                $isWaqf = str_contains($seal, 'وقف') || str_contains($seal, 'واقف');
                            @endphp
                            <div class="p-3.5 rounded-2xl border flex items-start gap-3 {{ $isSeal ? 'bg-amber-50/60 dark:bg-amber-950/20 border-amber-200 dark:border-amber-900/60' : ($isWaqf ? 'bg-emerald-50/60 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-900/60' : 'bg-stone-50/80 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800') }}">
                                <div class="shrink-0 mt-0.5">
                                    @if($isSeal)
                                        <div class="w-7 h-7 rounded-xl bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-200 flex items-center justify-center" title="نشان مهر نسخه">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                            </svg>
                                        </div>
                                    @elseif($isWaqf)
                                        <div class="w-7 h-7 rounded-xl bg-emerald-100 dark:bg-emerald-900/60 text-emerald-800 dark:text-emerald-200 flex items-center justify-center" title="یادداشت وقف">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                            </svg>
                                        </div>
                                    @else
                                        <div class="w-7 h-7 rounded-xl bg-stone-200 dark:bg-stone-700 text-stone-700 dark:text-stone-300 flex items-center justify-center" title="تملک و سرگذشت نسخه">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                            </svg>
                                        </div>
                                    @endif
                                </div>
                                <div class="text-xs text-stone-800 dark:text-stone-200 font-medium leading-relaxed">
                                    {{ $seal }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($manuscript->residual_notes_text || count($manuscript->editorial_notes_list) > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    @if($manuscript->residual_notes_text)
                        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                            <span class="text-xs font-bold text-stone-500 uppercase tracking-wider block">فوائد و رسائل مندرج در نسخه:</span>
                            <div class="text-stone-700 dark:text-stone-300 text-xs leading-relaxed bg-stone-50 dark:bg-stone-800/40 p-3.5 rounded-2xl border border-stone-200 dark:border-stone-800">
                                {{ $manuscript->residual_notes_text }}
                            </div>
                        </div>
                    @endif

                    @if(count($manuscript->editorial_notes_list) > 0)
                        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                            <span class="text-xs font-bold text-stone-500 uppercase tracking-wider block">یادداشت‌های انتقادی و تصحیحی فهرست‌نگار:</span>
                            <div class="space-y-1.5">
                                @foreach($manuscript->editorial_notes_list as $editNote)
                                    <div class="text-stone-700 dark:text-stone-300 text-xs leading-relaxed bg-stone-50 dark:bg-stone-800/40 p-3 rounded-xl border border-stone-200 dark:border-stone-800 flex items-start gap-2">
                                        <span class="text-[#B38A50] mt-0.5">•</span>
                                        <span>{{ $editNote }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                </div>
            @endif

        </div>
    @endif

    <!-- CITATION IN PRINTED SOURCE CATALOG & RAW CATALOG TEXT -->
    <div class="p-6 sm:p-7 rounded-3xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] space-y-5 shadow-xs">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
            <div class="space-y-1.5 text-right">
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-md bg-amber-100 dark:bg-amber-950/60 text-[#B38A50] text-[11px] font-bold">
                        {{ $manuscript->catalog?->short_name ?? 'فنخا' }}
                    </span>
                    <span class="font-bold text-sm text-stone-800 dark:text-stone-200">مشخصات مأخذ و استناد فهرست‌نگاری:</span>
                </div>
                <p class="text-stone-600 dark:text-stone-300 text-xs leading-relaxed">
                    {{ $manuscript->catalog?->citation_format ?? ($manuscript->catalog?->name ?? 'فهرستگان نسخه‌های خطی ایران (فنخا)') }}
                </p>
            </div>
            
            <div class="text-xs font-bold text-[#B38A50] whitespace-nowrap bg-white dark:bg-[#15192C] px-5 py-2.5 rounded-2xl border border-[#EADFCF] dark:border-[#272F4C] shadow-2xs self-start sm:self-center">
                جلد {{ $manuscript->volume_number }} • صفحه {{ $manuscript->page_start }} • ردیف {{ $manuscript->sequence_number }}
            </div>
        </div>

        @if($manuscript->clean_raw_text)
            <div x-data="{ copied: false }" class="pt-4 border-t border-[#EADFCF] dark:border-[#272F4C] space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-3.5 bg-[#B38A50] rounded-xs"></span>
                        <span class="font-bold text-stone-800 dark:text-stone-200 text-xs">
                            متن خام مدخل در مأخذ چاپی (فنخا):
                        </span>
                    </div>
                    <button 
                        type="button" 
                        @click="navigator.clipboard.writeText($refs.rawTextContent.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-[#15192C] border border-[#EADFCF] dark:border-[#272F4C] text-[11px] font-semibold text-stone-600 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                        <svg x-show="!copied" class="w-3.5 h-3.5 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <svg x-show="copied" class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="display: none;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                        <span x-show="!copied">رونوشت متن مأخذ</span>
                        <span x-show="copied" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                    </button>
                </div>
                
                <div 
                    x-ref="rawTextContent" 
                    class="p-4 sm:p-5 rounded-2xl bg-white/70 dark:bg-[#15192C]/70 border border-[#EADFCF] dark:border-[#272F4C] text-xs text-stone-800 dark:text-stone-200 leading-relaxed whitespace-pre-line text-right selection:bg-amber-100 dark:selection:bg-amber-950">{{ $manuscript->clean_raw_text }}</div>
            </div>
        @endif

    </div>

    <!-- SUGGESTION MODAL DIALOG (Alpine.js) -->
    <div 
        x-show="suggestionModalOpen" 
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        
        <div 
            @click.away="suggestionModalOpen = false"
            class="bg-white dark:bg-[#15192C] w-full max-w-lg rounded-3xl p-6 sm:p-8 border border-stone-200 dark:border-stone-700 shadow-2xl space-y-6 text-right max-h-[90vh] overflow-y-auto">
            
            <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
                    <h3 class="font-bold text-lg text-stone-900 dark:text-stone-100">ثبت پیشنهاد تصحیح نسخه</h3>
                </div>
                <button @click="suggestionModalOpen = false" class="text-stone-400 hover:text-stone-600 text-lg">✕</button>
            </div>

            <p class="text-xs text-stone-500 leading-relaxed">
                پژوهشگر گرامی؛ در صورتی که در اطلاعات این نسخه (کاتب، تاریخ، آغاز/انجام، شماره نسخه یا مشخصات مادی) خطایی مشاهده نموده‌اید، لطفاً اصلاحیه خود را همراه با مستند علمی ثبت بفرمایید (همچنین می‌توانید گزارش خود را مستقیماً به ایمیل <a href="mailto:info@fahares.net" class="text-[#B38A50] hover:underline font-mono font-medium">info@fahares.net</a> ارسال فرمایید).
            </p>

            <form action="{{ route('suggestions.store') }}" method="POST" class="space-y-4 text-xs">
                @csrf
                <input type="hidden" name="suggestable_type" value="App\Models\Manuscript">
                <input type="hidden" name="suggestable_id" value="{{ $manuscript->id }}">

                <!-- Field Selector -->
                <div class="space-y-1">
                    <label class="block font-semibold text-stone-700 dark:text-stone-300">فیلد نیازمند تصحیح:</label>
                    <select name="field_name" x-model="selectedField" class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100 font-medium">
                        <option value="scribe_name">نام کاتب</option>
                        <option value="copy_date_raw">تاریخ کتابت</option>
                        <option value="shelfmark">شماره نسخه</option>
                        <option value="incipit_text">عبارت آغاز (Incipit)</option>
                        <option value="explicit_text">عبارت انجام (Explicit)</option>
                        <option value="ownership_and_seals">مهرها، یادداشت‌های تملک و وقفیات</option>
                        <option value="script_names">نوع خط</option>
                        <option value="folios">تعداد برگ</option>
                        <option value="dimensions">ابعاد نسخه</option>
                        <option value="paper">نوع کاغذ</option>
                        <option value="binding">نوع جلد</option>
                        <option value="other">سایر موارد نسخه‌شناسی</option>
                    </select>
                </div>

                <!-- Suggested Value -->
                <div class="space-y-1">
                    <label class="block font-semibold text-stone-700 dark:text-stone-300">مقدار یا عبارت صحیح پیشنهادی:</label>
                    <textarea name="suggested_value" rows="2" required placeholder="عبارت صحیح را اینجا وارد نمایید..." class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100 focus:border-[#B38A50]"></textarea>
                </div>

                <!-- Rationale & Citation -->
                <div class="space-y-1">
                    <label class="block font-semibold text-stone-700 dark:text-stone-300">مستند ادعا و منبع ارجاع (الزامی):</label>
                    <textarea name="rationale_citation" rows="3" required placeholder="مثال: ترقیمه صفحه آخر نسخه، یا فهرست کتابخانه ملی جلد ۴ صفحه ۱۲..." class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100 focus:border-[#B38A50]"></textarea>
                </div>

                <!-- Researcher Info -->
                <div class="grid grid-cols-2 gap-3 pt-2">
                    <div class="space-y-1">
                        <label class="block font-semibold text-stone-700 dark:text-stone-300">نام شما (اختیاری):</label>
                        <input type="text" name="guest_name" placeholder="دکتر..." class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                    </div>
                    <div class="space-y-1">
                        <label class="block font-semibold text-stone-700 dark:text-stone-300">ایمیل تماس (اختیاری):</label>
                        <input type="email" name="guest_email" placeholder="email@example.com" class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                    </div>
                </div>

                <!-- Actions -->
                <div class="pt-4 flex items-center justify-end gap-3 border-t border-stone-100 dark:border-stone-800">
                    <button type="button" @click="suggestionModalOpen = false" class="px-4 py-2 rounded-xl text-stone-500 hover:bg-stone-100 dark:hover:bg-stone-800 font-semibold">
                        انصراف
                    </button>
                    <button type="submit" class="px-5 py-2.5 bg-[#B38A50] hover:bg-[#9C753F] text-white rounded-xl font-bold shadow-md transition">
                        ارسال پیشنهاد به هیئت علمی
                    </button>
                </div>

            </form>

        </div>
    </div>

</div>
@endsection
