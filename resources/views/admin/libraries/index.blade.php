@extends('layouts.admin')

@section('title', 'کتابخانه‌ها')
@section('page_title', 'کتابخانه‌ها')

@section('content')
<x-admin.filters :action="route('admin.libraries.index')" :reset="($search || $city) ? route('admin.libraries.index') : null" :total="$libraries->total()" noun="کتابخانه">
    <input type="search" name="q" value="{{ $search }}" placeholder="نام کتابخانه یا مرکز" aria-label="جستجوی نام" class="admin-field flex-1 basis-48">
    <select name="city" aria-label="شهر" class="admin-field">
        <option value="">همه‌ی شهرها</option>
        @foreach($cities as $c)
            <option value="{{ $c }}" @selected($city === $c)>{{ $c }}</option>
        @endforeach
    </select>
</x-admin.filters>

<x-admin.table :paginator="$libraries" :is-empty="$libraries->isEmpty()" empty="کتابخانه‌ای با این مشخصات یافت نشد.">
    <x-slot:head>
        <x-admin.th>نام</x-admin.th>
        <x-admin.th>نام کامل</x-admin.th>
        <x-admin.th>شهر</x-admin.th>
        <x-admin.th>نسخه‌ها</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($libraries as $lib)
        <x-admin.row>
            <x-admin.cell main>
                <a href="{{ route('libraries.show', $lib) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $lib->name }}</a>
                <span class="text-xs text-muted tabular-nums">#{{ \App\Support\Fa::digits($lib->id) }}</span>
            </x-admin.cell>
            <x-admin.cell label="نام کامل" class="text-muted md:max-w-xs">{{ $lib->full_name ?: '—' }}</x-admin.cell>
            <x-admin.cell label="شهر">
                {{ $lib->city }}@if($lib->country)<span class="text-xs text-muted">، {{ $lib->country }}</span>@endif
            </x-admin.cell>
            <x-admin.cell label="نسخه‌ها" class="tabular-nums">{{ \App\Support\Fa::num($lib->manuscripts_count) }}</x-admin.cell>
            <x-admin.cell actions>
                <x-ui.btn :href="route('admin.libraries.edit', $lib)" size="sm">ویرایش</x-ui.btn>
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection
