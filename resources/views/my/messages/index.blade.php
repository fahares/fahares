@extends('layouts.app')

@section('title', 'پیام‌ها | فهارس')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-4">
    <div>
        <h1 class="font-naskh text-2xl sm:text-3xl font-bold text-ink leading-relaxed">پیام‌ها</h1>
        <p class="mt-1 text-xs text-muted">پرسش‌های داوران درباره‌ی پیشنهادهای شما و پیام‌های مدیریت فهارس. به هر پیام همین‌جا، یا با پاسخ به ایمیل آن، پاسخ دهید.</p>
    </div>

    @forelse($threads as $t)
        @php
            $new = $t->unreadByUser();
            $waiting = $t->suggestion?->awaiting_reply_since && $t->acceptsReplies();
            $last = $t->messages->last();
        @endphp
        <a href="{{ route('my.messages.show', $t) }}" class="block p-4 sm:p-5 rounded-sm border shadow-sm transition hover:border-cinnabar {{ $new ? 'bg-amber-50 dark:bg-amber-950/20 border-amber-300 dark:border-amber-800' : 'bg-surface border-line' }}">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-sm font-bold text-ink">
                    @if($new)<span class="inline-block w-2 h-2 rounded-full bg-amber-500 align-middle me-1"></span>@endif{{ $t->subject }}
                </h2>
                <span class="shrink-0 text-[11px] text-muted">@jdate($t->last_message_at, 'long_time')</span>
            </div>
            <p class="mt-1 text-[11px] text-muted">{{ $t->staffLabel() }}@if($waiting) · <b class="text-amber-800 dark:text-amber-300">منتظر پاسخ شما</b>@endif</p>
            @if($last)
                <p class="mt-2 text-xs text-ink/80 leading-relaxed break-words">{{ $last->is_staff ? '' : 'شما: ' }}{{ \Illuminate\Support\Str::limit($last->body, 200) }}</p>
            @endif
        </a>
    @empty
        <div class="p-8 rounded-sm bg-surface border border-line text-center text-sm text-muted">پیامی ندارید.</div>
    @endforelse

    {{ $threads->links() }}
</div>
@endsection
