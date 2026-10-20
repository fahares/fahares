{{-- A library's official logo on a plate of fixed colour, so black and navy logos stay legible in
     dark mode and white ones (logo_on_dark) in light mode. Renders nothing when there is no logo.
     Sizes: sm (list slot), md, lg (library header), band (full-width head of a card). Beyond sm, a
     logo is drawn at most at half its pixel size, so a small file shows small but sharp on 2× screens. --}}
@props(['library', 'size' => 'md', 'decorative' => false])

@if($library?->logo_url)
    @php
        // [plate classes, wanted height, widest it may get] in CSS px
        [$box, $wantH, $maxW] = match ($size) {
            // Fixed width in lists, so the names beside the logos line up
            'sm' => ['h-10 w-16 p-1', null, null],
            'lg' => ['p-3', 96, 256],
            'band' => ['flex w-full px-4 py-5', 128, 240],
            default => ['p-2', 48, 160],
        };
        $style = null;
        if ($wantH && $library->logo_width && $library->logo_height) {
            $ratio = $library->logo_width / $library->logo_height;
            $width = max(16, (int) min($library->logoMaxHeight($wantH) * $ratio, $maxW, intdiv($library->logo_width, 2)));
            $style = "width: {$width}px; max-width: 100%; height: auto";
        }
        $img = match (true) {
            $size === 'sm' => 'h-auto max-h-full max-w-full',
            $style !== null => '',
            // Size unknown: fill the wanted height
            default => 'w-auto max-w-full ' . match ($size) { 'lg' => 'h-24', 'band' => 'h-32', default => 'h-12' },
        };
        $plate = $library->logo_on_dark
            ? 'bg-[#221C17] border-[#3A3029]'
            : 'bg-[#FBF8F1] border-[#E3D8C3]';
    @endphp
    <span {{ $attributes->class(["inline-flex shrink-0 items-center justify-center rounded-sm border $box $plate"]) }}>
        <img src="{{ $library->logo_url }}" alt="{{ $decorative ? '' : 'نشان ' . $library->display_name }}" loading="lazy" decoding="async"
             @if($library->logo_width && $library->logo_height) width="{{ $library->logo_width }}" height="{{ $library->logo_height }}" @endif
             @if($style) style="{{ $style }}" @endif
             class="object-contain {{ $img }}">
    </span>
@endif
