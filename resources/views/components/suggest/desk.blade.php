@props(['type', 'record'])
@php
    use App\Models\FieldSuggestion;
    use App\Suggestions\SuggestionRegistry;

    $pending = FieldSuggestion::where('suggestable_type', $record::class)
        ->where('suggestable_id', $record->id)
        ->where('status', 'pending')
        ->where('kind', 'field')
        ->selectRaw('field_name, count(*) as n')
        ->groupBy('field_name')
        ->pluck('n', 'field_name');

    $fields = [];
    // With one address field per catalogue reference of the copy (backlog item 51)
    foreach (SuggestionRegistry::recordFields($record) as $name => $def) {
        $field = ['name' => $name] + $def;
        $current = SuggestionRegistry::currentValue($record, $field);
        $fields[$name] = [
            'label' => $def['label'],
            'input' => $def['input'],
            'current' => $current,
            // Start the edit from the stored text, never from display stand-ins like «بی‌تاریخ»
            'prefill' => $def['input'] === 'boolean' || (! empty($def['bit_column']) && $record->{$name} === null) ? '' : ($current ?? ''),
            'pending' => (int) ($pending[$name] ?? 0),
            'withRow' => ! empty($def['with_row']),
            'canBeAbsent' => ! empty($def['can_be_absent']),
            'unit' => $def['unit'] ?? 'ص',
        ];
    }

    $config = [
        'type' => $type,
        'typeLabel' => SuggestionRegistry::typeLabel($type),
        'id' => $record->id,
        'fields' => $fields,
        'storeUrl' => route('suggestions.store'),
        'peopleUrl' => route('suggestions.people'),
        'lookupUrl' => route('suggestions.lookup'),
        // Relations with options carry them: roles of people in the copy, or the kinds of relation between records
        'relations' => collect(SuggestionRegistry::RELATIONS[$type] ?? [])->map(fn ($r, $key) => ['key' => $key] + $r + ['options' => match ($r['apply'] ?? null) {
            'person_role' => \App\Models\ManuscriptPerson::ROLES,
            'record_relation' => \App\Suggestions\RecordRelations::options($type),
            default => null,
        }])->values(),
        'csrf' => csrf_token(),
        'userName' => auth()->user()?->name,
        // Reviewers may fix a typo at once, without a suggestion or a reading
        'directUrl' => auth()->user()?->canAccessAdmin() ? route('admin.suggestions.direct-edit') : null,
        // Research notes (see NoteTypes); images can be uploaded only when signed in
        'noteTypes' => \App\Suggestions\NoteTypes::forRecord($type),
        'noteFields' => \App\Suggestions\NoteTypes::FIELDS,
        'maxFiles' => \App\Suggestions\NoteTypes::MAX_FILES,
        'canUpload' => auth()->check(),
        // The current number, shown beside the «moved or renumbered» suggestion
        'shelfmark' => $type === 'manuscript' ? $record->shelfmark : null,
    ];
    $inputClass = 'w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink focus:border-gold';
@endphp

