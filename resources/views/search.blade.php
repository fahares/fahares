@extends('layouts.app')

@section('title', 'کاوش و جستجوی پیشرفته | فهارس')

@section('content')
@php use App\Support\Fa; @endphp
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Search Header & Mode Bar -->
    <div class="bg-surface p-6 rounded-sm border border-line space-y-4">
        
        <form action="{{ route('search') }}" method="GET" class="space-y-4">
            <input type="hidden" name="type" id="search-type-input" value="{{ $type }}">

            <!-- Search bar -->
            <div class="relative flex items-center">
                <input 
                    type="text" 
                    name="q" 
                    value="{{ $query }}" 
                    placeholder="عبارت مورد نظر برای کاوش..."
                    class="w-full pr-12 pl-28 py-3.5 bg-paper text-ink placeholder:text-muted rounded-sm border border-line-strong focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 text-base transition">
                
                <div class="absolute right-4 text-muted">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>

                <button 
                    type="submit" 
                    class="absolute left-2 px-5 py-2 bg-cinnabar hover:opacity-90 text-on-cinnabar font-semibold rounded-sm text-sm transition shadow">
                    جستجو
                </button>
            </div>

            <!-- Search Controls: Scope Radios & Type Tabs -->
            <div class="flex flex-wrap items-center justify-between gap-4 pt-3 border-t border-line text-xs">
                
                <!-- Where the words are looked for: the three general scopes, or one field of the tab (backlog item 83) -->
                <label class="flex flex-wrap items-center gap-2 text-ink/80">
                    <span class="font-bold text-muted">جستجوی عبارت در:</span>
                    <select name="scope" onchange="this.form.submit()" class="py-1 ps-2 pe-7 rounded-sm border border-line-strong bg-paper text-ink text-xs">
                        <option value="titles" @selected($scope === 'titles')>فقط عنوان‌ها</option>
                        <option value="titles_names" @selected($scope === 'titles_names')>عنوان‌ها و نام‌ها{{ $type === 'manuscripts' ? '، شماره و کتابخانه' : '' }}</option>
                        <option value="all" @selected($scope === 'all')>همه‌ی اطلاعات</option>
                        @if($fieldScopes = \App\Http\Controllers\SearchController::FIELD_SCOPES[$type] ?? [])
                            <optgroup label="فقط در">
                                @foreach($fieldScopes as $key => [$scopeLabel])
                                    <option value="{{ $key }}" @selected($scope === $key)>{{ $scopeLabel }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </label>

                <!-- Type Tabs -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-muted font-medium">دامنه:</span>
                    <button type="submit" onclick="document.getElementById('search-type-input').value='works'" 
                        class="px-3 py-1 rounded-sm font-medium transition {{ $type === 'works' ? 'bg-ink text-paper' : 'text-muted hover:text-ink' }}">
                        آثار و عناوین@isset($tabCounts['works']) <span class="text-[10px] opacity-75">({{ Fa::num($tabCounts['works']) }})</span>@endisset
                    </button>
                    <button type="submit" onclick="document.getElementById('search-type-input').value='manuscripts'" 
                        class="px-3 py-1 rounded-sm font-medium transition {{ $type === 'manuscripts' ? 'bg-ink text-paper' : 'text-muted hover:text-ink' }}">
                        نسخه‌های خطی@isset($tabCounts['manuscripts']) <span class="text-[10px] opacity-75">({{ Fa::num($tabCounts['manuscripts']) }})</span>@endisset
                    </button>
                    <button type="submit" onclick="document.getElementById('search-type-input').value='people'" 
                        class="px-3 py-1 rounded-sm font-medium transition {{ $type === 'people' ? 'bg-ink text-paper' : 'text-muted hover:text-ink' }}">
                        پدیدآوران و کاتبان@isset($tabCounts['people']) <span class="text-[10px] opacity-75">({{ Fa::num($tabCounts['people']) }})</span>@endisset
                    </button>
                    {{-- The printed entries of the sources: al-Dhari'a… (backlog item 83, phase 7) --}}
                    <button type="submit" onclick="document.getElementById('search-type-input').value='sources'"
                        class="px-3 py-1 rounded-sm font-medium transition {{ $type === 'sources' ? 'bg-ink text-paper' : 'text-muted hover:text-ink' }}">
                        در منابع@isset($tabCounts['sources']) <span class="text-[10px] opacity-75">({{ Fa::num($tabCounts['sources']) }})</span>@endisset
                    </button>
                </div>

                @if($results)
                    <div class="text-xs text-muted font-medium">
                        {{ Fa::num($results->total()) }} نتیجه
                    </div>
                @endif
            </div>

            <!-- Active filters, each with a way to remove it (App\Support\SearchFilters::active) -->
            @if($chips = $filters->active())
                <div class="flex flex-wrap items-center gap-2 pt-2 text-xs">
                    <span class="text-muted">فیلترهای فعال:</span>
                    @foreach($chips as [$chipLabel, $drop])
                        <a href="{{ \App\Support\SearchFilters::removeUrl($drop) }}"
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm bg-cinnabar/5 text-cinnabar border border-cinnabar/30 hover:bg-cinnabar/10 transition">
                            <span>{{ $chipLabel }}</span>
                            <span class="font-bold" aria-label="برداشتن">✕</span>
                        </a>
                    @endforeach
                    <a href="{{ route('search', array_filter(['q' => $query, 'type' => $type, 'scope' => $scope, 'sort' => $sortKey ?: null])) }}" class="px-2.5 py-1 rounded-sm bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-300 hover:underline">
                        پاکسازی همه فیلترها ✕
                    </a>
                </div>
            @endif
        </form>

    </div>

    <!-- Main Grid: Sidebar Filters + Results List -->
    {{-- Above filters and results, so a phone sees it first --}}
    @if($direct ?? null)
        <div class="mb-6">@include('search.direct-answer')</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
        
        <!-- SIDEBAR FILTERS -->
        <aside class="space-y-6">
            
            @include('search.filters')

        </aside>

        <!-- RESULTS CONTENT -->
        <div class="lg:col-span-3 space-y-4 min-w-0">

            @if($results && $results->count() > 0)

                <!-- Results bar with share button -->
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm"
                     x-data="{
                         copied: false,
                         copyLink() {
                             const url = window.location.href;
                             if (navigator.clipboard && navigator.clipboard.writeText) {
                                 navigator.clipboard.writeText(url).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2500); });
                             } else {
                                 const ta = document.createElement('textarea');
                                 ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                                 this.copied = true; setTimeout(() => this.copied = false, 2500);
                             }
                         }
                     }">
                    <h2 class="rubric text-lg">
                        <span><span class="tabular-nums">{{ Fa::num($results->total()) }}</span> {{ ['works' => 'اثر', 'manuscripts' => 'نسخه', 'people' => 'شخص', 'sources' => 'مدخل'][$type] ?? 'نتیجه' }}</span>
                        @if($query)<span class="font-sans text-sm font-normal text-muted">برای «<span class="text-ink font-semibold">{{ $query }}</span>»</span>@endif
                    </h2>
                    {{-- Order and page size (backlog item 83, phase 3); the other parameters of the search are kept --}}
                    <form method="GET" action="{{ route('search') }}" class="flex items-center gap-2 text-xs ms-auto">
                        @foreach(request()->except(['sort', 'per_page', 'page']) as $key => $value)
                            @foreach((array) $value as $v)
                                <input type="hidden" name="{{ $key }}{{ is_array($value) ? '[]' : '' }}" value="{{ $v }}">
                            @endforeach
                        @endforeach
                        <select name="sort" aria-label="ترتیب نتایج" onchange="this.form.submit()" class="py-1 ps-2 pe-7 rounded-sm border border-line-strong bg-paper text-ink text-xs">
                            <option value="">{{ $query !== '' ? 'مرتبط‌ترین' : 'ترتیب پیش‌فرض' }}</option>
                            @foreach(\App\Http\Controllers\SearchController::SORTS[$type] ?? [] as $key => [$label])
                                <option value="{{ $key }}" @selected($sortKey === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select name="per_page" aria-label="شمار در هر صفحه" onchange="this.form.submit()" class="py-1 ps-2 pe-7 rounded-sm border border-line-strong bg-paper text-ink text-xs">
                            @foreach(\App\Http\Controllers\SearchController::PER_PAGE as $n)
                                <option value="{{ $n }}" @selected($perPage === $n)>{{ Fa::num($n) }} در صفحه</option>
                            @endforeach
                        </select>
                    </form>
                    <x-ui.btn size="sm" @click="copyLink()">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                        <span x-show="!copied">پیوند این جستجو</span>
                        <span x-show="copied" x-cloak class="text-emerald-600">رونوشت شد</span>
                    </x-ui.btn>
                    {{-- The results as a file, with the same filters and order (backlog item 83, phase 4) --}}
                    @php
                        $exportLimit = auth()->check() ? \App\Support\SearchExport::MEMBER_LIMIT : \App\Support\SearchExport::GUEST_LIMIT;
                        $exportUrl = fn ($format) => route('search.export', array_merge(request()->except(['page', 'per_page']), ['format' => $format]));
                    @endphp
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <x-ui.btn size="sm" @click="open = !open" ::aria-expanded="open">خروجی</x-ui.btn>
                        <div x-show="open" x-cloak class="absolute end-0 z-20 mt-1 w-60 bg-surface border border-line shadow-lg p-2 text-xs space-y-1">
                            <a href="{{ $exportUrl('xlsx') }}" rel="nofollow" class="block px-2 py-1.5 hover:bg-paper hover:text-cinnabar">اکسل (xlsx)</a>
                            <a href="{{ $exportUrl('csv') }}" rel="nofollow" class="block px-2 py-1.5 hover:bg-paper hover:text-cinnabar">CSV</a>
                            <p class="px-2 pt-1 border-t border-line text-muted leading-5">
                                {{ Fa::num(min($exportLimit, $results->total())) }} نتیجه‌ی نخست، با همین فیلترها و ترتیب.
                                @guest <a href="{{ route('login') }}" class="text-cinnabar hover:underline">با ورود</a> تا {{ Fa::num(\App\Support\SearchExport::MEMBER_LIMIT) }} نتیجه. @endguest
                            </p>
                            {{-- More than a file built here can hold: the whole list, built in the queue (backlog item 83) --}}
                            @auth
                                @if($results->total() > \App\Support\SearchExport::MEMBER_LIMIT)
                                    <form method="POST" action="{{ route('search.export.queue', request()->except(['page', 'per_page'])) }}" class="px-2 pt-2 border-t border-line space-y-1.5">
                                        @csrf
                                        <input type="hidden" name="total" value="{{ $results->total() }}">
                                        <p class="text-muted leading-5">همه‌ی {{ Fa::num(min($results->total(), \App\Support\SearchExport::QUEUED_LIMIT)) }} نتیجه در پس‌زمینه ساخته و پیوندش برایتان فرستاده می‌شود:</p>
                                        <div class="flex gap-1.5">
                                            <x-ui.btn type="submit" size="sm" name="format" value="xlsx">اکسل کامل</x-ui.btn>
                                            <x-ui.btn type="submit" size="sm" name="format" value="csv">CSV کامل</x-ui.btn>
                                        </div>
                                    </form>
                                @endif
                            @endauth
                        </div>
                    </div>
                </div>

                <div @if($searchLogId) data-search-log="{{ $searchLogId }}" data-click-url="{{ route('search.click') }}" @endif>
                @if($type === 'works')
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($results as $work)
                            <li class="py-4 space-y-1.5" data-pos="{{ ($results->currentPage() - 1) * $results->perPage() + $loop->iteration }}">
                                <div class="flex items-baseline gap-2">
                                    <a href="{{ route('works.show', $work) }}" class="font-naskh text-lg sm:text-xl font-bold text-ink hover:text-cinnabar transition leading-relaxed">@highlight($work->primary_title, $query)</a>
                                    <span class="leader hidden sm:block" aria-hidden="true"></span>
                                    <a href="{{ route('works.show', $work) }}#manuscripts" class="ms-auto sm:ms-0 text-xs text-muted tabular-nums whitespace-nowrap hover:text-cinnabar">{{ Fa::num($work->manuscripts_count) }} نسخه</a>
                                </div>
                                @if(($work->clean_title && $work->clean_title !== $work->primary_title) || ($work->match_reason ?? null))
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-muted">
                                        @if($work->clean_title && $work->clean_title !== $work->primary_title)<span>@highlight($work->clean_title, $query)</span>@endif
                                        @if($work->match_reason ?? null)<x-ui.chip class="!text-[11px] !py-0">{{ $work->match_reason }}</x-ui.chip>@endif
                                    </div>
                                @endif
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink/80">
                                    @if($work->author)
                                        <span>از <a href="{{ route('people.show', $work->author) }}" class="font-semibold text-ink hover:text-cinnabar">@highlight($work->author->name, $query)</a> <x-ui.life :person="$work->author" /></span>
                                    @elseif($work->author_name)
                                        <span>از <span class="font-semibold text-ink">@highlight($work->author_name, $query)</span></span>
                                    @else
                                        <span class="text-muted">پدیدآور ناشناخته</span>
                                    @endif
                                    @if($work->composition_year_hijri)<span>تألیف {{ Fa::digits($work->composition_year_hijri) }} ق</span>@endif
                                    @if($work->work_form)<span>{{ $validWorkForms[$work->work_form] ?? $work->work_form }}</span>@endif
                                    @if($work->composition_place)<span>{{ $work->composition_place }}</span>@endif
                                    @if(! empty($work->copy_century_text))<span class="text-muted">نسخه‌ها {{ Fa::digits($work->copy_century_text) }}</span>@endif
                                    @if($work->has_autograph)<x-ui.chip tone="good" class="!text-[11px] !py-0">دستخط مؤلف</x-ui.chip>@endif
                                </div>
                                @if($work->subjects->isNotEmpty() || $work->languages->isNotEmpty())
                                    <div class="text-xs text-muted">
                                        {{ $work->subjects->pluck('title')->merge($work->languages->pluck('name'))->implode(' · ') }}
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                @elseif($type === 'manuscripts')
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($results as $ms)
                            <li class="py-4 space-y-1.5" data-pos="{{ ($results->currentPage() - 1) * $results->perPage() + $loop->iteration }}">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <a href="{{ route('manuscripts.show', $ms) }}" class="font-naskh text-lg font-bold text-ink hover:text-cinnabar transition leading-relaxed">@highlight($ms->work?->primary_title ?? 'نسخه‌ی بی‌عنوان', $query)</a>
                                    @if($ms->is_autograph)<x-ui.chip tone="good" class="!text-[11px] !py-0">دستخط مؤلف</x-ui.chip>@endif
                                    @if($ms->century_text)<span class="ms-auto text-xs text-muted whitespace-nowrap">{{ Fa::digits($ms->century_text) }}</span>@endif
                                </div>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink/80">
                                    @if($ms->work?->author)
                                        <span>از <a href="{{ route('people.show', $ms->work->author) }}" class="font-semibold text-ink hover:text-cinnabar">@highlight($ms->work->author->name, $query)</a> <x-ui.life :person="$ms->work->author" /></span>
                                    @elseif($ms->work?->author_name)
                                        <span>از <span class="font-semibold text-ink">@highlight($ms->work->author_name, $query)</span></span>
                                    @endif
                                    <span><span class="font-semibold text-ink" title="{{ $ms->libraryRecord?->display_name }}">{{ $ms->libraryRecord?->short_name ?? $ms->library ?? 'نامشخص' }}</span>@if($ms->city)، {{ $ms->city }}@endif</span>
                                    <span>شماره‌ی <span dir="ltr" class="font-semibold text-cinnabar">@highlight($ms->shelfmark ?? 'بی‌شماره', $query)</span></span>
                                    @if($ms->scribe_name)
                                        <span>کاتب: @highlight($ms->scribe_name, $query) <x-ui.life :person="$ms->scribe" /></span>
                                    @elseif($ms->is_autograph)
                                        <span>کاتب: <span class="text-emerald-700 dark:text-emerald-300">مؤلف</span></span>
                                    @elseif($ms->is_bika)
                                        <span class="text-muted">بی‌کاتب</span>
                                    @endif
                                    @if($ms->copy_date_raw)
                                        <span>کتابت {{ Fa::digits($ms->copy_date_raw) }}</span>
                                    @elseif($ms->is_bita)
                                        <span class="text-muted">بی‌تاریخ</span>
                                    @endif
                                    @if($ms->commissioned_by)<span>به سفارش {{ $ms->commissioned_by }}</span>@endif
                                    @if($ms->catalog_citation)<span class="text-muted" title="شماره و ارجاع در فهرست اصلی کتابخانه">{{ $ms->catalog_citation }}</span>@endif
                                </div>
                                @if(! empty($ms->incipit))
                                    <p class="text-[13px] text-ink/80 line-clamp-1 border-s-2 border-gold ps-3 font-naskh">
                                        <span class="font-sans text-xs font-bold text-cinnabar">{{ $ms->incipit_matches_work ? 'آغاز (برابر با اثر):' : 'آغاز:' }}</span>
                                        @highlight(Str::limit(trim($ms->incipit, "«» \t\n\r\0\x0B"), 110), $query)
                                    </p>
                                @endif
                                @if(! empty($ms->explicit))
                                    <p class="text-[13px] text-ink/80 line-clamp-1 border-s-2 border-line-strong ps-3 font-naskh">
                                        <span class="font-sans text-xs font-bold text-muted">{{ $ms->explicit_matches_work ? 'انجام (برابر با اثر):' : 'انجام:' }}</span>
                                        @highlight(Str::limit(trim($ms->explicit, "«» \t\n\r\0\x0B"), 110), $query)
                                    </p>
                                @endif
                                @php
                                    $msTags = array_filter([
                                        $ms->script_names ? 'خط ' . $ms->script_names : null,
                                        $ms->folios ? Fa::digits($ms->folios) . ' برگ' : null,
                                        $ms->has_author_marginalia ? 'حواشی مؤلف' : null,
                                        (! empty($ms->ownership_and_seals_list) && count($ms->ownership_and_seals_list)) ? 'مهر و تملک (' . Fa::digits(count($ms->ownership_and_seals_list)) . ')' : null,
                                        $ms->is_illuminated ? 'مذهّب' : null,
                                        $ms->is_illustrated ? 'مصوّر' : null,
                                        $ms->is_corrected ? 'مصحَّح' : null,
                                        $ms->has_marginal_notes ? 'محشّی' : null,
                                    ]);
                                @endphp
                                @if($msTags)
                                    <div class="text-xs text-muted">{{ implode(' · ', $msTags) }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                @elseif($type === 'people')
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($results as $person)
                            <li data-pos="{{ ($results->currentPage() - 1) * $results->perPage() + $loop->iteration }}">
                                <a href="{{ route('people.show', $person) }}" class="flex items-baseline gap-2 py-3 group">
                                    <span class="min-w-0">
                                        <span class="font-naskh text-lg font-bold text-ink group-hover:text-cinnabar transition">@highlight($person->name, $query)</span>
                                        <x-ui.life :person="$person" />
                                        @if($person->transliteration)<span class="block text-[11px] text-muted" dir="ltr">{{ $person->transliteration }}</span>@endif
                                    </span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($person->works_count) }} اثر</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>

                @elseif($type === 'sources')
                    @php
                        // The engine's marks (\x01…\x02) around the words found, kept through the escaping; the engine
                        // counts the article «ال» as a word of its own, and marking it in every word is noise
                        $marked = fn (?string $text) => str_replace(["\x01ال\x02", "\x01", "\x02"], ['ال', '<mark class="bg-gold/30 text-ink rounded-sm px-0.5">', '</mark>'], e($text));
                    @endphp
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($results as $item)
                            <li class="py-4 space-y-1.5" data-pos="{{ ($results->currentPage() - 1) * $results->perPage() + $loop->iteration }}">
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <a href="{{ $item->url() }}" class="font-naskh text-lg font-bold text-ink hover:text-cinnabar transition leading-relaxed">{!! $item->snippet['title'] ?? null ? $marked($item->snippet['title']) : e($item->title ?: 'مدخل بی‌عنوان') !!}</a>
                                    <span class="text-xs text-muted whitespace-nowrap">{{ $item->source?->short_name ?: $item->source?->title }}، {{ $item->placeLabel() }}</span>
                                </div>
                                <p class="text-[13px] text-ink/85 leading-7 font-naskh border-s-2 border-gold ps-3">
                                    {!! $item->snippet['text'] ?? null ? $marked($item->snippet['text']) : e(Str::limit($item->text, 260)) !!}
                                </p>
                                @if(($item->linked_works ?? collect())->isNotEmpty())
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="text-muted">در فهارس:</span>
                                        @foreach($item->linked_works as $linked)
                                            <a href="{{ route('works.show', $linked) }}" class="text-cinnabar hover:underline">{{ $linked->primary_title }}</a>@if(! $loop->last)<span class="text-muted">·</span>@endif
                                        @endforeach
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                </div>
                @if($searchLogId)
                    {{-- The rank of the first result opened, for the admin's search stats (backlog item 83) --}}
                    <script>
                        (() => {
                            const box = document.querySelector('[data-search-log]');
                            let sent = false;
                            const send = (e) => {
                                const item = !sent && e.target.closest('a') && e.target.closest('[data-pos]');
                                if (!item || !box.contains(item)) return;
                                sent = true;
                                const body = new FormData();
                                body.append('log', box.dataset.searchLog);
                                body.append('position', item.dataset.pos);
                                navigator.sendBeacon(box.dataset.clickUrl, body);
                            };
                            box.addEventListener('click', send);
                            box.addEventListener('auxclick', send);
                        })();
                    </script>
                @endif

                <div class="pt-6">
                    {{ $results->links() }}
                </div>
                @if(auth()->user()?->search_history)
                    <p class="pt-4 text-[11px] text-muted">جستجوهای شما برای بهتر شدن جستجوی فهارس با حسابتان نگه داشته می‌شود. <a href="{{ route('profile') }}#search-history" class="underline hover:text-cinnabar">تنظیم</a></p>
                @endif

            @elseif(($direct ?? null) && ! $direct->isEmpty())
                {{-- The direct answer above is the result --}}
            @else
                <!-- No Results State -->
                <div class="py-12 text-center space-y-5">
                    <p class="font-naskh text-2xl font-bold text-ink">نتیجه‌ای پیدا نشد</p>

                    @php
                        $tabLabels = ['works' => 'اثر', 'manuscripts' => 'نسخه', 'people' => 'شخص', 'sources' => 'مدخل در منابع'];
                        $elsewhere = collect($tabCounts ?? [])->except($type)->filter();
                    @endphp
                    @if($elsewhere->isNotEmpty())
                        <div class="flex flex-wrap justify-center gap-2 text-sm">
                            @foreach($elsewhere as $tab => $count)
                                <x-ui.btn :href="route('search', ['q' => $query, 'type' => $tab, 'scope' => $scope])">{{ Fa::num($count) }} {{ $tabLabels[$tab] }} با این عبارت پیدا شد ←</x-ui.btn>
                            @endforeach
                        </div>
                    @endif

                    @if(! empty($suggestion))
                        <p class="text-sm text-ink/80">
                            آیا منظور شما
                            <a href="{{ route('search', array_merge(request()->except(['page', 'q']), ['q' => $suggestion])) }}" class="font-bold text-cinnabar underline decoration-cinnabar/40 hover:decoration-cinnabar text-base">«{{ $suggestion }}»</a>
                            بود؟
                        </p>
                    @endif

                    <p class="text-sm text-muted max-w-md mx-auto leading-relaxed">املای واژه‌ها را بازبینی کنید، یا دامنه‌ی جستجو و پالایه‌ها را تغییر دهید.</p>
                    <x-ui.btn :href="route('search', ['type' => $type])">همه‌ی موارد این بخش</x-ui.btn>
                </div>
            @endif

        </div>

    </div>

</div>
@endsection
