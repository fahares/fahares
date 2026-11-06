{{-- Letters with the submitter (backlog item 91): before the decision a question that waits for the answer, after it a plain letter --}}
@php
    $thread = $suggestion->thread;
    $pending = $suggestion->status === 'pending';
@endphp
<x-ui.card class="space-y-3" id="conversation">
    <h3 class="rubric text-base">گفت‌وگو با پیشنهاددهنده{{ $thread ? ' (' . \App\Support\Fa::num($thread->messages->count()) . ' پیام)' : '' }}</h3>
    @if($suggestion->awaiting_reply_since)
        <x-admin.notice tone="warn">
            منتظر پاسخ پیشنهاددهنده از @jago($suggestion->awaiting_reply_since)؛ این پیشنهاد تا پاسخ او از صف «در انتظار» بیرون است.
            اگر تا {{ \App\Support\Fa::num((int) config('notifications.question_deadline_days')) }} روز پاسخی نرسد، با برچسب «بی‌پاسخ» به صف برمی‌گردد. می‌توانید پیش از آن هم تصمیم بگیرید.
        </x-admin.notice>
    @elseif($suggestion->reply_state === 'unanswered' && $pending)
        <x-admin.notice tone="warn">پرسش بی‌پاسخ ماند و پیشنهاد به صف برگشت.</x-admin.notice>
    @endif

    @if($thread)
        @include('messages.partials.letters', ['thread' => $thread, 'staffView' => true])
    @elseif($pending)
        <p class="text-xs text-muted">هنوز پیامی رد و بدل نشده است. پرسش از پیشنهاددهنده در پنل تصمیم است.</p>
    @endif

    {{-- While the suggestion waits, the question is asked from the decision panel (step 14.72) --}}
    @unless($pending)
        @include('admin.suggestions.partials.ask-form')
    @endunless
</x-ui.card>
