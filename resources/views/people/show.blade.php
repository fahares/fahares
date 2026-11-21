@extends('layouts.app')

@section('title', $person->name . ' | اعلام و پدیدآوران فهارس')
@section('meta_description', 'شناسنامه علمی، آثار و نسخه‌های کتابت‌شده ' . $person->name . ' در فهارس نسخه‌های خطی')

@section('content')
@php
    // Show only tabs that have records; open on the one with data (or the one being paginated).
    $hasAuthored = $authoredWorks->total() > 0;
    $hasScribed = $scribedManuscripts->total() > 0;
    // Works this person translated, had dedicated to them, commissioned…: one tab per role that has any
    $workRoleGroups = $person->workRoles()->with('work')->orderBy('id')->limit(1000)->get()
        ->filter(fn ($r) => $r->work)
        ->sortBy(fn ($r) => array_search($r->role, array_keys(\App\Models\WorkPerson::ROLES), true))
        ->groupBy('role');
    $tabs = array_filter([
        'authored' => $hasAuthored ? 'آثار' : null,
        'scribed' => $hasScribed ? 'نسخه‌های کتابت‌شده' : null,
    ]) + $workRoleGroups->mapWithKeys(fn ($rows, $role) => ['role-' . $role => \App\Models\WorkPerson::PERSON_TABS[$role] ?? $role])->all();
    $defaultTab = $hasScribed && (! $hasAuthored || request()->has('manuscripts_page')) ? 'scribed' : ($hasAuthored ? 'authored' : (array_key_first($tabs) ?? 'authored'));
    // A link may name the tab to open on («?tab=role-dedicatee», from the insights page, step 14.119)
    $defaultTab = isset($tabs[request('tab')]) ? request('tab') : $defaultTab;
@endphp
@php
    use App\Support\Fa;
    $worksTotal = $person->works_count ?? $authoredWorks->total();
    $scribedTotal = $person->scribed_manuscripts_count ?? $person->manuscripts_count ?? $scribedManuscripts->total();
    $roleRows = $person->manuscriptRoles()->with(['manuscript.work', 'manuscript.libraryRecord'])->latest('id')->limit(100)->get();
