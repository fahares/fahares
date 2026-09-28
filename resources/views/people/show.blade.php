@extends('layouts.app')

@section('title', $person->name . ' | اعلام و پدیدآوران فهارس')
@section('meta_description', 'شناسنامه علمی، آثار و نسخه‌های کتابت‌شده ' . $person->name . ' در فهارس نسخه‌های خطی')

@section('content')
<div x-data="{ activeTab: 'authored' }" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-stone-500">
        <a href="{{ route('home') }}" class="hover:text-[#B38A50]">فهارس</a>
        <span>/</span>
        <a href="{{ route('search', ['type' => 'people']) }}" class="hover:text-[#B38A50]">پدیدآوران و کاتبان</a>
        <span>/</span>
        <span class="text-stone-800 dark:text-stone-200 font-semibold">{{ $person->name }}</span>
    </nav>

    <!-- PERSON HEADER CARD -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-md space-y-6 relative overflow-hidden">
        
        <div class="flex flex-col sm:flex-row items-start justify-between gap-6">
            <div class="space-y-3">
                <div class="inline-block px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold">
                    شناسنامه شخص و اعلام
                </div>

                <h1 class="text-2xl sm:text-3xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                    {{ $person->name }}
                </h1>

                @if($person->transliteration)
                    <div class="text-xs text-stone-400">
                        {{ $person->transliteration }}
                    </div>
                @endif

                <!-- Roles Badges -->
                <div class="flex flex-wrap items-center gap-2 pt-2 text-xs">
                    @if($person->is_author)
                        <span class="px-2.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950 text-[#B38A50] font-bold">مؤلف / پدیدآور</span>
                    @endif
                    @if($person->is_scribe || ($person->scribed_manuscripts_count ?? $person->manuscripts_count) > 0)
                        <span class="px-2.5 py-0.5 rounded-full bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold">کاتب نسخه</span>
                    @endif
                    @if($person->is_translator)
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">مترجم</span>
                    @endif
                    @if($person->is_donor)
                        <span class="px-2.5 py-0.5 rounded-full bg-stone-100 dark:bg-stone-800 text-stone-700 dark:text-stone-300">واقف</span>
                    @endif
                </div>
            </div>

            <!-- Historical Dates Box -->
            <div class="p-4 rounded-2xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] text-xs space-y-2 min-w-[200px]">
                <div class="flex justify-between">
                    <span class="text-stone-400">سده هجری:</span>
                    <span class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $person->century_hijri ? 'سده ' . $person->century_hijri . ' هـ.ق' : 'نامشخص' }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-stone-400">سال وفات (قمری):</span>
                    <span class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $person->death_year_hijri ? $person->death_year_hijri . ' ق' : '-' }}
                    </span>
                </div>
                @if($person->death_year_gregorian)
                    <div class="flex justify-between">
                        <span class="text-stone-400">سال وفات (میلادی):</span>
                        <span class="text-stone-600 dark:text-stone-300">
                            {{ $person->death_year_gregorian }} م
                        </span>
                    </div>
                @endif
            </div>
        </div>

        <!-- Metric tabs switch -->
        <div class="flex items-center gap-3 pt-4 border-t border-stone-100 dark:border-stone-800 text-sm font-semibold">
            <button 
                @click="activeTab = 'authored'" 
                :class="activeTab === 'authored' ? 'bg-[#292C56] text-amber-100 shadow' : 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300'"
                class="px-4 py-2 rounded-xl transition flex items-center gap-2">
                <span>آثار و تألیفات</span>
                <span class="px-2 py-0.5 rounded-full text-xs bg-amber-500/20 font-bold">{{ $person->works_count ?? $authoredWorks->total() }}</span>
            </button>
            <button 
                @click="activeTab = 'scribed'" 
                :class="activeTab === 'scribed' ? 'bg-[#292C56] text-amber-100 shadow' : 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300'"
                class="px-4 py-2 rounded-xl transition flex items-center gap-2">
                <span>نسخه‌های کتابت‌شده</span>
                <span class="px-2 py-0.5 rounded-full text-xs bg-amber-500/20 font-bold">{{ $person->scribed_manuscripts_count ?? $person->manuscripts_count ?? $scribedManuscripts->total() }}</span>
            </button>
        </div>

    </div>

    <!-- TAB 1: AUTHORED WORKS -->
    <div x-show="activeTab === 'authored'" class="space-y-4">
        
        <h2 class="text-lg font-bold text-stone-900 dark:text-stone-100 flex items-center gap-2">
            <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
            <span>فهرست آثار و تألیفات ({{ number_format($authoredWorks->total()) }} اثر)</span>
        </h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @forelse($authoredWorks as $work)
                <div class="bg-white dark:bg-[#15192C] p-5 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-sm hover:shadow-md transition space-y-3 flex flex-col justify-between">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <a href="{{ route('works.show', $work) }}" class="text-base font-bold text-[#292C56] dark:text-amber-100 hover:text-[#B38A50] transition">
                                {{ $work->primary_title }}
                            </a>
                            <span class="px-2.5 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold whitespace-nowrap">
                                {{ $work->manuscripts_count }} نسخه
                            </span>
                        </div>

                        @if($work->clean_title && $work->clean_title !== $work->primary_title)
                            <div class="text-xs text-stone-400 mt-1">
                                {{ $work->clean_title }}
                            </div>
                        @endif

                        <div class="text-xs text-stone-500 mt-2 flex flex-wrap items-center gap-3">
                            @if($work->composition_year_hijri)
                                <span>تألیف: <strong class="text-stone-700 dark:text-stone-300">{{ $work->composition_year_hijri }} هـ.ق</strong></span>
                            @endif
                            @if($work->work_form)
                                <span class="px-2 py-0.5 rounded bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-400 text-[11px]">{{ $work->work_form }}</span>
                            @endif
                        </div>
                    </div>

                    <!-- Subjects & View Link -->
                    <div class="pt-3 border-t border-stone-100 dark:border-stone-800 flex items-center justify-between text-xs">
                        <div class="flex flex-wrap gap-1">
                            @foreach($work->subjects->take(2) as $s)
                                <span class="px-2 py-0.5 rounded bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-400 text-[10px]">{{ $s->title }}</span>
                            @endforeach
                        </div>
                        <a href="{{ route('works.show', $work) }}" class="text-[#B38A50] hover:underline font-semibold">
                            مشاهده اثر ←
                        </a>
                    </div>
                </div>
            @empty
                <div class="col-span-2 p-8 bg-white dark:bg-[#15192C] rounded-2xl text-center text-stone-400 text-xs">
                    اثری به عنوان مؤلف برای این شخص ثبت نشده است.
                </div>
            @endforelse
        </div>

        @if($authoredWorks->hasPages())
            <div class="pt-4">
                {{ $authoredWorks->links() }}
            </div>
        @endif

    </div>

    <!-- TAB 2: SCRIBED MANUSCRIPTS -->
    <div x-show="activeTab === 'scribed'" class="space-y-4" style="display: none;">
        
        <h2 class="text-lg font-bold text-stone-900 dark:text-stone-100 flex items-center gap-2">
            <span class="w-2 h-5 bg-[#292C56] dark:bg-indigo-400 rounded-sm"></span>
            <span>نسخه‌های خطی کتابت‌شده توسط این شخص ({{ number_format($scribedManuscripts->total()) }} نسخه)</span>
        </h2>

        <div class="space-y-3">
            @forelse($scribedManuscripts as $ms)
                <div class="bg-white dark:bg-[#15192C] p-5 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#292C56] shadow-sm transition space-y-2">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('manuscripts.show', $ms) }}" class="text-base font-bold text-[#292C56] dark:text-amber-100 hover:text-[#B38A50] transition">
                                    {{ $ms->work?->primary_title ?? 'نسخه بدون عنوان' }}
                                </a>
                                @if($ms->is_autograph)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300 text-[10px] font-bold">
                                        ✍️ دستخط مؤلف (نسخه اصل)
                                    </span>
                                @endif
                            </div>
                            <div class="text-xs text-stone-500 mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                @if($ms->work && $ms->work->author_id !== $person->id && $ms->work->author_name)
                                    <span>پدیدآور: <strong>{{ $ms->work->author_name }}</strong></span>
                                @endif
                                <span>کتابخانه: <strong>{{ $ms->libraryRecord?->name ?? $ms->library }}</strong> ({{ $ms->city }})</span>
                                <span>شماره نسخه: <strong class="text-[#B38A50]">{{ $ms->shelfmark ?? 'بی‌شماره' }}</strong></span>
                                <span>تاریخ کتابت: <strong>{{ $ms->copy_date_raw ?? 'نامشخص' }}</strong></span>
                            </div>
                        </div>

                        <a href="{{ route('manuscripts.show', $ms) }}" class="px-3 py-1 bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white rounded-lg text-xs font-semibold transition whitespace-nowrap">
                            شناسنامه نسخه ←
                        </a>
                    </div>
                </div>
            @empty
                <div class="p-8 bg-white dark:bg-[#15192C] rounded-2xl text-center text-stone-400 text-xs">
                    نسخه‌ای با ثبت این شخص به عنوان کاتب یافت نشد.
                </div>
            @endforelse
        </div>

        @if($scribedManuscripts->hasPages())
            <div class="pt-4">
                {{ $scribedManuscripts->links() }}
            </div>
        @endif

    </div>

</div>
@endsection
