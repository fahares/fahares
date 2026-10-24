@props(['tone' => 'info'])

{{-- A boxed note inside a panel page, in the tones of the frame's flash messages --}}
<div {{ $attributes->class([
    'px-4 py-3 bg-surface border border-s-4 leading-7',
    match ($tone) {
        'good' => 'border-emerald-600/40 text-emerald-800 dark:text-emerald-300',
        'error' => 'border-cinnabar/50 text-cinnabar font-bold',
        'warn' => 'border-amber-600/50 text-amber-800 dark:text-amber-300',
        default => 'border-line-strong text-ink',
    },
]) }}>{{ $slot }}</div>
