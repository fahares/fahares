{{-- What a change of a citation form did (or, in a preview, would do) to the citations, from CitationFormEditor::run --}}
@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $names = \App\Models\Source::whereIn('code', array_merge(array_keys((array) ($effect['taken'] ?? [])), array_keys((array) ($effect['other'] ?? []))))
        ->get(['code', 'title', 'short_name'])->mapWithKeys(fn ($s) => [$s->code => $s->short_name ?: $s->title]);
    $name = fn ($code) => $names[$code] ?? $code;
    $where = ($effect['context'] ?? 'bibliography') === 'page_text' ? 'صفحه' : 'رشته‌ی مأخذ';
    $lines = array_filter([
        $effect['gained'] ? $fa($effect['gained']) . " {$where} به این منبع وصل می‌شود" : null,
        $effect['taken'] ? 'از آن‌ها ' . collect($effect['taken'])->map(fn ($n, $code) => $fa($n) . ' از «' . $name($code) . '»')->implode('، ') : null,
        $effect['lost'] ? $fa($effect['lost']) . " {$where} از این منبع جدا می‌شود" : null,
        $effect['moved'] ? 'جلد یا صفحه‌ی ' . $fa($effect['moved']) . " {$where} عوض می‌شود" : null,
    ]);
    $kinds = ['gained' => 'تازه‌وصل', 'taken' => 'از منبع دیگر', 'lost' => 'جداشده', 'moved' => 'جای تازه', 'other' => 'نام هست ولی منبع دیگری آن را دارد', 'unread' => 'نام هست ولی عدد خوانده نشد'];
@endphp
<x-admin.notice :tone="$done ? 'good' : 'warn'" class="text-xs space-y-2">
    <p class="font-bold">
        {{ $done ? 'ذخیره شد.' : 'پیش‌نمایش: هنوز چیزی ذخیره نشده است.' }}
        {{ $lines ? implode('؛ ', $lines) . '.' : 'هیچ ارجاعی عوض نمی‌شود.' }}
    </p>
    <p class="text-muted">
        {{ $fa($effect['read']) }} {{ $where }} که یکی از نام‌ها را دارند خوانده شد.
        @if(! empty($effect['works'])) مآخذ {{ $fa($effect['works']) }} اثر دوباره ساخته {{ $done ? (! empty($effect['queued']) ? 'می‌شود، در صف' : 'شد') : 'می‌شود' }}. @endif
        @if(! empty($effect['other'])) {{ collect($effect['other'])->map(fn ($n, $code) => $fa($n) . ' رشته نام را دارد ولی «' . $name($code) . '» آن را دارد')->implode('؛ ') }}. @endif
        @if(! empty($effect['unread'])) {{ $fa($effect['unread']) }} رشته نام را دارد ولی عددش خوانده نشد. @endif
    </p>
    @foreach($kinds as $kind => $label)
        @if(! empty($effect['samples'][$kind]))
            <details class="bg-surface border border-line">
                <summary class="px-3 py-1.5 cursor-pointer text-ink">نمونه‌ها: {{ $label }} ({{ $fa(count($effect['samples'][$kind])) }})</summary>
                <ul class="px-3 pb-2 space-y-1 text-ink">
                    @foreach($effect['samples'][$kind] as $s)
                        <li class="leading-6">
                            <span dir="auto">{{ \App\Support\Fa::digits($s['text']) }}</span>
                            @if($s['cite'])<span class="text-emerald-700 dark:text-emerald-300"> ← ج {{ $fa($s['cite']['volume']) }}، ص {{ $fa($s['cite']['page']) }}</span>@endif
                            @if($s['other'])<span class="text-muted"> («{{ $name($s['other']) }}»)</span>@endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endforeach
</x-admin.notice>
