@props(['entry', 'record' => null])

{{-- The text printed at one place of the record, under «متن مأخذ» (backlog item 60, steps 12.68 and 13.4): every source
     alike in x-ui.source-text, in the order of the sources' rank. A copy's own line in Fankha follows the work's part of
     the entry it stands in (clipped, as it can be long), with «...» for the copies before it. --}}
@php
    use App\Support\Fa;
    use App\Models\SourceEntry;
    $viewer = $entry->viewer();
    $entryText = SourceEntry::cleanText($entry->entry_text);
    $copyText = SourceEntry::cleanText($entry->copy_text);
    // Fankha prints the work's entry, then its copies: a copy's Fankha entry has its line alone, the work has the rest
    $workText = ! $entryText && $entry->is_primary && $record instanceof \App\Models\Manuscript
        ? $record->work?->sources()->primary()?->cleanEntryText() : null;
    $long = ! $workText && mb_strlen((string) $entryText . $copyText) > 700;
    $field = $entry->suggestionField();
    // the copy's row in the Fankha entry its record was made from
    $address = $entry->placeLabel() . ($entry->is_primary && $record instanceof \App\Models\Manuscript && $record->sequence_number ? Fa::digits('، ردیف ' . $record->sequence_number) : '')
        . ($entry->itemLabel() ? '، ' . $entry->itemLabel() : '');
    // a printed catalogue's or bibliography's text in Persian digits; Fankha's as the corpus has it
    $digits = fn (?string $t) => in_array($entry->source?->kind, [\App\Models\Source::KIND_LIBRARY_CATALOGUE, \App\Models\Source::KIND_BIBLIOGRAPHY], true) ? Fa::digits($t) : $t;
@endphp
<x-ui.source-text data-source-text data-machine-item="{{ $entry->reviewKey() }}" :title="$entry->textTitle()" :address="$address"
    :viewer="$viewer ? '$dispatch(\'open-catalog-viewer\', ' . \Illuminate\Support\Js::from($viewer) . ')' : null" :collapsible="$long"
    :link="$entry->sourceItem?->url()" :page="$entry->pageTextUrl()">
    @if($entry->isAwaitingReview() && ($field || $entry->source_item_id))
        <x-slot:badge>
            <x-ui.machine-badge
                :text="$entry->source_item_id ? $entry->machineNote() : 'این متن را هوشواره با تطبیق شماره‌ی نسخه از چاپ فهرست برداشته و هنوز به دست انسان بازبینی نشده است.'"
                report-label="گزارش نشانی نادرست"
                :report="$field ? ['field' => $field] : null" :review="$entry->badgeReview()" />
        </x-slot:badge>
    @endif
    <x-source.stale-text :entry="$entry" />
    @if($workText)
        <div x-data="{ more: false }" class="relative" data-fankha-work-text>
            <div :class="more ? '' : 'max-h-48 overflow-hidden'" class="whitespace-pre-line" data-copy>{{ $digits($workText) }}</div>
            <div x-show="! more" class="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-surface to-transparent flex items-end justify-center">
                <button type="button" @click="more = true" class="text-xs font-semibold text-cinnabar hover:underline cursor-pointer">نمایش کامل متن کتاب‌شناسی</button>
            </div>
        </div>
        @if(($record->sequence_number ?? 1) > 1)
            <div class="my-2 text-center text-muted tracking-widest" data-copy>...</div>
        @else
            <div class="my-3 border-t border-dashed border-line"></div>
        @endif
    @endif
    @if($entryText)
        <div class="whitespace-pre-line" data-copy>{{ $digits($entryText) }}</div>
    @endif
    @if($copyText)
        {{-- a copy's own line under the entry it belongs to --}}
        <div @class(['whitespace-pre-line', 'mt-3 pt-3 border-t border-dashed border-line font-semibold' => filled($entryText)]) data-copy>{{ $digits($copyText) }}</div>
    @endif
</x-ui.source-text>
