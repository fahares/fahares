@extends('layouts.admin')

@php
    $fa = fn ($v) => \App\Support\Fa::digits($v);
    [$statusText, $statusTone] = match (true) {
        $suggestion->status === 'pending' && $suggestion->awaiting_reply_since => ['منتظر پاسخ پیشنهاددهنده', 'default'],
        $suggestion->status === 'pending' && $suggestion->reply_state === 'answered' => ['در انتظار داوری، پیشنهاددهنده پاسخ داد', 'good'],
        $suggestion->status === 'pending' && $suggestion->reply_state === 'unanswered' => ['در انتظار داوری، پرسش بی‌پاسخ ماند', 'accent'],
        $suggestion->status === 'pending' => ['در انتظار داوری', 'accent'],
        $suggestion->status === 'approved' => [($suggestion->kind === 'note' ? 'منتشر شد' : 'تأیید شد') . '، به دست ' . ($suggestion->reviewer?->name ?: 'مدیر'), 'good'],
        $suggestion->status === 'converted' => ['تبدیل شد', 'default'],
        $suggestion->status === 'withdrawn' => ['از صفحه برداشته شد', 'default'],
        default => ['رد شد، به دست ' . ($suggestion->reviewer?->name ?: 'مدیر'), 'default'],
    };
    $targetUrl = $target ? match (class_basename($suggestion->suggestable_type)) {
        'Manuscript' => route('manuscripts.show', $target),
        'Work' => route('works.show', $target),
        'Person' => route('people.show', $target),
        'Library' => route('libraries.show', $target),
        default => null,
    } : null;
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
    @if($targetUrl)
        <x-ui.btn :href="$targetUrl" target="_blank" size="sm">صفحه‌ی رکورد در سایت ↗</x-ui.btn>
    @endif
@endsection

