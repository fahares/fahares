@props(['type', 'id'])
{{-- The record's views so far, for those who see the visit stats (backlog item 103) --}}
@can('visits.stats')
    <a href="{{ route('admin.visit-stats.index') }}" class="text-muted hover:text-cinnabar" title="بازدید این صفحه از آغاز آمار بازدید">{{ \App\Support\Fa::num(\App\Support\PageViews::total($type, (string) $id)) }} بازدید</a>
    <span class="text-line-strong">·</span>
@endcan
