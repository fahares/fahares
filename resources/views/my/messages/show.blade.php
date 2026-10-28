@extends('layouts.app')

@section('title', $thread->subject . ' | پیام‌ها | فهارس')

@section('content')
@php
    $suggestion = $thread->suggestion;
    $record = $suggestion?->suggestable ? \App\Suggestions\SuggestionRegistry::describe($suggestion->suggestable) : null;
@endphp
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-4 text-xs sm:text-sm">
    <a href="{{ route('my.messages') }}" class="text-xs text-cinnabar hover:underline">→ همه‌ی پیام‌ها</a>
    <div class="bg-surface p-5 sm:p-6 rounded-sm border border-line shadow-sm space-y-3">
        <h1 class="font-naskh text-xl sm:text-2xl font-bold text-ink leading-relaxed">{{ $thread->subject }}</h1>
        @if($suggestion)
            <div class="p-3 rounded-sm bg-paper border border-line space-y-1">
                <p class="text-[11px] text-muted">
                    @if($record)<a href="{{ $record['url'] }}" class="font-bold text-ink hover:text-cinnabar">{{ $record['label'] }}</a> · @endif{{ $suggestion->field_label }} · <a href="{{ route('my.suggestions') }}" class="text-cinnabar hover:underline">پیشنهادهای من</a>
                </p>
                <p class="text-ink whitespace-pre-line break-words">{{ \Illuminate\Support\Str::limit($suggestion->suggested_value, 600) }}</p>
                @if($suggestion->rationale_citation)
                    <p class="text-[11px] text-muted whitespace-pre-line">مستند: {{ \Illuminate\Support\Str::limit($suggestion->rationale_citation, 400) }}</p>
                @endif
            </div>
        @endif

        @include('messages.partials.letters', ['thread' => $thread])

        @if($thread->acceptsReplies())
            <form action="{{ route('my.messages.reply', $thread) }}" method="POST" class="space-y-2 pt-3 border-t border-line">
                @csrf
                <label for="reply-body" class="block text-xs text-muted">پاسخ شما</label>
                <textarea id="reply-body" name="body" rows="4" required maxlength="{{ \App\Models\Message::MAX_LENGTH }}" class="w-full p-3 rounded-lg border border-line-strong bg-paper text-ink">{{ old('body') }}</textarea>
                @error('body')<p class="text-xs text-cinnabar">{{ $message }}</p>@enderror
                <button type="submit" class="px-4 py-2 rounded-sm bg-cinnabar text-on-cinnabar hover:opacity-90 font-bold">فرستادن پاسخ</button>
            </form>
        @else
            <p class="pt-3 border-t border-line text-xs text-muted">{{ $suggestion && $suggestion->status !== 'pending' ? 'این پیشنهاد داوری شده است و گفت‌وگو درباره‌ی آن بسته است.' : 'این گفت‌وگو بسته شده است.' }}</p>
        @endif
    </div>

    @if($thread->kind === \App\Models\MessageThread::KIND_DIRECT)
        <div class="flex flex-wrap items-center gap-3 text-[11px] text-muted">
            @if($thread->reported_at)
                <span>این پیام را گزارش کرده‌اید.</span>
            @else
                <form action="{{ route('my.messages.report', $thread) }}" method="POST" onsubmit="return confirm('این پیام به مدیر کل فهارس گزارش شود؟')">
                    @csrf
                    <button type="submit" class="hover:text-cinnabar underline">گزارش این پیام</button>
                </form>
            @endif
            <a href="{{ route('notifications.settings') }}" class="hover:text-cinnabar underline">خاموش‌کردن ایمیل این نوع پیام</a>
        </div>
    @endif
</div>
@endsection
