@extends('layouts.admin')

@section('title', 'بررسی پیوند خودکار اثر')
@section('page_title', 'بررسی پیوند خودکار اثر')

@section('content')
@php
    use App\Http\Controllers\Admin\CatalogReferenceController as Refs;
    use App\Http\Controllers\Admin\SourceLinkController as Queue;
    use App\Models\SourceEntry;
    use App\Support\Fa;
    $work = $entry->citable;
    $item = $entry->sourceItem;
    $unit = $entry->source?->unit === 'ستون' ? 'ستون' : 'ص';
@endphp

<div class="flex flex-wrap items-center gap-2 mb-4 text-[13px]">
    <x-ui.btn size="sm" variant="ghost" :href="route('admin.source-links.index', $query)">بازگشت به صف</x-ui.btn>
    @if($next)
        <x-ui.btn size="sm" variant="ghost" :href="route('admin.source-links.show', [$next] + $query)">پیوند بعدی بی‌داوری</x-ui.btn>
    @endif
    <span class="ms-auto text-xs text-muted">{{ $entry->sourceName() }}، {{ Queue::methodLabel($entry->match_method) }}، اطمینان {{ Fa::digits($entry->confidence) }}٪</span>
</div>

<div class="grid gap-5 lg:grid-cols-2">
    <div class="space-y-4 min-w-0">
        <section class="rounded-sm border border-line bg-surface p-4">
            <h2 class="text-xs text-muted mb-1">اثر</h2>
            @if($work)
                <a href="{{ route('works.show', $work) }}" target="_blank" class="text-base font-bold text-ink hover:text-cinnabar">{{ $work->primary_title }}</a>
                @if($work->author_name)<p class="text-[13px] text-muted mt-0.5">{{ $work->author_name }}</p>@endif
            @endif
            @if($entry->citation_text)
                <p class="text-xs text-muted mt-2">ارجاع فنخا: {{ Fa::digits($entry->citation_text) }}@if($cited)، {{ $unit }} {{ Fa::digits($cited) }}@endif</p>
            @endif
        </section>

        <section class="rounded-sm border border-line bg-surface p-4">
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <h2 class="text-xs text-muted">مدخل پیوندشده</h2>
                <x-ui.chip>{{ Queue::ROLES[$entry->role] ?? $entry->role }}</x-ui.chip>
                @if($entry->status !== SourceEntry::STATUS_UNVERIFIED)
                    <x-ui.chip :tone="$entry->status === 'verified' ? 'good' : 'accent'">{{ Refs::STATUSES[$entry->status] }}</x-ui.chip>
                    <span class="text-xs text-muted">{{ $entry->reviewer?->name }}@if($entry->reviewed_at)، {{ \App\Support\Jalali::format($entry->reviewed_at) }}@endif</span>
                @endif
            </div>
            @if($item)
                <a href="{{ $item->url() }}" target="_blank" class="font-bold text-cinnabar hover:underline">{{ $item->title ?: 'بی‌عنوان' }}</a>
                <span class="text-xs text-muted ms-2">{{ $item->placeLabel() }}</span>
                @if($item->author_text)<p class="text-[13px] text-muted mt-0.5">{{ $item->author_text }}</p>@endif
                <p class="mt-2 text-ink leading-7 whitespace-pre-line max-h-80 overflow-y-auto">{{ Fa::digits(trim((string) $item->text)) }}</p>
            @endif
            @if($entry->status === SourceEntry::STATUS_UNVERIFIED)
                <div class="flex flex-wrap gap-2 mt-4">
                    @foreach([SourceEntry::STATUS_VERIFIED => 'تأیید، همین مدخل است', SourceEntry::STATUS_REJECTED => 'رد، در این صفحه‌ها نیست'] as $value => $label)
                        <form method="POST" action="{{ route('admin.source-links.review', [$entry] + $query) }}">
                            @csrf
                            <input type="hidden" name="decision" value="{{ $value }}">
                            @if($value === SourceEntry::STATUS_VERIFIED)
                                <button type="submit" class="px-4 py-2 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white text-[13px] font-bold transition cursor-pointer">{{ $label }}</button>
                            @else
                                <x-ui.btn type="submit">{{ $label }}</x-ui.btn>
                            @endif
                        </form>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-sm border border-line bg-surface p-4">
            <h2 class="text-sm font-bold text-ink mb-1">مدخل دیگری است</h2>
            <p class="text-xs text-muted mb-3">مدخل‌های {{ $unit }} {{ Fa::digits($from) }} تا {{ Fa::digits($to) }} همین جلد. مدخل درست را برگزینید؛ پیوند به آن برده و تأیید می‌شود.</p>
            <form method="POST" action="{{ route('admin.source-links.move', [$entry] + $query) }}" class="space-y-2">
                @csrf
                <div class="max-h-[32rem] overflow-y-auto divide-y divide-line border border-line rounded-sm">
                    @forelse($candidates as $candidate)
                        @php $current = $candidate->id === $entry->source_item_id; @endphp
                        <label class="flex gap-2 p-2 cursor-pointer hover:bg-cinnabar/5 {{ $current ? 'bg-amber-500/10' : '' }}">
                            <input type="radio" name="item" value="{{ $candidate->id }}" class="mt-1 shrink-0" @disabled($current) required>
                            <span class="min-w-0">
                                <span class="font-bold text-ink">{{ $candidate->title ?: 'بی‌عنوان' }}</span>
                                <span class="text-xs text-muted ms-1">{{ $unit }} {{ Fa::digits($candidate->page_start) }}@if($candidate->entry_number)، ش {{ Fa::digits($candidate->entry_number) }}@endif</span>
                                @if($current)<span class="text-xs text-amber-800 dark:text-amber-300 ms-1">(پیوند کنونی)</span>@endif
                                @if($candidate->author_text)<span class="block text-xs text-muted">{{ $candidate->author_text }}</span>@endif
                                <span class="block text-xs text-ink/80 leading-6 line-clamp-2">{{ Queue::excerpt($candidate->text, 240) }}</span>
                            </span>
                        </label>
                    @empty
                        <p class="p-3 text-xs text-muted">مدخلی در این صفحه‌ها نیست.</p>
                    @endforelse
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select name="role" aria-label="نقش" class="admin-field">
                        <option value="{{ SourceEntry::ROLE_ENTRY }}">این مدخلِ خود اثر است</option>
                        <option value="{{ SourceEntry::ROLE_MENTION }}">اثر در متن این مدخل یاد شده</option>
                    </select>
                    <x-ui.btn type="submit">پیوند به مدخل برگزیده</x-ui.btn>
                </div>
            </form>
        </section>
    </div>

    <section class="rounded-sm border border-line bg-surface p-4 min-w-0">
        <h2 class="text-sm font-bold text-ink mb-1">متن صفحه‌ها</h2>
        <p class="text-xs text-muted mb-3">این مأخذ تصویر صفحه ندارد؛ متن خوانده‌شده‌ی صفحه‌ها به جای برگه.</p>
        @forelse($pageTexts as $page => $text)
            <details class="mb-2 border border-line rounded-sm" @if(in_array($page, [$entry->page_start, $cited], true)) open @endif>
                <summary class="px-3 py-1.5 cursor-pointer text-[13px] font-bold text-ink">
                    {{ $unit }} {{ Fa::digits($page) }}
                    @if($page === $entry->page_start)<span class="text-xs font-normal text-muted">، صفحه‌ی پیوند</span>@endif
                    @if($page === $cited)<span class="text-xs font-normal text-muted">، صفحه‌ی ارجاع فنخا</span>@endif
                </summary>
                <p class="px-3 pb-3 text-ink leading-7 whitespace-pre-line max-h-[36rem] overflow-y-auto">{{ Fa::digits(trim((string) $text)) }}</p>
            </details>
        @empty
            <p class="text-xs text-muted">متن صفحه‌ای ثبت نشده است.</p>
        @endforelse
    </section>
</div>
@endsection
