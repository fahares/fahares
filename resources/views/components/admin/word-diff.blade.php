{{-- The ops of App\Support\WordDiff as text: additions green, drops struck, left-out stretches as «…» (step 14.72).
     Built in one string: whitespace between Blade directives would show as spaces inside the words. --}}
@props(['ops'])
@php
    $html = '';
    foreach ($ops as [$op, $text]) {
        $html .= match ($op) {
            '+' => '<ins class="no-underline bg-emerald-600/15 text-emerald-900 dark:text-emerald-200 px-0.5 rounded-sm" data-diff="ins">' . e($text) . '</ins>',
            '-' => '<del class="line-through decoration-cinnabar bg-cinnabar/10 px-0.5 rounded-sm" data-diff="del">' . e($text) . '</del>',
            '…' => '<span class="text-muted px-1" data-diff="gap" title="بخش بی‌تغییر">…</span>',
            default => e($text),
        };
    }
@endphp
<span {{ $attributes->merge(['class' => 'whitespace-pre-line']) }}>{!! $html !!}</span>
