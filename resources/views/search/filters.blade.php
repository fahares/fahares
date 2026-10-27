{{-- The filters of the search tab (backlog item 83, phase 5): the choices and counts come from
     SearchController::filterOptions, the meaning of each from App\Support\SearchFilters --}}
@php
    use App\Support\Fa;
    use App\Support\SearchFilters;

    $v = fn ($param) => $filters->get($param);
    $n = fn ($count) => $count !== null ? ' (' . Fa::num($count) . ')' : '';
    $field = 'w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink';
    $label = 'block text-xs font-bold text-ink/80';
    $subjectItems = fn () => $options['subjects']->map(fn ($s) => ['id' => $s->id, 'name' => $s->title ?: $s->name, 'count' => $s->matching_count, 'hint' => $s->parent?->name])->values()->all();
@endphp
<form action="{{ route('search') }}" method="GET" class="bg-surface p-5 rounded-sm border border-line shadow-sm space-y-5 text-sm">
    @foreach(['q' => $query, 'type' => $type, 'scope' => $scope, 'sort' => $sortKey ?: null, 'per_page' => $perPage !== 20 ? $perPage : null] as $key => $value)
        @if($value !== null && $value !== '')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
    @endforeach

    <div class="flex items-center justify-between pb-3 border-b border-line">
        <span class="rubric text-base">پالایش نتایج</span>
        <button type="submit" class="text-xs text-cinnabar font-semibold hover:underline">اعمال فیلتر</button>
    </div>

    @if($type === 'sources')
        <div class="space-y-2">
            <label for="f-source" class="{{ $label }}">منبع</label>
            <select id="f-source" name="source_id" class="{{ $field }}">
                <option value="">همه‌ی منابع</option>
                @foreach($options['sources'] as $source)
                    <option value="{{ $source->id }}" @selected($v('source_id') === $source->id)>{{ $source->short_name ?: $source->title }}{{ $n($source->matching_count) }}</option>
                @endforeach
            </select>
        </div>
        @if($options['volumes'])
            <div class="space-y-2">
                <label for="f-volume" class="{{ $label }}">جلد</label>
                <select id="f-volume" name="volume" class="{{ $field }}">
                    <option value="">همه‌ی جلدها</option>
                    @foreach($options['volumes'] as $vol => $count)
                        <option value="{{ $vol }}" @selected($v('volume') === $vol)>جلد {{ Fa::digits($vol) }}{{ $n($count) }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    @endif

    @if($type === 'people')
        <div class="space-y-2">
            <label for="f-role" class="{{ $label }}">نقش</label>
            <select id="f-role" name="role" class="{{ $field }}">
                <option value="">همه‌ی نقش‌ها</option>
                @foreach($options['roles'] as $role => [$roleLabel, $count])
                    <option value="{{ $role }}" @selected($v('role') === $role)>{{ $roleLabel }}{{ $n($count) }}</option>
                @endforeach
            </select>
        </div>
        <div class="space-y-2">
            <label for="f-century" class="{{ $label }}">سده‌ی وفات (قمری)</label>
            <select id="f-century" name="century" class="{{ $field }}">
                <option value="">همه‌ی سده‌ها</option>
                @foreach($options['centuries'] as $c => $count)
                    <option value="{{ $c }}" @selected($v('century') === $c)>سده‌ی {{ Fa::digits($c) }}{{ $n($count) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if($type === 'works')
        <x-search.combobox name="subject_id" label="موضوع" :items="$subjectItems()" :selected="$v('subject_id')" all="همه‌ی موضوعات" placeholder="مثلاً فقه، فلسفه…" unit="موضوع" />
        <div class="space-y-2">
            <label for="f-language" class="{{ $label }}">زبان</label>
            <select id="f-language" name="language_id" class="{{ $field }}">
                <option value="">همه‌ی زبان‌ها</option>
                @foreach($options['languages'] as $lang)
                    <option value="{{ $lang->id }}" @selected($v('language_id') === $lang->id)>{{ $lang->name }}{{ $n($lang->matching_count) }}</option>
                @endforeach
            </select>
        </div>
        <div class="space-y-2">
            <label for="f-century" class="{{ $label }}">سده <span class="font-normal text-muted">(تألیف، یا وفات مؤلف)</span></label>
            <select id="f-century" name="century" class="{{ $field }}">
                <option value="">همه‌ی سده‌ها</option>
                @foreach($options['centuries'] as $c => $count)
                    <option value="{{ $c }}" @selected($v('century') === $c)>سده‌ی {{ Fa::digits($c) }}{{ $n($count) }}</option>
                @endforeach
            </select>
        </div>
        <div class="space-y-2">
            <label for="f-form" class="{{ $label }}">قالب اثر</label>
            <select id="f-form" name="work_form" class="{{ $field }}">
                <option value="">همه‌ی قالب‌ها</option>
                @foreach($options['workForms'] as $form => [$formLabel, $count])
                    <option value="{{ $form }}" @selected($v('work_form') === $form)>{{ $formLabel }}{{ $n($count) }}</option>
                @endforeach
            </select>
        </div>
        <div class="space-y-2">
            <label for="f-source" class="{{ $label }}">یادشده در</label>
            <select id="f-source" name="source_id" class="{{ $field }}">
                <option value="">همه‌ی آثار</option>
                @foreach($options['sources'] as $source)
                    <option value="{{ $source->id }}" @selected($v('source_id') === $source->id)>{{ $source->short_name ?: $source->title }}{{ $n($source->matching_count) }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center justify-between gap-2 text-xs text-ink/80 cursor-pointer">
            <span class="flex items-center gap-2">
                <input type="checkbox" name="has_autograph" value="1" @checked($v('has_autograph')) class="w-4 h-4 rounded text-cinnabar focus:ring-cinnabar border-line-strong">
                دارای نسخه‌ی دستخط مؤلف
            </span>
            @if($options['autographs'] !== null)<span class="text-[10px] text-muted">{{ Fa::num($options['autographs']) }}</span>@endif
        </label>
    @endif

    @if($type === 'manuscripts')
        <x-search.combobox name="library_id" label="کتابخانه" :selected="$v('library_id') ?? []" multiple all="همه‌ی کتابخانه‌ها" placeholder="نام کتابخانه یا شهر؛ چند تا هم می‌شود…" unit="کتابخانه"
            :items="$options['libraries']->map(fn ($l) => ['id' => $l->id, 'name' => $l->short_name, 'count' => $l->matching_count, 'hint' => $l->city])->values()->all()" />
        <x-search.combobox name="city" label="شهر کتابخانه" :selected="$v('city')" all="همه‌ی شهرها" placeholder="نام شهر…" unit="شهر"
            :items="collect($options['cities'])->map(fn ($count, $city) => ['id' => $city, 'name' => $city, 'count' => $count, 'hint' => null])->values()->all()" />
        <x-search.combobox name="subject_id" label="موضوع اثر" :items="$subjectItems()" :selected="$v('subject_id')" all="همه‌ی موضوعات" placeholder="مثلاً فقه، فلسفه…" unit="موضوع" />

        <div class="space-y-2">
            <label for="f-century" class="{{ $label }}">سده‌ی کتابت (قمری)</label>
            <select id="f-century" name="century" class="{{ $field }}">
                <option value="">همه‌ی سده‌ها</option>
                @foreach($options['centuries'] as $c => $count)
                    <option value="{{ $c }}" @selected($v('century') === $c)>سده‌ی {{ Fa::digits($c) }}{{ $n($count) }}</option>
                @endforeach
            </select>
            <div class="flex items-center gap-2 text-xs text-muted">
                <span>یا سال</span>
                <input type="text" inputmode="numeric" name="year_from" value="{{ $v('year_from') }}" placeholder="از" aria-label="سال کتابت، از" class="{{ $field }} !p-2 tabular-nums">
                <input type="text" inputmode="numeric" name="year_to" value="{{ $v('year_to') }}" placeholder="تا" aria-label="سال کتابت، تا" class="{{ $field }} !p-2 tabular-nums">
            </div>
        </div>

        <div class="space-y-2">
            <label for="f-script" class="{{ $label }}">خط</label>
            <select id="f-script" name="script_id" class="{{ $field }}">
                <option value="">همه‌ی خطوط</option>
                @foreach($options['scripts'] as $sc)
                    <option value="{{ $sc->id }}" @selected($v('script_id') === $sc->id)>{{ $sc->name }}{{ $n($sc->matching_count) }}</option>
                @endforeach
            </select>
        </div>

        <fieldset class="space-y-2">
            <legend class="{{ $label }} mb-2">ویژگی‌های نسخه</legend>
            @foreach($options['flags'] as $flag => [$flagLabel, $count])
                <label class="flex items-center justify-between gap-2 text-xs text-ink/80 cursor-pointer">
                    <span class="flex items-center gap-2">
                        <input type="checkbox" name="flags[]" value="{{ $flag }}" @checked(in_array($flag, $v('flags') ?? [], true)) class="w-4 h-4 rounded text-cinnabar focus:ring-cinnabar border-line-strong">
                        {{ $flagLabel }}
                    </span>
                    @if($count)<span class="text-[10px] text-muted tabular-nums">{{ Fa::num($count) }}</span>@endif
                </label>
            @endforeach
        </fieldset>

        {{-- Used less often; open when one of them is set --}}
        <details class="group space-y-3" @if($v('language_id') || $v('copy_city') || $v('facsimile') !== null || $v('folios_from') !== null || $v('folios_to') !== null) open @endif>
            <summary class="cursor-pointer text-xs font-bold text-cinnabar list-none">
                <span class="group-open:hidden">+ فیلترهای بیشتر</span><span class="hidden group-open:inline">− فیلترهای بیشتر</span>
            </summary>
            <div class="space-y-4 pt-3">
                <div class="space-y-2">
                    <label for="f-language" class="{{ $label }}">زبان اثر</label>
                    <select id="f-language" name="language_id" class="{{ $field }}">
                        <option value="">همه‌ی زبان‌ها</option>
                        @foreach($options['languages'] as $lang)
                            <option value="{{ $lang->id }}" @selected($v('language_id') === $lang->id)>{{ $lang->name }}{{ $n($lang->matching_count) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-2">
                    <label for="f-copy-city" class="{{ $label }}">محل کتابت</label>
                    <select id="f-copy-city" name="copy_city" class="{{ $field }}">
                        <option value="">همه‌جا</option>
                        @foreach($options['copyCities'] as $city)
                            <option value="{{ $city }}" @selected($v('copy_city') === $city)>{{ $city }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-2">
                    <label for="f-facsimile" class="{{ $label }}">اصل یا عکسی</label>
                    <select id="f-facsimile" name="facsimile" class="{{ $field }}">
                        <option value="">هر دو</option>
                        <option value="0" @selected($v('facsimile') === '0')>فقط نسخه‌ی اصل{{ $n($options['facsimile']['0']) }}</option>
                        <option value="1" @selected($v('facsimile') === '1')>فقط عکسی و میکروفیلم{{ $n($options['facsimile']['1']) }}</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <span class="{{ $label }}">شمار برگ</span>
                    <div class="flex items-center gap-2">
                        <input type="text" inputmode="numeric" name="folios_from" value="{{ $v('folios_from') }}" placeholder="از" aria-label="شمار برگ، از" class="{{ $field }} !p-2 tabular-nums">
                        <input type="text" inputmode="numeric" name="folios_to" value="{{ $v('folios_to') }}" placeholder="تا" aria-label="شمار برگ، تا" class="{{ $field }} !p-2 tabular-nums">
                    </div>
                </div>
            </div>
        </details>
    @endif

    <button type="submit" class="w-full py-2.5 bg-cinnabar hover:opacity-90 text-on-cinnabar font-semibold rounded-sm text-xs transition shadow">
        اعمال فیلترها
    </button>
</form>
