@props(['toc', 'open' => false])

{{-- A volume's table of contents (step 13.84): its parts, and the letters under a part, each leading to its page text;
     a heading whose page the site lacks stays plain. Folded under its volume, open for a book in one volume. --}}
<details {{ $attributes->class('group') }} @if($open) open @endif data-source-toc>
    <summary class="inline-flex items-center gap-1 text-xs font-semibold text-cinnabar cursor-pointer select-none hover:underline">
        فهرست مطالب <span class="text-muted font-normal">({{ \App\Support\Fa::num(count($toc)) }})</span>
    </summary>
    <ol class="mt-3 columns-1 sm:columns-2 lg:columns-3 gap-8 text-sm">
        @foreach($toc as $h)
            <li @class(['break-inside-avoid flex items-baseline gap-2 py-0.5', 'ps-5 text-[13px]' => $h['level'] > 1, 'font-semibold' => $h['level'] === 1])>
                @if($h['url'])
                    <a href="{{ $h['url'] }}" class="flex-1 min-w-0 text-ink hover:text-cinnabar">{{ \App\Support\Fa::digits($h['title']) }}</a>
                @else
                    <span class="flex-1 min-w-0 text-muted">{{ \App\Support\Fa::digits($h['title']) }}</span>
                @endif
                <span class="shrink-0 text-[11px] text-muted tabular-nums">{{ \App\Support\Fa::digits($h['page']) }}</span>
            </li>
        @endforeach
    </ol>
</details>