@section('content')
<div class="max-w-5xl space-y-6 text-[13px]">

    {{-- What the suggestion is about, and who sent it --}}
    <x-ui.card class="space-y-4">
        <div>
            <h2 class="font-naskh text-xl font-bold text-ink leading-snug">{{ $fa(\Illuminate\Support\Str::limit($suggestion->place_label, 120)) }}</h2>
            <p class="mt-1 text-muted">
                @if($suggestion->kind === 'general')
                    پیشنهاد کلی (متن آزاد)
                @elseif($suggestion->kind === 'relation')
                    پیشنهاد ساختاری: <b class="text-cinnabar">{{ $relation['label'] ?? $suggestion->field_name }}</b>
                @else
                    فیلد: <b class="text-cinnabar">{{ $suggestion->field_label }}</b>
                    <code class="text-xs" dir="ltr">{{ $suggestion->field_name }}</code>
                @endif
            </p>
        </div>

        <x-ui.meta-list>
            <x-ui.meta label="پیشنهاددهنده">
                <span class="inline-flex items-center gap-1.5 align-middle">
                    <x-ui.avatar :user="$suggestion->user" :name="$suggestion->guest_name" size="sm" />
                    <b>{{ $suggestion->guest_name ?: 'پژوهشگر مهمان' }}</b>
                </span>
                @if($suggestion->guest_email)
                    <span class="text-xs text-muted" dir="ltr">{{ $suggestion->guest_email }}</span>
                @endif
            </x-ui.meta>
            <x-ui.meta label="تاریخ">@jdate($suggestion->created_at, 'datetime') <span class="text-xs text-muted">(@jago($suggestion->created_at))</span></x-ui.meta>
        </x-ui.meta-list>

        @unless($suggestion->kind === 'feedback')
            <div class="pt-3 border-t border-line space-y-2">
                <p>
                    <span class="text-muted">نام در صفحه‌ی عمومی:</span>
                    @if($suggestion->credit_name)
                        <b class="text-emerald-800 dark:text-emerald-300">{{ $suggestion->credit_name }}</b>
                        <span class="text-xs text-muted">{{ $suggestion->status === 'approved' ? '(نمایش داده می‌شود)' : '(پس از تأیید نمایش داده می‌شود)' }}</span>
                    @elseif($suggestion->held_credit_name)
                        <b>{{ $suggestion->held_credit_name }}</b>
                        <span class="text-xs text-muted">(پس از تأیید ایمیل پیشنهاددهنده نمایش داده می‌شود)</span>
                    @else
                        بدون نام
                    @endif
                </p>
                <form action="{{ route('admin.suggestions.credit', $suggestion) }}" method="POST" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="text" name="credit_name" value="{{ old('credit_name', $suggestion->credit_name) }}" maxlength="150"
                        placeholder="فقط با رضایت پیشنهاددهنده؛ خالی یعنی بدون نام" aria-label="نام در صفحه‌ی عمومی"
                        class="admin-field flex-1 basis-60">
                    <x-ui.btn type="submit">ذخیره‌ی نام</x-ui.btn>
                </form>
            </div>
        @endunless
    </x-ui.card>

    {{-- Links between a converted suggestion and the ones made from it --}}
    @php
        $derivedIds = $suggestion->resolution['converted_to'] ?? [];
        $sourceId = $suggestion->payload['derived_from'] ?? null;
    @endphp
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

    @if($suggestion->kind === 'note')
        @include('admin.suggestions.partials.note-review')
        @include('admin.suggestions.partials.conversation')
    @elseif($suggestion->kind === 'feedback')
        @include('admin.suggestions.partials.feedback-review')
        @include('admin.suggestions.partials.conversation')
    @else
    @if($suggestion->kind === 'relation')
        {{-- The two records the suggestion connects --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach(array_filter([['این رکورد', $target], $relation && (! $relation['target'] || (! empty($relation['target_optional']) && ! $relationTarget)) ? null : [$relation && $relation['key'] === 'move_to_work' ? 'اثر پیشنهادی' : ($relation && $relation['target'] === 'person' ? 'شخص' : ($relation && $relation['target'] === 'library' ? (($relation['apply'] ?? null) === 'relocate' ? 'کتابخانه‌ی پیشنهادی' : 'کتابخانه‌ی مقصد') : 'رکورد مقصد')), $relationTarget]]) as [$caption, $record])
                <x-ui.card class="space-y-1">
                    <h3 class="rubric text-base">{{ $caption }}</h3>
                    @if($record)
                        @php $info = \App\Suggestions\SuggestionRegistry::describe($record); @endphp
                        <a href="{{ $info['url'] }}" target="_blank" class="block font-bold text-ink hover:text-cinnabar">{{ $info['label'] }}</a>
                        <div class="text-xs text-muted"><span class="tabular-nums">#{{ $fa($info['id']) }}</span>{{ $info['sub'] ? ' · ' . $info['sub'] : '' }}</div>
                    @else
                        <x-admin.notice tone="error">این رکورد دیگر وجود ندارد (شاید ادغام یا حذف شده است).</x-admin.notice>
                    @endif
                </x-ui.card>
            @endforeach
        </div>
    @endif

    {{-- The value now beside the one suggested --}}
    <div class="grid grid-cols-1 {{ $suggestion->kind === 'field' ? 'md:grid-cols-2' : '' }} gap-4">
        @if($suggestion->kind === 'field')
            <x-ui.card class="space-y-2">
                <h3 class="rubric text-base">مقدار کنونی</h3>
                <p class="text-xs text-muted">در پایگاه داده، یا متن مأخذ چاپی</p>
                <div class="whitespace-pre-line leading-7 text-ink min-h-16">{{ $suggestion->current_value ?: '— فیلد خالی است —' }}</div>
            </x-ui.card>
        @endif

        {{-- A relation often carries no text of its own --}}
        @if($suggestion->kind !== 'relation' || trim((string) $suggestion->suggested_value) !== '' || ! empty($suggestion->payload['shelfmark']))
        <x-ui.card framed class="space-y-2">
            <h3 class="rubric text-base">مقدار پیشنهادی پژوهشگر</h3>
            <div class="whitespace-pre-line leading-7 font-semibold text-ink min-h-16">{{ $suggestion->suggested_value }}</div>
            @if(! empty($suggestion->payload['shelfmark']))
                <p>شماره‌ی تازه‌ی پیشنهادی: <b>{{ $suggestion->payload['shelfmark'] }}</b></p>
            @endif
        </x-ui.card>
        @endif
    </div>

    @includeWhen($suggestion->review_queue, 'admin.suggestions.partials.collective')

    <x-ui.card class="space-y-2">
        <h3 class="rubric text-base">مستند و منبع پژوهشگر</h3>
        <div class="whitespace-pre-line leading-7 text-ink">{{ $suggestion->rationale_citation ?: '— مستندی ذکر نشده است —' }}</div>
    </x-ui.card>

    @include('admin.suggestions.partials.conversation')

    @php $promoting = $suggestion->status === 'approved' && $suggestion->standing === 'alternative'; @endphp
    @if($suggestion->status === 'pending' || $promoting)
        {{-- The desk: approve, or reject (an alternative reading can still be adopted) --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            <x-ui.card class="lg:col-span-2 space-y-4 !border-emerald-600/40">
                <h3 class="rubric text-base !text-emerald-800 dark:!text-emerald-300">{{ $relation ? $relation['action'] : ($promoting ? 'انتخاب این «قول دیگر» به‌عنوان مقدار منتخب' : ($field ? 'تأیید و اعمال اصلاحیه در پایگاه داده' : 'رسیدگی به پیشنهاد')) }}</h3>

                @php $toolPermission = $relation ? (\App\Auth\Permissions::FOR_APPLY[$relation['apply'] ?? ''] ?? null) : null; @endphp
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
                    @include('admin.suggestions.partials.relation-approve')
                @else
                <form action="{{ route('admin.suggestions.approve', $suggestion) }}" method="POST" class="space-y-4"
                    x-data="{ outcome: @js(old('outcome', 'adopt')) }">
                    @csrf

                    @if($errors->any())
                        <x-admin.notice tone="error">{{ $errors->first() }}</x-admin.notice>
                    @endif

                    @if(! $field)
                        <x-admin.notice>
                            @if($suggestion->kind === 'general')
                                این پیشنهاد کلی است و خودکار اعمال نمی‌شود. تغییرات لازم را دستی انجام دهید. با تأیید، پیشنهاد «رسیدگی‌شده» ثبت می‌شود.
                            @else
                                فیلد <code dir="ltr">{{ $suggestion->field_name }}</code> در فهرست فیلدهای قابل اصلاح نیست و خودکار اعمال نمی‌شود. تغییر را دستی انجام دهید. با تأیید، پیشنهاد «رسیدگی‌شده» ثبت می‌شود.
                            @endif
                        </x-admin.notice>
                    @elseif($field['input'] === 'boolean')
                        <fieldset class="space-y-1">
                            <legend class="font-bold text-ink">مقدار نهایی «{{ $field['label'] }}»</legend>
                            @php $boolDefault = old('applied_value', in_array(trim($suggestion->applied_value ?? $suggestion->suggested_value), ['بله', '1', 'دارد', 'هست', 'true'], true) ? '1' : '0'); @endphp
                            <div class="flex gap-4 pt-1">
                                <label class="inline-flex items-center gap-1.5"><input type="radio" name="applied_value" value="1" @checked($boolDefault === '1') class="accent-cinnabar"> بله</label>
                                <label class="inline-flex items-center gap-1.5"><input type="radio" name="applied_value" value="0" @checked($boolDefault === '0') class="accent-cinnabar"> خیر</label>
                            </div>
                        </fieldset>
                    @else
                        <div class="space-y-1">
                            <label for="applied_value" class="block font-bold text-ink">
                                مقدار نهایی «{{ $field['label'] }}» <span class="font-normal text-muted">(پیش از تأیید ویرایش‌پذیر است)</span>
                            </label>
                            <textarea id="applied_value" name="applied_value" rows="{{ in_array($field['input'], ['quote', 'list', 'textarea'], true) ? 5 : 2 }}" class="admin-field w-full font-semibold leading-7">{{ old('applied_value', $suggestion->applied_value ?? $suggestion->suggested_value) }}</textarea>
                            @if($field['input'] === 'catalog_page')
                                @php
                                    $pageRef = isset($field['entry_id']) ? \App\Models\SourceEntry::with(['source', 'sourceVolume'])->find($field['entry_id']) : null;
                                    $suggestedPage = \App\Suggestions\SuggestionRegistry::parseCatalogPage($suggestion->applied_value ?? $suggestion->suggested_value);
                                @endphp
                                <p class="text-xs text-muted leading-6">
                                    به شکل «ج ۲، ص ۷»@if(! empty($field['with_row']))، و «، ردیف ۳» اگر ردیف هم تغییر کرده است@endif.
                                    @if(! empty($field['can_be_absent'])) «نیست» یعنی نسخه در این فهرست نیامده است و پیوندش از صفحه‌ی نسخه برداشته می‌شود. با تأیید، نشانی «دستی و بازبینی‌شده» می‌شود و نشان ⓘ برداشته می‌شود.@endif
                                </p>
                                @if($pageRef && ($viewer = $pageRef->viewer()))
                                    <div class="flex flex-wrap gap-2 pt-1">
                                        <x-ui.btn size="sm" @click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($viewer) }})">برگه‌ی فعلی (ص {{ $fa($viewer['page']) }})</x-ui.btn>
                                        @if($suggestedPage)
                                            <x-ui.btn size="sm" @click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from(['volume' => $suggestedPage['volume'], 'page' => $suggestedPage['page']] + $viewer) }})">برگه‌ی پیشنهادی (ص {{ $fa($suggestedPage['page']) }})</x-ui.btn>
                                        @endif
                                    </div>
                                @endif
                            @elseif($field['input'] === 'list')
                                <p class="text-xs text-muted">هر مورد در یک سطر. فهرست فعلی به‌طور کامل جایگزین می‌شود.</p>
                            @elseif($field['input'] === 'quote' && count($target?->metadata[$field['meta_key']] ?? []) > 1)
                                <x-admin.notice tone="warn">این نسخه اکنون {{ $fa(count($target->metadata[$field['meta_key']])) }} مورد ثبت‌شده دارد. با تأیید، همه با همین یک متن جایگزین می‌شوند.</x-admin.notice>
                            @endif
                        </div>

                        @include('admin.suggestions.partials.retext')

                        @if($field['input'] === 'hijri_date')
                            <div class="space-y-1">
                                <label for="hijri_year" class="block font-bold text-ink">سال هجری قمری برای مرتب‌سازی و جستجو</label>
                                <input id="hijri_year" type="number" name="hijri_year" min="{{ \App\Support\HijriYear::MIN }}" max="{{ \App\Support\HijriYear::MAX }}" value="{{ old('hijri_year', $parsedYear) }}" class="admin-field w-40 font-bold tabular-nums">
                                <p class="text-xs text-muted">از متن پیشنهادی استخراج شد{{ $parsedYear ? '' : ' (سالی پیدا نشد)' }}. اگر تاریخ فقط سده است یا شمسی و میلادی است، خالی بگذارید.</p>
                            </div>
                        @endif

                        @if($field['input'] === 'person')
                            @php
                                $defaultPick = old('person_pick', $people['suggested'] ? 'p:' . $people['suggested']->id : ($people['current'] ? 'text' : ($people['candidates']->isNotEmpty() ? 'p:' . $people['candidates']->first()->id : 'create')));
                            @endphp
                            <fieldset class="space-y-1 p-4 bg-paper border border-line">
                                <legend class="px-1 font-bold text-ink">پیوند به جدول اشخاص</legend>

                                @foreach(array_filter(['suggested' => $people['suggested'], 'current' => $people['current']]) as $role => $person)
                                    @include('admin.suggestions.partials.person-option', ['person' => $person, 'note' => $role === 'suggested' ? 'انتخاب پیشنهاددهنده' : ($field['role'] === 'author' ? 'پدیدآور فعلی پیوندخورده' : 'کاتب فعلی پیوندخورده'), 'checked' => $defaultPick === 'p:' . $person->id])
                                @endforeach

                                @foreach($people['candidates'] as $person)
                                    @include('admin.suggestions.partials.person-option', ['person' => $person, 'note' => null, 'checked' => $defaultPick === 'p:' . $person->id])
                                @endforeach

                                <label class="flex flex-wrap items-center gap-2 py-1">
                                    <input type="radio" name="person_pick" value="manual" @checked($defaultPick === 'manual') class="accent-cinnabar">
                                    <span>پیوند به شخص با شناسه‌ی</span>
                                    <input type="number" name="person_manual_id" value="{{ old('person_manual_id') }}" placeholder="مثلاً ۱۲۳۴" aria-label="شناسه‌ی شخص" class="admin-field w-28 tabular-nums">
                                </label>
                                <label class="flex items-center gap-2 py-1">
                                    <input type="radio" name="person_pick" value="create" @checked($defaultPick === 'create') class="accent-cinnabar">
                                    <span>ساخت شخص تازه با همین نام</span>
                                </label>
                                <label class="flex items-center gap-2 py-1">
                                    <input type="radio" name="person_pick" value="text" @checked($defaultPick === 'text') class="accent-cinnabar">
                                    <span>فقط ذخیره‌ی متن نام{{ $people['current'] ? ' (پیوند فعلی دست نمی‌خورد)' : ' (بدون پیوند)' }}</span>
                                </label>
                            </fieldset>
                        @endif

                        {{-- Outcome: a reading (adopted or kept as alternative) or an edit that is not a reading --}}
                        @if($promoting)
                            <input type="hidden" name="outcome" value="adopt">
                        @else
                            <fieldset class="space-y-1.5">
                                <legend class="font-bold text-ink">نتیجه‌ی داوری</legend>
                                @foreach([
                                    'adopt' => ['قول علمی: اعمال به‌عنوان مقدار منتخب', 'با نام پیشنهاددهنده (اگر رضایت داده) در صفحه نمایش داده می‌شود.'],
                                    'alternative' => ['قول علمی: نگه‌داشتن به‌عنوان «قول دیگر»', 'مقدار رکورد تغییر نمی‌کند؛ این قول با مستندش در پایین صفحه می‌ماند.'],
                                    'ocr_fix' => ['ویرایش: غلط چاپی یا نویسه‌خوان (OCR)', 'اعمال می‌شود ولی قول به‌شمار نمی‌آید.'],
                                    'parser_fix' => ['ویرایش: خطای پارسر (تفکیک اشتباه فیلدها)', 'اعمال می‌شود ولی قول به‌شمار نمی‌آید.'],
                                ] as $value => [$label, $hint])
                                    <label class="flex items-start gap-2 py-0.5 cursor-pointer">
                                        <input type="radio" name="outcome" value="{{ $value }}" x-model="outcome" class="mt-1.5 accent-cinnabar">
                                        <span><b class="text-ink">{{ $label }}</b> <span class="text-xs text-muted">— {{ $hint }}</span></span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @endif

                        @if($previousAdopted)
                            <x-admin.notice tone="warn" x-show="outcome === 'adopt'" class="space-y-1">
                                <p class="font-bold">
                                    این فیلد مقدار منتخب دارد: «{{ \Illuminate\Support\Str::limit($previousAdopted->display_value, 120) }}»
                                    <span class="font-normal">(قائل: {{ $previousAdopted->credit_name ?: $previousAdopted->guest_name ?: 'نامشخص' }}، <a href="{{ route('admin.suggestions.show', $previousAdopted) }}" class="underline">#{{ $fa($previousAdopted->id) }}</a>)</span>
                                </p>
                                <p class="text-ink">با تأیید این پیشنهاد، آن قول:</p>
                                <label class="flex items-center gap-2 text-ink"><input type="radio" name="previous_standing" value="alternative" @checked(old('previous_standing') === 'alternative') class="accent-cinnabar"> <span><b>قول دیگر</b> می‌شود (هنوز قابل دفاع است)</span></label>
                                <label class="flex items-center gap-2 text-ink"><input type="radio" name="previous_standing" value="superseded" @checked(old('previous_standing') === 'superseded') class="accent-cinnabar"> <span><b>منسوخ</b> می‌شود (نادرست بودنش روشن شد؛ در «سابقه» می‌ماند)</span></label>
                            </x-admin.notice>
                        @endif

                    @endif

                    @if($field)
                        {{-- Public note: required for an alternative, and when an adopted reading is replaced --}}
                        <div class="space-y-1">
                            <label for="approve_public_note" class="block font-bold text-ink">
                                یادداشت عمومی
                                <span class="font-normal text-xs text-muted">(پیشنهاددهنده آن را می‌بیند؛ برای «قول دیگر» و «منسوخ» در صفحه‌ی عمومی هم نمایش داده می‌شود)</span>
                                @if($previousAdopted)<span x-show="outcome === 'adopt'" class="text-cinnabar">الزامی</span>@endif
                                <span x-show="outcome === 'alternative'" class="text-cinnabar">الزامی</span>
                            </label>
                            <textarea id="approve_public_note" name="public_note" rows="2" placeholder="مثال: مقدار منتخب به استناد ترقیمه‌ی رساله‌ی هشتم همین مجموعه ترجیح داده شد." class="admin-field w-full">{{ old('public_note', $promoting ? $suggestion->public_note : '') }}</textarea>
                        </div>
                    @endif

                    <div class="space-y-1">
                        <label for="approve_admin_notes" class="block font-bold text-ink">یادداشت داخلی <span class="font-normal text-xs text-muted">(فقط داوران می‌بینند؛ اختیاری)</span></label>
                        <input id="approve_admin_notes" type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="توضیحات داوری…" class="admin-field w-full">
                    </div>

                    <button type="submit"
                            onclick="return confirm(@js($field ? 'این پیشنهاد تأیید و تغییر در پایگاه داده اعمال شود؟' : 'این پیشنهاد «رسیدگی‌شده» ثبت شود؟'))"
                            class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">
                        {{ $field ? 'تأیید و اعمال در پایگاه داده' : 'ثبت به‌عنوان رسیدگی‌شده' }}
                    </button>
                </form>
                @endif
            </x-ui.card>

            @unless($promoting)
            <x-ui.card class="space-y-4 !border-cinnabar/40">
                <h3 class="rubric text-base">رد پیشنهاد</h3>
                <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-4">
                    @csrf
                    <div class="space-y-1">
                        <label for="reject_public_note" class="block font-bold text-ink">علت رد <span class="font-normal text-xs text-muted">(یادداشت عمومی؛ پیشنهاددهنده آن را می‌بیند)</span></label>
                        <textarea id="reject_public_note" name="public_note" rows="3" placeholder="مثال: در ترقیمه‌ی نسخه تاریخ دیگری آمده است." class="admin-field w-full"></textarea>
                    </div>
                    <div class="space-y-1">
                        <label for="reject_admin_notes" class="block font-bold text-ink">یادداشت داخلی <span class="font-normal text-xs text-muted">(فقط داوران)</span></label>
                        <textarea id="reject_admin_notes" name="admin_notes" rows="3" placeholder="مثال: مستند ارائه‌شده کافی نبود یا در متن مأخذ چاپی به صراحت خلاف این آمده است…" class="admin-field w-full"></textarea>
                    </div>
                    <button type="submit" onclick="return confirm('این پیشنهاد رد شود؟')"
                            class="w-full py-2.5 px-4 rounded-sm border border-cinnabar text-cinnabar hover:bg-cinnabar/5 font-bold transition cursor-pointer">
                        رد پیشنهاد
                    </button>
                </form>
            </x-ui.card>
            @endunless

        </div>
    @endif

    @if($suggestion->status !== 'pending')
        {{-- Already reviewed --}}
        <x-ui.card class="space-y-3">
            <h3 class="rubric text-base">نتیجه‌ی داوری</h3>
            @if($suggestion->standing)
                <p>
                    <b class="text-ink">وضعیت قول:</b> {{ $suggestion->standing_label }}
                    @if($suggestion->supersededBy)
                        — جای خود را به <a href="{{ route('admin.suggestions.show', $suggestion->supersededBy) }}" class="text-cinnabar hover:underline">#{{ $fa($suggestion->superseded_by) }}</a> داد.
                    @endif
                    @if($suggestion->applied_value !== null)
                        <span class="block mt-1">مقدار ثبت‌شده: <b class="text-ink">{{ $suggestion->display_value }}</b></span>
                    @endif
                </p>
            @endif
            <x-ui.meta-list>
                <x-ui.meta label="یادداشت عمومی"><span class="whitespace-pre-line">{{ $suggestion->public_note ?: '—' }}</span></x-ui.meta>
                <x-ui.meta label="یادداشت داخلی"><span class="whitespace-pre-line">{{ $suggestion->admin_notes ?: 'هیچ یادداشتی ثبت نشده است.' }}</span></x-ui.meta>
            </x-ui.meta-list>
            @php $res = $suggestion->resolution ?? []; @endphp
            @if($suggestion->status === 'approved' && $res)
                <p>
                    @if(isset($res['kept_id']))
                        ادغام شد: رکورد <span class="tabular-nums">#{{ $fa($res['removed_id']) }}</span> در <span class="tabular-nums">#{{ $fa($res['kept_id']) }}</span>.
                    @elseif($suggestion->kind === 'relation' && isset($res['work_id']))
                        نسخه به اثر <a href="{{ route('works.show', $res['work_id']) }}" class="text-cinnabar hover:underline tabular-nums">#{{ $fa($res['work_id']) }}</a> منتقل شد.
                    @elseif(empty($res['applied']))
                        به‌صورت دستی رسیدگی شد (اعمال خودکار نداشت).
                    @else
                        در شناسنامه اعمال شد.
                        @if(($res['person_action'] ?? null) === 'link') پیوند به شخص <a href="{{ route('people.show', $res['person_id']) }}" class="text-cinnabar hover:underline tabular-nums">#{{ $fa($res['person_id']) }}</a>.
                        @elseif(($res['person_action'] ?? null) === 'create') شخص تازه ساخته شد: <a href="{{ route('people.show', $res['person_id']) }}" class="text-cinnabar hover:underline tabular-nums">#{{ $fa($res['person_id']) }}</a>.
                        @elseif(($res['person_action'] ?? null) === 'text') فقط متن نام ذخیره شد.
                        @endif
                        @if(array_key_exists('hijri_year', $res)) سال هجری: {{ $fa($res['hijri_year'] ?? '—') }}. @endif
                    @endif
                </p>
            @endif
            <p class="text-xs text-muted">داوری به دست {{ $suggestion->reviewer?->name ?: 'سامانه' }}، @jdate($suggestion->reviewed_at, 'datetime')</p>
            @php $reopenPermission = $suggestion->status === 'approved' ? (\App\Auth\Permissions::FOR_APPLY[$suggestion->relationDefinition()['apply'] ?? ''] ?? null) : null; @endphp
            @if(in_array($suggestion->status, ['approved', 'rejected'], true) && (! $reopenPermission || auth()->user()->can($reopenPermission)))
                <form action="{{ route('admin.suggestions.reopen', $suggestion) }}" method="POST" class="pt-3 border-t border-line flex flex-wrap items-center gap-3"
                      onsubmit="return confirm(@js($suggestion->status === 'rejected' ? 'این پیشنهاد به صف داوری بازگردد؟' : 'تغییری که این تأیید در رکورد داده بازگردانده شود و پیشنهاد دوباره داوری شود؟'))">
                    @csrf
                    <x-ui.btn type="submit">{{ $wasPromoted ? 'بازگرداندن به «قول دیگر»' : ($suggestion->status === 'rejected' ? 'لغو رد و بازگشت به صف داوری' : 'لغو تأیید و بازگشت به صف داوری') }}</x-ui.btn>
                    <span class="text-xs text-muted">
                        @if($suggestion->status === 'approved')
                            اگر داوری به اشتباه بوده، تغییر رکورد هم برمی‌گردد. پیام قبلی به پیشنهاددهنده پس گرفته نمی‌شود.
                        @else
                            اگر رد به اشتباه بوده، پیشنهاد دوباره در انتظار داوری قرار می‌گیرد.@if(in_array($suggestion->kind, ['note', 'feedback'], true)) پرونده‌های پیوست هنگام رد حذف شده‌اند.@endif
                        @endif
                    </span>
                </form>
            @endif
        </x-ui.card>
    @endif
    @endif

</div>
<x-catalog-viewer />
@endsection
