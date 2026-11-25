@extends('layouts.admin')

{{-- «اثر تازه» (backlog item 161): a work's record without a copy; the labels are those of the work's correction form (SuggestionRegistry::workFields) --}}
@php
    $fa = fn ($v) => \App\Support\Fa::digits($v);
    $picker = fn (string $field, string $type) => ['url' => route('suggestions.lookup'), 'type' => $type, 'picked' => $picked[$field] ?? null];
@endphp

@section('title', 'اثر تازه')
@section('page_title', 'اثر تازه')
@section('breadcrumb')
    <span>ساختن رکورد اثر</span>
@endsection

@section('content')
<x-ui.card class="max-w-3xl space-y-5 text-[13px]">
    <p class="text-muted leading-7">
        رکورد اثری که در سایت نیست، بی نسخه: اثری که در کتاب‌شناسی یا تراجم‌نامه‌ای آمده و نسخه‌ای از آن در فهرست‌ها نیست.
        برای اثری که نسخه‌اش را می‌شناسید، از «نسخۀ تازه» بسازید. رکورد در لاگ ممیزی ثبت می‌شود و تا نسخه‌ای نگرفته، از همان‌جا بازگرداندنی است.
    </p>

    @if($errors->any())
        <x-admin.notice tone="error">{{ $errors->first() }}</x-admin.notice>
    @endif

    <form action="{{ route('admin.works.store') }}" method="POST" class="space-y-6">
        @csrf

        @if($duplicates->isNotEmpty() || $namesakes->isNotEmpty())
            <x-admin.notice tone="warn" data-duplicate-warning>
                @if($duplicates->isNotEmpty())
                    <p class="font-bold text-ink">اثری با همین عنوان{{ old('author_id') ? ' از همین پدیدآور' : '' }} در سایت هست:</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach($duplicates as $w)
                            <li data-duplicate-work="{{ $w->id }}">
                                <a href="{{ route('works.show', $w) }}" target="_blank" class="font-bold hover:text-cinnabar">{{ $fa($w->primary_title) }}</a>
                                <span class="text-muted">— {{ $w->author_name ? $fa($w->author_name) : 'بی پدیدآور' }}، {{ $fa($w->manuscripts_count ?: 0) }} نسخه (#{{ $fa($w->id) }})</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if($namesakes->isNotEmpty())
                    <p class="font-bold text-ink mt-2">شخص یا اشخاصی با نام پدیدآور در سایت هستند؛ اگر همان شخص است، او را در «پدیدآور (شخص سایت)» انتخاب کنید:</p>
                    @include('admin.people._namesakes', ['people' => $namesakes])
                @endif
                <label class="mt-2 flex items-center gap-2 font-bold text-ink">
                    <input type="checkbox" name="confirm_duplicate" value="1" class="accent-cinnabar">
                    {{ $duplicates->isNotEmpty() ? 'اثر دیگری است؛ ساخته شود.' : 'شخص دیگری است؛ ساخته شود.' }}
                </label>
            </x-admin.notice>
        @endif

        {{-- 1. The work's titles --}}
        <fieldset class="space-y-4">
            <legend class="rubric text-base">۱. عنوان</legend>
            <x-admin.field label="عنوان اثر" for="title" error="title">
                <input type="text" name="title" id="title" value="{{ old('title') }}" maxlength="255" required class="admin-field w-full font-bold">
            </x-admin.field>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-admin.field label="عناوین دیگر" for="alternative_titles" error="alternative_titles" hint="هر عنوان در یک سطر.">
                    <textarea name="alternative_titles" id="alternative_titles" rows="3" class="admin-field w-full leading-7">{{ old('alternative_titles') }}</textarea>
                </x-admin.field>
                <x-admin.field label="آوانگاری عنوان" for="transliteration" error="transliteration">
                    <input type="text" name="transliteration" id="transliteration" value="{{ old('transliteration') }}" maxlength="255" dir="ltr" class="admin-field w-full text-left">
                </x-admin.field>
            </div>
        </fieldset>

        {{-- 2. The author --}}
        <fieldset class="space-y-4">
            <legend class="rubric text-base">۲. پدیدآور</legend>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-admin.field label="پدیدآور (شخص سایت)" error="author_id" hint="اگر شخص در سایت هست.">
                    <x-admin.record-picker name="author_id" :setup="$picker('author_id', 'person')" placeholder="نام شخص…" />
                </x-admin.field>
                <x-admin.field label="نام پدیدآور" for="author_name" error="author_name" hint="اگر شخص در سایت نیست، یا نامش در منبع صورت دیگری دارد.">
                    <input type="text" name="author_name" id="author_name" value="{{ old('author_name') }}" maxlength="500" class="admin-field w-full">
                </x-admin.field>
            </div>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2" x-data="{ make: @js((bool) old('author_new')) }">
                <label class="flex items-center gap-2"><input type="checkbox" name="author_new" value="1" x-model="make" class="accent-cinnabar"> رکورد شخص تازه با این نام ساخته شود</label>
                <label x-show="make" x-cloak class="flex items-center gap-2">درگذشت (قمری)
                    <input type="number" name="author_death_year" value="{{ old('author_death_year') }}" min="1" max="1500" class="admin-field w-24 tabular-nums">
                </label>
            </div>
        </fieldset>

        {{-- 3. The work --}}
        <fieldset class="space-y-4">
            <legend class="rubric text-base">۳. اثر</legend>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-admin.field label="موضوع" for="subject_summary" error="subject_summary" hint="چند موضوع را با «،» جدا کنید؛ موضوع‌های شناختۀ سایت پیوند می‌گیرند.">
                    <input type="text" name="subject_summary" id="subject_summary" value="{{ old('subject_summary') }}" maxlength="500" list="subject-names" class="admin-field w-full">
                    <datalist id="subject-names">@foreach($subjects as $name)<option value="{{ $name }}">@endforeach</datalist>
                </x-admin.field>
                <x-admin.field label="زبان" for="language_summary" error="language_summary" hint="«فارسی»، «عربی، فارسی»">
                    <input type="text" name="language_summary" id="language_summary" value="{{ old('language_summary') }}" maxlength="500" list="language-names" class="admin-field w-full">
                    <datalist id="language-names">@foreach($languages as $name)<option value="{{ $name }}">@endforeach</datalist>
                </x-admin.field>
                <x-admin.field label="تاریخ تألیف" for="composition_date_raw" error="composition_date_raw" hint="«۱۰۸۴ق»، «قرن ۱۲»، «محتملاً قرن ۱۲»">
                    <input type="text" name="composition_date_raw" id="composition_date_raw" value="{{ old('composition_date_raw') }}" maxlength="300" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="محل تألیف" for="composition_place" error="composition_place">
                    <input type="text" name="composition_place" id="composition_place" value="{{ old('composition_place') }}" maxlength="300" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="قالب (نثر، نظم و…)" for="work_form" error="work_form">
                    <input type="text" name="work_form" id="work_form" value="{{ old('work_form') }}" maxlength="100" class="admin-field w-full">
                </x-admin.field>
            </div>
            <x-admin.field label="آغاز اثر" for="incipit_text" error="incipit_text">
                <textarea name="incipit_text" id="incipit_text" rows="2" class="admin-field w-full font-naskh">{{ old('incipit_text') }}</textarea>
            </x-admin.field>
            <x-admin.field label="انجام اثر" for="explicit_text" error="explicit_text">
                <textarea name="explicit_text" id="explicit_text" rows="2" class="admin-field w-full font-naskh">{{ old('explicit_text') }}</textarea>
            </x-admin.field>
            <x-admin.field label="معرفی اثر" for="description" error="description">
                <textarea name="description" id="description" rows="4" class="admin-field w-full leading-7">{{ old('description') }}</textarea>
            </x-admin.field>
        </fieldset>

        @include('admin._record-source', ['record' => 'اثر', 'picker' => $picker])

        <x-admin.form-actions :cancel="route('admin.dashboard')" submit="ساختن رکورد اثر" />
    </form>
</x-ui.card>
@endsection
