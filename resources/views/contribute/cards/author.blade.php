{{-- A work whose author is a name only, and the one person of that name (AuthorLinkQueue::card). --}}
@use('App\Support\Fa')
<div class="space-y-1">
    <h2 class="font-naskh text-lg sm:text-xl font-bold text-ink leading-relaxed m-0">آیا پدیدآور این اثر همین شخص است؟</h2>
    <p class="text-xs sm:text-sm text-muted leading-6 m-0">
        فهرست فقط نام پدیدآور را آورده است. سال درگذشت شخص را با کهن‌ترین تاریخ کتابت نسخه‌های اثر بسنجید: نسخه‌ای که پیش از روزگار او نوشته شده، یعنی شخص دیگری است.
    </p>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <section class="p-3 border border-line bg-paper space-y-1.5 min-w-0">
        <h3 class="text-[11px] font-bold text-muted m-0">اثر</h3>
        @if($work)
            <a href="{{ route('works.show', $work) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar break-words leading-7">{{ $work->primary_title }}</a>
            <p class="m-0 text-xs sm:text-sm text-ink">پدیدآور در فهرست: <b>{{ $work->author_name }}</b></p>
            @if($translit)<p class="m-0 text-xs text-muted" dir="ltr">{{ $translit }}</p>@endif
            <p class="m-0 text-[11px] text-muted">
                {{ implode(' · ', array_filter([
                    $copies ? Fa::num($copies) . ' نسخه' : null,
                    $earliest ? 'کهن‌ترین کتابت ' . Fa::digits((string) $earliest) . ' ق' : null,
                ])) }}
            </p>
        @endif
    </section>

    <section class="p-3 border border-line bg-paper space-y-1.5 min-w-0">
        <h3 class="text-[11px] font-bold text-muted m-0">شخص در سایت</h3>
        @if($person)
            <a href="{{ route('people.show', $person) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar break-words leading-7">{{ $person->name }}</a>
            <p class="m-0 text-[11px] text-muted">
                {{ implode(' · ', array_filter([
                    $person->death_year_hijri ? 'درگذشت ' . Fa::digits((string) $person->death_year_hijri) . ' ق' : ($person->century_hijri ? 'سده‌ی ' . Fa::digits((string) $person->century_hijri) : null),
                    $person->life_dates_raw ? '«' . Fa::digits($person->life_dates_raw) . '»' : null,
                    $person->manuscripts_count ? Fa::num($person->manuscripts_count) . ' نسخه' : null,
                ])) }}
            </p>
            @if($profile && ($profile['works']->isNotEmpty() || $profile['copies']->isNotEmpty()))
                <ul class="m-0 ps-4 text-xs text-ink/85 leading-6 list-disc">
                    @foreach($profile['works'] as $w)<li>تألیف: {{ $w->title }}</li>@endforeach
                    @foreach($profile['copies'] as $c)<li>کتابت: {{ $c->title }} <span class="text-muted">({{ implode('، ', array_filter([$c->city, $c->year ? Fa::digits((string) $c->year) . ' ق' : null])) }})</span></li>@endforeach
                </ul>
            @else
                <p class="m-0 text-xs text-muted">اثر یا نسخه‌ی دیگری از او در سایت نیست.</p>
            @endif
        @endif
    </section>
</div>
