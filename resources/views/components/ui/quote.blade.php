@props(['label', 'field' => null])

{{-- Incipit or explicit: catalog text in Naskh with the gold double rule, never slanted --}}
<figure {{ $attributes->merge(['class' => 'm-0 border-s-4 border-double border-gold ps-4']) }}>
    <figcaption class="text-xs font-bold text-cinnabar mb-1">
        {{ $label }}
        @if($field)<x-suggest.field :name="$field" class="ms-1" />@endif
    </figcaption>
    <blockquote class="m-0 font-naskh text-[15px] sm:text-base leading-[2.1] text-ink whitespace-pre-line">{{ $slot }}</blockquote>
</figure>
