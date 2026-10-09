@extends('layouts.admin')

@section('title', "ویرایش شناسنامه: {$cataloger->name}")
@section('page_title', "ویرایش شناسنامه فهرست‌نگار: {$cataloger->name}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.catalogers.index') }}" class="hover:text-[#B38A50]">فهرست‌نگاران</a>
    <span>/</span>
    <span class="text-stone-400">ویرایش #{{ $cataloger->id }}</span>
@endsection

@section('content')
<div class="max-w-3xl mx-auto p-8 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-6">

    <div class="flex items-center justify-between border-b border-stone-100 dark:border-stone-800 pb-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl overflow-hidden bg-stone-100 dark:bg-stone-800 border-2 border-[#B38A50]/40 shadow-xs shrink-0">
                @if($cataloger->avatar_url)
                    <img src="{{ $cataloger->avatar_url }}" alt="{{ $cataloger->name }}" class="w-full h-full object-cover">
                @else
                    <div class="w-full h-full flex items-center justify-center font-bold text-stone-400">
                        {{ mb_substr($cataloger->name, 0, 1) }}
                    </div>
                @endif
            </div>
            <div>
                <h3 class="text-base font-black text-stone-900 dark:text-stone-100">{{ $cataloger->name }}</h3>
                <span class="text-xs text-stone-400">شناسه رکورد: #{{ $cataloger->id }} • {{ number_format($cataloger->manuscripts_count) }} نسخه</span>
            </div>
        </div>
        <a href="{{ route('catalogers.show', $cataloger) }}" target="_blank" class="text-xs text-[#B38A50] hover:underline font-bold">
            مشاهده در سایت ↗
        </a>
    </div>

    <form action="{{ route('admin.catalogers.update', $cataloger) }}" method="POST" enctype="multipart/form-data" class="space-y-5 text-xs">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-stone-700 dark:text-stone-300">نام رسمی فهرست‌نگار:</label>
            <input type="text" name="name" id="name" value="{{ old('name', $cataloger->name) }}" required 
                   class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-bold focus:border-[#B38A50] outline-none">
            @error('name')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Avatar Upload -->
        <div class="space-y-1.5">
            <label for="avatar" class="block font-bold text-stone-700 dark:text-stone-300">تصویر پرتره جدید (اختیاری):</label>
            <input type="file" name="avatar" id="avatar" accept="image/*" 
                   class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 file:mr-4 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-[#B38A50] file:text-white">
            <p class="text-[11px] text-stone-400">بهترین ابعاد: کادر مربعی ۱:۱ متمرکز بر چهره (حداکثر ۳ مگابایت)</p>
            @error('avatar')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Life Years (Solar & Lunar) -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="space-y-1">
                <label for="birth_year_solar" class="block font-bold text-stone-700 dark:text-stone-300">ولادت (شمسی):</label>
                <input type="number" name="birth_year_solar" id="birth_year_solar" value="{{ old('birth_year_solar', $cataloger->birth_year_solar) }}" 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono">
            </div>
            <div class="space-y-1">
                <label for="death_year_solar" class="block font-bold text-stone-700 dark:text-stone-300">وفات (شمسی):</label>
                <input type="number" name="death_year_solar" id="death_year_solar" value="{{ old('death_year_solar', $cataloger->death_year_solar) }}" 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono">
            </div>
            <div class="space-y-1">
                <label for="birth_year_hijri" class="block font-bold text-stone-700 dark:text-stone-300">ولادت (قمری):</label>
                <input type="number" name="birth_year_hijri" id="birth_year_hijri" value="{{ old('birth_year_hijri', $cataloger->birth_year_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono">
            </div>
            <div class="space-y-1">
                <label for="death_year_hijri" class="block font-bold text-stone-700 dark:text-stone-300">وفات (قمری):</label>
                <input type="number" name="death_year_hijri" id="death_year_hijri" value="{{ old('death_year_hijri', $cataloger->death_year_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono">
            </div>
        </div>

        <!-- Bio -->
        <div class="space-y-1.5">
            <label for="bio" class="block font-bold text-stone-700 dark:text-stone-300">زیست‌نامه و کارنامه فهرست‌نگاری:</label>
            <textarea name="bio" id="bio" rows="6" class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 leading-relaxed focus:border-[#B38A50] outline-none">{{ old('bio', $cataloger->bio) }}</textarea>
        </div>

        <!-- Submit -->
        <div class="pt-4 flex items-center justify-between border-t border-stone-100 dark:border-stone-800">
            <a href="{{ route('admin.catalogers.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold shadow-md transition">
                ذخیره تغییرات
            </button>
        </div>

    </form>

</div>
@endsection
