{{-- Approval of a field or general suggestion: the final value, the person link, the outcome and the notes; also adopts an alternative reading --}}
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
                فیلد «{{ $suggestion->field_label }}»@if(auth()->user()->isAdmin()) <code class="text-[11px] text-muted" dir="ltr">{{ $suggestion->field_name }}</code>@endif در فهرست فیلدهای قابل اصلاح نیست و خودکار اعمال نمی‌شود. تغییر را دستی انجام دهید. با تأیید، پیشنهاد «رسیدگی‌شده» ثبت می‌شود.
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
                <label for="hijri_year" class="block font-bold text-ink">سال هجری قمری برای مرتب‌سازی و جست‌وجو</label>
                <input id="hijri_year" type="number" name="hijri_year" min="{{ \App\Support\HijriYear::MIN }}" max="{{ \App\Support\HijriYear::MAX }}" value="{{ old('hijri_year', $parsedYear) }}" class="admin-field w-40 font-bold tabular-nums">
                <p class="text-xs text-muted">از متن پیشنهادی استخراج شد{{ $parsedYear ? '' : ' (سالی پیدا نشد)' }}. اگر تاریخ فقط سده است یا شمسی و میلادی است، خالی بگذارید.</p>
            </div>
        @endif

        @if($field['input'] === 'person')
            @php
                $defaultPick = old('person_pick', ! empty($suggestion->payload['clear']) ? 'clear' : ($people['suggested'] ? 'p:' . $people['suggested']->id : ($people['current'] ? 'text' : ($people['candidates']->isNotEmpty() ? 'p:' . $people['candidates']->first()->id : 'create'))));
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
                <label class="flex items-center gap-2 py-1">
                    <input type="radio" name="person_pick" value="clear" @checked($defaultPick === 'clear') class="accent-cinnabar">
                    <span>خالی کردن فیلد: نام{{ $people['current'] ? ' و پیوند' : '' }} برداشته می‌شود{{ ! empty($field['autograph_column']) && $target?->{$field['autograph_column']} ? ' و نسخه دیگر «دستخط مؤلف» نیست' : '' }} (متن بالا نادیده گرفته می‌شود)</span>
                </label>
            </fieldset>
        @endif

        {{-- Outcome: a reading (adopted or kept as alternative) or an edit that is not a reading --}}
        @if($promoting)
            <input type="hidden" name="outcome" value="adopt">
        @else
            {{-- Most approvals adopt the reading: the other outcomes wait in a closed box (step 14.72) --}}
            @php
                $outcomes = [
                    'adopt' => ['قول علمی: اعمال به‌عنوان مقدار منتخب', 'با نام پیشنهاددهنده (اگر رضایت داده) در صفحه نمایش داده می‌شود.', 'قول منتخب'],
                    'alternative' => ['قول علمی: نگه‌داشتن به‌عنوان «قول دیگر»', 'مقدار رکورد تغییر نمی‌کند؛ این قول با مستندش در پایین صفحه می‌ماند.', 'قول دیگر'],
                    'ocr_fix' => ['ویرایش: غلط چاپی یا نویسه‌خوان (OCR)', 'اعمال می‌شود ولی قول به‌شمار نمی‌آید.', 'غلط چاپی یا OCR'],
                    'parser_fix' => ['ویرایش: خطای پارسر (تفکیک اشتباه فیلدها)', 'اعمال می‌شود ولی قول به‌شمار نمی‌آید.', 'خطای پارسر'],
                ];
            @endphp
            <details class="border border-line bg-paper px-3 py-2" data-outcome @if(old('outcome', 'adopt') !== 'adopt') open @endif>
                <summary class="cursor-pointer text-ink">
                    <b>نوع اصلاح:</b>
                    @foreach($outcomes as $value => [, , $short])
                        <span x-show="outcome === @js($value)" @if($value !== old('outcome', 'adopt')) x-cloak @endif>{{ $short }}</span>
                    @endforeach
                    <span class="text-xs text-muted">(تغییر)</span>
                </summary>
                <fieldset class="space-y-1.5 pt-2">
                    <legend class="sr-only">نتیجه‌ی داوری</legend>
                    @foreach($outcomes as $value => [$label, $hint])
                        <label class="flex items-start gap-2 py-0.5 cursor-pointer">
                            <input type="radio" name="outcome" value="{{ $value }}" x-model="outcome" class="mt-1.5 accent-cinnabar">
                            <span><b class="text-ink">{{ $label }}</b> <span class="text-xs text-muted">— {{ $hint }}</span></span>
                        </label>
                    @endforeach
                </fieldset>
            </details>
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

    {{-- What the approval will do, in one line --}}
    <p class="px-3 py-2 bg-paper border border-line text-xs text-ink leading-6" data-approve-summary>
        <b>با تأیید:</b>
        @if(! $field)
            پیشنهاد «رسیدگی‌شده» ثبت می‌شود و چیزی خودکار تغییر نمی‌کند.
        @elseif($promoting)
            این قول مقدار منتخب «{{ $field['label'] }}» می‌شود و در رکورد جای مقدار کنونی را می‌گیرد.
        @else
            <span x-show="outcome === 'adopt'">«{{ $field['label'] }}» این {{ $typeLabel }} با مقدار نهایی بالا جایگزین می‌شود{{ $previousAdopted ? ' و مقدار منتخب پیشین قول دیگر یا منسوخ می‌شود' : '' }}.</span>
            <span x-show="outcome === 'alternative'" x-cloak>رکورد تغییر نمی‌کند؛ این قول با مستندش «قول دیگر» ثبت می‌شود.</span>
            <span x-show="outcome === 'ocr_fix' || outcome === 'parser_fix'" x-cloak>«{{ $field['label'] }}» این {{ $typeLabel }} با مقدار نهایی بالا جایگزین می‌شود، ولی قول به‌شمار نمی‌آید.</span>
        @endif
    </p>

    <button type="submit"
            onclick="return confirm(@js($field ? 'این پیشنهاد تأیید و تغییر در پایگاه داده اعمال شود؟' : 'این پیشنهاد «رسیدگی‌شده» ثبت شود؟'))"
            class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">
        {{ $field ? 'تأیید و اعمال در پایگاه داده' : 'ثبت به‌عنوان رسیدگی‌شده' }}
    </button>
</form>
