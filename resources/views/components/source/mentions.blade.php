@props(['mentions', 'title', 'note'])

{{-- Where the sources' page texts name a record, the reverse of their links to it (App\Support\SourceMentions, steps
     13.136 and 13.140): each with its page, linking the mark there, the entry it is in, and the words around it. --}}
@if($mentions->isNotEmpty())
    <x-ui.section :title="$title" :count="$mentions->flatten(1)->count()">
        @foreach($mentions as $source => $rows)
            @if($mentions->count() > 1)<h3 class="text-sm font-bold text-ink mb-2">{{ $source }}</h3>@endif
            <ul class="divide-y divide-line border-y border-line text-sm" data-mentions>
                @foreach($rows as $row)
                    <li class="py-2.5">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <a href="{{ $row['url'] }}" class="font-semibold text-ink hover:text-cinnabar transition">{{ $mentions->count() > 1 ? '' : $source . '، ' }}{{ $row['place'] }}</a>
                            @if($row['entry'])
                                <span class="text-xs text-muted">مدخل <a href="{{ $row['entry']->url() }}" class="font-naskh text-ink hover:text-cinnabar">{{ $row['entry']->title }}</a></span>
                            @endif
                        </div>
                        <p class="mt-1 font-naskh text-[15px] leading-loose text-muted">…{{ \App\Support\Fa::digits($row['before']) }} <mark class="bg-transparent text-ink font-bold">{{ \App\Support\Fa::digits($row['name']) }}</mark> {{ \App\Support\Fa::digits($row['after']) }}…</p>
                    </li>
                @endforeach
            </ul>
        @endforeach
        <p class="mt-2 text-xs text-muted">{{ $note }}</p>
    </x-ui.section>
@endif
