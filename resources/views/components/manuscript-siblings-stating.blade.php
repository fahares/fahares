@props(['manuscript', 'field', 'what'])
{{-- Under an empty date or place of copying: what the volume's other treatises say (step 14.324, the user's wish). It is
     shown, not read as this treatise's: nothing of it reaches the search, the statistics or the estimates --}}
@php
    use App\Support\Fa;
    // treatises that say the same (in the form two statements are compared in) stand under one value, in the order of their leaves
    $groups = ($manuscript->boundCollection() ? $manuscript->siblingsStating($field) : collect())
        ->groupBy(fn ($m) => \App\Support\CollectionValues::compared($field, $m))->values();
    $link = fn ($m) => '<a href="' . e(route('manuscripts.show', $m)) . '" class="border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition" dir="ltr">' . e(Fa::digits($m->shelfmark)) . '</a>';
    // «۲۱۷۱۴/۱ و ۲۱۷۱۴/۲»، «الف، ب و ج»، and past six «… و ۳ رسالۀ دیگر»
    $names = function ($same) use ($link) {
        $links = $same->take(6)->map($link)->all();
        if ($same->count() > 6) {
            return implode('، ', $links) . ' و ' . Fa::num($same->count() - 6) . ' رسالۀ دیگر';
        }
        $last = array_pop($links);

        return $links ? implode('، ', $links) . ' و ' . $last : $last;
    };
    $parts = $groups->take(4)->map(fn ($same) => '<span class="text-ink">' . e(Fa::digits($same->first()->{$field})) . '</span> (' . $names($same) . ')')->implode('؛ ');
@endphp
@if($groups->isNotEmpty())
    <span class="block text-[12px] text-muted mt-0.5" data-siblings-stating="{{ $field }}">
        {{ $what }} رساله‌های دیگر این مجموعه: {!! $parts !!}
        @if($groups->count() > 4)<a href="#collection" class="text-cinnabar hover:underline">و {{ Fa::num($groups->count() - 4) }} {{ $field === 'copy_place' ? 'محل' : 'تاریخ' }} دیگر</a>@endif
    </span>
@endif
