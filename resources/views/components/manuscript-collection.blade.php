@props(['manuscript'])
{{-- The other treatises bound in the same volume, found from the shelfmark («6415/8») and in the order of their leaves; see Manuscript::collectionKey and collectionSiblings --}}
@php $siblings = $manuscript->collectionSiblings(); @endphp
@if($siblings->count() > 1)
    @php
        // In some libraries the part number is a volume of one multi-volume work («13/1» to «13/5»: وسائل الشیعة)
        $volumes = $siblings->pluck('work_id')->unique()->count() === 1;
        $number = $manuscript->collection_key ?? \App\Models\Manuscript::collectionKeyOf($manuscript->shelfmark, $manuscript->raw_text);
    @endphp
    @php
        // What is the volume's own (backlog item 163, step 14.324): its record's values, worked out from the entries of
        // its treatises (App\Support\CollectionValues). Null for the volumes of one work and for a copy not linked yet
        $volume = $volumes ? null : $manuscript->boundCollection();
        $basis = $volume?->basis ?? [];
        $folded = fn ($text) => \App\Support\CollectionValues::folded($text);
        $own = [
            'dimensions' => ['اندازه (سانتی‌متر)', fn ($v) => \App\Support\Fa::digits($v)],
            'paper' => ['کاغذ', fn ($v) => $v],
            'binding' => ['جلد', fn ($v) => $v],
        ];
        $volumeNotes = $volume ? $manuscript->collectionNotes() : collect();
    @endphp
    <x-ui.section id="collection" x-data="{ all: false }" data-collection="{{ $volume?->id }}"
        :title="$volumes ? 'مجلدهای این دوره' : ($volume ? 'مجموعۀ ' . \App\Support\Fa::digits($number) : 'رساله‌های این مجموعه')" :count="$siblings->count()">
        <div class="mb-2">
            <p class="text-xs text-muted">
                @if($volumes)
                    مجلدهای همین اثر در همین کتابخانه که زیر شمارۀ {{ $number }} آمده‌اند.
                @else
                    این نسخه در مجموعۀ شمارۀ <span dir="ltr">{{ \App\Support\Fa::digits($number) }}</span>{{ $manuscript->rangeLabel() ? '، ' . $manuscript->rangeLabel() : '' }}، همراه با این رساله‌ها{{ $siblings->contains(fn ($m) => $m->range_from) ? ' (به ترتیب برگ)' : '' }} صحافی شده است.
                    @if(\App\Models\Manuscript::isRunKey($number))
                        کتابخانه به هر رسالۀ این مجلد شماره‌ای جداگانه داده است؛ پیوستگی برگ‌ها و اندازۀ یکسانشان نشان می‌دهد که با هم صحافی شده‌اند.
                    @elseif(! \App\Models\Manuscript::collectionKey($manuscript->shelfmark) && ! $manuscript->provenance())
                        فهرست شمارۀ بخش رساله‌ها را نیاورده است؛ پیوستگی برگ‌هایشان نشان می‌دهد که در یک مجلدند.
                    @endif
                @endif
            </p>
        </div>
        @if($volume)
            <x-ui.meta-list class="mb-4" data-collection-values>
                @foreach($own as $field => [$label, $show])
                    @php
                        $value = $volume->{$field};
                        $mine = $manuscript->{$field};
                        // the one treatise whose entry gives it, when only one does
                        $only = ($basis[$field] ?? null) === \App\Support\CollectionValues::SINGLE ? $siblings->first(fn ($m) => filled($m->{$field})) : null;
                        $differs = filled($value) && filled($mine) && ! str_contains((string) $folded($value), (string) $folded($mine));
                    @endphp
                    {{-- the pencil corrects the collection's value, for all its treatises (step 14.330) --}}
                    <x-ui.meta :label="$label" :field="\App\Suggestions\SuggestionRegistry::COLLECTION_PREFIX . $field" :empty="blank($value) && blank($mine)">
                        @if(filled($value))
                            {{ $show($value) }}
                            @if(($basis[$field] ?? null) === 'editor')<span class="block text-[12px] text-muted" data-collection-basis="editor">به تصحیح ویراستار، برای همۀ مجموعه.</span>@endif
                            @if($only)<span class="block text-[12px] text-muted">تنها در مدخل {{ $only->is($manuscript) ? 'همین رساله' : 'رسالۀ ' . \App\Support\Fa::digits($only->shelfmark) }} آمده است.</span>@endif
                            @if($differs)<span class="block text-[12px] text-muted" data-own-entry="{{ $field }}">در مدخل این رساله: {{ $show($mine) }}</span>@endif
                        @else
                            {{ $show($mine) }}
                            <span class="block text-[12px] text-muted" data-own-entry="{{ $field }}">در مدخل این رساله؛ مدخل رساله‌های این مجموعه در این باره هم‌سخن نیست.</span>
                        @endif
                    </x-ui.meta>
                @endforeach
                @if($volume->copy_date_raw)
                    <x-ui.meta label="تاریخ کتابت مشترک">{{ \App\Support\Fa::digits($volume->copy_date_raw) }} <span class="text-[12px] text-muted">(در مدخل همۀ رساله‌ها)</span></x-ui.meta>
                @endif
                @if($volume->copy_place)
                    <x-ui.meta label="محل کتابت مشترک">{{ $volume->copy_place }} <span class="text-[12px] text-muted">(در مدخل همۀ رساله‌ها)</span></x-ui.meta>
                @endif
            </x-ui.meta-list>
            @if($volumeNotes->isNotEmpty())
                <div class="mb-4" data-collection-notes>
                    <h3 class="text-xs font-bold text-muted mb-2">مهرها، تملک‌ها و وقف‌نامه‌ها در مدخل رساله‌های دیگر این مجموعه</h3>
                    <ul class="space-y-2 text-sm">
                        @foreach($volumeNotes as $row)
                            <li class="flex items-start gap-3 leading-relaxed">
                                <span class="mt-0.5 shrink-0 w-20 text-center text-[11px] font-bold py-0.5 border border-line text-muted">{{ \App\Models\ManuscriptNote::TYPES[$row['note']->type] ?? $row['note']->type }}</span>
                                <span class="text-ink whitespace-pre-line">{{ \App\Support\Fa::digits($row['note']->text) }}
                                    <span class="block text-[11px] text-muted">از مدخل @foreach($row['copies'] as $m)<a href="{{ route('manuscripts.show', $m) }}" class="hover:text-cinnabar" dir="ltr">{{ \App\Support\Fa::digits($m->shelfmark) }}</a>@if(! $loop->last)، @endif @endforeach</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <h3 class="text-xs font-bold text-muted mb-2">رساله‌های این مجموعه</h3>
        @endif
        <ol class="divide-y divide-line text-sm border-y border-line">
            @foreach($siblings as $i => $m)
                @php $current = $m->is($manuscript); @endphp
                <li @if($i >= 12) x-show="all" x-cloak @endif class="py-2 flex items-baseline gap-3 {{ $current ? 'font-bold' : '' }}">
                    <span class="shrink-0 w-16 text-muted tabular-nums" dir="ltr">{{ \App\Support\Fa::digits($m->shelfmark) }}</span>
                    <span class="flex-1 min-w-0">
                        @if($current)
                            <span class="text-ink">{{ $m->work?->primary_title ?? 'نسخه' }}</span>
                            <span class="text-[10px] text-emerald-700 dark:text-emerald-400">(همین نسخه)</span>
                        @else
                            <a href="{{ route('manuscripts.show', $m) }}" class="text-ink hover:text-cinnabar transition">{{ $m->work?->primary_title ?? 'نسخه' }}</a>
                        @endif
                        @if($added = $m->provenance())<x-ui.chip class="!text-[10px] !py-0" title="{{ $added['label'] }}" data-added-copy>{{ $added['chip'] }}</x-ui.chip>@endif
                        @if($m->inMargin())<span class="text-[10px] text-cinnabar" title="به گفتۀ فهرست، در هامش رساله‌ای دیگر نوشته شده است">(در حاشیه)</span>@endif
                        @if($m->work?->author_label)<span class="text-[11px] text-muted"> · {{ $m->work->author_label }}</span>@endif
                        {{-- the scribe, the date and the place of copying of each treatise (step 14.324, the user's wish) --}}
                        @php $copied = array_filter([$m->scribe_name ? 'کاتب: ' . $m->scribe_name : null, $m->copy_date_raw ? \App\Support\Fa::digits($m->copy_date_raw) : null, $m->copy_place]); @endphp
                        @if($copied)<span class="block text-[11px] text-muted font-normal" data-copied>{{ implode(' · ', $copied) }}</span>@endif
                    </span>
                    @if($m->extentLabel())<span class="shrink-0 text-[11px] text-muted">{{ $m->extentLabel() }}</span>@endif
                </li>
            @endforeach
        </ol>
        @if($siblings->count() > 12)
            <button type="button" x-show="!all" @click="all = true" class="text-[11px] font-bold text-cinnabar hover:underline cursor-pointer">نمایش همۀ {{ \App\Support\Fa::num($siblings->count()) }} {{ $volumes ? 'مجلد' : 'رساله' }}</button>
        @endif
    </x-ui.section>
@endif
{{-- For the reviewers: the treatise placed in another collection of its library by hand, or given back to the
     collection its shelfmark names (backlog item 163, step 14.330) --}}
@can('suggestions.direct_edit')
    @if($manuscript->library_id)
        <form method="POST" action="{{ route('admin.manuscripts.collection', $manuscript->id) }}" class="text-xs flex flex-wrap items-center gap-2" x-show="$store.suggest.on" x-cloak data-move-collection>
            @csrf
            <label for="move-collection-key" class="font-bold text-ink">{{ $manuscript->collection_pinned ? 'این رساله دستی در این مجموعه گذاشته شده است. مجموعۀ دیگر:' : 'انتقال این رساله به مجموعۀ دیگر همین کتابخانه:' }}</label>
            <input type="text" name="collection_key" id="move-collection-key" dir="ltr" maxlength="100" placeholder="شمارۀ مجموعه" class="w-32 border border-line bg-surface px-2 py-1">
            <button type="submit" class="font-bold text-cinnabar hover:underline cursor-pointer">انتقال</button>
            @if($manuscript->collection_pinned)<button type="submit" name="collection_key" value="" class="text-muted hover:text-cinnabar hover:underline cursor-pointer">بازگشت به مجموعۀ شمارۀ نسخه</button>@endif
        </form>
    @endif
@endcan
{{-- For those who may make a copy's record (backlog item 149): another treatise of this copy's volume --}}
@can('manuscripts.create')
    <p class="text-xs" data-add-sibling>
        <a href="{{ route('admin.manuscripts.create', ['from' => $manuscript->id]) }}" class="font-bold text-cinnabar hover:underline">＋ جزء دیگری از همین مجموعه</a>
        <span class="text-muted">— ساختن رکورد رساله‌ای از این مجلد که در فهرست نیامده است</span>
    </p>
@endcan
