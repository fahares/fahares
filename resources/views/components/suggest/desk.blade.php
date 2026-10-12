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
    foreach (SuggestionRegistry::fields($type) as $name => $def) {
        $field = ['name' => $name] + $def;
        $current = SuggestionRegistry::currentValue($record, $field);
        $fields[$name] = [
            'label' => $def['label'],
            'input' => $def['input'],
            'current' => $current,
            // Start the edit from the stored text, never from display stand-ins like «بی‌تاریخ»
            'prefill' => $def['input'] === 'boolean' || (! empty($def['bit_column']) && $record->{$name} === null) ? '' : ($current ?? ''),
            'pending' => (int) ($pending[$name] ?? 0),
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
        'relations' => collect(SuggestionRegistry::RELATIONS[$type] ?? [])->map(fn ($r, $key) => ['key' => $key] + $r)->values(),
        'csrf' => csrf_token(),
    ];
    $inputClass = 'w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100 focus:border-[#B38A50]';
@endphp

<div x-data="suggestDesk(@js($config))" @suggest-field.window="start($event.detail)" @keydown.escape.window="close()">

    <!-- Suggestion-mode banner -->
    <div x-show="$store.suggest.on" x-cloak x-transition
        class="fixed bottom-4 inset-x-4 sm:inset-x-auto sm:left-1/2 sm:-translate-x-1/2 z-40 max-w-2xl mx-auto p-3 sm:p-4 rounded-2xl bg-[#292C56] text-amber-50 shadow-2xl flex flex-col sm:flex-row sm:items-center gap-3 text-xs">
        <div class="flex-1 leading-relaxed">
            <strong class="text-amber-200">حالت پیشنهاد اصلاح.</strong>
            روی <span class="inline-flex w-5 h-5 items-center justify-center rounded-md bg-amber-50 text-[#B38A50] align-middle">✎</span> کنار هر فیلد بزنید. برای موارد دیگر، مثل تکراری بودن رکورد، از «پیشنهاد کلی» استفاده کنید.
        </div>
        <div class="flex gap-2 shrink-0">
            <button type="button" @click="start({ general: true })" class="px-3 py-2 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold">پیشنهاد کلی</button>
            <button type="button" @click="$store.suggest.toggle()" class="px-3 py-2 rounded-xl border border-amber-100/30 hover:bg-white/10 font-semibold">خروج</button>
        </div>
    </div>

    <!-- Modal -->
    <div x-show="open" x-cloak x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
        <div @click.outside="close()"
            class="bg-white dark:bg-[#15192C] w-full max-w-lg rounded-3xl p-6 sm:p-8 border border-stone-200 dark:border-stone-700 shadow-2xl space-y-5 text-right max-h-[90vh] overflow-y-auto text-xs">

            <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
                    <h3 class="font-bold text-base text-stone-900 dark:text-stone-100" x-text="open ? title : ''"></h3>
                </div>
                <button type="button" @click="close()" class="text-stone-400 hover:text-stone-600 text-lg" aria-label="بستن">✕</button>
            </div>

            <!-- Thank-you state -->
            <template x-if="done">
                <div class="space-y-4">
                    <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold leading-relaxed" x-text="done"></div>
                    <div class="flex justify-end">
                        <button type="button" @click="close()" class="px-5 py-2.5 bg-[#B38A50] hover:bg-[#9C753F] text-white rounded-xl font-bold">بستن</button>
                    </div>
                </div>
            </template>

            <template x-if="!done">
                <form @submit.prevent="submit()" class="space-y-4">

                    <template x-if="mode === 'general'">
                        <div class="space-y-3">
                            <template x-if="config.relations.length">
                                <div class="space-y-1.5">
                                    <span class="block font-semibold text-stone-700 dark:text-stone-300">نوع پیشنهاد:</span>
                                    <label class="flex items-center gap-2"><input type="radio" value="" x-model="relationKey" @change="chooseRelation()" class="text-[#B38A50]"> توضیح آزاد</label>
                                    <template x-for="r in config.relations" :key="r.key">
                                        <label class="flex items-center gap-2"><input type="radio" :value="r.key" x-model="relationKey" @change="chooseRelation()" class="text-[#B38A50]"> <span x-text="r.label"></span></label>
                                    </template>
                                </div>
                            </template>
                            <p x-show="!relationKey" class="text-stone-500 leading-relaxed">
                                هر نکته‌ای که در فیلدهای این صفحه نمی‌گنجد اینجا بنویسید. مثلاً: «این کاتب همان کسی است که در صفحه‌ی فلان آمده» یا «این دو کتابخانه یکی‌اند».
                            </p>

                            <!-- Target picker for relational suggestions -->
                            <template x-if="relation">
                                <div class="space-y-1 relative">
                                    <label class="block font-semibold text-stone-700 dark:text-stone-300"
                                        x-text="relation.target === 'work' ? 'اثر مقصد:' : (relation.target === 'person' ? 'شخص مقصد:' : 'نسخه‌ی مقصد:')"></label>
                                    <input type="text" x-model="targetQuery" @input="searchTargets()" autocomplete="off" class="{{ $inputClass }}"
                                        :placeholder="relation.target === 'manuscript' ? 'پیوند یا شناسه‌ی نسخه در فهارس، مثلاً fahares.net/m/1234' : 'عنوان یا نام را بنویسید، یا پیوند فهارس را بچسبانید'">
                                    <ul x-show="targetResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-xl border border-stone-200 dark:border-stone-700 bg-white dark:bg-[#1A1E35] shadow-xl">
                                        <template x-for="r in targetResults" :key="r.id">
                                            <li>
                                                <button type="button" @click="pickTarget(r)" class="w-full text-right px-3 py-2 hover:bg-amber-50 dark:hover:bg-amber-950/40">
                                                    <span class="block font-bold text-stone-800 dark:text-stone-100" x-text="r.label"></span>
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
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="mode === 'field'">
                        <div class="space-y-3">
                            <div class="space-y-1">
                                <span class="block font-semibold text-stone-500">مقدار فعلی:</span>
                                <div class="p-2.5 rounded-xl bg-stone-50 dark:bg-stone-900 border border-stone-200 dark:border-stone-800 text-stone-700 dark:text-stone-300 whitespace-pre-line max-h-32 overflow-y-auto"
                                    x-text="field.current ?? '— خالی —'"></div>
                            </div>
                            <template x-if="field.pending > 0">
                                <p class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 text-amber-800 dark:text-amber-300">
                                    برای این فیلد <strong x-text="field.pending"></strong> پیشنهاد دیگر در انتظار بررسی است.
                                </p>
                            </template>
                        </div>
                    </template>

                    <!-- Suggested value, by input type -->
                    <div class="space-y-1">
                        <label class="block font-semibold text-stone-700 dark:text-stone-300"
                            x-text="mode === 'general' ? (relationKey ? 'توضیح (اختیاری):' : 'شرح پیشنهاد:') : 'مقدار درست:'"></label>

                        <template x-if="mode === 'general' || ['textarea', 'quote', 'list'].includes(field.input)">
                            <textarea x-model="value" :rows="relationKey ? 2 : 5" :required="!relationKey" class="{{ $inputClass }}"
                                :placeholder="mode === 'field' && field.input === 'list' ? 'هر مورد در یک سطر' : ''"></textarea>
                        </template>

                        <template x-if="mode === 'field' && ['text', 'hijri_date'].includes(field.input)">
                            <input type="text" x-model="value" required class="{{ $inputClass }}">
                        </template>

                        <template x-if="mode === 'field' && field.input === 'integer'">
                            <input type="text" inputmode="numeric" x-model="value" required class="{{ $inputClass }} font-mono w-40">
                        </template>

                        <template x-if="mode === 'field' && field.input === 'boolean'">
                            <div class="flex gap-5 pt-1">
                                <label class="inline-flex items-center gap-1.5 font-bold"><input type="radio" value="بله" x-model="value" class="text-[#B38A50]"> بله</label>
                                <label class="inline-flex items-center gap-1.5 font-bold"><input type="radio" value="خیر" x-model="value" class="text-[#B38A50]"> خیر</label>
                            </div>
                        </template>

                        <template x-if="mode === 'field' && field.input === 'person'">
                            <div class="relative">
                                <input type="text" x-model="value" @input="searchPeople()" required autocomplete="off" class="{{ $inputClass }}"
                                    placeholder="نام را بنویسید و اگر در فهرست اشخاص هست، انتخاب کنید">
                                <ul x-show="personResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-xl border border-stone-200 dark:border-stone-700 bg-white dark:bg-[#1A1E35] shadow-xl">
                                    <template x-for="p in personResults" :key="p.id">
                                        <li>
                                            <button type="button" @click="pickPerson(p)" class="w-full text-right px-3 py-2 hover:bg-amber-50 dark:hover:bg-amber-950/40 flex justify-between gap-2">
                                                <span class="font-bold text-stone-800 dark:text-stone-100" x-text="p.name"></span>
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
                        <label class="block font-semibold text-stone-700 dark:text-stone-300"
                            x-text="mode === 'general' && !relationKey ? 'مستند یا منبع (اختیاری):' : 'مستند و منبع (الزامی):'"></label>
                        <textarea x-model="rationale" rows="3" :required="mode === 'field' || !!relationKey" class="{{ $inputClass }}"
                            placeholder="مثال: ترقیمه‌ی برگ آخر نسخه، یا فهرست کتابخانه‌ی ملی، ج ۴، ص ۱۲"></textarea>
                        <p x-show="errors.rationale_citation" x-text="errors.rationale_citation" class="text-red-600 dark:text-red-400 font-bold"></p>
                    </div>

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
                                <label class="block font-semibold text-stone-700 dark:text-stone-300">نام شما (اختیاری):</label>
                                <input type="text" x-model="guestName" class="{{ $inputClass }}">
                            </div>
                            <div class="space-y-1">
                                <label class="block font-semibold text-stone-700 dark:text-stone-300">ایمیل (اختیاری):</label>
                                <input type="email" x-model="guestEmail" dir="ltr" class="{{ $inputClass }}">
                                <p x-show="errors.guest_email" x-text="errors.guest_email" class="text-red-600 dark:text-red-400 font-bold"></p>
                            </div>
                        </div>
                        <p class="text-[11px] text-stone-400">
                            با <a href="{{ route('login') }}" class="text-[#B38A50] hover:underline font-medium">ورود</a> یا <a href="{{ route('register') }}" class="text-[#B38A50] hover:underline font-medium">عضویت</a>، پیشنهادها به نام شما ثبت می‌شوند.
                        </p>
                    @endauth

                    <p x-show="errors.form || errors.field_name || errors.id || errors.website"
                        x-text="errors.form || errors.field_name || errors.id || errors.website"
                        class="p-2.5 rounded-xl bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 font-bold"></p>

                    <div class="pt-3 flex items-center justify-end gap-3 border-t border-stone-100 dark:border-stone-800">
                        <button type="button" @click="close()" class="px-4 py-2 rounded-xl text-stone-500 hover:bg-stone-100 dark:hover:bg-stone-800 font-semibold">انصراف</button>
                        <button type="submit" :disabled="submitting" class="px-5 py-2.5 bg-[#B38A50] hover:bg-[#9C753F] disabled:opacity-60 text-white rounded-xl font-bold shadow-md transition">
                            <span x-text="submitting ? 'در حال ارسال…' : 'ارسال به هیئت علمی'"></span>
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
