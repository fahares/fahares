@extends('layouts.admin')

@section('title', 'عملیات سامانه و زیرساخت')
@section('page_title', 'عملیات سامانه، کش و موتور Meilisearch')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">سامانه و زیرساخت</span>
@endsection

@section('content')
<div class="space-y-8 max-w-5xl">

    <!-- Cache Management Card -->
    <div class="p-6 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="p-2.5 rounded-xl bg-amber-500/10 text-[#B38A50]">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </span>
                <div>
                    <h3 class="text-sm font-bold text-stone-900 dark:text-stone-100">پاکسازی حافظه‌های موقت (Cache Flushing)</h3>
                    <p class="text-xs text-stone-500">حذف کش اپلیکیشن، قالب‌های کامپایل‌شده Blade و مسیرهای ثبت‌شده</p>
                </div>
            </div>

            <form action="{{ route('admin.system.clear-cache') }}" method="POST">
                @csrf
                <button type="submit" 
                        onclick="return confirm('آیا از پاکسازی تمامی حافظه‌های موقت سیستم اطمینان دارید؟')"
                        class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-[#292C56] to-[#3D427D] hover:from-[#191B36] hover:to-[#292C56] text-amber-200 text-xs font-bold shadow-md transition flex items-center gap-2">
                    <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>پاکسازی کش سامانه</span>
                </button>
            </form>
        </div>
    </div>

    <!-- Meilisearch Sync and Reindexing Card -->
    <div class="p-6 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-stone-100 dark:border-stone-800 pb-4">
            <div class="flex items-center gap-3">
                <span class="p-2.5 rounded-xl bg-blue-500/10 text-blue-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <div>
                    <h3 class="text-sm font-bold text-stone-900 dark:text-stone-100">موتور جستجوی سریع Meilisearch</h3>
                    <p class="text-xs text-stone-500">پایش نمایه‌ها و بازتولید اسناد شاخص‌های جستجوی زنده</p>
                </div>
            </div>

            @if($meiliStats['healthy'])
                <span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    برخط و آماده
                </span>
            @else
                <span class="px-3 py-1 rounded-full text-xs font-bold bg-red-500/15 text-red-700 dark:text-red-300">
                    قطع ارتباط
                </span>
            @endif
        </div>

        <!-- Indexes List -->
        @if($meiliStats['healthy'] && !empty($meiliStats['indexes']))
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                @foreach($meiliStats['indexes'] as $idx)
                    <div class="p-4 rounded-xl border border-stone-200 dark:border-stone-800 bg-stone-50 dark:bg-stone-800/40 text-xs space-y-1">
                        <span class="text-stone-400 font-mono text-[10px]">Index:</span>
                        <h4 class="font-bold text-stone-900 dark:text-stone-100 font-mono">{{ $idx['uid'] ?? 'unknown' }}</h4>
                        <div class="text-[10px] text-stone-500">
                            کلید اصلی: <span class="font-mono text-[#B38A50]">{{ $idx['primaryKey'] ?? 'id' }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Reindex Actions -->
        <div class="pt-2 flex flex-wrap items-center gap-3">
            <span class="text-xs font-bold text-stone-700 dark:text-stone-300">ارسال به صف بازنمایه‌سازی:</span>
            
            <form action="{{ route('admin.system.reindex') }}" method="POST" class="inline">
                @csrf
                <input type="hidden" name="model" value="Work">
                <button type="submit" 
                        onclick="return confirm('آیا از بازنمایه‌سازی تمامی آثار در Meilisearch اطمینان دارید؟')"
                        class="px-4 py-2 rounded-xl border border-stone-300 dark:border-stone-700 hover:border-[#B38A50] text-xs font-bold text-stone-700 dark:text-stone-200 transition">
                    بازنمایه‌سازی عناوین آثار (Works)
                </button>
            </form>

            <form action="{{ route('admin.system.reindex') }}" method="POST" class="inline">
                @csrf
                <input type="hidden" name="model" value="Manuscript">
                <button type="submit" 
                        onclick="return confirm('آیا از بازنمایه‌سازی ۳۲۳ هزار نسخه خطی در Meilisearch اطمینان دارید؟ این عملیات در پس‌زمینه اجرا خواهد شد.')"
                        class="px-4 py-2 rounded-xl border border-stone-300 dark:border-stone-700 hover:border-[#B38A50] text-xs font-bold text-stone-700 dark:text-stone-200 transition">
                    بازنمایه‌سازی نسخ خطی (Manuscripts)
                </button>
            </form>
        </div>
    </div>

    <!-- System Diagnostics Grid -->
    <div class="p-6 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-4">
        <h3 class="text-sm font-bold text-stone-900 dark:text-stone-100">شناسنامه محیط و درایورهای اجرایی</h3>
        
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-xs font-mono">
            @foreach($systemInfo as $key => $val)
                <div class="p-3 rounded-xl bg-stone-50 dark:bg-stone-800/40 border border-stone-100 dark:border-stone-800">
                    <span class="text-stone-400 text-[10px] block font-sans">{{ $key }}</span>
                    <span class="font-bold text-stone-800 dark:text-stone-200">{{ $val }}</span>
                </div>
            @endforeach
        </div>
    </div>

</div>
@endsection
