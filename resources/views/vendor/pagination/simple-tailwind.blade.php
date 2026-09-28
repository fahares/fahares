@if ($paginator->hasPages())
    <nav role="navigation" aria-label="ناوبری صفحات" class="flex items-center justify-between gap-3 py-4">

        @if ($paginator->onFirstPage())
            <span class="px-4 py-2 text-xs font-semibold text-stone-300 dark:text-stone-600 bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 rounded-xl cursor-not-allowed">
                صفحه قبلی
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="px-4 py-2 text-xs font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 rounded-xl shadow-xs transition">
                صفحه قبلی
            </a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="px-4 py-2 text-xs font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 rounded-xl shadow-xs transition">
                صفحه بعدی
            </a>
        @else
            <span class="px-4 py-2 text-xs font-semibold text-stone-300 dark:text-stone-600 bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 rounded-xl cursor-not-allowed">
                صفحه بعدی
            </span>
        @endif

    </nav>
@endif
