@extends('layouts.app')

@section('title', 'نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' | ' . ($manuscript->libraryRecord?->display_name ?? $manuscript->library) . ' (' . ($manuscript->shelfmark ?? 'بی‌شماره') . ')')
@section('meta_description', 'شناسنامه کالبدشناسی نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' در ' . ($manuscript->libraryRecord?->display_name ?? $manuscript->library) . ' با شماره بازیابی ' . ($manuscript->shelfmark ?? ''))
@section('og_type', 'article')

@section('content')
<div x-data="{ 
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
            const canvas = document.getElementById('manuscript-qr-canvas');
            if (canvas && window.QRCode) {
                window.QRCode.toCanvas(canvas, '{{ url('/m/' . $manuscript->id) }}', {
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
        const canvas = document.getElementById('manuscript-qr-canvas');
        if (canvas) {
            const link = document.createElement('a');
            link.download = 'qrcode-manuscript-{{ $manuscript->id }}.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        }
    }
}" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    @php
        use App\Support\Fa;
        // the record's sources (steps 13.2 and 13.4), all alike in the order of their rank; its Fankha entry gives the
        // addendum chip
        $sources = $manuscript->sources();
        $fankha = $sources->primary();
        $libraryName = $manuscript->libraryRecord?->display_name ?? $manuscript->library ?? 'کتابخانۀ نامشخص';
        $formerLocations = (array) ($manuscript->metadata['former_locations'] ?? []);
        // A photograph's mother, and what the record does not say that the mother does (step 14.16)
        ['chain' => $photoChain, 'mother' => $mother] = $manuscript->facsimileChain();
        $motherNote = $manuscript->original_note ?? collect($photoChain)->map(fn ($p) => $p->original_note)->filter()->last();
        $possiblySame = $manuscript->possiblySame();
        $motherLabel = $mother ? trim(($mother->libraryRecord?->name ?? $mother->library ?? '') . ' ' . ($mother->shelfmark ?? '')) : null;
        // The «مجموعه» the copy reads from: treatises bound together, two or more (backlog item 163, step 14.324)
        $volume = $manuscript->boundCollection();
        $fromMother = fn (?string $value) => $value !== null && $value !== ''
            ? '<span class="border-b border-dotted border-line-strong">' . e(Fa::digits($value)) . '</span> <span class="text-[11px] text-muted">از نسخۀ مادر</span>'
            : null;
        $imageNote = ($researchNotes ?? collect())->firstWhere('field_name', 'image');
        // The parsed year adds nothing when the catalog text already shows it in digits
        $showParsedYear = $manuscript->copy_date_raw && $manuscript->copy_date_hijri_year
            && ! str_contains(strtr($manuscript->copy_date_raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), (string) $manuscript->copy_date_hijri_year);
        $flags = [
            'is_autograph' => 'اصل نسخه (دستخط مؤلف)',
            'has_author_marginalia' => 'حواشی به خط مؤلف',
            'corrected_by_author' => 'مقابله یا تصحیح به دست مؤلف',
            'collated_with_author_copy' => 'مقابله با نسخۀ مؤلف',
            'copied_from_author_copy' => 'کتابت از روی نسخۀ مؤلف',
            'is_illuminated' => 'تذهیب و سرلوح',
            'is_illustrated' => 'نگاره و نقاشی',
            'is_corrected' => 'تصحیح‌شده',
            'has_marginal_notes' => 'حواشی (محشّی)',
            'is_ruled' => 'جدول‌کشی',
            'has_catchwords' => 'رکابه',
            'is_collated' => 'مقابله و بلاغ',
            'is_facsimile' => 'نسخۀ عکسی یا میکروفیلم',
        ];
    @endphp

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('search', ['type' => 'manuscripts']) }}" class="hover:text-cinnabar">نسخه‌ها</a>
        <span aria-hidden="true">/</span>
        @if($manuscript->work)
            <a href="{{ route('works.show', $manuscript->work) }}" class="hover:text-cinnabar truncate max-w-xs">{{ $manuscript->work->primary_title }}</a>
            <span aria-hidden="true">/</span>
        @endif
        <span class="text-ink font-semibold">@if($manuscript->shelfmark)<span dir="ltr">{{ Fa::digits($manuscript->shelfmark) }}</span>@else شناسنامۀ نسخه @endif</span>
    </nav>

    <!-- MANUSCRIPT HEADER -->
    <header class="text-center grid justify-items-center gap-2 pb-6">
        <p class="text-xs font-bold text-cinnabar">شناسنامۀ نسخۀ خطی</p>
        @if($manuscript->work)
            <h1 class="font-naskh text-[26px] sm:text-[36px] font-bold leading-[1.6] text-ink text-balance">
                <a href="{{ route('works.show', $manuscript->work) }}" class="hover:text-cinnabar transition">{{ $manuscript->work->primary_title }}</a>
            </h1>
            @if($manuscript->work->effective_author)
                <p class="text-sm text-ink">
                    از <a href="{{ route('people.show', $manuscript->work->effective_author) }}" class="font-bold border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $manuscript->work->effective_author->name }}</a>
                    <x-ui.life :person="$manuscript->work->effective_author" />
                    @if($manuscript->work->inherits('author'))<x-work-inherited :from="$manuscript->work->traitSource('author')" />@endif
                </p>
            @endif
        @endif
        <p class="mt-1 text-base sm:text-lg text-ink">
            <span class="font-bold">{{ $libraryName }}</span>@if($manuscript->city)<span class="text-muted">، {{ $manuscript->city }}</span>@endif
            <span class="text-muted">·</span>
            <span class="font-bold text-cinnabar">شمارۀ @if($manuscript->shelfmark)<span dir="ltr">{{ Fa::digits($manuscript->shelfmark) }}</span>@else — @endif</span>
        </p>
        <div class="flex flex-wrap justify-center gap-1.5 mt-2">
            @if($manuscript->copy_date_raw)<x-ui.chip>کتابت {{ Fa::digits($manuscript->copy_date_raw) }}</x-ui.chip>@endif
            @if($manuscript->script_names)<x-ui.chip>{{ $manuscript->script_names }}</x-ui.chip>@endif
            @if($manuscript->is_autograph)<x-ui.chip tone="good">دستخط مؤلف</x-ui.chip>@endif
            @if($manuscript->is_illuminated)<x-ui.chip tone="accent">مذهّب</x-ui.chip>@endif
            @if($manuscript->is_illustrated)<x-ui.chip tone="accent">مصوّر</x-ui.chip>@endif
            @if($manuscript->is_distinct_work)<x-ui.chip title="این نسخه ذیل مدخلی «غیر همانند» آمده است: متنی جدا با همان عنوان">اثر متمایز</x-ui.chip>@endif
            @if($fankha?->role === \App\Models\SourceEntry::ROLE_ADDENDUM)<x-ui.chip tone="accent" title="این نسخه در بخش استدراک مأخذ معرفی شده است">استدراک</x-ui.chip>@endif
            @if($manuscript->is_facsimile)<x-ui.chip title="نسخۀ عکسی یا میکروفیلم">عکسی</x-ui.chip>@endif
        </div>
    </header>

    {{-- A photograph and its mother, or a description possibly of another copy (step 14.16) --}}
    @if($mother || $manuscript->is_facsimile || $possiblySame)
        <div class="border border-line bg-paper px-4 py-3 text-sm leading-relaxed space-y-1.5" data-copy-link x-data>
            @if($mother)
                <p>این نسخه عکسی یا میکروفیلمِ <a href="{{ route('manuscripts.show', $mother) }}" class="font-semibold border-b border-line-strong hover:text-cinnabar">{{ Fa::digits($motherLabel) }}</a>@if($mother->city)<span class="text-muted">، {{ $mother->city }}</span>@endif است.
                    @if($photoChain)<span class="text-muted">(از راه عکسیِ <a href="{{ route('manuscripts.show', $photoChain[0]) }}" class="border-b border-line-strong hover:text-cinnabar">{{ Fa::digits(trim(($photoChain[0]->library ?? '') . ' ' . ($photoChain[0]->shelfmark ?? ''))) }}</a>)</span>@endif
                    آنچه این نسخه ندارد و با خط نقطه‌چین آمده، از آن نسخه است.</p>
            @elseif($manuscript->is_facsimile)
                <p>این نسخه عکسی یا میکروفیلم است. نسخۀ مادر: <span class="font-semibold">{{ $motherNote ? Fa::digits($motherNote) : 'نامعلوم' }}</span>
                    @if($photoChain)<span class="text-muted">(همان مادرِ عکسیِ <a href="{{ route('manuscripts.show', $photoChain[0]) }}" class="border-b border-line-strong hover:text-cinnabar">{{ Fa::digits(trim(($photoChain[0]->library ?? '') . ' ' . ($photoChain[0]->shelfmark ?? ''))) }}</a>)</span>@endif
                </p>
                @if($motherNote)
                    <button type="button" @click="$dispatch('suggest-field', { general: true, relation: 'manuscript_relation' })" class="text-[13px] font-bold text-cinnabar hover:underline cursor-pointer">این نسخۀ مادر را در سامانه یافته‌اید؟</button>
                @else
                    <button type="button" @click="$dispatch('suggest-field', { general: true })" class="text-[13px] font-bold text-cinnabar hover:underline cursor-pointer">نسخۀ مادرش را می‌شناسید؟</button>
                @endif
            @endif
            @if($possiblySame)
                <p>بنا بر یادداشت «همان نسخه بالا» در مأخذ، این معرفی احتمالاً همان نسخۀ <a href="{{ route('manuscripts.show', $possiblySame) }}" class="font-semibold border-b border-line-strong hover:text-cinnabar">{{ Fa::digits(trim(($possiblySame->libraryRecord?->name ?? $possiblySame->library ?? '') . ' ' . ($possiblySame->shelfmark ?? ''))) }}</a> است. تا روشن شدن آن، دو رکورد جدا مانده‌اند.</p>
                {{-- One step for a reader who knows: a suggestion with the other copy already chosen, for the reviewers --}}
                @php $sameTarget = \App\Suggestions\SuggestionRegistry::describe($possiblySame) + ['id' => $possiblySame->id]; @endphp
                <div class="flex flex-wrap items-center gap-2 text-[13px]" data-same-answer>
                    <span class="text-muted">شما می‌دانید؟</span>
                    <button type="button" @click="$dispatch('suggest-field', { general: true, relation: 'duplicate', target: @js($sameTarget) })" class="px-2.5 py-1 rounded-sm border border-line-strong font-bold hover:text-cinnabar hover:border-cinnabar cursor-pointer">یک نسخه‌اند</button>
                    <button type="button" @click="$dispatch('suggest-field', { general: true, relation: 'manuscript_relation', role: 'distinct_from', target: @js($sameTarget) })" class="px-2.5 py-1 rounded-sm border border-line-strong font-bold hover:text-cinnabar hover:border-cinnabar cursor-pointer">دو نسخۀ جدایند</button>
                </div>
            @endif
        </div>
    @endif

    <div class="jadval" aria-hidden="true"></div>

    <!-- Quick actions: permalink, QR, digital image, suggestion mode; citing is on the source pages (step 14.390) -->
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex items-center gap-1 p-1 ps-2.5 rounded-sm bg-surface border border-line text-xs">
                <span class="text-muted select-none hidden sm:inline">پیوند کوتاه:</span>
                <a href="{{ url('/m/' . $manuscript->id) }}" class="font-mono text-[11px] font-bold text-ink px-1.5 hover:text-cinnabar transition" dir="ltr" title="پیوند پایدار نسخه">/m/{{ $manuscript->id }}</a>
                <button type="button" @click="copyText('{{ url('/m/' . $manuscript->id) }}', 'permalink')"
                    class="relative p-1.5 rounded-sm text-muted hover:text-cinnabar transition cursor-pointer" title="رونوشت پیوند کوتاه">
                    <svg x-show="copiedKey !== 'permalink'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                    <svg x-show="copiedKey === 'permalink'" x-cloak class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                    <span x-show="copiedKey === 'permalink'" x-cloak x-transition class="absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap px-2 py-0.5 rounded-sm bg-ink text-paper text-[10px] font-bold z-30 pointer-events-none">رونوشت شد</span>
                </button>
                <button type="button" @click="openQrModal()" class="p-1.5 rounded-sm text-muted hover:text-cinnabar transition cursor-pointer" title="رمزینۀ QR نسخه">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" /></svg>
                </button>
            </div>
            {{-- A published digital image of this copy (research note), see <x-suggest.notes> --}}
            @if($imageNote)
                <x-ui.btn size="sm" href="#research-notes">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    تصویر نسخه
                </x-ui.btn>
            @endif
            <x-suggest.toggle type="manuscript" />
        </div>
        <span class="text-xs text-muted"><x-visit-count type="manuscript" :id="$manuscript->id" />شناسۀ فهارس: <span class="font-mono text-ink font-bold">#{{ $manuscript->id }}</span></span>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px] items-start">

        <!-- Summary column: first on phones, beside the details on wide screens -->
        <aside class="space-y-4 lg:order-last">
            <x-ui.card :framed="true" class="space-y-3 text-sm">
                @if($manuscript->libraryRecord?->logo_url)
                    <!-- The holding library's emblem heads the card, as large as its file allows -->
                    <a href="{{ route('libraries.show', $manuscript->libraryRecord) }}" class="block hover:opacity-90 transition" tabindex="-1" aria-hidden="true">
                        <x-library-logo :library="$manuscript->libraryRecord" size="band" :decorative="true" />
                    </a>
                @endif
                <div>
                    <div class="text-xs text-muted">محل نگهداری</div>
                    @if($manuscript->libraryRecord || $manuscript->library_id)
                        <a href="{{ route('libraries.show', $manuscript->libraryRecord ?? $manuscript->library_id) }}" class="font-bold text-ink hover:text-cinnabar transition">{{ $libraryName }}</a>
                    @else
                        <span class="font-bold text-ink">{{ $libraryName }}</span>
                    @endif
                    @if($manuscript->city)<span class="block text-xs text-muted">{{ $manuscript->city }}</span>@endif
                </div>
                <div>
                    <div class="text-xs text-muted">شمارۀ نسخه</div>
                    <span class="font-naskh text-2xl font-bold text-cinnabar">@if($manuscript->shelfmark)<span dir="ltr">{{ Fa::digits($manuscript->shelfmark) }}</span>@else بی‌شماره @endif</span>
                    <x-suggest.field name="shelfmark" class="ms-1" />
                </div>
                @if($formerLocations)
                    <!-- Where the copy was before it moved or was renumbered (older catalogs cite these) -->
                    <div class="pt-3 border-t border-line text-xs text-muted space-y-0.5">
                        @foreach($formerLocations as $former)
                            <div>نشانی پیشین: <span class="text-ink font-semibold">{{ implode('، ', array_filter([$former['library'] ?? null, $former['city'] ?? null])) ?: '—' }}، شمارۀ @if(isset($former['shelfmark']))<span dir="ltr">{{ Fa::digits($former['shelfmark']) }}</span>@else بی‌شماره @endif</span>@if($extent = \App\Support\FormerLocation::extent($former))<span>، {{ $extent }}</span>@endif @if(isset($former['catalog']))<span> (در {{ Fa::digits($former['catalog']) }})</span>@endif</div>
                        @endforeach
                    </div>
                @endif
                @if($manuscript->work)
                    <x-ui.btn :href="route('works.show', $manuscript->work)" class="w-full">
                        نسخه‌های دیگر این اثر
                        @if($manuscript->work->manuscripts_count)<span class="text-muted font-normal">({{ Fa::num($manuscript->work->manuscripts_count) }})</span>@endif
                    </x-ui.btn>
                @endif
            </x-ui.card>

            <!-- The copy's places in the sources (step 13.4), in the order of their rank: the library's catalogues first, Fankha, compiled from them, last -->
            <x-ui.card class="space-y-4">
                <div class="text-xs text-muted">در مآخذ نسخه‌شناسی</div>
                @php
                    // Fankha's own card folded under the printed catalogues' (backlog item 147)
                    [$secondHandGroups, $firstHandGroups] = $sources->cardGroups()->partition(fn ($g) => $g['entries'][0]->isSecondHand());
                    // and left out, but in suggestion mode, when the reader sees the copy in its catalogue (step 14.376)
                    $seesFirstHand = $sources->seesFirstHand();
                @endphp
                @foreach($firstHandGroups as $group)
                    @if($group['label'])
                        {{-- several pages of one volume (step 14.42) --}}
                        <div class="space-y-2 pb-4 border-b border-line last:border-b-0 last:pb-0" data-source-group>
                            <div class="text-sm font-bold text-ink leading-6">{{ $group['label'] }}</div>
                            @foreach($group['entries'] as $entry)
                                <x-source.page-card :entry="$entry" :record="$manuscript" grouped />
                            @endforeach
                        </div>
                    @else
                        <x-source.page-card :entry="$group['entries'][0]" :record="$manuscript" />
                    @endif
                @endforeach
                @if($secondHandGroups->isNotEmpty())
                    <x-source.secondary :suggest-only="$seesFirstHand">
                        @foreach($secondHandGroups as $group)
                            @foreach($group['entries'] as $entry)
                                <x-source.page-card :entry="$entry" :record="$manuscript" />
                            @endforeach
                        @endforeach
                    </x-source.secondary>
                @endif
                {{-- A copy made in the panel (backlog item 149): where the knowledge of it came from --}}
                @if($provenance = $manuscript->provenance())
                    <div class="space-y-1 pb-4 border-b border-line last:border-b-0 last:pb-0" data-provenance="{{ $provenance['kind'] }}">
                        <div class="text-xs text-muted">منبع اطلاع</div>
                        <p class="text-sm font-bold text-ink leading-6">{{ Fa::digits($provenance['label']) }}</p>
                        @if($provenance['suggestion_id'])
                            @can('suggestions.review')
                                <a href="{{ route('admin.suggestions.show', $provenance['suggestion_id']) }}" class="text-[11px] text-muted hover:text-cinnabar" data-provenance-suggestion>پیشنهاد #{{ Fa::digits($provenance['suggestion_id']) }}</a>
                            @endcan
                        @endif
                    </div>
                @endif
                @php
                    // the cataloger, volume and part of the copy's citation entry (step 14.402)
                    $cited = $manuscript->citedEntry;
                    $cataloger = $cited?->cataloger;
                    $catalogVolume = $cited?->catalogVolume;
                @endphp
                @if($cataloger)
                    @php
                        // A copy in a volume compiled under someone else (an article of «اوراق عتیق»): name the volume and its supervisor
                        $supervisors = $catalogVolume?->catalogers->where('pivot.role', 'supervisor')->where('id', '!=', $cataloger->id);
                        // ...or of a part of a volume of several libraries' catalogues: the part, its other catalogers, and
                        // the volume's people, all its editors (step 13.150)
                        $part = $cited->relationLoaded('catalogVolumePart') ? $cited->catalogVolumePart : null;
                        if ($part) {
                            $supervisors = $catalogVolume?->catalogers->where('id', '!=', $cataloger->id);
                            $coauthors = $part->catalogers->where('id', '!=', $cataloger->id);
                        }
                    @endphp
                    <a href="{{ route('catalogers.show', $cataloger) }}" class="flex items-center gap-3 pt-3 border-t border-line group">
                        <span class="w-11 h-14 shrink-0 rounded-t-full overflow-hidden border border-gold bg-paper grid place-items-center">
                            @if($cataloger->avatar_url)
                                <img src="{{ $cataloger->avatar_url }}" alt="" class="w-full h-full object-cover sepia-[.85]">
                            @else
                                <span class="font-naskh text-lg text-muted">{{ mb_substr($cataloger->name, 0, 1) }}</span>
                            @endif
                        </span>
                        <span class="min-w-0">
                            <span class="block text-xs text-muted">فهرست‌نگار</span>
                            <span class="block text-sm font-bold text-ink group-hover:text-cinnabar transition">{{ $cataloger->display_name }}</span>
                            @if($cataloger->life_years_text)
                                <span class="block text-[11px] text-muted">{{ Fa::digits($cataloger->life_years_text) }}</span>
                            @endif
                            @if($part)
                                @if($coauthors->isNotEmpty())<span class="block text-[11px] text-muted" data-cataloger-coauthors>با {{ $coauthors->map(fn ($c) => $c->display_name ?? $c->name)->implode('، ') }}</span>@endif
                                <span class="block text-[11px] text-muted" data-cataloger-part>در «{{ Fa::digits($catalogVolume->title) }}»، بخش «{{ Fa::digits($part->title) }}»{{ $part->pages_text ? ' (' . Fa::digits($part->pages_text) . ')' : '' }}{{ $supervisors?->isNotEmpty() ? '، زیر نظر ' . $supervisors->map(fn ($c) => $c->display_name ?? $c->name)->implode('، ') : '' }}</span>
                            @elseif($supervisors?->isNotEmpty())
                                <span class="block text-[11px] text-muted" data-cataloger-volume>در «{{ Fa::digits($catalogVolume->title) }}»، زیر نظر {{ $supervisors->map(fn ($c) => $c->display_name ?? $c->name)->implode('، ') }}</span>
                            @endif
                        </span>
                    </a>
                @endif
            </x-ui.card>
        </aside>

        <!-- Details -->
        <div class="min-w-0 space-y-10">
            <x-ui.section title="کاتب و کتابت">
                <x-ui.meta-list>
                    <x-ui.meta label="کاتب" field="scribe_name">
                        @if($manuscript->scribeEntries->count() > 1 || $manuscript->scribeEntries->contains(fn($e) => ! $e->person_id) || ($manuscript->has_other_scribes && $manuscript->scribeEntries->isNotEmpty()))
                            <x-contributors :entries="$manuscript->scribeEntries" :others="$manuscript->has_other_scribes" :life="true"
                                link-class="font-semibold border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition" />
                        @elseif($manuscript->is_autograph)
                            @php $autographPerson = $manuscript->work?->effective_author ?? $manuscript->scribe; @endphp
                            @if($autographPerson)
                                <a href="{{ route('people.show', $autographPerson) }}" class="font-semibold text-emerald-700 dark:text-emerald-400 hover:underline">دستخط مؤلف ({{ $autographPerson->name }})</a>
                                <x-ui.life :person="$autographPerson" />
                            @else
                                <span class="font-semibold text-emerald-700 dark:text-emerald-400">مؤلف (نسخۀ اصل)</span>
                            @endif
                        @elseif($manuscript->scribe_id)
                            <a href="{{ route('people.show', $manuscript->scribe_id) }}" class="font-semibold border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $manuscript->scribe_name ?: 'دیدن کاتب' }}</a>
                            <x-ui.life :person="$manuscript->scribe" />
                        @elseif($manuscript->scribe_name)
                            {{ $manuscript->scribe_name }}
                        @elseif($inherited = $fromMother($mother?->scribe_name))
                            {!! $inherited !!}
                        @elseif($manuscript->inheritsFromCollection('scribe'))
                            {{-- the scribe its volume's treatises agree on (backlog item 163, step 14.324) --}}
                            @if($volume->scribe_id)
                                <a href="{{ route('people.show', $volume->scribe_id) }}" class="font-semibold border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $volume->scribe_name ?: $volume->scribe?->name }}</a>
                            @else
                                {{ $volume->scribe_name }}
                            @endif
                            <x-manuscript-inherited :manuscript="$manuscript" field="scribe" />
                        @else
                            <span class="text-muted">ثبت نشده</span>
                        @endif
                    </x-ui.meta>
                    <x-ui.meta label="تاریخ کتابت" field="copy_date_raw">
                        @if($manuscript->copy_date_raw)
                            {{ Fa::digits($manuscript->copy_date_raw) }}
                            @if($showParsedYear)<span class="text-muted">({{ Fa::digits($manuscript->copy_date_hijri_year) }} ق)</span>@endif
                            {{-- A note on the date the parser split off it («در حیات مؤلف»), backlog item 96 --}}
                            @if($manuscript->copy_date_note)<span class="block text-[12px] text-muted">{{ $manuscript->copy_date_note }}</span>@endif
                            {{-- A three-digit year far before the work, read again as a year that lost a digit (item 128) --}}
                            @if($rereadYear = \App\Support\CopyDateRange::rereadYear($manuscript->copy_date_hedge_raw))
                                <span class="block text-[12px] text-muted">سال {{ Fa::digits($rereadYear) }} از زمان این اثر بسیار پیش‌تر است. با فرض افتادن یک رقم، {{ Fa::digits($manuscript->copy_date_from === $manuscript->copy_date_to ? $manuscript->copy_date_from : $manuscript->copy_date_from . ' تا ' . $manuscript->copy_date_to) }} ق خوانده شد.</span>
                            @endif
                        @elseif($inherited = $fromMother($mother?->copy_date_raw))
                            {!! $inherited !!}
                        @else
                            <span class="text-muted">ثبت نشده</span>
                            <x-manuscript-siblings-stating :manuscript="$manuscript" field="copy_date_raw" what="تاریخ کتابت" />
                        @endif
                    </x-ui.meta>
                    <x-date-conflict-note type="manuscript" :id="$manuscript->id" field="copy" />
                    <x-ui.meta label="محل کتابت" field="copy_place">
                        @if($manuscript->copy_place){{ $manuscript->copy_place }}@elseif($inherited = $fromMother($mother?->copy_place)){!! $inherited !!}@else<span class="text-muted">ثبت نشده</span><x-manuscript-siblings-stating :manuscript="$manuscript" field="copy_place" what="محل کتابت" />@endif
                    </x-ui.meta>
                    {{-- A commissioner listed among the people of the copy (role patron, backlog item 96) is not repeated here --}}
                    @unless($manuscript->commissioned_by && $manuscript->otherPeople()->where('role', 'patron')->whereNull('person_id')->where('name', $manuscript->commissioned_by)->exists())
                        <x-ui.meta label="سفارش‌دهنده" field="commissioned_by" :empty="! $manuscript->commissioned_by">{{ $manuscript->commissioned_by }}</x-ui.meta>
                    @endunless
                </x-ui.meta-list>
            </x-ui.section>

            <x-ui.section title="ویژگی‌های ظاهری">
                <x-ui.meta-list>
                    {{-- Two fields in one row: each pencil stands by its own value, and the one of an empty «کیفیت خط»
                         comes only in suggestion mode, with its name (a bare second pencil says nothing) --}}
                    <x-ui.meta label="خط">
                        @if($manuscript->script_names){{ $manuscript->script_names }}@elseif($inherited = $fromMother($mother?->script_names)){!! $inherited !!}@elseif($manuscript->inheritsFromCollection('script_names')){{ $volume->script_names }}@if($volume->script_style) <span class="text-muted">({{ $volume->script_style }})</span>@endif <x-manuscript-inherited :manuscript="$manuscript" field="script_names" /><x-suggest.field name="collection_script_names" title="پیشنهاد اصلاح خط همۀ مجموعه" aria-label="پیشنهاد اصلاح خط همۀ مجموعه" />@else<span class="text-muted">ثبت نشده</span>@endif
                        <x-suggest.field name="script_names" class="ms-1" />
                        @if($manuscript->script_style)
                            <span class="text-muted ms-1">({{ $manuscript->script_style }})</span>
                            <x-suggest.field name="script_style" title="پیشنهاد اصلاح کیفیت خط" aria-label="پیشنهاد اصلاح کیفیت خط" />
                        @else
                            <span x-show="$store.suggest.on" x-cloak data-script-style-empty><span class="text-muted ms-1">کیفیت خط: ثبت نشده</span> <x-suggest.field name="script_style" /></span>
                        @endif
                        {{-- «نوع خط» is the catalogue's wording; the script list links each script it stands for to the search filter --}}
                        @php $scriptLinks = $manuscript->scripts->sortByDesc('pivot.is_primary'); @endphp
                        @if($scriptLinks->isNotEmpty() && $scriptLinks->pluck('name')->implode('، ') !== $manuscript->script_names)
                            <span class="block text-xs text-muted mt-1">در فهرست خطوط:
                                @foreach($scriptLinks as $sc)<a href="{{ route('search', ['type' => 'manuscripts', 'script_id' => $sc->id]) }}" class="border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $sc->name }}</a>@if(! $loop->last) · @endif @endforeach
                            </span>
                        @endif
                    </x-ui.meta>
                    {{-- Leaves or pages, and the leaves the copy takes in its volume (backlog item 97) --}}
                    @php $pagesOnly = $manuscript->pages && ! $manuscript->folios; @endphp
                    <x-ui.meta :label="$pagesOnly ? 'شمار صفحه' : 'شمار برگ'" :field="$pagesOnly ? 'pages' : 'folios'">
                        @if($manuscript->extentLabel()){{ $manuscript->extentLabel() }}@else<span class="text-muted">ثبت نشده</span>@endif
                    </x-ui.meta>
                    {{-- The other count and the place in the volume are fields of their own (step 14.244), reached in
                         suggestion mode: a catalogue's «12ص» that counts leaves is emptied here (step 14.317) --}}
                    <div class="contents suggest-row" x-show="$store.suggest.on" x-cloak data-extent-fields>
                        <dt class="text-muted text-[13px] sm:pt-px">{{ $pagesOnly ? 'شمار برگ' : 'شمار صفحه' }}</dt>
                        <dd class="m-0 mb-2 sm:mb-0 text-ink min-w-0 leading-relaxed">
                            @php $otherCount = $pagesOnly ? $manuscript->folios : $manuscript->pages; @endphp
                            @if($otherCount){{ Fa::digits($otherCount) }}@else<span class="text-muted">ثبت نشده</span>@endif
                            <x-suggest.field :name="$pagesOnly ? 'folios' : 'pages'" class="ms-1" />
                        </dd>
                        <dt class="text-muted text-[13px] sm:pt-px">جای نسخه در مجموعه</dt>
                        <dd class="m-0 mb-2 sm:mb-0 text-ink min-w-0 leading-relaxed">
                            @if($manuscript->rangeLabel()){{ $manuscript->rangeLabel() }}@else<span class="text-muted">ثبت نشده</span>@endif
                            <x-suggest.field name="range_from" class="ms-1" />
                        </dd>
                    </div>
                    <x-ui.meta label="شمار سطر" field="lines" :empty="! $manuscript->lines_raw && ! $manuscript->lines && ! $manuscript->inheritsFromCollection('lines')">
                        @if($manuscript->inheritsFromCollection('lines')){{ Fa::digits($volume->lines_raw ?: $volume->lines . ' سطر') }} <x-manuscript-inherited :manuscript="$manuscript" field="lines" /><x-suggest.field name="collection_lines" title="پیشنهاد اصلاح شمار سطر همۀ مجموعه" aria-label="پیشنهاد اصلاح شمار سطر همۀ مجموعه" />@elseif($manuscript->lines_raw){{-- the catalogue's wording keeps every layout: «18 و 21 سطر» (step 13.156) --}}{{ Fa::digits($manuscript->lines_raw) }}@elseif($manuscript->lines){{ Fa::digits($manuscript->lines) }} سطر@else<span class="text-muted">—</span>@endif
                    </x-ui.meta>
                    {{-- The size, the paper and the binding of a treatise in a «مجموعه» are the volume's: they stand in its
                         section below (x-manuscript-collection; backlog item 163, step 14.324) --}}
                    @unless($volume)
                    <x-ui.meta label="اندازه (سانتی‌متر)" field="dimensions" :empty="! $manuscript->dimensions">
                        @if($manuscript->dimensions){{ Fa::digits($manuscript->dimensions) }}@else<span class="text-muted">—</span>@endif
                    </x-ui.meta>
                    <x-ui.meta label="کاغذ" field="paper" :empty="! $manuscript->paper">
                        @if($manuscript->paper){{ $manuscript->paper }}@else<span class="text-muted">—</span>@endif
                    </x-ui.meta>
                    <x-ui.meta label="جلد" field="binding" :empty="! $manuscript->binding">
                        @if($manuscript->binding){{ $manuscript->binding }}@else<span class="text-muted">—</span>@endif
                    </x-ui.meta>
                    @endunless
                </x-ui.meta-list>
            </x-ui.section>

            <!-- The «مجموعه» the copy is bound in: what is the volume's, and its treatises (from the shelfmark) -->
            <x-manuscript-collection :manuscript="$manuscript" />

            {{-- Only the marks the record has are shown: an unset mark means the catalogue is silent, and a list of
                 them read as the copy's features. The unset ones (and the whole section, when none is set) appear in
                 suggestion mode, where one can be proposed (step 14.150) --}}
            @php $hasFlags = collect($flags)->keys()->contains(fn ($flag) => (bool) $manuscript->{$flag}); @endphp
            <x-ui.section title="نشان‌های نسخه‌شناسی" data-flags-section
                :x-show="$hasFlags ? null : '$store.suggest.on'" :x-cloak="! $hasFlags">
                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-1.5 text-sm">
                    @foreach($flags as $flag => $flagLabel)
                        @if($manuscript->{$flag})
                            <li class="flex items-center gap-2.5 text-ink font-semibold" data-flag="{{ $flag }}">
                                <span class="w-2 h-2 shrink-0 rotate-45 bg-cinnabar" aria-hidden="true"></span>
                                <span>{{ $flagLabel }}</span>
                                <x-suggest.field :name="$flag" />
                            </li>
                        @else
                            <li class="flex items-center gap-2.5 text-muted" data-flag-unset="{{ $flag }}" x-show="$store.suggest.on" x-cloak>
                                <span class="w-2 h-2 shrink-0 rotate-45 border border-line-strong" aria-hidden="true"></span>
                                <span>{{ $flagLabel }}</span>
                                <span class="text-[11px]">(ثبت نشده)</span>
                                <x-suggest.field :name="$flag" />
                            </li>
                        @endif
                    @endforeach
                </ul>
            </x-ui.section>

            <!-- People connected with the copy other than its scribes -->
            <x-manuscript-people :manuscript="$manuscript" />

            <!-- Relations with other copies (copied from, collated with…) -->
            <x-record-relations :record="$manuscript" />

            @foreach(['incipit_text' => ['آغاز', $manuscript->incipits_list], 'explicit_text' => ['انجام', $manuscript->explicits_list]] as $field => [$kind, $list])
                @if(count($list) > 0)
                    <x-ui.section :title="$kind . ' نسخه'" :field="$field" :count="count($list) > 1 ? count($list) : null">
                        <div class="space-y-5">
                            @foreach($list as $idx => $item)
                                @php
                                    $itemLabel = $item['label'] ?? null;
                                    if (! $itemLabel && count($list) > 1) {
                                        $itemLabel = $kind . ' ' . Fa::digits($idx + 1);
                                    }
                                @endphp
                                @if(! empty($item['is_placeholder']))
                                    <div>
                                        @if($itemLabel)<div class="text-xs font-bold text-cinnabar mb-1">{{ $itemLabel }}</div>@endif
                                        <p class="text-sm text-muted leading-relaxed whitespace-pre-line">{{ $item['text'] }}</p>
                                    </div>
                                @else
                                    <x-ui.quote :label="$itemLabel ?? $kind">{{ trim($item['text'], "«» \t\n\r\0\x0B") }}</x-ui.quote>
                                    {{-- A pointer at one of the work's numbered or part incipits leads to it (backlog item 98) --}}
                                    @if(! empty($item['work_excerpt_id']) && $manuscript->work)
                                        <a href="{{ route('works.show', $manuscript->work) }}#excerpt-{{ $item['work_excerpt_id'] }}" class="inline-block mt-1 text-[11px] text-cinnabar hover:underline">در صفحۀ اثر ←</a>
                                    @endif
                                @endif
                            @endforeach
                        </div>
                    </x-ui.section>
                @endif
            @endforeach

            <!-- Sections hidden while empty: reachable in suggestion mode -->
            @php
                // The copy's typed notes take the place of the three sections below (backlog item 87, step 14.260). A copy
                // whose seals list a reviewer corrected keeps that list, and so does one without a note of provenance.
                $noteGroups = $manuscript->noteGroups();
                $oldSeals = $manuscript->keepsSealsList();
                // ...and the two other old lists stay while no note of the import stands in their place: a copy without
                // a note, or with notes a reviewer added (step 14.318)
                $oldNotes = ($manuscript->residual_notes_text || $manuscript->editorial_notes_list) && $manuscript->keepsOldNotes();
                $emptySections = array_filter([
                    'incipit_text' => count($manuscript->incipits_list) === 0 ? 'آغاز نسخه' : null,
                    'explicit_text' => count($manuscript->explicits_list) === 0 ? 'انجام نسخه' : null,
                ]);
                // A note can be added to a section the page does not have yet (steps 14.264, 14.269), on a copy without
                // a note too: one button, and the form asks the note's type (step 14.318). A note of provenance is not
                // added beside the old list of them (SuggestionRegistry::recordFields).
                $addsProvenance = ! $oldSeals || ! $manuscript->ownership_and_seals_list;
                $addsNote = false;
                foreach (\App\Models\ManuscriptNote::GROUPS as $groupKey => $group) {
                    $lacks = ! isset($noteGroups[$groupKey]) || ($groupKey === 'provenance' && $oldSeals);
                    $addsNote = $addsNote || ($lacks && ($groupKey !== 'provenance' || $addsProvenance));
                }
            @endphp
            @if($emptySections || $addsNote)
                <div x-show="$store.suggest.on" x-cloak class="p-4 border border-dashed border-cinnabar/40 bg-cinnabar/5 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                    <span class="text-muted font-semibold">بخش‌های خالیِ این نسخه:</span>
                    @foreach($emptySections as $name => $label)
                        <span class="inline-flex items-center gap-1.5 font-bold text-ink">{{ $label }} <x-suggest.field :name="$name" /></span>
                    @endforeach
                    @if($addsNote)
                        <span class="inline-flex items-center gap-1.5 font-bold text-ink">افزودن یادداشت
                            <button type="button" data-suggest-new-note @click="$dispatch('suggest-field', { newNote: true })"
                                title="پیشنهاد افزودن یادداشت؛ نوع آن در فرم پرسیده می‌شود" aria-label="پیشنهاد افزودن یادداشت"
                                class="suggest-btn is-on inline-flex items-center justify-center shrink-0 w-6 h-6 rounded-lg border border-gold/50 bg-amber-50 dark:bg-amber-950/40 text-cinnabar hover:bg-cinnabar hover:text-white transition cursor-pointer align-middle">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 5v14m-7-7h14"/></svg>
                            </button>
                            <span class="font-normal text-muted">({{ $addsProvenance ? 'مهر، تملک، حواشی' : 'حواشی، مقابله' }}، یادداشت فهرست‌نگار و…)</span>
                        </span>
                    @endif
                </div>
            @endif

            @foreach($noteGroups as $groupKey => $groupNotes)
                @continue($groupKey === 'provenance' && $oldSeals)
                <x-ui.section :title="\App\Models\ManuscriptNote::GROUPS[$groupKey]['title']" :count="$groupNotes->count()">
                    <ul class="space-y-2 text-sm">
                        @foreach($groupNotes as $note)
                            <li class="flex items-start gap-3 leading-relaxed">
                                <span class="mt-0.5 shrink-0 w-20 text-center text-[11px] font-bold py-0.5 border {{ $note->type === 'seal' ? 'border-cinnabar/40 text-cinnabar' : ($note->type === 'endowment' ? 'border-emerald-600/40 text-emerald-700 dark:text-emerald-300' : 'border-line-strong text-muted') }}">{{ \App\Models\ManuscriptNote::TYPES[$note->type] ?? $note->type }}</span>
                                <span class="text-ink whitespace-pre-line">{{ Fa::digits($note->text) }}@if($note->by_author) <x-ui.chip tone="good" class="!text-[11px] !py-0 ms-1">{{ $note->by_author_hedge ? $note->by_author_hedge . ' ' : '' }}از مؤلف</x-ui.chip>@endif <x-suggest.field :name="'note_' . $note->id" /> <x-suggest.field :name="'note_type_' . $note->id" x-show="$store.suggest.on" x-cloak title="پیشنهاد تغییر نوع این یادداشت" aria-label="پیشنهاد تغییر نوع این یادداشت" class="!border-line-strong !bg-surface !text-muted" /></span>
                            </li>
                        @endforeach
                    </ul>
                    {{-- a note of the section's types the catalogue lacks (steps 14.264, 14.269) --}}
                    <div x-show="$store.suggest.on" x-cloak class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                        @foreach(\App\Models\ManuscriptNote::GROUPS[$groupKey]['types'] as $newType)
                            @continue(! \App\Suggestions\SuggestionRegistry::newNoteLabel($newType))
                            <span class="inline-flex items-center gap-1.5 font-bold text-ink">{{ \App\Suggestions\SuggestionRegistry::newNoteLabel($newType) }} <x-suggest.field :name="'note_new_' . $newType" /></span>
                        @endforeach
                    </div>
                </x-ui.section>
            @endforeach

            @if($oldSeals && count($manuscript->ownership_and_seals_list) > 0)
                <x-ui.section title="مهرها، تملک‌ها و وقف‌نامه‌ها" field="ownership_and_seals" :count="count($manuscript->ownership_and_seals_list)">
                    <ul class="space-y-2 text-sm">
                        @foreach($manuscript->ownership_and_seals_list as $seal)
                            @php
                                $sealKind = str_contains($seal, 'مهر') ? 'مهر' : ((str_contains($seal, 'وقف') || str_contains($seal, 'واقف')) ? 'وقف' : 'تملک');
                            @endphp
                            <li class="flex items-start gap-3 leading-relaxed">
                                <span class="mt-0.5 shrink-0 w-11 text-center text-[11px] font-bold py-0.5 border {{ $sealKind === 'مهر' ? 'border-cinnabar/40 text-cinnabar' : ($sealKind === 'وقف' ? 'border-emerald-600/40 text-emerald-700 dark:text-emerald-300' : 'border-line-strong text-muted') }}">{{ $sealKind }}</span>
                                <span class="text-ink">{{ Fa::digits($seal) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.section>
            @endif

            @if($oldNotes && $manuscript->residual_notes_text)
                <x-ui.section title="فوائد و رسائل مندرج در نسخه">
                    <div class="text-sm text-ink leading-loose whitespace-pre-line max-w-[72ch]">{{ Fa::digits($manuscript->residual_notes_text) }}</div>
                </x-ui.section>
            @endif

            @if($oldNotes && count($manuscript->editorial_notes_list) > 0)
                <x-ui.section title="یادداشت‌های فهرست‌نگار">
                    <ul class="space-y-1.5 text-sm">
                        @foreach($manuscript->editorial_notes_list as $editNote)
                            <li class="flex gap-2.5 leading-relaxed">
                                <span class="mt-2.5 w-1.5 h-1.5 shrink-0 rotate-45 bg-gold" aria-hidden="true"></span>
                                <span class="text-ink">{{ Fa::digits($editNote) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.section>
            @endif

            <!-- The text printed at each of those places, under one heading and in the same order (backlog item 60, steps 12.68 and 13.4) -->
            @php
                $texts = $sources->texts();
                [$secondHandTexts, $firstHandTexts] = $texts->partition(fn ($e) => $e->isSecondHand());
            @endphp
            @if($texts->isNotEmpty())
                {{-- Fankha's text alone, left out (step 14.376): the heading goes with it, but in suggestion mode --}}
                <div @if($seesFirstHand && $firstHandTexts->isEmpty()) data-suggest-only style="display: none" x-data x-show="$store.suggest?.on" @endif>
                <x-ui.section title="متن مأخذ">
                    <div class="space-y-6">
                        @foreach($firstHandTexts as $entry)
                            <x-source.text :entry="$entry" :record="$manuscript" />
                        @endforeach
                        @if($secondHandTexts->isNotEmpty())
                            <x-source.secondary :suggest-only="$seesFirstHand">
                                @foreach($secondHandTexts as $entry)
                                    <x-source.text :entry="$entry" :record="$manuscript" />
                                @endforeach
                            </x-source.secondary>
                        @endif
                    </div>
                </x-ui.section>
                </div>
            @endif

            <!-- MENTIONS: where the sources' texts cite this copy by its shelfmark, the reverse of their links to it (step 13.155) -->
            <x-source.mentions :mentions="\App\Support\SourceMentions::of($manuscript)" title="یادکرد در منابع"
                note="این یادکردها را ماشین از روی کتابخانه، شمارۀ نسخه و عنوان اثر یافته است، و ممکن است خطا داشته باشد." />

            <!-- READINGS: adopted, alternative and superseded readings from researchers -->
            <x-suggest.readings />

            <!-- RESEARCH NOTES: published notes and the invitation to add one -->
            <x-suggest.notes type="manuscript" />

            <!-- CONTRIBUTE: the empty sections above, as invitations in one box (step 14.132) -->
            <x-suggest.contribute type="manuscript" />

            <!-- CORRECTION HISTORY: approved suggestions that changed this record (backlog item 39) -->
            <x-record-history :record="$manuscript" />
        </div>
    </div>

    <!-- SUGGESTION DESK: per-field and general correction suggestions -->
    <x-suggest.desk type="manuscript" :record="$manuscript" />

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
                    <h3 class="font-bold text-base text-ink">رمزینه هوشمند (QR Code) نسخه</h3>
                </div>
                <button @click="qrModalOpen = false" class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-lg leading-none cursor-pointer">✕</button>
            </div>

            <!-- QR Canvas Container -->
            <div class="flex flex-col items-center justify-center p-4 rounded-2xl bg-white border border-line shadow-inner">
                <canvas id="manuscript-qr-canvas" class="max-w-full h-auto"></canvas>
            </div>

            <!-- Details -->
            <div class="space-y-1.5 text-right bg-paper p-3 rounded-2xl border border-stone-200/70 dark:border-stone-700/70 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-stone-400">شماره نسخه:</span>
                    <span class="font-bold text-cinnabar">{{ $manuscript->shelfmark ?? 'بی‌شماره' }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-stone-400">کتابخانه:</span>
                    <span class="font-bold text-ink truncate max-w-[200px]" title="{{ $libraryName }}">{{ $manuscript->libraryRecord?->short_name ?? $manuscript->library }}</span>
                </div>
                <div class="flex justify-between items-center pt-1.5 border-t border-stone-200/60 dark:border-stone-700/60">
                    <span class="text-stone-400">پیوند پایدار:</span>
                    <span class="font-mono text-[11px] font-bold text-ink" dir="ltr">{{ url('/m/' . $manuscript->id) }}</span>
                </div>
            </div>

            <p class="text-[11px] text-muted leading-relaxed text-justify">
                این بارکد را با دوربین تلفن همراه خود اسکن کنید تا در مراجعات حضوری به مخازن، اطلاعات و شناسنامه این نسخه خطی مستقیماً روی گوشی همراه شما نمایش یابد.
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
                    @click="copyText('{{ url('/m/' . $manuscript->id) }}', 'qr-modal');"
                    class="px-4 py-2 rounded-xl border border-line-strong hover:border-gold text-ink text-xs font-semibold transition cursor-pointer">
                    <span x-show="copiedKey !== 'qr-modal'">کپی آدرس</span>
                    <span x-show="copiedKey === 'qr-modal'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
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
 "@type": "Manuscript",
 "name": "نسخه خطی {{ addcslashes($manuscript->work?->primary_title ?? 'بدون عنوان', '"\\') }}",
 "identifier": "{{ addcslashes($manuscript->shelfmark ?? '', '"\\') }}",
 "holdingArchive": {
 "@type": "Library",
 "name": "{{ addcslashes($manuscript->libraryRecord?->display_name ?? $manuscript->library ?? '', '"\\') }}"
  },
  @if($manuscript->scribe_name)"creator": { "@type": "Person", "name": "{{ addcslashes($manuscript->scribe_name, '"\\') }}" },@endif
 "url": "{{ route('manuscripts.show', $manuscript) }}"
}
</script>
@endpush