@endphp
<div x-data="{ activeTab: '{{ $defaultTab }}' }" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        {{-- The tab of the search the person is found in (step 14.154) --}}
        @if($person->is_scribe && ! $person->is_author)
            <a href="{{ route('search', ['type' => 'scribes']) }}" class="hover:text-cinnabar">کاتبان</a>
        @else
            <a href="{{ route('search', ['type' => 'authors']) }}" class="hover:text-cinnabar">پدیدآوران</a>
        @endif
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold">{{ $person->name }}</span>
    </nav>

    <!-- PERSON HEADER -->
    <header class="text-center grid justify-items-center gap-2 pb-6">
        <p class="text-xs font-bold text-cinnabar">شناسنامۀ شخص</p>
        <h1 class="font-naskh text-[26px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">
            {{ $person->name }}
            <x-suggest.field name="name" class="ms-1 align-middle" />
        </h1>
        @if($person->life_span)
            <p class="text-base text-muted">{{ $person->life_span }}</p>
        @endif
        <p class="text-xs text-muted" dir="ltr" @unless($person->transliteration) x-show="$store.suggest.on" x-cloak @endunless>
            {{ $person->transliteration ?? 'آوانگاری نام: —' }}
            <x-suggest.field name="transliteration" class="ms-1" />
        </p>
        <div class="flex flex-wrap justify-center items-center gap-1.5 mt-1 text-sm" @if(empty($person->aliases)) x-show="$store.suggest.on" x-cloak @endif>
            <span class="text-muted text-xs">نام‌های دیگر:</span>
            @foreach($person->aliases ?? [] as $alias)
                <x-ui.chip>{{ $alias }}</x-ui.chip>
            @endforeach
            <x-suggest.field name="aliases" />
        </div>
        {{-- Misspelt forms (backlog item 90): searchable, named once here, not listed with the other names --}}
        @if(! empty($person->wrong_names))
            <p class="text-xs text-muted mt-1">در برخی منابع به صورت {{ collect($person->wrong_names)->map(fn ($n) => '«' . $n . '»')->implode('؛ ') }} آمده است.</p>
        @endif
        <div class="flex flex-wrap justify-center gap-1.5 mt-1">
            @if($person->is_author)<x-ui.chip tone="accent">پدیدآور</x-ui.chip>@endif
            @if($person->is_scribe || $scribedTotal > 0)<x-ui.chip>کاتب</x-ui.chip>@endif
            @if($person->is_translator)<x-ui.chip>مترجم</x-ui.chip>@endif
            @if($person->is_donor)<x-ui.chip>واقف</x-ui.chip>@endif
        </div>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-suggest.toggle type="person" />
        <span class="text-xs text-muted">شناسۀ فهارس: <span class="font-mono text-ink font-bold">#{{ $person->id }}</span></span>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px] items-start">

        <!-- Summary column: first on phones -->
        <aside class="space-y-4 lg:order-last lg:sticky lg:top-24">
            <x-ui.card :framed="true">
                <x-ui.meta-list class="!grid-cols-[max-content_minmax(0,1fr)] !gap-y-2">
                    <x-ui.meta label="سده" field="century_hijri">{{ $person->centuries_text ?? 'نامشخص' }}</x-ui.meta>
                    <x-ui.meta label="تولد" field="birth_year_hijri" :empty="! $person->birth_year_hijri">{{ Fa::digits($person->birth_year_hijri) }} ق</x-ui.meta>
                    <x-date-conflict-note type="person" :id="$person->id" field="birth" />
                    {{-- With no death date, the latest year a dated work or copy shows the person alive (step 14.172) --}}
                    @php $alive = $person->death_year_hijri ? null : \App\Support\DateEstimates\EstimateText::aliveIn($person->id); @endphp
                    <x-ui.meta label="وفات" field="death_year_hijri">
                        @if($person->death_year_hijri){{ Fa::digits($person->death_year_hijri) }} ق@elseif($alive)<x-date-estimate :estimate="$alive" name="alive" />@else<span class="text-muted">نامشخص</span>@endif
                    </x-ui.meta>
                    @if($alive)<x-date-estimate-why :estimate="$alive" name="alive" />@endif
                    <x-date-conflict-note type="person" :id="$person->id" field="death" />
                    <x-ui.meta label="وفات (میلادی)" field="death_year_gregorian" :empty="! $person->death_year_gregorian">{{ Fa::digits($person->death_year_gregorian) }} م</x-ui.meta>
                </x-ui.meta-list>
                @if($worksTotal || $scribedTotal)
                    <div class="mt-4 pt-3 border-t border-line grid grid-cols-2 gap-2 text-center">
                        <div>
                            <div class="font-naskh text-2xl font-bold text-cinnabar tabular-nums">{{ Fa::num($worksTotal) }}</div>
                            <div class="text-xs text-muted">اثر</div>
                        </div>
                        <div>
                            <div class="font-naskh text-2xl font-bold text-cinnabar tabular-nums">{{ Fa::num($scribedTotal) }}</div>
                            <div class="text-xs text-muted">نسخۀ کتابت‌شده</div>
                        </div>
                    </div>
                @endif
            </x-ui.card>
        </aside>

        <div class="min-w-0 space-y-10">
            <div @unless($person->bio_notes) x-show="$store.suggest.on" x-cloak @endunless>
            <x-ui.section title="زندگی‌نامۀ کوتاه" field="bio_notes">
                @if($person->bio_notes)
                    <p class="text-sm sm:text-[15px] text-ink leading-loose whitespace-pre-line max-w-[72ch]">{{ $person->bio_notes }}</p>
                @else
                    <p class="text-sm text-muted">زندگی‌نامه‌ای ثبت نشده است.</p>
                @endif
            </x-ui.section>
            </div>

            {{-- The periods of the person's life a reviewer entered: residence, study, teaching, travel, office (item 139, step 14.218) --}}
            @php $periods = $person->periods; @endphp
            {{-- Empty, and no reviewer: a row of the contribution box, where a member suggests one (step 14.219) --}}
            @if($periods->isEmpty() && ! auth()->user()?->can('people.edit'))
                <x-suggest.contribute-item title="دوره‌های زندگی" label="افزودن اقامت، تحصیل، تدریس، سفر یا منصب…" :payload="['general' => true, 'relation' => 'period']"
                    hint="اگر در منبعی دیده‌اید که این شخص از چه سالی تا چه سالی در شهری اقامت، تحصیل یا تدریس داشته، سفری کرده یا منصبی داشته است، با ذکر مأخذ بنویسید." />
            @endif
            @if($periods->isNotEmpty() || auth()->user()?->can('people.edit'))
                <x-ui.section title="دوره‌های زندگی" :count="$periods->count() ?: null" id="periods">
                    <x-slot:actions>
                        <button type="button" @click="$dispatch('suggest-field', { general: true, relation: 'period' })"
                            class="font-bold text-cinnabar hover:underline cursor-pointer">+ پیشنهاد دوره</button>
                    </x-slot:actions>
                    @if($periods->isNotEmpty())
                        <ul class="divide-y divide-line border-y border-line text-sm">
                            @foreach($periods as $period)
                                <li class="py-2">
                                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                        <span class="text-xs font-bold text-cinnabar">{{ $period->kind_label }}</span>
                                        @if($period->place_name)<span class="font-semibold text-ink">{{ $period->place_name }}</span>@endif
                                        <span class="tabular-nums text-ink">{{ Fa::digits($period->date_text) }}@if($period->period_certainty) <span class="text-xs text-muted">({{ \App\Models\PersonPeriod::CERTAINTIES[$period->period_certainty] ?? '' }})</span>@endif</span>
                                        @if($period->place && $period->place !== $period->place_name)<span class="text-xs text-muted">«{{ $period->place }}»</span>@endif
                                    </div>
                                    @if($period->note || $period->source_text)
                                        <p class="text-[13px] text-muted mt-0.5">{{ implode(' · ', array_filter([$period->note, $period->source_text ? 'مأخذ: ' . $period->source_text : null])) }}</p>
                                    @endif
                                    @can('people.edit')
                                        <details class="mt-1" @if(old('period_form') === 'p' . $period->id) open @endif>
                                            <summary class="text-xs text-muted cursor-pointer hover:text-cinnabar">ویرایش</summary>
                                            <div class="mt-2 space-y-2">
                                                @include('people.partials.period-form', ['action' => route('admin.people.periods.update', [$person->id, $period->id]), 'period' => $period])
                                                <form method="POST" action="{{ route('admin.people.periods.destroy', [$person->id, $period->id]) }}" onsubmit="return confirm('این دوره حذف شود؟')">
                                                    @csrf @method('DELETE')
                                                    <x-ui.btn type="submit" size="sm" variant="ghost">حذف این دوره</x-ui.btn>
                                                </form>
                                            </div>
                                        </details>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-muted">دوره‌ای ثبت نشده است.</p>
                    @endif
                    {{-- What the machine read in the biographies of the sources, for a reviewer to approve or reject (step 14.220) --}}
                    @can('people.edit')
                        @php $proposed = $person->proposedPeriods; @endphp
                        @if($proposed->isNotEmpty())
                            <div class="mt-4 border border-dashed border-line-strong p-3 space-y-3">
                                <p class="text-xs font-bold text-muted">پیشنهادهای هوشواره از زندگی‌نامه‌های منابع ({{ Fa::num($proposed->count()) }}): خوانندگان این‌ها را نمی‌بینند. مکان و سال‌ها را با متن بسنجید، درست کنید و ذخیره کنید، یا رد کنید.</p>
                                @foreach($proposed as $proposal)
                                    <div class="space-y-1.5 text-sm">
                                        <p><span class="text-xs font-bold text-cinnabar">{{ $proposal->kind_label }}</span> <span class="font-semibold">{{ $proposal->place_name }}</span>
                                            @if($proposal->date_text)<span class="tabular-nums">{{ Fa::digits($proposal->date_text) }}</span>@endif</p>
                                        <p class="text-[13px] text-muted leading-6">«{{ $proposal->note }}» <span class="text-xs">({{ $proposal->source_text }})</span></p>
                                        <details @if(old('period_form') === 'p' . $proposal->id) open @endif>
                                            <summary class="text-xs font-bold text-cinnabar cursor-pointer">بازبینی و تأیید</summary>
                                            <div class="mt-2">@include('people.partials.period-form', ['action' => route('admin.people.periods.update', [$person->id, $proposal->id]), 'period' => $proposal, 'submitLabel' => 'تأیید و ثبت دوره'])</div>
                                        </details>
                                        <form method="POST" action="{{ route('admin.people.periods.reject', [$person->id, $proposal->id]) }}">
                                            @csrf
                                            <x-ui.btn type="submit" size="sm" variant="ghost">نادرست است، رد شود</x-ui.btn>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endcan
                    @can('people.edit')
                        <details class="mt-3" @if(old('period_form') === 'new') open @endif>
                            <summary class="text-sm font-semibold text-cinnabar cursor-pointer">افزودن دوره</summary>
                            <div class="mt-2">@include('people.partials.period-form', ['action' => route('admin.people.periods.store', $person->id), 'period' => null])</div>
                        </details>
                    @endcan
                </x-ui.section>
            @endif

            {{-- The city a nisba of the name points to (PersonOrigins, backlog item 137, step 14.291): read by rule, shown as such --}}
            @php $nisbaCities = \App\Support\PersonOrigins::fromName($person->name); @endphp
            @if($nisbaCities)
                <p class="text-sm text-muted" data-person-nisba>
                    <span class="text-xs font-bold text-cinnabar">نسبت نام</span>
                    <span class="text-ink">{{ implode('، ', $nisbaCities) }}</span>
                    <span class="text-xs">خوانده‌شده از نام، بی بازبینی. نسبت خاستگاه شخص یا خاندان اوست و گاه از پدر یا استاد است؛ زادگاه یا محل زندگی را نمی‌گوید.</span>
                </p>
            @endif

            {{-- Where the person was and when, read from their dated copies and works that name a place (item 139, step 14.207) --}}
            @php $places = \App\Support\PersonPlaces::for($person); @endphp
            @if($places)
                <x-ui.section title="حضور در شهرها" :count="count($places)">
                    <p class="text-xs text-muted mb-3">از محل و سالِ کتابت نسخه‌ها و تألیف آثار این شخص. نوشتۀ خود فهرست، اگر با نام شهر فرق دارد، کنار هر شاهد آمده است.</p>
                    <ul class="divide-y divide-line border-y border-line text-sm">
                        @foreach($places as $place)
                            <li>
                                <details class="group">
                                    <summary class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2 cursor-pointer list-none">
                                        <span class="font-semibold text-ink">{{ $place['place'] }}@if($place['kind']) <span class="text-xs font-normal text-muted">({{ $place['kind'] }})</span>@endif</span>
                                        <span class="tabular-nums text-ink">{{ Fa::digits($place['from'] === $place['to'] ? $place['from'] : $place['from'] . '–' . $place['to']) }} ق</span>
                                        <span class="text-xs text-muted ms-auto">{{ Fa::num($place['count']) }} شاهد <span aria-hidden="true" class="inline-block transition group-open:rotate-180">▾</span></span>
                                    </summary>
                                    <ul class="pb-3 ps-3 space-y-1 text-[13px]">
                                        @foreach($place['items'] as $item)
                                            <li><span class="tabular-nums">{{ Fa::digits($item['years']) }}</span> · <span class="text-muted">{{ $item['what'] }}:</span> <a href="{{ $item['url'] }}" class="text-cinnabar hover:underline">{{ $item['title'] }}</a>@if($item['text']) <span class="text-muted">(«{{ $item['text'] }}»)</span>@endif</li>
                                        @endforeach
                                        @if($place['count'] > count($place['items']))
                                            <li class="text-muted">و {{ Fa::num($place['count'] - count($place['items'])) }} شاهد دیگر</li>
                                        @endif
                                    </ul>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.section>
            @endif

            @if(! $tabs)
                <p class="text-sm text-muted">اثر یا نسخۀ کتابت‌شده‌ای برای این شخص ثبت نشده است.</p>
            @endif

            @if(count($tabs) > 1)
                <div class="inline-flex flex-wrap border border-line-strong rounded-sm overflow-hidden text-sm" role="tablist">
                    @foreach($tabs as $tabKey => $tabLabel)
                        @php $tabCount = match ($tabKey) { 'authored' => $worksTotal, 'scribed' => $scribedTotal, default => $workRoleGroups[substr($tabKey, 5)]->count() }; @endphp
                        <button type="button" role="tab" @click="activeTab = '{{ $tabKey }}'" :aria-selected="activeTab === '{{ $tabKey }}'"
                            :class="activeTab === '{{ $tabKey }}' ? 'bg-ink text-paper' : 'text-muted hover:text-ink'" class="px-4 py-1.5 transition cursor-pointer @unless($loop->first) border-s border-line-strong @endunless">
                            {{ $tabLabel }} <span class="tabular-nums">{{ Fa::num($tabCount) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            <!-- TAB 1: AUTHORED WORKS -->
            @if($hasAuthored)
                <x-ui.section title="آثار" :count="$authoredWorks->total()" x-show="activeTab === 'authored'" :style="$defaultTab !== 'authored' ? 'display: none' : null">
                    @if($authoredWorks->total() > 1)
                        <x-search.list-tools :params="['type' => 'works', 'author_id' => $person->id]" placeholder="عنوان در آثار این پدیدآور…" plural="آثار" />
                    @endif
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($authoredWorks as $work)
                            <li class="py-3">
                                <a href="{{ route('works.show', $work) }}" class="flex items-baseline gap-2 group">
                                    <span class="font-naskh text-base sm:text-lg font-bold text-ink group-hover:text-cinnabar transition">{{ $work->primary_title }}</span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($work->manuscripts_count) }} نسخه</span>
                                </a>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                                    @if($work->composition_year_hijri)<span>تألیف {{ Fa::digits($work->composition_year_hijri) }} ق</span>@endif
                                    @if($work->work_form)<span>{{ $work->work_form }}</span>@endif
                                    @foreach($work->subjects->take(2) as $s)<span>{{ $s->title }}</span>@endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if($authoredWorks->hasPages())
                        <div class="pt-4">{{ $authoredWorks->links() }}</div>
                    @endif
                </x-ui.section>
            @endif

            <!-- TAB 2: SCRIBED MANUSCRIPTS -->
            @if($hasScribed)
                <x-ui.section title="نسخه‌های کتابت‌شده" :count="$scribedManuscripts->total()" x-show="activeTab === 'scribed'" :style="$defaultTab !== 'scribed' ? 'display: none' : null">
                    @if($scribedManuscripts->total() > 1)
                        <x-search.list-tools :params="['type' => 'manuscripts', 'scribe_id' => $person->id]" placeholder="عنوان یا کتابخانه در نسخه‌های این کاتب…" />
                    @endif
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($scribedManuscripts as $ms)
                            <li class="py-3">
                                <a href="{{ route('manuscripts.show', $ms) }}" class="flex flex-wrap items-baseline gap-x-2 group">
                                    <span class="font-naskh text-base font-bold text-ink group-hover:text-cinnabar transition">{{ $ms->work?->primary_title ?? 'نسخۀ بی‌عنوان' }}</span>
                                    @if($ms->is_autograph)<x-ui.chip tone="good" class="!text-[11px]">دستخط مؤلف</x-ui.chip>@endif
                                </a>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                                    @if($ms->work && $ms->work->author_id !== $person->id && $ms->work->author_name)<span>از {{ $ms->work->author_name }} <x-ui.life :person="$ms->work->author" /></span>@endif
                                    <span class="text-ink" title="{{ $ms->libraryRecord?->display_name }}">{{ $ms->libraryRecord?->short_name ?? $ms->library }}@if($ms->city)، {{ $ms->city }}@endif</span>
                                    <span>شمارۀ @if($ms->shelfmark)<span dir="ltr">{{ Fa::digits($ms->shelfmark) }}</span>@else — @endif</span>
                                    @if($ms->copy_date_raw)<span>کتابت {{ Fa::digits($ms->copy_date_raw) }}</span>@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if($scribedManuscripts->hasPages())
                        <div class="pt-4">{{ $scribedManuscripts->links() }}</div>
                    @endif
                </x-ui.section>
            @endif

            <!-- WORK ROLES: works this person translated, had dedicated to them, commissioned… (one tab each) -->
            @foreach($workRoleGroups as $role => $rows)
                <x-ui.section :title="\App\Models\WorkPerson::PERSON_TABS[$role] ?? $role" :count="$rows->count()" x-show="activeTab === 'role-{{ $role }}'" :style="$defaultTab !== 'role-' . $role ? 'display: none' : null">
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($rows as $row)
                            <li class="py-3">
                                <a href="{{ route('works.show', $row->work) }}" class="flex items-baseline gap-2 group">
                                    <span class="font-naskh text-base sm:text-lg font-bold text-ink group-hover:text-cinnabar transition">{{ $row->work->primary_title }}</span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($row->work->manuscripts_count) }} نسخه</span>
                                </a>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                                    @if($row->work->author_name && $row->work->author_id !== $person->id)<span>از {{ $row->work->author_name }}</span>@endif
                                    @if($row->hedge_label)<span>{{ $row->hedge_label }}</span>@endif
                                    @if($row->note)<span>{{ $row->note }}</span>@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.section>
            @endforeach

            <!-- OTHER ROLES: manuscripts this person owned, endowed, corrected… -->
            @if($roleRows->isNotEmpty())
                <x-ui.section title="نقش‌های دیگر در نسخه‌ها" :count="$roleRows->count()">
                    <ul class="divide-y divide-line border-y border-line text-sm">
                        @foreach($roleRows as $row)
                            @continue(! $row->manuscript)
                            <li class="py-2.5 flex flex-wrap items-baseline justify-between gap-2">
                                <a href="{{ route('manuscripts.show', $row->manuscript) }}" class="font-semibold text-ink hover:text-cinnabar transition">
                                    {{ $row->manuscript->work?->primary_title ?? 'نسخه' }}
                                    <span class="font-normal text-muted">· {{ $row->manuscript->libraryRecord?->short_name ?? $row->manuscript->library }} <span dir="ltr">{{ Fa::digits($row->manuscript->shelfmark) }}</span></span>
                                </a>
                                <span class="text-xs text-muted">{{ $row->role_label }}@if($row->note) · {{ $row->note }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.section>
            @endif

            <!-- LIVES: his own life in the books of lives, tied from his works (step 14.83) -->
            <x-source.lives :person="$person" />

            <!-- MENTIONS: where the sources' texts name this person, the reverse of their links to him (step 13.136) -->
            <x-source.mentions :mentions="\App\Support\SourceMentions::of($person)" title="یادکرد در منابع"
                note="این یادکردها را ماشین از روی نام و سال درگذشت یافته است، و ممکن است خطا داشته باشد." />

            <!-- READINGS: adopted, alternative and superseded readings from researchers -->
            <x-suggest.readings />

            <!-- RESEARCH NOTES: published notes and the invitation to add one -->
            <x-suggest.notes type="person" />

            <!-- CONTRIBUTE: the empty sections above, as invitations in one box (step 14.132) -->
            <x-suggest.contribute type="person" />

            <!-- CORRECTION HISTORY: approved suggestions that changed this record (backlog item 39) -->
            <x-record-history :record="$person" />
        </div>
    </div>

    <!-- SUGGESTION DESK: per-field and general correction suggestions -->
    <x-suggest.desk type="person" :record="$person" />

</div>
@endsection
