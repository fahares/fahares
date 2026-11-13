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
        'relations' => SuggestionRegistry::relationChoices($type),
        'csrf' => csrf_token(),
        'userName' => auth()->user()?->name,
        'creditName' => auth()->user() ? \App\Models\FieldSuggestion::creditNameFor(auth()->user()) : null,
        // Guests get a sign-in dialog instead of the form (backlog item 76); the name is published once the email is verified
        'signedIn' => auth()->check(),
        'verified' => (bool) auth()->user()?->hasVerifiedEmail(),
        'registerUrl' => route('register.post'),
        'loginUrl' => route('login'),
        'googleUrl' => route('auth.google'),
        // Reviewers may fix a typo at once, without a suggestion or a reading
        'directUrl' => auth()->user()?->can('suggestions.direct_edit') ? route('admin.suggestions.direct-edit') : null,
        // ...and run a structural tool at once, after its preview in the panel (backlog item 45)
        'structureUrl' => auth()->user()?->can('structure.direct') && in_array($type, SuggestionRegistry::STRUCTURE_TYPES, true) ? route('admin.structure.show', [$type, $record->id]) : null,
        // Research notes (see NoteTypes)
        'noteTypes' => \App\Suggestions\NoteTypes::forRecord($type),
        'noteFields' => \App\Suggestions\NoteTypes::FIELDS,
        'maxFiles' => \App\Suggestions\NoteTypes::MAX_FILES,
        // The current number, shown beside the «moved or renumbered» suggestion
        'shelfmark' => $type === 'manuscript' ? $record->shelfmark : null,
        // A source's volumes with their catalogers, for «the cataloger is someone else» (step 14.58)
        'volumes' => $type === 'source' ? \App\Suggestions\VolumeCatalogers::volumes($record) : [],
    ];
    // The grounds a suggestion may stand on, instead of a required free text (step 14.156); `detail`: the note under it
    $typeKey = SuggestionRegistry::typeKey($type) ?? $type;
    $seen = ['manuscript' => 'نسخه را خودم دیده‌ام', 'work' => 'نسخه یا چاپ اثر را خودم دیده‌ام', 'library' => 'از خود کتابخانه می‌دانم'][$typeKey] ?? null;
    $config['basis'] = array_values(array_filter([
        ['key' => 'typo', 'label' => 'غلط تایپی یا املایی است', 'fieldOnly' => true, 'detail' => 'optional', 'placeholder' => 'اگر توضیحی لازم است'],
        $seen ? ['key' => 'seen', 'label' => $seen, 'detail' => 'optional', 'placeholder' => 'مثلاً: ترقیمه‌ی برگ آخر، یا مهر برگ نخست'] : null,
        ['key' => 'catalog', 'label' => 'در فهرست یا منبع چاپی چنین آمده', 'detail' => 'optional', 'placeholder' => 'نام منبع، جلد و صفحه (اگر در دست دارید)'],
        ['key' => 'other', 'label' => 'دلیل یا منبع دیگر', 'detail' => 'required', 'placeholder' => 'در یک جمله بنویسید'],
    ]));
    $inputClass = 'w-full px-3 py-2.5 rounded-sm border border-line-strong bg-paper text-ink text-[15px] leading-relaxed focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 outline-none transition';
    $labelClass = 'block text-[13px] font-bold text-ink';
@endphp

