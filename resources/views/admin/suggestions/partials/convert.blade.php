{{-- Turn a misfiled suggestion into a standard one (field correction, research note, general report or relation) on this or another record; see SuggestionController::convert --}}
@php
    use App\Suggestions\NoteTypes;
    use App\Suggestions\SuggestionRegistry;

    $types = [];
    foreach (array_keys(SuggestionRegistry::TYPES) as $t) {
        $types[$t] = [
            'label' => SuggestionRegistry::typeLabel($t),
            'fields' => collect(SuggestionRegistry::fields($t))->map(fn ($def, $name) => ['name' => $name, 'label' => $def['label']])->values(),
            'notes' => array_values(NoteTypes::forRecord($t)),
            // Relations with options carry them, as in the public suggestion form
            'relations' => collect(SuggestionRegistry::RELATIONS[$t] ?? [])->map(fn ($r, $key) => ['key' => $key] + $r + ['options' => match ($r['apply'] ?? null) {
                'person_role' => \App\Models\ManuscriptPerson::ROLES,
                'record_relation' => \App\Suggestions\RecordRelations::options($t),
                default => null,
            }])->values(),
        ];
    }
    // Site feedback has no record: the reviewer names one
    $ownType = SuggestionRegistry::typeKey($suggestion->suggestable_type) ?? 'manuscript';
    $hasRecord = (bool) $suggestion->suggestable_type;
    $setup = [
        'types' => $types,
        'noteFields' => NoteTypes::FIELDS,
        'targetType' => old('target_type', $ownType),
        'kind' => old('kind', 'field'),
        'fieldName' => old('field_name', ''),
        'relationTarget' => old('relation_target', ''),
        'lookupUrl' => route('suggestions.lookup'),
        'otherRecord' => (bool) old('target_ref') || ! $hasRecord,
        'prefill' => $suggestion->kind === 'note' ? ($suggestion->payload['text'] ?? $suggestion->suggested_value) : $suggestion->suggested_value,
    ];
    $input = 'w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink';
    $prefill = $suggestion->kind === 'note' ? ($suggestion->payload['text'] ?? $suggestion->suggested_value) : $suggestion->suggested_value;
