@php $n = fn ($v) => \App\Support\Fa::num($v); @endphp
{{-- The panel's pager: Persian digits, square corners; a phone keeps only «پیشین» and «پسین» --}}
<nav role="navigation" aria-label="صفحه‌ها" class="flex flex-wrap items-center justify-between gap-3 mt-4 text-xs">
    <span class="text-muted">{{ $n($paginator->firstItem()) }} تا {{ $n($paginator->lastItem()) }} از <b class="text-ink">{{ $n($paginator->total()) }}</b></span>
    <div class="flex items-center gap-1">
        @if($paginator->onFirstPage())
            <span class="px-2.5 py-1 rounded-sm border border-line text-muted/60">پیشین</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="px-2.5 py-1 rounded-sm border border-line-strong bg-surface hover:border-cinnabar hover:text-cinnabar">پیشین</a>
        @endif
        @foreach($elements as $element)
            @if(is_string($element))
                <span class="hidden sm:inline px-1 text-muted">…</span>
            @else
                @foreach($element as $page => $url)
                    @if($page == $paginator->currentPage())
                        <span aria-current="page" class="hidden sm:inline px-2.5 py-1 rounded-sm border border-cinnabar bg-cinnabar text-on-cinnabar font-bold tabular-nums">{{ $n($page) }}</span>
                    @else
                        <a href="{{ $url }}" class="hidden sm:inline px-2.5 py-1 rounded-sm border border-line bg-surface hover:border-cinnabar hover:text-cinnabar tabular-nums">{{ $n($page) }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        <span class="sm:hidden px-2 text-muted">{{ $n($paginator->currentPage()) }} از {{ $n($paginator->lastPage()) }}</span>
        @if($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="px-2.5 py-1 rounded-sm border border-line-strong bg-surface hover:border-cinnabar hover:text-cinnabar">پسین</a>
        @else
            <span class="px-2.5 py-1 rounded-sm border border-line text-muted/60">پسین</span>
        @endif
    </div>
</nav>