<div x-data="suggestDesk(@js($config))" @suggest-field.window="start($event.detail)" @keydown.escape.window="close()">

    <!-- Suggestion-mode banner -->
    <div x-show="$store.suggest.on" x-cloak x-transition
        class="fixed bottom-4 inset-x-4 sm:inset-x-auto sm:left-1/2 sm:-translate-x-1/2 z-40 max-w-xl mx-auto p-3 sm:px-4 rounded-sm bg-[#221C17] text-[#F3EAD6] border border-gold/50 shadow-2xl flex items-center gap-3 text-[13px]">
        <div class="flex-1 leading-relaxed">
            <strong class="text-amber-200">اصلاح فیلدها.</strong>
            روی <span class="inline-flex w-5 h-5 items-center justify-center rounded-md bg-amber-50 text-cinnabar align-middle">✎</span> کنار هر فیلد بزنید. فیلدهای خالی این صفحه هم اکنون دیده می‌شوند.
        </div>
        <div class="flex gap-2 shrink-0">
            <button type="button" @click="$store.suggest.toggle()" class="px-4 py-2 rounded-sm border border-amber-100/40 hover:bg-white/10 font-bold cursor-pointer">پایان</button>
        </div>
    </div>

    <!-- Modal -->
    <div x-show="open" x-cloak x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
        <div @click.outside="close()" role="dialog" aria-modal="true" aria-labelledby="suggest-desk-title"
            class="bg-surface w-full max-w-xl rounded-sm border border-gold shadow-2xl text-right max-h-[92vh] overflow-y-auto text-sm text-ink">

            <div class="sticky top-0 z-10 flex items-start justify-between gap-3 px-5 sm:px-7 pt-5 pb-3 bg-surface border-b border-line">
                <h3 id="suggest-desk-title" class="font-naskh text-xl font-bold text-ink leading-relaxed" x-text="open ? title : ''"></h3>
                <button type="button" @click="close()" class="w-8 h-8 shrink-0 inline-flex items-center justify-center rounded-sm text-muted hover:text-ink hover:bg-paper text-lg cursor-pointer" aria-label="بستن">✕</button>
            </div>
            <div class="px-5 sm:px-7 py-5">

            <!-- Thank-you state -->
            <template x-if="done">
                <div class="space-y-4">
                    <div class="p-4 rounded-sm bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold leading-relaxed" x-text="done"></div>
                    <p class="text-[13px] text-muted leading-relaxed">
                        نتیجه‌ی بررسی به شما خبر داده می‌شود. وضعیت همه‌ی پیشنهادهایتان در
                        <a href="{{ route('my.suggestions') }}" class="font-bold text-cinnabar hover:underline">«پیشنهادهای من»</a> هست.
                    </p>
                    @auth<x-web-push context="suggest" />@endauth
                    <div class="flex justify-end">
                        <button type="button" @click="close()" class="px-5 py-2.5 bg-cinnabar hover:opacity-90 text-on-cinnabar rounded-sm font-bold cursor-pointer">بستن</button>
                    </div>
                </div>
            </template>

            <template x-if="!done">
                <form @submit.prevent="submit()" @input="saveDraft()" @change="saveDraft()" class="space-y-5">

                    <template x-if="mode === 'general'">
                        <div class="space-y-3">
                            <template x-if="config.relations.length">
                                <div class="space-y-1.5">
                                    <span class="block text-[13px] font-bold text-ink">نوع پیشنهاد:</span>
                                    <label class="flex items-start gap-2 leading-relaxed cursor-pointer"><input type="radio" value="" x-model="relationKey" @change="chooseRelation()" class="mt-1 text-cinnabar"> توضیح آزاد</label>
                                    <template x-for="r in config.relations" :key="r.key">
                                        <label class="flex items-start gap-2 leading-relaxed cursor-pointer"><input type="radio" :value="r.key" x-model="relationKey" @change="chooseRelation()" class="mt-1 text-cinnabar"> <span x-text="r.label"></span></label>
                                    </template>
                                </div>
                            </template>
                            <p x-show="!relationKey" class="text-xs text-muted leading-relaxed">
                                هر نکته‌ای که در فیلدهای این صفحه نمی‌گنجد اینجا بنویسید. مثلاً: «این کاتب همان کسی است که در صفحه‌ی فلان آمده» یا «این دو کتابخانه یکی‌اند».
                            </p>

                            <!-- A person connected with the copy: role, then a person from the list or a name -->
                            <template x-if="relation && relation.options">
                                <div class="space-y-1">
                                    <label class="block text-[13px] font-bold text-ink" x-text="['person_role', 'volume_cataloger'].includes(relation.apply) ? 'نقش:' : 'نوع رابطه:'"></label>
                                    <select x-model="roleKey" class="{{ $inputClass }}">
                                        <option value="">انتخاب کنید…</option>
                                        <template x-for="(label, key) in relation.options" :key="key"><option :value="key" x-text="label"></option></template>
                                    </select>
                                    <p x-show="errors.role" x-text="errors.role" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                                </div>
                            </template>

                            <!-- Target picker for relational suggestions (another name of a person has none) -->
                            <template x-if="relation && relation.target">
                                <div class="space-y-1 relative">
                                    <label class="block text-[13px] font-bold text-ink"
                                        x-text="relation.target === 'cataloger' ? 'فهرست‌نگار (اگر در فهارس ثبت شده است):' : relation.apply === 'relocate' ? 'کتابخانه‌ی تازه (اگر کتابخانه عوض شده است):' : relation.target === 'library' ? 'کتابخانه‌ی مقصد:' : (relation.target_optional ? 'شخص (اگر در فهارس ثبت شده است):' : (relation.target === 'work' ? 'اثر مقصد:' : (relation.target === 'person' ? 'شخص مقصد:' : 'نسخه‌ی مقصد:')))"></label>
                                    <input type="text" x-model="targetQuery" @input="searchTargets()" autocomplete="off" class="{{ $inputClass }}"
                                        :placeholder="relation.target === 'cataloger' ? 'نام فهرست‌نگار را بنویسید یا پیوند صفحه‌اش در فهارس را بچسبانید' : relation.target === 'manuscript' ? 'پیوند یا شناسه‌ی نسخه در فهارس، مثلاً fahares.net/m/1234' : (relation.target === 'library' ? 'نام کتابخانه یا شهر را بنویسید' : 'عنوان یا نام را بنویسید، یا پیوند فهارس را بچسبانید')">
                                    <ul x-show="targetResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-sm border border-line-strong bg-white dark:bg-paper shadow-xl">
                                        <template x-for="r in targetResults" :key="r.id">
                                            <li>
                                                <button type="button" @click="pickTarget(r)" class="w-full text-right px-3 py-2 hover:bg-amber-50 dark:hover:bg-amber-950/40">
                                                    <span class="block font-bold text-ink" x-text="r.label"></span>
                                                    <span class="block text-muted" x-text="'#' + r.id + (r.sub ? ' · ' + r.sub : '')"></span>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                    <template x-if="target">
                                        <div class="p-2.5 rounded-sm bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200">
                                            ✓ <a :href="target.url" target="_blank" class="font-bold hover:underline" x-text="target.label"></a>
                                            <span class="text-emerald-700/70 dark:text-emerald-300/70" x-text="'#' + target.id"></span>
                                        </div>
                                    </template>
                                    <p x-show="errors.target_id" x-text="errors.target_id" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                                    <template x-if="['person_role', 'volume_cataloger'].includes(relation.apply) && !target">
                                        <div class="space-y-1 pt-1">
                                            <label class="block text-[13px] font-bold text-ink" x-text="relation.apply === 'volume_cataloger' ? 'یا نام او، همان‌گونه که در صفحه‌ی عنوان یا دیباچه‌ی فهرست آمده:' : config.type === 'work' ? 'یا نام او، همان‌گونه که در فهرست یا دیباچه آمده:' : 'یا نام او، همان‌گونه که در نسخه آمده:'"></label>
                                            <input type="text" x-model="roleName" class="{{ $inputClass }}" placeholder="مثلاً محمدحسین بن علی تبریزی">
                                            <p x-show="errors.role_name" x-text="errors.role_name" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <!-- A cataloger of the source: one volume or all, with the people each has now (step 14.58) -->
                            <template x-if="relation && relation.apply === 'volume_cataloger'">
                                <div class="space-y-1">
                                    <label class="block text-[13px] font-bold text-ink">کدام جلد:</label>
                                    <select x-model="volumeId" class="{{ $inputClass }}">
                                        <option value="">همه‌ی جلدها</option>
                                        <template x-for="v in config.volumes" :key="v.id">
                                            <option :value="String(v.id)" x-text="v.label + (v.people ? ' — اکنون: ' + v.people : ' — فهرست‌نگار ثبت نشده')"></option>
                                        </template>
                                    </select>
                                    <p class="text-xs text-muted leading-relaxed" x-text="relation.replace ? 'شخص انتخاب‌شده جای فهرست‌نگار کنونیِ همان نقش را در جلد می‌گیرد.' : 'شخص انتخاب‌شده کنار فهرست‌نگاران کنونی جلد می‌آید.'"></p>
                                    <p x-show="errors.volume_id" x-text="errors.volume_id" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                                </div>
                            </template>

                            <!-- The copy moved or was renumbered: the new shelfmark (the library is picked above) -->
                            <template x-if="relation && relation.apply === 'relocate'">
                                <div class="space-y-1">
                                    <label class="block text-[13px] font-bold text-ink">شماره‌ی تازه‌ی نسخه (اگر شماره عوض شده است):</label>
                                    <input type="text" x-model="newShelfmark" class="{{ $inputClass }}" :placeholder="'شماره‌ی کنونی: ' + (config.shelfmark ?? 'بی‌شماره')">
                                    <p class="text-xs text-muted leading-relaxed">اگر کتابخانه‌ی تازه در فهرست نیست، نام و شهرش را در توضیح بنویسید. نشانی پیشین نسخه در شناسنامه‌اش می‌ماند.</p>
                                    <p x-show="errors.shelfmark" x-text="errors.shelfmark" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="mode === 'field'">
                        <div class="space-y-3">
                            <div class="space-y-1">
                                <span class="block text-xs font-bold text-muted">اکنون در فهارس:</span>
                                <div class="px-3 py-2 border-s-2 border-line-strong text-ink/80 whitespace-pre-line max-h-32 overflow-y-auto leading-relaxed"
                                    x-text="field.current ?? 'ثبت نشده'"></div>
                            </div>
                            <template x-if="field.pending > 0">
                                <p class="p-2.5 rounded-sm bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 text-[13px] text-amber-800 dark:text-amber-300 leading-relaxed">
                                    برای این فیلد <strong x-text="field.pending"></strong> پیشنهاد دیگر در انتظار بررسی است.
                                </p>
                            </template>
                        </div>
                    </template>

                    <!-- Research note: typed fields, and images for the image type -->
                    <template x-if="mode === 'note'">
                        <div class="space-y-3">
                            <div class="space-y-1.5">
                                <span class="block text-[13px] font-bold text-ink">نوع یادداشت:</span>
                                <template x-for="t in Object.values(config.noteTypes)" :key="t.key">
                                    <label class="flex items-start gap-2 leading-relaxed cursor-pointer"><input type="radio" :value="t.key" x-model="noteType" class="mt-1 text-cinnabar"> <span x-text="t.label"></span></label>
                                </template>
                                <p class="text-xs text-muted leading-relaxed" x-text="noteDef.hint"></p>
                            </div>
                            <template x-for="f in noteDef.fields" :key="noteType + f">
                                <div class="space-y-1">
                                    <label class="block text-[13px] font-bold text-ink">
                                        <span x-text="config.noteFields[f]"></span><span x-show="noteDef.required.includes(f)" class="text-red-600"> *</span>
                                    </label>
                                    <template x-if="f === 'text'">
                                        <textarea x-model="note[f]" rows="4" class="{{ $inputClass }}"></textarea>
                                    </template>
                                    <template x-if="f !== 'text'">
                                        <input type="text" x-model="note[f]" :dir="f === 'url' ? 'ltr' : null" :placeholder="f === 'url' ? 'https://' : ''" class="{{ $inputClass }}">
                                    </template>
                                    <p x-show="errors['note.' + f]" x-text="errors['note.' + f]" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                                </div>
                            </template>
                            <template x-if="noteDef.files">
                                <div class="space-y-1.5 p-3 rounded-sm bg-paper border border-line">
                                    <span class="block text-[13px] font-bold text-ink">بارگذاری تصویر (اختیاری؛ حداکثر <span x-text="config.maxFiles"></span> تصویر):</span>
                                        <div class="space-y-1.5">
                                            <input type="file" accept="image/jpeg,image/png,image/webp" multiple @change="pickFiles($event)" class="block w-full text-ink/80">
                                            <p class="text-xs text-muted">تصویرهای بزرگ پیش از ارسال کوچک می‌شوند.</p>
                                            <template x-if="noteFiles.length">
                                                <p class="text-emerald-700 dark:text-emerald-400" x-text="noteFiles.length + ' تصویر آماده‌ی ارسال'"></p>
                                            </template>
                                            <label x-show="noteFiles.length" class="flex items-start gap-2 cursor-pointer">
                                                <input type="checkbox" x-model="rightsOk" class="mt-0.5 rounded border-stone-300 text-cinnabar">
                                                <span>این تصویر را خودم تهیه کرده‌ام یا اجازه‌ی انتشار آن را دارم.</span>
                                            </label>
                                            <p x-show="errors.files || errors.rights_confirmed" x-text="errors.files || errors.rights_confirmed" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                                        </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="mode !== 'note'">
                        <div class="space-y-4">
                    <!-- Suggested value, by input type -->
                    <div class="space-y-1">
                        <label class="block text-[13px] font-bold text-ink"
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
                                    <label class="grid gap-1"><span class="text-muted">جلد</span>
                                        <input type="text" inputmode="numeric" x-model="vol" @input="compose()" :disabled="absent" :required="!absent" class="{{ $inputClass }} font-mono w-24"></label>
                                    <label class="grid gap-1"><span class="text-muted" x-text="field.unit === 'ستون' ? 'ستون' : 'صفحه'"></span>
                                        <input type="text" inputmode="numeric" x-model="pg" @input="compose()" :disabled="absent" :required="!absent" class="{{ $inputClass }} font-mono w-24"></label>
                                    <template x-if="field.withRow">
                                        <label class="grid gap-1"><span class="text-muted">ردیف</span>
                                            <input type="text" inputmode="numeric" x-model="row" @input="compose()" :disabled="absent" class="{{ $inputClass }} font-mono w-24"></label>
                                    </template>
                                </div>
                                <template x-if="field.canBeAbsent">
                                    <label class="inline-flex items-center gap-1.5"><input type="checkbox" x-model="absent" @change="compose()" class="text-cinnabar"> این نسخه در این فهرست نیامده است</label>
                                </template>
                                <p class="text-xs text-muted">صفحه‌ای را بنویسید که مدخل از آن آغاز می‌شود.</p>
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
                                <ul x-show="personResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-sm border border-line-strong bg-white dark:bg-paper shadow-xl">
                                    <template x-for="p in personResults" :key="p.id">
                                        <li>
                                            <button type="button" @click="pickPerson(p)" class="w-full text-right px-3 py-2 hover:bg-amber-50 dark:hover:bg-amber-950/40 flex justify-between gap-2">
                                                <span class="font-bold text-ink" x-text="p.name"></span>
                                                <span class="text-muted shrink-0" x-text="[p.years, p.manuscripts_count + ' نسخه'].filter(Boolean).join(' · ')"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                                <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-400" x-show="personId">✓ به شخص ثبت‌شده در فهارس پیوند می‌خورد.</p>
                                <p class="mt-1 text-xs text-muted" x-show="!personId">اگر شخص در فهرست نیامد، نام کامل را همان‌گونه که در نسخه آمده بنویسید.</p>
                            </div>
                        </template>

                        <template x-if="mode === 'field' && field.input === 'hijri_date'">
                            <p class="text-xs text-muted">تاریخ را همان‌گونه که در ترقیمه آمده بنویسید، مثلاً «یک‌شنبه ۲۳ ربیع‌الاول ۱۲۳۶ق». برای تاریخ شمسی یا میلادی، «ش» یا «م» را بیاورید.</p>
                        </template>

                        <p x-show="errors.suggested_value" x-text="errors.suggested_value" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                    </div>

                    <template x-if="basisOptions.length">
                        <fieldset class="space-y-2">
                            <legend class="{{ $labelClass }} mb-1.5">این را از کجا می‌دانید؟</legend>
                            <div class="grid sm:grid-cols-2 gap-2">
                                <template x-for="b in basisOptions" :key="b.key">
                                    <label class="flex items-center gap-2 px-3 py-2 rounded-sm border cursor-pointer transition"
                                        :class="basisKey === b.key ? 'border-cinnabar bg-cinnabar/5 text-ink font-bold' : 'border-line-strong hover:border-gold'">
                                        <input type="radio" name="basis" :value="b.key" x-model="basisKey" class="text-cinnabar focus:ring-cinnabar">
                                        <span x-text="b.label"></span>
                                    </label>
                                </template>
                            </div>
                            <div x-show="basis" x-cloak class="space-y-1 pt-1">
                                <label class="{{ $labelClass }}" x-text="basis && basis.detail === 'required' ? 'دلیل یا منبع:' : 'توضیح یا نشانی منبع (اختیاری):'"></label>
                                <textarea x-model="rationale" rows="2" class="{{ $inputClass }}" :placeholder="basis ? basis.placeholder : ''"></textarea>
                            </div>
                            <p x-show="errors.rationale_citation" x-text="errors.rationale_citation" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                        </fieldset>
                    </template>
                    <template x-if="!basisOptions.length">
                        <div class="space-y-1">
                            <label class="{{ $labelClass }}">مستند یا منبع (اختیاری):</label>
                            <textarea x-model="rationale" rows="2" class="{{ $inputClass }}"
                                placeholder="مثال: ترقیمه‌ی برگ آخر نسخه، یا فهرست کتابخانه‌ی ملی، ج ۴، ص ۱۲"></textarea>
                            <p x-show="errors.rationale_citation" x-text="errors.rationale_citation" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                        </div>
                    </template>

                        </div>
                    </template>

                    <div class="space-y-2 pt-4 border-t border-line">
                        <label class="flex items-start gap-2 cursor-pointer text-ink">
                            <input type="checkbox" x-model="creditOn" @change="toggleCredit()" class="mt-0.5 rounded border-stone-300 dark:border-stone-600 text-cinnabar focus:ring-cinnabar">
                            <span x-text="mode === 'note' ? 'مایلم در صورت تأیید، نامم در کنار این یادداشت در سایت درج شود.' : 'مایلم در صورت تأیید، نامم در کنار این اصلاح در سایت درج شود.'"></span>
                        </label>
                        <div x-show="creditOn" x-cloak class="space-y-1">
                            <label class="block text-[13px] font-bold text-ink">نام به این صورت درج شود:</label>
                            <input type="text" x-model="creditName" maxlength="150" :required="creditOn" class="{{ $inputClass }}" placeholder="مثلاً: دکتر … ، پژوهشگر نسخ خطی">
                            <p x-show="errors.credit_name" x-text="errors.credit_name" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                            <p x-show="config.signedIn && !config.verified" class="text-xs text-muted leading-relaxed">نام شما پس از <a href="{{ route('verification.notice') }}" target="_blank" class="text-cinnabar hover:underline font-bold">تأیید ایمیل</a> منتشر می‌شود؛ پیشنهاد همین حالا ثبت می‌شود.</p>
                        </div>
                    </div>

                    <p x-show="errors.form || errors.field_name || errors.id"
                        x-text="errors.form || errors.field_name || errors.id"
                        class="p-2.5 rounded-sm bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 font-bold"></p>

                    <p x-show="!config.signedIn" class="text-xs text-muted leading-relaxed">در گام بعد، برای ثبت پیشنهاد یک ایمیل و گذرواژه (یا حساب گوگل) می‌خواهیم؛ آنچه نوشته‌اید نگه داشته می‌شود.</p>

                    <div class="pt-4 flex flex-wrap items-center justify-end gap-3 border-t border-line">
                        <button type="button" @click="cancel()" class="px-4 py-2.5 rounded-sm text-muted hover:bg-paper font-semibold cursor-pointer">انصراف</button>
                        <template x-if="config.structureUrl && mode === 'general' && relationKey">
                            <button type="button" @click="openStructure()"
                                title="بی‌آنکه پیشنهادی ثبت شود: در پنل، اثر تغییر را می‌بینید و پس از تأیید اجرا می‌شود"
                                class="px-4 py-2.5 rounded-sm border border-gold text-cinnabar hover:bg-amber-50 dark:hover:bg-amber-950/30 font-bold transition">
                                اعمال مستقیم (با پیش‌نمایش)
                            </button>
                        </template>
                        <template x-if="config.directUrl && mode === 'field'">
                            <button type="button" @click="submitDirect()" :disabled="submitting"
                                title="فقط برای غلط تایپی و خطای آشکار: بی‌درنگ اعمال می‌شود، مستند نمی‌خواهد و قول به‌شمار نمی‌آید"
                                class="px-4 py-2.5 rounded-sm border border-gold text-cinnabar hover:bg-amber-50 dark:hover:bg-amber-950/30 disabled:opacity-60 font-bold transition">
                                ویرایش مستقیم (غلط تایپی)
                            </button>
                        </template>
                        <button type="submit" :disabled="submitting" class="px-6 py-2.5 bg-cinnabar hover:opacity-90 disabled:opacity-60 text-on-cinnabar rounded-sm font-bold transition cursor-pointer">
                            <span x-text="submitting ? 'در حال ارسال…' : (config.signedIn ? 'ارسال پیشنهاد' : 'ادامه')"></span>
                        </button>
                    </div>
                </form>
            </template>
            </div>
        </div>
    </div>
    <!-- Sign-in dialog for guests (backlog item 76), the last step of their suggestion (step 14.156) -->
    <div x-show="gate" x-cloak x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
        <div @click.outside="close()" role="dialog" aria-modal="true" aria-labelledby="suggest-gate-title"
            class="bg-surface w-full max-w-md rounded-sm p-6 sm:p-8 border border-gold shadow-2xl space-y-5 text-right max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between gap-3">
                <h3 id="suggest-gate-title" class="font-naskh text-xl font-bold text-ink leading-relaxed" x-text="pendingSend ? 'یک گام تا ارسال' : 'ورود به فهارس'">ورود به فهارس</h3>
                <button type="button" @click="close()" class="text-muted hover:text-ink text-lg cursor-pointer" aria-label="بازگشت به فرم">✕</button>
            </div>
            <p class="text-sm text-ink leading-7">پیشنهاد شما آماده است. برای ثبت آن یک ایمیل و گذرواژه بگذارید، یا با حساب گوگل وارد شوید. با همین حساب نتیجه‌ی بررسی را هم می‌بینید.</p>

            @include('auth.partials.google-button', ['label' => 'ورود با حساب گوگل', 'hrefExpr' => 'authUrl(config.googleUrl)'])

            @guest
                @php $gateField = 'w-full px-3 py-2.5 rounded-sm border border-line-strong bg-paper text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none'; @endphp
                <form @submit.prevent="register($el)" class="space-y-3">
                    <div class="space-y-1">
                        <label for="gate-email" class="block text-xs font-bold text-ink">ایمیل:</label>
                        <input type="email" id="gate-email" name="email" required dir="ltr" autocomplete="email" maxlength="255" class="{{ $gateField }} text-left">
                        <p x-show="gateErrors.email" x-text="gateErrors.email" class="text-[11px] text-red-500 font-medium"></p>
                    </div>
                    <div class="space-y-1">
                        <label for="gate-password" class="block text-xs font-bold text-ink">گذرواژه (دست‌کم ۸ نویسه):</label>
                        <div class="relative" x-data="{ show: false }">
                            <input type="password" :type="show ? 'text' : 'password'" id="gate-password" name="password" required minlength="8" dir="ltr" autocomplete="new-password" class="{{ $gateField }} text-left pl-11">
                            <x-password-toggle />
                        </div>
                        <p x-show="gateErrors.password" x-text="gateErrors.password" class="text-[11px] text-red-500 font-medium"></p>
                    </div>
                    <p x-show="gateErrors.form" x-text="gateErrors.form" class="p-2.5 rounded-sm bg-red-50 dark:bg-red-950/30 text-xs text-red-700 dark:text-red-300 font-bold"></p>
                    <button type="submit" :disabled="gateBusy" class="w-full py-2.5 px-4 rounded-sm bg-cinnabar text-on-cinnabar font-bold text-sm hover:opacity-90 disabled:opacity-60 transition">
                        <span x-text="gateBusy ? 'در حال ارسال…' : 'ساخت حساب و ارسال پیشنهاد'"></span>
                    </button>
                </form>
            @endguest

            <p class="pt-3 border-t border-line text-center text-xs text-muted">
                حساب دارید؟ <a :href="authUrl(config.loginUrl)" class="font-bold text-cinnabar hover:underline">ورود</a>
            </p>
        </div>
    </div>
</div>
