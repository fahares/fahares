@extends('layouts.admin')

@section('title', 'فهرست‌نگاران')
@section('page_title', 'فهرست‌نگاران')

@section('content')
<p class="text-[13px] text-muted mb-4 max-w-3xl leading-7">
    استادان و پژوهشگرانی که نسخه‌ها را در فهرست‌های چاپی شناسانده‌اند. تصویر و شناسنامه‌ی هر کدام از اینجا ویرایش می‌شود.
    <b class="text-ink tabular-nums">{{ \App\Support\Fa::num($catalogers->count()) }}</b> فهرست‌نگار.
</p>

<x-admin.table :is-empty="$catalogers->isEmpty()" empty="فهرست‌نگاری ثبت نشده است.">
    <x-slot:head>
        <x-admin.th>نام</x-admin.th>
        <x-admin.th>سال‌های حیات</x-admin.th>
        <x-admin.th>جلدهای فهرست</x-admin.th>
        <x-admin.th>نسخه‌ها</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($catalogers as $cat)
        <x-admin.row>
            <x-admin.cell main>
                <span class="flex items-center gap-3">
                    <span class="size-10 shrink-0 overflow-hidden rounded-sm border border-line bg-paper grid place-items-center text-xs font-bold text-muted">
                        @if($cat->avatar_url)
                            <img src="{{ $cat->avatar_url }}" alt="" class="size-full object-cover" loading="lazy">
                        @else
                            {{ mb_substr($cat->name, 0, 1) }}
                        @endif
                    </span>
                    <span>
                        <a href="{{ route('catalogers.show', $cat) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $cat->name }}</a>
                        <span class="text-xs text-muted tabular-nums">#{{ \App\Support\Fa::digits($cat->id) }}</span>
                    </span>
                </span>
            </x-admin.cell>
            <x-admin.cell label="حیات">{{ $cat->life_years_text ? \App\Support\Fa::digits($cat->life_years_text) : '—' }}</x-admin.cell>
            <x-admin.cell label="جلدها" class="tabular-nums">{{ \App\Support\Fa::num($cat->volumes_count) }}</x-admin.cell>
            <x-admin.cell label="نسخه‌ها" class="tabular-nums">{{ \App\Support\Fa::num($cat->manuscripts_count) }}</x-admin.cell>
            <x-admin.cell actions>
                <x-ui.btn :href="route('admin.catalogers.edit', $cat)" size="sm">ویرایش</x-ui.btn>
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection
