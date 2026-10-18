@extends('layouts.app')

@section('title', 'نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' | ' . ($manuscript->libraryRecord?->name ?? $manuscript->library) . ' (' . ($manuscript->shelfmark ?? 'بی‌شماره') . ')')
@section('meta_description', 'شناسنامه کالبدشناسی نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' در ' . ($manuscript->libraryRecord?->name ?? $manuscript->library) . ' با شماره بازیابی ' . ($manuscript->shelfmark ?? ''))
@section('og_type', 'article')

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
        $catShort = $manuscript->catalog?->short_name ?? 'فنخا';
        $catCode = $manuscript->catalog?->code ?? 'fankha';
        $libraryName = $manuscript->libraryRecord?->name ?? $manuscript->library ?? 'کتابخانه‌ی نامشخص';
        $hasPage = $manuscript->volume_number && $manuscript->page_start;
        $pageRef = $hasPage ? Fa::digits("ج {$manuscript->volume_number}، ص {$manuscript->page_start}، ردیف {$manuscript->sequence_number}") : null;
        $pageThumb = $hasPage
            ? asset('storage/catalogs/' . $catCode . '/v' . str_pad($manuscript->volume_number, 2, '0', STR_PAD_LEFT) . '/p' . str_pad($manuscript->page_start, 4, '0', STR_PAD_LEFT) . '.webp')
            : null;
        $openViewer = $hasPage
            ? "\$dispatch('open-catalog-viewer', { catalog: '{$catCode}', catalogName: '{$catShort}', volume: {$manuscript->volume_number}, page: {$manuscript->page_start} })"
            : null;
        $formerLocations = (array) ($manuscript->metadata['former_locations'] ?? []);
        $imageNote = ($researchNotes ?? collect())->firstWhere('field_name', 'image');
        // The parsed year adds nothing when the catalog text already shows it in digits
        $showParsedYear = $manuscript->copy_date_raw && $manuscript->copy_date_hijri_year
            && ! str_contains(strtr($manuscript->copy_date_raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), (string) $manuscript->copy_date_hijri_year);
        $flags = [
            'is_autograph' => 'اصل نسخه (دستخط مؤلف)',
            'has_author_marginalia' => 'حواشی به خط مؤلف',
            'is_illuminated' => 'تذهیب و سرلوح',
            'is_illustrated' => 'نگاره و نقاشی',
            'is_corrected' => 'تصحیح‌شده',
            'has_marginal_notes' => 'حواشی (محشّی)',
            'is_ruled' => 'جدول‌کشی',
            'has_catchwords' => 'رکابه',
            'is_collated' => 'مقابله و بلاغ',
            'is_facsimile' => 'نسخه‌ی عکسی یا میکروفیلم',
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
        <span class="text-ink font-semibold">@if($manuscript->shelfmark)<span dir="ltr">{{ Fa::digits($manuscript->shelfmark) }}</span>@else شناسنامه‌ی نسخه @endif</span>
    </nav>

    <!-- MANUSCRIPT HEADER -->
    <header class="text-center grid justify-items-center gap-2 pb-6">
        <p class="text-xs font-bold text-cinnabar">شناسنامه‌ی نسخه‌ی خطی</p>
        @if($manuscript->work)
            <h1 class="font-naskh text-[26px] sm:text-[36px] font-bold leading-[1.6] text-ink text-balance">
                <a href="{{ route('works.show', $manuscript->work) }}" class="hover:text-cinnabar transition">{{ $manuscript->work->primary_title }}</a>
            </h1>
            @if($manuscript->work->author)
                <p class="text-sm text-ink">
                    از <a href="{{ route('people.show', $manuscript->work->author) }}" class="font-bold border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $manuscript->work->author->name }}</a>
                    <x-ui.life :person="$manuscript->work->author" />
                </p>
            @endif
        @endif
        <p class="mt-1 text-base sm:text-lg text-ink">
            <span class="font-bold">{{ $libraryName }}</span>@if($manuscript->city)<span class="text-muted">، {{ $manuscript->city }}</span>@endif
            <span class="text-muted">·</span>
            <span class="font-bold text-cinnabar">شماره‌ی @if($manuscript->shelfmark)<span dir="ltr">{{ Fa::digits($manuscript->shelfmark) }}</span>@else — @endif</span>
        </p>
        <div class="flex flex-wrap justify-center gap-1.5 mt-2">
            @if($manuscript->copy_date_raw)<x-ui.chip>کتابت {{ Fa::digits($manuscript->copy_date_raw) }}</x-ui.chip>@endif
            @if($manuscript->script_names)<x-ui.chip>{{ $manuscript->script_names }}</x-ui.chip>@endif
            @if($manuscript->is_autograph)<x-ui.chip tone="good">دستخط مؤلف</x-ui.chip>@endif
            @if($manuscript->is_illuminated)<x-ui.chip tone="accent">مذهّب</x-ui.chip>@endif
            @if($manuscript->is_illustrated)<x-ui.chip tone="accent">مصوّر</x-ui.chip>@endif
            @if($manuscript->is_distinct_work)<x-ui.chip title="این نسخه ذیل مدخلی «غیر همانند» آمده است: متنی جدا با همان عنوان">اثر متمایز</x-ui.chip>@endif
            @if($manuscript->is_addendum_entry)<x-ui.chip tone="accent" title="این نسخه در بخش استدراک مأخذ معرفی شده است">استدراک</x-ui.chip>@endif
        </div>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <!-- Quick actions: permalink, QR, citation, digital image, suggestion mode -->
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
                <button type="button" @click="openQrModal()" class="p-1.5 rounded-sm text-muted hover:text-cinnabar transition cursor-pointer" title="رمزینه‌ی QR نسخه">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" /></svg>
                </button>
            </div>
            <x-ui.btn size="sm" @click="citationModalOpen = true">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" /></svg>
                استناد
            </x-ui.btn>
            {{-- A published digital image of this copy (research note), see <x-suggest.notes> --}}
            @if($imageNote)
                <x-ui.btn size="sm" href="#research-notes">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    تصویر نسخه
                </x-ui.btn>
            @endif
            <x-suggest.toggle />
        </div>
        <span class="text-xs text-muted">شناسه‌ی فهارس: <span class="font-mono text-ink font-bold">#{{ $manuscript->id }}</span></span>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px] items-start">

        <!-- Summary column: first on phones, beside the details on wide screens -->
        <aside class="space-y-4 lg:order-last lg:sticky lg:top-24">
            <x-ui.card :framed="true" class="space-y-3 text-sm">
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
                    <div class="text-xs text-muted">شماره‌ی نسخه</div>
                    <span class="font-naskh text-2xl font-bold text-cinnabar">@if($manuscript->shelfmark)<span dir="ltr">{{ Fa::digits($manuscript->shelfmark) }}</span>@else بی‌شماره @endif</span>
                    <x-suggest.field name="shelfmark" class="ms-1" />
                </div>
                @if($formerLocations)
                    <!-- Where the copy was before it moved or was renumbered (older catalogs cite these) -->
                    <div class="pt-3 border-t border-line text-xs text-muted space-y-0.5">
                        @foreach($formerLocations as $former)
                            <div>نشانی پیشین: <span class="text-ink font-semibold">{{ implode('، ', array_filter([$former['library'] ?? null, $former['city'] ?? null])) ?: '—' }}، شماره‌ی @if(isset($former['shelfmark']))<span dir="ltr">{{ Fa::digits($former['shelfmark']) }}</span>@else بی‌شماره @endif</span></div>
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

            <x-ui.card class="space-y-3">
                <div>
                    <div class="text-xs text-muted">مأخذ</div>
                    <div class="text-sm font-bold text-ink">{{ $catShort }}@if($pageRef)، {{ $pageRef }}@endif</div>
                    @if($manuscript->catalogVolume)
                        <div class="text-xs text-muted mt-0.5">{{ $manuscript->catalogVolume->title }}</div>
                    @endif
                </div>
                @if($pageThumb)
                    <button type="button" @click="{{ $openViewer }}" class="block w-full group cursor-pointer" title="دیدن برگه در مأخذ چاپی">
                        <div class="aspect-[16/9] lg:aspect-[4/3] overflow-hidden border border-line bg-white">
                            <img src="{{ $pageThumb }}" alt="برگه‌ی {{ $pageRef }} در {{ $catShort }}" loading="lazy" class="w-full h-full object-cover object-top group-hover:opacity-90 transition" onerror="this.closest('button').remove()">
                        </div>
                        <span class="block mt-1.5 text-xs font-semibold text-cinnabar">دیدن برگه در مأخذ چاپی</span>
                    </button>
                @endif
                @if($manuscript->cataloger)
                    <a href="{{ route('catalogers.show', $manuscript->cataloger) }}" class="flex items-center gap-3 pt-3 border-t border-line group">
                        <span class="w-11 h-14 shrink-0 rounded-t-full overflow-hidden border border-gold bg-paper grid place-items-center">
                            @if($manuscript->cataloger->avatar_url)
                                <img src="{{ $manuscript->cataloger->avatar_url }}" alt="" class="w-full h-full object-cover sepia-[.85]">
                            @else
                                <span class="font-naskh text-lg text-muted">{{ mb_substr($manuscript->cataloger->name, 0, 1) }}</span>
                            @endif
                        </span>
                        <span class="min-w-0">
                            <span class="block text-xs text-muted">فهرست‌نگار</span>
                            <span class="block text-sm font-bold text-ink group-hover:text-cinnabar transition">{{ $manuscript->cataloger->display_name }}</span>
                            @if($manuscript->cataloger->life_years_text)
                                <span class="block text-[11px] text-muted">{{ Fa::digits($manuscript->cataloger->life_years_text) }}</span>
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
                            @php $autographPerson = $manuscript->work?->author ?? $manuscript->scribe; @endphp
                            @if($autographPerson)
                                <a href="{{ route('people.show', $autographPerson) }}" class="font-semibold text-emerald-700 dark:text-emerald-400 hover:underline">دستخط مؤلف ({{ $autographPerson->name }})</a>
                            @else
                                <span class="font-semibold text-emerald-700 dark:text-emerald-400">مؤلف (نسخه‌ی اصل)</span>
                            @endif
                        @elseif($manuscript->scribe_id)
                            <a href="{{ route('people.show', $manuscript->scribe_id) }}" class="font-semibold border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $manuscript->scribe_name ?: 'دیدن کاتب' }}</a>
                        @elseif($manuscript->scribe_name)
                            {{ $manuscript->scribe_name }}
                        @elseif($manuscript->is_bika)
                            <span class="text-muted">بی‌کاتب</span>
                        @else
                            <span class="text-muted">نامشخص</span>
                        @endif
                    </x-ui.meta>
                    <x-ui.meta label="تاریخ کتابت" field="copy_date_raw">
                        @if($manuscript->copy_date_raw)
                            {{ Fa::digits($manuscript->copy_date_raw) }}
                            @if($showParsedYear)<span class="text-muted">({{ Fa::digits($manuscript->copy_date_hijri_year) }} ق)</span>@endif
                        @elseif($manuscript->is_bita)
                            <span class="text-muted">بی‌تاریخ</span>
                        @else
                            <span class="text-muted">نامشخص</span>
                        @endif
                    </x-ui.meta>
                    <x-ui.meta label="محل کتابت" field="copy_place">
                        @if($manuscript->copy_place){{ $manuscript->copy_place }}@else<span class="text-muted">نامشخص</span>@endif
                    </x-ui.meta>
                    <x-ui.meta label="سفارش‌دهنده" field="commissioned_by" :empty="! $manuscript->commissioned_by">{{ $manuscript->commissioned_by }}</x-ui.meta>
                </x-ui.meta-list>
            </x-ui.section>

            <x-ui.section title="ویژگی‌های ظاهری">
                <x-ui.meta-list>
                    <x-ui.meta label="خط" field="script_names">
                        @if($manuscript->script_names){{ $manuscript->script_names }}@else<span class="text-muted">نامشخص</span>@endif
                        @if($manuscript->script_style)<span class="text-muted">({{ $manuscript->script_style }})</span>@endif
                        <span x-show="$store.suggest.on" x-cloak class="text-muted ms-1">کیفیت خط:</span>
                        <x-suggest.field name="script_style" />
                    </x-ui.meta>
                    <x-ui.meta label="شمار برگ" field="folios">
                        @if($manuscript->folios){{ Fa::digits($manuscript->folios) }} برگ@else<span class="text-muted">—</span>@endif
                    </x-ui.meta>
                    <x-ui.meta label="شمار سطر" field="lines">
                        @if($manuscript->lines){{ Fa::digits($manuscript->lines) }} سطر@else<span class="text-muted">—</span>@endif
                    </x-ui.meta>
                    <x-ui.meta label="اندازه (سانتی‌متر)" field="dimensions">
                        @if($manuscript->dimensions){{ Fa::digits($manuscript->dimensions) }}@else<span class="text-muted">—</span>@endif
                    </x-ui.meta>
                    <x-ui.meta label="کاغذ" field="paper">
                        @if($manuscript->paper){{ $manuscript->paper }}@else<span class="text-muted">—</span>@endif
                    </x-ui.meta>
                    <x-ui.meta label="جلد" field="binding">
                        @if($manuscript->binding){{ $manuscript->binding }}@else<span class="text-muted">—</span>@endif
                    </x-ui.meta>
                </x-ui.meta-list>
            </x-ui.section>

            <x-ui.section title="نشان‌های نسخه‌شناسی">
                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-1.5 text-sm">
                    @foreach($flags as $flag => $flagLabel)
                        <li class="flex items-center gap-2.5 {{ $manuscript->{$flag} ? 'text-ink font-semibold' : 'text-muted' }}">
                            <span class="w-2 h-2 shrink-0 rotate-45 {{ $manuscript->{$flag} ? 'bg-cinnabar' : 'border border-line-strong' }}" aria-hidden="true"></span>
                            <span>{{ $flagLabel }}</span>
                            <span class="sr-only">{{ $manuscript->{$flag} ? 'دارد' : 'ندارد' }}</span>
                            <x-suggest.field :name="$flag" />
                        </li>
                    @endforeach
                </ul>
            </x-ui.section>

            <!-- People connected with the copy other than its scribes -->
            <x-manuscript-people :manuscript="$manuscript" />

            <!-- The other treatises bound in the same volume (from the shelfmark) -->
            <x-manuscript-collection :manuscript="$manuscript" />

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
                                @endif
                            @endforeach
                        </div>
                    </x-ui.section>
                @endif
            @endforeach

            <!-- Sections hidden while empty: reachable in suggestion mode -->
            @php
                $emptySections = array_filter([
                    'incipit_text' => count($manuscript->incipits_list) === 0 ? 'آغاز نسخه' : null,
                    'explicit_text' => count($manuscript->explicits_list) === 0 ? 'انجام نسخه' : null,
                    'ownership_and_seals' => count($manuscript->ownership_and_seals_list) === 0 ? 'مهرها، تملک‌ها و وقف‌نامه‌ها' : null,
                ]);
            @endphp
            @if($emptySections)
                <div x-show="$store.suggest.on" x-cloak class="p-4 border border-dashed border-cinnabar/40 bg-cinnabar/5 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                    <span class="text-muted font-semibold">بخش‌های خالیِ این نسخه:</span>
                    @foreach($emptySections as $name => $label)
                        <span class="inline-flex items-center gap-1.5 font-bold text-ink">{{ $label }} <x-suggest.field :name="$name" /></span>
                    @endforeach
                </div>
            @endif

            @if(count($manuscript->ownership_and_seals_list) > 0)
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

            @if($manuscript->residual_notes_text)
                <x-ui.section title="فوائد و رسائل مندرج در نسخه">
                    <div class="text-sm text-ink leading-loose whitespace-pre-line max-w-[72ch]">{{ Fa::digits($manuscript->residual_notes_text) }}</div>
                </x-ui.section>
            @endif

            @if(count($manuscript->editorial_notes_list) > 0)
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

            @if($manuscript->clean_raw_text)
                <x-ui.section title="متن مأخذ" x-data="{ copied: false, open: false }">
                    <x-slot:actions>
                        <span class="text-muted">{{ $manuscript->catalog?->citation_format ?? ($manuscript->catalog?->name ?? 'فهرستگان نسخه‌های خطی ایران (فنخا)') }}</span>
                        @if($openViewer)
                            <x-ui.btn size="sm" @click="{{ $openViewer }}">دیدن برگه</x-ui.btn>
                        @endif
                        <x-ui.btn size="sm" @click="navigator.clipboard.writeText($refs.rawTextContent.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)">
                            <span x-show="!copied">رونوشت متن</span>
                            <span x-show="copied" x-cloak class="text-emerald-600">رونوشت شد</span>
                        </x-ui.btn>
                    </x-slot:actions>
                    <div class="relative">
                        <div x-ref="rawTextContent" :class="open ? '' : 'max-h-64 overflow-hidden'"
                             class="p-4 sm:p-5 bg-surface border border-line text-xs sm:text-[13px] text-ink leading-loose whitespace-pre-line text-right">{{ $manuscript->clean_raw_text }}</div>
                        <div x-show="! open" class="absolute inset-x-px bottom-px h-20 bg-gradient-to-t from-surface to-transparent flex items-end justify-center pb-2">
                            <button type="button" @click="open = true" class="text-xs font-semibold text-cinnabar hover:underline cursor-pointer">نمایش کامل متن</button>
                        </div>
                    </div>
                </x-ui.section>
            @endif

            <!-- OTHER CATALOG CITATIONS (addenda, errata) -->
            <x-source-citations :citations="$manuscript->sourceCitations" />

            <!-- READINGS: adopted, alternative and superseded readings from researchers -->
            <x-suggest.readings />

            <!-- RESEARCH NOTES: published notes and the invitation to add one -->
            <x-suggest.notes type="manuscript" />
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
            class="bg-white dark:bg-[#15192C] w-full max-w-sm rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-2xl space-y-5 text-center relative overflow-hidden">
            
            <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
                    <h3 class="font-bold text-base text-stone-900 dark:text-stone-100">رمزینه هوشمند (QR Code) نسخه</h3>
                </div>
                <button @click="qrModalOpen = false" class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-lg leading-none cursor-pointer">✕</button>
            </div>

            <!-- QR Canvas Container -->
            <div class="flex flex-col items-center justify-center p-4 rounded-2xl bg-white border border-[#EADFCF] shadow-inner">
                <canvas id="manuscript-qr-canvas" class="max-w-full h-auto"></canvas>
            </div>

            <!-- Details -->
            <div class="space-y-1.5 text-right bg-stone-50 dark:bg-stone-800/50 p-3 rounded-2xl border border-stone-200/70 dark:border-stone-700/70 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-stone-400">شماره نسخه:</span>
                    <span class="font-bold text-[#B38A50]">{{ $manuscript->shelfmark ?? 'بی‌شماره' }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-stone-400">کتابخانه:</span>
                    <span class="font-bold text-stone-800 dark:text-stone-200 truncate max-w-[200px]">{{ $manuscript->libraryRecord?->name ?? $manuscript->library }}</span>
                </div>
                <div class="flex justify-between items-center pt-1.5 border-t border-stone-200/60 dark:border-stone-700/60">
                    <span class="text-stone-400">پیوند پایدار:</span>
                    <span class="font-mono text-[11px] font-bold text-[#292C56] dark:text-amber-200" dir="ltr">{{ url('/m/' . $manuscript->id) }}</span>
                </div>
            </div>

            <p class="text-[11px] text-stone-500 dark:text-stone-400 leading-relaxed text-justify">
                این بارکد را با دوربین تلفن همراه خود اسکن کنید تا در مراجعات حضوری به مخازن، اطلاعات و شناسنامه این نسخه خطی مستقیماً روی گوشی همراه شما نمایش یابد.
            </p>

            <!-- Modal Actions -->
            <div class="pt-1 flex items-center justify-center gap-2">
                <button 
                    type="button" 
                    @click="downloadQr()"
                    class="px-4 py-2 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white text-xs font-bold transition flex items-center gap-1.5 shadow-sm cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    <span>ذخیره تصویر QR</span>
                </button>
                <button 
                    type="button" 
                    @click="copyText('{{ url('/m/' . $manuscript->id) }}', 'qr-modal');"
                    class="px-4 py-2 rounded-xl border border-stone-300 dark:border-stone-700 hover:border-[#B38A50] text-stone-700 dark:text-stone-300 text-xs font-semibold transition cursor-pointer">
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
            class="bg-white dark:bg-[#15192C] w-full max-w-2xl rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-2xl space-y-6 text-right max-h-[90vh] overflow-y-auto">
            
            <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
                    <h3 class="font-bold text-lg text-stone-900 dark:text-stone-100">دریافت استناد علمی و ارجاع استاندارد</h3>
                </div>
                <button @click="citationModalOpen = false" class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-lg leading-none cursor-pointer">✕</button>
            </div>

            <p class="text-xs text-stone-500 dark:text-stone-400 leading-relaxed">
                پژوهشگر گرامی؛ جهت استناد به این نسخه خطی در مقالات علمی، پایان‌نامه‌ها و طرح‌های کتاب‌شناختی، می‌توانید از قالب‌های آماده زیر استفاده فرمایید:
            </p>

            @php
                $fankhaCitation = "درایتی، مصطفی. فهرستگان نسخه‌های خطی ایران (فنخا). ج " . ($manuscript->volume_number ?? '-') . "، ص " . ($manuscript->page_start ?? '-') . "، ردیف " . ($manuscript->sequence_number ?? '-') . ".";
                
                $workTitle = $manuscript->work?->primary_title ?? 'نسخه خطی';
                $authorName = $manuscript->work?->author?->name ?? 'ناشناخته';
                $libName = $manuscript->libraryRecord?->name ?? $manuscript->library ?? 'نامشخص';
                $shelfmark = $manuscript->shelfmark ?? 'بی‌شماره';
                $shortUrl = url('/m/' . $manuscript->id);

                $persianArticleCitation = "{$workTitle} (مؤلف: {$authorName})، نسخه خطی {$libName}، شماره بازیابی: {$shelfmark}. پایگاه فهارس: {$shortUrl}";

                $authorEn = $manuscript->work?->author?->name ?? 'Unknown';
                $cityEn = $manuscript->city ?? 'Iran';
                $chicagoCitation = "{$authorEn}. \"{$workTitle}.\" Manuscript {$shelfmark}, {$libName}, {$cityEn}. Fahares: {$shortUrl}";
            @endphp

            <div class="space-y-4">
                
                <!-- Format 1: FanKha Source -->
                <div class="p-4 rounded-2xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-amber-100 dark:bg-amber-950/60 text-[#B38A50] text-[11px] font-bold">
                                قالب ۱
                            </span>
                            <span class="text-xs font-bold text-stone-800 dark:text-stone-200">قالب مأخذ فنخا (فهرستگان نسخه‌های خطی ایران)</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($fankhaCitation), 'fankha')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-[#EADFCF] dark:border-[#272F4C] text-[11px] font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'fankha'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'fankha'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed font-normal select-all">
                        {{ $fankhaCitation }}
                    </div>
                </div>

                <!-- Format 2: Persian Articles / Codicological Reference -->
                <div class="p-4 rounded-2xl bg-stone-50 dark:bg-[#1A1E35] border border-stone-200 dark:border-[#272F4C] space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-stone-200 dark:bg-stone-800 text-stone-700 dark:text-stone-300 text-[11px] font-bold">
                                قالب ۲
                            </span>
                            <span class="text-xs font-bold text-stone-800 dark:text-stone-200">قالب ارجاع نسخه‌شناسی در مقالات و رسائل</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($persianArticleCitation), 'article')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-700 text-[11px] font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'article'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'article'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed font-normal select-all">
                        {{ $persianArticleCitation }}
                    </div>
                </div>

                <!-- Format 3: Chicago Style -->
                <div class="p-4 rounded-2xl bg-indigo-50/40 dark:bg-[#1A1E35] border border-indigo-100 dark:border-[#272F4C] space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-indigo-100 dark:bg-indigo-950/60 text-[#292C56] dark:text-indigo-300 text-[11px] font-bold">
                                قالب ۳
                            </span>
                            <span class="text-xs font-bold text-stone-800 dark:text-stone-200">قالب استاندارد شیکاگو (Chicago Style)</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($chicagoCitation), 'chicago')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-700 text-[11px] font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'chicago'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'chicago'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed font-mono select-all text-left" dir="ltr">
                        {{ $chicagoCitation }}
                    </div>
                </div>

            </div>

            <div class="pt-2 flex justify-end">
                <button type="button" @click="citationModalOpen = false" class="px-5 py-2 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-stone-200 dark:hover:bg-stone-700 text-stone-700 dark:text-stone-300 text-xs font-bold transition cursor-pointer">
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
  "@type": "Manuscript",
  "name": "نسخه خطی {{ addcslashes($manuscript->work?->primary_title ?? 'بدون عنوان', '"\\') }}",
  "identifier": "{{ addcslashes($manuscript->shelfmark ?? '', '"\\') }}",
  "holdingArchive": {
    "@type": "Library",
    "name": "{{ addcslashes($manuscript->libraryRecord?->name ?? $manuscript->library ?? '', '"\\') }}"
  },
  @if($manuscript->scribe_name)"creator": { "@type": "Person", "name": "{{ addcslashes($manuscript->scribe_name, '"\\') }}" },@endif
  "url": "{{ route('manuscripts.show', $manuscript) }}"
}
</script>
@endpush

