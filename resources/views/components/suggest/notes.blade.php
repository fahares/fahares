@props(['type'])
{{-- Published research notes of the record, grouped by note type, with an invitation to add one (shared by the record page, see AppServiceProvider) --}}
@php
    $notes = $researchNotes ?? collect();
    $types = \App\Suggestions\NoteTypes::forRecord($type);
    $formatter = class_exists(\IntlDateFormatter::class)
        ? new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'd MMMM y')
        : null;
    $link = fn (?string $url, string $label = 'پیوند') => $url ? '<a href="' . e($url) . '" target="_blank" rel="nofollow noopener" class="text-[#B38A50] hover:underline font-bold">' . e($label) . ' ↗</a>' : '';
@endphp
@if($types)
<div id="research-notes" x-data class="scroll-mt-24 bg-white dark:bg-[#15192C] rounded-3xl p-5 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-2 pb-3.5 border-b border-stone-100 dark:border-stone-800/80">
        <div class="flex items-center gap-2">
            <span class="w-2 h-4 bg-[#292C56] dark:bg-indigo-400 rounded-sm"></span>
            <h3 class="text-xs sm:text-sm font-bold text-stone-900 dark:text-stone-100 tracking-wide">یادداشت‌ها و منابع پژوهشی</h3>
        </div>
        <button type="button" @click="$dispatch('suggest-field', { note: true })"
            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl border border-[#B38A50]/50 text-[11px] font-bold text-[#B38A50] hover:bg-amber-50 dark:hover:bg-amber-950/30 transition cursor-pointer">
            + افزودن یادداشت یا منبع
        </button>
    </div>

    @if($notes->isEmpty())
        <p class="text-xs text-stone-500 dark:text-stone-400 leading-relaxed">
            اگر درباره‌ی این {{ \App\Suggestions\SuggestionRegistry::typeLabel($type) }} نکته‌ای می‌دانید که در شناسنامه نیامده، یا چاپ، پژوهش، فهرست دیگر یا تصویری از آن سراغ دارید، آن را بیفزایید. پس از بررسی، با نام شما (اگر بخواهید) در اینجا منتشر می‌شود.
        </p>
    @endif

    @foreach($types as $key => $definition)
        @php $group = $notes->where('field_name', $key); @endphp
        @continue($group->isEmpty())
        <section class="space-y-3">
            <h4 class="text-xs font-bold text-stone-400">{{ $definition['label'] }}</h4>
            <ul class="space-y-4 text-sm">
                @foreach($group as $n)
                    @php $p = $n->payload ?? []; @endphp
                    <li class="space-y-1.5">
                        @switch($key)
                            @case('observation')
                                <p class="text-stone-800 dark:text-stone-100 leading-relaxed whitespace-pre-line break-words">{{ $p['text'] ?? '' }}</p>
                                @if(! empty($p['location']))<p class="text-[11px] text-stone-500">جای آن در نسخه: {{ $p['location'] }}</p>@endif
                                @break
                            @case('image')
                                @if(! empty($p['files']))
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                        @foreach($p['files'] as $i => $path)
                                            <a href="{{ route('notes.file', [$n, $i]) }}" target="_blank"><img src="{{ route('notes.file', [$n, $i]) }}" alt="{{ $p['caption'] ?? 'تصویر' }}" loading="lazy" class="w-full h-32 object-cover rounded-xl border border-stone-200 dark:border-stone-700 hover:opacity-90"></a>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="text-stone-800 dark:text-stone-100">{{ $p['caption'] ?? '' }} {!! $link($p['url'] ?? null, 'مشاهده‌ی تصویر در مخزن') !!}</p>
                                @break
                            @default
                                @php
                                    // Citation order: author, title, publication, publisher, place, year, volume, pages
                                    $tail = array_filter([$p['publication'] ?? null, $p['publisher'] ?? null, $p['place'] ?? null, $p['year'] ?? null,
                                        isset($p['volume']) ? 'ج ' . $p['volume'] : null, isset($p['page']) ? 'ص ' . $p['page'] : null, isset($p['pages']) ? 'ص ' . $p['pages'] : null]);
                                @endphp
                                <p class="text-stone-800 dark:text-stone-100 leading-relaxed">@if(! empty($p['author'])){{ $p['author'] }}، @endif<strong>{{ $p['title'] ?? '' }}</strong>@if($tail)، {{ implode('، ', $tail) }}@endif {!! $link($p['url'] ?? null) !!}</p>
                                @if(! empty($p['text']))<p class="text-xs text-stone-600 dark:text-stone-300 leading-relaxed whitespace-pre-line break-words">{{ $p['text'] }}</p>@endif
                        @endswitch
                        <p class="text-[11px] text-stone-500 dark:text-stone-400">
                            افزوده‌ی <strong class="text-stone-700 dark:text-stone-200">{{ $n->credit_name ?: 'یکی از پژوهشگران' }}</strong>@if($n->reviewed_at) · {{ $formatter ? $formatter->format($n->reviewed_at) : $n->reviewed_at->format('Y/m/d') }}@endif
                        </p>
                        @if($n->public_note)
                            <p class="text-xs text-stone-600 dark:text-stone-300 whitespace-pre-line"><span class="font-bold text-stone-500">یادداشت داور:</span> {{ $n->public_note }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
@endif
