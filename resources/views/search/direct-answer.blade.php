{{-- The direct answer to a search that names a shelfmark, a bare number or a catalogue page (backlog item 83, phase 2) --}}
@php
    use App\Support\DirectAnswer;
    use App\Support\Fa;

    $lib = $direct->library;
    $libName = $lib ? (str_starts_with($lib->display_name, 'کتابخانه') ? $lib->display_name : 'کتابخانه‌ی ' . $lib->display_name) : null;
    $place = fn ($l) => trim($l->name . ' ' . $l->city);
    $shown = 12;
@endphp
<x-ui.card framed class="space-y-3" x-data="{ more: false }">
    @if($direct->kind === DirectAnswer::NUMBER)
        <h2 class="rubric text-base">نسخه‌ای با شماره‌ی <span dir="ltr">{{ Fa::digits($direct->number) }}</span> می‌جویید؟</h2>
        <p class="text-xs text-muted">این شماره در {{ Fa::num($direct->libraryCounts->count()) }} کتابخانه هست. کتابخانه را برگزینید:</p>
        <div class="flex flex-wrap gap-1.5 text-xs">
            @foreach($direct->libraryCounts->values() as $i => $row)
                <a href="{{ route('search', ['q' => $place($row['library']) . ' ' . $direct->number, 'type' => 'manuscripts']) }}" title="{{ $row['library']->display_name }}"
                   @if($i >= $shown) x-show="more" x-cloak @endif
                   class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm border border-line bg-paper hover:border-cinnabar hover:text-cinnabar transition">
                    {{ $row['library']->short_name }}@if($row['library']->city)<span class="text-muted">، {{ $row['library']->city }}</span>@endif
                    <span class="tabular-nums text-muted">{{ Fa::num($row['count']) }}</span>
                </a>
            @endforeach
            @if($direct->libraryCounts->count() > $shown)
                <button type="button" x-show="!more" @click="more = true" class="px-2.5 py-1 text-cinnabar hover:underline">و {{ Fa::num($direct->libraryCounts->count() - $shown) }} کتابخانه‌ی دیگر</button>
            @endif
        </div>
    @else
        <h2 class="rubric text-base">
            @if($direct->kind === DirectAnswer::SHELFMARK)
                شماره‌ی <span dir="ltr">{{ Fa::digits($direct->number) }}</span> در {{ $libName }}
            @else
                {{ $direct->source ? ($direct->source->short_name ?: $direct->source->title) : ($direct->fankha ? 'فنخا' : 'فهرست‌های ' . $libName) }}@if($direct->volume)، ج {{ Fa::digits($direct->volume) }}@endif، ص {{ Fa::digits($direct->page) }}
            @endif
            @if($direct->manuscripts->isNotEmpty())<span class="font-sans text-xs font-semibold text-muted tabular-nums">{{ Fa::num($direct->manuscripts->count()) }} نسخه</span>@endif
            @if($direct->items->isNotEmpty())<span class="font-sans text-xs font-semibold text-muted tabular-nums">{{ Fa::num($direct->items->count()) }} مدخل</span>@endif
        </h2>

        @if($direct->isEmpty())
            <p class="text-sm text-muted">
                @if($direct->kind === DirectAnswer::SHELFMARK)
                    در این کتابخانه نسخه‌ای با این شماره ثبت نشده است.
                @else
                    مدخلی در این جلد و صفحه پیدا نشد.
                @endif
            </p>
        @endif

        {{-- The printed entries of a source on that page (phase 7) --}}
        @if($direct->items->isNotEmpty())
            <ul class="divide-y divide-line text-[13px]">
                @foreach($direct->items as $i => $item)
                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5" @if($i >= $shown) x-show="more" x-cloak @endif>
                        <a href="{{ $item->url() }}" class="font-naskh text-base font-bold text-ink hover:text-cinnabar">{{ $item->title ?: 'مدخل بی‌عنوان' }}</a>
                        <span class="text-xs text-muted">{{ $item->placeLabel() }}</span>
                    </li>
                @endforeach
            </ul>
            @if($direct->items->count() > $shown)
                <button type="button" x-show="!more" @click="more = true" class="text-xs text-cinnabar hover:underline">و {{ Fa::num($direct->items->count() - $shown) }} مدخل دیگر</button>
            @endif
        @endif

        @foreach([['works', 'آثار'], ['manuscripts', 'نسخه‌ها']] as [$list, $label])
            @if($direct->$list->isNotEmpty())
                @if($direct->works->isNotEmpty())<h3 class="text-xs font-bold text-muted">{{ $label }}</h3>@endif
                <ul class="divide-y divide-line text-[13px]">
                    @foreach($direct->$list as $i => $item)
                        @php $work = $list === 'works' ? $item : $item->work; @endphp
                        <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5" @if($i >= $shown) x-show="more" x-cloak @endif>
                            @if($list === 'manuscripts')
                                <a href="{{ route('manuscripts.show', $item) }}" dir="ltr" class="font-bold text-cinnabar hover:underline tabular-nums">{{ $item->shelfmark ?? 'بی‌شماره' }}</a>
                                @if($direct->kind === DirectAnswer::CITATION)<span class="text-xs text-muted">{{ $item->libraryRecord?->short_name ?? $item->library }}</span>@endif
                            @endif
                            <a href="{{ $list === 'works' ? route('works.show', $item) : route('manuscripts.show', $item) }}" class="font-naskh text-base font-bold text-ink hover:text-cinnabar">{{ $work?->primary_title ?? 'نسخه‌ی بی‌عنوان' }}</a>
                            @if($work?->author ?? null)<span class="text-xs text-muted">{{ $work->author->name }}</span>@elseif($work?->author_name)<span class="text-xs text-muted">{{ $work->author_name }}</span>@endif
                        </li>
                    @endforeach
                </ul>
                @if($direct->$list->count() > $shown)
                    <button type="button" x-show="!more" @click="more = true" class="text-xs text-cinnabar hover:underline">و {{ Fa::num($direct->$list->count() - $shown) }} مورد دیگر</button>
                @endif
            @endif
        @endforeach

        @if($direct->alternatives->isNotEmpty())
            <p class="text-xs text-muted pt-2 border-t border-line">
                کتابخانه‌ی دیگری با همین نام:
                @foreach($direct->alternatives as $alt)
                    <a href="{{ route('search', ['q' => $place($alt) . ' ' . ($direct->kind === DirectAnswer::SHELFMARK ? $direct->number : ($direct->volume ? 'ج ' . $direct->volume . ' ' : '') . 'ص ' . $direct->page), 'type' => 'manuscripts']) }}" class="text-cinnabar hover:underline">{{ $alt->display_name }}@if($alt->city) ({{ $alt->city }})@endif</a>@if(! $loop->last)، @endif
                @endforeach
            </p>
        @endif
    @endif
</x-ui.card>
