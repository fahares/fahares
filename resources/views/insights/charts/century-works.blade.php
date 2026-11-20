@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 3b. The most-copied works of each century (backlog item 146) --}}
@php $cw = $data['century_works']; @endphp
@php $centuryCols = array_values(array_filter($cw['centuries'], fn ($c) => count($c['items']) > 0 && $c['count'] >= config('insights.min_sample'))); @endphp
{{-- One work a century, as in the scope of a single work, says no more than the timeline --}}
@if($centuryCols && max(array_map(fn ($c) => count($c['items']), $centuryCols)) > 1)
<x-ui.section title="پرنسخه‌ترین آثار هر سده" id="century-works">
    <x-ui.card>
        <p class="text-xs text-muted mb-4">در هر سده از کدام آثار بیشتر نسخه برداشته‌اند. عدد کنار هر اثر شمار نسخه‌های آن است که در همان سده کتابت شده‌اند.</p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-6">
            @foreach($centuryCols as $col)
                <div>
                    <h3 class="flex items-baseline gap-2 border-b border-line-strong pb-1 mb-2">
                        <span class="font-naskh font-bold text-ink">سدۀ {{ Fa::digits($col['century']) }}</span>
                        <span class="text-[11px] text-muted tabular-nums">{{ Fa::num($col['count']) }} نسخه</span>
                    </h3>
                    <ol class="space-y-1.5 text-sm">
                        @foreach($col['items'] as $work)
                            <li class="flex items-baseline gap-2">
                                <a href="{{ route('works.show', $work['key']) }}" class="text-ink hover:text-cinnabar transition truncate min-w-0" title="{{ $work['title'] }}{{ $work['author'] ? ' — ' . $work['author'] : '' }}">{{ $work['title'] }}</a>
                                <span class="leader" aria-hidden="true"></span>
                                <a href="{{ $search('manuscripts', ['work_id' => $work['id'], 'century' => $col['century']]) }}" class="text-xs text-muted tabular-nums hover:text-cinnabar">{{ Fa::num($work['count']) }}</a>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        </div>
        <p class="mt-5 text-xs text-muted leading-relaxed">
            بر پایۀ {{ Fa::num($cw['counted']) }} نسخه که تاریخ کتابتشان در یک سده است. رکوردهای بی‌پدیدآوری که عنوانشان نام یک گونۀ متن است («اشعار»، «جنگ») نیامده‌اند.
        </p>
        @include('insights.explain', ['note' => $notes['centuryWorks'], 'tables' => $tables['centuryWorks']])
    </x-ui.card>
</x-ui.section>
@endif
