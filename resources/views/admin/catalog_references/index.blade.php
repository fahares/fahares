@extends('layouts.admin')

@section('title', 'نشانی‌های خودکار فهرست')
@section('page_title', 'نشانی‌های خودکار فهرست')

@section('content')
@php
    use App\Http\Controllers\Admin\CatalogReferenceController as Queue;
    use App\Support\Fa;
    $methods = Queue::METHODS;
    $filters = array_filter(['volume' => request()->integer('volume') ?: null, 'method' => request('method'), 'confidence' => request('confidence')]);
    $tabs = [];
    foreach (Queue::STATUSES as $key => $label) {
        $tabs[] = ['label' => $label, 'url' => route('admin.catalog-references.index', ['status' => $key] + $filters), 'active' => $status === $key, 'count' => $counts[$key] ?? 0];
    }
@endphp

<p class="text-[13px] text-muted leading-7 mb-4 max-w-3xl">
    هوشواره این نسخه‌ها را با شماره‌ی نسخه در چاپ دیگری از فهرست کتابخانه پیدا کرده است. هر نشانی را با برگه بسنجید:
    نمایشگر صفحه‌ی آغاز مدخل را باز می‌کند، و شماره‌ی نسخه ممکن است در صفحه‌ی بعد باشد. با تأیید، نشان ⓘ از صفحه‌ی نسخه برداشته می‌شود و با رد، پیوند پنهان می‌شود.
</p>

<x-admin.tabs :options="$tabs" />

