@extends('layouts.admin')

@section('title', 'ابزار ادغام موضوعات همپوشان')
@section('page_title', 'ابزار ادغام و تجمیع موضوعات (Subject Merger)')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-[#B38A50]">موضوعات</a>
    <span>/</span>
    <span class="text-stone-400">ادغام موضوعات</span>
@endsection

@section('content')
<div class="max-w-3xl mx-auto space-y-6">

    <!-- Warning Banner -->
    <div class="p-5 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-xs text-amber-900 dark:text-amber-200 space-y-2 leading-relaxed">
        <div class="flex items-center gap-2 font-bold text-sm text-amber-800 dark:text-amber-300">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>راهنمای ادغام موضوعات و رده‌های دانشی:</span>
        </div>
        <p>
            با اجرای این عملیات، تمامی آثار و رساله‌های منتسب به «موضوع مبدأ» به «موضوع مقصد» منتقل شده، شمارنده‌های آماری به صورت خودکار بازشماری می‌شوند و در نهایت رکورد موضوع مبدأ حذف خواهد شد. این عملیات در یک تراکنش امن انجام می‌پذیرد و در گزارش ممیزی سیستم ثبت می‌گردد.
        </p>
    </div>

    <!-- Merge Form Card -->
    <div class="p-8 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-6">
        <form action="{{ route('admin.subjects.merge') }}" method="POST" class="space-y-6 text-xs">
            @csrf

            <!-- Source Subject -->
            <div class="space-y-1.5">
                <label for="source_subject_id" class="block font-bold text-red-600 dark:text-red-400">
                    ۱. موضوع مبدأ (تکراری / حذف‌شونده):
                </label>
                <select name="source_subject_id" id="source_subject_id" required 
                        class="w-full p-3 rounded-xl border border-red-300 dark:border-red-900/50 bg-red-50/30 dark:bg-red-950/20 text-stone-900 dark:text-stone-100 font-semibold focus:border-red-500 outline-none">
                    <option value="">-- انتخاب موضوعی که باید حذف و ادغام شود --</option>
                    @foreach($subjects as $s)
                        <option value="{{ $s->id }}" {{ old('source_subject_id') == $s->id ? 'selected' : '' }}>
                            {{ $s->name }} (شناسه #{{ $s->id }} • {{ number_format($s->works_count) }} اثر)
                        </option>
                    @endforeach
                </select>
                @error('source_subject_id')
                    <p class="text-[11px] text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Arrow Indicator -->
            <div class="flex items-center justify-center">
                <div class="w-10 h-10 rounded-full bg-stone-100 dark:bg-stone-800 flex items-center justify-center text-stone-500 shadow-inner">
                    <svg class="w-5 h-5 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                </div>
            </div>

            <!-- Target Subject -->
            <div class="space-y-1.5">
                <label for="target_subject_id" class="block font-bold text-emerald-600 dark:text-emerald-400">
                    ۲. موضوع مقصد (موضوع اصلی / حفظ‌شونده):
                </label>
                <select name="target_subject_id" id="target_subject_id" required 
                        class="w-full p-3 rounded-xl border border-emerald-300 dark:border-emerald-900/50 bg-emerald-50/30 dark:bg-emerald-950/20 text-stone-900 dark:text-stone-100 font-semibold focus:border-emerald-500 outline-none">
                    <option value="">-- انتخاب موضوعی که آثار به آن منتقل می‌شود --</option>
                    @foreach($subjects as $s)
                        <option value="{{ $s->id }}" {{ old('target_subject_id') == $s->id ? 'selected' : '' }}>
                            {{ $s->name }} (شناسه #{{ $s->id }} • {{ number_format($s->works_count) }} اثر)
                        </option>
                    @endforeach
                </select>
                @error('target_subject_id')
                    <p class="text-[11px] text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Submit -->
            <div class="pt-4 flex items-center justify-between border-t border-stone-100 dark:border-stone-800">
                <a href="{{ route('admin.subjects.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">
                    انصراف
                </a>
                <button type="submit" 
                        onclick="return confirm('هشدار: این عملیات قابل بازگشت نیست. آیا از انتقال آثار و حذف موضوع مبدأ کاملاً اطمینان دارید؟')"
                        class="px-6 py-3 rounded-xl bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-700 hover:to-amber-800 text-white font-bold text-xs shadow-md transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span>اجرای قطعی ادغام موضوعات</span>
                </button>
            </div>

        </form>
    </div>

</div>
@endsection
