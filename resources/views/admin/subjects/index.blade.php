@extends('layouts.admin')

@section('title', 'مدیریت موضوعات و تاکسونومی')
@section('page_title', 'مدیریت درخت موضوعات و رده‌بندی دانش')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
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
                       class="w-full pl-8 pr-4 py-2 rounded-xl border border-line-strong bg-surface text-xs focus:border-gold outline-none">
                @if($search)
                    <a href="{{ route('admin.subjects.index') }}" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-stone-400 hover:text-stone-600 text-xs">✕</a>
                @endif
            </div>
            <button type="submit" class="px-3.5 py-2 rounded-xl bg-ink text-paper text-xs font-bold shrink-0">جستجو</button>
        </form>

        <!-- Action Buttons -->
        <div class="flex items-center gap-2 text-xs">
            <a href="{{ route('admin.subjects.tree') }}" 
               class="px-3.5 py-2 rounded-xl border border-line-strong bg-surface hover:border-gold text-ink font-bold transition flex items-center gap-1.5 shadow-2xs">
                <svg class="w-4 h-4 text-cinnabar" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"/></svg>
                <span>نمای درختی (Tree)</span>
            </a>

            <a href="{{ route('admin.subjects.merge') }}" 
               class="px-3.5 py-2 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-900 dark:text-amber-200 hover:bg-amber-500/25 font-bold transition flex items-center gap-1.5 shadow-2xs">
                <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                <span>ابزار ادغام موضوعات</span>
            </a>

            <form action="{{ route('admin.subjects.recount') }}" method="POST" onsubmit="return confirm('آیا از بازشماری سرتاسری تعداد آثار موضوعات اطمینان دارید؟')">
                @csrf
                <button type="submit" class="px-3.5 py-2 rounded-xl border border-line-strong bg-surface hover:bg-stone-50 text-ink font-semibold transition" title="بازشماری آثار">
                    🔄 بازشماری
                </button>
            </form>

            <a href="{{ route('admin.subjects.create') }}" 
               class="px-4 py-2 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold transition flex items-center gap-1.5 shadow-md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>موضوع جدید</span>
            </a>
        </div>

    </div>

    <!-- Subjects Table -->
    <div class="bg-surface rounded-2xl border border-line shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-paper text-stone-500 border-b border-line">
                    <tr>
                        <th class="p-4 font-bold">شناسه</th>
                        <th class="p-4 font-bold">نام موضوع</th>
                        <th class="p-4 font-bold">موضوع والد (شاخه اصلی)</th>
                        <th class="p-4 font-bold">تعداد زیرمجموعه‌ها</th>
                        <th class="p-4 font-bold">تعداد آثار</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse($subjects as $subj)
                        <tr class="hover:bg-paper transition">
                            <td class="p-4 font-mono font-bold text-stone-400">#{{ $subj->id }}</td>
                            <td class="p-4">
                                <a href="{{ route('subjects.show', $subj) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar inline-flex items-center gap-1">
                                    <span>{{ $subj->name }}</span>
                                    <svg class="w-3 h-3 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                                <div class="text-[10px] text-stone-400 font-mono">{{ $subj->slug }}</div>
                            </td>
                            <td class="p-4">
                                @if($subj->parent)
                                    <span class="px-2.5 py-1 rounded-lg bg-stone-100 dark:bg-stone-800 text-ink font-medium">
                                        {{ $subj->parent->name }}
                                    </span>
                                @else
                                    <span class="text-[11px] text-amber-600 dark:text-amber-400 font-bold">شاخه ریشه (اصلی)</span>
                                @endif
                            </td>
                            <td class="p-4 font-mono font-semibold text-ink">
                                {{ number_format($subj->children->count()) }}
                            </td>
                            <td class="p-4">
                                <span class="px-2.5 py-0.5 rounded-full font-mono font-bold text-[11px] bg-stone-100 dark:bg-stone-800 text-ink">
                                    {{ number_format($subj->works_count) }}
                                </span>
                            </td>
                            <td class="p-4 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <a href="{{ route('admin.subjects.edit', $subj) }}" 
                                       class="px-2.5 py-1.5 rounded-lg bg-stone-100 dark:bg-stone-800 hover:bg-cinnabar hover:text-white text-ink font-bold transition">
                                        ویرایش
                                    </a>
                                    <a href="{{ route('admin.subjects.merge', ['source_subject_id' => $subj->id]) }}" 
                                       title="ادغام این موضوع در موضوع دیگر"
                                       class="px-2 py-1.5 rounded-lg bg-amber-500/10 hover:bg-amber-600 hover:text-white text-amber-700 dark:text-amber-300 font-bold transition flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                        <span>ادغام</span>
                                    </a>
                                </div>
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
            <div class="p-4 border-t border-line">
                {{ $subjects->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
