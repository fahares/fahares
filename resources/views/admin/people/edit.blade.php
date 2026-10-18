@extends('layouts.admin')

@section('title', "ویرایش پدیدآور: {$person->name}")
@section('page_title', "ویرایش شناسنامه علمی: {$person->name}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.people.index') }}" class="hover:text-cinnabar">اشخاص</a>
    <span>/</span>
    <span class="text-stone-400">ویرایش #{{ $person->id }}</span>
@endsection

@section('content')
<div class="max-w-3xl mx-auto p-8 rounded-2xl bg-surface border border-line shadow-xs space-y-6">

    <div class="flex items-center justify-between border-b border-line pb-4">
        <div>
            <span class="text-xs text-stone-400">شناسه رکورد: #{{ $person->id }}</span>
            <div class="text-xs font-semibold text-ink mt-1 flex items-center gap-4">
                <span>تألیفات: <strong class="text-cinnabar font-mono">{{ number_format($person->works_count) }}</strong> اثر</span>
                <span>کتابت‌ها: <strong class="text-amber-600 font-mono">{{ number_format($person->manuscripts_count) }}</strong> نسخه</span>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.people.merge', ['source_id' => $person->id]) }}" 
               title="ادغام این شخص تکراری در شخص دیگر یا ادغام شخصی در این رکورد"
               class="px-3 py-1.5 rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-600 hover:text-white text-amber-800 dark:text-amber-300 font-bold transition flex items-center gap-1.5 text-xs shadow-xs">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                <span>ادغام با شخص دیگر...</span>
            </a>
            <a href="{{ route('people.show', $person) }}" target="_blank" class="text-xs text-cinnabar hover:underline font-bold">
                مشاهده در سایت ↗
            </a>
        </div>
    </div>

    <form action="{{ route('admin.people.update', $person) }}" method="POST" class="space-y-5 text-xs">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-ink">نام کامل و شهرت معیار:</label>
            <input type="text" name="name" id="name" value="{{ old('name', $person->name) }}" required 
                   class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink font-bold focus:border-gold outline-none">
            @error('name')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Transliteration -->
        <div class="space-y-1.5">
            <label for="transliteration" class="block font-bold text-ink">نویسه‌گردانی لاتین (Transliteration):</label>
            <input type="text" name="transliteration" id="transliteration" value="{{ old('transliteration', $person->transliteration) }}" dir="ltr"
                   class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink font-mono text-left focus:border-gold outline-none">
        </div>

        <!-- Dates Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="space-y-1">
                <label for="birth_year_hijri" class="block font-bold text-ink">ولادت (هـ.ق):</label>
                <input type="number" name="birth_year_hijri" id="birth_year_hijri" value="{{ old('birth_year_hijri', $person->birth_year_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink font-mono">
            </div>
            <div class="space-y-1">
                <label for="death_year_hijri" class="block font-bold text-ink">وفات (هـ.ق):</label>
                <input type="number" name="death_year_hijri" id="death_year_hijri" value="{{ old('death_year_hijri', $person->death_year_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink font-mono">
            </div>
            <div class="space-y-1">
                <label for="century_hijri" class="block font-bold text-ink">قرن هجری:</label>
                <input type="number" name="century_hijri" id="century_hijri" min="1" max="15" value="{{ old('century_hijri', $person->century_hijri) }}" 
                       class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink font-mono">
            </div>
            <div class="space-y-1">
                <label for="death_year_gregorian" class="block font-bold text-ink">وفات (میلادی):</label>
                <input type="number" name="death_year_gregorian" id="death_year_gregorian" value="{{ old('death_year_gregorian', $person->death_year_gregorian) }}" 
                       class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink font-mono">
            </div>
        </div>

        <!-- Role Flags -->
        <div class="flex items-center gap-6 pt-2">
            <label class="flex items-center gap-2 cursor-pointer font-bold text-ink">
                <input type="checkbox" name="is_author" value="1" {{ $person->is_author ? 'checked' : '' }} class="rounded border-stone-300 text-cinnabar focus:ring-cinnabar w-4 h-4">
                <span>پدیدآور / مؤلف</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer font-bold text-ink">
                <input type="checkbox" name="is_scribe" value="1" {{ $person->is_scribe ? 'checked' : '' }} class="rounded border-stone-300 text-cinnabar focus:ring-cinnabar w-4 h-4">
                <span>کاتب / خوشنویس</span>
            </label>
        </div>

        <!-- Bio Notes -->
        <div class="space-y-1.5">
            <label for="bio_notes" class="block font-bold text-ink">زیست‌نامه و یادداشت‌های پژوهشی:</label>
            <textarea name="bio_notes" id="bio_notes" rows="4" class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink focus:border-gold outline-none">{{ old('bio_notes', $person->bio_notes) }}</textarea>
        </div>

        <!-- Actions -->
        <div class="pt-4 flex items-center justify-between border-t border-line">
            <a href="{{ route('admin.people.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold shadow-md transition">
                ذخیره تغییرات
            </button>
        </div>

    </form>

</div>
@endsection
