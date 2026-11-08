{{-- Filters of the list of copies (backlog item 79, step 14.99): only the values of this work's copies, with their
     counts under the other filters. A GET form, so the filtered address can be shared; folded on phones. --}}
@php
    $selects = ['library' => 'کتابخانه', 'city' => 'شهر', 'century' => 'سده‌ی کتابت', 'script' => 'خط'];
    $active = ! $copyFilters->isEmpty();
    $clearUrl = route('works.show', array_merge(request()->except([...\App\Support\WorkCopyFilters::PARAMS, 'page']), ['id' => $work->getRouteKey()])) . '#manuscripts';
@endphp
<details class="mb-3 bg-surface border border-line text-xs group" data-copy-filters
         @if($active) open @endif
         x-data x-init="$el.open = $el.open || window.matchMedia('(min-width: 640px)').matches; window.addEventListener('pageshow', () => $el.querySelectorAll('select').forEach(s => s.disabled = false))">
    <summary class="flex items-center justify-between gap-2 px-3 py-2 cursor-pointer select-none text-ink">
        <span class="inline-flex items-center gap-1 font-semibold"><svg class="w-3.5 h-3.5 shrink-0 transition group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>فیلتر نسخه‌ها @if($active)<span class="text-cinnabar">({{ \App\Support\Fa::num(count($copyFilters->values)) }})</span>@endif</span>
        <span class="text-muted" data-copy-filter-count>
            @if($active)
                <span class="font-bold text-ink">{{ \App\Support\Fa::num($manuscripts->total()) }}</span> از {{ \App\Support\Fa::num($copyTotal) }} نسخه
            @else
                {{ \App\Support\Fa::num($copyTotal) }} نسخه
            @endif
        </span>
    </summary>
    <form method="GET" action="{{ route('works.show', $work) }}#manuscripts" class="px-3 pb-3 pt-1 border-t border-line"
          onsubmit="this.querySelectorAll('select').forEach(s => s.disabled = ! s.value)">
        @foreach(['sort', 'direction'] as $keep)
            @if(request()->filled($keep))<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif
        @endforeach
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($selects as $param => $title)
                @continue(empty($copyFilterOptions[$param]) && ! isset($copyFilters->values[$param]))
                <label class="block">
                    <span class="block mb-0.5 text-muted">{{ $title }}</span>
                    <select name="{{ $param }}" onchange="this.form.requestSubmit()"
                            class="w-full px-2 py-1.5 rounded-sm border border-line-strong bg-paper text-ink text-xs focus:border-cinnabar outline-none">
                        <option value="">همه</option>
                        @foreach($copyFilterOptions[$param] as [$value, $label, $n])
                            <option value="{{ $value }}" @selected((string) ($copyFilters->values[$param] ?? '') === (string) $value)>{{ $label }} ({{ \App\Support\Fa::num($n) }})</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
        </div>
        <div class="grid gap-2 mt-2 sm:grid-cols-2 lg:grid-cols-4">
            @foreach(\App\Support\WorkCopyFilters::FLAGS as $param => [$title])
                @continue(collect($copyFilterOptions[$param] ?? [])->sum(2) === 0 && ! isset($copyFilters->values[$param]))
                <label class="block">
                    <span class="block mb-0.5 text-muted">{{ $title }}</span>
                    <select name="{{ $param }}" onchange="this.form.requestSubmit()"
                            class="w-full px-2 py-1.5 rounded-sm border border-line-strong bg-paper text-ink text-xs focus:border-cinnabar outline-none">
                        <option value="">همه</option>
                        @foreach($copyFilterOptions[$param] as [$value, $label, $n])
                            <option value="{{ $value }}" @selected(($copyFilters->values[$param] ?? '') === $value)>{{ $label }} ({{ \App\Support\Fa::num($n) }})</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2 mt-3">
            <noscript><x-ui.btn type="submit" size="sm">اعمال</x-ui.btn></noscript>
            @foreach($copyFilters->chips() as [$label, $param])
                <a href="{{ route('works.show', array_merge(request()->except([$param, 'page']), ['id' => $work->getRouteKey()])) }}#manuscripts"
                   class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm border border-cinnabar/40 bg-paper text-cinnabar hover:bg-cinnabar/10" title="حذف این فیلتر" data-copy-filter-chip>
                    {{ $label }} <span aria-hidden="true">×</span>
                </a>
            @endforeach
            @if($active)
                <a href="{{ $clearUrl }}" class="text-muted hover:text-cinnabar underline" data-copy-filter-clear>حذف فیلترها</a>
            @endif
        </div>
    </form>
</details>
