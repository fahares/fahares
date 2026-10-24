@extends('layouts.admin')

@section('title', 'ویرایش ' . $library->name)
@section('page_title', $library->name)
@section('breadcrumb')
    <a href="{{ route('admin.libraries.index') }}" class="hover:text-cinnabar">کتابخانه‌ها</a>
    <span>/</span>
    <span>ویرایش #{{ \App\Support\Fa::digits($library->id) }}</span>
@endsection
@section('page_actions')
    <x-ui.btn :href="route('libraries.show', $library)" target="_blank" size="sm">صفحه در سایت ↗</x-ui.btn>
@endsection

@section('content')
<x-ui.card class="max-w-2xl space-y-5 text-[13px]">
    <p class="text-muted">نسخه‌های ثبت‌شده: <b class="text-ink tabular-nums">{{ \App\Support\Fa::num($library->manuscripts_count) }}</b></p>

    <form action="{{ route('admin.libraries.update', $library) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
        @csrf
        @method('PUT')

        <x-admin.field label="نام کوتاه یا رایج" for="name" error="name">
            <input type="text" name="name" id="name" value="{{ old('name', $library->name) }}" required class="admin-field w-full font-bold">
        </x-admin.field>

        <x-admin.field label="نام رسمی و کامل" for="full_name">
            <input type="text" name="full_name" id="full_name" value="{{ old('full_name', $library->full_name) }}"
                   placeholder="مثال: کتابخانه، موزه و مرکز اسناد مجلس شورای اسلامی" class="admin-field w-full">
        </x-admin.field>

        <x-admin.field label="نام‌های دیگر" for="aliases" hint="نام پیشین یا نامی که در فهرست‌های قدیم آمده است؛ هر نام در یک سطر. تغییر نام یا شهر، نام کتابخانه را در همه‌ی نسخه‌هایش هم به‌روز می‌کند.">
            <textarea name="aliases" id="aliases" rows="2" class="admin-field w-full">{{ old('aliases', implode("\n", (array) ($library->aliases ?? []))) }}</textarea>
        </x-admin.field>

        <div class="grid grid-cols-2 gap-4">
            <x-admin.field label="شهر" for="city" error="city">
                <input type="text" name="city" id="city" value="{{ old('city', $library->city) }}" required class="admin-field w-full">
            </x-admin.field>
            <x-admin.field label="کشور" for="country" error="country">
                <input type="text" name="country" id="country" value="{{ old('country', $library->country) }}" required class="admin-field w-full">
            </x-admin.field>
        </div>

        <x-admin.field label="پیشینه و مشخصات (اختیاری)" for="description">
            <textarea name="description" id="description" rows="4" class="admin-field w-full leading-7">{{ old('description', $library->description) }}</textarea>
        </x-admin.field>

        <x-admin.field label="نشان (لوگو)" for="logo" error="logo" hint="PNG با زمینه‌ی شفاف بهتر است؛ JPG، WebP و GIF هم پذیرفته می‌شود (حداکثر ۲ مگابایت). نشان روی پلاکی با رنگ ثابت نمایش داده می‌شود.">
            @if($library->logo_url)
                <div class="flex items-center gap-4 pb-1">
                    <x-library-logo :library="$library" />
                    <label class="inline-flex items-center gap-1.5 text-muted cursor-pointer">
                        <input type="checkbox" name="remove_logo" value="1" class="accent-cinnabar"> حذف نشان
                    </label>
                </div>
            @endif
            <x-admin.file name="logo" accept="image/png,image/jpeg,image/webp,image/gif" />
        </x-admin.field>
        <label class="inline-flex items-center gap-1.5 cursor-pointer">
            <input type="hidden" name="logo_on_dark" value="0">
            <input type="checkbox" name="logo_on_dark" value="1" class="accent-cinnabar" @checked(old('logo_on_dark', $library->logo_on_dark))>
            نشان سفید یا روشن است؛ روی پلاک تیره نمایش داده شود
        </label>

        <x-admin.form-actions :cancel="route('admin.libraries.index')" />
    </form>
</x-ui.card>
@endsection
