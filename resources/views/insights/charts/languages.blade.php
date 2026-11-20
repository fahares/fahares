@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 4. Scripts and languages --}}
<x-ui.section title="سهم زبان‌ها" id="languages">
    <x-ui.card>
    @if($l['with_language'] < $min)
        @include('insights.thin', ['n' => $l['with_language'], 'what' => 'اثرِ زبان‌دار'])
    @else
    @include('insights.small', ['n' => $l['with_language'], 'what' => 'اثرِ زبان‌دار'])
    <div x-data="{ by: 'works', rows: @js($l['items']), other: @js($l['other']),
        get sum() { return this.rows.reduce((s, r) => s + r[this.by], 0) + this.other[this.by] },
        share(v) { return (Math.round(v * 1000 / Math.max(1, this.sum)) / 10).toLocaleString('fa-IR') + '٪' } }">
        <div class="flex flex-wrap items-center gap-1.5 text-xs mb-3">
            <span class="text-muted me-1">بر پایۀ</span>
            @foreach(['works' => 'آثار', 'manuscripts' => 'نسخه‌ها'] as $key => $label)
                <button type="button" @click="by = '{{ $key }}'" :aria-pressed="(by === '{{ $key }}').toString()"
                        :class="by === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                        class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $label }}</button>
            @endforeach
        </div>
        @php $langSum = array_sum(array_column($l['items'], 'works')) + $l['other']['works']; @endphp
        <ul class="space-y-2.5 text-sm">
            @foreach($l['items'] as $i => $row)
                <li>
                    <a href="{{ $search('works', ['language_id' => $row['id']]) }}" class="group block">
                        <span class="flex items-baseline gap-2">
                            <span class="text-ink group-hover:text-cinnabar transition">{{ $row['name'] }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums" x-text="`${rows[{{ $i }}][by].toLocaleString('fa-IR')} · ${share(rows[{{ $i }}][by])}`">{{ Fa::num($row['works']) }} · {{ $pct(round($row['works'] * 100 / max(1, $langSum), 1)) }}</span>
                        </span>
                        <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                            <span class="block h-full bg-gold/80 group-hover:bg-gold rounded-full transition-[width]"
                                  style="width: max(2px, {{ round($row['works'] * 100 / max(1, $langSum), 2) }}%)"
                                  :style="`width: max(2px, ${rows[{{ $i }}][by] * 100 / Math.max(1, sum)}%)`"></span>
                        </span>
                    </a>
                </li>
            @endforeach
            @if($l['other']['works'] > 0)
                <li class="text-muted">
                    <span class="flex items-baseline gap-2">
                        <span>سایر ({{ Fa::num($l['other']['kinds']) }} زبان)</span>
                        <span class="leader" aria-hidden="true"></span>
                        <span class="text-xs tabular-nums" x-text="`${other[by].toLocaleString('fa-IR')} · ${share(other[by])}`">{{ Fa::num($l['other']['works']) }}</span>
                    </span>
                </li>
            @endif
        </ul>
        <p class="mt-4 text-xs text-muted leading-relaxed">
            بر پایۀ {{ Fa::num($l['with_language']) }} اثر، یعنی {{ $pct($l['coverage']) }} آثار، که زبانشان ثبت شده است.
            {{ Fa::num($l['multilingual']) }} اثرِ چندزبانه در هر زبانِ خود شمرده شده‌اند، پس جمع سهم‌ها کمی بیش از شمار آثار است.
            در شمارش «نسخه‌ها» نسخه‌های هر اثر به زبان آن اثر نسبت داده شده است.
        </p>
        @include('insights.explain', ['note' => $notes['languages'], 'tables' => $tables['languages']])
    </div>
    @endif
    </x-ui.card>
</x-ui.section>
