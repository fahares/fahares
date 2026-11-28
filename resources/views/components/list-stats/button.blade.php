@props(['param', 'id'])
{{-- «آمار این فهرست» beside the heading of a long list (backlog item 106): the way to the list's insights page --}}
@if(\App\Support\ListStats::shown($param, (int) $id))
    <x-ui.btn size="sm" :href="\App\Support\ListStats::url($param, (int) $id)" rel="nofollow" data-list-stats-link>
        <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2 13h12v1H2zM3 8h2v4H3zm4-4h2v8H7zm4 2h2v6h-2z"/></svg>
        آمار این فهرست
    </x-ui.btn>
@endif
