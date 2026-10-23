@props([
    // The user whose picture is shown; null shows the neutral mark (an unnamed researcher)
    'user' => null,
    // The name the initial is taken from, when it differs from the account name (credit_name)
    'name' => null,
    // sm 24px, md 32px, lg 48px, xl 96px
    'size' => 'md',
    // «staff»: the seal of «ویراستار فهارس» instead of a person
    'kind' => null,
])
@php
    $box = match ($size) {
        'sm' => 'w-6 h-6 text-[11px]',
        'lg' => 'w-12 h-12 text-lg',
        'xl' => 'w-24 h-24 text-4xl',
        default => 'w-8 h-8 text-sm',
    };
    $name = trim((string) ($name ?? $user?->name));
    $initial = $name !== '' ? mb_substr(preg_replace('/^(سید|سیده|شیخ|دکتر|حاج|آیت‌الله)\s+/u', '', $name), 0, 1) : '';
    // The colour of the initial follows the user id, in the site's palette
    $tones = [
        'bg-cinnabar/15 text-cinnabar',
        'bg-gold/20 text-gold',
        'bg-emerald-600/15 text-emerald-700 dark:text-emerald-300',
        'bg-sky-600/15 text-sky-700 dark:text-sky-300',
        'bg-violet-600/15 text-violet-700 dark:text-violet-300',
        'bg-ink/10 text-ink',
    ];
    $tone = $tones[($user?->id ?? 0) % count($tones)];
    $base = 'inline-flex items-center justify-center shrink-0 rounded-full overflow-hidden font-bold leading-none select-none';
@endphp
{{-- A user's picture (backlog item 66): the uploaded or Google copy, or the initial of the name on a colour --}}
@if($user?->avatar_url)
    <img src="{{ $user->avatar_url }}" alt="" loading="lazy" width="96" height="96" {{ $attributes->class([$base, $box, 'object-cover border border-line']) }}>
@elseif($user && $initial !== '')
    <span aria-hidden="true" {{ $attributes->class([$base, $box, $tone]) }}>{{ $initial }}</span>
@elseif($kind === 'staff')
    <span aria-hidden="true" {{ $attributes->class([$base, $box, 'bg-gold/15 text-gold border border-gold/40']) }}>
        <svg class="w-[55%] h-[55%]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
    </span>
@else
    <span aria-hidden="true" {{ $attributes->class([$base, $box, 'bg-paper text-muted border border-line']) }}>
        <svg class="w-[55%] h-[55%]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM4 21a8 8 0 0116 0"/></svg>
    </span>
@endif
