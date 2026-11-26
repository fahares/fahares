@extends('layouts.admin')

{{-- The works, for the panel (step 14.397); «اثر تازه» is made from here --}}
@php $fa = fn ($v) => \App\Support\Fa::digits($v); @endphp

@section('title', 'آثار')
@section('page_title', 'آثار')

@section('page_actions')
    @can('manuscripts.create')
        <x-ui.btn :href="route('admin.works.create')" variant="primary">اثر تازه</x-ui.btn>
    @endcan
@endsection

@section('content')
<x-admin.filters :action="route('admin.works.index')" :reset="($search || $filter) ? route('admin.works.index') : null" :total="$works->total()" noun="اثر">
    <input type="search" name="q" value="{{ $search }}" placeholder="عنوان، پدیدآور یا شناسه" aria-label="جست‌وجوی اثر" class="admin-field flex-1 basis-48">
    <select name="filter" aria-label="صافی" class="admin-field">
        <option value="">همۀ آثار</option>
        <option value="panel" @selected($filter === 'panel')>ساخته‌شده در پنل</option>
        <option value="no_copies" @selected($filter === 'no_copies')>بی نسخه</option>
    </select>
</x-admin.filters>

<x-admin.table :paginator="$works" :is-empty="$works->isEmpty()" empty="اثری با این مشخصات یافت نشد.">
    <x-slot:head>
        <x-admin.th>عنوان</x-admin.th>
        <x-admin.th>پدیدآور</x-admin.th>
        <x-admin.th>تاریخ تألیف</x-admin.th>
        <x-admin.th>نسخه‌ها</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($works as $w)
        <x-admin.row data-work-row="{{ $w->id }}">
            <x-admin.cell main>
                <a href="{{ route('works.show', $w) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $fa($w->primary_title) }}</a>
                <span class="text-xs text-muted tabular-nums">#{{ $fa($w->id) }}</span>
                @if($w->fankhaEntry === null)<x-ui.chip>ساخته‌شده در پنل</x-ui.chip>@endif
            </x-admin.cell>
            <x-admin.cell label="پدیدآور">{{ $w->author_name ? $fa($w->author_name) : '—' }}</x-admin.cell>
            <x-admin.cell label="تاریخ تألیف">{{ $w->composition_date_raw ? $fa($w->composition_date_raw) : '—' }}</x-admin.cell>
            <x-admin.cell label="نسخه‌ها" class="tabular-nums">{{ \App\Support\Fa::num($w->manuscripts_count) }}</x-admin.cell>
            <x-admin.cell actions>
                @can('structure.direct')
                    <x-ui.btn :href="route('admin.structure.show', ['work', $w->id])" size="sm" variant="ghost" title="ادغام، تفکیک، انتقال یا حذف، با پیش‌نمایش">ابزارهای ساختاری</x-ui.btn>
                @endcan
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection
