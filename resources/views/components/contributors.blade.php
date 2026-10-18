@props(['entries', 'others' => false, 'linkClass' => 'text-cinnabar hover:underline', 'life' => false])

{{-- Scribes or authors of one record in catalog order: linked people, names without a record, and each one's share. «life» adds a linked person's dates --}}
<span>
    @foreach($entries as $entry)
        @php
            $separator = $loop->last ? '' : ($loop->remaining === 1 && ! $others ? ' و' : '،');
            $note = $entry->note ? ' <span class="text-[11px] font-normal text-muted">(' . e($entry->note) . ')</span>' : '';
            $name = $entry->person
                ? '<a href="' . e(route('people.show', $entry->person)) . '" class="' . e($linkClass) . '">' . e($entry->person->name) . '</a>'
                : '<span>' . e($entry->name) . '</span>';
            if ($life && $entry->person?->life_span) {
                $name .= ' <span class="text-muted font-normal text-[0.9em] whitespace-nowrap">(' . e($entry->person->life_span) . ')</span>';
            }
        @endphp
        <span>{!! $name . $note . e($separator) !!}</span>
    @endforeach
    @if($others)
        <span class="font-normal text-muted">و دیگران</span>
    @endif
</span>
