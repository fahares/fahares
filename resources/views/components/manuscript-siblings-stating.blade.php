@props(['manuscript', 'field', 'what'])
{{-- Under an empty date or place of copying: what the volume's other treatises say (step 14.324, the user's wish). It is
     shown, not read as this treatise's: nothing of it reaches the search, the statistics or the estimates --}}
@php $stating = $manuscript->boundCollection() ? $manuscript->siblingsStating($field) : collect(); @endphp
@if($stating->isNotEmpty())
    <span class="block text-[12px] text-muted mt-0.5" data-siblings-stating="{{ $field }}">
        {{ $what }} رساله‌های دیگر این مجموعه:
        @foreach($stating->take(4) as $m)<a href="{{ route('manuscripts.show', $m) }}" class="text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ \App\Support\Fa::digits($m->{$field}) }}</a> <span dir="ltr">({{ \App\Support\Fa::digits($m->shelfmark) }})</span>@if(! $loop->last)، @endif @endforeach
        @if($stating->count() > 4)<a href="#collection" class="text-cinnabar hover:underline">و {{ \App\Support\Fa::num($stating->count() - 4) }} رسالۀ دیگر</a>@endif
    </span>
@endif
