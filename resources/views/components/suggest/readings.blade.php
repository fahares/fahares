{{-- Readings of the record's fields from approved suggestions (shared by the record page, see AppServiceProvider and SuggestionReadings) --}}
@php $readings = $suggestionReadings ?? collect(); @endphp
@if($readings->isNotEmpty())
    <x-ui.section id="suggestion-readings" title="اقوال، اصلاحات و تعلیقات">
        <div class="space-y-5">

        @foreach($readings as $r)
            <section class="space-y-3 text-sm {{ $loop->first ? '' : 'pt-5 border-t border-line' }}">
                <h3 class="text-xs font-bold text-muted">{{ $r['label'] }}</h3>

                @if($r['adopted'])
                    <x-suggest.reading :s="$r['adopted']" chosen />
                @elseif($r['current'] !== null)
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="px-2 py-0.5 rounded-md border text-[10px] font-bold bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-900/60">ضبط فعلی (فهرست)</span>
                        <span class="font-bold text-ink whitespace-pre-line">{{ $r['current'] }}</span>
                    </div>
                @endif

                @foreach($r['alternatives'] as $s)
                    <x-suggest.reading :s="$s" tag="قول دیگر" tone="sky" />
                @endforeach

                @if($r['baseline'] !== null)
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="px-2 py-0.5 rounded-md border text-[10px] font-bold bg-stone-100 dark:bg-stone-800 text-ink/80 border-line">ضبط فهرست پیش از اصلاح</span>
                        <span class="text-ink whitespace-pre-line">{{ $r['baseline'] }}</span>
                    </div>
                @endif

                @if($r['history']->isNotEmpty())
                    <details class="group">
                        <summary class="cursor-pointer text-[11px] font-bold text-muted hover:text-cinnabar select-none">سابقه‌ی اصلاحات ({{ $r['history']->count() }})</summary>
                        <div class="mt-3 space-y-3 ps-3 border-s-2 border-line">
                            @foreach($r['history'] as $s)
                                <x-suggest.reading :s="$s" :tag="$s->standing_label" />
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @endforeach
        </div>
    </x-ui.section>
@endif
