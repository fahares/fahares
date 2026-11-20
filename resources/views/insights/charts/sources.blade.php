@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 5. The catalogues the figures rest on --}}
@php $src = $data['sources']; @endphp
@if(! $scope->get('catalog') && $src['items'])
<x-ui.section title="فهرست‌هایی که این آمار بر آن‌ها بنا شده" id="sources">
    <x-ui.card>
        @php $maxSrc = max([1, ...array_column($src['items'], 'count')]); @endphp
        <p class="text-xs text-muted mb-3">هر نسخه از کدام فهرست کتابخانه به فهارس رسیده است. روی «آمار» بزنید تا آمار نسخه‌های همان فهرست را ببینید.</p>
        <ul class="space-y-2.5 text-sm">
            @foreach($src['items'] as $row)
                <li>
                    <span class="flex items-baseline gap-2">
                        {{-- The volume, unless the title already names it («جلد شانزدهم») --}}
                        <span class="text-ink truncate min-w-0" data-tip="{{ $row['title'] }}">{{ $row['title'] }}@if($row['volume'] && ! str_contains($row['title'], 'جلد')) <span class="text-muted">ج {{ Fa::digits($row['volume']) }}</span>@endif</span>
                        <span class="leader" aria-hidden="true"></span>
                        <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($row['count']) }} · {{ $pct(round($row['count'] * 100 / max(1, $data['totals']['manuscripts']), 1)) }}</span>
                        <a href="{{ route('insights', ['catalog' => $row['id']] + $scope->query()) }}" class="text-[11px] text-cinnabar hover:underline">آمار</a>
                    </span>
                    <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                        <span class="block h-full bg-gold/70 rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / $maxSrc, 2) }}%)"></span>
                    </span>
                </li>
            @endforeach
            @if($src['other'] > 0)
                <li class="text-muted flex items-baseline gap-2">
                    <span>سایر ({{ Fa::num($src['other_kinds']) }} جلد فهرست)</span>
                    <span class="leader" aria-hidden="true"></span>
                    <span class="text-xs tabular-nums">{{ Fa::num($src['other']) }} · {{ $pct(round($src['other'] * 100 / max(1, $data['totals']['manuscripts']), 1)) }}</span>
                </li>
            @endif
        </ul>
        <p class="mt-4 text-xs text-muted leading-relaxed">
            نشانی هر نسخه در فهرست چاپی کتابخانه‌اش آمده است.
            {{ Fa::num($src['counted']) }} نسخه ({{ $pct($src['coverage']) }}) به یکی از {{ Fa::num($src['volumes']) }} جلد فهرست پیوند دارند@if($src['unknown'] > 0) و جلد فهرست {{ Fa::num($src['unknown']) }} نسخۀ دیگر هنوز شناخته نیست@endif.
        </p>
        @include('insights.explain', ['note' => $notes['sources'], 'tables' => $tables['sources']])
    </x-ui.card>
</x-ui.section>
@endif
