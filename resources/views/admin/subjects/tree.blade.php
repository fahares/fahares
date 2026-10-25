@extends('layouts.admin')

@section('title', 'درخت موضوعات')
@section('page_title', 'درخت موضوعات')
@section('breadcrumb')
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-cinnabar">موضوعات</a>
    <span>/</span>
    <span>نمای درختی</span>
@endsection
@section('page_actions')
    @can('subjects.merge')
        <x-ui.btn :href="route('admin.subjects.merge')" size="sm">ادغام موضوعات</x-ui.btn>
    @endcan
    <x-ui.btn :href="route('admin.subjects.index')" size="sm">نمای جدولی</x-ui.btn>
@endsection

@section('content')
@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $total = $rootSubjects->count() + $rootSubjects->sum(fn ($r) => $r->children->count());
@endphp
<div class="max-w-5xl space-y-4 text-[13px]" x-data>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-muted">{{ $fa($rootSubjects->count()) }} شاخه‌ی اصلی و {{ $fa($total) }} موضوع در دو سطح. هر شاخه را برای دیدن زیرشاخه‌هایش باز کنید.</p>
        <span class="flex gap-1.5">
            <x-ui.btn size="sm" variant="ghost" @click="$dispatch('tree-toggle', true)">باز کردن همه</x-ui.btn>
            <x-ui.btn size="sm" variant="ghost" @click="$dispatch('tree-toggle', false)">بستن همه</x-ui.btn>
        </span>
    </div>

    <div class="bg-surface border border-line divide-y divide-line">
        @foreach($rootSubjects as $root)
            <section x-data="{ open: true }" @tree-toggle.window="open = $event.detail">
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <button type="button" @click="open = !open" :aria-expanded="open" class="flex items-center gap-2 min-w-0 text-right cursor-pointer">
                        <svg :class="open && '-rotate-90'" class="size-4 shrink-0 text-muted transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        <span class="rubric text-base">{{ $root->name }}</span>
                        <span class="text-xs text-muted whitespace-nowrap">{{ $fa($root->works_count) }} اثر · {{ $fa($root->children->count()) }} زیرشاخه</span>
                    </button>
                    <x-ui.btn :href="route('admin.subjects.edit', $root)" size="sm" variant="ghost">ویرایش</x-ui.btn>
                </div>
                <div x-show="open" x-cloak class="px-4 pb-4 ps-10">
                    @if($root->children->isEmpty())
                        <p class="text-xs text-muted">زیرشاخه‌ای ندارد.</p>
                    @else
                        <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-1">
                            @foreach($root->children as $child)
                                <li class="flex items-baseline gap-2">
                                    <a href="{{ route('admin.subjects.edit', $child) }}" class="text-ink hover:text-cinnabar truncate" title="ویرایش {{ $child->name }}">{{ $child->name }}</a>
                                    <span class="leader"></span>
                                    <span class="text-xs text-muted tabular-nums">{{ $fa($child->works_count) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>
@endsection
