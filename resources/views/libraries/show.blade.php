@extends('layouts.app')

@section('title', $library->display_name . ' | کتابخانه‌ها در فهارس')
@section('meta_description', 'فهرست نسخه‌های خطی نگهداری‌شده در ' . $library->display_name . ' (' . ($library->city ?? '') . ') در سامانه فهارس')

@section('content')
@php use App\Support\Fa; @endphp
<div x-data class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('libraries.index') }}" class="hover:text-cinnabar">کتابخانه‌ها</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold truncate">{{ $library->display_name }}</span>
    </nav>

    <!-- LIBRARY HEADER -->
    <header class="text-center grid justify-items-center gap-2 pb-6">
        <x-library-logo :library="$library" size="lg" class="mb-2" />
        <p class="text-xs font-bold text-cinnabar">کتابخانه و مرکز نگهداری</p>
        <h1 class="font-naskh text-[26px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">
            <x-suggest.centered name="full_name">{{ $library->display_name }}</x-suggest.centered>
        </h1>
        <p class="text-sm text-ink">
            <x-suggest.centered name="country">{{ $library->city ?? 'ایران' }}@if($library->country && $library->country !== 'ایران')، {{ $library->country }}@endif</x-suggest.centered>
        </p>
        <a href="#manuscripts" class="text-sm text-muted hover:text-cinnabar transition">
            <span class="font-bold text-cinnabar tabular-nums">{{ Fa::num($library->manuscripts_count) }}</span> نسخۀ ثبت‌شده
        </a>
        @if($coverage ?? null)
            <p class="text-xs text-muted max-w-[60ch] leading-relaxed" data-library-firsthand>
                شناسه‌های اصلی <span class="font-bold text-ink tabular-nums">{{ Fa::num($coverage['complete']) }}</span> نسخه
                ({{ Fa::digits(str_replace('.', '٫', (string) $coverage['share'])) }}٪ نسخه‌ها) با فهرست چاپی کتابخانه سنجیده و تأیید شده است.
                <a href="{{ route('insights.topic', 'libraries') }}#firsthand" class="text-cinnabar hover:underline">دربارۀ این رقم</a>
            </p>
        @endif
        @if($library->full_name || $library->aliases)
            <div class="flex flex-wrap justify-center items-center gap-1.5 mt-1">
                @if($library->full_name)<x-ui.chip title="نام کوتاه در فهرست‌ها">{{ $library->short_name }}</x-ui.chip>@endif
                @foreach($library->aliases ?? [] as $alias)
                    <x-ui.chip title="نام دیگر">{{ $alias }}</x-ui.chip>
                @endforeach
            </div>
        @endif
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-suggest.toggle type="library" />
        <span class="text-xs text-muted">شناسۀ فهارس: <span class="font-mono text-ink font-bold">#{{ $library->id }}</span></span>
    </div>

    <div class="min-w-0 space-y-10">
        <div @unless($library->description) x-show="$store.suggest.on" x-cloak @endunless>
            <x-ui.section title="معرفی کتابخانه" field="description">
                @if($library->description)
                    <p class="text-sm sm:text-[15px] text-ink leading-loose whitespace-pre-line max-w-[72ch]">{{ $library->description }}</p>
                @else
                    <p class="text-sm text-muted">معرفی‌ای ثبت نشده است.</p>
                @endif
            </x-ui.section>
        </div>

        @if($catalogues->isNotEmpty() || $parts->isNotEmpty())
            <x-ui.section title="فهرست‌های چاپی" :count="$catalogues->count() + $parts->count()">
                <ul class="space-y-1 text-sm" data-library-catalogues>
                    @foreach($catalogues as $catalogue)
                        <li class="flex gap-2.5 leading-relaxed">
                            <span class="mt-2.5 w-1.5 h-1.5 shrink-0 rotate-45 bg-gold" aria-hidden="true"></span>
                            @if($catalogue->isUnpublished() && $catalogue->editions->isNotEmpty())
                                {{-- an unprinted catalogue comes after the printed one of the library (step 14.240) --}}
                                <span>@foreach($catalogue->editions as $edition)<a href="{{ route('sources.show', $edition->code) }}" class="text-cinnabar hover:underline">{{ $edition->title }}</a>@if($edition->edition_label)<span class="text-muted"> ({{ Fa::digits($edition->edition_label) }})</span>@endif<span class="text-muted">، </span>@endforeach<a href="{{ route('sources.show', $catalogue->code) }}" class="text-cinnabar hover:underline" data-library-unprinted>فهرست چاپ‌نشده</a>
                                </span>
                            @else
                            <span><a href="{{ route('sources.show', $catalogue->code) }}" class="text-cinnabar hover:underline">{{ $catalogue->title }}</a>
                                @foreach($catalogue->editions as $edition)<span class="text-muted">، </span><a href="{{ route('sources.show', $edition->code) }}" class="text-cinnabar hover:underline">{{ Fa::digits($edition->edition_label ?: $edition->title) }}</a>@endforeach
                            </span>
                            @endif
                        </li>
                    @endforeach
                    {{-- a part of a volume that holds several libraries' catalogues (step 14.50) --}}
                    @foreach($parts as $part)
                        @php($volume = $part->sourceVolume)
                        <li class="flex gap-2.5 leading-relaxed" data-library-part>
                            <span class="mt-2.5 w-1.5 h-1.5 shrink-0 rotate-45 bg-gold" aria-hidden="true"></span>
                            <span><a href="{{ route('sources.show', $volume->source->code) }}" class="text-cinnabar hover:underline">{{ $volume->source->title }}</a>@if($volume->volume_number)<span class="text-muted">، جلد {{ Fa::num($volume->volume_number) }}</span>@endif
                                <span class="text-muted">— {{ collect(['بخش «' . $part->title . '»', $part->page_first ? Fa::digits($part->pages_text) : null,
                                    $part->catalogers->pluck('name')->implode('، ') ?: null, $part->manuscripts_count ? Fa::num($part->manuscripts_count) . ' نسخه' : null])->filter()->implode('، ') }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.section>
        @endif

        {{-- the library's places in the sources, as a copy's or a work's (backlog item 170, step 14.405) --}}
        @php($libraryCards = $library->sources()->cards())
        @if($libraryCards->isNotEmpty())
            <x-ui.section title="در منابع" :count="$libraryCards->count()">
                <div class="space-y-4" data-library-sources>
                    @foreach($libraryCards as $entry)
                        <x-source.page-card :entry="$entry" :record="$library" />
                    @endforeach
                </div>
            </x-ui.section>
        @endif

        <!-- MANUSCRIPTS TABLE -->
        <x-ui.section id="manuscripts" title="نسخه‌ها" :count="$library->manuscripts_count">
            <x-slot:actions><x-list-stats.button param="library" :id="$library->id" /></x-slot:actions>
            <x-list-stats.summary param="library" :id="$library->id" />
            <x-search.list-tools :params="['type' => 'manuscripts', 'library_id' => $library->id]" placeholder="عنوان، پدیدآور، کاتب یا شماره در این کتابخانه…" />
            <div class="bg-surface border border-line">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs sm:text-[13px] min-w-[640px]">
                        <thead class="text-muted border-b border-gold">
                            <tr>
                                <th class="py-3 px-3 font-semibold">اثر</th>
                                <th class="py-3 px-3 font-semibold">پدیدآور</th>
                                <th class="py-3 px-3 font-semibold">شماره</th>
                                <th class="py-3 px-3 font-semibold">کاتب</th>
                                <th class="py-3 px-3 font-semibold">تاریخ کتابت</th>
                                <th class="py-3 px-3 font-semibold">خط</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line text-ink tabular-nums">
                            @forelse($manuscripts as $ms)
                                <tr onclick="if (!window.getSelection().toString()) { if (event.ctrlKey || event.metaKey) { window.open('{{ route('manuscripts.show', $ms) }}', '_blank'); } else { window.location.href = '{{ route('manuscripts.show', $ms) }}'; } }"
                                    onauxclick="if (event.button === 1) { window.open('{{ route('manuscripts.show', $ms) }}', '_blank'); }"
                                    class="hover:bg-paper cursor-pointer transition">
                                    <td class="py-2.5 px-3">
                                        <a href="{{ route('manuscripts.show', $ms) }}" class="font-semibold hover:text-cinnabar" onclick="event.stopPropagation()">{{ $ms->work?->primary_title ?? 'نسخۀ بی‌عنوان' }}</a>
                                    </td>
                                    <td class="py-2.5 px-3">{{ $ms->work?->effective_author?->name ?? $ms->work?->effective_author_name ?? '—' }}</td>
                                    <td class="py-2.5 px-3 font-semibold">@if($ms->shelfmark)<span dir="ltr">{{ Fa::digits($ms->shelfmark) }}</span><x-series-mark :manuscript="$ms" />@else بی‌شماره @endif</td>
                                    <td class="py-2.5 px-3">
                                        @if($ms->scribe_name)
                                            {{ $ms->scribe_name }}
                                        @elseif($ms->is_autograph)
                                            <span class="text-emerald-700 dark:text-emerald-300">مؤلف</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-3">{{ $ms->copy_date_raw ? Fa::digits($ms->copy_date_raw) : '—' }}</td>
                                    <td class="py-2.5 px-3">{{ $ms->script_names ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-8 text-center text-muted">نسخه‌ای در این مرکز ثبت نشده است.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($manuscripts->hasPages())
                    <div class="p-4 border-t border-line">{{ $manuscripts->links() }}</div>
                @endif
            </div>
        </x-ui.section>

        <!-- READINGS: adopted, alternative and superseded readings from researchers -->
        <x-suggest.readings />

        <!-- RESEARCH NOTES: published notes and the invitation to add one -->
        <x-suggest.notes type="library" />

        <!-- CONTRIBUTE: the empty sections above, as invitations in one box (step 14.132) -->
        <x-suggest.contribute type="library" />

        <!-- CORRECTION HISTORY: approved suggestions that changed this record (backlog item 39) -->
        <x-record-history :record="$library" />
    </div>

    <!-- SUGGESTION DESK: per-field and general correction suggestions -->
    <x-suggest.desk type="library" :record="$library" />

</div>
@endsection
