{{-- The doubtful share of a chart (Insights::centuryLayers): $layers, $unit («نسخه» …), $what («تاریخ کتابتشان» …) --}}
@if($layers['doubtful'] > 0)
    {{ \App\Support\Fa::num($layers['doubtful']) }} {{ $unit }} ({{ \App\Support\Fa::digits(str_replace('.', '٫', (string) $layers['doubtful_share'])) }}٪) {{ $what }} را با تردید («محتملاً»، «؟») دارند و کم‌رنگ آمده‌اند.
@endif