<x-admin.filters :action="route('admin.catalog-references.index')" :reset="$filters ? route('admin.catalog-references.index', ['status' => $status]) : null" :total="$references->total()" noun="نشانی">
    <input type="hidden" name="status" value="{{ $status }}">
    <select name="volume" aria-label="جلد" class="admin-field max-w-xs">
        <option value="">همه‌ی جلدها</option>
        @foreach($volumes as $volume)
            <option value="{{ $volume->id }}" @selected(request()->integer('volume') === $volume->id)>{{ $volume->title }}@if($volume->edition_label) (چاپ {{ $volume->edition_label }})@endif</option>
        @endforeach
    </select>
    <select name="method" aria-label="روش" class="admin-field">
        <option value="">همه‌ی روش‌ها</option>
        @foreach($methods as $key => $label)
            <option value="{{ $key }}" @selected(request('method') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="confidence" aria-label="اطمینان" class="admin-field">
        <option value="">هر اطمینانی</option>
        @foreach(Queue::CONFIDENCE_BANDS as $key => $band)
            <option value="{{ $key }}" @selected(request('confidence') === $key)>{{ $band['label'] }}</option>
        @endforeach
    </select>
</x-admin.filters>

<div class="space-y-3 mb-4 text-[13px]">
    {{-- Bulk verification (title matches only) and the revert of the last one --}}
    @if($status === \App\Models\SourceEntry::STATUS_UNVERIFIED && ! ($bulkAllowed && $references->total() === 0))
        <x-admin.notice :tone="$bulkAllowed ? 'good' : 'info'" class="flex flex-wrap items-center gap-3">
            @if($bulkAllowed)
                <p class="flex-1 min-w-64 text-ink">
                    <b>تأیید گروهی:</b>
                    شماره‌ی نسخه و عنوان اثر هر دو با مدخل چاپ همخوان‌اند. نمونه‌ها را با برگه بسنجید و سپس همه‌ی نشانی‌های این پالایه را یک‌جا تأیید کنید. آخرین تأیید گروهی برگشت‌پذیر است.
                </p>
                @php $count = Fa::num($references->total()); @endphp
                <form method="POST" action="{{ route('admin.catalog-references.bulk-verify') }}" onsubmit="return confirm(@js($count . ' تطبیق تأیید شود؟'))">
                    @csrf
                    <input type="hidden" name="volume" value="{{ request()->integer('volume') ?: '' }}">
                    <input type="hidden" name="method" value="{{ request('method') }}">
                    <input type="hidden" name="confidence" value="{{ request('confidence') }}">
                    <input type="hidden" name="expected" value="{{ $references->total() }}">
                    <button type="submit" class="px-4 py-2 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">تأیید همه‌ی {{ $count }} نشانی این پالایه</button>
                </form>
            @else
                <p class="flex-1 min-w-64 text-muted">
                    تأیید گروهی فقط برای روش «شماره و عنوان» است. در روش‌های «شماره، عنوان نزدیک»، «فقط شماره» و «مبهم» عنوان شماره را تأیید نمی‌کند و هر نشانی باید جداگانه با برگه سنجیده شود.
                </p>
                <x-ui.btn :href="route('admin.catalog-references.index', array_filter(['volume' => request()->integer('volume') ?: null, 'method' => \App\Models\ManuscriptCatalogReference::BULK_METHOD, 'confidence' => 'high']))">پالایه‌ی پیشنهادی: شماره و عنوان، اطمینان بالا</x-ui.btn>
            @endif
        </x-admin.notice>
    @endif
    @if($lastBulk)
        <x-admin.notice class="flex flex-wrap items-center gap-3">
            <p class="flex-1 min-w-64">
                آخرین تأیید گروهی (دسته‌ی #{{ Fa::digits($lastBulk->id) }}): {{ Fa::num($lastBulk->new_values['count'] ?? 0) }} نشانی،
                به دست {{ $lastBulk->user?->name ?? '—' }}، {{ \App\Support\Jalali::format($lastBulk->created_at, 'datetime') }}.
            </p>
            <form method="POST" action="{{ route('admin.catalog-references.bulk-revert', $lastBulk) }}" onsubmit="return confirm('نشانی‌های این دسته به صف داوری برگردند؟ آن‌هایی که از آن پس جداگانه داوری شده‌اند دست نمی‌خورند.')">
                @csrf
                <x-ui.btn type="submit">برگرداندن این دسته</x-ui.btn>
            </form>
        </x-admin.notice>
    @endif
</div>

<x-admin.table :paginator="$references" :is-empty="$references->isEmpty()" empty="نشانی‌ای با این پالایه نیست.">
    <x-slot:head>
        <x-admin.th>نسخه</x-admin.th>
        <x-admin.th>مدخل در چاپ</x-admin.th>
        <x-admin.th>روش و اطمینان</x-admin.th>
        <x-admin.th><span class="sr-only">داوری</span></x-admin.th>
    </x-slot:head>
    @foreach($references as $ref)
        @php
            $viewer = $ref->viewer();
            $tone = $ref->confidence >= 60 ? 'border-emerald-600/40 text-emerald-800 dark:text-emerald-300'
                : ($ref->confidence >= 35 ? 'border-amber-600/50 text-amber-800 dark:text-amber-300' : 'border-cinnabar/50 text-cinnabar');
        @endphp
        <x-admin.row>
            <x-admin.cell main class="md:min-w-48">
                @if($ref->citable)
                    <a href="{{ route('manuscripts.show', $ref->citable) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $ref->citable->work?->primary_title ?? 'بی‌عنوان' }}</a>
                    <span class="block text-xs text-muted">شماره‌ی <span dir="ltr">{{ Fa::digits($ref->citable->shelfmark) }}</span></span>
                @endif
            </x-admin.cell>
            <x-admin.cell class="basis-full md:min-w-72">
                <span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1">
                    @if($viewer)
                        <button type="button" @click="$dispatch('open-catalog-viewer', @js($viewer))" class="font-bold text-cinnabar hover:underline cursor-pointer">ص {{ Fa::digits($ref->page_start) }}</button>
                        @if($ref->shelfmark_page && $ref->shelfmark_page !== $ref->page_start)
                            <button type="button" @click="$dispatch('open-catalog-viewer', @js(['page' => $ref->shelfmark_page] + $viewer))" class="text-muted hover:text-cinnabar cursor-pointer">شماره در ص {{ Fa::digits($ref->shelfmark_page) }}</button>
                        @endif
                    @endif
                    @if($ref->entry_number)<span class="text-muted">مدخل {{ Fa::digits($ref->entry_number) }}</span>@endif
                </span>
                <span class="block text-xs text-muted mt-0.5">{{ $ref->sourceName() }}، ج {{ Fa::digits($ref->volumeNumber()) }}</span>
                @if($ref->citation_text)
                    <span class="block mt-1.5 text-ink leading-6 whitespace-pre-line line-clamp-3">{{ $ref->citation_text }}</span>
                @endif
                @if($ref->notes)
                    <span class="block mt-1 text-amber-800 dark:text-amber-300">{{ $ref->notes }}</span>
                @endif
            </x-admin.cell>
            <x-admin.cell label="روش" class="whitespace-nowrap">
                <span class="font-bold text-ink">{{ $methods[$ref->match_method] ?? $ref->match_method }}</span>
                <span class="inline-block md:block md:w-fit mt-1 px-2 rounded-full border bg-surface text-xs font-bold tabular-nums {{ $tone }}" title="اطمینان">{{ Fa::digits($ref->confidence) }}٪</span>
            </x-admin.cell>
            <x-admin.cell actions>
                @if($ref->status === \App\Models\SourceEntry::STATUS_UNVERIFIED)
                    <span class="inline-flex items-center gap-1.5">
                        <form method="POST" action="{{ route('admin.catalog-references.verify', $ref) }}">
                            @csrf
                            <button type="submit" class="px-3 py-1 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold transition cursor-pointer">تأیید</button>
                        </form>
                        <form method="POST" action="{{ route('admin.catalog-references.reject', $ref) }}">
                            @csrf
                            <x-ui.btn type="submit" size="sm">رد</x-ui.btn>
                        </form>
                    </span>
                @else
                    <x-ui.chip :tone="$ref->status === 'verified' ? 'good' : 'accent'">{{ Queue::STATUSES[$ref->status] }}</x-ui.chip>
                    <span class="block text-xs text-muted mt-0.5">{{ $ref->reviewer?->name }}@if($ref->reviewed_at)، {{ \App\Support\Jalali::format($ref->reviewed_at) }}@endif</span>
                @endif
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>

<x-catalog-viewer />
@endsection
