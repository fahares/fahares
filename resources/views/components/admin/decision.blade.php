{{-- The review desk's decision panel (step 14.72): three choices, and only the chosen one's form open --}}
@props(['suggestion', 'approveLabel' => 'تأیید', 'rejectLabel' => 'رد'])
@php
    $initial = $errors->has('body') ? 'ask' : 'approve';
    // the chosen one is styled by its aria-pressed, which Alpine keeps current
    $choices = ['approve' => [$approveLabel, 'aria-pressed:border-emerald-600 aria-pressed:bg-emerald-600/10 aria-pressed:text-emerald-800 dark:aria-pressed:text-emerald-300'],
        'reject' => [$rejectLabel, 'aria-pressed:border-cinnabar aria-pressed:bg-cinnabar/10 aria-pressed:text-cinnabar'],
        'ask' => ['پرسش', 'aria-pressed:border-gold aria-pressed:bg-gold/10 aria-pressed:text-gold']];
@endphp
{{-- a plain element, not <x-ui.card>: Blade does not compile @js inside a component's attributes --}}
<div {{ $attributes->class('bg-surface border border-line-strong p-4 sm:p-5 space-y-3') }} x-data="{ choice: @js($initial) }" data-decision>
    <h3 class="font-naskh text-lg font-bold text-ink">تصمیم شما</h3>
    <div class="grid grid-cols-3 gap-1.5" role="group" aria-label="نوع تصمیم">
        @foreach($choices as $key => [$label, $on])
            <button type="button" @click="choice = @js($key)" :aria-pressed="choice === @js($key) ? 'true' : 'false'" aria-pressed="{{ $key === $initial ? 'true' : 'false' }}"
                class="py-2 px-1 rounded-sm border border-line-strong bg-paper text-ink hover:border-ink font-bold transition cursor-pointer {{ $on }}"
                data-choice="{{ $key }}">{{ $label }}</button>
        @endforeach
    </div>
    <div x-show="choice === 'approve'" @if($initial !== 'approve') x-cloak @endif class="space-y-3" data-pane="approve">{{ $approve }}</div>
    <div x-show="choice === 'reject'" @if($initial !== 'reject') x-cloak @endif class="space-y-3" data-pane="reject">{{ $reject }}</div>
    <div x-show="choice === 'ask'" @if($initial !== 'ask') x-cloak @endif class="space-y-3" data-pane="ask">
        @include('admin.suggestions.partials.ask-form', ['pending' => true])
    </div>
</div>
