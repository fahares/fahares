@props(['entry'])

{{-- The text printed at one place of the record (steps 13.2 and 13.4): every source alike, headed by its name and
     address, in the order of the sources' rank; a long text opens on request. --}}
@php
    use App\Support\Fa;
    $viewer = $entry->viewer();
    $entryText = \App\Models\SourceEntry::cleanText($entry->entry_text);
    $copyText = \App\Models\SourceEntry::cleanText($entry->copy_text);
    $long = mb_strlen((string) $entryText . $copyText) > 700;
@endphp
<section class="scroll-mt-24" data-source-text x-data="{ copied: false, open: {{ $long ? 'false' : 'true' }} }">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 mb-3">
        <h2 class="rubric text-lg">
            <span>{{ $entry->textTitle() }}</span>
            @if($entry->isAwaitingReview() && ($field = $entry->suggestionField()))
                <x-ui.machine-badge
                    text="این متن را هوشواره با تطبیق شماره‌ی نسخه از چاپ فهرست برداشته و هنوز به دست انسان بازبینی نشده است."
                    report-label="گزارش نشانی نادرست"
                    :report="['field' => $field]" />
            @endif
        </h2>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @if($place = $entry->placeLabel())
                <span class="text-muted">{{ $place }}</span>
            @endif
            @if($viewer)
                <x-ui.btn size="sm" x-on:click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($viewer) }})">دیدن برگه</x-ui.btn>
            @endif
            <x-ui.btn size="sm" @click="navigator.clipboard.writeText($refs.text.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)">
                <span x-show="!copied">رونوشت متن</span>
                <span x-show="copied" x-cloak class="text-emerald-600">رونوشت شد</span>
            </x-ui.btn>
        </div>
    </div>
    <x-source.stale-text :entry="$entry" />
    <div class="relative">
        <div x-ref="text" :class="open ? '' : 'max-h-64 overflow-hidden'" class="p-4 sm:p-5 bg-surface border border-line text-[13px] sm:text-sm text-ink leading-loose text-right">
            @if($entryText)
                <div class="whitespace-pre-line">{{ Fa::digits($entryText) }}</div>
            @endif
            @if($copyText)
                {{-- a copy's own line under the entry it belongs to --}}
                <div @class(['whitespace-pre-line', 'mt-3 pt-3 border-t border-dashed border-line font-semibold' => filled($entryText)])>{{ Fa::digits($copyText) }}</div>
            @endif
        </div>
        @if($long)
            <div x-show="! open" class="absolute inset-x-px bottom-px h-20 bg-gradient-to-t from-surface to-transparent flex items-end justify-center pb-2">
                <button type="button" @click="open = true" class="text-xs font-semibold text-cinnabar hover:underline cursor-pointer">نمایش کامل متن</button>
            </div>
        @endif
    </div>
</section>
