{{-- Turn a misfiled suggestion into a standard one of any kind on this or another record, or into site feedback; see SuggestionController::convert.
     Every choice comes from SuggestionRegistry (kinds, fields with the record's dynamic address fields, notes, relations with their options),
     so a new kind of suggestion reaches this form by itself (backlog item 68). --}}
@php
    use App\Suggestions\NoteTypes;
    use App\Suggestions\SuggestionRegistry;

    $ownType = SuggestionRegistry::typeKey($suggestion->suggestable_type) ?? 'manuscript';
    $hasRecord = (bool) $suggestion->suggestable_type;
    $types = [];
    foreach (array_keys(SuggestionRegistry::TYPES) as $t) {
        $types[$t] = [
            'label' => SuggestionRegistry::typeLabel($t),
            // The suggestion's own record lists its dynamic fields at once; another record's come from convertFields
            'fields' => array_values(SuggestionRegistry::fieldChoices($t, $hasRecord && $t === $ownType ? $target : null)),
            'notes' => array_values(NoteTypes::forRecord($t)),
            'relations' => SuggestionRegistry::relationChoices($t),
        ];
    }
    $prefill = $suggestion->kind === 'note' ? ($suggestion->payload['text'] ?? $suggestion->suggested_value) : $suggestion->suggested_value;
    $setup = [
        'types' => $types,
        'kinds' => SuggestionRegistry::KIND_LABELS,
        'feedbackCategories' => SuggestionRegistry::FEEDBACK_CATEGORIES,
        'noteFields' => NoteTypes::FIELDS,
        'ownType' => $ownType,
        'targetType' => old('target_type', $ownType),
        'targetRef' => old('target_ref', ''),
        'kind' => old('kind', $suggestion->kind === 'feedback' && ! $hasRecord ? 'general' : 'field'),
        'fieldName' => old('field_name', ''),
        'relationTarget' => old('relation_target', ''),
        'role' => old('role', ''),
        'value' => old('suggested_value', $prefill ?? ''),
        'personId' => old('person_id', ''),
        'fieldsUrl' => route('admin.suggestions.convert-fields', $suggestion),
        'lookupUrl' => route('suggestions.lookup'),
        'peopleUrl' => route('suggestions.people'),
        'otherRecord' => (bool) old('target_ref') || ! $hasRecord,
        'prefill' => $prefill,
    ];
    $input = 'admin-field w-full';
    $errorKeys = ['target_ref', 'target_type', 'kind', 'field_name', 'suggested_value', 'relation_target', 'target_id', 'role', 'role_name', 'shelfmark', 'person_id'];
@endphp
<details @if($errors->hasAny($errorKeys) || $errors->has('note.*')) open @endif
    class="p-4 sm:p-5 bg-surface border border-line text-xs">
    <summary class="cursor-pointer font-naskh text-base font-bold text-cinnabar">تبدیل به پیشنهاد دیگر</summary>
    <p class="mt-2 text-muted leading-relaxed">اگر پژوهشگر نکته را در جای نادرست فرستاده (مثلاً نام کاتب را به‌صورت یادداشت)، از روی آن یک پیشنهاد استاندارد بسازید. پیشنهاد تازه به نام همین پژوهشگر و با رضایت او به درج نام ثبت می‌شود و مانند هر پیشنهاد دیگری داوری می‌شود.</p>

    <form action="{{ route('admin.suggestions.convert', $suggestion) }}" method="POST" class="mt-4 space-y-3" x-data="convertDesk(@js($setup))">
        @csrf
        @if($errors->hasAny($errorKeys) || $errors->has('note.*'))
            <div class="px-4 py-3 bg-surface border border-s-4 border-cinnabar/50 text-cinnabar font-bold">{{ $errors->first() }}</div>
        @endif

        <label class="block space-y-1">
            <span class="block font-bold text-ink">نوع پیشنهاد تازه:</span>
            <select name="kind" x-model="kind" @change="fieldName = ''" class="{{ $input }}">
                <template x-for="(label, key) in kinds" :key="key"><option :value="key" x-text="label" :selected="key === kind"></option></template>
            </select>
        </label>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" x-show="kind !== 'feedback'">
            <label class="space-y-1">
                <span class="block font-bold text-ink">رکورد مقصد:</span>
                <select name="target_type" x-model="targetType" @change="fieldName = ''; if (targetType !== ownType) otherRecord = true; loadFields()" :disabled="kind === 'feedback'" class="{{ $input }}">
                    <template x-for="(t, key) in types" :key="key"><option :value="key" x-text="t.label" :selected="key === targetType"></option></template>
                </select>
            </label>
            <div class="space-y-1">
                <span class="block font-bold text-ink">کدام رکورد:</span>
                @if($hasRecord)
                    <label class="flex items-center gap-2" x-show="targetType === ownType"><input type="checkbox" x-model="otherRecord" @change="loadFields()" class="accent-cinnabar"> رکورد دیگری است</label>
                @endif
                <input type="text" name="target_ref" x-show="otherRecord" x-model="targetRef" @input="loadFields()" placeholder="پیوند یا شناسه‌ی فهارس، مثلاً fahares.net/w/123" dir="ltr" class="{{ $input }}">
                <span x-show="!otherRecord" class="block text-muted">همین رکورد</span>
                <template x-if="otherRecord && record">
                    <a :href="record.url" target="_blank" class="block text-emerald-700 dark:text-emerald-300 hover:underline">✓ <span x-text="record.label"></span></a>
                </template>
            </div>
        </div>

        <label class="block space-y-1" x-show="kind !== 'general'">
            <span class="block font-bold text-ink" x-text="({field: 'فیلد:', relation: 'نوع رابطه:', note: 'نوع یادداشت:', feedback: 'دسته‌ی بازخورد:'})[kind]"></span>
            <select name="field_name" x-model="fieldName" @change="target = null; targetResults = []" class="{{ $input }}">
                <option value="">انتخاب کنید…</option>
                <template x-for="o in options" :key="o.name ?? o.key"><option :value="o.name ?? o.key" x-text="o.label" :selected="(o.name ?? o.key) === fieldName"></option></template>
            </select>
            <span x-show="kind === 'field' && otherRecord && !record" class="block text-muted">نشانی‌های رکورد در فهرست‌ها و مآخذ، پس از نوشتن پیوند یا شناسه‌ی رکورد مقصد در فهرست می‌آیند.</span>
        </label>

        {{-- Relations: the option, the other record, and what some of them need besides --}}
        <template x-if="relation && relation.options">
            <label class="block space-y-1">
                <span class="block font-bold text-ink" x-text="relation.apply === 'person_role' ? 'نقش:' : 'نوع رابطه‌ی دو رکورد:'"></span>
                <select name="role" x-model="role" class="{{ $input }}">
                    <option value="">انتخاب کنید…</option>
                    <template x-for="(label, key) in relation.options" :key="key"><option :value="key" x-text="label" :selected="key === role"></option></template>
                </select>
            </label>
        </template>
        <template x-if="relation && relation.target">
            <div class="space-y-1 relative">
                <span class="block font-bold text-ink"
                    x-text="relation.apply === 'relocate' ? 'کتابخانه‌ی تازه (اگر عوض شده است):' : (relation.target_optional ? 'شخص (اگر در فهارس ثبت شده است):' : ({work: 'اثر مقصد:', person: 'شخص مقصد:', library: 'کتابخانه‌ی مقصد:', manuscript: 'نسخه‌ی مقصد:'})[relation.target])"></span>
                <input type="text" name="relation_target" x-model="relationTarget" @input="searchTargets()" autocomplete="off" placeholder="پیوند یا شناسه‌ی فهارس، یا جست‌وجوی عنوان و نام" class="{{ $input }}">
                <ul x-show="targetResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-sm border border-line-strong bg-surface shadow-lg">
                    <template x-for="r in targetResults" :key="r.id">
                        <li>
                            <button type="button" @click="pickTarget(r)" class="w-full text-right px-3 py-2 hover:bg-paper">
                                <span class="block font-bold text-ink" x-text="r.label"></span>
                                <span class="block text-muted" x-text="'#' + r.id + (r.sub ? ' · ' + r.sub : '')"></span>
                            </button>
                        </li>
                    </template>
                </ul>
                <template x-if="target">
                    <div class="px-3 py-2 bg-surface border border-s-4 border-emerald-600/40 text-emerald-800 dark:text-emerald-300">
                        ✓ <a :href="target.url" target="_blank" class="font-bold hover:underline" x-text="target.label"></a>
                        <span x-text="'#' + target.id"></span>
                    </div>
                </template>
            </div>
        </template>
        <template x-if="relation && relation.apply === 'person_role'">
            <label class="block space-y-1">
                <span class="block font-bold text-ink">یا نام شخص، همان‌گونه که در نسخه آمده:</span>
                <input type="text" name="role_name" value="{{ old('role_name') }}" class="{{ $input }}">
            </label>
        </template>
        <template x-if="relation && relation.apply === 'relocate'">
            <label class="block space-y-1">
                <span class="block font-bold text-ink">شماره‌ی تازه‌ی نسخه (اگر عوض شده است):</span>
                <input type="text" name="shelfmark" value="{{ old('shelfmark') }}" class="{{ $input }}">
            </label>
        </template>

        {{-- The value, in the input the public form gives this field --}}
        <template x-if="kind !== 'note'">
            <div class="space-y-3">
                <div class="space-y-1">
                    <span class="block font-bold text-ink" x-text="valueLabel"></span>
                    <template x-if="field && field.input === 'catalog_page'">
                        <div class="space-y-1.5" x-data="{ vol: '', pg: '', row: '', absent: false,
                                read(v, k) { const m = (v || '').replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).match(new RegExp(k + '\\s*(\\d+)')); return m ? m[1] : ''; },
                                compose() { value = this.absent ? 'نیست' : (this.vol && this.pg ? 'ج ' + this.vol + '، ' + (field.unit || 'ص') + ' ' + this.pg + (this.row ? '، ردیف ' + this.row : '') : ''); } }"
                            x-init="vol = read(value, 'ج'); pg = read(value, '(?:ص|ستون)'); row = read(value, 'ردیف'); absent = value === 'نیست'">
                            <input type="hidden" name="suggested_value" :value="value">
                            <div class="flex flex-wrap items-end gap-2" :class="absent && 'opacity-40'">
                                <label class="space-y-0.5"><span class="block text-muted">جلد</span><input type="text" inputmode="numeric" x-model="vol" @input="compose()" :disabled="absent" class="admin-field w-20 tabular-nums"></label>
                                <label class="space-y-0.5"><span class="block text-muted" x-text="field.unit === 'ستون' ? 'ستون' : 'صفحه'"></span><input type="text" inputmode="numeric" x-model="pg" @input="compose()" :disabled="absent" class="admin-field w-20 tabular-nums"></label>
                                <template x-if="field.withRow"><label class="space-y-0.5"><span class="block text-muted">ردیف</span><input type="text" inputmode="numeric" x-model="row" @input="compose()" :disabled="absent" class="admin-field w-20 tabular-nums"></label></template>
                            </div>
                            <template x-if="field.canBeAbsent">
                                <label class="flex items-center gap-2"><input type="checkbox" x-model="absent" @change="compose()" class="accent-cinnabar"> این نسخه در این فهرست نیامده است</label>
                            </template>
                        </div>
                    </template>
                    <template x-if="field && field.input === 'boolean'">
                        <div class="flex gap-4">
                            <label class="flex items-center gap-1.5 font-bold"><input type="radio" name="suggested_value" value="بله" x-model="value" class="accent-cinnabar"> بله</label>
                            <label class="flex items-center gap-1.5 font-bold"><input type="radio" name="suggested_value" value="خیر" x-model="value" class="accent-cinnabar"> خیر</label>
                        </div>
                    </template>
                    <template x-if="field && field.input === 'person'">
                        <div class="relative space-y-1">
                            <input type="text" name="suggested_value" x-model="value" @input="searchPeople()" autocomplete="off" class="{{ $input }}" placeholder="نام شخص؛ برای پیوند، از فهرست انتخاب کنید">
                            <input type="hidden" name="person_id" :value="personId">
                            <ul x-show="personResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-sm border border-line-strong bg-surface shadow-lg">
                                <template x-for="p in personResults" :key="p.id">
                                    <li><button type="button" @click="pickPerson(p)" class="w-full text-right px-3 py-2 hover:bg-paper"><span class="font-bold text-ink" x-text="p.name"></span> <span class="text-muted" x-text="'#' + p.id + (p.years ? ' · ' + p.years : '')"></span></button></li>
                                </template>
                            </ul>
                            <span x-show="personId" class="block text-emerald-700 dark:text-emerald-300" x-text="'✓ پیوند به شخص #' + personId"></span>
                        </div>
                    </template>
                    <template x-if="!field || !['catalog_page', 'boolean', 'person'].includes(field.input)">
                        <textarea name="suggested_value" rows="3" x-model="value" class="{{ $input }}"></textarea>
                    </template>
                </div>
                <label class="block space-y-1" x-show="kind !== 'feedback'">
                    <span class="block font-bold text-ink">مستند و منبع:</span>
                    <textarea name="rationale_citation" rows="2" class="{{ $input }}">{{ old('rationale_citation', $suggestion->rationale_citation) }}</textarea>
                </label>
            </div>
        </template>
        <template x-if="kind === 'note' && note">
            <div class="space-y-3">
                <template x-for="f in note.fields" :key="fieldName + f">
                    <label class="block space-y-1">
                        <span class="block font-bold text-ink"><span x-text="noteFields[f]"></span><span x-show="note.required.includes(f)" class="text-cinnabar"> *</span></span>
                        <template x-if="f === 'text'">
                            <textarea name="note[text]" rows="4" class="{{ $input }}" x-text="prefill"></textarea>
                        </template>
                        <template x-if="f !== 'text'">
                            <input type="text" :name="'note[' + f + ']'" :dir="f === 'url' ? 'ltr' : null" class="{{ $input }}">
                        </template>
                    </label>
                </template>
                <p x-show="note.files" class="text-muted">تصاویر بارگذاری‌شده‌ی پیشنهاد اصلی منتقل نمی‌شوند؛ اگر لازم است، پیوند تصویر را بنویسید.</p>
            </div>
        </template>

        <label class="flex items-center gap-2"><input type="checkbox" name="close_original" value="1" checked class="accent-cinnabar"> پیشنهاد اصلی بسته شود («تبدیل‌شده»)؛ برای تفکیک به چند پیشنهاد، تیک را در تبدیل‌های پیش از آخر بردارید.</label>
        <button type="submit" class="w-full py-2.5 px-4 rounded-sm bg-ink text-paper hover:opacity-90 font-bold transition cursor-pointer">ساختن پیشنهاد تازه</button>
    </form>
</details>
