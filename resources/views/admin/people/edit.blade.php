@extends('layouts.admin')

@php $fa = fn ($v) => \App\Support\Fa::digits($v); @endphp

@section('title', 'ویرایش ' . $person->name)
@section('page_title', $person->name)
@section('breadcrumb')
    <a href="{{ route('admin.people.index') }}" class="hover:text-cinnabar">اشخاص</a>
    <span>/</span>
    <span>ویرایش #{{ $fa($person->id) }}</span>
@endsection
@section('page_actions')
    @can('structure.direct')
        <x-ui.btn :href="route('admin.structure.show', ['person', $person->id])" size="sm" title="تفکیک، حذف رکورد زائد یا نام دیگر، با پیش‌نمایش">ابزارهای ساختاری</x-ui.btn>
    @endcan
    @can('people.merge')
        <x-ui.btn :href="route('admin.people.merge', ['source_id' => $person->id])" size="sm" title="ادغام این شخص در شخص دیگر">ادغام با شخص دیگر</x-ui.btn>
    @endcan
    <x-ui.btn :href="route('people.show', $person)" target="_blank" size="sm">صفحه در سایت ↗</x-ui.btn>
@endsection

@section('content')
<x-ui.card class="max-w-3xl space-y-5 text-[13px]">
    <ul class="flex flex-wrap gap-x-6 gap-y-1 text-muted">
        <li>آثار: <b class="text-ink tabular-nums">{{ \App\Support\Fa::num($person->works_count) }}</b></li>
        <li>نسخه‌های کتابت: <b class="text-ink tabular-nums">{{ \App\Support\Fa::num($person->manuscripts_count) }}</b></li>
    </ul>

    <form action="{{ route('admin.people.update', $person) }}" method="POST" class="space-y-5">
        @csrf
        @method('PUT')

        <x-admin.field label="نام کامل و شهرت معیار" for="name" error="name">
            <input type="text" name="name" id="name" value="{{ old('name', $person->name) }}" required class="admin-field w-full font-bold">
        </x-admin.field>

        <x-admin.field label="نویسه‌گردانی لاتین" for="transliteration">
            <input type="text" name="transliteration" id="transliteration" value="{{ old('transliteration', $person->transliteration) }}" dir="ltr" class="admin-field w-full text-left">
        </x-admin.field>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-admin.field label="ولادت (قمری)" for="birth_year_hijri">
                <input type="number" name="birth_year_hijri" id="birth_year_hijri" value="{{ old('birth_year_hijri', $person->birth_year_hijri) }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
            <x-admin.field label="درگذشت (قمری)" for="death_year_hijri">
                <input type="number" name="death_year_hijri" id="death_year_hijri" value="{{ old('death_year_hijri', $person->death_year_hijri) }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
            <x-admin.field label="قرن (قمری)" for="century_hijri">
                <input type="number" name="century_hijri" id="century_hijri" min="1" max="15" value="{{ old('century_hijri', $person->century_hijri) }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
            <x-admin.field label="درگذشت (میلادی)" for="death_year_gregorian">
                <input type="number" name="death_year_gregorian" id="death_year_gregorian" value="{{ old('death_year_gregorian', $person->death_year_gregorian) }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
        </div>

        <fieldset class="flex flex-wrap items-center gap-6">
            <legend class="sr-only">نقش</legend>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="is_author" value="1" @checked($person->is_author) class="size-4 accent-cinnabar"> مؤلف
            </label>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="is_scribe" value="1" @checked($person->is_scribe) class="size-4 accent-cinnabar"> کاتب
            </label>
        </fieldset>

        <x-admin.field label="زیست‌نامه و یادداشت‌های پژوهشی" for="bio_notes">
            <textarea name="bio_notes" id="bio_notes" rows="5" class="admin-field w-full leading-7">{{ old('bio_notes', $person->bio_notes) }}</textarea>
        </x-admin.field>

        <x-admin.form-actions :cancel="route('admin.people.index')" />
    </form>
</x-ui.card>
@endsection
