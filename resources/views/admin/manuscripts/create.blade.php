@extends('layouts.admin')

{{-- «نسخۀ تازه» (backlog item 149): the record of a copy the catalogues do not list --}}
@php
    use App\Suggestions\ManuscriptCreator as Creator;

    $fa = fn ($v) => \App\Support\Fa::digits($v);
    $val = fn (string $field, $default = null) => old($field, $defaults[$field] ?? $default);
    $lookup = route('suggestions.lookup');
    $picker = fn (string $field, string $type) => ['url' => $lookup, 'type' => $type, 'picked' => $picked[$field] ?? null];
@endphp

@section('title', 'نسخۀ تازه')
@section('page_title', 'نسخۀ تازه')
@section('breadcrumb')
    <span>ساختن رکورد نسخه</span>
@endsection

@section('content')
<x-ui.card class="max-w-3xl space-y-5 text-[13px]">
    <p class="text-muted leading-7">
        رکورد نسخه‌ای که در فهرست‌ها نیامده است: رساله‌ای که پژوهشگری در مجموعه‌ای دیده، یا نسخه‌ای که فهرستی چاپی معرفی کرده است.
        رکورد در لاگ ممیزی ثبت می‌شود و از همان‌جا بازگرداندنی است.
    </p>

    @if($from)
        <x-admin.notice>
            جزء دیگری از مجموعۀ
            <a href="{{ route('manuscripts.show', $from) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $fa(trim(($from->libraryRecord?->name ?? $from->library) . ' ' . $from->shelfmark)) }}</a>:
            کتابخانه و شمارۀ مجموعه از همان نسخه آمده است.
            {{-- what the new treatise will read from the collection if its own fields are left empty (step 14.330) --}}
            @php
                $volume = $from->boundCollection();
                $read = $volume ? array_filter(['خط' => $volume->script_names, 'شمار سطر' => $volume->lines ? $volume->lines . ' سطر' : null,
                    'کاتب' => $volume->scribe_name ?: $volume->scribe?->name, 'اندازه' => $volume->dimensions, 'کاغذ' => $volume->paper, 'جلد' => $volume->binding]) : [];
            @endphp
            @if($read)
                <span class="block mt-1" data-collection-read>اگر خط، سطر و کاتب را خالی بگذارید، رساله آن‌ها را از مجموعه می‌خواند؛ اندازه، کاغذ و جلد از آنِ مجموعه است و نوشتنش لازم نیست:
                    {{ $fa(collect($read)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ')) }}</span>
            @endif
        </x-admin.notice>
    @endif

    @if($errors->any())
        <x-admin.notice tone="error">{{ $errors->first() }}</x-admin.notice>
    @endif

    <form action="{{ route('admin.manuscripts.store') }}" method="POST" class="space-y-6" x-data="{ workMode: @js($val('work_mode', 'existing')), sourceKind: @js($val('source_kind', 'researcher')) }">
        @csrf
        <input type="hidden" name="from_id" value="{{ $val('from_id') }}">

        @if($duplicates->isNotEmpty())
            <x-admin.notice tone="warn" data-duplicate-warning>
                <p class="font-bold text-ink">این کتابخانه با همین شماره نسخه دارد:</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach($duplicates as $d)
                        <li>
                            <a href="{{ route('manuscripts.show', $d) }}" target="_blank" class="font-bold hover:text-cinnabar">{{ $d->work?->primary_title ?? 'نسخه' }}</a>
                            <span class="text-muted">— {{ $fa($d->shelfmark) }}{{ $d->rangeLabel() ? '، ' . $d->rangeLabel() : '' }} (#{{ $fa($d->id) }})</span>
                        </li>
                    @endforeach
                </ul>
                <label class="mt-2 flex items-center gap-2 font-bold text-ink">
                    <input type="checkbox" name="confirm_duplicate" value="1" class="accent-cinnabar">
                    این نسخۀ دیگری است (جزء دیگری با همین شماره)؛ ساخته شود.
                </label>
            </x-admin.notice>
        @endif

        {{-- the people of the name given for a new author or scribe (backlog item 161) --}}
        @if($namesakes->isNotEmpty())
            <x-admin.notice tone="warn" data-namesake-warning>
                @foreach($namesakes as $role => $people)
                    <p class="font-bold text-ink">{{ $role === 'author' ? 'شخص یا اشخاصی با نام پدیدآور در سایت هستند:' : 'شخص یا اشخاصی با نام کاتب در سایت هستند:' }}</p>
                    @include('admin.people._namesakes', ['people' => $people])
                @endforeach
                <p class="mt-1 text-xs">اگر همان شخص است، او را در «{{ $namesakes->keys()->map(fn ($r) => $r === 'author' ? 'پدیدآور (شخص سایت)' : 'کاتب (شخص سایت)')->implode('» و «') }}» انتخاب کنید.</p>
                <label class="mt-2 flex items-center gap-2 font-bold text-ink">
                    <input type="checkbox" name="confirm_people" value="1" class="accent-cinnabar">
                    شخص دیگری است؛ رکورد تازه ساخته شود.
                </label>
            </x-admin.notice>
        @endif

        {{-- 1. Where the copy is --}}
        <fieldset class="space-y-4">
            <legend class="rubric text-base">۱. جای نسخه</legend>
            <x-admin.field label="کتابخانه" error="library_id">
                <x-admin.record-picker name="library_id" :setup="$picker('library_id', 'library')" placeholder="نام کتابخانه یا شهر…" />
            </x-admin.field>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-admin.field label="شمارۀ نسخه" for="shelfmark" error="shelfmark" hint="چنان‌که کتابخانه می‌نویسد: «۲۸۹۷/۳»، یا شمارۀ خود مجموعه.">
                    <input type="text" name="shelfmark" id="shelfmark" value="{{ $val('shelfmark') }}" maxlength="100" dir="ltr" required class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="شمارۀ مجموعه" for="collection_key" error="collection_key" hint="اگر نسخه جزئی از یک مجموعه است. با شمارۀ بخش‌دار («۲۸۹۷/۳») خودش به دست می‌آید. اگر شمارۀ نسخه چیز دیگری است ولی کتابخانه مجموعه‌ای با این شماره دارد، نسخه دستی در همان مجموعه جای می‌گیرد.">
                    <input type="text" name="collection_key" id="collection_key" value="{{ $val('collection_key') }}" maxlength="100" dir="ltr" class="admin-field w-full">
                </x-admin.field>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-admin.field label="جای نسخه در مجموعه" for="range" error="range" hint="«۷پ تا ۱۲پ» یا «۱–۱۲»">
                    <input type="text" name="range" id="range" value="{{ $val('range') }}" maxlength="30" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="تعداد برگ" for="folios" error="folios">
                    <input type="number" name="folios" id="folios" value="{{ $val('folios') }}" min="1" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="تعداد صفحه" for="pages" error="pages" hint="اگر فهرست به صفحه شمرده است.">
                    <input type="number" name="pages" id="pages" value="{{ $val('pages') }}" min="1" class="admin-field w-full">
                </x-admin.field>
            </div>
        </fieldset>

        {{-- 2. The work --}}
        <fieldset class="space-y-4">
            <legend class="rubric text-base">۲. اثر</legend>
            <div class="flex flex-wrap gap-x-6 gap-y-1.5">
                @foreach(Creator::WORK_MODES as $key => $label)
                    <label class="flex items-center gap-2"><input type="radio" name="work_mode" value="{{ $key }}" x-model="workMode" class="accent-cinnabar"> <b class="text-ink">{{ $label }}</b></label>
                @endforeach
            </div>
            <div x-show="workMode === 'existing'" x-cloak>
                <x-admin.field label="اثر" error="work_id" hint="عنوان، نشانی صفحۀ اثر یا شناسۀ آن.">
                    <x-admin.record-picker name="work_id" :setup="$picker('work_id', 'work')" placeholder="عنوان اثر…" />
                </x-admin.field>
            </div>
            <div x-show="workMode === 'new'" x-cloak class="space-y-4">
                <x-admin.field label="عنوان اثر تازه" for="work_title" error="work_title">
                    <input type="text" name="work_title" id="work_title" value="{{ $val('work_title') }}" maxlength="255" class="admin-field w-full">
                </x-admin.field>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field label="پدیدآور (شخص سایت)" error="author_id" hint="اگر شخص در سایت هست.">
                        <x-admin.record-picker name="author_id" :setup="$picker('author_id', 'person')" placeholder="نام شخص…" />
                    </x-admin.field>
                    <x-admin.field label="نام پدیدآور" for="author_name" error="author_name" hint="اگر شخص در سایت نیست، یا نامش در نسخه صورت دیگری دارد.">
                        <input type="text" name="author_name" id="author_name" value="{{ $val('author_name') }}" maxlength="500" class="admin-field w-full">
                        <label class="mt-1.5 flex items-center gap-2 text-xs"><input type="checkbox" name="author_new" value="1" @checked(old('author_new')) class="accent-cinnabar"> رکورد شخص تازه با این نام ساخته شود</label>
                    </x-admin.field>
                </div>
            </div>
            <p x-show="workMode === 'untitled'" x-cloak class="text-xs text-muted leading-6">
                برای این نسخه اثری با عنوان «بی‌عنوان (کتابخانه، شمارۀ نسخه)» ساخته می‌شود؛ عنوان بعدها از راه پیشنهاد اصلاح عوض‌شدنی است.
            </p>
        </fieldset>

        {{-- 3. The copy --}}
        <fieldset class="space-y-4">
            <legend class="rubric text-base">۳. نسخه</legend>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-admin.field label="کاتب (شخص سایت)" error="scribe_id">
                    <x-admin.record-picker name="scribe_id" :setup="$picker('scribe_id', 'person')" placeholder="نام شخص…" />
                </x-admin.field>
                <x-admin.field label="نام کاتب" for="scribe_name" error="scribe_name" hint="چنان‌که در نسخه آمده است.">
                    <input type="text" name="scribe_name" id="scribe_name" value="{{ $val('scribe_name') }}" maxlength="600" class="admin-field w-full">
                    <label class="mt-1.5 flex items-center gap-2 text-xs"><input type="checkbox" name="scribe_new" value="1" @checked(old('scribe_new')) class="accent-cinnabar"> رکورد شخص تازه با این نام ساخته شود</label>
                </x-admin.field>
            </div>
            <x-admin.field label="تاریخ کتابت" for="copy_date_raw" error="copy_date_raw" hint="«۱۰۸۴ق»، «قرن ۱۲»، «محتملاً قرن ۱۲»">
                <input type="text" name="copy_date_raw" id="copy_date_raw" value="{{ $val('copy_date_raw') }}" maxlength="300" class="admin-field w-full sm:w-1/2">
            </x-admin.field>
            <x-admin.field label="آغاز" for="incipit_text" error="incipit_text">
                <textarea name="incipit_text" id="incipit_text" rows="2" class="admin-field w-full font-naskh">{{ $val('incipit_text') }}</textarea>
            </x-admin.field>
            <x-admin.field label="انجام" for="explicit_text" error="explicit_text">
                <textarea name="explicit_text" id="explicit_text" rows="2" class="admin-field w-full font-naskh">{{ $val('explicit_text') }}</textarea>
            </x-admin.field>
        </fieldset>

        {{-- 4. The copy's body --}}
        <fieldset class="space-y-4">
            <legend class="rubric text-base">۴. کتابت و کالبد نسخه</legend>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-admin.field label="محل کتابت" for="copy_place" error="copy_place">
                    <input type="text" name="copy_place" id="copy_place" value="{{ $val('copy_place') }}" maxlength="300" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="به سفارش" for="commissioned_by" error="commissioned_by">
                    <input type="text" name="commissioned_by" id="commissioned_by" value="{{ $val('commissioned_by') }}" maxlength="500" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="نوع خط" for="script_names" error="script_names" hint="«نستعلیق»، «نسخ و نستعلیق»: خط‌های شناختۀ سایت پیوند می‌گیرند.">
                    <input type="text" name="script_names" id="script_names" value="{{ $val('script_names') }}" maxlength="300" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="کیفیت خط" for="script_style" error="script_style" hint="«خوش»، «تحریری»، «ریز»">
                    <input type="text" name="script_style" id="script_style" value="{{ $val('script_style') }}" maxlength="300" class="admin-field w-full">
                </x-admin.field>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-admin.field label="شمار سطر" for="lines" error="lines">
                    <input type="number" name="lines" id="lines" value="{{ $val('lines') }}" min="1" max="99" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field class="sm:col-span-2" label="اندازه" for="dimensions" error="dimensions" hint="«۱۱×۱۳/۵ سم»">
                    <input type="text" name="dimensions" id="dimensions" value="{{ $val('dimensions') }}" maxlength="255" class="admin-field w-full">
                </x-admin.field>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-admin.field label="کاغذ" for="paper" error="paper">
                    <input type="text" name="paper" id="paper" value="{{ $val('paper') }}" maxlength="500" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="جلد" for="binding" error="binding">
                    <input type="text" name="binding" id="binding" value="{{ $val('binding') }}" maxlength="500" class="admin-field w-full">
                </x-admin.field>
            </div>
            <div class="space-y-1">
                <span class="block font-bold text-ink">ویژگی‌ها</span>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                    @foreach(Creator::FLAGS as $flag => $label)
                        <label class="flex items-center gap-2"><input type="checkbox" name="flags[{{ $flag }}]" value="1" @checked(old('flags.' . $flag)) class="accent-cinnabar"> {{ $label }}</label>
                    @endforeach
                </div>
            </div>
        </fieldset>

        {{-- 5. The copy's notes, each with its type --}}
        <fieldset class="space-y-3" x-data="{ notes: @js(array_values(array_filter((array) old('notes', []), fn ($n) => filled($n['text'] ?? null)))) }">
            <legend class="rubric text-base">۵. یادداشت‌های نسخه</legend>
            <p class="text-xs text-muted leading-6">مهر، تملک، وقف، افتادگی، شرح نسخه و مانند آن؛ هر یادداشت با نوعش در بخش خود صفحۀ نسخه می‌آید.</p>
            <template x-for="(note, i) in notes" :key="i">
                <div class="flex items-start gap-2" data-note-row>
                    <select :name="`notes[${i}][type]`" x-model="note.type" class="admin-field w-40 shrink-0">
                        @foreach(\App\Models\ManuscriptNote::TYPES as $type => $label)
                            <option value="{{ $type }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <textarea :name="`notes[${i}][text]`" x-model="note.text" rows="2" class="admin-field flex-1 font-naskh"></textarea>
                    <button type="button" @click="notes.splice(i, 1)" class="mt-1.5 text-xs font-bold text-cinnabar hover:underline cursor-pointer">حذف</button>
                </div>
            </template>
            <button type="button" @click="notes.push({ type: 'description', text: '' })" class="text-xs font-bold text-cinnabar hover:underline cursor-pointer" data-add-note>＋ یادداشت</button>
        </fieldset>

        {{-- 6. Where the knowledge came from --}}
        <fieldset class="space-y-4">
            <legend class="rubric text-base">۶. منبع اطلاع</legend>
            <div class="flex flex-wrap gap-x-6 gap-y-1.5">
                @foreach(Creator::SOURCE_KINDS as $key => $label)
                    <label class="flex items-center gap-2"><input type="radio" name="source_kind" value="{{ $key }}" x-model="sourceKind" class="accent-cinnabar"> <b class="text-ink">{{ $label }}</b></label>
                @endforeach
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-admin.field class="sm:col-span-2" for="source_text" error="source_text" label="نام پژوهشگر یا ارجاع فهرست"
                    hint="در صفحۀ نسخه نمایش داده می‌شود: «به گزارش … که نسخه را دیده است»، یا متن ارجاع فهرست.">
                    <input type="text" name="source_text" id="source_text" value="{{ $val('source_text') }}" maxlength="300" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="شمارۀ پیشنهاد" for="suggestion_id" error="suggestion_id" hint="اگر از پیشنهادی آمده است؛ فقط داوران می‌بینند.">
                    <input type="number" name="suggestion_id" id="suggestion_id" value="{{ $val('suggestion_id') }}" min="1" class="admin-field w-full">
                </x-admin.field>
            </div>
            {{-- the library's catalogue on the web: the page of the copy links its record there (step 14.445) --}}
            <div x-show="sourceKind === 'online'" x-cloak>
                <x-admin.field label="نشانی رکورد نسخه در فهرست برخط کتابخانه" for="source_url" error="source_url"
                    hint="در صفحۀ نسخه پیوند «دیدن در فهرست برخط کتابخانه» می‌شود. نام فهرست و شمارۀ بازیابی را در «ارجاع فهرست» بنویسید.">
                    <input type="url" name="source_url" id="source_url" value="{{ $val('source_url') }}" maxlength="1000" dir="ltr" :disabled="sourceKind !== 'online'" class="admin-field w-full">
                </x-admin.field>
            </div>
            <div x-show="sourceKind === 'catalogue'" x-cloak class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-admin.field class="sm:col-span-2" label="فهرست (منبع سایت)" error="source_id" hint="اگر فهرست در منابع سایت هست، نسخه کارت همان فهرست را می‌گیرد.">
                    <x-admin.record-picker name="source_id" :setup="$picker('source_id', 'source')" placeholder="نام یا کد منبع…" />
                </x-admin.field>
                <x-admin.field label="نشانی در فهرست" for="source_place" error="source_place" hint="«ج ۶، ص ۶۰۱»">
                    <input type="text" name="source_place" id="source_place" value="{{ $val('source_place') }}" maxlength="60" class="admin-field w-full">
                </x-admin.field>
            </div>
        </fieldset>

        <x-admin.form-actions :cancel="$from ? route('manuscripts.show', $from) : route('admin.dashboard')" submit="ساختن رکورد نسخه" />
    </form>
</x-ui.card>
@endsection
