@extends('layouts.admin')

@section('title', 'ویرایش ' . $cataloger->name)
@section('page_title', $cataloger->name)
@section('breadcrumb')
    <a href="{{ route('admin.catalogers.index') }}" class="hover:text-cinnabar">فهرست‌نگاران</a>
    <span>/</span>
    <span>ویرایش #{{ \App\Support\Fa::digits($cataloger->id) }}</span>
@endsection
@section('page_actions')
    <x-ui.btn :href="route('catalogers.show', $cataloger)" target="_blank" size="sm">صفحه در سایت ↗</x-ui.btn>
@endsection

@section('content')
<x-ui.card class="max-w-3xl space-y-5 text-[13px]">
    <div class="flex items-center gap-4">
        <span class="size-16 shrink-0 overflow-hidden rounded-sm border border-gold bg-paper grid place-items-center font-bold text-muted">
            @if($cataloger->avatar_url)
                <img src="{{ $cataloger->avatar_url }}" alt="" class="size-full object-cover">
            @else
                {{ mb_substr($cataloger->name, 0, 1) }}
            @endif
        </span>
        <p class="text-muted">نسخه‌ها: <b class="text-ink tabular-nums">{{ \App\Support\Fa::num($cataloger->manuscripts_count) }}</b></p>
    </div>

    <form action="{{ route('admin.catalogers.update', $cataloger) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
        @csrf
        @method('PUT')

        <x-admin.field label="نام رسمی" for="name" error="name">
            <input type="text" name="name" id="name" value="{{ old('name', $cataloger->name) }}" required class="admin-field w-full font-bold">
        </x-admin.field>

        <x-admin.field label="تصویر تازه (اختیاری)" for="avatar" error="avatar" hint="بهترین ابعاد: کادر مربعی ۱:۱ با چهره در میانه (حداکثر ۳ مگابایت).">
            <x-admin.file name="avatar" accept="image/*" />
        </x-admin.field>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-admin.field label="ولادت (شمسی)" for="birth_year_solar">
                <input type="number" name="birth_year_solar" id="birth_year_solar" value="{{ old('birth_year_solar', $cataloger->birth_year_solar) }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
            <x-admin.field label="درگذشت (شمسی)" for="death_year_solar">
                <input type="number" name="death_year_solar" id="death_year_solar" value="{{ old('death_year_solar', $cataloger->death_year_solar) }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
            <x-admin.field label="ولادت (قمری)" for="birth_year_hijri">
                <input type="number" name="birth_year_hijri" id="birth_year_hijri" value="{{ old('birth_year_hijri', $cataloger->birth_year_hijri) }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
            <x-admin.field label="درگذشت (قمری)" for="death_year_hijri">
                <input type="number" name="death_year_hijri" id="death_year_hijri" value="{{ old('death_year_hijri', $cataloger->death_year_hijri) }}" class="admin-field w-full tabular-nums">
            </x-admin.field>
        </div>

        <x-admin.field label="زیست‌نامه و کارنامه‌ی فهرست‌نگاری" for="bio">
            <textarea name="bio" id="bio" rows="6" class="admin-field w-full leading-7">{{ old('bio', $cataloger->bio) }}</textarea>
        </x-admin.field>

        <x-admin.form-actions :cancel="route('admin.catalogers.index')" />
    </form>
</x-ui.card>
@endsection
