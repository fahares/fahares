@extends('layouts.admin')

@section('title', 'نشانی‌های خودکار فهرست')
@section('page_title', 'نشانی‌های خودکار فهرست')

@section('content')
@php
    use App\Http\Controllers\Admin\CatalogReferenceController as Queue;
    use App\Support\Fa;
    $methods = Queue::METHODS;
    // Blade directives do not run inside a component tag's attributes, so the token is prepared here
    $csrf = \Illuminate\Support\Js::from(csrf_token());
    $filters = array_filter(['volume' => request()->integer('volume') ?: null, 'method' => request('method'), 'confidence' => request('confidence'), 'opinions' => request('opinions'), 'entry' => request()->integer('entry') ?: null]);
    $reviewTitle = config('contributions.review_title');
    $tabs = [];
    foreach (Queue::STATUSES as $key => $label) {
        $tabs[] = ['label' => $label, 'url' => route('admin.catalog-references.index', ['status' => $key] + $filters), 'active' => $status === $key, 'count' => $counts[$key] ?? 0, 'key' => $key];
    }
@endphp

<p class="text-[13px] text-muted leading-7 mb-4 max-w-3xl">
    هوشواره این نسخه‌ها را با شمارۀ نسخه در چاپ دیگری از فهرست کتابخانه پیدا کرده است. هر نشانی را با برگه بسنجید:
    نمایشگر صفحۀ آغاز مدخل را باز می‌کند، و شمارۀ نسخه ممکن است در صفحۀ بعد باشد. با تأیید، نشان ⓘ از صفحۀ نسخه برداشته می‌شود و با رد، پیوند پنهان می‌شود.
</p>

<x-admin.tabs :options="$tabs" />

