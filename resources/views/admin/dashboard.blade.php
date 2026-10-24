@extends('layouts.admin')

@section('title', 'پیشخوان')
@section('page_title', 'پیشخوان')

@section('content')
<div class="space-y-6">

    @if($queue)
        <section>
            <h2 class="rubric text-lg mb-3">منتظر داوری</h2>
            {{-- The work waiting, in the gold double ruling of a manuscript page; a phone shows two columns --}}
            <div class="grid grid-cols-2 {{ [1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-4'][count($queue)] ?? 'lg:grid-cols-5' }} bg-surface border border-gold shadow-[inset_0_0_0_3px_var(--surface),inset_0_0_0_4px_var(--line)] p-1.5">
                @foreach($queue as $i => $q)
                    <a href="{{ $q['url'] }}"
                       class="flex flex-col gap-0.5 px-4 py-3 hover:bg-paper transition border-line {{ $i % 2 ? 'border-s' : '' }} {{ $i >= 2 ? 'border-t lg:border-t-0' : '' }} {{ $i ? 'lg:border-s' : '' }} {{ $loop->last && $i % 2 === 0 ? 'col-span-2 lg:col-span-1' : '' }}">
                        <span class="text-3xl font-extrabold tabular-nums leading-tight {{ $q['count'] ? 'text-cinnabar' : 'text-muted font-semibold' }}">{{ \App\Support\Fa::num($q['count']) }}</span>
                        <span class="text-[13px] font-bold text-ink">{{ $q['label'] }}</span>
                        <span class="text-xs text-muted">{{ $q['hint'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] gap-6 items-start">

        @can('suggestions.review')
            <x-ui.card>
                <div class="flex items-baseline justify-between gap-3 mb-2">
                    <h2 class="rubric text-base">تازه‌ترین پیشنهادها</h2>
                    <a href="{{ route('admin.suggestions.index') }}" class="text-xs text-cinnabar hover:underline">همه‌ی پیشنهادها</a>
                </div>
                <div class="divide-y divide-line">
                    @forelse($pendingSuggestions as $sug)
                        <a href="{{ route('admin.suggestions.show', $sug) }}" class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 py-2.5 group">
                            <x-ui.chip :tone="$sug->kind === 'relation' ? 'accent' : ($sug->kind === 'note' ? 'good' : 'default')">{{ $sug->field_label }}</x-ui.chip>
                            <span class="min-w-0">
                                <span class="block text-[13px] font-semibold text-ink group-hover:text-cinnabar truncate">{{ $sug->place_label }}</span>
                                <span class="block text-xs text-muted truncate">
                                    از {{ $sug->user?->name ?: ($sug->guest_name ?: 'مهمان') }}@if($sug->kind === 'field' && $sug->suggested_value !== '') · {{ \Illuminate\Support\Str::limit($sug->suggested_value, 60) }}@endif
                                </span>
                            </span>
                            <span class="text-xs text-muted whitespace-nowrap">@jago($sug->created_at)</span>
                        </a>
                    @empty
                        <p class="py-6 text-center text-[13px] text-muted">صف داوری خالی است.</p>
                    @endforelse
                </div>
            </x-ui.card>
        @endcan

        <div class="space-y-6 min-w-0">
            <x-ui.card>
                <h2 class="rubric text-base mb-3">پایگاه در یک نگاه</h2>
                <ul class="space-y-1.5 text-[13px]">
                    @foreach($counts as $label => $n)
                        <li class="flex items-baseline gap-2">
                            <span class="text-ink">{{ $label }}</span>
                            <span class="leader"></span>
                            <span class="font-bold tabular-nums">{{ \App\Support\Fa::num($n) }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            @can('audit.view')
                <x-ui.card>
                    <div class="flex items-baseline justify-between gap-3 mb-2">
                        <h2 class="rubric text-base">رخدادهای اخیر</h2>
                        <a href="{{ route('admin.audit-logs.index') }}" class="text-xs text-cinnabar hover:underline">لاگ ممیزی</a>
                    </div>
                    <div class="divide-y divide-line text-xs">
                        @forelse($recentLogs as $log)
                            <div class="flex items-start justify-between gap-3 py-2">
                                <span class="min-w-0">
                                    <span class="block font-semibold text-ink">{{ $log->actionLabel() }} · {{ $log->typeLabel() }} #{{ \App\Support\Fa::digits($log->auditable_id) }}</span>
                                    <span class="block text-muted">{{ $log->user?->name ?: 'سامانه' }}</span>
                                </span>
                                <span class="text-muted whitespace-nowrap">@jago($log->created_at)</span>
                            </div>
                        @empty
                            <p class="py-4 text-center text-muted">هنوز رخدادی ثبت نشده است.</p>
                        @endforelse
                    </div>
                </x-ui.card>
            @endcan
        </div>
    </div>
</div>
@endsection
