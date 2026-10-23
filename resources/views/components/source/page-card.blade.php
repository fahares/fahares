@props(['entry', 'record'])

{{-- One place of the record in a source on its side card (step 13.4): the source and address, the page image when the
     volume has one, and the address field. Every source alike; their order is their rank. --}}
@php
    $label = $entry->cardLabel($record);
    $viewer = $entry->viewer();
    $field = $entry->suggestionField();
    $name = $entry->sourceName();
@endphp
<div class="space-y-2 pb-4 border-b border-line last:border-b-0 last:pb-0" data-source-card>
    <div class="flex items-start gap-1">
        <div class="min-w-0">
            <div class="text-sm font-bold text-ink leading-6">{{ $label }}</div>
            @if($entry->origin === \App\Models\SourceEntry::ORIGIN_FANKHA_CITATION)
                <div class="text-[11px] text-muted">به نشانی فنخا</div>
            @endif
        </div>
        @if($entry->isAwaitingReview() && $field)
            <x-ui.machine-badge class="shrink-0 mt-0.5"
                text="این نشانی را هوشواره با تطبیق شماره‌ی نسخه استخراج کرده و هنوز به دست انسان بازبینی نشده است."
                report-label="گزارش نشانی نادرست"
                :report="['field' => $field]" />
        @endif
        @if($field)<x-suggest.field :name="$field" class="shrink-0" />@endif
    </div>
    @if($viewer)
        <button type="button" @click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($viewer) }})" class="block w-full group cursor-pointer" title="دیدن برگه در {{ $name }}">
            <div class="aspect-[16/9] lg:aspect-[4/3] overflow-hidden border border-line bg-white">
                <img src="{{ $entry->thumbUrl() }}" alt="برگه‌ی {{ $label }}" loading="lazy" class="w-full h-full object-cover object-top group-hover:opacity-90 transition" onerror="this.closest('button').remove()">
            </div>
            <span class="block mt-1.5 text-xs font-semibold text-cinnabar">دیدن برگه در {{ $name }}</span>
        </button>
    @endif
</div>
