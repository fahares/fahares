{{-- A library's official logo on a plate of fixed colour, so black and navy logos stay legible in
     dark mode and white ones (logo_on_dark) in light mode. Renders nothing when there is no logo. --}}
@props(['library', 'size' => 'md', 'decorative' => false])

@if($library?->logo_url)
    @php
        [$box, $img] = match ($size) {
            // Fixed width in lists, so the names beside the logos line up
            'sm' => ['h-10 w-16 p-1', 'h-auto max-h-full max-w-full'],
            'lg' => ['h-28 p-3', 'h-full w-auto max-w-64'],
            default => ['h-16 p-2', 'h-full w-auto max-w-40'],
        };
        $plate = $library->logo_on_dark
            ? 'bg-[#221C17] border-[#3A3029]'
            : 'bg-[#FBF8F1] border-[#E3D8C3]';
    @endphp
    <span {{ $attributes->class(["inline-flex shrink-0 items-center justify-center rounded-sm border $box $plate"]) }}>
        <img src="{{ $library->logo_url }}" alt="{{ $decorative ? '' : 'نشان ' . $library->display_name }}" loading="lazy" decoding="async"
             class="object-contain {{ $img }}">
    </span>
@endif
