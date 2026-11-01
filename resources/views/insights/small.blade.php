{{-- A warning on a chart of a scope that rests on few records (below small_sample): $n records, $what --}}
@if(! $scope->isEmpty() && $n < config('insights.small_sample'))
    <p class="mb-4 inline-flex items-center gap-1.5 bg-gold/15 border border-gold/40 px-2.5 py-1 text-xs text-ink">
        <span aria-hidden="true">⚠</span>
        نمونه‌ی کوچک: تنها {{ \App\Support\Fa::num($n) }} {{ $what }}؛ این نمودار را با احتیاط بخوانید.
    </p>
@endif
