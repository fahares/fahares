@props(['label', 'field' => null, 'empty' => false])

{{-- One row of x-ui.meta-list. An empty row shows only in suggestion mode, so the field can still be suggested --}}
<div class="contents suggest-row" @if($empty) x-show="$store.suggest.on" x-cloak @endif>
    <dt class="text-muted text-[13px] sm:pt-px">{{ $label }}</dt>
    <dd class="m-0 mb-2 sm:mb-0 text-ink min-w-0 leading-relaxed">
        @if($empty)<span class="text-muted">—</span>@else{{ $slot }}@endif
        @if($field)<x-suggest.field :name="$field" class="ms-1" />@endif
    </dd>
</div>
