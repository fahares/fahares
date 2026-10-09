@extends('layouts.admin')

@section('title', 'نمای درختی تاکسونومی موضوعات')
@section('page_title', 'ساختار درختی سلسله‌مراتب موضوعات (Taxonomy Tree)')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-[#B38A50]">موضوعات</a>
    <span>/</span>
    <span class="text-stone-400">نمای درختی</span>
@endsection

@section('content')
<div class="space-y-6 max-w-5xl">

    <div class="flex items-center justify-between">
        <p class="text-xs text-stone-500">
            ساختار والد/فرزند ۱۲۹ موضوع پایگاه فهارس؛ برای مشاهده زیرمجموعه‌ها روی هر شاخه کلیک کنید.
        </p>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.subjects.merge') }}" class="px-3.5 py-1.5 rounded-xl bg-amber-500/15 text-amber-900 dark:text-amber-200 text-xs font-bold hover:bg-amber-500/25 transition">
                ادغام شاخه‌ها
            </a>
            <a href="{{ route('admin.subjects.index') }}" class="px-3.5 py-1.5 rounded-xl border border-stone-300 dark:border-stone-700 text-xs font-bold hover:bg-stone-50 dark:hover:bg-stone-800 transition">
                بازگشت به جدول
            </a>
        </div>
    </div>

    <div class="bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs p-6 space-y-4">
        @foreach($rootSubjects as $root)
            <div x-data="{ open: true }" class="border border-stone-100 dark:border-stone-800 rounded-2xl overflow-hidden">
                <!-- Root Node Header -->
                <div class="p-4 bg-stone-50 dark:bg-stone-800/40 flex items-center justify-between cursor-pointer select-none" @click="open = !open">
                    <div class="flex items-center gap-3">
                        <button type="button" class="text-stone-400 hover:text-stone-600 transition">
                            <svg :class="open ? 'rotate-90' : ''" class="w-4 h-4 transition-transform transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <span class="text-sm font-bold text-stone-900 dark:text-stone-100">{{ $root->name }}</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#B38A50]/20 text-[#B38A50]">
                            {{ number_format($root->works_count) }} اثر
                        </span>
                        <span class="text-[10px] text-stone-400">
                            ({{ $root->children->count() }} زیرمجموعه)
                        </span>
                    </div>

                    <div class="flex items-center gap-2" @click.stop>
                        <a href="{{ route('admin.subjects.edit', $root) }}" class="text-[11px] font-bold text-[#B38A50] hover:underline px-2 py-1">
                            ویرایش
                        </a>
                    </div>
                </div>

                <!-- Children Sub-tree -->
                <div x-show="open" x-cloak class="p-4 pr-10 border-t border-stone-100 dark:border-stone-800 bg-white dark:bg-[#15192C] grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                    @forelse($root->children as $child)
                        <div class="p-3 rounded-xl border border-stone-200 dark:border-stone-800 flex items-center justify-between hover:border-[#B38A50]/40 transition group">
                            <div class="min-w-0 pr-2">
                                <span class="font-bold text-stone-800 dark:text-stone-200 block truncate">{{ $child->name }}</span>
                                <span class="text-[10px] text-stone-400 font-mono">{{ number_format($child->works_count) }} اثر</span>
                            </div>
                            <a href="{{ route('admin.subjects.edit', $child) }}" class="text-[10px] text-stone-400 hover:text-[#B38A50] font-bold opacity-0 group-hover:opacity-100 transition">
                                ویرایش
                            </a>
                        </div>
                    @empty
                        <div class="col-span-full text-stone-400 text-xs py-1">
                            شاخه بدون زیرمجموعه
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

</div>
@endsection
