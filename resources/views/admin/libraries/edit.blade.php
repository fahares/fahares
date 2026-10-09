@extends('layouts.admin')

@section('title', "ویرایش کتابخانه: {$library->name}")
@section('page_title', "ویرایش کتابخانه و مرکز: {$library->name}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.libraries.index') }}" class="hover:text-[#B38A50]">کتابخانه‌ها</a>
    <span>/</span>
    <span class="text-stone-400">ویرایش #{{ $library->id }}</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto p-8 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-6">

    <div class="flex items-center justify-between border-b border-stone-100 dark:border-stone-800 pb-4">
        <div>
            <span class="text-xs text-stone-400">شناسه رکورد: #{{ $library->id }}</span>
            <div class="text-sm font-bold text-stone-800 dark:text-stone-200 mt-0.5">
                تعداد نسخه‌های ثبت‌شده: <span class="font-mono text-[#B38A50]">{{ number_format($library->manuscripts_count) }} نسخه</span>
            </div>
        </div>
        <a href="{{ route('libraries.show', $library) }}" target="_blank" class="text-xs text-[#B38A50] hover:underline font-bold">
            مشاهده در سایت ↗
        </a>
    </div>

    <form action="{{ route('admin.libraries.update', $library) }}" method="POST" class="space-y-5 text-xs">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-stone-700 dark:text-stone-300">نام مختصر یا رایج کتابخانه:</label>
            <input type="text" name="name" id="name" value="{{ old('name', $library->name) }}" required 
                   class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-bold focus:border-[#B38A50] outline-none">
            @error('name')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Full Name -->
        <div class="space-y-1.5">
            <label for="full_name" class="block font-bold text-stone-700 dark:text-stone-300">عنوان رسمی و تفصیلی مرکز:</label>
            <input type="text" name="full_name" id="full_name" value="{{ old('full_name', $library->full_name) }}" 
                   placeholder="مثال: کتابخانه، موزه و مرکز اسناد مجلس شورای اسلامی..."
                   class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:border-[#B38A50] outline-none">
        </div>

        <!-- City and Country -->
        <div class="grid grid-cols-2 gap-4">
            <div class="space-y-1">
                <label for="city" class="block font-bold text-stone-700 dark:text-stone-300">شهر محل استقرار:</label>
                <input type="text" name="city" id="city" value="{{ old('city', $library->city) }}" required 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-semibold focus:border-[#B38A50] outline-none">
            </div>
            <div class="space-y-1">
                <label for="country" class="block font-bold text-stone-700 dark:text-stone-300">کشور:</label>
                <input type="text" name="country" id="country" value="{{ old('country', $library->country) }}" required 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-semibold focus:border-[#B38A50] outline-none">
            </div>
        </div>

        <!-- Description -->
        <div class="space-y-1.5">
            <label for="description" class="block font-bold text-stone-700 dark:text-stone-300">پیشینه و مشخصات آرشیو (اختیاری):</label>
            <textarea name="description" id="description" rows="4" class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 leading-relaxed focus:border-[#B38A50] outline-none">{{ old('description', $library->description) }}</textarea>
        </div>

        <!-- Submit -->
        <div class="pt-4 flex items-center justify-between border-t border-stone-100 dark:border-stone-800">
            <a href="{{ route('admin.libraries.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold shadow-md transition">
                ذخیره تغییرات
            </button>
        </div>

    </form>

</div>
@endsection
