@extends('layouts.admin')

@section('title', 'ابزار ادغام و یکپارچه‌سازی اعلام')
@section('page_title', 'ابزار ادغام و یکپارچه‌سازی اعلام تکراری (Authorities Merger)')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.people.index') }}" class="hover:text-[#B38A50]">اشخاص</a>
    <span>/</span>
    <span class="text-stone-400">ادغام اعلام</span>
@endsection

@section('content')
<div class="max-w-3xl mx-auto space-y-6">

    <!-- Warning / Guide Banner -->
    <div class="p-6 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-xs text-amber-900 dark:text-amber-200 space-y-3 leading-relaxed">
        <div class="flex items-center gap-2 font-bold text-sm text-amber-800 dark:text-amber-300">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>راهنمای یکپارچه‌سازی پدیدآوران و اعلام همپوشان:</span>
        </div>
        <p>
            در پیکره‌های نسخ خطی، نام یک شخصیت ممکن است به دلیل تفاوت در ذکر القاب، کنیه‌ها یا نگارش‌های گوناگون در چند مدخل جداگانه ایجاد شده باشد. با استفاده از این ابزار:
        </p>
        <ul class="list-disc list-inside space-y-1 text-stone-700 dark:text-stone-300 pr-2">
            <li>تمامی آثار تألیفی (`author_id`) شخص مبدأ به شخص مقصد منتقل می‌شود.</li>
            <li>تمامی نسخه‌های کتابت‌شده (`scribe_id`) شخص مبدأ به شخص مقصد بازانتساب می‌گردد.</li>
            <li>شمارنده‌های آماری شخص مقصد بازشماری و نمایه میلی‌سرچ او بازتولید می‌شود.</li>
            <li>رکورد شخص مبدأ حذف شده و سند کامل تغییرات در لاگ ممیزی ثبت می‌گردد.</li>
        </ul>
    </div>

    <!-- Merge Form -->
    <div class="p-8 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-6">
        <form action="{{ route('admin.people.merge') }}" method="POST" class="space-y-6 text-xs">
            @csrf

            <!-- Source Person ID -->
            <div class="space-y-1.5">
                <label for="source_id" class="block font-bold text-red-600 dark:text-red-400">
                    ۱. شناسه عددی شخص مبدأ (تکراری / حذف‌شونده):
                </label>
                <div class="relative">
                    <input type="number" name="source_id" id="source_id" value="{{ old('source_id') }}" required placeholder="مثال: 12450" 
                           class="w-full p-3 rounded-xl border border-red-300 dark:border-red-900/50 bg-red-50/30 dark:bg-red-950/20 text-stone-900 dark:text-stone-100 font-mono text-sm font-bold focus:border-red-500 outline-none">
                </div>
                <p class="text-[11px] text-stone-400">شناسه رکورد شخص تکراری که می‌خواهید آثار آن منتقل و خودش حذف شود.</p>
                @error('source_id')
                    <p class="text-[11px] text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Arrow Indicator -->
            <div class="flex items-center justify-center">
                <div class="w-10 h-10 rounded-full bg-stone-100 dark:bg-stone-800 flex items-center justify-center text-stone-500 shadow-inner">
                    <svg class="w-5 h-5 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                </div>
            </div>

            <!-- Target Person ID -->
            <div class="space-y-1.5">
                <label for="target_id" class="block font-bold text-emerald-600 dark:text-emerald-400">
                    ۲. شناسه عددی شخص مقصد (اصلی / حفظ‌شونده):
                </label>
                <div class="relative">
                    <input type="number" name="target_id" id="target_id" value="{{ old('target_id') }}" required placeholder="مثال: 84" 
                           class="w-full p-3 rounded-xl border border-emerald-300 dark:border-emerald-900/50 bg-emerald-50/30 dark:bg-emerald-950/20 text-stone-900 dark:text-stone-100 font-mono text-sm font-bold focus:border-emerald-500 outline-none">
                </div>
                <p class="text-[11px] text-stone-400">شناسه رکورد شخص معیار و اصلی پایگاه که اطلاعات به آن تجمیع می‌شود.</p>
                @error('target_id')
                    <p class="text-[11px] text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Actions -->
            <div class="pt-4 flex items-center justify-between border-t border-stone-100 dark:border-stone-800">
                <a href="{{ route('admin.people.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
                <button type="submit" 
                        onclick="return confirm('هشدار: آیا از ادغام قطعی این دو رکورد اطمینان دارید؟ تمامی آثار شخص مبدأ به شخص مقصد منتقل و شخص مبدأ برای همیشه حذف خواهد شد.')"
                        class="px-6 py-3 rounded-xl bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-700 hover:to-amber-800 text-white font-bold text-xs shadow-md transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span>اجرای تراکنش ادغام اعلام</span>
                </button>
            </div>

        </form>
    </div>

</div>
@endsection
