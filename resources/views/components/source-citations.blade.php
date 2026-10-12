@props(['citations'])

{{-- Citations of a record beyond its primary catalog entry: addenda, errata, other catalogs --}}
@if($citations->isNotEmpty())
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-5 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
        <div class="flex items-center gap-2 pb-3.5 border-b border-stone-100 dark:border-stone-800/80">
            <span class="w-2 h-4 bg-[#B38A50] rounded-sm"></span>
            <h3 class="text-xs sm:text-sm font-bold text-stone-900 dark:text-stone-100 tracking-wide">
                ارجاعات دیگر در مآخذ چاپی
            </h3>
        </div>

        <div class="space-y-4">
            @foreach($citations as $citation)
                @php
                    $catalogCode = $citation->catalog?->code ?? 'fankha';
                    $catalogName = $citation->catalog?->short_name ?? 'فنخا';
                @endphp
                <div x-data="{ open: false }" class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-lg text-[11px] font-bold {{ $citation->role === 'erratum' ? 'bg-rose-50 dark:bg-rose-950/30 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-900/50' : 'bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] dark:text-amber-300 border border-amber-200 dark:border-amber-800/60' }}">
                            {{ $citation->role_label }}
                        </span>
                        <span class="text-sm font-bold text-stone-700 dark:text-stone-300">
                            {{ $catalogName }}، ج {{ $citation->volume_number }}، ص {{ $citation->page_start }}
                            @if($citation->page_end && $citation->page_end > $citation->page_start)
                                تا {{ $citation->page_end }}
                            @endif
                        </span>

                        @if($citation->volume_number && $citation->page_start)
                            <button
                                type="button"
                                @click="$dispatch('open-catalog-viewer', { catalog: '{{ $catalogCode }}', catalogName: '{{ $catalogName }}', volume: {{ $citation->volume_number }}, page: {{ $citation->page_start }} })"
                                class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 text-[11px] font-bold text-[#B38A50] hover:bg-amber-100 dark:hover:bg-amber-900/50 transition cursor-pointer"
                                title="مشاهده برگه در مأخذ چاپی {{ $catalogName }}">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                                <span>مشاهده برگه</span>
                            </button>
                        @endif

                        @if($citation->clean_raw_text)
                            <button
                                type="button"
                                @click="open = !open"
                                :aria-expanded="open.toString()"
                                class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg bg-stone-50 dark:bg-stone-800/80 border border-stone-200 dark:border-stone-700 text-[11px] font-semibold text-stone-600 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition cursor-pointer">
                                <span x-show="!open">نمایش متن</span>
                                <span x-show="open" style="display: none;">بستن متن</span>
                            </button>
                        @endif
                    </div>

                    @if($citation->clean_raw_text)
                        <div x-show="open" style="display: none;"
                             class="p-4 sm:p-6 rounded-2xl bg-[#FEF9F3] dark:bg-[#0C0F1D] border border-[#EADFCF] dark:border-[#272F4C] text-xs sm:text-[13px] text-stone-800 dark:text-stone-100 leading-loose whitespace-pre-line text-right">{{ $citation->clean_raw_text }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
