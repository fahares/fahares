@extends('layouts.app')

@section('title', $work->primary_title . ' | شناسنامه اثر در فهارس')
@section('meta_description', 'مشخصات کتاب‌شناختی و نسخه‌های خطی ' . $work->primary_title . ' در فهارس نسخه‌های خطی')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-stone-500">
        <a href="{{ route('home') }}" class="hover:text-[#B38A50]">فهارس</a>
        <span>/</span>
        <a href="{{ route('search', ['type' => 'works']) }}" class="hover:text-[#B38A50]">آثار</a>
        <span>/</span>
        <span class="text-stone-800 dark:text-stone-200 font-semibold truncate">{{ $work->primary_title }}</span>
    </nav>

    <!-- WORK HEADER CARD -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-md space-y-6 relative overflow-hidden">
        
        <!-- Subtle decorative glow -->
        <div class="absolute -top-12 -left-12 w-48 h-48 bg-[#B38A50]/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="flex flex-col md:flex-row md:items-start justify-between gap-6">
            <div class="space-y-3 flex-1">
                <div class="inline-block px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold">
                    شناسنامه کتاب‌شناختی اثر
                </div>

                <h1 class="text-2xl sm:text-3xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                    {{ $work->primary_title }}
                </h1>

                @if($work->clean_title && $work->clean_title !== $work->primary_title)
                    <div class="text-sm text-stone-500 font-medium">
                        عنوان پیراسته: <span class="text-stone-700 dark:text-stone-300">{{ $work->clean_title }}</span>
                    </div>
                @endif

                @if($work->transliteration)
                    <div class="text-xs text-stone-400">
                        {{ $work->transliteration }}
                    </div>
                @endif
            </div>

            <!-- Manuscript Count Badge -->
            <div class="flex flex-col items-start md:items-end gap-2">
                <div class="px-5 py-3 rounded-2xl bg-gradient-to-br from-amber-50 to-[#FEF9F3] dark:from-stone-800 dark:to-[#15192C] border border-[#B38A50]/40 text-center shadow-sm">
                    <span class="block text-2xl sm:text-3xl font-black text-[#B38A50]">
                        {{ number_format($work->manuscripts_count) }}
                    </span>
                    <span class="text-xs font-semibold text-stone-600 dark:text-stone-400">نسخه ثبت‌شده</span>
                    @if(!empty($work->copy_century_text))
                        <span class="block text-[11px] text-stone-500 dark:text-stone-400 font-medium mt-1">
                            {{ $work->copy_century_text }}
                        </span>
                    @endif
                </div>

                @if($work->has_autograph)
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-xs font-bold shadow-xs">
                        <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                        </svg>
                        <span>دارای نسخه اصل (دستخط مؤلف)</span>
                    </div>
                @endif

                <a href="#manuscripts" class="text-xs text-[#B38A50] hover:underline font-semibold flex items-center gap-1">
                    <span>مشاهده فهرست نسخه‌ها</span>
                    <span>↓</span>
                </a>
            </div>
        </div>

        <!-- Metadata Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 pt-6 border-t border-stone-100 dark:border-stone-800 text-xs">
            
            <!-- Author -->
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">پدیدآور / مؤلف:</span>
                @if($work->author)
                    <a href="{{ route('people.show', $work->author) }}" class="text-sm font-bold text-[#292C56] dark:text-amber-200 hover:text-[#B38A50] transition">
                        {{ $work->author->name }}
                    </a>
                @elseif($work->author_name)
                    <span class="text-sm font-bold text-stone-700 dark:text-stone-300">{{ $work->author_name }}</span>
                @else
                    <span class="text-sm text-stone-400 italic">ناشناخته</span>
                @endif
            </div>

            <!-- Composition Year -->
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">تاریخ و سده تألیف:</span>
                @if($work->composition_year_hijri)
                    <span class="text-sm font-bold text-stone-700 dark:text-stone-300">{{ $work->composition_year_hijri }} هـ.ق</span>
                @elseif($work->composition_date_raw)
                    <span class="text-sm font-bold text-stone-700 dark:text-stone-300">{{ $work->composition_date_raw }}</span>
                @else
                    <span class="text-sm text-stone-400 italic">نامشخص</span>
                @endif
            </div>

            <!-- Source in Catalog -->
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">مأخذ فهرست‌نویسی:</span>
                <span class="text-sm font-bold text-stone-700 dark:text-stone-300">
                    {{ $work->catalog?->short_name ?? 'فنخا' }}، ج {{ $work->volume_number }}، ص {{ $work->page_start }}
                    @if($work->page_end && $work->page_end > $work->page_start)
                        تا {{ $work->page_end }}
                    @endif
                </span>
            </div>

            <!-- Language & Form -->
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">زبان و قالب:</span>
                <span class="text-sm font-bold text-stone-700 dark:text-stone-300">
                    {{ $work->language_summary ?? 'عربی / فارسی' }}
                    @if($work->work_form)
                        <span class="text-xs text-stone-400 font-normal">({{ $work->work_form }})</span>
                    @endif
                </span>
            </div>

        </div>

        <!-- Subjects -->
        @if($work->subjects && $work->subjects->count() > 0)
            <div class="pt-4 border-t border-stone-100 dark:border-stone-800 flex flex-wrap items-center gap-2 text-xs">
                <span class="text-stone-400 font-medium">رده‌های موضوعی:</span>
                @foreach($work->subjects as $subj)
                    <a href="{{ route('subjects.show', $subj) }}" 
                       class="px-3 py-1 rounded-full bg-stone-100 dark:bg-stone-800 hover:bg-amber-100 dark:hover:bg-amber-950/40 text-stone-700 dark:text-stone-300 hover:text-[#B38A50] transition">
                        {{ $subj->title }}
                    </a>
                @endforeach
            </div>
        @endif

        <!-- Alternative Titles & Referrals -->
        @if(!empty($work->alternative_titles))
            <div class="pt-4 border-t border-stone-100 dark:border-stone-800 text-xs space-y-1">
                <span class="text-stone-400 font-medium">عناوین دیگر و هم‌تراز:</span>
                <div class="flex flex-wrap gap-2 pt-1">
                    @foreach($work->alternative_titles as $alt)
                        @if(is_array($alt) && !empty($alt['title']))
                            <span class="px-2.5 py-1 rounded-lg bg-stone-50 dark:bg-stone-800/80 border border-stone-200 dark:border-stone-700 text-stone-700 dark:text-stone-300">
                                {{ $alt['title'] }}
                                @if(!empty($alt['transliteration']))
                                    <span class="text-[10px] text-stone-400">({{ $alt['transliteration'] }})</span>
                                @endif
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Dedication, Place, Related Work -->
        @if(!empty($work->dedication) || !empty($work->composition_place) || !empty($work->related_work))
            <div class="pt-4 border-t border-stone-100 dark:border-stone-800 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                @if(!empty($work->dedication))
                    <div class="space-y-1">
                        <span class="text-stone-400 block font-medium">اهدا / به درخواست:</span>
                        <span class="font-bold text-stone-700 dark:text-stone-300">{{ $work->dedication }}</span>
                    </div>
                @endif
                @if(!empty($work->composition_place))
                    <div class="space-y-1">
                        <span class="text-stone-400 block font-medium">محل تألیف:</span>
                        <span class="font-bold text-stone-700 dark:text-stone-300">{{ $work->composition_place }}</span>
                    </div>
                @endif
                @if(!empty($work->related_work))
                    <div class="space-y-1">
                        <span class="text-stone-400 block font-medium">اثر مرتبط / وابسته به:</span>
                        <span class="font-bold text-stone-700 dark:text-stone-300">{{ $work->related_work }}</span>
                    </div>
                @endif
            </div>
        @endif

    </div>

    <!-- WORK DESCRIPTION & INCIPIT/EXPLICIT -->
    @if(!empty($work->description) || !empty($work->incipit_text) || !empty($work->explicit_text))
        <div class="space-y-4">
            @if(!empty($work->description))
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
                    <div class="flex items-center gap-2 pb-2 border-b border-stone-100 dark:border-stone-800">
                        <span class="w-2 h-4 bg-[#B38A50] rounded-sm"></span>
                        <h3 class="text-xs font-bold text-[#B38A50] uppercase tracking-wider">
                            معرفی و مشخصات کتاب‌شناختی اثر (در مأخذ فنخا)
                        </h3>
                    </div>
                    <div class="text-stone-700 dark:text-stone-200 text-xs sm:text-sm leading-loose text-justify font-normal">
                        {{ $work->description }}
                    </div>
                </div>
            @endif

            @if(!empty($work->incipit_text) || !empty($work->explicit_text))
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @if(!empty($work->incipit_text))
                        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                            <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                                <span class="w-2 h-3.5 bg-[#B38A50] rounded-sm"></span>
                                <span class="text-xs font-bold text-[#B38A50]">آغاز اثر</span>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-amber-50/40 dark:bg-amber-950/20 border-r-4 border-[#B38A50] text-xs leading-relaxed text-stone-800 dark:text-stone-100 font-medium">
                                « {{ trim($work->incipit_text, "«» \t\n\r\0\x0B") }} »
                            </div>
                        </div>
                    @endif

                    @if(!empty($work->explicit_text))
                        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                            <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                                <span class="w-2 h-3.5 bg-[#292C56] dark:bg-indigo-400 rounded-sm"></span>
                                <span class="text-xs font-bold text-[#292C56] dark:text-indigo-400">انجام اثر</span>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-indigo-50/40 dark:bg-indigo-950/20 border-r-4 border-[#292C56] dark:border-indigo-400 text-xs leading-relaxed text-stone-800 dark:text-stone-100 font-medium">
                                « {{ trim($work->explicit_text, "«» \t\n\r\0\x0B") }} »
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <!-- PRINT INFO, COMMENTARIES, BIBLIOGRAPHY & RAW TEXT -->
    <div class="space-y-4">
        @if(!empty($work->print_info))
            <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                    <span class="w-2 h-3.5 bg-amber-600 rounded-sm"></span>
                    <span class="text-xs font-bold text-amber-800 dark:text-amber-300">سوابق و چاپ‌های اثر</span>
                </div>
                <div class="text-xs leading-loose text-stone-700 dark:text-stone-300 font-medium">
                    {{ $work->print_info }}
                </div>
            </div>
        @endif

        @if(!empty($work->commentaries_and_glosses) && count($work->commentaries_and_glosses) > 0)
            <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
                <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                    <span class="w-2 h-3.5 bg-[#292C56] dark:bg-indigo-400 rounded-sm"></span>
                    <span class="text-xs font-bold text-[#292C56] dark:text-indigo-400">شروح، حواشی و منظومه‌ها (در فنخا)</span>
                </div>
                <div class="flex flex-wrap gap-2 pt-1 text-xs">
                    @foreach($work->commentaries_and_glosses as $comm)
                        <span class="px-3 py-1.5 rounded-xl bg-stone-50 dark:bg-stone-800/80 border border-stone-200 dark:border-stone-700 text-stone-700 dark:text-stone-300 font-medium">
                            {{ $comm }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        @if(!empty($work->bibliography) && count($work->bibliography) > 0)
            <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
                <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                    <span class="w-2 h-3.5 bg-[#B38A50] rounded-sm"></span>
                    <span class="text-xs font-bold text-[#B38A50]">مآخذ و منابع کتاب‌شناسی اثر</span>
                </div>
                <div class="flex flex-wrap gap-2 pt-1 text-xs">
                    @foreach($work->bibliography as $bib)
                        <span class="px-3 py-1.5 rounded-xl bg-amber-50/50 dark:bg-amber-950/20 border border-amber-200/60 dark:border-amber-900/40 text-stone-800 dark:text-stone-200 font-medium">
                            {{ $bib }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        @if($work->clean_raw_text)
            <div x-data="{ copied: false }" class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-4 bg-[#B38A50] rounded-sm"></span>
                        <h3 class="text-xs font-bold text-[#B38A50] uppercase tracking-wider">
                            متن خام مدخل اثر در مأخذ چاپی (فنخا)
                        </h3>
                    </div>
                    <button 
                        type="button" 
                        @click="navigator.clipboard.writeText($refs.workRawText.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)"
                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-stone-50 dark:bg-stone-800 border border-[#EADFCF] dark:border-[#272F4C] text-[11px] font-semibold text-stone-600 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
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
                    x-ref="workRawText" 
                    class="p-4 sm:p-5 rounded-2xl bg-stone-50/70 dark:bg-stone-900/50 border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed whitespace-pre-line text-right selection:bg-amber-100 dark:selection:bg-amber-950 font-normal">{{ $work->clean_raw_text }}</div>
            </div>
        @endif
    </div>

    <!-- MANUSCRIPTS TABLE SECTION -->
    <section id="manuscripts" class="space-y-4">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-6 bg-[#B38A50] rounded-sm"></span>
                <h2 class="text-xl font-bold text-stone-900 dark:text-stone-100">
                    فهرست نسخه‌های خطی این اثر
                </h2>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50]">
                    {{ number_format($work->manuscripts_count) }} نسخه
                </span>
            </div>

            @if(isset($sort) && $sort !== 'sequence')
                <div class="flex items-center gap-2 text-xs">
                    <span class="text-stone-400">مرتب‌شده بر اساس:</span>
                    <span class="font-bold text-[#B38A50]">
                        @switch($sort)
                            @case('library') کتابخانه @break
                            @case('shelfmark') شماره نسخه @break
                            @case('scribe') کاتب @break
                            @case('date') تاریخ کتابت @break
                            @case('script') نوع خط @break
                            @case('folios') تعداد برگ @break
                            @default {{ $sort }}
                        @endswitch
                        ({{ $direction === 'asc' ? 'صعودی' : 'نزولی' }})
                    </span>
                    <a href="{{ route('works.show', $work) }}#manuscripts" 
                       class="text-stone-400 hover:text-red-500 transition mr-2 underline">
                        بازنشانی ترتیب
                    </a>
                </div>
            @endif
        </div>

        <div class="bg-white dark:bg-[#15192C] rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-600 dark:text-stone-300 font-bold border-b border-stone-200 dark:border-stone-700 select-none">
                        @php
                            $renderSortHeader = function($col, $title, $align = 'right') use ($work, $sort, $direction) {
                                $isActive = ($sort === $col);
                                $nextDir = ($isActive && $direction === 'asc') ? 'desc' : 'asc';
                                $url = route('works.show', array_merge(request()->except(['page']), ['id' => $work->getRouteKey(), 'sort' => $col, 'direction' => $nextDir, 'page' => 1])) . '#manuscripts';
                                
                                $justify = $align === 'center' ? 'justify-center' : 'justify-start';
                                
                                $html = '<a href="' . e($url) . '" class="inline-flex items-center gap-1.5 hover:text-[#B38A50] transition group ' . ($isActive ? 'text-[#B38A50] font-black' : 'text-stone-600 dark:text-stone-300') . '" title="مرتب‌سازی بر اساس ' . e($title) . '">';
                                $html .= '<span>' . e($title) . '</span>';
                                
                                if ($isActive) {
                                    if ($direction === 'asc') {
                                        $html .= '<svg class="w-3.5 h-3.5 text-[#B38A50] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/></svg>';
                                    } else {
                                        $html .= '<svg class="w-3.5 h-3.5 text-[#B38A50] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>';
                                    }
                                } else {
                                    $html .= '<svg class="w-3 h-3 text-stone-300 dark:text-stone-600 opacity-60 group-hover:opacity-100 group-hover:text-[#B38A50] shrink-0 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>';
                                }
                                
                                $html .= '</a>';
                                return $html;
                            };
                        @endphp
                        <tr>
                            <th class="py-3.5 px-4 w-16 text-center">{!! $renderSortHeader('sequence', 'ردیف', 'center') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('library', 'کتابخانه و مرکز نگهداری') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('shelfmark', 'شماره نسخه') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('scribe', 'کاتب') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('date', 'تاریخ کتابت') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('script', 'نوع خط') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('folios', 'برگ') !!}</th>
                            <th class="py-3.5 px-4 text-center">مشاهده</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-stone-800/60 text-stone-700 dark:text-stone-300">
                        @forelse($manuscripts as $index => $ms)
                            <tr class="hover:bg-amber-50/40 dark:hover:bg-stone-800/40 transition">
                                <td class="py-3 px-4 text-center text-stone-400">
                                    {{ $manuscripts->firstItem() + $index }}
                                </td>
                                
                                <td class="py-3 px-4">
                                    <div class="font-bold text-stone-900 dark:text-stone-100">
                                        {{ $ms->library?->name ?? $ms->library ?? 'نامشخص' }}
                                    </div>
                                    @if($ms->city)
                                        <span class="text-[11px] text-stone-400">{{ $ms->city }}</span>
                                    @endif
                                </td>

                                <td class="py-3 px-4 font-bold text-[#B38A50]">
                                    {{ $ms->shelfmark ?? 'بی‌شماره' }}
                                </td>

                                <td class="py-3 px-4">
                                    @if($ms->scribe_name)
                                        <span class="font-medium">{{ $ms->scribe_name }}</span>
                                        @if($ms->is_autograph)
                                            <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 mr-1">(مؤلف)</span>
                                        @endif
                                    @elseif($ms->is_autograph)
                                        @if($work->author)
                                            <a href="{{ route('people.show', $work->author) }}" class="font-medium text-emerald-700 dark:text-emerald-400 hover:underline">
                                                {{ $work->author->name }} <span class="text-xs font-semibold opacity-90 mr-0.5">(مؤلف)</span>
                                            </a>
                                        @elseif($work->author_name)
                                            <span class="font-medium text-emerald-700 dark:text-emerald-400">
                                                {{ $work->author_name }} <span class="text-xs font-semibold opacity-90 mr-0.5">(مؤلف)</span>
                                            </span>
                                        @else
                                            <span class="font-medium text-emerald-700 dark:text-emerald-400">مؤلف</span>
                                        @endif
                                    @elseif($ms->is_bika)
                                        <span class="text-stone-400 italic">بی‌کاتب</span>
                                    @else
                                        <span class="text-stone-400">نامشخص</span>
                                    @endif
                                </td>

                                <td class="py-3 px-4">
                                    @if($ms->copy_date_raw)
                                        <span>{{ $ms->copy_date_raw }}</span>
                                    @elseif($ms->is_bita)
                                        <span class="text-stone-400 italic">بی‌تاریخ</span>
                                    @else
                                        <span class="text-stone-400">-</span>
                                    @endif
                                </td>

                                <td class="py-3 px-4">
                                    {{ $ms->script_names ?? '-' }}
                                </td>

                                <td class="py-3 px-4">
                                    {{ $ms->folios ? $ms->folios . ' ب' : '-' }}
                                </td>

                                <td class="py-3 px-4 text-center">
                                    <a href="{{ route('manuscripts.show', $ms) }}" 
                                       class="px-3 py-1 bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white rounded-lg text-xs font-semibold transition inline-block">
                                        شناسنامه ←
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-8 text-center text-stone-400">
                                    هیچ نسخه‌ای برای این اثر ثبت نشده است.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Table Pagination -->
            @if($manuscripts->hasPages())
                <div class="p-4 border-t border-stone-100 dark:border-stone-800 bg-stone-50/50 dark:bg-stone-800/30">
                    {{ $manuscripts->fragment('manuscripts')->links() }}
                </div>
            @endif
        </div>

    </section>

</div>
@endsection
