@extends('layouts.admin')

{{-- The review desk (step 14.72): the record and field on top, the suggestion as one sentence with a word-level
     comparison, its evidence and submitter, the conversation, rare tools folded at the bottom, and the decision in a
     panel beside it that opens only the chosen form. --}}
@php
    use App\Suggestions\SuggestionRegistry;
    use App\Support\WordDiff;
    use Illuminate\Support\Str;

    $fa = fn ($v) => \App\Support\Fa::digits($v);
    [$statusText, $statusTone] = match (true) {
        $suggestion->status === 'pending' && $suggestion->awaiting_reply_since => ['منتظر پاسخ پیشنهاددهنده', 'default'],
        $suggestion->status === 'pending' && $suggestion->reply_state === 'answered' => ['در انتظار داوری، پیشنهاددهنده پاسخ داد', 'good'],
        $suggestion->status === 'pending' && $suggestion->reply_state === 'unanswered' => ['در انتظار داوری، پرسش بی‌پاسخ ماند', 'accent'],
        $suggestion->status === 'pending' => ['در انتظار داوری', 'accent'],
        $suggestion->status === 'approved' && ! empty($suggestion->payload['direct']) => ['اقدام مستقیم، به دست ' . ($suggestion->reviewer?->name ?: 'مدیر'), 'good'],
        $suggestion->status === 'approved' => [($suggestion->kind === 'note' ? 'منتشر شد' : 'تأیید شد') . '، به دست ' . ($suggestion->reviewer?->name ?: 'مدیر'), 'good'],
        $suggestion->status === 'undone' => ['اقدام مستقیم بازگردانده شد', 'default'],
        $suggestion->status === 'converted' => ['تبدیل شد', 'default'],
        $suggestion->status === 'withdrawn' => ['از صفحه برداشته شد', 'default'],
        default => ['رد شد، به دست ' . ($suggestion->reviewer?->name ?: 'مدیر'), 'default'],
    };

    $kind = $suggestion->kind;
    $payload = $suggestion->payload ?? [];
    $typeLabel = SuggestionRegistry::typeLabel($suggestion->suggestable_type);
    $submitterName = $suggestion->guest_name ?: ($suggestion->user?->name ?: 'پژوهشگر مهمان');
    $isAdmin = auth()->user()->isAdmin();
    $promoting = $suggestion->status === 'approved' && $suggestion->standing === 'alternative';

    // The record: its real title, not only «اثر #id»
    $recordTitle = match (true) {
        $kind === 'feedback' => $payload['title'] ?? $payload['url'] ?? 'صفحه‌ی نامشخص',
        (bool) $recordInfo => $recordInfo['label'],
        default => $suggestion->place_label,
    };
    $recordUrl = $recordInfo['url'] ?? $suggestion->place_url;
    $kindChip = match ($kind) {
        'field' => 'فیلد: ' . $suggestion->field_label,
        'relation' => 'ساختاری: ' . ($relation['label'] ?? $suggestion->field_label),
        'note' => 'یادداشت: ' . $suggestion->field_label,
        'feedback' => $suggestion->field_label,
        default => 'پیشنهاد کلی',
    };

    // The value now beside the one suggested, word by word
    $quote = fn (?string $v) => '«' . Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $v)), 90) . '»';
    $clearing = ! empty($payload['clear']);
    $current = trim((string) $currentValue);
    $diff = null;
    if ($kind === 'field' && ! $clearing) {
        $ops = WordDiff::ops($current, (string) $suggestion->suggested_value);
        $diff = ['old' => WordDiff::side($ops, 'old'), 'new' => WordDiff::side($ops, 'new'), 'changed' => WordDiff::changed($ops)];
        // a long text shows only its changed parts, with the whole of both under a closed box
        $short = $diff['changed'] ? WordDiff::compact($ops) : $ops;
        $diff['shortened'] = $short !== $ops;
        if ($diff['shortened']) {
            [$diff['old'], $diff['new']] = [WordDiff::side($short, 'old'), WordDiff::side($short, 'new')];
        }
    }
    $suggestedPerson = ! empty($payload['person_id']) ? (int) $payload['person_id'] : null;
    $derivedIds = $suggestion->resolution['converted_to'] ?? [];
    $sourceId = $payload['derived_from'] ?? null;
    $toolsOpen = $errors->has('credit_name') || $errors->hasAny(['target_ref', 'target_type', 'kind', 'field_name', 'suggested_value', 'relation_target', 'target_id', 'role_name', 'shelfmark']);
