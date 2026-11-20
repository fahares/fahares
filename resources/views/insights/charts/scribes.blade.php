@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2e. Scribes and libraries --}}
@php
    $sb = $data['scribes'];
    $lb = $data['libraries'];
    // Known years, or else the centuries of the copies dated by their century alone
    $years = fn (array $row) => $row['from'] !== null
        ? ($row['from'] === $row['to'] ? Fa::digits($row['from']) : Fa::digits($row['from']) . ' تا ' . Fa::digits($row['to']))
        : ($row['centuries'] ? ($row['centuries'][0] === $row['centuries'][1] ? 'سدۀ ' . Fa::digits($row['centuries'][0]) : 'سده‌های ' . Fa::digits($row['centuries'][0]) . ' تا ' . Fa::digits($row['centuries'][1])) : '');
@endphp
@if(! $scope->get('scribe') && count($sb['items']) > 1)
<x-ui.section title="کاتبان پرکار" id="scribes">
    <x-ui.card class="!p-0 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-muted border-b border-line">
                <tr>
                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">کاتب</th>
                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">سال‌ها و شهر</th>
                    <th scope="col" class="px-3 py-2.5 text-left font-semibold">نسخه</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sb['items'] as $row)
                    <tr class="border-b border-line last:border-0 hover:bg-paper">
                        <td class="px-3 py-2 align-top"><a href="{{ route('people.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a></td>
                        <td class="px-3 py-2 align-top text-xs text-muted tabular-nums">{{ $years($row) }}@if($years($row) !== '' && $row['city']) · @endif{{ $row['city'] }}</td>
                        <td class="px-3 py-2 align-top text-left"><a href="{{ $search('manuscripts', ['scribe_id' => $row['id']]) }}" class="text-xs tabular-nums text-ink hover:text-cinnabar">{{ Fa::num($row['count']) }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>
    <p class="mt-3 text-xs text-muted leading-relaxed">
        کاتب شناخته (پیوندشده به یک شخص) برای {{ Fa::num($sb['with_scribe']) }} نسخه از {{ Fa::num($sb['scribes']) }} کاتب ثبت است.
        سال‌ها، نخستین و واپسین سال نسخه‌های سال‌دار هر کاتب است (تاریخی که تا {{ Fa::num(config('insights.exact_span')) }} سال دامنه دارد، با میانه‌اش). کاتبی که نسخۀ سال‌دار ندارد، سدۀ نسخه‌هایی را دارد که تنها سده‌شان آمده است.
        شهر، شهری است که بیشترین نسخه‌اش در آن کتابت شده است.
    </p>
    @include('insights.explain', ['note' => $notes['scribes'], 'tables' => $tables['scribes']])
</x-ui.section>
@endif
