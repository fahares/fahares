@props(['action', 'reset' => null, 'total' => null, 'noun' => null])

{{-- A list page's filter bar: its fields in the slot, then «اعمال», a way back to the full list, and the count --}}
<form method="GET" action="{{ $action }}" {{ $attributes->class('flex flex-wrap items-center gap-2 mb-4') }}>
    {{ $slot }}
    <x-ui.btn type="submit">اعمال</x-ui.btn>
    @if($reset)
        <x-ui.btn :href="$reset" variant="ghost">همه</x-ui.btn>
    @endif
    @if(! is_null($total))
        <span class="ms-auto text-xs text-muted"><b class="text-ink tabular-nums">{{ \App\Support\Fa::num($total) }}</b> {{ $noun }}</span>
    @endif
</form>
