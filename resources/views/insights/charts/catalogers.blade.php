@php
    use App\Support\Fa;
@endphp
{{-- The cataloguers who described the most copies (backlog item 162, step 14.344) --}}
@php $cg = $data['catalogers']; @endphp
@if(count($cg['items']) > 1)
<x-ui.section title="فهرست‌نگاران پرکار" id="catalogers">
    <x-ui.card class="!p-0 overflow-x-auto">
        @php $maxCataloger = max(1, $cg['items'][0]['count']); @endphp
        <table class="w-full text-sm" data-catalogers>
            <thead class="text-xs text-muted border-b border-line">
                <tr>
                    <th scope="col" class="px-3 py-2.5 text-right font-semibold">فهرست‌نگار</th>
                    <th scope="col" class="px-3 py-2.5 text-left font-semibold w-28 sm:w-56">نسخه</th>
                    <th scope="col" class="px-3 py-2.5 text-left font-semibold">کتابخانه</th>
                    <th scope="col" class="px-3 py-2.5 text-left font-semibold">جلد</th>
                </tr>
            </thead>
            <tbody>
                @foreach($cg['items'] as $row)
                    <tr class="border-b border-line last:border-0 hover:bg-paper">
                        <td class="px-3 py-2 align-top"><a href="{{ route('catalogers.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a></td>
                        <td class="px-3 py-2 align-top text-left" data-tip="{{ $row['name'] }}: {{ Fa::num($row['count']) }} نسخه، {{ $pct($row['share']) }} نسخه‌هایی که فهرست‌نگارشان معلوم است">
                            <span class="text-xs tabular-nums text-ink">{{ Fa::num($row['count']) }}</span>
                            <span class="mt-1 block h-1 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                <span class="block h-full bg-cinnabar/60 rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / $maxCataloger, 2) }}%)"></span>
                            </span>
                        </td>
                        <td class="px-3 py-2 align-top text-left text-xs text-muted tabular-nums">{{ Fa::num($row['libraries']) }}</td>
                        <td class="px-3 py-2 align-top text-left text-xs text-muted tabular-nums">{{ Fa::num($row['volumes']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>
    <p class="mt-3 text-xs text-muted leading-relaxed">
        فهرست‌نگار {{ Fa::num($cg['counted']) }} نسخه ({{ $pct($cg['coverage']) }}) معلوم است، از {{ Fa::num($cg['catalogers']) }} فهرست‌نگار.
        هر نسخه به نام یک فهرست‌نگار شمرده شده است. «کتابخانه» و «جلد»، شمار کتابخانه‌ها و جلدهای فهرستی است که نسخه‌های او در این دامنه در آن‌ها آمده‌اند.
        <a href="{{ route('catalogers.index') }}" class="text-cinnabar hover:underline">همۀ فهرست‌نگاران</a>
    </p>
    @include('insights.explain', ['note' => $notes['catalogers'], 'tables' => $tables['catalogers']])
</x-ui.section>
@endif