@endphp

@section('title', 'پیشنهاد #' . $fa($suggestion->id))
@section('page_title', 'پیشنهاد #' . $fa($suggestion->id))
@section('breadcrumb')
    <a href="{{ route('admin.suggestions.index') }}" class="hover:text-cinnabar">پیشنهادهای اصلاح</a>
    <span>/</span>
    <span>داوری</span>
@endsection
@section('page_actions')
    <x-ui.chip :tone="$statusTone">{{ $statusText }}</x-ui.chip>
    <x-ui.btn href="#conversation" size="sm">گفت‌وگو با پیشنهاددهنده{{ $suggestion->thread ? ' (' . \App\Support\Fa::num($suggestion->thread->messages->count()) . ')' : '' }}</x-ui.btn>
@endsection

@section('content')
<div class="max-w-7xl space-y-5 text-[13px]">

    {{-- Context: what record, which field --}}
    <header class="space-y-1.5 pb-4 border-b border-line" data-review-context>
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.chip>{{ $typeLabel }}</x-ui.chip>
            <x-ui.chip :tone="$kind === 'relation' ? 'accent' : ($kind === 'note' ? 'good' : 'default')">{{ $kindChip }}</x-ui.chip>
            {{-- the technical key helps only the site's admin, and stays apart from the Persian label (backlog item 89) --}}
            @if($isAdmin && in_array($kind, ['field', 'relation', 'note'], true) && $suggestion->field_name)
                <code class="text-[11px] text-muted/70" dir="ltr" title="کلید فنی فیلد">{{ $suggestion->field_name }}</code>
            @endif
            @if($suggestion->suggestable_id)
                <span class="text-xs text-muted">رکورد <span class="tabular-nums">#{{ $fa($suggestion->suggestable_id) }}</span></span>
            @endif
        </div>
        <h2 class="font-naskh text-2xl font-bold text-ink leading-relaxed" data-record-title>{{ $fa(Str::limit($recordTitle, 160)) }}</h2>
        @if($suggestion->suggestable_id && ! $target)
            <x-admin.notice tone="error">این رکورد دیگر وجود ندارد (شاید ادغام یا حذف شده است).</x-admin.notice>
        @endif
        @if(! empty($recordInfo['sub']))
            <p class="text-muted">{{ $fa($recordInfo['sub']) }}</p>
        @endif
        <div class="flex flex-wrap gap-x-4 gap-y-1">
            @if($recordUrl)
                <a href="{{ $recordUrl }}" target="_blank" class="font-bold text-cinnabar hover:underline">{{ $kind === 'feedback' ? 'صفحه‌ی گزارش‌شده' : 'دیدن رکورد در سایت' }} ↗</a>
            @endif
            @if($printedPage)
                @if(isset($printedPage['viewer']))
                    <a href="#" @click.prevent="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($printedPage['viewer']) }})" class="font-bold text-cinnabar hover:underline" data-printed-page>برگه‌ی فهرست چاپی ({{ $printedPage['label'] }})</a>
                @else
                    <a href="{{ $printedPage['url'] }}" target="_blank" class="font-bold text-cinnabar hover:underline" data-printed-page>متن صفحه‌ی فهرست چاپی ({{ $printedPage['label'] }})</a>
                @endif
            @endif
        </div>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_22rem] xl:grid-cols-[minmax(0,1fr)_25rem] gap-5 items-start">
        <div class="space-y-4 min-w-0">

            {{-- The suggestion, said plainly --}}
            <x-ui.card class="space-y-3" data-claim-card>
                <h3 class="text-xs font-bold text-muted">پیشنهاد</h3>
                <p class="text-[15px] leading-8 text-ink" data-claim>
                    «{{ $submitterName }}»
                    @switch($kind)
                        @case('field')
                            @if($clearing)
                                پیشنهاد می‌کند <b>{{ $suggestion->field_label }}</b> این {{ $typeLabel }} خالی شود@if($current !== '') (اکنون <b>{{ $quote($current) }}</b>)@endif.
                            @elseif($current === '')
                                پیشنهاد می‌کند برای <b>{{ $suggestion->field_label }}</b> این {{ $typeLabel }} مقدار <b>{{ $quote($suggestion->suggested_value) }}</b> ثبت شود@if($suggestedPerson) و به شخص <span class="tabular-nums">#{{ $fa($suggestedPerson) }}</span> پیوند بخورد@endif.
                            @elseif(mb_strlen($current) > 80 || mb_strlen((string) $suggestion->suggested_value) > 80)
                                {{-- two long texts look alike in a sentence; the comparison below shows the change --}}
                                پیشنهاد می‌کند متن <b>{{ $suggestion->field_label }}</b> این {{ $typeLabel }} را تغییر دهد، چنان‌که در مقایسه‌ی زیر آمده است@if($suggestedPerson) و به شخص <span class="tabular-nums">#{{ $fa($suggestedPerson) }}</span> پیوند بخورد@endif.
                            @else
                                پیشنهاد می‌کند <b>{{ $suggestion->field_label }}</b> این {{ $typeLabel }} از <b>{{ $quote($current) }}</b> به <b>{{ $quote($suggestion->suggested_value) }}</b> تغییر کند@if($suggestedPerson) و به شخص <span class="tabular-nums">#{{ $fa($suggestedPerson) }}</span> پیوند بخورد@endif.
                            @endif
                            @break
                        @case('relation')
                            پیشنهاد ساختاری داده است: <b>{{ $relation['action'] ?? $suggestion->field_label }}</b>@if($relationTarget), با {{ $fa(Str::limit(SuggestionRegistry::describe($relationTarget)['label'], 90)) }}@endif.
                            @break
                        @case('note')
                            یادداشتی از نوع <b>«{{ $suggestion->field_label }}»</b> برای این {{ $typeLabel }} فرستاده است.
                            @break
                        @case('feedback')
                            درباره‌ی صفحه‌ی <b>{{ $quote($recordTitle) }}</b> بازخورد فرستاده است: <b>{{ $suggestion->field_label }}</b>.
                            @break
                        @default
                            پیشنهادی کلی درباره‌ی این {{ $typeLabel }} فرستاده است.
                    @endswitch
                </p>

                @if($diff)
                    <dl class="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-x-3 gap-y-2 items-baseline" data-diff-table>
                        <dt class="text-xs text-muted">مقدار کنونی</dt>
                        <dd class="font-naskh text-base leading-8 text-ink break-words">
                            @if($current === '')<span class="text-muted font-sans text-[13px]">— فیلد خالی است —</span>@else<x-admin.word-diff :ops="$diff['old']" />@endif
                        </dd>
                        <dt class="text-xs text-muted">مقدار پیشنهادی</dt>
                        <dd class="font-naskh text-base leading-8 text-ink break-words font-semibold"><x-admin.word-diff :ops="$diff['new']" /></dd>
                    </dl>
                    <p class="text-xs text-muted">
                        @if(! $diff['changed'])
                            مقدار پیشنهادی جز در فاصله‌ها با مقدار کنونی یکی است.
                        @else
                            افزوده‌ها سبز و حذف‌شده‌ها خط‌خورده‌اند؛ در متن بلند فقط بخش‌های تغییرکرده با چند کلمه‌ی پیرامونشان آمده است.
                        @endif
                        {{ $currentIsLive ? 'مقدار کنونی از پایگاه داده خوانده شد (هنگام ثبت پیشنهاد ذخیره نشده بود).' : '' }}
                    </p>
                    @if($diff['shortened'])
                        <details class="text-xs">
                            <summary class="cursor-pointer text-muted font-bold">متن کامل دو مقدار</summary>
                            <div class="grid gap-3 md:grid-cols-2 pt-2">
                                <div class="p-3 bg-paper border border-line whitespace-pre-line leading-7 text-ink">{{ $current }}</div>
                                <div class="p-3 bg-paper border border-line whitespace-pre-line leading-7 text-ink">{{ $suggestion->suggested_value }}</div>
                            </div>
                        </details>
                    @endif
                @elseif($kind === 'field' && $clearing)
                    <p class="font-semibold text-cinnabar">{{ SuggestionRegistry::CLEAR_LABEL }}</p>
                @elseif($kind === 'relation')
                    {{-- The two records the suggestion connects --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach(array_filter([['این رکورد', $target], $relation && (! $relation['target'] || (! empty($relation['target_optional']) && ! $relationTarget)) ? null : [$relation && $relation['key'] === 'move_to_work' ? 'اثر پیشنهادی' : ($relation && $relation['target'] === 'person' ? 'شخص' : ($relation && $relation['target'] === 'cataloger' ? 'فهرست‌نگار پیشنهادی' : ($relation && $relation['target'] === 'library' ? (($relation['apply'] ?? null) === 'relocate' ? 'کتابخانه‌ی پیشنهادی' : 'کتابخانه‌ی مقصد') : 'رکورد مقصد'))), $relationTarget]]) as [$caption, $record])
                            <div class="p-3 bg-paper border border-line space-y-1">
                                <h4 class="text-xs font-bold text-muted">{{ $caption }}</h4>
                                @if($record)
                                    @php $info = SuggestionRegistry::describe($record); @endphp
                                    <a href="{{ $info['url'] }}" target="_blank" class="block font-naskh text-base font-bold text-ink hover:text-cinnabar">{{ $fa($info['label']) }}</a>
                                    <div class="text-xs text-muted"><span class="tabular-nums">#{{ $fa($info['id']) }}</span>{{ $info['sub'] ? ' · ' . $fa($info['sub']) : '' }}</div>
                                @else
                                    <x-admin.notice tone="error">این رکورد دیگر وجود ندارد (شاید ادغام یا حذف شده است).</x-admin.notice>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if(trim((string) $suggestion->suggested_value) !== '')
                        <div class="whitespace-pre-line leading-7 text-ink"><span class="text-xs text-muted">متن پیشنهاددهنده:</span> {{ $suggestion->suggested_value }}</div>
                    @endif
                    @if(! empty($payload['shelfmark']))
                        <p>شماره‌ی تازه‌ی پیشنهادی: <b>{{ $payload['shelfmark'] }}</b></p>
                    @endif
                    @if(($relation['apply'] ?? null) === 'volume_cataloger')
                        {{-- what the submitter chose for a source's cataloger (step 14.58) --}}
                        @php $vcVolume = collect($target ? \App\Suggestions\VolumeCatalogers::volumes($target) : [])->firstWhere('id', $payload['volume_id'] ?? null); @endphp
                        <p>جلد: <b>{{ $fa($vcVolume['label'] ?? 'همه‌ی جلدها') }}</b> · نقش: <b>{{ \App\Suggestions\VolumeCatalogers::ROLES[$payload['role'] ?? ''] ?? '—' }}</b>
                            @if(! empty($payload['name'])) · نام: <b>{{ $payload['name'] }}</b>@endif</p>
                    @endif
                @elseif(in_array($kind, ['field', 'general'], true))
                    <div class="p-3 bg-paper border border-line whitespace-pre-line leading-7 text-ink font-semibold">{{ $suggestion->suggested_value }}</div>
                @endif
            </x-ui.card>

            @if($kind === 'note')
                @include('admin.suggestions.partials.note-review', ['part' => 'main'])
            @elseif($kind === 'feedback')
                @include('admin.suggestions.partials.feedback-review', ['part' => 'main'])
            @endif

            @includeWhen($suggestion->review_queue, 'admin.suggestions.partials.collective')

            {{-- Evidence --}}
            @if(! in_array($kind, ['note', 'feedback'], true) || $suggestion->rationale_citation)
                <x-ui.card class="space-y-2" data-evidence>
                    <h3 class="text-xs font-bold text-muted">{{ $kind === 'note' ? 'توضیح پژوهشگر' : 'مستند و منبع پژوهشگر' }}</h3>
                    <div class="whitespace-pre-line leading-7 text-ink border-s-[3px] border-gold ps-3">{{ $suggestion->rationale_citation ?: '— مستندی ذکر نشده است —' }}</div>
                </x-ui.card>
            @endif

            {{-- Who sent it, and how their suggestions have fared --}}
            <x-ui.card class="space-y-2" data-submitter>
                <h3 class="text-xs font-bold text-muted">پیشنهاددهنده</h3>
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <x-ui.avatar :user="$suggestion->user" :name="$suggestion->guest_name" size="sm" />
                    <b class="text-ink">{{ $submitterName }}</b>
                    @if($suggestion->guest_email)
                        <span class="text-xs text-muted" dir="ltr">{{ $suggestion->guest_email }}</span>
                    @endif
                    @if($suggestion->user?->is_verified_scholar)
                        <x-ui.chip tone="good">پژوهشگر تأییدشده</x-ui.chip>
                    @endif
                    <span class="text-xs text-muted">@jdate($suggestion->created_at, 'datetime') (@jago($suggestion->created_at))</span>
                </div>
                @if($submitterStats)
                    <div class="flex flex-wrap gap-1.5" data-submitter-stats>
                        <x-ui.chip tone="good">{{ \App\Support\Fa::num($submitterStats['approved']) }} تأییدشده</x-ui.chip>
                        <x-ui.chip>{{ \App\Support\Fa::num($submitterStats['rejected']) }} ردشده</x-ui.chip>
                        <x-ui.chip tone="accent">{{ \App\Support\Fa::num($submitterStats['pending']) }} در انتظار</x-ui.chip>
                    </div>
                @else
                    <p class="text-xs text-muted">مهمانی بی ایمیل؛ سابقه‌ای برای او نمی‌توان شمرد.</p>
                @endif
                @unless($kind === 'feedback')
                    <p class="text-xs">
                        <span class="text-muted">نام در صفحه‌ی عمومی:</span>
                        @if($suggestion->credit_name)
                            <b class="text-emerald-800 dark:text-emerald-300">{{ $suggestion->credit_name }}</b>
                            <span class="text-muted">{{ $suggestion->status === 'approved' ? '(نمایش داده می‌شود)' : '(پس از تأیید نمایش داده می‌شود)' }}</span>
                        @elseif($suggestion->held_credit_name)
                            <b>{{ $suggestion->held_credit_name }}</b>
                            <span class="text-muted">(پس از تأیید ایمیل پیشنهاددهنده نمایش داده می‌شود)</span>
                        @else
                            بدون نام
                        @endif
                    </p>
                @endunless
            </x-ui.card>

            @include('admin.suggestions.partials.conversation')

            {{-- Tools a review needs now and then --}}
            <details class="border border-dashed border-line-strong px-4 py-3 space-y-4" data-more-tools @if($toolsOpen) open @endif>
                <summary class="cursor-pointer font-bold text-muted">ابزارهای دیگر</summary>

                @unless($kind === 'feedback')
                    <form action="{{ route('admin.suggestions.credit', $suggestion) }}" method="POST" class="space-y-1.5 pt-3">
                        @csrf
                        <label for="credit_name" class="block font-bold text-ink">نام در صفحه‌ی عمومی</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <input id="credit_name" type="text" name="credit_name" value="{{ old('credit_name', $suggestion->credit_name) }}" maxlength="150"
                                placeholder="فقط با رضایت پیشنهاددهنده؛ خالی یعنی بدون نام" class="admin-field flex-1 basis-60">
                            <x-ui.btn type="submit">ذخیره‌ی نام</x-ui.btn>
                        </div>
                    </form>
                @endunless

                {{-- Links between a converted suggestion and the ones made from it --}}
                @if($derivedIds || $sourceId)
                    <x-admin.notice class="space-y-1">
                        @if($sourceId)
                            <p>این پیشنهاد را داور از روی <a href="{{ route('admin.suggestions.show', $sourceId) }}" class="font-bold text-cinnabar hover:underline">پیشنهاد #{{ $fa($sourceId) }}</a> ساخته است.</p>
                        @endif
                        @if($derivedIds)
                            <p>پیشنهادهای ساخته‌شده از این پیشنهاد:
                                @foreach(\App\Models\FieldSuggestion::whereIn('id', $derivedIds)->get() as $d)
                                    <a href="{{ route('admin.suggestions.show', $d) }}" class="font-bold text-cinnabar hover:underline">#{{ $fa($d->id) }} ({{ $d->field_label }})</a>@if(! $loop->last)، @endif
                                @endforeach
                            </p>
                        @endif
                    </x-admin.notice>
                @endif

                @if($suggestion->status === 'pending')
                    @include('admin.suggestions.partials.convert')
                @endif

                @if($fieldHistory->isNotEmpty())
                    <div class="space-y-1.5" data-field-history>
                        <h4 class="font-bold text-ink">سابقه‌ی این فیلد: پیشنهادهای دیگر</h4>
                        <ul class="space-y-1 m-0 p-0 list-none">
                            @foreach($fieldHistory as $h)
                                <li class="flex flex-wrap items-baseline gap-x-2">
                                    <a href="{{ route('admin.suggestions.show', $h) }}" class="font-bold text-cinnabar hover:underline tabular-nums">#{{ $fa($h->id) }}</a>
                                    <span class="text-ink">{{ Str::limit($h->display_value, 80) ?: '—' }}</span>
                                    <span class="text-xs text-muted">{{ $h->standing_label ?? ['pending' => 'در انتظار', 'approved' => 'تأییدشده', 'rejected' => 'ردشده', 'converted' => 'تبدیل‌شده'][$h->status] ?? $h->status }} · {{ $h->user?->name ?: ($h->guest_name ?: 'مهمان') }} · @jago($h->created_at)</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </details>
        </div>

        {{-- The decision, beside the suggestion; below it on a narrow screen --}}
        <aside class="space-y-4 lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto" aria-label="تصمیم" data-decision-panel>
            @if($kind === 'note')
                @include('admin.suggestions.partials.note-review', ['part' => 'panel'])
            @elseif($kind === 'feedback')
                @include('admin.suggestions.partials.feedback-review', ['part' => 'panel'])
            @elseif($suggestion->status === 'pending')
                @php $toolPermission = $relation ? (\App\Auth\Permissions::FOR_APPLY[$relation['apply'] ?? ''] ?? null) : null; @endphp
                <x-admin.decision :suggestion="$suggestion">
                    <x-slot:approve>
                        @if(in_array($suggestion->review_queue, \App\Review\CollectiveReview::DECIDED_IN_QUEUE, true))
                            {{-- People are merged or kept apart in the panel's queue, which settles every answer on the item --}}
                            <x-admin.notice>
                                این پاسخی در «{{ config('contributions.review_title') }}» است. درباره‌ی این مورد در صف «بازبینی اشخاص» تصمیم بگیرید؛ همه‌ی پاسخ‌های آن با همان تصمیم بسته می‌شوند.
                                <a href="{{ \App\Review\CollectiveReview::queue($suggestion->review_queue)->reviewUrl((int) $suggestion->review_item_id) }}" class="text-cinnabar font-bold hover:underline">رفتن به مورد</a>
                            </x-admin.notice>
                        @elseif($toolPermission && ! auth()->user()->can($toolPermission))
                            <x-admin.notice>
                                اجرای «{{ \App\Auth\Permissions::label($toolPermission) }}» برای نقش شما مجاز نیست. داوری این پیشنهاد با مدیر کل است؛ می‌توانید آن را رد کنید یا یادداشت بگذارید.
                            </x-admin.notice>
                        @elseif($relation)
                            <h4 class="font-bold text-emerald-800 dark:text-emerald-300">{{ $relation['action'] }}</h4>
                            @include('admin.suggestions.partials.relation-approve')
                        @else
                            @if(! $field)
                                <x-admin.notice>
                                    @if($kind === 'general')
                                        این پیشنهاد کلی است و خودکار اعمال نمی‌شود. تغییرات لازم را دستی انجام دهید. با تأیید، پیشنهاد «رسیدگی‌شده» ثبت می‌شود.
                                    @else
                                        فیلد «{{ $suggestion->field_label }}»@if($isAdmin) <code class="text-[11px] text-muted" dir="ltr">{{ $suggestion->field_name }}</code>@endif در فهرست فیلدهای قابل اصلاح نیست و خودکار اعمال نمی‌شود. تغییر را دستی انجام دهید. با تأیید، پیشنهاد «رسیدگی‌شده» ثبت می‌شود.
                                    @endif
                                </x-admin.notice>
                            @endif
                            @include('admin.suggestions.partials.field-approve')
                        @endif
                    </x-slot:approve>
                    <x-slot:reject>
                        <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-3">
                            @csrf
                            <div class="space-y-1">
                                <label for="reject_public_note" class="block font-bold text-ink">علت رد <span class="font-normal text-xs text-muted">(یادداشت عمومی؛ پیشنهاددهنده آن را می‌بیند)</span></label>
                                <textarea id="reject_public_note" name="public_note" rows="3" placeholder="مثال: در ترقیمه‌ی نسخه تاریخ دیگری آمده است." class="admin-field w-full"></textarea>
                            </div>
                            <div class="space-y-1">
                                <label for="reject_admin_notes" class="block font-bold text-ink">یادداشت داخلی <span class="font-normal text-xs text-muted">(فقط داوران)</span></label>
                                <textarea id="reject_admin_notes" name="admin_notes" rows="2" placeholder="مثال: مستند ارائه‌شده کافی نبود…" class="admin-field w-full"></textarea>
                            </div>
                            <button type="submit" onclick="return confirm('این پیشنهاد رد شود؟')"
                                    class="w-full py-2.5 px-4 rounded-sm border border-cinnabar text-cinnabar hover:bg-cinnabar/5 font-bold transition cursor-pointer">
                                رد پیشنهاد
                            </button>
                        </form>
                    </x-slot:reject>
                </x-admin.decision>
            @else
                {{-- Already reviewed: the outcome takes the decision's place --}}
                <x-ui.card class="space-y-3 !border-line-strong" data-outcome-summary>
                    <h3 class="font-naskh text-lg font-bold text-ink">نتیجه‌ی داوری</h3>
                    @include('admin.suggestions.partials.outcome')
                </x-ui.card>
                @if($promoting)
                    <x-ui.card class="space-y-3 !border-emerald-600/40">
                        <h3 class="rubric text-base !text-emerald-800 dark:!text-emerald-300">انتخاب این «قول دیگر» به‌عنوان مقدار منتخب</h3>
                        @include('admin.suggestions.partials.field-approve')
                    </x-ui.card>
                @endif
            @endif
        </aside>
    </div>

</div>
<x-catalog-viewer />
@endsection
