@props(['label' => null, 'main' => false, 'actions' => false])

<td {{ $attributes->class([
    'md:table-cell md:px-4 md:py-3 align-top',
    'basis-full' => $main,
    'basis-full pt-1.5 md:pt-3 md:text-left md:whitespace-nowrap' => $actions,
]) }}>
    @if($label && ! $main && ! $actions)
        <span class="md:hidden text-xs text-muted">{{ $label }}:</span>
    @endif
    {{ $slot }}
</td>
