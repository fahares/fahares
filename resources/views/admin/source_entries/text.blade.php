@extends('layouts.admin')

@php
    use App\Support\Fa;
    $record = $entry->citable;
    $recordUrl = $record instanceof \App\Models\Manuscript ? route('manuscripts.show', $record) : route('works.show', $record);
    $viewer = $entry->viewer();
    $foundText = $found ? trim(implode("\n\n", array_filter([$found['entry_text'], $found['copy_text']]))) : null;
    $nav = fn (int $f, int $t) => route('admin.source-entries.text', ['entry' => $entry, 'from' => max(1, $f), 'to' => max(1, $t)]);
    // @js is not compiled inside a component's attributes, so the buttons that need it are plain buttons in x-ui.btn's classes
    $btn = 'inline-flex items-center justify-center gap-1.5 rounded-sm border font-semibold transition cursor-pointer whitespace-nowrap bg-surface border-line-strong text-ink hover:border-cinnabar hover:text-cinnabar';
@endphp

@section('title', 'متن مأخذ')
@section('page_title', 'متن مأخذ: ' . $entry->textTitle())
@section('breadcrumb')
    <a href="{{ $recordUrl }}" class="hover:text-cinnabar">{{ $record->work?->primary_title ?? $record->primary_title ?? 'رکورد' }}@if($record->shelfmark ?? null)، شماره‌ی <span dir="ltr">{{ Fa::digits($record->shelfmark) }}</span>@endif</a>
    <span>/</span>
    <span>{{ $entry->cardLabel($record) }}</span>
@endsection
@section('page_actions')
    @if($viewer)
        <button type="button" x-data @click="$dispatch('open-catalog-viewer', @js($viewer))" class="{{ $btn }} px-2.5 py-1 text-xs">دیدن برگه</button>
    @endif
    <x-ui.btn :href="$recordUrl" target="_blank" size="sm">صفحه در سایت ↗</x-ui.btn>
@endsection

@section('content')
<div class="max-w-4xl space-y-6 text-[13px]" x-data="{ entryText: @js((string) $entry->entry_text), copyText: @js((string) $entry->copy_text) }">
    <x-source.stale-text :entry="$entry" />

    @if($readable)
        <x-ui.card class="space-y-3">
            <h2 class="rubric text-base">متن در نشانی کنونی</h2>
            @if($found)
                <p class="text-muted">صفحه‌های مأخذ در نشانی کنونی این متن را نشان می‌دهند:</p>
                <div class="p-3 border border-emerald-600/40 bg-paper text-ink whitespace-pre-line leading-7 max-h-96 overflow-y-auto">{{ Fa::digits($foundText) }}</div>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.source-entries.update', $entry) }}">
                        @csrf
                        <input type="hidden" name="mode" value="found">
                        <x-ui.btn type="submit" variant="primary">همین متن را بگذار</x-ui.btn>
                    </form>
                    <button type="button" @click="entryText = @js((string) $found['entry_text']); copyText = @js((string) $found['copy_text'])" class="{{ $btn }} px-3.5 py-2 text-xs sm:text-sm">به ویرایش دستی ببر</button>
                </div>
            @else
                <x-admin.notice tone="warn">این نسخه در صفحه‌های مأخذ در نشانی کنونی پیدا نشد. متن را از صفحه‌های زیر یا از تصویر برگه بنویسید.</x-admin.notice>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card>
        <form method="POST" action="{{ route('admin.source-entries.update', $entry) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="mode" value="manual">
            <div>
                <h2 class="rubric text-base">ویرایش دستی</h2>
                <p class="text-xs text-muted mt-1">متنی که دستی نوشته شود، دیگر با برداشت خودکار جایگزین نمی‌شود.</p>
            </div>
            <x-admin.field label="متن مدخل" for="entry_text">
                <textarea name="entry_text" id="entry_text" x-model="entryText" rows="10" class="admin-field w-full leading-7"></textarea>
            </x-admin.field>
            <x-admin.field label="سطر نسخه" for="copy_text">
                <textarea name="copy_text" id="copy_text" x-model="copyText" rows="4" class="admin-field w-full leading-7"></textarea>
            </x-admin.field>
            <x-admin.form-actions :cancel="$recordUrl" submit="ذخیره‌ی متن دستی" />
        </form>
    </x-ui.card>

    @if($entry->source_volume_id)
        <section class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="rubric text-base">متن صفحه‌های مأخذ <small class="font-sans text-xs text-muted font-normal">{{ Fa::digits("ص {$from} تا {$to}") }}</small></h2>
                <div class="flex flex-wrap gap-1.5">
                    <x-ui.btn :href="$nav($from - 1, $to)" size="sm">صفحه‌ی پیش</x-ui.btn>
                    <x-ui.btn :href="$nav($from, $to + 1)" size="sm">صفحه‌ی بعد</x-ui.btn>
                    @if($to > $from)
                        <x-ui.btn :href="$nav($from, $to - 1)" size="sm" variant="ghost">یک صفحه کمتر</x-ui.btn>
                    @endif
                </div>
            </div>
            @forelse($pages as $page => $text)
                <x-ui.card data-source-page="{{ $page }}">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="font-bold text-ink">{{ Fa::digits("ص {$page}") }}</span>
                        @if($viewer)
                            <button type="button" x-data @click="$dispatch('open-catalog-viewer', @js(['page' => $page] + $viewer))" class="text-xs text-cinnabar hover:underline cursor-pointer">دیدن برگه</button>
                        @endif
                    </div>
                    <div class="whitespace-pre-line leading-7 text-ink">{{ Fa::digits($text) }}</div>
                </x-ui.card>
            @empty
                <x-admin.notice>متن صفحه‌های این جلد در سایت نیست. متن را از تصویر برگه بنویسید.</x-admin.notice>
            @endforelse
        </section>
    @endif
</div>
<x-catalog-viewer />
@endsection
