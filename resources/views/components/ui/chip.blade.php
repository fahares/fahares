@props(['href' => null, 'tone' => 'default'])

@php
    $classes = [
        'inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full border text-xs leading-5',
        match ($tone) {
            'accent' => 'border-cinnabar/40 text-cinnabar bg-cinnabar/5',
            'good' => 'border-emerald-600/40 text-emerald-700 dark:text-emerald-300 bg-emerald-600/5',
            default => 'border-line bg-surface text-ink',
        },
        $href ? 'hover:border-cinnabar hover:text-cinnabar transition' : '',
    ];
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <span {{ $attributes->class($classes) }}>{{ $slot }}</span>
@endif
