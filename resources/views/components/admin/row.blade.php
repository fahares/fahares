{{-- On a phone a card: the main cell on its own line, the others side by side, the actions at the foot --}}
<tr {{ $attributes->class('flex flex-wrap items-baseline gap-x-5 gap-y-1 px-4 py-3 md:table-row md:p-0 hover:bg-paper transition') }}>
    {{ $slot }}
</tr>
