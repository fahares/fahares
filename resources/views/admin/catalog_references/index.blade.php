@extends('layouts.admin')

@section('title', 'نشانی‌های خودکار فهرست')
@section('page_title', 'داوری نشانی‌های خودکار فهرست')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">نشانی‌های خودکار فهرست</span>
@endsection

@section('content')
@php
    use App\Http\Controllers\Admin\CatalogReferenceController as Queue;
    use App\Support\Fa;
    $methods = Queue::METHODS;
@endphp
<div class="space-y-6">
    <p class="text-xs text-stone-500 leading-6">
        هوشواره این نسخه‌ها را با شماره‌ی نسخه در چاپ دیگری از فهرست کتابخانه پیدا کرده است. هر نشانی را با برگه بسنجید:
        نمایشگر صفحه‌ی آغاز مدخل را باز می‌کند، و شماره‌ی نسخه ممکن است در صفحه‌ی بعد باشد. با تأیید، نشان ⓘ از صفحه‌ی نسخه برداشته می‌شود و با رد، پیوند پنهان می‌شود.
    </p>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-line pb-4 text-xs">
        <label class="grid gap-1">
            <span class="font-bold text-stone-500">وضع</span>
            <select name="status" class="p-2 rounded-xl border border-line-strong bg-paper text-ink">
                @foreach(Queue::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }} ({{ Fa::num($counts[$key] ?? 0) }})</option>
                @endforeach
            </select>
        </label>
        <label class="grid gap-1">
            <span class="font-bold text-stone-500">جلد</span>
            <select name="volume" class="p-2 rounded-xl border border-line-strong bg-paper text-ink max-w-xs">
                <option value="">همه</option>
                @foreach($volumes as $volume)
                    <option value="{{ $volume->id }}" @selected(request()->integer('volume') === $volume->id)>{{ $volume->title }}@if($volume->edition_label) (چاپ {{ $volume->edition_label }})@endif</option>
                @endforeach
            </select>
        </label>
        <label class="grid gap-1">
            <span class="font-bold text-stone-500">روش</span>
            <select name="method" class="p-2 rounded-xl border border-line-strong bg-paper text-ink">
                <option value="">همه</option>
                @foreach($methods as $key => $label)
                    <option value="{{ $key }}" @selected(request('method') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="grid gap-1">
            <span class="font-bold text-stone-500">اطمینان</span>
            <select name="confidence" class="p-2 rounded-xl border border-line-strong bg-paper text-ink">
                <option value="">همه</option>
                @foreach(Queue::CONFIDENCE_BANDS as $key => $band)
                    <option value="{{ $key }}" @selected(request('confidence') === $key)>{{ $band['label'] }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="px-4 py-2 rounded-xl bg-stone-800 text-white font-bold hover:bg-cinnabar transition cursor-pointer">پالایش</button>
        <span class="ms-auto font-bold text-ink">{{ Fa::num($references->total()) }} نشانی</span>
    </form>

    <!-- Bulk verification (title matches only) and the revert of the last one -->
    @if($status === \App\Models\SourceEntry::STATUS_UNVERIFIED && ! ($bulkAllowed && $references->total() === 0))
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border p-4 text-xs leading-6 {{ $bulkAllowed ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30' : 'border-line bg-paper' }}">
            @if($bulkAllowed)
                <div class="flex-1 min-w-64 text-ink">
                    <span class="font-bold">تأیید گروهی:</span>
                    شماره‌ی نسخه و عنوان اثر هر دو با مدخل چاپ همخوان‌اند. نمونه‌ها را با برگه بسنجید و سپس همه‌ی نشانی‌های این پالایه را یک‌جا تأیید کنید. آخرین تأیید گروهی برگشت‌پذیر است.
                </div>
                @php $count = Fa::num($references->total()); @endphp
                <form method="POST" action="{{ route('admin.catalog-references.bulk-verify') }}" onsubmit="return confirm(@js($count . ' تطبیق تأیید شود؟'))">
                    @csrf
                    <input type="hidden" name="volume" value="{{ request()->integer('volume') ?: '' }}">
                    <input type="hidden" name="method" value="{{ request('method') }}">
                    <input type="hidden" name="confidence" value="{{ request('confidence') }}">
                    <input type="hidden" name="expected" value="{{ $references->total() }}">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 text-white font-bold hover:bg-emerald-700 transition cursor-pointer">تأیید همه‌ی {{ $count }} نشانی این پالایه</button>
                </form>
            @else
                <div class="flex-1 min-w-64 text-stone-500">
                    تأیید گروهی فقط برای روش «شماره و عنوان» است. در روش‌های «شماره، عنوان نزدیک»، «فقط شماره» و «مبهم» عنوان شماره را تأیید نمی‌کند و هر نشانی باید جداگانه با برگه سنجیده شود.
                </div>
                <a href="{{ route('admin.catalog-references.index', array_filter(['volume' => request()->integer('volume') ?: null, 'method' => \App\Models\ManuscriptCatalogReference::BULK_METHOD, 'confidence' => 'high'])) }}" class="px-4 py-2 rounded-xl border border-line-strong font-bold text-ink hover:text-cinnabar transition">پالایه‌ی پیشنهادی: شماره و عنوان، اطمینان بالا</a>
            @endif
        </div>
    @endif
    @if($lastBulk)
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-line bg-paper p-4 text-xs leading-6 text-stone-600 dark:text-stone-300">
            <div class="flex-1 min-w-64">
                آخرین تأیید گروهی (دسته‌ی #{{ Fa::digits($lastBulk->id) }}): {{ Fa::num($lastBulk->new_values['count'] ?? 0) }} نشانی،
                به دست {{ $lastBulk->user?->name ?? '—' }}، {{ \App\Support\Jalali::format($lastBulk->created_at, 'datetime') }}.
            </div>
            <form method="POST" action="{{ route('admin.catalog-references.bulk-revert', $lastBulk) }}" onsubmit="return confirm('نشانی‌های این دسته به صف داوری برگردند؟ آن‌هایی که از آن پس جداگانه داوری شده‌اند دست نمی‌خورند.')">
                @csrf
                <button type="submit" class="px-4 py-2 rounded-xl bg-stone-100 dark:bg-stone-800 text-ink font-bold hover:bg-red-600 hover:text-white transition cursor-pointer">برگرداندن این دسته</button>
            </form>
        </div>
    @endif

    <div class="bg-surface rounded-2xl border border-line shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-paper text-stone-500 border-b border-line">
                    <tr>
                        <th class="p-3 font-bold">نسخه</th>
                        <th class="p-3 font-bold">مدخل در چاپ</th>
                        <th class="p-3 font-bold">روش و اطمینان</th>
                        <th class="p-3 font-bold text-center">داوری</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse($references as $ref)
                        @php $viewer = $ref->viewer(); @endphp
                        <tr class="align-top hover:bg-paper transition">
                            <td class="p-3 min-w-48">
                                @if($ref->citable)
                                    <a href="{{ route('manuscripts.show', $ref->citable) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $ref->citable->work?->primary_title ?? 'بی‌عنوان' }}</a>
                                    <div class="text-stone-500 mt-0.5">شماره‌ی <span dir="ltr" class="font-mono">{{ Fa::digits($ref->citable->shelfmark) }}</span></div>
                                @endif
                            </td>
                            <td class="p-3 min-w-72">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if($viewer)
                                        <button type="button" @click="$dispatch('open-catalog-viewer', @js($viewer))" class="font-bold text-cinnabar hover:underline cursor-pointer">ص {{ Fa::digits($ref->page_start) }}</button>
                                        @if($ref->shelfmark_page && $ref->shelfmark_page !== $ref->page_start)
                                            <button type="button" @click="$dispatch('open-catalog-viewer', @js(['page' => $ref->shelfmark_page] + $viewer))" class="text-stone-500 hover:text-cinnabar cursor-pointer">شماره در ص {{ Fa::digits($ref->shelfmark_page) }}</button>
                                        @endif
                                    @endif
                                    @if($ref->entry_number)<span class="text-stone-400">مدخل {{ Fa::digits($ref->entry_number) }}</span>@endif
                                </div>
                                <div class="text-[11px] text-stone-400 mt-0.5">{{ $ref->sourceName() }}، ج {{ Fa::digits($ref->volumeNumber()) }}</div>
                                @if($ref->citation_text)
                                    <div class="mt-1.5 text-ink/80 leading-6 whitespace-pre-line line-clamp-3">{{ $ref->citation_text }}</div>
                                @endif
                                @if($ref->notes)
                                    <div class="mt-1 text-amber-700 dark:text-amber-400">{{ $ref->notes }}</div>
                                @endif
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                <div class="font-bold text-ink">{{ $methods[$ref->match_method] ?? $ref->match_method }}</div>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded-full font-mono font-bold text-[11px] {{ $ref->confidence >= 60 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300' : ($ref->confidence >= 35 ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300' : 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-300') }}">{{ Fa::digits($ref->confidence) }}٪</span>
                            </td>
                            <td class="p-3 text-center whitespace-nowrap">
                                @if($ref->status === \App\Models\SourceEntry::STATUS_UNVERIFIED)
                                    <div class="flex items-center justify-center gap-2">
                                        <form method="POST" action="{{ route('admin.catalog-references.verify', $ref) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-600 text-white font-bold hover:bg-emerald-700 transition cursor-pointer">تأیید</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.catalog-references.reject', $ref) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 text-ink font-bold hover:bg-red-600 hover:text-white transition cursor-pointer">رد</button>
                                        </form>
                                    </div>
                                @else
                                    <div class="font-bold {{ $ref->status === 'verified' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400' }}">{{ Queue::STATUSES[$ref->status] }}</div>
                                    <div class="text-[11px] text-stone-400 mt-0.5">{{ $ref->reviewer?->name }}@if($ref->reviewed_at)، {{ \App\Support\Jalali::format($ref->reviewed_at) }}@endif</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-8 text-center text-stone-500">نشانی‌ای با این پالایه نیست.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $references->links() }}</div>
</div>

<x-catalog-viewer />
@endsection