<x-admin.filters :action="route('admin.catalog-references.index')" :reset="$filters ? route('admin.catalog-references.index', ['status' => $status]) : null" :total="$references->total()" noun="نشانی">
    <input type="hidden" name="status" value="{{ $status }}">
    <select name="volume" aria-label="جلد" class="admin-field max-w-xs">
        <option value="">همۀ جلدها</option>
        @foreach($volumes as $volume)
            <option value="{{ $volume->id }}" @selected(request()->integer('volume') === $volume->id)>{{ $volume->title }}@if($volume->edition_label) (چاپ {{ $volume->edition_label }})@endif</option>
        @endforeach
    </select>
    <select name="method" aria-label="روش" class="admin-field">
        <option value="">همۀ روش‌ها</option>
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
    <select name="opinions" aria-label="رأی پژوهشگران" class="admin-field">
        <option value="">با رأی و بی‌رأی</option>
        @foreach(Queue::OPINIONS as $key => $label)
            <option value="{{ $key }}" @selected(request('opinions') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    @if(request()->integer('entry'))
        <input type="hidden" name="entry" value="{{ request()->integer('entry') }}">
    @endif
</x-admin.filters>

<div class="space-y-3 mb-4 text-[13px]">
    {{-- The researchers' votes of «تصحیح جمعی» are decided here, not on the suggestions desk (step 14.417) --}}
    @if($status === \App\Models\SourceEntry::STATUS_UNVERIFIED && $voted && request('opinions') !== 'consensus')
        <x-admin.notice class="flex flex-wrap items-center gap-3" data-collective-summary>
            <p class="flex-1 min-w-64">
                پژوهشگران در «{{ $reviewTitle }}» دربارۀ {{ Fa::num($voted) }} نشانی رأی داده‌اند؛ این نشانی‌ها در بالای صف‌اند و رأی‌ها زیر هر کدام آمده است.
                با تأیید یا رد نشانی، رأی‌های آن هم بسته می‌شوند.
                @if($agreed)
                    بر {{ Fa::num($agreed) }} نشانی اجماع است: رأی‌های «همین نسخه است» به وزن {{ Fa::num(\App\Review\CollectiveReview::ENOUGH) }} یا بیشتر رسیده و رأی مخالفی نیست.
                @endif
            </p>
            @if(request('opinions') !== 'any')
                <x-ui.btn :href="route('admin.catalog-references.index', ['opinions' => 'any'])">فقط نشانی‌های رأی‌دار</x-ui.btn>
            @endif
            @if($agreed)
                <x-ui.btn :href="route('admin.catalog-references.index', ['opinions' => 'consensus'])">نشانی‌های با اجماع</x-ui.btn>
            @endif
        </x-admin.notice>
    @endif
    {{-- Bulk verification (title matches only) and the revert of the last one --}}
    @if($status === \App\Models\SourceEntry::STATUS_UNVERIFIED && ! ($bulkAllowed && $references->total() === 0))
        <x-admin.notice :tone="$bulkAllowed ? 'good' : 'info'" class="flex flex-wrap items-center gap-3">
            @if($bulkAllowed)
                <p class="flex-1 min-w-64 text-ink">
                    <b>تأیید گروهی:</b>
                    @if(request('opinions') === 'consensus')
                        پژوهشگران بر این نشانی‌ها هم‌رأی‌اند که همین نسخه است و کسی مخالف نیست. نمونه‌ها را با برگه بسنجید و سپس همه را یک‌جا تأیید کنید؛ رأی‌هایشان هم پذیرفته می‌شود.
                    @else
                        شمارۀ نسخه و عنوان اثر هر دو با مدخل چاپ همخوان‌اند. نمونه‌ها را با برگه بسنجید و سپس همۀ نشانی‌های این پالایه را یک‌جا تأیید کنید.
                    @endif
                    آخرین تأیید گروهی برگشت‌پذیر است.
                </p>
                @php $count = Fa::num($references->total()); @endphp
                <form method="POST" action="{{ route('admin.catalog-references.bulk-verify') }}" onsubmit="return confirm(@js($count . ' تطبیق تأیید شود؟'))">
                    @csrf
                    <input type="hidden" name="volume" value="{{ request()->integer('volume') ?: '' }}">
                    <input type="hidden" name="method" value="{{ request('method') }}">
                    <input type="hidden" name="confidence" value="{{ request('confidence') }}">
                    <input type="hidden" name="opinions" value="{{ request('opinions') }}">
                    <input type="hidden" name="expected" value="{{ $references->total() }}">
                    <button type="submit" class="px-4 py-2 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">تأیید همۀ {{ $count }} نشانی این پالایه</button>
                </form>
            @else
                <p class="flex-1 min-w-64 text-muted">
                    تأیید گروهی فقط برای روش «شماره و عنوان» و برای نشانی‌های با اجماع پژوهشگران است. در روش‌های «شماره، عنوان نزدیک»، «فقط شماره» و «مبهم» عنوان شماره را تأیید نمی‌کند و هر نشانی باید جداگانه با برگه سنجیده شود.
                </p>
                <x-ui.btn :href="route('admin.catalog-references.index', array_filter(['volume' => request()->integer('volume') ?: null, 'method' => \App\Models\SourceEntry::BULK_METHOD, 'confidence' => 'high']))">پالایۀ پیشنهادی: شماره و عنوان، اطمینان بالا</x-ui.btn>
            @endif
        </x-admin.notice>
    @endif
    @if($lastBulk)
        <x-admin.notice class="flex flex-wrap items-center gap-3">
            <p class="flex-1 min-w-64">
                آخرین تأیید گروهی (دستۀ #{{ Fa::digits($lastBulk->id) }}): {{ Fa::num($lastBulk->new_values['count'] ?? 0) }} نشانی،
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
        <x-admin.row x-data="referenceReview({ csrf: {{ $csrf }} })" ::class="status !== 'unverified' && 'opacity-60'">
            <x-admin.cell main class="md:min-w-48">
                @if($ref->citable)
                    <a href="{{ route('manuscripts.show', $ref->citable) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $ref->citable->work?->primary_title ?? 'بی‌عنوان' }}</a>
                    <span class="block text-xs text-muted">شمارۀ <span dir="ltr">{{ Fa::digits($ref->citable->shelfmark) }}</span></span>
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
                @if($item = $opinions['catalog:' . $ref->id] ?? null)
                    {{-- The researchers' votes (backlog item 80); deciding the match settles them (step 14.417) --}}
                    <span class="block mt-2 text-xs px-3 py-2 leading-6 bg-paper border border-s-4 border-gold" data-collective-opinions>
                        <b>{{ $reviewTitle }}:</b>
                        @foreach($item['answers'] as $a){{ $loop->first ? '' : '؛ ' }}{{ Fa::num($a['count']) }} نفر «{{ $a['label'] }}» (وزن {{ Fa::num($a['weight']) }})@endforeach
                        @foreach($item['answers'] as $a)
                            @foreach($a['opinions'] as $o)
                                <span class="block text-muted">
                                    {{ $o->user?->name ?? $o->guest_name }}: {{ $a['label'] }}@if($o->rationale_citation) — {{ \Illuminate\Support\Str::limit($o->rationale_citation, 200) }}@endif
                                    {{-- another page is a change of its own: it is applied from its suggestion's page --}}
                                    @if(str_starts_with($a['answer'], 'page:'))
                                        <a href="{{ route('admin.suggestions.show', $o) }}" class="text-cinnabar font-bold hover:underline">بررسی جابه‌جایی</a>
                                    @endif
                                </span>
                            @endforeach
                        @endforeach
                    </span>
                @endif
            </x-admin.cell>
            <x-admin.cell label="روش" class="whitespace-nowrap">
                <span class="font-bold text-ink">{{ $methods[$ref->match_method] ?? $ref->match_method }}</span>
                <span class="inline-block md:block md:w-fit mt-1 px-2 rounded-full border bg-surface text-xs font-bold tabular-nums {{ $tone }}" title="اطمینان">{{ Fa::digits($ref->confidence) }}٪</span>
            </x-admin.cell>
            <x-admin.cell actions>
                @if($ref->status === \App\Models\SourceEntry::STATUS_UNVERIFIED)
                    {{-- Sent without a reload; the forms still post on their own when scripts do not run --}}
                    <span x-show="status === 'unverified'" class="inline-flex items-center gap-1.5">
                        <form method="POST" action="{{ route('admin.catalog-references.verify', $ref) }}" @submit.prevent="send($el)">
                            @csrf
                            <button type="submit" :disabled="busy" class="px-3 py-1 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold transition cursor-pointer disabled:opacity-50">تأیید</button>
                        </form>
                        <form method="POST" action="{{ route('admin.catalog-references.reject', $ref) }}" @submit.prevent="send($el)">
                            @csrf
                            <x-ui.btn type="submit" size="sm" ::disabled="busy" class="disabled:opacity-50">رد</x-ui.btn>
                        </form>
                    </span>
                    <span x-show="status !== 'unverified'" x-cloak class="inline-flex items-center gap-1.5">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full border text-xs leading-5" :class="status === 'verified' ? 'border-emerald-600/40 text-emerald-700 dark:text-emerald-300 bg-emerald-600/5' : 'border-cinnabar/40 text-cinnabar bg-cinnabar/5'" x-text="label"></span>
                        <x-ui.btn size="sm" variant="ghost" @click="undo()" ::disabled="busy">بازگردانی</x-ui.btn>
                    </span>
                    <span x-show="error" x-cloak role="alert" class="block mt-1 text-xs text-cinnabar md:max-w-56 md:whitespace-normal" x-text="error"></span>
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
