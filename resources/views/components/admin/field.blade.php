@props(['label', 'for' => null, 'hint' => null, 'error' => null])

{{-- One field of a panel form: its label, the control in the slot, a hint and the validation message of «error» --}}
<div {{ $attributes->class('space-y-1') }}>
    <label @if($for) for="{{ $for }}" @endif class="block font-bold text-ink">{{ $label }}</label>
    {{ $slot }}
    @if($hint)
        <p class="text-xs text-muted leading-6">{{ $hint }}</p>
    @endif
    @if($error)
        @error($error)
            <p class="text-xs font-bold text-cinnabar">{{ $message }}</p>
        @enderror
    @endif
</div>
