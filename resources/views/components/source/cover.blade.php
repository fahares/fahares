@props(['source', 'size' => 'card', 'title' => null])

{{-- A source's cover (step 13.84): its picture from mtif.org, or a cloth binding with its title stamped in gold, so a
     shelf of sources looks like books whether a cover was found or not. --}}
@php $url = $source->coverUrl(); @endphp
<div {{ $attributes->class(['relative aspect-[2/3] overflow-hidden bg-paper', 'shadow-[0_12px_28px_-14px_rgba(74,52,24,0.55)]']) }}>
    @if($url)
        <img src="{{ $url }}" alt="جلد {{ $source->title }}" loading="lazy" class="absolute inset-0 w-full h-full object-cover" data-source-cover>
    @else
        <div class="absolute inset-0 grid place-items-center p-[12%] bg-[#5b3a29] dark:bg-[#3a2619] text-center" data-source-cover-plain>
            <div class="absolute inset-[6%] border border-[#c9a45c]/60"></div>
            <span @class(['relative font-naskh font-bold text-[#e9d3a0] leading-relaxed text-balance', 'text-sm' => $size === 'card', 'text-xl' => $size === 'large'])>{{ $title ?? ($source->short_name ?: $source->title) }}</span>
        </div>
    @endif
</div>
