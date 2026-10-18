@props(['title', 'field' => null, 'count' => null])

{{-- A page section with a rubric heading; optional suggestion button and actions on the other side --}}
<section {{ $attributes->merge(['class' => 'scroll-mt-24']) }}>
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 mb-3">
        <h2 class="rubric text-lg">
            <span>{{ $title }}</span>
            @if(! is_null($count))
                <span class="font-sans text-xs font-semibold text-muted tabular-nums">{{ \App\Support\Fa::num($count) }}</span>
            @endif
            @if($field)
                <x-suggest.field :name="$field" />
            @endif
        </h2>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2 text-xs">{{ $actions }}</div>
        @endisset
    </div>
    {{ $slot }}
</section>