@endphp
<details @if($errors->hasAny(['target_ref', 'field_name', 'suggested_value', 'relation_target', 'target_id', 'role', 'role_name', 'shelfmark']) || $errors->has('note.*')) open @endif
    class="p-6 rounded-2xl bg-surface border border-sky-500/30 shadow-xs text-xs">
    <summary class="cursor-pointer text-sm font-bold text-sky-800 dark:text-sky-300">تبدیل به پیشنهاد دیگر</summary>
    <p class="mt-2 text-stone-500 leading-relaxed">اگر پژوهشگر نکته را در جای نادرست فرستاده (مثلاً نام کاتب را به‌صورت یادداشت)، از روی آن یک پیشنهاد استاندارد بسازید. پیشنهاد تازه به نام همین پژوهشگر و با رضایت او به درج نام ثبت می‌شود و مانند هر پیشنهاد دیگری داوری می‌شود.</p>

    <form action="{{ route('admin.suggestions.convert', $suggestion) }}" method="POST" class="mt-4 space-y-3" x-data="convertDesk(@js($setup))">
        @csrf
        @if($errors->hasAny(['target_ref', 'field_name', 'suggested_value', 'relation_target', 'target_id', 'role', 'role_name', 'shelfmark']) || $errors->has('note.*'))
            <div class="p-3 rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 font-bold">{{ $errors->first() }}</div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="space-y-1">
                <span class="block font-bold text-ink">رکورد مقصد:</span>
                <select name="target_type" x-model="targetType" @change="fieldName = ''; if (targetType !== @js($ownType)) otherRecord = true" class="{{ $input }}">
                    <template x-for="(t, key) in types" :key="key"><option :value="key" x-text="t.label" :selected="key === targetType"></option></template>
                </select>
            </label>
            <div class="space-y-1">
                <span class="block font-bold text-ink">کدام رکورد:</span>
                @if($hasRecord)
                    <label class="flex items-center gap-2" x-show="targetType === @js($ownType)"><input type="checkbox" x-model="otherRecord" class="rounded"> رکورد دیگری است</label>
                @endif
                <input type="text" name="target_ref" x-show="otherRecord" value="{{ old('target_ref') }}" placeholder="پیوند یا شناسه‌ی فهارس، مثلاً fahares.net/w/123" dir="ltr" class="{{ $input }}">
                <span x-show="!otherRecord" class="block text-stone-500">همین رکورد</span>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="space-y-1">
                <span class="block font-bold text-ink">نوع پیشنهاد تازه:</span>
                <select name="kind" x-model="kind" @change="fieldName = ''" class="{{ $input }}">
                    <option value="field">اصلاح یک فیلد</option>
                    <option value="note">یادداشت یا منبع پژوهشی</option>
                    <option value="general">پیشنهاد کلی</option>
                    <option value="relation">رابطه با رکورد دیگر (انتقال، ادغام، تکراری…)</option>
                </select>
            </label>
            <label class="space-y-1" x-show="kind !== 'general'">
                <span class="block font-bold text-ink" x-text="kind === 'field' ? 'فیلد:' : (kind === 'relation' ? 'نوع رابطه:' : 'نوع یادداشت:')"></span>
                <select name="field_name" x-model="fieldName" @change="target = null; targetResults = []" class="{{ $input }}">
                    <option value="">انتخاب کنید…</option>
                    <template x-for="o in options" :key="o.name ?? o.key"><option :value="o.name ?? o.key" x-text="o.label" :selected="(o.name ?? o.key) === fieldName"></option></template>
                </select>
            </label>
        </div>

        <template x-if="relation && relation.options">
            <label class="block space-y-1">
                <span class="block font-bold text-ink" x-text="relation.apply === 'person_role' ? 'نقش:' : 'نوع رابطه‌ی دو رکورد:'"></span>
                <select name="role" class="{{ $input }}">
                    <option value="">انتخاب کنید…</option>
                    <template x-for="(label, key) in relation.options" :key="key"><option :value="key" x-text="label" :selected="key === @js(old('role'))"></option></template>
                </select>
            </label>
        </template>
        <template x-if="relation && relation.target">
            <div class="space-y-1 relative">
                <span class="block font-bold text-ink"
                    x-text="relation.apply === 'relocate' ? 'کتابخانه‌ی تازه (اگر عوض شده است):' : (relation.target_optional ? 'شخص (اگر در فهارس ثبت شده است):' : ({work: 'اثر مقصد:', person: 'شخص مقصد:', library: 'کتابخانه‌ی مقصد:', manuscript: 'نسخه‌ی مقصد:'})[relation.target])"></span>
                <input type="text" name="relation_target" x-model="relationTarget" @input="searchTargets()" autocomplete="off" placeholder="پیوند یا شناسه‌ی فهارس، یا جستجوی عنوان و نام" class="{{ $input }}">
                <ul x-show="targetResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-xl border border-line-strong bg-white dark:bg-paper shadow-xl">
                    <template x-for="r in targetResults" :key="r.id">
                        <li>
                            <button type="button" @click="pickTarget(r)" class="w-full text-right px-3 py-2 hover:bg-sky-50 dark:hover:bg-sky-950/40">
                                <span class="block font-bold text-ink" x-text="r.label"></span>
                                <span class="block text-stone-400" x-text="'#' + r.id + (r.sub ? ' · ' + r.sub : '')"></span>
                            </button>
                        </li>
                    </template>
                </ul>
                <template x-if="target">
                    <div class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200">
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

        <template x-if="kind !== 'note'">
            <div class="space-y-3">
                <label class="block space-y-1">
                    <span class="block font-bold text-ink" x-text="kind === 'relation' ? (relation && relation.needs_text ? 'متن لازم این رابطه (نام دیگر، نام تازه، بخش‌های جداشدنی):' : 'توضیح (اختیاری):') : 'مقدار پیشنهادی (به شکل استاندارد):'"></span>
                    <textarea name="suggested_value" rows="3" class="{{ $input }}">{{ old('suggested_value', $prefill) }}</textarea>
                </label>
                <label class="block space-y-1">
                    <span class="block font-bold text-ink">مستند و منبع:</span>
                    <textarea name="rationale_citation" rows="2" class="{{ $input }}">{{ old('rationale_citation', $suggestion->rationale_citation) }}</textarea>
                </label>
            </div>
        </template>
        <template x-if="kind === 'note' && note">
            <div class="space-y-3">
                <template x-for="f in note.fields" :key="fieldName + f">
                    <label class="block space-y-1">
                        <span class="block font-bold text-ink"><span x-text="noteFields[f]"></span><span x-show="note.required.includes(f)" class="text-red-600"> *</span></span>
                        <template x-if="f === 'text'">
                            <textarea name="note[text]" rows="4" class="{{ $input }}" x-text="prefill"></textarea>
                        </template>
                        <template x-if="f !== 'text'">
                            <input type="text" :name="'note[' + f + ']'" :dir="f === 'url' ? 'ltr' : null" class="{{ $input }}">
                        </template>
                    </label>
                </template>
                <p x-show="note.files" class="text-stone-400">تصاویر بارگذاری‌شده‌ی پیشنهاد اصلی منتقل نمی‌شوند؛ اگر لازم است، پیوند تصویر را بنویسید.</p>
            </div>
        </template>

        <label class="flex items-center gap-2"><input type="checkbox" name="close_original" value="1" checked class="rounded"> پیشنهاد اصلی بسته شود («تبدیل‌شده»)؛ برای تفکیک به چند پیشنهاد، تیک را در تبدیل‌های پیش از آخر بردارید.</label>
        <button type="submit" class="w-full py-2.5 rounded-xl bg-sky-700 hover:bg-sky-800 text-white font-bold">ساختن پیشنهاد تازه</button>
    </form>
</details>
