@extends('layouts.admin')

@section('title', "ویرایش شناسنامه: {$cataloger->name}")
@section('page_title', "ویرایش شناسنامه فهرست‌نگار: {$cataloger->name}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.catalogers.index') }}" class="hover:text-cinnabar">فهرست‌نگاران</a>
    <span>/</span>
    <span class="text-stone-400">ویرایش #{{ $cataloger->id }}</span>
@endsection

@section('content')
<div class="max-w-3xl mx-auto p-8 rounded-2xl bg-surface border border-line shadow-xs space-y-6">

    <div class="flex items-center justify-between border-b border-line pb-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl overflow-hidden bg-stone-100 dark:bg-stone-800 border-2 border-gold/40 shadow-xs shrink-0">
                @if($cataloger->avatar_url)
                    <img src="{{ $cataloger->avatar_url }}" alt="{{ $cataloger->name }}" class="w-full h-full object-cover">
                @else
                    <div class="w-full h-full flex items-center justify-center font-bold text-stone-400">
                        {{ mb_substr($cataloger->name, 0, 1) }}
                    </div>
                @endif
            </div>
            <div>
                <h3 class="text-base font-black text-ink">{{ $cataloger->name }}</h3>
                <span class="text-xs text-stone-400">شناسه رکورد: #{{ $cataloger->id }} • {{ number_format($cataloger->manuscripts_count) }} نسخه</span>
            </div>
        </div>
        <a href="{{ route('catalogers.show', $cataloger) }}" target="_blank" class="text-xs text-cinnabar hover:underline font-bold">
            مشاهده در سایت ↗
        </a>
    </div>

    <form action="{{ route('admin.catalogers.update', $cataloger) }}" method="POST" enctype="multipart/form-data" class="space-y-5 text-xs">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-ink">نام رسمی فهرست‌نگار:</label>
            <input type="text" name="name" id="name" value="{{ old('name', $cataloger->name) }}" required 
                   class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink font-bold focus:border-gold outline-none">
            @error('name')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Avatar Upload -->
        <div class="space-y-1.5">
            <label for="avatar" class="block font-bold text-ink">تصویر پرتره جدید (اختیاری):</label>
            <input type="file" name="avatar" id="avatar" accept="image/*" 
                   class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink file:mr-4 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-cinnabar file:text-on-cinnabar">
            <p class="text-[11px] text-stone-400">بهترین ابعاد: کادر مربعی ۱:۱ متمرکز بر چهره (حداکثر ۳ مگابایت)</p>
            @error('avatar')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Life Years (Solar & Lunar) -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="space-y-1">
                <label for="birth_year_solar" class="block font-bold text-ink">ولادت (شمسی):</label>
                <input type="number" name="birth_year_solar" id="birth_year_solar" value="{{ old('birth_year_solar', $cataloger->birth_year_solar) }}" 
                       class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink font-mono">
            </div>
            <div class="space-y-1">
                <label for="death_year_solar" class="block font-bold text-ink">وفات (شمسی):</label>
                <input type="number" name="death_year_solar" id="death_year_solar" value="{{ old('death_year_solar', $cataloger->death_year_solar) }}" 
                       class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink font-mono">
            </div>
            <div class="space-y-1">
                <label for="birth_year_hijri" class="block font-bold text-ink">ولادت (قمری):</label>
                <input type="number" name="birth_year_hijri" id="birth_year_hijri" value="{{ old('birth_year_hijri', $cataloger->birth_year_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink font-mono">
            </div>
            <div class="space-y-1">
                <label for="death_year_hijri" class="block font-bold text-ink">وفات (قمری):</label>
                <input type="number" name="death_year_hijri" id="death_year_hijri" value="{{ old('death_year_hijri', $cataloger->death_year_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink font-mono">
            </div>
        </div>

        <!-- Bio -->
        <div class="space-y-1.5">
            <label for="bio" class="block font-bold text-ink">زیست‌نامه و کارنامه فهرست‌نگاری:</label>
            <textarea name="bio" id="bio" rows="6" class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink leading-relaxed focus:border-gold outline-none">{{ old('bio', $cataloger->bio) }}</textarea>
        </div>

        <!-- Submit -->
        <div class="pt-4 flex items-center justify-between border-t border-line">
            <a href="{{ route('admin.catalogers.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold shadow-md transition">
                ذخیره تغییرات
            </button>
        </div>

    </form>

</div>
@endsection
