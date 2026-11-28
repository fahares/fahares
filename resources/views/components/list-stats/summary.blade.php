@props(['param', 'id'])
{{--
    The summary of a long list (backlog item 106): drawn here when it is in the cache; otherwise the page asks for it
    once shown (route list-stats), as building it reads every copy of the list.
--}}
@php
    $id = (int) $id;
    $shown = \App\Support\ListStats::shown($param, $id);
    $stats = $shown ? \App\Support\ListStats::cached($param, $id) : null;
@endphp
@if($stats)
    @include('list-stats.panel', ['stats' => $stats])
@elseif($shown)
    <div class="mb-4 border border-line bg-surface px-4 py-3 min-h-[7.5rem] text-xs text-muted" data-list-stats-pending="{{ $param }}" aria-busy="true"
         x-data x-init="fetch(@js(route('list-stats', [$param, $id])), { headers: { Accept: 'text/html' } }).then(r => r.ok ? r.text() : '').then(html => { $el.outerHTML = html })">
        در حال شمارش نسخه‌های این فهرست…
    </div>
@endif
