@extends('layouts.admin')

{{-- The copies, for the panel (step 14.397); «نسخۀ تازه» is made from here --}}
@php $fa = fn ($v) => \App\Support\Fa::digits($v); @endphp

@section('title', 'نسخه‌ها')
@section('page_title', 'نسخه‌ها')

@section('page_actions')
    @can('manuscripts.create')
        <x-ui.btn :href="route('admin.manuscripts.create')" variant="primary">نسخۀ تازه</x-ui.btn>
    @endcan
@endsection

@section('content')
<x-admin.filters :action="route('admin.manuscripts.index')" :reset="($search || $filter || $picked['library_id']) ? route('admin.manuscripts.index') : null" :total="$manuscripts->total()" noun="نسخه">
    <div class="flex-1 basis-56">
        <x-admin.record-picker name="library_id" :setup="['url' => route('suggestions.lookup'), 'type' => 'library', 'picked' => $picked['library_id']]" placeholder="کتابخانه…" />
    </div>
    <input type="search" name="q" value="{{ $search }}" placeholder="شمارۀ نسخه؛ با کتابخانه، عنوان اثر هم" aria-label="جست‌وجوی نسخه" class="admin-field flex-1 basis-48"
           title="شمارۀ نسخه، یا «#شناسه» یا نشانی صفحۀ نسخه. اگر کتابخانه انتخاب شده باشد، عنوان اثر هم جست‌وجو می‌شود.">
    <select name="filter" aria-label="صافی" class="admin-field">
        <option value="">همۀ نسخه‌ها</option>
        <option value="panel" @selected($filter === 'panel')>ساخته‌شده در پنل</option>
    </select>
</x-admin.filters>

<x-admin.table :paginator="$manuscripts" :is-empty="$manuscripts->isEmpty()" empty="نسخه‌ای با این مشخصات یافت نشد.">
    <x-slot:head>
        <x-admin.th>کتابخانه و شماره</x-admin.th>
        <x-admin.th>اثر</x-admin.th>
        <x-admin.th>کاتب و تاریخ کتابت</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($manuscripts as $m)
        <x-admin.row data-manuscript-row="{{ $m->id }}">
            <x-admin.cell main>
                <a href="{{ route('manuscripts.show', $m) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $fa(collect([$m->library ?: 'بی کتابخانه', $m->shelfmark])->filter()->implode('، ')) }}</a>
                @if($m->rangeLabel())<span class="text-xs text-muted">{{ $fa($m->rangeLabel()) }}</span>@endif
                <span class="text-xs text-muted tabular-nums">#{{ $fa($m->id) }}</span>
                @if($m->catalog_id === null)<x-ui.chip>ساخته‌شده در پنل</x-ui.chip>@endif
            </x-admin.cell>
            <x-admin.cell label="اثر">
                @if($m->work)
                    <a href="{{ route('works.show', $m->work) }}" target="_blank" class="hover:text-cinnabar">{{ $fa($m->work->primary_title) }}</a>
                    @if($m->work->author_name)<span class="block text-xs text-muted">{{ $fa($m->work->author_name) }}</span>@endif
                @else
                    —
                @endif
            </x-admin.cell>
            <x-admin.cell label="کتابت">{{ $fa(collect([$m->scribe_name, $m->copy_date_raw])->filter()->implode('، ')) ?: '—' }}</x-admin.cell>
            <x-admin.cell actions>
                @can('manuscripts.create')
                    <x-ui.btn :href="route('admin.manuscripts.create', ['from' => $m->id])" size="sm" variant="ghost" title="رساله‌ای دیگر از همین مجموعه">جزء دیگر</x-ui.btn>
                @endcan
                @can('structure.direct')
                    <x-ui.btn :href="route('admin.structure.show', ['manuscript', $m->id])" size="sm" variant="ghost" title="ادغام، انتقال یا حذف، با پیش‌نمایش">ابزارهای ساختاری</x-ui.btn>
                @endcan
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection
