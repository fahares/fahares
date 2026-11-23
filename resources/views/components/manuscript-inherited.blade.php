@props(['manuscript', 'field'])
{{-- The mark beside a value the copy reads from its «مجموعه» (hand, lines, scribe; backlog item 163, step 14.324): its
     own entry is silent and the volume's treatises that speak agree. See Manuscript::inheritsFromCollection --}}
@php $stating = $manuscript->siblingsStating($field)->count(); @endphp
<span class="text-[11px] text-muted cursor-help border-b border-dotted border-line-strong" data-from-collection="{{ $field }}"
    title="{{ $field === 'scribe' && $manuscript->is_bika ? 'فهرست برای این رساله کاتبی نام نبرده است (بی‌کا).' : 'مدخل این رساله در این باره چیزی نگفته است.' }} {{ \App\Support\Fa::num($stating) }} رسالۀ دیگر این مجموعه چنین‌اند.">از مجموعه</span>
