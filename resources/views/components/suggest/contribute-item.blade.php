@props(['title', 'hint', 'label', 'payload'])
{{-- One empty section of the record, pushed into <x-suggest.contribute> instead of showing an empty heading (step 14.132) --}}
@push('contribute')
    <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1.5 py-3">
        <div class="min-w-0 flex-1 basis-80 space-y-0.5">
            <p class="text-sm font-bold text-ink">{{ $title }}</p>
            <p class="text-xs text-muted leading-relaxed max-w-[72ch]">{{ $hint }}</p>
        </div>
        <button type="button" @click="$dispatch('suggest-field', @js($payload))"
            class="shrink-0 text-xs font-bold text-cinnabar hover:underline cursor-pointer">+ {{ $label }}</button>
    </li>
@endpush
