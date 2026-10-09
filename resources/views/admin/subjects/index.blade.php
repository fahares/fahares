@extends('layouts.admin')

@section('title', 'مدیریت موضوعات و تاکسونومی')
@section('page_title', 'مدیریت درخت موضوعات و رده‌بندی دانش')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">موضوعات</span>
@endsection

@section('content')
<div class="space-y-6">

    <!-- Action Toolbar & Search -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        
        <!-- Search -->
        <form action="{{ route('admin.subjects.index') }}" method="GET" class="flex items-center gap-2 max-w-sm w-full">
            <div class="relative w-full">
                <input type="text" name="q" value="{{ $search }}" placeholder="جستجوی موضوع..." 
                       class="w-full pl-8 pr-4 py-2 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-[#15192C] text-xs focus:border-[#B38A50] outline-none">
                @if($search)
                    <a href="{{ route('admin.subjects.index') }}" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-stone-400 hover:text-stone-600 text-xs">✕</a>
                @endif
            </div>
            <button type="submit" class="px-3.5 py-2 rounded-xl bg-[#292C56] text-amber-200 text-xs font-bold shrink-0">جستجو</button>
        </form>

        <!-- Action Buttons -->
        <div class="flex items-center gap-2 text-xs">
            <a href="{{ route('admin.subjects.tree') }}" 
               class="px-3.5 py-2 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-[#15192C] hover:border-[#B38A50] text-stone-700 dark:text-stone-200 font-bold transition flex items-center gap-1.5 shadow-2xs">
                <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"/></svg>
                <span>نمای درختی (Tree)</span>
            </a>

            <a href="{{ route('admin.subjects.merge') }}" 
               class="px-3.5 py-2 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-900 dark:text-amber-200 hover:bg-amber-500/25 font-bold transition flex items-center gap-1.5 shadow-2xs">
                <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                <span>ابزار ادغام موضوعات</span>
            </a>

            <form action="{{ route('admin.subjects.recount') }}" method="POST" onsubmit="return confirm('آیا از بازشماری سرتاسری تعداد آثار موضوعات اطمینان دارید؟')">
                @csrf
                <button type="submit" class="px-3.5 py-2 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-[#15192C] hover:bg-stone-50 text-stone-700 dark:text-stone-300 font-semibold transition" title="بازشماری آثار">
                    🔄 بازشماری
                </button>
            </form>

            <a href="{{ route('admin.subjects.create') }}" 
               class="px-4 py-2 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold transition flex items-center gap-1.5 shadow-md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>موضوع جدید</span>
            </a>
        </div>

    </div>

    <!-- Subjects Table -->
    <div class="bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-500 border-b border-stone-100 dark:border-stone-800">
                    <tr>
                        <th class="p-4 font-bold">شناسه</th>
                        <th class="p-4 font-bold">نام موضوع</th>
                        <th class="p-4 font-bold">موضوع والد (شاخه اصلی)</th>
                        <th class="p-4 font-bold">تعداد زیرمجموعه‌ها</th>
                        <th class="p-4 font-bold">تعداد آثار</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                    @forelse($subjects as $subj)
                        <tr class="hover:bg-stone-50/50 dark:hover:bg-stone-800/40 transition">
                            <td class="p-4 font-mono font-bold text-stone-400">#{{ $subj->id }}</td>
                            <td class="p-4">
                                <a href="{{ route('subjects.show', $subj) }}" target="_blank" class="font-bold text-stone-900 dark:text-stone-100 hover:text-[#B38A50] inline-flex items-center gap-1">
                                    <span>{{ $subj->name }}</span>
                                    <svg class="w-3 h-3 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                                <div class="text-[10px] text-stone-400 font-mono">{{ $subj->slug }}</div>
                            </td>
                            <td class="p-4">
                                @if($subj->parent)
                                    <span class="px-2.5 py-1 rounded-lg bg-stone-100 dark:bg-stone-800 text-stone-700 dark:text-stone-300 font-medium">
                                        {{ $subj->parent->name }}
                                    </span>
                                @else
                                    <span class="text-[11px] text-amber-600 dark:text-amber-400 font-bold">شاخه ریشه (اصلی)</span>
                                @endif
                            </td>
                            <td class="p-4 font-mono font-semibold text-stone-700 dark:text-stone-300">
                                {{ number_format($subj->children->count()) }}
                            </td>
                            <td class="p-4">
                                <span class="px-2.5 py-0.5 rounded-full font-mono font-bold text-[11px] bg-stone-100 dark:bg-stone-800 text-stone-800 dark:text-stone-200">
                                    {{ number_format($subj->works_count) }}
                                </span>
                            </td>
                            <td class="p-4 text-center">
                                <a href="{{ route('admin.subjects.edit', $subj) }}" 
                                   class="px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white text-stone-700 dark:text-stone-200 font-bold transition">
                                    ویرایش
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-12 text-center text-xs text-stone-400">
                                موضوعی یافت نشد.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($subjects->hasPages())
            <div class="p-4 border-t border-stone-100 dark:border-stone-800">
                {{ $subjects->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
