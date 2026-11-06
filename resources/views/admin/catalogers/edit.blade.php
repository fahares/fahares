@extends('layouts.admin')

{{-- One form to create a cataloger (backlog item 40) and to edit one --}}
@php
    use App\Http\Controllers\Admin\CatalogerController as Form;

    $creating = ! $cataloger->exists;
    $meta = $cataloger->metadata ?? [];
    $fa = fn ($v) => \App\Support\Fa::digits($v);
@endphp

@section('title', $creating ? 'فهرست‌نگار تازه' : 'ویرایش ' . $cataloger->name)
@section('page_title', $creating ? 'فهرست‌نگار تازه' : $cataloger->name)
@section('breadcrumb')
    <a href="{{ route('admin.catalogers.index') }}" class="hover:text-cinnabar">فهرست‌نگاران</a>
    <span>/</span>
    <span>{{ $creating ? 'افزودن' : 'ویرایش #' . $fa($cataloger->id) }}</span>
@endsection
@section('page_actions')
    @unless($creating)
        <x-ui.btn :href="route('catalogers.show', $cataloger)" target="_blank" size="sm">صفحه در سایت ↗</x-ui.btn>
    @endunless
@endsection

@section('content')
<x-ui.card class="max-w-3xl space-y-5 text-[13px]">
    @unless($creating)
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
    @endunless

    @if($errors->any())
        <x-admin.notice tone="error">{{ $errors->first() }}</x-admin.notice>
    @endif

    <form action="{{ $creating ? route('admin.catalogers.store') : route('admin.catalogers.update', $cataloger) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
        @csrf
        @unless($creating) @method('PUT') @endunless

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <x-admin.field label="عنوان" for="title_prefix" error="title_prefix" hint="استاد، دکتر، علامه…">
                <input type="text" name="title_prefix" id="title_prefix" value="{{ old('title_prefix', $cataloger->title_prefix) }}" maxlength="100" class="admin-field w-full">
            </x-admin.field>
            <x-admin.field label="نام رسمی" for="name" error="name" class="sm:col-span-2">
                <input type="text" name="name" id="name" value="{{ old('name', $cataloger->name) }}" required maxlength="255" class="admin-field w-full font-bold">
            </x-admin.field>
            <x-admin.field label="شهرت" for="nickname" error="nickname" hint="حائری، دانش‌پژوه…">
                <input type="text" name="nickname" id="nickname" value="{{ old('nickname', $cataloger->nickname) }}" maxlength="150" class="admin-field w-full">
            </x-admin.field>
        </div>

        <x-admin.field :label="$creating ? 'تصویر (اختیاری)' : 'تصویر تازه (اختیاری)'" for="avatar" error="avatar" hint="بهترین ابعاد: کادر مربعی ۱:۱ با چهره در میانه (حداکثر ۳ مگابایت).">
            <x-admin.file name="avatar" accept="image/*" />
        </x-admin.field>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            @foreach(['birth_year_solar' => 'ولادت (شمسی)', 'death_year_solar' => 'درگذشت (شمسی)', 'birth_year_hijri' => 'ولادت (قمری)', 'death_year_hijri' => 'درگذشت (قمری)'] as $column => $label)
                <x-admin.field :label="$label" :for="$column" :error="$column">
                    <input type="number" name="{{ $column }}" id="{{ $column }}" value="{{ old($column, $cataloger->{$column}) }}" class="admin-field w-full tabular-nums">
                </x-admin.field>
            @endforeach
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach(Form::TEXT_META as $key => $label)
                <x-admin.field :label="$label" for="meta_{{ $key }}" error="meta.{{ $key }}">
                    <input type="text" name="meta[{{ $key }}]" id="meta_{{ $key }}" value="{{ old('meta.' . $key, $meta[$key] ?? '') }}" class="admin-field w-full">
                </x-admin.field>
            @endforeach
        </div>

        <x-admin.field label="زیست‌نامه و کارنامه‌ی فهرست‌نگاری" for="bio" error="bio">
            <textarea name="bio" id="bio" rows="6" class="admin-field w-full leading-7">{{ old('bio', $cataloger->bio) }}</textarea>
        </x-admin.field>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach(Form::LIST_META as $key => $label)
                <x-admin.field :label="$label" for="meta_{{ $key }}" error="meta.{{ $key }}" hint="هر مورد در یک سطر.">
                    <textarea name="meta[{{ $key }}]" id="meta_{{ $key }}" rows="4" class="admin-field w-full leading-7">{{ old('meta.' . $key, implode("\n", $meta[$key] ?? [])) }}</textarea>
                </x-admin.field>
            @endforeach
        </div>

        <x-admin.field label="منابع زندگی‌نامه" for="meta_sources" error="meta.sources" hint="هر منبع در یک سطر. برای پیوند، نشانی را پس از «|» بنویسید: «ویکی‌نور | https://…».">
            <textarea name="meta[sources]" id="meta_sources" rows="3" class="admin-field w-full leading-7">{{ old('meta.sources', Form::sourcesText($meta['sources'] ?? [])) }}</textarea>
        </x-admin.field>

        {{-- The person this cataloger is, among the authors and scribes: search by name, or type the id --}}
        <x-admin.field label="پیوند به شخص" for="person_id" error="person_id" hint="اگر این فهرست‌نگار در جدول اشخاص هم هست (مثلاً پدیدآور اثری است). خالی یعنی بدون پیوند.">
            <div x-data="{
                    q: '', results: [], id: @js(old('person_id', $cataloger->person_id)), label: @js(Form::personLabel((int) old('person_id', $cataloger->person_id))),
                    async search() {
                        if (this.q.trim().length < 2) { this.results = []; return; }
                        const r = await fetch(@js(route('suggestions.people')) + '?q=' + encodeURIComponent(this.q.trim()), { headers: { Accept: 'application/json' } });
                        this.results = r.ok ? await r.json() : [];
                    },
                    pick(p) { this.id = p.id; this.label = p.name + (p.years ? ' (' + p.years + ')' : ''); this.results = []; this.q = ''; },
                }" class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <input type="number" name="person_id" id="person_id" x-model="id" placeholder="شناسه" class="admin-field w-28 tabular-nums">
                    <span class="text-muted" x-text="label || (id ? '' : 'بدون پیوند')"></span>
                    <button type="button" x-show="id" @click="id = ''; label = null" class="text-xs text-cinnabar hover:underline cursor-pointer">برداشتن پیوند</button>
                </div>
                <input type="search" x-model="q" @input.debounce.300ms="search()" placeholder="جست‌وجوی نام در جدول اشخاص…" aria-label="جست‌وجوی شخص" class="admin-field w-full">
                <ul x-show="results.length" class="border border-line bg-surface divide-y divide-line">
                    <template x-for="p in results" :key="p.id">
                        <li><button type="button" @click="pick(p)" class="w-full text-start px-3 py-1.5 hover:bg-paper cursor-pointer">
                            <b x-text="p.name"></b> <span class="text-xs text-muted" x-text="(p.years || '') + ' · #' + p.id"></span>
                        </button></li>
                    </template>
                </ul>
            </div>
        </x-admin.field>

        <x-admin.form-actions :cancel="route('admin.catalogers.index')" :submit="$creating ? 'ساخت فهرست‌نگار' : 'ذخیره‌ی تغییرات'" />
    </form>
</x-ui.card>
@endsection
