@props(['framed' => false])

{{-- A surface panel. «framed» adds the gold double ruling of a manuscript page --}}
<div {{ $attributes->class([
    'bg-surface border p-4 sm:p-5',
    'border-gold shadow-[inset_0_0_0_3px_var(--surface),inset_0_0_0_4px_var(--line)]' => $framed,
    'border-line' => ! $framed,
]) }}>
    {{ $slot }}
</div>
