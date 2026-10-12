<label class="flex flex-wrap items-center gap-x-2 gap-y-0.5 py-1">
    <input type="radio" name="person_pick" value="p:{{ $person->id }}" @checked($checked) class="text-[#B38A50]">
    <a href="{{ route('people.show', $person) }}" target="_blank" class="font-bold text-[#292C56] dark:text-amber-200 hover:text-[#B38A50]">{{ $person->name }}</a>
    <span class="font-mono text-stone-400">#{{ $person->id }}</span>
    @if($years = \App\Http\Controllers\SuggestionController::lifeYears($person))
        <span class="text-stone-500">({{ $years }})</span>
    @endif
    <span class="text-stone-400">{{ (int) $person->manuscripts_count }} نسخه، {{ (int) $person->works_count }} اثر</span>
    @if($note)
        <span class="px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-950/50 text-[#B38A50] text-[10px] font-bold">{{ $note }}</span>
    @endif
</label>
