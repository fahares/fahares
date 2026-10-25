<label class="flex flex-wrap items-center gap-x-2 gap-y-0.5 py-1">
    <input type="radio" name="person_pick" value="p:{{ $person->id }}" @checked($checked) class="accent-cinnabar">
    <a href="{{ route('people.show', $person) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $person->name }}</a>
    <span class="text-muted tabular-nums">#{{ \App\Support\Fa::digits($person->id) }}</span>
    @if($years = \App\Http\Controllers\SuggestionController::lifeYears($person))
        <span class="text-muted">({{ $years }})</span>
    @endif
    <span class="text-muted">{{ \App\Support\Fa::num($person->manuscripts_count) }} نسخه، {{ \App\Support\Fa::num($person->works_count) }} اثر</span>
    @if($note)
        <span class="px-1.5 rounded-sm border border-cinnabar/40 bg-cinnabar/5 text-cinnabar text-xs">{{ $note }}</span>
    @endif
</label>
