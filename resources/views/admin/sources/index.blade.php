@extends('layouts.admin')

@section('title', 'منابع')
@section('page_title', 'منابع')
@section('page_actions')
    @can('sources.patterns')
        <x-ui.btn :href="route('admin.sources.create')" size="sm" variant="primary">ثبت منبع تازه</x-ui.btn>
    @endcan
    @can('sources.merge')
        <x-ui.btn :href="route('admin.sources.merge')" size="sm" title="دو منبعی که یک کتاب‌اند، با پیش‌نمایش و برگشت‌پذیر">ادغام دو منبع</x-ui.btn>
    @endcan
    <x-ui.btn :href="route('sources.index')" target="_blank" size="sm">منابع در سایت ↗</x-ui.btn>
@endsection

@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $sel = fn ($key, $value) => (string) ($filters[$key] ?? '') === (string) $value;
    $filtered = collect($filters)->except('sort')->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp

@section('content')
<x-admin.filters :action="route('admin.sources.index')" :reset="$filtered ? route('admin.sources.index') : null" :total="$sources->total()" noun="منبع">
    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="عنوان، نام کوتاه، کد یا شناسه" aria-label="جست‌وجوی منبع" class="admin-field flex-1 basis-48">
    <select name="kind" aria-label="نوع" class="admin-field">
        <option value="">همه‌ی نوع‌ها</option>
        @foreach(\App\Models\Source::KIND_LABELS as $key => $label)
            <option value="{{ $key }}" @selected($sel('kind', $key))>{{ $label }}</option>
        @endforeach
    </select>
    <select name="rank" aria-label="رتبه" class="admin-field">
        <option value="">همه‌ی رتبه‌ها</option>
        @foreach($ranks as $rank)
            <option value="{{ $rank }}" @selected($sel('rank', $rank))>رتبه‌ی {{ $fa($rank) }}</option>
        @endforeach
    </select>
    <select name="library" aria-label="کتابخانه" class="admin-field max-w-56">
        <option value="">همه‌ی کتابخانه‌ها</option>
        @foreach($libraries as $lib)
            <option value="{{ $lib->id }}" @selected($sel('library', $lib->id))>{{ $lib->name }} ({{ $lib->city }})</option>
        @endforeach
    </select>
    <select name="volumes" aria-label="شمار جلد" class="admin-field">
        <option value="">هر شمار جلد</option>
        <option value="one" @selected($sel('volumes', 'one'))>تک‌جلدی</option>
        <option value="many" @selected($sel('volumes', 'many'))>چندجلدی</option>
        <option value="none" @selected($sel('volumes', 'none'))>بی‌جلد</option>
    </select>
    <select name="entries" aria-label="مدخل" class="admin-field">
        <option value="">با و بی مدخل</option>
        <option value="some" @selected($sel('entries', 'some'))>با مدخل</option>
        <option value="none" @selected($sel('entries', 'none'))>بی‌مدخل</option>
    </select>
    <select name="text" aria-label="متن صفحه‌ها" class="admin-field">
        <option value="">با و بی متن</option>
        <option value="yes" @selected($sel('text', 'yes'))>خواندنی (متن صفحه دارد)</option>
        <option value="no" @selected($sel('text', 'no'))>بی‌متن</option>
    </select>
    <select name="sort" aria-label="ترتیب" class="admin-field">
        @foreach(['entries' => 'بیشترین مدخل', 'copies' => 'بیشترین نسخه', 'volumes' => 'بیشترین جلد', 'rank' => 'رتبه', 'title' => 'عنوان'] as $key => $label)
            <option value="{{ $key }}" @selected($sort === $key)>ترتیب: {{ $label }}</option>
        @endforeach
    </select>
</x-admin.filters>

<x-admin.table :paginator="$sources" :is-empty="$sources->isEmpty()" empty="منبعی با این مشخصات یافت نشد.">
    <x-slot:head>
        <x-admin.th>عنوان</x-admin.th>
        <x-admin.th>نوع</x-admin.th>
        <x-admin.th>رتبه</x-admin.th>
        <x-admin.th>جلد</x-admin.th>
        <x-admin.th>بخش</x-admin.th>
        <x-admin.th>مدخل</x-admin.th>
        <x-admin.th>نسخه</x-admin.th>
        <x-admin.th>خواندنی</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($sources as $source)
        <x-admin.row>
            <x-admin.cell main class="md:max-w-md">
                <a href="{{ route('admin.sources.show', $source) }}" class="font-bold text-ink hover:text-cinnabar">{{ $source->title }}</a>
                <span class="text-xs text-muted tabular-nums">#{{ \App\Support\Fa::digits($source->id) }}</span>
                <span class="block text-xs text-muted">
                    <span dir="ltr">{{ $source->code }}</span>@if($source->library) · {{ $source->library->name }}، {{ $source->library->city }}@endif
                    @if($source->edition_label) · {{ $source->edition_label }}@endif
                </span>
            </x-admin.cell>
            <x-admin.cell label="نوع">{{ \App\Models\Source::KIND_LABELS[$source->kind] ?? $source->kind }}</x-admin.cell>
            <x-admin.cell label="رتبه" class="tabular-nums">{{ $fa($source->rank) }}</x-admin.cell>
            <x-admin.cell label="جلد" class="tabular-nums">{{ $fa($source->volumes_count) }}</x-admin.cell>
            <x-admin.cell label="بخش" class="tabular-nums">{{ $source->parts_count ? $fa($source->parts_count) : '—' }}</x-admin.cell>
            <x-admin.cell label="مدخل" class="tabular-nums">{{ $fa($source->entries_count) }}</x-admin.cell>
            <x-admin.cell label="نسخه" class="tabular-nums">{{ $fa($source->copies_count) }}</x-admin.cell>
            <x-admin.cell label="خواندنی" class="text-xs">
                @if($source->pages_count)
                    <span class="text-emerald-700 dark:text-emerald-300">{{ $fa($source->pages_count) }} صفحه</span>
                    @if($source->extractor)<span class="block text-muted" dir="ltr">{{ $source->extractor }}</span>@endif
                @else
                    <span class="text-muted">—</span>
                @endif
            </x-admin.cell>
            <x-admin.cell actions>
                <x-ui.btn :href="route('admin.sources.show', $source)" size="sm">ویرایش</x-ui.btn>
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection
