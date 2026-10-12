@props(['people', 'others' => false, 'linkClass' => 'text-[#B38A50] hover:underline'])

{{-- Several scribes or authors of one record, in catalog order, each with the catalog's note on their share --}}
<span>
    @foreach($people as $person)
        <a href="{{ route('people.show', $person) }}" class="{{ $linkClass }}">{{ $person->name }}</a>@if($person->pivot?->note)
            <span class="text-[11px] font-normal text-stone-500 dark:text-stone-400">({{ $person->pivot->note }})</span>@endif{{ $loop->last ? '' : ($loop->remaining === 1 && ! $others ? ' و' : '،') }}
    @endforeach
    @if($others)
        <span class="font-normal text-stone-500 dark:text-stone-400">و دیگران</span>
    @endif
</span>
