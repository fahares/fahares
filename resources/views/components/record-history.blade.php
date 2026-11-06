@props(['record'])
{{-- The record's approved corrections, newest first, each as one sentence with whose suggestion it was (backlog item 39, see Contributions::forRecord) --}}
@php
    $history = \App\Suggestions\Contributions::forRecord($record);
    $shown = 10;
@endphp
@if($history->isNotEmpty())
<x-ui.section id="correction-history" title="تاریخچه‌ی اصلاحات" :count="$history->count()" x-data="{ all: false }">
    <ol class="space-y-2.5 text-sm leading-7">
        @foreach($history as $i => $item)
            @php $c = $item['contributor']; @endphp
            <li class="flex items-start gap-2.5" data-history="{{ $item['suggestion']->id }}"
                @if($i >= $shown) x-show="all" style="display: none" @endif>
                <x-ui.avatar :user="$c['user']" :name="$c['name']" :kind="$c['kind']" size="sm" />
                @php
                    // one sentence without stray spaces between its parts: the name, then the change read from this record's side
                    $link = fn (string $label, ?string $url, string $class) => $url
                        ? '<a href="' . e($url) . '" class="' . $class . '">' . e($label) . '</a>'
                        : '<span class="' . $class . '">' . e($label) . '</span>';
                    $sentence = e($c['kind'] === 'staff' ? 'به دست ' : 'به پیشنهاد ')
                        . $link($c['name'], $c['url'], $c['url'] ? 'font-bold text-ink hover:text-cinnabar' : ($c['kind'] === 'credited' ? 'font-bold text-ink' : 'font-bold text-muted')) . '، '
                        . collect($item['line'])->map(fn ($part) => is_array($part)
                            ? $link($part['label'], $part['url'], $part['url'] ? 'font-semibold text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition' : 'font-semibold')
                            : e($part))->implode('');
                @endphp
                <div class="min-w-0 break-words">
                    <p class="m-0">{!! $sentence !!}</p>
                    <time datetime="{{ $item['date']?->toIso8601String() }}" class="block text-[11px] text-muted">{{ \App\Support\Jalali::format($item['date'], 'long') }}</time>
                </div>
            </li>
        @endforeach
    </ol>
    @if($history->count() > $shown)
        <button type="button" x-show="! all" @click="all = true" class="mt-3 text-xs font-bold text-cinnabar hover:underline cursor-pointer">
            نمایش همه ({{ \App\Support\Fa::num($history->count()) }})
        </button>
    @endif
</x-ui.section>
@endif
