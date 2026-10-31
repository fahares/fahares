@extends('layouts.admin')

@php $fa = fn ($v) => \App\Support\Fa::digits($v); @endphp

@section('title', 'اشخاص')
@section('page_title', 'اشخاص')

@can('people.merge')
    @section('page_actions')
        <x-ui.btn :href="route('admin.people.merge')">ادغام اشخاص تکراری</x-ui.btn>
    @endsection
@endcan

@section('content')
<x-admin.filters :action="route('admin.people.index')" :reset="($search || $role || $century) ? route('admin.people.index') : null" :total="$people->total()" noun="شخص">
    <input type="search" name="q" value="{{ $search }}" placeholder="نام، کنیه یا لقب" aria-label="جست‌وجوی نام" class="admin-field flex-1 basis-48">
    <select name="role" aria-label="نقش" class="admin-field">
        <option value="">همه‌ی نقش‌ها</option>
        <option value="author" @selected($role === 'author')>مؤلف</option>
        <option value="scribe" @selected($role === 'scribe')>کاتب</option>
    </select>
    <select name="century" aria-label="قرن" class="admin-field">
        <option value="">همه‌ی قرن‌ها</option>
        @for($c = 1; $c <= 15; $c++)
            <option value="{{ $c }}" @selected($century == $c)>قرن {{ $fa($c) }}</option>
        @endfor
    </select>
</x-admin.filters>

<x-admin.table :paginator="$people" :is-empty="$people->isEmpty()" empty="شخصی با این مشخصات یافت نشد.">
    <x-slot:head>
        <x-admin.th>نام</x-admin.th>
        <x-admin.th>درگذشت یا قرن</x-admin.th>
        <x-admin.th>نقش</x-admin.th>
        <x-admin.th>آثار</x-admin.th>
        <x-admin.th>نسخه‌های کتابت</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($people as $p)
        <x-admin.row>
            <x-admin.cell main>
                <a href="{{ route('people.show', $p) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $p->name }}</a>
                <span class="text-xs text-muted tabular-nums">#{{ $fa($p->id) }}</span>
                @if($p->transliteration)
                    <span class="block text-xs text-muted" dir="ltr">{{ $p->transliteration }}</span>
                @endif
            </x-admin.cell>
            <x-admin.cell label="درگذشت">
                @if($p->death_year_hijri)
                    {{ $fa($p->death_year_hijri) }} ق
                @elseif($p->century_hijri)
                    قرن {{ $fa($p->century_hijri) }}
                @else
                    <span class="text-muted">—</span>
                @endif
            </x-admin.cell>
            <x-admin.cell label="نقش" class="whitespace-nowrap">
                @if($p->is_author)<x-ui.chip>مؤلف</x-ui.chip>@endif
                @if($p->is_scribe)<x-ui.chip>کاتب</x-ui.chip>@endif
            </x-admin.cell>
            <x-admin.cell label="آثار" class="tabular-nums">{{ \App\Support\Fa::num($p->works_count) }}</x-admin.cell>
            <x-admin.cell label="نسخه‌ها" class="tabular-nums">{{ \App\Support\Fa::num($p->manuscripts_count) }}</x-admin.cell>
            <x-admin.cell actions>
                <x-ui.btn :href="route('admin.people.edit', $p)" size="sm">ویرایش</x-ui.btn>
                @can('people.merge')
                    <x-ui.btn :href="route('admin.people.merge', ['source_id' => $p->id])" size="sm" variant="ghost" title="ادغام این شخص در شخص دیگر">ادغام</x-ui.btn>
                @endcan
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection
