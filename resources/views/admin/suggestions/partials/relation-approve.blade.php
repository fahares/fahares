{{-- Approval controls for a relational suggestion (move a manuscript, merge duplicates) --}}
<div class="space-y-4">
    @if($errors->any())
        <div class="px-4 py-3 bg-surface border border-s-4 border-cinnabar/50 text-cinnabar font-bold">
            {{ $errors->first() }}
        </div>
    @endif

    @php
        $apply = $relation['apply'] ?? null;
        // The admin's direct action (backlog item 45) posts the same form to its own route, with the tool's choices
        $formAction ??= route('admin.suggestions.approve', $suggestion);
        $directFields ??= [];
    @endphp
    @if($apply === 'record_relation' && $target && $relationTarget)
        @php $relInput = 'admin-field w-full'; @endphp
        <form action="{{ $formAction }}" method="POST" class="space-y-4">
            @csrf
            @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach
            <p class="leading-relaxed text-ink">رابطه در صفحه‌ی هر دو رکورد نمایش داده می‌شود. «این» یعنی رکورد #{{ \App\Support\Fa::digits($target->id) }} و «انتخاب‌شده» یعنی #{{ \App\Support\Fa::digits($relationTarget->id) }}. این تغییر از «گزارش ممیزی» بازگرداندنی است.</p>
            <label class="block space-y-1">
                <span class="block font-bold text-ink">نوع رابطه:</span>
                <select name="role" class="{{ $relInput }}">
                    @foreach(\App\Suggestions\SuggestionRegistry::relationOptions($suggestion->suggestable_type, $relation) as $key => $label)
                        <option value="{{ $key }}" @selected(old('role', $suggestion->payload['role'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <input type="text" name="contributor_note" value="{{ old('contributor_note') }}" placeholder="یادداشت رابطه (اختیاری؛ مثلاً «فقط جلد اول»)" class="{{ $relInput }}">
            <input type="text" name="public_note" value="{{ old('public_note') }}" placeholder="یادداشت عمومی (پیشنهاددهنده می‌بیند؛ اختیاری)" class="{{ $relInput }}">
            <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $relInput }}">
            <button type="submit" class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">{{ $relation['action'] }}</button>
        </form>
    @elseif($apply === 'person_role' && $target && ! empty($relation['remove']))
        @php
            $payload = $suggestion->payload ?? [];
            $removeRows = $target->workPeople()->with('person')->get();
            // The row the suggestion was sent from, else the first that matches its role and person or name
            $suggestedRow = $removeRows->firstWhere('id', $payload['row_id'] ?? 0)
                ?? $removeRows->first(fn ($r) => $r->role === ($payload['role'] ?? null) && ($relationTarget ? $r->person_id === $relationTarget->id : $r->name === ($payload['name'] ?? null)));
            $pickedRow = (int) old('role_row_id', $suggestedRow?->id);
        @endphp
        <form action="{{ $formAction }}" method="POST" class="space-y-4">
            @csrf
            @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach
            <p class="leading-relaxed text-ink">سطری که انتخاب می‌کنید از بخش «اشخاص مرتبط با اثر» حذف می‌شود. این تغییر از «گزارش ممیزی» بازگرداندنی است و سطر با همه‌ی اطلاعاتش برمی‌گردد.</p>
            @if($removeRows->isEmpty())
                <p class="text-muted">این اثر اکنون شخص مرتبطی ندارد؛ پیشنهاد را رد یا بایگانی کنید.</p>
            @else
                <div class="space-y-1.5 p-3 bg-paper border border-line">
                    <span class="block font-bold text-ink">شخص مرتبطی که حذف می‌شود:</span>
                    @foreach($removeRows as $row)
                        <label class="flex items-center gap-2"><input type="radio" name="role_row_id" value="{{ $row->id }}" @checked($pickedRow === $row->id) class="accent-cinnabar">
                            {{ $row->role_label }}: {{ $row->display_name }}@if($row->hedge_label) ({{ $row->hedge_label }})@endif @if($row->note)<span class="text-muted">— {{ $row->note }}</span>@endif</label>
                    @endforeach
                </div>
                <button type="submit" onclick="return confirm('{{ $relation['action'] }} انجام شود؟')"
                    class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">{{ $relation['action'] }}</button>
            @endif
        </form>
    @elseif($apply === 'person_role' && $target)
        @php
            $roleInput = 'admin-field w-full';
            $payload = $suggestion->payload ?? [];
            $defaultLink = old('role_link', $relationTarget ? 'target' : 'name');
        @endphp
        <form action="{{ $formAction }}" method="POST" class="space-y-4">
            @csrf
            @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach
            @php $roleRecord = $target instanceof \App\Models\Work ? 'اثر' : 'نسخه'; @endphp
            <p class="leading-relaxed text-ink">شخص با نقشی که تعیین می‌کنید در بخش «اشخاص مرتبط با {{ $roleRecord }}» در صفحه‌ی {{ $roleRecord }}، و اگر به جدول اشخاص پیوند بخورد در صفحه‌ی آن شخص هم، نمایش داده می‌شود. این تغییر از «گزارش ممیزی» بازگرداندنی است.</p>
            <label class="block space-y-1">
                <span class="block font-bold text-ink">نقش:</span>
                <select name="role" class="{{ $roleInput }}">
                    @foreach(\App\Suggestions\SuggestionRegistry::relationOptions($suggestion->suggestable_type, $relation) as $key => $label)
                        <option value="{{ $key }}" @selected(old('role', $payload['role'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <div class="space-y-1.5 p-3 bg-paper border border-line">
                <span class="block font-bold text-ink">شخص:</span>
                @if($relationTarget)
                    <label class="flex items-center gap-2"><input type="radio" name="role_link" value="target" @checked($defaultLink === 'target') class="accent-cinnabar"> پیوند به «<a href="{{ route('people.show', $relationTarget) }}" target="_blank" class="text-cinnabar hover:underline">{{ $relationTarget->name }}</a>» (انتخاب پیشنهاددهنده)</label>
                @endif
                <label class="flex items-center gap-2"><input type="radio" name="role_link" value="manual" @checked($defaultLink === 'manual') class="accent-cinnabar"> پیوند به شخص با شناسه‌ی <input type="number" name="role_person_id" value="{{ old('role_person_id') }}" class="admin-field w-28 tabular-nums"></label>
                <label class="flex items-center gap-2"><input type="radio" name="role_link" value="name" @checked($defaultLink === 'name') class="accent-cinnabar"> فقط نام، بدون پیوند:</label>
                <input type="text" name="role_name" value="{{ old('role_name', $payload['name'] ?? $relationTarget?->name) }}" class="{{ $roleInput }}">
            </div>
            @if($target instanceof \App\Models\Work)
                <label class="block space-y-1">
                    <span class="block font-bold text-ink">قطعیت انتساب (اختیاری):</span>
                    <select name="certainty" class="{{ $roleInput }}">
                        <option value="">نامشخص (فهرست چیزی نگفته است)</option>
                        @foreach(\App\Models\WorkPerson::CERTAINTIES as $key => $label)
                            <option value="{{ $key }}" @selected(old('certainty') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="block space-y-1">
                <span class="block font-bold text-ink">یادداشت (اختیاری؛ مثلاً «مهر بیضی به تاریخ ۱۲۴۰ق در برگ نخست»):</span>
                <input type="text" name="contributor_note" value="{{ old('contributor_note', $suggestion->suggested_value !== $relation['label'] ? $suggestion->suggested_value : '') }}" class="{{ $roleInput }}">
            </label>
            <input type="text" name="public_note" value="{{ old('public_note') }}" placeholder="یادداشت عمومی (پیشنهاددهنده می‌بیند؛ اختیاری)" class="{{ $roleInput }}">
            <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $roleInput }}">
            <button type="submit" class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">{{ $relation['action'] }}</button>
        </form>
    @elseif(in_array($apply, ['contributor_set', 'contributor_add', 'alias'], true) && $target && ($relationTarget || ! $relation['target']))
        <form action="{{ $formAction }}" method="POST" class="space-y-4">
            @csrf
            @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach
            <p class="leading-relaxed text-ink">
                @switch($apply)
                    @case('contributor_set')
                        «{{ $relationTarget->name }}» {{ $relation['key'] === 'scribe_is' ? 'کاتب اصلی این نسخه' : 'پدیدآور اصلی این اثر' }} می‌شود{{ $relation['key'] === 'scribe_is' ? ($target->scribe_id ? ' و کاتب فعلی از فهرست کاتبان برداشته می‌شود' : '') : ($target->author_id ? ' و پدیدآور فعلی از فهرست پدیدآوران برداشته می‌شود' : '') }}. نامی که فهرست چاپی آورده، دست نمی‌خورد.
                        @break
                    @case('contributor_add')
                        «{{ $relationTarget->name }}» پس از {{ $relation['key'] === 'scribe_also' ? 'کاتبان' : 'پدیدآوران' }} فعلی به فهرست افزوده می‌شود.
                        @break
                    @default
                        این نام به نام‌ها و شهرت‌های «{{ $target->name }}» افزوده می‌شود و در جست‌وجو هم پیدا می‌شود.
                @endswitch
                این تغییر از «گزارش ممیزی» بازگرداندنی است.
            </p>
            @if($apply === 'contributor_add')
                <div class="space-y-1">
                    <label class="block font-bold text-ink">سهم او (اختیاری؛ مثلاً «جلد دوم» یا «از برگ ۴۰ تا پایان»):</label>
                    <input type="text" name="contributor_note" value="{{ old('contributor_note') }}" class="admin-field w-full">
                </div>
            @elseif($apply === 'alias')
                <div class="space-y-1">
                    <label class="block font-bold text-ink">نام دیگر (می‌توانید پیش از تأیید ویرایش کنید):</label>
                    <input type="text" name="alias" value="{{ old('alias', $suggestion->suggested_value) }}" class="admin-field w-full font-bold">
                </div>
            @endif
            <div class="space-y-1">
                <label class="block font-bold text-ink">یادداشت عمومی <span class="font-normal text-muted">(پیشنهاددهنده می‌بیند؛ اختیاری)</span>:</label>
                <input type="text" name="public_note" value="{{ old('public_note') }}" class="admin-field w-full">
            </div>
            <div class="space-y-1">
                <label class="block font-bold text-ink">یادداشت داخلی <span class="font-normal text-muted">(فقط داوران)</span>:</label>
                <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" class="admin-field w-full">
            </div>
            <button type="submit" onclick="return confirm('{{ $relation['action'] }} انجام شود؟')"
                class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">{{ $relation['action'] }}</button>
        </form>
    @elseif($apply === 'relocate' && $target)
        @php
            $locInput = 'admin-field w-full';
            $choice = old('library_choice', $relationTarget ? 'target' : 'keep');
        @endphp
        <form action="{{ $formAction }}" method="POST" class="space-y-4">
            @csrf
            @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach
            <p class="leading-relaxed text-ink">
                نشانی کنونی: <strong>{{ $target->libraryRecord?->name ?? $target->library ?? '—' }}</strong>{{ $target->city ? '، ' . $target->city : '' }}، شماره‌ی <strong>{{ $target->shelfmark ?? 'بی‌شماره' }}</strong>.
                نشانی پیشین در شناسنامه‌ی نسخه با عنوان «نشانی پیشین» می‌ماند و شمار نسخه‌های هر دو کتابخانه به‌روز می‌شود. این تغییر از «گزارش ممیزی» بازگرداندنی است.
            </p>
            <div class="space-y-1.5 p-3 bg-paper border border-line">
                <span class="block font-bold text-ink">کتابخانه:</span>
                @if($relationTarget)
                    <label class="flex items-center gap-2"><input type="radio" name="library_choice" value="target" @checked($choice === 'target') class="accent-cinnabar"> «<a href="{{ route('libraries.show', $relationTarget) }}" target="_blank" class="text-cinnabar hover:underline">{{ $relationTarget->name }}</a>»، {{ $relationTarget->city }} (انتخاب پیشنهاددهنده)</label>
                @endif
                <label class="flex items-center gap-2"><input type="radio" name="library_choice" value="keep" @checked($choice === 'keep') class="accent-cinnabar"> کتابخانه عوض نشده است (فقط شماره)</label>
                <label class="flex items-center gap-2"><input type="radio" name="library_choice" value="manual" @checked($choice === 'manual') class="accent-cinnabar"> کتابخانه با شناسه‌ی <input type="number" name="library_id" value="{{ old('library_id') }}" class="admin-field w-28 tabular-nums"></label>
                <label class="flex items-center gap-2"><input type="radio" name="library_choice" value="new" @checked($choice === 'new') class="accent-cinnabar"> کتابخانه‌ی تازه (در فهارس نیست):</label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <input type="text" name="library_name" value="{{ old('library_name') }}" placeholder="نام کتابخانه" class="{{ $locInput }}">
                    <input type="text" name="library_city" value="{{ old('library_city') }}" placeholder="شهر" class="{{ $locInput }}">
                    <input type="text" name="library_country" value="{{ old('library_country') }}" placeholder="کشور (پیش‌فرض: ایران)" class="{{ $locInput }}">
                </div>
            </div>
            <label class="block space-y-1">
                <span class="block font-bold text-ink">شماره‌ی نسخه:</span>
                <input type="text" name="shelfmark" value="{{ old('shelfmark', $suggestion->payload['shelfmark'] ?? $target->shelfmark) }}" class="{{ $locInput }} font-bold">
            </label>
            <input type="text" name="public_note" value="{{ old('public_note') }}" placeholder="یادداشت عمومی (پیشنهاددهنده می‌بیند؛ اختیاری)" class="{{ $locInput }}">
            <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $locInput }}">
            <button type="submit" onclick="return confirm('{{ $relation['action'] }} انجام شود؟')"
                class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">{{ $relation['action'] }}</button>
        </form>
    @elseif($apply === 'split' && $target)
        @php
            $splitInput = 'admin-field w-full';
            $picked = fn ($name) => array_map('intval', (array) old($name, []));
            $isWork = $target instanceof \App\Models\Work;
            $copyLabel = fn ($m) => trim(($m->libraryRecord?->name ?? $m->library ?? '—') . ' ' . ($m->shelfmark ?? '') . ($m->scribe_name ? ' · کاتب: ' . $m->scribe_name : '') . ($m->copy_date_raw ? ' · ' . $m->copy_date_raw : ''));
            if ($isWork) {
                $splitCopies = $target->manuscripts()->with('libraryRecord')->orderBy('id')->limit(2000)->get();
            } else {
                $splitWorks = $target->authoredWorks()->orderBy('works.id')->limit(2000)->get();
                $splitCopies = $target->scribedManuscripts()->with(['work', 'libraryRecord'])->orderBy('manuscripts.id')->limit(2000)->get();
                $splitRoles = \App\Models\ManuscriptPerson::with('manuscript.work')->where('person_id', $target->id)->orderBy('id')->get();
                $splitWorkRoles = \App\Models\WorkPerson::with('work')->where('person_id', $target->id)->orderBy('id')->get();
            }
        @endphp
        <form action="{{ $formAction }}" method="POST" class="space-y-4">
            @csrf
            @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach
            <p class="leading-relaxed text-ink">
                {{ $isWork ? 'اثر' : 'شخص' }} تازه‌ای ساخته می‌شود و موارد تیک‌خورده به آن منتقل می‌شوند. بقیه در رکورد کنونی (#{{ \App\Support\Fa::digits($target->id) }}) می‌مانند. این تغییر از «گزارش ممیزی» بازگرداندنی است.
            </p>
            @if($isWork)
                <label class="block space-y-1">
                    <span class="block font-bold text-ink">عنوان اثر تازه:</span>
                    <input type="text" name="new_title" value="{{ old('new_title') }}" required class="{{ $splitInput }} font-bold">
                </label>
                <div class="space-y-1.5 p-3 bg-paper border border-line">
                    <span class="block font-bold text-ink">پدیدآور اثر تازه:</span>
                    <label class="flex items-center gap-2"><input type="radio" name="author" value="same" @checked(old('author', 'same') === 'same') class="accent-cinnabar"> همان پدیدآور اثر کنونی ({{ $target->author_name ?? '—' }})</label>
                    <label class="flex items-center gap-2"><input type="radio" name="author" value="person" @checked(old('author') === 'person') class="accent-cinnabar"> شخص با شناسه‌ی <input type="number" name="author_id" value="{{ old('author_id') }}" class="admin-field w-28 tabular-nums"></label>
                    <label class="flex items-center gap-2"><input type="radio" name="author" value="none" @checked(old('author') === 'none') class="accent-cinnabar"> بدون پدیدآور</label>
                </div>
                <label class="flex items-center gap-2"><input type="hidden" name="copy_subjects" value="0"><input type="checkbox" name="copy_subjects" value="1" @checked(old('copy_subjects', '1') === '1') class="accent-cinnabar"> موضوع‌ها و زبان‌های اثر کنونی به اثر تازه هم داده شود</label>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <label class="sm:col-span-2 block space-y-1">
                        <span class="block font-bold text-ink">نام شخص تازه:</span>
                        <input type="text" name="new_name" value="{{ old('new_name') }}" required class="{{ $splitInput }} font-bold">
                    </label>
                    <label class="block space-y-1">
                        <span class="block font-bold text-ink">سال وفات (قمری، اختیاری):</span>
                        <input type="number" name="death_year_hijri" value="{{ old('death_year_hijri') }}" class="{{ $splitInput }}">
                    </label>
                </div>
            @endif

            @foreach(array_filter([
                ! $isWork && $splitWorks->isNotEmpty() ? ['work_ids', 'آثاری که از شخص تازه‌اند', $splitWorks->map(fn ($w) => [$w->id, $w->primary_title . ($w->composition_date_raw ? ' · ' . $w->composition_date_raw : ''), route('works.show', $w)])] : null,
                $splitCopies->isNotEmpty() ? ['manuscript_ids', $isWork ? 'نسخه‌هایی که از اثر تازه‌اند' : 'نسخه‌هایی که کاتبشان شخص تازه است', $splitCopies->map(fn ($m) => [$m->id, ($isWork ? '' : ($m->work?->primary_title . ' — ')) . $copyLabel($m), route('manuscripts.show', $m)])] : null,
                ! $isWork && $splitRoles->isNotEmpty() ? ['role_ids', 'نقش‌های دیگری که از شخص تازه‌اند', $splitRoles->map(fn ($r) => [$r->id, (\App\Models\ManuscriptPerson::ROLES[$r->role] ?? $r->role) . ' — ' . ($r->manuscript?->work?->primary_title ?? 'نسخه') . ' #' . $r->manuscript_id, $r->manuscript ? route('manuscripts.show', $r->manuscript) : null])] : null,
                ! $isWork && $splitWorkRoles->isNotEmpty() ? ['work_role_ids', 'نقش‌هایی در آثار که از شخص تازه‌اند', $splitWorkRoles->map(fn ($r) => [$r->id, $r->role_label . ' — ' . ($r->work?->primary_title ?? 'اثر') . ' #' . $r->work_id, $r->work ? route('works.show', $r->work) : null])] : null,
            ]) as [$field, $caption, $items])
                <div class="space-y-1">
                    <span class="block font-bold text-ink">{{ $caption }} ({{ \App\Support\Fa::num($items->count()) }} مورد):</span>
                    <div class="max-h-72 overflow-y-auto p-2 rounded-sm border border-line space-y-1">
                        @foreach($items as [$id, $label, $url])
                            <label class="flex items-start gap-2"><input type="checkbox" name="{{ $field }}[]" value="{{ $id }}" @checked(in_array($id, $picked($field), true)) class="mt-1 accent-cinnabar">
                                <span>#{{ \App\Support\Fa::digits($id) }} {{ $label }} @if($url)<a href="{{ $url }}" target="_blank" class="text-cinnabar hover:underline">↗</a>@endif</span></label>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <input type="text" name="public_note" value="{{ old('public_note') }}" placeholder="یادداشت عمومی (پیشنهاددهنده می‌بیند؛ اختیاری)" class="{{ $splitInput }}">
            <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $splitInput }}">
            <button type="submit" onclick="return confirm('{{ $relation['action'] }} انجام شود؟')"
                class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">{{ $relation['action'] }}</button>
        </form>
    @elseif($apply === 'remove' && $target)
        @php
            $removeInput = 'admin-field w-full';
            $blockers = app(\App\Suggestions\RecordRemover::class)->blockers($target);
        @endphp
        @if($blockers)
            <p class="px-4 py-3 bg-surface border border-s-4 border-cinnabar/50 text-cinnabar font-bold leading-relaxed">
                این رکورد هنوز به {{ implode('، ', array_map(fn ($label, $n) => \App\Support\Fa::num($n) . ' ' . $label, array_keys($blockers), $blockers)) }} پیوند دارد.
                نخست آن‌ها را به رکورد درست منتقل یا ادغام کنید، سپس این پیشنهاد را تأیید کنید.
            </p>
        @else
            <form action="{{ $formAction }}" method="POST" class="space-y-4">
                @csrf
                @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach
                <div class="px-4 py-3 bg-surface border border-s-4 border-amber-600/50 text-amber-800 dark:text-amber-300 leading-relaxed">
                    رکورد #{{ \App\Support\Fa::digits($target->id) }} حذف می‌شود و نشانی‌اش صفحه‌ی «این رکورد حذف شده است» را نشان می‌دهد{{ $target instanceof \App\Models\Manuscript ? '، و به صفحه‌ی اثرش هدایت می‌شود' : '' }}.
                    اطلاعات کامل آن در گزارش ممیزی می‌ماند و از همان‌جا با همان شناسه بازگرداندنی است.
                </div>
                <input type="text" name="public_note" value="{{ old('public_note') }}" placeholder="یادداشت عمومی (پیشنهاددهنده می‌بیند؛ اختیاری)" class="{{ $removeInput }}">
                <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $removeInput }}">
                <button type="submit" onclick="return confirm('{{ $relation['action'] }} انجام شود؟')"
                    class="w-full py-2.5 px-4 rounded-sm bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold transition cursor-pointer">{{ $relation['action'] }}</button>
            </form>
        @endif
    @elseif($apply === 'rename_library' && $target)
        @php $libInput = 'admin-field w-full'; @endphp
        <form action="{{ $formAction }}" method="POST" class="space-y-4">
            @csrf
            @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach
            <p class="leading-relaxed text-ink">
                نام کنونی: <strong>{{ $target->name }}</strong>، {{ $target->city }}.
                نام کتابخانه در همه‌ی {{ \App\Support\Fa::num($target->manuscripts_count) }} نسخه‌اش هم به‌روز می‌شود. این تغییر از «گزارش ممیزی» بازگرداندنی است.
            </p>
            <label class="block space-y-1">
                <span class="block font-bold text-ink">نام تازه:</span>
                <input type="text" name="name" value="{{ old('name', $suggestion->suggested_value) }}" required class="{{ $libInput }} font-bold">
            </label>
            <label class="flex items-center gap-2"><input type="hidden" name="keep_former_name" value="0"><input type="checkbox" name="keep_former_name" value="1" @checked(old('keep_former_name', '1') === '1') class="accent-cinnabar"> نام کنونی در «نام‌های دیگر» کتابخانه بماند (اگر فقط غلط املایی بوده، تیک را بردارید)</label>
            <input type="text" name="public_note" value="{{ old('public_note') }}" placeholder="یادداشت عمومی (پیشنهاددهنده می‌بیند؛ اختیاری)" class="{{ $libInput }}">
            <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $libInput }}">
            <button type="submit" onclick="return confirm('{{ $relation['action'] }} انجام شود؟')"
                class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">{{ $relation['action'] }}</button>
        </form>
    @elseif($apply === 'merge_library' && $target && $relationTarget)
        @php $libInput = 'admin-field w-full'; @endphp
        <form action="{{ $formAction }}" method="POST" class="space-y-4" x-data="{ keep: @js(old('keep', 'target')) }">
            @csrf
            @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach
            <div class="space-y-1.5">
                <span class="block font-bold text-ink">کدام کتابخانه بماند؟</span>
                <label class="flex items-center gap-2"><input type="radio" name="keep" value="target" x-model="keep" @checked(old('keep', 'target') === 'target') class="accent-cinnabar"> «{{ $relationTarget->name }}»، {{ $relationTarget->city }} (#{{ \App\Support\Fa::digits($relationTarget->id) }}، {{ \App\Support\Fa::num($relationTarget->manuscripts_count) }} نسخه) بماند</label>
                <label class="flex items-center gap-2"><input type="radio" name="keep" value="source" x-model="keep" @checked(old('keep') === 'source') class="accent-cinnabar"> «{{ $target->name }}»، {{ $target->city }} (#{{ \App\Support\Fa::digits($target->id) }}، {{ \App\Support\Fa::num($target->manuscripts_count) }} نسخه) بماند</label>
            </div>
            <div class="px-4 py-3 bg-surface border border-s-4 border-amber-600/50 text-amber-800 dark:text-amber-300 leading-relaxed">
                نسخه‌ها و جلدهای فهرست کتابخانه‌ی حذف‌شونده به کتابخانه‌ی ماندنی منتقل می‌شوند و نام و شهر کتابخانه‌ی ماندنی را می‌گیرند.
                نام کتابخانه‌ی حذف‌شونده در «نام‌های دیگر» کتابخانه‌ی ماندنی می‌ماند و نشانی‌اش با ریدایرکت دائمی به آن می‌رود.
                این ادغام از «گزارش ممیزی» بازگرداندنی است.
            </div>
            @include('admin.suggestions.partials.merge-directions')
            <input type="text" name="public_note" value="{{ old('public_note') }}" placeholder="یادداشت عمومی (پیشنهاددهنده می‌بیند؛ اختیاری)" class="{{ $libInput }}">
            <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $libInput }}">
            <button type="submit" onclick="return confirm('{{ $relation['action'] }} انجام شود؟')"
                class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">{{ $relation['action'] }}</button>
        </form>
    @elseif(! $target || ! $relationTarget)
        <p class="px-4 py-3 bg-surface border border-s-4 border-cinnabar/50 text-cinnabar font-bold">یکی از دو رکورد وجود ندارد و این پیشنهاد قابل اعمال نیست. آن را رد کنید.</p>
    @elseif($apply === 'merge_people')
        <p class="leading-relaxed text-ink">
            ادغام اشخاص با ابزار ادغام اشخاص انجام می‌شود. در آنجا مقایسه‌ی کامل دو شخص را می‌بینید و می‌توانید جای مبدأ و مقصد را عوض کنید. با انجام ادغام، این پیشنهاد خودکار «تأییدشده» ثبت می‌شود و با بازگردانی ادغام، دوباره «در انتظار» می‌شود.
        </p>
        <a href="{{ route('admin.people.merge', ['source_id' => $target->id, 'target_id' => $relationTarget->id, 'suggestion_id' => $suggestion->id ?: null]) }}"
           class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer flex items-center justify-center gap-2">
            رفتن به ابزار ادغام اشخاص
        </a>
    @else
        <form action="{{ $formAction }}" method="POST" class="space-y-4" x-data="{ keep: @js(old('keep', 'target')) }">
            @csrf
            @foreach($directFields as $directName => $directValue)<input type="hidden" name="{{ $directName }}" value="{{ $directValue }}">@endforeach

            @if($relation['key'] === 'move_to_work')
                <p class="leading-relaxed text-ink">
                    نسخه از اثر کنونی‌اش به اثر پیشنهادی منتقل می‌شود و شمار نسخه‌های هر دو اثر به‌روز می‌شود. این تغییر از «گزارش ممیزی» بازگرداندنی است.
                </p>
            @else
                <div class="space-y-1.5">
                    <span class="block font-bold text-ink">کدام رکورد بماند؟</span>
                    <label class="flex items-center gap-2"><input type="radio" name="keep" value="target" x-model="keep" class="accent-cinnabar"> رکورد مقصد (#{{ \App\Support\Fa::digits($relationTarget->id) }}) بماند و این رکورد (#{{ \App\Support\Fa::digits($target->id) }}) در آن ادغام شود</label>
                    <label class="flex items-center gap-2"><input type="radio" name="keep" value="source" x-model="keep" class="accent-cinnabar"> این رکورد (#{{ \App\Support\Fa::digits($target->id) }}) بماند و رکورد مقصد (#{{ \App\Support\Fa::digits($relationTarget->id) }}) در آن ادغام شود</label>
                </div>
                <div class="px-4 py-3 bg-surface border border-s-4 border-amber-600/50 text-amber-800 dark:text-amber-300 leading-relaxed">
                    @if($relation['target'] === 'work')
                        نسخه‌های اثر حذف‌شونده به اثر ماندنی منتقل می‌شوند و نسخه‌هایی که با همان کتابخانه و شماره در اثر ماندنی هست، ادغام می‌شوند. عنوان اثر حذف‌شونده به عناوین دیگر، و موضوع‌ها و زبان‌هایش به اثر ماندنی افزوده می‌شود.
                    @else
                        کاتبان و اشخاص مرتبط نسخه‌ی حذف‌شونده به نسخه‌ی ماندنی افزوده می‌شوند.
                    @endif
                    ارجاع مأخذ رکورد حذف‌شونده روی رکورد ماندنی ثبت می‌شود و نشانی‌اش با ریدایرکت دائمی به آن می‌رود.
                    این ادغام از «گزارش ممیزی» بازگرداندنی است: رکورد حذف‌شده با همان شناسه بازمی‌گردد.
                </div>
                @include('admin.suggestions.partials.merge-directions')
            @endif

            <div class="space-y-1">
                <label class="block font-bold text-ink">یادداشت داور علمی (اختیاری):</label>
                <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" class="admin-field w-full">
            </div>

            <button type="submit" onclick="return confirm('{{ $relation['action'] }} انجام شود؟')"
                class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer flex items-center justify-center gap-2">
                {{ $relation['action'] }}
            </button>
        </form>
    @endif
</div>
