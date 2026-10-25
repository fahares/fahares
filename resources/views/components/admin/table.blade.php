@props(['paginator' => null, 'empty' => 'موردی یافت نشد.', 'isEmpty' => false, 'grouped' => false])

{{-- A list as a table on wide screens and as cards on a phone: rows are x-admin.row, cells x-admin.cell.
     «grouped»: the slot brings its own tbody per item, for an item with a row that opens under it --}}
<div {{ $attributes->class('bg-surface border border-line') }}>
    <table class="w-full text-right text-[13px] block md:table">
        @isset($head)
            <thead class="hidden md:table-header-group bg-paper text-xs text-muted border-b border-line">
                <tr>{{ $head }}</tr>
            </thead>
        @endisset
        @if($grouped)
            {{ $slot }}
        @else
            <tbody class="block md:table-row-group divide-y divide-line">
                {{ $slot }}
            </tbody>
        @endif
    </table>
    @if($isEmpty)
        <p class="px-4 py-10 text-center text-[13px] text-muted">{{ $empty }}</p>
    @endif
</div>
@if($paginator?->hasPages())
    {{ $paginator->links('admin.partials.pagination') }}
@endif
