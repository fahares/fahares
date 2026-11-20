@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2d. Features of the copies across the centuries --}}
<x-ui.section title="ویژگی‌های نسخه‌ها در گذر سده‌ها" id="features">
    <x-ui.card>
        @php $fg = $data['features']; @endphp
        @if($fg['counted'] < $min)
            @include('insights.thin', ['n' => $fg['counted'], 'what' => 'نسخۀ تاریخ‌دار'])
        @else
            @include('insights.small', ['n' => $fg['counted'], 'what' => 'نسخۀ تاریخ‌دار'])
            <p class="text-xs text-muted mb-4">نسخه‌های مذهّب، تصحیح‌شده، مقابله‌شده، حاشیه‌دار و به خط مؤلف در هر سده: شمارشان، یا سهمشان از نسخه‌های آن سده.</p>
            @include('insights.century-grid', ['grid' => $fg, 'label' => 'سهم ویژگی‌های نسخه در هر سده',
                'href' => fn ($flag, $c) => $search('manuscripts', ['flags' => [$flag], 'century' => $c])])
            <p class="mt-4 text-xs text-muted leading-relaxed">
                بر پایۀ {{ Fa::num($fg['counted']) }} نسخه که تاریخ کتابتشان در یک سده است.
                این ویژگی‌ها همان‌اند که فهرست‌نگار یاد کرده است؛ یاد نشدن یک ویژگی همیشه به معنای نبودن آن نیست، و فهرست‌ها در یاد کردن آن یکسان نیستند.
            </p>
            @include('insights.explain', ['note' => $notes['features'], 'tables' => $tables['features']])
        @endif
    </x-ui.card>
</x-ui.section>
