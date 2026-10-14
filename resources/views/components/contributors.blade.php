@props(['entries', 'others' => false, 'linkClass' => 'text-[#B38A50] hover:underline'])

{{-- Scribes or authors of one record in catalog order: linked people, names without a record, and each one's share --}}
<span>
    @foreach($entries as $entry)
        @php
            $separator = $loop->last ? '' : ($loop->remaining === 1 && ! $others ? ' و' : '،');
            $note = $entry->note ? ' <span class="text-[11px] font-normal text-stone-500 dark:text-stone-400">(' . e($entry->note) . ')</span>' : '';
            $name = $entry->person
                ? '<a href="' . e(route('people.show', $entry->person)) . '" class="' . e($linkClass) . '">' . e($entry->person->name) . '</a>'
                : '<span>' . e($entry->name) . '</span>';
        @endphp
        <span>{!! $name . $note . e($separator) !!}</span>
    @endforeach
    @if($others)
        <span class="font-normal text-stone-500 dark:text-stone-400">و دیگران</span>
    @endif
</span>
