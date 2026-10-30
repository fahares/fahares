@extends('layouts.app')

@section('title', $work->primary_title . ' | شناسنامه اثر در فهارس')
@section('meta_description', 'مشخصات کتاب‌شناختی و نسخه‌های خطی ' . $work->primary_title . ' در فهارس نسخه‌های خطی')
@section('og_type', 'book')

@section('content')
<div x-data="{ 
    citationModalOpen: false,
    qrModalOpen: false,
    copiedKey: null,
    copyText(text, key) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                this.copiedKey = key;
                setTimeout(() => { if (this.copiedKey === key) this.copiedKey = null; }, 2500);
            });
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            this.copiedKey = key;
            setTimeout(() => { if (this.copiedKey === key) this.copiedKey = null; }, 2500);
        }
    },
    openQrModal() {
        this.qrModalOpen = true;
        this.$nextTick(() => {
            const canvas = document.getElementById('work-qr-canvas');
            if (canvas && window.QRCode) {
                window.QRCode.toCanvas(canvas, '{{ url('/w/' . $work->id) }}', {
                    width: 220,
                    margin: 1,
                    color: {
                        dark: '#241F1A',
                        light: '#FFFFFF'
                    }
                }, function (error) {
                    if (error) console.error(error);
                });
            }
        });
    },
    downloadQr() {
        const canvas = document.getElementById('work-qr-canvas');
        if (canvas) {
            const link = document.createElement('a');
            link.download = 'qrcode-work-{{ $work->id }}.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        }
    }
}" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    @php
        use App\Support\Fa;
        // the record's sources (steps 13.2 and 13.4), all alike in the order of their rank; its Fankha entry gives the
        // addendum chip and the Fankha citation format
        $sources = $work->sources();
        $fankha = $sources->primary();
        $bibliography = $sources->bibliography()->pluck('citation_text');
        $hasAltTitles = collect($work->alternative_titles ?? [])->contains(fn ($alt) => is_array($alt) && ! empty($alt['title']));
    @endphp

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('search', ['type' => 'works']) }}" class="hover:text-cinnabar">آثار</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold truncate">{{ $work->primary_title }}</span>
    </nav>

    <!-- WORK HEADER -->
    <header class="text-center grid justify-items-center gap-2 pb-6">
        <p class="text-xs font-bold text-cinnabar">شناسنامه‌ی اثر</p>
        <h1 class="font-naskh text-[26px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">
            {{ $work->primary_title }}
            <x-suggest.field name="primary_title" class="ms-1 align-middle" />
        </h1>

        @if($work->clean_title && $work->clean_title !== $work->primary_title)
            <p class="text-sm text-muted">عنوان پیراسته: <span class="text-ink">{{ $work->clean_title }}</span></p>
        @endif

        <p class="text-xs text-muted" dir="ltr" @unless($work->transliteration) x-show="$store.suggest.on" x-cloak @endunless>
            {{ $work->transliteration ?? 'آوانگاری عنوان: —' }}
            <x-suggest.field name="transliteration" class="ms-1" />
        </p>

        <p class="text-base text-ink mt-1">
            @if($work->authorEntries->count() > 1 || $work->authorEntries->contains(fn($e) => ! $e->person_id) || ($work->has_other_authors && $work->authorEntries->isNotEmpty()))
                <x-contributors :entries="$work->authorEntries" :others="$work->has_other_authors" :life="true"
                    link-class="font-bold text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition" />
            @elseif($work->author)
                <a href="{{ route('people.show', $work->author) }}" class="font-bold text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $work->author->name }}</a>
                <x-ui.life :person="$work->author" />
            @elseif($work->author_name)
                <span class="font-bold">{{ $work->author_name }}</span>
            @else
                <span class="text-muted">پدیدآور ناشناخته</span>
            @endif
            <x-suggest.field name="author_name" class="ms-1" />
        </p>

        <div class="flex flex-wrap justify-center gap-1.5 mt-2">
            @if($work->language_summary)<x-ui.chip>{{ $work->language_summary }}</x-ui.chip>@endif
            @if($work->work_form)<x-ui.chip>{{ $work->work_form }}</x-ui.chip>@endif
            @if($work->composition_year_hijri)
                <x-ui.chip>تألیف {{ Fa::digits($work->composition_year_hijri) }} ق</x-ui.chip>
            @endif
            @foreach($work->subjects ?? [] as $subj)
                <x-ui.chip :href="route('subjects.show', $subj)">{{ $subj->title }}</x-ui.chip>
            @endforeach
            @if($work->has_autograph)<x-ui.chip tone="good">نسخه‌ی اصل (دستخط مؤلف)</x-ui.chip>@endif
            @if($fankha?->role === \App\Models\SourceEntry::ROLE_ADDENDUM)<x-ui.chip tone="accent" title="این اثر در بخش استدراک مأخذ معرفی شده است">استدراک</x-ui.chip>@endif
        </div>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <!-- Quick actions: permalink, QR, citation, suggestion mode -->
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex items-center gap-1 p-1 ps-2.5 rounded-sm bg-surface border border-line text-xs">
                <span class="text-muted select-none hidden sm:inline">پیوند کوتاه:</span>
                <a href="{{ url('/w/' . $work->id) }}" class="font-mono text-[11px] font-bold text-ink px-1.5 hover:text-cinnabar transition" dir="ltr" title="پیوند پایدار اثر">/w/{{ $work->id }}</a>
                <button type="button" @click="copyText('{{ url('/w/' . $work->id) }}', 'permalink')"
                    class="relative p-1.5 rounded-sm text-muted hover:text-cinnabar transition cursor-pointer" title="رونوشت پیوند کوتاه">
                    <svg x-show="copiedKey !== 'permalink'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                    <svg x-show="copiedKey === 'permalink'" x-cloak class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                    <span x-show="copiedKey === 'permalink'" x-cloak x-transition class="absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap px-2 py-0.5 rounded-sm bg-ink text-paper text-[10px] font-bold z-30 pointer-events-none">رونوشت شد</span>
                </button>
                <button type="button" @click="openQrModal()" class="p-1.5 rounded-sm text-muted hover:text-cinnabar transition cursor-pointer" title="رمزینه‌ی QR اثر">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" /></svg>
                </button>
            </div>
            <x-ui.btn size="sm" @click="citationModalOpen = true">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" /></svg>
                استناد
            </x-ui.btn>
            <x-suggest.toggle />
        </div>
        <span class="text-xs text-muted">شناسه‌ی فهارس: <span class="font-mono text-ink font-bold">#{{ $work->id }}</span></span>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px] items-start">

        <!-- Summary column: first on phones, beside the details on wide screens -->
        <aside class="space-y-4 lg:order-last lg:sticky lg:top-24">
            <x-ui.card :framed="true" class="text-center sm:text-right">
                <div class="font-naskh text-4xl font-bold text-cinnabar leading-tight tabular-nums">{{ Fa::num($work->manuscripts_count) }}</div>
                <div class="text-sm text-ink">نسخه‌ی ثبت‌شده</div>
                @if(! empty($work->copy_century_text))
                    <div class="text-xs text-muted mt-0.5">{{ Fa::digits($work->copy_century_text) }}</div>
                @endif
                @if($earliest)
                    <a href="{{ route('manuscripts.show', $earliest) }}" class="block mt-3 pt-3 border-t border-line text-xs text-right group">
                        <span class="block text-muted">کهن‌ترین نسخه‌ی تاریخ‌دار</span>
                        <span class="block font-bold text-ink group-hover:text-cinnabar transition" title="{{ $earliest->libraryRecord?->display_name }}">
                            {{ $earliest->libraryRecord?->short_name ?? $earliest->library ?? 'کتابخانه‌ی نامشخص' }}@if($earliest->shelfmark)، <span dir="ltr">{{ Fa::digits($earliest->shelfmark) }}</span>@endif
                        </span>
                        <span class="block text-muted">{{ Fa::digits($earliest->copy_date_raw ?: $earliest->copy_date_hijri_year . ' ق') }}</span>
                    </a>
                @endif
                <x-ui.btn href="#manuscripts" variant="primary" class="w-full mt-4">فهرست نسخه‌ها</x-ui.btn>
            </x-ui.card>

            <!-- Cited sources: bibliographies with a scanned page first, the catalog (فنخا) last -->
            <x-ui.card class="space-y-4">
                <div class="text-xs text-muted">در مآخذ کتاب‌شناسی</div>
                @php
                    // a cited page whose printed entry is on the card below needs no card of its own
                    $entryCards = $sources->cards()->reject(fn ($e) => $e->isMention());
                    $coveredPages = $entryCards->filter(fn ($e) => $e->sourceItem)->map(fn ($e) => [$e->sourceVolume?->facsimile_code, $e->volumeNumber(), $e->sourceItem->page_start, $e->sourceItem->page_end]);
                    $covered = fn ($vp) => $coveredPages->contains(fn ($c) => $c[0] === $vp['catalog'] && $c[1] === (int) $vp['volume'] && $vp['page'] >= $c[2] && $vp['page'] <= $c[3]);
                @endphp
                @foreach(collect(\App\Support\BibliographyLinks::viewerPages($bibliography))->reject($covered) as $vp)
                    @php
                        $vpRef = Fa::digits("ج {$vp['volume']}، " . config("bibliography.sources.{$vp['catalog']}.unit", 'ص') . " {$vp['page']}");
                        $vpThumb = asset('storage/catalogs/' . $vp['catalog'] . '/v' . str_pad($vp['volume'], 2, '0', STR_PAD_LEFT) . '/p' . str_pad($vp['page'], 4, '0', STR_PAD_LEFT) . '.webp');
                    @endphp
                    <div class="space-y-2 pb-4 border-b border-line" data-viewer-card>
                        <div class="text-sm font-bold text-ink">{{ $vp['catalogName'] }}، {{ $vpRef }} <x-suggest.field :name="\App\Suggestions\SuggestionRegistry::bibliographyPageName($vp)" class="ms-1" /></div>
                        <button type="button" @click="$dispatch('open-catalog-viewer', @js($vp))" class="block w-full group cursor-pointer" title="دیدن برگه در {{ $vp['catalogName'] }}">
                            <div class="aspect-[16/9] lg:aspect-[4/3] overflow-hidden border border-line bg-white">
                                <img src="{{ $vpThumb }}" alt="برگه‌ی {{ $vpRef }} در {{ $vp['catalogName'] }}" loading="lazy" class="w-full h-full object-cover object-top group-hover:opacity-90 transition" onerror="this.closest('[data-viewer-card]').remove()">
                            </div>
                            <span class="block mt-1.5 text-xs font-semibold text-cinnabar">دیدن برگه در {{ $vp['catalogName'] }}</span>
                        </button>
                        @if($vpText = \App\Support\PrintedPage::urlForViewer($vp))
                            <a href="{{ $vpText }}" class="inline-block text-xs text-muted hover:text-cinnabar" data-page-text>متن صفحه ←</a>
                        @endif
                    </div>
                @endforeach
                @foreach($entryCards as $entry)
                    <x-source.page-card :entry="$entry" :record="$work" />
                @endforeach
            </x-ui.card>
        </aside>

        <!-- Details -->
        <div class="min-w-0 space-y-10">
            <x-ui.section title="مشخصات">
                <x-ui.meta-list>
                    <x-ui.meta label="تاریخ تألیف" field="composition_date_raw" :empty="! $work->composition_year_hijri && ! $work->composition_date_raw">
                        {{ Fa::digits($work->composition_date_raw ?: $work->composition_year_hijri . ' ق') }}
                    </x-ui.meta>
                    <x-ui.meta label="زبان" field="language_summary" :empty="! $work->language_summary">{{ $work->language_summary }}</x-ui.meta>
                    <x-ui.meta label="قالب" field="work_form" :empty="! $work->work_form">{{ $work->work_form }}</x-ui.meta>
                    @if($work->subjects && $work->subjects->count())
                        <x-ui.meta label="رده‌های موضوعی">
                            @foreach($work->subjects as $subj)
                                <a href="{{ route('subjects.show', $subj) }}" class="border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $subj->title }}</a>@unless($loop->last)، @endunless
                            @endforeach
                        </x-ui.meta>
                    @endif
                    <x-ui.meta label="عناوین دیگر" field="alternative_titles" :empty="! $hasAltTitles">
                        @foreach($work->alternative_titles ?? [] as $alt)
                            @if(is_array($alt) && ! empty($alt['title']))
                                <span class="block">{{ $alt['title'] }}@if(! empty($alt['transliteration'])) <span class="text-xs text-muted" dir="ltr">({{ $alt['transliteration'] }})</span>@endif</span>
                            @endif
                        @endforeach
                    </x-ui.meta>
                    <x-ui.meta label="اهدا / به درخواست" field="dedication" :empty="empty($work->dedication)">{{ $work->dedication }}</x-ui.meta>
                    <x-ui.meta label="محل تألیف" field="composition_place" :empty="empty($work->composition_place)">{{ $work->composition_place }}</x-ui.meta>
                    <x-ui.meta label="وابسته به" field="related_work" :empty="empty($work->related_work)">{{ $work->related_work }}</x-ui.meta>
                </x-ui.meta-list>
            </x-ui.section>

            <!-- People connected with the work other than its authors: translators, dedicatees… -->
            <x-work-people :work="$work" />

            @if(! empty($work->description))
                <x-ui.section title="معرفی اثر" field="description">
                    <x-slot:actions><span class="text-muted">به روایت {{ $fankha?->sourceName() ?: 'فنخا' }}</span></x-slot:actions>
                    <div class="text-ink text-sm sm:text-[15px] leading-loose text-justify whitespace-pre-line max-w-[72ch]">{{ $work->description }}</div>
                </x-ui.section>
            @endif

            @if(! empty($work->incipit_text) || ! empty($work->explicit_text))
                <x-ui.section title="آغاز و انجام">
                    <div class="space-y-5">
                        @if(! empty($work->incipit_text))
                            <x-ui.quote label="آغاز" field="incipit_text">{{ trim($work->incipit_text, "«» \t\n\r\0\x0B") }}</x-ui.quote>
                        @endif
                        @if(! empty($work->explicit_text))
                            <x-ui.quote label="انجام" field="explicit_text">{{ trim($work->explicit_text, "«» \t\n\r\0\x0B") }}</x-ui.quote>
                        @endif
                    </div>
                </x-ui.section>
            @endif

            <!-- Sections hidden while empty: reachable in suggestion mode -->
            @php
                $emptySections = array_filter([
                    'description' => empty($work->description) ? 'معرفی اثر' : null,
                    'incipit_text' => empty($work->incipit_text) ? 'آغاز اثر' : null,
                    'explicit_text' => empty($work->explicit_text) ? 'انجام اثر' : null,
                    'print_info' => empty($work->print_info) ? 'چاپ‌ها و سوابق' : null,
                    'commentaries_and_glosses' => empty($work->commentaries_and_glosses) ? 'شروح و حواشی' : null,
                ]);
            @endphp
            @if($emptySections)
                <div x-show="$store.suggest.on" x-cloak class="p-4 border border-dashed border-cinnabar/40 bg-cinnabar/5 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                    <span class="text-muted font-semibold">بخش‌های خالیِ این اثر:</span>
                    @foreach($emptySections as $name => $label)
                        <span class="inline-flex items-center gap-1.5 font-bold text-ink">{{ $label }} <x-suggest.field :name="$name" /></span>
                    @endforeach
                </div>
            @endif

            @if(! empty($work->print_info))
                <x-ui.section title="چاپ‌ها و سوابق" field="print_info">
                    <div class="text-sm leading-loose text-ink whitespace-pre-line max-w-[72ch]">{{ Fa::digits($work->print_info) }}</div>
                </x-ui.section>
            @endif

            @if(! empty($work->commentaries_and_glosses) && count($work->commentaries_and_glosses) > 0)
                <x-ui.section title="شروح، حواشی و منظومه‌ها" field="commentaries_and_glosses" :count="count($work->commentaries_and_glosses)">
                    <x-slot:actions><span class="text-muted">به روایت {{ $fankha?->sourceName() ?: 'فنخا' }}</span></x-slot:actions>
                    <ul class="space-y-1.5 text-sm" x-data="{ all: false }">
                        @foreach($work->commentaries_and_glosses as $comm)
                            <li class="flex gap-2.5 leading-relaxed" @if($loop->index >= 12) x-show="all" x-cloak @endif>
                                <span class="mt-2.5 w-1.5 h-1.5 shrink-0 rotate-45 bg-gold" aria-hidden="true"></span>
                                <span class="text-ink">{{ Fa::digits($comm) }}</span>
                            </li>
                        @endforeach
                        @if(count($work->commentaries_and_glosses) > 12)
                            <li x-show="! all"><button type="button" @click="all = true" class="text-xs font-semibold text-cinnabar hover:underline cursor-pointer">نمایش همه‌ی {{ Fa::num(count($work->commentaries_and_glosses)) }} مدخل</button></li>
                        @endif
                    </ul>
                </x-ui.section>
            @endif

            <!-- Fankha's bibliography of the work, then the work's places in the sources, Fankha last (step 13.4) -->
            <x-ui.section title="مآخذ کتاب‌شناسی" field="bibliography">
                <ul class="space-y-1 text-sm">
                    @foreach($bibliography as $bib)
                        <li class="flex gap-2.5 leading-relaxed">
                            <span class="mt-2.5 w-1.5 h-1.5 shrink-0 rotate-45 bg-gold" aria-hidden="true"></span>
                            <span class="text-ink">{{ \App\Support\BibliographyLinks::html($bib) }}</span>
                        </li>
                    @endforeach
                    @foreach($sources->cards() as $entry)
                        @if($entry->isMention() && $entry->sourceItem)
                            {{-- named inside another work's entry: one line, never that entry's text (plan dharia-text) --}}
                            <li class="flex gap-2.5 leading-relaxed" data-machine-item="{{ $entry->reviewKey() }}" data-source-mention>
                                <span class="mt-2.5 w-1.5 h-1.5 shrink-0 rotate-45 bg-gold" aria-hidden="true"></span>
                                <span class="text-ink">یاد شده در {{ $entry->sourceName() }}، {{ \App\Support\Fa::digits('ج ' . $entry->volumeNumber() . '، ' . ($entry->source?->unit === 'ستون' ? 'ستون ' : 'ص ') . $entry->sourceItem->page_start) }}، ذیل
                                    <a href="{{ $entry->sourceItem->url() }}" class="text-cinnabar border-b border-cinnabar/40 hover:border-cinnabar transition">«{{ $entry->sourceItem->title }}»</a>@if($entry->sourceItem->entry_number) ({{ $entry->itemLabel() }})@endif
                                    @if($entry->isAwaitingReview())<x-ui.machine-badge class="ms-0.5" :text="$entry->machineNote()" :review="$entry->badgeReview()" />@endif
                                </span>
                            </li>
                            @continue
                        @endif
                        @php
                            $place = $entry->placeLabel(true);
                            $viewer = $entry->viewer();
                            $named = $entry->source?->title . ($entry->role !== \App\Models\SourceEntry::ROLE_ENTRY ? ' (' . $entry->roleLabel() . ')' : '');
                        @endphp
                        <li class="flex gap-2.5 leading-relaxed">
                            <span class="mt-2.5 w-1.5 h-1.5 shrink-0 rotate-45 bg-gold" aria-hidden="true"></span>
                            <span class="text-ink">{{ $named }}@if($place)، @if($viewer)<a href="#" @click.prevent="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($viewer) }})" title="دیدن برگه در {{ $entry->sourceName() }}" class="text-cinnabar border-b border-cinnabar/40 hover:border-cinnabar transition">{{ $place }}</a>@elseif($textPage = $entry->textPageUrl())<a href="{{ $textPage }}" title="متن صفحه در {{ $entry->sourceName() }}" class="text-cinnabar border-b border-cinnabar/40 hover:border-cinnabar transition" data-text-page>{{ $place }}</a>@else{{ $place }}@endif @endif
                                @if($entry->sourceItem)، <a href="{{ $entry->sourceItem->url() }}" class="text-cinnabar border-b border-cinnabar/40 hover:border-cinnabar transition">{{ $entry->itemLabel() }}</a>
                                    @if($entry->isAwaitingReview())<x-ui.machine-badge class="ms-0.5" :text="$entry->machineNote()" :review="$entry->badgeReview()" />@endif
                                @endif</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.section>

            <!-- The text printed at each of those places, under one heading and in the same order -->
            @php $texts = $sources->texts(); @endphp
            @if($texts->isNotEmpty())
                <x-ui.section title="متن مأخذ">
                    <div class="space-y-6">
                        @foreach($texts as $entry)
                            <x-source.text :entry="$entry" :record="$work" />
                        @endforeach
                    </div>
                </x-ui.section>
            @endif

            <!-- Relations with other works (commentary on, translation of…) -->
            <x-record-relations :record="$work" />

            <x-suggest.readings />

            <!-- RESEARCH NOTES: published notes and the invitation to add one -->
            <x-suggest.notes type="work" />
        </div>
    </div>

    <!-- MANUSCRIPTS TABLE SECTION -->
    <x-ui.section id="manuscripts" title="نسخه‌ها" :count="$work->manuscripts_count">
        @if($work->manuscripts_count > 1)
            <x-search.list-tools :params="['type' => 'manuscripts', 'work_id' => $work->id]" placeholder="کتابخانه، کاتب یا شماره در نسخه‌های این اثر…" />
        @endif
        @if(isset($sort) && $sort !== 'sequence')
            <x-slot:actions>
                <span class="text-muted">مرتب‌شده بر اساس:</span>
                <span class="font-bold text-cinnabar">
                    @switch($sort)
                        @case('library') کتابخانه @break
                        @case('shelfmark') شماره نسخه @break
                        @case('scribe') کاتب @break
                        @case('date') تاریخ کتابت @break
                        @case('script') نوع خط @break
                        @case('folios') تعداد برگ @break
                        @default {{ $sort }}
                    @endswitch
                    ({{ $direction === 'asc' ? 'صعودی' : 'نزولی' }})
                </span>
                <a href="{{ route('works.show', $work) }}#manuscripts" class="text-muted hover:text-cinnabar underline">بازنشانی ترتیب</a>
            </x-slot:actions>
        @endif

        <div class="bg-surface border border-line">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs sm:text-[13px] min-w-[640px]">
                    <thead class="text-muted border-b border-gold select-none">
                        @php
                            $renderSortHeader = function($col, $title, $align = 'right') use ($work, $sort, $direction) {
                                $isActive = ($sort === $col);
                                $nextDir = ($isActive && $direction === 'asc') ? 'desc' : 'asc';
                                $url = route('works.show', array_merge(request()->except(['page']), ['id' => $work->getRouteKey(), 'sort' => $col, 'direction' => $nextDir, 'page' => 1])) . '#manuscripts';

                                $html = '<a href="' . e($url) . '" class="inline-flex items-center gap-1.5 hover:text-cinnabar transition group ' . ($isActive ? 'text-cinnabar font-bold' : '') . '" title="مرتب‌سازی بر اساس ' . e($title) . '">';
                                $html .= '<span>' . e($title) . '</span>';

                                if ($isActive) {
                                    $html .= $direction === 'asc'
                                        ? '<svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/></svg>'
                                        : '<svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>';
                                } else {
                                    $html .= '<svg class="w-3 h-3 opacity-40 group-hover:opacity-100 shrink-0 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>';
                                }

                                return $html . '</a>';
                            };
                        @endphp
                        <tr>
                            <th class="py-3 px-3 w-14 text-center font-semibold">{!! $renderSortHeader('sequence', 'ردیف', 'center') !!}</th>
                            <th class="py-3 px-3 font-semibold">{!! $renderSortHeader('library', 'کتابخانه') !!}</th>
                            <th class="py-3 px-3 font-semibold">{!! $renderSortHeader('shelfmark', 'شماره') !!}</th>
                            <th class="py-3 px-3 font-semibold">{!! $renderSortHeader('scribe', 'کاتب') !!}</th>
                            <th class="py-3 px-3 font-semibold">{!! $renderSortHeader('date', 'تاریخ کتابت') !!}</th>
                            <th class="py-3 px-3 font-semibold">{!! $renderSortHeader('script', 'خط') !!}</th>
                            <th class="py-3 px-3 font-semibold">{!! $renderSortHeader('folios', 'برگ') !!}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line text-ink tabular-nums">
                        @forelse($manuscripts as $index => $ms)
                            <tr onclick="if (!window.getSelection().toString()) { if (event.ctrlKey || event.metaKey) { window.open('{{ route('manuscripts.show', $ms) }}', '_blank'); } else { window.location.href = '{{ route('manuscripts.show', $ms) }}'; } }"
                                onauxclick="if (event.button === 1) { window.open('{{ route('manuscripts.show', $ms) }}', '_blank'); }"
                                class="hover:bg-paper cursor-pointer transition">
                                <td class="py-2.5 px-3 text-center text-muted">{{ Fa::num($manuscripts->firstItem() + $index) }}</td>
                                <td class="py-2.5 px-3">
                                    <a href="{{ route('manuscripts.show', $ms) }}" class="font-semibold hover:text-cinnabar" title="{{ $ms->libraryRecord?->display_name }}" onclick="event.stopPropagation()">{{ $ms->libraryRecord?->short_name ?? $ms->library ?? 'نامشخص' }}</a>
                                    @if($ms->city)<span class="block text-[11px] text-muted">{{ $ms->city }}</span>@endif
                                </td>
                                <td class="py-2.5 px-3 font-semibold">
                                    @if($ms->shelfmark)<span dir="ltr">{{ Fa::digits($ms->shelfmark) }}</span>@else بی‌شماره @endif
                                    @if($ms->is_addendum_entry)
                                        <span class="block text-[10px] font-semibold text-muted" title="معرفی‌شده در بخش استدراک، ج {{ $ms->volume_number }} ص {{ $ms->page_start }}">استدراک</span>
                                    @endif
                                    @if($ms->is_distinct_work)
                                        <span class="block text-[10px] font-semibold text-sky-700 dark:text-sky-300" title="متنی جدا با همان عنوان (مدخل غیر همانند)">اثر متمایز</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3">
                                    @if($ms->scribe_name)
                                        {{ $ms->scribe_name }}
                                        <x-ui.life :person="$ms->scribe" />
                                        @if($ms->is_autograph)<span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 ms-1">(مؤلف)</span>@endif
                                    @elseif($ms->is_autograph)
                                        <span class="text-emerald-700 dark:text-emerald-400">{{ $work->author?->name ?? $work->author_name ?? '' }} <span class="text-xs font-semibold">(مؤلف)</span></span>
                                    @elseif($ms->is_bika)
                                        <span class="text-muted">بی‌کاتب</span>
                                    @else
                                        <span class="text-muted">نامشخص</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3">
                                    @if($ms->copy_date_raw)
                                        {{ Fa::digits($ms->copy_date_raw) }}
                                    @elseif($ms->is_bita)
                                        <span class="text-muted">بی‌تاریخ</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3">{{ $ms->script_names ?? '—' }}</td>
                                <td class="py-2.5 px-3">{{ $ms->folios ? Fa::digits($ms->folios) : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-muted">هیچ نسخه‌ای برای این اثر ثبت نشده است.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($manuscripts->hasPages())
                <div class="p-4 border-t border-line">
                    {{ $manuscripts->fragment('manuscripts')->links() }}
                </div>
            @endif
        </div>
    </x-ui.section>

    <!-- SUGGESTION DESK: per-field and general correction suggestions -->
    <x-suggest.desk type="work" :record="$work" />

    <!-- QR CODE MODAL DIALOG (Alpine.js) -->
    <div 
        x-show="qrModalOpen" 
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        
        <div 
            @click.away="qrModalOpen = false"
            class="bg-surface w-full max-w-sm rounded-3xl p-6 sm:p-7 border border-line shadow-2xl space-y-5 text-center relative overflow-hidden">
            
            <div class="flex items-center justify-between pb-3 border-b border-line">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-cinnabar rounded-sm"></span>
                    <h3 class="font-bold text-base text-ink">رمزینه هوشمند (QR Code) اثر</h3>
                </div>
                <button @click="qrModalOpen = false" class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-lg leading-none cursor-pointer">✕</button>
            </div>

            <!-- QR Canvas Container -->
            <div class="flex flex-col items-center justify-center p-4 rounded-2xl bg-white border border-line shadow-inner">
                <canvas id="work-qr-canvas" class="max-w-full h-auto"></canvas>
            </div>

            <!-- Details -->
            <div class="space-y-1.5 text-right bg-paper p-3 rounded-2xl border border-stone-200/70 dark:border-stone-700/70 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-stone-400">عنوان اثر:</span>
                    <span class="font-bold text-cinnabar truncate max-w-[200px]">{{ $work->primary_title }}</span>
                </div>
                @if($work->author || $work->author_name)
                    <div class="flex justify-between items-center">
                        <span class="text-stone-400">مؤلف:</span>
                        <span class="font-bold text-ink truncate max-w-[200px]">{{ $work->author?->name ?? $work->author_name }}</span>
                    </div>
                @endif
                <div class="flex justify-between items-center pt-1.5 border-t border-stone-200/60 dark:border-stone-700/60">
                    <span class="text-stone-400">پیوند پایدار:</span>
                    <span class="font-mono text-[11px] font-bold text-ink" dir="ltr">{{ url('/w/' . $work->id) }}</span>
                </div>
            </div>

            <p class="text-[11px] text-muted leading-relaxed text-justify">
                این بارکد را اسکن کنید تا صفحه شناسنامه کتاب‌شناختی این اثر مستقیماً روی تلفن همراه باز شود.
            </p>

            <!-- Modal Actions -->
            <div class="pt-1 flex items-center justify-center gap-2">
                <button 
                    type="button" 
                    @click="downloadQr()"
                    class="px-4 py-2 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar text-xs font-bold transition flex items-center gap-1.5 shadow-sm cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    <span>ذخیره تصویر QR</span>
                </button>
                <button 
                    type="button" 
                    @click="copyText('{{ url('/w/' . $work->id) }}', 'qr-modal');"
                    class="px-4 py-2 rounded-xl border border-line-strong hover:border-gold text-ink text-xs font-semibold transition cursor-pointer">
                    <span x-show="copiedKey !== 'qr-modal'">کپی آدرس</span>
                    <span x-show="copiedKey === 'qr-modal'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                </button>
            </div>

        </div>
    </div>

    <!-- ACADEMIC CITATION MODAL DIALOG (Alpine.js) -->
    <div 
        x-show="citationModalOpen" 
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        
        <div 
            @click.away="citationModalOpen = false"
            class="bg-surface w-full max-w-2xl rounded-3xl p-6 sm:p-8 border border-line shadow-2xl space-y-6 text-right max-h-[90vh] overflow-y-auto">
            
            <div class="flex items-center justify-between pb-3 border-b border-line">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-cinnabar rounded-sm"></span>
                    <h3 class="font-bold text-lg text-ink">دریافت استناد علمی اثر</h3>
                </div>
                <button @click="citationModalOpen = false" class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-lg leading-none cursor-pointer">✕</button>
            </div>

            <p class="text-xs text-muted leading-relaxed">
                جهت استناد و ارجاع علمی به این اثر در مقالات پژوهشی، تصحیحات انتقادی و پایان‌نامه‌ها، می‌توانید از قالب‌های آماده زیر استفاده فرمایید:
            </p>

            @php
                $workFankhaCitation = "درایتی، مصطفی. فهرستگان نسخه‌های خطی ایران (فنخا). ج " . ($fankha?->volume_number ?? '-') . "، ص " . ($fankha?->page_start ?? '-') . ($fankha?->page_end && $fankha->page_end > $fankha->page_start ? " تا {$fankha->page_end}" : "") . "، مدخل «" . $work->primary_title . "».";
                
                $authorPrefix = $work->author ? $work->author->name . '. ' : ($work->author_name ? $work->author_name . '. ' : '');
                $shortUrlWork = url('/w/' . $work->id);
                $workGeneralCitation = "{$authorPrefix}«{$work->primary_title}». پایگاه فهارس: {$shortUrlWork}";

                $authorEn = $work->author?->name ?? ($work->author_name ?? 'Unknown');
                $workChicagoCitation = "{$authorEn}. \"{$work->primary_title}.\" Fahares Database: {$shortUrlWork}";
            @endphp

            <div class="space-y-4">
                
                <!-- Format 1: FanKha Source -->
                <div class="p-4 rounded-2xl bg-paper border border-line space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-amber-100 dark:bg-amber-950/60 text-cinnabar text-[11px] font-bold">
                                قالب ۱
                            </span>
                            <span class="text-xs font-bold text-ink">قالب مأخذ ({{ $fankha?->source?->title ?? 'فهرستگان نسخه‌های خطی ایران' }})</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($workFankhaCitation), 'fankha')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-surface border border-line text-[11px] font-semibold text-ink hover:text-cinnabar hover:border-gold transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'fankha'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'fankha'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-surface border border-stone-200/80 dark:border-stone-800 text-xs text-ink leading-relaxed font-normal select-all">
                        {{ $workFankhaCitation }}
                    </div>
                </div>

                <!-- Format 2: General Citation in Persian Articles -->
                <div class="p-4 rounded-2xl bg-stone-50 dark:bg-paper border border-stone-200 dark:border-line space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-stone-200 dark:bg-stone-800 text-ink text-[11px] font-bold">
                                قالب ۲
                            </span>
                            <span class="text-xs font-bold text-ink">قالب ارجاع عمومی در مقالات و کتب پژوهشی</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($workGeneralCitation), 'general')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-surface border border-line-strong text-[11px] font-semibold text-ink hover:text-cinnabar hover:border-gold transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'general'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'general'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-surface border border-stone-200/80 dark:border-stone-800 text-xs text-ink leading-relaxed font-normal select-all">
                        {{ $workGeneralCitation }}
                    </div>
                </div>

                <!-- Format 3: Chicago Style -->
                <div class="p-4 rounded-2xl bg-indigo-50/40 dark:bg-paper border border-indigo-100 dark:border-line space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-indigo-100 dark:bg-indigo-950/60 text-ink dark:text-indigo-300 text-[11px] font-bold">
                                قالب ۳
                            </span>
                            <span class="text-xs font-bold text-ink">قالب استاندارد شیکاگو (Chicago Style)</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($workChicagoCitation), 'chicago')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-surface border border-line-strong text-[11px] font-semibold text-ink hover:text-cinnabar hover:border-gold transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'chicago'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'chicago'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-surface border border-stone-200/80 dark:border-stone-800 text-xs text-ink leading-relaxed font-mono select-all text-left" dir="ltr">
                        {{ $workChicagoCitation }}
                    </div>
                </div>

            </div>

            <div class="pt-2 flex justify-end">
                <button type="button" @click="citationModalOpen = false" class="px-5 py-2 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-stone-200 dark:hover:bg-stone-700 text-ink text-xs font-bold transition cursor-pointer">
                    بستن
                </button>
            </div>

        </div>
    </div>

</div>
@endsection

@push('structured_data')
<script type="application/ld+json">
{
 "@@context": "https://schema.org",
 "@type": "Book",
 "name": "{{ addcslashes($work->primary_title, '"\\') }}",
  @if($work->author)"author": { "@type": "Person", "name": "{{ addcslashes($work->author->name, '"\\') }}" },@endif
 "inLanguage": "fa",
 "url": "{{ route('works.show', $work) }}",
 "description": "{{ addcslashes(str_replace(["\r", "\n"], ' ', Str::limit(strip_tags($work->description ?? $fankha?->cleanEntryText() ?? ''), 250)), '"\\') }}"
}
</script>
@endpush

