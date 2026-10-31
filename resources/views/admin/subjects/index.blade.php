@extends('layouts.admin')

@section('title', 'موضوعات')
@section('page_title', 'موضوعات')
@section('page_actions')
    <x-ui.btn :href="route('admin.subjects.tree')" size="sm">نمای درختی</x-ui.btn>
    @can('subjects.merge')
        <x-ui.btn :href="route('admin.subjects.merge')" size="sm">ادغام موضوعات</x-ui.btn>
    @endcan
    <form action="{{ route('admin.subjects.recount') }}" method="POST" onsubmit="return confirm('شمار آثار همه‌ی موضوعات دوباره حساب شود؟')">
        @csrf
        <x-ui.btn type="submit" size="sm" title="شمار آثار هر موضوع دوباره حساب می‌شود">بازشماری آثار</x-ui.btn>
    </form>
    <x-ui.btn :href="route('admin.subjects.create')" size="sm" variant="primary">موضوع تازه</x-ui.btn>
@endsection

@section('content')
<x-admin.filters :action="route('admin.subjects.index')" :reset="$search ? route('admin.subjects.index') : null" :total="$subjects->total()" noun="موضوع">
    <input type="search" name="q" value="{{ $search }}" placeholder="نام موضوع" aria-label="جست‌وجوی موضوع" class="admin-field flex-1 basis-48 max-w-sm">
</x-admin.filters>

<x-admin.table :paginator="$subjects" :is-empty="$subjects->isEmpty()" empty="موضوعی یافت نشد.">
    <x-slot:head>
        <x-admin.th>نام</x-admin.th>
        <x-admin.th>شاخه‌ی بالاتر</x-admin.th>
        <x-admin.th>زیرشاخه‌ها</x-admin.th>
        <x-admin.th>آثار</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($subjects as $subj)
        <x-admin.row>
            <x-admin.cell main>
                <a href="{{ route('subjects.show', $subj) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $subj->name }}</a>
                <span class="text-xs text-muted tabular-nums">#{{ \App\Support\Fa::digits($subj->id) }}</span>
                <span class="block text-xs text-muted" dir="ltr">{{ $subj->slug }}</span>
            </x-admin.cell>
            <x-admin.cell label="شاخه‌ی بالاتر">
                @if($subj->parent)
                    <x-ui.chip>{{ $subj->parent->name }}</x-ui.chip>
                @else
                    <span class="text-gold font-bold">شاخه‌ی اصلی</span>
                @endif
            </x-admin.cell>
            <x-admin.cell label="زیرشاخه‌ها" class="tabular-nums">{{ \App\Support\Fa::num($subj->children->count()) }}</x-admin.cell>
            <x-admin.cell label="آثار" class="tabular-nums">{{ \App\Support\Fa::num($subj->works_count) }}</x-admin.cell>
            <x-admin.cell actions>
                <x-ui.btn :href="route('admin.subjects.edit', $subj)" size="sm">ویرایش</x-ui.btn>
                @can('subjects.merge')
                    <x-ui.btn :href="route('admin.subjects.merge', ['source_subject_id' => $subj->id])" size="sm" variant="ghost" title="ادغام این موضوع در موضوع دیگر">ادغام</x-ui.btn>
                @endcan
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection
