@props(['href' => null, 'variant' => 'default', 'size' => 'md'])

@php
    $classes = [
        'inline-flex items-center justify-center gap-1.5 rounded-sm border font-semibold transition cursor-pointer whitespace-nowrap',
        $size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3.5 py-2 text-xs sm:text-sm',
        match ($variant) {
            'primary' => 'bg-cinnabar border-cinnabar text-on-cinnabar hover:opacity-90',
            'ghost' => 'border-transparent text-muted hover:text-cinnabar',
            default => 'bg-surface border-line-strong text-ink hover:border-cinnabar hover:text-cinnabar',
        },
    ];
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
