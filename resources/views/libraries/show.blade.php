@extends('layouts.app')

@section('title', $library->name . ' | کتابخانه‌ها در فهارس')
@section('meta_description', 'فهرست نسخه‌های خطی نگهداری‌شده در ' . $library->name . ' (' . ($library->city ?? '') . ') در سامانه فهارس')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-stone-500">
        <a href="{{ route('home') }}" class="hover:text-[#B38A50]">فهارس</a>
        <span>/</span>
        <a href="{{ route('libraries.index') }}" class="hover:text-[#B38A50]">کتابخانه‌ها</a>
        <span>/</span>
        <span class="text-stone-800 dark:text-stone-200 font-semibold truncate">{{ $library->name }}</span>
    </nav>

    <!-- LIBRARY HEADER -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-md flex flex-col sm:flex-row items-start justify-between gap-6">
        <div class="space-y-2">
            <div class="inline-block px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold">
                مرکز اسنادی و کتابخانه
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                {{ $library->name }}
            </h1>
            <div class="text-xs text-stone-500">
                موقعیت: <strong class="text-stone-700 dark:text-stone-300">{{ $library->city ?? 'ایران' }}</strong>
                @if($library->country && $library->country !== 'ایران')
                    • {{ $library->country }}
                @endif
            </div>
        </div>

        <div class="px-5 py-3 rounded-2xl bg-gradient-to-br from-amber-50 to-[#FEF9F3] dark:from-stone-800 dark:to-[#15192C] border border-[#B38A50]/40 text-center shadow-sm">
            <span class="block text-2xl sm:text-3xl font-black text-[#B38A50] font-mono">
                {{ number_format($library->manuscripts_count) }}
            </span>
            <span class="text-xs font-semibold text-stone-600 dark:text-stone-400">نسخه در فنخا</span>
        </div>
    </div>

    <!-- MANUSCRIPTS TABLE -->
    <section class="space-y-4">
        <h2 class="text-lg font-bold text-stone-900 dark:text-stone-100 flex items-center gap-2">
            <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
            <span>نسخه‌های خطی موجود در این مرکز</span>
        </h2>

        <div class="bg-white dark:bg-[#15192C] rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-600 dark:text-stone-300 font-bold border-b border-stone-200 dark:border-stone-700">
                        <tr>
                            <th class="py-3.5 px-4">عنوان اثر</th>
                            <th class="py-3.5 px-4">پدیدآور اثر</th>
                            <th class="py-3.5 px-4">شماره بازیابی / قفسه</th>
                            <th class="py-3.5 px-4">کاتب</th>
                            <th class="py-3.5 px-4">تاریخ کتابت</th>
                            <th class="py-3.5 px-4">نوع خط</th>
                            <th class="py-3.5 px-4 text-center">مشاهده</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-stone-800/60 text-stone-700 dark:text-stone-300">
                        @forelse($manuscripts as $ms)
                            <tr class="hover:bg-amber-50/40 dark:hover:bg-stone-800/40 transition">
                                <td class="py-3 px-4 font-bold text-[#292C56] dark:text-amber-100">
                                    @if($ms->work)
                                        <a href="{{ route('works.show', $ms->work_id) }}" class="hover:text-[#B38A50] transition">
                                            {{ $ms->work->primary_title }}
                                        </a>
                                    @else
                                        نسخه بدون عنوان
                                    @endif
                                </td>

                                <td class="py-3 px-4">
                                    {{ $ms->work?->author?->name ?? $ms->work?->author_name ?? '-' }}
                                </td>

                                <td class="py-3 px-4 font-mono font-bold text-[#B38A50]">
                                    {{ $ms->shelfmark ?? 'بی‌شماره' }}
                                </td>

                                <td class="py-3 px-4">
                                    {{ $ms->scribe_name ?? ($ms->is_bika ? 'بی‌کاتب' : '-') }}
                                </td>

                                <td class="py-3 px-4 font-mono">
                                    {{ $ms->copy_date_raw ?? ($ms->is_bita ? 'بی‌تاریخ' : '-') }}
                                </td>

                                <td class="py-3 px-4">
                                    {{ $ms->script_names ?? '-' }}
                                </td>

                                <td class="py-3 px-4 text-center">
                                    <a href="{{ route('manuscripts.show', $ms->id) }}" class="px-3 py-1 bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white rounded-lg text-xs font-semibold transition inline-block">
                                        شناسنامه ←
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-stone-400">
                                    نسخه‌ای در این مرکز ثبت نشده است.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($manuscripts->hasPages())
                <div class="p-4 border-t border-stone-100 dark:border-stone-800 bg-stone-50/50 dark:bg-stone-800/30">
                    {{ $manuscripts->links() }}
                </div>
            @endif
        </div>
    </section>

</div>
@endsection
