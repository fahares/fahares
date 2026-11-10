@extends('layouts.app')

@php
    use App\Support\Fa;
    use App\Models\Source;
    use App\Support\SearchFilters;
    $name = $source->short_name ?: $source->title;
    $kind = Source::KIND_LABELS[$source->kind] ?? 'منبع';
    $about = array_filter([$source->compiler, $source->publisher, $source->edition_label]);
    // «سید احمد حسینی اشکوری؛ زیر نظر سید محمود مرعشی», each name linking the cataloger
    $catalogers = function ($volume) {
        $link = fn ($c) => '<a href="' . e(route('catalogers.show', $c)) . '" class="text-ink hover:text-cinnabar">' . e($c->display_name ?? $c->name) . '</a>';
        $by = $volume->catalogers->where('pivot.role', '!=', 'supervisor')->map($link)->implode('، ');
        $over = $volume->catalogers->where('pivot.role', 'supervisor')->map($link)->implode('، ');

        return new \Illuminate\Support\HtmlString(implode('؛ ', array_filter([$by, $over ? 'زیر نظر ' . $over : null])));
    };
    // the people of a volume with parts, all its editors (step 13.150)
    $editors = fn ($volume) => new \Illuminate\Support\HtmlString($volume->catalogers->map(fn ($c) => '<a href="' . e(route('catalogers.show', $c)) . '" class="text-ink hover:text-cinnabar">' . e($c->display_name ?? $c->name) . '</a>')->implode('، '));
@endphp

@section('title', $source->title . ' | منابع و مراجع فهارس')
@section('meta_description', $kind . ': ' . $source->title . ($about ? '، ' . implode('، ', $about) : '') . '. جلدها، متن صفحه‌ها و برگه‌ی چاپی آن در فهارس.')

@section('content')
{{-- One source (step 13.80): what it is, what the site has of it, and each volume with where to start reading its text
     and turning its page images. --}}
