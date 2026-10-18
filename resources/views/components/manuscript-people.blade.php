@props(['manuscript'])
{{-- People connected with the copy other than its scribes, grouped by role; with an invitation to add one --}}
@php
    $people = $manuscript->otherPeople()->with(['person', 'suggestion'])->get();
    $roles = \App\Models\ManuscriptPerson::ROLES;
@endphp
<x-ui.section x-data title="اشخاص مرتبط با نسخه">
    <x-slot:actions>
        <button type="button" @click="$dispatch('suggest-field', { general: true, relation: 'person_role' })"
            class="font-bold text-cinnabar hover:underline cursor-pointer">+ افزودن مالک، واقف، مصحح…</button>
    </x-slot:actions>
    @if($people->isEmpty())
        <p class="text-sm text-muted leading-relaxed max-w-[72ch]">مالکان، واقفان، مصححان، محشیان و دیگر اشخاصی که در این نسخه نشانی از خود گذاشته‌اند هنوز ثبت نشده‌اند. اگر مهر، یادداشت تملک یا وقف‌نامه‌ای در نسخه دیده‌اید، آن را بیفزایید.</p>
    @else
        <dl class="grid grid-cols-1 sm:grid-cols-[max-content_minmax(0,1fr)] gap-x-8 gap-y-1 sm:gap-y-3 text-sm m-0">
            @foreach($people->sortBy(fn ($p) => array_search($p->role, array_keys($roles), true))->groupBy('role') as $role => $group)
                <div class="contents">
                    <dt class="text-[13px] text-muted">{{ $roles[$role] ?? $role }}</dt>
                    <dd class="m-0 mb-2 sm:mb-0 space-y-1">
                        @foreach($group as $p)
                            <div>
                                @if($p->person)
                                    <a href="{{ route('people.show', $p->person) }}" class="font-semibold text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $p->person->name }}</a>
                                @else
                                    <span class="font-semibold text-ink">{{ $p->name }}</span>
                                @endif
                                @if($p->note)<span class="text-[11px] text-muted">({{ $p->note }})</span>@endif
                                @if($p->suggestion?->credit_name)<span class="block text-[10px] text-muted">به پیشنهاد {{ $p->suggestion->credit_name }}</span>@endif
                            </div>
                        @endforeach
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif
</x-ui.section>
