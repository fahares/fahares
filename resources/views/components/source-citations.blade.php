@props(['citations'])

{{-- Citations of a record beyond its primary catalog entry: addenda, errata, other catalogs --}}
@if($citations->isNotEmpty())
    <x-ui.section title="ارجاع‌های دیگر در مآخذ چاپی">

        <div class="space-y-4">
            @foreach($citations as $citation)
                @php
                    $catalogCode = $citation->catalog?->code ?? 'fankha';
                    $catalogName = $citation->catalog?->short_name ?? 'فنخا';
                @endphp
                <div x-data="{ open: false }" class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-lg text-[11px] font-bold {{ $citation->role === 'erratum' ? 'bg-rose-50 dark:bg-rose-950/30 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-900/50' : 'bg-amber-50 dark:bg-amber-950/40 text-cinnabar dark:text-amber-300 border border-amber-200 dark:border-amber-800/60' }}">
                            {{ $citation->role_label }}
                        </span>
                        <span class="text-sm font-bold text-ink tabular-nums">
                            {{ $catalogName }}، ج {{ \App\Support\Fa::digits($citation->volume_number) }}، ص {{ \App\Support\Fa::digits($citation->page_start) }}
                            @if($citation->page_end && $citation->page_end > $citation->page_start)
                                تا {{ \App\Support\Fa::digits($citation->page_end) }}
                            @endif
                        </span>

                        @if($citation->volume_number && $citation->page_start)
                            <button
                                type="button"
                                @click="$dispatch('open-catalog-viewer', { catalog: '{{ $catalogCode }}', catalogName: '{{ $catalogName }}', volume: {{ $citation->volume_number }}, page: {{ $citation->page_start }} })"
                                class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-sm border border-line-strong bg-surface text-[11px] font-semibold text-ink hover:text-cinnabar hover:border-cinnabar transition cursor-pointer"
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
                                class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-sm border border-line-strong bg-surface text-[11px] font-semibold text-ink hover:text-cinnabar hover:border-cinnabar transition cursor-pointer">
                                <span x-show="!open">نمایش متن</span>
                                <span x-show="open" style="display: none;">بستن متن</span>
                            </button>
                        @endif
                    </div>

                    @if($citation->clean_raw_text)
                        <div x-show="open" style="display: none;"
                             class="p-4 sm:p-5 bg-surface border border-line text-xs sm:text-[13px] text-ink leading-loose whitespace-pre-line text-right">{{ $citation->clean_raw_text }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.section>
@endif
