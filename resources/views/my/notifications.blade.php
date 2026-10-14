@extends('layouts.app')

@section('title', 'اعلان‌ها | فهارس')

@section('content')
@php
    $formatter = class_exists(\IntlDateFormatter::class)
        ? new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'd MMMM y، HH:mm')
        : null;
@endphp
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-lg sm:text-xl font-bold text-stone-900 dark:text-stone-100">اعلان‌ها</h1>
        <a href="{{ route('notifications.settings') }}" class="text-xs font-bold text-[#B38A50] hover:underline">تنظیمات اعلان</a>
    </div>

    @forelse($notifications as $n)
        @php $new = in_array($n->id, $unreadIds, true); @endphp
        <a href="{{ $n->data['url'] ?? '#' }}" class="block p-4 sm:p-5 rounded-2xl border shadow-sm transition hover:border-[#B38A50] {{ $new ? 'bg-amber-50 dark:bg-amber-950/20 border-amber-300 dark:border-amber-800' : 'bg-white dark:bg-[#15192C] border-[#EADFCF] dark:border-[#272F4C]' }}">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-sm font-bold text-stone-900 dark:text-stone-100">
                    @if($new)<span class="inline-block w-2 h-2 rounded-full bg-amber-500 align-middle me-1"></span>@endif{{ $n->data['title'] ?? '' }}
                </h2>
                <span class="shrink-0 text-[11px] text-stone-400">{{ $formatter ? $formatter->format($n->created_at) : $n->created_at->format('Y/m/d H:i') }}</span>
            </div>
            @if(! empty($n->data['body']))
                <p class="mt-2 text-xs text-stone-600 dark:text-stone-300 leading-relaxed whitespace-pre-line break-words">{{ $n->data['body'] }}</p>
            @endif
        </a>
    @empty
        <div class="p-8 rounded-3xl bg-white dark:bg-[#15192C] border border-[#EADFCF] dark:border-[#272F4C] text-center text-sm text-stone-500 dark:text-stone-400">اعلانی ندارید.</div>
    @endforelse

    {{ $notifications->links() }}
</div>
@endsection
