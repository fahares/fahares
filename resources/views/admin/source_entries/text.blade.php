@extends('layouts.admin')

@section('title', 'متن مأخذ')
@section('page_title', 'متن مأخذ: ' . $entry->textTitle())
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">متن مأخذ</span>
@endsection

@section('content')
@php
    use App\Support\Fa;
    $record = $entry->citable;
    $recordUrl = $record instanceof \App\Models\Manuscript ? route('manuscripts.show', $record) : route('works.show', $record);
    $viewer = $entry->viewer();
    $foundText = $found ? trim(implode("\n\n", array_filter([$found['entry_text'], $found['copy_text']]))) : null;
    $nav = fn (int $f, int $t) => route('admin.source-entries.text', ['entry' => $entry, 'from' => max(1, $f), 'to' => max(1, $t)]);
@endphp
<div class="space-y-6 text-xs" x-data="{ entryText: @js((string) $entry->entry_text), copyText: @js((string) $entry->copy_text) }">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ $recordUrl }}" class="font-bold text-ink hover:text-cinnabar">{{ $record->work?->primary_title ?? $record->primary_title ?? 'رکورد' }}@if($record->shelfmark ?? null)، شماره‌ی <span dir="ltr">{{ Fa::digits($record->shelfmark) }}</span>@endif</a>
        <span class="text-stone-500">{{ $entry->cardLabel($record) }}</span>
        @if($viewer)
            <button type="button" @click="$dispatch('open-catalog-viewer', @js($viewer))" class="px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 font-bold text-ink hover:bg-cinnabar hover:text-white transition cursor-pointer">دیدن برگه</button>
        @endif
        <x-source.stale-text :entry="$entry" />
    </div>

    @if($readable)
        <div class="space-y-2 p-4 rounded-xl border border-line bg-surface">
            <div class="font-bold text-ink">متنی که صفحه‌های مأخذ در نشانی کنونی نشان می‌دهند</div>
            @if($found)
                <div class="p-3 rounded-lg border border-emerald-600/40 bg-emerald-50/50 dark:bg-emerald-950/20 text-ink whitespace-pre-line leading-7 max-h-96 overflow-y-auto">{{ Fa::digits($foundText) }}</div>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.source-entries.update', $entry) }}">
                        @csrf
                        <input type="hidden" name="mode" value="found">
                        <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 text-white font-bold hover:bg-emerald-700 transition cursor-pointer">همین متن را بگذار</button>
                    </form>
                    <button type="button" @click="entryText = @js((string) $found['entry_text']); copyText = @js((string) $found['copy_text'])" class="px-4 py-2 rounded-xl border border-line-strong font-bold text-ink hover:text-cinnabar transition cursor-pointer">به ویرایش دستی ببر</button>
                </div>
            @else
                <p class="text-amber-700 dark:text-amber-400">این نسخه در صفحه‌های مأخذ در نشانی کنونی پیدا نشد. متن را از صفحه‌های زیر یا از تصویر برگه بنویسید.</p>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('admin.source-entries.update', $entry) }}" class="space-y-3 p-4 rounded-xl border border-line bg-surface">
        @csrf
        <input type="hidden" name="mode" value="manual">
        <div class="font-bold text-ink">ویرایش دستی</div>
        <p class="text-stone-400">متنی که دستی نوشته شود، دیگر با برداشت خودکار جایگزین نمی‌شود.</p>
        <label class="block space-y-1">
            <span class="font-bold text-stone-500">متن مدخل</span>
            <textarea name="entry_text" x-model="entryText" rows="10" class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink leading-7"></textarea>
        </label>
        <label class="block space-y-1">
            <span class="font-bold text-stone-500">سطر نسخه</span>
            <textarea name="copy_text" x-model="copyText" rows="4" class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink leading-7"></textarea>
        </label>
        <button type="submit" class="px-4 py-2 rounded-xl bg-stone-800 text-white font-bold hover:bg-cinnabar transition cursor-pointer">ذخیره‌ی متن دستی</button>
    </form>

    @if($entry->source_volume_id)
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="font-bold text-ink">متن صفحه‌های مأخذ{{ Fa::digits(" (ص {$from} تا {$to})") }}</div>
                <a href="{{ $nav($from - 1, $to) }}" class="px-3 py-1 rounded-lg border border-line-strong hover:text-cinnabar">صفحه‌ی پیش</a>
                <a href="{{ $nav($from, $to + 1) }}" class="px-3 py-1 rounded-lg border border-line-strong hover:text-cinnabar">صفحه‌ی بعد</a>
                @if($to > $from)
                    <a href="{{ $nav($from, $to - 1) }}" class="px-3 py-1 rounded-lg border border-line-strong hover:text-cinnabar">یک صفحه کمتر</a>
                @endif
            </div>
            @forelse($pages as $page => $text)
                <div class="p-3 rounded-lg border border-line bg-paper" data-source-page="{{ $page }}">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="font-bold text-ink">{{ Fa::digits("ص {$page}") }}</span>
                        @if($viewer)
                            <button type="button" @click="$dispatch('open-catalog-viewer', @js(['page' => $page] + $viewer))" class="text-cinnabar hover:underline cursor-pointer">دیدن برگه</button>
                        @endif
                    </div>
                    <div class="whitespace-pre-line leading-7 text-ink">{{ Fa::digits($text) }}</div>
                </div>
            @empty
                <p class="text-stone-500">متن صفحه‌های این جلد در سایت نیست. متن را از تصویر برگه بنویسید.</p>
            @endforelse
        </div>
    @endif
</div>
<x-catalog-viewer />
@endsection
