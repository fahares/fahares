@props(['type'])
{{-- Published research notes of the record, grouped by note type, with an invitation to add one (shared by the record page, see AppServiceProvider) --}}
@php
    $notes = $researchNotes ?? collect();
    $types = \App\Suggestions\NoteTypes::forRecord($type);
    // What to invite, matched to the note types each record offers (NoteTypes::BY_RECORD)
    $invitation = match ($type) {
        'work' => 'اگر درباره‌ی این اثر نکته‌ای می‌دانید که در شناسنامه نیامده، یا چاپ، پژوهش یا نسخه دیگری از آن سراغ دارید، آن را بیفزایید.',
        'manuscript' => 'اگر درباره‌ی این نسخه نکته‌ای می‌دانید که در شناسنامه نیامده، یا چاپ، پژوهش، فهرست دیگر یا تصویری از آن سراغ دارید، آن را بیفزایید.',
        'person' => 'اگر درباره‌ی این شخص نکته‌ای می‌دانید که در شناسنامه نیامده، یا پژوهش، منبع یا تصویری درباره‌ی او سراغ دارید، آن را بیفزایید.',
        'library' => 'اگر درباره‌ی این کتابخانه نکته‌ای می‌دانید، یا پیوند مخزن دیجیتال، فهرست برخط یا پژوهشی درباره‌ی آن سراغ دارید، آن را بیفزایید.',
        default => 'اگر درباره‌ی این ' . \App\Suggestions\SuggestionRegistry::typeLabel($type) . ' نکته‌ای می‌دانید که در شناسنامه نیامده، آن را بیفزایید.',
    };
    $link = fn (?string $url, string $label = 'پیوند') => $url ? '<a href="' . e($url) . '" target="_blank" rel="nofollow noopener" class="text-cinnabar hover:underline font-bold">' . e($label) . ' ↗</a>' : '';
@endphp
@if($types)
<x-ui.section id="research-notes" x-data title="یادداشت‌ها و منابع پژوهشی">
    <x-slot:actions>
        <button type="button" @click="$dispatch('suggest-field', { note: true })"
            class="font-bold text-cinnabar hover:underline cursor-pointer">+ افزودن یادداشت یا منبع</button>
    </x-slot:actions>
    <div class="space-y-5">

    @if($notes->isEmpty())
        <p class="text-sm text-muted leading-relaxed max-w-[72ch]">
            {{ $invitation }} پس از بررسی، با نام شما (اگر بخواهید) در اینجا منتشر می‌شود.
        </p>
    @endif

    @foreach($types as $key => $definition)
        @php $group = $notes->where('field_name', $key); @endphp
        @continue($group->isEmpty())
        <section class="space-y-3">
            <h3 class="text-xs font-bold text-muted">{{ $definition['label'] }}</h3>
            <ul class="space-y-4 text-sm">
                @foreach($group as $n)
                    @php $p = $n->payload ?? []; @endphp
                    <li class="space-y-1.5">
                        @switch($key)
                            @case('observation')
                                <p class="text-ink leading-relaxed whitespace-pre-line break-words">{{ $p['text'] ?? '' }}</p>
                                @if(! empty($p['location']))<p class="text-[11px] text-muted">جای آن در نسخه: {{ $p['location'] }}</p>@endif
                                @break
                            @case('image')
                                @if(! empty($p['files']))
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                        @foreach($p['files'] as $i => $path)
                                            <a href="{{ route('notes.file', [$n, $i]) }}" target="_blank"><img src="{{ route('notes.file', [$n, $i]) }}" alt="{{ $p['caption'] ?? 'تصویر' }}" loading="lazy" class="w-full h-32 object-cover border border-line hover:opacity-90"></a>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="text-ink">{{ $p['caption'] ?? '' }} {!! $link($p['url'] ?? null, 'مشاهده‌ی تصویر در مخزن') !!}</p>
                                @break
                            @default
                                @php
                                    // Citation order: author, title, publication, publisher, place, year, volume, pages
                                    $tail = array_filter([$p['publication'] ?? null, $p['publisher'] ?? null, $p['place'] ?? null, $p['year'] ?? null,
                                        isset($p['volume']) ? 'ج ' . $p['volume'] : null, isset($p['page']) ? 'ص ' . $p['page'] : null, isset($p['pages']) ? 'ص ' . $p['pages'] : null]);
                                @endphp
                                <p class="text-ink leading-relaxed">@if(! empty($p['author'])){{ $p['author'] }}، @endif<strong>{{ $p['title'] ?? '' }}</strong>@if($tail)، {{ implode('، ', $tail) }}@endif {!! $link($p['url'] ?? null) !!}</p>
                                @if(! empty($p['text']))<p class="text-xs text-ink/80 leading-relaxed whitespace-pre-line break-words">{{ $p['text'] }}</p>@endif
                        @endswitch
                        <p class="text-[11px] text-muted">
                            افزوده‌ی <strong class="text-ink">{{ $n->credit_name ?: 'یکی از پژوهشگران' }}</strong>@if($n->reviewed_at) · @jdate($n->reviewed_at, 'long')@endif
                        </p>
                        @if($n->public_note)
                            <p class="text-xs text-ink/80 whitespace-pre-line"><span class="font-bold text-muted">یادداشت داور:</span> {{ $n->public_note }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
    </div>
</x-ui.section>
@endif
