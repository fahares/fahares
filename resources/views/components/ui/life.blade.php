@props(['person'])

{{-- A person's life dates after the name: «(۸۱۷–۸۹۸ ق)». Nothing when the dates are unknown --}}
@if($person?->life_span)
    <span {{ $attributes->merge(['class' => 'text-muted font-normal text-[0.9em] whitespace-nowrap']) }}>({{ $person->life_span }})</span>
@endif
