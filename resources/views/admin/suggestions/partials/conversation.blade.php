{{-- Letters with the submitter (backlog item 91): before the decision a question that waits for the answer, after it a plain letter --}}
@php
    $thread = $suggestion->thread;
    $pending = $suggestion->status === 'pending';
@endphp
<x-ui.card class="space-y-3" id="conversation">
    <h3 class="rubric text-base">گفت‌وگو با پیشنهاددهنده</h3>
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
    @endif

    @if($suggestion->user_id)
        <form action="{{ route('admin.suggestions.ask', $suggestion) }}" method="POST" class="space-y-2">
            @csrf
            <label for="ask-body" class="block text-xs text-muted">
                @if($pending)
                    پرسش از پیشنهاددهنده. پیشنهاد تا پاسخ او در تب «منتظر پاسخ» می‌ماند.
                @else
                    پیام به پیشنهاددهنده، مثلاً توضیح تصمیم یا سپاس. وضعیت پیشنهاد تغییر نمی‌کند و او می‌تواند پاسخ دهد.
                @endif
                به نام «داور فهارس» فرستاده می‌شود و در «پیام‌ها»ی او و با ایمیل می‌رسد؛ نام و نشانی شما را نمی‌بیند.
            </label>
            <textarea id="ask-body" name="body" rows="3" required maxlength="{{ \App\Models\Message::MAX_LENGTH }}" class="admin-field w-full" placeholder="{{ $pending ? 'مثلاً: آیا نشانی فهرستی را که به آن ارجاع داده‌اید دارید؟' : 'مثلاً: این پیشنهاد را رد کردیم، زیرا…' }}">{{ old('body') }}</textarea>
            @error('body')<p class="text-xs text-cinnabar">{{ $message }}</p>@enderror
            <x-ui.btn type="submit" variant="primary">{{ $pending ? 'فرستادن پرسش' : 'فرستادن پیام' }}</x-ui.btn>
        </form>
    @else
        <p class="text-xs text-muted">این پیشنهاد از مهمان و بی حساب کاربری فرستاده شده است؛ پرسش از پیشنهاددهنده ممکن نیست.</p>
    @endif
</x-ui.card>
