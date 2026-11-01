{{-- A chart of a scope with too little data to draw (min_sample): $n records, $what («نسخه‌ی تاریخ‌دار» …) --}}
<p class="text-sm text-muted py-4 text-center">
    @if($n > 0)
        در این دامنه تنها {{ \App\Support\Fa::num($n) }} {{ $what }} هست که برای این نمودار بسنده نیست.
    @else
        در این دامنه {{ $what }} نیست.
    @endif
</p>
