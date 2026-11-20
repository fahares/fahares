@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2c. Subjects across the centuries --}}
<x-ui.section title="موضوع‌ها در گذر سده‌ها" id="subjects">
    <x-ui.card>
        @php $sg = $data['subjects']; @endphp
        @if($sg['counted'] < $min || count($sg['rows']) < 2)
            @include('insights.thin', ['n' => $sg['counted'], 'what' => count($sg['rows']) < 2 && $sg['counted'] >= $min ? 'موضوع جدا' : 'نسخۀ موضوع‌دار و تاریخ‌دار'])
        @else
            @include('insights.small', ['n' => $sg['counted'], 'what' => 'نسخۀ موضوع‌دار و تاریخ‌دار'])
            <p class="text-xs text-muted mb-4">
                نسخه‌های هر {{ $sg['children'] ? 'زیرموضوع' : 'موضوع اصلی' }} در هر سده: شمارشان، یا سهمشان از نسخه‌های آن سده (برای نمونه، از نسخه‌های سدۀ ۱۱ چند درصد فقه است). روی هر خانه بزنید تا نسخه‌های آن در جست‌وجو باز شود.
            </p>
            @include('insights.century-grid', ['grid' => $sg, 'label' => 'سهم موضوع‌ها در نسخه‌های هر سده',
                'href' => fn ($id, $c) => $search('manuscripts', ['subject_id' => $id, 'century' => $c])])
            <p class="mt-4 text-xs text-muted leading-relaxed">
                بر پایۀ {{ Fa::num($sg['counted']) }} نسخه که اثرشان موضوع دارد و تاریخ کتابتشان در یک سده است؛ نسخه‌ای که بازۀ تاریخش دو سده را می‌گیرد در این جدول نیامده است.
                {{ Fa::num(count($sg['rows'])) }} {{ $sg['children'] ? 'زیرموضوع' : 'موضوع' }} پرنسخه از {{ Fa::num($sg['kinds']) }} آمده است.
                اثری که چند موضوع دارد در هر موضوعش شمرده شده است، پس جمع یک ستون می‌تواند از ۱۰۰ بیشتر شود.
            </p>
            @include('insights.explain', ['note' => $notes['subjects'], 'tables' => $tables['subjects']])
        @endif
    </x-ui.card>
</x-ui.section>
