@extends('layouts.admin')

@php
    $exists = $announcement->exists;
    $heading = $exists ? $announcement->title : 'اعلان تازه';
    $status = $exists ? $announcement->status() : 'off';
    $input = 'admin-field w-full';
@endphp

@section('title', $heading)
@section('page_title', $heading)
@section('breadcrumb')
    <a href="{{ route('admin.announcements.index') }}" class="hover:text-cinnabar">اعلان‌های سایت</a>
    <span>/</span>
    <span>{{ $exists ? 'ویرایش #' . \App\Support\Fa::digits($announcement->id) : 'اعلان تازه' }}</span>
@endsection
@if($exists)
    @section('page_actions')
        <x-ui.btn :href="route('home', ['announcement_preview' => $announcement->id])" target="_blank" size="sm">پیش‌نمایش در سایت ↗</x-ui.btn>
    @endsection
@endif

@section('content')
<div class="space-y-5 text-[13px] max-w-3xl">

    @if($errors->any())
        <x-admin.notice tone="error" class="space-y-1">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-admin.notice>
    @endif

    @if($exists)
        <p class="text-muted leading-7">
            @if($status === 'running')
                این اعلان روشن است و تا @jdate($announcement->ends_at, 'long') نشان داده می‌شود.
            @elseif($status === 'ended')
                دوره‌ی نمایش این اعلان در @jdate($announcement->ends_at, 'long') پایان یافت.
            @else
                این اعلان خاموش است و کسی آن را نمی‌بیند.
            @endif
            تاکنون {{ \App\Support\Fa::num($announcement->views_count) }} بار دیده شده@if($announcement->link_url) و {{ \App\Support\Fa::num($announcement->clicks_count) }} بار روی پیوندش کلیک شده@endif است.
        </p>
    @endif

    <form action="{{ $exists ? route('admin.announcements.update', $announcement) : route('admin.announcements.store') }}" method="POST" enctype="multipart/form-data" class="bg-surface border border-line p-4 sm:p-5 space-y-5">
        @csrf
        @if($exists) @method('PUT') @endif

        <x-admin.field label="عنوان" for="title" hint="کوتاه و گویا، مانند «کانال تلگرام فهارس راه افتاد».">
            <input type="text" name="title" id="title" value="{{ old('title', $announcement->title) }}" required maxlength="120" class="{{ $input }} text-base font-bold">
        </x-admin.field>

        <x-admin.field label="متن (اختیاری)" for="body" hint="یک تا سه جمله. متن ساده است و شکستن سطر در آن می‌ماند. اگر مطلب بلند است، آن را در «نوشته‌ها» بنویسید و اینجا به آن پیوند بدهید.">
            <textarea name="body" id="body" rows="3" maxlength="600" class="{{ $input }} leading-7">{{ old('body', $announcement->body) }}</textarea>
        </x-admin.field>

        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_200px]">
            <x-admin.field label="پیوند دکمه (اختیاری)" for="link_url" hint="نشانی صفحه‌ای از همین سایت، مانند ‎/posts/12‎، یا نشانی کامل با https://">
                <input type="text" name="link_url" id="link_url" dir="ltr" value="{{ old('link_url', $announcement->link_url) }}" maxlength="500" class="{{ $input }}" placeholder="/posts/12">
            </x-admin.field>
            <x-admin.field label="نوشته‌ی دکمه" for="link_label" hint="اگر خالی بماند: «بیشتر».">
                <input type="text" name="link_label" id="link_label" value="{{ old('link_label', $announcement->link_label) }}" maxlength="40" class="{{ $input }}" placeholder="بیشتر">
            </x-admin.field>
        </div>

        <x-admin.field :label="$announcement->image_path ? 'تصویر تازه (اختیاری)' : 'تصویر (اختیاری)'" for="image" hint="بالای اعلان می‌آید؛ تصویر افقی بهتر می‌نشیند. حداکثر ۲ مگابایت.">
            <x-admin.file name="image" accept="image/jpeg,image/png,image/webp" />
            @if($announcement->image_path)
                <div class="mt-2 flex items-center gap-3">
                    <img src="{{ $announcement->image_url }}" alt="" class="h-16 rounded-sm border border-line">
                    <label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" name="remove_image" value="1" class="accent-cinnabar"> حذف تصویر</label>
                </div>
            @endif
        </x-admin.field>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-admin.field label="شکل نمایش" for="style" hint="پنجره صفحه را می‌پوشاند؛ آن را برای خبرهای مهم و کم‌شمار نگه دارید.">
                <select name="style" id="style" class="{{ $input }}">
                    @foreach(\App\Models\Announcement::STYLES as $key => $label)
                        <option value="{{ $key }}" @selected(old('style', $announcement->style) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field label="مخاطب" for="audience">
                <select name="audience" id="audience" class="{{ $input }}">
                    @foreach(\App\Models\Announcement::AUDIENCES as $key => $label)
                        <option value="{{ $key }}" @selected(old('audience', $announcement->audience) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field label="مدت نمایش (روز)" for="days" hint="از لحظه‌ی روشن شدن شمرده می‌شود.">
                <input type="number" name="days" id="days" min="1" max="365" value="{{ old('days', $announcement->days) }}" required class="{{ $input }}" dir="ltr">
            </x-admin.field>
        </div>

        <div class="border-t border-line pt-4 space-y-1">
            <label class="inline-flex items-center gap-2 font-bold cursor-pointer">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $status === 'running') ? 'checked' : '' }} class="size-4 accent-cinnabar">
                روشن باشد
            </label>
            <p class="text-xs text-muted leading-6">با روشن شدن، دوره‌ی نمایش از همین لحظه آغاز می‌شود. کسی که اعلان را پیش‌تر دیده، با روشن شدنِ دوباره آن را نمی‌بیند.</p>
        </div>

        <x-admin.form-actions :cancel="route('admin.announcements.index')" :submit="$exists ? 'ذخیره‌ی تغییرات' : 'ذخیره'" />
    </form>

    @if($exists)
        <form action="{{ route('admin.announcements.destroy', $announcement) }}" method="POST" onsubmit="return confirm('این اعلان حذف شود؟ این کار برگشت‌پذیر نیست.')" class="bg-surface border border-line p-4 sm:p-5 flex items-center justify-between gap-3">
            @csrf @method('DELETE')
            <span class="text-muted">برای برداشتن موقت اعلان، خاموش کردنش کافی است.</span>
            <button type="submit" class="text-xs font-bold text-cinnabar hover:underline cursor-pointer">حذف اعلان</button>
        </form>
    @endif
</div>
@endsection
