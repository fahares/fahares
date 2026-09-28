@extends('layouts.app')

@section('title', $work->primary_title . ' | شناسنامه اثر در فهارس')
@section('meta_description', 'مشخصات کتاب‌شناختی و نسخه‌های خطی ' . $work->primary_title . ' در فهرستگان نسخه‌های خطی ایران (فنخا)')

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
                    <div class="text-xs font-mono text-stone-400">
                        {{ $work->transliteration }}
                    </div>
                @endif
            </div>

            <!-- Manuscript Count Badge -->
            <div class="flex flex-col items-start md:items-end gap-2">
                <div class="px-5 py-3 rounded-2xl bg-gradient-to-br from-amber-50 to-[#FEF9F3] dark:from-stone-800 dark:to-[#15192C] border border-[#B38A50]/40 text-center shadow-sm">
                    <span class="block text-2xl sm:text-3xl font-black text-[#B38A50] font-mono">
                        {{ number_format($work->manuscripts_count) }}
                    </span>
                    <span class="text-xs font-semibold text-stone-600 dark:text-stone-400">نسخه ثبت‌شده</span>
                </div>

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
                    <a href="{{ route('people.show', $work->author_id) }}" class="text-sm font-bold text-[#292C56] dark:text-amber-200 hover:text-[#B38A50] transition">
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
                    <span class="text-sm font-bold text-stone-700 dark:text-stone-300 font-mono">{{ $work->composition_year_hijri }} هـ.ق</span>
                @elseif($work->composition_date_raw)
                    <span class="text-sm font-bold text-stone-700 dark:text-stone-300">{{ $work->composition_date_raw }}</span>
                @else
                    <span class="text-sm text-stone-400 italic">نامشخص</span>
                @endif
            </div>

            <!-- Source in Fankha -->
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">منبع در فنخا:</span>
                <span class="text-sm font-bold text-stone-700 dark:text-stone-300 font-mono">
                    جلد {{ $work->volume_number }}، ص {{ $work->page_start }}
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
                    <a href="{{ route('search', ['type' => 'works', 'subject_id' => $subj->id]) }}" 
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
                                    <span class="text-[10px] text-stone-400 font-mono">({{ $alt['transliteration'] }})</span>
                                @endif
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

    </div>

    <!-- MANUSCRIPTS TABLE SECTION -->
    <section id="manuscripts" class="space-y-4">
        
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-6 bg-[#B38A50] rounded-sm"></span>
                <h2 class="text-xl font-bold text-stone-900 dark:text-stone-100">
                    فهرست نسخه‌های خطی این اثر
                </h2>
                <span class="text-xs font-mono font-semibold px-2 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50]">
                    {{ number_format($work->manuscripts_count) }} نسخه
                </span>
            </div>
        </div>

        <div class="bg-white dark:bg-[#15192C] rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-600 dark:text-stone-300 font-bold border-b border-stone-200 dark:border-stone-700">
                        <tr>
                            <th class="py-3.5 px-4 w-12 text-center">ردیف</th>
                            <th class="py-3.5 px-4">کتابخانه و مرکز نگهداری</th>
                            <th class="py-3.5 px-4">شماره بازیابی / قفسه</th>
                            <th class="py-3.5 px-4">کاتب</th>
                            <th class="py-3.5 px-4">تاریخ کتابت</th>
                            <th class="py-3.5 px-4">نوع خط</th>
                            <th class="py-3.5 px-4">برگ</th>
                            <th class="py-3.5 px-4 text-center">مشاهده</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-stone-800/60 text-stone-700 dark:text-stone-300">
                        @forelse($manuscripts as $index => $ms)
                            <tr class="hover:bg-amber-50/40 dark:hover:bg-stone-800/40 transition">
                                <td class="py-3 px-4 text-center font-mono text-stone-400">
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

                                <td class="py-3 px-4 font-mono font-bold text-[#B38A50]">
                                    {{ $ms->shelfmark ?? 'بی‌شماره' }}
                                    @if($ms->is_autograph)
                                        <span class="block text-[10px] text-emerald-600 font-sans font-bold">اصل نسخه</span>
                                    @endif
                                </td>

                                <td class="py-3 px-4">
                                    @if($ms->scribe_name)
                                        <span class="font-medium">{{ $ms->scribe_name }}</span>
                                    @elseif($ms->is_bika)
                                        <span class="text-stone-400 italic">بی‌کاتب</span>
                                    @else
                                        <span class="text-stone-400">نامشخص</span>
                                    @endif
                                </td>

                                <td class="py-3 px-4">
                                    @if($ms->copy_date_raw)
                                        <span class="font-mono">{{ $ms->copy_date_raw }}</span>
                                    @elseif($ms->is_bita)
                                        <span class="text-stone-400 italic">بی‌تاریخ</span>
                                    @else
                                        <span class="text-stone-400">-</span>
                                    @endif
                                </td>

                                <td class="py-3 px-4">
                                    {{ $ms->script_names ?? '-' }}
                                </td>

                                <td class="py-3 px-4 font-mono">
                                    {{ $ms->folios ? $ms->folios . ' ب' : '-' }}
                                </td>

                                <td class="py-3 px-4 text-center">
                                    <a href="{{ route('manuscripts.show', $ms->id) }}" 
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
