@props(['options'])

{{-- One-click filters above a list; each option is ['label', 'url', 'active', 'count' (optional), 'key' (optional:
     names the count for a script that keeps it current, as data-tab-count)] --}}
<nav {{ $attributes->class('flex flex-wrap items-center gap-1.5 mb-3 text-xs') }}>
    @foreach($options as $o)
        <a href="{{ $o['url'] }}" @if($o['active']) aria-current="true" @endif
           class="inline-flex items-center gap-1.5 px-3 py-1 rounded-sm border transition {{ $o['active'] ? 'border-cinnabar bg-cinnabar/5 text-cinnabar font-bold' : 'border-line bg-surface text-ink hover:border-cinnabar hover:text-cinnabar' }}">
            {{ $o['label'] }}
            @isset($o['count'])
                <span class="tabular-nums text-muted" @isset($o['key']) data-tab-count="{{ $o['key'] }}" data-n="{{ $o['count'] }}" @endisset>{{ \App\Support\Fa::num($o['count']) }}</span>
            @endisset
        </a>
    @endforeach
</nav>