<div x-data class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <nav class="flex flex-wrap items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('sources.index') }}" class="hover:text-cinnabar">منابع و مراجع</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold">{{ $name }}</span>
    </nav>

    {{-- the book beside what it is: a large cover, then its title, makers, counts and where to read --}}
    <header class="grid gap-8 md:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] lg:grid-cols-[17rem_minmax(0,1fr)] items-start pb-4">
        <x-source.cover :source="$source" size="large" class="w-44 sm:w-52 md:w-full mx-auto" />
        <div class="grid gap-2 justify-items-center md:justify-items-start text-center md:text-start md:pt-4">
            <p class="text-xs font-bold text-cinnabar">{{ $kind }}</p>
            <h1 class="font-naskh text-[26px] sm:text-[36px] font-bold leading-[1.6] text-ink text-balance">{{ $source->title }} <x-suggest.field name="title" class="ms-1 align-middle" /></h1>
            {{-- the compiler, publisher and year, each correctable (step 14.52); an empty one shows only in suggestion mode --}}
            @php
                $makers = ['compiler' => ['پدیدآورنده', $source->compiler], 'publisher' => ['ناشر', $source->publisher], 'publication_year' => ['سال نشر', $source->publication_year]];
            @endphp
            <p class="flex flex-wrap justify-center md:justify-start items-center gap-x-3 gap-y-1 text-sm text-muted" @unless($about || $source->publication_year) x-show="$store.suggest.on" x-cloak @endunless data-source-makers>
                @foreach($makers as $field => [$label, $value])
                    <span class="inline-flex items-center gap-1" @unless($value) x-show="$store.suggest.on" x-cloak @endunless>
                        @if($value){{ Fa::digits($value) }}@else<span class="text-muted/70">{{ $label }}: ثبت نشده</span>@endif
                        <x-suggest.field :name="$field" />
                    </span>
                @endforeach
                @if($source->edition_label)<span>{{ Fa::digits($source->edition_label) }}</span>@endif
            </p>
            @if($source->library)
                <p class="text-sm"><a href="{{ route('libraries.show', $source->library) }}" class="text-cinnabar hover:underline">{{ $source->library->display_name }}</a></p>
            @endif
            @if($source->editionOf)
                <p class="text-xs text-muted">چاپی از <a href="{{ route('sources.show', $source->editionOf->code) }}" class="text-cinnabar hover:underline">{{ $source->editionOf->title }}</a></p>
            @endif
            <div class="flex flex-wrap justify-center md:justify-start gap-x-5 gap-y-1 mt-2 text-sm text-muted" data-source-counts>
                @if($counts['manuscripts'])<span><span class="font-bold text-ink tabular-nums">{{ Fa::num($counts['manuscripts']) }}</span> نسخه</span>@endif
                @if($counts['works'])<span><span class="font-bold text-ink tabular-nums">{{ Fa::num($counts['works']) }}</span> اثر</span>@endif
                @if($counts['items'])<span><span class="font-bold text-ink tabular-nums">{{ Fa::num($counts['items']) }}</span> مدخل چاپی</span>@endif
                @if($counts['pages'])<span><span class="font-bold text-ink tabular-nums">{{ Fa::num($counts['pages']) }}</span> صفحه‌ی متن</span>@endif
            </div>
            @php $singleParts = $single && $single['volume']->relationLoaded('parts') ? $single['volume']->parts : collect(); @endphp
            @if($single && $singleParts->isNotEmpty() && $single['volume']->catalogers->isNotEmpty())
                <p class="text-sm text-muted" data-volume-catalogers>زیر نظر {{ $editors($single['volume']) }} <x-suggest.cataloger :volume="$single['volume']->id" class="ms-1" /></p>
            @elseif($single && $single['volume']->catalogers->isNotEmpty())
                <p class="text-sm text-muted" data-volume-catalogers>فهرست‌نگار: {{ $catalogers($single['volume']) }} <x-suggest.cataloger :volume="$single['volume']->id" class="ms-1" /></p>
            @elseif($single)
                {{-- no cataloger recorded: the suggestion is offered in suggestion mode (step 14.58) --}}
                <p class="text-sm text-muted" x-show="$store.suggest.on" x-cloak>فهرست‌نگار: ثبت نشده <x-suggest.cataloger :volume="$single['volume']->id" class="ms-1" /></p>
            @endif
            <div class="flex flex-wrap justify-center md:justify-start gap-2 mt-2">
                @if($single && $single['read'])<x-ui.btn size="sm" variant="primary" :href="$single['read']" data-read-volume>خواندن متن</x-ui.btn>@endif
                @if($single && $single['viewer'])<x-ui.btn size="sm" x-data x-on:click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($single['viewer']) }})">دیدن برگه‌ها</x-ui.btn>@endif
                @if($counts['items'])
                    <x-ui.btn size="sm" :href="route('search', ['type' => 'sources', 'source_id' => $source->id])">جست‌وجو در مدخل‌های این منبع</x-ui.btn>
                @endif
                @if($counts['works'] && in_array($source->kind, SearchFilters::SOURCE_KINDS, true))
                    <x-ui.btn size="sm" :href="route('search', ['type' => 'works', 'source_id' => $source->id])">آثار یادشده در آن</x-ui.btn>
                @endif
            </div>
            @if($source->description)
                <p class="max-w-3xl mt-3 text-sm text-ink leading-loose text-justify">{{ Fa::digits($source->description) }} <x-suggest.field name="description" class="ms-1" /></p>
            @else
                <p class="mt-3 text-sm text-muted" x-show="$store.suggest.on" x-cloak>معرفی‌ای ثبت نشده است. <x-suggest.field name="description" class="ms-1" /></p>
            @endif
        </div>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-suggest.toggle />
        <span class="text-xs text-muted">شناسه‌ی فهارس: <span class="font-mono text-ink font-bold">#{{ $source->id }}</span></span>
    </div>

    @if($source->editions->isNotEmpty())
        <x-ui.section title="چاپ‌های دیگر">
            <ul class="space-y-1 text-sm">
                @foreach($source->editions as $edition)
                    <li><a href="{{ route('sources.show', $edition->code) }}" class="text-cinnabar hover:underline">{{ $edition->title }}</a>@if($edition->edition_label)<span class="text-muted"> ({{ Fa::digits($edition->edition_label) }})</span>@endif</li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif

    {{-- a book in one volume of several libraries' catalogues: its parts (step 13.168) --}}
    @if($singleParts->isNotEmpty())
        <x-ui.section title="بخش‌ها" :count="$singleParts->count()">
            <ul class="divide-y divide-line border-y border-line text-xs text-muted" data-volume-parts>
                @foreach($singleParts as $part)
                    <li class="py-2" data-volume-part>
                        <span class="text-ink">@if($part->library)<a href="{{ route('libraries.show', $part->library) }}" class="hover:text-cinnabar">{{ Fa::digits($part->title) }}</a>@else{{ Fa::digits($part->title) }}@endif</span>
                        @if($part->pages_text)· {{ Fa::digits($part->pages_text) }}@endif
                        @if($part->catalogers->isNotEmpty())· {{ $catalogers($part) }}@endif
                        @if($part->manuscripts_count)· {{ Fa::num($part->manuscripts_count) }} نسخه@endif
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif

    @if($single && $single['toc'])
        <x-ui.section title="فهرست مطالب">
            <x-source.toc :toc="$single['toc']" open />
        </x-ui.section>
    @endif

    @unless($single)
    <x-ui.section title="جلدها" :count="$volumes->count()">
        <ul class="divide-y divide-line border-y border-line" data-source-volumes>
            @foreach($volumes as $row)
                @php
                    $v = $row['volume'];
                    // none until the deploy has migrated (CatalogVolumePart::ready)
                    $parts = $v->relationLoaded('parts') ? $v->parts : collect();
                @endphp
                <li id="v{{ $v->volume_number }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3 scroll-mt-24 target:bg-paper" data-volume="{{ $v->volume_number }}">
                    <span class="w-12 shrink-0 font-naskh text-lg font-bold text-ink">{{ Fa::digits('ج ' . $v->volume_number) }}</span>
                    <span class="flex-1 min-w-40 text-xs text-muted">
                        @if($v->title && $v->title !== $source->title){{ Fa::digits($v->title) }} · @endif
                        @if($row['manuscripts'])<a href="{{ route('search', ['type' => 'manuscripts', 'source_volume_id' => $v->id]) }}" class="text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar" data-volume-manuscripts>{{ Fa::num($row['manuscripts']) }} نسخه</a> · @endif
                        @if($row['pages']){{ Fa::num($row['pages']) }} صفحه‌ی متن @elseif($row['items']){{ Fa::num($row['items']) }} مدخل @endif
                        @if($v->publication_year)· {{ Fa::digits($v->publication_year) }}@endif
                        @if($parts->isNotEmpty())
                            {{-- a volume of several libraries' catalogues: its own people are its editors, each part has its own (step 13.150) --}}
                            @if($v->catalogers->isNotEmpty())<span class="block mt-0.5" data-volume-catalogers>زیر نظر {{ $editors($v) }} <x-suggest.cataloger :volume="$v->id" class="ms-1" /></span>@endif
                        @elseif($v->catalogers->isNotEmpty())<span class="block mt-0.5" data-volume-catalogers>فهرست‌نگار: {{ $catalogers($v) }} <x-suggest.cataloger :volume="$v->id" class="ms-1" /></span>
                        @else<span class="block mt-0.5" x-show="$store.suggest.on" x-cloak>فهرست‌نگار: ثبت نشده <x-suggest.cataloger :volume="$v->id" class="ms-1" /></span>@endif
                    </span>
                    <span class="flex flex-wrap gap-2">
                        @if($row['read'])<x-ui.btn size="sm" :href="$row['read']" data-read-volume>خواندن متن</x-ui.btn>@endif
                        @if($row['viewer'])<x-ui.btn size="sm" x-data x-on:click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($row['viewer']) }})">دیدن برگه‌ها</x-ui.btn>@endif
                    </span>
                    @if($parts->isNotEmpty())
                        <ul class="basis-full grid gap-1 ps-16 text-xs text-muted" data-volume-parts>
                            @foreach($parts as $part)
                                <li data-volume-part>
                                    <span class="text-ink">@if($part->library)<a href="{{ route('libraries.show', $part->library) }}" class="hover:text-cinnabar">{{ Fa::digits($part->title) }}</a>@else{{ Fa::digits($part->title) }}@endif</span>
                                    @if($part->pages_text)· {{ Fa::digits($part->pages_text) }}@endif
                                    @if($part->catalogers->isNotEmpty())· {{ $catalogers($part) }}@endif
                                    @if($part->manuscripts_count)· {{ Fa::num($part->manuscripts_count) }} نسخه@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($row['toc'])
                        <x-source.toc :toc="$row['toc']" class="basis-full" />
                    @endif
                </li>
            @endforeach
        </ul>
    </x-ui.section>
    @endunless

    @if($linked)
        @php
            $labels = ['manuscripts' => 'نسخه‌ها', 'works' => 'آثار'];
            // the volumes whose page texts the site has, for each entry's «ج ۳، ص ۱۲» to open its page
            $read = $volumes->filter(fn ($r) => $r['pages'] > 0)->mapWithKeys(fn ($r) => [$r['volume']->volume_number => true]);
        @endphp
        {{-- The site's records this source is cited for (its entries in the book's order), searchable by title --}}
        <x-ui.section id="linked" :title="$labels[$linked['type']] . ' پیوسته به این منبع'" :count="$linked['rows']->total()" data-source-linked="{{ $linked['type'] }}">
            <form action="{{ route('sources.show', $source->code) }}#linked" method="GET" role="search" class="flex flex-wrap items-center gap-2 mb-3 text-xs">
                @if(count($linked['types']) > 1)
                    <span class="flex rounded-sm border border-line-strong overflow-hidden" aria-label="نوع">
                        @foreach($linked['types'] as $t)
                            <a href="{{ route('sources.show', array_filter([$source->code, 'list' => $t, 'q' => $linked['q'], 'volume' => $linked['volume']])) }}#linked" @class(['px-3 py-1.5 font-bold', 'bg-cinnabar text-white' => $t === $linked['type'], 'bg-paper text-muted hover:text-ink' => $t !== $linked['type']]) @if($t === $linked['type']) aria-current="true" @endif>{{ $labels[$t] }} <span class="tabular-nums">{{ Fa::num($counts[$t]) }}</span></a>
                        @endforeach
                    </span>
                @endif
                <input type="hidden" name="list" value="{{ $linked['type'] }}">
                <input type="search" name="q" value="{{ $linked['q'] }}" placeholder="عنوان اثر…" aria-label="عنوان اثر"
                       class="flex-1 min-w-[12rem] max-w-sm px-2.5 py-1.5 rounded-sm border border-line-strong bg-paper text-ink text-xs focus:border-cinnabar focus:ring-2 focus:ring-cinnabar/15 outline-none">
                @if($volumes->count() > 1)
                    <select name="volume" aria-label="جلد" class="px-2 py-1.5 rounded-sm border border-line-strong bg-paper text-ink text-xs" onchange="this.form.requestSubmit()">
                        <option value="">همه‌ی جلدها</option>
                        @foreach($volumes as $row)
                            <option value="{{ $row['volume']->volume_number }}" @selected($linked['volume'] === $row['volume']->volume_number)>{{ Fa::digits('ج ' . $row['volume']->volume_number) }}</option>
                        @endforeach
                    </select>
                @endif
                <x-ui.btn type="submit" size="sm">جست‌وجو</x-ui.btn>
                @if($linked['q'] !== '' || $linked['volume'])
                    <a href="{{ route('sources.show', [$source->code, 'list' => $linked['type']]) }}#linked" class="text-cinnabar hover:underline">پاک کردن</a>
                @endif
            </form>
            <div class="bg-surface border border-line overflow-x-auto">
                <table class="w-full text-right text-xs sm:text-[13px] min-w-[560px]">
                    <thead class="text-muted border-b border-gold">
                        <tr>
                            <th class="py-3 px-3 font-semibold">{{ $linked['type'] === 'works' ? 'اثر' : 'نسخه' }}</th>
                            <th class="py-3 px-3 font-semibold">پدیدآور</th>
                            @if($linked['type'] === 'manuscripts')<th class="py-3 px-3 font-semibold">کتابخانه و شماره</th>@endif
                            <th class="py-3 px-3 font-semibold">جای آن در منبع</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line text-ink tabular-nums">
                        @forelse($linked['rows'] as $entry)
                            @php
                                $record = $entry->citable;
                                $work = $linked['type'] === 'works' ? $record : $record?->work;
                                $place = $entry->volume_number ? Fa::digits(($volumes->count() > 1 ? 'ج ' . $entry->volume_number . '، ' : '') . 'ص ' . $entry->page_start) : null;
                            @endphp
                            @continue(! $record)
                            <tr class="hover:bg-paper" data-linked-entry="{{ $entry->id }}">
                                <td class="py-2.5 px-3">
                                    <a href="{{ $linked['type'] === 'works' ? route('works.show', $record) : route('manuscripts.show', $record) }}" class="font-semibold hover:text-cinnabar">{{ $work?->primary_title ?? 'بی‌عنوان' }}</a>
                                </td>
                                <td class="py-2.5 px-3">{{ $work?->author?->name ?? $work?->author_name ?? '—' }}</td>
                                @if($linked['type'] === 'manuscripts')
                                    <td class="py-2.5 px-3">{{ $record->libraryRecord?->display_name ?? $record->library ?? '—' }}@if($record->shelfmark)<span class="text-muted"> · <span dir="ltr">{{ Fa::digits($record->shelfmark) }}</span></span>@endif</td>
                                @endif
                                <td class="py-2.5 px-3 whitespace-nowrap">
                                    @if($place && $read->has($entry->volume_number) && $entry->page_start)
                                        <a href="{{ route('source-pages.show', [$source->code, $entry->volume_number, $entry->page_start]) }}" class="text-cinnabar hover:underline">{{ $place }}</a>
                                    @else
                                        {{ $place ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-muted">چیزی با این عنوان پیدا نشد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($linked['rows']->hasPages())<div class="pt-4">{{ $linked['rows']->links() }}</div>@endif
        </x-ui.section>
    @endif

    <!-- READINGS and RESEARCH NOTES of the source, and the suggestion desk (step 14.52) -->
    <x-suggest.readings />
    <x-suggest.notes type="source" />
    <x-suggest.contribute type="source" />
    <x-suggest.desk type="source" :record="$source" />
</div>
@endsection
