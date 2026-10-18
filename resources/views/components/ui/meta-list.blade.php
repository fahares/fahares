{{-- Label and value pairs (x-ui.meta), one per row; labels share a column on wide screens --}}
<dl {{ $attributes->merge(['class' => 'grid grid-cols-1 sm:grid-cols-[max-content_minmax(0,1fr)] gap-x-8 gap-y-1 sm:gap-y-3 text-sm m-0']) }}>
    {{ $slot }}
</dl>
