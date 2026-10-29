@props(['work'])
{{-- People connected with the work other than its authors, grouped by role with the catalogue's hedge; with an invitation to add one --}}
@php
    $people = $work->workPeople()->with(['person', 'suggestion'])->get();
    $roles = \App\Models\WorkPerson::ROLES;
@endphp
<x-ui.section x-data title="اشخاص مرتبط با اثر">
    <x-slot:actions>
        <button type="button" @click="$dispatch('suggest-field', { general: true, relation: 'person_role' })"
            class="font-bold text-cinnabar hover:underline cursor-pointer">+ افزودن مترجم، تقدیم‌شده به، سفارش‌دهنده…</button>
    </x-slot:actions>
    @if($people->isEmpty())
        <p class="text-sm text-muted leading-relaxed max-w-[72ch]">مترجم، کسی که اثر به او تقدیم شده یا به سفارش او نوشته شده، و دیگر اشخاصی که با این اثر پیوند دارند هنوز ثبت نشده‌اند. اگر در دیباچه یا در فهرستی نام آنان را دیده‌اید، بیفزایید.</p>
    @else
        <dl class="grid grid-cols-1 sm:grid-cols-[max-content_minmax(0,1fr)] gap-x-8 gap-y-1 sm:gap-y-3 text-sm m-0">
            @foreach($people->sortBy(fn ($p) => array_search($p->role, array_keys($roles), true))->groupBy('role') as $role => $group)
                <div class="contents">
                    <dt class="text-[13px] text-muted">{{ $roles[$role] ?? $role }}</dt>
                    <dd class="m-0 mb-2 sm:mb-0 space-y-1">
                        @foreach($group as $p)
                            <div>
                                @if($p->hedge_label)<span class="text-[12px] text-muted" title="به تردید فهرست‌نگار">{{ $p->hedge_label }}</span>@endif
                                @if($p->person)
                                    <a href="{{ route('people.show', $p->person) }}" class="font-semibold text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $p->person->name }}</a>
                                    <x-ui.life :person="$p->person" />
                                @else
                                    <span class="font-semibold text-ink">{{ $p->name }}</span>
                                @endif
                                @if($p->note)<span class="text-[11px] text-muted">({{ $p->note }})</span>@endif
                                <button type="button" x-show="$store.suggest.on" x-cloak
                                    @click="$dispatch('suggest-field', @js(['general' => true, 'relation' => 'person_role_removed', 'role' => $p->role, 'roleName' => $p->person ? null : $p->name, 'roleRowId' => $p->id, 'target' => $p->person ? ['id' => $p->person->id, 'label' => $p->person->name, 'url' => route('people.show', $p->person)] : null]))"
                                    class="ms-1 text-[11px] text-cinnabar hover:underline cursor-pointer">پیشنهاد حذف</button>
                                @if($p->suggestion?->credit_name)<span class="block text-[10px] text-muted">به پیشنهاد {{ $p->suggestion->credit_name }}</span>@endif
                            </div>
                        @endforeach
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif
</x-ui.section>
