@extends('layouts.admin')

@section('title', "ویرایش پدیدآور: {$person->name}")
@section('page_title', "ویرایش شناسنامه علمی: {$person->name}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.people.index') }}" class="hover:text-[#B38A50]">اشخاص</a>
    <span>/</span>
    <span class="text-stone-400">ویرایش #{{ $person->id }}</span>
@endsection

@section('content')
<div class="max-w-3xl mx-auto p-8 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-6">

    <div class="flex items-center justify-between border-b border-stone-100 dark:border-stone-800 pb-4">
        <div>
            <span class="text-xs text-stone-400">شناسه رکورد: #{{ $person->id }}</span>
            <div class="text-xs font-semibold text-stone-700 dark:text-stone-300 mt-1 flex items-center gap-4">
                <span>تألیفات: <strong class="text-[#B38A50] font-mono">{{ number_format($person->works_count) }}</strong> اثر</span>
                <span>کتابت‌ها: <strong class="text-amber-600 font-mono">{{ number_format($person->manuscripts_count) }}</strong> نسخه</span>
            </div>
        </div>
        <a href="{{ route('people.show', $person) }}" target="_blank" class="text-xs text-[#B38A50] hover:underline font-bold">
            مشاهده در سایت ↗
        </a>
    </div>

    <form action="{{ route('admin.people.update', $person) }}" method="POST" class="space-y-5 text-xs">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-stone-700 dark:text-stone-300">نام کامل و شهرت معیار:</label>
            <input type="text" name="name" id="name" value="{{ old('name', $person->name) }}" required 
                   class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-bold focus:border-[#B38A50] outline-none">
            @error('name')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Transliteration -->
        <div class="space-y-1.5">
            <label for="transliteration" class="block font-bold text-stone-700 dark:text-stone-300">نویسه‌گردانی لاتین (Transliteration):</label>
            <input type="text" name="transliteration" id="transliteration" value="{{ old('transliteration', $person->transliteration) }}" dir="ltr"
                   class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono text-left focus:border-[#B38A50] outline-none">
        </div>

        <!-- Dates Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="space-y-1">
                <label for="birth_year_hijri" class="block font-bold text-stone-700 dark:text-stone-300">ولادت (هـ.ق):</label>
                <input type="number" name="birth_year_hijri" id="birth_year_hijri" value="{{ old('birth_year_hijri', $person->birth_year_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono">
            </div>
            <div class="space-y-1">
                <label for="death_year_hijri" class="block font-bold text-stone-700 dark:text-stone-300">وفات (هـ.ق):</label>
                <input type="number" name="death_year_hijri" id="death_year_hijri" value="{{ old('death_year_hijri', $person->death_year_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono">
            </div>
            <div class="space-y-1">
                <label for="century_hijri" class="block font-bold text-stone-700 dark:text-stone-300">قرن هجری:</label>
                <input type="number" name="century_hijri" id="century_hijri" min="1" max="15" value="{{ old('century_hijri', $person->century_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono">
            </div>
            <div class="space-y-1">
                <label for="death_year_gregorian" class="block font-bold text-stone-700 dark:text-stone-300">وفات (میلادی):</label>
                <input type="number" name="death_year_gregorian" id="death_year_gregorian" value="{{ old('death_year_gregorian', $person->death_year_gregorian) }}" 
                       class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono">
            </div>
        </div>

        <!-- Role Flags -->
        <div class="flex items-center gap-6 pt-2">
            <label class="flex items-center gap-2 cursor-pointer font-bold text-stone-700 dark:text-stone-300">
                <input type="checkbox" name="is_author" value="1" {{ $person->is_author ? 'checked' : '' }} class="rounded border-stone-300 text-[#B38A50] focus:ring-[#B38A50] w-4 h-4">
                <span>پدیدآور / مؤلف</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer font-bold text-stone-700 dark:text-stone-300">
                <input type="checkbox" name="is_scribe" value="1" {{ $person->is_scribe ? 'checked' : '' }} class="rounded border-stone-300 text-[#B38A50] focus:ring-[#B38A50] w-4 h-4">
                <span>کاتب / خوشنویس</span>
            </label>
        </div>

        <!-- Bio Notes -->
        <div class="space-y-1.5">
            <label for="bio_notes" class="block font-bold text-stone-700 dark:text-stone-300">زیست‌نامه و یادداشت‌های پژوهشی:</label>
            <textarea name="bio_notes" id="bio_notes" rows="4" class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:border-[#B38A50] outline-none">{{ old('bio_notes', $person->bio_notes) }}</textarea>
        </div>

        <!-- Actions -->
        <div class="pt-4 flex items-center justify-between border-t border-stone-100 dark:border-stone-800">
            <a href="{{ route('admin.people.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold shadow-md transition">
                ذخیره تغییرات
            </button>
        </div>

    </form>

</div>
@endsection
