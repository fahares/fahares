@props(['manuscript'])
{{-- People connected with the copy other than its scribes, grouped by role; with an invitation to add one --}}
@php
    $people = $manuscript->otherPeople()->with(['person', 'suggestion'])->get();
    $roles = \App\Models\ManuscriptPerson::ROLES;
@endphp
<div x-data class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-bold text-[#292C56] dark:text-amber-200 border-r-2 border-[#B38A50] pr-2">اشخاص مرتبط با نسخه</h3>
        <button type="button" @click="$dispatch('suggest-field', { general: true, relation: 'person_role' })"
            class="text-[11px] font-bold text-[#B38A50] hover:underline cursor-pointer">+ افزودن مالک، واقف، مصحح…</button>
    </div>
    @if($people->isEmpty())
        <p class="text-xs text-stone-500 dark:text-stone-400 leading-relaxed">مالکان، واقفان، مصححان، محشیان و دیگر اشخاصی که در این نسخه نشانی از خود گذاشته‌اند هنوز ثبت نشده‌اند. اگر مهر، یادداشت تملک یا وقف‌نامه‌ای در نسخه دیده‌اید، آن را بیفزایید.</p>
    @else
        <dl class="divide-y divide-stone-100 dark:divide-stone-800 text-xs">
            @foreach($people->sortBy(fn ($p) => array_search($p->role, array_keys($roles), true))->groupBy('role') as $role => $group)
                <div class="flex justify-between gap-3 py-2">
                    <dt class="text-stone-400 shrink-0">{{ $roles[$role] ?? $role }}:</dt>
                    <dd class="text-left space-y-1">
                        @foreach($group as $p)
                            <div>
                                @if($p->person)
                                    <a href="{{ route('people.show', $p->person) }}" class="font-bold text-[#B38A50] hover:underline">{{ $p->person->name }}</a>
                                @else
                                    <span class="font-bold text-stone-800 dark:text-stone-200">{{ $p->name }}</span>
                                @endif
                                @if($p->note)<span class="text-[11px] text-stone-500 dark:text-stone-400">({{ $p->note }})</span>@endif
                                @if($p->suggestion?->credit_name)<span class="block text-[10px] text-stone-400">به پیشنهاد {{ $p->suggestion->credit_name }}</span>@endif
                            </div>
                        @endforeach
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
