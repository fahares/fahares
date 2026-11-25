@extends('layouts.admin')

{{-- «شخص تازه» (backlog item 161): the fields of the edit form, and where the person was read --}}
@php $picker = fn (string $field, string $type) => ['url' => route('suggestions.lookup'), 'type' => $type, 'picked' => $picked[$field] ?? null]; @endphp

@section('title', 'شخص تازه')
@section('page_title', 'شخص تازه')
@section('breadcrumb')
    <a href="{{ route('admin.people.index') }}" class="hover:text-cinnabar">اشخاص</a>
    <span>/</span>
    <span>شخص تازه</span>
@endsection

@section('content')
<x-ui.card class="max-w-3xl space-y-5 text-[13px]">
    <p class="text-muted leading-7">
        رکورد شخصی که در سایت نیست. پیش از ساختن، اشخاص هم‌نام سایت نشان داده می‌شوند.
        رکورد در لاگ ممیزی ثبت می‌شود و تا اثر یا نسخه‌ای به آن پیوند نخورده، از همان‌جا بازگرداندنی است.
    </p>

    @if($errors->any())
        <x-admin.notice tone="error">{{ $errors->first() }}</x-admin.notice>
    @endif

    <form action="{{ route('admin.people.store') }}" method="POST" class="space-y-5">
        @csrf

        @if($duplicates->isNotEmpty())
            <x-admin.notice tone="warn" data-duplicate-warning>
                <p class="font-bold text-ink">شخص یا اشخاصی با همین نام یا یکی از نام‌های دیگر در سایت هستند:</p>
                @include('admin.people._namesakes', ['people' => $duplicates])
                <p class="mt-1 text-xs">اگر همان شخص است، به‌جای ساختن رکورد تازه، نام‌ها و تاریخ‌ها را در ویرایش همان شخص بیفزایید.</p>
                <label class="mt-2 flex items-center gap-2 font-bold text-ink">
                    <input type="checkbox" name="confirm_duplicate" value="1" class="accent-cinnabar">
                    شخص دیگری است؛ ساخته شود.
                </label>
            </x-admin.notice>
        @endif

        <x-admin.field label="نام کامل و شهرت معیار" for="name" error="name">
            <input type="text" name="name" id="name" value="{{ old('name') }}" maxlength="600" required class="admin-field w-full font-bold">
        </x-admin.field>

        <x-admin.field label="نویسه‌گردانی لاتین" for="transliteration">
            <input type="text" name="transliteration" id="transliteration" value="{{ old('transliteration') }}" dir="ltr" class="admin-field w-full text-left">
        </x-admin.field>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-admin.field label="نام‌های دیگر (شهرت، لقب، کنیه؛ هر نام در یک سطر؛ آوانگاری پس از «|»: «مجنون مشهدی | majnūn-e mašhadī»)" for="aliases" error="aliases">
                <textarea name="aliases" id="aliases" rows="4" class="admin-field w-full leading-7">{{ old('aliases') }}</textarea>
            </x-admin.field>
            <x-admin.field label="صورت‌های نادرست (فقط برای جست‌وجو؛ هر نام در یک سطر)" for="wrong_names" error="wrong_names">
                <textarea name="wrong_names" id="wrong_names" rows="4" class="admin-field w-full leading-7">{{ old('wrong_names') }}</textarea>
            </x-admin.field>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-admin.field label="ولادت (قمری)" for="birth_year_hijri" error="birth_year_hijri">
                <input type="number" name="birth_year_hijri" id="birth_year_hijri" value="{{ old('birth_year_hijri') }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
            <x-admin.field label="درگذشت (قمری)" for="death_year_hijri" error="death_year_hijri">
                <input type="number" name="death_year_hijri" id="death_year_hijri" value="{{ old('death_year_hijri') }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
            <x-admin.field label="قرن (قمری)" for="century_hijri" error="century_hijri">
                <input type="number" name="century_hijri" id="century_hijri" min="1" max="15" value="{{ old('century_hijri') }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
            <x-admin.field label="نخستین سدۀ زندگی" for="century_from" error="century_from">
                <input type="number" name="century_from" id="century_from" min="1" max="15" value="{{ old('century_from') }}" class="admin-field w-full tabular-nums"
                       title="برای کسی که در دو سده زیسته و سال ولادتش دانسته نیست. با سال ولادت، از همان به دست می‌آید.">
            </x-admin.field>
            <x-admin.field label="درگذشت (میلادی)" for="death_year_gregorian" error="death_year_gregorian">
                <input type="number" name="death_year_gregorian" id="death_year_gregorian" value="{{ old('death_year_gregorian') }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
        </div>

        <x-admin.field label="زیست‌نامه و یادداشت‌های پژوهشی" for="bio_notes" error="bio_notes">
            <textarea name="bio_notes" id="bio_notes" rows="5" class="admin-field w-full leading-7">{{ old('bio_notes') }}</textarea>
        </x-admin.field>

        @include('admin._record-source', ['record' => 'شخص', 'picker' => $picker])

        <x-admin.form-actions :cancel="route('admin.people.index')" submit="ساختن رکورد شخص" />
    </form>
</x-ui.card>
@endsection
