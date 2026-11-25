@props(['group'])

{{-- The work in a printed catalogue of its copies that introduces it (step 14.378, App\Support\WorkCatalogues): the page
     of its first entry there, and the other pages its copies are on, each opening the catalogue viewer. --}}
@php
    use App\Support\Fa;
    $entry = $group['entry'];
    $vp = $entry->viewer();
    $place = fn ($e) => Fa::digits(($e->sourceVolume?->volume_number ? 'ج ' . $e->sourceVolume->volume_number . '، ' : '') . 'ص ' . $e->page_start);
    $shown = 12;
@endphp
<div class="space-y-2 pb-4 border-b border-line" data-viewer-card data-work-catalogue="{{ $entry->source?->code }}">
    <div class="text-sm font-bold text-ink">{{ $vp['catalogName'] }}، {{ $place($entry) }}</div>
    <button type="button" @click="$dispatch('open-catalog-viewer', @js($vp))" class="block w-full group cursor-pointer" title="دیدن برگه در {{ $vp['catalogName'] }}">
        <div class="aspect-[16/9] lg:aspect-[4/3] overflow-hidden border border-line bg-white">
            <img src="{{ $entry->thumbUrl() }}" alt="برگۀ {{ $place($entry) }} در {{ $vp['catalogName'] }}" loading="lazy" class="w-full h-full object-cover object-top group-hover:opacity-90 transition" onerror="this.closest('[data-viewer-card]').remove()">
        </div>
        <span class="block mt-1.5 text-xs font-semibold text-cinnabar">دیدن برگه در {{ $vp['catalogName'] }}</span>
    </button>
    @if($text = $entry->pageTextUrl())
        <a href="{{ $text }}" class="inline-block text-xs text-muted hover:text-cinnabar" data-page-text>متن صفحه ←</a>
    @endif
    <div class="text-xs text-muted leading-6">
        {{ Fa::num($group['copies']) }} نسخه از این اثر در این فهرست
        @if($group['others']->isNotEmpty())
            <span class="block" data-work-catalogue-pages>
                صفحه‌های دیگر:
                @foreach($group['others']->take($shown) as $other)
                    <button type="button" @click="$dispatch('open-catalog-viewer', @js($other->viewer()))" class="hover:text-cinnabar underline decoration-dotted underline-offset-4">{{ $place($other) }}</button>@if(! $loop->last)، @endif
                @endforeach
                @if($group['others']->count() > $shown)
                    و {{ Fa::num($group['others']->count() - $shown) }} صفحۀ دیگر
                @endif
            </span>
        @endif
    </div>
</div>
