@props(['params', 'placeholder' => 'جستجو در این فهرست…', 'plural' => 'نسخه‌ها'])

{{-- Above a record's list (a library's manuscripts, an author's works…): search within it, or open it in the search
     page, which narrows to it and adds the filters, orders and Excel export (backlog item 83, phase 6) --}}
<div {{ $attributes->class('flex flex-wrap items-center gap-2 mb-3 text-xs') }}>
    <form action="{{ route('search') }}" method="GET" role="search" class="flex items-center gap-1.5 flex-1 min-w-[14rem] max-w-md">
        @foreach($params as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <input type="search" name="q" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"
               class="flex-1 min-w-0 px-2.5 py-1.5 rounded-sm border border-line-strong bg-paper text-ink text-xs focus:border-cinnabar focus:ring-2 focus:ring-cinnabar/15 outline-none">
        <x-ui.btn type="submit" size="sm">جستجو</x-ui.btn>
    </form>
    <a href="{{ route('search', $params) }}" class="text-cinnabar hover:underline">فیلتر، مرتب‌سازی و خروجی اکسل این {{ $plural }} ←</a>
</div>
