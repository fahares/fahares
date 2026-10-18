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
        <p class="text-xs font-bold text-cinnabar">کتابخانه و مرکز نگهداری</p>
        <h1 class="font-naskh text-[26px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">
            {{ $library->display_name }}
            <x-suggest.field name="full_name" class="ms-1 align-middle" />
        </h1>
        <p class="text-sm text-ink">
            {{ $library->city ?? 'ایران' }}@if($library->country && $library->country !== 'ایران')، {{ $library->country }}@endif
            <x-suggest.field name="country" class="ms-1" />
        </p>
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
        <x-suggest.toggle />
        <span class="text-xs text-muted">شناسه‌ی فهارس: <span class="font-mono text-ink font-bold">#{{ $library->id }}</span></span>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px] items-start">
        <aside class="space-y-4 lg:order-last lg:sticky lg:top-24">
            <x-ui.card :framed="true" class="text-center">
                <div class="font-naskh text-4xl font-bold text-cinnabar leading-tight tabular-nums">{{ Fa::num($library->manuscripts_count) }}</div>
                <div class="text-sm text-ink">نسخه‌ی ثبت‌شده</div>
                <x-ui.btn href="#manuscripts" variant="primary" class="w-full mt-4">فهرست نسخه‌ها</x-ui.btn>
            </x-ui.card>
        </aside>

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

            <!-- MANUSCRIPTS TABLE -->
            <x-ui.section id="manuscripts" title="نسخه‌ها" :count="$library->manuscripts_count">
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
                                            <a href="{{ route('manuscripts.show', $ms) }}" class="font-semibold hover:text-cinnabar" onclick="event.stopPropagation()">{{ $ms->work?->primary_title ?? 'نسخه‌ی بی‌عنوان' }}</a>
                                        </td>
                                        <td class="py-2.5 px-3">{{ $ms->work?->author?->name ?? $ms->work?->author_name ?? '—' }}</td>
                                        <td class="py-2.5 px-3 font-semibold">@if($ms->shelfmark)<span dir="ltr">{{ Fa::digits($ms->shelfmark) }}</span>@else بی‌شماره @endif</td>
                                        <td class="py-2.5 px-3">
                                            @if($ms->scribe_name)
                                                {{ $ms->scribe_name }}
                                            @elseif($ms->is_autograph)
                                                <span class="text-emerald-700 dark:text-emerald-300">مؤلف</span>
                                            @elseif($ms->is_bika)
                                                <span class="text-muted">بی‌کاتب</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 px-3">{{ $ms->copy_date_raw ? Fa::digits($ms->copy_date_raw) : ($ms->is_bita ? 'بی‌تاریخ' : '—') }}</td>
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
        </div>
    </div>

    <!-- SUGGESTION DESK: per-field and general correction suggestions -->
    <x-suggest.desk type="library" :record="$library" />

</div>
@endsection