<div x-data="suggestDesk(@js($config))" @suggest-field.window="start($event.detail)" @keydown.escape.window="close()">

    <!-- Suggestion-mode banner -->
    <div x-show="$store.suggest.on" x-cloak x-transition
        class="fixed bottom-4 inset-x-4 sm:inset-x-auto sm:left-1/2 sm:-translate-x-1/2 z-40 max-w-2xl mx-auto p-3 sm:p-4 rounded-sm bg-[#221C17] text-[#F3EAD6] border border-gold/50 shadow-2xl flex flex-col sm:flex-row sm:items-center gap-3 text-xs">
        <div class="flex-1 leading-relaxed">
            <strong class="text-amber-200">حالت پیشنهاد اصلاح.</strong>
            روی <span class="inline-flex w-5 h-5 items-center justify-center rounded-md bg-amber-50 text-cinnabar align-middle">✎</span> کنار هر فیلد بزنید. برای موارد دیگر، مثل تکراری بودن رکورد، از «پیشنهاد کلی» استفاده کنید.
        </div>
        <div class="flex gap-2 shrink-0">
            <button type="button" @click="start({ general: true })" class="px-3 py-2 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold">پیشنهاد کلی</button>
            <button type="button" x-show="Object.keys(config.noteTypes).length" @click="start({ note: true })" class="px-3 py-2 rounded-xl border border-amber-100/40 hover:bg-white/10 font-bold">یادداشت یا منبع</button>
            <button type="button" @click="$store.suggest.toggle()" class="px-3 py-2 rounded-xl border border-amber-100/30 hover:bg-white/10 font-semibold">خروج</button>
        </div>
    </div>

    <!-- Modal -->
    <div x-show="open" x-cloak x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
        <div @click.outside="close()"
            class="bg-surface w-full max-w-lg rounded-3xl p-6 sm:p-8 border border-line-strong shadow-2xl space-y-5 text-right max-h-[90vh] overflow-y-auto text-xs">

            <div class="flex items-center justify-between pb-3 border-b border-line">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-cinnabar rounded-sm"></span>
                    <h3 class="font-bold text-base text-ink" x-text="open ? title : ''"></h3>
                </div>
                <button type="button" @click="close()" class="text-stone-400 hover:text-stone-600 text-lg" aria-label="بستن">✕</button>
            </div>

            <!-- Thank-you state -->
            <template x-if="done">
                <div class="space-y-4">
                    <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold leading-relaxed" x-text="done"></div>
                    <div class="flex justify-end">
                        <button type="button" @click="close()" class="px-5 py-2.5 bg-cinnabar hover:opacity-90 text-on-cinnabar rounded-xl font-bold">بستن</button>
                    </div>
                </div>
            </template>

            <template x-if="!done">
                <form @submit.prevent="submit()" class="space-y-4">

                    <template x-if="mode === 'general'">
                        <div class="space-y-3">
                            <template x-if="config.relations.length">
                                <div class="space-y-1.5">
                                    <span class="block font-semibold text-ink">نوع پیشنهاد:</span>
                                    <label class="flex items-center gap-2"><input type="radio" value="" x-model="relationKey" @change="chooseRelation()" class="text-cinnabar"> توضیح آزاد</label>
                                    <template x-for="r in config.relations" :key="r.key">
                                        <label class="flex items-center gap-2"><input type="radio" :value="r.key" x-model="relationKey" @change="chooseRelation()" class="text-cinnabar"> <span x-text="r.label"></span></label>
                                    </template>
                                </div>
                            </template>
                            <p x-show="!relationKey" class="text-stone-500 leading-relaxed">
                                هر نکته‌ای که در فیلدهای این صفحه نمی‌گنجد اینجا بنویسید. مثلاً: «این کاتب همان کسی است که در صفحه‌ی فلان آمده» یا «این دو کتابخانه یکی‌اند».
                            </p>

                            <!-- A person connected with the copy: role, then a person from the list or a name -->
                            <template x-if="relation && relation.options">
                                <div class="space-y-1">
                                    <label class="block font-semibold text-ink" x-text="relation.apply === 'person_role' ? 'نقش:' : 'نوع رابطه:'"></label>
                                    <select x-model="roleKey" class="{{ $inputClass }}">
                                        <option value="">انتخاب کنید…</option>
                                        <template x-for="(label, key) in relation.options" :key="key"><option :value="key" x-text="label"></option></template>
                                    </select>
                                    <p x-show="errors.role" x-text="errors.role" class="text-red-600 dark:text-red-400 font-bold"></p>
                                </div>
                            </template>

                            <!-- Target picker for relational suggestions (another name of a person has none) -->
                            <template x-if="relation && relation.target">
                                <div class="space-y-1 relative">
                                    <label class="block font-semibold text-ink"
                                        x-text="relation.apply === 'relocate' ? 'کتابخانه‌ی تازه (اگر کتابخانه عوض شده است):' : relation.target === 'library' ? 'کتابخانه‌ی مقصد:' : (relation.target_optional ? 'شخص (اگر در فهارس ثبت شده است):' : (relation.target === 'work' ? 'اثر مقصد:' : (relation.target === 'person' ? 'شخص مقصد:' : 'نسخه‌ی مقصد:')))"></label>
                                    <input type="text" x-model="targetQuery" @input="searchTargets()" autocomplete="off" class="{{ $inputClass }}"
                                        :placeholder="relation.target === 'manuscript' ? 'پیوند یا شناسه‌ی نسخه در فهارس، مثلاً fahares.net/m/1234' : (relation.target === 'library' ? 'نام کتابخانه یا شهر را بنویسید' : 'عنوان یا نام را بنویسید، یا پیوند فهارس را بچسبانید')">
                                    <ul x-show="targetResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-xl border border-line-strong bg-white dark:bg-paper shadow-xl">
                                        <template x-for="r in targetResults" :key="r.id">
                                            <li>
                                                <button type="button" @click="pickTarget(r)" class="w-full text-right px-3 py-2 hover:bg-amber-50 dark:hover:bg-amber-950/40">
                                                    <span class="block font-bold text-ink" x-text="r.label"></span>
                                                    <span class="block text-stone-400" x-text="'#' + r.id + (r.sub ? ' · ' + r.sub : '')"></span>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                    <template x-if="target">
                                        <div class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200">
                                            ✓ <a :href="target.url" target="_blank" class="font-bold hover:underline" x-text="target.label"></a>
                                            <span class="text-emerald-700/70 dark:text-emerald-300/70" x-text="'#' + target.id"></span>
                                        </div>
                                    </template>
                                    <p x-show="errors.target_id" x-text="errors.target_id" class="text-red-600 dark:text-red-400 font-bold"></p>
                                    <template x-if="relation.apply === 'person_role' && !target">
                                        <div class="space-y-1 pt-1">
                                            <label class="block font-semibold text-ink">یا نام او، همان‌گونه که در نسخه آمده:</label>
                                            <input type="text" x-model="roleName" class="{{ $inputClass }}" placeholder="مثلاً محمدحسین بن علی تبریزی">
                                            <p x-show="errors.role_name" x-text="errors.role_name" class="text-red-600 dark:text-red-400 font-bold"></p>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <!-- The copy moved or was renumbered: the new shelfmark (the library is picked above) -->
                            <template x-if="relation && relation.apply === 'relocate'">
                                <div class="space-y-1">
                                    <label class="block font-semibold text-ink">شماره‌ی تازه‌ی نسخه (اگر شماره عوض شده است):</label>
                                    <input type="text" x-model="newShelfmark" class="{{ $inputClass }}" :placeholder="'شماره‌ی کنونی: ' + (config.shelfmark ?? 'بی‌شماره')">
                                    <p class="text-stone-400 leading-relaxed">اگر کتابخانه‌ی تازه در فهرست نیست، نام و شهرش را در توضیح بنویسید. نشانی پیشین نسخه در شناسنامه‌اش می‌ماند.</p>
                                    <p x-show="errors.shelfmark" x-text="errors.shelfmark" class="text-red-600 dark:text-red-400 font-bold"></p>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="mode === 'field'">
                        <div class="space-y-3">
                            <div class="space-y-1">
                                <span class="block font-semibold text-stone-500">مقدار فعلی:</span>
                                <div class="p-2.5 rounded-xl bg-stone-50 dark:bg-stone-900 border border-line text-ink whitespace-pre-line max-h-32 overflow-y-auto"
                                    x-text="field.current ?? '— خالی —'"></div>
                            </div>
                            <template x-if="field.pending > 0">
                                <p class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 text-amber-800 dark:text-amber-300">
                                    برای این فیلد <strong x-text="field.pending"></strong> پیشنهاد دیگر در انتظار بررسی است.
                                </p>
                            </template>
                        </div>
                    </template>

                    <!-- Research note: typed fields, and images for the image type -->
                    <template x-if="mode === 'note'">
                        <div class="space-y-3">
                            <div class="space-y-1.5">
                                <span class="block font-semibold text-ink">نوع یادداشت:</span>
                                <template x-for="t in Object.values(config.noteTypes)" :key="t.key">
                                    <label class="flex items-center gap-2"><input type="radio" :value="t.key" x-model="noteType" class="text-cinnabar"> <span x-text="t.label"></span></label>
                                </template>
                                <p class="text-stone-400 leading-relaxed" x-text="noteDef.hint"></p>
                            </div>
                            <template x-for="f in noteDef.fields" :key="noteType + f">
                                <div class="space-y-1">
                                    <label class="block font-semibold text-ink">
                                        <span x-text="config.noteFields[f]"></span><span x-show="noteDef.required.includes(f)" class="text-red-600"> *</span>
                                    </label>
                                    <template x-if="f === 'text'">
                                        <textarea x-model="note[f]" rows="4" class="{{ $inputClass }}"></textarea>
                                    </template>
                                    <template x-if="f !== 'text'">
                                        <input type="text" x-model="note[f]" :dir="f === 'url' ? 'ltr' : null" :placeholder="f === 'url' ? 'https://' : ''" class="{{ $inputClass }}">
                                    </template>
                                    <p x-show="errors['note.' + f]" x-text="errors['note.' + f]" class="text-red-600 dark:text-red-400 font-bold"></p>
                                </div>
                            </template>
                            <template x-if="noteDef.files">
                                <div class="space-y-1.5 p-3 rounded-xl bg-stone-50 dark:bg-stone-900 border border-line">
                                    <span class="block font-semibold text-ink">بارگذاری تصویر (اختیاری؛ حداکثر <span x-text="config.maxFiles"></span> تصویر):</span>
                                    <template x-if="config.canUpload">
                                        <div class="space-y-1.5">
                                            <input type="file" accept="image/jpeg,image/png,image/webp" multiple @change="pickFiles($event)" class="block w-full text-ink/80">
                                            <p class="text-stone-400">تصویرهای بزرگ پیش از ارسال کوچک می‌شوند.</p>
                                            <template x-if="noteFiles.length">
                                                <p class="text-emerald-700 dark:text-emerald-400" x-text="noteFiles.length + ' تصویر آماده‌ی ارسال'"></p>
                                            </template>
                                            <label x-show="noteFiles.length" class="flex items-start gap-2 cursor-pointer">
                                                <input type="checkbox" x-model="rightsOk" class="mt-0.5 rounded border-stone-300 text-cinnabar">
                                                <span>این تصویر را خودم تهیه کرده‌ام یا اجازه‌ی انتشار آن را دارم.</span>
                                            </label>
                                            <p x-show="errors.files || errors.rights_confirmed" x-text="errors.files || errors.rights_confirmed" class="text-red-600 dark:text-red-400 font-bold"></p>
                                        </div>
                                    </template>
                                    <template x-if="!config.canUpload">
                                        <p class="text-stone-500">برای بارگذاری تصویر <a href="{{ route('login') }}" class="text-cinnabar hover:underline font-bold">وارد شوید</a>. پیوند تصویر را بدون ورود هم می‌توانید بفرستید.</p>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="mode !== 'note'">
                        <div class="space-y-4">
                    <!-- Suggested value, by input type -->
                    <div class="space-y-1">
                        <label class="block font-semibold text-ink"
                            x-text="mode === 'general' ? (relation && relation.needs_text ? (relation.apply === 'rename_library' ? 'نام تازه‌ی کتابخانه:' : (relation.apply === 'split' ? 'کدام نسخه‌ها یا آثار از رکورد دیگری‌اند، و آن رکورد چیست؟' : 'نام دیگر:')) : (relation && relation.apply === 'person_role' ? 'توضیح (اختیاری؛ مثلاً «مهر بیضی به تاریخ ۱۲۴۰ق در برگ نخست»):' : (relationKey ? 'توضیح (اختیاری):' : 'شرح پیشنهاد:'))) : 'مقدار درست:'"></label>

                        <template x-if="mode === 'general' || ['textarea', 'quote', 'list'].includes(field.input)">
                            <textarea x-model="value" :rows="relationKey ? 2 : 5" :required="!relationKey || (relation && relation.needs_text)" class="{{ $inputClass }}"
                                :placeholder="mode === 'field' && field.input === 'list' ? 'هر مورد در یک سطر' : ''"></textarea>
                        </template>

                        <template x-if="mode === 'field' && ['text', 'hijri_date'].includes(field.input)">
                            <input type="text" x-model="value" required class="{{ $inputClass }}">
                        </template>

                        <template x-if="mode === 'field' && field.input === 'integer'">
                            <input type="text" inputmode="numeric" x-model="value" required class="{{ $inputClass }} font-mono w-40">
                        </template>

                        <!-- An address in a source: volume, page (and row in Fankha), composed into «ج ۲، ص ۷» -->
                        <template x-if="mode === 'field' && field.input === 'catalog_page'">
                            <div x-data="{
                                    vol: '', pg: '', row: '', absent: false,
                                    read(text, key) { const m = (text || '').replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).match(new RegExp(key + '\\s*(\\d+)')); return m ? m[1] : ''; },
                                    compose() { value = this.absent ? 'نیست' : (this.vol && this.pg ? 'ج ' + this.vol + '، ' + (field.unit || 'ص') + ' ' + this.pg + (this.row ? '، ردیف ' + this.row : '') : ''); },
                                }"
                                x-init="vol = read(value, 'ج'); pg = read(value, '(?:ص|ستون)'); row = read(value, 'ردیف'); absent = value === 'نیست'; compose()"
                                class="space-y-2">
                                <div class="flex flex-wrap items-end gap-3" :class="absent && 'opacity-40'">
                                    <label class="grid gap-1"><span class="text-stone-500">جلد</span>
                                        <input type="text" inputmode="numeric" x-model="vol" @input="compose()" :disabled="absent" :required="!absent" class="{{ $inputClass }} font-mono w-24"></label>
                                    <label class="grid gap-1"><span class="text-stone-500" x-text="field.unit === 'ستون' ? 'ستون' : 'صفحه'"></span>
                                        <input type="text" inputmode="numeric" x-model="pg" @input="compose()" :disabled="absent" :required="!absent" class="{{ $inputClass }} font-mono w-24"></label>
                                    <template x-if="field.withRow">
                                        <label class="grid gap-1"><span class="text-stone-500">ردیف</span>
                                            <input type="text" inputmode="numeric" x-model="row" @input="compose()" :disabled="absent" class="{{ $inputClass }} font-mono w-24"></label>
                                    </template>
                                </div>
                                <template x-if="field.canBeAbsent">
                                    <label class="inline-flex items-center gap-1.5"><input type="checkbox" x-model="absent" @change="compose()" class="text-cinnabar"> این نسخه در این فهرست نیامده است</label>
                                </template>
                                <p class="text-stone-400">صفحه‌ای را بنویسید که مدخل از آن آغاز می‌شود.</p>
                            </div>
                        </template>

                        <template x-if="mode === 'field' && field.input === 'boolean'">
                            <div class="flex gap-5 pt-1">
                                <label class="inline-flex items-center gap-1.5 font-bold"><input type="radio" value="بله" x-model="value" class="text-cinnabar"> بله</label>
                                <label class="inline-flex items-center gap-1.5 font-bold"><input type="radio" value="خیر" x-model="value" class="text-cinnabar"> خیر</label>
                            </div>
                        </template>

                        <template x-if="mode === 'field' && field.input === 'person'">
                            <div class="relative">
                                <input type="text" x-model="value" @input="searchPeople()" required autocomplete="off" class="{{ $inputClass }}"
                                    placeholder="نام را بنویسید و اگر در فهرست اشخاص هست، انتخاب کنید">
                                <ul x-show="personResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-xl border border-line-strong bg-white dark:bg-paper shadow-xl">
                                    <template x-for="p in personResults" :key="p.id">
                                        <li>
                                            <button type="button" @click="pickPerson(p)" class="w-full text-right px-3 py-2 hover:bg-amber-50 dark:hover:bg-amber-950/40 flex justify-between gap-2">
                                                <span class="font-bold text-ink" x-text="p.name"></span>
                                                <span class="text-stone-400 shrink-0" x-text="[p.years, p.manuscripts_count + ' نسخه'].filter(Boolean).join(' · ')"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                                <p class="mt-1 text-emerald-700 dark:text-emerald-400" x-show="personId">✓ به شخص ثبت‌شده در فهارس پیوند می‌خورد.</p>
                                <p class="mt-1 text-stone-400" x-show="!personId">اگر شخص در فهرست نیامد، نام کامل را همان‌گونه که در نسخه آمده بنویسید.</p>
                            </div>
                        </template>

                        <template x-if="mode === 'field' && field.input === 'hijri_date'">
                            <p class="text-stone-400">تاریخ را همان‌گونه که در ترقیمه آمده بنویسید، مثلاً «یک‌شنبه ۲۳ ربیع‌الاول ۱۲۳۶ق». برای تاریخ شمسی یا میلادی، «ش» یا «م» را بیاورید.</p>
                        </template>

                        <p x-show="errors.suggested_value" x-text="errors.suggested_value" class="text-red-600 dark:text-red-400 font-bold"></p>
                    </div>

                    <div class="space-y-1">
                        <label class="block font-semibold text-ink"
                            x-text="mode === 'general' && !relationKey ? 'مستند یا منبع (اختیاری):' : 'مستند و منبع (الزامی):'"></label>
                        <textarea x-model="rationale" rows="3" :required="mode === 'field' || !!relationKey" class="{{ $inputClass }}"
                            placeholder="مثال: ترقیمه‌ی برگ آخر نسخه، یا فهرست کتابخانه‌ی ملی، ج ۴، ص ۱۲"></textarea>
                        <p x-show="errors.rationale_citation" x-text="errors.rationale_citation" class="text-red-600 dark:text-red-400 font-bold"></p>
                    </div>

                        </div>
                    </template>

                    <!-- Honeypot: hidden from people, bots fill it -->
                    <div class="hidden" aria-hidden="true">
                        <label>وب‌سایت <input type="text" x-model="website" tabindex="-1" autocomplete="off"></label>
                    </div>

                    @auth
                        <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-900 dark:text-amber-200 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            <span>ثبت پیشنهاد با نام <strong>{{ auth()->user()->name }}</strong></span>
                        </div>
                    @else
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <label class="block font-semibold text-ink">نام شما (اختیاری):</label>
                                <input type="text" x-model="guestName" class="{{ $inputClass }}">
                            </div>
                            <div class="space-y-1">
                                <label class="block font-semibold text-ink">ایمیل (اختیاری):</label>
                                <input type="email" x-model="guestEmail" dir="ltr" class="{{ $inputClass }}">
                                <p x-show="errors.guest_email" x-text="errors.guest_email" class="text-red-600 dark:text-red-400 font-bold"></p>
                            </div>
                        </div>
                        <label x-show="guestEmail.trim()" x-cloak class="flex items-start gap-2 cursor-pointer text-ink">
                            <input type="checkbox" x-model="notifyOn" class="mt-0.5 rounded border-stone-300 dark:border-stone-600 text-cinnabar focus:ring-cinnabar">
                            <span>نتیجه‌ی داوری را به این ایمیل خبر دهید.</span>
                        </label>
                        <p class="text-[11px] text-stone-400">
                            با <a href="{{ route('login') }}" class="text-cinnabar hover:underline font-medium">ورود</a> یا <a href="{{ route('register') }}" class="text-cinnabar hover:underline font-medium">عضویت</a>، پیشنهادها به نام شما ثبت می‌شوند.
                        </p>
                    @endauth

                    <div class="space-y-2">
                        <label class="flex items-start gap-2 cursor-pointer text-ink">
                            <input type="checkbox" x-model="creditOn" @change="toggleCredit()" class="mt-0.5 rounded border-stone-300 dark:border-stone-600 text-cinnabar focus:ring-cinnabar">
                            <span x-text="mode === 'note' ? 'مایلم در صورت تأیید، نامم در کنار این یادداشت در سایت درج شود.' : 'مایلم در صورت تأیید، نامم در کنار این اصلاح در سایت درج شود.'"></span>
                        </label>
                        <div x-show="creditOn" x-cloak class="space-y-1">
                            <label class="block font-semibold text-ink">نام به این صورت درج شود:</label>
                            <input type="text" x-model="creditName" maxlength="150" :required="creditOn" class="{{ $inputClass }}" placeholder="مثلاً: دکتر … ، پژوهشگر نسخ خطی">
                            <p x-show="errors.credit_name" x-text="errors.credit_name" class="text-red-600 dark:text-red-400 font-bold"></p>
                        </div>
                    </div>

                    <p x-show="errors.form || errors.field_name || errors.id || errors.website"
                        x-text="errors.form || errors.field_name || errors.id || errors.website"
                        class="p-2.5 rounded-xl bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 font-bold"></p>

                    <div class="pt-3 flex items-center justify-end gap-3 border-t border-line">
                        <button type="button" @click="close()" class="px-4 py-2 rounded-xl text-stone-500 hover:bg-paper font-semibold">انصراف</button>
                        <template x-if="config.directUrl && mode === 'field'">
                            <button type="button" @click="submitDirect()" :disabled="submitting"
                                title="فقط برای غلط تایپی و خطای آشکار: بی‌درنگ اعمال می‌شود، مستند نمی‌خواهد و قول به‌شمار نمی‌آید"
                                class="px-4 py-2.5 rounded-xl border border-gold text-cinnabar hover:bg-amber-50 dark:hover:bg-amber-950/30 disabled:opacity-60 font-bold transition">
                                ویرایش مستقیم (غلط تایپی)
                            </button>
                        </template>
                        <button type="submit" :disabled="submitting" class="px-5 py-2.5 bg-cinnabar hover:opacity-90 disabled:opacity-60 text-on-cinnabar rounded-xl font-bold shadow-md transition">
                            <span x-text="submitting ? 'در حال ارسال…' : 'ارسال به هیئت علمی'"></span>
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
