@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 5a. How far the copies stand on their libraries' own printed catalogues (step 14.256) --}}
@if($coverage ?? null)
<x-ui.section title="نسخه‌هایی که بر فهرست چاپی کتابخانه استوارند" id="firsthand">
    <x-ui.card>
        <p class="text-sm text-ink leading-loose" data-firsthand-total>
            شناسه‌های اصلی <span class="font-bold tabular-nums">{{ Fa::num($coverage['complete']) }}</span> نسخه
            ({{ $pct(\App\Support\FirsthandCoverage::share($coverage['complete'], $coverage['copies'])) }} همۀ نسخه‌های فهارس)
            با فهرست چاپی کتابخانۀ خودشان سنجیده و تأیید شده است.
            تاکنون {{ Fa::num($coverage['read']) }} نسخه با فهرست چاپی کتابخانه‌شان سنجیده شده است.
        </p>
        <p class="text-xs text-muted mt-4 mb-3">سهم این نسخه‌ها از همۀ نسخه‌های هر کتابخانه:</p>
        <ul class="space-y-2.5 text-sm">
            @foreach($coverage['libraries'] as $row)
                <li data-firsthand-library="{{ $row['id'] }}">
                    <span class="flex items-baseline gap-2">
                        <a href="{{ route('libraries.show', $row['key']) }}" class="text-ink hover:text-cinnabar truncate min-w-0">{{ $row['name'] }}</a>
                        <span class="text-xs text-muted">{{ $row['city'] }}</span>
                        <span class="leader" aria-hidden="true"></span>
                        <span class="text-xs text-muted tabular-nums whitespace-nowrap" title="{{ Fa::num($row['complete']) }} نسخه از {{ Fa::num($row['copies']) }}؛ {{ Fa::num($row['read']) }} نسخه سنجیده شده">{{ $pct($row['share']) }}</span>
                    </span>
                    <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                        <span class="block h-full bg-gold/80 rounded-full" style="width: max(2px, {{ $row['share'] }}%)"></span>
                    </span>
                </li>
            @endforeach
            @if($coverage['more'] > 0)
                <li class="text-xs text-muted">و {{ Fa::num($coverage['more']) }} کتابخانۀ دیگر.</li>
            @endif
        </ul>
        <p class="mt-4 text-xs text-muted leading-relaxed">
            شناسه‌های اصلی یک نسخه شمارۀ آن در کتابخانه، شمار برگ‌ها، تاریخ کتابت و نام کاتب است.
            نسخه‌ای در این شمار آمده که وصفش در فهرست چاپی کتابخانه خوانده شده و هر یک از این شناسه‌ها که در فهارس دارد، با آن وصف یکی است یا اختلافش بررسی و روشن شده است.
            فهرست‌های چاپی کتابخانه‌ها یکی‌یکی خوانده می‌شوند و این ارقام هر شب از نو سنجیده می‌شود@if($coverage['measured_at'])؛ آخرین بار {{ Jalali::format(\Illuminate\Support\Carbon::parse($coverage['measured_at']), 'long') }}@endif.
        </p>
        @include('insights.data-table', ['tables' => \App\Support\InsightsTables::firsthand($coverage)])
    </x-ui.card>
</x-ui.section>
@endif
