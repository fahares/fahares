@if ($paginator->hasPages())
    <nav role="navigation" aria-label="ناوبری صفحات" class="flex flex-col sm:flex-row items-center justify-between gap-4 py-4">

        {{-- Results Summary Count --}}
        <div class="text-xs sm:text-sm text-stone-500 dark:text-stone-400 font-medium">
            @if ($paginator->firstItem())
                <span>نمایش</span>
                <span class="font-bold text-stone-800 dark:text-stone-200">{{ number_format($paginator->firstItem()) }}</span>
                <span>تا</span>
                <span class="font-bold text-stone-800 dark:text-stone-200">{{ number_format($paginator->lastItem()) }}</span>
                <span>از مجموع</span>
                <span class="font-bold text-[#B38A50]">{{ number_format($paginator->total()) }}</span>
                <span>نتیجه</span>
            @else
                <span>نمایش</span>
                <span class="font-bold text-[#B38A50]">{{ number_format($paginator->count()) }}</span>
                <span>نتیجه</span>
            @endif
        </div>

        {{-- Mobile Pagination (Prev / Next Buttons) --}}
        <div class="flex items-center justify-between w-full sm:hidden gap-2">
            @if ($paginator->onFirstPage())
                <span class="px-4 py-2 text-xs font-semibold text-stone-300 dark:text-stone-600 bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 rounded-xl cursor-not-allowed">
                    صفحه قبلی
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="px-4 py-2 text-xs font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 rounded-xl shadow-xs transition">
                    صفحه قبلی
                </a>
            @endif

            <span class="text-xs text-stone-500 font-medium">
                صفحه {{ number_format($paginator->currentPage()) }} از {{ number_format($paginator->lastPage()) }}
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="px-4 py-2 text-xs font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 rounded-xl shadow-xs transition">
                    صفحه بعدی
                </a>
            @else
                <span class="px-4 py-2 text-xs font-semibold text-stone-300 dark:text-stone-600 bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 rounded-xl cursor-not-allowed">
                    صفحه بعدی
                </span>
            @endif
        </div>

        {{-- Desktop Pagination Elements --}}
        <div class="hidden sm:flex items-center gap-1 bg-white dark:bg-[#15192C] p-1.5 rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs">
            
            {{-- Previous Page Link (Points Right in RTL) --}}
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" aria-label="صفحه قبلی" class="w-9 h-9 inline-flex items-center justify-center text-stone-300 dark:text-stone-700 rounded-xl cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="صفحه قبلی" title="صفحه قبلی" class="w-9 h-9 inline-flex items-center justify-center text-stone-600 dark:text-stone-300 hover:text-[#B38A50] hover:bg-amber-50 dark:hover:bg-stone-800/80 rounded-xl transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            @endif

            {{-- Numbers & Dots --}}
            @foreach ($elements as $element)
                {{-- Separator ... --}}
                @if (is_string($element))
                    <span class="w-8 h-9 inline-flex items-center justify-center text-xs text-stone-400 font-medium">
                        {{ $element }}
                    </span>
                @endif

                {{-- Page Links Array --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="min-w-9 h-9 px-2.5 inline-flex items-center justify-center text-xs font-bold text-white bg-[#B38A50] rounded-xl shadow-xs">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}" class="min-w-9 h-9 px-2.5 inline-flex items-center justify-center text-xs font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:bg-amber-50/80 dark:hover:bg-stone-800/80 rounded-xl transition" aria-label="برو به صفحه {{ $page }}">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link (Points Left in RTL) --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="صفحه بعدی" title="صفحه بعدی" class="w-9 h-9 inline-flex items-center justify-center text-stone-600 dark:text-stone-300 hover:text-[#B38A50] hover:bg-amber-50 dark:hover:bg-stone-800/80 rounded-xl transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
            @else
                <span aria-disabled="true" aria-label="صفحه بعدی" class="w-9 h-9 inline-flex items-center justify-center text-stone-300 dark:text-stone-700 rounded-xl cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </span>
            @endif

        </div>

    </nav>
@endif
