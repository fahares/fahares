@extends('layouts.app')

@section('title', $person->name . ' | اعلام و پدیدآوران فهارس')
@section('meta_description', 'شناسنامه علمی، آثار و نسخه‌های کتابت‌شده ' . $person->name . ' در فهارس نسخه‌های خطی')

@section('content')
@php
    // Show only tabs that have records; open on the one with data (or the one being paginated).
    $hasAuthored = $authoredWorks->total() > 0;
    $hasScribed = $scribedManuscripts->total() > 0;
    $defaultTab = $hasScribed && (! $hasAuthored || request()->has('manuscripts_page')) ? 'scribed' : 'authored';
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
        <a href="{{ route('search', ['type' => 'people']) }}" class="hover:text-cinnabar">پدیدآوران و کاتبان</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold">{{ $person->name }}</span>
    </nav>

    <!-- PERSON HEADER -->
    <header class="text-center grid justify-items-center gap-2 pb-6">
        <p class="text-xs font-bold text-cinnabar">شناسنامه‌ی شخص</p>
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
        <div class="flex flex-wrap justify-center gap-1.5 mt-1">
            @if($person->is_author)<x-ui.chip tone="accent">پدیدآور</x-ui.chip>@endif
            @if($person->is_scribe || $scribedTotal > 0)<x-ui.chip>کاتب</x-ui.chip>@endif
            @if($person->is_translator)<x-ui.chip>مترجم</x-ui.chip>@endif
            @if($person->is_donor)<x-ui.chip>واقف</x-ui.chip>@endif
        </div>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-suggest.toggle />
        <span class="text-xs text-muted">شناسه‌ی فهارس: <span class="font-mono text-ink font-bold">#{{ $person->id }}</span></span>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px] items-start">

        <!-- Summary column: first on phones -->
        <aside class="space-y-4 lg:order-last lg:sticky lg:top-24">
            <x-ui.card :framed="true">
                <x-ui.meta-list class="!grid-cols-[max-content_minmax(0,1fr)] !gap-y-2">
                    <x-ui.meta label="سده">{{ $person->century_hijri ? 'سده‌ی ' . Fa::digits($person->century_hijri) . ' ق' : 'نامشخص' }}</x-ui.meta>
                    <x-ui.meta label="تولد" field="birth_year_hijri" :empty="! $person->birth_year_hijri">{{ Fa::digits($person->birth_year_hijri) }} ق</x-ui.meta>
                    <x-ui.meta label="وفات" field="death_year_hijri">
                        @if($person->death_year_hijri){{ Fa::digits($person->death_year_hijri) }} ق@else<span class="text-muted">نامشخص</span>@endif
                    </x-ui.meta>
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
                            <div class="text-xs text-muted">نسخه‌ی کتابت‌شده</div>
                        </div>
                    </div>
                @endif
            </x-ui.card>
        </aside>

        <div class="min-w-0 space-y-10">
            <div @unless($person->bio_notes) x-show="$store.suggest.on" x-cloak @endunless>
            <x-ui.section title="زندگی‌نامه‌ی کوتاه" field="bio_notes">
                @if($person->bio_notes)
                    <p class="text-sm sm:text-[15px] text-ink leading-loose whitespace-pre-line max-w-[72ch]">{{ $person->bio_notes }}</p>
                @else
                    <p class="text-sm text-muted">زندگی‌نامه‌ای ثبت نشده است.</p>
                @endif
            </x-ui.section>
            </div>

            @if(! $hasAuthored && ! $hasScribed)
                <p class="text-sm text-muted">اثر یا نسخه‌ی کتابت‌شده‌ای برای این شخص ثبت نشده است.</p>
            @endif

            @if($hasAuthored && $hasScribed)
                <div class="inline-flex border border-line-strong rounded-sm overflow-hidden text-sm" role="tablist">
                    <button type="button" role="tab" @click="activeTab = 'authored'" :aria-selected="activeTab === 'authored'"
                        :class="activeTab === 'authored' ? 'bg-ink text-paper' : 'text-muted hover:text-ink'" class="px-4 py-1.5 transition cursor-pointer">
                        آثار <span class="tabular-nums">{{ Fa::num($worksTotal) }}</span>
                    </button>
                    <button type="button" role="tab" @click="activeTab = 'scribed'" :aria-selected="activeTab === 'scribed'"
                        :class="activeTab === 'scribed' ? 'bg-ink text-paper' : 'text-muted hover:text-ink'" class="px-4 py-1.5 transition cursor-pointer border-s border-line-strong">
                        نسخه‌های کتابت‌شده <span class="tabular-nums">{{ Fa::num($scribedTotal) }}</span>
                    </button>
                </div>
            @endif

            <!-- TAB 1: AUTHORED WORKS -->
            @if($hasAuthored)
                <x-ui.section title="آثار" :count="$authoredWorks->total()" x-show="activeTab === 'authored'" :style="$defaultTab !== 'authored' ? 'display: none' : null">
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
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($scribedManuscripts as $ms)
                            <li class="py-3">
                                <a href="{{ route('manuscripts.show', $ms) }}" class="flex flex-wrap items-baseline gap-x-2 group">
                                    <span class="font-naskh text-base font-bold text-ink group-hover:text-cinnabar transition">{{ $ms->work?->primary_title ?? 'نسخه‌ی بی‌عنوان' }}</span>
                                    @if($ms->is_autograph)<x-ui.chip tone="good" class="!text-[11px]">دستخط مؤلف</x-ui.chip>@endif
                                </a>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                                    @if($ms->work && $ms->work->author_id !== $person->id && $ms->work->author_name)<span>از {{ $ms->work->author_name }}</span>@endif
                                    <span class="text-ink" title="{{ $ms->libraryRecord?->display_name }}">{{ $ms->libraryRecord?->short_name ?? $ms->library }}@if($ms->city)، {{ $ms->city }}@endif</span>
                                    <span>شماره‌ی @if($ms->shelfmark)<span dir="ltr">{{ Fa::digits($ms->shelfmark) }}</span>@else — @endif</span>
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

            <!-- READINGS: adopted, alternative and superseded readings from researchers -->
            <x-suggest.readings />

            <!-- RESEARCH NOTES: published notes and the invitation to add one -->
            <x-suggest.notes type="person" />
        </div>
    </div>

    <!-- SUGGESTION DESK: per-field and general correction suggestions -->
    <x-suggest.desk type="person" :record="$person" />

</div>
@endsection
