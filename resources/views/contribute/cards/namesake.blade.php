{{-- A group of namesakes (NamesakeQueue::card): each record with its dates and a sample of its works and copies.
     The boxes join the answer form for «برخی یک نفرند». --}}
@use('App\Support\Fa')
<div class="space-y-1">
    <h2 class="font-naskh text-lg sm:text-xl font-bold text-ink leading-relaxed m-0">آیا این‌ها یک نفرند؟</h2>
    <p class="text-xs sm:text-sm text-muted leading-6 m-0">
        {{ Fa::num(count($people)) }} رکورد با نام یکسان یا نزدیک. تاریخ‌ها، آثار و نسخه‌ها را بسنجید و اگر لازم شد صفحه‌ی هر شخص را باز کنید.
        @if($reason) {{ $reason }} @endif
    </p>
</div>

@php $marked = old('people', $previous?->payload['merge_ids'] ?? []); @endphp
<div class="grid gap-3 {{ count($people) > 1 ? 'sm:grid-cols-2' : '' }}">
    @foreach($people as $m)
        @php $p = $m['person']; @endphp
        <section class="p-3 border border-line bg-paper space-y-1.5 min-w-0">
            <div class="flex items-start gap-2">
                <input type="checkbox" form="review-answer" name="people[]" value="{{ $p->id }}" x-show="answer === 'some'" x-cloak
                       @checked(in_array($p->id, array_map('intval', (array) $marked), true)) aria-label="یک نفر با دیگر رکوردهای علامت‌خورده: {{ $p->name }}"
                       class="mt-1.5 rounded border-line-strong text-cinnabar focus:ring-cinnabar">
                <div class="min-w-0">
                    <a href="{{ route('people.show', $p) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar break-words leading-7">{{ $p->name }}</a>
                    <p class="m-0 text-[11px] text-muted">
                        {{ implode(' · ', array_filter([
                            $p->death_year_hijri ? 'درگذشت ' . Fa::digits((string) $p->death_year_hijri) . ' ق' : ($p->century_hijri ? 'سده‌ی ' . Fa::digits((string) $p->century_hijri) : null),
                            $m['span'] ? 'کتابت ' . Fa::digits($m['span'][0] . ($m['span'][1] !== $m['span'][0] ? '–' . $m['span'][1] : '')) . ' ق' : null,
                            $p->works_count ? Fa::num($p->works_count) . ' اثر' : null,
                            $p->manuscripts_count ? Fa::num($p->manuscripts_count) . ' نسخه' : null,
                        ])) }}
                    </p>
                </div>
            </div>
            @if($m['works']->isNotEmpty() || $m['copies']->isNotEmpty())
                <ul class="m-0 ps-4 text-xs text-ink/85 leading-6 list-disc">
                    @foreach($m['works'] as $w)<li>تألیف: {{ $w->title }}</li>@endforeach
                    @foreach($m['copies'] as $c)<li>کتابت: {{ $c->title }} <span class="text-muted">({{ implode('، ', array_filter([$c->city, $c->year ? Fa::digits((string) $c->year) . ' ق' : null])) }})</span></li>@endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>
