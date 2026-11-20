@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 4. Scripts and languages --}}
<x-ui.section title="سهم خطوط" id="scripts">
    <x-ui.card>
        @if($s['counted'] < $min)
            @include('insights.thin', ['n' => $s['counted'], 'what' => 'نسخۀ خط‌دار'])
        @else
        @include('insights.small', ['n' => $s['counted'], 'what' => 'نسخۀ خط‌دار'])
        <p class="text-xs text-muted mb-3">خط اصلی هر نسخه. روی هر خط بزنید تا نسخه‌های آن خط را ببینید.</p>
        <ul class="space-y-2.5 text-sm">
            @foreach($s['items'] as $row)
                <li>
                    <a href="{{ $search('manuscripts', ['script_id' => $row['id']]) }}" class="group block">
                        <span class="flex items-baseline gap-2">
                            <span class="text-ink group-hover:text-cinnabar transition">{{ $row['name'] }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums">{{ Fa::num($row['count']) }} · {{ $pct(round($row['count'] * 100 / max(1, $s['counted']), 1)) }}</span>
                        </span>
                        <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full bg-cinnabar/70 group-hover:bg-cinnabar rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / max(1, $s['counted']), 2) }}%)"></span>
                        </span>
                    </a>
                </li>
            @endforeach
            @if($s['other'] > 0)
                <li class="text-muted">
                    <span class="flex items-baseline gap-2">
                        <span>سایر ({{ Fa::num($s['other_kinds']) }} گونه)</span>
                        <span class="leader" aria-hidden="true"></span>
                        <span class="text-xs tabular-nums">{{ Fa::num($s['other']) }} · {{ $pct(round($s['other'] * 100 / max(1, $s['counted']), 1)) }}</span>
                    </span>
                    <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                        <span class="block h-full bg-muted/60 rounded-full" style="width: max(2px, {{ round($s['other'] * 100 / max(1, $s['counted']), 2) }}%)"></span>
                    </span>
                </li>
            @endif
        </ul>
        <p class="mt-4 text-xs text-muted">بر پایۀ {{ Fa::num($s['counted']) }} نسخه، یعنی {{ $pct($s['coverage']) }} نسخه‌ها، که خطشان ثبت شده است.</p>
        @include('insights.explain', ['note' => $notes['scripts'], 'tables' => $tables['scripts']])
        @endif
    </x-ui.card>
</x-ui.section>
