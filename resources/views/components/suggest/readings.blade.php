{{-- Readings of the record's fields from approved suggestions (shared by the record page, see AppServiceProvider and SuggestionReadings) --}}
@php $readings = $suggestionReadings ?? collect(); @endphp
@if($readings->isNotEmpty())
    <div id="suggestion-readings" class="scroll-mt-24 bg-white dark:bg-[#15192C] rounded-3xl p-5 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-5">
        <div class="flex items-center gap-2 pb-3.5 border-b border-stone-100 dark:border-stone-800/80">
            <span class="w-2 h-4 bg-emerald-600 rounded-sm"></span>
            <h3 class="text-xs sm:text-sm font-bold text-stone-900 dark:text-stone-100 tracking-wide">اقوال و اصلاحات پژوهشگران</h3>
        </div>

        @foreach($readings as $r)
            <section class="space-y-3 text-sm {{ $loop->first ? '' : 'pt-5 border-t border-stone-100 dark:border-stone-800/80' }}">
                <h4 class="text-xs font-bold text-stone-400">{{ $r['label'] }}</h4>

                @if($r['adopted'])
                    <x-suggest.reading :s="$r['adopted']" tag="قول مختار" tone="emerald" />
                @elseif($r['current'] !== null)
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="px-2 py-0.5 rounded-md border text-[10px] font-bold bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-900/60">ضبط فعلی (فهرست)</span>
                        <span class="font-bold text-stone-800 dark:text-stone-100 whitespace-pre-line">{{ $r['current'] }}</span>
                    </div>
                @endif

                @foreach($r['alternatives'] as $s)
                    <x-suggest.reading :s="$s" tag="قول دیگر" tone="sky" />
                @endforeach

                @if($r['baseline'] !== null)
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="px-2 py-0.5 rounded-md border text-[10px] font-bold bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300 border-stone-200 dark:border-stone-700">ضبط فهرست پیش از اصلاح</span>
                        <span class="text-stone-700 dark:text-stone-200 whitespace-pre-line">{{ $r['baseline'] }}</span>
                    </div>
                @endif

                @if($r['history']->isNotEmpty())
                    <details class="group">
                        <summary class="cursor-pointer text-[11px] font-bold text-stone-500 dark:text-stone-400 hover:text-[#B38A50] select-none">سابقه‌ی اصلاحات ({{ $r['history']->count() }})</summary>
                        <div class="mt-3 space-y-3 ps-3 border-s-2 border-stone-200 dark:border-stone-700">
                            @foreach($r['history'] as $s)
                                <x-suggest.reading :s="$s" :tag="$s->standing_label" />
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @endforeach
    </div>
@endif
