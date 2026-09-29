@extends('layouts.app')

@section('title', 'موضوع ' . $subject->name . ' | آثار و نسخه‌های خطی فهارس')
@section('meta_description', 'شناسنامه و فهرست کامل آثار و نسخه‌های خطی کهن در موضوع ' . $subject->name . ' مشتمل بر ' . number_format($subject->works_count) . ' اثر شناسایی‌شده.')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Breadcrumb Navigation -->
    <nav class="flex items-center gap-2 text-xs sm:text-sm text-stone-500 dark:text-stone-400">
        <a href="{{ route('home') }}" class="hover:text-[#B38A50] transition">خانه</a>
        <span>/</span>
        <a href="{{ route('subjects.index') }}" class="hover:text-[#B38A50] transition">موضوعات</a>
        @if($subject->parent)
            <span>/</span>
            <a href="{{ route('subjects.show', $subject->parent) }}" class="hover:text-[#B38A50] transition">
                {{ $subject->parent->name }}
            </a>
        @endif
        <span>/</span>
        <span class="font-bold text-[#292C56] dark:text-amber-100">{{ $subject->name }}</span>
    </nav>

    <!-- Subject Hero Header & Analytical Card -->
    <div class="relative overflow-hidden bg-white dark:bg-[#15192C] p-6 sm:p-8 rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-6">
        <div class="absolute -right-20 -top-20 w-72 h-72 bg-amber-500/5 rounded-full blur-3xl pointer-events-none"></div>

        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 relative z-10">
            <div class="space-y-3 max-w-3xl">
                
                <div class="flex flex-wrap items-center gap-2">
                    @if($subject->parent)
                        <a href="{{ route('subjects.show', $subject->parent) }}" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300 hover:text-[#B38A50] transition">
                            <span class="text-stone-400">شاخه اصلی:</span>
                            <span>{{ $subject->parent->name }}</span>
                        </a>
                    @else
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-[#B38A50]/15 text-[#B38A50] border border-[#B38A50]/30">
                            رده کلان دانشی
                        </span>
                    @endif

                    @if($hasChildren)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 dark:bg-amber-950/30 text-amber-700 dark:text-amber-300 border border-amber-500/20">
                            {{ $subject->children->count() }} زیرشاخه فعال
                        </span>
                    @endif
                </div>

                <h1 class="text-2xl sm:text-3xl lg:text-4xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                    موضوع: {{ $subject->name }}
                </h1>

                <p class="text-xs sm:text-sm text-stone-500 dark:text-stone-400 leading-relaxed">
                    فهرست و شناسنامه آثار، کتب و رسائل خطی تدوین‌شده ذیل موضوع «{{ $subject->name }}» در پایگاه جامع فهارس نسخه‌های خطی.
                </p>

                <!-- Top Languages in this subject -->
                @if($topLanguages->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-2 pt-1 text-xs">
                        <span class="text-stone-400">زبان‌های شاخص:</span>
                        @foreach($topLanguages as $lang)
                            <span class="px-2.5 py-0.5 rounded-lg bg-stone-100 dark:bg-stone-800 text-stone-700 dark:text-stone-300 text-[11px] font-medium border border-stone-200 dark:border-stone-700">
                                {{ $lang->name }} ({{ number_format($lang->count) }})
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Stats & Quick Actions -->
            <div class="flex flex-col sm:flex-row md:flex-col items-start md:items-end gap-3 shrink-0">
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <div class="bg-amber-500/10 dark:bg-amber-400/10 border border-amber-500/20 rounded-2xl px-4 py-3 text-center flex-1 sm:flex-none min-w-[110px]">
                        <span class="block text-2xl font-black text-[#B38A50] dark:text-amber-300">{{ number_format($subject->works_count) }}</span>
                        <span class="text-[11px] font-semibold text-stone-600 dark:text-stone-400">کل عناوین</span>
                    </div>

                    <div class="bg-stone-50 dark:bg-stone-800/80 border border-stone-200 dark:border-stone-700 rounded-2xl px-4 py-3 text-center flex-1 sm:flex-none min-w-[110px]">
                        <span class="block text-2xl font-black text-[#292C56] dark:text-amber-100">{{ number_format($totalManuscripts) }}</span>
                        <span class="text-[11px] font-semibold text-stone-500 dark:text-stone-400">نسخه خطی</span>
                    </div>
                </div>

                <a href="{{ route('search', ['type' => 'works', 'subject_id' => $subject->id]) }}" 
                   class="inline-flex items-center justify-center gap-2 w-full sm:w-auto px-4 py-2.5 bg-[#292C56] hover:bg-[#1f2244] dark:bg-stone-800 dark:hover:bg-stone-700 text-amber-100 rounded-xl text-xs font-bold transition shadow-sm">
                    <svg class="w-4 h-4 text-[#B38A50]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <span>کاوش پیشرفته با فیلترها</span>
                </a>
            </div>
        </div>

    </div>

    <!-- Subcategories Section (If subject is a parent) -->
    @if($hasChildren)
        <div class="bg-white dark:bg-[#15192C] p-6 rounded-3xl border border-stone-200/90 dark:border-stone-800 shadow-sm space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
                    <h2 class="text-base sm:text-lg font-bold text-[#292C56] dark:text-stone-100">
                        زیرشاخه‌های تخصصی «{{ $subject->name }}»
                    </h2>
                </div>
                <span class="text-xs text-stone-400">جهت محدود کردن به یک شاخه، بر روی آن کلیک کنید</span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2.5">
                @foreach($subject->children as $child)
                    <a href="{{ route('subjects.show', $child) }}" 
                       class="p-3 rounded-2xl bg-stone-50 dark:bg-stone-800/70 hover:bg-amber-500/10 dark:hover:bg-amber-400/10 border border-stone-200/80 dark:border-stone-700/80 hover:border-[#B38A50]/50 transition duration-150 flex flex-col justify-between group">
                        <span class="text-xs font-bold text-stone-800 dark:text-stone-200 group-hover:text-[#B38A50] transition">
                            {{ $child->name }}
                        </span>
                        <div class="mt-2 pt-1 border-t border-stone-200/40 dark:border-stone-700/40 flex items-center justify-between text-[11px] text-stone-400">
                            <span>تعداد اثر:</span>
                            <span class="font-bold text-[#B38A50]">{{ number_format($child->works_count) }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Works List Section -->
    <div class="space-y-4">

        <!-- Toolbar & Filter Bar -->
        <div class="bg-white dark:bg-[#15192C] p-4 sm:p-5 rounded-3xl border border-stone-200/90 dark:border-stone-800 shadow-sm space-y-4">
            
            <form action="{{ route('subjects.show', $subject) }}" method="GET" class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-4">
                
                <!-- Cumulative Scope Toggle (If parent subject) -->
                @if($hasChildren)
                    <div class="flex items-center gap-1 p-1 bg-stone-100 dark:bg-stone-800 rounded-2xl text-xs font-semibold shrink-0">
                        <button 
                            type="submit" 
                            name="scope" 
                            value="cumulative"
                            class="px-3 py-2 rounded-xl transition {{ $isCumulative ? 'bg-white dark:bg-stone-700 text-[#B38A50] shadow-xs font-bold' : 'text-stone-500 hover:text-stone-700 dark:hover:text-stone-300' }}">
                            شامل همه زیرشاخه‌ها (تجمعی)
                        </button>
                        <button 
                            type="submit" 
                            name="scope" 
                            value="direct"
                            class="px-3 py-2 rounded-xl transition {{ !$isCumulative ? 'bg-white dark:bg-stone-700 text-[#B38A50] shadow-xs font-bold' : 'text-stone-500 hover:text-stone-700 dark:hover:text-stone-300' }}">
                            تنها آثار مستقیم
                        </button>
                    </div>
                @else
                    <input type="hidden" name="scope" value="direct">
                @endif

                <!-- Inline Search in Subject Works -->
                <div class="flex-1 relative">
                    <input 
                        type="text" 
                        name="q" 
                        value="{{ $search }}" 
                        placeholder="جستجو در عناوین یا پدیدآوران این موضوع..."
                        class="w-full pr-10 pl-4 py-2.5 bg-stone-50 dark:bg-stone-800 rounded-xl border border-stone-300 dark:border-stone-700 text-xs sm:text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20">
                    <div class="absolute right-3.5 top-3 text-stone-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                </div>

                <!-- Sort & Submit -->
                <div class="flex items-center gap-2 shrink-0">
                    <select name="sort" onchange="this.form.submit()" class="p-2.5 bg-stone-50 dark:bg-stone-800 rounded-xl border border-stone-300 dark:border-stone-700 text-xs">
                        <option value="copies" {{ $sort === 'copies' ? 'selected' : '' }}>بیشترین نسخه خطی</option>
                        <option value="title" {{ $sort === 'title' ? 'selected' : '' }}>عنوان (الفبایی)</option>
                        <option value="date" {{ $sort === 'date' ? 'selected' : '' }}>سال تألیف (کهن‌ترین)</option>
                    </select>

                    <button type="submit" class="px-4 py-2.5 bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold rounded-xl text-xs transition shadow-xs">
                        پالایش
                    </button>

                    @if($search !== '' || $sort !== 'copies' || ($hasChildren && !$isCumulative))
                        <a href="{{ route('subjects.show', $subject) }}" class="px-3 py-2.5 bg-stone-100 hover:bg-stone-200 dark:bg-stone-800 dark:hover:bg-stone-700 text-stone-600 dark:text-stone-300 rounded-xl text-xs transition" title="لغو فیلترها">
                            ✕
                        </a>
                    @endif
                </div>

            </form>

            <div class="flex items-center justify-between text-xs text-stone-500 pt-1">
                <span>
                    نمایش نتایج: <strong>{{ number_format($works->total()) }}</strong> اثر یافت شد
                </span>
                <span>
                    صفحه {{ $works->currentPage() }} از {{ $works->lastPage() }}
                </span>
            </div>

        </div>

        <!-- Works Cards List -->
        @if($works->count() > 0)
            <div class="space-y-3">
                @foreach($works as $work)
                    <div class="bg-white dark:bg-[#15192C] p-5 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-sm hover:shadow-md transition duration-200 space-y-3">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <a href="{{ route('works.show', $work) }}" class="text-base sm:text-lg font-bold text-[#292C56] dark:text-amber-100 hover:text-[#B38A50] transition">
                                    {{ $work->primary_title }}
                                </a>
                                @if($work->clean_title && $work->clean_title !== $work->primary_title)
                                    <span class="text-xs text-stone-400 mr-2">({{ $work->clean_title }})</span>
                                @endif
                                
                                <div class="text-xs text-stone-600 dark:text-stone-300 mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                                    @if($work->author)
                                        <span>پدیدآور: <a href="{{ route('people.show', $work->author) }}" class="font-semibold text-stone-800 dark:text-stone-200 hover:underline">{{ $work->author->name }}</a></span>
                                    @elseif($work->author_name)
                                        <span>پدیدآور: <strong class="text-stone-700 dark:text-stone-300">{{ $work->author_name }}</strong></span>
                                    @else
                                        <span class="text-stone-400">پدیدآور: ناشناخته</span>
                                    @endif

                                    @if($work->composition_year_hijri)
                                        <span>تألیف: <strong>{{ $work->composition_year_hijri }} هـ.ق</strong></span>
                                    @elseif($work->author?->death_year_hijri)
                                        <span>وفات مؤلف: <strong>{{ $work->author->death_year_hijri }} هـ.ق</strong></span>
                                    @elseif($work->author?->death_century_hijri)
                                        <span>وفات مؤلف: <strong>قرن {{ $work->author->death_century_hijri }} هـ.ق</strong></span>
                                    @endif

                                    @if($work->work_form)
                                        <span>قالب: <strong>{{ $work->work_form }}</strong></span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-1.5 shrink-0 text-left">
                                <span class="px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold shadow-xs">
                                    {{ number_format($work->manuscripts_count) }} نسخه
                                </span>
                                @if($work->has_autograph)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/80 text-[11px] font-bold shadow-xs">
                                        <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                        </svg>
                                        <span>دستخط مؤلف</span>
                                    </span>
                                @endif
                            </div>
                        </div>

                        <!-- Subjects & Languages Tags -->
                        <div class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-stone-100 dark:border-stone-800/80 text-[11px]">
                            @foreach($work->subjects as $subj)
                                <a href="{{ route('subjects.show', $subj) }}" 
                                   class="px-2 py-0.5 rounded-md transition {{ $subj->id === $subject->id ? 'bg-amber-500/20 text-[#B38A50] font-bold border border-amber-500/30' : 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-400 hover:text-[#B38A50]' }}">
                                    {{ $subj->name }}
                                </a>
                            @endforeach

                            @foreach($work->languages as $lang)
                                <span class="px-2 py-0.5 rounded-md bg-stone-50 dark:bg-stone-800/60 text-stone-500 border border-stone-200/50 dark:border-stone-700/50">
                                    {{ $lang->name }}
                                </span>
                            @endforeach

                            <a href="{{ route('works.show', $work) }}" class="mr-auto font-bold text-[#B38A50] hover:text-[#9C753F] transition text-xs flex items-center gap-1">
                                <span>شناسنامه اثر و نسخه‌ها</span>
                                <span>←</span>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination -->
            @if($works->hasPages())
                <div class="pt-4">
                    {{ $works->links() }}
                </div>
            @endif

        @else
            <div class="p-12 bg-white dark:bg-[#15192C] rounded-3xl border border-stone-200 dark:border-stone-800 text-center text-stone-400 space-y-3">
                <svg class="w-12 h-12 mx-auto text-stone-300 dark:text-stone-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                <div class="text-sm font-semibold">اثری با این مشخصات در این موضوع یافت نشد.</div>
                @if($search !== '')
                    <a href="{{ route('subjects.show', $subject) }}" class="inline-block text-xs text-[#B38A50] hover:underline">
                        پاک‌کردن فیلتر جستجو
                    </a>
                @endif
            </div>
        @endif

    </div>

</div>
@endsection
