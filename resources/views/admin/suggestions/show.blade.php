@extends('layouts.admin')

@section('title', "بررسی پیشنهاد اصلاحی #{$suggestion->id}")
@section('page_title', "داوری و بازبینی پیشنهاد اصلاحی #{$suggestion->id}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.suggestions.index') }}" class="hover:text-cinnabar">پیشنهادات اصلاحی</a>
    <span>/</span>
    <span class="text-stone-400">داوری رکورد #{{ $suggestion->id }}</span>
@endsection

@section('content')
<div class="max-w-5xl mx-auto space-y-8">

    <!-- Header & Target Info Card -->
    <div class="p-6 rounded-2xl bg-surface border border-line shadow-xs space-y-4">
        
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
            <div class="flex items-center gap-3">
                <span class="px-3 py-1 rounded-xl text-xs font-black bg-ink text-paper">
                    {{ \Illuminate\Support\Str::limit($suggestion->place_label, 60) }}
                </span>
                <span class="text-sm font-bold text-ink">
                    @if($suggestion->kind === 'general')
                        پیشنهاد کلی (متن آزاد)
                    @elseif($suggestion->kind === 'relation')
                        پیشنهاد رابطه‌ای: <span class="text-cinnabar">{{ $relation['label'] ?? $suggestion->field_name }}</span>
                    @else
                        فیلد هدف: <span class="text-cinnabar">{{ $suggestion->field_label }}</span>
                        <code class="text-stone-400 font-mono text-[11px]">{{ $suggestion->field_name }}</code>
                    @endif
                </span>
            </div>

            <!-- Status Indicator -->
            <div>
                @if($suggestion->status === 'pending')
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-amber-500/15 text-amber-800 dark:text-amber-300">
                        در انتظار داوری
                    </span>
                @elseif($suggestion->status === 'approved')
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-800 dark:text-emerald-300">
                        {{ $suggestion->kind === 'note' ? 'منتشر شد' : 'تایید و اعمال شد' }} (توسط {{ $suggestion->reviewer?->name ?: 'مدیر' }})
                    </span>
                @elseif(in_array($suggestion->status, ['converted', 'withdrawn'], true))
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-sky-500/15 text-sky-800 dark:text-sky-300">
                        {{ $suggestion->status === 'converted' ? 'تبدیل شد' : 'از صفحه برداشته شد' }}
                    </span>
                @else
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-red-500/15 text-red-800 dark:text-red-300">
                        رد شد (توسط {{ $suggestion->reviewer?->name ?: 'مدیر' }})
                    </span>
                @endif
            </div>
        </div>

        <!-- Entity Metadata Summary -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs text-ink/80">
            <div>
                <span class="text-stone-400">پژوهشگر ارائه‌دهنده:</span>
                <x-ui.avatar :user="$suggestion->user" :name="$suggestion->guest_name" size="sm" class="align-middle ms-1" />
                <strong class="text-ink mr-1">{{ $suggestion->guest_name ?: 'پژوهشگر مهمان' }}</strong>
                @if($suggestion->guest_email)
                    <span class="text-stone-400 dir-ltr font-mono text-[11px] block mt-0.5">({{ $suggestion->guest_email }})</span>
                @endif
            </div>
            @unless($suggestion->kind === 'feedback')
            <div class="md:col-span-2 p-3 rounded-xl bg-paper border border-line-strong space-y-2">
                <span class="text-stone-400">نام در صفحه‌ی عمومی:</span>
                @if($suggestion->credit_name)
                    <strong class="text-emerald-700 dark:text-emerald-400 mr-1">{{ $suggestion->credit_name }}</strong>
                    <span class="text-[11px] text-stone-400">{{ $suggestion->status === 'approved' ? '(نمایش داده می‌شود)' : '(پس از تأیید نمایش داده می‌شود)' }}</span>
                @else
                    <span class="mr-1">بدون نام</span>
                @endif
                <form action="{{ route('admin.suggestions.credit', $suggestion) }}" method="POST" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="text" name="credit_name" value="{{ old('credit_name', $suggestion->credit_name) }}" maxlength="150"
                        placeholder="فقط با رضایت پیشنهاددهنده؛ خالی یعنی بدون نام"
                        class="flex-1 min-w-48 p-2 rounded-lg border border-line-strong bg-white dark:bg-stone-900 text-ink">
                    <button type="submit" class="px-3 py-2 rounded-lg bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold">ذخیره‌ی نام</button>
                </form>
            </div>
            @endunless
            <div>
                <span class="text-stone-400">تاریخ ارسال:</span>
                <span class="font-mono text-ink mr-1">@jdate($suggestion->created_at, 'datetime')</span>
                <span class="text-stone-400 text-[10px]">(@jago($suggestion->created_at))</span>
            </div>
        </div>

        @if($target)
            <div class="pt-2">
                @php
                    $base = class_basename($suggestion->suggestable_type);
                    $targetUrl = match($base) {
                        'Manuscript' => route('manuscripts.show', $target),
                        'Work' => route('works.show', $target),
                        'Person' => route('people.show', $target),
                        'Library' => route('libraries.show', $target),
                        default => '#'
                    };
                @endphp
                <a href="{{ $targetUrl }}" target="_blank" class="inline-flex items-center gap-1.5 text-xs text-cinnabar hover:underline font-bold">
                    <span>مشاهده صفحه عمومی این رکورد در سایت</span>
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
            </div>
        @endif

    </div>

    {{-- Links between a converted suggestion and the ones made from it --}}
    @php
        $derivedIds = $suggestion->resolution['converted_to'] ?? [];
        $sourceId = $suggestion->payload['derived_from'] ?? null;
    @endphp
    @if($derivedIds || $sourceId)
        <div class="p-4 rounded-2xl bg-sky-50 dark:bg-sky-950/20 border border-sky-200 dark:border-sky-900 text-xs text-sky-900 dark:text-sky-200 space-y-1">
            @if($sourceId)
                <p>این پیشنهاد را داور از روی <a href="{{ route('admin.suggestions.show', $sourceId) }}" class="font-bold underline">پیشنهاد #{{ $sourceId }}</a> ساخته است.</p>
            @endif
            @if($derivedIds)
                <p>پیشنهادهای ساخته‌شده از این پیشنهاد:
                    @foreach(\App\Models\FieldSuggestion::whereIn('id', $derivedIds)->get() as $d)
                        <a href="{{ route('admin.suggestions.show', $d) }}" class="font-bold underline">#{{ $d->id }} ({{ $d->field_label }})</a>@if(! $loop->last)، @endif
                    @endforeach
                </p>
            @endif
        </div>
    @endif

    @if($suggestion->status === 'pending')
        @include('admin.suggestions.partials.convert')
    @endif

    @if($suggestion->kind === 'note')
        @include('admin.suggestions.partials.note-review')
    @elseif($suggestion->kind === 'feedback')
        @include('admin.suggestions.partials.feedback-review')
    @else
    @if($suggestion->kind === 'relation')
        <!-- The two records the suggestion connects -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach(array_filter([['این رکورد', $target], $relation && (! $relation['target'] || (! empty($relation['target_optional']) && ! $relationTarget)) ? null : [$relation && $relation['key'] === 'move_to_work' ? 'اثر پیشنهادی' : ($relation && $relation['target'] === 'person' ? 'شخص' : ($relation && $relation['target'] === 'library' ? (($relation['apply'] ?? null) === 'relocate' ? 'کتابخانه‌ی پیشنهادی' : 'کتابخانه‌ی مقصد') : 'رکورد مقصد')), $relationTarget]]) as [$caption, $record])
                <div class="p-6 rounded-2xl bg-surface border border-line shadow-xs space-y-2 text-xs">
                    <h4 class="font-bold text-stone-500">{{ $caption }}</h4>
                    @if($record)
                        @php $info = \App\Suggestions\SuggestionRegistry::describe($record); @endphp
                        <a href="{{ $info['url'] }}" target="_blank" class="block text-sm font-bold text-ink hover:text-cinnabar">{{ $info['label'] }}</a>
                        <div class="text-stone-500"><span class="font-mono">#{{ $info['id'] }}</span>{{ $info['sub'] ? ' · ' . $info['sub'] : '' }}</div>
                    @else
                        <div class="text-red-600 font-bold">این رکورد دیگر وجود ندارد (شاید ادغام یا حذف شده است).</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <!-- Side-by-Side Comparison Box -->
    <div class="grid grid-cols-1 {{ $suggestion->kind === 'field' ? 'md:grid-cols-2' : '' }} gap-6">
        @if($suggestion->kind === 'field')
        
        <!-- Current DB Value -->
        <div class="p-6 rounded-2xl bg-surface border border-line shadow-xs space-y-3">
            <div class="flex items-center gap-2 text-stone-500">
                <span class="w-2.5 h-2.5 rounded-full bg-stone-400"></span>
                <h4 class="text-xs font-bold uppercase tracking-wider">مقدار موجود در پایگاه داده (یا متن مأخذ چاپی)</h4>
            </div>
            <div class="p-4 rounded-xl bg-stone-50 dark:bg-stone-900 border border-line text-ink text-sm leading-relaxed min-h-[90px] font-sans whitespace-pre-line">{{ $suggestion->current_value ?: '— فیلد خالی است —' }}</div>
        </div>

        @endif

        <!-- Suggested Value by Researcher -->
        <div class="p-6 rounded-2xl bg-amber-500/5 border-2 border-amber-500/30 rounded-2xl shadow-xs space-y-3">
            <div class="flex items-center gap-2 text-amber-700 dark:text-amber-400">
                <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                <h4 class="text-xs font-bold uppercase tracking-wider">مقدار پیشنهادی پژوهشگر</h4>
            </div>
            <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-stone-900 dark:text-amber-100 text-sm font-semibold leading-relaxed min-h-[90px] font-sans whitespace-pre-line">{{ $suggestion->suggested_value }}</div>
            @if(! empty($suggestion->payload['shelfmark']))
                <div class="text-xs text-ink">شماره‌ی تازه‌ی پیشنهادی: <strong class="text-sm">{{ $suggestion->payload['shelfmark'] }}</strong></div>
            @endif
        </div>

    </div>

    <!-- Rationale and Citation Box -->
    <div class="p-6 rounded-2xl bg-surface border border-line shadow-xs space-y-3">
        <div class="flex items-center gap-2 text-ink">
            <svg class="w-4 h-4 text-cinnabar" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
            </svg>
            <h4 class="text-xs font-bold">مستند علمی، ترقیمه و منبع ادعای پژوهشگر:</h4>
        </div>
        <div class="p-4 rounded-xl bg-stone-50 dark:bg-stone-900 border border-line text-xs text-ink leading-relaxed font-sans whitespace-pre-line">{{ $suggestion->rationale_citation ?: '— مستندی ذکر نشده است —' }}</div>
    </div>

    @php $promoting = $suggestion->status === 'approved' && $suggestion->standing === 'alternative'; @endphp
    @if($suggestion->status === 'pending' || $promoting)
        <!-- Action Desk: Approve & Reject Forms (an alternative reading can still be adopted) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-2">
            
            <!-- Approval Form (Takes 2 columns) -->
            <div class="lg:col-span-2 p-6 rounded-2xl bg-surface border border-emerald-500/30 shadow-xs space-y-4">
                <div class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <h3 class="text-sm font-bold">{{ $relation ? $relation['action'] : ($promoting ? 'انتخاب این «قول دیگر» به‌عنوان مقدار منتخب' : ($field ? 'تأیید و اعمال اصلاحیه در پایگاه داده' : 'رسیدگی به پیشنهاد')) }}</h3>
                </div>

                @if($relation)
                    @include('admin.suggestions.partials.relation-approve')
                @else
                <form action="{{ route('admin.suggestions.approve', $suggestion) }}" method="POST" class="space-y-4 text-xs"
                    x-data="{ outcome: @js(old('outcome', 'adopt')) }">
                    @csrf

                    @if($errors->any())
                        <div class="p-3 rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 font-bold">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    @if(! $field)
                        <div class="p-4 rounded-xl bg-sky-50 dark:bg-sky-950/30 border border-sky-200 dark:border-sky-900 text-sky-900 dark:text-sky-200 leading-relaxed">
                            @if($suggestion->kind === 'general')
                                این پیشنهاد کلی است و خودکار اعمال نمی‌شود. تغییرات لازم را دستی انجام دهید. با تأیید، پیشنهاد «رسیدگی‌شده» ثبت می‌شود.
                            @else
                                فیلد <code class="font-mono">{{ $suggestion->field_name }}</code> در فهرست فیلدهای قابل اصلاح نیست و خودکار اعمال نمی‌شود. تغییر را دستی انجام دهید. با تأیید، پیشنهاد «رسیدگی‌شده» ثبت می‌شود.
                            @endif
                        </div>
                    @elseif($field['input'] === 'boolean')
                        <div class="space-y-1">
                            <label class="block font-bold text-ink">مقدار نهایی «{{ $field['label'] }}»:</label>
                            @php $boolDefault = old('applied_value', in_array(trim($suggestion->applied_value ?? $suggestion->suggested_value), ['بله', '1', 'دارد', 'هست', 'true'], true) ? '1' : '0'); @endphp
                            <div class="flex gap-4 pt-1">
                                <label class="inline-flex items-center gap-1.5 font-bold"><input type="radio" name="applied_value" value="1" @checked($boolDefault === '1') class="text-cinnabar"> بله</label>
                                <label class="inline-flex items-center gap-1.5 font-bold"><input type="radio" name="applied_value" value="0" @checked($boolDefault === '0') class="text-cinnabar"> خیر</label>
                            </div>
                        </div>
                    @else
                        <div class="space-y-1">
                            <label class="block font-bold text-ink">
                                مقدار نهایی «{{ $field['label'] }}» (می‌توانید پیش از تأیید ویرایش کنید):
                            </label>
                            <textarea name="applied_value" rows="{{ in_array($field['input'], ['quote', 'list', 'textarea'], true) ? 5 : 2 }}" class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink font-semibold focus:border-gold">{{ old('applied_value', $suggestion->applied_value ?? $suggestion->suggested_value) }}</textarea>
                            @if($field['input'] === 'catalog_page')
                                @php
                                    $pageRef = isset($field['entry_id']) ? \App\Models\SourceEntry::with(['source', 'sourceVolume'])->find($field['entry_id']) : null;
                                    $suggestedPage = \App\Suggestions\SuggestionRegistry::parseCatalogPage($suggestion->applied_value ?? $suggestion->suggested_value);
                                @endphp
                                <p class="text-stone-400">
                                    به شکل «ج ۲، ص ۷»@if(! empty($field['with_row']))، و «، ردیف ۳» اگر ردیف هم تغییر کرده است@endif.
                                    @if(! empty($field['can_be_absent'])) «نیست» یعنی نسخه در این فهرست نیامده است و پیوندش از صفحه‌ی نسخه برداشته می‌شود. با تأیید، نشانی «دستی و بازبینی‌شده» می‌شود و نشان ⓘ برداشته می‌شود.@endif
                                </p>
                                @if($pageRef && ($viewer = $pageRef->viewer()))
                                    <div class="flex flex-wrap gap-2 pt-1">
                                        <button type="button" @click="$dispatch('open-catalog-viewer', @js($viewer))" class="px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 font-bold text-ink hover:bg-cinnabar hover:text-white transition cursor-pointer">برگه‌ی فعلی (ص {{ \App\Support\Fa::digits($viewer['page']) }})</button>
                                        @if($suggestedPage)
                                            <button type="button" @click="$dispatch('open-catalog-viewer', @js(['volume' => $suggestedPage['volume'], 'page' => $suggestedPage['page']] + $viewer))" class="px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 font-bold text-ink hover:bg-cinnabar hover:text-white transition cursor-pointer">برگه‌ی پیشنهادی (ص {{ \App\Support\Fa::digits($suggestedPage['page']) }})</button>
                                        @endif
                                    </div>
                                @endif
                            @elseif($field['input'] === 'list')
                                <p class="text-stone-400">هر مورد در یک سطر. فهرست فعلی به‌طور کامل جایگزین می‌شود.</p>
                            @elseif($field['input'] === 'quote' && count($target?->metadata[$field['meta_key']] ?? []) > 1)
                                <p class="text-amber-700 dark:text-amber-400 font-bold">این نسخه اکنون {{ count($target->metadata[$field['meta_key']]) }} مورد ثبت‌شده دارد. با تأیید، همه با همین یک متن جایگزین می‌شوند.</p>
                            @endif
                        </div>

                        @include('admin.suggestions.partials.retext')

                        @if($field['input'] === 'hijri_date')
                            <div class="space-y-1">
                                <label class="block font-bold text-ink">سال هجری قمری برای مرتب‌سازی و جستجو:</label>
                                <input type="number" name="hijri_year" min="{{ \App\Support\HijriYear::MIN }}" max="{{ \App\Support\HijriYear::MAX }}" value="{{ old('hijri_year', $parsedYear) }}" class="w-40 p-2.5 rounded-xl border border-line-strong bg-paper text-ink font-mono font-bold">
                                <p class="text-stone-400">از متن پیشنهادی استخراج شد{{ $parsedYear ? '' : ' (سالی پیدا نشد)' }}. اگر تاریخ فقط سده است یا شمسی و میلادی است، خالی بگذارید.</p>
                            </div>
                        @endif

                        @if($field['input'] === 'person')
                            @php
                                $defaultPick = old('person_pick', $people['suggested'] ? 'p:' . $people['suggested']->id : ($people['current'] ? 'text' : ($people['candidates']->isNotEmpty() ? 'p:' . $people['candidates']->first()->id : 'create')));
                            @endphp
                            <div class="space-y-2 p-4 rounded-xl bg-stone-50 dark:bg-stone-900 border border-line">
                                <div class="font-bold text-ink">پیوند به جدول اشخاص:</div>

                                @foreach(array_filter(['suggested' => $people['suggested'], 'current' => $people['current']]) as $role => $person)
                                    @include('admin.suggestions.partials.person-option', ['person' => $person, 'note' => $role === 'suggested' ? 'انتخاب پیشنهاددهنده' : ($field['role'] === 'author' ? 'پدیدآور فعلی پیوندخورده' : 'کاتب فعلی پیوندخورده'), 'checked' => $defaultPick === 'p:' . $person->id])
                                @endforeach

                                @foreach($people['candidates'] as $person)
                                    @include('admin.suggestions.partials.person-option', ['person' => $person, 'note' => null, 'checked' => $defaultPick === 'p:' . $person->id])
                                @endforeach

                                <label class="flex items-center gap-2 py-1">
                                    <input type="radio" name="person_pick" value="manual" @checked($defaultPick === 'manual') class="text-cinnabar">
                                    <span>پیوند به شخص با شناسه‌ی</span>
                                    <input type="number" name="person_manual_id" value="{{ old('person_manual_id') }}" placeholder="مثلاً 1234" class="w-28 p-1.5 rounded-lg border border-line-strong bg-white dark:bg-stone-800 font-mono">
                                </label>
                                <label class="flex items-center gap-2 py-1">
                                    <input type="radio" name="person_pick" value="create" @checked($defaultPick === 'create') class="text-cinnabar">
                                    <span>ساخت شخص تازه با همین نام</span>
                                </label>
                                <label class="flex items-center gap-2 py-1">
                                    <input type="radio" name="person_pick" value="text" @checked($defaultPick === 'text') class="text-cinnabar">
                                    <span>فقط ذخیره‌ی متن نام{{ $people['current'] ? ' (پیوند فعلی دست نمی‌خورد)' : ' (بدون پیوند)' }}</span>
                                </label>
                            </div>
                        @endif

                        <!-- Outcome: a reading (adopted or kept as alternative) or an edit that is not a reading -->
                        @if($promoting)
                            <input type="hidden" name="outcome" value="adopt">
                        @else
                            <div class="space-y-1.5">
                                <label class="block font-bold text-ink">نتیجه‌ی داوری:</label>
                                @foreach([
                                    'adopt' => ['قول علمی: اعمال به‌عنوان مقدار منتخب', 'با نام پیشنهاددهنده (اگر رضایت داده) در صفحه نمایش داده می‌شود.'],
                                    'alternative' => ['قول علمی: نگه‌داشتن به‌عنوان «قول دیگر»', 'مقدار رکورد تغییر نمی‌کند؛ این قول با مستندش در پایین صفحه می‌ماند.'],
                                    'ocr_fix' => ['ویرایش: غلط چاپی یا نویسه‌خوان (OCR)', 'اعمال می‌شود ولی قول به‌شمار نمی‌آید.'],
                                    'parser_fix' => ['ویرایش: خطای پارسر (تفکیک اشتباه فیلدها)', 'اعمال می‌شود ولی قول به‌شمار نمی‌آید.'],
                                ] as $value => [$label, $hint])
                                    <label class="flex items-start gap-2 py-0.5 cursor-pointer">
                                        <input type="radio" name="outcome" value="{{ $value }}" x-model="outcome" class="mt-0.5 text-cinnabar">
                                        <span><span class="font-bold text-ink">{{ $label }}</span> <span class="text-stone-400">— {{ $hint }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        @if($previousAdopted)
                            <div x-show="outcome === 'adopt'" class="space-y-2 p-4 rounded-xl bg-amber-50 dark:bg-amber-950/20 border border-amber-300 dark:border-amber-800">
                                <div class="font-bold text-amber-900 dark:text-amber-200">
                                    این فیلد مقدار منتخب دارد: «{{ \Illuminate\Support\Str::limit($previousAdopted->display_value, 120) }}»
                                    <span class="font-normal">(قائل: {{ $previousAdopted->credit_name ?: $previousAdopted->guest_name ?: 'نامشخص' }}، <a href="{{ route('admin.suggestions.show', $previousAdopted) }}" class="underline">#{{ $previousAdopted->id }}</a>)</span>
                                </div>
                                <div class="text-ink/80">با تأیید این پیشنهاد، آن قول:</div>
                                <label class="flex items-center gap-2"><input type="radio" name="previous_standing" value="alternative" @checked(old('previous_standing') === 'alternative') class="text-cinnabar"> <span><strong>قول دیگر</strong> می‌شود (هنوز قابل دفاع است)</span></label>
                                <label class="flex items-center gap-2"><input type="radio" name="previous_standing" value="superseded" @checked(old('previous_standing') === 'superseded') class="text-cinnabar"> <span><strong>منسوخ</strong> می‌شود (نادرست بودنش روشن شد؛ در «سابقه» می‌ماند)</span></label>
                            </div>
                        @endif

                    @endif

                    @if($field)
                        <!-- Public note: required for an alternative, and when an adopted reading is replaced -->
                        <div class="space-y-1">
                            <label class="block font-bold text-ink">
                                یادداشت عمومی
                                <span class="font-normal text-stone-400">(پیشنهاددهنده آن را می‌بیند؛ برای «قول دیگر» و «منسوخ» در صفحه‌ی عمومی هم نمایش داده می‌شود)</span>
                                @if($previousAdopted)<span x-show="outcome === 'adopt'" class="text-red-600">الزامی</span>@endif
                                <span x-show="outcome === 'alternative'" class="text-red-600">الزامی</span>
                            </label>
                            <textarea name="public_note" rows="2" placeholder="مثال: مقدار منتخب به استناد ترقیمه‌ی رساله‌ی هشتم همین مجموعه ترجیح داده شد." class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink">{{ old('public_note', $promoting ? $suggestion->public_note : '') }}</textarea>
                        </div>
                    @endif

                    <!-- Internal note -->
                    <div class="space-y-1">
                        <label class="block font-bold text-ink">یادداشت داخلی <span class="font-normal text-stone-400">(فقط داوران می‌بینند؛ اختیاری)</span>:</label>
                        <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="توضیحات داوری..." class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink">
                    </div>

                    <div class="pt-2">
                        <button type="submit" 
                                onclick="return confirm('{{ $field ? 'آیا از تأیید این پیشنهاد و اعمال تغییر در پایگاه داده اطمینان دارید؟' : 'این پیشنهاد «رسیدگی‌شده» ثبت شود؟' }}')"
                                class="w-full py-3 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-md transition flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>{{ $field ? 'تأیید قطعی و اعمال در پایگاه داده' : 'ثبت به‌عنوان رسیدگی‌شده' }}</span>
                        </button>
                    </div>
                </form>
                @endif
            </div>

            <!-- Rejection Form (Takes 1 column) -->
            @unless($promoting)
            <div class="p-6 rounded-2xl bg-surface border border-red-500/30 shadow-xs space-y-4">
                <div class="flex items-center gap-2 text-red-600 dark:text-red-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <h3 class="text-sm font-bold">رد پیشنهاد</h3>
                </div>

                <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-4 text-xs">
                    @csrf

                    <div class="space-y-1">
                        <label class="block font-bold text-ink">علت رد <span class="font-normal text-stone-400">(یادداشت عمومی؛ پیشنهاددهنده آن را می‌بیند)</span>:</label>
                        <textarea name="public_note" rows="3" placeholder="مثال: در ترقیمه‌ی نسخه تاریخ دیگری آمده است." class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink"></textarea>
                    </div>
                    <div class="space-y-1">
                        <label class="block font-bold text-ink">یادداشت داخلی <span class="font-normal text-stone-400">(فقط داوران)</span>:</label>
                        <textarea name="admin_notes" rows="3" placeholder="مثال: مستند ارائه شده کافی نبود یا در متن مأخذ چاپی به صراحت خلاف این ذکر شده است..." class="w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink"></textarea>
                    </div>

                    <div class="pt-2">
                        <button type="submit" 
                                onclick="return confirm('آیا از رد این پیشنهاد اطمینان دارید؟')"
                                class="w-full py-2.5 px-4 rounded-xl border border-red-500 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20 font-bold text-xs transition flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            <span>رد پیشنهاد اصلاحی</span>
                        </button>
                    </div>
                </form>
            </div>
            @endunless

        </div>
    @endif

    @if($suggestion->status !== 'pending')
        <!-- Already reviewed notice -->
        <div class="p-6 rounded-2xl bg-paper border border-line-strong text-xs text-ink/80 space-y-2">
            @if($suggestion->standing)
                <p>
                    <span class="font-bold text-ink">وضعیت قول:</span> {{ $suggestion->standing_label }}
                    @if($suggestion->supersededBy)
                        — جای خود را به <a href="{{ route('admin.suggestions.show', $suggestion->supersededBy) }}" class="text-cinnabar hover:underline">#{{ $suggestion->superseded_by }}</a> داد.
                    @endif
                    @if($suggestion->applied_value !== null)
                        <span class="block mt-1">مقدار ثبت‌شده: <strong class="text-ink">{{ $suggestion->display_value }}</strong></span>
                    @endif
                </p>
            @endif
            <h4 class="font-bold text-ink">یادداشت عمومی:</h4>
            <p class="whitespace-pre-line">{{ $suggestion->public_note ?: '—' }}</p>
            <h4 class="font-bold text-ink">یادداشت داخلی داور:</h4>
            <p class="whitespace-pre-line">{{ $suggestion->admin_notes ?: 'هیچ یادداشتی ثبت نشده است.' }}</p>
            @php $res = $suggestion->resolution ?? []; @endphp
            @if($suggestion->status === 'approved' && $res)
                <p class="pt-1">
                    @if(isset($res['kept_id']))
                        ادغام شد: رکورد <span class="font-mono">#{{ $res['removed_id'] }}</span> در <span class="font-mono">#{{ $res['kept_id'] }}</span>.
                    @elseif($suggestion->kind === 'relation' && isset($res['work_id']))
                        نسخه به اثر <a href="{{ route('works.show', $res['work_id']) }}" class="text-cinnabar hover:underline font-mono">#{{ $res['work_id'] }}</a> منتقل شد.
                    @elseif(empty($res['applied']))
                        به‌صورت دستی رسیدگی شد (اعمال خودکار نداشت).
                    @else
                        در شناسنامه اعمال شد.
                        @if(($res['person_action'] ?? null) === 'link') پیوند به شخص <a href="{{ route('people.show', $res['person_id']) }}" class="text-cinnabar hover:underline font-mono">#{{ $res['person_id'] }}</a>.
                        @elseif(($res['person_action'] ?? null) === 'create') شخص تازه ساخته شد: <a href="{{ route('people.show', $res['person_id']) }}" class="text-cinnabar hover:underline font-mono">#{{ $res['person_id'] }}</a>.
                        @elseif(($res['person_action'] ?? null) === 'text') فقط متن نام ذخیره شد.
                        @endif
                        @if(array_key_exists('hijri_year', $res)) سال هجری: {{ $res['hijri_year'] ?? '—' }}. @endif
                    @endif
                </p>
            @endif
            <div class="text-[10px] text-stone-400 pt-2 font-mono">
                داوری توسط {{ $suggestion->reviewer?->name ?: 'سیستم' }} در تاریخ @jdate($suggestion->reviewed_at, 'datetime')
            </div>
            @if(in_array($suggestion->status, ['approved', 'rejected'], true))
                <form action="{{ route('admin.suggestions.reopen', $suggestion) }}" method="POST" class="pt-3 mt-2 border-t border-line flex flex-wrap items-center gap-3"
                      onsubmit="return confirm('{{ $suggestion->status === 'rejected' ? 'این پیشنهاد به صف داوری بازگردد؟' : 'تغییری که این تأیید در رکورد داده بازگردانده شود و پیشنهاد دوباره داوری شود؟' }}')">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 rounded-xl border border-amber-500/40 bg-amber-500/10 hover:bg-amber-500/20 text-amber-800 dark:text-amber-300 font-bold transition flex items-center gap-1.5 cursor-pointer">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                        <span>{{ $wasPromoted ? 'بازگرداندن به «قول دیگر»' : ($suggestion->status === 'rejected' ? 'لغو رد و بازگشت به صف داوری' : 'لغو تأیید و بازگشت به صف داوری') }}</span>
                    </button>
                    <span class="text-[11px] text-stone-400">
                        @if($suggestion->status === 'approved')
                            اگر داوری به اشتباه بوده، تغییر رکورد هم برمی‌گردد. پیام قبلی به پیشنهاددهنده پس گرفته نمی‌شود.
                        @else
                            اگر رد به اشتباه بوده، پیشنهاد دوباره در انتظار داوری قرار می‌گیرد.@if(in_array($suggestion->kind, ['note', 'feedback'], true)) پرونده‌های پیوست هنگام رد حذف شده‌اند.@endif
                        @endif
                    </span>
                </form>
            @endif
        </div>
    @endif
    @endif

</div>
<x-catalog-viewer />
@endsection
