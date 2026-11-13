@props(['source', 'text' => null, 'open' => false])

{{-- «راهنمای نشانه‌ها» (step 14.159): the signs a source prints its entries with, as the source itself explains them
     (Source::legend). Under a text, only the signs found in that text; on the source's page, all of them. A source
     with no list shows nothing. --}}
@php $legend = $source?->legend($text); @endphp
@if($legend)
    <details {{ $attributes->merge(['class' => 'group text-xs', 'data-source-legend' => $source->code]) }} @if($open) open @endif>
        <summary class="inline-flex items-center gap-1 cursor-pointer select-none text-muted hover:text-cinnabar transition list-none [&::-webkit-details-marker]:hidden">
            <svg class="w-3 h-3 transition-transform group-open:rotate-90 rtl:-scale-x-100" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="font-semibold">راهنمای نشانه‌ها{{ $text !== null ? 'ی این متن' : '' }}</span>
            <span>({{ \App\Support\Fa::digits(count($legend['items'])) }})</span>
        </summary>
        <dl class="mt-2 px-4 py-3 border border-line bg-surface grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 leading-relaxed">
            @foreach($legend['items'] as $item)
                <dt class="font-bold text-ink whitespace-nowrap">{{ $item['sign'] }}</dt>
                <dd class="m-0 text-ink">{{ $item['meaning'] }}</dd>
            @endforeach
            @if(! empty($legend['origin']))
                <div class="col-span-2 pt-1.5 mt-1 border-t border-dashed border-line text-muted">برگرفته از {{ $source->title }}، {{ $legend['origin'] }}</div>
            @endif
        </dl>
    </details>
@endif
