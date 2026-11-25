{{-- The people of a name about to be made a new person (backlog item 161): their page, and the edit form that can take the other names instead --}}
<ul class="mt-1 space-y-0.5">
    @foreach($people as $p)
        <li data-namesake="{{ $p->id }}">
            <a href="{{ route('people.show', $p) }}" target="_blank" class="font-bold hover:text-cinnabar">{{ \App\Support\Fa::digits($p->name) }}</a>
            <span class="text-muted">— {{ \App\Support\Fa::digits(collect([$p->life_span, $p->works_count ? $p->works_count . ' اثر' : null, $p->manuscripts_count ? $p->manuscripts_count . ' نسخه' : null])->filter()->implode('، ')) ?: 'بی اثر و بی نسخه' }} (#{{ \App\Support\Fa::digits($p->id) }})</span>
            @can('people.edit')
                <a href="{{ route('admin.people.edit', $p) }}" target="_blank" class="text-xs text-cinnabar hover:underline">ویرایش</a>
            @endcan
        </li>
    @endforeach
</ul>
