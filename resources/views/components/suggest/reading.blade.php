@props(['s', 'tag', 'tone' => 'stone'])
{{-- One approved reading in <x-suggest.readings>: value, who holds it, when, the evidence and the reviewer's public note --}}
@php
    $formatter = class_exists(\IntlDateFormatter::class)
        ? new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'd MMMM y')
        : null;
    $tones = [
        'emerald' => 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-900/60',
        'sky' => 'bg-sky-50 dark:bg-sky-950/30 text-sky-800 dark:text-sky-300 border-sky-200 dark:border-sky-900/60',
        'stone' => 'bg-stone-100 dark:bg-stone-800 text-ink/80 border-line-strong',
    ];
    $linkify = fn (string $text) => preg_replace('~https?://[^\s<]+~u', '<a href="$0" target="_blank" rel="nofollow noopener" class="text-cinnabar hover:underline break-all" dir="ltr">$0</a>', e($text));
@endphp
<div x-data="{ open: false }" class="space-y-1.5">
    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <span class="px-2 py-0.5 rounded-md border text-[10px] font-bold {{ $tones[$tone] }}">{{ $tag }}</span>
        <span class="font-bold text-ink whitespace-pre-line">{{ $s->display_value }}</span>
    </div>
    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-[11px] text-muted">
        <span>{{ $s->standing === 'edit' ? 'ویرایش به پیشنهاد' : 'قائل:' }} <strong class="text-ink">{{ $s->credit_name ?: 'یکی از پژوهشگران' }}</strong></span>
        @if($s->reviewed_at)
            <span>· {{ $formatter ? $formatter->format($s->reviewed_at) : $s->reviewed_at->format('Y/m/d') }}</span>
        @endif
        @if($s->rationale_citation)
            <button type="button" @click="open = !open" class="font-bold text-cinnabar hover:underline cursor-pointer" x-text="open ? 'بستن مستند' : 'مستند'">مستند</button>
        @endif
    </div>
    @if($s->rationale_citation)
        <p x-show="open" x-cloak class="p-3 rounded-xl bg-paper text-xs text-ink/80 leading-loose whitespace-pre-line break-words">{!! $linkify($s->rationale_citation) !!}</p>
    @endif
    @if($s->standing === 'superseded' && $s->supersededBy)
        <p class="text-[11px] text-muted">جای خود را به قول «{{ \Illuminate\Support\Str::limit($s->supersededBy->display_value, 80) }}» داد.</p>
    @endif
    @if($s->public_note)
        <p class="text-xs text-ink/80 leading-relaxed whitespace-pre-line break-words"><span class="font-bold text-muted">یادداشت داور:</span> {!! $linkify($s->public_note) !!}</p>
    @endif
</div>
